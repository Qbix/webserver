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

	/**
	 * Search paths for app/user files.
	 *
	 * Q::$paths is declared by the webserver's STANDALONE shim (src/Q.php), not
	 * by the Platform's Q.php. In --app mode the Platform's Q is authoritative,
	 * so dereferencing Q::$paths there raised
	 * "Access to undeclared static property Q::$paths" and turned every request
	 * into a 500. Use this instead of touching the property directly.
	 */
	static function paths()
	{
		// NOTE: read via reflection rather than Q::$paths directly. The property is
		// declared by this repo's standalone shim, NOT by the Platform's Q.php, and
		// naming it directly is exactly the coupling this class exists to avoid.
		if (property_exists('Q', 'paths')) {
			$declared = (new ReflectionClass('Q'))->getStaticPropertyValue('paths', null);
			if (is_array($declared)) {
				return $declared;
			}
		}
		$paths = array();
		if (defined('APP_DIR') && APP_DIR) { $paths[] = APP_DIR; }
		if (defined('Q_DIR') && Q_DIR)     { $paths[] = Q_DIR; }
		return $paths;
	}

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
	/**
	 * Refuse to run without per-request process isolation.
	 * Qbix (and ordinary PHP) assume one request per process. Fork gives that
	 * on Unix, php-cgi on Windows. With neither, a process would serve many
	 * requests and inherit the previous one's state -- which fails silently on
	 * the SECOND request, the worst possible shape to debug.
	 * @method requireIsolation
	 * @static
	 */
	static function requireIsolation()
	{
		if (function_exists('pcntl_fork')) {
			return true;
		}
		$bin = Q_Config::get('Q', 'webserver', 'cgi', 'binary', null);
		if (!$bin) {
			foreach (array('php-cgi','php-cgi8.3','php-cgi8.2','php-cgi8.1') as $b) {
				$w = @shell_exec("command -v $b 2>/dev/null");
				if (trim((string) $w) !== '') { $bin = trim($w); break; }
			}
		}
		if ($bin) {
			return true;
		}
		fwrite(STDERR, "\n  ERROR: no per-request process isolation available.\n"
			. "  Neither the pcntl extension (fork) nor a php-cgi binary was found.\n"
			. "  Install one, or set Q.webserver.cgi.binary in your config.\n\n");
		exit(1);
	}

	static function start($dir, $host = '0.0.0.0', $port = 8080, $workers = 0)
	{
		self::requireIsolation();
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

		// Initialize dashboard stats (uptime tracking)
		Q_WebServer_Dashboard::init();

		if ($ext = Q_Config::get('Q', 'webserver', 'extensions', null)) {
			self::$allowedExtensions = $ext;
		}

		// Read PHP ini limits — respect the same limits php-fpm would use
		self::$maxPostSize = self::parseIniBytes(
			Q_Config::get('Q', 'webserver', 'postMaxSize', ini_get('post_max_size') ?: '8M')
		);
		self::$maxUploadSize = self::parseIniBytes(
			Q_Config::get('Q', 'webserver', 'uploadMaxFilesize', ini_get('upload_max_filesize') ?: '2M')
		);
		self::$maxFileUploads = (int) Q_Config::get(
			'Q', 'webserver', 'maxFileUploads', ini_get('max_file_uploads') ?: 20
		);
		self::$fileUploads = (bool) Q_Config::get(
			'Q', 'webserver', 'fileUploads', ini_get('file_uploads') !== '0'
		);

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
			Q_Evented::onSignal(SIGHUP, function () {
				echo "\n  Reloading (SIGHUP)...\n";
				self::stop();
				Q_Evented::stop();
				// Re-exec with same arguments
				$args = $_SERVER['argv'] ?? array();
				if (function_exists('pcntl_exec')) {
					pcntl_exec(PHP_BINARY, $args);
				}
			});
			// Reap zombie children from fork-per-request PHP execution
			Q_Evented::onSignal(SIGCHLD, function () {
				while (($pid = pcntl_waitpid(-1, $st, WNOHANG)) > 0) {
					$info = Q_WebServer::$workerPids[$pid] ?? null;
					unset(Q_WebServer::$workerPids[$pid]);
					if (!$info) continue;

					$startTime = is_array($info) ? $info['time'] : $info;
					$ms = round((microtime(true) - $startTime) * 1000, 1);
					$method = is_array($info) ? ($info['method'] ?? 'GET') : 'GET';
					$uri = is_array($info) ? ($info['uri'] ?? '/') : '/';

					// Check if child exited abnormally
					if (!pcntl_wifexited($st) || pcntl_wexitstatus($st) !== 0) {
						// Worker crashed or was killed — record as 502
						Q_WebServer_Dashboard::recordRequest($method, $uri, 502, $ms, 0, true);
					} else {
						// Normal exit — child already sent the response and recorded nothing
						// The child handles recording on its own via exit(0)
					}
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
				foreach (Q_WebServer::$workerPids as $pid => $info) {
					$startTime = is_array($info) ? $info['time'] : $info;
					if ($now - $startTime > $timeout) {
						$ms = round(($now - $startTime) * 1000, 1);
						$method = is_array($info) ? ($info['method'] ?? 'GET') : 'GET';
						$uri = is_array($info) ? ($info['uri'] ?? '/') : '/';
						@posix_kill($pid, SIGKILL);
						unset(Q_WebServer::$workerPids[$pid]);
						Q_WebServer_Dashboard::recordRequest($method, $uri, 504, $ms, 0, true);
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

		// Hot reload — watch for file changes in classes/, handlers/, config/
		if (Q_Config::get('Q', 'webserver', 'hotReload', false)) {
			Q_HotReload::init();
			Q_Evented::repeat(2, function () {
				Q_HotReload::check();
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


	/**
	 * Whether a buffer holds a COMPLETE request (headers plus, for methods
	 * that carry one, the whole body). Used to decide if another socket read
	 * is needed. Checking only for "\r\n\r\n" treats a partially-received
	 * POST as finished, which truncates bodies larger than one read.
	 *
	 * @method requestComplete
	 * @static
	 * @param {string} $buf
	 * @return {boolean}
	 */
	static function requestComplete($buf)
	{
		$headerEnd = strpos($buf, "\r\n\r\n");
		if ($headerEnd === false) return false;
		if ($buf[0] !== 'P') return true;   // only POST/PUT/PATCH carry a body
		if (preg_match('/transfer-encoding:\s*chunked/i', $buf)) {
			$bodyPart = substr($buf, $headerEnd + 4);
			return strpos($bodyPart, "\r\n0\r\n") !== false
				|| strpos($bodyPart, "\n0\n") !== false;
		}
		$cl = 0;
		if (preg_match('/content-length:\s*(\d+)/i', $buf, $m)) $cl = (int) $m[1];
		if ($cl <= 0) return true;
		return (strlen($buf) - $headerEnd - 4) >= $cl;
	}

	static function onClientData($client)
	{
		$key = (int) $client;
		if (!isset(self::$clients[$key])) return;

		// Check if we already have a complete request from pipelining.
		//
		// Headers alone are NOT enough: a large POST arrives over several TCP
		// segments, so after the first read the buffer holds "\r\n\r\n" plus a
		// partial body. Treating that as pipelined skipped the next fread(),
		// and the loop then returned waiting for bytes it had stopped reading
		// -- so any body larger than one 64KB read was silently truncated.
		// Only skip the read when the FULL body is already buffered.
		$buf = self::$buffers[$key] ?? '';
		$havePipelined = ($buf !== '' && self::requestComplete($buf));

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
			$isChunked = (bool) preg_match('/transfer-encoding:\s*chunked/i', $buf);
			if (preg_match('/content-length:\s*(\d+)/i', $buf, $m)) {
				$cl = (int) $m[1];
			}
			// Respect PHP's post_max_size (default 8M)
			if ($cl > self::$maxPostSize) {
				self::sendResponse($client, 413, 'Payload Too Large');
				self::closeClient($key);
				return;
			}
			if ($isChunked) {
				// Chunked: wait for terminating 0\r\n\r\n
				$bodyPart = substr($buf, $headerEnd + 4);
				if (strpos($bodyPart, "\r\n0\r\n") === false && strpos($bodyPart, "\n0\n") === false) return;
			} elseif ($cl > 0) {
				if (strlen($buf) - $headerEnd - 4 < $cl) return;
			}
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

		// Issue #1: reject wrong-case methods (e.g. "get" instead of "GET")
		if (!empty($parsed['_badMethod'])) {
			self::sendResponse($client, 501, 'Not Implemented');
			self::closeClient($key);
			return;
		}

		// Issue #2: reject invalid header names
		if (!empty($parsed['_badHeaders'])) {
			self::sendResponse($client, 400, 'Bad Request: Invalid header name');
			self::closeClient($key);
			return;
		}

		// Issue #2: reject duplicate Host headers (common attack vector)
		if (!empty($parsed['_duplicateHost'])) {
			self::sendResponse($client, 400, 'Bad Request: Multiple Host headers');
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
			$savedRoot = self::$rootDir;
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
		} finally {
			// Restore rootDir after vhost override
			if (self::$rootDir !== $savedRoot) {
				self::$rootDir = $savedRoot;
			}
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
		// Skip if request was delegated to a forked child (-1)
		// The child's exit status is recorded in the SIGCHLD handler
		if (self::$lastStatus !== -1) {
			Q_WebServer_Dashboard::recordRequest(
				$parsed['method'], $parsed['uri'], self::$lastStatus, $ms, self::$lastBytes
			);
			self::$lastBytes = 0;
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
		}
		self::$lastStatus = 200; // reset for next request

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

		if ($path === '/Q/event' && $method === 'POST') {
			return self::handleRemoteEvent($parsed);
		}

		// Server discovery — .well-known/qbix
		if (strpos($path, '/.well-known/') === 0) {
			$wellKnown = substr($path, 13);
			if ($wellKnown === 'qbix' || $wellKnown === 'qbix.json') {
				return self::wellKnownQbix($parsed);
			}
			if ($wellKnown === 'openapi.json' || $wellKnown === 'openapi') {
				return self::wellKnownOpenAPI($parsed);
			}
			if ($wellKnown === 'mcp.json' || $wellKnown === 'mcp') {
				return self::wellKnownMCP($parsed);
			}
			if (strpos($wellKnown, 'openclaiming/') === 0) {
				return self::wellKnownOpenClaiming($parsed, $wellKnown);
			}
		}

		if ($path === '/Q/health') {
			if (Q_Config::get('Q', 'dashboard', null) === false) {
				return array('status' => 404, 'body' => 'Not found');
			}
			$stats = Q_WebServer_Dashboard::getStats();
			return array('status'=>200, 'body'=>json_encode(array('status'=>'ok')+$stats),
				'headers'=>array('Content-Type'=>'application/json'));
		}
		if ($path === '/Q/dashboard' || $path === '/Q/dashboard/') {
			if (Q_Config::get('Q', 'dashboard', null) === false) {
				return array('status' => 404, 'body' => 'Not found');
			}
			$token = Q_Config::get('Q', 'dashboard', 'token', null);
			if ($token !== null) {
				$qp = array();
				if (!empty($parsed['query'])) parse_str($parsed['query'], $qp);
				$given = $qp['token'] ?? '';
				if ($given !== $token) {
					return array('status' => 403, 'body' => 'Forbidden — token required',
						'headers' => array('Content-Type' => 'text/plain'));
				}
			}
			return array('status'=>200, 'body'=>Q_WebServer_Dashboard::renderHtml($parsed),
				'headers'=>array('Content-Type'=>'text/html; charset=utf-8'));
		}
		if (self::isBlocked($path)) {
			return array('status'=>403, 'body'=>self::renderErrorPage(403, $path),
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
				// Root path with no index → show welcome page
				if ($path === '/') {
					$welcome = __DIR__ . DS . 'welcome.php';
					if (file_exists($welcome)) {
						ob_start();
						include $welcome;
						return array('status' => 200, 'body' => ob_get_clean(),
							'headers' => array('Content-Type' => 'text/html; charset=utf-8'));
					}
				}
				// Show directory listing if indexed
				if (self::isIndexed($path) || $path === '/') {
					return array('status'=>200,
						'body'=>self::renderDirectoryListing($fsPath, $path),
						'headers'=>array('Content-Type'=>'text/html; charset=utf-8',
							'Cache-Control'=>'no-store'));
				}
				return array('status'=>403, 'body'=>self::renderErrorPage(403, $path),
					'headers'=>array('Content-Type'=>'text/plain'));
			}
		}

		// BUG #37: split "script.php/extra/path" into SCRIPT + PATH_INFO, the way
		// nginx's fastcgi_split_path_info does. Without this, a request for
		// /action.php/Safebox/workload is not a file, so it fell through to the
		// clean-URL front controller and every framework action URL rendered the
		// notFound page instead of dispatching. That broke the entire Node->PHP
		// contract (Q.Utils.sendToPHP posts to action.php/<Module>/<action>).
		if (!$fsPath || !is_file($fsPath)) {
			$_pi = self::splitPathInfo($path);
			if ($_pi !== null) {
				$parsed['_scriptPath'] = $_pi['scriptPath'];
				$parsed['_pathInfo']   = $_pi['pathInfo'];
				$response = self::dispatchToQ($parsed);
				$response = self::processPhpResponse($response, $parsed['headers']);
				return $response;
			}
		}

		// File
		// Take the extension from the RESOLVED file, not the URL. A directory
		// request like "/" has no extension in $path, but $fsPath has already been
		// resolved to the directory index (.../index.php) above. Reading $path here
		// meant "/" fell past the PHP branch into the static branch, where an empty
		// extension is not in $allowedExtensions — so the app's own home page came
		// back 403 while "/index.php" served fine.
		$ext = strtolower(pathinfo($fsPath ? $fsPath : $path, PATHINFO_EXTENSION));
		if ($fsPath && is_file($fsPath)) {

			if ($ext === 'php') {
				// PHP dispatch (in-process — amphp uses fibers for concurrency)
				// Run the *requested* script (e.g. action.php), not index.php.
				$parsed['_scriptPath'] = $fsPath;
				$response = self::dispatchToQ($parsed);
				$response = self::processPhpResponse($response, $parsed['headers']);
				Q_WebServer_Cache::put($parsed, $response);
				return $response;
			}

			if (in_array($ext, self::$allowedExtensions)
				&& ($method === 'GET' || $method === 'HEAD')
			) {
				// Image resize/convert: ?w=300 or ?w=300&h=200
				if (in_array($ext, array('png','jpg','jpeg','gif','webp','bmp','avif'))
					&& !empty($parsed['query'])
				) {
					$imgResponse = Q_WebServer_Image::handle($fsPath, $path, $parsed);
					if ($imgResponse) return $imgResponse;
				}
				return self::buildFileResponse($fsPath, $ext, $method, $parsed['headers']);
			}
		}

		// Image format conversion: /photo.webp when only /photo.png exists
		$imgExts = array('webp', 'avif', 'jpg', 'jpeg', 'png', 'gif');
		if (in_array($ext, $imgExts) && ($method === 'GET' || $method === 'HEAD')) {
			$imgResponse = Q_WebServer_Image::handle(null, $path, $parsed);
			if ($imgResponse) return $imgResponse;
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
		$passthrough = Q_Config::get(
			'Q', 'webserver', 'accel', 'passthrough', false
		);
		$headers = $passthrough
			? Q_WebServer_Headers::stripInternalExceptAccel($response['headers'] ?? array())
			: Q_WebServer_Headers::stripInternal($response['headers'] ?? array());
		$body = $response['body'] ?? '';

		// X-Accel-Redirect
		if (!$passthrough) {
			foreach ($response['headers'] ?? array() as $k => $v) {
				if (strtolower($k) === 'x-accel-redirect') {
					// Standalone: read the file and replace the body
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
		}
		// In passthrough mode, X-Accel-Redirect stays in $headers for the
		// reverse proxy (nginx) to intercept and serve with sendfile().

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
		if ($method === 'HEAD') {
			$body = '';
		} else {
			// Try the pre-compressed LRU cache first (build once, serve cached).
			$body = Q_WebServer_Precompress::serve(
				$fsPath, $ct, $mtime, $size, $reqHeaders, $headers
			);
			if ($body === null) {
				// Not eligible / disabled — read and compress on the fly as before.
				$body = file_get_contents($fsPath);
				$body = Q_WebServer_Headers::maybeCompress($body, $ct, $reqHeaders, $headers);
			}
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

		// Virtual hosts — override rootDir based on Host header
		$host = $parsed['headers']['host'] ?? '';
		$host = strtolower(preg_replace('/:\d+$/', '', $host)); // strip port
		$hostConfig = Q_Config::get('Q', 'webserver', 'hosts', $host, null);
		if ($hostConfig && isset($hostConfig['root'])) {
			$vroot = realpath($hostConfig['root']);
			if ($vroot && is_dir($vroot)) {
				self::$rootDir = rtrim(str_replace(array('/', '\\'), DS, $vroot), DS) . DS;
			}
		}

		// 1. Dashboard + Panel + WebSocket + Health (/Q/*)
		// 1. Serve built-in assets (JS clients, logo, bundled frontend)
		$assetMap = array();
		$jsPath = Q_Config::get('Q', 'socket', 'js', '/Q/socket.js');
		if ($jsPath !== false) $assetMap[$jsPath] = array(__DIR__ . DS . 'socket.js', 'application/javascript');
		$ioPath = Q_Config::get('Q', 'socket', 'io', '/socket.io');
		if ($ioPath !== false) $assetMap[$ioPath . '/socket.io.js'] = array(__DIR__ . DS . 'socket.io.js', 'application/javascript');
		$assetMap['/Q/logo.svg'] = array(__DIR__ . DS . 'logo.svg', 'image/svg+xml');
		$assetMap['/Q/logo.png'] = array(__DIR__ . DS . 'logo.png', 'image/png');
		$assetMap['/Q/prism.js'] = array(__DIR__ . DS . 'prism.js', 'application/javascript');
		$assetMap['/Q/prism.css'] = array(__DIR__ . DS . 'prism.css', 'text/css');
		if (isset($assetMap[$path])) {
			list($assetFile, $assetType) = $assetMap[$path];
			if (file_exists($assetFile)) {
				self::sendResponse($client, 200, file_get_contents($assetFile),
					$assetType, array('Cache-Control' => 'public, max-age=86400'));
			} else {
				self::sendResponse($client, 404, 'Not found');
			}
			return false;
		}

		// Serve bundled Qbix frontend: /Q/plugins/Q/js/*, /Q/plugins/Q/text/*
		if (strpos($path, '/Q/plugins/') === 0) {
			$relPath = substr($path, 11); // strip /Q/plugins/
			$bundledFile = __DIR__ . DS . 'plugins' . DS . str_replace('/', DS, $relPath);
			if (file_exists($bundledFile) && is_file($bundledFile)) {
				$ext = strtolower(pathinfo($bundledFile, PATHINFO_EXTENSION));
				$mimeMap = array(
					'js' => 'application/javascript', 'json' => 'application/json',
					'css' => 'text/css', 'html' => 'text/html',
					'png' => 'image/png', 'jpg' => 'image/jpeg',
					'svg' => 'image/svg+xml', 'woff2' => 'font/woff2',
				);
				$mime = $mimeMap[$ext] ?? 'application/octet-stream';
				self::sendResponse($client, 200, file_get_contents($bundledFile),
					$mime, array('Cache-Control' => 'public, max-age=86400'));
				return false;
			}
		}

		// 2. Server discovery + federation endpoints
		// RFC 8615 .well-known endpoints
		if (strpos($path, '/.well-known/') === 0) {
			$wellKnown = substr($path, 13);

			$wkResponse = null;
			if ($wellKnown === 'qbix' || $wellKnown === 'qbix.json') {
				$wkResponse = self::wellKnownQbix($parsed);
			} elseif ($wellKnown === 'openapi.json' || $wellKnown === 'openapi') {
				$wkResponse = self::wellKnownOpenAPI($parsed);
			} elseif ($wellKnown === 'mcp.json' || $wellKnown === 'mcp') {
				$wkResponse = self::wellKnownMCP($parsed);
			} elseif (strpos($wellKnown, 'openclaiming/') === 0) {
				$wkResponse = self::wellKnownOpenClaiming($parsed, $wellKnown);
			}

			if ($wkResponse) {
				self::sendResponse($client, $wkResponse['status'] ?? 200,
					$wkResponse['body'] ?? '',
					$wkResponse['headers']['Content-Type'] ?? 'application/json',
					$wkResponse['headers'] ?? array());
				return false;
			}
			// Fall through for other .well-known files (apple-app-site-association, acme, etc.)
		}
		if ($path === '/Q/event' && $method === 'POST') {
			$response = self::handleRemoteEvent($parsed);
			self::sendResponse($client, $response['status'] ?? 200, $response['body'] ?? '',
				'application/json');
			return false;
		}

		// 3. Dashboard + Panel + WebSocket + Health (/Q/*)
		if (strpos($path, '/Q/') === 0) {
			if ($path === '/Q/ws') {
				if (Q_Config::get('Q', 'dashboard', null) === false) {
					self::sendResponse($client, 404, 'Not found');
					return false;
				}
				$upgraded = Q_WebSocket::upgrade(
					$client, $parsed['headers'], null, 'dashboard'
				);
				return $upgraded; // true = keep open
			}
			if ($path === '/Q/health') {
				if (Q_Config::get('Q', 'dashboard', null) === false) {
					self::sendResponse($client, 404, 'Not found');
					return false;
				}
				$stats = Q_WebServer_Dashboard::getStats();
				self::sendResponse($client, 200,
					json_encode(array('status' => 'ok') + $stats),
					'application/json');
				return false;
			}
			// Panel (control panel + API)
			if ($path === '/Q/api/images/zip' && $method === 'POST') {
				self::handleBulkImageZip($client, $parsed);
				return false;
			}
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
			self::sendResponse($client, 403, self::renderErrorPage(403, $path), 'text/html; charset=utf-8');
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
				// Root path with no index → show welcome page
				if ($path === '/') {
					$welcome = __DIR__ . DS . 'welcome.php';
					if (file_exists($welcome)) {
						ob_start();
						include $welcome;
						self::sendResponse($client, 200, ob_get_clean(),
							'text/html; charset=utf-8');
						return false;
					}
				}
				// Show directory listing if indexed OR if it's the root path
				if (self::isIndexed($path) || $path === '/') {
					$html = self::renderDirectoryListing($fsPath, $path);
					self::sendResponse($client, 200, $html, 'text/html; charset=utf-8',
						array('Cache-Control' => 'no-store'));
				} else {
					self::sendResponse($client, 403, self::renderErrorPage(403, $path), 'text/html; charset=utf-8');
				}
				return false;
			}
		}

		// BUG #37: split "script.php/extra/path" into SCRIPT + PATH_INFO, the way
		// nginx's fastcgi_split_path_info does. /action.php/Safebox/workload is
		// not itself a file, so without this it fell through to the clean-URL
		// branch (index.php) and every framework action URL rendered the notFound
		// page instead of dispatching — which broke the whole Node->PHP contract,
		// since Q.Utils.sendToPHP posts to action.php/<Module>/<action>.
		if (!$fsPath || !is_file($fsPath)) {
			$_pi = self::splitPathInfo($path);
			if ($_pi !== null) {
				$parsed['_pathInfo'] = $_pi['pathInfo'];
				return self::handlePhp($client, $parsed, $_pi['scriptPath']);
			}
		}

		// 5. File handling
		// Extension from the RESOLVED file, not the URL. "/" carries no
		// extension, but $fsPath was already resolved to the directory index
		// (.../index.php). Reading $path here sent "/" down the STATIC path,
		// where serveStaticFile() rejects an unlisted extension — so the app's
		// home page returned 403 while "/index.php" served fine.
		$ext = strtolower(pathinfo($fsPath ? $fsPath : $path, PATHINFO_EXTENSION));
		if ($fsPath && is_file($fsPath)) {

			// PHP scripts → worker pool or in-process
			if ($ext === 'php') {
				return self::handlePhp($client, $parsed, $fsPath);
			}

			// Static file
			if ($method === 'GET' || $method === 'HEAD') {
				// Image resize/convert: ?w=300 or ?w=300&h=200
				if (in_array($ext, array('png','jpg','jpeg','gif','webp','bmp','avif'))
					&& !empty($parsed['query'])
				) {
					$imgResponse = Q_WebServer_Image::handle($fsPath, $path, $parsed);
					if ($imgResponse) {
						Q_WebServer_Headers::processResponse($client, $imgResponse, $parsed['headers']);
						return false;
					}
				}
				self::serveStaticFile($client, $fsPath, $method, $parsed['headers'], !empty($parsed['_keepAlive']));
				return false;
			}
		}

		// Image format conversion: /photo.webp when only /photo.png exists
		$imgExts = array('webp', 'avif', 'jpg', 'jpeg', 'png', 'gif');
		if (in_array($ext, $imgExts) && ($method === 'GET' || $method === 'HEAD')) {
			$imgResponse = Q_WebServer_Image::handle(null, $path, $parsed);
			if ($imgResponse) {
				Q_WebServer_Headers::processResponse($client, $imgResponse, $parsed['headers']);
				return false;
			}
		}

		// 6. Route dispatch — if Q.routes configured, match URL to handler
		//    Q_Uri caches compiled patterns and path→URI results in memory.
		// Q_WebServer_Router picks whichever Q_Uri entry point exists: our
		// fromPath() standalone, the Platform's from() in --app mode. Both read
		// the same Q/routes table, so the webserver's cases behave identically.
		$uri = Q_WebServer_Router::resolve($path);
		if ($uri) {
			return self::handleRoute($client, $parsed, $uri);
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
		Q_WebServer_State::setInput($rawBody);

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

			$headers = Q_WebServer::getResponseHeaders();
					$code = http_response_code();
					if ($code && $code !== 200) $status = $code;
					if (Q_WebServer::responseCode() !== 200) $status = Q_WebServer::responseCode();
				} catch (\Throwable $e) {
					$status = 500;
					ob_clean();
					echo json_encode(array('error' => $e->getMessage()));
					$headers['Content-Type'] = 'application/json';
				}

				$body = ob_get_clean();
				$_method = $parsed['method'] ?? 'GET';
				$response = compact('status', 'body', 'headers', '_method');
				Q_WebServer_Headers::processResponse($client, $response, $parsed['headers']);
				@fclose($client);
				exit($status >= 500 ? 1 : 0);
			} elseif ($pid > 0) {
				@fclose($client);
				$key = (int) $client;
				if (isset(self::$clientWatchers[$key])) {
					Q_Evented::cancel(self::$clientWatchers[$key]);
				}
				unset(self::$clientWatchers[$key], self::$clients[$key], self::$buffers[$key]);
				self::$workerPids[$pid] = array(
					'time' => microtime(true),
					'method' => $parsed['method'],
					'uri' => $parsed['uri'],
				);
				pcntl_waitpid($pid, $st, WNOHANG);
				self::$lastStatus = -1; // -1 = delegated to child, don't record in parent
				list($_SERVER, $_GET, $_POST, $_REQUEST, $_COOKIE) = $saved;
				return false;
			}
			// Fork failed — fall through to in-process
		}

		// EMERGENCY in-process fallback -- reached only when fork() itself failed
		// (EAGAIN/ENOMEM). This is the ONE path where a process serves more than
		// one request, so it must explicitly discard the previous request's
		// captured state. Everything else relies on per-request processes.
		// See requireIsolation(): we never get here without pcntl or php-cgi.
		if (class_exists('Q_Response', false)
		and method_exists('Q_Response', 'clear')) {
			Q_WebServer_State::clear();
		}
		if (class_exists('Q_Sapi', false)) {
			Q_Sapi::$captured = null;
			Q_Sapi::$delivered = false;
			Q_Sapi::$entered = false;
		}
		@error_log('Q_WebServer: fork failed, serving in-process (state cleared)');

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
			$headers = Q_WebServer::getResponseHeaders();
			$code = http_response_code();
			if ($code && $code !== 200) $status = $code;
			if (Q_WebServer::responseCode() !== 200) $status = Q_WebServer::responseCode();
		} catch (\Throwable $e) {
			$status = 500;
			ob_clean();
			echo json_encode(array('error' => $e->getMessage()));
			$headers['Content-Type'] = 'application/json';
		}

		$body = ob_get_clean();
		header_remove();
		list($_SERVER, $_GET, $_POST, $_REQUEST, $_COOKIE) = $saved;

		$_method = $parsed['method'] ?? 'GET';
		$response = compact('status', 'body', 'headers', '_method');
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
			// Item 2: without pcntl there is no fork, so per-request process
			// isolation can only come from php-cgi. Default to routing ALL php
			// through it there; `patterns` then means "exceptions". With pcntl
			// available the carveout stays opt-in.
			$_cgiDefault = function_exists('pcntl_fork') ? array() : array('*');
			$cgiPatterns = Q_Config::get('Q', 'webserver', 'cgi', 'patterns', $_cgiDefault);
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
				if (@preg_match(self::ensureRegex($pattern), $relPath)) {
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

				// A script calling exit()/die() unwinds straight past
				// dispatchToQ()'s return, so processResponse() below never ran
				// and the client received ZERO bytes -- while the access log
				// still said 200, because the parent had already assumed
				// success. Register the emit as a shutdown function so it
				// happens on every termination path: normal return, exit(),
				// uncaught exception and fatal error.
				$emitted = false;
				$emit = function ($response) use (&$emitted, $client, $parsed) {
					if ($emitted) return;      // exactly once
					$emitted = true;
					Q_WebServer_Headers::processResponse($client, $response, $parsed['headers']);
					@fclose($client);
				};
				register_shutdown_function(function () use ($emit) {
					// Reached only when dispatchToQ() did NOT return normally --
					// the Platform's exception handler echoes and then exits.
					// Read with ob_get_contents(): dispatchToQ() uses a
					// NON-REMOVABLE buffer, on which ob_get_clean() fails.
					$body = '';
					if (ob_get_level() > 0) {
						$body = (string) ob_get_contents();
					}
					while (@ob_end_clean()) { /* drop what we can */ }
					if ($body === '') {
						$body = Q_WebServer::$_capturedOutput;
					}
					Q_WebServer::$_capturedOutput = '';
					$status = Q_WebServer::responseCode();
					// Same recovery as dispatchToQ(): the Platform's exception
					// handler echoes the error page and then exits, so we never
					// reach the normal status resolution. The code lives on the
					// exception objects, not in http_response_code().
					if ((!$status or $status === 200)
					and class_exists('Q_Response', false)
					and method_exists('Q_Response', 'getErrors')) {
						try {
							foreach ((array) Q_Response::getErrors() as $err) {
								if (is_object($err) and !empty($err->httpResponseCode)) {
									$status = (int) $err->httpResponseCode;
									break;
								}
							}
						} catch (\Throwable $e) { /* non-fatal */ }
					}
					$err = error_get_last();
					if ($err && in_array($err['type'],
						array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
						$status = 500;
					}
					$emit(array(
						'status'  => $status ?: 200,
						'body'    => $body,
						'headers' => Q_WebServer::getResponseHeaders(),
					));
				});

				$response = self::dispatchToQ($parsed);
				$emit($response);
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
    if (isset($req['projectRoot']) && method_exists('Q', 'init')) Q::init($req['projectRoot']);
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
if (class_exists('Q_Request',false)) Q_WebServer_State::setInput($raw);
ob_start(); $status = 200; $headers = [];
try {
    if (is_file($req['scriptPath'])) include $req['scriptPath']; else { $status = 404; echo 'Not Found'; }
			$headers = Q_WebServer::getResponseHeaders();
    $code = http_response_code(); if (Q_WebServer::responseCode() !== 200) $status = Q_WebServer::responseCode(); if ($code) $status = $code;
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
				Q_WebServer::cleanupUploadFiles();
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
			self::sendResponse($client, 403, self::renderErrorPage(403, $path), 'text/html; charset=utf-8');
			return;
		}

		$connKey = $keepAlive ? 'ka' : 'cl';
		$now = microtime(true);

		// The cache key MUST include the negotiated content-encoding.
		// Keyed on $fsPath alone, whichever variant was stored first was then
		// served to everyone: a client that sent no Accept-Encoding poisoned
		// the entry so later gzip-capable clients got the uncompressed body --
		// and in the reverse order a client that cannot decompress received
		// gzipped bytes, which it has no way to read.
		$aeRaw = strtolower($reqHeaders['accept-encoding'] ?? '');
		$encKey = strpos($aeRaw, 'br') !== false ? 'br'
			: (strpos($aeRaw, 'gzip') !== false ? 'gzip' : 'id');
		$cacheKey = $fsPath . '|' . $encKey;

		// ── Try response cache ──
		if (isset(self::$fileCache[$cacheKey])) {
			$cached = &self::$fileCache[$cacheKey];
			// Revalidate mtime periodically
			if (($now - $cached['checked']) >= self::$fileCacheCheckInterval) {
				clearstatcache(true, $fsPath);
				if (filemtime($fsPath) !== $cached['mtime']) {
					self::$fileCacheSize -= $cached['bodyLen'] * 2;
					unset(self::$fileCache[$cacheKey]);
				} else {
					$cached['checked'] = $now;
				}
			}
		}

		if (isset(self::$fileCache[$cacheKey])) {
			$cached = &self::$fileCache[$cacheKey];
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
			self::$lastBytes = $cached['bodyLen'];
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
			self::$lastBytes = $preComp['size'];
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
				self::$lastBytes = strlen($body);
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
		self::$lastBytes = $size;
		$headStr = $keepAlive ? $kaHead : $clHead;
		@fwrite($client, $method === 'HEAD' ? $headStr : $headStr . $body);

		// Cache if small enough
		if ($size <= self::$fileCacheMaxFile
			&& self::$fileCacheSize + $size * 2 < self::$fileCacheMaxSize
		) {
			self::$fileCache[$cacheKey] = array(
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
		if ($patterns === null) {
			$patterns = Q_Config::get('Q', 'web', 'indexed', 'paths', array(
				'/img/' => true
			));
		}
		foreach ($patterns as $regex => $enabled) {
			if (preg_match(self::ensureRegex($regex), $urlPath)) return (bool) $enabled;
		}
		return false;
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
		$images = array();

		$imageExts = array('png','jpg','jpeg','gif','webp','svg','bmp');
		$videoExts = array('mp4','webm','ogg');
		$audioExts = array('mp3','wav','ogg');

		foreach ($items as $name) {
			if ($name === '.' || $name === '..') continue;
			if ($name[0] === '.') continue;

			$full = $dir . DS . $name;
			$href = htmlspecialchars($urlPath . $name, ENT_QUOTES);
			$safe = htmlspecialchars($name, ENT_QUOTES);

			if (is_dir($full)) {
				$dirs[] = array('name' => $safe, 'href' => $href . '/');
				continue;
			}

			$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
			if (!in_array($ext, self::$allowedExtensions)) continue;

			$size = filesize($full);
			$sizeStr = $size < 1024 ? "{$size} B"
				: ($size < 1048576 ? round($size/1024,1).' KB'
				: round($size/1048576,1).' MB');

			$isImage = in_array($ext, $imageExts);
			$isVideo = in_array($ext, $videoExts);
			$isAudio = in_array($ext, $audioExts);

			$fileInfo = array('name' => $safe, 'href' => $href, 'size' => $sizeStr, 'ext' => $ext,
				'isImage' => $isImage, 'isVideo' => $isVideo, 'isAudio' => $isAudio);
			$files[] = $fileInfo;

			if ($isImage && count($images) < $maxImages) {
				$dim = @getimagesize($full);
				$fileInfo['width'] = $dim ? $dim[0] : 0;
				$fileInfo['height'] = $dim ? $dim[1] : 0;
				$images[] = $fileInfo;
			}
		}

		// Check for user override: listing.php or listing.html
		// in the app root, similar to errors/ override
		$_path = $urlPath;
		$_dirs = $dirs;
		$_files = $files;
		$_images = $images;
		$_dir = $dir;

		foreach (Q_WebServer::paths() as $base) {
			$listingPhp = $base . DS . 'listing.php';
			if (file_exists($listingPhp)) {
				ob_start();
				include $listingPhp;
				return ob_get_clean();
			}
			$listingHtml = $base . DS . 'listing.html';
			if (file_exists($listingHtml)) {
				return file_get_contents($listingHtml);
			}
		}

		// Built-in listing
		$safePath = htmlspecialchars($urlPath, ENT_QUOTES);
		$isRoot = ($urlPath === '/');

		// Build file list HTML
		$listHtml = '';
		if (!$isRoot) {
			$listHtml .= '<a href="../" class="item dir up"><span class="icon">⬆</span><span class="name">Parent Directory</span></a>';
		}
		foreach ($dirs as $d) {
			$listHtml .= '<a href="' . $d['href'] . '" class="item dir"><span class="icon">📁</span><span class="name">' . $d['name'] . '/</span></a>';
		}
		foreach ($files as $f) {
			$icon = in_array($f['ext'], $imageExts) ? '🖼' : (in_array($f['ext'], $videoExts) ? '🎬' : (in_array($f['ext'], $audioExts) ? '🎵' : '📄'));
			$listHtml .= '<a href="' . $f['href'] . '" class="item file"><span class="icon">' . $icon . '</span><span class="name">' . $f['name'] . '</span><span class="size">' . $f['size'] . '</span></a>';
		}

		// Build image grid data as JSON for JS
		$imagesJson = json_encode($images, JSON_UNESCAPED_SLASHES);

		return <<<HTML
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Index of {$safePath}</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,system-ui,'Segoe UI',sans-serif;background:#f8f9fa;color:#333}
.layout{display:flex;min-height:100vh}
.sidebar{width:240px;background:#fff;border-right:1px solid #e9ecef;padding:12px;overflow-y:auto;flex-shrink:0}
.main{flex:1;padding:20px;overflow-y:auto;max-width:900px}
h1{font-size:18px;font-weight:600;padding:12px 0;border-bottom:2px solid #e9ecef;margin-bottom:12px;word-break:break-all}
.toolbar{display:flex;gap:8px;margin-bottom:12px;align-items:center}
.toolbar button{background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:13px}
.toolbar button.active{background:#1971c2;color:#fff;border-color:#1971c2}
.toolbar .spacer{flex:1}
.listing{display:flex;flex-direction:column;gap:2px}
.item{display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:6px;text-decoration:none;color:#333;transition:background .15s}
.item:hover{background:#e9ecef}
.icon{font-size:16px;flex-shrink:0;width:22px;text-align:center}
.name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px}
.size{color:#868e96;font-size:12px;flex-shrink:0}
.dir .name{color:#1971c2;font-weight:500}
.up{border-bottom:1px solid #e9ecef;margin-bottom:4px;padding-bottom:10px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;padding:8px 0}
.grid-item{background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);cursor:pointer;transition:transform .15s,box-shadow .15s;position:relative}
.grid-item:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.12)}
.grid-item.selected{outline:3px solid #1971c2;outline-offset:-3px}
.grid-item .check{position:absolute;top:6px;left:6px;width:22px;height:22px;border-radius:50%;border:2px solid rgba(255,255,255,.7);background:rgba(0,0,0,.2);display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff;opacity:0;transition:opacity .15s;z-index:1}
.grid-item:hover .check,.grid-item.selected .check{opacity:1}
.grid-item.selected .check{background:#1971c2;border-color:#1971c2}
.grid-item img{width:100%;aspect-ratio:1;object-fit:cover;display:block;background:#f0f0f0}
.grid-item .info{padding:6px 8px;font-size:11px;color:#868e96;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.grid-item .info .dim{float:right;color:#adb5bd}
.lightbox{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:1000;display:none;align-items:center;justify-content:center;flex-direction:column}
.lightbox.open{display:flex}
.lightbox img{max-width:90vw;max-height:70vh;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,.4)}
.lightbox .close{position:absolute;top:16px;right:20px;color:#fff;font-size:28px;cursor:pointer;opacity:.7;background:none;border:none}
.lightbox .close:hover{opacity:1}
.lightbox .meta{color:#fff;text-align:center;padding:16px;max-width:500px}
.lightbox .meta h3{font-size:16px;margin-bottom:8px;font-weight:500}
.lightbox .meta .dims{color:#adb5bd;font-size:13px;margin-bottom:12px}
.lightbox .sizes{display:flex;flex-wrap:wrap;gap:6px;justify-content:center}
.lightbox .sizes a{background:rgba(255,255,255,.15);color:#fff;padding:4px 12px;border-radius:20px;font-size:12px;text-decoration:none;transition:background .15s}
.lightbox .sizes a:hover{background:rgba(255,255,255,.3)}
.lightbox .sizes a.orig{background:rgba(25,113,194,.6)}
.sidebar h2{font-size:13px;font-weight:600;color:#868e96;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.tree-item{display:block;padding:4px 8px;font-size:13px;color:#495057;text-decoration:none;border-radius:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tree-item:hover{background:#f1f3f5}
.tree-item.active{background:#e7f5ff;color:#1971c2;font-weight:500}
.tree-item .ti{margin-right:4px}
.action-bar{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#1a1a2e;color:#fff;
  padding:10px 20px;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.3);display:none;align-items:center;gap:12px;z-index:500;font-size:13px}
.action-bar.show{display:flex}
.action-bar button{background:rgba(255,255,255,.15);color:#fff;border:none;padding:6px 14px;border-radius:8px;cursor:pointer;font-size:13px}
.action-bar button:hover{background:rgba(255,255,255,.25)}
.action-bar button.primary{background:#1971c2}
.action-bar button.primary:hover{background:#1562a5}
.action-bar .count{font-weight:600}
.bulk-panel{position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:1001;display:none;flex-direction:column;align-items:center;padding:20px}
.bulk-panel.open{display:flex}
.bulk-panel .bulk-header{color:#fff;text-align:center;padding:16px;width:100%}
.bulk-panel .bulk-header h3{font-size:18px;margin-bottom:8px}
.bulk-panel .bulk-options{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin:12px 0}
.bulk-panel .bulk-options button{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.2);padding:6px 16px;border-radius:20px;cursor:pointer;font-size:13px}
.bulk-panel .bulk-options button:hover,.bulk-panel .bulk-options button.active{background:rgba(25,113,194,.6);border-color:#1971c2}
.bulk-panel .bulk-preview{flex:1;overflow-y:auto;display:flex;flex-wrap:wrap;gap:8px;justify-content:center;align-content:start;padding:12px}
.bulk-panel .bulk-preview img{height:100px;border-radius:6px;object-fit:cover}
.bulk-panel .bulk-close{position:absolute;top:12px;right:16px;color:#fff;font-size:28px;background:none;border:none;cursor:pointer;opacity:.7}
.bulk-panel .bulk-close:hover{opacity:1}
.bulk-panel .bulk-download{background:#1971c2;color:#fff;border:none;padding:10px 28px;border-radius:8px;font-size:15px;cursor:pointer;margin-top:12px}
.bulk-panel .bulk-download:hover{background:#1562a5}
@media(max-width:700px){
  .sidebar{display:none}
  .main{padding:12px}
  .grid{grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px}
  .lightbox img{max-width:95vw;max-height:60vh}
}
</style>
</head><body>

<div class="layout">
  <div class="sidebar" id="sidebar">
    <h2>Folders</h2>
    <div id="tree"></div>
  </div>
  <div class="main">
    <h1>Index of {$safePath}</h1>
    <div class="toolbar" id="toolbar">
      <button onclick="setView('list')" id="btn-list">☰ List</button>
      <button onclick="setView('grid')" id="btn-grid" class="active">▦ Grid</button>
      <span class="spacer"></span>
      <span style="font-size:12px;color:#868e96" id="count"></span>
    </div>
    <div id="list-view" class="listing" style="display:none">
      {$listHtml}
    </div>
    <div id="grid-view" class="grid"></div>
  </div>
</div>

<div class="lightbox" id="lightbox" onclick="closeLightbox(event)">
  <button class="close" onclick="closeLightbox()">&times;</button>
  <img id="lb-img" src="">
  <div class="meta">
    <h3 id="lb-name"></h3>
    <div class="dims" id="lb-dims"></div>
    <div class="sizes" id="lb-sizes"></div>
  </div>
</div>

<div class="action-bar" id="action-bar">
  <span><span class="count" id="sel-count">0</span> selected</span>
  <button onclick="selectAll()">Select All</button>
  <button onclick="clearSelection()">Clear</button>
  <button class="primary" onclick="openBulkPanel()">⬇ Download</button>
</div>

<div class="bulk-panel" id="bulk-panel">
  <button class="bulk-close" onclick="closeBulkPanel()">&times;</button>
  <div class="bulk-header">
    <h3>Download <span id="bulk-count">0</span> images</h3>
    <p style="color:#adb5bd;font-size:13px">Choose a size and format for all images</p>
    <div class="bulk-options" id="bulk-sizes">
      <button onclick="setBulkSize(320)">320px</button>
      <button onclick="setBulkSize(640)" class="active">640px</button>
      <button onclick="setBulkSize(1024)">1024px</button>
      <button onclick="setBulkSize(1920)">1920px</button>
      <button onclick="setBulkSize(0)" class="active">Original</button>
    </div>
    <div class="bulk-options" id="bulk-formats">
      <button onclick="setBulkFormat('')" class="active">Keep format</button>
      <button onclick="setBulkFormat('webp')">WebP</button>
      <button onclick="setBulkFormat('jpg')">JPEG</button>
      <button onclick="setBulkFormat('png')">PNG</button>
    </div>
    <label style="color:#adb5bd;font-size:13px;cursor:pointer;margin-top:4px;display:inline-flex;align-items:center;gap:6px">
      <input type="checkbox" id="bulk-rename"> Include size in filenames (e.g. photo-640w.webp)
    </label>
    <button class="bulk-download" onclick="bulkDownload()">⬇ Download ZIP</button>
  </div>
  <div class="bulk-preview" id="bulk-preview"></div>
</div>

<script>
var images = {$imagesJson};
var currentView = 'grid';
var dirs = document.querySelectorAll('.item.dir:not(.up)');

// Sidebar tree
var tree = document.getElementById('tree');
if ('{$safePath}' !== '/') {
  tree.innerHTML += '<a class="tree-item" href="../"><span class="ti">⬆</span> ..</a>';
}
dirs.forEach(function(d) {
  tree.innerHTML += '<a class="tree-item" href="' + d.getAttribute('href') + '"><span class="ti">📁</span> ' + d.querySelector('.name').textContent + '</a>';
});
if (!dirs.length && '{$safePath}' === '/') {
  document.getElementById('sidebar').style.display = 'none';
}

// Count
document.getElementById('count').textContent = images.length + ' images, ' + (dirs.length) + ' folders';

// Grid view
var gridEl = document.getElementById('grid-view');
var selected = {};
images.forEach(function(img, i) {
  var thumb = img.href + '?w=240';
  if (img.ext === 'svg') thumb = img.href;
  var dim = img.width ? img.width+'×'+img.height : '';
  var div = document.createElement('div');
  div.className = 'grid-item';
  div.dataset.idx = i;
  div.innerHTML = '<div class="check">✓</div>'
    + '<img src="' + thumb + '" loading="lazy" alt="' + img.name + '">'
    + '<div class="info">' + img.name + '<span class="dim">' + dim + '</span></div>';
  div.onclick = function(e) {
    if (e.shiftKey || e.ctrlKey || e.metaKey || Object.keys(selected).length > 0) {
      toggleSelect(i, div);
    } else {
      openLightbox(i);
    }
  };
  div.querySelector('.check').onclick = function(e) {
    e.stopPropagation();
    toggleSelect(i, div);
  };
  gridEl.appendChild(div);
});

function toggleSelect(i, el) {
  if (selected[i]) { delete selected[i]; el.classList.remove('selected'); }
  else { selected[i] = true; el.classList.add('selected'); }
  updateActionBar();
}
function selectAll() {
  document.querySelectorAll('.grid-item').forEach(function(el) {
    var i = parseInt(el.dataset.idx);
    selected[i] = true;
    el.classList.add('selected');
  });
  updateActionBar();
}
function clearSelection() {
  selected = {};
  document.querySelectorAll('.grid-item.selected').forEach(function(el) {
    el.classList.remove('selected');
  });
  updateActionBar();
}
function updateActionBar() {
  var n = Object.keys(selected).length;
  document.getElementById('sel-count').textContent = n;
  document.getElementById('action-bar').className = 'action-bar' + (n > 0 ? ' show' : '');
}

// Bulk download
var bulkSize = 0; // 0 = original
var bulkFormat = ''; // '' = keep original
function setBulkSize(w) {
  bulkSize = w;
  document.querySelectorAll('#bulk-sizes button').forEach(function(b) {
    b.className = (w === 0 && b.textContent === 'Original') || (w && b.textContent === w+'px') ? 'active' : '';
  });
}
function setBulkFormat(f) {
  bulkFormat = f;
  document.querySelectorAll('#bulk-formats button').forEach(function(b) {
    b.className = (f === '' && b.textContent === 'Keep format') || (f && b.textContent.toLowerCase().indexOf(f) >= 0) ? 'active' : '';
  });
}
function openBulkPanel() {
  var keys = Object.keys(selected);
  document.getElementById('bulk-count').textContent = keys.length;
  var preview = document.getElementById('bulk-preview');
  preview.innerHTML = '';
  keys.forEach(function(k) {
    var img = images[k];
    var el = document.createElement('img');
    el.src = img.href + '?w=100';
    el.alt = img.name;
    preview.appendChild(el);
  });
  document.getElementById('bulk-panel').classList.add('open');
}
function closeBulkPanel() {
  document.getElementById('bulk-panel').classList.remove('open');
}
function bulkDownload() {
  var keys = Object.keys(selected);
  var files = keys.map(function(k) {
    var img = images[k];
    var href = img.href;
    if (bulkFormat && bulkFormat !== img.ext) {
      href = href.replace(/\.[^.]+$/, '.' + bulkFormat);
    }
    if (bulkSize) href += (href.indexOf('?') >= 0 ? '&' : '?') + 'w=' + bulkSize;
    return href;
  });
  var form = document.createElement('form');
  form.method = 'POST';
  form.action = '/Q/api/images/zip';
  form.style.display = 'none';
  var input = document.createElement('input');
  input.name = 'files';
  input.value = JSON.stringify(files);
  form.appendChild(input);
  var renameInput = document.createElement('input');
  renameInput.name = 'rename';
  renameInput.value = document.getElementById('bulk-rename').checked ? '1' : '0';
  form.appendChild(renameInput);
  document.body.appendChild(form);
  form.submit();
  document.body.removeChild(form);
  closeBulkPanel();
}

function setView(v) {
  currentView = v;
  document.getElementById('list-view').style.display = v === 'list' ? '' : 'none';
  document.getElementById('grid-view').style.display = v === 'grid' ? '' : 'none';
  document.getElementById('btn-list').className = v === 'list' ? 'active' : '';
  document.getElementById('btn-grid').className = v === 'grid' ? 'active' : '';
}

function openLightbox(i) {
  var img = images[i];
  var lb = document.getElementById('lightbox');
  document.getElementById('lb-img').src = img.href;
  document.getElementById('lb-name').textContent = img.name;
  document.getElementById('lb-dims').textContent = img.width ? img.width + ' × ' + img.height + ' · ' + img.size : img.size;
  var sizes = document.getElementById('lb-sizes');
  sizes.innerHTML = '';
  // Download size options
  var widths = [320, 640, 1024, 1920];
  widths.forEach(function(w) {
    if (img.width && w >= img.width) return;
    var a = document.createElement('a');
    a.href = img.href + '?w=' + w;
    a.download = img.name.replace(/\.[^.]+$/, '') + '-' + w + 'w.' + img.ext;
    a.textContent = w + 'px';
    sizes.appendChild(a);
  });
  // WebP version
  if (img.ext !== 'webp' && img.ext !== 'svg') {
    var a = document.createElement('a');
    a.href = img.href.replace(/\.[^.]+$/, '.webp');
    a.download = img.name.replace(/\.[^.]+$/, '.webp');
    a.textContent = 'WebP';
    sizes.appendChild(a);
  }
  // Original
  var orig = document.createElement('a');
  orig.href = img.href;
  orig.download = img.name;
  orig.className = 'orig';
  orig.textContent = 'Original' + (img.width ? ' (' + img.width + 'px)' : '');
  sizes.appendChild(orig);
  lb.classList.add('open');
}
function closeLightbox(e) {
  if (e && e.target !== document.getElementById('lightbox') && e.target !== document.querySelector('.close')) return;
  document.getElementById('lightbox').classList.remove('open');
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeLightbox();
});

// Default to list if no images
if (!images.length) setView('list');
</script>
</body></html>
HTML;
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
		// Compute SCRIPT_NAME / PATH_INFO relative to docroot. Frameworks (Qbix
		// included) route off PATH_INFO for action.php/{route}-style URLs and off
		// a correct SCRIPT_NAME when the app is served under a subpath — hardcoding
		// PATH_INFO to '' breaks both.
		$docRoot     = rtrim(self::$rootDir, DS);
		$requestPath = parse_url($parsed['uri'], PHP_URL_PATH) ?: '/';
		$scriptRel   = '/' . ltrim(str_replace(DS, '/', substr($scriptPath, strlen($docRoot))), '/');
		$pathInfo    = '';
		if (isset($parsed['_pathInfo'])) {
			// Precomputed by splitPathInfo() (BUG #37) — authoritative.
			$pathInfo = $parsed['_pathInfo'];
		} else if (strncmp($requestPath, $scriptRel, strlen($scriptRel)) === 0
			&& strlen($requestPath) > strlen($scriptRel)) {
			$pathInfo = substr($requestPath, strlen($scriptRel));
		}
		$_SERVER['SCRIPT_NAME']       = $scriptRel;
		$_SERVER['SCRIPT_FILENAME']   = $scriptPath;
		$_SERVER['PHP_SELF']          = $scriptRel . $pathInfo; // WordPress uses this
		$_SERVER['PATH_TRANSLATED']   = $pathInfo ? $docRoot . $pathInfo : $scriptPath;
		$_SERVER['PATH_INFO']         = $pathInfo;
		$_SERVER['DOCUMENT_ROOT']     = $docRoot;
		$_SERVER['DOCUMENT_URI']      = $scriptRel;
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
		Q_WebServer_State::setInput($rawBody);

		// Clear any stale headers and output from previous in-process requests,
		// then start fresh output buffering. This prevents "headers already sent"
		// errors when scripts call header() after prior output leaked through.
		while (ob_get_level()) ob_end_clean();
		@header_remove();
		@http_response_code(200);
		Q_WebServer::clearResponseState();
		if (class_exists('Q_Response', false)) Q_WebServer_State::clear();

		// Capture in a NON-REMOVABLE buffer.
		//
		// Q_Dispatcher::dispatch() unwinds through Q_OutputBuffer::endFlush(),
		// which calls @ob_end_flush() down to and including our level. A normal
		// buffer is destroyed by that: its contents go to the process's STDOUT
		// and there is nothing left to send. The visible symptom was every
		// application error under --app arriving as "200 with an empty body"
		// while the rendered error page appeared in the server's log.
		//
		// Passing flags=0 (not PHP_OUTPUT_HANDLER_REMOVABLE) makes
		// ob_end_flush()/ob_end_clean() fail on this buffer instead, so it
		// survives however the Platform chooses to unwind. We read it with
		// ob_get_contents(), which works on a protected buffer.
		ob_start(null, 0, 0);
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
			$headers = Q_WebServer::getResponseHeaders();
			$code = http_response_code();
			if ($code && $code !== 200) $status = $code;
			elseif (Q_WebServer::responseCode() !== 200) $status = Q_WebServer::responseCode();

			// Recover the status from the Platform's OWN error state.
			//
			// Q_Response::errorHeaderCode() and Q_Response::code() both publish
			// the status by calling http_response_code()/header(), which the CLI
			// SAPI discards -- so a validation failure that should be a 412 went
			// out with a 200 status line and an error page as its body. An API
			// client reads 200, treats it as success, and parses the error page.
			//
			// The code is not actually lost: it is still on the exception
			// objects in Q_Response::getErrors(), which the Platform declares.
			// Read it from there rather than from PHP's SAPI plumbing.
			if ($status === 200
			and class_exists('Q_Response', false)
			and method_exists('Q_Response', 'getErrors')) {
				try {
					foreach ((array) Q_Response::getErrors() as $err) {
						if (is_object($err) and !empty($err->httpResponseCode)) {
							$status = (int) $err->httpResponseCode;
							break;
						}
					}
				} catch (\Throwable $e) { /* never let error reporting throw */ }
			}

			// Cookies: Q_Response::setCookie() stores them.
			// Headers::processResponse() reads them via cookieHeaders()
			// and emits Set-Cookie headers when assembling the response.
			// No action needed here.
		} catch (\Throwable $e) {
			$status = 500;
			if (ob_get_level()) ob_clean();
			echo json_encode(array('error' => $e->getMessage()));
			$headers['Content-Type'] = 'application/json';
		}
		// ob_get_contents() reads a non-removable buffer; ob_get_clean() would
		// return false on it. Record the content for the shutdown handler in
		// case the Platform exits before we return, then drop the buffer.
		$body = '';
		if (ob_get_level()) {
			$body = (string) ob_get_contents();
			@ob_clean();
		}
		self::$_capturedOutput = '';
		while (@ob_end_clean()) { /* drop any buffers we can */ }
		@header_remove();

		// Fix 1: Clean up upload temp files
		self::cleanupUploadFiles();

		// Fix 3: Restore native php:// stream wrapper
		if (class_exists('Q_Request', false)) {
			Q_WebServer_State::restoreInput();
		}

		list($_SERVER, $_GET, $_POST, $_REQUEST, $_COOKIE) = $saved;

		// Process Merkle cache headers (strips X-Q-Cache-* from response)
		if (Q_WebServer_Cache_Components::enabled()) {
			$pageKey = $parsed['path'] . '?' . ($parsed['query'] ?? '');
			Q_WebServer_Cache_Components::processResponseHeaders($pageKey, $headers);
		}

		// Default Content-Type. A script that never calls header() leaves none set,
		// so we were emitting a 200 with NO Content-Type at all — browsers sniff and
		// strict clients reject. Error paths set it explicitly, which is why this only
		// ever bit successful PHP dispatches, such as the app's own home page.
		$hasContentType = false;
		foreach ($headers as $_hk => $_hv) {
			if (strcasecmp($_hk, 'Content-Type') === 0) { $hasContentType = true; break; }
		}
		if (!$hasContentType && $status != 204 && $status != 304) {
			$headers['Content-Type'] = 'text/html; charset=utf-8';
		}
		// Carry the request method so processResponse() can honour HEAD.
		$_method = $parsed['method'] ?? 'GET';
		return compact('status', 'body', 'headers', '_method');
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

		// Issue #1: HTTP methods are case-sensitive per RFC 9110 §9.1.
		// Standard methods are uppercase. We reject non-standard casing
		// with 501 Not Implemented (same as nginx/Apache behavior).
		$method = $m[1];
		$knownMethods = array('GET','HEAD','POST','PUT','DELETE','PATCH','OPTIONS','TRACE','CONNECT');
		if (!in_array($method, $knownMethods, true)) {
			// Check if it's a known method with wrong case
			if (in_array(strtoupper($method), $knownMethods, true)) {
				return array(
					'method' => $method, 'uri' => $m[2], 'path' => '/',
					'query' => '', 'headers' => array(), 'body' => '',
					'httpVersion' => $m[3], '_badMethod' => true
				);
			}
			// Unknown method — allow it through (extensions are valid per RFC)
		}

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

		// Issue #2: RFC-compliant header parsing
		$headers = array();
		$hostCount = 0;
		$pos = $rlEnd !== false ? $rlEnd + 2 : strlen($headerBlock);
		$len = strlen($headerBlock);
		while ($pos < $len) {
			$nlPos = strpos($headerBlock, "\r\n", $pos);
			if ($nlPos === false) $nlPos = $len;

			// Check for continuation line (starts with SP or TAB) — obs-fold, RFC 9110 §5.2
			$firstChar = $pos < $len ? $headerBlock[$pos] : '';
			if (($firstChar === ' ' || $firstChar === "\t") && !empty($lastKey)) {
				// Append to previous header value
				$continuation = trim(substr($headerBlock, $pos, $nlPos - $pos), " \t");
				$headers[$lastKey] .= ' ' . $continuation;
				$pos = $nlPos + 2;
				continue;
			}

			$colonPos = strpos($headerBlock, ':', $pos);
			if ($colonPos !== false && $colonPos < $nlPos) {
				$k = substr($headerBlock, $pos, $colonPos - $pos);

				// Validate header name — must be a valid token (RFC 9110 §5.1)
				// token = 1*tchar, tchar = letters, digits, !#$%&'*+-.^_`|~
				if (!preg_match('/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/', $k)) {
					// Invalid characters in header name → reject request
					return array(
						'method' => $method, 'uri' => $uri, 'path' => $path,
						'query' => $query, 'headers' => array(), 'body' => '',
						'httpVersion' => $httpVersion, '_badHeaders' => true
					);
				}

				$k = strtolower($k);
				// Trim both sides: SP and HTAB only (RFC 9110 §5.5)
				$v = trim(substr($headerBlock, $colonPos + 1, $nlPos - $colonPos - 1), " \t");

				// Duplicate header handling
				if ($k === 'host') {
					$hostCount++;
					if ($hostCount > 1) {
						// Multiple Host headers → reject (RFC 9110 §7.2, common attack vector)
						return array(
							'method' => $method, 'uri' => $uri, 'path' => $path,
							'query' => $query, 'headers' => array(), 'body' => '',
							'httpVersion' => $httpVersion, '_duplicateHost' => true
						);
					}
				}

				// RFC 9110 §5.3: multiple headers → combine with ", "
				// Exceptions: Set-Cookie is special but that's a response header
				if (isset($headers[$k])) {
					$headers[$k] .= ', ' . $v;
				} else {
					$headers[$k] = $v;
				}
				$lastKey = $k;
			}
			$pos = $nlPos + 2;
		}

		// HTTP/1.0 defaults to Connection: close
		if ($httpVersion === '1.0' && !isset($headers['connection'])) {
			$headers['connection'] = 'close';
		}

		// Decode chunked transfer-encoding
		if (isset($headers['transfer-encoding'])
			&& stripos($headers['transfer-encoding'], 'chunked') !== false
			&& $body
		) {
			$decoded = '';
			$pos = 0;
			while ($pos < strlen($body)) {
				$nlPos = strpos($body, "\r\n", $pos);
				if ($nlPos === false) break;
				$chunkSize = hexdec(substr($body, $pos, $nlPos - $pos));
				if ($chunkSize === 0) break;
				$pos = $nlPos + 2;
				$decoded .= substr($body, $pos, $chunkSize);
				$pos += $chunkSize + 2; // skip chunk data + \r\n
			}
			$body = $decoded;
			unset($headers['transfer-encoding']);
			$headers['content-length'] = (string) strlen($body);
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
		// Check if file uploads are enabled
		if (!self::$fileUploads) {
			// Parse form fields only, skip files
		}

		// Extract boundary from Content-Type
		if (!preg_match('/boundary=(?:"([^"]+)"|([^\s;]+))/i', $contentType, $bm)) {
			return;
		}
		$boundary = '--' . ($bm[1] ?: $bm[2]);
		$endBoundary = $boundary . '--';

		$parts = explode($boundary, $body);
		array_shift($parts); // before first boundary
		$fileCount = 0;

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
				// Enforce file upload limits
				if (!self::$fileUploads) continue; // uploads disabled
				$fileCount++;
				if ($fileCount > self::$maxFileUploads) continue; // too many files

				$error = UPLOAD_ERR_OK;
				if (strlen($partBody) > self::$maxUploadSize) {
					$error = UPLOAD_ERR_INI_SIZE;
					$partBody = ''; // don't write oversized file
				}

				// File upload — write to temp file
				$tmpPath = tempnam(sys_get_temp_dir(), 'qbix_upload_');
				if ($error === UPLOAD_ERR_OK) {
					file_put_contents($tmpPath, $partBody);
				}
				self::$uploadTempFiles[] = $tmpPath;

				$fileEntry = array(
					'name'     => $filename,
					'type'     => $partHeaders['content-type'] ?? 'application/octet-stream',
					'tmp_name' => $tmpPath,
					'error'    => $error,
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
		self::$lastBytes = strlen($body);
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

	/**
	 * Render an error page. Checks for user override at errors/$code.php
	 * (or errors/$code.html), then falls back to built-in styled page.
	 * @method renderErrorPage
	 * @static
	 * @param {integer} $code HTTP status code
	 * @param {string} $path The requested path (for display)
	 * @return {string} HTML
	 */
	static function renderErrorPage($code, $path = '')
	{
		$safe = htmlspecialchars($path, ENT_QUOTES);

		// Check user overrides: errors/404.php, errors/404.html
		foreach (Q_WebServer::paths() as $base) {
			$phpFile = $base . DS . 'errors' . DS . $code . '.php';
			if (file_exists($phpFile)) {
				ob_start();
				$_code = $code; $_path = $path;
				include $phpFile;
				return ob_get_clean();
			}
			$htmlFile = $base . DS . 'errors' . DS . $code . '.html';
			if (file_exists($htmlFile)) {
				return file_get_contents($htmlFile);
			}
		}

		// Built-in error pages
		$titles = array(
			403 => 'Forbidden',
			404 => 'Not Found',
			413 => 'Payload Too Large',
			429 => 'Too Many Requests',
			500 => 'Server Error',
			502 => 'Bad Gateway',
			503 => 'Service Unavailable',
		);
		$messages = array(
			403 => 'You don\'t have permission to access this resource.',
			404 => "The page <code>{$safe}</code> could not be found.",
			413 => 'The request body exceeds the maximum allowed size.',
			429 => 'Please slow down and try again later.',
			500 => 'Something went wrong. The server encountered an internal error.',
			502 => 'The server received an invalid response from an upstream server.',
			503 => 'The server is temporarily unavailable. Please try again later.',
		);
		$title = $titles[$code] ?? 'Error';
		$msg = $messages[$code] ?? 'An unexpected error occurred.';

		return '<!DOCTYPE html><html><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. "<title>{$code} {$title}</title>"
			. '<style>'
			. '*{margin:0;padding:0;box-sizing:border-box}'
			. 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
			. 'min-height:100vh;display:flex;align-items:center;justify-content:center;'
			. 'background:#f8f9fa;color:#333}'
			. '.c{text-align:center;padding:40px 20px;max-width:480px}'
			. '.logo{width:40px;height:auto;margin-bottom:12px;opacity:.35}'
			. '.n{font-size:100px;font-weight:700;color:#e0e0e0;line-height:1}'
			. '.t{font-size:18px;margin:12px 0 8px;font-weight:600}'
			. '.m{color:#888;line-height:1.5;font-size:14px}'
			. '.m code{background:#eee;padding:2px 6px;border-radius:3px;font-size:13px;word-break:break-all}'
			. '.b{margin-top:20px}'
			. '.b a{color:#4a9eff;text-decoration:none;font-size:14px}'
			. '.b a:hover{text-decoration:underline}'
			. '.f{margin-top:28px;color:#ccc;font-size:11px}'
			. '@media(max-width:500px){.n{font-size:72px}.t{font-size:16px}.m{font-size:13px}}'
			. '</style></head><body>'
			. '<div class="c">'
			. '<img class="logo" src="/Q/logo.png" alt="">'
			. "<div class=\"n\">{$code}</div>"
			. "<div class=\"t\">{$title}</div>"
			. "<div class=\"m\">{$msg}</div>"
			. '<div class="b"><a href="/">← Back to home</a></div>'
			. '<div class="f">Qbix Server</div>'
			. '</div></body></html>';
	}

	private static function render404($path)
	{
		return self::renderErrorPage(404, $path);
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


	/**
	 * Split a URL path into an existing .php script plus the remaining PATH_INFO.
	 * Mirrors nginx's fastcgi_split_path_info: walk the segments and return the
	 * LONGEST prefix that is an actual .php file on disk.
	 * @return {array|null} ['scriptPath'=>..., 'pathInfo'=>...] or null
	 */
	protected static function splitPathInfo($path)
	{
		$segments = explode('/', trim($path, '/'));
		$prefix = '';
		for ($i = 0; $i < count($segments); ++$i) {
			$prefix .= '/' . $segments[$i];
			if (substr($prefix, -4) !== '.php') {
				continue;
			}
			// Resolve against the document root directly. resolveStatic() applies
			// extension allow-listing and a realpath cache that reject a bare
			// script prefix, so it cannot be used here.
			$candidate = rtrim(self::$rootDir, '/\\') . $prefix;
			if (is_file($candidate)) {
				$rest = implode('/', array_slice($segments, $i + 1));
				return array(
					'scriptPath' => $candidate,
					'pathInfo'   => $rest === '' ? '' : '/' . $rest
				);
			}
		}
		return null;
	}

	private static function resolveStatic($urlPath)
	{
		// Path resolution cache — avoids repeated realpath() syscalls
		// Key includes rootDir so vhosts don't cross-contaminate
		static $pathCache = array();
		$cacheKey = self::$rootDir . $urlPath;
		if (isset($pathCache[$cacheKey])) {
			$cached = $pathCache[$cacheKey];
			// Quick mtime check for invalidation (cheaper than realpath)
			if ($cached === null || file_exists($cached)) {
				return $cached;
			}
			unset($pathCache[$cacheKey]);
		}

		$rel = str_replace('/', DS, ltrim($urlPath, '/'));
		// Block null bytes (directory traversal via null byte injection)
		if (strpos($rel, "\0") !== false) return null;
		$fsPath = realpath(self::$rootDir . $rel);

		// If file not found, check for shortcuts/aliases:
		// .lnk (Windows) or Mac alias with same name
		if (!$fsPath) {
			// Try .lnk extension
			$lnkPath = self::$rootDir . $rel . '.lnk';
			if (file_exists($lnkPath)) {
				$target = Q_WebServer_Shortcut::resolve($lnkPath);
				if ($target) $fsPath = $target;
			}
			// Try without extension — check if it's a Mac alias
			if (!$fsPath && PHP_OS_FAMILY === 'Darwin') {
				$rawPath = self::$rootDir . $rel;
				if (file_exists($rawPath) && Q_WebServer_Shortcut::isShortcut($rawPath)) {
					$target = Q_WebServer_Shortcut::resolve($rawPath);
					if ($target) $fsPath = $target;
				}
			}
			// Also check parent directories for shortcuts
			// e.g. /plugins/Users/js/Users.js where /plugins/Users is an alias
			if (!$fsPath) {
				$parts = explode(DS, $rel);
				$accumulated = self::$rootDir;
				for ($i = 0; $i < count($parts) - 1; $i++) {
					$accumulated .= $parts[$i];
					if (!file_exists($accumulated) && !is_dir($accumulated)) {
						// Check .lnk
						if (file_exists($accumulated . '.lnk')) {
							$target = Q_WebServer_Shortcut::resolve($accumulated . '.lnk');
							if ($target && is_dir($target)) {
								$remaining = implode(DS, array_slice($parts, $i + 1));
								$resolved = realpath($target . DS . $remaining);
								if ($resolved) { $fsPath = $resolved; break; }
							}
						}
						// Check Mac alias
						if (!$fsPath && PHP_OS_FAMILY === 'Darwin'
							&& file_exists($accumulated)
							&& Q_WebServer_Shortcut::isShortcut($accumulated)
						) {
							$target = Q_WebServer_Shortcut::resolve($accumulated);
							if ($target && is_dir($target)) {
								$remaining = implode(DS, array_slice($parts, $i + 1));
								$resolved = realpath($target . DS . $remaining);
								if ($resolved) { $fsPath = $resolved; break; }
							}
						}
					}
					$accumulated .= DS;
				}
			}
			if (!$fsPath) {
				if (count($pathCache) < 10000) $pathCache[$cacheKey] = null;
				return null;
			}
		}

		$fsPath = str_replace(array('/','\\'), DS, $fsPath);
		$result = (is_dir($fsPath) || is_file($fsPath)) ? $fsPath : null;
		if (count($pathCache) < 10000) $pathCache[$cacheKey] = $result;
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
	private static $lastBytes = 0;

	// ── Response state (internal — not Q_Response, to avoid Platform collision) ──

	/** @var array Captured response headers: name => value */
	private static $_responseHeaders = array();
	/** @var integer Captured response status code */
	private static $_responseCode = 200;

	/**
	 * Output accumulated by dispatchToQ()'s callback buffer.
	 *
	 * Public because the buffer callback and the fork child's shutdown handler
	 * both need it. It cannot live in the buffer itself: Q_Dispatcher flushes
	 * and closes buffers on its way out, which would send the response to the
	 * process's stdout and leave the client with an empty body.
	 */
	static $_capturedOutput = '';

	/**
	 * Capture a response header. Called by Q_Response::header() in standalone mode,
	 * or read from headers_list() / http_response_code() in --app mode.
	 * @method setResponseHeader
	 * @static
	 */
	static function setResponseHeader($name, $value, $replace = true)
	{
		if ($replace || !isset(self::$_responseHeaders[$name])) {
			self::$_responseHeaders[$name] = $value;
		}
	}

	/**
	 * Get all captured response headers.
	 * In --app mode (Platform loaded), also reads headers_list().
	 */
	static function getResponseHeaders()
	{
		// Merge headers captured by Q_WebServer_State (webserver-owned, so it
		// exists in BOTH modes). This was previously guarded on
		// method_exists('Q_Response','getHeaders') -- a method only the
		// STANDALONE shim declares -- so under --app the guard was false and
		// every header a script set was silently dropped, even though State
		// had captured them correctly.
		if (class_exists('Q_WebServer_State', false)) {
			foreach (Q_WebServer_State::getHeaders() as $k => $v) {
				if (!isset(self::$_responseHeaders[$k])) {
					self::$_responseHeaders[$k] = $v;
				}
			}
		}
		// NOTE: headers_list() is ALWAYS empty under the CLI SAPI -- PHP
		// discards native header() calls and gives no hook to capture them.
		// So this merge is a no-op in normal operation; it only contributes
		// if the server is run under a SAPI that does record them. Scripts
		// relying on native header() must be routed to php-cgi via
		// Q.webserver.cgi.patterns -- that is the documented escape hatch.
		if (function_exists('headers_list')) {
			foreach (headers_list() as $h) {
				$pos = strpos($h, ':');
				if ($pos !== false) {
					$name = trim(substr($h, 0, $pos));
					$value = trim(substr($h, $pos + 1));
					if (!isset(self::$_responseHeaders[$name])) {
						self::$_responseHeaders[$name] = $value;
					}
				}
			}
		}
		return self::$_responseHeaders;
	}

	/**
	 * Get or set the response status code.
	 * In --app mode, also reads http_response_code().
	 */
	static function responseCode($code = null)
	{
		// Set only our own field. Q_WebServer_State::responseCode() already
		// forwards to Q_Response::code(), which calls back here -- propagating
		// in this direction too would close the cycle into infinite recursion.
		if ($code !== null) {
			self::$_responseCode = (int) $code;
			return $code;
		}
		// Q::header() and Q_Response::code() write to Q_WebServer_State, so it
		// is the authority. Reading only $_responseCode here silently dropped
		// every status a script set, and everything went out as 200.
		if (class_exists('Q_WebServer_State', false)) {
			$state = Q_WebServer_State::responseCode();
			if ($state && $state !== 200) return $state;
		}
		// Check native http_response_code (catches Platform's header() calls)
		$native = http_response_code();
		if ($native && $native !== 200) return $native;
		return self::$_responseCode;
	}

	/**
	 * Clear response state between requests in the event loop.
	 */
	static function clearResponseState()
	{
		self::$_responseHeaders = array();
		self::$_responseCode = 200;
		@header_remove();
	}
	/** @internal pid => start_time for request timeout enforcement */
	static $workerPids = array();
	/** @var integer Max POST body size in bytes (from post_max_size ini) */
	static $maxPostSize = 8388608; // 8M default
	/** @var integer Max upload file size in bytes (from upload_max_filesize ini) */
	static $maxUploadSize = 2097152; // 2M default
	/** @var integer Max number of files per upload (from max_file_uploads ini) */
	static $maxFileUploads = 20;
	/** @var boolean Whether file uploads are enabled (from file_uploads ini) */
	static $fileUploads = true;
	/** @var array Temp files created by multipart parsing — cleaned on request end */
	static $uploadTempFiles = array();

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

	/**
	 * Parse a PHP ini byte value like "8M", "128K", "1G" into bytes.
	 * @method parseIniBytes
	 * @static
	 * @param {string|integer} $value
	 * @return {integer}
	 */
	static function parseIniBytes($value)
	{
		if (is_numeric($value)) return (int) $value;
		$value = trim($value);
		$num = (int) $value;
		$suffix = strtoupper(substr($value, -1));
		switch ($suffix) {
			case 'G': $num *= 1073741824; break;
			case 'M': $num *= 1048576; break;
			case 'K': $num *= 1024; break;
		}
		return $num;
	}

	/**
	 * Remove temp files created during multipart upload parsing.
	 * Called after each request in both in-process and fork modes.
	 * @method cleanupUploadFiles
	 * @static
	 */
	/**
	 * Handle bulk image download as ZIP.
	 * Accepts POST with files[] JSON array of image URLs (with ?w= params).
	 * Generates/caches each image, then streams a ZIP.
	 */
	// ── PHPDoc / YUIDoc parsing for API discovery ────────

	/**
	 * Parse PHPDoc or YUIDoc block from a handler file.
	 * Supports both styles and degrades gracefully on malformed input.
	 *
	 * PHPDoc:  @param string $name Description
	 * YUIDoc:  @param {String} name Description
	 * YUIDoc:  @param name {String} Description
	 * YUIDoc:  @param {Object} [name=default] Description (optional)
	 *
	 * @method parseHandlerDoc
	 * @static
	 */
	static function parseHandlerDoc($filePath)
	{
		$result = array(
			'summary' => '',
			'description' => '',
			'params' => array(),
			'return' => '',
			'method' => '',
			'deprecated' => false,
		);

		try {
			$source = @file_get_contents($filePath, false, null, 0, 16384);
		} catch (\Exception $e) {
			return $result;
		}
		if (!$source) return $result;

		// Find /** ... */ blocks
		if (!preg_match_all('/\/\*\*\s*(.*?)\s*\*\//s', $source, $matches)) {
			return $result;
		}

		// Pick the docblock closest to (and before) the first function
		$funcPos = strpos($source, 'function ');
		$bestBlock = '';
		if ($funcPos !== false) {
			$bestDist = PHP_INT_MAX;
			foreach ($matches[0] as $i => $fullMatch) {
				$blockPos = strpos($source, $fullMatch);
				$blockEnd = $blockPos + strlen($fullMatch);
				$dist = $funcPos - $blockEnd;
				if ($dist >= 0 && $dist < $bestDist) {
					$bestDist = $dist;
					$bestBlock = $matches[1][$i];
				}
			}
		}
		if (!$bestBlock) $bestBlock = $matches[1][0] ?? '';
		if (!$bestBlock) return $result;

		// Clean: strip leading * from each line
		$lines = preg_split('/\r?\n/', $bestBlock);
		$cleaned = array();
		foreach ($lines as $line) {
			$cleaned[] = preg_replace('/^\s*\*\s?/', '', $line);
		}

		// Split into prose (summary + description) and tags
		$proseLines = array();
		$tagLines = array();
		$inTags = false;
		foreach ($cleaned as $line) {
			$trimmed = trim($line);
			if ($trimmed !== '' && $trimmed[0] === '@') {
				$inTags = true;
				$tagLines[] = $trimmed;
			} elseif ($inTags && $trimmed !== '') {
				// Continuation line for the previous tag
				if ($tagLines) {
					$tagLines[count($tagLines) - 1] .= ' ' . $trimmed;
				}
			} else {
				if (!$inTags) $proseLines[] = $trimmed;
			}
		}

		// Summary = first non-empty line(s) up to first blank line
		// Description = everything after the first blank line
		$summary = array();
		$desc = array();
		$pastBlank = false;
		foreach ($proseLines as $line) {
			if (!$pastBlank && $line === '' && $summary) {
				$pastBlank = true;
				continue;
			}
			if ($pastBlank) {
				$desc[] = $line;
			} else if ($line !== '') {
				$summary[] = $line;
			}
		}
		$result['summary'] = trim(implode(' ', $summary));
		$result['description'] = trim(implode(' ', $desc));

		// Parse tags — each pattern wrapped in try/catch for graceful degradation
		foreach ($tagLines as $tag) {
			try {
				$parsed = self::parseDocTag($tag);
				if (!$parsed) continue;

				switch ($parsed['tag']) {
					case 'param':
						// Skip the handler signature params ($params, $result)
						if (in_array($parsed['name'], array('params', 'result', ''))) continue 2;
						$result['params'][] = $parsed;
						break;
					case 'return':
					case 'returns':
						$result['return'] = $parsed['description']
							? ($parsed['type'] ? '{' . $parsed['type'] . '} ' : '') . $parsed['description']
							: ($parsed['type'] ?: '');
						break;
					case 'method':
						$result['method'] = $parsed['value'] ?? '';
						break;
					case 'deprecated':
						$result['deprecated'] = true;
						break;
					case 'private':
					case 'internal':
						$result['private'] = true;
						break;
				}
			} catch (\Exception $e) {
				// Malformed tag — skip it silently
				continue;
			}
		}

		return $result;
	}

	/**
	 * Parse a single @tag line. Handles PHPDoc and YUIDoc variants.
	 * Returns null for unrecognized tags.
	 * @method parseDocTag
	 * @static
	 */
	static function parseDocTag($tag)
	{
		if (!$tag || $tag[0] !== '@') return null;

		// Extract tag name
		if (!preg_match('/^@(\w+)\s*(.*)/s', $tag, $base)) return null;
		$tagName = strtolower($base[1]);
		$rest = trim($base[2]);

		// --- @param variants ---
		if ($tagName === 'param') {
			$name = ''; $type = ''; $desc = ''; $optional = false; $default = null;

			// PHPDoc: @param string $name Description
			if (preg_match('/^(\S+)\s+\$(\w+)\s*(.*)/s', $rest, $m)) {
				$type = $m[1]; $name = $m[2]; $desc = trim($m[3]);
			}
			// YUIDoc: @param {Type} name Description
			// YUIDoc: @param {Type} [name] Description (optional)
			// YUIDoc: @param {Type} [name=default] Description
			elseif (preg_match('/^\{([^}]*)\}\s+(\[?)(\$?\w[\w.]*)\]?(?:\s*=\s*(\S+))?\s*(.*)/s', $rest, $m)) {
				$type = $m[1]; $optional = ($m[2] === '['); $name = ltrim($m[3], '$'); $default = $m[4] ?? null; $desc = trim($m[5]);
			}
			// YUIDoc alternate: @param name {Type} Description
			elseif (preg_match('/^(\[?)(\$?\w[\w.]*)\]?(?:\s*=\s*\S+)?\s+\{([^}]*)\}\s*(.*)/s', $rest, $m)) {
				$optional = ($m[1] === '['); $name = ltrim($m[2], '$'); $type = $m[3]; $desc = trim($m[4]);
			}
			// Bare: @param $name Description (no type)
			elseif (preg_match('/^\$(\w+)\s*(.*)/s', $rest, $m)) {
				$name = $m[1]; $desc = trim($m[2]);
			}
			// Bare YUIDoc: @param name Description (no type)
			elseif (preg_match('/^(\w+)\s+(.*)/s', $rest, $m)) {
				$name = $m[1]; $desc = trim($m[2]);
			}
			else {
				return null; // Unparseable — skip gracefully
			}

			// Detect optional from type or description
			if (strpos($type, '?') === 0 || preg_match('/\bnull\b/i', $type)) {
				$optional = true;
				$type = preg_replace('/^\?|(\|null|null\|)/i', '', $type);
			}
			if ($default !== null) $optional = true;

			// Strip nested param prefix (config.name → name) for dotted YUIDoc params
			// but keep the full name for context
			$type = trim($type);

			return array('tag' => 'param', 'name' => $name, 'type' => $type,
				'description' => $desc, 'optional' => $optional, 'default' => $default);
		}

		// --- @return / @returns ---
		if ($tagName === 'return' || $tagName === 'returns') {
			$type = ''; $desc = '';
			// {Type} Description
			if (preg_match('/^\{([^}]*)\}\s*(.*)/s', $rest, $m)) {
				$type = $m[1]; $desc = trim($m[2]);
			}
			// Type Description (PHPDoc)
			elseif (preg_match('/^(\S+)\s*(.*)/s', $rest, $m)) {
				$type = $m[1]; $desc = trim($m[2]);
			}
			return array('tag' => 'return', 'type' => $type, 'description' => $desc);
		}

		// --- @method ---
		if ($tagName === 'method') {
			return array('tag' => 'method', 'value' => $rest);
		}

		// --- @deprecated ---
		if ($tagName === 'deprecated') {
			return array('tag' => 'deprecated', 'value' => $rest);
		}

		// --- @private / @internal / @access private ---
		if ($tagName === 'private' || $tagName === 'internal') {
			return array('tag' => 'private', 'value' => $rest);
		}
		if ($tagName === 'access' && strtolower(trim($rest)) === 'private') {
			return array('tag' => 'private', 'value' => '');
		}

		// --- @throws / @throw ---
		if ($tagName === 'throws' || $tagName === 'throw') {
			$type = ''; $desc = '';
			if (preg_match('/^\{([^}]*)\}\s*(.*)/s', $rest, $m)) {
				$type = $m[1]; $desc = trim($m[2]);
			} elseif (preg_match('/^(\S+)\s*(.*)/s', $rest, $m)) {
				$type = $m[1]; $desc = trim($m[2]);
			}
			return array('tag' => 'throws', 'type' => $type, 'description' => $desc);
		}

		// Unknown tag — skip
		return null;
	}

	/**
	 * Map PHP type annotations to JSON Schema types.
	 * @method phpTypeToJsonSchema
	 * @static
	 */
	static function phpTypeToJsonSchema($phpType)
	{
		$phpType = strtolower(trim($phpType));
		// Strip nullable prefix
		$phpType = ltrim($phpType, '?');

		$map = array(
			'string' => 'string',
			'int' => 'integer', 'integer' => 'integer',
			'float' => 'number', 'double' => 'number', 'number' => 'number',
			'bool' => 'boolean', 'boolean' => 'boolean',
			'array' => 'object',
			'object' => 'object',
			'mixed' => 'string',
		);

		// Handle array<type> or type[]
		if (preg_match('/^array\s*<\s*(.+)\s*>$/i', $phpType, $m) || preg_match('/^(\w+)\[\]$/', $phpType, $m)) {
			return 'array';
		}

		return $map[$phpType] ?? 'string';
	}

	/**
	 * Check if a handler should be hidden from API discovery.
	 * @method isHandlerHidden
	 * @static
	 * @param {string} $eventName e.g. "admin/cleanup"
	 * @param {array} $patterns Patterns from Q.api.discover.hidden config
	 * @return {boolean}
	 */
	static function isHandlerHidden($eventName, $patterns)
	{
		foreach ($patterns as $pattern => $hidden) {
			if ($hidden && @preg_match(self::ensureRegex($pattern), $eventName)) return true;
		}
		return false;
	}

	// ── .well-known endpoints ────────────────────────────

	/**
	 * Qbix server manifest — server identity, fingerprint, capabilities.
	 */
	static function wellKnownQbix($parsed)
	{
		if (Q_Config::get('Q', 'federation', 'advertise', true) === false) {
			return array('status' => 404, 'body' => 'Not found');
		}
		$host = $parsed['headers']['host'] ?? 'localhost';
		$identity = Q_WebServer_Identity::serverIdentity();
		$info = array(
			'server' => 'Qbix Server',
			'version' => defined('QBIX_SERVER_VERSION') ? QBIX_SERVER_VERSION : '1.0.0',
			'fingerprint' => $identity ? $identity['fingerprint'] : null,
			'endpoints' => array(
				'event' => '/Q/event',
				'health' => '/Q/health',
				'openapi' => '/.well-known/openapi.json',
				'mcp' => '/.well-known/mcp.json',
				'websocket' => '/Q/ws',
			),
			'links' => array(
				'openapi' => 'https://' . $host . '/.well-known/openapi.json',
				'mcp' => 'https://' . $host . '/.well-known/mcp.json',
				'health' => 'https://' . $host . '/Q/health',
				'openclaiming' => 'https://' . $host . '/.well-known/openclaiming/' . preg_replace('/:\d+$/', '', $host) . '/server.json',
			),
		);
		if (Q_Config::get('Q', 'federation', 'advertiseApps', false)) {
			$apps = Q_WebServer_Panel::apiListApps();
			$info['apps'] = array_map(function ($a) {
				return array('name' => $a['name'], 'plugins' => $a['plugins'] ?? array());
			}, $apps['apps'] ?? array());
		}
		if (Q_Config::get('Q', 'federation', 'advertisePlugins', true)) {
			$plugins = Q_WebServer_Panel::apiListPlugins();
			$info['plugins'] = array_map(function ($p) {
				return array('name' => $p['name'], 'version' => $p['version'] ?? null);
			}, $plugins['plugins'] ?? array());
		}
		return array('status' => 200,
			'body' => json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			'headers' => array('Content-Type' => 'application/json',
				'Access-Control-Allow-Origin' => '*'));
	}

	/**
	 * OpenAPI 3.1 spec — compatible with Swagger UI, Postman, Redoc.
	 * Auto-generated from the server's actual endpoints.
	 */
	static function wellKnownOpenAPI($parsed)
	{
		$host = $parsed['headers']['host'] ?? 'localhost';
		$spec = array(
			'openapi' => '3.1.0',
			'info' => array(
				'title' => 'Qbix Server API',
				'version' => defined('QBIX_SERVER_VERSION') ? QBIX_SERVER_VERSION : '1.0.0',
				'description' => 'Auto-generated API spec for this Qbix Server instance.',
				'contact' => array('url' => 'https://github.com/Qbix/Server'),
			),
			'servers' => array(
				array('url' => 'https://' . $host, 'description' => 'This server'),
			),
			'paths' => array(
				'/Q/health' => array(
					'get' => array(
						'summary' => 'Server health check',
						'operationId' => 'health',
						'tags' => array('System'),
						'responses' => array(
							'200' => array('description' => 'Server status',
								'content' => array('application/json' => array(
									'schema' => array('type' => 'object',
										'properties' => array(
											'status' => array('type' => 'string', 'example' => 'ok'),
											'uptimeSec' => array('type' => 'integer'),
											'memory' => array('type' => 'number'),
											'php' => array('type' => 'string'),
										))))),
						),
					),
				),
				'/Q/event' => array(
					'post' => array(
						'summary' => 'Forward an event to this server',
						'operationId' => 'event',
						'tags' => array('Federation'),
						'description' => 'Dispatches a Q::event() on this server. Requests must be signed with Q_Utils::sign().',
						'requestBody' => array(
							'required' => true,
							'content' => array('application/json' => array(
								'schema' => array('type' => 'object',
									'required' => array('event'),
									'properties' => array(
										'event' => array('type' => 'string', 'example' => 'Users/login'),
										'params' => array('type' => 'object'),
										'_msgId' => array('type' => 'string', 'description' => 'Unique message ID for loop prevention'),
										'Q.sig' => array('type' => 'string', 'description' => 'HMAC-SHA1 signature'),
									)))),
						),
						'responses' => array(
							'200' => array('description' => 'Event result'),
							'401' => array('description' => 'Invalid signature'),
							'403' => array('description' => 'Unknown peer'),
						),
					),
				),
				'/.well-known/qbix.json' => array(
					'get' => array(
						'summary' => 'Server discovery manifest',
						'operationId' => 'discovery',
						'tags' => array('Discovery'),
						'responses' => array(
							'200' => array('description' => 'Server identity, fingerprint, and capabilities'),
						),
					),
				),
			),
			'tags' => array(
				array('name' => 'System', 'description' => 'Server status and management'),
				array('name' => 'Federation', 'description' => 'Inter-server event forwarding'),
				array('name' => 'Discovery', 'description' => 'Server identity and API discovery'),
			),
		);

		// Add app-defined routes from handlers/ directory, parsed from PHPDoc
		$handlersDir = (defined('APP_DIR') ? APP_DIR : dirname(self::$rootDir)) . DS . 'handlers';
		if (is_dir($handlersDir)) {
			$hiddenPatterns = Q_Config::get('Q', 'api', 'discover', 'hidden', array());
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($handlersDir, RecursiveDirectoryIterator::SKIP_DOTS)
			);
			foreach ($it as $file) {
				if ($file->getExtension() !== 'php') continue;
				$rel = str_replace(DS, '/', substr($file->getPathname(), strlen($handlersDir) + 1));
				$eventName = str_replace('.php', '', $rel);
				$doc = self::parseHandlerDoc($file->getPathname());

				// Skip private/internal handlers
				if (!empty($doc['private'])) continue;
				// Skip config-hidden paths
				if (self::isHandlerHidden($eventName, $hiddenPatterns)) continue;

				$properties = array();
				$required = array();
				foreach ($doc['params'] as $p) {
					$prop = array('description' => $p['description']);
					if ($p['type']) $prop['type'] = self::phpTypeToJsonSchema($p['type']);
					$properties[$p['name']] = $prop;
					if (!$p['optional']) $required[] = $p['name'];
				}

				$schema = array('type' => 'object');
				if ($properties) $schema['properties'] = $properties;
				if ($required) $schema['required'] = $required;

				$operation = array(
					'summary' => $doc['summary'] ?: "Handler: $eventName",
					'operationId' => str_replace('/', '_', $eventName),
					'tags' => array('Handlers'),
					'description' => ($doc['description'] ? $doc['description'] . "\n\n" : '')
						. "Dispatch via POST /Q/event with {\"event\": \"$eventName\", \"params\": {...}}",
					'requestBody' => array(
						'content' => array('application/json' => array(
							'schema' => array('type' => 'object', 'properties' => array(
								'event' => array('type' => 'string', 'example' => $eventName),
								'params' => $schema,
							)),
						)),
					),
					'responses' => array('200' => array(
						'description' => $doc['return'] ?: 'Handler result',
					)),
				);

				$spec['paths']['/handler/' . $eventName] = array('post' => $operation);
			}
			if (count($spec['paths']) > 3) {
				$spec['tags'][] = array('name' => 'Handlers', 'description' => 'App event handlers');
			}
		}

		return array('status' => 200,
			'body' => json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			'headers' => array('Content-Type' => 'application/json',
				'Access-Control-Allow-Origin' => '*'));
	}

	/**
	 * MCP (Model Context Protocol) server manifest.
	 * Allows AI tools (Claude, etc.) to discover and call this server's APIs.
	 */
	static function wellKnownMCP($parsed)
	{
		$host = $parsed['headers']['host'] ?? 'localhost';

		// Discover available tools from handlers
		$tools = array();

		// Built-in tools
		$tools[] = array(
			'name' => 'health',
			'description' => 'Check server health and uptime',
			'inputSchema' => array('type' => 'object', 'properties' => new \stdClass()),
		);
		$tools[] = array(
			'name' => 'event',
			'description' => 'Dispatch a Q::event() on this server',
			'inputSchema' => array(
				'type' => 'object',
				'required' => array('event'),
				'properties' => array(
					'event' => array('type' => 'string', 'description' => 'Event name (e.g. Users/login)'),
					'params' => array('type' => 'object', 'description' => 'Event parameters'),
				),
			),
		);

		// Add handler-based tools with PHPDoc metadata
		$handlersDir = (defined('APP_DIR') ? APP_DIR : dirname(self::$rootDir)) . DS . 'handlers';
		if (is_dir($handlersDir)) {
			$hiddenPatterns = Q_Config::get('Q', 'api', 'discover', 'hidden', array());
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($handlersDir, RecursiveDirectoryIterator::SKIP_DOTS)
			);
			foreach ($it as $file) {
				if ($file->getExtension() !== 'php') continue;
				$rel = str_replace(DS, '/', substr($file->getPathname(), strlen($handlersDir) + 1));
				$eventName = str_replace('.php', '', $rel);
				$doc = self::parseHandlerDoc($file->getPathname());

				// Skip private/internal handlers
				if (!empty($doc['private'])) continue;
				if (self::isHandlerHidden($eventName, $hiddenPatterns)) continue;

				$properties = array();
				$required = array();
				foreach ($doc['params'] as $p) {
					$prop = array('description' => $p['description']);
					if ($p['type']) $prop['type'] = self::phpTypeToJsonSchema($p['type']);
					$properties[$p['name']] = $prop;
					if (!$p['optional']) $required[] = $p['name'];
				}

				$schema = array('type' => 'object');
				if ($properties) $schema['properties'] = $properties;
				if ($required) $schema['required'] = $required;

				$description = $doc['summary'] ?: "Dispatch event: $eventName";
				if ($doc['description']) {
					$description .= ' — ' . $doc['description'];
				}

				$tools[] = array(
					'name' => str_replace('/', '_', $eventName),
					'description' => $description,
					'inputSchema' => $schema,
				);
			}
		}

		$manifest = array(
			'schema_version' => '2025-01-01',
			'name' => 'qbix-server',
			'display_name' => 'Qbix Server on ' . $host,
			'description' => 'Qbix Server instance — PHP web server with WebSocket, rooms, and federation.',
			'url' => 'https://' . $host,
			'provider' => array(
				'name' => 'Qbix',
				'url' => 'https://qbix.com',
			),
			'tools' => $tools,
			'links' => array(
				'openapi' => 'https://' . $host . '/.well-known/openapi.json',
				'health' => 'https://' . $host . '/Q/health',
			),
		);

		return array('status' => 200,
			'body' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			'headers' => array('Content-Type' => 'application/json',
				'Access-Control-Allow-Origin' => '*'));
	}

	/**
	 * OpenClaiming endpoint. Serves signed claims from three sources:
	 *
	 * 1. claims/{domain}/{name}.php  → dynamic, auto-signed by server
	 * 2. claims/{domain}/{name}.json → template, auto-signed by server
	 * 3. web/.well-known/openclaiming/{domain}/{name}.json → pre-signed, as-is
	 *
	 * Server auto-generates its own claim at {hostname}/server.json.
	 * Signed claims are cached in files/Q/cached/claims/ keyed by mtime.
	 */
	static function wellKnownOpenClaiming($parsed, $wellKnown)
	{
		$host = $parsed['headers']['host'] ?? 'localhost';
		$hostname = preg_replace('/:\d+$/', '', $host);

		// Strip "openclaiming/" prefix
		$claimPath = substr($wellKnown, strlen('openclaiming/'));
		if (!$claimPath) return null;

		// Security: block traversal
		if (strpos($claimPath, '..') !== false) {
			return array('status' => 400, 'body' => 'Invalid path');
		}

		// 1. Auto-generated server identity claim
		if ($claimPath === $hostname . '/server.json' || $claimPath === 'server.json') {
			$claim = Q_WebServer_Identity::serverClaim($hostname);
			if (!$claim) {
				return array('status' => 500,
					'body' => json_encode(array('error' => 'Could not generate claim')),
					'headers' => array('Content-Type' => 'application/json'));
			}
			return self::claimResponse($claim);
		}

		// Find base directories
		$base = defined('APP_DIR') ? APP_DIR : dirname(self::$rootDir);
		$claimsDir = $base . DS . 'claims';
		$cacheDir = $base . DS . 'files' . DS . 'Q' . DS . 'cached' . DS . 'claims';

		// Strip .json extension for lookup
		$lookupPath = preg_replace('/\.json$/', '', $claimPath);
		$safePath = str_replace('/', DS, $lookupPath);

		// 2. PHP claim (dynamic, auto-signed)
		$phpFile = $claimsDir . DS . $safePath . '.php';
		if (file_exists($phpFile)) {
			$params = $parsed['query'] ?? '';
			$queryParams = array();
			if ($params) parse_str($params, $queryParams);
			$claim = self::loadClaimPhp($phpFile, $queryParams);
			if ($claim) {
				$claim = Q_WebServer_Identity::signClaim($claim);
				return self::claimResponse($claim);
			}
		}

		// 3. JSON template (static, auto-signed with caching)
		$jsonFile = $claimsDir . DS . $safePath . '.json';
		if (file_exists($jsonFile)) {
			$mtime = filemtime($jsonFile);
			$cacheFile = $cacheDir . DS . $safePath . '.' . $mtime . '.json';

			// Cache hit
			if (file_exists($cacheFile)) {
				$cached = json_decode(file_get_contents($cacheFile), true);
				if ($cached) return self::claimResponse($cached);
			}

			// Sign and cache
			$template = json_decode(file_get_contents($jsonFile), true);
			if ($template) {
				$signed = Q_WebServer_Identity::signClaim($template);
				$dir = dirname($cacheFile);
				if (!is_dir($dir)) @mkdir($dir, 0755, true);
				file_put_contents($cacheFile, json_encode($signed, JSON_UNESCAPED_SLASHES));
				return self::claimResponse($signed);
			}
		}

		// 4. Fall through to static file serving (pre-signed claims in web/)
		return null;
	}

	private static function loadClaimPhp($file, $params = array())
	{
		try {
			$claim = include $file;
			if (is_array($claim)) return $claim;
		} catch (\Exception $e) {
			// Log but don't crash
		}
		return null;
	}

	private static function claimResponse($claim)
	{
		return array('status' => 200,
			'body' => json_encode($claim, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			'headers' => array(
				'Content-Type' => 'application/json',
				'Access-Control-Allow-Origin' => '*',
				'Cache-Control' => 'public, max-age=300',
			));
	}

	static function handleBulkImageZip($client, $parsed)
	{
		if (!class_exists('ZipArchive')) {
			self::sendResponse($client, 500, 'ZipArchive extension not available');
			return;
		}

		// Parse form data
		$body = $parsed['body'] ?? '';
		parse_str($body, $post);
		$files = json_decode($post['files'] ?? '[]', true);
		if (!$files || !is_array($files) || count($files) > 200) {
			self::sendResponse($client, 400, 'Invalid files list');
			return;
		}

		$tmpZip = tempnam(sys_get_temp_dir(), 'qbix_zip_') . '.zip';
		$zip = new \ZipArchive();
		if ($zip->open($tmpZip, \ZipArchive::CREATE) !== true) {
			self::sendResponse($client, 500, 'Cannot create ZIP');
			return;
		}

		$rename = !empty($post['rename']) && $post['rename'] !== '0';

		foreach ($files as $url) {
			// Parse URL into path + query
			$parts = parse_url($url);
			$path = $parts['path'] ?? '';
			$query = $parts['query'] ?? '';
			$qp = array();
			if ($query) parse_str($query, $qp);

			$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
			$fsPath = self::resolveStatic($path);

			// Try image handler (resize/convert)
			$fakeParsed = array(
				'path' => $path,
				'query' => $query,
				'headers' => $parsed['headers'] ?? array(),
			);
			$imgResponse = Q_WebServer_Image::handle($fsPath, $path, $fakeParsed);

			if ($imgResponse && !empty($imgResponse['body'])) {
				$filename = pathinfo($path, PATHINFO_FILENAME) . '.' . $ext;
				if ($rename && !empty($qp['w'])) {
					$filename = pathinfo($path, PATHINFO_FILENAME) . '-' . $qp['w'] . 'w.' . $ext;
				}
				$zip->addFromString($filename, $imgResponse['body']);
			} elseif ($fsPath && is_file($fsPath)) {
				$zip->addFile($fsPath, pathinfo($path, PATHINFO_BASENAME));
			}
		}

		$zip->close();

		if (!file_exists($tmpZip)) {
			self::sendResponse($client, 500, 'ZIP creation failed');
			return;
		}

		$zipData = file_get_contents($tmpZip);
		@unlink($tmpZip);

		self::sendResponse($client, 200, $zipData, 'application/zip', array(
			'Content-Disposition' => 'attachment; filename="images.zip"',
			'Content-Length' => (string) strlen($zipData),
		));
	}

	static function cleanupUploadFiles()
	{
		foreach (self::$uploadTempFiles as $f) {
			if ($f && file_exists($f)) @unlink($f);
		}
		self::$uploadTempFiles = array();
	}

	/**
	 * Handle an incoming remote event forwarded from another Qbix server.
	 * Verifies HMAC signature if internal secret is configured.
	 * @method handleRemoteEvent
	 * @static
	 */
	/** @var array Message IDs already processed — prevents loops in federation */
	static $seenMessages = array();
	/** @var integer TTL for seen messages in seconds */
	static $seenMessageTTL = 3600; // 1 hour

	static function handleRemoteEvent($parsed)
	{
		$body = json_decode($parsed['body'], true);
		if (!$body || empty($body['event'])) {
			return array('status' => 400,
				'body' => json_encode(array('error' => 'Missing event')),
				'headers' => array('Content-Type' => 'application/json'));
		}

		// Per-message loop prevention
		$msgId = $body['_msgId'] ?? '';
		if ($msgId) {
			// Evict expired entries
			$now = time();
			foreach (self::$seenMessages as $id => $ts) {
				if ($now - $ts > self::$seenMessageTTL) unset(self::$seenMessages[$id]);
			}
			// Check if already seen
			if (isset(self::$seenMessages[$msgId])) {
				return array('status' => 200,
					'body' => json_encode(array('_duplicate' => true, '_msgId' => $msgId)),
					'headers' => array('Content-Type' => 'application/json'));
			}
			// Mark as seen
			self::$seenMessages[$msgId] = $now;
		}

		// Verify signature — check X-Q-HMAC header (Platform convention)
		// or Q.sig in body (Q_Utils::sign convention). Either is valid.
		$secret = Q_Config::get('Q', 'internal', 'secret', null);
		if ($secret) {
			$hmacHeader = $parsed['headers']['x-q-hmac'] ?? '';
			$bodyValid = Q_WebServer_Identity::verify($body, $secret);
			$headerValid = $hmacHeader && hash_equals(
				hash_hmac('sha1', $parsed['body'], $secret), $hmacHeader
			);
			if (!$bodyValid && !$headerValid) {
				return array('status' => 401,
					'body' => json_encode(array('error' => 'Invalid signature')),
					'headers' => array('Content-Type' => 'application/json'));
			}
		}

		// Check fingerprint against known peers
		$fingerprint = $parsed['headers']['x-q-fingerprint'] ?? '';
		$knownPeers = Q_Config::get('Q', 'federation', 'peers', array());
		if ($knownPeers && $fingerprint) {
			$trusted = false;
			foreach ($knownPeers as $peer) {
				if (($peer['fingerprint'] ?? '') === $fingerprint) {
					$trusted = true; break;
				}
			}
			if (!$trusted && Q_Config::get('Q', 'federation', 'requireKnownPeers', false)) {
				return array('status' => 403,
					'body' => json_encode(array('error' => 'Unknown peer')),
					'headers' => array('Content-Type' => 'application/json'));
			}
		}

		$eventName = $body['event'];
		$params = $body['params'] ?? array();

		// Dispatch locally — skip remote forwarding to avoid loops
		$saved = Q_Config::get('Q', 'handlersRemote', $eventName, null);
		if ($saved) Q_Config::set('Q', 'handlersRemote', $eventName, null);

		$result = null;
		Q::event($eventName, $params, false, false, $result);

		if ($saved) Q_Config::set('Q', 'handlersRemote', $eventName, $saved);

		return array('status' => 200,
			'body' => json_encode($result),
			'headers' => array('Content-Type' => 'application/json'));
	}

	/**
	 * Ensure a string is a valid regex. If it already has delimiters
	 * (starts with # / ~ { or another non-alnum), return as-is.
	 * Otherwise wrap with #^ ... $# so plain strings like
	 * "/wp-admin/" work as patterns without manual delimiters.
	 * @method ensureRegex
	 * @static
	 * @param {string} $pattern
	 * @return {string}
	 */
	static function ensureRegex($pattern)
	{
		if ($pattern === '') return "\x01^\$\x01";
		// Only treat # as a pre-existing delimiter (our convention).
		if ($pattern[0] === '#') {
			return $pattern;
		}
		// Use \x01 as delimiter — won't appear in URL patterns
		return "\x01" . $pattern . "\x01";
	}
}
