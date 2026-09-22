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
 *   Parent (event loop, Q.inc.php loaded)
 *     ├── Worker 0 [idle, waiting on socketpair]
 *     ├── Worker 1 [busy, processing request]  → exits → replacement forked
 *     ├── Worker 2 [idle]
 *     └── Worker 3 [idle]
 *
 * @class Q_WebServer_Pool
 */
class Q_WebServer_Pool
{
	public $targetSize;
	protected $workers = array();       // index => [pid, socket, busy]
	protected $workerClients = array(); // index => HTTP client socket
	protected $workerBuffers = array(); // index => partial response data
	protected $watchers = array();      // index => Q_Evented watcher id
	protected $pending = array();       // queued [client, parsed, scriptPath]
	protected $nextIndex = 0;
	protected static $inputWrapperRegistered = false;

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

		// In octane mode, default compat on (unless explicitly disabled)
		// so lifecycle functions are shimmed for shared-nothing safety.
		if ($this->octane) {
			$compatSet = Q_Config::get('Q', 'compat', 'skipSourceCodeTransform', null);
			if ($compatSet === null) {
				Q_Config::set('Q', 'compat', 'skipSourceCodeTransform', false);
			}
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
	}

	/**
	 * Fork one worker. Child inherits parent's loaded state
	 * via copy-on-write.
	 * @method forkWorker
	 * @return {integer} Worker index
	 */
	protected function forkWorker()
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
			self::childRun($pair[1], $this->octane, $this->maxRequests);
			exit(0);
		}

		// ── PARENT ──
		fclose($pair[1]);
		$sock = $pair[0];
		stream_set_blocking($sock, false);

		$index = $this->nextIndex++;
		$this->workers[$index] = array(
			'pid' => $pid, 'socket' => $sock, 'busy' => false
		);

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

	// ── Child process ────────────────────────────────────

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

			// Execute the PHP script
			$resp = self::executeScript($req);
			self::writeMsg($socket, $resp['status'], $resp['body'], $resp['headers']);
			$handled++;

			if (!$octane) break;

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
			// had after preloading. restoreStatics() resets all user-defined class
			// statics via ReflectionProperty::setValue — 0.05ms, vs 8ms for fork.
			if (class_exists('Q_WebServer_Snapshot', false)) {
				// Auto-introspect: scripts may declare new classes (e.g. inline
				// class definitions). These weren't in the original snapshot
				// because they didn't exist at preload time. Detect and add them
				// so their statics get reset on subsequent requests.
				Q_WebServer_Snapshot::updateNewClasses();
				Q_WebServer_Snapshot::restoreStatics();
			}

			// Global variables: remove anything the script added.
			// Keep superglobals and the server's own bookkeeping.
			$keepGlobals = array('_GET','_POST','_COOKIE','_SERVER','_REQUEST',
				'_FILES','_ENV','_SESSION','GLOBALS','argv','argc',
				'_Q_RAW_INPUT');
			foreach (array_keys($GLOBALS) as $gk) {
				if (!in_array($gk, $keepGlobals, true)) {
					unset($GLOBALS[$gk]);
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
	protected static function executeScript($req)
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

		$_SERVER['REQUEST_METHOD'] = $req['method'];
		$_SERVER['REQUEST_URI'] = $req['uri'];
		$_SERVER['QUERY_STRING'] = $req['query'] ?? '';
		$_SERVER['SCRIPT_FILENAME'] = $req['scriptFilename'];
		$_SERVER['SCRIPT_NAME'] = $req['scriptName'] ?? '/index.php';
		$_SERVER['PHP_SELF'] = $req['scriptName'] ?? '/index.php';
		$_SERVER['DOCUMENT_ROOT'] = $req['documentRoot'] ?? '';
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

		// Non-removable buffer: Q_Dispatcher::dispatch() calls ob_end_flush()
		// which would destroy a normal buffer. Passing flags=0 makes
		// ob_end_flush()/ob_end_clean() fail on this buffer, so it survives.
		// We read it with ob_get_contents(). (Same fix as dispatchToQ, issue #12.)
		ob_start(null, 0, 0);
		$status = 200;
		$headers = array();
		try {
			include($req['scriptFilename']);
			// Collect headers from native header() (works in fpm, no-op in CLI)
			foreach (headers_list() as $h) {
				if (strpos($h, ':') !== false) {
					list($k, $v) = explode(':', $h, 2);
					$headers[trim($k)] = trim($v);
				}
			}
			// Also collect headers from Q_WebServer_State (works in CLI/octane)
			if (class_exists('Q_WebServer_State', false)) {
				foreach (\Q_WebServer_State::getHeaders() as $k => $v) {
					$headers[$k] = $v;
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
		} catch (\Throwable $e) {
			$status = 500;
			if (ob_get_level()) ob_clean();
			echo $e->getMessage();
		}
		// ob_get_contents reads the non-removable buffer; ob_get_clean would
		// return false. Then drop any buffers we can.
		$body = '';
		if (ob_get_level()) {
			$body = (string) ob_get_contents();
			@ob_clean();
		}
		while (@ob_end_clean()) { /* drop removable buffers */ }
		return compact('status', 'body', 'headers');
	}

	// ── Parent-side dispatch ─────────────────────────────

	/**
	 * Send a request to an idle worker. Queues if all busy.
	 */
	function dispatch($client, $parsed, $scriptPath)
	{
		$idle = $this->findIdle();
		if ($idle === null) {
			$this->pending[] = array($client, $parsed, $scriptPath);
			return;
		}
		$this->sendTo($idle, $client, $parsed, $scriptPath);
	}

	protected function sendTo($index, $client, $parsed, $scriptPath)
	{
		$this->workers[$index]['busy'] = true;
		$this->workerClients[$index] = $client;
		$this->workerBuffers[$index] = '';
		$this->workerRequestHeaders[$index] = $parsed['headers'];
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

		$msg = json_encode(array(
			'method'         => $parsed['method'],
			'uri'            => $parsed['uri'],
			'path'           => $parsed['path'],
			'query'          => $parsed['query'],
			'headers'        => $parsed['headers'],
			'rawHeaders'     => $parsed['rawHeaders'] ?? array(),
			'body'           => $parsed['body'],
			'scriptFilename' => $scriptPath,
			'scriptName'     => '/' . basename($scriptPath),
			'documentRoot'   => Q_WebServer::$rootDir ?? '',
			'serverPort'     => (string)($_SERVER['SERVER_PORT'] ?? '8080'),
			'remoteAddr'     => '127.0.0.1'
		));
		$written = @fwrite($this->workers[$index]['socket'], pack('N', strlen($msg)) . $msg);
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

		// Check for cache messages piggybacked on the response
		if ($response && !empty($response['_cacheMessages'])) {
			foreach ($response['_cacheMessages'] as $msg) {
				Q_WebServer_Cache_Components::processChildMessage($msg);
			}
			unset($response['_cacheMessages']);
		}

		$client = $this->workerClients[$index] ?? null;
		$reqHeaders = $this->workerRequestHeaders[$index] ?? [];
		if ($response && $client && is_resource($client)) {
			$reqHeaders['_keepAlive'] = false;
			$this->workerRequestHeaders[$index] = $reqHeaders;
			$this->sendHttp($client, $response, $index);
			Q_WebServer::closeClient((int) $client);
		}

		// In octane mode the worker is still alive — mark it idle so it
		// can receive the next request. In classic mode, recycle it (the
		// child exited after one request).
		if ($this->octane) {
			$this->workers[$index]['busy'] = false;
			$this->workers[$index]['requests'] = ($this->workers[$index]['requests'] ?? 0) + 1;
			$this->workerBuffers[$index] = '';
			unset($this->workerClients[$index]);

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
			if (!empty($this->pending)) {
				$next = array_shift($this->pending);
				$this->dispatch($next[0], $next[1], $next[2]);
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

		if (isset($this->workers[$index])) {
			$sock = $this->workers[$index]['socket'];
			if (is_resource($sock)) @fclose($sock);
			Q_WebServer_Fork::waitpid($this->workers[$index]["pid"], $st, 1);
		}
		unset($this->workers[$index], $this->workerClients[$index],
			$this->workerBuffers[$index], $this->workerRequestHeaders[$index]);

		// Immediately fork replacement
		$newIdx = $this->forkWorker();

		// Drain pending queue
		if (!empty($this->pending)) {
			$next = array_shift($this->pending);
			$this->sendTo($newIdx, $next[0], $next[1], $next[2]);
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

	protected function findIdle()
	{
		foreach ($this->workers as $i => $w) {
			if (!$w['busy']) return $i;
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
		foreach ($this->workers as $i => $w) {
			if ($w['busy']) {
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
		$stats = [];
		foreach ($this->workers as $i => $w) {
			$stats[] = [
				'index' => $i,
				'pid' => $w['pid'],
				'busy' => $w['busy'],
				'requests' => $w['requests'] ?? 0,
				'recycleAfter' => !empty($w['recycleAfter']),
			];
		}
		return [
			'workers' => $stats,
			'total' => count($this->workers),
			'busy' => count(array_filter($this->workers, function($w) { return $w['busy']; })),
			'idle' => count(array_filter($this->workers, function($w) { return !$w['busy']; })),
			'maxRequests' => $this->maxRequests,
			'mode' => $this->octane ? 'persistent' : 'fork-per-request',
			'pending' => count($this->pending),
		];
	}

	/**
	 * Graceful shutdown: SIGTERM all workers, wait up to $timeout seconds,
	 * then SIGKILL any remaining.
	 * @param {float} $timeout Seconds to wait after SIGTERM before SIGKILL
	 */
	function shutdown($timeout = 3.0)
	{
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
			if ($c === false || $c === '') return false;
			$buf .= $c;
		}
		return $buf;
	}

	protected static function writeMsg($sock, $status, $body, $headers)
	{
		$j = json_encode(compact('status', 'body', 'headers'));
		fwrite($sock, pack('N', strlen($j)) . $j);
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
