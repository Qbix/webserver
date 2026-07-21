<?php
/**
 * @module Q
 */

/**
 * Pure-PHP web server for Qbix apps.
 *
 * Serves static files with readfile(), ETag/304, companion .headers.
 * Routes .php scripts to pre-forked workers (Q_WebServer_Pool).
 * Upgrades WebSocket connections via Q_WebSocket.
 * Responsive directory listings with media previews.
 * Runs entirely on Q_Evented.
 *
 * @class Q_WebServer
 */
class Q_WebServer
{
	/** @property $pool Q_WebServer_Pool|null */
	static $pool = null;
	/** @property $rootDir Document root with trailing DS */
	public static $rootDir;
	/** @property $host Bound host */
	public static $host;
	/** @property $port Bound port */
	public static $port;
	/** @property $onRequest Logging callback(method, uri, status, ms) */
	static $onRequest = null;

	// ── Lifecycle ────────────────────────────────────────

	/**
	 * @method start
	 * @static
	 * @param {string} $dir Document root
	 * @param {string} [$host='0.0.0.0']
	 * @param {int} [$port=8080]
	 * @param {int} [$workers=0] 0=in-process, N=prefork pool
	 */
	static function start($dir, $host = '0.0.0.0', $port = 8080, $workers = 0)
	{
		if (self::$running) {
			throw new Exception("Q_WebServer already running");
		}

		// Ignore SIGPIPE — prevents crash when writing to a closed socket
		// (e.g. client disconnects, or worker dies mid-response)
		if (function_exists('pcntl_signal')) {
			pcntl_signal(SIGPIPE, SIG_IGN);
		}

		$root = realpath($dir);
		if (!$root || !is_dir($root)) {
			throw new Exception("Invalid document root: $dir");
		}
		self::$rootDir = rtrim(str_replace(array('/','\\'), DS, $root), DS) . DS;
		self::$host = $host;
		self::$port = $port;

		if ($ext = Q_Config::get('Q', 'webserver', 'extensions', null)) {
			self::$allowedExtensions = $ext;
		}

		// File response cache config
		self::$fileCacheMaxSize = Q_Config::get('Q', 'webserver', 'fileCache', 'maxSize', 67108864);
		self::$fileCacheMaxFile = Q_Config::get('Q', 'webserver', 'fileCache', 'maxFile', 1048576);
		self::$fileCacheCheckInterval = Q_Config::get('Q', 'webserver', 'fileCache', 'checkInterval', 1);

		// ── HTTP listener ────────────────────────────────
		$errno = $errstr = 0;
		self::$socket = stream_socket_server(
			"tcp://{$host}:{$port}", $errno, $errstr,
			STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
		);
		if (!self::$socket) {
			throw new Exception("Could not bind to {$host}:{$port} — $errstr");
		}
		stream_set_blocking(self::$socket, false);
		self::$acceptWatcher = Q_Evented::onReadable(
			self::$socket, array(__CLASS__, 'onAccept')
		);

		// ── HTTPS listener (if certs configured) ─────────
		$httpsConfig = Q_Config::get('Q', 'web', 'https', array());
		$httpsPort = (int) Q::ifset($httpsConfig, 'port', 0);
		if ($httpsPort || Q::ifset($httpsConfig, 'mode', '')) {
			if (!$httpsPort) $httpsPort = 443;
			self::$httpsPort = $httpsPort;

			$domain = Q::ifset($httpsConfig, 'domain', '');
			$certsReady = Q_WebServer_Certs::init($domain);

			if ($certsReady) {
				self::startTls($host, $httpsPort);
			} else {
				echo "[HTTPS] No valid certs yet, HTTPS disabled. "
					. "HTTP still running on port $port.\n";
			}
		}

		// ── Preload classes (before forking) ─────────────
		$preload = Q_Config::get('Q', 'webserver', 'preload', array());
		if (!empty($preload)) {
			// Load the autoloader first (e.g. Composer's)
			$autoload = is_string($preload)
				? $preload
				: (isset($preload['autoload']) ? $preload['autoload'] : null);
			if ($autoload) {
				$autoloadPath = $autoload;
				// Resolve relative to the document root's parent (project root)
				if ($autoloadPath[0] !== '/' && $autoloadPath[0] !== '\\') {
					$projectRoot = dirname(rtrim(self::$rootDir, DS));
					$autoloadPath = $projectRoot . DS . $autoloadPath;
				}
				if (file_exists($autoloadPath)) {
					require_once $autoloadPath;
					$count = count(get_declared_classes());
					echo "  Autoloader: " . basename($autoload) . "\n";
				} else {
					echo "  Warning: autoload file not found: $autoloadPath\n";
				}
			}
			// Then load each named class (triggers the autoloader)
			$classes = isset($preload['classes']) ? $preload['classes'] : array();
			if (!empty($classes)) {
				$loaded = 0;
				foreach ($classes as $class) {
					if (!class_exists($class, true) && !interface_exists($class, true)
						&& !trait_exists($class, true)
					) {
						echo "  Warning: could not preload $class\n";
					} else {
						$loaded++;
					}
				}
				echo "  Preloaded: $loaded classes\n";
			}
		}

		// ── Worker pool ──────────────────────────────────
		if ($workers > 0 && function_exists('pcntl_fork')) {
			self::$pool = new Q_WebServer_Pool($workers);
		}

		self::$running = true;
	}

	/**
	 * Start or restart the TLS listener.
	 * Uses tcp:// + stream_socket_enable_crypto() for non-blocking
	 * TLS handshake. The handshake happens per-connection in the
	 * event loop, not during accept.
	 *
	 * @method startTls
	 * @static
	 */
	static function startTls($host, $port)
	{
		if (self::$tlsWatcher) {
			Q_Evented::cancel(self::$tlsWatcher);
			self::$tlsWatcher = null;
		}
		if (self::$tlsSocket) {
			@fclose(self::$tlsSocket);
			self::$tlsSocket = null;
		}

		if (!Q_WebServer_Certs::validateCerts()) return;

		// Listen on plain tcp:// — TLS handshake happens after accept
		$errno = $errstr = 0;
		self::$tlsSocket = stream_socket_server(
			"tcp://{$host}:{$port}", $errno, $errstr,
			STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
		);
		if (!self::$tlsSocket) {
			echo "[HTTPS] Could not bind to {$host}:{$port} — $errstr\n";
			return;
		}
		stream_set_blocking(self::$tlsSocket, false);

		self::$tlsWatcher = Q_Evented::onReadable(
			self::$tlsSocket,
			function ($sock) { Q_WebServer::onAcceptTls($sock); }
		);
		echo "[HTTPS] Listening on https://{$host}:{$port}\n";
	}

	/**
	 * Accept a connection on the TLS port and begin
	 * non-blocking crypto handshake.
	 *
	 * @method onAcceptTls
	 * @static
	 */
	static function onAcceptTls($serverSocket)
	{
		$client = @stream_socket_accept($serverSocket, 0);
		if (!$client) return;

		stream_set_blocking($client, false);

		// Set SSL context options on this specific socket
		$certPath = Q_WebServer_Certs::$certPath;
		$keyPath = Q_WebServer_Certs::$keyPath;
		stream_context_set_option($client, 'ssl', 'local_cert', $certPath);
		stream_context_set_option($client, 'ssl', 'local_pk', $keyPath);
		stream_context_set_option($client, 'ssl', 'allow_self_signed', true);
		stream_context_set_option($client, 'ssl', 'verify_peer', false);

		$key = (int) $client;
		self::$clients[$key] = $client;
		self::$buffers[$key] = '';
		self::$tlsPending[$key] = true;

		// Start the handshake — may need multiple attempts
		self::continueTlsHandshake($key);
	}

	/**
	 * Continue a non-blocking TLS handshake.
	 * stream_socket_enable_crypto() returns:
	 *   true  → handshake complete
	 *   false → handshake failed
	 *   0     → handshake in progress, try again
	 *
	 * @method continueTlsHandshake
	 * @static
	 */
	static function continueTlsHandshake($key)
	{
		if (!isset(self::$clients[$key])) return;
		$client = self::$clients[$key];

		$cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;
		if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')) {
			$cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;
		}

		$result = @stream_socket_enable_crypto($client, true, $cryptoMethod);

		if ($result === true) {
			// Handshake complete — treat like a normal client
			unset(self::$tlsPending[$key]);
			self::$clientWatchers[$key] = Q_Evented::onReadable(
				$client,
				function ($c) { Q_WebServer::onClientData($c); }
			);
		} elseif ($result === 0) {
			// In progress — watch for readability to retry
			self::$clientWatchers[$key] = Q_Evented::onReadable(
				$client,
				function ($c) {
					$k = (int) $c;
					// Cancel this watcher and retry handshake
					if (isset(Q_WebServer::$clientWatchers[$k])) {
						Q_Evented::cancel(Q_WebServer::$clientWatchers[$k]);
						unset(Q_WebServer::$clientWatchers[$k]);
					}
					Q_WebServer::continueTlsHandshake($k);
				}
			);
		} else {
			// Failed
			self::closeClient($key);
		}
	}

	/**
	 * Reload TLS after cert renewal. Called by Q_WebServer_Certs.
	 * New connections will use the new certs. Existing connections
	 * keep their old certs until they close (normal behavior).
	 *
	 * @method reloadTls
	 * @static
	 */
	static function reloadTls()
	{
		if (self::$httpsPort) {
			// No need to restart the listener — we set SSL context
			// per-connection in onAcceptTls, so new connections
			// will pick up the new cert files automatically.
			echo "[HTTPS] Certificates reloaded for new connections.\n";
		}
	}

	/**
	 * Graceful shutdown: stop accepting new connections,
	 * wait for in-flight requests to complete (up to timeout),
	 * then close everything.
	 * @method stop
	 * @static
	 * @param {float} $drainTimeout Max seconds to wait for in-flight requests
	 */
	static function stop($drainTimeout = 5.0)
	{
		if (!self::$running) return;
		self::$running = false;

		// 1. Stop accepting new connections
		if (self::$acceptWatcher) {
			Q_Evented::cancel(self::$acceptWatcher);
			self::$acceptWatcher = null;
		}
		if (self::$tlsWatcher) {
			Q_Evented::cancel(self::$tlsWatcher);
			self::$tlsWatcher = null;
		}
		if (self::$socket) { @fclose(self::$socket); self::$socket = null; }
		if (self::$tlsSocket) { @fclose(self::$tlsSocket); self::$tlsSocket = null; }

		// 2. Wait for in-flight connections to drain (up to timeout)
		$deadline = microtime(true) + $drainTimeout;
		while (!empty(self::$clients) && microtime(true) < $deadline) {
			Q_Evented::tick(0.1); // process pending I/O briefly
		}

		// 3. Force-close remaining connections
		foreach (self::$timeoutWatchers as $id) Q_Evented::cancel($id);
		self::$timeoutWatchers = array();
		foreach (self::$clientWatchers as $id) Q_Evented::cancel($id);
		self::$clientWatchers = array();
		foreach (self::$clients as $c) @fclose($c);
		self::$clients = array();
		self::$buffers = array();
		self::$clientInfo = array();
		self::$keepAliveCount = array();

		// 4. Disconnect WebSockets
		Q_WebSocket::disconnectAll();

		// 5. Gracefully shut down worker pool (SIGTERM → wait → SIGKILL)
		if (self::$pool) { self::$pool->shutdown(); self::$pool = null; }
	}

	static function run()
	{
		if (!self::$running) return;
		if (function_exists('pcntl_signal')) {
			Q_Evented::onSignal(SIGINT, function () {
				echo "\n  Graceful shutdown (SIGINT)...\n";
				self::stop();
				Q_Evented::stop();
			});
			Q_Evented::onSignal(SIGTERM, function () {
				echo "\n  Graceful shutdown (SIGTERM)...\n";
				self::stop();
				Q_Evented::stop();
			});
			// Reap zombie children from fork-per-request PHP execution
			Q_Evented::onSignal(SIGCHLD, function () {
				while (($pid = pcntl_waitpid(-1, $st, WNOHANG)) > 0) {
					unset(Q_WebServer::$workerPids[$pid]);
				}
			});
		}
		// Engine.IO ping timer — keeps Socket.IO connections alive
		Q_Evented::repeat(25, function () {
			Q_WebSocket::pingSocketIO();
		});

		// Request timeout — kill workers that exceed the configured limit
		$timeout = Q_Config::get('Q', 'webserver', 'requestTimeout', 30);
		if ($timeout > 0) {
			Q_Evented::repeat(1, function () use ($timeout) {
				$now = microtime(true);
				foreach (Q_WebServer::$workerPids as $pid => $start) {
					if ($now - $start > $timeout) {
						@posix_kill($pid, SIGKILL);
						unset(Q_WebServer::$workerPids[$pid]);
					}
				}
			});
		}

		// Scheduler — run tasks on intervals or at specific times
		$schedule = Q_Config::get('Q', 'scheduler', array());
		if (!empty($schedule)) {
			Q_Scheduler::init($schedule);
			Q_Evented::repeat(1, function () {
				Q_Scheduler::tick();
			});
		}

		Q_Evented::run();
	}

	// ── Connection handling ──────────────────────────────

	static function onAccept($socket)
	{
		// Max connections check (cached)
		static $maxConn = null;
		if ($maxConn === null) $maxConn = Q_Config::get('Q', 'webserver', 'maxConnections', 1024);
		if (count(self::$clients) >= $maxConn) {
			$reject = @stream_socket_accept($socket, 0);
			if ($reject) {
				@fwrite($reject, "HTTP/1.1 503 Service Unavailable\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
				@fclose($reject);
			}
			return;
		}

		$client = @stream_socket_accept($socket, 0);
		if (!$client) return;
		stream_set_blocking($client, false);
		// Disable Nagle's algorithm — eliminates 40ms delayed ACK on keep-alive
		if (function_exists('socket_import_stream')) {
			$rawSocket = socket_import_stream($client);
			if ($rawSocket) {
				socket_set_option($rawSocket, SOL_TCP, TCP_NODELAY, 1);
			}
		}
		$key = (int) $client;
		self::$clients[$key] = $client;
		self::$buffers[$key] = '';
		self::$keepAliveCount[$key] = 0;

		// Store remote IP for logging + proxy resolution
		$peer = stream_socket_get_name($client, true);
		$ip = $peer ? explode(':', $peer)[0] : '0.0.0.0';
		self::$clientInfo[$key] = array(
			'ip' => $ip,
			'connectTime' => microtime(true)
		);

		// Rate limit check
		if (!self::checkRateLimit($ip)) {
			@fwrite($client, "HTTP/1.1 429 Too Many Requests\r\n"
				. "Retry-After: 60\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
			@fclose($client);
			unset(self::$clients[$key], self::$buffers[$key], self::$keepAliveCount[$key],
				self::$clientInfo[$key]);
			return;
		}

		self::$clientWatchers[$key] = Q_Evented::onReadable(
			$client, function ($c) { Q_WebServer::onClientData($c); }
		);

		// Read timeout — close if no complete request within N seconds
		static $readTimeout = null;
		if ($readTimeout === null) $readTimeout = (float) Q_Config::get('Q', 'webserver', 'timeout', 'read', 30);
		self::$timeoutWatchers[$key] = Q_Evented::delay($readTimeout, function () use ($key) {
			Q_WebServer::closeClient($key);
		});
	}

	static function onClientData($client)
	{
		$key = (int) $client;
		if (!isset(self::$clients[$key])) return;

		// Check if we already have a complete request from pipelining
		$buf = self::$buffers[$key] ?? '';
		$havePipelined = ($buf !== '' && strpos($buf, "\r\n\r\n") !== false);

		if (!$havePipelined) {
			$chunk = @fread($client, 65536);
			if ($chunk === false || $chunk === '') {
				self::closeClient($key);
				return;
			}
			self::$buffers[$key] .= $chunk;
			$buf = self::$buffers[$key];
		}

		// Wait for complete headers
		$headerEnd = strpos($buf, "\r\n\r\n");
		if ($headerEnd === false) {
			if (strlen($buf) > 65536) self::closeClient($key);
			return;
		}

		// Wait for complete body on POST/PUT/PATCH
		$firstChar = $buf[0];
		if ($firstChar === 'P') { // POST, PUT, PATCH all start with P
			$cl = 0;
			if (preg_match('/content-length:\s*(\d+)/i', $buf, $m)) {
				$cl = (int) $m[1];
			}
			if ($cl > 10485760) {
				self::sendResponse($client, 413, 'Payload Too Large');
				self::closeClient($key);
				return;
			}
			if (strlen($buf) - $headerEnd - 4 < $cl) return;
		}

		// Cancel read timeout (request received)
		if (isset(self::$timeoutWatchers[$key])) {
			Q_Evented::cancel(self::$timeoutWatchers[$key]);
			unset(self::$timeoutWatchers[$key]);
		}

		// Calculate consumed bytes for pipelining support
		$headerEnd = strpos($buf, "\r\n\r\n");
		$bodyLen = 0;
		$firstChar = $buf[0];
		if ($firstChar === 'P') { // POST/PUT/PATCH
			if (preg_match('/content-length:\s*(\d+)/i', $buf, $clm)) {
				$bodyLen = (int) $clm[1];
			}
		}
		$consumed = $headerEnd + 4 + $bodyLen;

		$start = microtime(true);
		$parsed = self::parseRequest($buf);

		// Reject malformed request lines
		if (!empty($parsed['_malformed'])) {
			self::sendResponse($client, 400, 'Bad Request');
			self::closeClient($key);
			return;
		}

		// Reject oversized headers (>64KB total)
		$headerEnd = strpos($buf, "\r\n\r\n");
		if ($headerEnd > 65536) {
			self::sendResponse($client, 431, 'Request Header Fields Too Large',
				'text/plain; charset=utf-8', array('Connection' => 'close'));
			self::closeClient($key);
			return;
		}

		// Resolve proxy headers for real client IP
		$directIp = self::$clientInfo[$key]['ip'] ?? '0.0.0.0';
		$parsed['clientIp'] = Q_WebServer_Proxy::clientIp($directIp, $parsed['headers']);
		$parsed['_remoteAddr'] = $parsed['clientIp'];
		$peer = stream_socket_get_name($client, true);
		$parsed['_remotePort'] = $peer ? (int) substr(strrchr($peer, ':'), 1) : 0;

		// Determine keep-alive before handling request
		static $maxKeepAlive = null;
		if ($maxKeepAlive === null) $maxKeepAlive = (int) Q_Config::get('Q', 'webserver', 'keepAlive', 'max', 100);
		$connHeader = strtolower($parsed['headers']['connection'] ?? 'keep-alive');
		self::$keepAliveCount[$key] = (self::$keepAliveCount[$key] ?? 0) + 1;
		$parsed['_keepAlive'] = ($connHeader !== 'close')
			&& self::$keepAliveCount[$key] < $maxKeepAlive;

		try {
			$keepOpen = self::handleRequest($client, $parsed);
		} catch (\Throwable $e) {
			// Never let a request crash the event loop
			$msg = htmlspecialchars($e->getMessage());
			self::sendResponse($client, 500, "Internal Server Error: $msg");
			self::closeClient($key);
			$ms = round((microtime(true) - $start) * 1000, 1);
			Q_WebServer_Dashboard::recordRequest(
				$parsed['method'] ?? 'GET', $parsed['uri'] ?? '/', 500, $ms
			);
			if (self::$onRequest) {
				(self::$onRequest)($parsed['method'] ?? 'GET', $parsed['uri'] ?? '/', 500, $ms);
			}
			return;
		}
		$ms = round((microtime(true) - $start) * 1000, 1);

		if ($keepOpen) {
			// WebSocket upgraded — Q_WebSocket owns this socket now
			if (isset(self::$clientWatchers[$key])) {
				Q_Evented::cancel(self::$clientWatchers[$key]);
			}
			unset(self::$clientWatchers[$key], self::$clients[$key],
				self::$buffers[$key], self::$clientInfo[$key],
				self::$keepAliveCount[$key]);
			return;
		}

		// Stats + logging
		Q_WebServer_Dashboard::recordRequest(
			$parsed['method'], $parsed['uri'], self::$lastStatus, $ms
		);
		if (self::$onRequest) {
			(self::$onRequest)($parsed['method'], $parsed['uri'], self::$lastStatus, $ms);
		}

		// Log to file
		$bodyLen = strlen(self::$lastBody ?? '');
		Q_WebServer_Log::access(
			$parsed['clientIp'], $parsed['method'], $parsed['uri'],
			self::$lastStatus, $bodyLen,
			$parsed['headers']['referer'] ?? '',
			$parsed['headers']['user-agent'] ?? '',
			$ms
		);

		// ── Keep-alive decision ──────────────────────────
		static $keepAliveTimeout = null;
		if ($keepAliveTimeout === null) $keepAliveTimeout = (float) Q_Config::get('Q', 'webserver', 'keepAlive', 'timeout', 15);
		$shouldKeepAlive = !empty($parsed['_keepAlive']) && self::$lastStatus < 500;

		if ($shouldKeepAlive) {
			// Keep leftover data for pipelined requests
			$leftover = strlen($buf) > $consumed ? substr($buf, $consumed) : '';
			self::$buffers[$key] = $leftover;

			// Set idle timeout — close if no new request arrives
			self::$timeoutWatchers[$key] = Q_Evented::delay(
				$keepAliveTimeout,
				function () use ($key) {
					Q_WebServer::closeClient($key);
				}
			);

			// If there's already a complete request in the buffer, process it now
			if ($leftover !== '' && strpos($leftover, "\r\n\r\n") !== false) {
				Q_Evented::defer(function () use ($client) {
					Q_WebServer::onClientData($client);
				});
			}
		} else {
			self::closeClient($key);
		}
	}

	// ── Request routing ──────────────────────────────────

	/**
	 * Route a parsed request and return a response array.
	 *
	 * This is the clean interface that external HTTP drivers
	 * (like amphp/http-server) call. Handles all routing:
	 * blocked paths, static files, PHP dispatch, directory
	 * listings, X-Accel-Redirect, compression.
	 *
	 * The built-in server uses handleRequest() which writes
	 * directly to sockets. amphp calls route() and converts
	 * the response to its own format.
	 *
	 * @method route
	 * @static
	 * @param {array} $parsed [method, uri, path, query, headers, body, clientIp]
	 * @return {array} [status, headers, body]
	 */
	static function route($parsed)
	{
		$method = $parsed['method'];
		$path = $parsed['path'];

		// Reverse cache check (before any dispatch)
		$cached = Q_WebServer_Cache::get($parsed);
		if ($cached) return $cached;

		if ($path === '/Q/health') {
			$stats = Q_WebServer_Dashboard::getStats();
			return array('status'=>200, 'body'=>json_encode(array('status'=>'ok')+$stats),
				'headers'=>array('Content-Type'=>'application/json'));
		}
		if ($path === '/Q/dashboard' || $path === '/Q/dashboard/') {
			return array('status'=>200, 'body'=>Q_WebServer_Dashboard::renderHtml($parsed),
				'headers'=>array('Content-Type'=>'text/html; charset=utf-8'));
		}
		if (self::isBlocked($path)) {
			return array('status'=>403, 'body'=>'Forbidden',
				'headers'=>array('Content-Type'=>'text/plain'));
		}

		$fsPath = self::resolveStatic($path);

		// Directory
		if ($fsPath && is_dir($fsPath)) {
			if (substr($path, -1) !== '/') {
				return array('status'=>301, 'body'=>'',
					'headers'=>array('Location'=>$path.'/'));
			}
			foreach (array('index.html','index.php') as $idx) {
				$ip = $fsPath.DS.$idx;
				if (is_file($ip)) { $fsPath = $ip; break; }
			}
			if (is_dir($fsPath)) {
				if (self::isIndexed($path)) {
					return array('status'=>200,
						'body'=>self::renderDirectoryListing($fsPath, $path),
						'headers'=>array('Content-Type'=>'text/html; charset=utf-8',
							'Cache-Control'=>'no-store'));
				}
				return array('status'=>403, 'body'=>'Forbidden',
					'headers'=>array('Content-Type'=>'text/plain'));
			}
		}

		// File
		if ($fsPath && is_file($fsPath)) {
			$ext = strtolower(pathinfo($fsPath, PATHINFO_EXTENSION));

			if ($ext === 'php') {
				// PHP dispatch (in-process — amphp uses fibers for concurrency)
				$response = self::dispatchToQ($parsed);
				$response = self::processPhpResponse($response, $parsed['headers']);
				Q_WebServer_Cache::put($parsed, $response);
				return $response;
			}

			if (in_array($ext, self::$allowedExtensions)
				&& ($method === 'GET' || $method === 'HEAD')
			) {
				return self::buildFileResponse($fsPath, $ext, $method, $parsed['headers']);
			}
		}

		// Clean URL → index.php
		if (is_file(self::$rootDir . 'index.php')) {
			$response = self::dispatchToQ($parsed);
			$response = self::processPhpResponse($response, $parsed['headers']);
			Q_WebServer_Cache::put($parsed, $response);
			return $response;
		}

		return array('status'=>404, 'body'=>self::render404($path),
			'headers'=>array('Content-Type'=>'text/html; charset=utf-8'));
	}

	/**
	 * Process a PHP response: X-Accel-Redirect + compression.
	 * Used by both route() and handlePhp().
	 */
	static function processPhpResponse($response, $reqHeaders)
	{
		$headers = Q_WebServer_Headers::stripInternal($response['headers'] ?? array());
		$body = $response['body'] ?? '';

		// X-Accel-Redirect
		foreach ($response['headers'] ?? array() as $k => $v) {
			if (strtolower($k) === 'x-accel-redirect') {
				$af = Q_WebServer_Headers::resolveAccelPath($v);
				if ($af && is_file($af)) {
					$body = file_get_contents($af);
					$ext = strtolower(pathinfo($af, PATHINFO_EXTENSION));
					if (!Q_WebServer_Headers::hasHeader($headers, 'Content-Type')) {
						$headers['Content-Type'] = self::mimeType($ext);
					}
				}
				$headers = Q_WebServer_Headers::stripInternal($headers);
				break;
			}
		}

		$ct = '';
		foreach ($headers as $k => $v) {
			if (strtolower($k) === 'content-type') $ct = $v;
		}
		$body = Q_WebServer_Headers::maybeCompress($body, $ct, $reqHeaders, $headers);
		return array('status'=>$response['status']??200, 'body'=>$body, 'headers'=>$headers);
	}

	/**
	 * Build a static file response with ETag/compression.
	 * Used by route() for amphp compatibility.
	 */
	static function buildFileResponse($fsPath, $ext, $method, $reqHeaders)
	{
		clearstatcache(true, $fsPath);
		$mtime = filemtime($fsPath);
		$size = filesize($fsPath);
		$ct = self::mimeType($ext);
		$headers = array(
			'Content-Type' => $ct,
			'ETag' => '"' . dechex($mtime) . '-' . dechex($size) . '"',
			'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
			'Cache-Control' => 'public, max-age=0, must-revalidate'
		);
		$body = ($method === 'HEAD') ? '' : file_get_contents($fsPath);
		if ($method !== 'HEAD') {
			$body = Q_WebServer_Headers::maybeCompress($body, $ct, $reqHeaders, $headers);
		}
		return array('status'=>200, 'body'=>$body, 'headers'=>$headers);
	}

	// ── Built-in server: socket-based routing ────────────

	/**
	 * Route a request (built-in server).
	 * Returns true if the connection should stay open (WebSocket).
	 * @return {boolean}
	 */
	private static function handleRequest($client, $parsed)
	{
		$method = $parsed['method'];
		$path = $parsed['path'];

		// 1. Dashboard + Panel + WebSocket + Health (/Q/*)
		// 1. Serve client JS files (before /Q/ check — socket.io.js is at /socket.io/)
		$jsMap = array();
		$jsPath = Q_Config::get('Q', 'socket', 'js', '/Q/socket.js');
		if ($jsPath !== false) $jsMap[$jsPath] = __DIR__ . DS . 'socket.js';
		$ioPath = Q_Config::get('Q', 'socket', 'io', '/socket.io');
		if ($ioPath !== false) $jsMap[$ioPath . '/socket.io.js'] = __DIR__ . DS . 'socket.io.js';
		if (isset($jsMap[$path])) {
			$jsFile = $jsMap[$path];
			if (file_exists($jsFile)) {
				self::sendResponse($client, 200, file_get_contents($jsFile),
					'application/javascript',
					array('Cache-Control' => 'public, max-age=3600'));
			} else {
				self::sendResponse($client, 404, 'Not found');
			}
			return false;
		}

		// 2. Dashboard + Panel + WebSocket + Health (/Q/*)
		if (strpos($path, '/Q/') === 0) {
			if ($path === '/Q/ws') {
				$upgraded = Q_WebSocket::upgrade(
					$client, $parsed['headers'], null, 'dashboard'
				);
				return $upgraded; // true = keep open
			}
			if ($path === '/Q/health') {
				$stats = Q_WebServer_Dashboard::getStats();
				self::sendResponse($client, 200,
					json_encode(array('status' => 'ok') + $stats),
					'application/json');
				return false;
			}
			// Panel (control panel + API)
			$handled = Q_WebServer_Panel::handle($client, $parsed);
			if ($handled) return false;
			// Dashboard (live stats)
			$handled = Q_WebServer_Dashboard::handle($client, $parsed);
			if ($handled) return false;
		}

		// 2. WebSocket upgrade on any path
		$upgrade = strtolower($parsed['headers']['upgrade'] ?? '');
		if ($upgrade === 'websocket' && $path !== '/Q/ws') {
			$upgraded = Q_WebSocket::upgrade(
				$client, $parsed['headers'],
				function ($sk, $msg) use ($path) {
					Q_WebSocket::dispatchEvent($sk, $msg, $path);
				},
				null, $path
			);
			return $upgraded;
		}

		// 3. Blocked paths
		if (self::isBlocked($path)) {
			self::sendResponse($client, 403, 'Forbidden');
			return false;
		}

		// 3. Component cache check (Merkle tree — serves from cached slots)
		if (Q_WebServer_Cache_Components::enabled()) {
			$pageKey = $parsed['path'] . '?' . ($parsed['query'] ?? '');
			$cachedPage = Q_WebServer_Cache_Components::getPage($pageKey);
			if ($cachedPage !== null) {
				self::sendResponse($client, 200, $cachedPage,
					'text/html; charset=utf-8',
					array('X-Cache' => 'HIT-COMPONENTS'));
				return false;
			}
		}

		// 4. Reverse cache check (before forking a worker)
		$cached = Q_WebServer_Cache::get($parsed);
		if ($cached) {
			self::sendResponse($client, $cached['status'],
				$cached['body'], $cached['headers']['Content-Type'] ?? 'text/html',
				$cached['headers']);
			return false;
		}

		// 4. Resolve filesystem path
		$fsPath = self::resolveStatic($path);

		// 4. Directory handling
		if ($fsPath && is_dir($fsPath)) {
			if (substr($path, -1) !== '/') {
				self::sendRedirect($client, $path . '/');
				return false;
			}
			// Check for index files
			foreach (array('index.html', 'index.php') as $idx) {
				$indexPath = $fsPath . DS . $idx;
				if (is_file($indexPath)) {
					$fsPath = $indexPath;
					break;
				}
			}
			if (is_dir($fsPath)) {
				// No index file → check if listings are enabled for this path
				if (self::isIndexed($path)) {
					$html = self::renderDirectoryListing($fsPath, $path);
					self::sendResponse($client, 200, $html, 'text/html; charset=utf-8',
						array('Cache-Control' => 'no-store'));
				} else {
					self::sendResponse($client, 403, 'Forbidden');
				}
				return false;
			}
		}

		// 5. File handling
		if ($fsPath && is_file($fsPath)) {
			$ext = strtolower(pathinfo($fsPath, PATHINFO_EXTENSION));

			// PHP scripts → worker pool or in-process
			if ($ext === 'php') {
				return self::handlePhp($client, $parsed, $fsPath);
			}

			// Static file
			if ($method === 'GET' || $method === 'HEAD') {
				self::serveStaticFile($client, $fsPath, $method, $parsed['headers'], !empty($parsed['_keepAlive']));
				return false;
			}
		}

		// 6. Route dispatch — if Q.routes configured, match URL to handler
		//    Q_Uri caches compiled patterns and path→URI results in memory.
		static $routingEnabled = null;
		if ($routingEnabled === null) {
			$routingEnabled = Q_Config::get('Q', 'routes', null) !== null
				&& class_exists('Q_Uri', true);
		}
		if ($routingEnabled) {
			$uri = Q_Uri::fromPath($path);
			if ($uri && !empty($uri->module) && !empty($uri->action)) {
				return self::handleRoute($client, $parsed, $uri);
			}
		}

		// 7. Clean URL → route through index.php (if exists)
		$indexPhp = self::$rootDir . 'index.php';
		if (is_file($indexPhp)) {
			return self::handlePhp($client, $parsed, $indexPhp);
		}

		// 8. Configurable fallback (SPA routing, custom 404 page, etc.)
		//    Q.webserver.fallback can be:
		//    - string: path to a static file relative to web/ (e.g. "index.html")
		//    - object with "handler": event name to dispatch via Q::event()
		//    - object with "file": static file + auto-detect Content-Type
		static $fallback = null;
		if ($fallback === null) $fallback = Q_Config::get('Q', 'webserver', 'fallback', null);
		if ($fallback !== null) {
			if (is_string($fallback)) {
				// Static file (SPA catch-all: serve index.html for all routes)
				$fbPath = self::$rootDir . str_replace('/', DS, $fallback);
				if (is_file($fbPath)) {
					return self::serveFile($client, $parsed, $fbPath);
				}
			} elseif (is_array($fallback)) {
				if (!empty($fallback['handler'])) {
					// Route to a handler — build a synthetic Q_Uri
					$uri = Q_Uri::from(array(
						'module' => dirname($fallback['handler']),
						'action' => basename($fallback['handler']),
						'_originalPath' => $path,
					));
					if ($uri) {
						return self::handleRoute($client, $parsed, $uri);
					}
				} elseif (!empty($fallback['file'])) {
					$fbPath = self::$rootDir . str_replace('/', DS, $fallback['file']);
					if (is_file($fbPath)) {
						return self::serveFile($client, $parsed, $fbPath);
					}
				}
			}
		}

		// 9. Not found
		self::sendResponse($client, 404, self::render404($path), 'text/html; charset=utf-8');
		return false;
	}

	/**
	 * Handle a routed request via Q::event() dispatch pipeline.
	 * Fires the same events as Qbix Platform's Q_Dispatcher:
	 *   {module}/{action}/validate → validate input
	 *   {module}/{action}/{method} → handle GET/POST/PUT/DELETE
	 *   {module}/{action}/response → render response
	 *
	 * @method handleRoute
	 * @static
	 * @private
	 * @param {resource} $client
	 * @param {array} $parsed
	 * @param {Q_Uri} $uri
	 * @return {boolean}
	 */
	private static function handleRoute($client, $parsed, $uri)
	{
		$module = $uri->module;
		$action = $uri->action;
		$routed = $uri->toArray();
		$method = strtolower($parsed['method']); // get, post, put, delete

		// Set up superglobals
		$parsed['_scriptPath'] = ''; // no script — handler-based
		$saved = array($_SERVER, $_GET, $_POST, $_REQUEST, $_COOKIE);		$_SERVER['REQUEST_METHOD'] = $parsed['method'];
		$_SERVER['REQUEST_URI'] = $parsed['uri'];
		$_SERVER['QUERY_STRING'] = $parsed['query'];
		$_SERVER['SERVER_NAME'] = explode(':', $parsed['headers']['host'] ?? 'localhost')[0];
		$_SERVER['SERVER_PORT'] = self::$port;
		$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
		$_SERVER['SERVER_SOFTWARE'] = 'QbixServer/1.0';
		$_SERVER['DOCUMENT_ROOT'] = rtrim(self::$rootDir, DS);
		$_SERVER['REMOTE_ADDR'] = $parsed['_remoteAddr'] ?? '127.0.0.1';
		$_SERVER['REQUEST_TIME'] = time();
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
		foreach ($parsed['headers'] as $k => $v) {
			$_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
		}

		$_GET = $_POST = $_REQUEST = $_FILES = array();
		if ($parsed['query']) parse_str($parsed['query'], $_GET);
		$ct = strtolower($parsed['headers']['content-type'] ?? '');
		$rawBody = $parsed['body'] ?? '';
		if (strpos($ct, 'application/x-www-form-urlencoded') !== false) {
			parse_str($rawBody, $_POST);
		} elseif (strpos($ct, 'application/json') !== false) {
			$_POST = json_decode($rawBody, true) ?: array();
		} elseif (strpos($ct, 'multipart/form-data') !== false) {
			$origCt = $parsed['headers']['content-type'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
			self::parseMultipart($origCt, $rawBody, $_POST, $_FILES);
		}
		$_REQUEST = array_merge($_GET, $_POST);

		// Make raw body available
		Q_Request::$input = $rawBody;

		// If pcntl available, fork to isolate
		if (function_exists('pcntl_fork')) {
			$pid = pcntl_fork();
			if ($pid === 0) {
				// ── CHILD: run dispatch pipeline ──
				while (ob_get_level()) ob_end_clean();
				ob_start();
				$status = 200;
				$headers = array();

				try {
					// 1. Validate
					Q::event("$module/$action/validate", $routed, false, true);

					// 2. Method handler (get, post, put, delete)
					if (Q::canHandle("$module/$action/$method")) {
						Q::event("$module/$action/$method", $routed);
					} elseif ($method !== 'get') {
						$status = 405;
						echo 'Method Not Allowed';
					}

					// 3. Response
					Q::event("$module/$action/response", $routed, false, true);

			$headers = Q::getResponseHeaders();
					$code = http_response_code();
					if ($code && $code !== 200) $status = $code;
					if (Q::$_responseCode !== 200) $status = Q::$_responseCode;
				} catch (\Throwable $e) {
					$status = 500;
					ob_clean();
					echo json_encode(array('error' => $e->getMessage()));
					$headers['Content-Type'] = 'application/json';
				}

				$body = ob_get_clean();
				$response = compact('status', 'body', 'headers');
				Q_WebServer_Headers::processResponse($client, $response, $parsed['headers']);
				@fclose($client);
				exit(0);
			} elseif ($pid > 0) {
				@fclose($client);
				$key = (int) $client;
				if (isset(self::$clientWatchers[$key])) {
					Q_Evented::cancel(self::$clientWatchers[$key]);
				}
				unset(self::$clientWatchers[$key], self::$clients[$key], self::$buffers[$key]);
				self::$workerPids[$pid] = microtime(true);
				pcntl_waitpid($pid, $st, WNOHANG);
				self::$lastStatus = 200;
				list($_SERVER, $_GET, $_POST, $_REQUEST, $_COOKIE) = $saved;
				return false;
			}
			// Fork failed — fall through to in-process
		}

		// In-process fallback
		while (ob_get_level()) ob_end_clean();
		header_remove();
		http_response_code(200);
		ob_start();
		$status = 200;
		$headers = array();

		try {
			Q::event("$module/$action/validate", $routed, false, true);
			if (Q::canHandle("$module/$action/$method")) {
				Q::event("$module/$action/$method", $routed);
			} elseif ($method !== 'get') {
				$status = 405;
				echo 'Method Not Allowed';
			}
			Q::event("$module/$action/response", $routed, false, true);
			$headers = Q::getResponseHeaders();
			$code = http_response_code();
			if ($code && $code !== 200) $status = $code;
			if (Q::$_responseCode !== 200) $status = Q::$_responseCode;
		} catch (\Throwable $e) {
			$status = 500;
			ob_clean();
			echo json_encode(array('error' => $e->getMessage()));
			$headers['Content-Type'] = 'application/json';
		}

		$body = ob_get_clean();
		header_remove();
		list($_SERVER, $_GET, $_POST, $_REQUEST, $_COOKIE) = $saved;

		$response = compact('status', 'body', 'headers');
		Q_WebServer_Headers::processResponse($client, $response, $parsed['headers']);
		self::$lastStatus = $status;
		Q_WebServer_Cache::put($parsed, $response);
		return false;
	}

	/**
	 * Route a .php script to the worker pool or dispatch in-process.
	 * @return {boolean} false (connection closes after response)
	 */
	private static function handlePhp($client, $parsed, $scriptPath)
	{
		// ── CGI carveout: check if this script should use php-cgi ──
		// Scripts matching Q.webserver.cgi.patterns run via php-cgi subprocess
		// where native header(), setcookie(), headers_list() all work.
		// Use for legacy/third-party code (WordPress, etc.) that calls header() directly.
		static $cgiPatterns = null;
		static $cgiBinary = null;
		if ($cgiPatterns === null) {
			$cgiPatterns = Q_Config::get('Q', 'webserver', 'cgi', 'patterns', array());
			$cgiBinary = Q_Config::get('Q', 'webserver', 'cgi', 'binary', null);
			if (!$cgiBinary) {
				// Auto-detect php-cgi
				foreach (array('php-cgi', 'php-cgi8.3', 'php-cgi8.2', 'php-cgi8.1') as $bin) {
					$path = trim(shell_exec("which $bin 2>/dev/null") ?? '');
					if ($path && is_executable($path)) {
						$cgiBinary = $path;
						break;
					}
				}
			}
		}
		if (!empty($cgiPatterns) && $cgiBinary) {
			$relPath = '/' . ltrim(str_replace(DS, '/', substr($scriptPath, strlen(self::$rootDir))), '/');
			foreach ($cgiPatterns as $pattern) {
				if (@preg_match($pattern, $relPath)) {
					return self::handlePhpCgi($client, $parsed, $scriptPath, $cgiBinary);
				}
			}
		}

		if (self::$pool) {
			self::$lastStatus = 200;
			self::$pool->dispatch($client, $parsed, $scriptPath);
			$key = (int) $client;
			if (isset(self::$clientWatchers[$key])) {
				Q_Evented::cancel(self::$clientWatchers[$key]);
			}
			unset(self::$clientWatchers[$key], self::$clients[$key], self::$buffers[$key]);
			return false;
		}

		// No pool — fork a single child if pcntl is available.
		// This protects the server from exit()/die() in scripts
		// and prevents blocking the event loop during PHP execution.
		if (function_exists('pcntl_fork')) {
			$pid = pcntl_fork();
			if ($pid === -1) {
				// Fork failed — fall through to in-process execution
			} elseif ($pid === 0) {
				// ── CHILD: handle request, write response to client, exit ──
				$parsed['_scriptPath'] = $scriptPath;
				$response = self::dispatchToQ($parsed);
				Q_WebServer_Headers::processResponse($client, $response, $parsed['headers']);
				@fclose($client);
				exit(0);
			} else {
				// ── PARENT: close client socket (child owns it now), reap later ──
				@fclose($client);
				$key = (int) $client;
				if (isset(self::$clientWatchers[$key])) {
					Q_Evented::cancel(self::$clientWatchers[$key]);
				}
				unset(self::$clientWatchers[$key], self::$clients[$key], self::$buffers[$key]);
				self::$workerPids[$pid] = microtime(true);
				// Non-blocking reap — don't wait for child
				pcntl_waitpid($pid, $st, WNOHANG);
				self::$lastStatus = 200;
				return false;
			}
		}

		// Fallback: use proc_open to run PHP in a subprocess (Windows,
		// or fork failed). Safe against exit()/die() — the subprocess
		// dies, not the server. Slower than fork (no shared classes)
		// but still provides isolation.
		return self::handlePhpSubprocess($client, $parsed, $scriptPath);
	}

	/**
	 * Execute a PHP script in a subprocess via proc_open.
	 * Used on Windows (no pcntl_fork) or as fallback when fork fails.
	 * The subprocess gets request data via stdin, returns response via stdout.
	 * @method handlePhpSubprocess
	 * @static
	 * @private
	 */
	private static function handlePhpSubprocess($client, $parsed, $scriptPath)
	{
		// Build a small inline PHP worker that:
		// 1. Reads request JSON from stdin
		// 2. Sets up superglobals
		// 3. Loads Q.php for autoloader/events
		// 4. Includes the target script
		// 5. Writes response JSON to stdout
		$qFile = dirname(__FILE__) . DS . 'Q.php';
		$workerCode = <<<'WORKER'
<?php
$json = '';
while (!feof(STDIN)) { $c = fread(STDIN, 65536); if ($c === false || $c === '') break; $json .= $c; }
$req = json_decode($json, true);
if (!$req) { echo json_encode(['status'=>500,'body'=>'Bad request','headers'=>[]]); exit; }
if (isset($req['qFile']) && file_exists($req['qFile'])) {
    require_once $req['qFile'];
    if (isset($req['projectRoot'])) Q::init($req['projectRoot']);
}
$_SERVER['REQUEST_METHOD'] = $req['method'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $req['uri'] ?? '/';
$_SERVER['QUERY_STRING'] = $req['query'] ?? '';
$_SERVER['SCRIPT_FILENAME'] = $req['scriptPath'] ?? '';
$_SERVER['SCRIPT_NAME'] = '/' . basename($req['scriptPath'] ?? 'index.php');
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['PATH_TRANSLATED'] = $req['scriptPath'] ?? '';
$_SERVER['DOCUMENT_ROOT'] = $req['documentRoot'] ?? '';
$_SERVER['DOCUMENT_URI'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['SERVER_NAME'] = $req['serverName'] ?? 'localhost';
$_SERVER['SERVER_PORT'] = $req['serverPort'] ?? '8080';
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['SERVER_SOFTWARE'] = 'QbixServer/1.0';
$_SERVER['GATEWAY_INTERFACE'] = 'CGI/1.1';
$_SERVER['REDIRECT_STATUS'] = 200;
$_SERVER['REMOTE_ADDR'] = $req['remoteAddr'] ?? '127.0.0.1';
$_SERVER['REMOTE_PORT'] = $req['remotePort'] ?? 0;
$_SERVER['REQUEST_TIME'] = time();
$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
$_SERVER['REQUEST_SCHEME'] = ($req['https'] ?? false) ? 'https' : 'http';
$_SERVER['HTTPS'] = ($req['https'] ?? false) ? 'on' : '';
foreach ($req['headers'] ?? [] as $k=>$v) $_SERVER['HTTP_'.strtoupper(str_replace('-','_',$k))] = $v;
if (isset($req['headers']['content-type'])) $_SERVER['CONTENT_TYPE'] = $req['headers']['content-type'];
if (isset($req['headers']['content-length'])) $_SERVER['CONTENT_LENGTH'] = $req['headers']['content-length'];
// Parse cookies
$_COOKIE = [];
$ck = $req['headers']['cookie'] ?? '';
if ($ck) { foreach (explode(';',$ck) as $p) { $p=trim($p); if(!$p)continue; $e=strpos($p,'='); if($e===false)continue; $_COOKIE[urldecode(trim(substr($p,0,$e)))]=urldecode(trim(substr($p,$e+1))); } }
// Parse Basic auth
$auth = $req['headers']['authorization'] ?? '';
if (stripos($auth,'Basic ')===0) { $d=base64_decode(substr($auth,6)); if($d&&strpos($d,':')!==false) { [$u,$pw]=explode(':',$d,2); $_SERVER['PHP_AUTH_USER']=$u; $_SERVER['PHP_AUTH_PW']=$pw; $_SERVER['AUTH_TYPE']='Basic'; } }
$_GET = $_POST = $_REQUEST = $_FILES = [];
if (!empty($req['query'])) parse_str($req['query'], $_GET);
$ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
$raw = $req['body'] ?? '';
if (strpos($ct,'application/x-www-form-urlencoded') !== false) parse_str($raw, $_POST);
elseif (strpos($ct,'application/json') !== false) $_POST = json_decode($raw, true) ?: [];
elseif (strpos($ct,'multipart/form-data') !== false) { $oct=$req['headers']['content-type']??''; Q_WebServer::parseMultipart($oct, $raw, $_POST, $_FILES); }
$_REQUEST = array_merge($_COOKIE, $_GET, $_POST);
if (class_exists('Q_Request',false)) Q_Request::$input = $raw;
ob_start(); $status = 200; $headers = [];
try {
    if (is_file($req['scriptPath'])) include $req['scriptPath']; else { $status = 404; echo 'Not Found'; }
			$headers = Q::getResponseHeaders();
    $code = http_response_code(); if (Q::$_responseCode !== 200) $status = Q::$_responseCode; if ($code) $status = $code;
} catch (Throwable $e) { $status = 500; ob_clean(); echo $e->getMessage(); $headers['Content-Type']='text/plain'; }
$body = ob_get_clean();
echo json_encode(compact('status','body','headers'), JSON_UNESCAPED_SLASHES);
WORKER;

		// Write worker code to a temp file (reuse across requests)
		static $workerFile = null;
		if (!$workerFile || !file_exists($workerFile)) {
			$workerFile = tempnam(sys_get_temp_dir(), 'qbix_worker_');
			file_put_contents($workerFile, $workerCode);
			register_shutdown_function(function () use (&$workerFile) {
				@unlink($workerFile);
			});
		}

		// Build request payload
		$host = $parsed['headers']['host'] ?? 'localhost';
		$payload = json_encode(array(
			'method'      => $parsed['method'],
			'uri'         => $parsed['uri'],
			'query'       => $parsed['query'],
			'headers'     => $parsed['headers'],
			'body'        => $parsed['body'] ?? '',
			'scriptPath'  => $scriptPath,
			'documentRoot'=> rtrim(self::$rootDir, DS),
			'serverName'  => explode(':', $host)[0],
			'serverPort'  => (string) self::$port,
			'remoteAddr'  => $parsed['_remoteAddr'] ?? '127.0.0.1',
			'remotePort'  => $parsed['_remotePort'] ?? 0,
			'https'       => !empty(self::$tlsSocket),
			'qFile'       => $qFile,
			'projectRoot' => dirname(rtrim(self::$rootDir, DS)),
		), JSON_UNESCAPED_SLASHES);

		// Launch subprocess
		$descriptors = array(
			0 => array('pipe', 'r'), // stdin
			1 => array('pipe', 'w'), // stdout
			2 => array('pipe', 'w'), // stderr
		);

		$phpBin = defined('PHP_BINARY') ? PHP_BINARY : 'php';
		$process = proc_open($phpBin . ' ' . escapeshellarg($workerFile), $descriptors, $pipes);

		if (!is_resource($process)) {
			// proc_open failed — last resort, run in-process
			$parsed['_scriptPath'] = $scriptPath;
			$response = self::dispatchToQ($parsed);
			Q_WebServer_Headers::processResponse($client, $response, $parsed['headers']);
			self::$lastStatus = $response['status'] ?? 200;
			return false;
		}

		// Send request data to child's stdin
		fwrite($pipes[0], $payload);
		fclose($pipes[0]);

		// Read response from child's stdout
		$stdout = '';
		while (!feof($pipes[1])) {
			$chunk = fread($pipes[1], 65536);
			if ($chunk === false || $chunk === '') break;
			$stdout .= $chunk;
		}
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		// Parse response
		$response = json_decode($stdout, true);
		if (!$response) {
			$response = array(
				'status' => 502,
				'body' => 'Worker subprocess failed',
				'headers' => array('Content-Type' => 'text/plain'),
			);
		}

		Q_WebServer_Headers::processResponse($client, $response, $parsed['headers']);
		self::$lastStatus = $response['status'] ?? 200;
		Q_WebServer_Cache::put($parsed, $response);
		return false;
	}

	/**
	 * Execute a PHP script via php-cgi binary for full header() compatibility.
	 * Used for legacy/third-party code (WordPress, Laravel, etc.) that calls
	 * header() and setcookie() directly. The php-cgi binary outputs real HTTP
	 * headers followed by the body — we parse and forward them.
	 *
	 * Slower than fork mode (no preload benefit) but 100% compatible.
	 *
	 * @method handlePhpCgi
	 * @static
	 * @private
	 */
	private static function handlePhpCgi($client, $parsed, $scriptPath, $cgiBinary)
	{
		$host = $parsed['headers']['host'] ?? 'localhost';
		$hostParts = explode(':', $host);
		$isHttps = !empty(self::$tlsSocket);
		$fwdProto = strtolower($parsed['headers']['x-forwarded-proto'] ?? '');
		if ($fwdProto === 'https') $isHttps = true;
		$cfVisitor = $parsed['headers']['cf-visitor'] ?? '';
		if (strpos($cfVisitor, '"https"') !== false) $isHttps = true;

		// Compute SCRIPT_NAME and PATH_INFO (frameworks need correct PATH_INFO)
		$requestPath = parse_url($parsed['uri'], PHP_URL_PATH) ?: '/';
		$docRoot = rtrim(self::$rootDir, DS);
		$scriptRel = '/' . ltrim(str_replace(DS, '/', substr($scriptPath, strlen($docRoot))), '/');
		$pathInfo = '';
		if (strlen($requestPath) > strlen($scriptRel)) {
			$pathInfo = substr($requestPath, strlen($scriptRel));
		}

		// Build CGI environment variables — full set matching nginx fastcgi_params
		$env = array(
			'REDIRECT_STATUS'    => '200',
			'GATEWAY_INTERFACE'  => 'CGI/1.1',
			'SERVER_SOFTWARE'    => 'QbixServer/' . (defined('QBIX_SERVER_VERSION') ? QBIX_SERVER_VERSION : '1.0'),
			'SERVER_PROTOCOL'    => 'HTTP/' . ($parsed['httpVersion'] ?? '1.1'),
			'SERVER_NAME'        => $hostParts[0],
			'SERVER_PORT'        => isset($hostParts[1]) ? $hostParts[1] : (string) self::$port,
			'SERVER_ADDR'        => self::$host === '0.0.0.0' ? '127.0.0.1' : self::$host,
			'REQUEST_METHOD'     => $parsed['method'],
			'REQUEST_URI'        => $parsed['uri'],
			'QUERY_STRING'       => $parsed['query'],
			'SCRIPT_FILENAME'    => $scriptPath,
			'SCRIPT_NAME'        => $scriptRel,
			'PHP_SELF'           => $scriptRel . $pathInfo,
			'PATH_INFO'          => $pathInfo,
			'PATH_TRANSLATED'    => $pathInfo ? $docRoot . $pathInfo : '',
			'DOCUMENT_ROOT'      => $docRoot,
			'DOCUMENT_URI'       => $scriptRel,
			'REMOTE_ADDR'        => $parsed['_remoteAddr'] ?? '127.0.0.1',
			'REMOTE_PORT'        => (string) ($parsed['_remotePort'] ?? 0),
			'REQUEST_SCHEME'     => $isHttps ? 'https' : 'http',
			'HTTPS'              => $isHttps ? 'on' : '',
			'REQUEST_TIME'       => (string) time(),
			'REQUEST_TIME_FLOAT' => (string) microtime(true),
		);

		// Forward ALL request headers as HTTP_* env vars
		foreach ($parsed['headers'] as $k => $v) {
			$envKey = 'HTTP_' . strtoupper(str_replace('-', '_', $k));
			$env[$envKey] = $v;
		}
		// Content-Type and Content-Length are special (no HTTP_ prefix per CGI spec)
		if (isset($parsed['headers']['content-type'])) {
			$env['CONTENT_TYPE'] = $parsed['headers']['content-type'];
		}
		if (isset($parsed['headers']['content-length'])) {
			$env['CONTENT_LENGTH'] = $parsed['headers']['content-length'];
		}
		// Basic auth
		$auth = $parsed['headers']['authorization'] ?? '';
		if (stripos($auth, 'Basic ') === 0) {
			$decoded = base64_decode(substr($auth, 6));
			if ($decoded && strpos($decoded, ':') !== false) {
				list($user, $pass) = explode(':', $decoded, 2);
				$env['PHP_AUTH_USER'] = $user;
				$env['PHP_AUTH_PW'] = $pass;
				$env['AUTH_TYPE'] = 'Basic';
			}
		}
		// Inherit essential system env vars
		foreach (array('PATH', 'HOME', 'TEMP', 'TMP', 'TMPDIR', 'SYSTEMROOT') as $sysVar) {
			if (isset($_ENV[$sysVar])) $env[$sysVar] = $_ENV[$sysVar];
			elseif (($v = getenv($sysVar)) !== false) $env[$sysVar] = $v;
		}

		// Launch php-cgi
		$descriptors = array(
			0 => array('pipe', 'r'), // stdin (request body)
			1 => array('pipe', 'w'), // stdout (CGI response)
			2 => array('pipe', 'w'), // stderr (errors)
		);

		$cwd = dirname($scriptPath); // run in the script's directory
		$process = proc_open($cgiBinary, $descriptors, $pipes, $cwd, $env);
		if (!is_resource($process)) {
			self::sendResponse($client, 502, 'CGI process failed to start');
			return false;
		}

		// Non-blocking reads for timeout support
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		// Send request body to stdin
		if (!empty($parsed['body'])) {
			@fwrite($pipes[0], $parsed['body']);
		}
		fclose($pipes[0]);

		// Read CGI response with timeout
		static $timeout = null;
		if ($timeout === null) $timeout = Q_Config::get('Q', 'webserver', 'cgi', 'timeout', 30);
		$deadline = microtime(true) + $timeout;
		$stdout = '';
		$stderr = '';
		while (true) {
			$read = array($pipes[1], $pipes[2]);
			$write = $except = null;
			$remaining = max(0.1, $deadline - microtime(true));
			if ($remaining <= 0) break; // timeout
			$ready = @stream_select($read, $write, $except, (int) $remaining, (int) (($remaining - (int) $remaining) * 1000000));
			if ($ready === false) break;
			if ($ready === 0) continue;
			foreach ($read as $pipe) {
				$chunk = @fread($pipe, 65536);
				if ($chunk === false || $chunk === '') {
					if ($pipe === $pipes[1] && feof($pipes[1])) break 2;
					continue;
				}
				if ($pipe === $pipes[1]) $stdout .= $chunk;
				else $stderr .= $chunk;
			}
		}
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);

		// Log stderr if non-empty
		if ($stderr !== '') {
			Q_WebServer_Log::error("CGI stderr ($scriptPath): " . trim($stderr));
		}

		// Handle timeout
		if (microtime(true) >= $deadline && $stdout === '') {
			self::sendResponse($client, 504, 'CGI process timed out');
			return false;
		}

		// Handle empty response
		if ($stdout === '') {
			$status = ($exitCode !== 0) ? 500 : 200;
			$response = array('status' => $status, 'body' => '', 'headers' => array());
			Q_WebServer_Headers::processResponse($client, $response, $parsed['headers']);
			self::$lastStatus = $status;
			return false;
		}

		// Parse CGI output: headers separated by blank line from body
		$headerEnd = strpos($stdout, "\r\n\r\n");
		$sep = 4;
		if ($headerEnd === false) {
			$headerEnd = strpos($stdout, "\n\n");
			$sep = 2;
		}

		if ($headerEnd === false) {
			$body = $stdout;
			$headers = array();
			$status = 200;
			$extraHeaders = array();
		} else {
			$headerBlock = substr($stdout, 0, $headerEnd);
			$body = substr($stdout, $headerEnd + $sep);
			$headers = array();
			$extraHeaders = array(); // for multiple Set-Cookie headers
			$status = 200;

			foreach (explode("\n", $headerBlock) as $line) {
				$line = rtrim($line, "\r");
				if ($line === '') continue;

				// Status line: "Status: 404 Not Found"
				if (stripos($line, 'Status:') === 0) {
					$status = (int) trim(substr($line, 7));
					continue;
				}
				// Location header implies redirect status
				$colonPos = strpos($line, ':');
				if ($colonPos === false) continue;

				$name = trim(substr($line, 0, $colonPos));
				$value = trim(substr($line, $colonPos + 1));

				// Multiple Set-Cookie headers must all be forwarded
				if (strtolower($name) === 'set-cookie') {
					$extraHeaders[] = array($name, $value);
				} else {
					$headers[$name] = $value;
				}

				if (strtolower($name) === 'location' && $status === 200) {
					$status = 302; // implicit redirect
				}
			}
		}

		// Build and send response
		// We bypass processResponse for Set-Cookie to handle multiples
		$headers['Content-Length'] = strlen($body);
		$headers['Connection'] = 'close';

		static $reasons = array(
			200=>'OK', 201=>'Created', 204=>'No Content',
			301=>'Moved Permanently', 302=>'Found', 304=>'Not Modified',
			400=>'Bad Request', 401=>'Unauthorized', 403=>'Forbidden',
			404=>'Not Found', 405=>'Method Not Allowed',
			413=>'Payload Too Large', 500=>'Internal Server Error',
			502=>'Bad Gateway', 503=>'Service Unavailable', 504=>'Gateway Timeout',
		);
		$reason = $reasons[$status] ?? 'OK';
		$out = "HTTP/1.1 $status $reason\r\n";
		foreach ($headers as $k => $v) {
			$out .= "$k: $v\r\n";
		}
		// Append all Set-Cookie headers (can't use associative array)
		foreach ($extraHeaders as $pair) {
			$out .= $pair[0] . ': ' . $pair[1] . "\r\n";
		}
		@fwrite($client, $out . "\r\n" . $body);

		self::$lastStatus = $status;
		return false;
	}

	private static function serveStaticFile($client, $fsPath, $method, $reqHeaders, $keepAlive = false)
	{
		$ext = strtolower(pathinfo($fsPath, PATHINFO_EXTENSION));
		if (!in_array($ext, self::$allowedExtensions)) {
			self::sendResponse($client, 403, 'Forbidden');
			return;
		}

		$connKey = $keepAlive ? 'ka' : 'cl';
		$now = microtime(true);

		// ── Try response cache ──
		if (isset(self::$fileCache[$fsPath])) {
			$cached = &self::$fileCache[$fsPath];
			// Revalidate mtime periodically
			if (($now - $cached['checked']) >= self::$fileCacheCheckInterval) {
				clearstatcache(true, $fsPath);
				if (filemtime($fsPath) !== $cached['mtime']) {
					self::$fileCacheSize -= $cached['bodyLen'] * 2;
					unset(self::$fileCache[$fsPath]);
				} else {
					$cached['checked'] = $now;
				}
			}
		}

		if (isset(self::$fileCache[$fsPath])) {
			$cached = &self::$fileCache[$fsPath];
			$etag = $cached['etag'];

			// 304 against cached etag
			if (isset($reqHeaders['if-none-match']) && trim($reqHeaders['if-none-match']) === $etag) {
				self::sendNotModified($client, $etag, $cached['mtime'], $keepAlive);
				return;
			}
			if (isset($reqHeaders['if-modified-since'])) {
				$since = strtotime($reqHeaders['if-modified-since']);
				if ($since !== false && $cached['mtime'] <= $since) {
					self::sendNotModified($client, $etag, $cached['mtime'], $keepAlive);
					return;
				}
			}

			// Serve from cache — single fwrite
			self::$lastStatus = 200;
			if ($method === 'HEAD') {
				@fwrite($client, $cached['head'][$connKey]);
			} else {
				@fwrite($client, $cached['full'][$connKey]);
			}
			return;
		}

		// ── Cache miss — build from disk ──
		clearstatcache(true, $fsPath);
		$mtime = filemtime($fsPath);
		$size = filesize($fsPath);
		$etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';

		// 304 Not Modified
		if (isset($reqHeaders['if-none-match']) && trim($reqHeaders['if-none-match']) === $etag) {
			self::sendNotModified($client, $etag, $mtime, $keepAlive);
			return;
		}
		if (isset($reqHeaders['if-modified-since'])) {
			$since = strtotime($reqHeaders['if-modified-since']);
			if ($since !== false && $mtime <= $since) {
				self::sendNotModified($client, $etag, $mtime, $keepAlive);
				return;
			}
		}

		$contentType = self::mimeType($ext);
		$baseHeaders = "Content-Type: $contentType\r\n"
			. "ETag: $etag\r\n"
			. "Last-Modified: " . gmdate('D, d M Y H:i:s', $mtime) . " GMT\r\n"
			. "Cache-Control: public, max-age=0, must-revalidate\r\n";

		// Companion .headers file
		$hf = $fsPath . '.headers';
		if (file_exists($hf)) {
			foreach (file($hf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
				if ($line[0] === '#' || strpos($line, ':') === false) continue;
				$baseHeaders .= trim($line) . "\r\n";
			}
		}

		$connHeader = $keepAlive ? 'keep-alive' : 'close';

		// Pre-compressed siblings — not cached (different per Accept-Encoding)
		$preComp = Q_WebServer_Headers::findPreCompressed($fsPath, $reqHeaders);
		if ($preComp) {
			$out = "HTTP/1.1 200 OK\r\n" . $baseHeaders
				. "Content-Encoding: " . $preComp['encoding'] . "\r\n"
				. "Content-Length: " . $preComp['size'] . "\r\n"
				. "Vary: Accept-Encoding\r\n"
				. "Connection: $connHeader\r\n\r\n";
			self::$lastStatus = 200;
			@fwrite($client, $method === 'HEAD' ? $out : $out . file_get_contents($preComp['path']));
			return;
		}

		// On-the-fly gzip — not cached (different per Accept-Encoding)
		if ($size < 5242880) {
			$gzHeaders = array();
			if (Q_WebServer_Headers::shouldCompress($contentType, $size, $reqHeaders)) {
				$body = file_get_contents($fsPath);
				$body = Q_WebServer_Headers::maybeCompress($body, $contentType, $reqHeaders, $gzHeaders);
				$out = "HTTP/1.1 200 OK\r\n" . $baseHeaders;
				foreach ($gzHeaders as $k => $v) $out .= "$k: $v\r\n";
				$out .= "Content-Length: " . strlen($body) . "\r\n"
					. "Connection: $connHeader\r\n\r\n";
				self::$lastStatus = 200;
				@fwrite($client, $method === 'HEAD' ? $out : $out . $body);
				return;
			}
		}

		// ── Uncompressed — serve and cache ──
		$body = file_get_contents($fsPath);
		$kaHead = "HTTP/1.1 200 OK\r\n" . $baseHeaders
			. "Content-Length: $size\r\nConnection: keep-alive\r\n\r\n";
		$clHead = "HTTP/1.1 200 OK\r\n" . $baseHeaders
			. "Content-Length: $size\r\nConnection: close\r\n\r\n";

		self::$lastStatus = 200;
		$headStr = $keepAlive ? $kaHead : $clHead;
		@fwrite($client, $method === 'HEAD' ? $headStr : $headStr . $body);

		// Cache if small enough
		if ($size <= self::$fileCacheMaxFile
			&& self::$fileCacheSize + $size * 2 < self::$fileCacheMaxSize
		) {
			self::$fileCache[$fsPath] = array(
				'mtime'   => $mtime,
				'bodyLen' => $size,
				'etag'    => $etag,
				'checked' => $now,
				'head'    => array('ka' => $kaHead, 'cl' => $clHead),
				'full'    => array('ka' => $kaHead . $body, 'cl' => $clHead . $body),
			);
			self::$fileCacheSize += $size * 2;

			// Evict oldest if over limit
			while (self::$fileCacheSize > self::$fileCacheMaxSize && self::$fileCache) {
				$evict = array_key_first(self::$fileCache);
				self::$fileCacheSize -= self::$fileCache[$evict]['bodyLen'] * 2;
				unset(self::$fileCache[$evict]);
			}
		}
	}

	// ── Privacy / access control ─────────────────────────

	/**
	 * Check if a URL path is blocked entirely (403 Forbidden).
	 *
	 * These paths cannot be accessed by any URL. They contain
	 * server-side code, config, and internal data.
	 *
	 * Blocked: /config/, /classes/, /handlers/, /scripts/
	 * Also: dotfiles/dotdirs (except /.well-known/)
	 * Also: paths in Q.web.blocked.paths config
	 *
	 * For true access control on files, use X-Accel-Redirect
	 * (PHP checks permissions, server does file I/O).
	 *
	 * @method isBlocked
	 * @static
	 * @param {string} $urlPath
	 * @return {boolean}
	 */
	static function isBlocked($urlPath)
	{
		// Core blocked directories (server internals)
		$blocked = array('/config/', '/classes/', '/handlers/', '/scripts/');
		foreach ($blocked as $prefix) {
			if (strpos($urlPath, $prefix) === 0) return true;
		}

		// Dotfiles/dotdirs (except /.well-known/)
		if (preg_match('#/\.(?!well-known)#', $urlPath)) return true;

		// Config-based blocked paths
		static $blockedPaths = null;
		if ($blockedPaths === null) $blockedPaths = Q_Config::get('Q', 'web', 'blocked', 'paths', array());
		foreach ($blockedPaths as $pp => $v) {
			if ($v && strpos($urlPath, '/' . ltrim($pp, '/')) === 0) return true;
		}

		return false;
	}

	/**
	 * Check if a URL path allows directory listing.
	 *
	 * Directory listings are OFF by default (more secure).
	 * Only paths matching regexes in
	 * Q.web.indexed.paths get listings. Default: /img/.
	 *
	 * Config:
	 *   "Q": { "web": { "indexed": { "paths": {
	 *     "#^/img/#": true,
	 *     "#^/downloads/#": true
	 *   }}}}
	 *
	 * For actual access control, use X-Accel-Redirect.
	 *
	 * @method isIndexed
	 * @static
	 * @param {string} $urlPath
	 * @return {boolean}
	 */
	static function isIndexed($urlPath)
	{
		static $patterns = null;
		if ($patterns === null) $patterns = Q_Config::get('Q', 'web', 'indexed', 'paths', array(
			'#^/img/#' => true
		));
		foreach ($patterns as $regex => $enabled) {
			if ($enabled && preg_match($regex, $urlPath)) return true;
		}
		return false; // not indexed by default
	}

	// ── Directory listing ────────────────────────────────

	/**
	 * Render a responsive directory listing with media previews.
	 * Only called for paths that pass isIndexed().
	 * Dotfiles are always hidden from listings.
	 *
	 * @method renderDirectoryListing
	 * @static
	 * @param {string} $dir Filesystem path
	 * @param {string} $urlPath URL path
	 * @return {string} HTML
	 */
	static function renderDirectoryListing($dir, $urlPath)
	{
		$maxImages = (int) Q_Config::get('Q', 'webserver', 'listing', 'images', 'max', 100);
		$items = scandir($dir);
		$dirs = array();
		$files = array();
		$media = array();

		$imageExts = array('png','jpg','jpeg','gif','webp','svg');
		$videoExts = array('mp4','webm','ogg');
		$audioExts = array('mp3','wav','ogg');

		foreach ($items as $name) {
			if ($name === '.' || $name === '..') continue;
			if ($name[0] === '.') continue; // dotfiles always hidden

			$full = $dir . DS . $name;
			$href = htmlspecialchars($urlPath . $name, ENT_QUOTES);
			$safe = htmlspecialchars($name, ENT_QUOTES);

			if (is_dir($full)) {
				$dirs[] = "<a href=\"{$href}/\" class=\"item dir\"><span class=\"icon\">📁</span><span class=\"name\">{$safe}/</span></a>";
				continue;
			}

			$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
			if (!in_array($ext, self::$allowedExtensions)) continue;

			$size = filesize($full);
			$sizeStr = $size < 1024 ? "${size} B"
				: ($size < 1048576 ? round($size/1024,1).' KB'
				: round($size/1048576,1).' MB');

			$files[] = "<a href=\"{$href}\" class=\"item file\"><span class=\"icon\">📄</span><span class=\"name\">{$safe}</span><span class=\"size\">{$sizeStr}</span></a>";

			// Collect media for preview grid
			if (count($media) < $maxImages) {
				if (in_array($ext, $imageExts)) {
					$media[] = "<div class=\"media-item\"><a href=\"{$href}\"><img src=\"{$href}\" loading=\"lazy\" alt=\"{$safe}\"></a><div class=\"caption\">{$safe}</div></div>";
				} elseif (in_array($ext, $videoExts)) {
					$media[] = "<div class=\"media-item\"><video src=\"{$href}\" controls preload=\"metadata\"></video><div class=\"caption\">{$safe}</div></div>";
				} elseif (in_array($ext, $audioExts)) {
					$media[] = "<div class=\"media-item\"><audio src=\"{$href}\" controls preload=\"metadata\"></audio><div class=\"caption\">{$safe}</div></div>";
				}
			}
		}

		$safePath = htmlspecialchars($urlPath, ENT_QUOTES);
		$upLink = ($urlPath !== '/')
			? '<a href="../" class="item dir up"><span class="icon">⬆</span><span class="name">Parent Directory</span></a>'
			: '';

		$mediaSection = '';
		if ($media) {
			$mediaSection = '<div class="divider">Media Preview</div><div class="media-grid">'
				. implode("\n", $media) . '</div>';
		}

		return <<<HTML
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Index of {$safePath}</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,system-ui,BlinkMacSystemFont,'Segoe UI',sans-serif;
  background:#f8f9fa;color:#333;padding:20px;max-width:960px;margin:0 auto}
h1{font-size:20px;font-weight:600;padding:16px 0;border-bottom:2px solid #e9ecef;margin-bottom:12px;
  word-break:break-all}
.listing{display:flex;flex-direction:column;gap:2px}
.item{display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:6px;
  text-decoration:none;color:#333;transition:background .15s}
.item:hover{background:#e9ecef}
.icon{font-size:18px;flex-shrink:0;width:24px;text-align:center}
.name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:14px}
.size{color:#868e96;font-size:12px;flex-shrink:0}
.dir .name{color:#1971c2;font-weight:500}
.file .name{color:#333}
.up{border-bottom:1px solid #e9ecef;margin-bottom:4px;padding-bottom:10px}
.divider{font-size:12px;color:#868e96;text-transform:uppercase;letter-spacing:.5px;
  padding:20px 0 8px;border-top:1px solid #e9ecef;margin-top:16px}
.media-grid{display:flex;flex-wrap:wrap;gap:12px;padding:8px 0}
.media-item{max-width:200px;text-align:center}
.media-item img{max-width:200px;max-height:200px;height:auto;border-radius:6px;
  display:block;margin:0 auto 4px;object-fit:cover}
.media-item video{max-width:200px;max-height:200px;border-radius:6px;display:block;margin:0 auto 4px}
.media-item audio{max-width:200px;display:block;margin:0 auto 4px}
.caption{font-size:11px;color:#868e96;word-break:break-all;max-width:200px}
@media(max-width:600px){
  body{padding:12px}
  .item{padding:10px 8px}
  .media-item{max-width:calc(50vw - 24px)}
  .media-item img,.media-item video{max-width:100%}
}
</style>
</head><body>
<h1>Index of {$safePath}</h1>
<div class="listing">
{$upLink}
HTML
		. implode("\n", $dirs) . "\n"
		. implode("\n", $files)
		. "\n</div>\n"
		. $mediaSection
		. "\n</body></html>";
	}

	// ── MIME types ────────────────────────────────────────

	static function mimeType($ext)
	{
		static $types = array(
			'html'=>'text/html; charset=utf-8', 'htm'=>'text/html; charset=utf-8',
			'css'=>'text/css; charset=utf-8', 'js'=>'application/javascript; charset=utf-8',
			'mjs'=>'application/javascript; charset=utf-8', 'json'=>'application/json; charset=utf-8',
			'xml'=>'application/xml', 'txt'=>'text/plain; charset=utf-8',
			'md'=>'text/plain; charset=utf-8', 'csv'=>'text/csv; charset=utf-8',
			'yaml'=>'text/yaml', 'yml'=>'text/yaml', 'log'=>'text/plain; charset=utf-8',
			'map'=>'application/json',
			'png'=>'image/png', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg',
			'gif'=>'image/gif', 'webp'=>'image/webp', 'svg'=>'image/svg+xml',
			'bmp'=>'image/bmp', 'ico'=>'image/x-icon', 'avif'=>'image/avif',
			'woff'=>'font/woff', 'woff2'=>'font/woff2',
			'ttf'=>'font/ttf', 'otf'=>'font/otf',
			'mp3'=>'audio/mpeg', 'wav'=>'audio/wav', 'ogg'=>'audio/ogg',
			'mp4'=>'video/mp4', 'webm'=>'video/webm',
			'pdf'=>'application/pdf', 'zip'=>'application/zip',
			'wasm'=>'application/wasm',
		);
		return $types[$ext] ?? 'application/octet-stream';
	}

	// ── Q_Dispatcher bridge ──────────────────────────────

	static function dispatchToQ($parsed)
	{
		$saved = array($_SERVER, $_GET, $_POST, $_REQUEST, $_COOKIE);
		$scriptPath = $parsed['_scriptPath'] ?? self::$rootDir . 'index.php';
		$host = $parsed['headers']['host'] ?? 'localhost';
		$hostParts = explode(':', $host);

		// ── Standard CGI variables ──────────────────────
		$_SERVER['REQUEST_METHOD']    = $parsed['method'];
		$_SERVER['REQUEST_URI']       = $parsed['uri'];
		$_SERVER['QUERY_STRING']      = $parsed['query'];
		$_SERVER['SCRIPT_NAME']       = '/' . basename($scriptPath);
		$_SERVER['SCRIPT_FILENAME']   = $scriptPath;
		$_SERVER['PHP_SELF']          = $_SERVER['SCRIPT_NAME']; // WordPress uses this
		$_SERVER['PATH_TRANSLATED']   = $scriptPath;
		$_SERVER['PATH_INFO']         = '';
		$_SERVER['DOCUMENT_ROOT']     = rtrim(self::$rootDir, DS);
		$_SERVER['DOCUMENT_URI']      = $_SERVER['SCRIPT_NAME'];
		$_SERVER['SERVER_NAME']       = $hostParts[0];
		$_SERVER['SERVER_PORT']       = isset($hostParts[1]) ? $hostParts[1] : self::$port;
		$_SERVER['SERVER_ADDR']       = self::$host === '0.0.0.0' ? '127.0.0.1' : self::$host;
		$_SERVER['SERVER_PROTOCOL']   = 'HTTP/' . ($parsed['httpVersion'] ?? '1.1');
		$_SERVER['SERVER_SOFTWARE']   = 'QbixServer/' . (defined('QBIX_SERVER_VERSION') ? QBIX_SERVER_VERSION : '1.0');
		$_SERVER['GATEWAY_INTERFACE'] = 'CGI/1.1';
		$_SERVER['REDIRECT_STATUS']   = 200;
		$_SERVER['REMOTE_ADDR']       = $parsed['_remoteAddr'] ?? '127.0.0.1';
		$_SERVER['REMOTE_PORT']       = $parsed['_remotePort'] ?? 0;
		$_SERVER['REQUEST_TIME']      = time();
		$_SERVER['REQUEST_TIME_FLOAT']= microtime(true);

		// ── HTTPS detection (direct TLS or proxy header) ──
		$isHttps = !empty(self::$tlsSocket);
		$fwdProto = $parsed['headers']['x-forwarded-proto'] ?? '';
		if (strtolower($fwdProto) === 'https') $isHttps = true;
		// CloudFront
		$cfProto = $parsed['headers']['cloudfront-forwarded-proto'] ?? '';
		if (strtolower($cfProto) === 'https') $isHttps = true;
		// Cloudflare
		$cfVisitor = $parsed['headers']['cf-visitor'] ?? '';
		if (strpos($cfVisitor, '"https"') !== false) $isHttps = true;
		$_SERVER['REQUEST_SCHEME']    = $isHttps ? 'https' : 'http';
		$_SERVER['HTTPS']             = $isHttps ? 'on' : '';

		// ── Request headers → HTTP_* ────────────────────
		// All request headers become HTTP_HEADERNAME (uppercase, hyphens→underscores)
		foreach ($parsed['headers'] as $k => $v) {
			$_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
		}
		// Content-Type and Content-Length are special (no HTTP_ prefix per CGI spec)
		if (isset($parsed['headers']['content-type']))
			$_SERVER['CONTENT_TYPE'] = $parsed['headers']['content-type'];
		if (isset($parsed['headers']['content-length']))
			$_SERVER['CONTENT_LENGTH'] = $parsed['headers']['content-length'];

		// ── Basic auth parsing ──────────────────────────
		$auth = $parsed['headers']['authorization'] ?? '';
		if (stripos($auth, 'Basic ') === 0) {
			$decoded = base64_decode(substr($auth, 6));
			if ($decoded && strpos($decoded, ':') !== false) {
				list($user, $pass) = explode(':', $decoded, 2);
				$_SERVER['PHP_AUTH_USER'] = $user;
				$_SERVER['PHP_AUTH_PW'] = $pass;
				$_SERVER['AUTH_TYPE'] = 'Basic';
			}
		} elseif (stripos($auth, 'Bearer ') === 0) {
			$_SERVER['HTTP_AUTHORIZATION'] = $auth; // already set by loop
			$_SERVER['AUTH_TYPE'] = 'Bearer';
		}

		// ── $_COOKIE ────────────────────────────────────
		$_COOKIE = array();
		$cookieHeader = $parsed['headers']['cookie'] ?? '';
		if ($cookieHeader) {
			$pairs = explode(';', $cookieHeader);
			foreach ($pairs as $pair) {
				$pair = trim($pair);
				if ($pair === '') continue;
				$eqPos = strpos($pair, '=');
				if ($eqPos === false) continue;
				$name = urldecode(trim(substr($pair, 0, $eqPos)));
				$value = urldecode(trim(substr($pair, $eqPos + 1)));
				$_COOKIE[$name] = $value;
			}
		}

		// ── $_GET, $_POST, $_FILES, $_REQUEST ───────────
		$_GET = $_POST = $_REQUEST = $_FILES = array();
		if ($parsed['query']) parse_str($parsed['query'], $_GET);
		$ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
		$rawBody = $parsed['body'] ?? '';
		if (strpos($ct, 'application/x-www-form-urlencoded') !== false) {
			parse_str($rawBody, $_POST);
		} elseif (strpos($ct, 'application/json') !== false) {
			$_POST = json_decode($rawBody, true) ?: array();
		} elseif (strpos($ct, 'multipart/form-data') !== false) {
			$origCt = $parsed['headers']['content-type'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
			self::parseMultipart($origCt, $rawBody, $_POST, $_FILES);
		}
		$_REQUEST = array_merge($_COOKIE, $_GET, $_POST); // PHP default order

		// Make raw body available
		Q_Request::$input = $rawBody;

		// Clear any stale headers and output from previous in-process requests,
		// then start fresh output buffering. This prevents "headers already sent"
		// errors when scripts call header() after prior output leaked through.
		while (ob_get_level()) ob_end_clean();
		header_remove();
		http_response_code(200);
		Q::clearResponseHeaders();
		if (class_exists('Q_Response', false)) Q_Response::clear();
		ob_start();
		$status = 200;
		$headers = array();
		try {
			if (class_exists('Q_Dispatcher', false)) {
				// Full Qbix Platform mode
				Q_Dispatcher::dispatch();
			} else {
				// Standalone mode — execute PHP script directly
				$scriptPath = $parsed['_scriptPath'] ?? $_SERVER['SCRIPT_FILENAME'];
				if (is_file($scriptPath)) {
					include $scriptPath;
				} else {
					$status = 404;
					echo 'Not Found';
				}
			}
			$headers = Q::getResponseHeaders();
			$code = http_response_code();
			if ($code && $code !== 200) $status = $code;
			if (Q::$_responseCode !== 200) $status = Q::$_responseCode;
		} catch (\Throwable $e) {
			$status = 500;
			ob_clean();
			echo json_encode(array('error' => $e->getMessage()));
			$headers['Content-Type'] = 'application/json';
		}
		$body = ob_get_clean();
		header_remove();
		list($_SERVER, $_GET, $_POST, $_REQUEST, $_COOKIE) = $saved;

		// Process Merkle cache headers (strips X-Q-Cache-* from response)
		if (Q_WebServer_Cache_Components::enabled()) {
			$pageKey = $parsed['path'] . '?' . ($parsed['query'] ?? '');
			Q_WebServer_Cache_Components::processResponseHeaders($pageKey, $headers);
		}

		return compact('status', 'body', 'headers');
	}

	// ── Request parsing ──────────────────────────────────

	static function parseRequest($raw)
	{
		$headerEnd = strpos($raw, "\r\n\r\n");
		$headerBlock = substr($raw, 0, $headerEnd);
		$body = substr($raw, $headerEnd + 4);

		// Fast request line parse
		$rlEnd = strpos($headerBlock, "\r\n");
		$requestLine = $rlEnd !== false ? substr($headerBlock, 0, $rlEnd) : $headerBlock;

		if (!preg_match('#^(\w+)\s+([^\s]+)\s+HTTP/(\d\.\d)#', $requestLine, $m)) {
			return array(
				'method' => 'GET', 'uri' => '/', 'path' => '/',
				'query' => '', 'headers' => array(), 'body' => '',
				'httpVersion' => '1.0', '_malformed' => true
			);
		}
		$method = strtoupper($m[1]);
		$uri = $m[2];
		$httpVersion = $m[3];

		// Fast path parsing — avoid parse_url for simple paths
		$qPos = strpos($uri, '?');
		if ($qPos !== false) {
			$path = urldecode(substr($uri, 0, $qPos));
			$query = substr($uri, $qPos + 1);
		} else {
			$path = urldecode($uri);
			$query = '';
		}
		// Collapse double slashes
		if (strpos($path, '//') !== false) {
			$path = preg_replace('#/+#', '/', $path);
		}

		// Fast header parsing — scan for common headers first
		$headers = array();
		$pos = $rlEnd !== false ? $rlEnd + 2 : strlen($headerBlock);
		$len = strlen($headerBlock);
		while ($pos < $len) {
			$nlPos = strpos($headerBlock, "\r\n", $pos);
			if ($nlPos === false) $nlPos = $len;
			$colonPos = strpos($headerBlock, ':', $pos);
			if ($colonPos !== false && $colonPos < $nlPos) {
				$k = strtolower(substr($headerBlock, $pos, $colonPos - $pos));
				$v = ltrim(substr($headerBlock, $colonPos + 1, $nlPos - $colonPos - 1));
				$headers[$k] = $v;
			}
			$pos = $nlPos + 2;
		}

		// HTTP/1.0 defaults to Connection: close
		if ($httpVersion === '1.0' && !isset($headers['connection'])) {
			$headers['connection'] = 'close';
		}
		return compact('method', 'uri', 'path', 'query', 'headers', 'body', 'httpVersion');
	}

	/**
	 * Parse multipart/form-data body into $_POST and $_FILES arrays.
	 * Handles file uploads by writing to temp files (same as php-fpm).
	 * @method parseMultipart
	 * @static
	 * @param {string} $contentType Full Content-Type header value
	 * @param {string} $body Raw request body
	 * @param {array} &$post Populated with form field values
	 * @param {array} &$files Populated with file upload entries
	 */
	static function parseMultipart($contentType, $body, &$post, &$files)
	{
		// Extract boundary from Content-Type
		if (!preg_match('/boundary=(?:"([^"]+)"|([^\s;]+))/i', $contentType, $bm)) {
			return;
		}
		$boundary = '--' . ($bm[1] ?: $bm[2]);
		$endBoundary = $boundary . '--';

		$parts = explode($boundary, $body);
		array_shift($parts); // before first boundary

		foreach ($parts as $part) {
			$part = ltrim($part, "\r\n");
			if ($part === '--' || $part === "--\r\n" || $part === '') continue;
			if (strpos($part, '--') === 0) continue; // end boundary

			// Split headers from body
			$headerEnd = strpos($part, "\r\n\r\n");
			if ($headerEnd === false) continue;

			$headerBlock = substr($part, 0, $headerEnd);
			$partBody = substr($part, $headerEnd + 4);
			// Remove trailing \r\n
			if (substr($partBody, -2) === "\r\n") {
				$partBody = substr($partBody, 0, -2);
			}

			// Parse part headers
			$partHeaders = array();
			foreach (explode("\r\n", $headerBlock) as $line) {
				$colonPos = strpos($line, ':');
				if ($colonPos !== false) {
					$k = strtolower(trim(substr($line, 0, $colonPos)));
					$v = trim(substr($line, $colonPos + 1));
					$partHeaders[$k] = $v;
				}
			}

			$disp = $partHeaders['content-disposition'] ?? '';
			if (strpos($disp, 'form-data') === false) continue;

			// Extract name
			$name = null;
			if (preg_match('/\bname="([^"]*)"/', $disp, $nm)) {
				$name = $nm[1];
			} elseif (preg_match("/\bname='([^']*)'/", $disp, $nm)) {
				$name = $nm[1];
			}
			if ($name === null) continue;

			// Check if it's a file upload
			$filename = null;
			if (preg_match('/\bfilename="([^"]*)"/', $disp, $fm)) {
				$filename = $fm[1];
			} elseif (preg_match("/\bfilename='([^']*)'/", $disp, $fm)) {
				$filename = $fm[1];
			}

			if ($filename !== null) {
				// File upload — write to temp file
				$tmpPath = tempnam(sys_get_temp_dir(), 'qbix_upload_');
				file_put_contents($tmpPath, $partBody);

				$fileEntry = array(
					'name'     => $filename,
					'type'     => $partHeaders['content-type'] ?? 'application/octet-stream',
					'tmp_name' => $tmpPath,
					'error'    => UPLOAD_ERR_OK,
					'size'     => strlen($partBody),
				);

				// Handle array notation: files[0], files[photo], etc.
				if (preg_match('/^([^\[]+)\[([^\]]*)\]$/', $name, $am)) {
					$files[$am[1]]['name'][$am[2]] = $fileEntry['name'];
					$files[$am[1]]['type'][$am[2]] = $fileEntry['type'];
					$files[$am[1]]['tmp_name'][$am[2]] = $fileEntry['tmp_name'];
					$files[$am[1]]['error'][$am[2]] = $fileEntry['error'];
					$files[$am[1]]['size'][$am[2]] = $fileEntry['size'];
				} else {
					$files[$name] = $fileEntry;
				}
			} else {
				// Regular form field
				// Handle array notation: tags[], data[key], etc.
				if (preg_match('/^([^\[]+)\[([^\]]*)\]$/', $name, $am)) {
					if ($am[2] === '') {
						$post[$am[1]][] = $partBody;
					} else {
						$post[$am[1]][$am[2]] = $partBody;
					}
				} else {
					$post[$name] = $partBody;
				}
			}
		}
	}

	// ── Response helpers ─────────────────────────────────

	static function sendResponse($client, $status, $body, $type = 'text/plain; charset=utf-8', $extra = array())
	{
		static $reasons = array(
			200=>'OK', 301=>'Moved Permanently', 304=>'Not Modified',
			400=>'Bad Request', 403=>'Forbidden', 404=>'Not Found',
			413=>'Payload Too Large', 429=>'Too Many Requests',
			431=>'Request Header Fields Too Large',
			500=>'Internal Server Error', 502=>'Bad Gateway'
		);
		self::$lastStatus = $status;
		self::$lastBody = $body;
		$body = (string) $body;
		$conn = $extra['Connection'] ?? 'keep-alive';
		unset($extra['Connection']);
		$out = "HTTP/1.1 $status " . ($reasons[$status] ?? 'OK')
			. "\r\nContent-Type: $type\r\nContent-Length: " . strlen($body)
			. "\r\nConnection: $conn\r\n";
		foreach ($extra as $k => $v) $out .= "$k: $v\r\n";
		@fwrite($client, $out . "\r\n" . $body);
	}

	private static function sendRedirect($client, $loc) {
		@fwrite($client, "HTTP/1.1 301 Moved Permanently\r\nLocation: $loc\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
		self::$lastStatus = 301;
	}

	private static function sendNotModified($client, $etag, $mtime, $keepAlive = false) {
		$conn = $keepAlive ? 'keep-alive' : 'close';
		@fwrite($client, "HTTP/1.1 304 Not Modified\r\nETag: $etag\r\n"
			. "Last-Modified: " . gmdate('D, d M Y H:i:s', $mtime) . " GMT\r\n"
			. "Cache-Control: public, max-age=0, must-revalidate\r\nContent-Length: 0\r\nConnection: $conn\r\n\r\n");
		self::$lastStatus = 304;
	}

	private static function render404($path)
	{
		$safe = htmlspecialchars($path, ENT_QUOTES);
		return "<!DOCTYPE html><html><head><title>404</title>"
			. "<style>body{font-family:sans-serif;padding:40px;text-align:center;color:#666}"
			. "h1{font-size:72px;color:#ddd}p{margin-top:12px}</style></head>"
			. "<body><h1>404</h1><p>{$safe} not found</p></body></html>";
	}

	// ── Path resolution ──────────────────────────────────

	/**
	 * Parse a Cookie header string into an associative array
	 * @method parseCookieHeader
	 * @static
	 * @param {string} $header The raw Cookie header value
	 * @return {array} name => value pairs
	 */
	static function parseCookieHeader($header)
	{
		$cookies = array();
		if (empty($header)) return $cookies;
		$pairs = explode(';', $header);
		foreach ($pairs as $pair) {
			$pair = trim($pair);
			if ($pair === '') continue;
			$eq = strpos($pair, '=');
			if ($eq === false) {
				$cookies[$pair] = '';
			} else {
				$name = trim(substr($pair, 0, $eq));
				$value = trim(substr($pair, $eq + 1));
				$cookies[$name] = urldecode($value);
			}
		}
		return $cookies;
	}

	/**
	 * Check rate limit for a client IP. Returns true if allowed, false if over limit.
	 * Configured via Q.webserver.rateLimit:
	 *   { "enabled": true, "requests": 100, "window": 60, "burstRequests": 20, "burstWindow": 1 }
	 * @method checkRateLimit
	 * @static
	 * @param {string} $ip Client IP address
	 * @return {boolean} true if request is allowed
	 */
	static function checkRateLimit($ip)
	{
		static $rateLimitEnabled = null;
		if ($rateLimitEnabled === null) $rateLimitEnabled = Q_Config::get('Q', 'webserver', 'rateLimit', 'enabled', false);
		if (!$rateLimitEnabled) {
			return true;
		}
		$now = time();
		static $maxReqs = null;
		if ($maxReqs === null) $maxReqs = Q_Config::get('Q', 'webserver', 'rateLimit', 'requests', 100);
		static $window = null;
		if ($window === null) $window = Q_Config::get('Q', 'webserver', 'rateLimit', 'window', 60);
		static $burstReqs = null;
		if ($burstReqs === null) $burstReqs = Q_Config::get('Q', 'webserver', 'rateLimit', 'burstRequests', 20);
		static $burstWindow = null;
		if ($burstWindow === null) $burstWindow = Q_Config::get('Q', 'webserver', 'rateLimit', 'burstWindow', 1);

		// Clean old entries
		if (!isset(self::$rateLimitData[$ip])) {
			self::$rateLimitData[$ip] = array();
		}
		$hits = &self::$rateLimitData[$ip];
		$cutoff = $now - $window;
		$hits = array_filter($hits, function ($t) use ($cutoff) {
			return $t >= $cutoff;
		});

		// Check window limit
		if (count($hits) >= $maxReqs) {
			return false;
		}

		// Check burst limit
		$burstCutoff = $now - $burstWindow;
		$recent = array_filter($hits, function ($t) use ($burstCutoff) {
			return $t >= $burstCutoff;
		});
		if (count($recent) >= $burstReqs) {
			return false;
		}

		$hits[] = $now;

		// Periodic cleanup: remove IPs not seen in the last window
		if (mt_rand(0, 99) < 5) { // 5% chance per request
			foreach (self::$rateLimitData as $k => $v) {
				if (empty($v) || max($v) < $cutoff) {
					unset(self::$rateLimitData[$k]);
				}
			}
		}

		return true;
	}

	private static function resolveStatic($urlPath)
	{
		// Path resolution cache — avoids repeated realpath() syscalls
		static $pathCache = array();
		if (isset($pathCache[$urlPath])) {
			$cached = $pathCache[$urlPath];
			// Quick mtime check for invalidation (cheaper than realpath)
			if ($cached === null || file_exists($cached)) {
				return $cached;
			}
			unset($pathCache[$urlPath]);
		}

		$rel = str_replace('/', DS, ltrim($urlPath, '/'));
		// Block null bytes (directory traversal via null byte injection)
		if (strpos($rel, "\0") !== false) return null;
		$fsPath = realpath(self::$rootDir . $rel);
		if (!$fsPath) {
			// Cache negative results too (404s won't re-stat)
			if (count($pathCache) < 10000) $pathCache[$urlPath] = null;
			return null;
		}
		$fsPath = str_replace(array('/','\\'), DS, $fsPath);
		$root = rtrim(self::$rootDir, DS);
		if ($fsPath !== $root && strncmp($fsPath, self::$rootDir, strlen(self::$rootDir)) !== 0) {
			$pathCache[$urlPath] = null;
			return null; // path traversal
		}
		$result = (is_dir($fsPath) || is_file($fsPath)) ? $fsPath : null;
		if (count($pathCache) < 10000) $pathCache[$urlPath] = $result;
		return $result;
	}

	private static function closeClient($key)
	{
		if (isset(self::$clientWatchers[$key])) {
			Q_Evented::cancel(self::$clientWatchers[$key]);
			unset(self::$clientWatchers[$key]);
		}
		if (isset(self::$timeoutWatchers[$key])) {
			Q_Evented::cancel(self::$timeoutWatchers[$key]);
			unset(self::$timeoutWatchers[$key]);
		}
		if (isset(self::$clients[$key])) {
			@fclose(self::$clients[$key]);
			unset(self::$clients[$key]);
		}
		unset(self::$buffers[$key], self::$clientInfo[$key],
			self::$keepAliveCount[$key]);
	}

	// ── State ────────────────────────────────────────────

	private static $socket = null;
	private static $tlsSocket = null;
	private static $tlsWatcher = null;
	private static $tlsPending = array();
	private static $httpsPort = 0;
	static $clients = array();
	static $clientWatchers = array();
	private static $buffers = array();
	private static $clientInfo = array();      // key => [ip, connectTime]
	private static $keepAliveCount = array();   // key => int
	private static $timeoutWatchers = array();  // key => evented timer id
	private static $acceptWatcher = null;
	private static $running = false;
	private static $lastStatus = 200;
	private static $lastBody = '';
	/** @internal pid => start_time for request timeout enforcement */
	static $workerPids = array();

	static $allowedExtensions = array(
		'html','htm','txt','md','json','xml','yaml','yml','csv','tsv','log',
		'css','js','mjs','map','wasm',
		'png','gif','webp','jpg','jpeg','svg','bmp','ico','avif',
		'woff','woff2','ttf','otf',
		'mp3','wav','ogg','mp4','webm',
		'pdf','zip'
	);
	private static $rateLimitData = array(); // ip => [timestamps]

	// ── Static file response cache ──────────────────────
	// Caches full response bytes (headers+body) keyed by fsPath.
	// Invalidated on mtime change. Saves stat/read/header-build per request.
	private static $fileCache = array();     // fsPath => [mtime, size, etag, responses => [connType => bytes]]
	private static $fileCacheSize = 0;       // total bytes in cache
	private static $fileCacheMaxSize = 67108864; // 64MB default, configurable
	private static $fileCacheMaxFile = 1048576;  // don't cache files > 1MB
	private static $fileCacheCheckInterval = 1;  // seconds between mtime checks
	private static $fileCacheLastCheck = 0;
}
