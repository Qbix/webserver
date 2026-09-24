<?php
/**
 * Mesh sync protocol: Bloom filter exchange → diff detection → record transfer.
 *
 * When two peers connect, they exchange Bloom filters of their recent changes.
 * The diff identifies which records each side is missing. Missing records
 * are fetched and merged with pluggable conflict resolution.
 *
 * Bloom filter: probabilistic set membership with configurable false positive rate.
 * False negatives never occur. A false positive makes a key the peer lacks
 * look present, so that record is not sent in this round; the filter is
 * sized per table to keep that rate near 0.1%.
 *
 * @class Q_WebServer_MeshSync
 */
class Q_WebServer_MeshSync
{
	/**
	 * Registered sync tables: name => [getRecords, putRecords, mergeConflict]
	 */
	private static $tables = array();

	/**
	 * Default merge strategy.
	 */
	private static $defaultMerge = 'last_writer_wins';

	// ── Table Registration ──────────────────────────────

	/**
	 * Register a table for syncing.
	 *
	 * @param string $name Table name (used in Bloom filter exchange)
	 * @param array $callbacks [
	 *   'getRecords' => function($keys = null, $since = 0): array of [key => [value, version, updated]],
	 *   'putRecords' => function(array $records): int count merged,
	 *   'mergeConflict' => function($key, $local, $remote): mixed resolved value (optional)
	 * ]
	 */
	static function registerTable($name, $callbacks)
	{
		self::$tables[$name] = array(
			'getRecords' => $callbacks['getRecords'],
			'putRecords' => $callbacks['putRecords'],
			'mergeConflict' => $callbacks['mergeConflict'] ?? null
		);
	}

	/**
	 * Get registered table names.
	 * @return array
	 */
	static function tables()
	{
		return array_keys(self::$tables);
	}

	// ── Bloom Filter ────────────────────────────────────

	/**
	 * Create a Bloom filter for a set of keys.
	 *
	 * @param array $keys The keys to add
	 * @param int $size Bit array size (default: 1024 bytes = 8192 bits)
	 * @param int $hashCount Number of hash functions (default: 7)
	 * @return array [bits => base64, size => int, hash_count => int, count => int]
	 */
	static function createBloom($keys, $size = 1024, $hashCount = 7)
	{
		$bits = str_repeat("\0", $size);
		$totalBits = $size * 8;

		foreach ($keys as $key) {
			$hashes = self::bloomHashes($key, $hashCount, $totalBits);
			foreach ($hashes as $pos) {
				$byteIdx = (int) ($pos / 8);
				$bitIdx = $pos % 8;
				$bits[$byteIdx] = chr(ord($bits[$byteIdx]) | (1 << $bitIdx));
			}
		}

		return array(
			'bits' => base64_encode($bits),
			'size' => $size,
			'hash_count' => $hashCount,
			'count' => count($keys)
		);
	}

	/**
	 * Check if a key might be in a Bloom filter.
	 *
	 * @param string $key
	 * @param array $bloom The filter from createBloom()
	 * @return bool true if possibly present, false if definitely absent
	 */
	static function bloomContains($key, $bloom)
	{
		$bits = base64_decode($bloom['bits']);
		$totalBits = $bloom['size'] * 8;
		$hashes = self::bloomHashes($key, $bloom['hash_count'], $totalBits);

		foreach ($hashes as $pos) {
			$byteIdx = (int) ($pos / 8);
			$bitIdx = $pos % 8;
			if (!(ord($bits[$byteIdx]) & (1 << $bitIdx))) {
				return false; // definitely not present
			}
		}
		return true; // possibly present
	}

	/**
	 * Compute the diff between two Bloom filters.
	 * Returns keys from $myKeys that are NOT in the remote filter,
	 * and an estimate of keys in the remote filter that we don't have.
	 *
	 * @param array $myKeys Our keys
	 * @param array $remoteBloom Their Bloom filter
	 * @return array [they_missing => [...keys remote doesn't have...], estimated_we_missing => int]
	 */
	static function bloomDiff($myKeys, $remoteBloom)
	{
		$theyMissing = array();
		foreach ($myKeys as $key) {
			if (!self::bloomContains($key, $remoteBloom)) {
				$theyMissing[] = $key;
			}
		}

		// Estimate how many keys they have that we don't:
		// their count minus (our count minus what they're missing)
		$overlap = count($myKeys) - count($theyMissing);
		$estimatedWeMissing = max(0, ($remoteBloom['count'] ?? 0) - $overlap);

		return array(
			'they_missing' => $theyMissing,
			'estimated_we_missing' => $estimatedWeMissing
		);
	}

	/**
	 * Generate hash positions for a key.
	 */
	private static function bloomHashes($key, $count, $totalBits)
	{
		$positions = array();
		// Double-hashing technique: h(i) = h1 + i*h2
		$h1 = crc32($key);
		$h2 = crc32(strrev($key) . "\x01");
		// Ensure positive
		$h1 = $h1 & 0x7FFFFFFF;
		$h2 = $h2 & 0x7FFFFFFF;
		if ($h2 === 0) $h2 = 1;

		for ($i = 0; $i < $count; $i++) {
			$positions[] = ($h1 + $i * $h2) % $totalBits;
		}
		return $positions;
	}

	// ── Sync Protocol ───────────────────────────────────

	/**
	 * Generate a Bloom filter for a table's records.
	 *
	 * @param string $table Table name
	 * @param int $since Only include records updated after this timestamp
	 * @return array|null Bloom filter, or null if table not registered
	 */
	static function bloomForTable($table, $since = 0)
	{
		if (!isset(self::$tables[$table])) return null;
		$getRecords = self::$tables[$table]['getRecords'];
		$records = $getRecords(null, $since);
		$keys = array_keys($records);
		// Size the filter to the key count: ~14.4 bits per key with 7 hashes
		// gives a false-positive rate near 0.1%. A fixed 1KB filter saturated
		// past a few thousand keys (93% false positives at 5,000), and a false
		// positive here means a record the peer lacks is never sent.
		$size = max(1024, (int) ceil(count($keys) * 14.4 / 8));
		$bloom = self::createBloom($keys, $size);
		$bloom['table'] = $table;
		$bloom['since'] = $since;
		return $bloom;
	}

	/**
	 * Get specific records from a table by keys.
	 *
	 * @param string $table
	 * @param array $keys
	 * @return array key => [value, version, updated]
	 */
	static function getRecords($table, $keys)
	{
		if (!isset(self::$tables[$table])) return array();
		$getRecords = self::$tables[$table]['getRecords'];
		return $getRecords($keys);
	}

	/**
	 * Merge remote records into a local table.
	 * Handles conflicts using the table's merge strategy or last-writer-wins.
	 *
	 * @param string $table
	 * @param array $remoteRecords key => [value, version, updated]
	 * @return array [merged => int, conflicts => int, skipped => int]
	 */
	static function mergeRecords($table, $remoteRecords)
	{
		if (!isset(self::$tables[$table])) {
			return array('error' => 'Unknown table: ' . $table);
		}

		$getRecords = self::$tables[$table]['getRecords'];
		$putRecords = self::$tables[$table]['putRecords'];
		$mergeConflict = self::$tables[$table]['mergeConflict'];

		// Fetch our versions of the same keys
		$keys = array_keys($remoteRecords);
		$localRecords = $getRecords($keys);

		$toMerge = array();
		$conflicts = 0;
		$skipped = 0;

		foreach ($remoteRecords as $key => $remote) {
			$local = $localRecords[$key] ?? null;

			if ($local === null) {
				// We don't have this record — accept it
				$toMerge[$key] = $remote;
				continue;
			}

			$localVersion = $local['version'] ?? 0;
			$remoteVersion = $remote['version'] ?? 0;

			if ($remoteVersion > $localVersion) {
				// Remote is newer — accept
				$toMerge[$key] = $remote;
			} elseif ($remoteVersion < $localVersion) {
				// Local is newer — skip
				$skipped++;
			} else {
				// Same version but different values — CONFLICT
				$localVal = $local['value'] ?? null;
				$remoteVal = $remote['value'] ?? null;
				if ($localVal === $remoteVal) {
					$skipped++; // identical, no conflict
					continue;
				}

				$conflicts++;
				if ($mergeConflict) {
					$resolved = $mergeConflict($key, $local, $remote);
					if ($resolved !== null) {
						$toMerge[$key] = $resolved;
					}
				} else {
					// Default: last-writer-wins by updated timestamp
					$localTime = $local['updated'] ?? 0;
					$remoteTime = $remote['updated'] ?? 0;
					if ($remoteTime > $localTime) {
						$toMerge[$key] = $remote;
					} elseif ($remoteTime === $localTime) {
						// Tie-break: higher value wins (deterministic)
						if (strcmp(json_encode($remoteVal), json_encode($localVal)) > 0) {
							$toMerge[$key] = $remote;
						}
					}
					// else: local wins
				}
			}
		}

		$merged = 0;
		if (!empty($toMerge)) {
			$merged = $putRecords($toMerge);
		}

		return array(
			'merged' => $merged,
			'conflicts' => $conflicts,
			'skipped' => $skipped,
			'total' => count($remoteRecords)
		);
	}

	/**
	 * Run the full sync protocol between us and a peer for a table.
	 * This is the high-level flow called by onPeerOnline.
	 *
	 * @param string $table Table name
	 * @param string $peerId Peer to sync with
	 * @param int $since Only sync records updated after this timestamp
	 * @param callable $requestFromPeer function($peerId, $method, $path, $headers, $body): array
	 * @return array Sync result
	 */
	static function syncWithPeer($table, $peerId, $since, $requestFromPeer)
	{
		if (!isset(self::$tables[$table])) {
			return array('error' => 'Unknown table');
		}

		$result = array('table' => $table, 'peer' => substr($peerId, 0, 8));

		// Step 1: Get our Bloom filter
		$myBloom = self::bloomForTable($table, $since);
		$getRecords = self::$tables[$table]['getRecords'];
		$myRecords = $getRecords(null, $since);
		$myKeys = array_keys($myRecords);

		// Step 2: Get their Bloom filter
		$theirBloom = $requestFromPeer(
			$peerId, 'GET',
			"/Q/sync/bloom?table=$table&since=$since",
			array(), ''
		);
		if (!$theirBloom || !isset($theirBloom['bits'])) {
			$result['error'] = 'Could not get peer Bloom filter';
			return $result;
		}

		// Step 3: Compute diff
		$diff = self::bloomDiff($myKeys, $theirBloom);
		$result['they_missing'] = count($diff['they_missing']);
		$result['estimated_we_missing'] = $diff['estimated_we_missing'];

		// Step 4: Send records they're missing
		if (!empty($diff['they_missing'])) {
			$sendRecords = array();
			foreach ($diff['they_missing'] as $key) {
				if (isset($myRecords[$key])) {
					$sendRecords[$key] = $myRecords[$key];
				}
			}
			$mergeResult = $requestFromPeer(
				$peerId, 'POST', '/Q/sync/merge',
				array('Content-Type' => 'application/json'),
				json_encode(array('table' => $table, 'records' => $sendRecords))
			);
			$result['sent'] = count($sendRecords);
			$result['peer_merge'] = $mergeResult;
		}

		// Step 5: Request records we're missing
		// If the estimated diff is large, use prolly tree comparison instead
		// of transferring the full Bloom filter — O(d·log n) vs O(n).
		$prollyThreshold = 500;
		if ($diff['estimated_we_missing'] > $prollyThreshold
			&& class_exists('Q_WebServer_ProllyTree', false)
			|| (is_file(__DIR__ . '/ProllyTree.php')
				&& $diff['estimated_we_missing'] > $prollyThreshold))
		{
			require_once __DIR__ . '/ProllyTree.php';
			$result['sync_method'] = 'prolly_tree';

			// Build our local tree
			$localTree = Q_WebServer_ProllyTree::build($myRecords);

			// Get the peer's tree root
			$peerRoot = $requestFromPeer(
				$peerId, 'POST', '/Q/sync/tree',
				array('Content-Type' => 'application/json'),
				json_encode(array('action' => 'root', 'table' => $table))
			);
			if ($peerRoot && !empty($peerRoot['root'])
				&& $peerRoot['root'] !== $localTree['root'])
			{
				// Get the peer's full tree for comparison
				// (In production, this would walk node-by-node; here we fetch all)
				$peerNodes = array();
				$peerRecords = $requestFromPeer(
					$peerId, 'POST', '/Q/sync/records',
					array('Content-Type' => 'application/json'),
					json_encode(array('table' => $table, 'bloom' => $myBloom))
				);
				if ($peerRecords && isset($peerRecords['records'])) {
					$localMerge = self::mergeRecords($table, $peerRecords['records']);
					$result['received'] = count($peerRecords['records']);
					$result['local_merge'] = $localMerge;
				}
			}
		} elseif ($diff['estimated_we_missing'] > 0) {
			$result['sync_method'] = 'bloom';
			$theirRecords = $requestFromPeer(
				$peerId, 'POST', '/Q/sync/records',
				array('Content-Type' => 'application/json'),
				json_encode(array(
					'table' => $table,
					'bloom' => $myBloom
				))
			);
			if ($theirRecords && isset($theirRecords['records'])) {
				$localMerge = self::mergeRecords($table, $theirRecords['records']);
				$result['received'] = count($theirRecords['records']);
				$result['local_merge'] = $localMerge;
			}
		}

		return $result;
	}

	// ── API Endpoints ───────────────────────────────────

	/**
	 * Handle /Q/sync/bloom, /Q/sync/records, /Q/sync/merge endpoints.
	 *
	 * @param string $action bloom|records|merge|tables
	 * @param array $data
	 * @return array
	 */
	static function handleSyncApi($action, $data = array())
	{
		switch ($action) {
			case 'bloom':
				$table = $data['table'] ?? '';
				$since = (int) ($data['since'] ?? 0);
				$bloom = self::bloomForTable($table, $since);
				return $bloom ?: array('error' => 'Unknown table: ' . $table);

			case 'records':
				$table = $data['table'] ?? '';
				$bloom = $data['bloom'] ?? null;
				if (!isset(self::$tables[$table])) {
					return array('error' => 'Unknown table');
				}
				// Get all our records
				$getRecords = self::$tables[$table]['getRecords'];
				$allRecords = $getRecords(null, (int) ($data['since'] ?? 0));

				// If they sent their Bloom, only return records they don't have
				if ($bloom) {
					$filtered = array();
					foreach ($allRecords as $key => $record) {
						if (!self::bloomContains($key, $bloom)) {
							$filtered[$key] = $record;
						}
					}
					return array('records' => $filtered);
				}
				return array('records' => $allRecords);

			case 'merge':
				$table = $data['table'] ?? '';
				$records = $data['records'] ?? array();
				return self::mergeRecords($table, $records);

			case 'tables':
				return array('tables' => self::tables());

			default:
				return array('error' => 'Unknown sync action: ' . $action);
		}
	}

	/**
	 * Reset (for testing).
	 */
	static function reset()
	{
		self::$tables = array();
	}
}
