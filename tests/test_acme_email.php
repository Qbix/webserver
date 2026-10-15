#!/usr/bin/env php
<?php
/**
 * Panel: provisioning a certificate with no ACME email asks for one, and keeps it.
 * Run: php tests/test_acme_email.php
 */
require_once dirname(__DIR__) . '/src/Q.php';
Q::init(dirname(__DIR__));
$testDir = sys_get_temp_dir() . '/qbix_test_acme_' . getmypid();
@mkdir($testDir . '/local', 0755, true);
function qbix_data_path($rel) { global $testDir; return $testDir . '/' . $rel; }
$pass = 0; $fail = 0;
function ok($cond, $msg) {
	global $pass, $fail;
	if ($cond) { ++$pass; echo "  PASS $msg\n"; } else { ++$fail; echo "  FAIL $msg\n"; }
}
function provision($body, $headers = array()) {
	return Q_WebServer_Panel::apiProvisionCert(array('body' => json_encode($body), 'headers' => $headers));
}
$r = provision(array('domain' => 'a.example.com'));
ok(!empty($r['needsEmail']) && $r['status'] === 400, 'no email: asks for one instead of telling you to edit config');
$r = provision(array('domain' => 'a.example.com', 'email' => 'nope'));
ok(!empty($r['needsEmail']), 'a bad address is asked for again');
$r = provision(array('domain' => 'a.example.com'), array('x-forwarded-proto' => 'https'));
ok(!empty($r['proxied']), 'behind nginx it says nginx owns the certificate');
$m = new ReflectionMethod('Q_WebServer_Panel', 'saveAcmeEmail');
$m->setAccessible(true);
$m->invoke(null, 'greg@example.com');
ok(Q_Config::get('Q', 'webserver', 'tls', 'acmeEmail', '') === 'greg@example.com', 'the email is live in the running config');
$saved = json_decode(file_get_contents(qbix_data_path('local/panel.json')), true);
ok(($saved['Q']['webserver']['tls']['acmeEmail'] ?? '') === 'greg@example.com', 'and written to panel.json for next start');
echo "\n  Results: $pass/" . ($pass + $fail) . ($fail ? " — $fail FAILED" : " — all clear!") . "\n";
exit($fail ? 1 : 0);
