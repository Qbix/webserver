<?php
/**
 * @module Q
 */

/**
 * Built-in reverse proxy cache for Q_WebServer.
 *
 * Sits in the parent process event loop, before worker dispatch.
 * Cached responses are served without forking a worker — pure
 * event loop speed, on par with Varnish for cache hits.
 *
 * Two storage tiers:
 *   - APCu: for small responses (under Q.web.cache.apcu.maxSize)
 *   - Filesystem: for larger responses
 *
 * Respects HTTP caching semantics:
 *   - Cache-Control: max-age, s-maxage, no-store, private, no-cache
 *   - Vary header (cache per Accept-Encoding, etc.)
 *   - Cookie bypass: skip cache if request has specific cookies
 *
 * Config:
 *   "Q": { "web": { "cache": {
 *     "enabled": true,
 *     "dir": "files/cache/reverse",
 *     "apcu": {
 *       "enabled": true,
 *       "maxSize": 65536
 *     },
 *     "defaultTtl": 0,
 *     "skip": {
 *       "cookies": ["Q_sid", "PHPSESSID"]
 *     }
 *   }}}
 *
 * @class Q_WebServer_Cache
 */
class Q_WebServer_Cache
{
	static $enabled = false;
	static $dir = '';
	static $apcuEnabled = false;
	static $apcuMaxSize = 65536; // 64KB
	static $defaultTtl = 0;      // 0 = don't cache unless told to
	static $skipCookies = array();
	static $hits = 0;
	static $misses = 0;

	/**
	 * Initialize cache from config.
	 * @method init
	 * @static
	 */
	static function init()
	{
		$config = Q_Config::get('Q', 'web', 'cache', array());
		self::$enabled = (bool) Q::ifset($config, 'enabled', false);
		if (!self::$enabled) return;

		self::$dir = Q::ifset($config, 'dir', '');
		if (!self::$dir && defined('APP_DIR')) {
			self::$dir = APP_DIR . DS . 'files' . DS . 'cache' . DS . 'reverse';
		}
		if (self::$dir && !is_dir(self::$dir)) {
			mkdir(self::$dir, 0755, true);
		}

		$apcu = Q::ifset($config, 'apcu', array());
		self::$apcuEnabled = (bool) Q::ifset($apcu, 'enabled', function_exists('apcu_fetch'));
		self::$apcuMaxSize = (int) Q::ifset($apcu, 'maxSize', 65536);
		self::$defaultTtl = (int) Q::ifset($config, 'defaultTtl', 0);
		self::$skipCookies = Q::ifset($config, 'skip', 'cookies', array('Q_sid', 'PHPSESSID'));
	}

	/**
	 * Try to serve from cache. Returns response array or null.
	 *
	 * Called in the parent event loop BEFORE dispatching to a
	 * worker. A cache hit means zero fork overhead.
	 *
	 * @method get
	 * @static
	 * @param {array} $parsed Parsed request
	 * @return {array|null} [status, headers, body] or null
	 */
	static function get($parsed)
	{
		if (!self::$enabled) return null;
		if ($parsed['method'] !== 'GET') return null;

		// Skip cache if request has bypass cookies
		if (self::hasSkipCookie($parsed['headers'])) return null;

		$key = self::cacheKey($parsed);

		// Try APCu first (faster)
		if (self::$apcuEnabled) {
			$entry = apcu_fetch('qcache:' . $key);
			if ($entry !== false) {
				if ($entry['expires'] > 0 && $entry['expires'] < time()) {
					apcu_delete('qcache:' . $key);
				} else {
					self::$hits++;
					$entry['headers']['X-Cache'] = 'HIT';
					return $entry;
				}
			}
		}

		// Try filesystem
		$path = self::filePath($key);
		if ($path && file_exists($path)) {
			$entry = json_decode(file_get_contents($path), true);
			if ($entry && ($entry['expires'] === 0 || $entry['expires'] > time())) {
				self::$hits++;
				$entry['headers']['X-Cache'] = 'HIT';
				// Promote to APCu if small enough
				if (self::$apcuEnabled && strlen($entry['body']) <= self::$apcuMaxSize) {
					apcu_store('qcache:' . $key, $entry, self::ttlRemaining($entry));
				}
				return $entry;
			}
			@unlink($path);
		}

		self::$misses++;
		return null;
	}

	/**
	 * Store a response in cache if cacheable.
	 *
	 * Checks Cache-Control headers to determine TTL.
	 * Only caches GET responses with 200 status.
	 *
	 * @method put
	 * @static
	 * @param {array} $parsed Request
	 * @param {array} $response [status, headers, body]
	 */
	static function put($parsed, $response)
	{
		if (!self::$enabled) return;
		if ($parsed['method'] !== 'GET') return;
		if (($response['status'] ?? 200) !== 200) return;
		if (self::hasSkipCookie($parsed['headers'])) return;

		$headers = $response['headers'] ?? array();
		$cc = self::parseCacheControl($headers);

		// Don't cache if explicitly forbidden
		if (isset($cc['no-store']) || isset($cc['private'])) return;

		// Determine TTL
		$ttl = 0;
		if (isset($cc['s-maxage'])) {
			$ttl = (int) $cc['s-maxage'];
		} elseif (isset($cc['max-age'])) {
			$ttl = (int) $cc['max-age'];
		} elseif (self::$defaultTtl > 0) {
			$ttl = self::$defaultTtl;
		}

		if ($ttl <= 0) return; // nothing to cache

		$key = self::cacheKey($parsed);
		$body = $response['body'] ?? '';
		$expires = time() + $ttl;

		$entry = array(
			'status'  => $response['status'] ?? 200,
			'headers' => $headers,
			'body'    => $body,
			'expires' => $expires,
			'stored'  => time(),
		);

		// Store in APCu if small enough
		if (self::$apcuEnabled && strlen($body) <= self::$apcuMaxSize) {
			apcu_store('qcache:' . $key, $entry, $ttl);
		}

		// Always store on filesystem (APCu is per-process, lost on restart)
		$path = self::filePath($key);
		if ($path) {
			$dir = dirname($path);
			if (!is_dir($dir)) mkdir($dir, 0755, true);
			file_put_contents($path, json_encode($entry), LOCK_EX);
		}
	}

	/**
	 * Purge cache entries matching a URL pattern.
	 *
	 * Called by application code when content changes:
	 *   Q_WebServer_Cache::purge('/blog/my-post');
	 *   Q_WebServer_Cache::purge('#^/api/v1/#');
	 *
	 * @method purge
	 * @static
	 * @param {string} $pattern URL path or regex
	 */
	static function purge($pattern)
	{
		if (!self::$dir || !is_dir(self::$dir)) return;

		// If it looks like a regex (starts with a delimiter), match against files
		$isRegex = (strlen($pattern) > 2 && $pattern[0] === $pattern[strlen($pattern)-1])
			|| (strlen($pattern) > 2 && $pattern[0] === '#');

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(self::$dir, RecursiveDirectoryIterator::SKIP_DOTS)
		);
		foreach ($files as $file) {
			if ($file->getExtension() !== 'json') continue;
			$entry = json_decode(file_get_contents($file->getPathname()), true);
			if (!$entry) continue;

			$url = $entry['url'] ?? '';
			$match = $isRegex ? preg_match($pattern, $url) : ($url === $pattern);
			if ($match) {
				@unlink($file->getPathname());
				if (self::$apcuEnabled) {
					$key = self::cacheKeyFromUrl($url);
					apcu_delete('qcache:' . $key);
				}
			}
		}
	}

	/**
	 * Clear all cached entries.
	 * @method clear
	 * @static
	 */
	static function clear()
	{
		if (self::$apcuEnabled) {
			$iterator = new APCUIterator('#^qcache:#');
			apcu_delete($iterator);
		}
		if (self::$dir && is_dir(self::$dir)) {
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(self::$dir, RecursiveDirectoryIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ($files as $f) {
				$f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
			}
		}
	}

	// ── Internals ────────────────────────────────────────

	/**
	 * Generate a cache key from a request.
	 * Includes path + query + Vary headers.
	 */
	static function cacheKey($parsed)
	{
		$parts = $parsed['path'] . '?' . ($parsed['query'] ?? '');

		// Include Accept-Encoding in key for compressed variants
		$ae = $parsed['headers']['accept-encoding'] ?? '';
		if (strpos($ae, 'br') !== false) {
			$parts .= '|br';
		} elseif (strpos($ae, 'gzip') !== false) {
			$parts .= '|gzip';
		}

		return md5($parts);
	}

	static function cacheKeyFromUrl($url)
	{
		return md5($url);
	}

	static function filePath($key)
	{
		if (!self::$dir) return null;
		// Two-level directory to avoid too many files in one dir
		return self::$dir . DS . substr($key, 0, 2) . DS . $key . '.json';
	}

	/**
	 * Check if request has any cookies from the skip list.
	 * If a session cookie is present, the response is likely
	 * personalized and shouldn't be cached.
	 */
	static function hasSkipCookie($headers)
	{
		$cookieHeader = $headers['cookie'] ?? '';
		if (!$cookieHeader || empty(self::$skipCookies)) return false;

		foreach (self::$skipCookies as $name) {
			if (preg_match('/(?:^|;\s*)' . preg_quote($name, '/') . '=/', $cookieHeader)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse Cache-Control header into directives.
	 */
	static function parseCacheControl($headers)
	{
		$cc = '';
		foreach ($headers as $k => $v) {
			if (strtolower($k) === 'cache-control') { $cc = $v; break; }
		}
		if (!$cc) return array();

		$directives = array();
		foreach (explode(',', $cc) as $part) {
			$part = trim($part);
			if (strpos($part, '=') !== false) {
				list($k, $v) = explode('=', $part, 2);
				$directives[trim($k)] = trim($v);
			} else {
				$directives[$part] = true;
			}
		}
		return $directives;
	}

	static function ttlRemaining($entry)
	{
		if ($entry['expires'] <= 0) return 86400;
		return max(1, $entry['expires'] - time());
	}

	/**
	 * Stats for the dashboard.
	 */
	static function stats()
	{
		$total = self::$hits + self::$misses;
		return array(
			'hits' => self::$hits,
			'misses' => self::$misses,
			'hitRate' => $total > 0 ? round(self::$hits / $total * 100, 1) : 0,
		);
	}
}
