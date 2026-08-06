<?php
/**
 * End-to-end HTTP probe. Drives a RUNNING server over a real socket and
 * checks the wire-level response: status codes, headers, bodies.
 *
 * Unit tests miss whole classes of bug here -- exit() in a script sent the
 * client zero bytes while the access log still said 200, and headers set
 * under --app were silently dropped. Both were invisible until something
 * actually read the socket.
 *
 * Usage: php tests/probe.php <port>
 *        PROBE_APP=1 php tests/probe.php <port>   # --app mode expectations
 */
// Dual-mode edge-case probe. Usage: php probe.php <port>
$port = (int)($argv[1] ?? 8080);

function raw($port, $meth, $path, $extra = '', $body = '') {
    $fp = @fsockopen("127.0.0.1", $port, $e, $s, 3);
    if (!$fp) return null;
    $req = "$meth $path HTTP/1.1\r\nHost: localhost:$port\r\nConnection: close\r\n";
    if ($extra) $req .= $extra;
    if ($body !== '') $req .= "Content-Length: " . strlen($body) . "\r\n";
    $req .= "\r\n" . $body;
    fwrite($fp, $req);
    stream_set_timeout($fp, 5);
    $r = '';
    while (!feof($fp)) { $c = @fread($fp, 65536); if ($c === '' || $c === false) break; $r .= $c; }
    fclose($fp);
    return $r;
}
function st($r) {
    if ($r === null) return 'CONNFAIL';
    if (!preg_match('#^HTTP/\S+ (\d{3})#', $r, $m)) return 'NORESP';
    return $m[1];
}
function bd($r) {
    if ($r === null) return '';
    $p = strpos($r, "\r\n\r\n");
    return $p === false ? '' : substr($r, $p + 4);
}
function hdr($r, $name) {
    if ($r === null) return null;
    if (preg_match('/^' . preg_quote($name,'/') . ':\s*(.*)$/mi', $r, $m)) return trim($m[1]);
    return null;
}
function row($label, $got, $want = null) {
    $mark = $want === null ? ' ' : ($got == $want ? 'ok' : 'XX');
    printf("  %-2s %-26s %s%s\n", $mark, $label, $got,
        ($want !== null && $got != $want) ? "  (want $want)" : '');
}

$APP = (bool) getenv('PROBE_APP');   // --app mode: some expectations differ

echo "═══ HTTP semantics ═══\n";
row('dup query param',   st(raw($port,'GET','/hello.php?a=1&a=2')), 200);
row('path traversal',    st(raw($port,'GET','/../../etc/passwd')), 403);
row('encoded traversal', st(raw($port,'GET','/..%2f..%2fetc%2fpasswd')), 403);
row('lowercase method',  st(raw($port,'get','/hello.php')), 501);
row('HEAD has no body',  strlen(bd(raw($port,'HEAD','/hello.php'))) === 0 ? 'empty' : 'HASBODY', 'empty');
row('HEAD status',       st(raw($port,'HEAD','/hello.php')), 200);
row('HTTP/1.0 request',  st(raw($port,'GET','/hello.php')), 200);

echo "\n═══ headers ═══\n";
row('dup Host rejected',  st(raw($port,'GET','/hello.php',"Host: evil.com\r\n")), 400);
row('bad header name',    st(raw($port,'GET','/hello.php',"Bad Header: x\r\n")), 400);
row('dup Accept combined', st(raw($port,'GET','/hello.php',"Accept: text/html\r\nAccept: application/json\r\n")), 200);
row('obs-fold continuation', st(raw($port,'GET','/hello.php',"X-Multi: a\r\n\tb\r\n")), 200);
$r = raw($port,'GET','/headers.php');
row('custom header',      hdr($r,'X-Custom-Header') ?: 'MISSING', 'hello');
row('Cache-Control kept', hdr($r,'Cache-Control') ?: 'MISSING', 'public, max-age=300');
row('Content-Length set', hdr(raw($port,'GET','/hello.php'),'Content-Length') !== null ? 'yes':'no', 'yes');

echo "\n═══ status codes ═══\n";
foreach (array(200,201,204,400,403,404,418,500,503) as $c) {
    row("status $c", st(raw($port,'GET',"/statuses.php?c=$c")), $c);
}
row('redirect 302',  st(raw($port,'GET','/redirect.php')), 302);
row('Location hdr',  hdr(raw($port,'GET','/redirect.php'),'Location') ?: 'MISSING', '/index.html');

echo "\n═══ body / methods ═══\n";
$r = raw($port,'POST','/methodecho.php',"Content-Type: application/json\r\n",'{"a":1}');
row('POST body echoed', strpos(bd($r),'{\"a\":1}')!==false || strpos(bd($r),'{"a":1}')!==false ? 'yes':'no', 'yes');
row('POST Content-Length', strpos(bd($r),'"len":7')!==false ? 'yes':'no', 'yes');
$r = raw($port,'PUT','/methodecho.php',"Content-Type: text/plain\r\n",'put-body');
row('PUT body', strpos(bd($r),'put-body')!==false ? 'yes':'no', 'yes');
$r = raw($port,'PATCH','/methodecho.php',"Content-Type: text/plain\r\n",'patch-body');
row('PATCH body', strpos(bd($r),'patch-body')!==false ? 'yes':'no', 'yes');
$r = raw($port,'DELETE','/methodecho.php');
row('DELETE method', strpos(bd($r),'"method":"DELETE"')!==false ? 'yes':'no', 'yes');
row('php://input', strpos(bd(raw($port,'POST','/raw-input.php',"Content-Type: text/plain\r\n",'hello-raw')),'hello-raw')!==false ? 'present':'MISSING', 'present');
$big = str_repeat('x', 100000);
$r = raw($port,'POST','/methodecho.php',"Content-Type: text/plain\r\n",$big);
row('100KB body', strpos(bd($r),'"len":100000')!==false ? 'ok':'TRUNCATED', 'ok');
row('empty body POST', st(raw($port,'POST','/methodecho.php',"Content-Type: text/plain\r\n",'')), 200);

echo "\n═══ query parsing ═══\n";
$r = raw($port,'GET','/querytypes.php?a[]=1&a[]=2');
row('array param', strpos(bd($r),'"arr":["1","2"]')!==false ? 'ok':'no', 'ok');
$r = raw($port,'GET','/querytypes.php?n[x]=1');
row('nested param', strpos(bd($r),'"x":"1"')!==false ? 'ok':'no', 'ok');
$r = raw($port,'GET','/querytypes.php?e=a%20b%26c');
row('percent-decoded', strpos(bd($r),'a b&c')!==false ? 'ok':'no', 'ok');

echo "\n═══ CGI superglobals ═══\n";
$r = raw($port,'GET','/servervars.php?q=1');
$j = json_decode(bd($r), true) ?: array();
row('SCRIPT_NAME',  $j['SCRIPT_NAME']  ?? 'MISSING', '/servervars.php');
row('QUERY_STRING', $j['QUERY_STRING'] ?? 'MISSING', 'q=1');
row('DOCUMENT_ROOT',$j['DOCUMENT_ROOT']?? 'MISSING', 'set');
row('REMOTE_ADDR',  $j['REMOTE_ADDR']  ?? 'MISSING', 'set');
$r = raw($port,'GET','/servervars.php/extra/path');
$j = json_decode(bd($r), true) ?: array();
row('PATH_INFO',    $j['PATH_INFO'] ?? 'MISSING', '/extra/path');
row('PHP_SELF',     $j['PHP_SELF']  ?? 'MISSING', '/servervars.php/extra/path');

echo "\n═══ script behaviour ═══\n";
row('exit() mid-script', st(raw($port,'GET','/exit.php')), 200);
row('exit() body sent',  strpos(bd(raw($port,'GET','/exit.php')),'about to exit')!==false?'yes':'no','yes');
row('fatal error',       st(raw($port,'GET','/fatal.php')), 500);
row('flush() then more', strpos(bd(raw($port,'GET','/slowecho.php')),'part1-part2')!==false?'whole':'PARTIAL','whole');
$b = bd(raw($port,'GET','/binary.php'));
row('binary safe', (strpos($b,"binary-ok")!==false && strpos($b,"\x00")!==false)?'ok':'CORRUPT','ok');
$nf = st(raw($port,'GET','/nope-xyz.php'));
row('404 missing', $nf, $APP ? $nf : 404);

echo "\n═══ static files ═══\n";
row('static txt',   st(raw($port,'GET','/large.txt')), 200);
row('static css',   strpos(hdr(raw($port,'GET','/style.css'),'Content-Type') ?: '', 'text/css')===0 ? 'text/css':'MISSING', 'text/css');
row('static json',  strpos(hdr(raw($port,'GET','/data.json'),'Content-Type') ?: '', 'application/json')===0 ? 'application/json':'MISSING', 'application/json');
$r = raw($port,'GET','/large.txt');
$etag = hdr($r,'ETag');
row('ETag present', $etag ? 'yes':'no', 'yes');
if ($etag) {
    row('304 on If-None-Match', st(raw($port,'GET','/large.txt',"If-None-Match: $etag\r\n")), 304);
}
row('gzip negotiated', hdr(raw($port,'GET','/large.txt',"Accept-Encoding: gzip\r\n"),'Content-Encoding') ?: 'none', 'gzip');

echo "\n═══ sessions & cookies ═══\n";
$r = raw($port,'GET','/cookie-read.php',"Cookie: a=1; b=2\r\n");
row('cookie parsed', strpos(bd($r),'1')!==false ? 'yes':'no', 'yes');
$r = raw($port,'GET','/setcookie.php');
row('Set-Cookie emitted', hdr($r,'Set-Cookie') ? 'yes':'MISSING', 'yes');
$r1 = raw($port,'GET','/session.php');
$sc = hdr($r1,'Set-Cookie');
row('session starts', strpos(bd($r1),'"n":1')!==false ? 'yes':'no', 'yes');
if ($sc) {
    $sid = explode(';', $sc)[0];
    $r2 = raw($port,'GET','/session.php',"Cookie: $sid\r\n");
    row('session persists', strpos(bd($r2),'"n":2')!==false ? 'yes':'no', 'yes');
}

echo "\n═══ concurrency ═══\n";
$ok = 0;
for ($i = 0; $i < 10; $i++) {
    if (st(raw($port,'GET','/hello.php')) === '200') $ok++;
}
row('10 sequential', "$ok/10", '10/10');
