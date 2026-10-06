<?php
/**
 * Source-transform regression: shimmed calls inside namespaced files.
 *
 * Symfony, Laravel and Drupal call header(), headers_sent(), session_start()
 * etc. from inside `namespace ...;` blocks. The transform must emit a fully
 * qualified \Q_WebServer_Compat::_x() call, otherwise PHP resolves the shim
 * relative to the file's namespace and the request fatals.
 *
 * Run: php tests/test_compat_namespace.php
 */
require_once dirname(__DIR__) . '/src/Q.php';
require_once dirname(__DIR__) . '/src/Q/WebServer/Compat.php';

$pass = 0; $fail = 0;
function ok($cond, $msg) {
	global $pass, $fail;
	if ($cond) { ++$pass; echo "  PASS $msg\n"; }
	else { ++$fail; echo "  FAIL $msg\n"; }
}

$src = <<<'PHP'
<?php
namespace Vendor\Http;
class Response {
	function send() {
		if (headers_sent()) return 'sent';
		header('X-Unqualified: 1');
		\header('X-Qualified: 2');
		http_response_code(201);
		return 'ok';
	}
	function header($x) { return 'method'; }
}
PHP;

$out = Q_WebServer_Compat::transformSource($src);
ok(strpos($out, '\Q_WebServer_Compat::_header(\'X-Unqualified') !== false, 'unqualified header() rewritten to fully qualified shim');
ok(strpos($out, '\Q_WebServer_Compat::_header(\'X-Qualified') !== false, '\\header() rewritten to fully qualified shim');
ok(strpos($out, '\Q_WebServer_Compat::_headers_sent()') !== false, 'headers_sent() rewritten');
ok(strpos($out, 'function header($x)') !== false, 'method definition left alone');
ok(substr_count($out, 'Q_WebServer_Compat::') === 4, 'exactly four calls rewritten');

$tmp = tempnam(sys_get_temp_dir(), 'qns') . '.php';
file_put_contents($tmp, $out);
Q_WebServer_State::clear();
try {
	require $tmp;
	$r = (new \Vendor\Http\Response)->send();
	ok($r === 'ok', 'namespaced code runs without "class not found"');
	$h = Q_WebServer_State::getHeaders();
	$flat = strtolower(json_encode($h));
	ok(strpos($flat, 'x-unqualified') !== false, 'header from unqualified call captured');
	ok(strpos($flat, 'x-qualified') !== false, 'header from \\header() call captured');
	ok(Q_Response::code() === 201, 'http_response_code() captured');
} catch (\Throwable $e) {
	ok(false, 'namespaced code runs: ' . get_class($e) . ': ' . $e->getMessage());
}
@unlink($tmp);

$sessionDir = sys_get_temp_dir() . '/qcompat-' . bin2hex(random_bytes(8));
mkdir($sessionDir);
$sessionFile = $sessionDir . '/session.php';
$sessionSource = '<?php session_start(["save_path" => '
	. var_export($sessionDir, true)
	. ']); $_SESSION["n"] = (isset($_SESSION["n"]) ? $_SESSION["n"] : 0) + 1;'
	. ' echo $_SESSION["n"];';
file_put_contents($sessionFile, $sessionSource);
Q_WebServer_Compat::init();
try {
	$stream = new Q_WebServer_CompatFileWrapper;
	$openedPath = '';
	$stream->stream_open($sessionFile, 'r', STREAM_REPORT_ERRORS, $openedPath);
	$stat = $stream->stream_stat();
	$stream->stream_close();
	$expectedSize = strlen(Q_WebServer_Compat::transformSource($sessionSource));
	ok(is_array($stat)
		&& count($stat) >= 26
		&& $stat[7] === $expectedSize
		&& $stat['size'] === $expectedSize,
		'transformed stream returns complete stat metadata with transformed size');

	ob_start();
	include $sessionFile;
	$sessionOutput = ob_get_clean();
	ok($sessionOutput === '1', 'transformed session script with ternary expression executes');
	Q_WebServer_Compat::finishRequest();
} catch (\Throwable $e) {
	ok(false, 'transformed session stream executes: ' . get_class($e) . ': ' . $e->getMessage());
}
Q_WebServer_Compat::shutdown();
@unlink($sessionFile);
foreach (glob($sessionDir . '/sess_*') ?: array() as $path) {
	@unlink($path);
}
@rmdir($sessionDir);

echo "\n  $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
