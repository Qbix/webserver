#!/usr/bin/env php
<?php
/** Per-domain app dropdown: domains/set-app and the domain/app lists. Run: php tests/test_domain_app.php */
require_once dirname(__DIR__) . '/src/Q.php';
Q::init(dirname(__DIR__));
$testDir = sys_get_temp_dir() . '/qbix_test_domapp_' . getmypid();
@mkdir($testDir . '/local', 0755, true);
@mkdir($testDir . '/apps/Unblocks/web', 0755, true);
@mkdir($testDir . '/apps/Other/public', 0755, true);
@mkdir($testDir . '/apps/Bare', 0755, true);
function qbix_data_path($rel) { global $testDir; return $testDir . '/' . $rel; }
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { ++$pass; echo "  PASS $m\n"; } else { ++$fail; echo "  FAIL $m\n"; } }
Q_Config::set('Q', 'webserver', 'panel', 'appsDir', $testDir . '/apps');
file_put_contents($testDir . '/local/panel.json', json_encode(array('passwordHash' => 'x')));
function req($b) { return array('headers' => array('host' => 'x.test'), 'body' => json_encode($b)); }

$r = Q_WebServer_Panel::apiDomainSetApp(req(array('domain' => 'a.example.com', 'app' => 'Unblocks')));
ok(!empty($r['ok']) && $r['app'] === 'Unblocks', 'assigns an app to a domain');
$r = Q_WebServer_Panel::apiDomainSetApp(req(array('domain' => 'b.example.com', 'app' => 'Unblocks')));
ok(!empty($r['ok']), 'the same app can serve a second domain');
$r = Q_WebServer_Panel::apiDomainSetApp(req(array('domain' => 'a.example.com', 'app' => 'Other')));
ok(($r['root'] ?? '') === realpath($testDir . '/apps/Other/public'), 'switching app uses its public/ folder');
$e = Q_Config::get('Q', 'webserver', 'domains', 'a.example.com', null);
ok($e['app'] === 'Other', 'config reflects the switch');
$apps = Q_WebServer_Panel::apiListApps(req(array()));
$by = array(); foreach (($apps['apps'] ?? $apps) as $a) $by[$a['name']] = $a;
ok(isset($by['Unblocks']) && in_array('b.example.com', $by['Unblocks']['domains'] ?? array()) && !in_array('a.example.com', $by['Unblocks']['domains'] ?? array()), 'app list names the domains each app serves');
ok(!empty($by['Other']['serving']), 'serving flag is set for an app with domains');
$r = Q_WebServer_Panel::apiDomainSetApp(req(array('domain' => 'a.example.com', 'app' => 'Bare')));
ok(($r['status'] ?? 0) === 400, 'app without a web folder is refused');
$r = Q_WebServer_Panel::apiDomainSetApp(req(array('domain' => 'a.example.com', 'app' => '..')));
ok(($r['status'] ?? 0) === 400, '".." is not an app name');
$r = Q_WebServer_Panel::apiDomainSetApp(req(array('domain' => 'a.example.com', 'app' => 'Nope')));
ok(($r['status'] ?? 0) === 404, 'unknown app gives 404');
$r = Q_WebServer_Panel::apiDomainSetApp(req(array('domain' => 'bad host', 'app' => 'Other')));
ok(($r['status'] ?? 0) === 400, 'invalid domain refused');
$r = Q_WebServer_Panel::apiDomainSetApp(req(array('domain' => 'b.example.com', 'app' => '')));
ok(!empty($r['ok']) && Q_Config::get('Q', 'webserver', 'domains', 'b.example.com', null) === null, 'empty app clears the domain (welcome page)');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
