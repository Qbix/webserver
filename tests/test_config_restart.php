#!/usr/bin/env php
<?php
/** Panel-saved domains survive a restart; auto-cache API works. Run: php tests/test_config_restart.php */
require_once dirname(__DIR__) . '/src/Q.php';
Q::init(dirname(__DIR__));
$testDir = sys_get_temp_dir() . '/qbix_test_cfgrs_' . getmypid();
@mkdir($testDir . '/local', 0755, true);
@mkdir($testDir . '/apps/Unblocks/web', 0755, true);
function qbix_data_path($rel) { global $testDir; return $testDir . '/' . $rel; }
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { ++$pass; echo "  PASS $m\n"; } else { ++$fail; echo "  FAIL $m\n"; } }
Q_Config::set('Q', 'webserver', 'panel', 'appsDir', $testDir . '/apps');
file_put_contents($testDir . '/local/panel.json', json_encode(array('passwordHash' => 'x')));
function req($b) { return array('headers' => array('host' => 'x.test'), 'body' => json_encode($b)); }

$r = Q_WebServer_Panel::apiDomainSetApp(req(array('domain' => 'unblocks.us', 'app' => 'Unblocks')));
ok(!empty($r['ok']), 'domain assigned to app');
$r = Q_WebServer_Panel::apiDomainCacheUpdate(req(array('domain' => 'unblocks.us', 'enabled' => true)));
ok(empty($r['error']), 'auto-cache update does not error: ' . json_encode($r));

// Simulate a restart: forget in-memory config, load files the way startup does
Q_Config::set('Q', 'webserver', 'domains', 'unblocks.us', null);
ok(Q_Config::get('Q', 'webserver', 'domains', 'unblocks.us', null) === null, 'in-memory entry gone');
$chdir = getcwd(); chdir(sys_get_temp_dir()); // cwd is NOT the app dir, as when launched from elsewhere
$m = new ReflectionMethod('Q_WebServer', 'configFileList'); $m->setAccessible(true);
$files = $m->invoke(null);
$n = new ReflectionMethod('Q_WebServer', 'normalizePanelConfig'); $n->setAccessible(true);
foreach ($files as $f) { $d = json_decode(file_get_contents($f), true); $n->invokeArgs(null, array(&$d)); Q_Config::merge($d); }
chdir($chdir);
$e = Q_Config::get('Q', 'webserver', 'domains', 'unblocks.us', null);
ok(is_array($e) && ($e['app'] ?? '') === 'Unblocks' && !empty($e['root']), 'domain served by its app again after restart');
ok(!empty($e['cache']['auto']['enabled']), 'cache setting survived');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
