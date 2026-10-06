<?php
/**
 * Test: Mesh identity + handshake + encrypted session.
 *
 * Simulates two peers (A and B) performing the full handshake
 * and exchanging encrypted messages. Runs in a single process
 * by using separate data directories for each peer.
 *
 * Usage: php tests/test_mesh.php
 */

// Bootstrap — load just enough to run Mesh.php
error_reporting(E_ALL);
$root = dirname(__DIR__);
require_once $root . '/src/Q/WebServer/Mesh.php';

// Minimal Q_Config stub
class Q_Config {
	static function get() {
		$args = func_get_args();
		return end($args); // return default
	}
}

$pass = 0;
$fail = 0;
$tests = 0;

function assert_true($cond, $msg) {
	global $pass, $fail, $tests;
	$tests++;
	if ($cond) {
		echo "  ✓ $msg\n";
		$pass++;
	} else {
		echo "  ✗ $msg\n";
		$fail++;
	}
}

function assert_eq($a, $b, $msg) {
	assert_true($a === $b, "$msg (got: " . var_export($a, true) . ")");
}

// ── Setup: two separate peers with different key directories ──

$dirA = sys_get_temp_dir() . '/mesh_test_A_' . getmypid();
$dirB = sys_get_temp_dir() . '/mesh_test_B_' . getmypid();
@mkdir($dirA . '/keys', 0700, true);
@mkdir($dirB . '/keys', 0700, true);

// We need two separate instances of Mesh. Since it's static,
// we simulate by initializing, extracting state, resetting, repeat.
// Better approach: use the API endpoints directly (JSON in, JSON out).

echo "\n  ── Mesh Identity Tests ──\n\n";

// ── Peer A: generate identity ──
Q_WebServer_Mesh::init($dirA);
$idA = Q_WebServer_Mesh::peerId();
$certA = Q_WebServer_Mesh::certPem();
$pubA = Q_WebServer_Mesh::publicKeyPem();

assert_true(strlen($idA) === 64, "Peer A ID is 64 hex chars");
assert_true(ctype_xdigit($idA), "Peer A ID is valid hex");
assert_true(strpos($certA, 'BEGIN CERTIFICATE') !== false, "Peer A has X.509 cert");
assert_true(strpos($pubA, 'BEGIN PUBLIC KEY') !== false, "Peer A has public key");

// Verify ID matches the cert
$derivedA = Q_WebServer_Mesh::deriveId($certA);
assert_eq($derivedA, $idA, "Peer A: deriveId(cert) matches peerId()");

// Verify identity verification
assert_true(Q_WebServer_Mesh::verifyIdentity($certA, $idA), "verifyIdentity succeeds with correct ID");
assert_true(!Q_WebServer_Mesh::verifyIdentity($certA, str_repeat('0', 64)), "verifyIdentity fails with wrong ID");

// Reload same peer — should get same ID (key persistence)
$reflection = new ReflectionClass('Q_WebServer_Mesh');
foreach (['privateKey', 'publicKeyPem', 'certPem', 'peerId', 'sessions', 'keyDir'] as $prop) {
	$p = $reflection->getProperty($prop);
	$p->setAccessible(true);
	$p->setValue(null, null);
}
// Re-init the static sessions array
$sp = $reflection->getProperty('sessions');
$sp->setAccessible(true);
$sp->setValue(null, array());

Q_WebServer_Mesh::init($dirA);
assert_eq(Q_WebServer_Mesh::peerId(), $idA, "Peer A ID persists across reload");

echo "\n  ── Handshake Tests ──\n\n";

// ── Simulate two-peer handshake ──
// Since Mesh is static, we'll drive the handshake manually using the
// underlying methods, switching state between A and B via reflection.

// Save Peer A's full state
function saveState() {
	$ref = new ReflectionClass('Q_WebServer_Mesh');
	$state = [];
	foreach (['privateKey', 'publicKeyPem', 'certPem', 'peerId', 'sessions', 'keyDir'] as $prop) {
		$p = $ref->getProperty($prop);
		$p->setAccessible(true);
		$state[$prop] = $p->getValue(null);
	}
	return $state;
}

function restoreState($state) {
	$ref = new ReflectionClass('Q_WebServer_Mesh');
	foreach ($state as $prop => $val) {
		$p = $ref->getProperty($prop);
		$p->setAccessible(true);
		$p->setValue(null, $val);
	}
}

$stateA = saveState();

// Reset and init Peer B
foreach (['privateKey', 'publicKeyPem', 'certPem', 'peerId', 'keyDir'] as $prop) {
	$p = $reflection->getProperty($prop);
	$p->setAccessible(true);
	$p->setValue(null, null);
}
$sp->setValue(null, array());
Q_WebServer_Mesh::init($dirB);

$idB = Q_WebServer_Mesh::peerId();
assert_true($idA !== $idB, "Peer A and B have different IDs");
assert_true(strlen($idB) === 64, "Peer B ID is 64 hex chars");

$stateB = saveState();

// Step 1: Peer A creates handshake init
restoreState($stateA);
$initMsg = Q_WebServer_Mesh::handshakeInit();
assert_true(!empty($initMsg['cert']), "Init has cert");
assert_true(!empty($initMsg['nonce']), "Init has nonce");
assert_eq($initMsg['peer_id'], $idA, "Init has correct peer_id");
$nonceA = $initMsg['nonce'];
$stateA = saveState();

// Step 2: Peer B receives init and responds
restoreState($stateB);
$initMsg['step'] = 'init'; // add step marker
$responseMsg = Q_WebServer_Mesh::handshakeRespond($initMsg);
assert_true($responseMsg !== null, "Handshake respond succeeded");
assert_true(!empty($responseMsg['signature']), "Response has signature");
assert_true(!empty($responseMsg['ecdh_pub']), "Response has ECDH public key");
assert_eq($responseMsg['peer_id'], $idB, "Response has correct peer_id");
$stateB = saveState();

// Step 3: Peer A receives response, completes handshake
restoreState($stateA);
$finalMsg = Q_WebServer_Mesh::handshakeComplete($responseMsg, $nonceA);
assert_true($finalMsg !== null, "Handshake complete succeeded");
assert_true(!empty($finalMsg['signature']), "Final has signature");
assert_true(!empty($finalMsg['ecdh_pub']), "Final has ECDH public key");
assert_true(Q_WebServer_Mesh::hasSession($idB), "Peer A has session with B");
$stateA = saveState();

// Step 4: Peer B receives final, establishes session
restoreState($stateB);
$ok = Q_WebServer_Mesh::handshakeFinalize($finalMsg);
assert_true($ok, "Handshake finalize succeeded");
assert_true(Q_WebServer_Mesh::hasSession($idA), "Peer B has session with A");
$stateB = saveState();

echo "\n  ── Encryption Tests ──\n\n";

// Peer A encrypts a message for B
restoreState($stateA);
$plaintext = 'GET /Q/health HTTP/1.1\r\nHost: localhost\r\n\r\n';
$encrypted = Q_WebServer_Mesh::encrypt($idB, $plaintext);
assert_true($encrypted !== null, "Encryption succeeded");
assert_true(!empty($encrypted['nonce']), "Encrypted has nonce");
assert_true(!empty($encrypted['ciphertext']), "Encrypted has ciphertext");
assert_true(!empty($encrypted['tag']), "Encrypted has GCM tag");
$stateA = saveState();

// Peer B decrypts
restoreState($stateB);
$decrypted = Q_WebServer_Mesh::decrypt($idA, $encrypted);
assert_eq($decrypted, $plaintext, "Decryption matches original");
$stateB = saveState();

// Peer B encrypts a response for A
$responseText = 'HTTP/1.1 200 OK\r\n\r\n{"status":"ok"}';
$encryptedResp = Q_WebServer_Mesh::encrypt($idA, $responseText);
assert_true($encryptedResp !== null, "B→A encryption succeeded");
$stateB = saveState();

// Peer A decrypts
restoreState($stateA);
$decryptedResp = Q_WebServer_Mesh::decrypt($idB, $encryptedResp);
assert_eq($decryptedResp, $responseText, "A decrypts B's response");
$stateA = saveState();

echo "\n  ── Replay Protection Tests ──\n\n";

// Try to replay the same encrypted message
restoreState($stateB);
$replay = Q_WebServer_Mesh::decrypt($idA, $encrypted);
assert_true($replay === null, "Replay of old message rejected");
$stateB = saveState();

echo "\n  ── Wrong Peer Decryption Test ──\n\n";

// Try to decrypt with wrong peer (should fail)
restoreState($stateA);
$wrongDecrypt = Q_WebServer_Mesh::decrypt(str_repeat('0', 64), $encrypted);
assert_true($wrongDecrypt === null, "Decryption with wrong peer_id fails");

echo "\n  ── Session Management Tests ──\n\n";

assert_true(in_array($idB, Q_WebServer_Mesh::activeSessions()), "B in active sessions");
Q_WebServer_Mesh::dropSession($idB);
assert_true(!Q_WebServer_Mesh::hasSession($idB), "Session dropped");
assert_true(!in_array($idB, Q_WebServer_Mesh::activeSessions()), "B not in active sessions after drop");

echo "\n  ── API Endpoint Tests ──\n\n";

restoreState($stateA);
$identity = Q_WebServer_Mesh::handleSyncApi('identity');
assert_eq($identity['peer_id'], $idA, "API: identity returns correct peer_id");
assert_true(!empty($identity['cert']), "API: identity returns cert");

// Cleanup
exec("rm -rf " . escapeshellarg($dirA) . " " . escapeshellarg($dirB));

// Summary
echo "\n  ═══════════════════════════════\n";
echo "  Mesh: $pass passed, $fail failed (of $tests)\n";
echo "  ═══════════════════════════════\n\n";

exit($fail > 0 ? 1 : 0);
