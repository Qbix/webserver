<?php
/**
 * Q_Relay_RateLimiter
 *
 * Token-bucket rate limiter with an hourly circuit breaker for the
 * Qbix relay system.
 *
 * On 2026-08-03 an unexplained 34,000 messages left in a few hours.
 * A token bucket makes that physically slow; the hourly breaker
 * makes it stop. Recovery is manual and deliberate.
 *
 * Runs in the parent relay process only. Forked workers call back
 * to the parent for rate checking.
 *
 * @package Q
 */
class Q_Relay_RateLimiter
{
	/**
	 * @var float Current tokens in the bucket.
	 */
	protected static $tokens = 0.0;

	/**
	 * @var float Timestamp of the last token refill (microtime).
	 */
	protected static $lastRefill = 0.0;

	/**
	 * @var float[] Timestamps of sends within the current hour window.
	 */
	protected static $hourWindow = [];

	/**
	 * @var bool Whether the circuit breaker has tripped.
	 */
	protected static $tripped = false;

	/**
	 * @var bool Whether init() has been called.
	 */
	protected static $initialized = false;

	/**
	 * @var int Token bucket capacity and per-minute refill rate.
	 */
	protected static $maxPerMinute = 60;

	/**
	 * @var int Hourly circuit breaker threshold.
	 */
	protected static $maxPerHour = 1000;

	/**
	 * @var float Timestamp of the last persist() call.
	 */
	protected static $lastPersist = 0.0;

	/**
	 * Initialize the rate limiter from config and, optionally,
	 * restore persisted state from the database.
	 *
	 * Config keys checked (Relay config first, Platform fallback second):
	 *  - Q.relay.rateLimit.maxPerMinute  /  Users.relay.rateLimit.maxPerMinute
	 *  - Q.relay.rateLimit.maxPerHour    /  Users.relay.rateLimit.maxPerHour
	 *
	 * @return void
	 */
	public static function init()
	{
		// Load config — Relay keys first, Platform fallbacks second
		self::$maxPerMinute = self::configInt(
			'Q.relay.rateLimit.maxPerMinute',
			'Users.relay.rateLimit.maxPerMinute',
			60
		);
		self::$maxPerHour = self::configInt(
			'Q.relay.rateLimit.maxPerHour',
			'Users.relay.rateLimit.maxPerHour',
			1000
		);

		// Try to restore state from DB
		$restored = false;
		try {
			$state = Q_Relay_Db::getRateState('global');
			if ($state) {
				self::$tokens = isset($state['tokens'])
					? (float)$state['tokens']
					: (float)self::$maxPerMinute;
				self::$lastRefill = isset($state['last_refill'])
					? (float)$state['last_refill']
					: microtime(true);
				self::$tripped = !empty($state['tripped']);

				$hourWindow = [];
				if (!empty($state['hour_window'])) {
					$decoded = json_decode($state['hour_window'], true);
					if (is_array($decoded)) {
						$hourWindow = $decoded;
					}
				}
				// Filter to the last hour on load
				$cutoff = microtime(true) - 3600;
				self::$hourWindow = array_values(array_filter(
					$hourWindow,
					function ($ts) use ($cutoff) {
						return $ts > $cutoff;
					}
				));

				$restored = true;
				error_log(sprintf(
					'[RateLimiter] Restored state: tokens=%.1f hourCount=%d tripped=%s',
					self::$tokens,
					count(self::$hourWindow),
					self::$tripped ? 'true' : 'false'
				));
			}
		} catch (\Exception $e) {
			error_log('[RateLimiter] Could not restore state: ' . $e->getMessage());
		}

		if (!$restored) {
			self::$tokens = (float)self::$maxPerMinute;
			self::$lastRefill = microtime(true);
			self::$hourWindow = [];
			self::$tripped = false;
		}

		self::$lastPersist = microtime(true);
		self::$initialized = true;

		error_log(sprintf(
			'[RateLimiter] Initialized: maxPerMinute=%d maxPerHour=%d',
			self::$maxPerMinute,
			self::$maxPerHour
		));
	}

	/**
	 * Refill tokens based on elapsed time since the last refill.
	 *
	 * tokens = min(maxPerMinute, tokens + elapsed_minutes * maxPerMinute)
	 *
	 * @return void
	 */
	public static function refill()
	{
		$now = microtime(true);
		$elapsedMinutes = ($now - self::$lastRefill) / 60.0;
		self::$tokens = min(
			(float)self::$maxPerMinute,
			self::$tokens + $elapsedMinutes * self::$maxPerMinute
		);
		self::$lastRefill = $now;
	}

	/**
	 * Try to acquire a send slot.
	 *
	 * @return int  0  — slot acquired, proceed.
	 *             >0  — milliseconds to wait before retrying.
	 *             -1  — circuit breaker tripped, halt all sending.
	 */
	public static function acquire()
	{
		self::ensureInit();

		// Circuit breaker already tripped
		if (self::$tripped) {
			return -1;
		}

		// Prune the hour window
		$now = microtime(true);
		$cutoff = $now - 3600;
		self::$hourWindow = array_values(array_filter(
			self::$hourWindow,
			function ($ts) use ($cutoff) {
				return $ts > $cutoff;
			}
		));

		// Hourly breaker check
		if (count(self::$hourWindow) >= self::$maxPerHour) {
			self::$tripped = true;
			error_log(sprintf(
				'[RateLimiter] CIRCUIT BREAKER TRIPPED: %d sends in the last hour (limit %d). '
				. 'All sending halted. Call Q_Relay_RateLimiter::reset() to resume.',
				count(self::$hourWindow),
				self::$maxPerHour
			));
			return -1;
		}

		// Refill tokens
		self::refill();

		// Try to consume a token
		if (self::$tokens >= 1.0) {
			self::$tokens -= 1.0;
			self::$hourWindow[] = $now;
			return 0;
		}

		// Not enough tokens — calculate wait time in ms
		$deficit = 1.0 - self::$tokens;
		$waitMs = (int)ceil(($deficit / self::$maxPerMinute) * 60000);
		return max(1, $waitMs);
	}

	/**
	 * Blocking wait for a send slot.
	 *
	 * Calls acquire() in a loop with usleep() until a slot opens,
	 * the circuit breaker trips, or the timeout expires.
	 *
	 * @param int $maxWaitMs Maximum time to wait in milliseconds.
	 * @return void
	 * @throws \RuntimeException If the circuit breaker trips or timeout is exceeded.
	 */
	public static function waitForSlot($maxWaitMs = 30000)
	{
		self::ensureInit();

		$deadline = microtime(true) + ($maxWaitMs / 1000.0);

		while (true) {
			$result = self::acquire();

			if ($result === 0) {
				return;
			}

			if ($result === -1) {
				throw new \RuntimeException(
					'[RateLimiter] Circuit breaker is tripped. '
					. 'Call Q_Relay_RateLimiter::reset() to resume sending.'
				);
			}

			// $result is the number of ms to wait
			if (microtime(true) + ($result / 1000.0) > $deadline) {
				throw new \RuntimeException(sprintf(
					'[RateLimiter] Timed out waiting for a send slot '
					. '(waited %d ms, next slot in ~%d ms).',
					$maxWaitMs,
					$result
				));
			}

			usleep($result * 1000);
		}
	}

	/**
	 * Reset the circuit breaker.
	 *
	 * Clears the tripped flag, empties the hour window, and refills
	 * tokens to the maximum. Recovery is manual and deliberate.
	 *
	 * @return void
	 */
	public static function reset()
	{
		self::ensureInit();

		self::$tripped = false;
		self::$hourWindow = [];
		self::$tokens = (float)self::$maxPerMinute;
		self::$lastRefill = microtime(true);

		error_log(sprintf(
			'[RateLimiter] Circuit breaker RESET. Tokens refilled to %d. '
			. 'Hour window cleared.',
			self::$maxPerMinute
		));

		self::persist();
	}

	/**
	 * Check whether the circuit breaker is currently tripped.
	 *
	 * @return bool
	 */
	public static function isTripped()
	{
		return self::$tripped;
	}

	/**
	 * Return current rate limiter metrics.
	 *
	 * @return array{tokens: float, hourCount: int, tripped: bool, maxPerMinute: int, maxPerHour: int}
	 */
	public static function getMetrics()
	{
		return [
			'tokens' => self::$tokens,
			'hourCount' => count(self::$hourWindow),
			'tripped' => self::$tripped,
			'maxPerMinute' => self::$maxPerMinute,
			'maxPerHour' => self::$maxPerHour,
		];
	}

	/**
	 * Persist current state to the database for crash recovery.
	 *
	 * Called periodically (~60s) by the relay process. Uses the key
	 * "global" since there is a single rate limiter per relay instance.
	 *
	 * @return void
	 */
	public static function persist()
	{
		try {
			Q_Relay_Db::setRateState(
				'global',
				self::$tokens,
				self::$lastRefill,
				json_encode(self::$hourWindow)
			);
			self::$lastPersist = microtime(true);
		} catch (\Exception $e) {
			error_log('[RateLimiter] Failed to persist state: ' . $e->getMessage());
		}
	}

	/**
	 * Persist if enough time has elapsed since the last persist.
	 *
	 * Convenience method for the relay's main loop to call on every
	 * iteration without worrying about timing.
	 *
	 * @param float $intervalSeconds Minimum seconds between persists (default 60).
	 * @return void
	 */
	public static function maybePersist($intervalSeconds = 60.0)
	{
		if ((microtime(true) - self::$lastPersist) >= $intervalSeconds) {
			self::persist();
		}
	}

	// ----------------------------------------------------------------
	// Internal helpers
	// ----------------------------------------------------------------

	/**
	 * Ensure init() has been called.
	 *
	 * @return void
	 */
	protected static function ensureInit()
	{
		if (!self::$initialized) {
			self::init();
		}
	}

	/**
	 * Read an integer config value, trying the primary key first and
	 * falling back to the platform key, then the default.
	 *
	 * @param string $primaryKey   Dot-path for Q_Config::get().
	 * @param string $fallbackKey  Dot-path for the platform fallback.
	 * @param int    $default      Value if neither key is set.
	 * @return int
	 */
	protected static function configInt($primaryKey, $fallbackKey, $default)
	{
		$parts = explode('.', $primaryKey);
		$value = call_user_func_array(
			['Q_Config', 'get'],
			array_merge($parts, [null])
		);
		if ($value !== null) {
			return (int)$value;
		}

		$parts = explode('.', $fallbackKey);
		$value = call_user_func_array(
			['Q_Config', 'get'],
			array_merge($parts, [null])
		);
		if ($value !== null) {
			return (int)$value;
		}

		return $default;
	}
}
