#!/usr/bin/env php
<?php
/**
 * Qbix Server — pure PHP web server.
 *
 * Standalone: php qbixserver.php --root=./web --port=8080
 * With Qbix:  php qbixserver.php --app=/path/to/myapp --port=8080
 *
 * Options:
 *   --root=DIR       Document root (default: ./web)
 *   --app=DIR        Qbix app directory (loads full Q framework)
 *   --host=IP        Bind address (default: 0.0.0.0)
 *   --port=PORT      Listen port (default: 8080)
 *   --workers=N      Pre-fork PHP workers (default: 0 = in-process)
 *   --config=FILE    JSON config file to load
 *   --pid=PATH       Write PID file
 *   --debug          Enable verbose logging
 *   --version        Print version and exit
 *   --help           Print usage and exit
 */

define('QBIX_SERVER_VERSION', '1.0.0');

// ── Parse CLI args ──────────────────────────────────

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

foreach ($argv as $i => $arg) {
	if ($i === 0) continue;
	if ($arg === '--help' || $arg === '-h') {
		echo "Qbix Server v" . QBIX_SERVER_VERSION . "\n\n";
		echo "Usage: ./qbixserver.php [options]\n\n";
		echo "Options:\n";
		echo "  --root=DIR       Document root (default: ./web)\n";
		echo "  --app=DIR        Qbix app directory (uses full Q framework)\n";
		echo "  --host=IP        Bind address (default: 0.0.0.0)\n";
		echo "  --port=PORT      Listen port (default: 8080)\n";
		echo "  --workers=N      Pre-fork workers (default: 0 = in-process)\n";
		echo "  --config=FILE    JSON config file\n";
		echo "  --pid=PATH       PID file path\n";
		echo "  --hotreload      Watch files, auto-restart on changes\n";
		echo "  --debug          Verbose logging\n";
		echo "  -t               Test config and exit\n";
		echo "  --stop           Graceful shutdown (via PID file)\n";
		echo "  --reload         Re-exec server (via PID file)\n";
		echo "  --version        Print version\n";
		exit(0);
	}
	if ($arg === '--version' || $arg === '-v') {
		echo "Qbix Server v" . QBIX_SERVER_VERSION . "\n";
		exit(0);
	}
	if ($arg === '-t') {
		$opts['test'] = true;
		continue;
	}
	if ($arg === '--stop') {
		$opts['signal'] = 'stop';
		continue;
	}
	if ($arg === '--reload') {
		$opts['signal'] = 'reload';
		continue;
	}
	if ($arg === '--debug') {
		$opts['debug'] = true;
		continue;
	}
	if ($arg === '--hotreload') {
		$opts['hotreload'] = true;
		continue;
	}
	if (preg_match('/^--(\w+)=(.+)$/', $arg, $m)) {
		$opts[$m[1]] = $m[2];
	}
}

// ── Determine mode: standalone vs Qbix app ──────────

$qbixMode = false;

if ($opts['app']) {
	// Qbix app mode — load the full framework
	$appDir = realpath($opts['app']);
	if (!$appDir || !is_dir($appDir)) {
		fwrite(STDERR, "Error: app directory not found: {$opts['app']}\n");
		exit(1);
	}

	// Look for Q.inc.php (standard Qbix app bootstrap)
	$qInc = $appDir . '/scripts/Q.inc.php';
	if (!file_exists($qInc)) {
		// Try Platform path relative to app
		$qInc = $appDir . '/../Platform/scripts/Q.inc.php';
	}
	if (file_exists($qInc)) {
		define('APP_DIR', $appDir);
		require_once $qInc;
		$qbixMode = true;
	} else {
		// Try local/paths.json (Qbix convention for Q_DIR)
		$pathsJson = $appDir . '/local/paths.json';
		if (file_exists($pathsJson)) {
			$paths = json_decode(file_get_contents($pathsJson), true);
			if (!empty($paths['platform'])) {
				$platformDir = realpath($paths['platform']);
				if ($platformDir && file_exists($platformDir . '/Q.php')) {
					define('APP_DIR', $appDir);
					define('Q_DIR', $platformDir);
					require_once $platformDir . '/Q.php';
					$qbixMode = true;
				}
			}
		}
		if (!$qbixMode) {
			fwrite(STDERR, "Warning: Q.inc.php not found, running in standalone mode\n");
		}
	}

	$webDir = $appDir . '/web';
} else {
	$webDir = $opts['root'] ?: (getcwd() . '/web');
}

if (!$qbixMode) {
	// Standalone mode — load minimal Q shim
	require_once __DIR__ . '/src/Q.php';
}

$webDir = realpath($webDir);
if (!$webDir || !is_dir($webDir)) {
	fwrite(STDERR, "Error: document root not found: " . ($opts['root'] ?: './web') . "\n");
	fwrite(STDERR, "Create a web/ directory or use --root=DIR\n");
	exit(1);
}

// Initialize Q with the project root (parent of web/)
// This sets up autoloading from classes/ and handlers from handlers/
$projectRoot = dirname($webDir);
if (!defined('APP_DIR')) {
	define('APP_DIR', $projectRoot);
}
Q::init($projectRoot);

// Load .env file if present (sets $_ENV and getenv())
$envFile = $projectRoot . DIRECTORY_SEPARATOR . '.env';
if (file_exists($envFile)) {
	$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#') continue;
		if (strpos($line, '=') === false) continue;
		list($name, $value) = explode('=', $line, 2);
		$name = trim($name);
		$value = trim($value);
		// Strip surrounding quotes
		if (strlen($value) >= 2
			&& (($value[0] === '"' && $value[strlen($value)-1] === '"')
			|| ($value[0] === "'" && $value[strlen($value)-1] === "'"))
		) {
			$value = substr($value, 1, -1);
		}
		$_ENV[$name] = $value;
		$_SERVER[$name] = $value;
		putenv("$name=$value");
	}
}

// ── Load config ─────────────────────────────────────

// Default server config
$defaultConfig = array(
	'Q' => array(
		'webserver' => array(
			'keepAlive' => array('max' => 100, 'timeout' => 15),
			'timeout'   => array('read' => 30),
			'maxConnections' => 1024,
			'fileCache' => array(
				'maxSize'       => 67108864,
				'maxFile'       => 1048576,
				'checkInterval' => 1,
			),
			'rateLimit' => array(
				'enabled'       => false,
				'requests'      => 100,
				'window'        => 60,
				'burstRequests' => 20,
				'burstWindow'   => 1,
			),
		),
		'web' => array(
			'cache' => array(
				'enabled'    => true,
				'defaultTtl' => 0,
				'components' => array('enabled' => false),
			),
		),
	),
);

// Apply defaults
foreach ($defaultConfig as $k1 => $v1) {
	if (is_array($v1)) {
		foreach ($v1 as $k2 => $v2) {
			if (is_array($v2)) {
				foreach ($v2 as $k3 => $v3) {
					if (is_array($v3)) {
						foreach ($v3 as $k4 => $v4) {
							if (Q_Config::get($k1, $k2, $k3, $k4, null) === null) {
								Q_Config::set($k1, $k2, $k3, $k4, $v4);
							}
						}
					} else {
						if (Q_Config::get($k1, $k2, $k3, null) === null) {
							Q_Config::set($k1, $k2, $k3, $v3);
						}
					}
				}
			}
		}
	}
}

// User config file
if ($opts['config']) {
	Q_Config::load($opts['config']);
}

// Config from app directory
$appConfig = dirname($webDir) . '/config/server.json';
if (file_exists($appConfig)) {
	Q_Config::load($appConfig);
}

// Preload handlers if configured (Q.handlers.preload: true)
Q::preload();

// CLI flag overrides
if (!empty($opts['hotreload'])) {
	Q_Config::set('Q', 'webserver', 'hotReload', true);
}

// ── Signal commands (--stop, --reload) ──────────────

if (!empty($opts['signal'])) {
	$pidFile = $opts['pid'] ?: dirname($webDir) . '/qbixserver.pid';
	if (!file_exists($pidFile)) {
		fwrite(STDERR, "PID file not found: $pidFile\n");
		fwrite(STDERR, "Use --pid=PATH to specify, or start the server with --pid first.\n");
		exit(1);
	}
	$pid = (int) trim(file_get_contents($pidFile));
	if ($pid <= 0 || !posix_kill($pid, 0)) {
		fwrite(STDERR, "No running server found (PID $pid)\n");
		@unlink($pidFile);
		exit(1);
	}
	if ($opts['signal'] === 'stop') {
		posix_kill($pid, SIGTERM);
		echo "Sent SIGTERM to PID $pid\n";
	} elseif ($opts['signal'] === 'reload') {
		posix_kill($pid, SIGHUP);
		echo "Sent SIGHUP to PID $pid\n";
	}
	exit(0);
}

// ── Config test (-t) ────────────────────────────────

if (!empty($opts['test'])) {
	echo "Qbix Server v" . QBIX_SERVER_VERSION . "\n";
	echo "Config: OK\n";
	echo "  Root:       $webDir\n";
	echo "  Host:       {$opts['host']}\n";
	echo "  Port:       {$opts['port']}\n";
	$app = Q::app();
	if ($app) echo "  App:        $app\n";
	$ioPath = Q_Config::get('Q', 'socket', 'io', '/socket.io');
	echo "  Socket.IO:  " . ($ioPath !== false ? $ioPath : 'disabled') . "\n";
	$jsPath = Q_Config::get('Q', 'socket', 'js', '/Q/socket.js');
	echo "  Socket.js:  " . ($jsPath !== false ? $jsPath : 'disabled') . "\n";
	$hosts = Q_Config::get('Q', 'webserver', 'hosts', array());
	if (!empty($hosts)) {
		echo "  Vhosts:     " . implode(', ', array_keys($hosts)) . "\n";
	}
	$schedule = Q_Config::get('Q', 'scheduler', array());
	if (!empty($schedule)) {
		echo "  Scheduled:  " . implode(', ', array_keys($schedule)) . "\n";
	}
	$timeout = Q_Config::get('Q', 'webserver', 'requestTimeout', 30);
	echo "  Timeout:    {$timeout}s\n";
	echo "  Post max:   " . Q_Config::get('Q', 'webserver', 'postMaxSize', ini_get('post_max_size') ?: '8M') . "\n";
	echo "  Upload max: " . Q_Config::get('Q', 'webserver', 'uploadMaxFilesize', ini_get('upload_max_filesize') ?: '2M') . "\n";
	if (defined('Q_DIR')) echo "  Q_DIR:      " . Q_DIR . "\n";
	$preloaded = Q::$preloadedHandlers;
	echo "  Classes:    " . count(get_declared_classes()) . " preloaded\n";
	echo "  Handlers:   " . ($preloaded > 0 ? "$preloaded preloaded" : "lazy") . "\n";
	exit(0);
}

// ── PID file ────────────────────────────────────────

if ($opts['pid']) {
	file_put_contents($opts['pid'], getmypid());
	register_shutdown_function(function () use ($opts) {
		@unlink($opts['pid']);
	});
}

// ── Request logging ─────────────────────────────────

$colors = array(
	2 => "\033[32m", // green for 2xx
	3 => "\033[33m", // yellow for 3xx
	4 => "\033[31m", // red for 4xx
	5 => "\033[31m", // red for 5xx
);
$reset = "\033[0m";

Q_WebServer::$onRequest = function ($method, $uri, $status, $ms) use ($colors, $reset, $opts) {
	$color = $colors[(int)($status / 100)] ?? '';
	$time = date('H:i:s');
	echo "$time {$color}{$status}{$reset} $method $uri ({$ms}ms)\n";
};

// ── Start server ────────────────────────────────────

$W = 38; // inner width of the box

echo "\n";
echo "  ┌" . str_repeat('─', $W) . "┐\n";
echo "  │" . str_pad("  Qbix Server v" . QBIX_SERVER_VERSION, $W) . "│\n";
echo "  ├" . str_repeat('─', $W) . "┤\n";
echo "  │" . str_pad("  http://{$opts['host']}:{$opts['port']}", $W) . "│\n";
echo "  │" . str_pad("  Root: " . basename($webDir), $W) . "│\n";
echo "  │" . str_pad("  Mode: " . ($qbixMode ? 'Qbix Platform' : 'Standalone'), $W) . "│\n";
echo "  │" . str_pad("  PHP: " . ($opts['workers'] ? $opts['workers'] . ' workers' : 'in-process'), $W) . "│\n";
$nClasses = count(get_declared_classes());
$nHandlers = Q::$preloadedHandlers;
$preloadLabel = $nHandlers > 0
	? "  Preloaded: {$nClasses} classes, {$nHandlers} handlers"
	: "  Preloaded: {$nClasses} classes (handlers: lazy)";
echo "  │" . str_pad($preloadLabel, $W) . "│\n";
echo "  ├" . str_repeat('─', $W) . "┤\n";
echo "  │" . str_pad("  Dashboard: /Q/dashboard", $W) . "│\n";
echo "  │" . str_pad("  Health:    /Q/health", $W) . "│\n";
echo "  │" . str_pad("  Ctrl+C to stop", $W) . "│\n";
echo "  └" . str_repeat('─', $W) . "┘\n";
echo "\n";

Q_WebServer::start(
	$webDir,
	$opts['host'],
	(int) $opts['port'],
	(int) $opts['workers']
);

Q_WebServer::run();
