<?php
/**
 * Q_Relay_SmtpClient
 *
 * Outbound SMTP client for the Qbix Server relay system.
 * PHP port of the outbound delivery logic from the Node.js smtp.js relay.
 *
 * Connects to upstream SMTP servers (e.g. Amazon SES) to deliver messages.
 * Handles rate limiting, retry with exponential backoff, header injection,
 * and audit logging via Q_Relay_Db.
 *
 * Config (Q.relay.smtp):
 *   host:           upstream SMTP host (required; falls back to Users.relay.smtp.host)
 *   port:           upstream port (default 587)
 *   user:           SMTP auth username (falls back to Users.relay.smtp.user)
 *   password:       SMTP auth password (falls back to Users.relay.smtp.password)
 *   secure:         implicit TLS for port 465 (default false)
 *   starttls:       STARTTLS upgrade for port 587 (default true)
 *   ignoreCert:     skip certificate verification (default false)
 *   timeout:        socket timeout in seconds (default 30)
 *   retries:        retry count (default 2)
 *   retryDelay:     base retry delay in seconds (default 5)
 *   defaultFrom:    fallback envelope sender
 *   sesConfigSet:   SES configuration set name
 *
 * All methods are static; no constructor needed.
 */
class Q_Relay_SmtpClient
{
	/**
	 * Read an SMTP config value with fallback to Users.relay.smtp.*.
	 *
	 * @param string $key The config key under Q.relay.smtp
	 * @param mixed $default Default if neither Q nor Users config has a value
	 * @return mixed
	 */
	private static function cfg($key, $default = null)
	{
		return Q_Config::get(
			'Q', 'relay', 'smtp', $key,
			Q_Config::get('Users', 'relay', 'smtp', $key, $default)
		);
	}

	/**
	 * Deliver a raw email message to the upstream SMTP server.
	 *
	 * Handles outbound header injection, rate limiting, retry with
	 * exponential backoff, and audit logging. Throws on final failure.
	 *
	 * @param string $rawMessage The raw RFC 5322 message
	 * @param string[] $recipients Envelope RCPT TO addresses
	 * @param string $mailFrom Envelope MAIL FROM address
	 * @param array $options {
	 *   @type bool $bytePreserved Skip line-ending normalization if true
	 *   @type array $meta Audit metadata with keys: mode, count
	 * }
	 * @throws \Exception On permanent failure or exhausted retries
	 */
	static function deliver($rawMessage, $recipients, $mailFrom, $options = array())
	{
		$bytePreserved = !empty($options['bytePreserved']);
		$meta = isset($options['meta']) ? $options['meta'] : array();

		// Inject missing outbound headers
		$rawMessage = self::ensureOutboundHeaders($rawMessage, $mailFrom);

		// Instrument email with tracking pixel and link wrapping
		if (class_exists('Q_Relay_EmailTracker', false)
			|| is_file(__DIR__ . '/EmailTracker.php')
		) {
			if (!class_exists('Q_Relay_EmailTracker', false)) {
				require_once __DIR__ . '/EmailTracker.php';
			}
			$template = isset($meta['template']) ? $meta['template'] : null;
			$appHost = isset($meta['appHost']) ? $meta['appHost'] : null;
			$toAddr = is_array($recipients) ? $recipients[0] : $recipients;
			$rawMessage = Q_Relay_EmailTracker::instrument(
				$rawMessage, $mailFrom, $toAddr, $template, $appHost
			);
		}

		// Parse headers for audit logging
		$split = Q_Relay_Mime::splitHeaderBody($rawMessage);
		$headers = Q_Relay_Mime::parseHeaders($split['headers']);
		$auditFrom = Q_Relay_Mime::headerValue($headers, 'from');
		$auditTo = Q_Relay_Mime::headerValue($headers, 'to');
		$auditSubject = Q_Relay_Mime::headerValue($headers, 'subject');
		$auditMessageId = Q_Relay_Mime::headerValue($headers, 'message-id');
		$auditBytes = strlen($rawMessage);

		$retries = (int) self::cfg('retries', 2);
		$retryDelay = (int) self::cfg('retryDelay', 5);
		$lastError = null;

		for ($attempt = 0; $attempt <= $retries; $attempt++) {
			try {
				// Rate limiting
				if (class_exists('Q_Relay_RateLimiter')) {
					Q_Relay_RateLimiter::acquire();
				}

				self::smtpDeliver($rawMessage, $recipients, $mailFrom, $bytePreserved);

				// Log success
				$toStr = implode(', ', $recipients);
				Q_Relay_Db::logDelivery(
					'smtp', 'outbound',
					$auditFrom ?: $mailFrom,
					$toStr,
					$auditSubject,
					$auditMessageId,
					$auditBytes,
					'sent',
					null,
					self::cfg('host'),
					null
				);

				return;
			} catch (\Exception $e) {
				$lastError = $e;
				$code = 0;

				// Try to extract SMTP status code from the error message
				if (preg_match('/^(\d{3})\s/', $e->getMessage(), $m)) {
					$code = (int) $m[1];
				}

				// Permanent failure (5xx) or auth failure: don't retry
				if ($code >= 500 || $code === 535) {
					break;
				}

				// Exponential backoff before next attempt
				if ($attempt < $retries) {
					$delay = $retryDelay * pow(2, $attempt);
					sleep($delay);
				}
			}
		}

		// Final failure: log and throw
		$toStr = implode(', ', $recipients);
		Q_Relay_Db::logDelivery(
			'smtp', 'outbound',
			$auditFrom ?: $mailFrom,
			$toStr,
			$auditSubject,
			$auditMessageId,
			$auditBytes,
			'failed',
			$lastError ? $lastError->getMessage() : 'unknown error',
			self::cfg('host'),
			null
		);

		throw new \Exception(
			'SMTP delivery failed after ' . ($retries + 1)
			. ' attempt(s): ' . ($lastError ? $lastError->getMessage() : 'unknown error')
		);
	}

	/**
	 * Low-level SMTP transaction.
	 *
	 * Opens a socket, performs the SMTP handshake (EHLO/HELO, optional
	 * STARTTLS, optional AUTH LOGIN), sends the envelope and message data,
	 * and issues QUIT.
	 *
	 * @param string $rawMessage The raw RFC 5322 message
	 * @param string[] $recipients Envelope RCPT TO addresses
	 * @param string $mailFrom Envelope MAIL FROM address
	 * @param bool $bytePreserved Skip line-ending normalization if true
	 * @throws \Exception On SMTP protocol errors or connection failure
	 */
	static function smtpDeliver($rawMessage, $recipients, $mailFrom, $bytePreserved = false)
	{
		$host = self::cfg('host');
		if (!$host) {
			throw new \Exception('SMTP host not configured (Q.relay.smtp.host)');
		}

		$port = (int) self::cfg('port', 587);
		$secure = (bool) self::cfg('secure', false);
		$starttls = (bool) self::cfg('starttls', true);
		$ignoreCert = (bool) self::cfg('ignoreCert', false);
		$timeout = (int) self::cfg('timeout', 30);
		$user = self::cfg('user');
		$pass = self::cfg('password');

		$sock = self::connectSocket($host, $port, $secure, $timeout, $ignoreCert);

		try {
			// Read 220 greeting
			$greeting = self::smtpRead($sock, $timeout);
			self::smtpExpect($greeting, 220);

			// EHLO, fall back to HELO
			$ehloHost = gethostname() ?: 'localhost';
			$ehloReply = self::smtpCmd($sock, 'EHLO ' . $ehloHost, $timeout);
			if (self::smtpCode($ehloReply) !== 250) {
				$heloReply = self::smtpCmd($sock, 'HELO ' . $ehloHost, $timeout);
				self::smtpExpect($heloReply, 250);
			}

			// STARTTLS upgrade
			if ($starttls && !$secure) {
				$starttlsReply = self::smtpCmd($sock, 'STARTTLS', $timeout);
				self::smtpExpect($starttlsReply, 220);

				$cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
				if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
					$cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
				}

				if ($ignoreCert) {
					stream_context_set_option(
						stream_context_get_default(),
						'ssl', 'verify_peer', false
					);
					stream_context_set_option(
						stream_context_get_default(),
						'ssl', 'verify_peer_name', false
					);
				}

				$upgraded = stream_socket_enable_crypto($sock, true, $cryptoMethod);
				if (!$upgraded) {
					throw new \Exception('STARTTLS crypto negotiation failed');
				}

				// Re-EHLO after TLS upgrade
				$ehloReply = self::smtpCmd($sock, 'EHLO ' . $ehloHost, $timeout);
				self::smtpExpect($ehloReply, 250);
			}

			// AUTH LOGIN
			if ($user && $pass) {
				$authReply = self::smtpCmd($sock, 'AUTH LOGIN', $timeout);
				self::smtpExpect($authReply, 334);

				$userReply = self::smtpCmd($sock, base64_encode($user), $timeout);
				self::smtpExpect($userReply, 334);

				$passReply = self::smtpCmd($sock, base64_encode($pass), $timeout);
				self::smtpExpect($passReply, 235);
			}

			// MAIL FROM
			$mailFromReply = self::smtpCmd(
				$sock,
				'MAIL FROM:<' . $mailFrom . '>',
				$timeout
			);
			self::smtpExpect($mailFromReply, 250);

			// RCPT TO for each recipient
			$accepted = 0;
			foreach ($recipients as $rcpt) {
				$rcptReply = self::smtpCmd(
					$sock,
					'RCPT TO:<' . $rcpt . '>',
					$timeout
				);
				if (self::smtpCode($rcptReply) === 250 || self::smtpCode($rcptReply) === 251) {
					$accepted++;
				}
			}
			if ($accepted === 0) {
				throw new \Exception(
					'No recipients accepted by server'
				);
			}

			// DATA
			$dataReply = self::smtpCmd($sock, 'DATA', $timeout);
			self::smtpExpect($dataReply, 354);

			// Normalize line endings unless byte-preserved
			if (!$bytePreserved) {
				$rawMessage = preg_replace('/\r?\n/', "\r\n", $rawMessage);
			}

			// Dot-stuff and send
			$stuffed = self::outboundDotStuff($rawMessage);
			fwrite($sock, $stuffed);

			// End with <CRLF>.<CRLF>
			if (substr($stuffed, -2) !== "\r\n") {
				fwrite($sock, "\r\n");
			}
			fwrite($sock, ".\r\n");

			$dataEndReply = self::smtpRead($sock, $timeout);
			self::smtpExpect($dataEndReply, 250);

			// QUIT (advisory, don't fail on timeout)
			try {
				self::smtpCmd($sock, 'QUIT', 5);
			} catch (\Exception $e) {
				// Ignore QUIT failures
			}
		} finally {
			if (is_resource($sock)) {
				@fclose($sock);
			}
		}
	}

	/**
	 * Create a stream socket connection to the SMTP server.
	 *
	 * Uses ssl:// for implicit TLS ($secure = true) and tcp:// for plain.
	 *
	 * @param string $host SMTP server hostname
	 * @param int $port SMTP server port
	 * @param bool $secure Use implicit TLS (ssl://) if true
	 * @param int $timeout Connection timeout in seconds
	 * @param bool $ignoreCert Skip certificate verification if true
	 * @return resource The connected stream socket
	 * @throws \Exception On connection failure
	 */
	static function connectSocket($host, $port, $secure = false, $timeout = 30, $ignoreCert = false)
	{
		$scheme = $secure ? 'ssl' : 'tcp';
		$address = $scheme . '://' . $host . ':' . $port;

		$contextOpts = array();
		if ($secure || $ignoreCert) {
			$sslOpts = array();
			if ($ignoreCert) {
				$sslOpts['verify_peer'] = false;
				$sslOpts['verify_peer_name'] = false;
				$sslOpts['allow_self_signed'] = true;
			}
			$contextOpts['ssl'] = $sslOpts;
		}
		$context = stream_context_create($contextOpts);

		$errno = 0;
		$errstr = '';
		$sock = @stream_socket_client(
			$address, $errno, $errstr, $timeout,
			STREAM_CLIENT_CONNECT, $context
		);

		if (!$sock) {
			throw new \Exception(
				"SMTP connection to $address failed: [$errno] $errstr"
			);
		}

		stream_set_timeout($sock, $timeout);

		return $sock;
	}

	/**
	 * Read a complete SMTP reply from the socket.
	 *
	 * Handles multi-line replies where continuation lines use the
	 * "250-" prefix and the final line uses "250 " (with a space).
	 *
	 * @param resource $sock The stream socket
	 * @param int $timeout Read timeout in seconds
	 * @return string The full reply text
	 * @throws \Exception On read failure or timeout
	 */
	static function smtpRead($sock, $timeout = 30)
	{
		stream_set_timeout($sock, $timeout);
		$reply = '';

		while (true) {
			$line = fgets($sock, 8192);
			if ($line === false) {
				$info = stream_get_meta_data($sock);
				if (!empty($info['timed_out'])) {
					throw new \Exception(
						'SMTP read timed out after ' . $timeout . 's'
					);
				}
				throw new \Exception(
					'SMTP connection closed unexpectedly'
				);
			}
			$reply .= $line;

			// A final line has a space after the 3-digit code (or is shorter than 4 chars)
			if (strlen($line) < 4 || $line[3] === ' ' || $line[3] === "\r" || $line[3] === "\n") {
				break;
			}
			// Continuation lines have '-' at position 3; keep reading
		}

		return $reply;
	}

	/**
	 * Send an SMTP command and read the reply.
	 *
	 * Writes the command followed by CRLF, then reads the response.
	 *
	 * @param resource $sock The stream socket
	 * @param string $command The SMTP command (without trailing CRLF)
	 * @param int $timeout Read timeout in seconds
	 * @return string The reply text
	 * @throws \Exception On write or read failure
	 */
	static function smtpCmd($sock, $command, $timeout = 30)
	{
		$written = fwrite($sock, $command . "\r\n");
		if ($written === false) {
			throw new \Exception(
				'SMTP write failed for command: '
				. substr($command, 0, 50)
			);
		}
		return self::smtpRead($sock, $timeout);
	}

	/**
	 * Extract the 3-digit status code from the last line of an SMTP reply.
	 *
	 * @param string $reply The full SMTP reply
	 * @return int The numeric status code, or 0 if unparseable
	 */
	static function smtpCode($reply)
	{
		// Get the last non-empty line
		$lines = preg_split('/\r?\n/', rtrim($reply));
		$lastLine = end($lines);
		if ($lastLine && preg_match('/^(\d{3})/', $lastLine, $m)) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * Assert that an SMTP reply's status code matches the expected code.
	 *
	 * @param string $reply The full SMTP reply text
	 * @param int $wantCode The expected 3-digit status code
	 * @throws \Exception If the actual code doesn't match
	 */
	static function smtpExpect($reply, $wantCode)
	{
		$got = self::smtpCode($reply);
		if ($got !== $wantCode) {
			throw new \Exception(
				$got . ' ' . trim($reply)
				. " (expected $wantCode)"
			);
		}
	}

	/**
	 * Inject missing outbound headers into a raw message.
	 *
	 * Adds headers only when they are not already present:
	 *   - Date: RFC 2822 formatted
	 *   - Message-ID: UUID@domain
	 *   - From: envelope sender
	 *   - X-SES-CONFIGURATION-SET: if configured
	 *
	 * @param string $raw The raw RFC 5322 message
	 * @param string $envFrom The envelope sender address
	 * @return string The message with any missing headers prepended
	 */
	static function ensureOutboundHeaders($raw, $envFrom)
	{
		$split = Q_Relay_Mime::splitHeaderBody($raw);
		$headers = Q_Relay_Mime::parseHeaders($split['headers']);
		$inject = '';

		if (!isset($headers['date'])) {
			$inject .= 'Date: ' . gmdate('D, d M Y H:i:s O') . "\r\n";
		}

		if (!isset($headers['message-id'])) {
			$domain = 'localhost';
			if ($envFrom && strpos($envFrom, '@') !== false) {
				$domain = substr($envFrom, strpos($envFrom, '@') + 1);
			}
			$uuid = sprintf(
				'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
				mt_rand(0, 0xffff), mt_rand(0, 0xffff),
				mt_rand(0, 0xffff),
				mt_rand(0, 0x0fff) | 0x4000,
				mt_rand(0, 0x3fff) | 0x8000,
				mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
			);
			$inject .= 'Message-ID: <' . $uuid . '@' . $domain . '>' . "\r\n";
		}

		if (!isset($headers['from'])) {
			$inject .= 'From: ' . $envFrom . "\r\n";
		}

		$sesConfigSet = self::cfg('sesConfigSet');
		if ($sesConfigSet && !isset($headers['x-ses-configuration-set'])) {
			$inject .= 'X-SES-CONFIGURATION-SET: ' . $sesConfigSet . "\r\n";
		}

		if ($inject === '') {
			return $raw;
		}

		return $inject . $raw;
	}

	/**
	 * Dot-stuff a message body for SMTP DATA transmission.
	 *
	 * Lines beginning with a dot get an extra dot prepended,
	 * per RFC 5321 section 4.5.2.
	 *
	 * @param string $s The message content
	 * @return string The dot-stuffed content
	 */
	static function outboundDotStuff($s)
	{
		// Dot-stuff: any line starting with "." gets an extra "." prepended
		return preg_replace('/^\./m', '..', $s);
	}
}
