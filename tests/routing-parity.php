#!/usr/bin/env php
<?php
/**
 * Routing parity: our matcher vs the Platform's, on the same Q/routes table.
 *
 * The webserver keeps its own Q_Uri because adopting the Platform's costs a
 * ~197KB closure (Uri 41K + Request 55K + Utils 84K + Valid 17K) against 7.5KB,
 * and a standalone static server needs none of slots, mobile detection or
 * validation to match "AI/webhook/:type/:task". In --app mode the Platform's
 * Q_Uri is already loaded and ours is unused.
 *
 * That split is only safe if the two AGREE on the subset we support. This test
 * feeds identical routes and paths to both and compares module/action/params.
 *
 * Usage: php tests/routing-parity.php [/path/to/platform]
 */

$wsRoot   = dirname(__DIR__);
$platform = $argv[1] ?? getenv('QBIX_PLATFORM_DIR') ?: '/tmp/qbix/Platform/platform';

if (!is_file($platform . '/classes/Q/Uri.php')) {
    fwrite(STDERR, "SKIP: no Platform at $platform\n");
    exit(0);
}

// The shared route table. Same grammar both sides claim to implement.
$ROUTES = array(
    'AI/webhook/:type/:task'      => array('module' => 'AI',      'action' => 'webhook'),
    'AI/stream/command'           => array('module' => 'AI',      'action' => 'stream/command'),
    'Safebox/workload/:workloadId'=> array('module' => 'Safebox', 'action' => 'workload'),
    'Safebox/action'              => array('module' => 'Safebox', 'action' => 'action'),
    'Streams/stream/:publisherId/:name' => array('module' => 'Streams', 'action' => 'stream'),
    ':module/:action'             => array(),
);

$PATHS = array(
    'AI/webhook/slack/ingest',
    'AI/stream/command',
    'Safebox/workload/abc123',
    'Safebox/action',
    'Streams/stream/alice/Streams%2Fchat%2Fmain',
    'Users/login',
    'nope',
    '',
    'AI/webhook/slack',          // too few segments for the :type/:task route
    'AI/webhook/slack/ingest/x', // too many
);

$pass = 0; $fail = 0;
function ok($cond, $msg) {
    global $pass, $fail;
    if ($cond) { ++$pass; echo "  ok   $msg\n"; }
    else       { ++$fail; echo "  FAIL $msg\n"; }
}

/** Normalise either implementation's result to a comparable array. */
function norm($uri) {
    if (empty($uri)) return null;
    $a = array();
    foreach (array('module', 'action') as $k) {
        $a[$k] = is_object($uri) ? (isset($uri->$k) ? $uri->$k : null)
                                 : (isset($uri[$k]) ? $uri[$k] : null);
    }
    if (empty($a['module']) && empty($a['action'])) return null;
    return $a;
}

// ── run each implementation in its own process, so the class names don't clash
function runSide($which, $root, $platform, $routes, $paths) {
    $script = <<<'PHP'
<?php
// $argv[0] is this script's own path; the 5 real arguments follow it.
// array_shift() + list() silently mis-bound them ($which received the repo
// root), so the platform branch never ran and every path came back null.
$args = array_slice($argv, 1);
if (count($args) < 5) {
    fwrite(STDERR, "expected 5 args, got " . count($args) . "\n");
    echo json_encode(null);
    exit(0);
}
list($which, $root, $platform, $routesJson, $pathsJson) = $args;
$routes = json_decode($routesJson, true);
$qParityPaths = json_decode($pathsJson, true);
if ($which === 'webserver') {
    require_once $root . '/src/Q.php';
    require_once $root . '/src/Q/Uri.php';
} else {
    // The Platform's Q_Uri is NOT loadable on its own: it needs DS, the
    // autoloader and a bootstrapped config, which only the app's Q.inc.php
    // sets up. Requiring classes/Q.php alone left Q_Uri undefined, so every
    // path came back null and the comparison was vacuous -- it would have
    // "passed" just as happily with our matcher broken too.
    $appDir = getenv('QBIX_APP_DIR') ?: '/tmp/apps/TestApp';
    $inc = $appDir . '/scripts/Q.inc.php';
    if (!is_file($inc)) {
        fwrite(STDERR, "no app bootstrap at $inc\n");
        echo json_encode(null);
        exit(0);
    }
    include_once $inc;
}
if (!class_exists('Q_Uri')) {
    fwrite(STDERR, "Q_Uri did not load for side=$which\n");
    echo json_encode(null);
    exit(0);
}
if (method_exists('Q_Config', 'set')) {
    Q_Config::set('Q', 'routes', $routes);
}
$out = array();
foreach ($qParityPaths as $p) {
    $r = null;
    try {
        if (method_exists('Q_Uri', 'fromPath')) {
            $r = Q_Uri::fromPath($p);
        } else if (method_exists('Q_Uri', 'from')) {
            // Q_Uri::from() only ROUTES when handed something Q_Valid::url()
            // accepts; a bare path goes to fromString(), which parses
            // "Module/action" syntax and never consults Q/routes. Passing
            // paths made the Platform look like it matched nothing.
            $base = method_exists('Q_Request', 'baseUrl') ? Q_Request::baseUrl() : '';
            $r = Q_Uri::from($base ? rtrim($base, '/') . '/' . ltrim($p, '/') : $p);
        }
    } catch (Throwable $e) { $r = null; }
    $m = $a = null;
    if (!empty($r)) {
        // The Platform's Q_Uri keeps module/action in a PROTECTED $fields
        // array, reachable only through __get()/toArray(). Reading $r->module
        // with isset() returned null for every route, so both sides looked
        // like "no match" and the comparison proved nothing. toArray() exists
        // on both implementations and is the honest accessor.
        $f = is_object($r) && method_exists($r, 'toArray') ? $r->toArray()
           : (is_array($r) ? $r : array());
        if (!is_array($f)) $f = array();
        $m = isset($f['module']) ? $f['module'] : null;
        $a = isset($f['action']) ? $f['action'] : null;
        if ($m === null && is_object($r)) { $m = $r->module; }
        if ($a === null && is_object($r)) { $a = $r->action; }
    }
    $out[$p] = ($m || $a) ? array('module' => $m, 'action' => $a) : null;
}
echo json_encode($out);
PHP;
    $tmp = tempnam(sys_get_temp_dir(), 'parity') . '.php';
    file_put_contents($tmp, $script);
    $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' '
        . escapeshellarg($which) . ' ' . escapeshellarg($root) . ' '
        . escapeshellarg($platform) . ' '
        . escapeshellarg(json_encode($routes)) . ' '
        . escapeshellarg(json_encode($paths));
    $json = shell_exec($cmd);
    @unlink($tmp);
    return json_decode((string)$json, true);
}

echo "Routing parity — webserver Q_Uri vs Platform Q_Uri\n";
echo "Platform: $platform\n\n";

$ours   = runSide('webserver', $wsRoot, $platform, $ROUTES, $PATHS);
$theirs = runSide('platform',  $wsRoot, $platform, $ROUTES, $PATHS);

if (!is_array($ours)) {
    echo "  FAIL webserver matcher produced no result (could not load)\n";
    exit(1);
}
if (!is_array($theirs)) {
    echo "  SKIP Platform matcher could not run standalone; parity unverified.\n";
    echo "       (This is itself informative: the Platform's Q_Uri needs a full\n";
    echo "        bootstrap, which is exactly why the webserver keeps its own.)\n";
    exit(0);
}

foreach ($PATHS as $p) {
    $a = isset($ours[$p]) ? $ours[$p] : null;
    $b = isset($theirs[$p]) ? $theirs[$p] : null;
    $label = $p === '' ? '(empty)' : $p;
    ok($a === $b, sprintf('%-42s ours=%s theirs=%s',
        $label,
        $a ? "{$a['module']}/{$a['action']}" : 'null',
        $b ? "{$b['module']}/{$b['action']}" : 'null'));
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
