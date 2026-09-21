<?php
/**
 * Snapshots and restores static properties on all user-defined classes.
 *
 * This lets workers handle multiple requests in a loop WITHOUT state leaks:
 * the worker takes a snapshot after preloading, then restores it between
 * requests. ReflectionProperty handles are cached at snapshot time so
 * restoreStatics() does only setValue() calls — no Reflection lookups.
 *
 * Compare: pcntl_fork() costs ~8ms. Snapshot restore is ~160× cheaper,
 * and achieves the same state isolation for static properties.
 *
 * What it DOES reset:
 *   - All static properties on all user-defined classes
 *   - $GLOBALS (except superglobals)
 *   - Superglobals ($_GET, $_POST, $_COOKIE, $_SERVER, $_REQUEST, $_FILES)
 *
 * What it does NOT reset:
 *   - Resources (DB connections, file handles) — these must be managed by
 *     the application, same as fpm's max_requests recycling
 *   - Closures that captured references to statics (rare, and unfixable
 *     without garbage-collecting the closure itself)
 *   - C-level extension state (same limitation as fpm)
 *
 * @class Q_WebServer_Snapshot
 */
class Q_WebServer_Snapshot
{
	/** @var array class => [property => value] */
	private static $snapshot = array();
	/** @var array class => [property => ReflectionProperty] — cached handles */
	private static $reflectors = array();
	/** @var array Global variables snapshot */
	private static $globals = array();
	/** @var boolean Whether a snapshot has been taken */
	private static $taken = false;

	/**
	 * Take a snapshot of all static properties. Call ONCE after preloading,
	 * before the worker starts handling requests.
	 *
	 * Caches ReflectionProperty handles alongside values. Once a class is
	 * loaded, its property slots never move, so the cached handle is safe
	 * to reuse indefinitely. Only false negatives are possible (a new class
	 * not yet cached), never false positives (stale handle pointing to the
	 * wrong slot).
	 */
	static function take()
	{
		self::$snapshot = array();
		self::$reflectors = array();
		$count = 0;
		foreach (get_declared_classes() as $cls) {
			$ref = new \ReflectionClass($cls);
			if ($ref->isInternal()) continue;
			$props = $ref->getProperties(\ReflectionProperty::IS_STATIC);
			if (empty($props)) continue;
			self::$snapshot[$cls] = array();
			self::$reflectors[$cls] = array();
			foreach ($props as $prop) {
				$prop->setAccessible(true);
				try {
					$val = $prop->getValue(null);
					if (is_resource($val)) continue;
					$name = $prop->getName();
					self::$snapshot[$cls][$name] =
						is_object($val) ? clone $val : $val;
					self::$reflectors[$cls][$name] = $prop;
					$count++;
				} catch (\Throwable $e) { /* uninitialized typed property */ }
			}
			if (empty(self::$snapshot[$cls])) {
				unset(self::$snapshot[$cls]);
				unset(self::$reflectors[$cls]);
			}
		}

		// Snapshot globals (excluding superglobals and our own state)
		$skip = array('_GET','_POST','_COOKIE','_SERVER','_REQUEST',
			'_FILES','_ENV','_SESSION','GLOBALS','argv','argc');
		self::$globals = array();
		foreach ($GLOBALS as $k => $v) {
			if (in_array($k, $skip, true)) continue;
			if (is_resource($v)) continue;
			self::$globals[$k] = is_object($v) ? clone $v : $v;
		}

		self::$taken = true;
		return $count;
	}

	/**
	 * Restore all static properties and globals to the snapshot.
	 * Call between requests in the worker loop.
	 */
	static function restore()
	{
		if (!self::$taken) return;

		// Restore static properties via cached handles
		foreach (self::$snapshot as $cls => $props) {
			foreach ($props as $name => $val) {
				self::$reflectors[$cls][$name]->setValue(
					null, is_object($val) ? clone $val : $val
				);
			}
		}

		// Restore globals
		$skip = array('_GET','_POST','_COOKIE','_SERVER','_REQUEST',
			'_FILES','_ENV','_SESSION','GLOBALS','argv','argc');
		foreach (array_keys($GLOBALS) as $k) {
			if (in_array($k, $skip, true)) continue;
			if (!array_key_exists($k, self::$globals)) {
				unset($GLOBALS[$k]);
			}
		}
		foreach (self::$globals as $k => $v) {
			$GLOBALS[$k] = is_object($v) ? clone $v : $v;
		}
	}

	/**
	 * How many properties are being tracked.
	 */
	static function count()
	{
		$n = 0;
		foreach (self::$snapshot as $props) $n += count($props);
		return $n;
	}

	/**
	 * Expose the class snapshot for callers that need statics-only restore.
	 */
	static function getClassSnapshot() { return self::$snapshot; }

	/**
	 * Restore ONLY class statics, not globals.
	 * Uses cached ReflectionProperty handles — no Reflection lookups per call.
	 */
	static function restoreStatics()
	{
		if (!self::$taken) return;
		foreach (self::$snapshot as $cls => $props) {
			// Never restore our own statics — doing so would undo
			// updateNewClasses() by reverting $snapshot/$reflectors.
			if ($cls === 'Q_WebServer_Snapshot') continue;
			// Never restore Compat statics — the compat layer manages its
			// own state via shutdown()/init().
			if ($cls === 'Q_WebServer_Compat') continue;
			foreach ($props as $name => $val) {
				$prop = self::$reflectors[$cls][$name];
				// A closure in a static is behaviour installed once, never
				// request data. Composer's ClassLoader keeps its include helper
				// there and builds it behind a null check, so putting the
				// declared null back left the loader calling null on the next
				// request.
				//
				// Only closures. Skipping the class entirely, or sparing every
				// object, both left an application's request state standing:
				// Exponential keeps the requested route in an object static, so
				// every later request came back with the first one's page.
				try {
					if ($prop->getValue(null) instanceof \Closure) continue;
				} catch (\Throwable $ignore) { /* uninitialized typed property */ }
				$prop->setValue(null, is_object($val) ? clone $val : $val);
			}
		}
	}

	/**
	 * Detect classes declared AFTER the initial snapshot and add their
	 * statics to the snapshot using the declaration default values.
	 *
	 * Also populates the $reflectors cache for the new class so
	 * restoreStatics() can use cached handles on subsequent requests.
	 *
	 * Cost: ~0.01ms per new class (one ReflectionClass + property scan).
	 * Already-tracked classes are skipped in O(1) via isset().
	 */
	static function updateNewClasses()
	{
		if (!self::$taken) return;
		foreach (get_declared_classes() as $cls) {
			if (isset(self::$snapshot[$cls])) continue;
			$ref = new \ReflectionClass($cls);
			if ($ref->isInternal()) continue;
			$props = $ref->getProperties(\ReflectionProperty::IS_STATIC);
			if (empty($props)) continue;
			self::$snapshot[$cls] = array();
			self::$reflectors[$cls] = array();
			foreach ($props as $prop) {
				$prop->setAccessible(true);
				try {
					$val = $prop->hasDefaultValue()
						? $prop->getDefaultValue()
						: $prop->getValue(null);
					if (is_resource($val)) continue;
					$name = $prop->getName();
					self::$snapshot[$cls][$name] =
						is_object($val) ? clone $val : $val;
					self::$reflectors[$cls][$name] = $prop;
				} catch (\Throwable $e) { /* uninitialized typed property */ }
			}
			if (empty(self::$snapshot[$cls])) {
				unset(self::$snapshot[$cls]);
				unset(self::$reflectors[$cls]);
			}
		}
	}
}
