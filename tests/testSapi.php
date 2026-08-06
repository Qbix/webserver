<?php
/**
 * Q_Sapi tests — SAPI emulation, shutdown ordering, sessions, flushing.
 * Run: php tests/testSapi.php
 */
require_once dirname(__DIR__) . '/src/Q.php';

$pass = 0; $fail = 0;
function ok($cond, $msg) {
	global $pass, $fail;
	if ($cond) { ++$pass; echo "  PASS $msg\n"; }
	else { ++$fail; echo "  FAIL $msg\n"; }
}
function req($over = array()) {
	return array_merge(array(
		'method' => 'GET', 'path' => '/index.php', 'query' => '',
		'headers' => array('host' => '127.0.0.1:8080'), 'body' => ''
	), $over);
}

echo "== superglobals ==\n";
Q_Sapi::enter(req(array(
	'method' => 'POST', 'query' => 'a=1&b=2', 'body' => 'c=3',
	'headers' => array('host' => 'example.com:9000', 'cookie' => 'sid=abc; x=y',
		'content-type' => 'application/x-www-form-urlencoded')
)));
echo "hello";
$g = $_GET; $p = $_POST; $c = $_COOKIE; $sv = $_SERVER;
list($status, $headers, $body) = Q_Sapi::leave();
ok($g === array('a'=>'1','b'=>'2'), '$_GET parsed from query string');
ok($p === array('c'=>'3'), '$_POST parsed from body');
ok($c['sid'] === 'abc' && $c['x'] === 'y', '$_COOKIE parsed from Cookie header');
ok($sv['REQUEST_METHOD'] === 'POST', 'REQUEST_METHOD set');
ok($sv['HTTP_HOST'] === 'example.com:9000', 'HTTP_HOST set (session cookie depends on it)');
ok($sv['REQUEST_URI'] === '/index.php?a=1&b=2', 'REQUEST_URI includes query');
ok($body === 'hello', 'output captured');
ok($status === 200, 'default status 200');

echo "== JSON body ==\n";
Q_Sapi::enter(req(array('method'=>'POST','body'=>'{"k":"v"}',
	'headers'=>array('host'=>'h','content-type'=>'application/json'))));
$p2 = $_POST; Q_Sapi::leave();
ok($p2 === array('k'=>'v'), 'JSON body decoded into $_POST');

echo "== headers and status ==\n";
Q_Response::clear();
Q_Sapi::enter(req());
Q_Response::header('X-Test: hi');
Q_Response::responseCode(418);
list($st, $hd, $bd) = Q_Sapi::leave();
ok($st === 418, 'status code captured');
ok(isset($hd['X-Test']) && $hd['X-Test'] === 'hi', 'header captured');

echo "== cookies reach the response ==\n";
Q_Response::clear();
Q_Sapi::enter(req());
Q_Response::setCookie('sess', 'v1', 0, '/');
list($st3, $hd3, $bd3) = Q_Sapi::leave();
ok(isset($hd3['Set-Cookie']) && strpos($hd3['Set-Cookie'], 'sess=v1') !== false,
   'Set-Cookie emitted (regression: silently dropped without HTTP_HOST)');

echo "== shutdown function output is captured ==\n";
// A shutdown callback registered by app code must still land in the body.
// The finalizer is a destructor, so it runs AFTER all such callbacks.
$code = '<?php register_shutdown_function(function(){ echo "FROM_SHUTDOWN"; }); echo "MAIN";';
$tmp = tempnam(sys_get_temp_dir(), 'sapi') . '.php';
file_put_contents($tmp, $code);
$out = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg(
	'require ' . var_export(dirname(__DIR__).'/src/Q.php', true) . ';'
	. 'Q_Sapi::enter(array("method"=>"GET","path"=>"/i.php","query"=>"","headers"=>array("host"=>"h"),"body"=>""));'
	. 'include ' . var_export($tmp, true) . ';'
) . ' 2>/dev/null');
ok(strpos($out, 'MAIN') !== false, 'main output present via finalizer');
ok(strpos($out, 'FROM_SHUTDOWN') !== false,
   'register_shutdown_function output captured (destructor runs last)');
@unlink($tmp);

echo "== exit() mid-script still captures ==\n";
$out2 = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg(
	'require ' . var_export(dirname(__DIR__).'/src/Q.php', true) . ';'
	. 'Q_Sapi::enter(array("method"=>"GET","path"=>"/i.php","query"=>"","headers"=>array("host"=>"h"),"body"=>""));'
	. 'echo "BEFORE_EXIT"; exit(0);'
) . ' 2>/dev/null');
ok(strpos($out2, 'BEFORE_EXIT') !== false, 'output captured even when script calls exit()');

echo "== capture is idempotent ==\n";
Q_Response::clear();
Q_Sapi::enter(req());
echo "once";
$a = Q_Sapi::capture();
$b = Q_Sapi::capture();
ok($a === $b, 'capture() is idempotent (finalizer after leave() is safe)');
Q_Sapi::leave();

echo "== no state leaks between requests ==\n";
// The regression test for the whole class of bugs: request 2 must not
// inherit request 1's cookies, headers, or parsed URI.
Q_Response::clear();
Q_Sapi::enter(req(array('query'=>'first=1')));
Q_Response::setCookie('leak', 'REQ1', 0, '/');
Q_Response::header('X-Leak: REQ1');
Q_Sapi::leave();
Q_Response::clear();
Q_Sapi::enter(req(array('query'=>'second=2')));
$g2 = $_GET;
list($st4, $hd4, $bd4) = Q_Sapi::leave();
ok($g2 === array('second'=>'2'), 'request 2 has its own $_GET');
ok(!isset($hd4['X-Leak']), 'request 2 does not inherit request 1 headers');
ok(empty($hd4['Set-Cookie']), 'request 2 does not inherit request 1 cookies');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
