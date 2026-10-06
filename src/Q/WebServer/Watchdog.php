<?php
/**
 * Watchdog — independent process that monitors the server,
 * logs crashes, and restarts automatically.
 *
 * The server forks a watchdog at startup. The watchdog:
 *   1. Detaches into its own session (setsid)
 *   2. Monitors the server process via waitpid/kill(0)
 *   3. On crash: logs the exit code, waits (with backoff), restarts
 *   4. On clean exit (SIGTERM, --stop): exits too
 *   5. Writes PID and crash log to local/watchdog.pid, local/watchdog.log
 *
 * Config:
 *   Q.webserver.watchdog: true        (enable)
 *   Q.webserver.watchdog.maxRestarts: 10  (give up after N crashes in 1 hour)
 *   Q.webserver.watchdog.backoff: 2       (initial backoff seconds, doubles each crash)
 *   Q.webserver.watchdog.maxBackoff: 60   (cap backoff at this)
 */
class Q_WebServer_Watchdog
{
	/**
	 * Fork a watchdog process. Called from the server's main startup.
	 * Returns the watchdog PID in the server process, or 0 if disabled/unavailable.
	 */
	static function start($serverPid, $argv)
	{
		if (!class_exists('Q_WebServer_Fork')) {
			$forkFile = dirname(__DIR__) . '/WebServer/Fork.php';
			if (is_file($forkFile)) require_once $forkFile;
		}
		if (!class_exists('Q_WebServer_Fork') || !Q_WebServer_Fork::available()) {
			return 0;
		}

		$pid = Q_WebServer_Fork::fork();
		if ($pid === -1 || $pid === false) return 0;

		if ($pid > 0) {
			// Parent (server) — return watchdog PID
			return $pid;
		}

		// ── Child (watchdog) ──
		// Detach from terminal
		if (function_exists('posix_setsid')) {
			posix_setsid();
		}

		$logFile = self::logPath();
		$pidFile = self::pidPath();
		@mkdir(dirname($logFile), 0755, true);
		@mkdir(dirname($pidFile), 0755, true);
		file_put_contents($pidFile, getmypid());

		$maxRestarts = Q_Config::get('Q', 'webserver', 'watchdog', 'maxRestarts', 10);
		$initialBackoff = Q_Config::get('Q', 'webserver', 'watchdog', 'backoff', 2);
		$maxBackoff = Q_Config::get('Q', 'webserver', 'watchdog', 'maxBackoff', 60);

		$crashes = [];
		$backoff = $initialBackoff;
		$monitorPid = $serverPid;

		self::log("Watchdog started (PID " . getmypid() . "), monitoring server PID $monitorPid");

		while (true) {
			// Wait for the server process to exit
			$status = 0;
			$result = Q_WebServer_Fork::waitpid($monitorPid, $status, 0);

			if ($result <= 0) {
				// Process doesn't exist or error — check if it's still alive
				if (!self::processAlive($monitorPid)) {
					self::log("Server PID $monitorPid gone (not found)");
				} else {
					sleep(1);
					continue;
				}
			}

			// Server exited. Check how.
			$exitCode = $status;
			$signal = 0;
			if (function_exists('pcntl_wifexited') && pcntl_wifexited($status)) {
				$exitCode = pcntl_wexitstatus($status);
			}
			if (function_exists('pcntl_wifsignaled') && pcntl_wifsignaled($status)) {
				$signal = pcntl_wtermsig($status);
			}

			// Clean exit (code 0, or SIGTERM/SIGINT) — watchdog exits too
			if ($exitCode === 0 && $signal === 0) {
				self::log("Server exited cleanly (code 0). Watchdog exiting.");
				@unlink($pidFile);
				exit(0);
			}
			if ($signal === 15 || $signal === 2) { // SIGTERM or SIGINT
				self::log("Server stopped (signal $signal). Watchdog exiting.");
				@unlink($pidFile);
				exit(0);
			}

			// Crash — log it
			$crashMsg = "Server crashed: exit=$exitCode signal=$signal";
			self::log($crashMsg);
			fwrite(STDERR, date('H:i:s') . " [watchdog] $crashMsg\n");

			// Rate limit: count crashes in the last hour
			$now = time();
			$crashes[] = $now;
			$crashes = array_filter($crashes, function($t) use ($now) {
				return $t > $now - 3600;
			});

			if (count($crashes) >= $maxRestarts) {
				self::log("Too many crashes (" . count($crashes) . " in 1 hour). Giving up.");
				fwrite(STDERR, date('H:i:s') . " [watchdog] Too many crashes. Not restarting.\n");
				@unlink($pidFile);
				exit(1);
			}

			// Backoff
			self::log("Restarting in {$backoff}s (crash " . count($crashes) . "/$maxRestarts in 1h)");
			sleep($backoff);
			$backoff = min($backoff * 2, $maxBackoff);

			// Restart the server
			$phpBinary = PHP_BINARY ?: 'php';
			$cmd = $argv;

			self::log("Restarting: " . implode(' ', $cmd));

			// Fork+exec the server again
			$newPid = Q_WebServer_Fork::fork();
			if ($newPid === 0) {
				// New child becomes the server
				if (function_exists('pcntl_exec')) {
					pcntl_exec($phpBinary, array_slice($cmd, 1));
				}
				// pcntl_exec replaces the process. If we get here, it failed.
				self::log("pcntl_exec failed, using exec()");
				$fullCmd = escapeshellarg($phpBinary);
				foreach (array_slice($cmd, 1) as $a) {
					$fullCmd .= ' ' . escapeshellarg($a);
				}
				exec($fullCmd . ' &');
				exit(0);
			} elseif ($newPid > 0) {
				$monitorPid = $newPid;
				self::log("Server restarted as PID $monitorPid");
				// Reset backoff on successful restart after a period of stability
			} else {
				self::log("Fork failed. Cannot restart.");
				@unlink($pidFile);
				exit(1);
			}
		}
	}

	static function processAlive($pid)
	{
		if (function_exists('posix_kill')) {
			return posix_kill($pid, 0);
		}
		if (PHP_OS_FAMILY === 'Windows') {
			exec("tasklist /FI \"PID eq $pid\" 2>NUL", $out);
			return count($out) > 1;
		}
		return file_exists("/proc/$pid");
	}

	static function logPath()
	{
		return Q_Config::get('Q', 'webserver', 'watchdog', 'log', qbix_data_path('local/watchdog.log'));
	}

	static function pidPath()
	{
		return Q_Config::get('Q', 'webserver', 'watchdog', 'pid', qbix_data_path('local/watchdog.pid'));
	}

	static function log($msg)
	{
		$line = date('c') . " [watchdog] $msg\n";
		@file_put_contents(self::logPath(), $line, FILE_APPEND | LOCK_EX);
	}

	/**
	 * Get status for the control panel.
	 */
	static function status()
	{
		$pidFile = self::pidPath();
		$logFile = self::logPath();
		$watchdogPid = is_file($pidFile) ? (int) trim(file_get_contents($pidFile)) : null;
		$alive = $watchdogPid ? self::processAlive($watchdogPid) : false;
		$recent = [];
		if (is_file($logFile)) {
			$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			$recent = array_slice($lines, -15);
		}
		return [
			'enabled' => (bool) Q_Config::get('Q', 'webserver', 'watchdog', null),
			'pid' => $alive ? $watchdogPid : null,
			'alive' => $alive,
			'log' => $recent,
		];
	}
}
