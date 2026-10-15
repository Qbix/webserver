#!/usr/bin/env php
<?php
/** AI catalog, RFC 9727 API catalog, MCP server card, llms.txt. Run: php tests/test_discovery.php */
require_once dirname(__DIR__) . '/src/Q.php';
Q::init(dirname(__DIR__));
require_once dirname(__DIR__) . '/src/Q/WebServer/Discovery.php';
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { ++$pass; echo "  PASS $m\n"; } else { ++$fail; echo "  FAIL $m\n"; } }
$p = array('headers' => array('host' => 'lab.example.net'), 'https' => true);
$hasMcp = Q_WebServer_Discovery::hasMcp();

$r = Q_WebServer_Discovery::route('/.well-known/ai-catalog.json', $p);
$d = json_decode($r['body'], true);
ok($r['status'] === 200 && $r['headers']['Content-Type'] === 'application/ai-catalog+json', 'ai-catalog served with its media type');
ok($d['specVersion'] === '1.0' && $d['host']['identifier'] === 'did:web:lab.example.net', 'catalog host identity');
$types = array_column($d['entries'], 'type');
ok(in_array('application/openapi+json', $types), 'catalog links the OpenAPI description');
ok(in_array('application/mcp-server-card+json', $types) === $hasMcp, 'catalog links a server card exactly when MCP exists');
ok($r['headers']['Access-Control-Allow-Origin'] === '*', 'catalog is readable cross-origin');

$r = Q_WebServer_Discovery::route('/.well-known/api-catalog', $p);
$d = json_decode($r['body'], true);
ok(strpos($r['headers']['Content-Type'], 'application/linkset+json') === 0
	&& strpos($r['headers']['Content-Type'], 'rfc9727') !== false, 'api-catalog is a linkset with the RFC 9727 profile');
ok($d['linkset'][0]['service-desc'][0]['href'] === 'https://lab.example.net/.well-known/openapi.json', 'api-catalog service-desc is the OpenAPI document');
ok(isset($d['linkset'][0]['status'][0]['href']), 'api-catalog has a status link');

$r = Q_WebServer_Discovery::route('/mcp/server-card', $p);
if ($hasMcp) {
	$d = json_decode($r['body'], true);
	ok($r['headers']['Content-Type'] === 'application/mcp-server-card+json', 'server card media type');
	ok($d['name'] === 'net.example.lab/server', 'card name is reverse DNS: ' . $d['name']);
	ok($d['remotes'][0]['type'] === 'streamable-http' && $d['remotes'][0]['url'] === 'https://lab.example.net/mcp', 'card points at /mcp');
	ok(!isset($d['tools']) && !isset($d['capabilities']), 'card carries no primitives or capabilities');
	ok(Q_WebServer_Discovery::route('/.well-known/mcp/server-card', $p)['body'] === $r['body'], 'well-known alias serves the same card');
} else {
	ok($r['status'] === 404, 'no MCP in this build: no server card');
}
ok(Q_WebServer_Discovery::route('/something-else', $p) === null, 'other paths are not ours');

$local = array('headers' => array('host' => 'localhost:8080'));
$d = json_decode(Q_WebServer_Discovery::route('/.well-known/api-catalog', $local)['body'], true);
ok(strpos($d['linkset'][0]['anchor'], 'http://localhost:8080') === 0, 'localhost keeps http and its port');
ok(Q_WebServer_Discovery::cardName($local) === 'local.localhost/server', 'a name that is not a domain still yields a valid card name');

$txt = Q_WebServer_Discovery::llmsTxt($p);
ok(strpos($txt, '/.well-known/ai-catalog.json') !== false && strpos($txt, '/.well-known/api-catalog') !== false, 'llms.txt lists the catalogs');

foreach (array('/llms.txt', '/llm.txt', '/.well-known/llms.txt', '/.well-known/llm.txt') as $n) {
	$r = Q_WebServer_Discovery::route($n, $p);
	ok($r && $r['status'] === 200 && strpos($r['headers']['Content-Type'], 'text/plain') === 0
		&& strpos($r['body'], '/.well-known/ai-catalog.json') !== false, "$n is generated and lists the catalogs");
}
$bp = $p + array('_branchRecord' => array('name' => 'testing1', 'appHost' => 'lab.example.net'));
ok(strpos(Q_WebServer_Discovery::route('/llms.txt', $bp)['body'], 'branch named "testing1" of https://lab.example.net/') !== false, 'llms.txt of a branch links to the main site');

$w = Q_WebServer::renderWelcome($bp, 'empty');
ok(strpos($w, 'This is a branch') !== false && strpos($w, 'href="https://lab.example.net/"') !== false, 'welcome page of a branch links to the main site');
ok(strpos($w, 'request a merge') !== false, 'branch welcome page names the next step');
$w = Q_WebServer::renderWelcome(array('headers' => array('host' => 'new.example.net')), 'unclaimed');
ok(strpos($w, 'No app is set up for new.example.net yet') !== false && strpos($w, '/Q/panel#domains') !== false, 'unclaimed host: says so and links to Domains');
$w = Q_WebServer::renderWelcome(array('headers' => array('host' => 'a.example.net')), 'empty');
ok(strpos($w, 'no home page yet') !== false, 'app without index file: welcome page, not an error');
$w = Q_WebServer::renderWelcome(array('headers' => array('host' => '<script>x</script>.example.net')), 'unclaimed');
ok(strpos($w, '<script>x</script>') === false, 'host name is escaped');

echo "\n  passed: $pass  failed: $fail\n";
exit($fail ? 1 : 0);
