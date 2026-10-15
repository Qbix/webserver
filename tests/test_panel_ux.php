#!/usr/bin/env php
<?php
/** CDN detection, language catalogs, removable flag. Run: php tests/test_panel_ux.php */
require_once dirname(__DIR__) . '/src/Q.php';
Q::init(dirname(__DIR__));
$testDir = sys_get_temp_dir() . '/qbix_test_ux_' . getmypid();
@mkdir($testDir . '/local/lang', 0755, true);
@mkdir($testDir . '/apps/App/web', 0755, true);
function qbix_data_path($rel) { global $testDir; return $testDir . '/' . $rel; }
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { ++$pass; echo "  PASS $m\n"; } else { ++$fail; echo "  FAIL $m\n"; } }
Q_Config::set('Q', 'webserver', 'panel', 'appsDir', $testDir . '/apps');
file_put_contents($testDir . '/local/panel.json', json_encode(array('passwordHash' => 'x')));
function req($b, $h = array()) { return array('headers' => array_merge(array('host' => 'x.test'), $h), 'body' => json_encode($b)); }

ok(Q_WebServer_Panel::cdnFromHeaders(array('cf-ray' => 'abc-LHR')) === 'Cloudflare', 'Cloudflare from cf-ray');
ok(Q_WebServer_Panel::cdnFromHeaders(array('X-Amz-Cf-Id' => 'x')) === 'Amazon CloudFront', 'CloudFront, any header case');
ok(Q_WebServer_Panel::cdnFromHeaders(array('x-forwarded-proto' => 'https')) === 'a reverse proxy', 'plain forwarding header is a reverse proxy');
ok(Q_WebServer_Panel::cdnFromHeaders(array('host' => 'x.test', 'accept' => '*/*')) === null, 'no proxy headers: null');

Q_Config::set('Q', 'webserver', 'domains', 'cdn.test', array('root' => $testDir . '/apps/App/web'));
$r = Q_WebServer_Panel::apiProvisionCert(req(array('domain' => 'cdn.test'), array('cf-ray' => '1', 'cf-connecting-ip' => '1.2.3.4')));
ok(!empty($r['proxied']) && $r['cdn'] === 'Cloudflare' && strpos($r['error'], 'Cloudflare') !== false, 'provision through Cloudflare asks first and explains');


file_put_contents($testDir . '/local/lang/xx.json', json_encode(array('Cancel' => 'Abbrechen-X', '{n} workers running.' => '{n} läuft')));
$r = Q_WebServer_Panel::apiI18n(req(array('lang' => 'xx-YY')));
ok($r['lang'] === 'xx' && $r['strings']['Cancel'] === 'Abbrechen-X' && in_array('xx', $r['available']) && in_array('es', $r['available']), 'local catalog is loaded, shipped ones listed');
$r = Q_WebServer_Panel::apiI18n(req(array('lang' => 'es-MX')));
ok($r['lang'] === 'es' && !empty($r['strings']['Save']), 'regional code falls back to base language');
$r = Q_WebServer_Panel::apiI18n(req(array('lang' => '../../etc/passwd')));
ok($r['lang'] === 'en' && !$r['strings'], 'path tricks give English');

$r = Q_WebServer_Panel::apiDomainSetApp(req(array('domain' => 'a.test', 'app' => 'App')));
$list = Q_WebServer_Panel::apiListDomains(req(array()));
$by = array(); foreach ($list['domains'] as $d) $by[$d['domain']] = $d;
ok(!empty($by['a.test']['removable']), 'a panel-added domain can be removed');
ok(isset($by['cdn.test']) ? empty($by['cdn.test']['removable']) : true, 'a config-file domain is not offered Remove');

// A request through Cloudflare marks the configured domain, in memory and in panel.json
Q_WebServer_Panel::apiDomainSetApp(req(array('domain' => 'cf.test', 'app' => 'App')));
Q_WebServer_Panel::noteCdn(array('host' => 'cf.test:443', 'cf-ray' => 'x'));
$pj = json_decode(file_get_contents($testDir . '/local/panel.json'), true);
ok(($pj['domains']['cf.test']['cdn'] ?? '') === 'Cloudflare' && (Q_Config::get('Q', 'webserver', 'domains', 'cf.test', array())['cdn'] ?? '') === 'Cloudflare', 'Cloudflare is remembered on the domain');
Q_WebServer_Panel::noteCdn(array('host' => 'unknown.test', 'cf-ray' => 'x'));
ok(Q_Config::get('Q', 'webserver', 'domains', 'unknown.test', null) === null, 'an unconfigured host is not added');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
