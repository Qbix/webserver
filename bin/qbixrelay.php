#!/usr/bin/env php
<?php
/**
 * Qbix Relay — email & SMS relay process.
 *
 * Normally spawned automatically by qbixserver.php when relay config is present.
 * Can also run standalone:
 *
 *   php qbixrelay.php
 *   php qbixrelay.php --app=/path/to/myapp
 *   php qbixrelay.php --config=relay.json
 *
 * Options:
 *   --app=DIR        Qbix app directory (loads full Q framework)
 *   --config=FILE    JSON config file to merge
 *   --host=IP        SMTP listen address (default: 127.0.0.1)
 *   --port=PORT      SMTP listen port (default: 2525)
 *   --pid=PATH       Write PID file
 *   --debug          Enable verbose logging
 *   --version        Print version and exit
 *   --help           Print usage and exit
 */

define('QBIX_RELAY_VERSION', '3.2.0');
define('QBIX_RELAY_DIR', dirname(__DIR__));

// ── Parse CLI args ─────────────────────────────────
$opts = [
	'app'     => null,
	'config'  => null,
	'host'    => null,
	'port'    => null,
	'pid'     => null,
	'debug'   => false,
	'version' => false,
	'help'    => false,
];

for ($i = 1; $i < $argc; $i++) {
	$arg = $argv[$i];
	if ($arg === '--version') {
		echo "Qbix Relay v" . QBIX_RELAY_VERSION . "\n";
		exit(0);
	}
	if ($arg === '--help') {
		echo <<<HELP
Qbix Relay v{QBIX_RELAY_VERSION}

Usage: php qbixrelay.php [options]

Options:
  --app=DIR        Qbix app directory (loads full Q framework)
  --config=FILE    JSON config file to merge
  --host=IP        SMTP listen address (default: 127.0.0.1)
  --port=PORT      SMTP listen port (default: 2525)
  --pid=PATH       Write PID file
  --debug          Enable verbose logging
  --version        Print version and exit
  --help           Print usage and exit

Normally spawned automatically by qbixserver.php when relay config exists.
HELP;
		exit(0);
	}
	if ($arg === '--debug') {
		$opts['debug'] = true;
		continue;
	}
	if (preg_match('/^--(\w[\w-]*)=(.*)$/', $arg, $m)) {
		$key = str_replace('-', '_', $m[1]);
		if (array_key_exists($key, $opts)) {
			$opts[$key] = $m[2];
		}
	}
}

// ── Bootstrap ──────────────────────────────────────
// If running inside the Qbix Server PHAR, classes are already available.
// If standalone, we need to load the source tree.
$srcDir = QBIX_RELAY_DIR . '/src';
if (is_dir($srcDir)) {
	// Autoloader for Q_* classes: Q_Relay_Smtp → src/Q/Relay/Smtp.php
	spl_autoload_register(function ($class) use ($srcDir) {
		$path = $srcDir . '/' . str_replace('_', '/', $class) . '.php';
		if (is_file($path)) {
			require_once $path;
		}
	});
}

// Load Q framework if --app specified and Q class isn't loaded yet
if ($opts['app'] && !class_exists('Q', false)) {
	$qPhp = $opts['app'] . '/Q.php';
	if (is_file($qPhp)) {
		require_once $qPhp;
	}
}

// Load a JSON config file if specified
if ($opts['config'] && is_file($opts['config'])) {
	$configData = json_decode(file_get_contents($opts['config']), true);
	if ($configData && class_exists('Q_Config', false)) {
		Q_Config::merge($configData);
	}
}

// Helper if Q_Config is not available
if (!function_exists('qbix_data_path')) {
	function qbix_data_path($relative) {
		$base = defined('QBIX_SERVER_DIR') ? QBIX_SERVER_DIR : dirname(__DIR__);
		return $base . '/' . $relative;
	}
}

// ── PID file ───────────────────────────────────────
if ($opts['pid']) {
	file_put_contents($opts['pid'], getmypid());
	register_shutdown_function(function () use ($opts) {
		@unlink($opts['pid']);
	});
}

// ── Banner ─────────────────────────────────────────
$host = $opts['host'] ?: '127.0.0.1';
$port = $opts['port'] ?: 2525;

fwrite(STDERR, "\n");
fwrite(STDERR, "  ╔══════════════════════════════════╗\n");
fwrite(STDERR, "  ║       Qbix Relay v" . QBIX_RELAY_VERSION . "         ║\n");
fwrite(STDERR, "  ╚══════════════════════════════════╝\n");
fwrite(STDERR, "\n");
fwrite(STDERR, "  PID:    " . getmypid() . "\n");
fwrite(STDERR, "  SMTP:   $host:$port\n");
if ($opts['debug']) {
	fwrite(STDERR, "  Debug:  enabled\n");
}
fwrite(STDERR, "\n");

// ── Apply CLI overrides to config ──────────────────
if (class_exists('Q_Config', false)) {
	if ($opts['host']) {
		Q_Config::set('Q', 'relay', 'smtp', 'listenHost', $opts['host']);
	}
	if ($opts['port']) {
		Q_Config::set('Q', 'relay', 'smtp', 'listenPort', (int) $opts['port']);
	}
	if ($opts['debug']) {
		Q_Config::set('Q', 'relay', 'log', 'level', 'debug');
	}
}

// ── Run the relay ──────────────────────────────────
try {
	Q_Relay::run([
		'host'  => $host,
		'port'  => (int) $port,
		'debug' => $opts['debug'],
	]);
} catch (\Throwable $e) {
	fwrite(STDERR, "\n  FATAL: " . get_class($e) . ": " . $e->getMessage() . "\n");
	fwrite(STDERR, "  in " . $e->getFile() . ":" . $e->getLine() . "\n");
	fwrite(STDERR, "  " . $e->getTraceAsString() . "\n\n");
	exit(1);
}
