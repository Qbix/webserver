#!/usr/bin/env php
<?php
/**
 * Q_Relay tests — MIME parsing, SMTP protocol, rate limiting, digest batching.
 * Run: php tests/test_relay.php
 *
 * These are unit tests that exercise each relay component in isolation,
 * without starting an actual SMTP server or connecting to upstream.
 */

$srcDir = dirname(__DIR__) . '/src';

// Autoloader for Q_* classes
spl_autoload_register(function ($class) use ($srcDir) {
	$path = $srcDir . '/' . str_replace('_', '/', $class) . '.php';
	if (is_file($path)) {
		require_once $path;
	}
});

// Stub Q_Config if not available (standalone test without framework)
if (!class_exists('Q_Config', false)) {
	class Q_Config {
		private static $data = [];
		static function get() {
			$args = func_get_args();
			$default = array_pop($args);
			$ref = &self::$data;
			foreach ($args as $k) {
				if (!is_array($ref) || !isset($ref[$k])) return $default;
				$ref = &$ref[$k];
			}
			return $ref;
		}
		static function set() {
			$args = func_get_args();
			$value = array_pop($args);
			$ref = &self::$data;
			foreach ($args as $k) {
				if (!isset($ref[$k]) || !is_array($ref[$k])) $ref[$k] = [];
				$ref = &$ref[$k];
			}
			$ref = $value;
		}
	}
}

// Stub qbix_data_path if not defined
if (!function_exists('qbix_data_path')) {
	function qbix_data_path($rel) {
		return sys_get_temp_dir() . '/qbix_test_relay/' . $rel;
	}
}

$pass = 0;
$fail = 0;
$errors = [];

function ok($cond, $msg) {
	global $pass, $fail, $errors;
	if ($cond) {
		++$pass;
		echo "  PASS $msg\n";
	} else {
		++$fail;
		$errors[] = $msg;
		echo "  FAIL $msg\n";
	}
}

// ── Setup temp DB ──────────────────────────────────
$testDir = sys_get_temp_dir() . '/qbix_test_relay';
@mkdir($testDir, 0755, true);
@mkdir($testDir . '/local', 0755, true);
$testDb = $testDir . '/local/relay.db';
@unlink($testDb);

echo "\n══════════════════════════════════════════\n";
echo "  Qbix Relay Test Suite\n";
echo "══════════════════════════════════════════\n\n";

// ========================================================================
// 1. MIME Parser Tests
// ========================================================================
echo "== Q_Relay_Mime ==\n";

// -- decodeRFC2047 --
$encoded = '=?UTF-8?B?SGVsbG8gV29ybGQ=?=';
$decoded = Q_Relay_Mime::decodeRFC2047($encoded);
ok($decoded === 'Hello World', 'decodeRFC2047: base64 UTF-8');

$qpEncoded = '=?UTF-8?Q?Hello=20World?=';
$decoded2 = Q_Relay_Mime::decodeRFC2047($qpEncoded);
ok($decoded2 === 'Hello World', 'decodeRFC2047: quoted-printable UTF-8');

$plain = 'No encoding here';
ok(Q_Relay_Mime::decodeRFC2047($plain) === $plain, 'decodeRFC2047: passthrough for plain text');

// -- splitHeaderBody --
$raw = "From: alice@example.com\r\nTo: bob@example.com\r\nSubject: Test\r\n\r\nHello body";
$split = Q_Relay_Mime::splitHeaderBody($raw);
ok(strpos($split['headers'], 'From:') !== false, 'splitHeaderBody: headers contain From');
ok(trim($split['body']) === 'Hello body', 'splitHeaderBody: body extracted');

// -- parseHeaders --
$headers = Q_Relay_Mime::parseHeaders($split['headers']);
ok(isset($headers['from']) && $headers['from'][0] === 'alice@example.com', 'parseHeaders: from');
ok(isset($headers['to']) && $headers['to'][0] === 'bob@example.com', 'parseHeaders: to');
ok(isset($headers['subject']) && $headers['subject'][0] === 'Test', 'parseHeaders: subject');

// -- parseHeaders with continuation lines --
$folded = "Subject: This is a\r\n very long subject\r\nFrom: test@test.com\r\n";
$h = Q_Relay_Mime::parseHeaders($folded);
ok(strpos($h['subject'][0], 'very long subject') !== false, 'parseHeaders: unfolds continuation lines');

// -- headerValue --
ok(Q_Relay_Mime::headerValue($headers, 'Subject') === 'Test', 'headerValue: retrieves decoded value');
ok(Q_Relay_Mime::headerValue($headers, 'X-Missing') === null, 'headerValue: null for missing header');

// -- extractBoundary --
$ct = 'multipart/mixed; boundary="----=_Part_123"';
ok(Q_Relay_Mime::extractBoundary($ct) === '----=_Part_123', 'extractBoundary: quoted boundary');

$ct2 = 'multipart/alternative; boundary=simple_boundary';
ok(Q_Relay_Mime::extractBoundary($ct2) === 'simple_boundary', 'extractBoundary: unquoted boundary');

ok(Q_Relay_Mime::extractBoundary('text/plain') === null, 'extractBoundary: null for non-multipart');

// -- decodeQuotedPrintable --
$qp = "Hello=20World=0D=0ALine=20two";
$dec = Q_Relay_Mime::decodeQuotedPrintable($qp);
ok($dec === "Hello World\r\nLine two", 'decodeQuotedPrintable: basic decode');

$softBreak = "Hello =\r\nWorld";
$dec2 = Q_Relay_Mime::decodeQuotedPrintable($softBreak);
ok($dec2 === "Hello World", 'decodeQuotedPrintable: soft line break removal');

// -- splitMultipart --
$boundary = "----=_Part_123";
$body = "Preamble text\r\n------=_Part_123\r\nContent-Type: text/plain\r\n\r\nPart 1\r\n------=_Part_123\r\nContent-Type: text/html\r\n\r\n<p>Part 2</p>\r\n------=_Part_123--\r\n";
$parts = Q_Relay_Mime::splitMultipart($body, $boundary);
ok(count($parts) === 2, 'splitMultipart: found 2 parts');
ok(strpos($parts[0], 'Part 1') !== false, 'splitMultipart: first part content');
ok(strpos($parts[1], 'Part 2') !== false, 'splitMultipart: second part content');

// -- parseFullMIME (simple text) --
$simpleMsg = "From: sender@test.com\r\nTo: rcpt@test.com\r\nSubject: Simple\r\nContent-Type: text/plain\r\n\r\nPlain text body";
$parsed = Q_Relay_Mime::parseFullMIME($simpleMsg);
ok(count($parsed['text']) > 0, 'parseFullMIME: extracted text part');
ok(strpos($parsed['text'][0], 'Plain text body') !== false, 'parseFullMIME: text content correct');
ok(count($parsed['attachments']) === 0, 'parseFullMIME: no attachments in simple message');

// -- parseFullMIME (multipart/alternative) --
$altMsg = "From: sender@test.com\r\nContent-Type: multipart/alternative; boundary=\"alt_bound\"\r\n\r\n--alt_bound\r\nContent-Type: text/plain\r\n\r\nPlain version\r\n--alt_bound\r\nContent-Type: text/html\r\n\r\n<p>HTML version</p>\r\n--alt_bound--\r\n";
$parsed2 = Q_Relay_Mime::parseFullMIME($altMsg);
ok(count($parsed2['text']) > 0 && strpos($parsed2['text'][0], 'Plain version') !== false,
	'parseFullMIME multipart: text part');
ok(count($parsed2['html']) > 0 && strpos($parsed2['html'][0], 'HTML version') !== false,
	'parseFullMIME multipart: html part');

// -- extractFilename --
$dispHeaders = ['content-disposition' => ['attachment; filename="report.pdf"']];
ok(Q_Relay_Mime::extractFilename($dispHeaders) === 'report.pdf', 'extractFilename: from Content-Disposition');

$nameHeaders = ['content-type' => ['application/pdf; name="doc.pdf"']];
ok(Q_Relay_Mime::extractFilename($nameHeaders) === 'doc.pdf', 'extractFilename: from Content-Type name');

$emptyHeaders = [];
ok(Q_Relay_Mime::extractFilename($emptyHeaders) === 'attachment', 'extractFilename: default');

// -- escapeHtml --
ok(Q_Relay_Mime::escapeHtml('<script>alert("xss")</script>') !== '<script>alert("xss")</script>',
	'escapeHtml: escapes tags');

// -- sanitizeHTML --
$dirty = '<p>Hello</p><script>alert("bad")</script><a onclick="evil()">link</a>';
$clean = Q_Relay_Mime::sanitizeHTML($dirty, []);
ok(strpos($clean, '<script>') === false, 'sanitizeHTML: strips script tags');
ok(strpos($clean, 'onclick') === false, 'sanitizeHTML: strips event handlers');

echo "\n";

// ========================================================================
// 2. SMTP Protocol Tests
// ========================================================================
echo "== Q_Relay_Smtp ==\n";

// Q_Relay_Smtp_Scanner is defined in the same file as Q_Relay_Smtp,
// not in its own file, so autoloader can't find it by name.
require_once $srcDir . '/Q/Relay/Smtp.php';

// -- DotTerminatorScanner --
$scanner = new Q_Relay_Smtp_Scanner();
$scanner->push("Hello\r\n");
ok(!$scanner->isTerminated(), 'Scanner: not terminated mid-message');

$scanner->push(".\r\n");  // "Hello\r\n" + ".\r\n" = ends with \r\n.\r\n
ok($scanner->isTerminated(), 'Scanner: CRLF + dot-CRLF across chunks is terminated');

// Separate test: dot-CRLF mid-message where preceding bytes are NOT CRLF
$scannerNoCrlf = new Q_Relay_Smtp_Scanner();
$scannerNoCrlf->push("Hello");  // no trailing CRLF
$scannerNoCrlf->push(".\r\n");
ok(!$scannerNoCrlf->isTerminated(), 'Scanner: dot-CRLF without preceding CRLF not terminated');

$scanner2 = new Q_Relay_Smtp_Scanner();
$scanner2->push("Hello\r\n.\r\n");
ok($scanner2->isTerminated(), 'Scanner: CRLF.CRLF detected');

// Edge case: empty body
$scanner3 = new Q_Relay_Smtp_Scanner();
$scanner3->push(".\r\n");
ok($scanner3->isTerminated(), 'Scanner: empty body (just dot-CRLF)');

// -- inboundDotUnstuff --
$stuffed = "..Leading dot\r\nNormal line\r\n..Another dot\r\n";
$unstuffed = Q_Relay_Smtp::inboundDotUnstuff($stuffed);
ok(strpos($unstuffed, '.Leading dot') !== false, 'inboundDotUnstuff: strips one dot');
ok(strpos($unstuffed, 'Normal line') !== false, 'inboundDotUnstuff: preserves non-dot lines');

// -- outboundDotStuff --
$msg = ".Leading dot\r\nNormal line\r\n.Another dot\r\n";
$dotted = Q_Relay_Smtp::outboundDotStuff($msg);
ok(strpos($dotted, '..Leading dot') !== false, 'outboundDotStuff: prepends dot');
ok(strpos($dotted, "\r\n..Another") !== false, 'outboundDotStuff: mid-message dot stuffed');

echo "\n";

// ========================================================================
// 3. Database Tests
// ========================================================================
echo "== Q_Relay_Db ==\n";

Q_Relay_Db::init($testDb);
$db = Q_Relay_Db::db();
ok($db instanceof \SQLite3, 'Db::init: creates SQLite database');

// -- logDelivery --
Q_Relay_Db::logDelivery('email', 'outbound', 'sender@test.com', 'rcpt@test.com',
	'Test Subject', '<msg-001@test>', 1234, 'sent');
$result = $db->querySingle("SELECT COUNT(*) FROM delivery_log", true);
ok((int)$result['COUNT(*)'] === 1, 'Db::logDelivery: inserts row');

// -- findOrCreateThread --
$tid = Q_Relay_Db::findOrCreateThread('thread-001', 'email', 'Test Thread');
ok($tid === 'thread-001', 'Db::findOrCreateThread: returns thread_id');

// Idempotent
Q_Relay_Db::findOrCreateThread('thread-001', 'email', 'Test Thread');
$count = $db->querySingle("SELECT COUNT(*) FROM threads WHERE thread_id='thread-001'");
ok((int)$count === 1, 'Db::findOrCreateThread: upsert is idempotent');

// -- addThreadMember --
Q_Relay_Db::addThreadMember('thread-001', 'alice@test.com', 'alice');
Q_Relay_Db::addThreadMember('thread-001', 'bob@test.com', 'bob');
Q_Relay_Db::addThreadMember('thread-001', 'alice@test.com', 'alice'); // duplicate
$memberCount = $db->querySingle("SELECT COUNT(*) FROM thread_members WHERE thread_id='thread-001'");
ok((int)$memberCount === 2, 'Db::addThreadMember: ignores duplicates');

// -- storeMessage --
Q_Relay_Db::storeMessage('thread-001', '<msg-001>', 'email', 'inbound',
	'alice@test.com', 'bob@test.com', 'Hello', 'Hello body', null, 'raw data');
$msgCount = $db->querySingle("SELECT COUNT(*) FROM thread_messages WHERE thread_id='thread-001'");
ok((int)$msgCount === 1, 'Db::storeMessage: inserts message');

// -- storeInbox --
Q_Relay_Db::storeInbox('bob', 'thread-001', '<msg-001>', 'email',
	'alice@test.com', 'Hello', 'Hello body');
$inboxCount = $db->querySingle("SELECT COUNT(*) FROM inbox WHERE username='bob'");
ok((int)$inboxCount === 1, 'Db::storeInbox: inserts into inbox');

// -- digest state --
Q_Relay_Db::setDigestState('rcpt@test.com', 'sender@test.com', 60000, 3);
$state = Q_Relay_Db::getDigestState('rcpt@test.com', 'sender@test.com');
ok($state !== null, 'Db::getDigestState: returns state');
ok((int)$state['next_delay_ms'] === 60000, 'Db::getDigestState: correct delay');
ok((int)$state['msg_count'] === 3, 'Db::getDigestState: correct count');

// -- digest pending --
Q_Relay_Db::addDigestPending('rcpt@test.com', 'sender@test.com', 'raw message 1');
Q_Relay_Db::addDigestPending('rcpt@test.com', 'sender@test.com', 'raw message 2');
$pending = Q_Relay_Db::getDigestPending('rcpt@test.com', 'sender@test.com');
ok(count($pending) === 2, 'Db::getDigestPending: returns pending messages');

Q_Relay_Db::clearDigestPending('rcpt@test.com', 'sender@test.com');
$pending2 = Q_Relay_Db::getDigestPending('rcpt@test.com', 'sender@test.com');
ok(count($pending2) === 0, 'Db::clearDigestPending: clears all pending');

// -- rate state --
Q_Relay_Db::setRateState('global', 55.5, time(), '[]');
$rState = Q_Relay_Db::getRateState('global');
ok($rState !== null, 'Db::getRateState: returns state');
ok(abs($rState['tokens'] - 55.5) < 0.01, 'Db::getRateState: correct tokens');

echo "\n";

// ========================================================================
// 4. Rate Limiter Tests
// ========================================================================
echo "== Q_Relay_RateLimiter ==\n";

// Use a fresh DB for rate limiter
Q_Config::set('Q', 'relay', 'rateLimit', 'maxPerMinute', 10);
Q_Config::set('Q', 'relay', 'rateLimit', 'maxPerHour', 100);

// Reset any existing state
$rlReflect = new ReflectionClass('Q_Relay_RateLimiter');
$initProp = $rlReflect->getProperty('initialized');
$initProp->setAccessible(true);
$initProp->setValue(null, false);

Q_Relay_RateLimiter::init();

// Should be able to acquire slots up to maxPerMinute
$acquired = 0;
for ($i = 0; $i < 10; $i++) {
	$result = Q_Relay_RateLimiter::acquire();
	if ($result === 0) $acquired++;
}
ok($acquired === 10, 'RateLimiter: acquired 10 slots (maxPerMinute=10)');

// Next acquire should return wait time > 0
$waitResult = Q_Relay_RateLimiter::acquire();
ok($waitResult > 0, 'RateLimiter: returns wait time when tokens exhausted');

// Check metrics
$metrics = Q_Relay_RateLimiter::getMetrics();
ok(isset($metrics['tokens']), 'RateLimiter::getMetrics: returns tokens');
ok($metrics['tripped'] === false, 'RateLimiter::getMetrics: not tripped');

// Reset
Q_Relay_RateLimiter::reset();
$afterReset = Q_Relay_RateLimiter::acquire();
ok($afterReset === 0, 'RateLimiter::reset: can acquire after reset');

echo "\n";

// ========================================================================
// 5. Digest Tests
// ========================================================================
echo "== Q_Relay_Digest ==\n";

// -- normalizeAddress --
ok(Q_Relay_Digest::normalizeAddress('<User+tag@Example.COM>') === 'user@example.com',
	'Digest::normalizeAddress: lowercases, strips <>, removes +tag');

ok(Q_Relay_Digest::normalizeAddress('plain@test.com') === 'plain@test.com',
	'Digest::normalizeAddress: passthrough for simple address');

// -- digestKey --
$key = Q_Relay_Digest::digestKey('rcpt@test.com', 'sender@test.com');
ok(strpos($key, '|') !== false, 'Digest::digestKey: contains separator');
ok($key === Q_Relay_Digest::digestKey('RCPT@TEST.COM', 'SENDER@TEST.COM'),
	'Digest::digestKey: case-insensitive');

// -- processMessage with digest disabled (default) --
Q_Config::set('Q', 'relay', 'digest', 'enabled', false);
$simpleRaw = "From: sender@test.com\r\nTo: rcpt@test.com\r\nSubject: Test\r\n\r\nBody";
$result = Q_Relay_Digest::processMessage('sender@test.com', ['rcpt@test.com'], $simpleRaw);
ok($result['action'] === 'immediate', 'Digest::processMessage: immediate when digest disabled');

// -- processMessage with digest enabled --
Q_Config::set('Q', 'relay', 'digest', 'enabled', true);
Q_Config::set('Q', 'relay', 'digest', 'firstDelay', 1000);
Q_Config::set('Q', 'relay', 'digest', 'cooldownMinutes', 30);

// First message should go immediately
$result1 = Q_Relay_Digest::processMessage('sender2@test.com', ['rcpt2@test.com'], $simpleRaw);
ok($result1['action'] === 'immediate' || (isset($result1['recipients']) && in_array('rcpt2@test.com', $result1['recipients'])),
	'Digest::processMessage: first message goes immediately');

// Second message should be digested
$result2 = Q_Relay_Digest::processMessage('sender2@test.com', ['rcpt2@test.com'], $simpleRaw);
ok($result2['action'] === 'digest' || $result2['action'] === 'mixed',
	'Digest::processMessage: second message is digested');

echo "\n";

// ========================================================================
// 6. SmtpClient Tests (unit tests only - no actual connections)
// ========================================================================
echo "== Q_Relay_SmtpClient ==\n";

// -- ensureOutboundHeaders --
$rawNoHeaders = "Subject: Test\r\n\r\nBody text";
$withHeaders = Q_Relay_SmtpClient::ensureOutboundHeaders($rawNoHeaders, 'sender@test.com');
ok(preg_match('/^Date:/mi', $withHeaders) === 1, 'SmtpClient::ensureOutboundHeaders: adds Date');
ok(preg_match('/^Message-ID:/mi', $withHeaders) === 1, 'SmtpClient::ensureOutboundHeaders: adds Message-ID');
ok(preg_match('/^From:/mi', $withHeaders) === 1, 'SmtpClient::ensureOutboundHeaders: adds From');

// Don't add duplicate headers
$rawWithDate = "Date: Mon, 01 Jan 2024 00:00:00 +0000\r\nSubject: Test\r\n\r\nBody";
$noDoubleDate = Q_Relay_SmtpClient::ensureOutboundHeaders($rawWithDate, 'sender@test.com');
$dateCount = preg_match_all('/^Date:/mi', $noDoubleDate);
ok($dateCount === 1, 'SmtpClient::ensureOutboundHeaders: does not duplicate existing Date');

// -- outboundDotStuff --
$dotMsg = ".Start with dot\r\nNormal\r\n.Another dot";
$stuffed = Q_Relay_SmtpClient::outboundDotStuff($dotMsg);
ok(substr($stuffed, 0, 2) === '..', 'SmtpClient::outboundDotStuff: stuffs leading dot');
ok(strpos($stuffed, "\r\n..Another") !== false, 'SmtpClient::outboundDotStuff: stuffs mid-line dot');

// -- smtpCode --
ok(Q_Relay_SmtpClient::smtpCode("250 OK\r\n") === 250, 'SmtpClient::smtpCode: extracts code');
ok(Q_Relay_SmtpClient::smtpCode("250-Size 35882577\r\n250 OK\r\n") === 250, 'SmtpClient::smtpCode: multi-line');
ok(Q_Relay_SmtpClient::smtpCode("550 User not found\r\n") === 550, 'SmtpClient::smtpCode: error code');

echo "\n";

// ========================================================================
// 7. Mobile (Twilio) Tests
// ========================================================================
echo "== Q_Relay_Mobile ==\n";

// -- formatE164 --
ok(Q_Relay_Mobile::formatE164('(555) 123-4567') === '+15551234567', 'Mobile::formatE164: US format');
ok(Q_Relay_Mobile::formatE164('+44 20 7946 0958') === '+442079460958', 'Mobile::formatE164: international');
ok(Q_Relay_Mobile::formatE164('5551234567') === '+15551234567', 'Mobile::formatE164: 10 digit US');

// -- validateSignature (known test vector) --
// Twilio signature is HMAC-SHA1 of URL + sorted params
$testToken = 'test_auth_token_12345';
$testUrl = 'https://example.com/webhook';
$testParams = ['Body' => 'Hello', 'From' => '+15551234567'];
// Build expected signature manually
$dataToSign = $testUrl;
ksort($testParams);
foreach ($testParams as $k => $v) $dataToSign .= $k . $v;
$expectedSig = base64_encode(hash_hmac('sha1', $dataToSign, $testToken, true));
ok(Q_Relay_Mobile::validateSignature($testUrl, $testParams, $expectedSig, $testToken),
	'Mobile::validateSignature: valid signature accepted');
ok(!Q_Relay_Mobile::validateSignature($testUrl, $testParams, 'bad_signature', $testToken),
	'Mobile::validateSignature: invalid signature rejected');

echo "\n";

// ========================================================================
// Summary
// ========================================================================
echo "══════════════════════════════════════════\n";
$total = $pass + $fail;
echo "  Results: $pass/$total passed";
if ($fail > 0) {
	echo " ($fail FAILED)\n";
	echo "  Failed tests:\n";
	foreach ($errors as $e) {
		echo "    - $e\n";
	}
} else {
	echo " — all clear!\n";
}
echo "══════════════════════════════════════════\n\n";

// Cleanup
@unlink($testDb);
@rmdir($testDir . '/local');
@rmdir($testDir);

exit($fail > 0 ? 1 : 0);
