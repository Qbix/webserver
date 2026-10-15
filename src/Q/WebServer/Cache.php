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
 * Three storage tiers (fastest first):
 *   1. In-memory PHP array (parent process, zero-copy)
 *   2. APCu (shared memory, requires serialization)
 *   3. Filesystem (survives restarts, unlimited size)
 *
 * Respects HTTP caching semantics:
 *   - Cache-Control: max-age, s-maxage, no-store, private, no-cache
 *   - Cache-Control: stale-while-revalidate (serve stale, refresh in background)
 *   - Vary header (cache per Accept-Encoding, Cookie, Accept-Language, etc.)
 *   - Cookie bypass: skip cache if request has specific cookies
 *   - ETag/If-None-Match validation
 *
 * Adaptive auto-caching (for frameworks without cache headers):
 *   When enabled (Q.web.cache.auto.enabled = true), the server learns
 *   which URLs produce stable content by fingerprinting response bodies.
 *   After N identical responses (default 2), the URL is auto-cached with
 *   an exponentially growing TTL (5s → 10s → 20s → ... → 1h max).
 *   If the content changes, the TTL resets and re-learning begins.
 *   This gives static-site-generator parity without framework changes.
 *   Stateful responses (Set-Cookie, per-user Vary: Cookie) are excluded.
 *
 * Vary header handling:
 *   When a response includes a Vary header (e.g. "Vary: Accept-Encoding, Cookie"),
 *   the cache key incorporates the values of those request headers. This means
 *   each unique combination of varied headers gets its own cache entry. The Vary
 *   fields from the FIRST cached response for a URL are remembered (stored in the
 *   vary index) and applied to subsequent requests for the same URL — so the
 *   cache key is correct even on cache misses.
 *
 *   For Vary: Cookie, this means logged-in users with different session cookies
 *   get separate cache entries. Combined with X-Cache-Deps from the Merkle
 *   component layer, this enables per-user caching with targeted invalidation.
 *
 * Config:
 *   "Q": { "web": { "cache": {
 *     "enabled": true,
 *     "dir": "files/cache/reverse",
 *     "memory": {
 *       "maxSize": 33554432,
 *       "maxEntries": 1000
 *     },
 *     "apcu": {
 *       "enabled": true,
 *       "maxSize": 65536
 *     },
 *     "defaultTtl": 0,
 *     "skip": {
 *       "cookies": ["Q_sid", "PHPSESSID"]
 *     },
 *     "auto": {
 *       "enabled": false,
 *       "initialTtl": 5,
 *       "maxTtl": 3600,
 *       "growFactor": 2,
 *       "minHits": 2,
 *       "maxFingerprints": 10000,
 *       "patterns": ["/blog/*", "/docs/*"],
 *       "skipPatterns": ["/api/*", "/admin/*"]
 *     }
 *   }}}
 *
 * Per-domain/app config (overrides global auto-cache settings):
 *   "Q": { "webserver": { "domains": {
 *     "myblog.example.com": {
 *       "root": "/var/www/myblog",
 *       "cache": { "auto": {
 *         "enabled": true,
 *         "framework": "wordpress"
 *       }}
 *     },
 *     "shop.example.com": {
 *       "root": "/var/www/shop",
 *       "cache": { "auto": {
 *         "enabled": true,
 *         "framework": "drupal",
 *         "maxTtl": 7200,
 *         "skipPatterns": ["/cart/*", "/checkout/*"]
 *       }}
 *     }
 *   }}}
 *
 * Framework presets (set "framework" key in per-host auto config):
 *   "wordpress"  — skips wp-admin, wp-login, wp-json; caches uploads/themes
 *   "drupal"     — skips admin, user, batch; caches files/assets
 *   "laravel"    — skips api, nova, horizon; caches build/vendor
 *   "symfony"    — skips _profiler, _wdt; caches bundles/build
 *   "joomla"     — skips administrator; caches media/templates
 *   "qbix"       — skips action.php, api; caches plugin assets
 *   "static"     — caches everything, 60s initial TTL, 24h max
 *
 * @class Q_WebServer_Cache
 */
class Q_WebServer_Cache
{
	static $enabled = false;
	static $dir = '';
	static $apcuEnabled = false;
	static $apcuMaxSize = 65536; // 64KB — max body for APCu tier
	static $defaultTtl = 0;      // 0 = don't cache unless told to
	static $skipCookies = array();
	static $hits = 0;
	static $misses = 0;
	static $staleHits = 0;       // stale-while-revalidate serves

	// ── In-memory hot tier (parent process) ─────────────

	/**
	 * In-memory cache: key => entry.
	 * Fastest tier — no serialization, no IPC, zero-copy.
	 * Same idea as the static file cache but for dynamic responses.
	 * @property $mem
	 */
	protected static $mem = array();
	protected static $memSize = 0;
	protected static $memMaxSize = 33554432; // 32MB default
	protected static $memMaxEntries = 1000;

	// ── Vary index ──────────────────────────────────────
	// Remembers which Vary fields a URL uses, so we can build the
	// correct cache key even on cache misses (before we've seen
	// the response's Vary header).
	// urlBase => array of lowercase header names

	/**
	 * @property $varyIndex
	 */
	protected static $varyIndex = array();

	// ── Stale-while-revalidate tracking ─────────────────
	// Pages currently being revalidated in background.
	// key => true (prevents duplicate revalidation requests)

	/**
	 * @property $revalidating
	 */
	protected static $revalidating = array();

	// ── Adaptive auto-caching ───────────────────────────
	// For frameworks that don't send cache headers, the server
	// learns what's static by observing response stability.
	//
	// $fingerprints: urlBase => {
	//   'hash'   => md5 of last response body,
	//   'hits'   => consecutive identical responses,
	//   'ttl'    => current adaptive TTL (grows exponentially),
	//   'time'   => timestamp of last observation,
	//   'size'   => response body length,
	// }

	/**
	 * @property $fingerprints
	 */
	protected static $fingerprints = array();

	/**
	 * Adaptive caching config
	 * @property $autoCache
	 */
	protected static $autoCache = false;          // off by default — explicit opt-in
	protected static $autoCacheInitialTtl = 5;    // start at 5s after first stable hit
	protected static $autoCacheMaxTtl = 3600;     // cap at 1 hour
	protected static $autoCacheGrowFactor = 2;    // double TTL each time content is stable
	protected static $autoCacheMinHits = 2;       // need 2 identical responses to start caching
	protected static $autoCacheMaxFingerprints = 10000;
	protected static $autoCachePatterns = array(); // URL patterns to always auto-cache
	protected static $autoCacheSkipPatterns = array(); // URL patterns to never auto-cache

	// Per-host auto-cache config overrides.
	// host => { enabled, initialTtl, maxTtl, growFactor, minHits,
	//           patterns, skipPatterns, framework }
	protected static $autoCachePerHost = array();

	// Stats
	protected static $autoCacheAdoptions = 0;     // URLs that graduated to cached
	protected static $autoCacheResets = 0;         // content changed, TTL reset

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

		$memory = Q::ifset($config, 'memory', array());
		self::$memMaxSize = (int) Q::ifset($memory, 'maxSize', 33554432);
		self::$memMaxEntries = (int) Q::ifset($memory, 'maxEntries', 1000);

		// Adaptive auto-caching for frameworks without cache headers
		$auto = Q::ifset($config, 'auto', array());
		self::$autoCache = (bool) Q::ifset($auto, 'enabled', false);
		self::$autoCacheInitialTtl = (int) Q::ifset($auto, 'initialTtl', 5);
		self::$autoCacheMaxTtl = (int) Q::ifset($auto, 'maxTtl', 3600);
		self::$autoCacheGrowFactor = (float) Q::ifset($auto, 'growFactor', 2);
		self::$autoCacheMinHits = (int) Q::ifset($auto, 'minHits', 2);
		self::$autoCacheMaxFingerprints = (int) Q::ifset($auto, 'maxFingerprints', 10000);
		self::$autoCachePatterns = Q::ifset($auto, 'patterns', array());
		self::$autoCacheSkipPatterns = Q::ifset($auto, 'skipPatterns', array());

		// Per-host auto-cache overrides from Q.webserver.domains.*.cache
		// and Q.webserver.hosts.*.cache. Each host can override:
		//   enabled, initialTtl, maxTtl, growFactor, minHits,
		//   patterns, skipPatterns, framework
		self::$autoCachePerHost = array();
		foreach (array('domains', 'hosts') as $section) {
			$entries = Q_Config::get('Q', 'webserver', $section, array());
			foreach ($entries as $hostname => $hconf) {
				$hcache = Q::ifset($hconf, 'cache', null);
				if ($hcache === null) continue;
				$hauto = Q::ifset($hcache, 'auto', null);
				if ($hauto === null) continue;
				$hostname = strtolower($hostname);
				// Apply framework preset if specified
				$framework = Q::ifset($hauto, 'framework', null);
				if ($framework) {
					$preset = self::frameworkPreset($framework);
					$hauto = array_merge($preset, $hauto);
				}
				self::$autoCachePerHost[$hostname] = $hauto;
			}
		}
	}

	/**
	 * Try to serve from cache. Returns response array or null.
	 *
	 * Called in the parent event loop BEFORE dispatching to a
	 * worker. A cache hit means zero fork overhead.
	 *
	 * Tries tiers in order: memory → APCu → filesystem.
	 * Promotes hits from slower tiers to faster ones.
	 *
	 * Supports stale-while-revalidate: if the entry is expired
	 * but within its SWR window, returns the stale entry and
	 * signals for background revalidation.
	 *
	 * @method get
	 * @static
	 * @param {array} $parsed Parsed request
	 * @return {array|null} Response array or null on miss
	 */
	static function get($parsed)
	{
		if (!self::$enabled) return null;
		if ($parsed['method'] !== 'GET') return null;

		// Skip cache if request has bypass cookies
		if (self::hasSkipCookie($parsed['headers'])) return null;

		$key = self::cacheKey($parsed);

		// Tier 1: In-memory (fastest — zero-copy, no serialization)
		$entry = self::memGet($key);
		if ($entry !== null) {
			$freshness = self::checkFreshness($entry);
			if ($freshness === 'fresh') {
				self::$hits++;
				$entry['headers']['X-Cache'] = !empty($entry['_auto']) ? 'HIT-AUTO' : 'HIT';
				return $entry;
			}
			if ($freshness === 'stale-swr') {
				self::$staleHits++;
				$entry['headers']['X-Cache'] = 'HIT-STALE';
				$entry['_revalidate'] = true;
				return $entry;
			}
			// expired past SWR window — evict
			self::memDelete($key);
		}

		// Tier 2: APCu (shared memory)
		if (self::$apcuEnabled) {
			$entry = apcu_fetch('qcache:' . $key);
			if ($entry !== false) {
				$freshness = self::checkFreshness($entry);
				if ($freshness === 'fresh') {
					self::$hits++;
					$entry['headers']['X-Cache'] = !empty($entry['_auto']) ? 'HIT-AUTO' : 'HIT';
					self::memPut($key, $entry); // promote
					return $entry;
				}
				if ($freshness === 'stale-swr') {
					self::$staleHits++;
					$entry['headers']['X-Cache'] = 'HIT-STALE';
					$entry['_revalidate'] = true;
					self::memPut($key, $entry);
					return $entry;
				}
				apcu_delete('qcache:' . $key);
			}
		}

		// Tier 3: Filesystem (survives restarts)
		$path = self::filePath($key);
		if ($path && file_exists($path)) {
			$entry = json_decode(file_get_contents($path), true);
			if ($entry) {
				$freshness = self::checkFreshness($entry);
				if ($freshness === 'fresh') {
					self::$hits++;
					$entry['headers']['X-Cache'] = !empty($entry['_auto']) ? 'HIT-AUTO' : 'HIT';
					self::memPut($key, $entry);
					if (self::$apcuEnabled && strlen($entry['body'] ?? '') <= self::$apcuMaxSize) {
						apcu_store('qcache:' . $key, $entry, self::ttlRemaining($entry));
					}
					return $entry;
				}
				if ($freshness === 'stale-swr') {
					self::$staleHits++;
					$entry['headers']['X-Cache'] = 'HIT-STALE';
					$entry['_revalidate'] = true;
					self::memPut($key, $entry);
					return $entry;
				}
				@unlink($path);
			}
		}

		self::$misses++;
		return null;
	}

	/**
	 * Store a response in cache if cacheable.
	 *
	 * Checks Cache-Control headers to determine TTL.
	 * Only caches GET responses with 200 status.
	 * Stores the URL in the entry for pattern-based purging.
	 * Records the Vary fields so future requests build correct keys.
	 *
	 * @method put
	 * @static
	 * @param {array} $parsed Request
	 * @param {array} $response Response with status, headers, body
	 */
	static function put($parsed, $response)
	{
		if (!self::$enabled) return;
		if ($parsed['method'] !== 'GET') return;
		if (($response['status'] ?? 200) !== 200) return;
		if (self::hasSkipCookie($parsed['headers'])) return;

		$headers = $response['headers'] ?? array();

		// If the Merkle component layer determined that the page output
		// is identical to what's already cached (false invalidation),
		// skip the expensive write. The existing cache entry is still valid.
		if (!empty($headers['X-Q-Cache-Unchanged'])) {
			unset($headers['X-Q-Cache-Unchanged']); // internal, don't leak
			return;
		}

		$cc = self::parseCacheControl($headers);

		// Don't cache if explicitly forbidden
		if (isset($cc['no-store']) || isset($cc['private'])) return;

		// no-cache means "store but always revalidate" — we cache it
		// with TTL=0 so the next request triggers revalidation.
		// For now, treat it as "don't cache in reverse proxy" since
		// we don't have upstream revalidation yet.
		if (isset($cc['no-cache'])) return;

		// Determine TTL
		$ttl = 0;
		if (isset($cc['s-maxage'])) {
			$ttl = (int) $cc['s-maxage'];
		} elseif (isset($cc['max-age'])) {
			$ttl = (int) $cc['max-age'];
		} elseif (self::$defaultTtl > 0) {
			$ttl = self::$defaultTtl;
		}

		$isAutoCached = false;
		if ($ttl <= 0) {
			// No cache headers from framework. Try adaptive auto-caching.
			// Check global setting OR per-host override
			$autoEnabled = self::$autoCache;
			if (!$autoEnabled) {
				$host = strtolower(preg_replace('/:\d+$/', '', $parsed['headers']['host'] ?? ''));
				$hostConf = self::$autoCachePerHost[$host] ?? null;
				if ($hostConf !== null && !empty($hostConf['enabled'])) {
					$autoEnabled = true;
				}
			}
			if ($autoEnabled) {
				$ttl = self::autoLearn($parsed, $response, $headers);
				if ($ttl > 0) $isAutoCached = true;
			}
			if ($ttl <= 0) return; // still nothing to cache
		}

		// Parse stale-while-revalidate window
		$swr = 0;
		if (isset($cc['stale-while-revalidate'])) {
			$swr = (int) $cc['stale-while-revalidate'];
		}

		// Record Vary fields for this URL (so future requests get correct keys)
		$varyFields = self::parseVaryHeader($headers);
		if (!empty($varyFields)) {
			$urlBase = self::urlBase($parsed);
			self::$varyIndex[$urlBase] = $varyFields;
		}

		$key = self::cacheKey($parsed);
		$body = $response['body'] ?? '';
		$expires = time() + $ttl;

		$entry = array(
			'status'  => $response['status'] ?? 200,
			'headers' => $headers,
			'body'    => $body,
			'expires' => $expires,
			'stored'  => time(),
			'url'     => ($parsed['headers']['host'] ?? '') . $parsed['path']
				. (!empty($parsed['query']) ? '?' . $parsed['query'] : ''),
			'swr'     => $swr > 0 ? $expires + $swr : 0,
		);
		if ($isAutoCached) {
			$entry['_auto'] = true; // flag for X-Cache: HIT-AUTO
		}

		// Tier 1: In-memory (always, if it fits)
		self::memPut($key, $entry);

		// Tier 2: APCu (shared memory, for small responses)
		if (self::$apcuEnabled && strlen($body) <= self::$apcuMaxSize) {
			apcu_store('qcache:' . $key, $entry, $ttl + $swr);
		}

		// Tier 3: Filesystem (survives restarts)
		$path = self::filePath($key);
		if ($path) {
			$dir = dirname($path);
			if (!is_dir($dir)) mkdir($dir, 0755, true);
			file_put_contents($path, json_encode($entry), LOCK_EX);
		}

		// Clear revalidation flag for this key
		unset(self::$revalidating[$key]);
	}

	/**
	 * Check whether a stale-while-revalidate response needs
	 * a background re-render. Returns true if the caller should
	 * trigger a worker dispatch (without waiting for it).
	 *
	 * @method needsRevalidation
	 * @static
	 * @param {array} $response The response from get()
	 * @return {boolean}
	 */
	static function needsRevalidation($response)
	{
		if (empty($response['_revalidate'])) return false;
		// Build key from the URL stored in the response
		$url = $response['url'] ?? '';
		$key = md5($url . '|' . self::encodingFromHeaders($response['headers'] ?? array()));
		if (isset(self::$revalidating[$key])) return false;
		self::$revalidating[$key] = true;
		return true;
	}

	/**
	 * Purge cache entries by cache key (direct, O(1)).
	 *
	 * Used by Q_WebServer_Cache_Components::invalidateStream() for
	 * targeted invalidation. Much faster than purge() which scans
	 * the filesystem.
	 *
	 * @method purgeByKey
	 * @static
	 * @param {string} $key The cache key (md5)
	 */
	static function purgeByKey($key)
	{
		// Memory tier
		self::memDelete($key);

		// APCu tier
		if (self::$apcuEnabled) {
			apcu_delete('qcache:' . $key);
		}

		// Filesystem tier
		$path = self::filePath($key);
		if ($path && file_exists($path)) {
			@unlink($path);
		}
	}

	/**
	 * Purge cache entries matching a URL pattern.
	 *
	 * For URL-pattern purging (regex or exact match against stored URLs).
	 * Scans the filesystem — use purgeByKey() for direct O(1) purge.
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
		// Purge from memory tier
		foreach (self::$mem as $key => $entry) {
			$url = $entry['url'] ?? '';
			if (self::matchesPattern($url, $pattern)) {
				self::memDelete($key);
			}
		}

		// Purge from filesystem + APCu
		if (!self::$dir || !is_dir(self::$dir)) return;

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(self::$dir, RecursiveDirectoryIterator::SKIP_DOTS)
		);
		foreach ($files as $file) {
			if ($file->getExtension() !== 'json') continue;
			$entry = json_decode(file_get_contents($file->getPathname()), true);
			if (!$entry) continue;

			$url = $entry['url'] ?? '';
			if (self::matchesPattern($url, $pattern)) {
				$key = basename($file->getPathname(), '.json');
				@unlink($file->getPathname());
				if (self::$apcuEnabled) {
					apcu_delete('qcache:' . $key);
				}
			}
		}
	}

	/**
	 * Purge ALL cache entries for a URL path (all Vary variants).
	 * Clears the vary index for this URL too.
	 *
	 * @method purgeUrl
	 * @static
	 * @param {string} $urlPath URL path (e.g. '/blog/my-post')
	 */
	static function purgeUrl($urlPath)
	{
		// Remove vary index for this URL
		unset(self::$varyIndex[$urlPath]);
		// Reset auto-cache fingerprint (content may have changed)
		unset(self::$fingerprints[$urlPath]);
		// Fall through to pattern purge
		self::purge($urlPath);
	}

	/**
	 * Clear all cached entries.
	 * @method clear
	 * @static
	 */
	static function clear()
	{
		// Memory tier
		self::$mem = array();
		self::$memSize = 0;

		// APCu tier
		if (self::$apcuEnabled) {
			if (class_exists('APCUIterator', false)) {
				$iterator = new APCUIterator('#^qcache:#');
				apcu_delete($iterator);
			}
		}

		// Filesystem tier
		if (self::$dir && is_dir(self::$dir)) {
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(self::$dir, RecursiveDirectoryIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ($files as $f) {
				$f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
			}
		}

		// Clear vary index
		self::$varyIndex = array();
		self::$revalidating = array();

		// Clear auto-cache fingerprints
		self::$fingerprints = array();
	}

	// ── Adaptive auto-caching ───────────────────────────

	/**
	 * Learn from a response that has no cache headers.
	 * Observes response stability and assigns a TTL when the content
	 * proves stable across multiple requests.
	 *
	 * Signals used:
	 *   - Response body hash: same hash N times → content is stable
	 *   - Set-Cookie: response sets cookies → likely stateful, skip
	 *   - Session cookie in request: logged-in user → response may be personal
	 *   - URL patterns: configurable always-cache / never-cache rules
	 *
	 * TTL grows exponentially: 5s → 10s → 20s → 40s → ... → maxTtl.
	 * If the body hash changes, TTL resets to zero (re-learn).
	 *
	 * @method autoLearn
	 * @static
	 * @param {array} $parsed Request
	 * @param {array} $response Response
	 * @param {array} $headers Response headers
	 * @return {integer} Inferred TTL (0 = don't cache)
	 */
	protected static function autoLearn($parsed, $response, $headers)
	{
		// Resolve per-host auto-cache config (merges over globals)
		$host = strtolower(preg_replace('/:\d+$/', '', $parsed['headers']['host'] ?? ''));
		$hostConf = self::$autoCachePerHost[$host] ?? null;

		// Per-host can disable auto-caching even if global is on
		if ($hostConf !== null && isset($hostConf['enabled']) && !$hostConf['enabled']) {
			return 0;
		}
		// Per-host can enable auto-caching even if global is off
		// (the put() caller already checked self::$autoCache, so if we got here
		//  via the global path it's on. But we also need to support per-host-only.)

		// Merge config: per-host overrides global
		$initialTtl = isset($hostConf['initialTtl'])
			? (int)$hostConf['initialTtl'] : self::$autoCacheInitialTtl;
		$maxTtl = isset($hostConf['maxTtl'])
			? (int)$hostConf['maxTtl'] : self::$autoCacheMaxTtl;
		$growFactor = isset($hostConf['growFactor'])
			? (float)$hostConf['growFactor'] : self::$autoCacheGrowFactor;
		$minHits = isset($hostConf['minHits'])
			? (int)$hostConf['minHits'] : self::$autoCacheMinHits;
		$patterns = isset($hostConf['patterns'])
			? (array)$hostConf['patterns'] : self::$autoCachePatterns;
		$skipPatterns = isset($hostConf['skipPatterns'])
			? (array)$hostConf['skipPatterns'] : self::$autoCacheSkipPatterns;

		// Don't auto-cache responses that set cookies — they're stateful
		foreach ($headers as $k => $v) {
			if (strtolower($k) === 'set-cookie') return 0;
		}

		// Don't auto-cache if response has Vary: Cookie and request has cookies
		// (per-user content that we can't safely generalize)
		$varyFields = self::parseVaryHeader($headers);
		if (in_array('cookie', $varyFields) && !empty($parsed['headers']['cookie'])) {
			return 0;
		}

		$urlBase = self::urlBase($parsed);
		$path = $parsed['path'] ?? '';

		// Check skip patterns first
		foreach ($skipPatterns as $pattern) {
			if (self::matchesPattern($path, $pattern)) return 0;
		}

		// Check force-cache patterns (bypass fingerprinting)
		$forceCache = false;
		foreach ($patterns as $pattern) {
			if (self::matchesPattern($path, $pattern)) {
				$forceCache = true;
				break;
			}
		}

		$body = $response['body'] ?? '';
		$hash = md5($body);
		$now = time();

		if (isset(self::$fingerprints[$urlBase])) {
			$fp = &self::$fingerprints[$urlBase];

			if ($fp['hash'] === $hash) {
				// Content is stable — same hash as last time
				$fp['hits']++;
				$fp['time'] = $now;

				if ($fp['hits'] >= $minHits || $forceCache) {
					// Graduate to cached. Grow TTL exponentially.
					if ($fp['ttl'] <= 0) {
						$fp['ttl'] = $initialTtl;
						self::$autoCacheAdoptions++;
					} else {
						$fp['ttl'] = min(
							(int) ($fp['ttl'] * $growFactor),
							$maxTtl
						);
					}
					return $fp['ttl'];
				}

				return 0; // not enough hits yet
			} else {
				// Content changed — reset
				$fp['hash'] = $hash;
				$fp['hits'] = 1;
				$fp['ttl'] = 0;
				$fp['time'] = $now;
				$fp['size'] = strlen($body);
				self::$autoCacheResets++;
				return $forceCache ? $initialTtl : 0;
			}
		}

		// First time seeing this URL — start fingerprinting
		// Evict oldest if over limit (PHP arrays are insertion-ordered)
		while (count(self::$fingerprints) >= self::$autoCacheMaxFingerprints
			&& self::$fingerprints
		) {
			$evict = array_key_first(self::$fingerprints);
			unset(self::$fingerprints[$evict]);
		}

		self::$fingerprints[$urlBase] = array(
			'hash' => $hash,
			'hits' => 1,
			'ttl'  => 0,
			'time' => $now,
			'size' => strlen($body),
		);

		return $forceCache ? $initialTtl : 0;
	}

	/**
	 * Called when a cached auto-learned entry expires and is re-rendered.
	 * If the new response matches the fingerprint, extend the TTL.
	 * If it differs, evict the fingerprint (content is no longer stable).
	 *
	 * This is called automatically from put() — the exponential TTL growth
	 * happens in autoLearn() which put() calls when there are no cache headers.
	 *
	 * @method autoInvalidate
	 * @static
	 * @param {string} $urlBase
	 */
	static function autoInvalidate($urlBase)
	{
		unset(self::$fingerprints[$urlBase]);
	}

	/**
	 * Get auto-cache fingerprint stats for the dashboard.
	 *
	 * @method autoCacheStats
	 * @static
	 * @return {array}
	 */
	static function autoCacheStats()
	{
		$cached = 0;
		$learning = 0;
		foreach (self::$fingerprints as $fp) {
			if ($fp['ttl'] > 0) $cached++;
			else $learning++;
		}
		return array(
			'enabled'     => self::$autoCache,
			'fingerprints' => count(self::$fingerprints),
			'cached'      => $cached,
			'learning'    => $learning,
			'adoptions'   => self::$autoCacheAdoptions,
			'resets'      => self::$autoCacheResets,
			'perHost'     => count(self::$autoCachePerHost),
		);
	}

	/**
	 * Set or update auto-cache config for a specific host at runtime.
	 *
	 * Call from Panel or config reload. Accepts the same keys as
	 * Q.webserver.domains.<host>.cache.auto in config:
	 *   enabled, initialTtl, maxTtl, growFactor, minHits,
	 *   patterns, skipPatterns, framework
	 *
	 * The "framework" key applies a preset of sensible defaults:
	 *   wordpress  — skip wp-admin, wp-login; cache wp-content
	 *   drupal     — skip admin, user; cache sites/default/files
	 *   laravel    — skip api, nova; cache build, vendor
	 *   symfony    — skip _profiler, admin
	 *   joomla     — skip administrator
	 *   qbix       — skip Q/action.php, Q/api; cache plugin assets
	 *   static     — cache everything (initialTtl=60, maxTtl=86400)
	 *
	 * Framework presets merge UNDER explicit config, so you can
	 * override any preset defaults with explicit keys.
	 *
	 * @method configureHost
	 * @static
	 * @param {string} $host  Hostname (lowercased, no port)
	 * @param {array}  $config  Auto-cache config overrides
	 */
	static function configureHost($host, $config)
	{
		$host = strtolower($host);

		// Apply framework preset as base, then overlay explicit config
		$framework = $config['framework'] ?? null;
		if ($framework) {
			$preset = self::frameworkPreset($framework);
			// Merge: explicit config wins over preset
			$config = array_merge($preset, $config);
		}

		self::$autoCachePerHost[$host] = $config;
	}

	/**
	 * Get the auto-cache config for a specific host, or null if
	 * no per-host config exists.
	 *
	 * @method getHostConfig
	 * @static
	 * @param {string} $host  Hostname (lowercased, no port)
	 * @return {array|null}
	 */
	static function getHostConfig($host)
	{
		$host = strtolower($host);
		return self::$autoCachePerHost[$host] ?? null;
	}

	/**
	 * Return the auto-cache preset for a framework.
	 *
	 * Each preset provides sensible skipPatterns (admin, API, login)
	 * and patterns (static assets, public content paths) so you can
	 * enable auto-caching for e.g. WordPress with just:
	 *   { "enabled": true, "framework": "wordpress" }
	 *
	 * @method frameworkPreset
	 * @static
	 * @param {string} $name  Framework name
	 * @return {array}  Config array suitable for merging
	 */
	static function frameworkPreset($name)
	{
		static $presets = array(
			'wordpress' => array(
				'enabled' => true,
				'skipPatterns' => array(
					'/wp-admin/*', '/wp-login.php', '/wp-cron.php',
					'/wp-json/*', '/xmlrpc.php',
				),
				'patterns' => array(
					'/wp-content/uploads/*', '/wp-content/themes/*/style.css',
					'/wp-includes/js/*', '/wp-includes/css/*',
				),
				'initialTtl' => 10,
				'maxTtl' => 3600,
				'minHits' => 2,
			),
			'drupal' => array(
				'enabled' => true,
				'skipPatterns' => array(
					'/admin/*', '/user/*', '/user', '/batch*',
					'/jsonapi/*', '/system/*',
				),
				'patterns' => array(
					'/sites/default/files/*', '/core/assets/*',
					'/themes/*/css/*', '/themes/*/js/*',
				),
				'initialTtl' => 10,
				'maxTtl' => 3600,
				'minHits' => 2,
			),
			'laravel' => array(
				'enabled' => true,
				'skipPatterns' => array(
					'/api/*', '/nova/*', '/horizon/*', '/telescope/*',
					'/sanctum/*', '/broadcasting/*', '/_ignition/*',
				),
				'patterns' => array(
					'/build/*', '/vendor/*', '/storage/*',
				),
				'initialTtl' => 5,
				'maxTtl' => 1800,
				'minHits' => 2,
			),
			'symfony' => array(
				'enabled' => true,
				'skipPatterns' => array(
					'/_profiler/*', '/_wdt/*', '/admin/*',
					'/api/*',
				),
				'patterns' => array(
					'/bundles/*', '/build/*',
				),
				'initialTtl' => 5,
				'maxTtl' => 1800,
				'minHits' => 2,
			),
			'joomla' => array(
				'enabled' => true,
				'skipPatterns' => array(
					'/administrator/*', '/api/*',
				),
				'patterns' => array(
					'/media/*', '/templates/*/css/*', '/templates/*/js/*',
				),
				'initialTtl' => 10,
				'maxTtl' => 3600,
				'minHits' => 2,
			),
			'qbix' => array(
				'enabled' => true,
				'skipPatterns' => array(
					'/Q/action.php/*', '/Q/api/*',
				),
				'patterns' => array(
					'/Q/plugins/*/css/*', '/Q/plugins/*/js/*',
					'/Q/plugins/*/img/*',
				),
				'initialTtl' => 5,
				'maxTtl' => 3600,
				'minHits' => 2,
			),
			'static' => array(
				'enabled' => true,
				'skipPatterns' => array(),
				'patterns' => array('/*'),
				'initialTtl' => 60,
				'maxTtl' => 86400,
				'minHits' => 1,
			),
		);

		return $presets[strtolower($name)] ?? array();
	}

	// ── Internals ────────────────────────────────────────

	/**
	 * Generate a cache key from a request.
	 *
	 * Includes host + path + query + Vary header values.
	 * Uses the vary index to know which request headers to include.
	 * Always includes Accept-Encoding (standard practice).
	 *
	 * @method cacheKey
	 * @static
	 * @param {array} $parsed Parsed request
	 * @return {string} md5 cache key
	 */
	static function cacheKey($parsed)
	{
		$host = $parsed['headers']['host'] ?? '';
		$parts = $host . $parsed['path'] . '?' . ($parsed['query'] ?? '');

		// Always vary by Accept-Encoding
		$ae = $parsed['headers']['accept-encoding'] ?? '';
		if (strpos($ae, 'br') !== false) {
			$parts .= '|br';
		} elseif (strpos($ae, 'gzip') !== false) {
			$parts .= '|gzip';
		}

		// Include Vary fields from the vary index
		$urlBase = self::urlBase($parsed);
		if (isset(self::$varyIndex[$urlBase])) {
			foreach (self::$varyIndex[$urlBase] as $field) {
				if ($field === 'accept-encoding') continue; // already handled
				$val = $parsed['headers'][$field] ?? '';
				$parts .= '|' . $field . '=' . $val;
			}
		}

		return md5($parts);
	}

	/**
	 * Build the URL base key for the vary index.
	 * @param {array} $parsed
	 * @return {string}
	 */
	protected static function urlBase($parsed)
	{
		return ($parsed['headers']['host'] ?? '') . $parsed['path'];
	}

	/**
	 * Parse the Vary response header into lowercase field names.
	 * @param {array} $headers
	 * @return {array} lowercase header names
	 */
	protected static function parseVaryHeader($headers)
	{
		$vary = '';
		foreach ($headers as $k => $v) {
			if (strtolower($k) === 'vary') { $vary = $v; break; }
		}
		if (!$vary || $vary === '*') return array();

		$fields = array();
		foreach (explode(',', $vary) as $f) {
			$f = strtolower(trim($f));
			if ($f && $f !== '*') $fields[] = $f;
		}
		return $fields;
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
	 * Check freshness of a cache entry.
	 * @return {string} 'fresh', 'stale-swr', or 'expired'
	 */
	protected static function checkFreshness($entry)
	{
		$now = time();
		$expires = $entry['expires'] ?? 0;

		// Never expires (TTL=0 stored as expires=0 is for permanent entries)
		if ($expires === 0) return 'fresh';

		// Still fresh
		if ($expires > $now) return 'fresh';

		// Expired but within stale-while-revalidate window
		$swr = $entry['swr'] ?? 0;
		if ($swr > 0 && $swr > $now) return 'stale-swr';

		return 'expired';
	}

	/**
	 * Get encoding string from response headers (for key building).
	 */
	protected static function encodingFromHeaders($headers)
	{
		$ce = '';
		foreach ($headers as $k => $v) {
			if (strtolower($k) === 'content-encoding') { $ce = $v; break; }
		}
		if (strpos($ce, 'br') !== false) return 'br';
		if (strpos($ce, 'gzip') !== false) return 'gzip';
		return 'id';
	}

	/**
	 * Check if a URL matches a pattern (exact or regex).
	 */
	protected static function matchesPattern($url, $pattern)
	{
		if (!$pattern) return false;
		$isRegex = (strlen($pattern) > 2 && $pattern[0] === $pattern[strlen($pattern)-1])
			|| (strlen($pattern) > 2 && $pattern[0] === '#');
		return $isRegex ? (bool) preg_match($pattern, $url) : ($url === $pattern);
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
		if (($entry['expires'] ?? 0) <= 0) return 86400;
		return max(1, $entry['expires'] - time());
	}

	// ── In-memory tier operations ────────────────────────

	protected static function memGet($key)
	{
		return self::$mem[$key] ?? null;
	}

	protected static function memPut($key, $entry)
	{
		$bodyLen = strlen($entry['body'] ?? '');

		// Don't cache entries larger than 1/4 of max (prevent one big entry
		// from evicting everything)
		if ($bodyLen > self::$memMaxSize / 4) return;

		// Evict old entry for this key first
		if (isset(self::$mem[$key])) {
			self::$memSize -= strlen(self::$mem[$key]['body'] ?? '');
			unset(self::$mem[$key]);
		}

		// Evict oldest entries if over limits
		while ((self::$memSize + $bodyLen > self::$memMaxSize
			|| count(self::$mem) >= self::$memMaxEntries) && self::$mem) {
			$evict = array_key_first(self::$mem);
			self::$memSize -= strlen(self::$mem[$evict]['body'] ?? '');
			unset(self::$mem[$evict]);
		}

		self::$mem[$key] = $entry;
		self::$memSize += $bodyLen;
	}

	protected static function memDelete($key)
	{
		if (isset(self::$mem[$key])) {
			self::$memSize -= strlen(self::$mem[$key]['body'] ?? '');
			unset(self::$mem[$key]);
		}
	}

	// ── Stats ───────────────────────────────────────────

	/**
	 * Stats for the dashboard.
	 */
	static function stats()
	{
		$total = self::$hits + self::$staleHits + self::$misses;
		$stats = array(
			'hits'       => self::$hits,
			'staleHits'  => self::$staleHits,
			'misses'     => self::$misses,
			'hitRate'    => $total > 0 ? round((self::$hits + self::$staleHits) / $total * 100, 1) : 0,
			'memory'     => array(
				'entries' => count(self::$mem),
				'bytes'   => self::$memSize,
				'maxBytes' => self::$memMaxSize,
			),
			'varyUrls'   => count(self::$varyIndex),
			'revalidating' => count(self::$revalidating),
		);
		if (self::$autoCache) {
			$stats['autoCache'] = self::autoCacheStats();
		}
		return $stats;
	}
}
