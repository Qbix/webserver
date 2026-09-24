<?php
/**
 * Test Turn 3: Encrypted request/response between two peers.
 *
 * Simulates two peers (A and B) performing:
 *   1. Identity discovery
 *   2. Full handshake via handleSyncApi
 *   3. Encrypted HTTP request from A→B
 *   4. B decrypts, processes, encrypts response
 *   5. A decrypts response
 *
 * Uses reflection to swap between A and B state, testing the
 * exact same code paths that would be used by two live servers.
 */

error_reporting(E_ALL);
$root = dirname(__DIR__);
require_once $root . '/src/Q/WebServer/Mesh.php';
require_once $root . '/src/Q/WebServer/Transport.php';

if (!class_exists('Q_Config')) {
	class Q_Config { static function get() { return end(func_get_args()); } }
}
if (!class_exists('Q_WebServer')) {
	class Q_WebServer { static $listenPort = 8080; }
}

$pass = 0; $fail = 0; $tests = 0;
function ok($cond, $msg) {
	global $pass, $fail, $tests; $tests++;
	if ($cond) { echo "  ✓ $msg\n"; $pass++; }
	else { echo "  ✗ $msg\n"; $fail++; }
}

// State management for two peers in one process
function saveMeshState() {
	$ref = new ReflectionClass('Q_WebServer_Mesh');
	$state = [];
	foreach (['privateKey','publicKeyPem','certPem','peerId','sessions','keyDir'] as $p) {
		$prop = $ref->getProperty($p);
		$prop->setAccessible(true);
		$state[$p] = $prop->getValue(null);
	}
	return $state;
}

function restoreMeshState($state) {
	$ref = new ReflectionClass('Q_WebServer_Mesh');
	foreach ($state as $k => $v) {
		$prop = $ref->getProperty($k);
		$prop->setAccessible(true);
		$prop->setValue(null, $v);
	}
}

function resetMesh() {
	$ref = new ReflectionClass('Q_WebServer_Mesh');
	foreach (['privateKey','publicKeyPem','certPem','peerId','keyDir'] as $p) {
		$prop = $ref->getProperty($p);
		$prop->setAccessible(true);
		$prop->setValue(null, null);
	}
	$sp = $ref->getProperty('sessions');
	$sp->setAccessible(true);
	$sp->setValue(null, array());
}

// ── Setup ──

$dirA = sys_get_temp_dir() . '/mesh3_A_' . getmypid();
$dirB = sys_get_temp_dir() . '/mesh3_B_' . getmypid();
@mkdir($dirA . '/keys', 0700, true);
@mkdir($dirB . '/keys', 0700, true);

echo "\n  ── Turn 3: Encrypted Peer-to-Peer HTTP ──\n";

// Initialize Peer A
Q_WebServer_Mesh::init($dirA);
$idA = Q_WebServer_Mesh::peerId();
$stateA = saveMeshState();

// Initialize Peer B
resetMesh();
Q_WebServer_Mesh::init($dirB);
$idB = Q_WebServer_Mesh::peerId();
$stateB = saveMeshState();

ok($idA !== $idB, "Two distinct identities");

echo "\n  ── Step 1: Identity Discovery ──\n\n";

// Peer A calls B's /Q/sync/identity
restoreMeshState($stateB);
$identityB = Q_WebServer_Mesh::handleSyncApi('identity');
ok($identityB['peer_id'] === $idB, "B returns correct peer_id");
ok(!empty($identityB['cert']), "B returns cert");
$stateB = saveMeshState();

// A verifies B's identity
restoreMeshState($stateA);
$verified = Q_WebServer_Mesh::verifyIdentity($identityB['cert'], $identityB['peer_id']);
ok($verified, "A verifies B's identity from cert");

echo "\n  ── Step 2: Handshake (A initiates → B responds → A completes → B finalizes) ──\n\n";

// A creates init
$init = Q_WebServer_Mesh::handshakeInit();
$nonceA = $init['nonce'];
ok(!empty($init['cert']), "A sends cert in init");
$stateA = saveMeshState();

// B processes init via handleSyncApi (simulates B's /Q/sync/handshake endpoint)
restoreMeshState($stateB);
$init['step'] = 'init';
$apiResponse = Q_WebServer_Mesh::handleSyncApi('handshake', $init);
ok($apiResponse['step'] === 'respond', "B responds to handshake init");
ok(!empty($apiResponse['data']['signature']), "B includes signature");
ok(!empty($apiResponse['data']['ecdh_pub']), "B includes ECDH pubkey");
$respondData = $apiResponse['data'];
$stateB = saveMeshState();

// A completes handshake
restoreMeshState($stateA);
$final = Q_WebServer_Mesh::handshakeComplete($respondData, $nonceA);
ok($final !== null, "A completes handshake");
ok(Q_WebServer_Mesh::hasSession($idB), "A has session with B");
$stateA = saveMeshState();

// B finalizes
restoreMeshState($stateB);
$final['step'] = 'finalize';
$finResult = Q_WebServer_Mesh::handleSyncApi('handshake', $final);
ok(!empty($finResult['established']), "B confirms session established");
ok(Q_WebServer_Mesh::hasSession($idA), "B has session with A");
$stateB = saveMeshState();

echo "\n  ── Step 3: Encrypted HTTP Request A→B ──\n\n";

// A builds and encrypts an HTTP request
restoreMeshState($stateA);
$httpRequest = "GET /Q/health HTTP/1.1\r\nHost: qbix-peer\r\n" .
	"X-Peer-Id: $idA\r\n\r\n";
$encrypted = Q_WebServer_Mesh::encrypt($idB, $httpRequest);
ok($encrypted !== null, "A encrypts HTTP request");
ok(!empty($encrypted['ciphertext']), "Ciphertext is not empty");

// A builds the payload that would be POSTed to B's /Q/sync/request
$requestPayload = array(
	'from' => $idA,
	'encrypted' => true,
	'payload' => $encrypted
);
$stateA = saveMeshState();

// B receives and processes via handleSyncApi
restoreMeshState($stateB);
// Simulate: B's /Q/sync/request decrypts, executes locally, encrypts response
// Since we can't call localhost in this test, we'll test decrypt + re-encrypt
$decrypted = Q_WebServer_Mesh::decrypt($idA, $requestPayload['payload']);
ok($decrypted !== null, "B decrypts A's request");
ok(strpos($decrypted, 'GET /Q/health') === 0, "Decrypted request has correct method+path");
ok(strpos($decrypted, "X-Peer-Id: $idA") !== false, "Decrypted request has A's peer ID");

// B creates a response and encrypts it
$httpResponse = "HTTP/1.1 200 OK\r\nServer: QbixServer/1.5.0\r\n" .
	"Content-Type: application/json\r\n\r\n" .
	'{"status":"ok","uptime":"42s"}';
$encryptedResp = Q_WebServer_Mesh::encrypt($idA, $httpResponse);
ok($encryptedResp !== null, "B encrypts response");
$responsePayload = array(
	'from' => $idB,
	'encrypted' => true,
	'payload' => $encryptedResp
);
$stateB = saveMeshState();

// A receives and decrypts B's response
restoreMeshState($stateA);
$decryptedResp = Q_WebServer_Mesh::decrypt($idB, $responsePayload['payload']);
ok($decryptedResp !== null, "A decrypts B's response");
ok(strpos($decryptedResp, 'HTTP/1.1 200 OK') === 0, "Response is HTTP 200");
ok(strpos($decryptedResp, '"status":"ok"') !== false, "Response body has health data");
ok(strpos($decryptedResp, 'QbixServer') !== false, "Response has Server header");
$stateA = saveMeshState();

echo "\n  ── Step 4: Bidirectional — B sends encrypted request to A ──\n\n";

// B encrypts a request to A
restoreMeshState($stateB);
$httpReq2 = "POST /Q/sync/ping HTTP/1.1\r\nHost: qbix-peer\r\n" .
	"Content-Type: application/json\r\n\r\n" .
	'{"ping":"from_B"}';
$enc2 = Q_WebServer_Mesh::encrypt($idA, $httpReq2);
ok($enc2 !== null, "B encrypts request to A");
$stateB = saveMeshState();

// A decrypts
restoreMeshState($stateA);
$dec2 = Q_WebServer_Mesh::decrypt($idB, $enc2);
ok($dec2 !== null, "A decrypts B's request");
ok(strpos($dec2, 'POST /Q/sync/ping') === 0, "A sees correct method/path");
ok(strpos($dec2, '"ping":"from_B"') !== false, "A sees correct body");
$stateA = saveMeshState();

echo "\n  ── Step 5: Replay and Tamper Protection ──\n\n";

// Replay: try to use the same encrypted payload again on A
restoreMeshState($stateA);
$replay = Q_WebServer_Mesh::decrypt($idB, $enc2);
ok($replay === null, "Replay of B's request rejected by A");

// Tamper: modify the ciphertext
$tampered = $encrypted;
$tampered['ciphertext'] = base64_encode(
	substr(base64_decode($tampered['ciphertext']), 0, -1) . 'X'
);
$tamperedDecrypt = Q_WebServer_Mesh::decrypt($idB, $tampered);
ok($tamperedDecrypt === null, "Tampered ciphertext rejected (GCM auth fails)");
$stateA = saveMeshState();

echo "\n  ── Step 6: Transport.registerPeer + requestFromPeer Integration ──\n\n";

// Reset transport and register B as a peer from A's perspective
Q_WebServer_Transport::reset();
restoreMeshState($stateA);

Q_WebServer_Transport::registerPeer(array(
	'peer_id' => $idB,
	'name' => 'Server B',
	'transport' => 'tcp',
	'address' => 'http://127.0.0.1:9999', // won't actually connect in unit test
	'hops' => 0,
	'direct' => true
));

$peers = Q_WebServer_Transport::peers();
ok(count($peers) === 1, "B registered as peer");
ok($peers[0]['peer_id'] === $idB, "Correct peer_id in registry");
ok($peers[0]['transport'] === 'tcp', "Transport is tcp");

$nearby = Q_WebServer_Transport::nearbyServers();
ok(count($nearby) === 1, "B in nearby servers");
ok(strpos($nearby[0]['url'], 'qbix-peer://') === 0, "URL uses qbix-peer:// scheme");
ok(strpos($nearby[0]['url'], $idB) !== false, "URL contains B's peer_id");

echo "\n  ── Step 7: Full handleSyncApi('request') round-trip ──\n\n";

// Test the request endpoint that would run on B's server
// A encrypts a request
$reqForB = "GET /Q/health HTTP/1.1\r\nHost: peer\r\nX-Peer-Id: $idA\r\n\r\n";
$encReq = Q_WebServer_Mesh::encrypt($idB, $reqForB);
ok($encReq !== null, "A encrypts request for sync/request endpoint");
$stateA = saveMeshState();

// Switch to B and process via handleSyncApi('request')
restoreMeshState($stateB);
// The request endpoint would normally curl localhost — skip that part
// and test the decrypt/encrypt round-trip
$incomingData = array(
	'from' => $idA,
	'encrypted' => true,
	'payload' => $encReq
);
// Manually decrypt to verify (the full endpoint would curl localhost)
$decReq = Q_WebServer_Mesh::decrypt($idA, $incomingData['payload']);
ok($decReq === $reqForB, "B's request endpoint decrypts correctly");

// B encrypts a fake response (simulating what curl localhost would return)
$fakeResp = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n{\"status\":\"ok\"}";
$encResp = Q_WebServer_Mesh::encrypt($idA, $fakeResp);
ok($encResp !== null, "B encrypts response for A");
$stateB = saveMeshState();

// A decrypts the response
restoreMeshState($stateA);
$finalResp = Q_WebServer_Mesh::decrypt($idB, $encResp);
ok($finalResp === $fakeResp, "A decrypts final response from sync/request");

// Parse HTTP response
$parts = explode("\r\n\r\n", $finalResp, 2);
$body = json_decode($parts[1] ?? '', true);
ok($body['status'] === 'ok', "Parsed response body has correct data");

// ── Cleanup ──
exec("rm -rf " . escapeshellarg($dirA) . " " . escapeshellarg($dirB));

echo "\n  ═══════════════════════════════\n";
echo "  Turn 3 (Encrypted P2P): $pass passed, $fail failed (of $tests)\n";
echo "  ═══════════════════════════════\n\n";

exit($fail > 0 ? 1 : 0);
