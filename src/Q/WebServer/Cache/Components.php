<?php
/**
 * @module Q
 */

/**
 * Merkle-tree invalidation layer for Q_WebServer_Cache.
 *
 * Does NOT store component HTML — only hashes and dependencies.
 * The actual cached page lives in Q_WebServer_Cache (page-level).
 * This layer answers one question: "is this cached page still valid?"
 *
 * Better than ESI/SSI because:
 *   - No component HTML in memory — just a tree of md5 hashes (~100 bytes/page)
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
 *   4. Next request for this page: cache miss → fork worker → full re-render
 *      → new tree + new page cached. The stale leaves tell the child which
 *      slots changed, enabling smart partial rendering if the app supports it.
 *
 * Config:
 *   "Q": { "web": { "cache": { "components": {
 *     "enabled": true,
 *     "maxTrees": 10000
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

	// ── Stats ───────────────────────────────────────────

	protected static $invalidations = 0;
	protected static $pagesInvalidated = 0;

	// ── Config ──────────────────────────────────────────

	protected static $enabled = false;
	protected static $maxTrees = 10000;

	/**
	 * Initialize from config.
	 */
	static function init()
	{
		$config = Q_Config::get('Q', 'web', 'cache', 'components', array());
		self::$enabled = (bool) Q::ifset($config, 'enabled', false);
		self::$maxTrees = (int) Q::ifset($config, 'maxTrees', 10000);
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

				// Purge from page-level cache
				Q_WebServer_Cache::purge($pageKey);
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
			'trees'            => count(self::$trees),
			'trackedStreams'    => count(self::$deps),
			'invalidations'    => self::$invalidations,
			'pagesInvalidated' => self::$pagesInvalidated,
			'stalePagesNow'    => count(self::$staleLeaves),
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
		return array(
			'rootHash' => $tree['hash'] ? substr($tree['hash'], 0, 8) : 'STALE',
			'cachedAt' => date('H:i:s', $tree['time']),
			'leaves'   => array_map(function ($h) {
				return $h ? substr($h, 0, 8) : 'STALE';
			}, $tree['leaves']),
			'stale'    => self::$staleLeaves[$pageKey] ?? array(),
			'deps'     => self::$pageStreams[$pageKey] ?? array(),
		);
	}
}
