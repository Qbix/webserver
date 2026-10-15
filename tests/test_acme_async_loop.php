#!/usr/bin/env php
<?php
/**
 * A certificate request runs in a child process forked while the server's event loop is running, so
 * the child inherits a loop that says it is running. The child waits with its own sleep; it must not
 * take that inherited loop for the server and report "Server is shutting down" at its first wait.
 * Run: php tests/test_acme_async_loop.php
 */
require_once dirname(__DIR__) . '/src/Q.php';
Q::init(dirname(__DIR__));
$pass = 0; $fail = 0;
function ok($cond, $msg) {
	global $pass, $fail;
	if ($cond) { ++$pass; echo "  PASS $msg\n"; } else { ++$fail; echo "  FAIL $msg\n"; }
}
function setStatic($name, $value) {
	$p = new ReflectionProperty('Q_WebServer_Acme', $name);
	if (PHP_VERSION_ID < 80100) $p->setAccessible(true);
	$p->setValue(null, $value);
}
$pump = new ReflectionMethod('Q_WebServer_Acme', 'pump');
if (PHP_VERSION_ID < 80100) $pump->setAccessible(true);

$err = 'not run';
Q_Evented::delay(0.05, function () use ($pump, &$err) {
	// What the child sees: a loop that reports running, and the child's own flags
	setStatic('inChild', true);
	$note = new ReflectionMethod('Q_WebServer_Acme', 'noteLoop');
	if (PHP_VERSION_ID < 80100) $note->setAccessible(true);
	$note->invoke(null); // what provision() does first, in the child
	try { $pump->invoke(null, 0.3); $err = null; } catch (Throwable $e) { $err = $e->getMessage(); }
	setStatic('inChild', false);
	Q_Evented::stop();
});
Q_Evented::delay(10, function () { Q_Evented::stop(); }); // safety net
Q_Evented::run();
ok($err === null, 'a child waiting while the inherited loop reports running does not throw' . ($err ? " ($err)" : ''));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
