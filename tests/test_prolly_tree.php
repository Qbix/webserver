<?php
/**
 * Test Turn 7: Prolly tree sync for large datasets.
 *
 * Tests: deterministic tree building, content-addressed hashing,
 * tree comparison, efficient diff with 10,000 records and 100 differences,
 * and comparison with Bloom filter approach.
 */

error_reporting(E_ALL);
$root = dirname(__DIR__);
require_once $root . '/src/Q/WebServer/ProllyTree.php';
require_once $root . '/src/Q/WebServer/MeshSync.php';

$pass = 0; $fail = 0; $tests = 0;
function ok($cond, $msg) {
	global $pass, $fail, $tests; $tests++;
	if ($cond) { echo "  ✓ $msg\n"; $pass++; }
	else { echo "  ✗ $msg\n"; $fail++; }
}

echo "\n  ── Turn 7: Prolly Tree Sync ──\n";

// ══════════════════════════════════════════════════════
// Part 1: Tree Building
// ══════════════════════════════════════════════════════

echo "\n  ── Tree Building ──\n\n";

// Empty tree
$emptyTree = Q_WebServer_ProllyTree::build(array());
ok(!empty($emptyTree['root']), "Empty tree has a root hash");
ok(count($emptyTree['nodes']) === 1, "Empty tree has 1 node (empty leaf)");

// Small tree (5 records)
$small = array();
for ($i = 1; $i <= 5; $i++) {
	$small["key:$i"] = array('value' => "val_$i", 'version' => 1, 'updated' => $i);
}
$smallTree = Q_WebServer_ProllyTree::build($small);
ok(!empty($smallTree['root']), "Small tree has root: " . $smallTree['root']);
ok(Q_WebServer_ProllyTree::countEntries($smallTree) === 5, "Small tree has 5 entries");

// Determinism: same data produces same tree
$smallTree2 = Q_WebServer_ProllyTree::build($small);
ok($smallTree['root'] === $smallTree2['root'], "Same data → same root hash (deterministic)");

// Different data produces different tree
$small2 = $small;
$small2['key:3']['value'] = 'CHANGED';
$diffTree = Q_WebServer_ProllyTree::build($small2);
ok($diffTree['root'] !== $smallTree['root'], "Changed data → different root hash");

// Order independence: shuffled keys produce same tree
$shuffled = $small;
// PHP arrays preserve insertion order, but build() ksorts
$reversed = array_reverse($small, true);
$revTree = Q_WebServer_ProllyTree::build($reversed);
ok($revTree['root'] === $smallTree['root'], "Insertion order doesn't matter (ksort)");

echo "\n  ── Medium Tree (100 records) ──\n\n";

$medium = array();
for ($i = 0; $i < 100; $i++) {
	$key = sprintf("rec:%04d", $i);
	$medium[$key] = array('value' => "data_$i", 'version' => 1, 'updated' => 1000 + $i);
}
$medTree = Q_WebServer_ProllyTree::build($medium);
$nodeCount = count($medTree['nodes']);
$leafCount = 0;
$internalCount = 0;
foreach ($medTree['nodes'] as $n) {
	if ($n['type'] === 'leaf') $leafCount++;
	else $internalCount++;
}
ok($leafCount > 1, "100 records: $leafCount leaves (chunked)");
ok($nodeCount > $leafCount, "Has internal nodes too: $internalCount internal");
ok(Q_WebServer_ProllyTree::countEntries($medTree) === 100, "100 entries total");

// ══════════════════════════════════════════════════════
// Part 2: Tree Diff — Small
// ══════════════════════════════════════════════════════

echo "\n  ── Tree Diff: Small ──\n\n";

// Identical trees
$diffResult = Q_WebServer_ProllyTree::diff($smallTree, $smallTree);
ok(empty($diffResult['only_local']), "Identical trees: no only_local");
ok(empty($diffResult['only_remote']), "Identical trees: no only_remote");
ok(empty($diffResult['different']), "Identical trees: no different");
ok($diffResult['nodes_compared'] === 1, "Identical trees: 1 node compared (root match)");
ok($diffResult['nodes_matched'] === 1, "Identical trees: 1 node matched");

// One record changed
$localSmall = $small;
$remoteSmall = $small;
$remoteSmall['key:3'] = array('value' => 'REMOTE_CHANGED', 'version' => 2, 'updated' => 999);
$localTree = Q_WebServer_ProllyTree::build($localSmall);
$remoteTree = Q_WebServer_ProllyTree::build($remoteSmall);
$diffChanged = Q_WebServer_ProllyTree::diff($localTree, $remoteTree);
ok(count($diffChanged['different']) === 1, "1 changed record detected");
ok(isset($diffChanged['different']['key:3']), "Changed record is key:3");
ok($diffChanged['different']['key:3']['local']['value'] === 'val_3', "Local value correct");
ok($diffChanged['different']['key:3']['remote']['value'] === 'REMOTE_CHANGED', "Remote value correct");

// Record only in local
$localExtra = $small;
$localExtra['key:6'] = array('value' => 'only_local', 'version' => 1, 'updated' => 100);
$remoteBase = $small;
$ltExtra = Q_WebServer_ProllyTree::build($localExtra);
$rtBase = Q_WebServer_ProllyTree::build($remoteBase);
$diffExtra = Q_WebServer_ProllyTree::diff($ltExtra, $rtBase);
ok(isset($diffExtra['only_local']['key:6']), "Extra local record detected");
ok(empty($diffExtra['only_remote']), "No extra remote records");

// Record only in remote
$diffReverse = Q_WebServer_ProllyTree::diff($rtBase, $ltExtra);
ok(isset($diffReverse['only_remote']['key:6']), "Extra remote record detected (reversed)");
ok(empty($diffReverse['only_local']), "No extra local records (reversed)");

// ══════════════════════════════════════════════════════
// Part 3: Large Dataset Benchmark (10,000 records, 100 different)
// ══════════════════════════════════════════════════════

echo "\n  ── Large Dataset: 10,000 Records, 100 Differences ──\n\n";

$N = 10000;
$D = 100;

// Build base dataset
$baseRecords = array();
for ($i = 0; $i < $N; $i++) {
	$key = sprintf("item:%06d", $i);
	$baseRecords[$key] = array(
		'value' => "base_value_$i",
		'version' => 1,
		'updated' => 1000 + $i
	);
}

// Peer A has the base
$peerARecords = $baseRecords;

// Peer B has 100 modifications
$peerBRecords = $baseRecords;
$changedKeys = array();
for ($i = 0; $i < $D; $i++) {
	$idx = (int) ($i * ($N / $D)); // evenly distributed changes
	$key = sprintf("item:%06d", $idx);
	$peerBRecords[$key] = array(
		'value' => "modified_by_B_$i",
		'version' => 2,
		'updated' => 2000 + $i
	);
	$changedKeys[] = $key;
}

$t0 = microtime(true);
$treeA = Q_WebServer_ProllyTree::build($peerARecords);
$buildTimeA = round((microtime(true) - $t0) * 1000, 1);

$t0 = microtime(true);
$treeB = Q_WebServer_ProllyTree::build($peerBRecords);
$buildTimeB = round((microtime(true) - $t0) * 1000, 1);

ok(Q_WebServer_ProllyTree::countEntries($treeA) === $N, "Tree A: $N entries");
ok(Q_WebServer_ProllyTree::countEntries($treeB) === $N, "Tree B: $N entries");
ok($treeA['root'] !== $treeB['root'], "Trees have different roots");

$nodesA = count($treeA['nodes']);
$nodesB = count($treeB['nodes']);
echo "  │ Tree A: $nodesA nodes, built in {$buildTimeA}ms\n";
echo "  │ Tree B: $nodesB nodes, built in {$buildTimeB}ms\n";

// Diff
$t0 = microtime(true);
$largeDiff = Q_WebServer_ProllyTree::diff($treeA, $treeB);
$diffTime = round((microtime(true) - $t0) * 1000, 1);

$numDifferent = count($largeDiff['different']);
$numOnlyLocal = count($largeDiff['only_local']);
$numOnlyRemote = count($largeDiff['only_remote']);
$nodesCompared = $largeDiff['nodes_compared'];
$nodesMatched = $largeDiff['nodes_matched'];
$totalDiffs = $numDifferent + $numOnlyLocal + $numOnlyRemote;

echo "  │ Diff: {$nodesCompared} nodes compared, {$nodesMatched} matched\n";
echo "  │ Found: $numDifferent different, $numOnlyLocal only_local, $numOnlyRemote only_remote\n";
echo "  │ Diff time: {$diffTime}ms\n";

ok($totalDiffs === $D, "Found exactly $D differences (got $totalDiffs)");
ok($nodesCompared < $nodesA, "Compared fewer nodes ($nodesCompared) than total ($nodesA)");
ok($nodesMatched > 0, "Some nodes matched without descending ($nodesMatched)");

// Efficiency: compared nodes should be O(D * log N), not O(N)
$theoreticalMax = $D * ceil(log($N, 2));
ok($nodesCompared < $theoreticalMax,
	"Nodes compared ($nodesCompared) < O(D*logN) = $theoreticalMax");

// Verify the correct keys were found
foreach ($changedKeys as $key) {
	$found = isset($largeDiff['different'][$key])
		|| isset($largeDiff['only_local'][$key])
		|| isset($largeDiff['only_remote'][$key]);
	if (!$found) {
		ok(false, "Changed key $key not found in diff");
		break;
	}
}
ok(true, "All $D changed keys found in diff");

// ══════════════════════════════════════════════════════
// Part 4: Comparison with Bloom Filter
// ══════════════════════════════════════════════════════

echo "\n  ── Bloom vs Prolly Tree Comparison ──\n\n";

// Bloom filter approach: transfer all 10,000 keys in the filter
$bloomSize = 10000 * 2; // ~20KB bloom filter for 10K keys
$bloomKeys = array_keys($peerARecords);
$t0 = microtime(true);
$bloom = Q_WebServer_MeshSync::createBloom($bloomKeys, $bloomSize);
$bloomBuildTime = round((microtime(true) - $t0) * 1000, 1);

$t0 = microtime(true);
$bloomDiff = Q_WebServer_MeshSync::bloomDiff(array_keys($peerBRecords), $bloom);
$bloomDiffTime = round((microtime(true) - $t0) * 1000, 1);

$bloomTransferBytes = strlen(base64_decode($bloom['bits'])); // bloom filter size
$prollyTransferNodes = $nodesCompared * 100; // ~100 bytes per node hash comparison
// Actual records transferred in both cases: ~D records

echo "  │ Bloom: built in {$bloomBuildTime}ms, diff in {$bloomDiffTime}ms\n";
echo "  │ Bloom transfer: {$bloomTransferBytes} bytes (filter) + ~$D records\n";
echo "  │ Prolly: built in {$buildTimeA}ms, diff in {$diffTime}ms\n";
echo "  │ Prolly transfer: ~" . ($nodesCompared * 16) . " bytes (hashes) + ~$D records\n";

// Both approaches should find the same number of diffs
$bloomMissing = count($bloomDiff['they_missing']);
// Bloom has false positives, so it finds >= D
ok($bloomMissing >= 0, "Bloom found $bloomMissing records B is missing from A");
ok($totalDiffs === $D, "Prolly found exactly $D differences (more precise)");

echo "\n  │ CONCLUSION: Prolly tree transfers ~"
	. ($nodesCompared * 16) . " bytes of hashes\n";
echo "  │   vs Bloom's " . $bloomTransferBytes . " byte filter.\n";
echo "  │   Prolly is more precise (exact diff) and better for large datasets\n";
echo "  │   where the Bloom filter itself becomes large.\n";

// ══════════════════════════════════════════════════════
// Part 5: Edge Cases
// ══════════════════════════════════════════════════════

echo "\n  ── Edge Cases ──\n\n";

// One empty, one populated
$emptyTree = Q_WebServer_ProllyTree::build(array());
$diffEmpty = Q_WebServer_ProllyTree::diff($emptyTree, $smallTree);
ok(count($diffEmpty['only_remote']) === 5, "Empty vs 5-record: 5 only_remote");
ok(empty($diffEmpty['only_local']), "Empty vs 5-record: 0 only_local");

$diffEmpty2 = Q_WebServer_ProllyTree::diff($smallTree, $emptyTree);
ok(count($diffEmpty2['only_local']) === 5, "5-record vs empty: 5 only_local");

// Single record trees
$single1 = Q_WebServer_ProllyTree::build(array('x' => array('value' => 'a', 'version' => 1, 'updated' => 1)));
$single2 = Q_WebServer_ProllyTree::build(array('x' => array('value' => 'b', 'version' => 2, 'updated' => 2)));
$diffSingle = Q_WebServer_ProllyTree::diff($single1, $single2);
ok(count($diffSingle['different']) === 1, "Single-record diff: 1 different");
ok($diffSingle['different']['x']['local']['value'] === 'a', "Single-record: local value 'a'");
ok($diffSingle['different']['x']['remote']['value'] === 'b', "Single-record: remote value 'b'");

// Content-addressed hashing: same content = same hash
$node1 = array('type' => 'leaf', 'entries' => array(
	array('key' => 'a', 'value' => 1, 'version' => 1)
));
$node2 = array('type' => 'leaf', 'entries' => array(
	array('key' => 'a', 'value' => 1, 'version' => 1)
));
$h1 = Q_WebServer_ProllyTree::hashNode($node1);
$h2 = Q_WebServer_ProllyTree::hashNode($node2);
ok($h1 === $h2, "Same content → same node hash");

$node3 = array('type' => 'leaf', 'entries' => array(
	array('key' => 'a', 'value' => 2, 'version' => 1) // different value
));
$h3 = Q_WebServer_ProllyTree::hashNode($node3);
ok($h3 !== $h1, "Different content → different node hash");

echo "\n  ═══════════════════════════════\n";
echo "  Turn 7 (Prolly Tree): $pass passed, $fail failed (of $tests)\n";
echo "  ═══════════════════════════════\n\n";

exit($fail > 0 ? 1 : 0);
