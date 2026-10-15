#!/usr/bin/env php
<?php
/**
 * Command line: "--port 8123" works like "--port=8123", and a taken port is reported with its owner.
 * Run: php tests/test_cli.php
 */
$root = dirname(__DIR__);
require_once $root . '/src/Q/WebServer/PortOwner.php';
$pass = 0; $fail = 0;
function ok($cond, $msg) {
	global $pass, $fail;
	if ($cond) { ++$pass; echo "  PASS $msg\n"; } else { ++$fail; echo "  FAIL $msg\n"; }
}
$php = escapeshellarg(PHP_BINARY);
foreach (array('--port=18123', '--port 18123') as $form) {
	$out = shell_exec("cd " . escapeshellarg($root) . " && $php qbixserver.php -t $form 2>&1");
	ok(strpos((string) $out, 'port 18123') !== false, "$form sets the HTTP port");
}
$out = shell_exec("cd " . escapeshellarg($root) . " && $php qbixserver.php -t --port 2>&1");
ok(strpos((string) $out, 'port 18123') === false, 'a bare --port at the end does not swallow anything');
$out = shell_exec("cd " . escapeshellarg($root) . " && $php qbixserver.php -t --port --debug 2>&1");
ok(strpos((string) $out, 'Fatal') === false, '--port followed by another option is left alone');

ok(Q_WebServer_PortOwner::isInUse('Address already in use'), 'Linux/macOS wording is recognized');
ok(Q_WebServer_PortOwner::isInUse('x', 48) && Q_WebServer_PortOwner::isInUse('x', 98), 'errno 48 and 98 are recognized');
ok(Q_WebServer_PortOwner::isInUse('Only one usage of each socket address is normally permitted'), 'Windows wording is recognized');
ok(!Q_WebServer_PortOwner::isInUse('Permission denied', 13), 'other bind errors are not called "in use"');

$srv = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
$port = (int) substr(strrchr(stream_socket_get_name($srv, false), ':'), 1);
$found = Q_WebServer_PortOwner::find($port);
$have = trim((string) shell_exec('command -v lsof || command -v ss')) !== '' || is_readable('/proc/net/tcp');
if (PHP_OS_FAMILY === 'Windows' || !$have) {
	echo "  SKIP no lsof/ss//proc here\n";
} else {
	ok(count($found) === 1 && $found[0]['pid'] === getmypid(), 'finds this process as the owner of the port');
	ok($found && strpos($found[0]['command'], 'test_cli') !== false, 'reports the command line');
	ok($found && $found[0]['cwd'] !== '', 'reports where it was started');
	$rep = Q_WebServer_PortOwner::report($port);
	ok(strpos($rep, 'PID ' . getmypid()) !== false && strpos($rep, '--port ' . ($port + 1)) !== false, 'the report names the process and offers another port');
}
fclose($srv);
ok(Q_WebServer_PortOwner::find($port) === array(), 'a free port has no owner');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
