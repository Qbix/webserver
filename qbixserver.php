#!/usr/bin/env php
<?php
/**
 * Qbix Server — pure PHP web server.
 *
 * Standalone: php qbixserver.php --root=./web --port=80
 * With Qbix:  php qbixserver.php --app=/path/to/myapp --port=80
 *
 * Options:
 *   --root=DIR       Document root (default: ./web)
 *   --app=DIR        Qbix app directory (loads full Q framework)
 *   --host=IP        Bind address (default: 0.0.0.0)
 *   --port=PORT      HTTP port (default: 80)
 *   --https-port=PORT HTTPS port (default: 443, only if certs available)
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
	'port'    => null,  // null = read from config, then default 80
	'https-port' => null, // null = read from config, then default 443
	'socket'  => null,  // Unix domain socket path (e.g. /run/qbix/app.sock)
	'socket-mode' => null, // Permissions for the socket file (e.g. 0660)
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
		echo "  --port=PORT      HTTP port (default: 80)\n";
		echo "  --https-port=PORT HTTPS port (default: 443, if certs available)\n";
		echo "  --socket=PATH    Unix domain socket (e.g. /run/qbix/app.sock)\n";
		echo "  --socket-mode=MODE  Permissions on socket file (default: 0660)\n";
		echo "  --workers=N      Pre-fork workers (default: 0 = in-process)\n";
		echo "  --config=FILE    JSON config file\n";
		echo "  --pid=PATH       PID file path\n";
		echo "  --hotreload      Watch files, auto-restart on changes\n";
		echo "  --debug          Verbose logging\n";
		echo "  -t               Test config and exit\n";
		echo "  --stop           Graceful shutdown (via PID file)\n";
		echo "  --reload         Re-exec server (via PID file)\n";
		echo "  --deploy=TARGET  Deploy to remote server (from config/deploy.json)\n";
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
	if (preg_match('/^--([\w-]+)=(.+)$/', $arg, $m)) {
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
		// The webserver owns these classes; the Platform does not need them.
		// Nothing in platform/classes or the plugins references Q_WebServer except
		// a single comment, and a Platform running behind nginx or php-fpm should
		// not carry a copy of code it never loads. So we do NOT duplicate them into
		// the Platform — we teach Q's autoloader where ours live.
		//
		// Deliberately selective. src/Q also contains Utils, Uri, Evented and
		// Snapshot, which the PLATFORM also defines. Claiming those here would
		// shadow the Platform's versions with the standalone ones — the same
		// duplication bug in reverse. In --app mode the Platform wins for anything
		// it defines; we only claim what is ours alone.
		$webserverOwnedClasses = array(
			'Q_WebServer',   // + Q_WebServer_* subclasses, handled by prefix below
			'Q_WebSocket',
			'Q_Scheduler',
			'Q_FileCache',
			'Q_HotReload'
		);
		$serverSrcDir = __DIR__ . DIRECTORY_SEPARATOR . 'src'
			. DIRECTORY_SEPARATOR . 'Q' . DIRECTORY_SEPARATOR;
		spl_autoload_register(function ($className) use ($webserverOwnedClasses, $serverSrcDir) {
			$ours = in_array($className, $webserverOwnedClasses, true)
				|| strpos($className, 'Q_WebServer_') === 0;
			if (!$ours) {
				return; // let the Platform's autoloader handle everything else
			}
			$rel = str_replace('_', DIRECTORY_SEPARATOR, substr($className, 2)) . '.php';
			$file = $serverSrcDir . $rel;
			if (file_exists($file)) {
				require_once $file;
			}
		}, true, true); // prepend: these names are ours, not the Platform's

		// Issue #13: fallback autoloader for shared classes (Q_Evented,
		// Q_Snapshot, Q_Utils, Q_Uri) when the Platform doesn't provide them.
		// Appended (not prepended) so the Platform gets first refusal.
		spl_autoload_register(function ($className) use ($serverSrcDir) {
			if (strpos($className, 'Q_') !== 0) {
				return;
			}
			// Only act if no other loader resolved it
			if (class_exists($className, false)) {
				return;
			}
			$rel = str_replace('_', DIRECTORY_SEPARATOR, substr($className, 2)) . '.php';
			$file = $serverSrcDir . $rel;
			if (file_exists($file)) {
				require_once $file;
			}
		}); // appended: Platform wins, we're the safety net
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

// Initialize project root
// In standalone mode: Q::init() sets up autoloading from classes/ and handlers/
// In --app mode: Platform already initialized, but we still register the path
$projectRoot = dirname($webDir);
if (!defined('APP_DIR')) {
	define('APP_DIR', $projectRoot);
}
if (method_exists('Q', 'init')) {
	Q::init($projectRoot);
} else if (property_exists('Q', 'paths')) {
	// Standalone shim (src/Q.php) exposes Q::$paths — register the path
	if (!in_array($projectRoot, Q::$paths ?? array())) {
		Q::$paths[] = $projectRoot;
	}
}
// else: Platform's Q was bootstrapped via scripts/Q.inc.php, which already
// registered all app/plugin paths — nothing to do here.

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
			'port'      => 80,
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
if (method_exists('Q', 'preload')) {
	Q::preload();
}

// CLI flag overrides
if (!empty($opts['hotreload'])) {
	Q_Config::set('Q', 'webserver', 'hotReload', true);
}

// ── Resolve ports: CLI overrides config, config overrides defaults ──
// HTTP port: --port > Q.webserver.port > 80
if ($opts['port'] !== null) {
	$httpPort = (int) $opts['port'];
} else {
	$httpPort = (int) Q_Config::get('Q', 'webserver', 'port', 80);
}
$opts['port'] = $httpPort;

// HTTPS port: --https-port > Q.web.https.port > 443
if ($opts['https-port'] !== null) {
	$httpsPort = (int) $opts['https-port'];
	// CLI explicitly set — store into config so start() sees it
	Q_Config::set('Q', 'web', 'https', 'port', $httpsPort);
} else {
	$httpsPort = (int) Q_Config::get('Q', 'web', 'https', 'port', 443);
}
$opts['https-port'] = $httpsPort;

// Store HTTP port in config too (for anything that reads it)
Q_Config::set('Q', 'webserver', 'port', $httpPort);

// Unix domain socket: --socket > Q.webserver.socket > null (TCP only)
$socketPath = $opts['socket'] ?: Q_Config::get('Q', 'webserver', 'socket', null);
if ($socketPath) {
	Q_Config::set('Q', 'webserver', 'socket', $socketPath);
	$socketMode = $opts['socket-mode']
		?: Q_Config::get('Q', 'webserver', 'socketMode', '0660');
	Q_Config::set('Q', 'webserver', 'socketMode', $socketMode);
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
		fwrite(STDERR, "Sent SIGTERM to PID $pid\n");
	} elseif ($opts['signal'] === 'reload') {
		posix_kill($pid, SIGHUP);
		fwrite(STDERR, "Sent SIGHUP to PID $pid\n");
	}
	exit(0);
}

// ── Config test (-t) ────────────────────────────────

if (!empty($opts['test'])) {
	echo "Qbix Server v" . QBIX_SERVER_VERSION . "\n";
	echo "Config: OK\n";
	echo "  Root:       $webDir\n";
	echo "  Host:       {$opts['host']}\n";
	echo "  HTTP:       port {$opts['port']}\n";
	echo "  HTTPS:      port {$opts['https-port']}\n";
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

// ── Deploy ──────────────────────────────────────────

foreach ($argv as $arg) {
	if (strpos($arg, '--deploy') === 0) {
		$target = (strpos($arg, '=') !== false) ? substr($arg, 9) : 'production';
		$deployFile = (defined('APP_DIR') ? APP_DIR : $projectRoot) . DS . 'config' . DS . 'deploy.json';
		if (!file_exists($deployFile)) {
			fwrite(STDERR, "No config/deploy.json found. Create one:\n");
			fwrite(STDERR, json_encode(array('targets' => array(
				'production' => array(
					'host' => 'myserver.com',
					'user' => 'deploy',
					'path' => '/var/www/myapp',
					'key' => '~/.ssh/deploy_key',
					'dirs' => array('web', 'handlers', 'classes', 'config')
				)
			)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			exit(1);
		}
		$deploy = json_decode(file_get_contents($deployFile), true);
		$t = $deploy['targets'][$target] ?? null;
		if (!$t) {
			fwrite(STDERR, "Unknown target: $target. Available: " . implode(', ', array_keys($deploy['targets'] ?? array())) . "\n");
			exit(1);
		}
		$baseDir = defined('APP_DIR') ? APP_DIR : $projectRoot;
		$dirs = $t['dirs'] ?? array('web', 'handlers', 'classes', 'config', 'local');
		$sshKey = !empty($t['key']) ? " -e 'ssh -i " . escapeshellarg($t['key']) . "'" : '';
		$remote = $t['user'] . '@' . $t['host'] . ':' . rtrim($t['path'], '/') . '/';

		echo "Deploying to {$target} ({$t['host']})...\n";
		$total = 0;
		foreach ($dirs as $dir) {
			$localDir = $baseDir . DS . $dir;
			if (!is_dir($localDir)) continue;
			$cmd = "rsync -avz --delete{$sshKey} "
				. escapeshellarg(rtrim($localDir, '/') . '/') . " "
				. escapeshellarg($remote . $dir . '/') . " 2>&1";
			echo "  rsync $dir/\n";
			$output = shell_exec($cmd);
			$lines = explode("\n", trim($output));
			// Count transferred files (lines that don't start with . or end with /)
			$transferred = count(array_filter($lines, function ($l) {
				return $l && $l[0] !== '.' && substr($l, -1) !== '/' && strpos($l, 'sending') === false
					&& strpos($l, 'total') === false && strpos($l, 'sent') === false;
			}));
			$total += $transferred;
		}

		// Hit remote reload endpoint if configured
		if (!empty($t['reload_url'])) {
			$secret = $t['reload_secret'] ?? '';
			$ctx = stream_context_create(array('http' => array(
				'method' => 'POST',
				'header' => "Authorization: Bearer {$secret}\r\nContent-Length: 0\r\n",
				'timeout' => 5,
			)));
			@file_get_contents($t['reload_url'], false, $ctx);
			echo "  Reload signal sent\n";
		}

		echo "✨ Deployed $total files to $target\n";
		exit(0);
	}
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
	fwrite(STDERR, "$time {$color}{$status}{$reset} $method $uri ({$ms}ms)\n");
};

// ── Start server ────────────────────────────────────

$W = 38; // inner width of the box

// Detect if HTTPS will be available (certs must actually exist)
$httpsAvailable = false;
$httpsConfig = Q_Config::get('Q', 'web', 'https', array());
$certsDir = (defined('APP_DIR') ? APP_DIR : dirname($webDir))
	. DIRECTORY_SEPARATOR . 'local' . DIRECTORY_SEPARATOR . 'certs';

// Check explicit cert paths from config first
$certFile = Q::ifset($httpsConfig, 'cert', $certsDir . DIRECTORY_SEPARATOR . 'fullchain.pem');
$keyFile = Q::ifset($httpsConfig, 'key', $certsDir . DIRECTORY_SEPARATOR . 'privkey.pem');
if (is_file($certFile) && is_file($keyFile)) {
	$httpsAvailable = true;
}

fwrite(STDERR, "\n");
fwrite(STDERR, "  ┌" . str_repeat('─', $W) . "┐\n");
fwrite(STDERR, "  │" . str_pad("  Qbix Server v" . QBIX_SERVER_VERSION, $W) . "│\n");
fwrite(STDERR, "  ├" . str_repeat('─', $W) . "┤\n");
$httpLine = "  http://{$opts['host']}:{$opts['port']}";
fwrite(STDERR, "  │" . str_pad($httpLine, $W) . "│\n");
if ($socketPath) {
	fwrite(STDERR, "  │" . str_pad("  unix:" . $socketPath, $W) . "│\n");
}
if ($httpsAvailable) {
	$httpsLine = "  https://{$opts['host']}:{$opts['https-port']}";
	fwrite(STDERR, "  │" . str_pad($httpsLine, $W) . "│\n");
}
fwrite(STDERR, "  │" . str_pad("  Root: " . basename($webDir), $W) . "│\n");
fwrite(STDERR, "  │" . str_pad("  Mode: " . ($qbixMode ? 'Qbix Platform' : 'Standalone'), $W) . "│\n");
fwrite(STDERR, "  │" . str_pad("  PHP: " . ($opts['workers'] ? $opts['workers'] . ' workers' : 'in-process'), $W) . "│\n");
$nClasses = count(get_declared_classes());
$nHandlers = property_exists('Q', 'preloadedHandlers') ? Q::$preloadedHandlers : 0;
$preloadLabel = $nHandlers > 0
	? "  Preloaded: {$nClasses} classes, {$nHandlers} handlers"
	: "  Preloaded: {$nClasses} classes (handlers: lazy)";
fwrite(STDERR, "  │" . str_pad($preloadLabel, $W) . "│\n");
fwrite(STDERR, "  ├" . str_repeat('─', $W) . "┤\n");
fwrite(STDERR, "  │" . str_pad("  Dashboard: /Q/dashboard", $W) . "│\n");
fwrite(STDERR, "  │" . str_pad("  Health:    /Q/health", $W) . "│\n");
fwrite(STDERR, "  │" . str_pad("  Ctrl+C to stop", $W) . "│\n");
fwrite(STDERR, "  └" . str_repeat('─', $W) . "┘\n");
fwrite(STDERR, "\n");

try {
	Q_WebServer::start(
		$webDir,
		$opts['host'],
		(int) $opts['port'],
		(int) $opts['workers']
	);
} catch (Exception $e) {
	fwrite(STDERR, "Failed to start: " . $e->getMessage() . "\n");
	exit(1);
}

// Issue #15: wrap the event loop so any uncaught exception or TypeError
// exits non-zero — a supervisor (Docker, systemd) can then restart us.
// Without this, the process exits 0 and looks like a clean shutdown.
try {
	Q_WebServer::run();
} catch (\Throwable $e) {
	fwrite(STDERR, "\n  FATAL: " . get_class($e) . ": " . $e->getMessage() . "\n");
	fwrite(STDERR, "  in " . $e->getFile() . ":" . $e->getLine() . "\n");
	fwrite(STDERR, "  " . $e->getTraceAsString() . "\n\n");
	exit(1);
}
