<?php
/**
 * Cron-like task scheduler. Runs handlers at configured intervals or times.
 * Checks every second, forks a child for each task to avoid blocking.
 *
 * Config example:
 *   "Q": {
 *     "scheduler": {
 *       "cleanup":       {"handler": "tasks/cleanup", "every": 3600},
 *       "daily-report":  {"handler": "tasks/report", "times": ["09:00"]},
 *       "biz-check":     {"handler": "tasks/check", "times": ["09:00","17:00"], "weekdays": ["mon","fri"]},
 *       "monthly":       {"handler": "tasks/invoice", "times": ["00:00"], "monthdays": [1]}
 *     }
 *   }
 *
 * @class Q_Scheduler
 */
class Q_Scheduler
{
	/** @var array Task definitions from config */
	static $tasks = array();
	/** @var array taskName => timestamp of last run */
	static $lastRun = array();
	/** @var float Server start time */
	static $startTime = 0;
	/** @var string The HH:MM at startup (to skip on restart) */
	static $startMinute = '';

	/**
	 * Initialize the scheduler with task definitions.
	 * Marks the current minute as "already checked" to avoid
	 * re-firing tasks if the server restarts mid-minute.
	 */
	static function init($schedule)
	{
		self::$startTime = microtime(true);
		self::$startMinute = date('H:i');

		foreach ($schedule as $name => $def) {
			if (empty($def['handler'])) continue;
			self::$tasks[$name] = $def;

			// For interval tasks, set lastRun to now so first fire
			// is one full interval after startup
			if (isset($def['every'])) {
				self::$lastRun[$name] = self::$startTime;
			}

			// For time-based tasks, mark current minute as run
			// to prevent re-fire on restart
			if (isset($def['times'])) {
				if (in_array(self::$startMinute, $def['times'])) {
					self::$lastRun[$name] = self::$startTime;
				}
			}
		}
	}

	/**
	 * Called every second by the event loop.
	 * Checks each task and fires if due.
	 */
	static function tick()
	{
		$now = microtime(true);
		$minute = date('H:i');
		$wday = strtolower(date('D')); // mon, tue, wed...
		$mday = (int) date('j');       // 1-31

		foreach (self::$tasks as $name => $def) {
			// Interval-based: "every" seconds
			if (isset($def['every'])) {
				$last = self::$lastRun[$name] ?? 0;
				if ($now - $last >= $def['every']) {
					self::run($name, $def);
				}
				continue;
			}

			// Time-based: check if current HH:MM matches
			if (isset($def['times'])) {
				if (!in_array($minute, $def['times'])) continue;

				// Already ran this minute?
				$last = self::$lastRun[$name] ?? 0;
				if ($now - $last < 60) continue;

				// Weekday filter
				if (isset($def['weekdays'])) {
					$allowed = array_map('strtolower', $def['weekdays']);
					// Accept both "mon" and "monday" style
					$wdayFull = strtolower(date('l'));
					if (!in_array($wday, $allowed) && !in_array($wdayFull, $allowed)) {
						continue;
					}
				}

				// Monthday filter
				if (isset($def['monthdays'])) {
					if (!in_array($mday, $def['monthdays'])) continue;
				}

				self::run($name, $def);
			}
		}
	}

	/**
	 * Run a task by forking a child process.
	 * Sets lastRun BEFORE launching to err on the side of skipping.
	 */
	static function run($name, $def)
	{
		$handler = $def['handler'];

		// Mark as run BEFORE fork — if we crash, we skip rather than double-run
		self::$lastRun[$name] = microtime(true);

		if (!function_exists('pcntl_fork')) {
			// No fork — run in-process (blocks event loop briefly)
			$result = null;
			Q::event($handler, array('task' => $name, 'scheduled' => true), false, false, $result);
			return;
		}

		$pid = pcntl_fork();
		if ($pid === 0) {
			// CHILD
			$result = null;
			try {
				Q::event($handler, array('task' => $name, 'scheduled' => true), false, false, $result);
			} catch (\Throwable $e) {
				// Task failed — log but don't crash
				fwrite(STDERR, date('H:i:s') . " scheduler: $name failed: " . $e->getMessage() . "\n");
			}
			exit(0);
		} elseif ($pid > 0) {
			// PARENT — track for timeout enforcement
			Q_WebServer::$workerPids[$pid] = microtime(true);
			pcntl_waitpid($pid, $st, WNOHANG);
		}
	}
}
