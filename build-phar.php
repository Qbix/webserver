#!/usr/bin/env php
<?php
/**
 * Build qbix-server.phar — single-file distributable.
 *
 * Usage: php build-phar.php
 * Output: bin/qbix-server.phar
 *
 * The PHAR includes all server classes and the minimal Q shim.
 * Run with: php bin/qbix-server.phar --root=./web --port=8080
 *
 * Requires: phar.readonly=0 in php.ini (or pass -d phar.readonly=0)
 */

if (ini_get('phar.readonly')) {
	echo "Error: phar.readonly is enabled.\n";
	echo "Run with: php -d phar.readonly=0 build-phar.php\n";
	exit(1);
}

$pharFile = __DIR__ . '/bin/qbix-server.phar';
if (file_exists($pharFile)) {
	unlink($pharFile);
}

@mkdir(__DIR__ . '/bin', 0755, true);

echo "Building qbix-server.phar...\n";

$phar = new Phar($pharFile, 0, 'qbix-server.phar');
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
Phar::mapPhar('qbix-server.phar');

// Bootstrap
require 'phar://qbix-server.phar/Q.php';

// Parse args the same way as server.php
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
		echo "Usage: php qbix-server.phar [options]\n\n";
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
if ($opts['app']) {
	$appDir = realpath($opts['app']);
	$qInc = $appDir . '/scripts/Q.inc.php';
	if (!$qInc) $qInc = $appDir . '/../Platform/scripts/Q.inc.php';
	if (file_exists($qInc)) {
		define('APP_DIR', $appDir);
		require_once $qInc;
	}
	$webDir = $appDir . '/web';
} else {
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
echo "Built: bin/qbix-server.phar (" . round($size / 1024) . " KB)\n";
echo "Run:   php bin/qbix-server.phar --root=./web --port=8080\n";
echo "Or:    chmod +x bin/qbix-server.phar && ./bin/qbix-server.phar --port=8080\n";
