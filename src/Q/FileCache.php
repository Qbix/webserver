<?php
/**
 * @module Q
 */

/**
 * Lightweight mtime-based file cache for long-running PHP processes.
 *
 * In php-fpm every request re-reads files from disk. In a persistent
 * server (Q_WebServer), files load once and stay in memory. This class
 * tracks mtimes so changed files get reloaded — one stat() syscall
 * per check, same cost as nginx checking a file.
 *
 * @class Q_FileCache
 */
class Q_FileCache
{
	/**
	 * path => [mtime, content, type]
	 * @property $cache
	 * @static
	 * @protected
	 */
	protected static $cache = array();

	/**
	 * Load file contents. Returns cached version if mtime unchanged.
	 * @method load
	 * @static
	 * @param {string} $path
	 * @return {string|false}
	 */
	static function load($path)
	{
		$mtime = self::mtime($path);
		if ($mtime === false) {
			unset(self::$cache[$path]);
			return false;
		}
		if (isset(self::$cache[$path]) && self::$cache[$path]['mtime'] === $mtime) {
			return self::$cache[$path]['content'];
		}
		$content = file_get_contents($path);
		if ($content === false) return false;
		self::$cache[$path] = array('mtime' => $mtime, 'content' => $content, 'type' => 'raw');
		return $content;
	}

	/**
	 * Load and JSON-decode a file.
	 * @method loadJson
	 * @static
	 * @param {string} $path
	 * @return {array|null}
	 */
	static function loadJson($path)
	{
		$mtime = self::mtime($path);
		if ($mtime === false) { unset(self::$cache[$path]); return null; }
		if (isset(self::$cache[$path])
			&& self::$cache[$path]['mtime'] === $mtime
			&& self::$cache[$path]['type'] === 'json'
		) {
			return self::$cache[$path]['content'];
		}
		$raw = file_get_contents($path);
		if ($raw === false) return null;
		$data = json_decode($raw, true);
		self::$cache[$path] = array('mtime' => $mtime, 'content' => $data, 'type' => 'json');
		return $data;
	}

	/**
	 * Load a PHP file that returns a value.
	 * Re-includes if mtime changed.
	 * @method loadPhp
	 * @static
	 * @param {string} $path
	 * @return {mixed}
	 */
	static function loadPhp($path)
	{
		$mtime = self::mtime($path);
		if ($mtime === false) { unset(self::$cache[$path]); return null; }
		if (isset(self::$cache[$path])
			&& self::$cache[$path]['mtime'] === $mtime
			&& self::$cache[$path]['type'] === 'php'
		) {
			return self::$cache[$path]['content'];
		}
		$data = include($path);
		self::$cache[$path] = array('mtime' => $mtime, 'content' => $data, 'type' => 'php');
		return $data;
	}

	/**
	 * Check all cached files for changes. Returns changed paths.
	 * @method checkAll
	 * @static
	 * @return {array}
	 */
	static function checkAll()
	{
		$changed = array();
		foreach (self::$cache as $path => $entry) {
			$mtime = self::mtime($path);
			if ($mtime === false || $mtime !== $entry['mtime']) {
				$changed[] = $path;
				if ($mtime === false) {
					unset(self::$cache[$path]);
				} else {
					self::$cache[$path]['mtime'] = -1; // mark stale
				}
			}
		}
		return $changed;
	}

	/** @method invalidate */
	static function invalidate($path) { unset(self::$cache[$path]); }

	/** @method clear */
	static function clear() { self::$cache = array(); }

	/**
	 * @method mtime
	 * @static
	 * @protected
	 */
	protected static function mtime($path)
	{
		clearstatcache(true, $path);
		return file_exists($path) ? filemtime($path) : false;
	}
}
