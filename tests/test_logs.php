#!/usr/bin/env php
<?php
/**
 * Log rotation, archiving and deletion (Q_WebServer_LogRotate).
 * Run: php tests/test_logs.php
 */
require_once dirname(__DIR__) . '/src/Q.php';
Q::init(dirname(__DIR__));
$testDir = sys_get_temp_dir() . '/qbix_test_logs_' . getmypid();
@mkdir($testDir . '/local', 0755, true);
function qbix_data_path($rel) { global $testDir; return $testDir . '/' . $rel; }
$pass = 0; $fail = 0;
function ok($cond, $msg) {
	global $pass, $fail;
	if ($cond) { ++$pass; echo "  PASS $msg\n"; } else { ++$fail; echo "  FAIL $msg\n"; }
}
$relayLog = $testDir . '/local/relay.log';
Q_Config::set('Q', 'webserver', 'logs', 'files', array('relay' => $relayLog));
Q_Config::set('Q', 'webserver', 'autohost', 'log', $testDir . '/local/autohost.log');
Q_Config::set('Q', 'webserver', 'watchdog', 'log', $testDir . '/local/watchdog.log');
$now = strtotime('2026-03-10 12:00:00');
$day = 86400;
function names($dir) { $n = array_map('basename', glob($dir . '/*')); sort($n); return array_values(array_filter($n, function ($x) { return $x[0] !== '.'; })); }

echo "--- rotation\n";
file_put_contents($relayLog, str_repeat("x\n", 100));
$r = Q_WebServer_LogRotate::run($now);
ok($r['relay']['rotated'] === null && is_file($relayLog), 'a small, new log is left alone (and its clock starts)');
$r = Q_WebServer_LogRotate::run($now + $day - 10);
ok($r['relay']['rotated'] === null, 'a day has not passed yet');
$r = Q_WebServer_LogRotate::run($now + $day + 10);
ok($r['relay']['rotated'] === 'relay.2026-03-11.log' && !is_file($relayLog) && is_file($testDir . '/local/relay.2026-03-11.log'), 'after rotateDays it is renamed with the date');
file_put_contents($relayLog, "again\n");
$r = Q_WebServer_LogRotate::run($now + $day + 20);
ok($r['relay']['rotated'] === null, 'the clock restarted at the rotation');
Q_Config::set('Q', 'webserver', 'logs', 'maxSize', 2048);
file_put_contents($relayLog, str_repeat('y', 5000));
$r = Q_WebServer_LogRotate::run($now + $day + 30);
ok($r['relay']['rotated'] === 'relay.2026-03-11-' . date('His', $now + $day + 30) . '.log', 'over maxSize it rotates at once, and a name that exists gets a time');
Q_Config::set('Q', 'webserver', 'logs', 'maxSize', 20971520);
file_put_contents($relayLog, "z\n");
Q_Config::set('Q', 'webserver', 'logs', 'rotateDays', 0);
$r = Q_WebServer_LogRotate::run($now + 40 * $day);
ok($r['relay']['rotated'] === null, 'rotateDays 0 means size only');
Q_Config::set('Q', 'webserver', 'logs', 'rotateDays', 1);
file_put_contents($testDir . '/local/empty.log', '');
Q_Config::set('Q', 'webserver', 'logs', 'files', array('empty' => $testDir . '/local/empty.log', 'bad name!' => '/etc/passwd'));
$r = Q_WebServer_LogRotate::run($now + 50 * $day);
ok(isset($r['empty']) && $r['empty']['rotated'] === null && !isset($r['bad name!']), 'an empty file is not rotated; a bad name in "files" is ignored');

echo "--- archive and delete\n";
$dir = $testDir . '/local';
foreach (array('relay.2026-01-01.log', 'relay.2026-02-20.log', 'relay.2026-03-09.log', 'relay.2026-01-02.log.gz') as $f) file_put_contents("$dir/$f", "old $f\n");
touch("$dir/relay.2026-01-01.log", $now - 60 * $day);
touch("$dir/relay.2026-02-20.log", $now - 10 * $day);
touch("$dir/relay.2026-03-09.log", $now - 1 * $day);
touch("$dir/relay.2026-01-02.log.gz", $now - 59 * $day);
file_put_contents("$dir/relay.keepme.log", 'not ours'); touch("$dir/relay.keepme.log", $now - 90 * $day);
file_put_contents("$dir/relayx.2026-01-01.log", 'other file'); touch("$dir/relayx.2026-01-01.log", $now - 90 * $day);
file_put_contents($relayLog, "live\n"); touch($relayLog, $now - 400 * $day);
Q_WebServer_LogRotate::run($now);
$n = names($dir);
ok(!in_array('relay.2026-01-01.log', $n) && !in_array('relay.2026-01-01.log.gz', $n) && !in_array('relay.2026-01-02.log.gz', $n), 'older than deleteAfterDays: deleted (log and archive)');
ok(in_array('relay.2026-02-20.log.gz', $n) && !in_array('relay.2026-02-20.log', $n), 'older than archiveAfterDays: compressed');
ok(strpos(implode(gzfile("$dir/relay.2026-02-20.log.gz")), 'old relay.2026-02-20.log') === 0, '... and readable');
ok(in_array('relay.2026-03-09.log', $n), 'recent: left as it was');
ok(in_array('relay.keepme.log', $n) && in_array('relayx.2026-01-01.log', $n), 'files that this class did not make are never touched');
ok(is_file($relayLog), 'the live log is never deleted');
Q_Config::set('Q', 'webserver', 'logs', 'deleteAfterDays', 0);
touch("$dir/relay.2026-02-20.log.gz", $now - 400 * $day);
Q_WebServer_LogRotate::run($now);
ok(in_array('relay.2026-02-20.log.gz', names($dir)), 'deleteAfterDays 0 keeps archives');
Q_Config::set('Q', 'webserver', 'logs', 'deleteAfterDays', 30);
Q_Config::set('Q', 'webserver', 'logs', 'managed', false);
ok(Q_WebServer_LogRotate::run($now + 1000 * $day) === array(), 'managed false turns it all off');
Q_Config::set('Q', 'webserver', 'logs', 'managed', true);

echo "\n══════════════════════════════════════════\n  Results: $pass/" . ($pass + $fail) . ($fail ? " — $fail FAILED" : ' — all clear!') . "\n══════════════════════════════════════════\n";
exec('rm -rf ' . escapeshellarg($testDir));
exit($fail ? 1 : 0);
