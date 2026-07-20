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
			// ── CHILD: wait for one request, handle, exit ──
			fclose($pair[0]);
			self::childRun($pair[1]);
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
	 * Child: block on socket, read one request, execute, respond, die.
	 */
	protected static function childRun($socket)
	{
		stream_set_blocking($socket, true);

		// Read length-prefixed request
		$hdr = self::readExact($socket, 4);
		if ($hdr === false) return;
		$len = unpack('N', $hdr)[1];
		if ($len > 10485760) return;
		$json = self::readExact($socket, $len);
		if ($json === false) return;
		$req = json_decode($json, true);
		if (!$req) {
			self::writeMsg($socket, 500, 'Bad message', array());
			return;
		}

		// Execute the PHP script
		$resp = self::executeScript($req);
		self::writeMsg($socket, $resp['status'], $resp['body'], $resp['headers']);
		fclose($socket);
	}

	/**
	 * Set up superglobals and include the PHP script.
	 * The script (index.php, action.php, etc.) internally calls
	 * Q_WebController::execute() or Q_ActionController::execute().
	 */
	protected static function executeScript($req)
	{
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

		$_GET = $_POST = $_REQUEST = array();
		if (!empty($req['query'])) parse_str($req['query'], $_GET);

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

		ob_start();
		$status = 200;
		$headers = array();
		try {
			include($req['scriptFilename']);
			foreach (headers_list() as $h) {
				if (strpos($h, ':') !== false) {
					list($k, $v) = explode(':', $h, 2);
					$headers[trim($k)] = trim($v);
				}
			}
			$code = http_response_code();
			if ($code) $status = $code;
		} catch (\Throwable $e) {
			$status = 500;
			ob_clean();
			echo $e->getMessage();
		}
		$body = ob_get_clean();
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
		Q_Evented::enable($this->watchers[$index]);

		$msg = json_encode(array(
			'method'         => $parsed['method'],
			'uri'            => $parsed['uri'],
			'path'           => $parsed['path'],
			'query'          => $parsed['query'],
			'headers'        => $parsed['headers'],
			'body'           => $parsed['body'],
			'scriptFilename' => $scriptPath,
			'scriptName'     => '/' . basename($scriptPath),
			'documentRoot'   => Q_WebServer::$rootDir ?? '',
			'serverPort'     => (string)($_SERVER['SERVER_PORT'] ?? '8080'),
			'remoteAddr'     => '127.0.0.1'
		));
		fwrite($this->workers[$index]['socket'], pack('N', strlen($msg)) . $msg);
	}

	/**
	 * Called when data or EOF arrives from a worker.
	 */
	function onWorkerData($index, $sock)
	{
		$chunk = @fread($sock, 65536);

		if ($chunk === false || $chunk === '') {
			// Worker exited (expected after one request)
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

		$this->recycle($index, false);
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
			@fclose($this->workerClients[$index]);
		}

		if (isset($this->workers[$index])) {
			@fclose($this->workers[$index]['socket']);
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
			@fclose($w['socket']);
		}

		// Send SIGTERM to all workers
		foreach ($this->workers as $w) {
			posix_kill($w['pid'], SIGTERM);
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
			posix_kill($w['pid'], SIGKILL);
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
