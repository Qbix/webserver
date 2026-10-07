<?php
/**
 * Test: Transport peer registry + events.
 *
 * Tests peer registration, events (onPeerOnline/Offline/Message),
 * heartbeat, pruning, and API endpoints using two simulated peers.
 *
 * Usage: php tests/test_transport.php
 */

error_reporting(E_ALL);
$root = dirname(__DIR__);
require_once $root . '/src/Q/WebServer/Mesh.php';
require_once $root . '/src/Q/WebServer/Transport.php';

// Minimal stubs
if (!class_exists('Q_Config')) {
	class Q_Config {
		static function get() { return end(func_get_args()); }
	}
}
if (!class_exists('Q_WebServer')) {
	class Q_WebServer { static $listenPort = 8080; }
}

$pass = 0; $fail = 0; $tests = 0;
function assert_true($cond, $msg) {
	global $pass, $fail, $tests; $tests++;
	if ($cond) { echo "  ✓ $msg\n"; $pass++; }
	else { echo "  ✗ $msg\n"; $fail++; }
}
function assert_eq($a, $b, $msg) {
	assert_true($a === $b, "$msg (got: " . json_encode($a) . ")");
}

// Use temp dir for keys
$tmpDir = sys_get_temp_dir() . '/transport_test_' . getmypid();
@mkdir($tmpDir . '/keys', 0700, true);

// ── Initialize ──

echo "\n  ── Transport Init Tests ──\n\n";

Q_WebServer_Transport::reset();
Q_WebServer_Mesh::init($tmpDir);

$meshId = Q_WebServer_Mesh::peerId();
assert_true(strlen($meshId) === 64, "Mesh identity initialized");

$status = Q_WebServer_Transport::handleApi('status');
assert_eq($status['mesh_id'], $meshId, "Status returns mesh ID");
assert_eq($status['count'], 0, "No peers initially");
assert_true(is_array($status['transports']), "Status has transports");
assert_true($status['transports']['tcp'] === true, "TCP enabled by default");

// ── Event System ──

echo "\n  ── Event System Tests ──\n\n";

$onlineEvents = array();
$offlineEvents = array();
$messageEvents = array();

Q_WebServer_Transport::onPeerOnline(function ($peer) use (&$onlineEvents) {
	$onlineEvents[] = $peer;
});
Q_WebServer_Transport::onPeerOffline(function ($peer) use (&$offlineEvents) {
	$offlineEvents[] = $peer;
});
Q_WebServer_Transport::onPeerMessage(function ($data) use (&$messageEvents) {
	$messageEvents[] = $data;
});

// ── Peer Registration ──

echo "\n  ── Peer Registration Tests ──\n\n";

$peerB = array(
	'peer_id' => str_repeat('b', 64),
	'name' => 'Test Peer B',
	'transport' => 'tcp',
	'address' => 'http://127.0.0.1:9999',
	'hops' => 0,
	'direct' => true,
	'capabilities' => array('sync', 'relay')
);

$result = Q_WebServer_Transport::registerPeer($peerB);
assert_true($result['registered'], "Peer B registered");
assert_true($result['new'], "Peer B is new");
assert_eq(count($onlineEvents), 1, "onPeerOnline fired once");
assert_eq($onlineEvents[0]['name'], 'Test Peer B', "Online event has correct name");
assert_eq($onlineEvents[0]['transport'], 'tcp', "Online event has correct transport");

// Register again — should NOT fire online event again
$result2 = Q_WebServer_Transport::registerPeer($peerB);
assert_true($result2['registered'], "Peer B re-registered");
assert_true(!$result2['new'], "Peer B is not new on re-register");
assert_eq(count($onlineEvents), 1, "onPeerOnline did NOT fire again for existing peer");

// Register a second peer
$peerC = array(
	'peer_id' => str_repeat('c', 64),
	'name' => 'Test Peer C',
	'transport' => 'ble',
	'hops' => 2,
	'direct' => false
);
Q_WebServer_Transport::registerPeer($peerC);
assert_eq(count($onlineEvents), 2, "onPeerOnline fired for second peer");

// ── Peer Query ──

echo "\n  ── Peer Query Tests ──\n\n";

$allPeers = Q_WebServer_Transport::peers();
assert_eq(count($allPeers), 2, "Two peers registered");

$tcpPeers = Q_WebServer_Transport::peers('tcp');
assert_eq(count($tcpPeers), 1, "One TCP peer");
assert_eq($tcpPeers[0]['name'], 'Test Peer B', "TCP peer is B");

$blePeers = Q_WebServer_Transport::peers('ble');
assert_eq(count($blePeers), 1, "One BLE peer");
assert_eq($blePeers[0]['hops'], 2, "BLE peer has 2 hops");
assert_true(!$blePeers[0]['direct'], "BLE peer is not direct");

$specific = Q_WebServer_Transport::peer(str_repeat('b', 64));
assert_true($specific !== null, "Can fetch specific peer by ID");
assert_eq($specific['name'], 'Test Peer B', "Specific peer has correct name");

$missing = Q_WebServer_Transport::peer(str_repeat('x', 64));
assert_true($missing === null, "Unknown peer returns null");

assert_eq(Q_WebServer_Transport::peerCount(), 2, "peerCount is 2");

// ── Heartbeat ──

echo "\n  ── Heartbeat Tests ──\n\n";

$hbResult = Q_WebServer_Transport::heartbeat(str_repeat('b', 64));
assert_true($hbResult['ok'] ?? false, "Heartbeat succeeds for known peer");

$hbFail = Q_WebServer_Transport::heartbeat(str_repeat('x', 64));
assert_true(isset($hbFail['error']), "Heartbeat fails for unknown peer");

// ── Messages ──

echo "\n  ── Message Tests ──\n\n";

$msgResult = Q_WebServer_Transport::deliverMessage(
	str_repeat('b', 64),
	array('type' => 'sync', 'data' => 'test payload')
);
assert_true($msgResult['delivered'] ?? false, "Message delivered");
assert_eq(count($messageEvents), 1, "onPeerMessage fired");
assert_eq($messageEvents[0]['peer_id'], str_repeat('b', 64), "Message from correct peer");
assert_eq($messageEvents[0]['message']['type'], 'sync', "Message has correct type");

$msgFail = Q_WebServer_Transport::deliverMessage(str_repeat('x', 64), 'test');
assert_true(isset($msgFail['error']), "Message to unknown peer fails");

// ── Unregister (Offline) ──

echo "\n  ── Unregister Tests ──\n\n";

$unregResult = Q_WebServer_Transport::unregisterPeer(str_repeat('b', 64), 'test');
assert_true($unregResult['unregistered'] ?? false, "Peer B unregistered");
assert_eq(count($offlineEvents), 1, "onPeerOffline fired");
assert_eq($offlineEvents[0]['name'], 'Test Peer B', "Offline event has correct name");
assert_eq($offlineEvents[0]['reason'], 'test', "Offline event has reason");
assert_eq(Q_WebServer_Transport::peerCount(), 1, "One peer remaining after unregister");
assert_true(Q_WebServer_Transport::peer(str_repeat('b', 64)) === null, "Peer B gone from registry");

// ── API Endpoint Tests ──

echo "\n  ── API Endpoint Tests ──\n\n";

// Peers API
$peersApi = Q_WebServer_Transport::handleApi('peers');
assert_eq($peersApi['count'], 1, "API peers count is 1");
assert_true(!empty($peersApi['mesh_id']), "API peers has mesh_id");

// Register via API
$regApi = Q_WebServer_Transport::handleApi('register', array(
	'peer_id' => str_repeat('d', 64),
	'name' => 'API Peer D',
	'transport' => 'multipeer'
));
assert_true($regApi['registered'] ?? false, "API register works");
assert_eq(count($onlineEvents), 3, "onPeerOnline fired via API register");

// Event endpoint
$eventApi = Q_WebServer_Transport::handleApi('event', array(
	'type' => 'online',
	'peer_id' => str_repeat('e', 64),
	'name' => 'Event Peer E',
	'transport' => 'ble'
));
assert_true($eventApi['registered'] ?? false, "Event API (online) works");

$eventOffline = Q_WebServer_Transport::handleApi('event', array(
	'type' => 'offline',
	'peer_id' => str_repeat('e', 64),
	'reason' => 'api_test'
));
assert_true($eventOffline['unregistered'] ?? false, "Event API (offline) works");

// Config API
$configApi = Q_WebServer_Transport::handleApi('config', array(
	'ble' => true
));
assert_true($configApi['transports']['ble'] === true, "Config API enables BLE");

$configRead = Q_WebServer_Transport::handleApi('config');
assert_true($configRead['transports']['ble'] === true, "Config persisted");

// Status API
$statusApi = Q_WebServer_Transport::handleApi('status');
assert_true(!empty($statusApi['mesh_id']), "Status API has mesh_id");
assert_true(is_array($statusApi['capabilities']), "Status API has capabilities");
assert_true(is_array($statusApi['sessions']), "Status API has sessions list");

// nearbyServers
$nearby = Q_WebServer_Transport::nearbyServers();
assert_true(is_array($nearby), "nearbyServers returns array");
// Should have peers C and D (B was unregistered, E was unregistered)
$nearbyIds = array_column($nearby, 'peer_id');
assert_true(in_array(str_repeat('c', 64), $nearbyIds), "nearbyServers includes peer C");
assert_true(in_array(str_repeat('d', 64), $nearbyIds), "nearbyServers includes peer D");
$cServer = array_values(array_filter($nearby, function ($s) {
	return $s['peer_id'] === str_repeat('c', 64);
}))[0] ?? null;
assert_true(strpos($cServer['url'] ?? '', 'qbix-peer://') === 0, "nearbyServers URL uses qbix-peer:// scheme");

// ── Cleanup ──
exec("rm -rf " . escapeshellarg($tmpDir));

echo "\n  ═══════════════════════════════\n";
echo "  Transport: $pass passed, $fail failed (of $tests)\n";
echo "  ═══════════════════════════════\n\n";

exit($fail > 0 ? 1 : 0);
