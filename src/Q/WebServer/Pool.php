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

	/**
	 * @method __construct
	 * @param {integer} [$size=4]
	 */
	function __construct($size = null)
	{
		if (!function_exists('pcntl_fork')) {
			throw new Exception(
				"Q_WebServer_Pool requires pcntl extension. "
				. "Use --workers=0 or Caddy/nginx + php-fpm."
			);
		}
		$this->targetSize = $size ?: (int) Q_Config::get(
			'Q', 'webserver', 'workers', 4
		);
		$this->octane = (bool) Q_Config::get(
			'Q', 'webserver', 'octane', true
		);
		$this->maxRequests = (int) Q_Config::get(
			'Q', 'webserver', 'maxRequests', 1000
		);
		// Take a snapshot BEFORE forking workers so they inherit clean state.
		// restoreStatics() costs ~0.05ms vs ~8ms for pcntl_fork().
		if ($this->octane) {
			$snapFile = dirname(__DIR__) . '/WebServer/Snapshot.php';
			if (!class_exists('Q_WebServer_Snapshot', false) && is_file($snapFile)) {
				require_once $snapFile;
			}
			if (class_exists('Q_WebServer_Snapshot', false)) {
				$n = Q_WebServer_Snapshot::take();
			}
		}
		pcntl_signal(SIGCHLD, SIG_DFL);
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
		$pair = stream_socket_pair(
			STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP
		);
		if (!$pair) throw new Exception("socketpair failed");

		$pid = pcntl_fork();
		if ($pid === -1) throw new Exception("fork failed");

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
		$_SERVER['DOCUMENT_ROOT'] = $req['documentRoot'] ?? '';
		$_SERVER['SERVER_NAME'] = $req['headers']['host'] ?? 'localhost';
		$_SERVER['SERVER_PORT'] = $req['serverPort'] ?? '8080';
		$_SERVER['REMOTE_ADDR'] = $req['remoteAddr'] ?? '127.0.0.1';

		foreach ($req['headers'] as $k => $v) {
			$_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
		}
		if (isset($req['headers']['content-type']))
			$_SERVER['CONTENT_TYPE'] = $req['headers']['content-type'];
		if (isset($req['headers']['content-length']))
			$_SERVER['CONTENT_LENGTH'] = $req['headers']['content-length'];

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
		$raw = $req['body'] ?? '';
		if (strpos($ct, 'application/x-www-form-urlencoded') !== false) {
			parse_str($raw, $_POST);
		} elseif (strpos($ct, 'application/json') !== false) {
			$_POST = json_decode($raw, true) ?: array();
		}
		$_REQUEST = array_merge($_GET, $_POST);

		// php://input workaround for forked processes
		$GLOBALS['_Q_RAW_INPUT'] = $raw;

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
			if ($code) $status = $code;

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

		// Issue #14: SCRIPT_NAME must keep its directory segments, and
		// SERVER_PORT must come from the request rather than the listen port.
		// Same normalisation as Q_WebServer::dispatchToQ(): realpath() both
		// sides so a symlinked docroot cannot eat a segment.
		$rootDir = Q_WebServer::$rootDir ?? '';
		$docRoot = rtrim(realpath($rootDir) ?: $rootDir, DS);
		$scriptReal = realpath($scriptPath) ?: $scriptPath;
		if ($docRoot !== ''
		and strncmp($scriptReal, $docRoot, strlen($docRoot)) === 0) {
			$scriptName = '/' . ltrim(
				str_replace(DS, '/', substr($scriptReal, strlen($docRoot))), '/'
			);
		} else {
			$scriptName = '/' . basename($scriptPath);
		}
		// The master is a CLI process, so $_SERVER['SERVER_PORT'] is unset there
		// and every worker used to receive the literal '8080'. Take the port
		// from the Host header, falling back to the scheme default.
		$hostParts = explode(':', $parsed['headers']['host'] ?? 'localhost');
		if (isset($hostParts[1])) {
			$serverPort = (string) $hostParts[1];
		} else {
			$scheme = $parsed['_scheme'] ?? 'http';
			$serverPort = ($scheme === 'https') ? '443' : '80';
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
			'scriptName'     => $scriptName,
			'documentRoot'   => Q_WebServer::$rootDir ?? '',
			'serverPort'     => $serverPort,
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
		if ($response && $client && is_resource($client)) {
			$this->sendHttp($client, $response, $index);
		}

		// In octane mode the worker is still alive — mark it idle so it
		// can receive the next request. In classic mode, recycle it (the
		// child exited after one request).
		if ($this->octane) {
			$this->workers[$index]['busy'] = false;
			$this->workerBuffers[$index] = '';
			unset($this->workerClients[$index]);
			// CANCEL the readable watcher entirely. stream_select reports a
			// Unix socket pair as readable whenever the other end is alive
			// (fread returns '' rather than blocking), so a disabled-but-
			// registered watcher fires endlessly. sendTo() creates a fresh
			// one-shot watcher for the next dispatch.
			if (isset($this->watchers[$index])) {
				Q_Evented::cancel($this->watchers[$index]);
				unset($this->watchers[$index]);
			}
			// Process any pending requests
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
			pcntl_waitpid($this->workers[$index]['pid'], $st, WNOHANG);
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
				$result = pcntl_waitpid($w['pid'], $st, WNOHANG);
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
			pcntl_waitpid($w['pid'], $st, 0);
		}

		$this->workers = array();
	}

	function idleCount()
	{
		$n = 0;
		foreach ($this->workers as $w) if (!$w['busy']) $n++;
		return $n;
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
