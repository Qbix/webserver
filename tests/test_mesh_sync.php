<?php
/**
 * Test Turn 5: Sync protocol — Bloom filters, record exchange, conflict resolution.
 *
 * Simulates two peers (A and B) with in-memory record stores.
 * Tests: Bloom filter creation/membership/diff, record exchange,
 * last-writer-wins, custom merge, and full sync flow.
 */

error_reporting(E_ALL);
$root = dirname(__DIR__);
require_once $root . '/src/Q/WebServer/MeshSync.php';

$pass = 0; $fail = 0; $tests = 0;
function ok($cond, $msg) {
	global $pass, $fail, $tests; $tests++;
	if ($cond) { echo "  ✓ $msg\n"; $pass++; }
	else { echo "  ✗ $msg\n"; $fail++; }
}

echo "\n  ── Turn 5: Sync Protocol ──\n";

// ── Part 1: Bloom Filter Unit Tests ──

echo "\n  ── Bloom Filter ──\n\n";

$keys = array('msg:001', 'msg:002', 'msg:003', 'msg:004', 'msg:005');
$bloom = Q_WebServer_MeshSync::createBloom($keys);

ok(!empty($bloom['bits']), "Bloom filter has bits");
ok($bloom['size'] === 1024, "Default size 1024 bytes");
ok($bloom['hash_count'] === 7, "Default 7 hash functions");
ok($bloom['count'] === 5, "Count is 5");

// Membership: all inserted keys should be found
foreach ($keys as $key) {
	ok(Q_WebServer_MeshSync::bloomContains($key, $bloom),
		"Bloom contains '$key'");
}

// Non-members: should NOT be found (with high probability)
$falsePositives = 0;
$testCount = 100;
for ($i = 0; $i < $testCount; $i++) {
	if (Q_WebServer_MeshSync::bloomContains("nothere:$i", $bloom)) {
		$falsePositives++;
	}
}
$fpRate = $falsePositives / $testCount;
ok($fpRate < 0.15, "False positive rate < 15% (got " . round($fpRate * 100, 1) . "%)");

// Empty Bloom filter
$emptyBloom = Q_WebServer_MeshSync::createBloom(array());
ok($emptyBloom['count'] === 0, "Empty bloom has count 0");
ok(!Q_WebServer_MeshSync::bloomContains('anything', $emptyBloom), "Empty bloom rejects everything");

// Bloom diff
$myKeys = array('a', 'b', 'c', 'd', 'e');
$theirKeys = array('c', 'd', 'e', 'f', 'g');
$theirBloom = Q_WebServer_MeshSync::createBloom($theirKeys);

$diff = Q_WebServer_MeshSync::bloomDiff($myKeys, $theirBloom);
// a and b are NOT in their bloom — they should appear in they_missing
ok(in_array('a', $diff['they_missing']), "Diff: 'a' missing from theirs");
ok(in_array('b', $diff['they_missing']), "Diff: 'b' missing from theirs");
// c, d, e are in both — should NOT appear
ok(!in_array('c', $diff['they_missing']), "Diff: 'c' is shared");
ok(!in_array('d', $diff['they_missing']), "Diff: 'd' is shared");
ok($diff['estimated_we_missing'] >= 1, "Estimated we're missing >= 1 record");

// ── Part 2: Record Store (in-memory simulation) ──

echo "\n  ── Record Store Setup ──\n\n";

Q_WebServer_MeshSync::reset();

// Peer A's records
$storeA = array(
	'msg:001' => array('value' => 'Hello from A', 'version' => 1, 'updated' => 1000),
	'msg:002' => array('value' => 'Second from A', 'version' => 1, 'updated' => 1001),
	'msg:003' => array('value' => 'Shared message', 'version' => 1, 'updated' => 1002),
	'msg:004' => array('value' => 'Conflict value A', 'version' => 2, 'updated' => 1010),
);

// Peer B's records
$storeB = array(
	'msg:003' => array('value' => 'Shared message', 'version' => 1, 'updated' => 1002),
	'msg:004' => array('value' => 'Conflict value B', 'version' => 2, 'updated' => 1011), // same version, newer
	'msg:005' => array('value' => 'Only on B', 'version' => 1, 'updated' => 1003),
	'msg:006' => array('value' => 'Also only on B', 'version' => 1, 'updated' => 1004),
);

// Register A's store
Q_WebServer_MeshSync::registerTable('messages', array(
	'getRecords' => function ($keys = null, $since = 0) use (&$storeA) {
		if ($keys !== null) {
			$result = array();
			foreach ($keys as $k) {
				if (isset($storeA[$k])) $result[$k] = $storeA[$k];
			}
			return $result;
		}
		if ($since > 0) {
			return array_filter($storeA, function ($r) use ($since) {
				return ($r['updated'] ?? 0) >= $since;
			});
		}
		return $storeA;
	},
	'putRecords' => function ($records) use (&$storeA) {
		$count = 0;
		foreach ($records as $key => $record) {
			$storeA[$key] = $record;
			$count++;
		}
		return $count;
	}
));

ok(in_array('messages', Q_WebServer_MeshSync::tables()), "Table 'messages' registered");

// ── Part 3: Bloom Filter for Table ──

echo "\n  ── Table Bloom Filter ──\n\n";

$bloomA = Q_WebServer_MeshSync::bloomForTable('messages');
ok($bloomA !== null, "Bloom for 'messages' table generated");
ok($bloomA['count'] === 4, "A has 4 records in bloom");
ok(Q_WebServer_MeshSync::bloomContains('msg:001', $bloomA), "A's bloom contains msg:001");
ok(!Q_WebServer_MeshSync::bloomContains('msg:005', $bloomA), "A's bloom does NOT contain msg:005");

$bloomBad = Q_WebServer_MeshSync::bloomForTable('nonexistent');
ok($bloomBad === null, "Bloom for unknown table returns null");

// ── Part 4: Record Exchange ──

echo "\n  ── Record Exchange ──\n\n";

// Get specific records by key
$fetched = Q_WebServer_MeshSync::getRecords('messages', array('msg:001', 'msg:003'));
ok(count($fetched) === 2, "Fetched 2 records by key");
ok($fetched['msg:001']['value'] === 'Hello from A', "Correct value for msg:001");

// ── Part 5: Merge — New Records ──

echo "\n  ── Merge: New Records ──\n\n";

$newRecords = array(
	'msg:005' => array('value' => 'Only on B', 'version' => 1, 'updated' => 1003),
	'msg:006' => array('value' => 'Also only on B', 'version' => 1, 'updated' => 1004),
);
$mergeResult = Q_WebServer_MeshSync::mergeRecords('messages', $newRecords);
ok($mergeResult['merged'] === 2, "2 new records merged");
ok($mergeResult['conflicts'] === 0, "0 conflicts for new records");
ok(isset($storeA['msg:005']), "msg:005 now in A's store");
ok($storeA['msg:005']['value'] === 'Only on B', "msg:005 has correct value");

// ── Part 6: Merge — Newer Version Wins ──

echo "\n  ── Merge: Version Comparison ──\n\n";

$newerRecord = array(
	'msg:001' => array('value' => 'Updated from remote', 'version' => 3, 'updated' => 2000),
);
$mergeV = Q_WebServer_MeshSync::mergeRecords('messages', $newerRecord);
ok($mergeV['merged'] === 1, "Newer version accepted");
ok($storeA['msg:001']['value'] === 'Updated from remote', "Value updated to newer version");
ok($storeA['msg:001']['version'] === 3, "Version updated to 3");

// Older version should be skipped
$olderRecord = array(
	'msg:001' => array('value' => 'Old version', 'version' => 1, 'updated' => 500),
);
$mergeOld = Q_WebServer_MeshSync::mergeRecords('messages', $olderRecord);
ok($mergeOld['skipped'] === 1, "Older version skipped");
ok($storeA['msg:001']['value'] === 'Updated from remote', "Value NOT overwritten by older version");

// ── Part 7: Conflict Resolution (Same Version, Different Values) ──

echo "\n  ── Merge: Conflict Resolution ──\n\n";

// Reset msg:004 to create a conflict scenario
$storeA['msg:004'] = array('value' => 'Conflict value A', 'version' => 2, 'updated' => 1010);

$conflictRecord = array(
	'msg:004' => array('value' => 'Conflict value B', 'version' => 2, 'updated' => 1011),
);
$mergeConflict = Q_WebServer_MeshSync::mergeRecords('messages', $conflictRecord);
ok($mergeConflict['conflicts'] === 1, "1 conflict detected");
// Last-writer-wins: B has updated=1011 > A's 1010
ok($storeA['msg:004']['value'] === 'Conflict value B', "Last-writer-wins: B's value accepted (newer timestamp)");

// Same timestamp tie-break: higher value wins (deterministic)
$storeA['msg:004'] = array('value' => 'AAA', 'version' => 5, 'updated' => 2000);
$tieRecord = array(
	'msg:004' => array('value' => 'ZZZ', 'version' => 5, 'updated' => 2000),
);
$mergeTie = Q_WebServer_MeshSync::mergeRecords('messages', $tieRecord);
ok($mergeTie['conflicts'] === 1, "Tie-break conflict detected");
ok($storeA['msg:004']['value'] === 'ZZZ', "Tie-break: 'ZZZ' > 'AAA' lexicographically");

// Identical values at same version — not a conflict
$storeA['msg:004'] = array('value' => 'Same', 'version' => 6, 'updated' => 3000);
$identicalRecord = array(
	'msg:004' => array('value' => 'Same', 'version' => 6, 'updated' => 3000),
);
$mergeIdentical = Q_WebServer_MeshSync::mergeRecords('messages', $identicalRecord);
ok($mergeIdentical['conflicts'] === 0, "Identical records: no conflict");
ok($mergeIdentical['skipped'] === 1, "Identical records: skipped");

// ── Part 8: Custom Merge Strategy ──

echo "\n  ── Custom Merge Strategy ──\n\n";

Q_WebServer_MeshSync::reset();
$storeCustom = array(
	'counter' => array('value' => 10, 'version' => 1, 'updated' => 1000),
);

Q_WebServer_MeshSync::registerTable('counters', array(
	'getRecords' => function ($keys = null, $since = 0) use (&$storeCustom) {
		if ($keys !== null) {
			return array_intersect_key($storeCustom, array_flip($keys));
		}
		return $storeCustom;
	},
	'putRecords' => function ($records) use (&$storeCustom) {
		$c = 0;
		foreach ($records as $k => $v) { $storeCustom[$k] = $v; $c++; }
		return $c;
	},
	'mergeConflict' => function ($key, $local, $remote) {
		// Custom: for counters, take the MAX value
		return array(
			'value' => max($local['value'], $remote['value']),
			'version' => max($local['version'], $remote['version']) + 1,
			'updated' => max($local['updated'], $remote['updated'])
		);
	}
));

$conflictCounter = array(
	'counter' => array('value' => 7, 'version' => 1, 'updated' => 1000),
);
$mergeCustom = Q_WebServer_MeshSync::mergeRecords('counters', $conflictCounter);
ok($mergeCustom['conflicts'] === 1, "Custom merge: conflict detected");
ok($storeCustom['counter']['value'] === 10, "Custom merge: max(10, 7) = 10");
ok($storeCustom['counter']['version'] === 2, "Custom merge: version bumped");

// ── Part 9: Full Sync Flow (simulated peer exchange) ──

echo "\n  ── Full Sync Flow ──\n\n";

Q_WebServer_MeshSync::reset();

// Re-create two distinct stores
$peerA_store = array(
	'doc:1' => array('value' => 'Alpha doc', 'version' => 1, 'updated' => 100),
	'doc:2' => array('value' => 'Beta doc', 'version' => 1, 'updated' => 101),
	'doc:3' => array('value' => 'Shared doc', 'version' => 1, 'updated' => 102),
);
$peerB_store = array(
	'doc:3' => array('value' => 'Shared doc', 'version' => 1, 'updated' => 102),
	'doc:4' => array('value' => 'Gamma doc', 'version' => 1, 'updated' => 103),
	'doc:5' => array('value' => 'Delta doc', 'version' => 1, 'updated' => 104),
);

// Register A's table
Q_WebServer_MeshSync::registerTable('docs', array(
	'getRecords' => function ($keys = null, $since = 0) use (&$peerA_store) {
		if ($keys !== null) return array_intersect_key($peerA_store, array_flip($keys));
		if ($since > 0) return array_filter($peerA_store, function ($r) use ($since) { return $r['updated'] >= $since; });
		return $peerA_store;
	},
	'putRecords' => function ($records) use (&$peerA_store) {
		$c = 0; foreach ($records as $k => $v) { $peerA_store[$k] = $v; $c++; } return $c;
	}
));

// Simulate B's responses (what B would return when A calls its endpoints)
$peerB_mock = function ($peerId, $method, $path, $headers = array(), $body = '') use (&$peerB_store, &$peerA_store) {
	if (strpos($path, '/Q/sync/bloom') !== false) {
		// B returns its Bloom filter
		return Q_WebServer_MeshSync::createBloom(array_keys($peerB_store));
	}
	if (strpos($path, '/Q/sync/records') !== false) {
		// B returns records A doesn't have (using A's Bloom to filter)
		$data = json_decode($body, true) ?: array();
		$bloom = $data['bloom'] ?? null;
		$filtered = array();
		foreach ($peerB_store as $key => $record) {
			if (!$bloom || !Q_WebServer_MeshSync::bloomContains($key, $bloom)) {
				$filtered[$key] = $record;
			}
		}
		return array('records' => $filtered);
	}
	if (strpos($path, '/Q/sync/merge') !== false) {
		// B merges records from A
		$data = json_decode($body, true) ?: array();
		$records = $data['records'] ?? array();
		$merged = 0;
		foreach ($records as $k => $v) {
			if (!isset($peerB_store[$k]) || ($v['version'] ?? 0) > ($peerB_store[$k]['version'] ?? 0)) {
				$peerB_store[$k] = $v;
				$merged++;
			}
		}
		return array('merged' => $merged);
	}
	return array('error' => 'Unknown path');
};

// Run sync: A syncs with B
$syncResult = Q_WebServer_MeshSync::syncWithPeer(
	'docs', 'peer_b_id', 0, $peerB_mock
);

ok(!isset($syncResult['error']), "Sync completed without error");
ok(($syncResult['they_missing'] ?? 0) >= 2, "A detected B is missing >= 2 records (doc:1, doc:2)");

// Verify convergence: A should now have all 5 docs
ok(isset($peerA_store['doc:4']), "A now has doc:4 (from B)");
ok(isset($peerA_store['doc:5']), "A now has doc:5 (from B)");
ok($peerA_store['doc:4']['value'] === 'Gamma doc', "doc:4 value correct");
ok(count($peerA_store) === 5, "A has all 5 docs after sync");

// Verify B also got A's records
ok(isset($peerB_store['doc:1']), "B now has doc:1 (from A)");
ok(isset($peerB_store['doc:2']), "B now has doc:2 (from A)");
ok(count($peerB_store) === 5, "B has all 5 docs after sync");

// ── Part 10: API Endpoints ──

echo "\n  ── Sync API Endpoints ──\n\n";

$tablesResult = Q_WebServer_MeshSync::handleSyncApi('tables');
ok(in_array('docs', $tablesResult['tables']), "API: tables lists 'docs'");

$bloomResult = Q_WebServer_MeshSync::handleSyncApi('bloom', array('table' => 'docs'));
ok($bloomResult['count'] === 5, "API: bloom returns count=5 after sync");

$recordsResult = Q_WebServer_MeshSync::handleSyncApi('records', array('table' => 'docs'));
ok(count($recordsResult['records']) === 5, "API: records returns all 5");

// Records with bloom filter (return only what's not in bloom)
$partialBloom = Q_WebServer_MeshSync::createBloom(array('doc:1', 'doc:2', 'doc:3'));
$filteredResult = Q_WebServer_MeshSync::handleSyncApi('records', array(
	'table' => 'docs',
	'bloom' => $partialBloom
));
// Should return doc:4 and doc:5 (not in the bloom)
$filteredKeys = array_keys($filteredResult['records']);
ok(!in_array('doc:1', $filteredKeys), "Filtered: doc:1 excluded (in bloom)");
ok(!in_array('doc:2', $filteredKeys), "Filtered: doc:2 excluded (in bloom)");
ok(in_array('doc:4', $filteredKeys), "Filtered: doc:4 included (not in bloom)");
ok(in_array('doc:5', $filteredKeys), "Filtered: doc:5 included (not in bloom)");

echo "\n  ═══════════════════════════════\n";
echo "  Turn 5 (Sync): $pass passed, $fail failed (of $tests)\n";
echo "  ═══════════════════════════════\n\n";

exit($fail > 0 ? 1 : 0);
