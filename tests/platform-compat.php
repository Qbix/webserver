#!/usr/bin/env php
<?php
/**
 * Platform compatibility audit.
 *
 * The webserver is a SUBSET that the Platform must be able to override. In
 * --app mode the Platform's classes win for every name it defines, so any
 * member the webserver touches on a shared class must exist in the Platform's
 * version too. Otherwise the call works standalone and dies under a real app —
 * which is exactly what `Q::$paths` did (undeclared in the Platform's Q.php,
 * so every request became a 500).
 *
 * Rule: anything the Platform does not define belongs on a webserver-only
 * class (Q_WebServer, Q_WebSocket, Q_Scheduler, Q_FileCache, Q_HotReload),
 * never on a shared one.
 *
 * Usage: php tests/platform-compat.php [/path/to/platform]
 */

$wsRoot = dirname(__DIR__);
$platform = $argv[1] ?? getenv('QBIX_PLATFORM_DIR') ?: '/tmp/qbix/Platform/platform';

if (!is_dir($platform . '/classes')) {
    fwrite(STDERR, "SKIP: no Platform at $platform (pass a path or set QBIX_PLATFORM_DIR)\n");
    exit(0);
}

/**
 * Map class name => file for a classes/ tree.
 *
 * Parses actual `class X` declarations rather than trusting the path. One file
 * can declare several classes — src/Q.php declares Q, Q_Response AND Q_Request —
 * and a path-only map silently misfiled those as "Platform-only", which is how
 * Q_Request::setInput() escaped an earlier version of this audit.
 */
function classMap($root, $sub) {
    $map = array();
    $dir = $root . '/' . $sub;
    if (!is_dir($dir)) return $map;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        $rel = substr($f->getPathname(), strlen($dir) + 1, -4);
        $map[str_replace(DIRECTORY_SEPARATOR, '_', $rel)] = $f->getPathname();
        $src = file_get_contents($f->getPathname());
        if (preg_match_all('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $src, $m)) {
            foreach ($m[1] as $cls) {
                if (!isset($map[$cls])) $map[$cls] = $f->getPathname();
            }
        }
    }
    return $map;
}

$platformClasses = classMap($platform, 'classes');
$wsClasses       = classMap($wsRoot, 'src');

// Two categories, both governed by the same rule.
//  SHARED   — we define it AND the Platform defines it. The Platform wins at
//             runtime, so we may only use members IT declares.
//  PLATFORM — the Platform defines it and we do NOT. Every use is the
//             Platform's class, so again only its members exist.
// Missing the second category is how `Q_Request::setInput()` slipped through:
// Q_Request is Platform-only, so a shared-classes-only audit never looked at it.
$shared     = array_intersect(array_keys($wsClasses), array_keys($platformClasses));
$platformOnly = array_diff(array_keys($platformClasses), array_keys($wsClasses));
$governed   = array_merge($shared, $platformOnly);
sort($shared); sort($governed);

/** Members declared by a PHP source file. */
function declaredMembers($file) {
    $src = file_get_contents($file);
    $out = array('methods' => array(), 'props' => array(), 'consts' => array());
    if (preg_match_all('/function\s+&?([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $src, $m)) {
        $out['methods'] = array_map('strtolower', $m[1]);
    }
    if (preg_match_all('/(?:public|protected|private|static)[\s\w]*?\$([A-Za-z_][A-Za-z0-9_]*)\s*(?:=|;)/', $src, $m)) {
        $out['props'] = $m[1];
    }
    if (preg_match_all('/const\s+([A-Za-z_][A-Za-z0-9_]*)/', $src, $m)) {
        $out['consts'] = $m[1];
    }
    return $out;
}

$platformMembers = array();
foreach ($governed as $c) {
    $platformMembers[$c] = declaredMembers($platformClasses[$c]);
}
$sharedSet = array_flip($governed);

$violations = array();
$checked = 0;

/**
 * Scan one file using PHP's own tokenizer.
 *
 * An earlier version stripped comments with /\*.*?\*\/ and then regex-matched.
 * That is unsafe: a `/*` sequence inside a string literal makes the match run to
 * the next `*\/` anywhere later in the file. On src/Q/WebServer.php it silently
 * deleted 22KB of real code — including the Q_Uri::fromPath() call — so the audit
 * reported PASS on a class that fatals in --app mode. Tokenizing is exact.
 */
function scanFile($file, $sharedSet, $platformMembers, &$violations, &$checked, $rel) {
    $tokens = token_get_all(file_get_contents($file));
    $n = count($tokens);
    // Build a comment-free, string-free stream of (type, text) we care about.
    $stream = array();
    foreach ($tokens as $t) {
        if (is_array($t)) {
            if (in_array($t[0], array(T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML,
                T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE), true)) {
                continue;
            }
            if ($t[0] === T_WHITESPACE) continue;
            $stream[] = array($t[0], $t[1], $t[2]);
        } else {
            $stream[] = array(null, $t, 0);
        }
    }
    $m = count($stream);
    for ($i = 0; $i < $m - 2; ++$i) {
        if ($stream[$i][0] !== T_STRING) continue;
        if ($stream[$i+1][0] !== T_DOUBLE_COLON) continue;
        $cls = $stream[$i][1];
        if (!isset($sharedSet[$cls])) continue;

        // Guarded uses degrade cleanly; skip if guarded on the same line.
        $line = $stream[$i][2];
        $guarded = false;
        for ($j = max(0, $i - 14); $j < min($m, $i + 4); ++$j) {
            if ($stream[$j][0] === T_STRING
            && in_array($stream[$j][1], array('method_exists', 'property_exists'), true)
            && abs($stream[$j][2] - $line) <= 1) { $guarded = true; break; }
        }
        if ($guarded) continue;

        $next = $stream[$i+2];
        if ($next[0] === T_VARIABLE) {              // Cls::$prop
            $prop = ltrim($next[1], '$');
            ++$checked;
            if (!in_array($prop, $platformMembers[$cls]['props'], true)) {
                $violations[] = array($rel, "$cls::\$$prop", 'property', $line);
            }
        } else if ($next[0] === T_STRING
        && isset($stream[$i+3]) && $stream[$i+3][1] === '(') {   // Cls::method(
            $meth = $next[1];
            ++$checked;
            if (!in_array(strtolower($meth), $platformMembers[$cls]['methods'], true)) {
                $violations[] = array($rel, "$cls::$meth()", 'method', $line);
            }
        }
    }
}

// Scan src/ AND the test fixtures. tests/web/*.php are executed by the server
// in BOTH modes, so a fixture calling a shim-only method (Q::header(),
// Q_Response::header()) passes standalone and 500s under --app -- exactly the
// bug this audit exists to prevent, but invisible while only src/ was scanned.
$scanDirs = array('/src', '/tests/web', '/tests/handlers', '/tests/classes');
foreach ($scanDirs as $sub) {
    $dir = $wsRoot . $sub;
    if (!is_dir($dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        $rel = substr($f->getPathname(), strlen($wsRoot) + 1);
        scanFile($f->getPathname(), $sharedSet, $platformMembers, $violations, $checked, $rel);
    }
}

echo "Platform: $platform\n";
echo "Shared class names (Platform overrides these): " . implode(', ', $shared) . "\n";
echo "Platform-only classes also governed: " . count($platformOnly) . "\n";
echo "Member references checked on shared classes: $checked\n\n";

if (!$violations) {
    echo "PASS: the webserver uses nothing on a shared class that the Platform lacks.\n";
    exit(0);
}

$seen = array();
foreach ($violations as $v) {
    $key = $v[1];
    if (isset($seen[$key])) { $seen[$key][] = $v[0]; continue; }
    $seen[$key] = array($v[0]);
}
echo "FAIL: " . count($seen) . " member(s) used but not defined by the Platform.\n";
echo "These break under --app mode. Move them onto a webserver-only class.\n\n";
foreach ($seen as $member => $files) {
    echo sprintf("  %-42s %s\n", $member, implode(', ', array_unique($files)));
}
exit(1);
