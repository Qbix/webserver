<?php
/**
 * Hot reload — watches directories for file changes and triggers
 * a graceful server restart when detected.
 *
 * Uses filemtime() polling (works everywhere, no extensions needed).
 * Watches classes/, handlers/, config/ directories.
 *
 * Config:
 *   "Q": {"webserver": {"hotReload": true}}
 *
 * When a change is detected:
 *   - Handler changes: no action needed (lazy-loaded, next fork loads fresh)
 *   - Class/config changes: graceful restart via re-exec
 *
 * @class Q_HotReload
 */
class Q_HotReload
{
	/** @var array path => mtime snapshot */
	static $snapshot = array();
	/** @var float Last full scan time */
	static $lastScan = 0;
	/** @var array Directories to watch */
	static $watchDirs = array();
	/** @var boolean Whether a restart is pending */
	static $restarting = false;

	/**
	 * Initialize: snapshot all watched files.
	 */
	static function init()
	{
		// Watch standard directories relative to each registered path
		foreach (Q::$paths as $base) {
			foreach (array('classes', 'handlers', 'config') as $dir) {
				$full = $base . DS . $dir;
				if (is_dir($full)) {
					self::$watchDirs[] = $full;
				}
			}
		}

		if (empty(self::$watchDirs)) return;

		self::$snapshot = self::scan();
		self::$lastScan = microtime(true);
	}

	/**
	 * Check for changes. Called every 2 seconds by the event loop.
	 */
	static function check()
	{
		if (self::$restarting || empty(self::$watchDirs)) return;

		$current = self::scan();
		$changes = self::diff($current);

		if (empty($changes)) {
			self::$snapshot = $current;
			return;
		}

		// Categorize changes
		$needsRestart = false;
		foreach ($changes as $file => $type) {
			$rel = self::relativePath($file);
			if (strpos($rel, 'classes' . DS) === 0 || strpos($rel, 'config' . DS) === 0) {
				$needsRestart = true;
			}
			$label = ($type === 'added') ? "\033[32m+\033[0m" :
					 (($type === 'removed') ? "\033[31m-\033[0m" : "\033[33m~\033[0m");
			fwrite(STDERR, date('H:i:s') . " hot-reload: $label $rel\n");
		}

		self::$snapshot = $current;

		if ($needsRestart) {
			self::restart();
		}
	}

	/**
	 * Scan all watched directories, return path => mtime map.
	 */
	static function scan()
	{
		$files = array();
		foreach (self::$watchDirs as $dir) {
			self::scanDir($dir, $files);
		}
		return $files;
	}

	private static function scanDir($dir, &$files)
	{
		$entries = @scandir($dir);
		if (!$entries) return;
		foreach ($entries as $e) {
			if ($e[0] === '.') continue;
			$path = $dir . DS . $e;
			if (is_dir($path)) {
				self::scanDir($path, $files);
			} elseif (is_file($path)) {
				$files[$path] = @filemtime($path);
			}
		}
	}

	/**
	 * Compare current scan to snapshot, return changed files.
	 */
	static function diff($current)
	{
		$changes = array();
		// Modified or added
		foreach ($current as $path => $mtime) {
			if (!isset(self::$snapshot[$path])) {
				$changes[$path] = 'added';
			} elseif ($mtime !== self::$snapshot[$path]) {
				$changes[$path] = 'modified';
			}
		}
		// Removed
		foreach (self::$snapshot as $path => $mtime) {
			if (!isset($current[$path])) {
				$changes[$path] = 'removed';
			}
		}
		return $changes;
	}

	/**
	 * Get a human-readable relative path.
	 */
	static function relativePath($path)
	{
		foreach (Q::$paths as $base) {
			if (strpos($path, $base . DS) === 0) {
				return substr($path, strlen($base) + 1);
			}
		}
		return basename($path);
	}

	/**
	 * Graceful restart — re-exec the server process.
	 */
	static function restart()
	{
		self::$restarting = true;
		fwrite(STDERR, date('H:i:s') . " hot-reload: restarting server...\n");

		// Re-exec: replace current process with a fresh one
		// This preserves the original command-line arguments
		$args = $_SERVER['argv'] ?? array();
		$php = PHP_BINARY;

		if (function_exists('pcntl_exec')) {
			pcntl_exec($php, $args);
			// If pcntl_exec fails, fall through
		}

		// Fallback: signal the event loop to stop, then exec
		fwrite(STDERR, date('H:i:s') . " hot-reload: stopping for manual restart\n");
		Q_Evented::stop();
	}
}
