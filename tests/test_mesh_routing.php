<?php
/**
 * Test Turn 4: Multi-hop routing with three peers.
 *
 * Topology: A ←→ B ←→ C  (A and C cannot connect directly)
 *
 * Tests: route propagation, forwarding, route expiry,
 * deduplication, TTL, dampening, and heartbeat.
 */

error_reporting(E_ALL);
$root = dirname(__DIR__);
require_once $root . '/src/Q/WebServer/MeshRouter.php';
require_once $root . '/src/Q/WebServer/Mesh.php';

if (!class_exists('Q_Config')) {
	class Q_Config { static function get() { return end(func_get_args()); } }
}

$pass = 0; $fail = 0; $tests = 0;
function ok($cond, $msg) {
	global $pass, $fail, $tests; $tests++;
	if ($cond) { echo "  ✓ $msg\n"; $pass++; }
	else { echo "  ✗ $msg\n"; $fail++; }
}

// Peer IDs (fixed for deterministic testing)
$A = str_repeat('aa', 32); // 64 hex chars
$B = str_repeat('bb', 32);
$C = str_repeat('cc', 32);

// We'll test from B's perspective (the middle node)
// so Mesh::peerId() returns B's ID
$dirB = sys_get_temp_dir() . '/mesh4_B_' . getmypid();
@mkdir($dirB . '/keys', 0700, true);
Q_WebServer_Mesh::init($dirB);
// Override B's peer_id for testing (we need deterministic IDs)
$ref = new ReflectionClass('Q_WebServer_Mesh');
$pidProp = $ref->getProperty('peerId');
$pidProp->setAccessible(true);
$pidProp->setValue(null, $B);

echo "\n  ── Turn 4: Multi-Hop Routing ──\n";

// ── Test 1: Direct Peer Addition ──

echo "\n  ── Direct Peer Management ──\n\n";

Q_WebServer_MeshRouter::reset();

// B adds A as a direct peer
$msgs = Q_WebServer_MeshRouter::addDirectPeer($A, 'Peer A');
ok(Q_WebServer_MeshRouter::isDirect($A), "A is direct peer of B");
ok(Q_WebServer_MeshRouter::nextHop($A) === $A, "Next hop to A is A (direct)");
ok(Q_WebServer_MeshRouter::hopsTo($A) === 0, "Hops to A = 0");

// Check generated messages
ok(!empty($msgs['for_others']), "HELLO for others generated");
ok(!empty($msgs['for_new_peer']), "Route info for new peer generated");

// B adds C as a direct peer
$msgs2 = Q_WebServer_MeshRouter::addDirectPeer($C, 'Peer C');
ok(Q_WebServer_MeshRouter::isDirect($C), "C is direct peer of B");
ok(count(Q_WebServer_MeshRouter::knownPeers()) === 2, "B knows 2 peers");

// Check that C gets told about A
$forC = $msgs2['for_new_peer'];
$aboutA = array_filter($forC, function ($m) use ($A) { return ($m['peer_id'] ?? '') === $A; });
ok(count($aboutA) > 0, "C is told about A");

// Check that A gets told about C (in for_others)
$forOthers = $msgs2['for_others'];
$aboutC = array_filter($forOthers, function ($m) use ($C) { return ($m['peer_id'] ?? '') === $C; });
ok(count($aboutC) > 0, "Others (A) told about new peer C");

// ── Test 2: HELLO Propagation ──

echo "\n  ── HELLO Propagation (Route Learning) ──\n\n";

Q_WebServer_MeshRouter::reset();

// Simulate: B is connected to A and C.
// A sends a HELLO about itself to B.
Q_WebServer_MeshRouter::addDirectPeer($A, 'Peer A');
Q_WebServer_MeshRouter::addDirectPeer($C, 'Peer C');

// Now A tells B about a new peer D (that A is directly connected to)
$D = str_repeat('dd', 32);
$helloD = array(
	'type' => 'hello',
	'peer_id' => $D,
	'name' => 'Peer D',
	'hops' => 0,       // D is 0 hops from A
	'ttl' => 7,
	'msg_id' => 'msg001'
);

$propagate = Q_WebServer_MeshRouter::handleHello($A, $helloD);
ok(Q_WebServer_MeshRouter::nextHop($D) === $A, "B routes to D via A");
ok(Q_WebServer_MeshRouter::hopsTo($D) === 1, "D is 1 hop from B (through A)");
ok(count($propagate) === 1, "B propagates HELLO for D");
ok($propagate[0]['hops'] === 1, "Propagated HELLO has hops=1");
ok($propagate[0]['ttl'] === 6, "Propagated HELLO has ttl=6");

// C would receive the propagated HELLO
$propagate2 = Q_WebServer_MeshRouter::handleHello($C, $propagate[0]);
// B already knows about D, so this is from C's perspective.
// But since we're running as B, B already has the route, so it won't
// update. In real deployment, C would be a separate process.
// What matters is B's routing table is correct.

ok(count(Q_WebServer_MeshRouter::knownPeers()) === 3, "B knows A, C, D (3 peers)");

// ── Test 3: Deduplication ──

echo "\n  ── Deduplication ──\n\n";

// Same HELLO again (same msg_id) — should be rejected
$dup = Q_WebServer_MeshRouter::handleHello($A, $helloD);
ok(count($dup) === 0, "Duplicate HELLO (same msg_id) is rejected");

// Different msg_id but same peer — updates last_seen, doesn't propagate
// if route is same or worse
$helloD2 = $helloD;
$helloD2['msg_id'] = 'msg002';
$helloD2['hops'] = 2; // worse route
$prop3 = Q_WebServer_MeshRouter::handleHello($A, $helloD2);
ok(count($prop3) === 0, "Worse route (more hops) not propagated");
ok(Q_WebServer_MeshRouter::hopsTo($D) === 1, "Better route retained");

// ── Test 4: TTL Expiry ──

echo "\n  ── TTL ──\n\n";

$E = str_repeat('ee', 32);
$helloE = array(
	'type' => 'hello',
	'peer_id' => $E,
	'name' => 'Peer E',
	'hops' => 5,
	'ttl' => 1,    // will expire after this hop
	'msg_id' => 'msg003'
);
$propE = Q_WebServer_MeshRouter::handleHello($A, $helloE);
ok(Q_WebServer_MeshRouter::nextHop($E) === $A, "E added to routing table");
ok(Q_WebServer_MeshRouter::hopsTo($E) === 6, "E is 6 hops away");
ok(count($propE) === 0, "HELLO with TTL=1 not propagated further");

$F = str_repeat('ff', 32);
$helloF = array(
	'type' => 'hello',
	'peer_id' => $F,
	'name' => 'Peer F',
	'hops' => 6,
	'ttl' => 0,    // already expired
	'msg_id' => 'msg004'
);
$propF = Q_WebServer_MeshRouter::handleHello($A, $helloF);
ok(Q_WebServer_MeshRouter::nextHop($F) === $A, "F added (TTL=0 still adds route)");
ok(count($propF) === 0, "TTL=0 HELLO not propagated");

// ── Test 5: BYE Messages ──

echo "\n  ── BYE (Route Withdrawal) ──\n\n";

$byeD = array(
	'type' => 'bye',
	'peer_id' => $D,
	'reason' => 'disconnected',
	'msg_id' => 'msg005',
	'ttl' => 7
);
$propBye = Q_WebServer_MeshRouter::handleBye($A, $byeD);
ok(Q_WebServer_MeshRouter::nextHop($D) === null, "Route to D removed after BYE");
ok(count($propBye) === 1, "BYE propagated to other peers");
ok($propBye[0]['ttl'] === 6, "Propagated BYE has ttl-1");

// BYE from wrong neighbor (route doesn't go through C)
// First re-add D via A
$helloD3 = $helloD;
$helloD3['msg_id'] = 'msg006';
Q_WebServer_MeshRouter::handleHello($A, $helloD3);
ok(Q_WebServer_MeshRouter::nextHop($D) === $A, "D re-added via A");

$byeFromWrong = array(
	'type' => 'bye', 'peer_id' => $D,
	'msg_id' => 'msg007', 'ttl' => 7
);
Q_WebServer_MeshRouter::handleBye($C, $byeFromWrong); // C says D is gone, but our route goes through A
ok(Q_WebServer_MeshRouter::nextHop($D) === $A, "BYE from wrong neighbor ignored");

// ── Test 6: Remove Direct Peer (Cascade) ──

echo "\n  ── Remove Direct Peer (Cascade) ──\n\n";

// D is routed through A. If we remove A, D should also be removed.
$byes = Q_WebServer_MeshRouter::removeDirectPeer($A);
ok(Q_WebServer_MeshRouter::nextHop($A) === null, "A removed from routing table");
ok(Q_WebServer_MeshRouter::nextHop($D) === null, "D removed (was routed through A)");
ok(!Q_WebServer_MeshRouter::isDirect($A), "A no longer direct");
ok(count($byes) >= 2, "BYE messages generated for A and cascaded peers");

// C should still be there
ok(Q_WebServer_MeshRouter::nextHop($C) === $C, "C still reachable");

// ── Test 7: HEARTBEAT ──

echo "\n  ── Heartbeat ──\n\n";

// Re-add A
Q_WebServer_MeshRouter::addDirectPeer($A, 'Peer A');

$heartbeat = array(
	'known_peers' => array($A, $D, $C),  // A knows about D which we don't have anymore
	'timestamp' => time()
);
$unknown = Q_WebServer_MeshRouter::handleHeartbeat($A, $heartbeat);
ok(in_array($D, $unknown), "Heartbeat reveals unknown peer D");
ok(!in_array($A, $unknown), "Known peer A not in unknown list");
ok(!in_array($C, $unknown), "Known peer C not in unknown list");

// Generate our own heartbeat
$hb = Q_WebServer_MeshRouter::generateHeartbeat();
ok($hb['type'] === 'heartbeat', "Heartbeat has correct type");
ok($hb['peer_id'] === $B, "Heartbeat has our peer_id");
ok(is_array($hb['known_peers']), "Heartbeat has known_peers list");

// ── Test 8: FORWARD (Multi-Hop Message Relay) ──

echo "\n  ── FORWARD (Message Relay) ──\n\n";

Q_WebServer_MeshRouter::reset();
Q_WebServer_MeshRouter::addDirectPeer($A, 'Peer A');
Q_WebServer_MeshRouter::addDirectPeer($C, 'Peer C');

// Add D reachable through A
$helloD4 = $helloD;
$helloD4['msg_id'] = 'msg008';
Q_WebServer_MeshRouter::handleHello($A, $helloD4);

// Create a forward: A wants to send to C (through us)
$fwd = Q_WebServer_MeshRouter::createForward($A, $C, array(
	'nonce' => 'abc123',
	'ciphertext' => 'encrypted_data_here',
	'tag' => 'gcm_tag_here'
));
ok($fwd !== null, "Forward created for A→C");
ok($fwd['next_hop'] === $C, "Next hop is C (direct)");
ok($fwd['ttl'] === 7, "Forward has max TTL");

// Process the forward as B (we're the relay)
$result = Q_WebServer_MeshRouter::handleForward($A, $fwd, $B);
ok($result['action'] === 'relay', "B relays the forward");
ok($result['next_hop'] === $C, "Relay next hop is C");
ok($result['message']['ttl'] === 6, "Relayed message has ttl-1");

// If we were C, it would be delivered
// Note: in real deployment C has its own seen-message cache.
// We simulate by using the relayed message which has the same msg_id
// but we already saw it as B. Reset seen cache for this test.
$refRouter = new ReflectionClass('Q_WebServer_MeshRouter');
$seenProp = $refRouter->getProperty('seenMessages');
$seenProp->setAccessible(true);
$savedSeen = $seenProp->getValue(null);
$seenProp->setValue(null, array()); // clear for C's perspective

$resultC = Q_WebServer_MeshRouter::handleForward($B, $result['message'], $C);
ok($resultC['action'] === 'deliver', "C receives the delivery");
ok($resultC['from'] === $A, "Delivery shows original sender A");
ok($resultC['payload']['ciphertext'] === 'encrypted_data_here', "Payload intact");

$seenProp->setValue(null, $savedSeen); // restore B's seen cache

// Forward to unknown peer — no route
$fwdBad = Q_WebServer_MeshRouter::createForward($A, str_repeat('99', 32), array());
ok($fwdBad === null, "Forward to unknown peer returns null");

// Forward with TTL=1 — relay drops it
$fwdLowTtl = $fwd;
$fwdLowTtl['ttl'] = 1;
$fwdLowTtl['msg_id'] = 'msg009';
$resultLow = Q_WebServer_MeshRouter::handleForward($A, $fwdLowTtl, $B);
ok($resultLow['action'] === 'drop', "TTL=1 forward dropped at relay");
ok($resultLow['reason'] === 'ttl_expired', "Drop reason is ttl_expired");

// Duplicate forward — dropped
$fwdDup = $fwd; // same msg_id as the first forward
$resultDup = Q_WebServer_MeshRouter::handleForward($A, $fwdDup, $B);
ok($resultDup['action'] === 'drop', "Duplicate forward dropped");
ok($resultDup['reason'] === 'duplicate', "Drop reason is duplicate");

// ── Test 9: Multi-Hop Forward (A → B → C, 2-hop path to D) ──

echo "\n  ── Multi-Hop Path: A→B→A→D ──\n\n";

// A wants to reach D. B knows D is through A.
// But this means A should already know D directly.
// More realistic: C wants to reach D (C→B→A→D path)
$fwdCD = Q_WebServer_MeshRouter::createForward($C, $D, array(
	'nonce' => 'def456',
	'ciphertext' => 'msg_for_D',
	'tag' => 'tag456'
));
ok($fwdCD !== null, "Forward C→D created");
ok($fwdCD['next_hop'] === $A, "B routes C→D via A");

// B relays to A
$relayResult = Q_WebServer_MeshRouter::handleForward($C, $fwdCD, $B);
ok($relayResult['action'] === 'relay', "B relays C→D");
ok($relayResult['next_hop'] === $A, "Next hop is A");

// ── Test 10: Routing Table Inspection ──

echo "\n  ── Routing Table ──\n\n";

$table = Q_WebServer_MeshRouter::routingTable();
ok(isset($table[$A]), "A in routing table");
ok(isset($table[$C]), "C in routing table");
ok(isset($table[$D]), "D in routing table");
ok($table[$A]['hops'] === 0, "A is 0 hops (direct)");
ok($table[$C]['hops'] === 0, "C is 0 hops (direct)");
ok($table[$D]['hops'] === 1, "D is 1 hop (through A)");
ok($table[$D]['next_hop'] === $A, "D routes through A");

$known = Q_WebServer_MeshRouter::knownPeers();
ok(count($known) === 3, "3 known peers total");

// ── Cleanup ──
exec("rm -rf " . escapeshellarg($dirB));

echo "\n  ═══════════════════════════════\n";
echo "  Turn 4 (Routing): $pass passed, $fail failed (of $tests)\n";
echo "  ═══════════════════════════════\n\n";

exit($fail > 0 ? 1 : 0);
