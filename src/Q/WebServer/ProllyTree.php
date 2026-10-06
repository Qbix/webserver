<?php
/**
 * Prolly tree: a probabilistic B-tree for efficient set reconciliation.
 *
 * Properties:
 *   - Same key-value pairs always produce the same tree (history-independent)
 *   - Nodes are content-addressed (hash of contents)
 *   - Two trees sharing most data share most nodes
 *   - Diff is O(d * log n) where d = differences, n = total records
 *
 * Used by MeshSync when Bloom filter diff exceeds a threshold.
 * Instead of transferring all records, compare tree roots, walk
 * divergent branches, transfer only the differing leaf entries.
 *
 * Tree structure:
 *   Internal node: { hash, children: [{key, hash}...] }
 *   Leaf node:     { hash, entries: [{key, value, version}...] }
 *
 * Chunk boundaries are determined by hashing the key: when
 * hash(key) mod B == 0, a new chunk starts. This means insertions
 * and deletions only affect nearby chunks, not the entire tree.
 *
 * @class Q_WebServer_ProllyTree
 */
class Q_WebServer_ProllyTree
{
	/**
	 * Target leaf size. Chunk boundary triggers when
	 * crc32(key) % BOUNDARY == 0, giving average leaf size of BOUNDARY entries.
	 */
	const BOUNDARY = 16;

	/**
	 * Target fan-out for internal nodes.
	 */
	const FANOUT = 16;

	// ── Tree Building ───────────────────────────────────

	/**
	 * Build a prolly tree from a sorted array of records.
	 *
	 * @param array $records Sorted by key. Each: key => [value, version, updated]
	 * @return array The tree: [nodes => [hash => node...], root => hash]
	 */
	static function build($records)
	{
		if (empty($records)) {
			$emptyHash = self::hashNode(array('type' => 'leaf', 'entries' => array()));
			return array(
				'nodes' => array($emptyHash => array('type' => 'leaf', 'entries' => array(), 'hash' => $emptyHash)),
				'root' => $emptyHash
			);
		}

		// Sort by key (must be deterministic)
		ksort($records);

		// Step 1: Build leaf nodes using probabilistic chunking
		$leaves = self::chunkIntoLeaves($records);

		// Step 2: Build internal nodes bottom-up
		$allNodes = array();
		foreach ($leaves as $leaf) {
			$allNodes[$leaf['hash']] = $leaf;
		}

		$currentLevel = $leaves;
		while (count($currentLevel) > 1) {
			$nextLevel = self::buildInternalLevel($currentLevel);
			foreach ($nextLevel as $node) {
				$allNodes[$node['hash']] = $node;
			}
			$currentLevel = $nextLevel;
		}

		$root = $currentLevel[0]['hash'];

		return array(
			'nodes' => $allNodes,
			'root' => $root
		);
	}

	/**
	 * Chunk sorted records into leaf nodes using probabilistic boundaries.
	 */
	private static function chunkIntoLeaves($records)
	{
		$leaves = array();
		$currentEntries = array();

		foreach ($records as $key => $record) {
			$currentEntries[] = array(
				'key' => $key,
				'value' => $record['value'] ?? null,
				'version' => $record['version'] ?? 0,
				'updated' => $record['updated'] ?? 0
			);

			// Probabilistic boundary: new chunk when hash hits boundary
			if (self::isBoundary($key, self::BOUNDARY) && count($currentEntries) >= 4) {
				$leaf = array('type' => 'leaf', 'entries' => $currentEntries);
				$leaf['hash'] = self::hashNode($leaf);
				$leaves[] = $leaf;
				$currentEntries = array();
			}
		}

		// Remaining entries become the last leaf
		if (!empty($currentEntries)) {
			$leaf = array('type' => 'leaf', 'entries' => $currentEntries);
			$leaf['hash'] = self::hashNode($leaf);
			$leaves[] = $leaf;
		}

		return $leaves;
	}

	/**
	 * Build one level of internal nodes from child nodes.
	 */
	private static function buildInternalLevel($children)
	{
		$nodes = array();
		$currentChildren = array();

		foreach ($children as $child) {
			// Use the last key in the child as the separator
			$lastKey = self::lastKey($child);
			$currentChildren[] = array(
				'key' => $lastKey,
				'hash' => $child['hash']
			);

			if (self::isBoundary($lastKey, self::FANOUT) && count($currentChildren) >= 2) {
				$node = array('type' => 'internal', 'children' => $currentChildren);
				$node['hash'] = self::hashNode($node);
				$nodes[] = $node;
				$currentChildren = array();
			}
		}

		if (!empty($currentChildren)) {
			$node = array('type' => 'internal', 'children' => $currentChildren);
			$node['hash'] = self::hashNode($node);
			$nodes[] = $node;
		}

		return $nodes;
	}

	// ── Tree Comparison (Diff) ──────────────────────────

	/**
	 * Compare two trees and return the differing records.
	 *
	 * Both trees must be available as node stores (hash => node).
	 * In practice, one is local and the other is fetched node-by-node
	 * from the peer via /Q/sync/tree.
	 *
	 * @param array $localTree [nodes => [...], root => hash]
	 * @param array $remoteTree [nodes => [...], root => hash]
	 * @return array [
	 *   only_local => [key => record...],
	 *   only_remote => [key => record...],
	 *   different => [key => [local => record, remote => record]...],
	 *   nodes_compared => int,
	 *   nodes_matched => int
	 * ]
	 */
	static function diff($localTree, $remoteTree)
	{
		$stats = array(
			'only_local' => array(),
			'only_remote' => array(),
			'different' => array(),
			'nodes_compared' => 0,
			'nodes_matched' => 0
		);

		if ($localTree['root'] === $remoteTree['root']) {
			$stats['nodes_compared'] = 1;
			$stats['nodes_matched'] = 1;
			return $stats; // identical trees
		}

		self::diffNodes(
			$localTree['root'], $remoteTree['root'],
			$localTree['nodes'], $remoteTree['nodes'],
			$stats
		);

		return $stats;
	}

	/**
	 * Recursively compare two nodes.
	 */
	private static function diffNodes($localHash, $remoteHash, &$localNodes, &$remoteNodes, &$stats)
	{
		$stats['nodes_compared']++;

		if ($localHash === $remoteHash) {
			$stats['nodes_matched']++;
			return; // subtrees identical
		}

		$localNode = $localNodes[$localHash] ?? null;
		$remoteNode = $remoteNodes[$remoteHash] ?? null;

		if (!$localNode && !$remoteNode) return;

		// Both are leaves — compare entries directly
		if (($localNode['type'] ?? '') === 'leaf' && ($remoteNode['type'] ?? '') === 'leaf') {
			self::diffLeaves($localNode, $remoteNode, $stats);
			return;
		}

		// Both are internal — compare children by key ranges
		if (($localNode['type'] ?? '') === 'internal' && ($remoteNode['type'] ?? '') === 'internal') {
			self::diffInternal($localNode, $remoteNode, $localNodes, $remoteNodes, $stats);
			return;
		}

		// Type mismatch (shouldn't happen with same data) — extract all entries
		if ($localNode) self::extractEntries($localNode, $localNodes, $stats, 'only_local');
		if ($remoteNode) self::extractEntries($remoteNode, $remoteNodes, $stats, 'only_remote');
	}

	/**
	 * Compare two leaf nodes entry by entry.
	 */
	private static function diffLeaves($local, $remote, &$stats)
	{
		$localMap = array();
		foreach ($local['entries'] as $e) {
			$localMap[$e['key']] = $e;
		}

		$remoteMap = array();
		foreach ($remote['entries'] as $e) {
			$remoteMap[$e['key']] = $e;
		}

		// Keys only in local
		foreach ($localMap as $key => $entry) {
			if (!isset($remoteMap[$key])) {
				$stats['only_local'][$key] = $entry;
			} elseif ($entry['value'] !== $remoteMap[$key]['value']
				|| $entry['version'] !== $remoteMap[$key]['version']) {
				$stats['different'][$key] = array(
					'local' => $entry,
					'remote' => $remoteMap[$key]
				);
			}
		}

		// Keys only in remote
		foreach ($remoteMap as $key => $entry) {
			if (!isset($localMap[$key])) {
				$stats['only_remote'][$key] = $entry;
			}
		}
	}

	/**
	 * Compare two internal nodes by matching children on key ranges.
	 */
	private static function diffInternal($local, $remote, &$localNodes, &$remoteNodes, &$stats)
	{
		$localChildren = array();
		foreach ($local['children'] as $c) {
			$localChildren[$c['key']] = $c['hash'];
		}

		$remoteChildren = array();
		foreach ($remote['children'] as $c) {
			$remoteChildren[$c['key']] = $c['hash'];
		}

		$allKeys = array_unique(array_merge(
			array_keys($localChildren),
			array_keys($remoteChildren)
		));
		sort($allKeys);

		foreach ($allKeys as $key) {
			$lHash = $localChildren[$key] ?? null;
			$rHash = $remoteChildren[$key] ?? null;

			if ($lHash && $rHash) {
				// Both have this child — recurse
				self::diffNodes($lHash, $rHash, $localNodes, $remoteNodes, $stats);
			} elseif ($lHash && !$rHash) {
				// Only local has this subtree
				$node = $localNodes[$lHash] ?? null;
				if ($node) self::extractEntries($node, $localNodes, $stats, 'only_local');
			} elseif (!$lHash && $rHash) {
				// Only remote has this subtree
				$node = $remoteNodes[$rHash] ?? null;
				if ($node) self::extractEntries($node, $remoteNodes, $stats, 'only_remote');
			}
		}
	}

	/**
	 * Extract all entries from a node (recursively) into the diff result.
	 */
	private static function extractEntries($node, &$nodeStore, &$stats, $side)
	{
		if ($node['type'] === 'leaf') {
			foreach ($node['entries'] as $entry) {
				$stats[$side][$entry['key']] = $entry;
			}
		} elseif ($node['type'] === 'internal') {
			foreach ($node['children'] as $child) {
				$childNode = $nodeStore[$child['hash']] ?? null;
				if ($childNode) {
					self::extractEntries($childNode, $nodeStore, $stats, $side);
				}
			}
		}
	}

	// ── API ─────────────────────────────────────────────

	/**
	 * Handle /Q/sync/tree endpoint.
	 *
	 * @param array $data [action, table, hash, ...]
	 * @return array
	 */
	static function handleApi($data)
	{
		$action = $data['action'] ?? 'root';

		switch ($action) {
			case 'root':
				// Return root hash for a table
				$table = $data['table'] ?? '';
				$records = self::getTableRecords($table);
				if ($records === null) return array('error' => 'Unknown table');
				$tree = self::build($records);
				return array(
					'root' => $tree['root'],
					'node_count' => count($tree['nodes']),
					'table' => $table
				);

			case 'node':
				// Return a specific node by hash
				$table = $data['table'] ?? '';
				$hash = $data['hash'] ?? '';
				$records = self::getTableRecords($table);
				if ($records === null) return array('error' => 'Unknown table');
				$tree = self::build($records);
				$node = $tree['nodes'][$hash] ?? null;
				return $node ?: array('error' => 'Node not found');

			case 'diff':
				// Compare with a remote root and return the diff
				$table = $data['table'] ?? '';
				$remoteRoot = $data['remote_root'] ?? '';
				$remoteNodes = $data['remote_nodes'] ?? array();
				$records = self::getTableRecords($table);
				if ($records === null) return array('error' => 'Unknown table');
				$localTree = self::build($records);
				$remoteTree = array('root' => $remoteRoot, 'nodes' => $remoteNodes);
				return self::diff($localTree, $remoteTree);

			default:
				return array('error' => 'Unknown tree action');
		}
	}

	// ── Helpers ──────────────────────────────────────────

	/**
	 * Deterministic boundary check.
	 * Returns true when this key should start a new chunk.
	 */
	private static function isBoundary($key, $factor)
	{
		return (crc32($key) & 0x7FFFFFFF) % $factor === 0;
	}

	/**
	 * Content-addressed hash of a node.
	 */
	static function hashNode($node)
	{
		// Canonical JSON (sorted keys) for deterministic hashing
		$type = $node['type'];
		if ($type === 'leaf') {
			$data = array();
			foreach ($node['entries'] as $e) {
				$data[] = $e['key'] . ':' . json_encode($e['value']) . ':' . $e['version'];
			}
			return substr(hash('sha256', 'leaf:' . implode('|', $data)), 0, 16);
		} else {
			$data = array();
			foreach ($node['children'] as $c) {
				$data[] = $c['key'] . ':' . $c['hash'];
			}
			return substr(hash('sha256', 'internal:' . implode('|', $data)), 0, 16);
		}
	}

	/**
	 * Get the last key in a node (for separator keys in internal nodes).
	 */
	private static function lastKey($node)
	{
		if ($node['type'] === 'leaf') {
			$entries = $node['entries'];
			return $entries[count($entries) - 1]['key'];
		} else {
			$children = $node['children'];
			return $children[count($children) - 1]['key'];
		}
	}

	/**
	 * Get records for a table from MeshSync.
	 */
	private static function getTableRecords($table)
	{
		if (!class_exists('Q_WebServer_MeshSync')) return null;
		$tables = Q_WebServer_MeshSync::tables();
		if (!in_array($table, $tables)) return null;
		return Q_WebServer_MeshSync::getRecords($table, null);
	}

	/**
	 * Get the total number of entries across all leaves.
	 */
	static function countEntries($tree)
	{
		$count = 0;
		foreach ($tree['nodes'] as $node) {
			if ($node['type'] === 'leaf') {
				$count += count($node['entries']);
			}
		}
		return $count;
	}
}
