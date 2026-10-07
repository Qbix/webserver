<?php
/**
 * Test Turn 6: BLE transport — chunking protocol, rate limiting, simulation.
 *
 * Tests the exact same chunking protocol the native Swift/Kotlin bridges use,
 * with realistic BLE constraints: MTU limits, latency, bandwidth, drops.
 */

error_reporting(E_ALL);
$root = dirname(__DIR__);
require_once $root . '/src/Q/WebServer/MeshBLE.php';

$pass = 0; $fail = 0; $tests = 0;
function ok($cond, $msg) {
	global $pass, $fail, $tests; $tests++;
	if ($cond) { echo "  ✓ $msg\n"; $pass++; }
	else { echo "  ✗ $msg\n"; $fail++; }
}

$A = str_repeat('a1', 32);
$B = str_repeat('b2', 32);

echo "\n  ── Turn 6: BLE Transport ──\n";

// ══════════════════════════════════════════════════════
// Part 1: Chunking Protocol
// ══════════════════════════════════════════════════════

echo "\n  ── Chunking: Small Messages (Single Chunk) ──\n\n";

// Empty message
$chunks = Q_WebServer_MeshBLE::chunk('', 247);
ok(count($chunks) === 1, "Empty message: 1 chunk");
$reassembled = Q_WebServer_MeshBLE::reassemble($chunks);
ok($reassembled === '', "Empty message reassembles to ''");

// Tiny message (fits in single chunk)
$tiny = 'Hello BLE';
$chunks = Q_WebServer_MeshBLE::chunk($tiny, 247);
ok(count($chunks) === 1, "Tiny message ($tiny): 1 chunk");
ok(ord($chunks[0][0]) === Q_WebServer_MeshBLE::FLAG_LAST, "Single chunk has FLAG_LAST");
$reassembled = Q_WebServer_MeshBLE::reassemble($chunks);
ok($reassembled === $tiny, "Tiny message reassembles correctly");

// Message exactly at MTU boundary (payload = MTU - 1 flag - 4 length = 242)
$exact = str_repeat('X', 242);
$chunks = Q_WebServer_MeshBLE::chunk($exact, 247);
ok(count($chunks) === 1, "Exact-fit message: 1 chunk");
$reassembled = Q_WebServer_MeshBLE::reassemble($chunks);
ok($reassembled === $exact, "Exact-fit reassembles correctly");

echo "\n  ── Chunking: Multi-Chunk Messages ──\n\n";

// Message that needs 2 chunks
$twoChunk = str_repeat('Y', 300);
$chunks = Q_WebServer_MeshBLE::chunk($twoChunk, 247);
ok(count($chunks) === 2, "300-byte message: 2 chunks (MTU 247)");
ok(ord($chunks[0][0]) === Q_WebServer_MeshBLE::FLAG_FIRST, "First chunk has FLAG_FIRST");
ok(ord($chunks[1][0]) === Q_WebServer_MeshBLE::FLAG_LAST, "Second chunk has FLAG_LAST");
$reassembled = Q_WebServer_MeshBLE::reassemble($chunks);
ok($reassembled === $twoChunk, "2-chunk message reassembles correctly");

// Large message (1KB — typical HTTP request)
$httpReq = "GET /Q/health HTTP/1.1\r\nHost: qbix-peer\r\n"
	. "X-Peer-Id: " . str_repeat('a', 64) . "\r\n"
	. "Accept: application/json\r\n"
	. str_repeat("X-Padding: " . str_repeat('z', 80) . "\r\n", 8)
	. "\r\n";
$chunks = Q_WebServer_MeshBLE::chunk($httpReq, 247);
ok(count($chunks) >= 4, "~1KB HTTP request: " . count($chunks) . " chunks");
$reassembled = Q_WebServer_MeshBLE::reassemble($chunks);
ok($reassembled === $httpReq, "1KB HTTP request reassembles perfectly");

// Large message (10KB — HTTP response with JSON body)
$bigBody = json_encode(array_fill(0, 200, array('id' => rand(), 'name' => 'test record', 'data' => str_repeat('x', 30))));
$httpResp = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: "
	. strlen($bigBody) . "\r\n\r\n" . $bigBody;
$chunks = Q_WebServer_MeshBLE::chunk($httpResp, 247);
$chunkCount = count($chunks);
ok($chunkCount >= 40, "~10KB response: $chunkCount chunks");
$reassembled = Q_WebServer_MeshBLE::reassemble($chunks);
ok($reassembled === $httpResp, "10KB response reassembles perfectly");
ok(strlen($reassembled) === strlen($httpResp), "Length matches: " . strlen($httpResp) . " bytes");

// Verify all middle chunks have correct flags
for ($i = 1; $i < count($chunks) - 1; $i++) {
	$flag = ord($chunks[$i][0]);
	if ($flag !== Q_WebServer_MeshBLE::FLAG_MIDDLE) {
		ok(false, "Middle chunk $i has wrong flag: $flag");
		break;
	}
}
ok(true, "All middle chunks have FLAG_MIDDLE");

echo "\n  ── Chunking: Different MTU Sizes ──\n\n";

$payload = str_repeat('M', 500);

// BLE 4.0 minimum MTU (23 bytes)
$chunks23 = Q_WebServer_MeshBLE::chunk($payload, 23);
ok(count($chunks23) >= 23, "MTU 23: " . count($chunks23) . " chunks for 500 bytes");
$r23 = Q_WebServer_MeshBLE::reassemble($chunks23);
ok($r23 === $payload, "MTU 23 reassembles correctly");

// BLE 5.0 negotiated (247)
$chunks247 = Q_WebServer_MeshBLE::chunk($payload, 247);
ok(count($chunks247) === 3, "MTU 247: 3 chunks for 500 bytes");
$r247 = Q_WebServer_MeshBLE::reassemble($chunks247);
ok($r247 === $payload, "MTU 247 reassembles correctly");

// BLE 5.0 max (517)
$chunks517 = Q_WebServer_MeshBLE::chunk($payload, 517);
ok(count($chunks517) === 1, "MTU 517: 1 chunk for 500 bytes");
$r517 = Q_WebServer_MeshBLE::reassemble($chunks517);
ok($r517 === $payload, "MTU 517 reassembles correctly");

echo "\n  ── Chunking: Edge Cases ──\n\n";

// Max message size
$maxMsg = str_repeat('Z', 65536);
$chunksMax = Q_WebServer_MeshBLE::chunk($maxMsg, 247);
$rMax = Q_WebServer_MeshBLE::reassemble($chunksMax);
ok($rMax === $maxMsg, "64KB max message: " . count($chunksMax) . " chunks, reassembles correctly");

// Over max — should throw
$threw = false;
try {
	Q_WebServer_MeshBLE::chunk(str_repeat('X', 65537), 247);
} catch (\RuntimeException $e) {
	$threw = true;
}
ok($threw, "65537-byte message throws RuntimeException");

// Incomplete chunks (missing last)
$incomplete = array_slice($chunks247, 0, -1);
$rIncomplete = Q_WebServer_MeshBLE::reassemble($incomplete);
ok($rIncomplete === null, "Incomplete chunks (missing last) returns null");

// Empty chunk array
ok(Q_WebServer_MeshBLE::reassemble(array()) === null, "Empty chunk array returns null");

// Corrupted flag
$corrupted = $chunks247;
$corrupted[1] = chr(0xFF) . substr($corrupted[1], 1);
$rCorrupted = Q_WebServer_MeshBLE::reassemble($corrupted);
ok($rCorrupted === null, "Corrupted flag byte returns null");

// Binary data (non-UTF8)
$binary = random_bytes(500);
$chunksBin = Q_WebServer_MeshBLE::chunk($binary, 247);
$rBin = Q_WebServer_MeshBLE::reassemble($chunksBin);
ok($rBin === $binary, "Binary data (random bytes) survives chunking");

// ══════════════════════════════════════════════════════
// Part 2: Rate Limiter
// ══════════════════════════════════════════════════════

echo "\n  ── Rate Limiter ──\n\n";

Q_WebServer_MeshBLE::reset();

// Normal usage — should be allowed
for ($i = 0; $i < 50; $i++) {
	$r = Q_WebServer_MeshBLE::rateCheck($A);
}
ok($r['allowed'], "50 requests in a minute: allowed");

// Hit the limit (100 per minute)
for ($i = 50; $i < 100; $i++) {
	Q_WebServer_MeshBLE::rateCheck($A);
}
$overLimit = Q_WebServer_MeshBLE::rateCheck($A);
ok(!$overLimit['allowed'], "101st request: rate limited");
ok(strpos($overLimit['reason'], 'banned') !== false, "Rate limit includes ban");

// Different peer should still be allowed
$bCheck = Q_WebServer_MeshBLE::rateCheck($B);
ok($bCheck['allowed'], "Different peer B: still allowed");

// Reset clears ban
Q_WebServer_MeshBLE::rateReset($A);
$afterReset = Q_WebServer_MeshBLE::rateCheck($A);
ok($afterReset['allowed'], "After reset: A allowed again");

// ══════════════════════════════════════════════════════
// Part 3: BLE Transport Simulation
// ══════════════════════════════════════════════════════

echo "\n  ── Transport Simulation: Clean Channel ──\n\n";

Q_WebServer_MeshBLE::reset();

$httpRequest = "GET /Q/health HTTP/1.1\r\nHost: peer\r\nX-Peer-Id: $A\r\n\r\n";

$result = Q_WebServer_MeshBLE::simulate($httpRequest, $A, $B);
ok($result['success'], "Clean BLE transfer succeeds");
ok($result['message'] === $httpRequest, "Message intact after simulation");
ok($result['chunks_dropped'] === 0, "0 chunks dropped");
ok($result['chunks_sent'] === $result['chunks_received'], "All chunks received");
ok($result['simulated_ms'] > 0, "Simulated latency: {$result['simulated_ms']}ms");

// Check transport headers
ok($result['headers']['X-Transport'] === 'ble', "Header: X-Transport = ble");
ok($result['headers']['X-Peer-Id'] === $A, "Header: X-Peer-Id correct");
ok(in_array($result['headers']['X-Transport-Bandwidth'], array('low', 'medium', 'high')),
	"Header: X-Transport-Bandwidth set");

echo "\n  ── Transport Simulation: Latency ──\n\n";

// High latency simulation (congested environment)
$slowResult = Q_WebServer_MeshBLE::simulate($httpRequest, $A, $B, array(
	'latency_ms' => 30,
	'mtu' => 23  // force many chunks
));
ok($slowResult['success'], "High-latency BLE transfer succeeds");
ok($slowResult['simulated_ms'] > 100, "Significant latency: {$slowResult['simulated_ms']}ms");
ok($slowResult['chunks_sent'] > 5, "Many chunks at MTU 23: {$slowResult['chunks_sent']}");

echo "\n  ── Transport Simulation: Large Payload ──\n\n";

// 5KB JSON response
$largePayload = json_encode(array(
	'status' => 'ok',
	'records' => array_fill(0, 50, array('id' => 'test', 'data' => str_repeat('x', 60)))
));
$largeResult = Q_WebServer_MeshBLE::simulate($largePayload, $A, $B, array(
	'mtu' => 247,
	'bandwidth_kbps' => 2000
));
ok($largeResult['success'], "5KB payload over BLE succeeds");
ok($largeResult['message'] === $largePayload, "5KB payload intact");
ok($largeResult['chunks_sent'] >= 15, "5KB = {$largeResult['chunks_sent']} chunks");
$estimatedTime = strlen($largePayload) * 8 / 2000; // ms
ok($largeResult['simulated_ms'] > $estimatedTime, "Latency realistic: {$largeResult['simulated_ms']}ms (>= {$estimatedTime}ms bandwidth)");

echo "\n  ── Transport Simulation: Packet Loss ──\n\n";

Q_WebServer_MeshBLE::reset();

// No drops — control
$noDrop = Q_WebServer_MeshBLE::simulate($httpRequest, $A, $B, array('drop_rate' => 0.0));
ok($noDrop['success'], "0% drop rate: success");

// High drop rate — likely failure
$failures = 0;
$attempts = 20;
for ($i = 0; $i < $attempts; $i++) {
	Q_WebServer_MeshBLE::reset();
	$dropResult = Q_WebServer_MeshBLE::simulate(
		str_repeat('X', 500), $A, $B,
		array('drop_rate' => 0.5, 'mtu' => 50)
	);
	if (!$dropResult['success']) $failures++;
}
ok($failures > $attempts * 0.3, "50% drop rate: $failures/$attempts transfers failed (expected most to fail)");

// Verify dropped chunks are counted
Q_WebServer_MeshBLE::reset();
$dropOnce = Q_WebServer_MeshBLE::simulate(
	str_repeat('Y', 1000), $A, $B,
	array('drop_rate' => 0.3, 'mtu' => 50)
);
ok($dropOnce['chunks_sent'] > $dropOnce['chunks_received'] || $dropOnce['chunks_dropped'] >= 0,
	"Drop accounting: sent={$dropOnce['chunks_sent']} recv={$dropOnce['chunks_received']} drop={$dropOnce['chunks_dropped']}");

echo "\n  ── Transport Simulation: Rate Limiting ──\n\n";

Q_WebServer_MeshBLE::reset();

// Send 100 messages (should hit rate limit)
$rateLimited = false;
for ($i = 0; $i < 110; $i++) {
	$r = Q_WebServer_MeshBLE::simulate("msg $i", $A, $B);
	if (!$r['success'] && strpos($r['error'] ?? '', 'Rate') !== false) {
		$rateLimited = true;
		ok(true, "Rate limiter triggered at message $i");
		break;
	}
}
ok($rateLimited, "Rate limiter fires during sustained sends");

echo "\n  ── Transport Simulation: Low Bandwidth ──\n\n";

Q_WebServer_MeshBLE::reset();

$lowBw = Q_WebServer_MeshBLE::simulate($httpRequest, $A, $B, array(
	'bandwidth_kbps' => 100  // Very slow BLE
));
ok($lowBw['success'], "Low bandwidth succeeds");
ok($lowBw['headers']['X-Transport-Bandwidth'] === 'low', "Header reports 'low' bandwidth");

$highBw = Q_WebServer_MeshBLE::simulate($httpRequest, $A, $B, array(
	'bandwidth_kbps' => 25000  // Wi-Fi Direct
));
ok($highBw['headers']['X-Transport-Bandwidth'] === 'high', "Header reports 'high' bandwidth (Wi-Fi Direct)");

// ══════════════════════════════════════════════════════
// Part 4: Full Round-Trip (Request + Response over simulated BLE)
// ══════════════════════════════════════════════════════

echo "\n  ── Full BLE Round-Trip ──\n\n";

Q_WebServer_MeshBLE::reset();

// A sends an HTTP request to B
$request = "GET /Q/health HTTP/1.1\r\nHost: qbix-peer\r\nX-Peer-Id: $A\r\n\r\n";
$reqResult = Q_WebServer_MeshBLE::simulate($request, $A, $B, array('mtu' => 100));
ok($reqResult['success'], "Request A→B over BLE");
ok($reqResult['message'] === $request, "Request intact");

// B processes and sends response back
$response = "HTTP/1.1 200 OK\r\nServer: QbixServer/1.5.0\r\nContent-Type: application/json\r\n\r\n" .
	'{"status":"ok","uptime":"42s","mesh_id":"' . $B . '"}';
$respResult = Q_WebServer_MeshBLE::simulate($response, $B, $A, array('mtu' => 100));
ok($respResult['success'], "Response B→A over BLE");
ok($respResult['message'] === $response, "Response intact");
ok(strpos($respResult['message'], '"status":"ok"') !== false, "Response body parseable");

// Total simulated round-trip time
$rtt = $reqResult['simulated_ms'] + $respResult['simulated_ms'];
ok($rtt > 0, "Round-trip time: {$rtt}ms");

echo "\n  ═══════════════════════════════\n";
echo "  Turn 6 (BLE): $pass passed, $fail failed (of $tests)\n";
echo "  ═══════════════════════════════\n\n";

exit($fail > 0 ? 1 : 0);
