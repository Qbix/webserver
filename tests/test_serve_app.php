#!/usr/bin/env php
<?php
/**
 * Enabling an app in the panel ties it to the domain the panel was opened on, so that domain
 * stops getting the welcome page, survives a restart (panel.json) and goes back when disabled.
 * Also: certificate requests wait their turn instead of failing when the worker limit is hit.
 * Run: php tests/test_serve_app.php
 */
require_once dirname(__DIR__) . '/src/Q.php';
Q::init(dirname(__DIR__));
$testDir = sys_get_temp_dir() . '/qbix_test_serve_' . getmypid();
@mkdir($testDir . '/local', 0755, true);
@mkdir($testDir . '/apps/Unblocks/web', 0755, true);
function qbix_data_path($rel) { global $testDir; return $testDir . '/' . $rel; }
$pass = 0; $fail = 0;
function ok($cond, $msg) {
	global $pass, $fail;
	if ($cond) { ++$pass; echo "  PASS $msg\n"; } else { ++$fail; echo "  FAIL $msg\n"; }
}
Q_Config::set('Q', 'webserver', 'panel', 'appsDir', $testDir . '/apps');
$cfg = $testDir . '/local/panel.json';
file_put_contents($cfg, json_encode(array('passwordHash' => 'x')));
function req($host, $body) {
	return array('headers' => array('host' => $host), 'body' => json_encode($body));
}
$web = realpath($testDir . '/apps/Unblocks/web');

ok(Q_Config::get('Q', 'webserver', 'domains', 'unblocks.us', null) === null, 'unclaimed domain has no config (welcome page)');
$r = Q_WebServer_Panel::apiServeApp(req('unblocks.us:443', array('app' => 'Unblocks', 'enable' => true)));
ok(!empty($r['ok']) && $r['host'] === 'unblocks.us', 'enable reports the domain it applied to');
$hc = Q_Config::get('Q', 'webserver', 'domains', 'unblocks.us', null);
ok(is_array($hc) && $hc['root'] === $web && $hc['app'] === 'Unblocks', 'domain now has the app web folder as its root');
$saved = json_decode(file_get_contents($cfg), true);
ok(($saved['domains']['unblocks.us']['root'] ?? '') === $web, 'root is saved in panel.json, so a restart keeps it');
ok(($saved['passwordHash'] ?? '') === 'x', 'other panel.json settings are untouched');

$r = Q_WebServer_Panel::apiServeApp(req('unblocks.us', array('app' => 'Unblocks', 'enable' => false)));
ok(Q_Config::get('Q', 'webserver', 'domains', 'unblocks.us', null) === null, 'disable returns the domain to the welcome page');
$saved = json_decode(file_get_contents($cfg), true);
ok(!isset($saved['domains']['unblocks.us']), 'and removes the entry the panel created');

// A domain the owner configured by hand keeps its other settings
file_put_contents($cfg, json_encode(array('domains' => array('mine.example.com' => array('tls' => 'manual', 'proxied' => true)))));
Q_WebServer_Panel::apiServeApp(req('mine.example.com', array('app' => 'Unblocks', 'enable' => true)));
$saved = json_decode(file_get_contents($cfg), true);
ok(($saved['domains']['mine.example.com']['proxied'] ?? false) === true && $saved['domains']['mine.example.com']['root'] === $web, 'enable keeps existing settings of a configured domain');
Q_WebServer_Panel::apiServeApp(req('mine.example.com', array('app' => 'Unblocks', 'enable' => false)));
$saved = json_decode(file_get_contents($cfg), true);
ok(isset($saved['domains']['mine.example.com']) && $saved['domains']['mine.example.com']['root'] === null && $saved['domains']['mine.example.com']['proxied'] === true, 'disable on a configured domain clears only root and app');

// localhost and IP addresses get no domain entry
foreach (array('localhost:8080', '127.0.0.1', '10.0.0.5:80', 'sub.localhost') as $h) {
	$before = file_get_contents($cfg);
	$r = Q_WebServer_Panel::apiServeApp(req($h, array('app' => 'Unblocks', 'enable' => true)));
	ok(!empty($r['ok']) && $r['host'] === null && file_get_contents($cfg) === $before, "$h: served in memory only, no domain entry");
}
$r = Q_WebServer_Panel::apiServeApp(req('evil.com', array('app' => 'Unblocks', 'enable' => true, 'host' => "x\"; DROP")));
ok(!empty($r['ok']) && $r['host'] === null, 'a malformed host is ignored');

// Certificate requests queue instead of failing at the limit
$p = new ReflectionProperty('Q_WebServer_Acme', 'maxChildren');
if (PHP_VERSION_ID < 80100) $p->setAccessible(true);
$old = $p->getValue();
$p->setValue(null, 0);
$r = Q_WebServer_Acme::provisionAsync(array('app.example.com'), $testDir . '/certs', 'a@b.com', true, null, 'acme', array('queue' => true));
ok(!empty($r['queued']) && empty($r['started']), 'at the limit a queued request waits');
$r2 = Q_WebServer_Acme::provisionAsync(array('app.example.com'), $testDir . '/certs', 'a@b.com', true, null, 'acme', array('queue' => true));
ok(!empty($r2['queued']), 'asking again does not queue it twice');
$q = new ReflectionProperty('Q_WebServer_Acme', 'queue');
if (PHP_VERSION_ID < 80100) $q->setAccessible(true);
ok(count($q->getValue()) === 1, 'one entry in the queue');
$r = Q_WebServer_Acme::provisionAsync(array('other.example.com'), $testDir . '/certs', 'a@b.com', true);
ok(empty($r['started']) && !empty($r['error']) && empty($r['queued']), 'without the queue option it still fails at once');
$q->setValue(null, array());
$p->setValue(null, $old);

exec('rm -rf ' . escapeshellarg($testDir));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
