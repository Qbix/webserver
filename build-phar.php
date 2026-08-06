#!/usr/bin/env php
<?php
/**
 * Build qbixserver.phar — single-file distributable.
 *
 * Usage: php build-phar.php
 * Output: bin/qbixserver.phar
 *
 * The PHAR includes all server classes and the minimal Q shim.
 * Run with: php bin/qbixserver.phar --root=./web --port=8080
 *
 * Requires: phar.readonly=0 in php.ini (or pass -d phar.readonly=0)
 */

if (ini_get('phar.readonly')) {
	echo "Error: phar.readonly is enabled.\n";
	echo "Run with: php -d phar.readonly=0 build-phar.php\n";
	exit(1);
}

$pharFile = __DIR__ . '/bin/qbixserver.phar';
if (file_exists($pharFile)) {
	unlink($pharFile);
}

@mkdir(__DIR__ . '/bin', 0755, true);

echo "Building qbixserver.phar...\n";

$phar = new Phar($pharFile, 0, 'qbixserver.phar');
$phar->startBuffering();

// Add source files
$srcDir = __DIR__ . '/src';
$phar->buildFromIterator(
	new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
	),
	$srcDir
);

// Add the entry point (modified for PHAR)
$stub = <<<'STUB'
#!/usr/bin/env php
<?php
Phar::mapPhar('qbixserver.phar');

// NOTE: Q.php is NOT required here. It declares `class Q`, and in --app mode
// the Platform declares its own, so loading it unconditionally made the phar
// die with "Cannot declare class Q, because the name is already in use" the
// moment it was pointed at a real app. Which shim to load depends on the mode,
// so the decision is made after the arguments are parsed, exactly as
// qbixserver.php does when running from source.

// Parse args the same way as qbixserver.php
$opts = array(
	'root'    => null,
	'app'     => null,
	'host'    => '0.0.0.0',
	'port'    => 8080,
	'workers' => 0,
	'config'  => null,
	'pid'     => null,
	'debug'   => false,
);

define('QBIX_SERVER_VERSION', '1.0.0');

foreach ($argv as $i => $arg) {
	if ($i === 0) continue;
	if ($arg === '--help' || $arg === '-h') {
		echo "Qbix Server v" . QBIX_SERVER_VERSION . " (PHAR)\n\n";
		echo "Usage: php qbixserver.phar [options]\n\n";
		echo "  --root=DIR       Document root (default: ./web)\n";
		echo "  --app=DIR        Qbix app directory\n";
		echo "  --host=IP        Bind address (default: 0.0.0.0)\n";
		echo "  --port=PORT      Listen port (default: 8080)\n";
		echo "  --workers=N      Pre-fork workers (default: 0)\n";
		echo "  --config=FILE    JSON config file\n";
		echo "  --version        Print version\n";
		exit(0);
	}
	if ($arg === '--version' || $arg === '-v') {
		echo "Qbix Server v" . QBIX_SERVER_VERSION . " (PHAR)\n";
		exit(0);
	}
	if ($arg === '--debug') { $opts['debug'] = true; continue; }
	if (preg_match('/^--(\w+)=(.+)$/', $arg, $m)) $opts[$m[1]] = $m[2];
}

// Qbix app mode
$qbixMode = false;
if ($opts['app']) {
	$appDir = realpath($opts['app']);
	$qInc = $appDir . '/scripts/Q.inc.php';
	if (!file_exists($qInc)) $qInc = $appDir . '/../Platform/scripts/Q.inc.php';
	if (file_exists($qInc)) {
		define('APP_DIR', $appDir);
		require_once $qInc;   // the Platform's Q wins for every name it defines
		$qbixMode = true;

		// Teach the Platform's autoloader where OUR classes live. Deliberately
		// selective: the phar also carries Utils, Uri, Evented and Snapshot,
		// which the Platform defines too. Claiming those would shadow the
		// Platform's with the standalone versions -- the same duplication bug
		// in reverse. We claim only what is ours alone.
		spl_autoload_register(function ($className) {
			$owned = array('Q_WebServer', 'Q_WebSocket', 'Q_Scheduler',
				'Q_FileCache', 'Q_HotReload');
			$ours = in_array($className, $owned, true)
				|| strpos($className, 'Q_WebServer_') === 0;
			if (!$ours) return;
			$rel = str_replace('_', '/', substr($className, 2)) . '.php';
			$file = 'phar://qbixserver.phar/Q/' . $rel;
			if (file_exists($file)) require_once $file;
		}, true, true);
	}
	$webDir = $appDir . '/web';
} else {
	// Standalone: the bundled shim provides Q, Q_Response, Q_Request, Q_Config.
	require 'phar://qbixserver.phar/Q.php';
	$webDir = $opts['root'] ?: (getcwd() . '/web');
}

$webDir = realpath($webDir);
if (!$webDir || !is_dir($webDir)) {
	fwrite(STDERR, "Error: document root not found\n");
	exit(1);
}

if ($opts['config']) Q_Config::load($opts['config']);

Q_WebServer::$onRequest = function ($method, $uri, $status, $ms) {
	$c = array(2=>"\033[32m",3=>"\033[33m",4=>"\033[31m",5=>"\033[31m");
	echo date('H:i:s').' '.($c[(int)($status/100)]??'').$status."\033[0m $method $uri ({$ms}ms)\n";
};

echo "\n  Qbix Server v" . QBIX_SERVER_VERSION . " (PHAR)\n";
echo "  http://{$opts['host']}:{$opts['port']}  Root: " . basename($webDir) . "\n\n";

Q_WebServer::start($webDir, $opts['host'], (int)$opts['port'], (int)$opts['workers']);
Q_WebServer::run();

__HALT_COMPILER();
STUB;

$phar->setStub($stub);
$phar->stopBuffering();

// Make executable
chmod($pharFile, 0755);

$size = filesize($pharFile);
echo "Built: bin/qbixserver.phar (" . round($size / 1024) . " KB)\n";
echo "Run:   php bin/qbixserver.phar --root=./web --port=8080\n";
echo "Or:    chmod +x bin/qbixserver.phar && ./bin/qbixserver.phar --port=8080\n";
