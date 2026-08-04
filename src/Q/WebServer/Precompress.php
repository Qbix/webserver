<?php

/**
 * Filesystem-backed LRU cache of pre-compressed static files.
 *
 * Instead of gzip-ing a static file on every request (what maybeCompress()
 * does), this compresses each eligible file once, stores the .gz in a cache
 * directory, and serves the cached bytes on subsequent requests — no repeated
 * CPU. The cache is a plain directory of .gz files, so it is shared across all
 * forked workers via the filesystem, and LRU ordering is tracked by each file's
 * mtime (serve() touch()es on every hit; eviction deletes the oldest).
 *
 * Because the cache holds real .gz files on disk, a front proxy (nginx/CDN) can
 * later stream them directly via X-Accel-Redirect / sendfile — this class sets
 * Content-Encoding + Vary and returns the bytes; "nginx or the CDN does the rest".
 *
 * Config (all under Q/webserver/precompress, with defaults):
 *   enabled  (bool, default false)  turn the cache on; build while on, then off
 *   maxFiles (int,  default 1000)   LRU cap — keep the top-N most-recently-served
 *   minSize  (int,  default 1024)   only precompress files at least this many bytes
 *   level    (int,  default 6)      gzip level
 *   dir      (string, default sys_temp/qbixserver-precompress) cache directory
 *
 * @class Q_WebServer_Precompress
 */
class Q_WebServer_Precompress
{
	protected static $dir = false; // false = not yet resolved, null = unavailable

	/**
	 * Return pre-compressed bytes for $fsPath (building + caching on first use),
	 * or null if precompression does not apply here (disabled, too small, wrong
	 * content type, or the client will not accept gzip). On success this sets
	 * Content-Encoding and Vary in $headers by reference.
	 *
	 * @method serve
	 * @static
	 * @param {string} $fsPath          absolute path to the static file
	 * @param {string} $contentType     the file's MIME type
	 * @param {integer} $mtime          filemtime($fsPath) — part of the cache key
	 * @param {integer} $size           filesize($fsPath)
	 * @param {array} $requestHeaders   lowercased request headers
	 * @param {array} &$headers         response headers (modified on success)
	 * @return {string|null} compressed bytes, or null to fall back to raw
	 */
	static function serve($fsPath, $contentType, $mtime, $size, $requestHeaders, &$headers)
	{
		if (!Q_Config::get('Q', 'webserver', 'precompress', 'enabled', false)) {
			return null;
		}
		$minSize = (int) Q_Config::get('Q', 'webserver', 'precompress', 'minSize', 1024);
		if ($size < $minSize) {
			return null;
		}
		if (!self::compressible($contentType)) {
			return null;
		}
		$accept = strtolower($requestHeaders['accept-encoding'] ?? '');
		if (strpos($accept, 'gzip') === false || !function_exists('gzencode')) {
			return null;
		}
		$dir = self::dir();
		if ($dir === null) {
			return null;
		}

		// Cache key includes mtime, so editing the source invalidates the entry.
		$cachePath = $dir . DIRECTORY_SEPARATOR . md5($fsPath) . '-' . dechex($mtime) . '.gz';

		if (!is_file($cachePath)) {
			$raw = file_get_contents($fsPath);
			if ($raw === false) {
				return null;
			}
			$level = (int) Q_Config::get('Q', 'webserver', 'precompress', 'level', 6);
			$gz = gzencode($raw, $level);
			if ($gz === false || strlen($gz) >= strlen($raw)) {
				return null; // compression didn't help — let caller serve raw
			}
			// Write atomically (rename) so concurrent workers never read a partial file.
			$tmp = $cachePath . '.' . getmypid() . '.tmp';
			if (@file_put_contents($tmp, $gz) !== false) {
				@rename($tmp, $cachePath);
				self::enforceLimit($dir);
			} else {
				@unlink($tmp);
				// Couldn't cache (e.g. read-only dir) — still serve the compressed bytes.
				$headers['Content-Encoding'] = 'gzip';
				$headers['Vary'] = 'Accept-Encoding';
				return $gz;
			}
		}

		@touch($cachePath);              // mark most-recently-used (LRU is by mtime)
		$bytes = @file_get_contents($cachePath);
		if ($bytes === false) {
			return null;
		}
		$headers['Content-Encoding'] = 'gzip';
		$headers['Vary'] = 'Accept-Encoding';
		return $bytes;
	}

	/**
	 * Whether a content type is worth compressing (text and text-ish formats).
	 * @method compressible
	 * @static
	 */
	static function compressible($ct)
	{
		$ct = strtolower($ct);
		if (strpos($ct, 'text/') === 0) {
			return true;
		}
		$needles = array(
			'json', 'javascript', 'ecmascript', 'xml', 'svg',
			'wasm', 'x-font', 'font/ttf', 'font/otf', '+json', '+xml'
		);
		foreach ($needles as $n) {
			if (strpos($ct, $n) !== false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve (and lazily create) the cache directory, memoized per worker.
	 * @method dir
	 * @static
	 * @return {string|null}
	 */
	protected static function dir()
	{
		if (self::$dir !== false) {
			return self::$dir;
		}
		$dir = Q_Config::get('Q', 'webserver', 'precompress', 'dir', null);
		if (!$dir) {
			$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qbixserver-precompress';
		}
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		self::$dir = is_dir($dir) ? $dir : null;
		return self::$dir;
	}

	/**
	 * LRU eviction: if the cache holds more than maxFiles entries, delete the
	 * oldest by mtime (serve() touches on each hit, so oldest = least-recently-used).
	 * @method enforceLimit
	 * @static
	 */
	protected static function enforceLimit($dir)
	{
		$maxFiles = (int) Q_Config::get('Q', 'webserver', 'precompress', 'maxFiles', 1000);
		if ($maxFiles <= 0) {
			return;
		}
		$files = glob($dir . DIRECTORY_SEPARATOR . '*.gz');
		if ($files === false) {
			return;
		}
		$n = count($files);
		if ($n <= $maxFiles) {
			return;
		}
		usort($files, function ($a, $b) {
			return filemtime($a) <=> filemtime($b); // oldest first
		});
		$overflow = $n - $maxFiles;
		for ($i = 0; $i < $overflow; $i++) {
			@unlink($files[$i]);
		}
	}
}
