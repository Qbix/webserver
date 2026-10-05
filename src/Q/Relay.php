<?php

/**
 * Q_Relay — Central coordinator for the Qbix Server relay system.
 *
 * Runs as a long-lived process (spawned by qbixserver.php or standalone via
 * bin/qbixrelay.php) and manages the SMTP inbound server, digest flushing,
 * rate limiting, and mobile (SMS) handling.
 *
 * Process tree: qbixserver.php → qbixrelay.php → relay workers (COW fork per message)
 *
 * @package Q
 */
class Q_Relay
{
	/**
	 * @var bool Whether a graceful shutdown has been requested.
	 */
	private static $shutdown = false;

	/**
	 * @var array Tracked child worker processes. pid => ['started' => int, 'type' => string]
	 */
	private static $children = [];

	/**
	 * @var int Unix timestamp when the relay process started.
	 */
	private static $startTime = 0;

	/**
	 * @var array Counters for status reporting.
	 */
	private static $counters = [
		'msg_in' => 0,
		'msg_out' => 0,
		'msg_failed' => 0,
		'msg_digested' => 0,
		'workers_forked' => 0,
		'workers_failed' => 0
	];

	/**
	 * @var array Active SMTP sessions.
	 */
	private static $sessions = [];

	/**
	 * @var float Last time the status file was written.
	 */
	private static $lastStatusWrite = 0;

	/**
	 * @var resource|null File handle for the log file.
	 */
	private static $logHandle = null;

	/**
	 * Main entry point for the relay process. Called from bin/qbixrelay.php.
	 *
	 * Initializes configuration, database, rate limiter, and SMTP server,
	 * then enters the main event loop. Handles SIGTERM/SIGINT for graceful
	 * shutdown and SIGHUP for config reload / circuit breaker reset.
	 *
	 * @param array $options Optional overrides:
	 *   - "selectTimeout" => float  stream_select timeout in seconds (default 0.2)
	 * @return void
	 */
	static function run($options = [])
	{
		if (!extension_loaded('pcntl')) {
			$msg = "Q_Relay requires the pcntl extension.\n"
				. "Install it with: apt-get install php-pcntl (Debian/Ubuntu)\n"
				. "or: yum install php-pcntl (CentOS/RHEL)\n"
				. "then restart PHP.\n";
			if (class_exists('Q')) {
				throw new Q_Exception($msg);
			}
			fwrite(STDERR, $msg);
			exit(1);
		}

		self::$startTime = time();
		self::$shutdown = false;
		self::$children = [];
		self::$lastStatusWrite = 0;

		// Load config
		self::log('info', ['msg' => 'relay_starting', 'pid' => getmypid()]);

		// Initialize database if Q is available
		if (class_exists('Q') && class_exists('Q_Db')) {
			// DB connection is lazy; nothing to do here
		}

		// Initialize rate limiter
		if (class_exists('Q_Relay_RateLimiter')) {
			Q_Relay_RateLimiter::init();
		}

		// Install signal handlers
		pcntl_signal(SIGTERM, [self::class, 'shutdown']);
		pcntl_signal(SIGINT, [self::class, 'shutdown']);
		pcntl_signal(SIGHUP, function () {
			if (class_exists('Q_Relay_RateLimiter')) {
				Q_Relay_RateLimiter::reset();
			}
			if (class_exists('Q_Config')) {
				// Reload config files
				// Q_Config is typically static; a full reload depends
				// on the application bootstrap, but we signal intent.
			}
			self::log('info', ['msg' => 'config_reloaded', 'pid' => getmypid()]);
		});
		pcntl_signal(SIGCHLD, SIG_DFL); // let reapChildren handle

		// Create SMTP inbound server
		$server = null;
		if (class_exists('Q_Relay_Smtp')) {
			$server = Q_Relay_Smtp::createServer(
				self::resolveConfig(['smtp', 'listenHost'], '127.0.0.1'),
				self::resolveConfig(['smtp', 'listenPort'], 2525)
			);
		}

		self::log('info', [
			'msg' => 'relay_started',
			'pid' => getmypid(),
			'listen' => self::resolveConfig(['smtp', 'listenHost'], '127.0.0.1')
				. ':' . self::resolveConfig(['smtp', 'listenPort'], 2525)
		]);

		// Enter main event loop
		self::loop($server, $options);

		// After loop exits (shutdown requested)
		self::log('info', ['msg' => 'relay_stopped', 'pid' => getmypid()]);

		if (self::$logHandle) {
			fclose(self::$logHandle);
			self::$logHandle = null;
		}
	}

	/**
	 * Main event loop. Runs until shutdown is requested.
	 *
	 * Each iteration:
	 * 1. Ticks the SMTP server (accept connections, read data, process messages)
	 * 2. Checks for pending digest flushes and forks workers to deliver them
	 * 3. Persists rate limiter state periodically
	 * 4. Reaps finished child workers (non-blocking)
	 * 5. Writes status file periodically
	 *
	 * @param resource|null $server The SMTP server socket, or null if SMTP is unavailable.
	 * @param array $options Optional overrides:
	 *   - "selectTimeout" => float  stream_select timeout in seconds (default 0.2)
	 * @return void
	 */
	static function loop($server, $options = [])
	{
		$selectTimeout = isset($options['selectTimeout'])
			? (float)$options['selectTimeout']
			: 0.2;

		$onMessage = function ($mailFrom, $rcptTo, $rawMessage) {
			self::onMessage($mailFrom, $rcptTo, $rawMessage);
		};

		$statusInterval = self::resolveConfig(['status', 'interval'], 30);

		while (!self::$shutdown) {
			pcntl_signal_dispatch();

			// 1. SMTP: accept connections, read data, process messages
			if ($server && class_exists('Q_Relay_Smtp')) {
				Q_Relay_Smtp::tick(
					$server,
					self::$sessions,
					$onMessage,
					$options,
					$selectTimeout
				);
			} else {
				// No SMTP server; sleep briefly to avoid busy-wait
				usleep((int)($selectTimeout * 1000000));
			}

			// 2. Digest: check for pending flushes
			if (class_exists('Q_Relay_Digest')) {
				$flushes = Q_Relay_Digest::getPendingFlushes();
				foreach ($flushes as $flush) {
					$maxWorkers = self::resolveConfig(['maxWorkers'], 50);
					if (count(self::$children) >= $maxWorkers) {
						self::log('warn', [
							'msg' => 'max_workers_reached',
							'max' => $maxWorkers,
							'active' => count(self::$children)
						]);
						break;
					}
					self::forkWorker(function () use ($flush) {
						Q_Relay_Digest::flush($flush);
					}, 'digest');
				}
			}

			// 3. Rate limiter: persist state periodically
			if (class_exists('Q_Relay_RateLimiter')) {
				Q_Relay_RateLimiter::maybePersist();
			}

			// 4. Reap child workers (non-blocking waitpid)
			self::reapChildren();

			// 5. Write status file periodically
			$now = microtime(true);
			if ($now - self::$lastStatusWrite >= $statusInterval) {
				self::writeStatusFile();
				self::$lastStatusWrite = $now;
			}
		}
	}

	/**
	 * Callback invoked when a complete SMTP message is received.
	 *
	 * Fires before/after Q::event hooks, checks digest eligibility,
	 * and either queues the message for digest delivery or forks a
	 * worker for immediate delivery.
	 *
	 * @param string $mailFrom The envelope sender (MAIL FROM).
	 * @param array $rcptTo Array of envelope recipients (RCPT TO).
	 * @param string $rawMessage The raw RFC 2822 message data.
	 * @return void
	 */
	static function onMessage($mailFrom, $rcptTo, $rawMessage)
	{
		self::$counters['msg_in']++;

		$params = [
			'mailFrom' => $mailFrom,
			'rcptTo' => $rcptTo,
			'rawMessage' => $rawMessage,
			'reject' => false,
			'skipDigest' => false
		];

		// Before hook: plugins can modify or reject
		if (class_exists('Q') && method_exists('Q', 'event')) {
			Q::event('Q/relay/message/received', $params, 'before');
		}

		if (!empty($params['reject'])) {
			self::log('info', [
				'msg' => 'message_rejected',
				'from' => $mailFrom,
				'reason' => is_string($params['reject']) ? $params['reject'] : 'hook'
			]);
			return;
		}

		// Check digest eligibility
		$digested = false;
		if (!$params['skipDigest'] && class_exists('Q_Relay_Digest')) {
			$digested = Q_Relay_Digest::processMessage(
				$params['mailFrom'],
				$params['rcptTo'],
				$params['rawMessage']
			);
		}

		if ($digested) {
			// Message was queued in the DB for digest delivery;
			// it will be flushed by the main loop.
			self::$counters['msg_digested']++;
			self::log('debug', [
				'msg' => 'message_digested',
				'from' => $mailFrom
			]);
		} else {
			// Immediate delivery: fork a worker
			$maxWorkers = self::resolveConfig(['maxWorkers'], 50);
			if (count(self::$children) >= $maxWorkers) {
				self::log('warn', [
					'msg' => 'max_workers_reached_delivery',
					'max' => $maxWorkers
				]);
				// Still attempt delivery in-process as a fallback
				self::deliverMessage(
					$params['mailFrom'],
					$params['rcptTo'],
					$params['rawMessage']
				);
			} else {
				$from = $params['mailFrom'];
				$to = $params['rcptTo'];
				$raw = $params['rawMessage'];
				self::forkWorker(function () use ($from, $to, $raw) {
					self::deliverMessage($from, $to, $raw);
				}, 'deliver');
			}
		}

		// After hook: logging, notifications, etc.
		if (class_exists('Q') && method_exists('Q', 'event')) {
			Q::event('Q/relay/message/processed', $params, 'after');
		}
	}

	/**
	 * Deliver a message via Q_Relay_SmtpClient.
	 *
	 * Separated from onMessage so it can run inside a forked worker.
	 *
	 * @param string $mailFrom Envelope sender.
	 * @param array $rcptTo Envelope recipients.
	 * @param string $rawMessage Raw RFC 2822 message.
	 * @return void
	 */
	private static function deliverMessage($mailFrom, $rcptTo, $rawMessage)
	{
		try {
			if (class_exists('Q_Relay_SmtpClient')) {
				Q_Relay_SmtpClient::deliver($mailFrom, $rcptTo, $rawMessage);
			}
			self::$counters['msg_out']++;
		} catch (Exception $e) {
			self::$counters['msg_failed']++;
			self::log('error', [
				'msg' => 'delivery_failed',
				'from' => $mailFrom,
				'error' => $e->getMessage()
			]);
		}
	}

	/**
	 * Fork a child process to run a callback.
	 *
	 * COW-safe: the child inherits the parent's memory but writes are
	 * copy-on-write (~120KB overhead per fork). The parent tracks the
	 * child PID for reaping.
	 *
	 * @param callable $callback The work to perform in the child process.
	 * @param string $type Label for the worker type ('deliver' or 'digest').
	 * @return int|false The child PID in the parent, or false on fork failure.
	 */
	static function forkWorker($callback, $type = 'deliver')
	{
		$pid = pcntl_fork();

		if ($pid === -1) {
			self::log('error', ['msg' => 'fork_failed', 'type' => $type]);
			return false;
		}

		if ($pid === 0) {
			// Child process
			// Reset signal handlers to defaults in child
			pcntl_signal(SIGTERM, SIG_DFL);
			pcntl_signal(SIGINT, SIG_DFL);
			pcntl_signal(SIGHUP, SIG_DFL);

			try {
				call_user_func($callback);
			} catch (Exception $e) {
				self::log('error', [
					'msg' => 'worker_exception',
					'type' => $type,
					'error' => $e->getMessage()
				]);
				exit(1);
			}
			exit(0);
		}

		// Parent process
		self::$children[$pid] = [
			'started' => time(),
			'type' => $type
		];
		self::$counters['workers_forked']++;

		return $pid;
	}

	/**
	 * Reap finished child worker processes (non-blocking).
	 *
	 * Uses waitpid(-1, WNOHANG) to collect any children that have exited.
	 * Logs failures (non-zero exit status).
	 *
	 * @return void
	 */
	static function reapChildren()
	{
		if (empty(self::$children)) {
			return;
		}

		while (true) {
			$status = 0;
			$pid = pcntl_waitpid(-1, $status, WNOHANG);

			if ($pid <= 0) {
				break;
			}

			$info = isset(self::$children[$pid]) ? self::$children[$pid] : null;
			unset(self::$children[$pid]);

			if (pcntl_wifexited($status)) {
				$exitCode = pcntl_wexitstatus($status);
				if ($exitCode !== 0) {
					self::$counters['workers_failed']++;
					self::log('warn', [
						'msg' => 'worker_exited_nonzero',
						'pid' => $pid,
						'exit' => $exitCode,
						'type' => $info ? $info['type'] : 'unknown'
					]);
				}
			} elseif (pcntl_wifsignaled($status)) {
				$sig = pcntl_wtermsig($status);
				self::$counters['workers_failed']++;
				self::log('warn', [
					'msg' => 'worker_killed',
					'pid' => $pid,
					'signal' => $sig,
					'type' => $info ? $info['type'] : 'unknown'
				]);
			}
		}
	}

	/**
	 * Resolve a config key with Platform (Users) fallback.
	 *
	 * Looks up Q.relay.<key> first, then falls back to Users.relay.<key>,
	 * then to the provided default. This allows platform-level defaults
	 * (e.g. SMTP credentials in Users config) to be overridden per-app.
	 *
	 * The eight keys with fallbacks:
	 * - smtp.host, smtp.port, smtp.user, smtp.password
	 * - smtp.secure, smtp.starttls
	 * - twilio.accountSid, twilio.authToken
	 *
	 * @param array $key Config key segments, e.g. ['smtp', 'host'].
	 * @param mixed $default Default value if neither config path exists.
	 * @return mixed The resolved config value.
	 */
	static function resolveConfig($key, $default = null)
	{
		if (!class_exists('Q_Config')) {
			return $default;
		}

		// Build argument list: ('Q', 'relay', ...key segments)
		$qArgs = array_merge(['Q', 'relay'], $key);
		$usersArgs = array_merge(['Users', 'relay'], $key);

		// Try Users fallback first to use as default for Q config
		$usersFallback = call_user_func_array(
			['Q_Config', 'get'],
			array_merge($usersArgs, [$default])
		);

		return call_user_func_array(
			['Q_Config', 'get'],
			array_merge($qArgs, [$usersFallback])
		);
	}

	/**
	 * Initiate graceful shutdown.
	 *
	 * Sets the shutdown flag, waits for active workers to finish (with a
	 * timeout), closes the SMTP server, persists rate limiter state, and exits.
	 *
	 * Can be called as a signal handler (receives $signo) or directly.
	 *
	 * @param int|null $signo Signal number if called as a signal handler.
	 * @return void
	 */
	static function shutdown($signo = null)
	{
		if (self::$shutdown) {
			return; // Already shutting down
		}

		self::$shutdown = true;
		self::log('info', [
			'msg' => 'shutdown_requested',
			'signal' => $signo,
			'active_workers' => count(self::$children)
		]);

		// Wait for active workers with timeout
		$deadline = time() + 30; // 30 second grace period
		while (!empty(self::$children) && time() < $deadline) {
			self::reapChildren();
			if (!empty(self::$children)) {
				usleep(100000); // 100ms
				pcntl_signal_dispatch();
			}
		}

		// Kill remaining workers
		foreach (self::$children as $pid => $info) {
			self::log('warn', [
				'msg' => 'killing_worker',
				'pid' => $pid,
				'type' => $info['type']
			]);
			posix_kill($pid, SIGTERM);
		}

		// Brief pause then force-kill
		if (!empty(self::$children)) {
			usleep(500000); // 500ms
			foreach (self::$children as $pid => $info) {
				posix_kill($pid, SIGKILL);
			}
			self::reapChildren();
		}

		// Close SMTP server
		if (class_exists('Q_Relay_Smtp')) {
			Q_Relay_Smtp::closeServer();
		}

		// Persist rate limiter state
		if (class_exists('Q_Relay_RateLimiter')) {
			Q_Relay_RateLimiter::persist();
		}

		// Write final status
		self::writeStatusFile();
	}

	/**
	 * Return current relay status as an associative array.
	 *
	 * Used by writeStatusFile() and available for dashboard reporting.
	 *
	 * @return array Status data including uptime, pid, active workers,
	 *               message counters, rate limiter state, digest queue
	 *               depth, and SMTP session count.
	 */
	static function getStatus()
	{
		$status = [
			'pid' => getmypid(),
			'uptime' => self::$startTime ? time() - self::$startTime : 0,
			'started' => self::$startTime,
			'shutdown' => self::$shutdown,
			'active_workers' => count(self::$children),
			'counters' => self::$counters,
			'smtp_sessions' => count(self::$sessions)
		];

		if (class_exists('Q_Relay_RateLimiter')
			&& method_exists('Q_Relay_RateLimiter', 'getState')
		) {
			$status['rate_limiter'] = Q_Relay_RateLimiter::getState();
		}

		if (class_exists('Q_Relay_Digest')
			&& method_exists('Q_Relay_Digest', 'getQueueDepth')
		) {
			$status['digest_queue_depth'] = Q_Relay_Digest::getQueueDepth();
		}

		return $status;
	}

	/**
	 * Write status to a JSON file for dashboard consumption.
	 *
	 * Writes to the path configured in Q.relay.status.file, defaulting
	 * to local/relay.status.json. Uses atomic write (temp file + rename)
	 * so readers never see a partial file.
	 *
	 * @return void
	 */
	static function writeStatusFile()
	{
		$file = self::resolveConfig(['status', 'file'], 'local/relay.status.json');

		$status = self::getStatus();
		$status['updated'] = time();

		$json = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			return;
		}

		$dir = dirname($file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}

		// Atomic write: write to temp, then rename
		$tmp = $file . '.tmp.' . getmypid();
		if (file_put_contents($tmp, $json) !== false) {
			rename($tmp, $file);
		}
	}

	/**
	 * Write a structured JSON log entry.
	 *
	 * Each log line is a single JSON object with at minimum "ts", "level",
	 * and "pid" fields, plus whatever is in $data. Writes to the path
	 * configured in Q.relay.log.file (default: local/relay.log).
	 *
	 * Messages below the configured log level (Q.relay.log.level, default
	 * "info") are discarded.
	 *
	 * @param string $level One of: debug, info, warn, error.
	 * @param array $data Structured data to include in the log entry.
	 * @return void
	 */
	static function log($level, $data)
	{
		static $levels = [
			'debug' => 0,
			'info' => 1,
			'warn' => 2,
			'error' => 3
		];

		$configLevel = self::resolveConfig(['log', 'level'], 'info');
		$minLevel = isset($levels[$configLevel]) ? $levels[$configLevel] : 1;
		$thisLevel = isset($levels[$level]) ? $levels[$level] : 1;

		if ($thisLevel < $minLevel) {
			return;
		}

		$entry = array_merge(
			[
				'ts' => gmdate('Y-m-d\TH:i:s\Z'),
				'level' => $level,
				'pid' => getmypid()
			],
			$data
		);

		$line = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";

		// Try to write to configured log file
		$logFile = self::resolveConfig(['log', 'file'], 'local/relay.log');

		if (self::$logHandle === null) {
			$dir = dirname($logFile);
			if (!is_dir($dir)) {
				@mkdir($dir, 0755, true);
			}
			self::$logHandle = @fopen($logFile, 'a');
		}

		if (self::$logHandle) {
			fwrite(self::$logHandle, $line);
		} else {
			// Fall back to stderr
			fwrite(STDERR, $line);
		}
	}
}
