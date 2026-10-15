#!/usr/bin/env php
<?php
/** A failed certificate request stays visible in the panel until the next one. Run: php tests/test_cert_errors.php */
require_once dirname(__DIR__) . '/src/Q.php';
Q::init(dirname(__DIR__));
$d = sys_get_temp_dir() . '/qbix_test_certerr_' . getmypid();
@mkdir("$d/certs", 0755, true); @mkdir("$d/local", 0755, true);
function qbix_data_path($rel) { global $d; return "$d/$rel"; }
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { ++$pass; echo "  PASS $m\n"; } else { ++$fail; echo "  FAIL $m\n"; } }
Q_Config::set('Q', 'webserver', 'tls', 'certDir', "$d/certs");
Q_Config::set('Q', 'webserver', 'tls', 'acmeEmail', 'a@b.com');
file_put_contents("$d/local/panel.json", '{}');

$called = null;
$r = Q_WebServer_Acme::provisionAsync(array('foo.test'), "$d/certs", 'a@b.com', false, function ($x) use (&$called) { $called = $x; });
ok(empty($r['started']) && $called && empty($called['success']), 'a name that cannot be certified fails at once, without exiting');
$f = Q_WebServer_Acme::lastFailure("$d/certs", 'foo.test');
ok($f && strpos($f['error'], 'not a public domain') !== false && !empty($f['at']), 'the failure and its time are kept for later');

Q_WebServer_Acme::clearResult("$d/certs", 'foo.test');
ok(Q_WebServer_Acme::lastFailure("$d/certs", 'foo.test') === null, 'a new request clears the old failure');

Q_Config::set('Q', 'webserver', 'tls', 'certbot', 'bin', '/nonexistent/certbot');
$r = Q_WebServer_Acme::provisionAsync(array('app.example.org'), "$d/certs", 'a@b.com', false, null, 'certbot', array());
$f = Q_WebServer_Acme::lastFailure("$d/certs", 'app.example.org');
ok(empty($r['started']) && $f && strpos($f['error'], 'Cannot use certbot') === 0 && ($f['via'] ?? '') === 'certbot', 'certbot missing: the reason is kept, tagged certbot');

file_put_contents("$d/certs/.results/ok.example.org.json", json_encode(array('success' => true)));
ok(Q_WebServer_Acme::lastFailure("$d/certs", 'ok.example.org') === null, 'a success is not reported as a failure');

// The panel's domain list carries the error
Q_Config::set('Q', 'webserver', 'domains', 'app.example.org', array('root' => $d));
$l = Q_WebServer_Panel::apiListDomains(array('headers' => array('host' => 'x.test')));
$row = null; foreach ($l['domains'] as $e) if ($e['domain'] === 'app.example.org') $row = $e;
ok($row && !empty($row['certError']) && $row['certStatus'] === 'none', 'domain list returns certError for a domain without a certificate');

// Provision through the panel: certbot is wanted for a proxied domain and its failure is returned and kept
Q_Config::set('Q', 'webserver', 'domains', 'app.example.org', array('root' => $d, 'proxied' => true));
Q_WebServer_Acme::clearResult("$d/certs", 'app.example.org');
$r = Q_WebServer_Panel::apiProvisionCert(array('headers' => array(), 'body' => json_encode(array('domain' => 'app.example.org'))));
ok(!empty($r['error']) && strpos($r['error'], 'certbot') !== false, 'panel: proxied domain goes through certbot and reports why it cannot');
ok(Q_WebServer_Acme::lastFailure("$d/certs", 'app.example.org') !== null, 'and the failure is still there afterwards');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
