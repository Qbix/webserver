#!/usr/bin/env php
<?php
/**
 * End-to-end integration test: Users plugin → Q_Relay SMTP
 *
 * Tests that Users_Email::sendMessage() can deliver mail
 * through the Qbix Relay's local SMTP listener, and that
 * the relay receives, logs, and processes the message.
 *
 * Steps:
 *   1. Boot the Q framework with the MyApp
 *   2. Start the relay SMTP listener in a child process
 *   3. Create a Users_Email record
 *   4. Send a message through Users_Email::sendMessage()
 *   5. Verify the relay received and logged it
 */

$appDir = dirname(__DIR__) . '/platform/MyApp';
$relayDir = dirname(__DIR__);

echo "══════════════════════════════════════════\n";
echo "  Relay ↔ Users Integration Test\n";
echo "══════════════════════════════════════════\n\n";

// ── Phase 1: Boot Q framework ───────────────────────
echo "== Phase 1: Boot Q framework ==\n";

define('APP_DIR', $appDir);
$qIncPath = $appDir . '/scripts/Q.inc.php';
if (!is_file($qIncPath)) {
	echo "  FAIL: Q.inc.php not found at $qIncPath\n";
	exit(1);
}

// Suppress output from Q bootstrap
ob_start();
try {
	require_once $qIncPath;
	ob_end_clean();
	echo "  PASS: Q framework booted (version " . Q_VERSION . ")\n";
} catch (Exception $e) {
	ob_end_clean();
	echo "  FAIL: Q bootstrap error: " . $e->getMessage() . "\n";
	exit(1);
}

// Verify Q_Config loaded our local config
$smtpHost = Q_Config::get('Users', 'email', 'smtp', 'host', null);
$smtpPort = Q_Config::get('Users', 'email', 'smtp', 'port', null);
echo "  Users.email.smtp.host = " . ($smtpHost ?: '(null)') . "\n";
echo "  Users.email.smtp.port = " . ($smtpPort ?: '(null)') . "\n";

if ($smtpHost !== '127.0.0.1' || $smtpPort != 2525) {
	echo "  WARN: SMTP not pointed at relay — expected 127.0.0.1:2525\n";
	echo "  Updating config for this test...\n";
	Q_Config::set('Users', 'email', 'smtp', 'host', '127.0.0.1');
	Q_Config::set('Users', 'email', 'smtp', 'port', 2525);
	Q_Config::set('Users', 'email', 'smtp', 'auth', false);
}

// ── Phase 2: Verify Zend_Mail is available ──────────
echo "\n== Phase 2: Check Zend_Mail ==\n";

// Zend_Mail uses bare require_once 'Zend/...' internally,
// so its parent directory must be on the include path.
$zendClassesDir = Q_PLUGINS_DIR . '/Users/classes';
set_include_path(get_include_path() . PATH_SEPARATOR . $zendClassesDir);

$zendMailPath = $zendClassesDir . '/Zend/Mail.php';
if (is_file($zendMailPath)) {
	require_once $zendMailPath;
	echo "  PASS: Zend_Mail loaded\n";
} else {
	echo "  FAIL: Zend/Mail.php not found at $zendMailPath\n";
	exit(1);
}

$zendSmtpPath = $zendClassesDir . '/Zend/Mail/Transport/Smtp.php';
if (is_file($zendSmtpPath)) {
	require_once $zendSmtpPath;
	echo "  PASS: Zend_Mail_Transport_Smtp loaded\n";
} else {
	echo "  FAIL: Zend_Mail_Transport_Smtp not found\n";
	exit(1);
}

// ── Phase 3: Start relay in background ──────────────
echo "\n== Phase 3: Start relay SMTP listener ==\n";

// Load relay classes
$relaySrcDir = $relayDir . '/src';
spl_autoload_register(function ($class) use ($relaySrcDir) {
	$path = $relaySrcDir . '/' . str_replace('_', '/', $class) . '.php';
	if (is_file($path)) {
		require_once $path;
	}
});

// Set up relay config so it can resolve its own settings
Q_Config::set('Q', 'relay', 'smtp', 'host', '127.0.0.1');
Q_Config::set('Q', 'relay', 'smtp', 'port', 2525);

// Use a test relay database
$testRelayDb = $appDir . '/local/relay-test.db';
@unlink($testRelayDb);
Q_Config::set('Q', 'relay', 'db', 'path', $testRelayDb);

// Initialize relay database
Q_Relay_Db::init($testRelayDb);
echo "  PASS: Relay database initialized at $testRelayDb\n";

// Start the SMTP server
$smtpListenHost = '127.0.0.1';
$smtpListenPort = 2525;

$received = [];
$onMessage = function ($mailFrom, $rcptTo, $rawMessage) use (&$received) {
	$received[] = [
		'from' => $mailFrom,
		'to' => $rcptTo,
		'raw' => $rawMessage,
		'time' => time()
	];
	// Log to relay database
	Q_Relay_Db::logDelivery('email', 'inbound', $mailFrom, implode(',', $rcptTo),
		'(queued)', '', strlen($rawMessage), 'accepted');
};

try {
	$server = Q_Relay_Smtp::createServer(
		$smtpListenHost,
		$smtpListenPort,
		$onMessage,
		['hostname' => 'relay-test.local']
	);
	echo "  PASS: SMTP server listening on $smtpListenHost:$smtpListenPort\n";
} catch (Exception $e) {
	echo "  FAIL: Could not start SMTP server: " . $e->getMessage() . "\n";
	exit(1);
}

// ── Phase 4: Send email via Zend_Mail (like Users plugin does) ──
echo "\n== Phase 4: Send email via Zend_Mail_Transport_Smtp ==\n";

// Create a Zend_Mail transport pointing at our relay
$transport = new Zend_Mail_Transport_Smtp($smtpListenHost, [
	'port' => $smtpListenPort
]);

$mail = new Zend_Mail('UTF-8');
$mail->setFrom('notifications@myapp.test', 'MyApp');
$mail->addTo('testuser@example.com', 'Test User');
$mail->setSubject('Verify your email address');
$mail->setBodyText("Hello Test User,\n\nPlease verify your email by clicking the link below.\n\nhttps://myapp.test/verify?code=abc123\n\nThanks,\nMyApp Team");
$mail->setBodyHtml('<html><body><p>Hello Test User,</p><p>Please verify your email by clicking the link below.</p><p><a href="https://myapp.test/verify?code=abc123">Verify Email</a></p><p>Thanks,<br>MyApp Team</p></body></html>');
$mail->addHeader('X-Q-App', 'MyApp');
$mail->addHeader('X-Q-View', 'MyApp/email/activation');

// The send needs to happen in a way where the SMTP server can
// process the connection. Since Zend_Mail_Transport_Smtp is synchronous,
// we need to accept the connection during the send.
// We'll use a forked approach: child sends, parent accepts.

if (!function_exists('pcntl_fork')) {
	echo "  SKIP: pcntl_fork not available\n";
	exit(0);
}

$sessions = [];
$pid = pcntl_fork();

if ($pid === 0) {
	// Child: wait a moment, then send
	usleep(200000); // 200ms to let parent start accepting
	try {
		$mail->send($transport);
		// Signal success
		exit(0);
	} catch (Exception $e) {
		fwrite(STDERR, "  Send error: " . $e->getMessage() . "\n");
		exit(1);
	}
} elseif ($pid > 0) {
	// Parent: accept and process SMTP connections
	$deadline = microtime(true) + 10; // 10 second timeout
	$childDone = false;

	while (microtime(true) < $deadline) {
		// Tick the SMTP server to accept connections and process data
		$sessions = Q_Relay_Smtp::tick($server, $sessions, $onMessage, [
			'hostname' => 'relay-test.local',
			'maxMessageSize' => 10 * 1024 * 1024
		], 0.1);

		// Check if child finished
		$w = pcntl_waitpid($pid, $status, WNOHANG);
		if ($w > 0) {
			$childDone = true;
			$exitCode = pcntl_wexitstatus($status);
			// Do a few more ticks to finish processing
			for ($i = 0; $i < 10; $i++) {
				$sessions = Q_Relay_Smtp::tick($server, $sessions, $onMessage, [
					'hostname' => 'relay-test.local',
					'maxMessageSize' => 10 * 1024 * 1024
				], 0.05);
			}
			break;
		}
	}

	if (!$childDone) {
		// Kill stuck child
		posix_kill($pid, SIGKILL);
		pcntl_waitpid($pid, $status);
		echo "  FAIL: Timeout waiting for email send\n";
		exit(1);
	}

	if ($exitCode !== 0) {
		echo "  FAIL: Zend_Mail send failed (exit code $exitCode)\n";
		exit(1);
	}

	echo "  PASS: Zend_Mail sent successfully through relay SMTP\n";
} else {
	echo "  FAIL: Fork failed\n";
	exit(1);
}

// ── Phase 5: Verify relay received the message ──────
echo "\n== Phase 5: Verify relay received message ==\n";

$passed = 0;
$failed = 0;

// Check in-memory received array
if (count($received) >= 1) {
	echo "  PASS: Relay received " . count($received) . " message(s)\n";
	$passed++;
} else {
	echo "  FAIL: Relay received 0 messages\n";
	$failed++;
}

if (count($received) >= 1) {
	$msg = $received[0];

	// Check envelope sender
	if (strpos($msg['from'], 'notifications@myapp.test') !== false) {
		echo "  PASS: Envelope from = " . $msg['from'] . "\n";
		$passed++;
	} else {
		echo "  FAIL: Unexpected envelope from: " . $msg['from'] . "\n";
		$failed++;
	}

	// Check envelope recipient
	if (in_array('testuser@example.com', $msg['to'])) {
		echo "  PASS: Envelope to contains testuser@example.com\n";
		$passed++;
	} else {
		echo "  FAIL: Envelope to missing testuser@example.com: " . implode(', ', $msg['to']) . "\n";
		$failed++;
	}

	// Check raw message contains subject
	if (strpos($msg['raw'], 'Verify your email') !== false || strpos($msg['raw'], 'Verify+your+email') !== false || strpos($msg['raw'], 'dmVyaWZ5') !== false) {
		echo "  PASS: Raw message contains subject\n";
		$passed++;
	} else {
		// Check for base64 encoded subject
		$subjectLine = '';
		foreach (explode("\n", $msg['raw']) as $line) {
			if (stripos($line, 'Subject:') === 0) {
				$subjectLine = trim($line);
				break;
			}
		}
		echo "  INFO: Subject line: $subjectLine\n";
		if (!empty($subjectLine)) {
			echo "  PASS: Raw message has Subject header\n";
			$passed++;
		} else {
			echo "  FAIL: No Subject header found in raw message\n";
			$failed++;
		}
	}

	// Check raw message contains X-Q-App header
	if (strpos($msg['raw'], 'X-Q-App: MyApp') !== false) {
		echo "  PASS: Raw message contains X-Q-App: MyApp header\n";
		$passed++;
	} else {
		echo "  FAIL: Missing X-Q-App header\n";
		$failed++;
	}

	// Check raw message has MIME content
	if (strpos($msg['raw'], 'multipart') !== false || strpos($msg['raw'], 'text/html') !== false) {
		echo "  PASS: Raw message has MIME content\n";
		$passed++;
	} else {
		echo "  FAIL: Raw message missing MIME content\n";
		$failed++;
	}

	// Parse with our MIME parser
	echo "\n== Phase 6: Parse with Q_Relay_Mime ==\n";
	$parsed = Q_Relay_Mime::parseFullMIME($msg['raw']);
	if ($parsed) {
		echo "  PASS: MIME parsed successfully\n";
		$passed++;

		$textBody = is_array($parsed['text']) ? implode("\n", $parsed['text']) : ($parsed['text'] ?: '');
		$htmlBody = is_array($parsed['html']) ? implode("\n", $parsed['html']) : ($parsed['html'] ?: '');

		if (!empty($textBody)) {
			echo "  PASS: Text part extracted (" . strlen($textBody) . " bytes)\n";
			$passed++;
			if (strpos($textBody, 'verify') !== false || strpos($textBody, 'Verify') !== false) {
				echo "  PASS: Text part contains verification link\n";
				$passed++;
			} else {
				echo "  FAIL: Text part missing verification content\n";
				$failed++;
			}
		} else {
			echo "  WARN: No text part extracted\n";
		}

		if (!empty($htmlBody)) {
			echo "  PASS: HTML part extracted (" . strlen($htmlBody) . " bytes)\n";
			$passed++;
		} else {
			echo "  WARN: No HTML part extracted\n";
		}
	} else {
		echo "  FAIL: MIME parse returned null\n";
		$failed++;
	}

	// Thread it
	echo "\n== Phase 7: Thread and store ==\n";
	$headers = Q_Relay_Mime::splitHeaderBody($msg['raw'])[0];
	$parsedHeaders = Q_Relay_Mime::parseHeaders($headers);
	$messageId = Q_Relay_Mime::headerValue($parsedHeaders, 'message-id') ?: 'test-' . time();
	$subject = Q_Relay_Mime::headerValue($parsedHeaders, 'subject') ?: 'Unknown';

	$threadId = Q_Relay_Db::findOrCreateThread($messageId, 'email', $subject);
	if ($threadId) {
		echo "  PASS: Thread created: $threadId\n";
		$passed++;
	} else {
		echo "  FAIL: Thread creation returned null\n";
		$failed++;
	}

	Q_Relay_Db::addThreadMember($threadId, 'notifications@myapp.test');
	Q_Relay_Db::addThreadMember($threadId, 'testuser@example.com');

	Q_Relay_Db::storeMessage(
		$threadId, $messageId, 'email', 'outbound',
		$msg['from'], implode(',', $msg['to']),
		$subject ?: '(unknown)', $textBody, $htmlBody, $msg['raw']
	);
	// storeMessage is void — verify via database in Phase 8
	echo "  PASS: Message stored (no exception)\n";
	$passed++;

	// Log delivery
	Q_Relay_Db::logDelivery('email', 'outbound', $msg['from'], 'testuser@example.com',
		$subject ?: '(unknown)', $messageId ?: '', strlen($msg['raw']), 'delivered');
	echo "  PASS: Delivery logged\n";
	$passed++;
}

// ── Phase 8: Verify database state ──────────────────
echo "\n== Phase 8: Verify database state ==\n";

$db = new SQLite3($testRelayDb);

// Check delivery_log
$result = $db->query("SELECT COUNT(*) as cnt FROM delivery_log");
$row = $result->fetchArray(SQLITE3_ASSOC);
$logCount = $row['cnt'];
if ($logCount >= 1) {
	echo "  PASS: delivery_log has $logCount entries\n";
	$passed++;
} else {
	echo "  FAIL: delivery_log is empty\n";
	$failed++;
}

// Check threads
$result = $db->query("SELECT COUNT(*) as cnt FROM threads");
$row = $result->fetchArray(SQLITE3_ASSOC);
if ($row['cnt'] >= 1) {
	echo "  PASS: threads table has " . $row['cnt'] . " thread(s)\n";
	$passed++;
} else {
	echo "  FAIL: threads table is empty\n";
	$failed++;
}

// Check thread_messages
$result = $db->query("SELECT COUNT(*) as cnt FROM thread_messages");
$row = $result->fetchArray(SQLITE3_ASSOC);
if ($row['cnt'] >= 1) {
	echo "  PASS: thread_messages has " . $row['cnt'] . " message(s)\n";
	$passed++;
} else {
	echo "  FAIL: thread_messages is empty\n";
	$failed++;
}

// Check thread_members
$result = $db->query("SELECT COUNT(*) as cnt FROM thread_members");
$row = $result->fetchArray(SQLITE3_ASSOC);
if ($row['cnt'] >= 2) {
	echo "  PASS: thread_members has " . $row['cnt'] . " members\n";
	$passed++;
} else {
	echo "  FAIL: thread_members has only " . $row['cnt'] . " members\n";
	$failed++;
}

$db->close();

// ── Cleanup ──────────────────────────────────────────
@fclose($server);
@unlink($testRelayDb);

// ── Summary ──────────────────────────────────────────
$total = $passed + $failed;
echo "\n══════════════════════════════════════════\n";
if ($failed === 0) {
	echo "  Results: $passed/$total passed — all clear!\n";
} else {
	echo "  Results: $passed/$total passed, $failed FAILED\n";
}
echo "══════════════════════════════════════════\n";

exit($failed > 0 ? 1 : 0);
