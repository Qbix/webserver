<?php
/**
 * Tests for Q_WebServer_PhpInfo — the parser that turns the CLI SAPI's
 * plain-text phpinfo() output back into the page phpinfo() renders under
 * mod_php and fpm.
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
function has($h, $needle) { return strpos($h, $needle) !== false; }
function countOf($h, $needle) { return substr_count($h, $needle); }

echo "==============================================\n";
echo " Q_WebServer_PhpInfo\n";
echo "==============================================\n\n";

// A synthetic document covering every shape the text format has. The
// leading spaces on the credits titles and the underscore rule are
// significant — phpinfo centres the two-column credit blocks and draws
// its section rule that way.
$text = "phpinfo()\n"
. "PHP Version => 8.3.33\n"
. "\n"
. "System => Linux box 5.15.0 x86_64\n"
. "Additional .ini files parsed => /etc/a.ini,\n"
. "/etc/b.ini,\n"
. "/etc/c.ini\n"
. "\n"
. " ___________________________________________________________\n"
. "\n"
. "Configuration\n"
. "\n"
. "bcmath\n"
. "\n"
. "BCMath support => enabled\n"
. "\n"
. "Directive => Local Value => Master Value\n"
. "bcmath.scale => 0 => no value\n"
. "\n"
. "mbstring\n"
. "\n"
. "Multibyte support => enabled\n"
. "\n"
. "mbstring extension makes use of \"streamable kanji code filter\", which is distributed under the LGPL 2.1.\n"
. "\n"
. "Multibyte regex support => enabled\n"
. "\n"
. "Phar\n"
. "\n"
. "Phar API version => 1.1.1\n"
. "\n"
. "\n"
. "Phar based on pear/PHP_Archive, original concept by Davey Shafik.\n"
. "Phar fully realized by Gregory Beaver and Marcus Boerger.\n"
. "Directive => Local Value => Master Value\n"
. "phar.readonly => On => On\n"
. "\n"
. "Additional Modules\n"
. "\n"
. "Module Name\n"
. "\n"
. "Environment\n"
. "\n"
. "Variable => Value\n"
. "PATH => /usr/bin:/bin\n"
. "\n"
. "PHP Variables\n"
. "\n"
. "Variable => Value\n"
. "\$_SERVER['PATH'] => /usr/bin:/bin\n"
. "\n"
. " ___________________________________________________________\n"
. "\n"
. "PHP Credits\n"
. "\n"
. "PHP Group\n"
. "Thies C. Arntzen, Stig Bakken\n"
. "\n"
. "                          PHP Authors                          \n"
. "Contribution => Authors\n"
. "Extension Module API => Andi Gutmans\n"
. "\n"
. "PHP License\n"
. "This program is free software; you can redistribute it\n"
. "under the terms of the PHP License.\n"
. "\n"
. "If you did not receive a copy, contact license@php.net.\n";

$h = Q_WebServer_PhpInfo::render($text);

// ── Document shell, as phpinfo builds it ─────────────────────────────
check('xhtml doctype', strpos($h, '<!DOCTYPE html PUBLIC') === 0);
check('centred wrapper', has($h, '<body><div class="center">'));
check('version heading', has($h, '<h1 class="p">PHP Version 8.3.33</h1>'));
check('heading sits in a .h row', has($h, '<tr class="h"><td>'));
check('title like phpinfo', has($h, '<title>PHP 8.3.33 - phpinfo()</title>'));
check('robots meta', has($h, 'NOINDEX,NOFOLLOW,NOARCHIVE'));

// ── Rows ─────────────────────────────────────────────────────────────
check('two-column row', has(
    $h, '<td class="e">System </td><td class="v">Linux box 5.15.0 x86_64 </td>'
));
check('directive header', has(
    $h, '<tr class="h"><th>Directive</th><th>Local Value</th><th>Master Value</th></tr>'
));
check('three-column row is tight', has(
    $h, '<td class="e">bcmath.scale</td><td class="v">0</td>'
));
check('no value italicised', has($h, '<i>no value</i>'));

// The wrapped .ini list is one value, not three headings. This is the case
// that makes a naive "line without => is a heading" parser fall over.
check('wrapped value stays one cell', has(
    $h, "/etc/a.ini,\n/etc/b.ini,\n/etc/c.ini </td>"
));

// ── Headings ─────────────────────────────────────────────────────────
check('underscore rule becomes hr', countOf($h, '<hr />') === 2);
check('Configuration is an h1', has($h, '<h1>Configuration</h1>'));
check('module heading anchored', has(
    $h, '<h2><a name="module_bcmath" href="#module_bcmath">bcmath</a></h2>'
));
check('sections are plain h2', has($h, '<h2>Environment</h2>'));

// A module notice is a sentence, not an extension name: phpinfo gives it a
// header row. Taken for a heading it yields an anchor holding the sentence.
check('module notice is a header row', has(
    $h, '<tr class="h"><th>mbstring extension makes use of'
));
check('no sentence-long anchors',
    preg_match('/<a name="module_[^"]{40,}"/', $h) === 0);

// Free-standing prose inside a module runs into the next line.
check('module prose is a .v block', has(
    $h,
    '<tr class="v"><td>' . "\n"
    . 'Phar based on pear/PHP_Archive, original concept by Davey Shafik.'
    . '<br />Phar fully realized by Gregory Beaver and Marcus Boerger.'
));
check('prose made no heading', !has($h, 'name="module_Phar based'));

// ── Section column headers, which the text carries itself ────────────
check('Module Name header row', has($h, '<tr class="h"><th>Module Name</th></tr>'));
check('Variable/Value header row', has(
    $h, '<tr class="h"><th>Variable</th><th>Value</th></tr>'
));
check('header row not duplicated as data',
    !has($h, '<td class="e">Variable </td><td class="v">Value </td>'));

// ── Credits ──────────────────────────────────────────────────────────
check('credits is an h1', has($h, '<h1>PHP Credits</h1>'));
check('credit block title', has($h, '<tr class="h"><th>PHP Group</th></tr>'));
check('credit names row', has(
    $h, '<tr><td class="e">Thies C. Arntzen, Stig Bakken </td></tr>'
));
check('centred credit title spans two columns', has(
    $h, '<th colspan="2">PHP Authors</th>'
));
check('credits contribution header', has(
    $h, '<tr class="h"><th>Contribution</th><th>Authors</th></tr>'
));

// ── Licence ──────────────────────────────────────────────────────────
check('licence in a .v row', has($h, '<tr class="v"><td>'));
check('licence paragraphs', countOf($h, '<p>') === 2);
check('licence made no headings', !has($h, '<h2>If you did not'));

// ── Escaping and passthrough ─────────────────────────────────────────
$evil = Q_WebServer_PhpInfo::render(
    "phpinfo()\nPHP Version => 1.0\n\nx => <script>alert(1)</script>\n"
);
check('values escaped', !has($evil, '<script>alert(1)</script>'));
check('escaped form present', has($evil, '&lt;script&gt;'));

$html = '<!DOCTYPE html><html><body><table><tr><td>x</td></tr></table></body></html>';
check('html input untouched', Q_WebServer_PhpInfo::render($html) === $html);

// ── Against this build's own phpinfo() ───────────────────────────────
ob_start();
phpinfo();
$real = (string) ob_get_clean();

if (stripos($real, '<table') !== false) {
    echo "  note: this SAPI emits HTML phpinfo; parser path not exercised\n";
} else {
    $r = Q_WebServer_PhpInfo::render($real);
    check('real: document', strpos($r, '<!DOCTYPE html PUBLIC') === 0);
    check('real: one version heading', countOf($r, '<h1 class="p">') === 1);
    check('real: many tables', countOf($r, '<table') > 20);
    check('real: module anchors', countOf($r, '<h2><a name="module_') > 10);
    check('real: no sentence-long anchors',
        preg_match('/<a name="module_[^"]{40,}"/', $r) === 0);
    check('real: nothing unescaped', countOf($r, '<script') === 0);
    // Nothing from the source should be silently dropped: every "a => b"
    // line has to show up as a row.
    $lines = 0;
    foreach (preg_split("/\n/", $real) as $l) {
        if (strpos($l, ' => ') !== false) $lines++;
    }
    $rows = countOf($r, '<tr');
    check(
        "real: row count tracks source lines ($rows rows for $lines lines)",
        $rows >= $lines * 0.9
    );
}

echo "\n  passed: $pass  failed: $fail\n";
exit($fail === 0 ? 0 : 1);
