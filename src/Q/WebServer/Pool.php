<?php
/**
 * @module Q
 */

/**
 * Pre-fork worker pool for PHP script execution.
 *
 * Each worker handles ONE request, then exits. The parent
 * maintains N idle workers at all times. When one finishes,
 * a replacement is forked immediately.
 *
 * Why one-request-per-process:
 *   PHP has no way to fully reset static state — Foo::$bar,
 *   DB connections, registered shutdown functions, output
 *   buffers all persist. The only clean reset is process exit.
 *
 * Why this is fast:
 *   fork() on Linux uses copy-on-write. The child inherits
 *   all loaded classes, opcache, config — everything the
 *   parent loaded during bootstrap — without copying memory.
 *   Cost: ~0.5ms per fork.
 *
 * Important: the parent must NOT open DB connections or
 * stateful resources before forking. Q's DB connections are
 * lazy (opened on first query), so this is natural.
 *
 * Elastic scaling:
 *   The pool starts at targetSize workers and scales between
 *   minWorkers and maxWorkers based on load:
 *   - Scale UP:   when all workers are busy and requests are
 *                 pending, fork new workers up to maxWorkers.
 *   - Scale DOWN: when idle workers exceed minWorkers and no
 *                 requests have been pending for scaleDownDelay
 *                 seconds, recycle excess workers without
 *                 replacing them.
 *   - Branch workers are on-demand and reaped after
 *                 branchIdleTimeout seconds of inactivity.
 *   - Workers busy longer than workerTimeout are killed.
 *   - Pending queue is capped at maxPendingRequests; overflow
 *                 gets 503 with Retry-After.
 *
 *   Parent (event loop, Q.inc.php loaded)
 *     ├── Worker 0 [idle, waiting on socketpair]
 *     ├── Worker 1 [busy, processing request]  → exits → replacement forked
 *     ├── Worker 2 [idle]
 *     └── Worker 3 [idle]  ← may be reaped if idle > minWorkers
 *
 * @class Q_WebServer_Pool
 */
class Q_WebServer_Pool
{
	public $targetSize;
	protected $octane = false;          // persistent workers (true) or fork-per-request (false)
	protected $maxRequests = 1000;      // voluntary recycle after N requests (octane mode)
	protected $workers = array();       // index => [pid, socket, busy, branch]
	protected $workerClients = array(); // index => HTTP client socket
	protected $workerBuffers = array(); // index => partial response data
	protected $workerParsed = array();  // index => full parsed request (for cache)
	protected $watchers = array();      // index => Q_Evented watcher id
	protected $pending = array();       // queued [client, parsed, scriptPath]
	protected $branchPending = array(); // branch => queued [client, parsed, scriptPath]
	protected $nextIndex = 0;
	protected $branchWorkerCounts = array(); // branch => count of workers
	protected $maxBranchWorkers = 2;    // max persistent workers per branch
	protected static $inputWrapperRegistered = false;

	// ── Elastic scaling ──────────────────────────────────
	protected $minWorkers = 2;          // never scale below this
	protected $maxWorkers = 16;         // never scale above this
	protected $maxPendingRequests = 100; // 503 backpressure when exceeded
	protected $workerTimeout = 30;      // kill workers busy longer than this (seconds)
	protected $branchIdleTimeout = 120; // reap branch workers idle longer than this
	protected $scaleDownDelay = 30;     // seconds of no pending before scaling down
	protected $lastPendingTime = 0;     // last time pending queue was non-empty
	protected $workerBusySince = array(); // index => hrtime(true) when worker became busy
	protected $branchLastUsed = array();  // branch => hrtime(true) when last request served
	protected $scalingTimerId = null;   // Q_Evented timer for scaling checks

	/**
	 * When set (in a booted worker), called with the request instead of
	 * including the script file. See Q_WebServer_Boot.
	 * @property $scriptRunner
	 * @type callable|null
	 */
	public static $scriptRunner = null;

	/**
	 * Serve exactly one request on an already-connected socket, then return.
	 * Used by booted single-use workers.
	 * @method serveOne
	 * @static
	 * @param {resource} $socket
	 */
	static function serveOne($socket)
	{
		self::childRun($socket, false, 1);
		self::runShutdownCallbacks();
	}

	/**
	 * Run shutdown callbacks captured by the source rewriter. In octane mode
	 * this happens between requests; a single-use worker must do it before
	 * exiting, after the response has gone out, or callbacks registered with
	 * register_shutdown_function() (WordPress's 'shutdown' action, session
	 * writes, deferred jobs) never run at all.
	 * @method runShutdownCallbacks
	 * @static
	 */
	static function runShutdownCallbacks()
	{
		// The response has been sent. Anything printed from here on (WordPress
		// flushes its output buffers on 'shutdown') belongs to no client and
		// would otherwise land in the server's log. Discard it; the buffer is
		// non-removable so callbacks cannot flush past it.
		ob_start(function () { return ''; }, 0, 0);
		if (class_exists('Q_WebServer_Compat', false)) {
			Q_WebServer_Compat::finishRequest();
		}
	}

	/**
	 * SCRIPT_NAME for a script: its path under the document root. It was
	 * the bare file name, so /wp-admin/edit.php ran with SCRIPT_NAME and
	 * PHP_SELF of /edit.php, and WordPress could not tell which admin screen
	 * it was on ("Invalid post type").
	 * @method scriptName
	 * @static
	 */
	static function scriptName($scriptPath)
	{
		$root = rtrim((string) (Q_WebServer::$rootDir ?? ''), '/\\');
		$real = realpath($scriptPath) ?: $scriptPath;
		$realRoot = $root !== '' ? (realpath($root) ?: $root) : '';
		foreach (array(array($scriptPath, $root), array($real, $realRoot)) as $pair) {
			list($p, $r) = $pair;
			if ($r !== '' && strncmp($p, $r . DIRECTORY_SEPARATOR, strlen($r) + 1) === 0) {
				return '/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($p, strlen($r) + 1));
			}
		}
		return '/' . basename($scriptPath);
	}

	/**
	 * Encode a request for a worker (length-prefixed JSON).
	 * @method encodeRequest
	 * @static
	 * @return {string}
	 */
	static function encodeRequest($parsed, $scriptPath)
	{
		$payload = array(
			'method'         => $parsed['method'],
			'uri'            => $parsed['uri'],
			'path'           => $parsed['path'],
			'query'          => $parsed['query'],
			'headers'        => $parsed['headers'],
			'rawHeaders'     => $parsed['rawHeaders'] ?? array(),
			'body'           => $parsed['body'],
			'scriptFilename' => $scriptPath,
			'scriptName'     => self::scriptName($scriptPath),
			'documentRoot'   => rtrim(Q_WebServer::$rootDir ?? '', '/\\'),
			'serverPort'     => (string)($_SERVER['SERVER_PORT'] ?? '8080'),
			'remoteAddr'     => '127.0.0.1',
			'pathInfo'       => $parsed['pathInfo'] ?? ''
		);
		// Pass sandbox config to the worker so it can apply per-app jailing
		if (Q_WebServer::$currentHostConfig
			&& !empty(Q_WebServer::$currentHostConfig['sandbox'])
		) {
			$payload['_hostConfig'] = Q_WebServer::$currentHostConfig;
		}
		// Pass branch metadata so workers can apply credential injection
		// and write restrictions
		if (!empty($parsed['_branch'])) {
			$payload['_branch'] = $parsed['_branch'];
			$payload['_branchRecord'] = $parsed['_branchRecord'];
		}
		$msg = json_encode($payload);
		return pack('N', strlen($msg)) . $msg;
	}

	/**
	 * @method __construct
	 * @param {integer} [$size=4]
	 */
	function __construct($size = null)
	{
		// Load Fork abstraction (pcntl on Unix, FFI+dll on Windows)
		$forkFile = dirname(__DIR__) . '/WebServer/Fork.php';
		if (!class_exists('Q_WebServer_Fork', false) && is_file($forkFile)) {
			require_once $forkFile;
		}
		if (!Q_WebServer_Fork::available()) {
			throw new Exception(
				"Q_WebServer_Pool requires fork capability. "
				. "Install pcntl (Linux/macOS) or qbix_fork.dll (Windows). "
				. "Or use --workers=0 for php-cgi mode."
			);
		}
		$this->targetSize = $size ?: (int) Q_Config::get(
			'Q', 'webserver', 'workers', 4
		);
		$this->octane = !Q_Config::get(
			'Q', 'webserver', 'forkPerRequest', false
		);
		$this->maxRequests = (int) Q_Config::get(
			'Q', 'webserver', 'maxRequests', 1000
		);
		$this->maxBranchWorkers = (int) Q_Config::get(
			'Q', 'webserver', 'maxBranchWorkers', 2
		);

		// ── Elastic scaling config ──
		// Detect CPU count for maxWorkers default
		$cpuCount = self::cpuCount();
		$defaultMax = min($cpuCount * 2, 64);

		$this->minWorkers = max(1, (int) Q_Config::get(
			'Q', 'webserver', 'minWorkers', 2
		));
		$this->maxWorkers = max($this->minWorkers, (int) Q_Config::get(
			'Q', 'webserver', 'maxWorkers', $defaultMax
		));
		// Clamp targetSize to [minWorkers, maxWorkers]
		$this->targetSize = max($this->minWorkers,
			min($this->maxWorkers, $this->targetSize));
		$this->maxPendingRequests = (int) Q_Config::get(
			'Q', 'webserver', 'maxPendingRequests', 100
		);
		$this->workerTimeout = (float) Q_Config::get(
			'Q', 'webserver', 'workerTimeout', 30
		);
		$this->branchIdleTimeout = (float) Q_Config::get(
			'Q', 'webserver', 'branchIdleTimeout', 120
		);
		$this->scaleDownDelay = (float) Q_Config::get(
			'Q', 'webserver', 'scaleDownDelay', 30
		);
		$this->lastPendingTime = 0;

		// Source rewriting on in both modes (unless explicitly disabled).
		// Octane needs it to reset lifecycle state; fork-per-request needs it
		// so header(), setcookie() and friends reach the client at all. Only
		// octane used to enable it, so fork-mode workers forked before the
		// first request silently dropped every header (a 302 with no Location).
		if (!Q_Config::get('Q', 'compat', 'skipSourceCodeTransform', false)) {
			$compatFile = dirname(__DIR__) . '/WebServer/Compat.php';
			if (!class_exists('Q_WebServer_Compat', false) && is_file($compatFile)) {
				require_once $compatFile;
			}
			if (class_exists('Q_WebServer_Compat', false)) {
				Q_WebServer_Compat::init();
			}
		}

		if ($this->octane) {
			$snapFile = dirname(__DIR__) . '/WebServer/Snapshot.php';
			if (!class_exists('Q_WebServer_Snapshot', false) && is_file($snapFile)) {
				require_once $snapFile;
			}
			if (class_exists('Q_WebServer_Snapshot', false)) {
				$n = Q_WebServer_Snapshot::take();
			}
		}
		if (function_exists('pcntl_signal')) {
			pcntl_signal(SIGCHLD, SIG_DFL);
		}
		for ($i = 0; $i < $this->targetSize; $i++) {
			$this->forkWorker();
		}

		// ── Start periodic scaling timer (every 5 seconds) ──
		$pool = $this;
		$this->scalingTimerId = Q_Evented::repeat(5.0, function () use ($pool) {
			$pool->scalingTick();
		});
	}

	/**
	 * Switch the pool to fork-per-request mode at runtime.
	 * Used when a preset is set but no Boot adapter detected the framework,
	 * so source transforms would break on re-inclusion in persistent workers.
	 * @method setForkPerRequest
	 * @param {boolean} $on
	 */
	function setForkPerRequest($on)
	{
		$this->octane = !$on;
	}

	/**
	 * Fork one worker. Child inherits parent's loaded state
	 * via copy-on-write.
	 * @method forkWorker
	 * @param {string|null} $branch  Branch tag (null = trunk worker)
	 * @return {integer} Worker index
	 */
	protected function forkWorker($branch = null)
	{
		// STREAM_PF_UNIX doesn't exist on Windows — use INET loopback
		$family = defined('STREAM_PF_UNIX') ? STREAM_PF_UNIX : STREAM_PF_INET;
		$pair = stream_socket_pair(
			$family, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP
		);
		if (!$pair) throw new Exception("socketpair failed");

		$pid = Q_WebServer_Fork::fork();
		if ($pid === -1 || $pid === false) throw new Exception("fork failed");

		if ($pid === 0) {
			// ── CHILD ──
			fclose($pair[0]);
			self::$roleSocket = $pair[1];
			self::$roleOctane = $this->octane;
			self::$roleMax = $this->maxRequests;
			throw new Q_WebServer_Role(__DIR__ . '/roles/worker.php');
		}

		// ── PARENT ──
		fclose($pair[1]);
		$sock = $pair[0];
		stream_set_blocking($sock, false);

		$index = $this->nextIndex++;
		$this->workers[$index] = array(
			'pid' => $pid, 'socket' => $sock, 'busy' => false,
			'branch' => $branch
		);
		if ($branch !== null) {
			$this->branchWorkerCounts[$branch]
				= ($this->branchWorkerCounts[$branch] ?? 0) + 1;
		}

		$pool = $this;
		$this->watchers[$index] = Q_Evented::onReadable(
			$sock,
			function ($s) use ($pool, $index) {
				$pool->onWorkerData($index, $s);
			}
		);
		Q_Evented::disable($this->watchers[$index]);
		return $index;
	}


	// ── Workers at global scope ─────────────────────────
	//
	// A forked worker does not run scripts from inside this class: PHP gives
	// an included file the scope of the include statement, and a method's
	// scope is not global. Frameworks assume global scope (wp-admin/menu.php
	// builds $menu and $submenu as top-level variables that admin functions
	// then read with `global $menu`), so a worker throws Q_WebServer_Role up
	// to the top level of qbixserver.php, which includes roles/worker.php
	// there. That file drives the loop below and includes each script at
	// global scope.

	/** @var resource Socket to the parent, for the worker role */
	static $roleSocket = null;
	static $roleOctane = false;
	static $roleMax = 0;
	static $roleKeepsGlobals = false;
	/** @var array Current request */
	static $req = null;
	/** @var string|null File to include at global scope for this request */
	static $scriptFile = null;
	protected static $handled = 0;
	protected static $failedRequest = false;

	/**
	 * Prepare this process to act as a worker: drop what belongs to the
	 * server (client sockets, the server's global variables).
	 * @method workerStart
	 * @static
	 */
	static function workerStart()
	{
		if (class_exists('Q_WebServer', false)) {
			foreach (Q_WebServer::$clients as $c) {
				if (is_resource($c)) @fclose($c);
			}
			Q_WebServer::$clients = array();
		}
		// A booted worker's globals are the framework's booted state; a pool
		// worker's are the server's and must not leak into scripts.
		if (!self::$roleKeepsGlobals) self::clearGlobals();
		stream_set_blocking(self::$roleSocket, true);
		self::$handled = 0;
		if (!self::$exitHandlerRegistered) {
			register_shutdown_function(array(__CLASS__, 'onExitDuringRequest'));
			self::$exitHandlerRegistered = true;
		}
	}

	/** Remove every global the server or a previous request left behind. */
	static function clearGlobals()
	{
		$keep = array('_GET','_POST','_COOKIE','_SERVER','_REQUEST',
			'_FILES','_ENV','_SESSION','GLOBALS','argv','argc', '_Q_RAW_INPUT');
		foreach (array_keys($GLOBALS) as $gk) {
			if (!in_array($gk, $keep, true)) unset($GLOBALS[$gk]);
		}
	}

	/**
	 * Wait for the next request and set up superglobals and output buffer.
	 * @method receive
	 * @static
	 * @return {boolean} false when the parent closed the connection
	 */
	static $__receiveTime = 0;
	static function receive()
	{
		$socket = self::$roleSocket;
		while (true) {
			$hdr = self::readExact($socket, 4);
			if ($hdr === false) return false;
			$len = unpack('N', $hdr)[1];
			if ($len > 10485760) return false;
			$json = self::readExact($socket, $len);
			if ($json === false) return false;
			$req = json_decode($json, true);
			if ($req) break;
			self::writeMsg($socket, 500, 'Bad message', array());
			if (!self::$roleOctane) return false;
		}
		self::$req = $req;
		self::$failedRequest = false;
		self::prepareRequest($req);

		// Apply per-app sandbox (same as executeScript does for the
		// fork-per-request path).  Must happen after prepareRequest
		// sets up superglobals but before the script runs.
		if (class_exists('Q_WebServer_Sandbox', false)
			&& (!empty($req['_hostConfig']) || !empty($req['_branchRecord']))
		) {
			$hostConfig = $req['_hostConfig'] ?? array();
			$branchRecord = $req['_branchRecord'] ?? null;
			Q_WebServer_Sandbox::apply(
				$hostConfig,
				rtrim($req['documentRoot'] ?? '', DIRECTORY_SEPARATOR),
				$branchRecord
			);

			// If sandbox detected a uid mismatch (persistent worker was
			// already at a different uid), send 503 and signal the loop
			// to exit so the parent can re-fork a fresh worker.
			if (Q_WebServer_Sandbox::$uidMismatch) {
				Q_WebServer_Sandbox::$uidMismatch = false;
				self::writeMsg(
					self::$roleSocket, 503,
					'Worker uid mismatch — retry',
					array('Retry-After' => '0')
				);
				return false; // exit the worker loop
			}
		}

		if (is_string(self::$scriptRunner)) {
			self::$scriptFile = self::$scriptRunner;     // adapter's global-scope handler
		} elseif (self::$scriptRunner) {
			self::$scriptFile = null;                    // adapter's callable handler
		} else {
			self::$scriptFile = $req['scriptFilename'];
		}
		self::$currentSocket = $socket;
		self::$__receiveTime = hrtime(true);
		return true;
	}

	/**
	 * Record an uncaught exception from the script.
	 * @method fail
	 * @static
	 */
	static function fail($e)
	{
		self::$failedRequest = true;
		if (ob_get_level()) @ob_clean();
		echo $e->getMessage();
		error_log(sprintf('PHP Fatal error:  Uncaught %s: %s in %s:%d%sStack trace:%s%s',
			get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(),
			PHP_EOL, PHP_EOL, $e->getTraceAsString()));
	}

	/**
	 * Send the response; in octane mode reset for the next request.
	 * @method respond
	 * @static
	 * @return {boolean} whether to take another request
	 */
	static function respond()
	{
		$resp = self::collectResponse(self::$failedRequest ? 500 : null);
		self::$currentSocket = null;
		self::writeMsg(self::$roleSocket, $resp['status'], $resp['body'], $resp['headers']);
		self::$handled++;
		if (!self::$roleOctane) return false;
		self::resetForNextRequest();
		if (self::$roleMax > 0 && self::$handled >= self::$roleMax) return false;
		return true;
	}

	// ── Child process ────────────────────────────────────

	/**
	 * Octane: reset state between requests in a persistent worker.
	 * @method resetForNextRequest
	 * @static
	 */
	protected static function resetForNextRequest()
	{
		// ── Octane: reset state for the next request ──

		// Compat layer: fire shutdown callbacks, restore error/exception
		// handlers, unregister request autoloaders, restore env vars,
		// close sessions, clean up uploads — all BEFORE snapshot restore
		// so shutdown callbacks see the request's final state.
		if (class_exists('Q_WebServer_Compat', false)
			&& Q_WebServer_Compat::isEnabled()) {
			Q_WebServer_Compat::shutdown();
			// Re-init for next request (re-registers file:// wrapper)
			Q_WebServer_Compat::init();
		}

		// Static properties: the snapshot captures the clean state the parent
    // had after preloading. restoreStatics() resets them via
    // ReflectionProperty::setValue — 0.05ms, vs 8ms for fork.
		if (class_exists('Q_WebServer_Snapshot', false)) {
			// Auto-introspect: scripts may declare new classes (e.g. inline
			// class definitions). These weren't in the original snapshot
			// because they didn't exist at preload time. Detect and add them
			// so their statics get reset on subsequent requests.
			Q_WebServer_Snapshot::updateNewClasses();
			Q_WebServer_Snapshot::restoreStatics();
		}

		// Global variables: restore to clean state between requests.
		// Boot workers use the Snapshot's global restore — this preserves
		// globals created during boot (e.g. $wp, $wpdb for WordPress)
		// while still resetting request-time additions.
		// Fork workers nuke everything since each fork is disposable.
		if (self::$roleOctane
			&& class_exists('Q_WebServer_Snapshot', false)) {
			Q_WebServer_Snapshot::restoreGlobals();
		} else {
			$keepGlobals = array('_GET','_POST','_COOKIE','_SERVER','_REQUEST',
				'_FILES','_ENV','_SESSION','GLOBALS','argv','argc',
				'_Q_RAW_INPUT');
			foreach (array_keys($GLOBALS) as $gk) {
				if (!in_array($gk, $keepGlobals, true)) {
					unset($GLOBALS[$gk]);
				}
			}
		}

		// Superglobals: overwritten by executeScript() on next iteration.
		// Output buffers: non-removable buffer in executeScript, read via ob_get_contents.
		// Error state: clear it.
		error_clear_last();

		// Response headers: clear Q_WebServer_State's accumulated headers
		// and any native header() calls from the previous request.
		if (class_exists('Q_WebServer_State', false)) {
			Q_WebServer_State::clear();
		}
		// Issue #16: also clear Q_Response accumulated state (scripts,
		// styles, cookies, errors) so they don't leak between requests.
		if (class_exists('Q_Response', false)
			&& method_exists('Q_Response', 'clear')) {
			Q_Response::clear();
		}
		if (function_exists('header_remove')) {
			@header_remove();
		}

		// DB connections: flush transaction state. A persistent worker that
		// serves request A (which starts a transaction) and then request B
		// would leak A's uncommitted transaction into B. ROLLBACK is safe
		// even if no transaction is active (it's a no-op).
		if (class_exists('Db', false) && method_exists('Db', 'getConnection')) {
			try {
				foreach (Db::getConnections() as $conn) {
					if (method_exists($conn, 'rawQuery')) {
						$conn->rawQuery('ROLLBACK');
					}
				}
			} catch (\Throwable $e) { /* no DB configured — that's fine */ }
		}
	}


	/**
	 * Child: handle requests. In octane mode, loops with snapshot restore
	 * between requests (0.05ms) instead of dying and re-forking (8ms).
	 * In classic mode, handles one request and exits.
	 *
	 * @method childRun
	 * @static
	 * @param {resource} $socket  Unix socket pair to the parent
	 * @param {boolean}  $octane  Whether to loop (true) or die after one (false)
	 * @param {integer}  $maxReqs Maximum requests before voluntary exit (0=unlimited)
	 */
	protected static function childRun($socket, $octane = false, $maxReqs = 0)
	{
		stream_set_blocking($socket, true);
		// An octane worker blocks on readExact() between requests, sometimes
		// for minutes. Without this, PHP's default_socket_timeout (60s) makes
		// that read return '' and the worker exits, so the first request after
		// an idle period gets a 502. There is no deadline on waiting for work.
		stream_set_timeout($socket, 86400);
		$handled = 0;

		do {
			// Read length-prefixed request
			$hdr = self::readExact($socket, 4);
			if ($hdr === false) break;
			$len = unpack('N', $hdr)[1];
			if ($len > 10485760) break;
			$json = self::readExact($socket, $len);
			if ($json === false) break;
			$req = json_decode($json, true);
			if (!$req) {
				self::writeMsg($socket, 500, 'Bad message', array());
				if (!$octane) break;
				continue;
			}

			// Execute the PHP script. If it calls exit(), the shutdown
			// function below still sends its response.
			if (!self::$exitHandlerRegistered) {
				register_shutdown_function(array(__CLASS__, 'onExitDuringRequest'));
				self::$exitHandlerRegistered = true;
			}
			self::$currentSocket = $socket;
			$resp = self::executeScript($req);
			self::writeMsg($socket, $resp['status'], $resp['body'],
				$resp['headers'], $resp['cookies'] ?? array());
			self::$currentSocket = null;
			$handled++;

			if (!$octane) break;

			self::resetForNextRequest();

			// Voluntary recycling: after N requests, exit so the parent
			// re-forks a clean worker. Safety net for state the snapshot
			// can't reach (C extension internals, accumulated closures).
			if ($maxReqs > 0 && $handled >= $maxReqs) break;

		} while (true);

		fclose($socket);
	}

	/**
	 * Set up superglobals and include the PHP script.
	 * The script (index.php, action.php, etc.) internally calls
	 * Q_WebController::execute() or Q_ActionController::execute().
	 */
	protected static function prepareRequest($req)
	{
		// ── Reset ALL superglobals to prevent cross-request leaks ──
		// $_SERVER: strip all HTTP_* headers and app-injected keys from
		// the previous request, then repopulate from this request only.
		foreach (array_keys($_SERVER) as $k) {
			if (strncmp($k, 'HTTP_', 5) === 0) unset($_SERVER[$k]);
		}
		unset($_SERVER['CONTENT_TYPE'], $_SERVER['CONTENT_LENGTH']);
		// Remove any keys the previous script injected
		$_serverKeep = array('PATH','HOME','LANG','USER','SHELL','TERM',
			'SHLVL','_','SERVER_SOFTWARE','GATEWAY_INTERFACE',
			'REQUEST_SCHEME','HTTPS','PHP_SELF','argv','argc');
		foreach (array_keys($_SERVER) as $k) {
			if (!in_array($k, $_serverKeep, true)
				&& strncmp($k, 'REQUEST_', 8) !== 0
				&& strncmp($k, 'SERVER_', 7) !== 0
				&& strncmp($k, 'SCRIPT_', 7) !== 0
				&& strncmp($k, 'DOCUMENT_', 9) !== 0
				&& strncmp($k, 'REMOTE_', 7) !== 0
				&& strncmp($k, 'QUERY_', 6) !== 0
			) {
				unset($_SERVER[$k]);
			}
		}

		$_SERVER['REQUEST_TIME']       = time();
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
		$_SERVER['REQUEST_METHOD'] = $req['method'];
		$_SERVER['REQUEST_URI'] = $req['uri'];
		$_SERVER['QUERY_STRING'] = $req['query'] ?? '';
		$_SERVER['SCRIPT_FILENAME'] = $req['scriptFilename'];
		$_SERVER['SCRIPT_NAME'] = $req['scriptName'] ?? '/index.php';
		$_SERVER['PHP_SELF'] = $req['scriptName'] ?? '/index.php';
		$_SERVER['DOCUMENT_ROOT'] = $req['documentRoot'] ?? '';
		$_SERVER['PATH_INFO'] = $req['pathInfo'] ?? '';

		// Match mod_php / PHP-FPM: set cwd to the script's directory so
		// relative paths (template caches, require's) resolve beside it.
		$scriptDir = dirname($req['scriptFilename']);
		if ($scriptDir !== '' && is_dir($scriptDir)) {
			chdir($scriptDir);
		}

		$_SERVER['SERVER_NAME'] = $req['headers']['host'] ?? 'localhost';
		$_SERVER['SERVER_PORT'] = $req['serverPort'] ?? '8080';
		$_SERVER['REMOTE_ADDR'] = $req['remoteAddr'] ?? '127.0.0.1';
		$_SERVER['SERVER_SOFTWARE'] = 'QbixServer/' . (defined('QBIX_SERVER_VERSION') ? QBIX_SERVER_VERSION : '1.0');
		$_SERVER['GATEWAY_INTERFACE'] = 'CGI/1.1';
		$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

		foreach ($req['headers'] as $k => $v) {
			$_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
		}
		if (isset($req['headers']['content-type']))
			$_SERVER['CONTENT_TYPE'] = $req['headers']['content-type'];
		if (isset($req['headers']['content-length']))
			$_SERVER['CONTENT_LENGTH'] = $req['headers']['content-length'];

		// REQUEST_SCHEME / HTTPS from proxy headers or direct
		$proto = $req['headers']['x-forwarded-proto'] ?? '';
		if (strtolower($proto) === 'https' || ($req['https'] ?? false)) {
			$_SERVER['HTTPS'] = 'on';
			$_SERVER['REQUEST_SCHEME'] = 'https';
		} else {
			$_SERVER['REQUEST_SCHEME'] = 'http';
			unset($_SERVER['HTTPS']);
		}

		// PHP_AUTH_USER / PHP_AUTH_PW from Authorization header
		$authHeader = $req['headers']['authorization'] ?? '';
		if ($authHeader && stripos($authHeader, 'Basic ') === 0) {
			$decoded = base64_decode(substr($authHeader, 6));
			if ($decoded !== false && strpos($decoded, ':') !== false) {
				list($user, $pass) = explode(':', $decoded, 2);
				$_SERVER['PHP_AUTH_USER'] = $user;
				$_SERVER['PHP_AUTH_PW'] = $pass;
			}
		} else {
			unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
		}

		// Populate getallheaders() / apache_request_headers()
		if (!class_exists('Q_WebServer_GetAllHeaders', false)) {
			$_gah = __DIR__ . '/GetAllHeaders.php';
			if (is_file($_gah)) require_once $_gah;
		}
		if (class_exists('Q_WebServer_GetAllHeaders', false)) {
			Q_WebServer_GetAllHeaders::register();
			Q_WebServer_GetAllHeaders::set(
				$req['headers'] ?? array(),
				$req['rawHeaders'] ?? array()
			);
		}

		// Sanitize $_SERVER: PSR-7 implementations (e.g. Diactoros)
		// reject booleans in header values. Cast everything to string.
		foreach ($_SERVER as $k => $v) {
			if (is_bool($v)) {
				$_SERVER[$k] = $v ? '1' : '';
			} elseif (!is_string($v) && !is_numeric($v) && !is_array($v)) {
				$_SERVER[$k] = (string)$v;
			}
		}

		// ── Clear and rebuild all input superglobals ──
		$_GET = $_POST = $_REQUEST = $_FILES = array();
		$_COOKIE = array();
		if (!empty($req['query'])) parse_str($req['query'], $_GET);

		// Parse cookies from the Cookie header
		$cookieHeader = $req['headers']['cookie'] ?? '';
		if ($cookieHeader !== '') {
			foreach (explode(';', $cookieHeader) as $c) {
				$c = trim($c);
				if ($c === '') continue;
				$eq = strpos($c, '=');
				if ($eq !== false) {
					$_COOKIE[urldecode(substr($c, 0, $eq))] = urldecode(substr($c, $eq + 1));
				}
			}
		}

		$ct = strtolower($req['headers']['content-type'] ?? '');
		$origCt = $req['headers']['content-type'] ?? '';
		$raw = $req['body'] ?? '';
		if (strpos($ct, 'application/x-www-form-urlencoded') !== false) {
			parse_str($raw, $_POST);
		} elseif (strpos($ct, 'application/json') !== false) {
			$_POST = json_decode($raw, true) ?: array();
		} elseif (strpos($ct, 'multipart/form-data') !== false) {
			\Q_WebServer::parseMultipart($origCt, $raw, $_POST, $_FILES);
		}
		$_REQUEST = array_merge($_GET, $_POST);

		// php://input workaround — register a custom stream wrapper
		// so file_get_contents('php://input') works in persistent workers.
		// PHP's built-in php://input is empty in CLI SAPI for included scripts.
		$GLOBALS['_Q_RAW_INPUT'] = $raw;
		if (!self::$inputWrapperRegistered) {
			stream_wrapper_unregister('php');
			stream_wrapper_register('php', 'Q_WebServer_PhpInputStream');
			self::$inputWrapperRegistered = true;
		}

		// ── Branch context for credential injection and write restrictions ──
		if (!empty($req['_branch'])
			&& class_exists('Q_WebServer_CompatFileWrapper', false)
		) {
			$branchRecord = $req['_branchRecord'] ?? array();
			$credentials = $branchRecord['credentials'] ?? array();
			Q_WebServer_CompatFileWrapper::setBranchContext($branchRecord, $credentials);
			if (!empty($branchRecord['user'])) {
				Q_WebServer_CompatFileWrapper::$branchUser = $branchRecord['user'];
			}
			if (!empty($branchRecord['fileTier'])) {
				Q_WebServer_CompatFileWrapper::$branchFileTier = $branchRecord['fileTier'];
			}
			// Templates writable below the code tier must not call arbitrary
			// methods or tools. Fail closed: no recorded tier means restricted.
			Q_Config::set('Q', 'handlebars', 'restricted',
				empty($branchRecord['fileTier']) || $branchRecord['fileTier'] !== 'code'
			);
			if (!empty($branchRecord['preset'])) {
				Q_WebServer_CompatFileWrapper::$branchPreset = $branchRecord['preset'];
			}
			if (!empty($branchRecord['userPaths'])) {
				Q_WebServer_CompatFileWrapper::$branchUserPaths = $branchRecord['userPaths'];
			}
			if (!empty($branchRecord['userConfig'])) {
				Q_WebServer_CompatFileWrapper::$branchUserConfig = $branchRecord['userConfig'];
			}
		}

		// Non-removable buffer: Q_Dispatcher::dispatch() calls ob_end_flush()
		// which would destroy a normal buffer. Passing flags=0 makes
		// ob_end_flush()/ob_end_clean() fail on this buffer, so it survives.
		// We read it with ob_get_contents(). (Same fix as dispatchToQ, issue #12.)
		// Our buffer: cleanable (so each request starts empty) but not
		// removable, because front controllers call ob_end_flush() and would
		// otherwise pop it. It used to be opened with flags 0, which also made
		// it impossible to clean: every page stayed in it and was printed to
		// the server's stdout when the worker exited, and octane workers
		// stacked a new permanent buffer on every request.
		if (self::$bufferLevel === 0 || ob_get_level() < self::$bufferLevel) {
			ob_start(null, 0, PHP_OUTPUT_HANDLER_CLEANABLE);
			self::$bufferLevel = ob_get_level();
		} else {
			while (ob_get_level() > self::$bufferLevel && @ob_end_clean()) {}
			@ob_clean();
		}
	}

	protected static function executeScript($req)
	{
		self::prepareRequest($req);

		// Apply per-app sandbox before running application code.
		// For branch requests, pass the branch record so Sandbox can
		// use the branch uid for posix_setuid and the branch root for
		// open_basedir.
		if (class_exists('Q_WebServer_Sandbox', false)
			&& (!empty($req['_hostConfig']) || !empty($req['_branchRecord']))
		) {
			$hostConfig = $req['_hostConfig'] ?? array();
			$branchRecord = $req['_branchRecord'] ?? null;
			Q_WebServer_Sandbox::apply(
				$hostConfig,
				rtrim($req['documentRoot'] ?? '', DIRECTORY_SEPARATOR),
				$branchRecord
			);

			// If sandbox detected a uid mismatch (persistent worker was
			// already at a different uid), return 503 so the parent
			// re-forks a fresh worker for this request.
			if (Q_WebServer_Sandbox::$uidMismatch) {
				Q_WebServer_Sandbox::$uidMismatch = false;
				return array(
					'status' => 503,
					'body' => 'Worker uid mismatch — retry',
					'headers' => array('Retry-After' => '0'),
				);
			}
		}

		// ── CVE-2026-109618 / CVE-2026-109619 (PHP2Shell) hardening ──
		// PHP's built-in multipart parser and session.upload_progress handler
		// contain heap use-after-free bugs reachable before any userland code
		// runs in a standard php-fpm setup.  This webserver runs as CLI SAPI
		// (not FPM), parses multipart itself, and has already populated
		// $_POST/$_FILES above.  Disabling PHP's own body-reading and upload-
		// progress machinery ensures the vulnerable C code never executes for
		// requests handled here, regardless of the PHP version in use.
		@ini_set('enable_post_data_reading',        '0');
		@ini_set('session.upload_progress.enabled', '0');

		$failed = false;
		try {
			if (self::$scriptRunner) {
				// Booted framework: the adapter handles the request with the
				// state its boot master prepared, instead of including the
				// front controller from scratch.
				call_user_func(self::$scriptRunner, $req);
			} else {
				include($req['scriptFilename']);
			}
		} catch (\Throwable $e) {
			$failed = true;
			if (ob_get_level()) @ob_clean();
			echo $e->getMessage();
			// PHP logs uncaught exceptions; catching them here must not hide them
			error_log(sprintf('PHP Fatal error:  Uncaught %s: %s in %s:%d%sStack trace:%s%s',
				get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(),
				PHP_EOL, PHP_EOL, $e->getTraceAsString()));
		}
		return self::collectResponse($failed ? 500 : null);
	}

	/**
	 * Gather status, headers and body for the current request.
	 * @method collectResponse
	 * @static
	 * @param {integer|null} $forceStatus
	 * @return {array}
	 */
	protected static function collectResponse($forceStatus = null)
	{
		$status = 200;
		$headers = array();
		if ($forceStatus === null) {
			// Collect headers from native header() (works in fpm, no-op in CLI)
			foreach (headers_list() as $h) {
				if (strpos($h, ':') !== false) {
					list($k, $v) = explode(':', $h, 2);
					$k = trim($k);
					if (strcasecmp($k, 'Set-Cookie') === 0) {
						$headers['Set-Cookie'][] = trim($v);
					} else {
						$headers[$k] = trim($v);
					}
				}
			}
			// Also collect headers from Q_WebServer_State (works in CLI/octane)
			if (class_exists('Q_WebServer_State', false)) {
				foreach (\Q_WebServer_State::getHeaders() as $k => $v) {
					if ($k === 'Set-Cookie') {
						$headers['Set-Cookie'] = array_merge(
							(array) ($headers['Set-Cookie'] ?? array()), (array) $v);
					} else {
						$headers[$k] = $v;
					}
				}
				// Cookies set with setcookie() (rewritten to Q_Response::setCookie)
				// were never collected here, so they never reached the client.
				foreach (\Q_WebServer_State::cookieHeaders() as $c) {
					$headers['Set-Cookie'][] = $c;
				}
			}
			$code = http_response_code();
			if ($code && $code !== 200) $status = $code;

			// Check Q_WebServer_State for status code (CLI SAPI ignores http_response_code)
			if (class_exists('Q_WebServer_State', false)) {
				$stateCode = \Q_WebServer_State::getStatusCode();
				if ($stateCode && $stateCode !== 200) $status = $stateCode;
			}

			// Recover status from the Platform's own error state.
			// Same fix as dispatchToQ: http_response_code() is a no-op under
			// CLI SAPI, so the Platform's 412/424 errors arrive as 200.
			if ($status === 200
			and class_exists('Q_Response', false)
			and method_exists('Q_Response', 'getErrors')) {
				try {
					foreach ((array) \Q_Response::getErrors() as $err) {
						if (is_object($err) and !empty($err->httpResponseCode)) {
							$status = (int) $err->httpResponseCode;
							break;
						}
					}
				} catch (\Throwable $ignore) {}
			}
		} else {
			$status = $forceStatus;
		}
		// Buffers the script opened and left open hold part of the page
		// (caching plugins rely on the end-of-request flush): fold them down
		// into ours, then read and empty ours.
		$body = '';
		if (self::$bufferLevel > 0) {
			while (ob_get_level() > self::$bufferLevel && @ob_end_flush()) {}
			if (ob_get_level() >= self::$bufferLevel) {
				$body = (string) ob_get_contents();

				@ob_clean();
			}
		}
		while (@ob_end_clean()) { /* drop removable buffers */ }

		// Cookies live in Q_Response, which is the worker's memory. The
		// parent used to read its own copy when writing the response and so
		// found nothing: setcookie() reached the client from no script at
		// all. Carry them across with the response.
		$cookies = array();
		if (class_exists('Q_WebServer_State', false)
		and method_exists('Q_WebServer_State', 'cookieHeaders')) {
			$cookies = (array) Q_WebServer_State::cookieHeaders();
		}
		return compact('status', 'body', 'headers', 'cookies');
	}

	/** @var integer Output-buffer level of the worker's response buffer (public for Compat shims) */
	public static $bufferLevel = 0;

	/** @var resource|null Socket of the request this worker is serving */
	protected static $currentSocket = null;
	protected static $exitHandlerRegistered = false;

	/**
	 * Shutdown function for workers: if the script called exit() or die()
	 * in the middle of a request (header('Location: ...'); exit; is the
	 * common case), send what it produced instead of dropping the response.
	 * Without this the client got a 502.
	 * @method onExitDuringRequest
	 * @static
	 */
	static function onExitDuringRequest()
	{
		$sock = self::$currentSocket;
		if (!$sock || !is_resource($sock)) return;
		self::$currentSocket = null;
		$err = error_get_last();
		$fatal = $err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true);
		$resp = self::collectResponse($fatal ? 500 : null);
		if ($fatal && $resp['body'] === '') {
			$resp['body'] = 'Internal Server Error';
		}
		self::writeMsg($sock, $resp['status'], $resp['body'], $resp['headers']);
		self::runShutdownCallbacks();
	}

	// ── Parent-side dispatch ─────────────────────────────


	/**
	 * Send a request to an idle worker. Queues if all busy.
	 *
	 * Branch routing (octane mode only):
	 *   - Trunk requests (no _branch) go to trunk workers (branch tag = null).
	 *   - Branch requests go to a worker tagged with the same branch.
	 *   - If no matching idle worker exists and we haven't hit
	 *     $maxBranchWorkers for that branch, fork a new tagged worker.
	 *   - Otherwise queue in per-branch pending queue.
	 *   - In fork-per-request mode, workers are disposable so any idle one works.
	 */
	function dispatch($client, $parsed, $scriptPath)
	{
		$branch = $parsed['_branch'] ?? null;

		if (!$this->octane || $branch === null) {
			// Trunk request or fork-per-request mode: use any idle trunk worker
			$idle = $this->findIdle(null);
			if ($idle === null) {
				// ── Elastic scale-up: fork a new worker if below maxWorkers ──
				$trunkCount = $this->trunkWorkerCount();
				if ($trunkCount < $this->maxWorkers) {
					$newIdx = $this->forkWorker(null);
					$this->sendTo($newIdx, $client, $parsed, $scriptPath);
					return;
				}
				// ── Backpressure: reject if pending queue is full ──
				if ($this->maxPendingRequests > 0
					&& count($this->pending) >= $this->maxPendingRequests
				) {
					Q_WebServer::sendResponse($client, 503,
						'Server busy — try again shortly',
						'text/plain; charset=utf-8',
						array('Retry-After' => '1'));
					Q_WebServer::closeClient((int) $client);
					return;
				}
				$this->pending[] = array($client, $parsed, $scriptPath);
				$this->lastPendingTime = hrtime(true);
				return;
			}
			$this->sendTo($idle, $client, $parsed, $scriptPath);
			return;
		}

		// Branch request in octane mode: find idle worker tagged to this branch
		$idle = $this->findIdle($branch);
		if ($idle !== null) {
			$this->sendTo($idle, $client, $parsed, $scriptPath);
			$this->branchLastUsed[$branch] = hrtime(true);
			return;
		}

		// No idle branch worker — can we fork a new one?
		$count = $this->branchWorkerCounts[$branch] ?? 0;
		if ($count < $this->maxBranchWorkers) {
			$newIdx = $this->forkWorker($branch);
			$this->sendTo($newIdx, $client, $parsed, $scriptPath);
			$this->branchLastUsed[$branch] = hrtime(true);
			return;
		}

		// ── Backpressure for branch pending ──
		$branchQueueSize = count($this->branchPending[$branch] ?? array());
		if ($this->maxPendingRequests > 0
			&& $branchQueueSize >= $this->maxPendingRequests
		) {
			Q_WebServer::sendResponse($client, 503,
				'Branch busy — try again shortly',
				'text/plain; charset=utf-8',
				array('Retry-After' => '1'));
			Q_WebServer::closeClient((int) $client);
			return;
		}

		// At capacity for this branch — queue
		if (!isset($this->branchPending[$branch])) {
			$this->branchPending[$branch] = array();
		}
		$this->branchPending[$branch][] = array($client, $parsed, $scriptPath);
		$this->lastPendingTime = hrtime(true);
	}

	protected function sendTo($index, $client, $parsed, $scriptPath)
	{
		$this->workers[$index]['busy'] = true;
		$this->workerBusySince[$index] = hrtime(true);
		$this->workerClients[$index] = $client;
		$this->workerBuffers[$index] = '';
		$this->workerRequestHeaders[$index] = $parsed['headers'];
		$this->workerParsed[$index] = $parsed;

		// Inject Merkle stale hints so the worker knows which slots changed.
		// This enables framework-aware partial re-rendering (skip unchanged slots).
		if (class_exists('Q_WebServer_Cache_Components', false)
			&& Q_WebServer_Cache_Components::enabled()
		) {
			$pageKey = $parsed['path'] . '?' . ($parsed['query'] ?? '');
			$staleHints = Q_WebServer_Cache_Components::buildStaleHintsHeader($pageKey);
			if ($staleHints !== null) {
				$parsed['headers']['x-q-cache-stale-hints'] = $staleHints;
			}
			// Also tell the worker which slots have cached HTML it can reuse.
			// The worker can skip rendering these slots entirely and inject
			// the cached HTML, saving render time on partially-stale pages.
			$cachedSlots = Q_WebServer_Cache_Components::buildCachedSlotsHeader($pageKey);
			if ($cachedSlots !== null) {
				$parsed['headers']['x-q-cache-cached-slots'] = $cachedSlots;
			}
		}

		// In octane mode, the watcher was cancelled after the previous
		// response to prevent stream_select from firing endlessly on the
		// idle socket. Create a fresh one-shot watcher for this dispatch.
		if (!isset($this->watchers[$index])) {
			$pool = $this;
			$this->watchers[$index] = Q_Evented::onReadable(
				$this->workers[$index]['socket'],
				function ($s) use ($pool, $index) {
					$pool->onWorkerData($index, $s);
				}
			);
		} else {
			Q_Evented::enable($this->watchers[$index]);
		}

		$written = @fwrite($this->workers[$index]['socket'], self::encodeRequest($parsed, $scriptPath));
		if ($written === false || $written === 0) {
			// Worker died before receiving the request — recycle and re-queue
			$this->pending[] = array($client, $parsed, $scriptPath);
			$this->recycle($index, true);
		}
	}

	/**
	 * Called when data or EOF arrives from a worker.
	 */
	function onWorkerData($index, $sock)
	{
		$chunk = @fread($sock, 65536);

		if ($chunk === false || $chunk === '') {
			// Empty read. In octane mode, the worker stays alive between
			// requests: an empty read means the socket has no new data,
			// NOT that the worker exited. Only recycle if the worker process
			// is actually gone.
			if ($this->octane) {
				$pid = $this->workers[$index]['pid'] ?? 0;
				$alive = $pid && posix_kill($pid, 0);
				if ($alive) return; // worker is idle, not dead
			}
			$this->recycle($index, true);
			return;
		}

		$this->workerBuffers[$index] .= $chunk;
		$buf = $this->workerBuffers[$index];
		if (strlen($buf) < 4) return;

		$len = unpack('N', substr($buf, 0, 4))[1];
		if (strlen($buf) < 4 + $len) return;

		// Got complete response
		$json = substr($buf, 4, $len);
		$response = json_decode($json, true);

		// Decode base64-encoded body (wire protocol uses body_b64 / b64
		// so NUL bytes and invalid UTF-8 survive JSON transport)
		if ($response && array_key_exists('body_b64', $response)) {
			$response['body'] = base64_decode($response['body_b64']);
			unset($response['body_b64']);
		}

		if ($response && !empty($response['b64'])) {
			$decoded = is_string($response['body'] ?? null)
				? base64_decode($response['body'], true) : false;
			if ($decoded === false) {
				$response = array(
					'status' => 500,
					'body' => 'Invalid binary response payload',
					'headers' => array('Content-Type' => 'text/plain')
				);
			} else {
				$response['body'] = $decoded;
				unset($response['b64']);
			}
		}

		// Check for cache messages piggybacked on the response
		if ($response && !empty($response['_cacheMessages'])) {
			foreach ($response['_cacheMessages'] as $msg) {
				Q_WebServer_Cache_Components::processChildMessage($msg);
			}
			unset($response['_cacheMessages']);
		}

		// Process Merkle cache headers from the worker response.
		// In-process paths do this inside executePhpScript(), but the forked
		// worker path was missing it — X-Q-Cache-Tree, X-Q-Cache-Deps and
		// X-Q-Cache-Invalidate headers went unprocessed.
		$parsed = $this->workerParsed[$index] ?? null;
		if ($response && $parsed
			&& class_exists('Q_WebServer_Cache_Components', false)
			&& Q_WebServer_Cache_Components::enabled()
		) {
			$pageKey = ($parsed['path'] ?? '') . '?' . ($parsed['query'] ?? '');
			$responseHeaders = $response['headers'] ?? array();
			Q_WebServer_Cache_Components::processResponseHeaders($pageKey, $responseHeaders);
			$response['headers'] = $responseHeaders;
		}

		// Store response in reverse proxy cache.
		// In-process paths call Cache::put() after processPhpResponse(),
		// but the forked worker path was missing it entirely.
		if ($response && $parsed) {
			Q_WebServer_Cache::put($parsed, $response);
		}

		$client = $this->workerClients[$index] ?? null;
		$reqHeaders = $this->workerRequestHeaders[$index] ?? [];
		if ($response && $client && is_resource($client)) {
			$reqHeaders['_keepAlive'] = false;
			$this->workerRequestHeaders[$index] = $reqHeaders;
			$this->sendHttp($client, $response, $index);
			Q_WebServer::closeClient((int) $client);
		}
		if ($client && class_exists('Q_WebServer_Boot', false)) {
			Q_WebServer_Boot::requestFinished($client);
		}

		// In octane mode the worker is still alive — mark it idle so it
		// can receive the next request. In classic mode, recycle it (the
		// child exited after one request).
		if ($this->octane) {
			$this->workers[$index]['busy'] = false;
			unset($this->workerBusySince[$index]);
			$this->workers[$index]['requests'] = ($this->workers[$index]['requests'] ?? 0) + 1;
			$this->workerBuffers[$index] = '';
			unset($this->workerClients[$index], $this->workerParsed[$index]);
			// Track branch activity
			$workerBranch2 = $this->workers[$index]['branch'] ?? null;
			if ($workerBranch2 !== null) {
				$this->branchLastUsed[$workerBranch2] = hrtime(true);
			}

			// Recycle if marked for graceful recycling, or hit maxRequests
			$shouldRecycle = !empty($this->workers[$index]['recycleAfter'])
				|| ($this->maxRequests > 0 && $this->workers[$index]['requests'] >= $this->maxRequests);

			if ($shouldRecycle) {
				$this->recycle($index, false);
				return;
			}

			if (isset($this->watchers[$index])) {
				Q_Evented::cancel($this->watchers[$index]);
				unset($this->watchers[$index]);
			}
			// Drain the correct pending queue: branch workers serve their
			// branch queue first, trunk workers serve the trunk queue.
			$workerBranch = $this->workers[$index]['branch'] ?? null;
			$drained = false;
			if ($workerBranch !== null
				&& !empty($this->branchPending[$workerBranch])
			) {
				$next = array_shift($this->branchPending[$workerBranch]);
				if (empty($this->branchPending[$workerBranch])) {
					unset($this->branchPending[$workerBranch]);
				}
				$this->sendTo($index, $next[0], $next[1], $next[2]);
				$drained = true;
			}
			if (!$drained && !empty($this->pending)) {
				// Only trunk workers should pick up trunk pending
				if ($workerBranch === null) {
					$next = array_shift($this->pending);
					$this->sendTo($index, $next[0], $next[1], $next[2]);
				}
			}
		} else {
			$this->recycle($index, false);
		}
	}

	/**
	 * Clean up a finished worker: close socket, reap pid,
	 * fork replacement, process pending queue.
	 */
	protected function recycle($index, $isEof)
	{
		if (isset($this->watchers[$index])) {
			Q_Evented::cancel($this->watchers[$index]);
			unset($this->watchers[$index]);
		}

		// EOF with no response → 502
		if ($isEof && isset($this->workerClients[$index])
			&& empty($this->workerBuffers[$index])
		) {
			$c = $this->workerClients[$index];
			if (is_resource($c)) {
				Q_WebServer::sendResponse($c, 502, 'Worker died');
				@fclose($c);
			}
		} elseif (isset($this->workerClients[$index])) {
			$c = $this->workerClients[$index];
			if (is_resource($c)) @fclose($c);
		}

		$workerBranch = null;
		if (isset($this->workers[$index])) {
			$workerBranch = $this->workers[$index]['branch'] ?? null;
			$sock = $this->workers[$index]['socket'];
			if (is_resource($sock)) @fclose($sock);
			Q_WebServer_Fork::waitpid($this->workers[$index]["pid"], $st, 1);
		}
		unset($this->workers[$index], $this->workerClients[$index],
			$this->workerBuffers[$index], $this->workerRequestHeaders[$index],
			$this->workerParsed[$index]);

		// Track branch worker count
		if ($workerBranch !== null) {
			if (isset($this->branchWorkerCounts[$workerBranch])) {
				$this->branchWorkerCounts[$workerBranch]--;
				if ($this->branchWorkerCounts[$workerBranch] <= 0) {
					unset($this->branchWorkerCounts[$workerBranch]);
				}
			}
		}

		// Clear busy-since tracking
		unset($this->workerBusySince[$index]);

		if ($workerBranch !== null) {
			// Branch worker died — check if there are pending branch requests
			if (!empty($this->branchPending[$workerBranch])) {
				$newIdx = $this->forkWorker($workerBranch);
				$next = array_shift($this->branchPending[$workerBranch]);
				if (empty($this->branchPending[$workerBranch])) {
					unset($this->branchPending[$workerBranch]);
				}
				$this->sendTo($newIdx, $next[0], $next[1], $next[2]);
			}
			// Don't fork a replacement branch worker if no pending —
			// branch workers are on-demand, not maintained at a target size
		} else {
			// Trunk worker — replace only if we'd drop below targetSize,
			// or if there are pending requests. This allows elastic
			// scale-down: excess workers recycled without replacement.
			$trunkCount = $this->trunkWorkerCount();
			if ($trunkCount < $this->targetSize || !empty($this->pending)) {
				$newIdx = $this->forkWorker(null);
				// Drain trunk pending queue
				if (!empty($this->pending)) {
					$next = array_shift($this->pending);
					$this->sendTo($newIdx, $next[0], $next[1], $next[2]);
				}
			}
		}
	}

	/**
	 * We need the original request headers for compression
	 * negotiation. Store them alongside the client.
	 * @property $workerRequestHeaders
	 */
	protected $workerRequestHeaders = array();

	protected function sendHttp($client, $resp, $index)
	{
		$reqHeaders = $this->workerRequestHeaders[$index] ?? array();
		Q_WebServer_Headers::processResponse($client, $resp, $reqHeaders);
	}

	/**
	 * Find an idle worker matching the given branch tag.
	 * @param {string|null} $branch  null = trunk workers only
	 * @return {integer|null} Worker index, or null if none idle
	 */
	protected function findIdle($branch = null)
	{
		foreach ($this->workers as $i => $w) {
			if (!$w['busy'] && ($w['branch'] ?? null) === $branch) return $i;
		}
		return null;
	}

	/**
	 * Gracefully recycle a single worker: let it finish its current request,
	 * then replace it with a fresh fork.
	 * If the worker is idle, recycle immediately.
	 */
	function recycleWorker($index)
	{
		if (!isset($this->workers[$index])) return false;
		if ($this->workers[$index]['busy']) {
			// Mark for recycling after current request finishes
			$this->workers[$index]['recycleAfter'] = true;
			return 'pending';
		}
		$this->recycle($index, false);
		return 'recycled';
	}

	/**
	 * Gracefully recycle ALL workers (rolling restart).
	 * Idle workers are replaced immediately. Busy workers are marked
	 * and replaced when their current request finishes.
	 * Returns count of immediately recycled vs pending.
	 */
	function recycleAll()
	{
		$immediate = 0;
		$pending = 0;
		// Collect indices first — recycle() modifies $this->workers
		$indices = array_keys($this->workers);
		foreach ($indices as $i) {
			if (!isset($this->workers[$i])) continue;
			if ($this->workers[$i]['busy']) {
				$this->workers[$i]['recycleAfter'] = true;
				$pending++;
			} else {
				$this->recycle($i, false);
				$immediate++;
			}
		}
		return ['immediate' => $immediate, 'pending' => $pending];
	}

	/**
	 * Get stats for the control panel.
	 */
	function workerStats()
	{
		$now = hrtime(true);
		$stats = [];
		foreach ($this->workers as $i => $w) {
			$busySec = null;
			if ($w['busy'] && isset($this->workerBusySince[$i])) {
				$busySec = round(($now - $this->workerBusySince[$i]) / 1e9, 1);
			}
			$stats[] = [
				'index' => $i,
				'pid' => $w['pid'],
				'busy' => $w['busy'],
				'busySec' => $busySec,
				'branch' => $w['branch'] ?? null,
				'requests' => $w['requests'] ?? 0,
				'recycleAfter' => !empty($w['recycleAfter']),
			];
		}
		$branchPendingTotal = 0;
		foreach ($this->branchPending as $q) {
			$branchPendingTotal += count($q);
		}
		return [
			'workers' => $stats,
			'total' => count($this->workers),
			'busy' => count(array_filter($this->workers, function($w) { return $w['busy']; })),
			'idle' => count(array_filter($this->workers, function($w) { return !$w['busy']; })),
			'trunkWorkers' => $this->trunkWorkerCount(),
			'branchWorkers' => $this->branchWorkerCounts,
			'maxBranchWorkers' => $this->maxBranchWorkers,
			'maxRequests' => $this->maxRequests,
			'mode' => $this->octane ? 'persistent' : 'fork-per-request',
			'pending' => count($this->pending),
			'branchPending' => $branchPendingTotal,
			// Elastic scaling info
			'minWorkers' => $this->minWorkers,
			'maxWorkers' => $this->maxWorkers,
			'targetSize' => $this->targetSize,
			'maxPendingRequests' => $this->maxPendingRequests,
			'workerTimeout' => $this->workerTimeout,
			'branchIdleTimeout' => $this->branchIdleTimeout,
			'scaleDownDelay' => $this->scaleDownDelay,
			'cpuCount' => self::cpuCount(),
		];
	}

	// ── Elastic scaling engine ──────────────────────────

	/**
	 * Count trunk workers (branch tag = null).
	 * @return int
	 */
	function trunkWorkerCount()
	{
		$n = 0;
		foreach ($this->workers as $w) {
			if (($w['branch'] ?? null) === null) $n++;
		}
		return $n;
	}

	/**
	 * Periodic scaling tick — called every 5 seconds by the event loop.
	 * Handles: scale-down of excess trunk workers, reaping idle branch
	 * workers, and killing timed-out workers.
	 */
	function scalingTick()
	{
		$now = hrtime(true);

		// ── 1. Kill timed-out workers ──
		if ($this->workerTimeout > 0) {
			$timeoutNs = $this->workerTimeout * 1e9;
			foreach ($this->workerBusySince as $index => $since) {
				if (($now - $since) > $timeoutNs && isset($this->workers[$index])) {
					$pid = $this->workers[$index]['pid'];
					error_log(sprintf(
						'[Pool] Worker %d (pid %d) timed out after %.1fs — killing',
						$index, $pid, ($now - $since) / 1e9
					));
					// SIGTERM first, then recycle will handle cleanup
					if (function_exists('posix_kill')) {
						posix_kill($pid, SIGTERM);
					}
					// Schedule a SIGKILL if it doesn't exit in 5 seconds
					$pool = $this;
					Q_Evented::delay(5.0, function () use ($pool, $index, $pid) {
						if (isset($pool->workers[$index])
							&& ($pool->workers[$index]['pid'] ?? 0) === $pid
						) {
							if (function_exists('posix_kill')) {
								posix_kill($pid, SIGKILL);
							}
							$pool->recycle($index, true);
						}
					});
				}
			}
		}

		// ── 2. Scale down excess trunk workers ──
		// Only scale down if no requests have been pending for scaleDownDelay
		$pendingIdleSec = $this->lastPendingTime > 0
			? ($now - $this->lastPendingTime) / 1e9
			: PHP_FLOAT_MAX; // never had pending = idle forever
		$trunkCount = $this->trunkWorkerCount();

		if ($pendingIdleSec >= $this->scaleDownDelay
			&& empty($this->pending)
			&& $trunkCount > $this->minWorkers
		) {
			// Recycle ONE idle excess worker per tick (gradual ramp-down)
			foreach ($this->workers as $i => $w) {
				if (!$w['busy'] && ($w['branch'] ?? null) === null
					&& $trunkCount > $this->minWorkers
				) {
					// Don't replace this worker — recycle handles that via
					// the trunkWorkerCount() < targetSize check
					$this->targetSize = max($this->minWorkers, $trunkCount - 1);
					$this->recycle($i, false);
					break; // one per tick to avoid thrashing
				}
			}
		}

		// ── 3. Reap idle branch workers ──
		if ($this->branchIdleTimeout > 0) {
			$branchTimeoutNs = $this->branchIdleTimeout * 1e9;
			foreach ($this->workers as $i => $w) {
				$branch = $w['branch'] ?? null;
				if ($branch === null) continue;
				if ($w['busy']) continue;
				$lastUsed = $this->branchLastUsed[$branch] ?? 0;
				if ($lastUsed > 0 && ($now - $lastUsed) > $branchTimeoutNs) {
					error_log(sprintf(
						'[Pool] Reaping idle branch worker %d (branch=%s, idle %.0fs)',
						$i, $branch, ($now - $lastUsed) / 1e9
					));
					$this->recycle($i, false);
					// Only reap one per branch per tick
					break;
				}
			}
		}
	}

	/**
	 * Dynamically resize the pool to a new target.
	 * If growing: forks new workers immediately.
	 * If shrinking: marks excess idle workers for recycling.
	 * @method resize
	 * @param int $newSize New target worker count
	 * @return array Summary of what happened
	 */
	function resize($newSize)
	{
		$newSize = max($this->minWorkers, min($this->maxWorkers, (int) $newSize));
		$oldSize = $this->targetSize;
		$this->targetSize = $newSize;
		$forked = 0;
		$recycled = 0;

		$trunkCount = $this->trunkWorkerCount();

		if ($newSize > $trunkCount) {
			// Scale up: fork the difference
			$toFork = $newSize - $trunkCount;
			for ($i = 0; $i < $toFork; $i++) {
				$this->forkWorker(null);
				$forked++;
			}
		} elseif ($newSize < $trunkCount) {
			// Scale down: recycle excess idle workers
			$toRecycle = $trunkCount - $newSize;
			$indices = array_keys($this->workers);
			foreach ($indices as $idx) {
				if ($toRecycle <= 0) break;
				if (!isset($this->workers[$idx])) continue;
				$w = $this->workers[$idx];
				if (($w['branch'] ?? null) !== null) continue; // skip branch workers
				if ($w['busy']) {
					// Mark for recycling after current request
					$this->workers[$idx]['recycleAfter'] = true;
					$toRecycle--;
					$recycled++;
				} else {
					$this->recycle($idx, false);
					$toRecycle--;
					$recycled++;
				}
			}
		}

		return array(
			'oldSize' => $oldSize,
			'newSize' => $newSize,
			'forked' => $forked,
			'recycled' => $recycled,
			'clamped' => ($newSize !== (int) $newSize),
			'minWorkers' => $this->minWorkers,
			'maxWorkers' => $this->maxWorkers,
		);
	}

	/**
	 * Detect the number of CPU cores. Cross-platform.
	 * @return int
	 */
	static function cpuCount()
	{
		// Linux: /proc/cpuinfo or nproc
		if (PHP_OS_FAMILY === 'Linux') {
			if (is_readable('/proc/cpuinfo')) {
				$cpuinfo = file_get_contents('/proc/cpuinfo');
				$count = substr_count($cpuinfo, 'processor');
				if ($count > 0) return $count;
			}
			$nproc = @shell_exec('nproc 2>/dev/null');
			if ($nproc && ($n = (int) trim($nproc)) > 0) return $n;
		}
		// macOS
		if (PHP_OS_FAMILY === 'Darwin') {
			$sysctl = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
			if ($sysctl && ($n = (int) trim($sysctl)) > 0) return $n;
		}
		// Windows
		if (PHP_OS_FAMILY === 'Windows') {
			$n = (int) ($_SERVER['NUMBER_OF_PROCESSORS'] ?? 0);
			if ($n > 0) return $n;
		}
		return 4; // safe fallback
	}

	/**
	 * Graceful shutdown: SIGTERM all workers, wait up to $timeout seconds,
	 * then SIGKILL any remaining.
	 * @param {float} $timeout Seconds to wait after SIGTERM before SIGKILL
	 */
	function shutdown($timeout = 3.0)
	{
		// Cancel scaling timer
		if ($this->scalingTimerId !== null) {
			Q_Evented::cancel($this->scalingTimerId);
			$this->scalingTimerId = null;
		}
		// Cancel watchers and close sockets
		foreach ($this->workers as $i => $w) {
			if (isset($this->watchers[$i])) {
				Q_Evented::cancel($this->watchers[$i]);
			}
			if (is_resource($w['socket'])) @fclose($w['socket']);
		}

		// Send SIGTERM to all workers
		foreach ($this->workers as $w) {
			if (function_exists("posix_kill")) posix_kill($w["pid"], SIGTERM);
		}

		// Wait for workers to exit gracefully
		$deadline = microtime(true) + $timeout;
		$remaining = $this->workers;
		while (!empty($remaining) && microtime(true) < $deadline) {
			foreach ($remaining as $i => $w) {
				$result = Q_WebServer_Fork::waitpid($w['pid'], $st, 1);
				if ($result > 0 || $result === -1) {
					unset($remaining[$i]);
				}
			}
			if (!empty($remaining)) {
				usleep(50000); // 50ms
			}
		}

		// SIGKILL any workers that didn't exit in time
		foreach ($remaining as $w) {
			if (function_exists("posix_kill")) posix_kill($w["pid"], SIGKILL);
			Q_WebServer_Fork::waitpid($w['pid'], $st, 0);
		}

		$this->workers = array();
	}

	function idleCount()
	{
		$n = 0;
		foreach ($this->workers as $w) if (!$w['busy']) $n++;
		return $n;
	}

	/**
	 * Get per-worker stats: PID, busy status, RSS memory.
	 * Linux: /proc/$pid/statm. macOS: ps -o rss. Windows: tasklist.
	 */
	function getWorkerStats()
	{
		$stats = array();
		$totalRss = 0;
		$pids = array();
		foreach ($this->workers as $i => $w) {
			$pids[] = $w['pid'];
		}

		// Batch RSS lookup by platform
		$rssMap = self::getProcessRss($pids);

		foreach ($this->workers as $i => $w) {
			$pid = $w['pid'];
			$rssKb = $rssMap[$pid] ?? 0;
			$totalRss += $rssKb;
			$stats[] = array(
				'pid' => $pid,
				'busy' => $w['busy'],
				'branch' => $w['branch'] ?? null,
				'rssKb' => $rssKb,
			);
		}
		return array(
			'workers' => $stats,
			'count' => count($this->workers),
			'target' => $this->targetSize,
			'idle' => $this->idleCount(),
			'totalRssKb' => $totalRss,
		);
	}

	/**
	 * Get RSS in KB for a list of PIDs. Cross-platform.
	 */
	static function getProcessRss($pids)
	{
		$map = array();
		if (empty($pids)) return $map;

		if (PHP_OS_FAMILY === 'Linux') {
			foreach ($pids as $pid) {
				$statm = @file_get_contents("/proc/$pid/statm");
				if ($statm) {
					$fields = explode(' ', $statm);
					$map[$pid] = (int) ($fields[1] ?? 0) * 4; // pages * 4KB
				}
			}
		} elseif (PHP_OS_FAMILY === 'Darwin') {
			// macOS: single ps call for all PIDs
			$pidList = implode(',', $pids);
			$out = @shell_exec("ps -o pid=,rss= -p $pidList 2>/dev/null");
			if ($out) {
				foreach (explode("\n", trim($out)) as $line) {
					$parts = preg_split('/\s+/', trim($line));
					if (count($parts) >= 2) {
						$map[(int) $parts[0]] = (int) $parts[1]; // ps rss is in KB
					}
				}
			}
		} elseif (PHP_OS_FAMILY === 'Windows') {
			// Windows: tasklist for each PID
			foreach ($pids as $pid) {
				$out = @shell_exec("tasklist /FI \"PID eq $pid\" /FO CSV /NH 2>NUL");
				if ($out && preg_match('/"(\d[\d,]+)\s*K"/', $out, $m)) {
					$map[$pid] = (int) str_replace(',', '', $m[1]);
				}
			}
		}
		return $map;
	}

	// ── Wire helpers ─────────────────────────────────────

	protected static function readExact($sock, $n)
	{
		$buf = '';
		while (strlen($buf) < $n) {
			$c = fread($sock, $n - strlen($buf));
			if ($c === false || $c === '') {
				// '' means EOF only when feof() says so. A read timeout —
				// or a signal interrupting the read — also yields '', and
				// treating that as EOF kills a perfectly healthy worker.
				if (feof($sock)) return false;
				$meta = @stream_get_meta_data($sock);
				if (!empty($meta['timed_out'])) continue;
				return false;
			}
			$buf .= $c;
		}
		return $buf;
	}

	protected static function writeMsg($sock, $status, $body, $headers,
		$cookies = array())
	{
		$payload = array(
			'status' => $status,
			'headers' => $headers,
			'cookies' => $cookies,
			'body_b64' => base64_encode($body),
		);
		$j = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
		if ($j === false) {
			// Last resort: send a 502 with a safe body
			$j = json_encode(array(
				'status' => 502,
				'headers' => array('Content-Type' => 'text/plain'),
				'cookies' => array(),
				'body_b64' => base64_encode('Internal encoding error'),
			));
		}
		$frame = pack('N', strlen($j)) . $j;
		$total = strlen($frame);
		$written = 0;
		while ($written < $total) {
			$n = @fwrite($sock, substr($frame, $written));
			if ($n === false) break;
			if ($n === 0) {
				if (!is_resource($sock) || feof($sock)) break;
				usleep(1000);
				continue;
			}
			$written += $n;
		}
	}
}

/**
 * Custom stream wrapper for php://input in persistent workers.
 * PHP's built-in php://input is empty in CLI SAPI for included scripts.
 * This wrapper reads from $GLOBALS['_Q_RAW_INPUT'] which the Pool sets
 * before each request.
 *
 * Only handles php://input — all other php:// streams pass through to
 * PHP's built-in handler.
 *
 * @class Q_WebServer_PhpInputStream
 */
class Q_WebServer_PhpInputStream
{
	protected $data = '';
	protected $pos = 0;
	protected $path = '';

	/**
	 * @var resource|null Fallback wrapper handle for non-input streams
	 */
	protected $fallback = null;

	function stream_open($path, $mode, $options, &$openedPath)
	{
		$this->path = $path;
		if ($path === 'php://input') {
			$this->data = $GLOBALS['_Q_RAW_INPUT'] ?? '';
			$this->pos = 0;
			return true;
		}
		// For php://stdout, php://stderr, php://temp, php://memory, etc.
		// restore the built-in wrapper, open, then re-register ours
		stream_wrapper_restore('php');
		$this->fallback = fopen($path, $mode);
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', __CLASS__);
		return $this->fallback !== false;
	}

	function stream_read($count)
	{
		if ($this->fallback) return fread($this->fallback, $count);
		$chunk = substr($this->data, $this->pos, $count);
		$this->pos += strlen($chunk);
		return $chunk;
	}

	function stream_write($data)
	{
		if ($this->fallback) return fwrite($this->fallback, $data);
		return 0;
	}

	function stream_tell()
	{
		if ($this->fallback) return ftell($this->fallback);
		return $this->pos;
	}

	function stream_eof()
	{
		if ($this->fallback) return feof($this->fallback);
		return $this->pos >= strlen($this->data);
	}

	function stream_stat()
	{
		if ($this->fallback) return fstat($this->fallback);
		return ['size' => strlen($this->data)];
	}

	function stream_close()
	{
		if ($this->fallback) { fclose($this->fallback); $this->fallback = null; }
	}

	function stream_seek($offset, $whence = SEEK_SET)
	{
		if ($this->fallback) return fseek($this->fallback, $offset, $whence) === 0;
		switch ($whence) {
			case SEEK_SET: $this->pos = $offset; break;
			case SEEK_CUR: $this->pos += $offset; break;
			case SEEK_END: $this->pos = strlen($this->data) + $offset; break;
		}
		return true;
	}
}
