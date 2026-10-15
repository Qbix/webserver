#!/usr/bin/env php
<?php
/**
 * A repeating timer may cancel itself from its own callback (the certificate request reaper does).
 * The loop must not bring it back as a half-made entry that fails on the next turn.
 * Run: php tests/test_evented_timers.php
 */
require_once dirname(__DIR__) . '/src/Q.php';
Q::init(dirname(__DIR__));
$pass = 0; $fail = 0;
function ok($cond, $msg) {
	global $pass, $fail;
	if ($cond) { ++$pass; echo "  PASS $msg\n"; } else { ++$fail; echo "  FAIL $msg\n"; }
}
$fired = 0; $other = 0; $id = null;
$id = Q_Evented::repeat(0.01, function () use (&$fired, &$id) {
	$fired++;
	Q_Evented::cancel($id);
});
Q_Evented::repeat(0.01, function () use (&$other) { $other++; });
$err = null;
try {
	$end = microtime(true) + 0.3;
	while (microtime(true) < $end) Q_Evented::tick(0.02);
} catch (Throwable $e) { $err = $e->getMessage(); }
ok($err === null, 'ticking after a timer cancelled itself does not fail' . ($err ? " ($err)" : ''));
ok($fired === 1, 'a timer that cancelled itself fired once and not again');
ok($other > 3, 'other timers keep running');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
