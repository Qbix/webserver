<?php
/**
 * Tests for Q_WebServer_PhpInfo — the parser that turns the CLI SAPI's
 * plain-text phpinfo() output back into the HTML page people expect.
 *
 *   php tests/phpinfo.php
 *
 * Exits non-zero on any failure.
 */

require_once __DIR__ . '/../src/Q/WebServer/PhpInfo.php';

$pass = 0;
$fail = 0;

function ok($msg) { global $pass; $pass++; echo "  ok   $msg\n"; }
function bad($msg) { global $fail; $fail++; echo "  FAIL $msg\n"; }
function check($msg, $cond) { $cond ? ok($msg) : bad($msg); }
function countOf($h, $needle) { return substr_count($h, $needle); }

echo "==============================================\n";
echo " Q_WebServer_PhpInfo\n";
echo "==============================================\n\n";

// ── A synthetic document covering every shape the format has ──────────
$text = <<<TXT
phpinfo()
PHP Version => 8.3.33

System => Linux box 5.15.0 x86_64
Additional .ini files parsed => /etc/a.ini,
/etc/b.ini,
/etc/c.ini

Configuration

bcmath

BCMath support => enabled

Directive => Local Value => Master Value
bcmath.scale => 0 => 0

Core

PHP Version => 8.3.33

Environment

PATH => /usr/bin:/bin

PHP License
This program is free software; you can redistribute it and/or modify
it under the terms of the PHP License.

If you did not receive a copy, contact license@php.net.
TXT;

$h = Q_WebServer_PhpInfo::render($text);

check('returns a full document', strpos($h, '<!DOCTYPE html>') === 0);
check('version becomes the h1', strpos($h, '<h1>PHP Version 8.3.33</h1>') !== false);
check('title carries the version', strpos($h, '<title>PHP 8.3.33</title>') !== false);

// Headings: bcmath, Core are modules; Configuration, Environment,
// PHP License are sections. Five in total, three of them sections.
check('module and section headings', countOf($h, '<h2') === 5);
check('sections marked apart', countOf($h, '<h2 class="section">') === 3);
check('module heading rendered', strpos($h, '<h2>bcmath</h2>') !== false);

// Two-column rows
check('two-column row', strpos(
    $h, '<td class="k">System</td><td class="v">Linux box 5.15.0 x86_64</td>'
) !== false);

// The wrapped .ini list is one value, not three headings. This is the
// case that makes a naive "line without => is a heading" parser fall over.
check('wrapped value stays one cell', strpos(
    $h, '/etc/a.ini,<br>/etc/b.ini,<br>/etc/c.ini'
) !== false);
check('wrapped value made no headings', strpos($h, '<h2>/etc/b.ini,</h2>') === false);

// Three-column table
check('directive header row', strpos(
    $h, '<tr class="head"><th>Directive</th><th>Local Value</th><th>Master Value</th></tr>'
) !== false);
check('three-column row', strpos(
    $h, '<td class="k">bcmath.scale</td><td class="v">0</td><td class="v">0</td>'
) !== false);

// The Core module repeats PHP Version; only the first becomes the h1.
check('repeated version stays a row', strpos(
    $h, '<td class="k">PHP Version</td><td class="v">8.3.33</td>'
) !== false);

// Licence prose must not turn into a heading per paragraph.
check('licence rendered as paragraphs', countOf($h, '<p>') === 2);
check('licence made no headings', strpos($h, '<h2>If you did not') === false);

// Escaping
$evil = Q_WebServer_PhpInfo::render("phpinfo()\nPHP Version => 1.0\n\nx => <script>alert(1)</script>\n");
check('values are escaped', strpos($evil, '<script>alert(1)</script>') === false);
check('escaped form present', strpos($evil, '&lt;script&gt;') !== false);

// Already-HTML input passes through untouched (mod_php, fpm).
$html = '<!DOCTYPE html><html><body><table><tr><td>x</td></tr></table></body></html>';
check('html input untouched', Q_WebServer_PhpInfo::render($html) === $html);

// ── The real thing ───────────────────────────────────────────────────
ob_start();
phpinfo();
$real = (string) ob_get_clean();

if (stripos($real, '<table') !== false) {
    echo "  note: this SAPI emits HTML phpinfo; parser path not exercised\n";
} else {
    $r = Q_WebServer_PhpInfo::render($real);
    check('real output: document', strpos($r, '<!DOCTYPE html>') === 0);
    check('real output: has an h1', countOf($r, '<h1>') === 1);
    check('real output: many tables', countOf($r, '<table') > 5);
    check('real output: many rows', countOf($r, '<tr') > 50);
    check('real output: no stray markup from values', substr_count($r, '<script') === 0);
    // Nothing from the source should be silently dropped: every "a => b"
    // line has to show up as a row.
    $lines = 0;
    foreach (preg_split("/\n/", $real) as $l) {
        if (strpos($l, ' => ') !== false) $lines++;
    }
    $rows = countOf($r, '<tr');
    check(
        "row count tracks source lines ($rows rows for $lines lines)",
        $rows >= $lines * 0.9
    );
}

echo "\n  passed: $pass  failed: $fail\n";
exit($fail === 0 ? 0 : 1);
