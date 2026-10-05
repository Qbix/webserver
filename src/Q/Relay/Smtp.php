<?php

/**
 * Q_Relay_Smtp_Scanner
 *
 * Tracks the last 5 bytes of incoming DATA to detect the SMTP
 * dot-terminator sequence "\r\n.\r\n" (RFC 5321 §4.1.1.4).
 *
 * Also handles the edge case where the message body is empty and the
 * client sends ".\r\n" as the very first 3 bytes after entering DATA mode.
 */
class Q_Relay_Smtp_Scanner
{
	/** @var string Ring of the last 5 bytes seen */
	private $tail = '';

	/** @var int Total bytes pushed */
	private $total = 0;

	/** @var bool Whether the terminator has been detected */
	private $terminated = false;

	/**
	 * Feed a chunk of data into the scanner.
	 *
	 * @param string $chunk Raw bytes from the socket
	 */
	public function push($chunk)
	{
		$len = strlen($chunk);
		if ($len === 0) {
			return;
		}
		$this->total += $len;
		$this->tail .= $chunk;
		// Keep only the last 5 bytes
		if (strlen($this->tail) > 5) {
			$this->tail = substr($this->tail, -5);
		}

		// Normal case: full 5-byte terminator "\r\n.\r\n"
		if (strlen($this->tail) >= 5
			&& substr($this->tail, -5) === "\r\n.\r\n"
		) {
			$this->terminated = true;
			return;
		}

		// Edge case: empty body — client sends ".\r\n" as the very first
		// 3 bytes after the DATA interim reply.
		if ($this->total <= 3 && substr($this->tail, -3) === ".\r\n") {
			// Verify the total accumulated data is exactly ".\r\n"
			if ($this->total === 3) {
				$this->terminated = true;
			}
		}
	}

	/**
	 * @return bool True when the dot-terminator has been detected
	 */
	public function isTerminated()
	{
		return $this->terminated;
	}

	/**
	 * @return int Total bytes pushed so far
	 */
	public function getTotal()
	{
		return $this->total;
	}
}

/**
 * Q_Relay_Smtp
 *
 * Inbound SMTP protocol handler for the Qbix Server relay system.
 *
 * Uses plain PHP stream sockets (stream_socket_server, stream_select,
 * stream_socket_accept) — no ext-event or ext-ev required.
 *
 * The class exposes all-static methods. The primary interface is:
 *
 *   $server   = Q_Relay_Smtp::createServer($host, $port, $onMessage, $options);
 *   $sessions = [];
 *   while (true) {
 *       $sessions = Q_Relay_Smtp::tick($server, $sessions, $onMessage, $options);
 *   }
 *
 * $onMessage receives function($mailFrom, $rcptTo, $rawMessage).
 */
class Q_Relay_Smtp
{
	// -----------------------------------------------------------------
	// Default configuration
	// -----------------------------------------------------------------

	const DEFAULT_HOST           = '127.0.0.1';
	const DEFAULT_PORT           = 2525;
	const DEFAULT_MAX_SIZE       = 26214400; // 25 MB
	const DEFAULT_MAX_LINES      = 200000;
	const DEFAULT_MAX_RECIPIENTS = 50;
	const DEFAULT_MAX_CONCURRENT = 50;
	const DEFAULT_SESSION_TIMEOUT = 300; // seconds

	// -----------------------------------------------------------------
	// Dot-stuffing helpers (RFC 5321 §4.5.2)
	// -----------------------------------------------------------------

	/**
	 * Remove dot-stuffing from inbound DATA.
	 *
	 * Any line that begins with a dot has that leading dot stripped.
	 *
	 * @param string $data Raw message body after the terminator is removed
	 * @return string Unstuffed message
	 */
	public static function inboundDotUnstuff($data)
	{
		// Replace "\r\n.." with "\r\n." etc. — strip exactly one leading dot
		// per line.  The first line has no preceding CRLF, so handle it too.
		$out = preg_replace('/^\.(.)/m', '$1', $data);
		return $out;
	}

	/**
	 * Prepend a dot to every line that already starts with a dot (outbound).
	 *
	 * @param string $s Message text
	 * @return string Dot-stuffed text
	 */
	public static function outboundDotStuff($s)
	{
		return preg_replace('/^\./m', '..', $s);
	}

	// -----------------------------------------------------------------
	// Configuration helper
	// -----------------------------------------------------------------

	/**
	 * Read a config value, falling back to a default.
	 *
	 * @param string $key    Dot-separated config path under "Q.relay.smtp"
	 * @param mixed  $default Fallback value
	 * @return mixed
	 */
	private static function cfg($key, $default)
	{
		if (class_exists('Q_Config')) {
			$val = Q_Config::get('Q', 'relay', 'smtp', $key, $default);
			return $val;
		}
		return $default;
	}

	// -----------------------------------------------------------------
	// Server creation
	// -----------------------------------------------------------------

	/**
	 * Create and return a listening stream socket server.
	 *
	 * @param string   $host      Bind address (e.g. "127.0.0.1")
	 * @param int      $port      Bind port (e.g. 2525)
	 * @param callable $onMessage Callback: function($mailFrom, $rcptTo, $rawMessage)
	 * @param array    $options   Optional overrides:
	 *   - maxSize        int   Max message size in bytes
	 *   - maxLines       int   Max lines in DATA
	 *   - maxRecipients  int   Max RCPT TO per transaction
	 *   - maxConcurrent  int   Max simultaneous connections
	 *   - sessionTimeout int   Idle timeout in seconds
	 *   - requireAuth    bool  Require AUTH before MAIL FROM
	 *   - authUser       string  Expected AUTH username
	 *   - authPass       string  Expected AUTH password
	 *   - tlsContext     array   stream_context options for STARTTLS
	 * @return resource Stream socket server
	 * @throws \RuntimeException on bind failure or open-relay misconfiguration
	 */
	public static function createServer($host, $port, $onMessage, $options = [])
	{
		$host = $host ?: self::cfg('listenHost', self::DEFAULT_HOST);
		$port = $port ?: self::cfg('listenPort', self::DEFAULT_PORT);

		// Open-relay protection: refuse to bind a non-loopback address
		// unless authentication is configured.
		$requireAuth = !empty($options['requireAuth']);
		$hasAuth = !empty($options['authUser']) && !empty($options['authPass']);
		$isLoopback = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);

		if (!$isLoopback && !$requireAuth && !$hasAuth) {
			throw new \RuntimeException(
				"Q_Relay_Smtp: Refusing to bind non-loopback address "
				. "$host without authentication configured (open relay protection)."
			);
		}

		$uri = "tcp://$host:$port";
		$ctx = stream_context_create();
		$errno = 0;
		$errstr = '';
		$server = @stream_socket_server(
			$uri, $errno, $errstr,
			STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx
		);
		if (!$server) {
			throw new \RuntimeException(
				"Q_Relay_Smtp: Could not bind to $uri — [$errno] $errstr"
			);
		}
		stream_set_blocking($server, false);
		return $server;
	}

	// -----------------------------------------------------------------
	// Event loop tick
	// -----------------------------------------------------------------

	/**
	 * One iteration of the event loop.
	 *
	 * Accepts new connections, reads from existing ones, enforces idle
	 * timeouts, and delivers completed messages via $onMessage.
	 *
	 * Designed to be called from the relay's main loop:
	 *
	 *   while (true) {
	 *       $sessions = Q_Relay_Smtp::tick($server, $sessions, $onMessage, $options, 0.2);
	 *   }
	 *
	 * @param resource $server    The listening socket from createServer()
	 * @param array    $sessions  Current session list (keyed by integer id)
	 * @param callable $onMessage Callback: function($mailFrom, $rcptTo, $rawMessage)
	 * @param array    $options   Same options as createServer()
	 * @param float    $timeout   stream_select timeout in seconds (0 = non-blocking)
	 * @return array Updated sessions array
	 */
	public static function tick($server, $sessions, $onMessage, $options, $timeout = 0)
	{
		$maxConcurrent = isset($options['maxConcurrent'])
			? (int)$options['maxConcurrent']
			: (int)self::cfg('maxConcurrent', self::DEFAULT_MAX_CONCURRENT);

		$sessionTimeout = isset($options['sessionTimeout'])
			? (int)$options['sessionTimeout']
			: (int)self::cfg('sessionTimeout', self::DEFAULT_SESSION_TIMEOUT);

		// Build read-set: server socket + all session sockets
		$readSocks = [$server];
		foreach ($sessions as $id => $sess) {
			if (!$sess['closed'] && is_resource($sess['sock'])) {
				$readSocks[] = $sess['sock'];
			}
		}

		$writeSocks = null;
		$exceptSocks = null;

		$tvSec = (int)floor($timeout);
		$tvUsec = (int)(($timeout - $tvSec) * 1000000);

		$changed = @stream_select($readSocks, $writeSocks, $exceptSocks, $tvSec, $tvUsec);

		if ($changed === false) {
			// stream_select error (signal interrupt, etc.) — just return
			return $sessions;
		}

		if ($changed > 0) {
			foreach ($readSocks as $sock) {
				if ($sock === $server) {
					// --- Accept new connection ---
					$activeSessions = 0;
					foreach ($sessions as $s) {
						if (!$s['closed']) {
							$activeSessions++;
						}
					}

					$client = @stream_socket_accept($server, 0, $peerName);
					if (!$client) {
						continue;
					}

					if ($activeSessions >= $maxConcurrent) {
						@fwrite($client, "421 Too many connections, try again later\r\n");
						@fclose($client);
						continue;
					}

					stream_set_blocking($client, false);

					$requireAuth = !empty($options['requireAuth']);
					$sess = [
						'sock'           => $client,
						'closed'         => false,
						'tlsUpgraded'    => false,
						'remote'         => $peerName ?: 'unknown',
						'authed'         => !$requireAuth,
						'expectAuthUser' => false,
						'expectAuthPass' => false,
						'cmdBuffer'      => '',
						'mailFrom'       => null,
						'rcptTo'         => [],
						'dataMode'       => false,
						'rawChunks'      => [],
						'dataBytes'      => 0,
						'dataLines'      => 0,
						'scanner'        => new Q_Relay_Smtp_Scanner(),
						'lastFrom'       => null,
						'lastRcpt'       => [],
						'lastActivity'   => time(),
					];

					self::send($sess, "220 ESMTP Qbix Relay");
					$sessions[] = $sess;
				} else {
					// --- Read from existing session ---
					$sessId = null;
					foreach ($sessions as $id => &$s) {
						if (!$s['closed'] && is_resource($s['sock']) && $s['sock'] === $sock) {
							$sessId = $id;
							break;
						}
					}
					unset($s);

					if ($sessId === null) {
						continue;
					}

					$chunk = @fread($sock, 65536);
					if ($chunk === false || $chunk === '') {
						// Connection closed by peer
						self::closeSession($sessions[$sessId]);
						continue;
					}

					$sessions[$sessId]['lastActivity'] = time();
					self::handleChunk(
						$sessions[$sessId], $chunk, $onMessage, $options
					);
				}
			}
		}

		// --- Enforce idle timeouts and prune closed sessions ---
		$now = time();
		foreach ($sessions as $id => &$sess) {
			if ($sess['closed']) {
				continue;
			}
			if (($now - $sess['lastActivity']) > $sessionTimeout) {
				self::send($sess, "421 Idle timeout, closing connection");
				self::closeSession($sess);
			}
		}
		unset($sess);

		// Remove closed sessions
		$sessions = array_values(array_filter($sessions, function ($s) {
			return !$s['closed'];
		}));

		return $sessions;
	}

	// -----------------------------------------------------------------
	// Data handling
	// -----------------------------------------------------------------

	/**
	 * Process an incoming chunk of data for a session.
	 *
	 * In DATA mode the chunk is accumulated and checked against size/line
	 * limits.  When the dot-terminator is detected, finishData() is called.
	 *
	 * In command mode the chunk is appended to the command buffer and
	 * complete lines are extracted and dispatched to handleCommand().
	 *
	 * @param array    &$sess     Session array (by reference)
	 * @param string   $chunk     Raw bytes from the socket
	 * @param callable $onMessage Delivery callback
	 * @param array    $options   Server options
	 */
	public static function handleChunk(&$sess, $chunk, $onMessage, $options)
	{
		if ($sess['closed']) {
			return;
		}

		if ($sess['dataMode']) {
			$maxSize = isset($options['maxSize'])
				? (int)$options['maxSize']
				: (int)self::cfg('maxSize', self::DEFAULT_MAX_SIZE);
			$maxLines = isset($options['maxLines'])
				? (int)$options['maxLines']
				: (int)self::cfg('maxLines', self::DEFAULT_MAX_LINES);

			$sess['rawChunks'][] = $chunk;
			$sess['dataBytes'] += strlen($chunk);
			$sess['dataLines'] += substr_count($chunk, "\n");
			$sess['scanner']->push($chunk);

			if ($sess['dataBytes'] > $maxSize) {
				self::send($sess, "552 Message exceeds maximum size");
				self::resetTransaction($sess);
				return;
			}
			if ($sess['dataLines'] > $maxLines) {
				self::send($sess, "552 Message exceeds maximum line count");
				self::resetTransaction($sess);
				return;
			}
			if ($sess['scanner']->isTerminated()) {
				self::finishData($sess, $onMessage);
			}
			return;
		}

		// Command mode — buffer and extract CRLF-terminated lines
		$sess['cmdBuffer'] .= $chunk;

		while (($pos = strpos($sess['cmdBuffer'], "\r\n")) !== false) {
			$line = substr($sess['cmdBuffer'], 0, $pos);
			$sess['cmdBuffer'] = substr($sess['cmdBuffer'], $pos + 2);

			$response = self::handleCommand($sess, $line, $options);
			if ($response !== null) {
				self::send($sess, $response);
			}

			if ($sess['closed']) {
				return;
			}
		}

		// Guard against absurdly long command lines (no CRLF yet)
		if (strlen($sess['cmdBuffer']) > 4096) {
			self::send($sess, "500 Line too long");
			$sess['cmdBuffer'] = '';
		}
	}

	// -----------------------------------------------------------------
	// SMTP command state machine
	// -----------------------------------------------------------------

	/**
	 * Handle a single SMTP command line.
	 *
	 * @param array  &$sess   Session array (by reference)
	 * @param string $line    The command line (without trailing CRLF)
	 * @param array  $options Server options
	 * @return string|null Response line to send, or null if session was closed
	 */
	public static function handleCommand(&$sess, $line, $options)
	{
		// --- AUTH LOGIN continuation ---
		if ($sess['expectAuthUser']) {
			$sess['expectAuthUser'] = false;
			$sess['_authUserGiven'] = base64_decode($line);
			$sess['expectAuthPass'] = true;
			return "334 " . base64_encode("Password:");
		}
		if ($sess['expectAuthPass']) {
			$sess['expectAuthPass'] = false;
			$givenUser = isset($sess['_authUserGiven']) ? $sess['_authUserGiven'] : '';
			$givenPass = base64_decode($line);
			unset($sess['_authUserGiven']);

			$wantUser = isset($options['authUser']) ? $options['authUser'] : '';
			$wantPass = isset($options['authPass']) ? $options['authPass'] : '';

			if ($givenUser === $wantUser && $givenPass === $wantPass) {
				$sess['authed'] = true;
				return "235 Authentication successful";
			}
			return "535 Authentication failed";
		}

		$upper = strtoupper(trim($line));
		$verb = strtoupper(strtok($line, ' '));

		// --- EHLO / HELO ---
		if ($verb === 'EHLO' || $verb === 'HELO') {
			$domain = trim(substr($line, strlen($verb)));
			$capabilities = [
				"250-Hello $domain",
				"250-PIPELINING",
				"250-8BITMIME",
			];

			$maxSize = isset($options['maxSize'])
				? (int)$options['maxSize']
				: (int)self::cfg('maxSize', self::DEFAULT_MAX_SIZE);
			$capabilities[] = "250-SIZE $maxSize";

			if (!empty($options['tlsContext']) && !$sess['tlsUpgraded']) {
				$capabilities[] = "250-STARTTLS";
			}
			if (!empty($options['requireAuth']) && !$sess['authed']) {
				$capabilities[] = "250-AUTH LOGIN PLAIN";
			}
			// Last capability line uses "250 " (space, not dash)
			$last = array_pop($capabilities);
			$last = "250 " . substr($last, 4);
			$capabilities[] = $last;

			return implode("\r\n", $capabilities);
		}

		// --- STARTTLS ---
		if ($verb === 'STARTTLS') {
			if (empty($options['tlsContext'])) {
				return "502 STARTTLS not available";
			}
			if ($sess['tlsUpgraded']) {
				return "503 TLS already active";
			}
			// Send the go-ahead, then upgrade the socket
			self::send($sess, "220 Ready to start TLS");
			$cryptoMethod = STREAM_CRYPTO_METHOD_TLS_SERVER;
			if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_SERVER')) {
				$cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;
			}
			$result = @stream_socket_enable_crypto(
				$sess['sock'], true, $cryptoMethod
			);
			if ($result === true) {
				$sess['tlsUpgraded'] = true;
				$sess['cmdBuffer'] = '';
				return null; // response already sent
			}
			self::send($sess, "454 TLS negotiation failed");
			self::closeSession($sess);
			return null;
		}

		// --- AUTH ---
		if ($verb === 'AUTH') {
			$parts = preg_split('/\s+/', trim($line), 3);
			$mechanism = isset($parts[1]) ? strtoupper($parts[1]) : '';

			if ($sess['authed']) {
				return "503 Already authenticated";
			}

			if ($mechanism === 'LOGIN') {
				// AUTH LOGIN may carry the username as a third token
				if (isset($parts[2]) && $parts[2] !== '') {
					$sess['_authUserGiven'] = base64_decode($parts[2]);
					$sess['expectAuthPass'] = true;
					return "334 " . base64_encode("Password:");
				}
				$sess['expectAuthUser'] = true;
				return "334 " . base64_encode("Username:");
			}

			if ($mechanism === 'PLAIN') {
				// AUTH PLAIN may carry the credentials inline
				$encoded = isset($parts[2]) ? $parts[2] : '';
				if ($encoded === '') {
					// Client will send credentials on the next line
					$sess['expectAuthUser'] = false;
					$sess['expectAuthPass'] = false;
					// Use a special flag for PLAIN continuation
					$sess['_authPlainNext'] = true;
					return "334 ";
				}
				return self::_handleAuthPlain($sess, $encoded, $options);
			}

			return "504 Unsupported authentication mechanism";
		}

		// AUTH PLAIN continuation
		if (!empty($sess['_authPlainNext'])) {
			unset($sess['_authPlainNext']);
			return self::_handleAuthPlain($sess, $line, $options);
		}

		// --- MAIL FROM ---
		if (strncasecmp($upper, 'MAIL FROM:', 10) === 0) {
			if (!$sess['authed']) {
				return "530 Authentication required";
			}
			$from = trim(substr($line, 10));
			// Strip angle brackets and parameters (SIZE=..., etc.)
			if (preg_match('/<([^>]*)>/', $from, $m)) {
				$from = $m[1];
			} else {
				$from = strtok($from, ' ');
			}
			$sess['mailFrom'] = $from;
			return "250 OK";
		}

		// --- RCPT TO ---
		if (strncasecmp($upper, 'RCPT TO:', 8) === 0) {
			if ($sess['mailFrom'] === null) {
				return "503 Need MAIL FROM first";
			}
			$maxRecipients = isset($options['maxRecipients'])
				? (int)$options['maxRecipients']
				: (int)self::cfg('maxRecipients', self::DEFAULT_MAX_RECIPIENTS);

			if (count($sess['rcptTo']) >= $maxRecipients) {
				return "452 Too many recipients";
			}

			$to = trim(substr($line, 8));
			if (preg_match('/<([^>]*)>/', $to, $m)) {
				$to = $m[1];
			} else {
				$to = strtok($to, ' ');
			}
			$sess['rcptTo'][] = $to;
			return "250 OK";
		}

		// --- DATA ---
		if ($verb === 'DATA') {
			if ($sess['mailFrom'] === null) {
				return "503 Need MAIL FROM first";
			}
			if (empty($sess['rcptTo'])) {
				return "503 Need RCPT TO first";
			}
			$sess['dataMode'] = true;
			$sess['rawChunks'] = [];
			$sess['dataBytes'] = 0;
			$sess['dataLines'] = 0;
			$sess['scanner'] = new Q_Relay_Smtp_Scanner();
			$sess['lastFrom'] = $sess['mailFrom'];
			$sess['lastRcpt'] = $sess['rcptTo'];
			return "354 Start mail input; end with <CRLF>.<CRLF>";
		}

		// --- RSET ---
		if ($verb === 'RSET') {
			self::resetTransaction($sess);
			return "250 OK";
		}

		// --- NOOP ---
		if ($verb === 'NOOP') {
			return "250 OK";
		}

		// --- VRFY ---
		if ($verb === 'VRFY') {
			return "252 Cannot verify user, but will accept message";
		}

		// --- QUIT ---
		if ($verb === 'QUIT') {
			self::send($sess, "221 Bye");
			self::closeSession($sess);
			return null;
		}

		return "500 Unrecognised command";
	}

	/**
	 * Handle AUTH PLAIN credential payload.
	 *
	 * @param array  &$sess   Session array
	 * @param string $encoded Base64-encoded PLAIN credentials
	 * @param array  $options Server options
	 * @return string SMTP response
	 */
	private static function _handleAuthPlain(&$sess, $encoded, $options)
	{
		$decoded = base64_decode($encoded);
		// PLAIN format: \0authcid\0passwd  (authzid is ignored)
		$parts = explode("\0", $decoded);
		// Typically 3 parts: authzid, authcid, password
		$givenUser = isset($parts[1]) ? $parts[1] : '';
		$givenPass = isset($parts[2]) ? $parts[2] : '';

		$wantUser = isset($options['authUser']) ? $options['authUser'] : '';
		$wantPass = isset($options['authPass']) ? $options['authPass'] : '';

		if ($givenUser === $wantUser && $givenPass === $wantPass) {
			$sess['authed'] = true;
			return "235 Authentication successful";
		}
		return "535 Authentication failed";
	}

	// -----------------------------------------------------------------
	// DATA completion
	// -----------------------------------------------------------------

	/**
	 * Called when the dot-terminator is detected in DATA mode.
	 *
	 * Concatenates accumulated chunks, strips the terminator, performs
	 * dot-unstuffing, and invokes the $onMessage callback.
	 *
	 * @param array    &$sess     Session array (by reference)
	 * @param callable $onMessage Delivery callback
	 */
	public static function finishData(&$sess, $onMessage)
	{
		$raw = implode('', $sess['rawChunks']);

		// Strip the dot-terminator from the end.
		// It is either "\r\n.\r\n" (5 bytes) or, for an empty body, ".\r\n" (3 bytes).
		if (strlen($raw) >= 5 && substr($raw, -5) === "\r\n.\r\n") {
			$raw = substr($raw, 0, -5);
		} elseif ($raw === ".\r\n") {
			$raw = '';
		}

		$raw = self::inboundDotUnstuff($raw);

		$mailFrom = $sess['lastFrom'] !== null ? $sess['lastFrom'] : $sess['mailFrom'];
		$rcptTo = !empty($sess['lastRcpt']) ? $sess['lastRcpt'] : $sess['rcptTo'];

		self::resetTransaction($sess);

		// Deliver
		try {
			if (is_callable($onMessage)) {
				call_user_func($onMessage, $mailFrom, $rcptTo, $raw);
			}
			self::send($sess, "250 OK");
		} catch (\Exception $e) {
			self::send($sess, "451 Temporary failure: " . $e->getMessage());
		}
	}

	// -----------------------------------------------------------------
	// Transaction & session lifecycle
	// -----------------------------------------------------------------

	/**
	 * Clear per-transaction state, keeping the connection alive.
	 *
	 * @param array &$sess Session array (by reference)
	 */
	public static function resetTransaction(&$sess)
	{
		$sess['mailFrom']  = null;
		$sess['rcptTo']    = [];
		$sess['dataMode']  = false;
		$sess['rawChunks'] = [];
		$sess['dataBytes'] = 0;
		$sess['dataLines'] = 0;
		$sess['scanner']   = new Q_Relay_Smtp_Scanner();
	}

	/**
	 * Mark a session as closed and release its socket.
	 *
	 * @param array &$sess Session array (by reference)
	 */
	public static function closeSession(&$sess)
	{
		$sess['closed'] = true;
		if (is_resource($sess['sock'])) {
			@fclose($sess['sock']);
		}
		$sess['sock'] = null;
	}

	/**
	 * Write a line to a session's socket, appending CRLF.
	 *
	 * Silently returns if the socket is already closed or destroyed.
	 *
	 * @param array  $sess Session array
	 * @param string $line Response line (without trailing CRLF)
	 */
	public static function send($sess, $line)
	{
		if ($sess['closed'] || !is_resource($sess['sock'])) {
			return;
		}
		@fwrite($sess['sock'], $line . "\r\n");
	}
}
