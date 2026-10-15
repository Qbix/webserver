<?php
/**
 * @module Q
 */

/**
 * Merkle-tree invalidation layer for Q_WebServer_Cache.
 *
 * Stores Merkle hashes, dependencies, AND individual slot HTML fragments.
 * The actual cached page lives in Q_WebServer_Cache (page-level).
 * This layer answers two questions:
 *   1. "Is this cached page still valid?" (Merkle tree invalidation)
 *   2. "Which parts changed?" (tree diff + slot cache for partial re-rendering)
 *
 * Better than ESI/SSI because:
 *   - Full assembled page cached as one string (Q_WebServer_Cache)
 *   - Only hashes (~100 bytes/page) for invalidation decisions
 *   - Slot-level cache enables partial re-rendering (only re-render changed slots)
 *   - Tree diff detects false invalidations (stream changed but output identical)
 *   - Stream-driven invalidation: change a stream → invalidate specific pages
 *   - Children communicate via response headers, not wire protocol
 *   - All in parent process memory — no edge proxy, no parsing
 *
 * How it works:
 *
 *   1. Child renders a page. Q_Response::fillSlot() tracks which slots
 *      were rendered and which streams each slot read from. After rendering,
 *      the child sets response headers:
 *
 *        X-Q-Cache-Tree: {"t":{"da":{"av":"a3f2","nt":"b8c1"},"co":{"fe":"d4e5","mb":"f6a7","sb":"c8d9"}},"h":"root_hash"}
 *        X-Q-Cache-Deps: {"co.fe":["community/feed/456"],"co.mb":["community/participants/456"],"da.av":["Users/avatar/123"]}
 *
 *   2. Parent receives response, caches it in Q_WebServer_Cache (full page),
 *      and stores the Merkle tree + deps here (hashes only, ~200 bytes).
 *
 *   3. When a stream changes (child sends X-Q-Cache-Invalidate header,
 *      or WebSocket message, or explicit API call):
 *        - Look up dependency index: stream → [pageKey, leafPath]
 *        - Remove the page from Q_WebServer_Cache
 *        - Mark the Merkle leaf as stale (optional: for partial re-render hints)
 *
 *   4. Next request for this page: cache miss → fork worker with stale hints
 *      + cached slot hashes. The worker knows which slots are stale and which
 *      have cached HTML it can reuse. Only stale slots need re-rendering.
 *
 *   5. Worker returns new tree. Parent diffs new tree against old:
 *        - Root matches → false invalidation! Skip Cache::put(), restore old entry.
 *        - Root differs → cache the new page, store changed slot HTML in slot cache.
 *      This makes most stream invalidations near-zero-cost when they don't
 *      actually affect visible output.
 *
 * Config:
 *   "Q": { "web": { "cache": { "components": {
 *     "enabled": true,
 *     "maxTrees": 10000,
 *     "slots": {
 *       "maxSize": 16777216,
 *       "maxEntries": 5000
 *     }
 *   }}}}
 *
 * @class Q_WebServer_Cache_Components
 */
class Q_WebServer_Cache_Components
{
	// ── State (parent process memory) ───────────────────

	/**
	 * Merkle trees: pageKey => tree
	 * A tree is: { 'hash' => rootHash, 'leaves' => { 'path' => hash, ... } }
	 * Compact — no HTML, no children structure. Just leaf hashes + root.
	 * The hierarchy is encoded in dot-separated leaf paths.
	 * @property $trees
	 */
	protected static $trees = array();

	/**
	 * Forward dependency index: streamKey => [ [pageKey, leafPath], ... ]
	 * @property $deps
	 */
	protected static $deps = array();

	/**
	 * Reverse index: pageKey => [ streamKey, ... ]
	 * For cleanup when a page tree is evicted.
	 * @property $pageStreams
	 */
	protected static $pageStreams = array();

	/**
	 * Stale leaves: pageKey => [ leafPath, ... ]
	 * After invalidation, records which leaves changed so the next
	 * render can optionally skip unchanged slots.
	 * @property $staleLeaves
	 */
	protected static $staleLeaves = array();

	/**
	 * Slot-level cache: leafHash => slotHTML
	 * Caches individual slot HTML by their content hash. When a page
	 * is re-rendered, unchanged slots (same hash as before) can reuse
	 * their cached HTML instead of re-rendering. This is what makes
	 * the Merkle tree an incremental page assembler, not just an
	 * invalidation index.
	 *
	 * Unlike the page cache which stores complete HTTP responses,
	 * this stores just the HTML fragment for each slot — typically
	 * a few KB each. Combined with the tree diff, this enables
	 * partial page assembly: only re-render the stale slots, reuse
	 * the rest from this cache.
	 * @property $slotCache
	 */
	protected static $slotCache = array();
	protected static $slotCacheSize = 0;

	// ── Stats ───────────────────────────────────────────

	protected static $invalidations = 0;
	protected static $pagesInvalidated = 0;
	protected static $falseInvalidations = 0;    // stream changed but page output identical
	protected static $slotCacheHits = 0;
	protected static $slotCacheMisses = 0;
	protected static $partialRenders = 0;         // pages rebuilt from mix of cached + fresh slots

	// ── Config ──────────────────────────────────────────

	protected static $enabled = false;
	protected static $maxTrees = 10000;
	protected static $slotCacheMaxSize = 16777216; // 16MB for slot fragments
	protected static $slotCacheMaxEntries = 5000;

	/**
	 * Initialize from config.
	 */
	static function init()
	{
		$config = Q_Config::get('Q', 'web', 'cache', 'components', array());
		self::$enabled = (bool) Q::ifset($config, 'enabled', false);
		self::$maxTrees = (int) Q::ifset($config, 'maxTrees', 10000);
		$slots = Q::ifset($config, 'slots', array());
		self::$slotCacheMaxSize = (int) Q::ifset($slots, 'maxSize', 16777216);
		self::$slotCacheMaxEntries = (int) Q::ifset($slots, 'maxEntries', 5000);
	}

	static function enabled()
	{
		return self::$enabled;
	}

	// ── Process response headers from child ─────────────

	/**
	 * Called by the parent after receiving a response from a worker.
	 * Extracts X-Q-Cache-Tree, X-Q-Cache-Deps, and X-Q-Cache-Invalidate
	 * headers. Strips them from the response (they're internal).
	 *
	 * @method processResponseHeaders
	 * @static
	 * @param {string} $pageKey Cache key for this page
	 * @param {array} &$headers Response headers (modified in place — internal headers removed)
	 */
	static function processResponseHeaders($pageKey, &$headers)
	{
		if (!self::$enabled) return;

		// 1. Handle invalidations first (from POST/write requests)
		$invalidateHeader = self::extractHeader($headers, 'X-Q-Cache-Invalidate');
		if ($invalidateHeader) {
			$streams = json_decode($invalidateHeader, true);
			if (is_array($streams)) {
				self::invalidateStreams($streams);
			}
		}

		// 2. Register tree from GET response
		$treeHeader = self::extractHeader($headers, 'X-Q-Cache-Tree');
		$depsHeader = self::extractHeader($headers, 'X-Q-Cache-Deps');

		if ($treeHeader) {
			$tree = json_decode($treeHeader, true);
			if (is_array($tree)) {
				// Check for false invalidation: if the new tree's root matches
				// the old one, the page output is identical despite the stream
				// change. This can save an expensive Cache::put().
				$slotHtml = null;
				$slotsHeader = self::extractHeader($headers, 'X-Q-Cache-Slots');
				if ($slotsHeader) {
					$slotHtml = json_decode($slotsHeader, true);
				}
				$rerenderResult = self::processRerender($pageKey, $tree, $slotHtml);

				// Signal to caller: if identical, set a flag so Cache::put()
				// can be skipped (the old cached response is still valid)
				if ($rerenderResult['identical']) {
					$headers['X-Q-Cache-Unchanged'] = '1';
				}

				self::registerTree($pageKey, $tree);
			}
		}

		if ($depsHeader) {
			$deps = json_decode($depsHeader, true);
			if (is_array($deps)) {
				self::registerDeps($pageKey, $deps);
			}
		}
	}

	/**
	 * Extract and remove an internal header from the response.
	 * Returns the value or null.
	 */
	protected static function extractHeader(&$headers, $name)
	{
		$lower = strtolower($name);
		foreach ($headers as $k => $v) {
			if (strtolower($k) === $lower) {
				unset($headers[$k]);
				return $v;
			}
		}
		return null;
	}

	// ── Tree registration ───────────────────────────────

	/**
	 * Register a Merkle tree from a child's response.
	 *
	 * Tree format (JSON from X-Q-Cache-Tree header):
	 *   { "h": "root_hash", "l": { "title": "abc", "content.feed": "def", ... } }
	 *
	 * "h" = root hash (md5 of concatenated leaf hashes)
	 * "l" = leaf hashes, keyed by dot-separated path
	 *
	 * @param {string} $pageKey
	 * @param {array} $tree Decoded JSON
	 */
	static function registerTree($pageKey, $tree)
	{
		// Evict old tree if exists (cleans up deps)
		if (isset(self::$trees[$pageKey])) {
			self::evictTree($pageKey);
		}

		self::$trees[$pageKey] = array(
			'hash'   => $tree['h'] ?? self::computeRoot($tree['l'] ?? array()),
			'leaves' => $tree['l'] ?? array(),
			'time'   => time(),
		);

		// Clear any stale markers (we have a fresh render)
		unset(self::$staleLeaves[$pageKey]);

		// Evict oldest if over limit
		while (count(self::$trees) > self::$maxTrees) {
			$oldest = array_key_first(self::$trees);
			if ($oldest === null || $oldest === $pageKey) break;
			self::evictTree($oldest);
		}
	}

	/**
	 * Register dependencies from a child's response.
	 *
	 * Deps format (JSON from X-Q-Cache-Deps header):
	 *   { "content.feed": ["community/feed/456"], "da.av": ["Users/avatar/123"], ... }
	 *
	 * Keys = leaf paths, values = arrays of stream keys that leaf reads from.
	 *
	 * @param {string} $pageKey
	 * @param {array} $deps Decoded JSON
	 */
	static function registerDeps($pageKey, $deps)
	{
		$allStreams = array();

		foreach ($deps as $leafPath => $streamKeys) {
			foreach ($streamKeys as $streamKey) {
				// Forward index
				if (!isset(self::$deps[$streamKey])) {
					self::$deps[$streamKey] = array();
				}
				self::$deps[$streamKey][] = array($pageKey, $leafPath);
				$allStreams[$streamKey] = true;
			}
		}

		// Reverse index for cleanup
		self::$pageStreams[$pageKey] = array_keys($allStreams);
	}

	// ── Invalidation ────────────────────────────────────

	/**
	 * Invalidate all pages that depend on a stream.
	 *
	 * @method invalidateStream
	 * @static
	 * @param {string} $streamKey e.g. 'Streams/avatar/123'
	 */
	static function invalidateStream($streamKey)
	{
		if (!isset(self::$deps[$streamKey])) return;

		self::$invalidations++;
		$pagesHit = array();

		foreach (self::$deps[$streamKey] as $dep) {
			list($pageKey, $leafPath) = $dep;

			if (!isset($pagesHit[$pageKey])) {
				$pagesHit[$pageKey] = true;

				// Purge from page-level cache using URL pattern match.
				// pageKey is "path?query" — purge all variants
				// (different Vary combinations) for this URL.
				Q_WebServer_Cache::purgeUrl($pageKey);
				self::$pagesInvalidated++;
			}

			// Record which leaf is stale (hint for partial re-render)
			if (!isset(self::$staleLeaves[$pageKey])) {
				self::$staleLeaves[$pageKey] = array();
			}
			if (!in_array($leafPath, self::$staleLeaves[$pageKey])) {
				self::$staleLeaves[$pageKey][] = $leafPath;
			}

			// Mark leaf hash as stale in tree
			if (isset(self::$trees[$pageKey]['leaves'][$leafPath])) {
				self::$trees[$pageKey]['leaves'][$leafPath] = null; // stale
				self::$trees[$pageKey]['hash'] = null; // root invalid
			}
		}
	}

	/**
	 * Invalidate multiple streams.
	 *
	 * @method invalidateStreams
	 * @static
	 * @param {array} $streamKeys
	 */
	static function invalidateStreams($streamKeys)
	{
		foreach ($streamKeys as $key) {
			self::invalidateStream($key);
		}
	}

	// ── Query ───────────────────────────────────────────

	/**
	 * Check if a cached page is still valid according to the Merkle tree.
	 *
	 * If the tree has been invalidated (any leaf marked stale), returns null
	 * to signal a cache miss. If the tree is intact, delegates to the
	 * page-level cache (Q_WebServer_Cache) to retrieve the full page.
	 *
	 * This is called from WebServer BEFORE worker dispatch. A hit here
	 * means we can serve the cached page with zero fork overhead AND
	 * with Merkle-validated freshness (not just TTL).
	 *
	 * @method getPage
	 * @static
	 * @param {string} $pageKey The page cache key (path?query)
	 * @return {string|null} Cached page body, or null on miss/stale
	 */
	static function getPage($pageKey)
	{
		if (!self::$enabled) return null;

		// If any leaf is stale, the page is invalid
		if (!empty(self::$staleLeaves[$pageKey])) return null;

		// If we have a tree and its root hash is null (invalidated), miss
		if (isset(self::$trees[$pageKey]) && self::$trees[$pageKey]['hash'] === null) {
			return null;
		}

		// Delegate to page-level cache — build a minimal parsed request
		// to look up the entry. The pageKey is path?query.
		$qpos = strpos($pageKey, '?');
		$parsed = array(
			'method'  => 'GET',
			'path'    => $qpos !== false ? substr($pageKey, 0, $qpos) : $pageKey,
			'query'   => $qpos !== false ? substr($pageKey, $qpos + 1) : '',
			'headers' => array(),
		);
		$entry = Q_WebServer_Cache::get($parsed);
		if ($entry) {
			return $entry['body'] ?? null;
		}
		return null;
	}

	/**
	 * Check if a page's Merkle root still matches what we have.
	 * Called optionally — the main cache layer (Q_WebServer_Cache)
	 * already handles TTL-based expiry. This is for instant invalidation.
	 *
	 * @method isValid
	 * @static
	 * @param {string} $pageKey
	 * @param {string} $rootHash The root hash to check against
	 * @return {boolean} true if the tree exists and the root matches
	 */
	static function isValid($pageKey, $rootHash)
	{
		if (!isset(self::$trees[$pageKey])) return true; // no tree = no opinion
		return self::$trees[$pageKey]['hash'] === $rootHash;
	}

	/**
	 * Get the list of stale leaves for a page.
	 * The child can use this to skip re-rendering unchanged slots.
	 *
	 * @method getStaleLeaves
	 * @static
	 * @param {string} $pageKey
	 * @return {array} Leaf paths that changed since last render
	 */
	static function getStaleLeaves($pageKey)
	{
		return self::$staleLeaves[$pageKey] ?? array();
	}

	/**
	 * Check if a specific leaf is stale.
	 *
	 * @method isLeafStale
	 * @static
	 * @param {string} $pageKey
	 * @param {string} $leafPath
	 * @return {boolean}
	 */
	static function isLeafStale($pageKey, $leafPath)
	{
		if (!isset(self::$staleLeaves[$pageKey])) return false;
		return in_array($leafPath, self::$staleLeaves[$pageKey]);
	}

	// ── Hints to child ──────────────────────────────────

	/**
	 * Build a header value telling the child which slots are stale.
	 * The child can set this on the request when dispatching to a worker.
	 *
	 * @method buildStaleHintsHeader
	 * @static
	 * @param {string} $pageKey
	 * @return {string|null} JSON array of stale leaf paths, or null if none
	 */
	static function buildStaleHintsHeader($pageKey)
	{
		$stale = self::$staleLeaves[$pageKey] ?? array();
		return !empty($stale) ? json_encode($stale) : null;
	}

	// ── Tree diff and slot caching ──────────────────────

	/**
	 * Compare a newly received tree against the old one for a page.
	 * Returns a diff describing what changed.
	 *
	 * This is the key to using the Merkle tree for speed:
	 *   - If rootHash matches → page output is identical (false invalidation)
	 *   - If rootHash differs → compare leaf-by-leaf to find which slots changed
	 *   - Unchanged slots can reuse cached HTML from the slot cache
	 *
	 * @method diffTree
	 * @static
	 * @param {string} $pageKey
	 * @param {array} $newTree Decoded X-Q-Cache-Tree from the worker
	 * @return {array} {
	 *   'identical' => bool,        // true = page didn't actually change
	 *   'changed'  => [leafPath],   // slots whose hash changed
	 *   'added'    => [leafPath],   // new slots not in old tree
	 *   'removed'  => [leafPath],   // old slots not in new tree
	 *   'unchanged' => [leafPath],  // slots with same hash (reusable)
	 * }
	 */
	static function diffTree($pageKey, $newTree)
	{
		$newLeaves = $newTree['l'] ?? array();
		$newRoot = $newTree['h'] ?? self::computeRoot($newLeaves);

		$result = array(
			'identical' => false,
			'changed'   => array(),
			'added'     => array(),
			'removed'   => array(),
			'unchanged' => array(),
		);

		// No old tree — everything is new
		if (!isset(self::$trees[$pageKey])) {
			$result['added'] = array_keys($newLeaves);
			return $result;
		}

		$oldTree = self::$trees[$pageKey];

		// Root hash match → page output is identical despite invalidation
		if ($oldTree['hash'] !== null && $oldTree['hash'] === $newRoot) {
			$result['identical'] = true;
			$result['unchanged'] = array_keys($newLeaves);
			return $result;
		}

		$oldLeaves = $oldTree['leaves'] ?? array();

		// Leaf-by-leaf comparison
		foreach ($newLeaves as $path => $hash) {
			if (!isset($oldLeaves[$path])) {
				$result['added'][] = $path;
			} elseif ($oldLeaves[$path] !== null && $oldLeaves[$path] === $hash) {
				$result['unchanged'][] = $path;
			} else {
				$result['changed'][] = $path;
			}
		}

		// Old leaves not in new tree
		foreach ($oldLeaves as $path => $_) {
			if (!isset($newLeaves[$path])) {
				$result['removed'][] = $path;
			}
		}

		return $result;
	}

	/**
	 * Process a re-render response using tree diff.
	 *
	 * Called after a worker re-renders a page. If the tree diff shows the
	 * page is identical (false invalidation), returns true to signal that
	 * Cache::put() can be skipped — the old cached response is still valid
	 * and can be restored.
	 *
	 * Also registers slot HTML in the slot cache for unchanged-leaf reuse.
	 *
	 * @method processRerender
	 * @static
	 * @param {string} $pageKey
	 * @param {array} $newTree Decoded X-Q-Cache-Tree
	 * @param {array|null} $slotHtml Optional: leafPath => HTML from X-Q-Cache-Slots header
	 * @return {array} { 'identical' => bool, 'diff' => diffResult }
	 */
	static function processRerender($pageKey, $newTree, $slotHtml = null)
	{
		$diff = self::diffTree($pageKey, $newTree);

		if ($diff['identical']) {
			// False invalidation — stream changed but page output is identical.
			// Restore the tree as valid and clear stale markers.
			self::$falseInvalidations++;
			if (isset(self::$trees[$pageKey])) {
				$newLeaves = $newTree['l'] ?? array();
				self::$trees[$pageKey]['hash'] = $newTree['h']
					?? self::computeRoot($newLeaves);
				// Restore leaf hashes that were nulled during invalidation
				foreach ($newLeaves as $path => $hash) {
					self::$trees[$pageKey]['leaves'][$path] = $hash;
				}
			}
			unset(self::$staleLeaves[$pageKey]);
			return array('identical' => true, 'diff' => $diff);
		}

		// Cache individual slot HTML for future partial assembly
		if (is_array($slotHtml)) {
			$newLeaves = $newTree['l'] ?? array();
			foreach ($slotHtml as $leafPath => $html) {
				$hash = $newLeaves[$leafPath] ?? null;
				if ($hash !== null) {
					self::slotPut($hash, $html);
				}
			}
		}

		if (!empty($diff['unchanged'])) {
			self::$partialRenders++;
		}

		return array('identical' => false, 'diff' => $diff);
	}

	/**
	 * Get cached HTML for a slot by its leaf hash.
	 * Returns null on miss.
	 *
	 * Workers can call this (via a request header or API) to skip
	 * re-rendering a slot whose hash hasn't changed.
	 *
	 * @method slotGet
	 * @static
	 * @param {string} $hash The leaf hash (md5 of slot content)
	 * @return {string|null}
	 */
	static function slotGet($hash)
	{
		if (isset(self::$slotCache[$hash])) {
			self::$slotCacheHits++;
			return self::$slotCache[$hash];
		}
		self::$slotCacheMisses++;
		return null;
	}

	/**
	 * Store slot HTML by its leaf hash.
	 *
	 * @method slotPut
	 * @static
	 * @param {string} $hash
	 * @param {string} $html
	 */
	static function slotPut($hash, $html)
	{
		$len = strlen($html);

		// Don't cache single slots larger than 1/4 of max
		if ($len > self::$slotCacheMaxSize / 4) return;

		// Evict if already present
		if (isset(self::$slotCache[$hash])) {
			self::$slotCacheSize -= strlen(self::$slotCache[$hash]);
			unset(self::$slotCache[$hash]);
		}

		// Evict oldest until we have room
		while ((self::$slotCacheSize + $len > self::$slotCacheMaxSize
			|| count(self::$slotCache) >= self::$slotCacheMaxEntries)
			&& self::$slotCache
		) {
			$evict = array_key_first(self::$slotCache);
			self::$slotCacheSize -= strlen(self::$slotCache[$evict]);
			unset(self::$slotCache[$evict]);
		}

		self::$slotCache[$hash] = $html;
		self::$slotCacheSize += $len;
	}

	/**
	 * Build a header telling the worker which slots have cached HTML.
	 * The worker can skip rendering these slots and use the cached version.
	 *
	 * Format: JSON object { leafPath: cachedHash, ... } for each leaf
	 * that has a cached slot AND whose hash matches what we have stored.
	 *
	 * @method buildCachedSlotsHeader
	 * @static
	 * @param {string} $pageKey
	 * @return {string|null} JSON or null if no cached slots available
	 */
	static function buildCachedSlotsHeader($pageKey)
	{
		if (!isset(self::$trees[$pageKey])) return null;

		$leaves = self::$trees[$pageKey]['leaves'] ?? array();
		$stale = self::$staleLeaves[$pageKey] ?? array();
		$cached = array();

		foreach ($leaves as $path => $hash) {
			// Skip stale leaves — those need re-rendering
			if (in_array($path, $stale)) continue;
			// Check if we have the slot HTML cached
			if ($hash !== null && isset(self::$slotCache[$hash])) {
				$cached[$path] = $hash;
			}
		}

		return !empty($cached) ? json_encode($cached) : null;
	}

	// ── Cleanup ─────────────────────────────────────────

	/**
	 * Remove a page's tree and clean up all its dependency entries.
	 */
	protected static function evictTree($pageKey)
	{
		// Remove from forward deps
		if (isset(self::$pageStreams[$pageKey])) {
			foreach (self::$pageStreams[$pageKey] as $streamKey) {
				if (isset(self::$deps[$streamKey])) {
					self::$deps[$streamKey] = array_values(array_filter(
						self::$deps[$streamKey],
						function ($d) use ($pageKey) { return $d[0] !== $pageKey; }
					));
					if (empty(self::$deps[$streamKey])) {
						unset(self::$deps[$streamKey]);
					}
				}
			}
			unset(self::$pageStreams[$pageKey]);
		}

		unset(self::$trees[$pageKey], self::$staleLeaves[$pageKey]);
	}

	// ── Merkle computation ──────────────────────────────

	/**
	 * Compute root hash from leaf hashes.
	 * Deterministic: sorts by path, concatenates "path:hash", md5s the result.
	 *
	 * @param {array} $leaves path => hash pairs
	 * @return {string} root hash
	 */
	protected static function computeRoot($leaves)
	{
		if (empty($leaves)) return md5('');
		ksort($leaves);
		$concat = '';
		foreach ($leaves as $path => $hash) {
			$concat .= $path . ':' . ($hash ?? 'null') . "\n";
		}
		return md5($concat);
	}

	// ── Wire protocol (legacy support) ──────────────────

	/**
	 * Process a cache message from a child (via Pool wire protocol).
	 * Supports both the header-based approach and explicit messages.
	 *
	 * @method processChildMessage
	 * @static
	 * @param {array} $msg
	 */
	static function processChildMessage($msg)
	{
		$action = $msg['action'] ?? '';
		if ($action === 'invalidate') {
			self::invalidateStreams($msg['streams'] ?? array());
		} elseif ($action === 'register') {
			$pageKey = $msg['pageKey'] ?? '';
			if (isset($msg['tree'])) {
				self::registerTree($pageKey, $msg['tree']);
			}
			if (isset($msg['deps'])) {
				self::registerDeps($pageKey, $msg['deps']);
			}
		}
	}

	// ── Stats ───────────────────────────────────────────

	static function stats()
	{
		return array(
			'trees'              => count(self::$trees),
			'trackedStreams'      => count(self::$deps),
			'invalidations'      => self::$invalidations,
			'pagesInvalidated'   => self::$pagesInvalidated,
			'falseInvalidations' => self::$falseInvalidations,
			'stalePagesNow'      => count(self::$staleLeaves),
			'slotCache'          => array(
				'entries' => count(self::$slotCache),
				'bytes'   => self::$slotCacheSize,
				'hits'    => self::$slotCacheHits,
				'misses'  => self::$slotCacheMisses,
			),
			'partialRenders'     => self::$partialRenders,
		);
	}

	/**
	 * Dump a page's tree for debugging/dashboard.
	 *
	 * @param {string} $pageKey
	 * @return {array|null}
	 */
	static function dumpTree($pageKey)
	{
		if (!isset(self::$trees[$pageKey])) return null;
		$tree = self::$trees[$pageKey];
		$leaves = array();
		foreach ($tree['leaves'] as $path => $h) {
			$leaves[$path] = array(
				'hash'   => $h ? substr($h, 0, 8) : 'STALE',
				'cached' => ($h !== null && isset(self::$slotCache[$h])),
			);
		}
		return array(
			'rootHash' => $tree['hash'] ? substr($tree['hash'], 0, 8) : 'STALE',
			'cachedAt' => date('H:i:s', $tree['time']),
			'leaves'   => $leaves,
			'stale'    => self::$staleLeaves[$pageKey] ?? array(),
			'deps'     => self::$pageStreams[$pageKey] ?? array(),
		);
	}
}
