#!/usr/bin/env php
<?php
/**
 * Qbix Server — pure PHP web server.
 *
 * Standalone: php qbixserver.php
 * With Qbix:  php qbixserver.php --app=/path/to/myapp
 *
 * Options:
 *   --root=DIR       Document root (default: ./web)
 *   --app=DIR        Qbix app directory (loads full Q framework)
 *   --host=IP        Bind address (default: 0.0.0.0)
 *   --port=PORT      HTTP port (default: 80)
 *   --https-port=PORT HTTPS port (default: 443, only if certs available)
 *   --workers=N      Persistent workers (default: auto = nproc × 50)
 *   --config=FILE    JSON config file to load
 *   --pid=PATH       Write PID file
 *   --debug          Enable verbose logging
 *   --version        Print version and exit
 *   --help           Print usage and exit
 */

define('QBIX_SERVER_VERSION', '1.5.0');
define('QBIX_SERVER_DIR', __DIR__);

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
	'preset'  => null,  // Framework preset: laravel, symfony, wordpress, drupal
	'sign'    => null,  // Sign a directory: --sign=DIR --key=private.pem --key-id=NAME
	'verify'  => null,  // Verify a directory: --verify=DIR
	'key'     => null,  // Private key for signing
	'key-id'  => null,  // Key identifier for signing
	'generate-key' => null, // Generate keypair: --generate-key=NAME
	'policy'  => null,  // Set M-of-N policy: --policy=2
	'pid'     => null,
	'pack'    => null,  // --pack=DIR : bundle app files into binary
	'output'  => null,  // --output=FILE : output path for --pack
	'gui'     => false, // --gui : mark packed binary as GUI (no console window)
	'open'    => null,  // --open[=/path] : open browser when server is ready
	'debug'   => false,
);

foreach ($argv as $i => $arg) {
	if ($i === 0) continue;
	if ($arg === '--help' || $arg === '-h') {
		$me = basename($argv[0]);
		echo "Qbix Server v" . QBIX_SERVER_VERSION . "\n\n";
		echo "Usage: $me [options]\n\n";
		echo "Options:\n";
		echo "  --root=DIR       Document root (default: ./web)\n";
		echo "  --app=DIR        Qbix app directory (uses full Q framework)\n";
		echo "  --host=IP        Bind address (default: 0.0.0.0)\n";
		echo "  --port=PORT      HTTP port (default: 80)\n";
		echo "  --https-port=PORT HTTPS port (default: 443, if certs available)\n";
		echo "  --socket=PATH    Unix domain socket (e.g. /run/qbix/app.sock)\n";
		echo "  --socket-mode=MODE  Permissions on socket file (default: 0660)\n";
		echo "  --workers=N      Persistent workers (default: auto = nproc × 50)\n";
		echo "  --config=FILE    JSON config file\n";
		echo "  --preset=NAME    Framework preset (laravel, symfony, wordpress, drupal)\n";
		echo "  --pid=PATH       PID file path\n";
		echo "  --hotreload      Watch files, auto-restart on changes\n";
		echo "  --debug          Verbose logging\n";
		echo "  -t               Test config and exit\n";
		echo "  --stop           Graceful shutdown (via PID file)\n";
		echo "  --reload         Re-exec server (via PID file)\n";
		echo "  --deploy=TARGET  Deploy to remote server (from config/deploy.json)\n";
		echo "  --sign=DIR       Generate/sign a manifest for a directory\n";
		echo "  --verify=DIR     Verify a directory against its manifest\n";
		echo "  --generate-key=NAME  Generate ECDSA P-256 keypair for signing\n";
		echo "  --sign-binary    Sign this binary (M-of-N): --key=alice.pem --signer=Alice\n";
		echo "  --verify-binary  Verify binary signatures: --m=2 for M-of-N threshold\n";
		echo "  --pack=DIR       Bundle app files into this binary as a standalone\n";
		echo "  --output=FILE    Output path for --pack (default: ./myapp)\n";
		echo "  --gui            With --pack: mark binary as GUI app (no console on Windows)\n";
		echo "  --open[=/path]   Open browser when server is ready (default: /)\n";
		echo "  --version        Print version\n";
		echo "\nQuick start:\n";
		echo "  mkdir -p web && echo '<?php echo \"Hello!\";' > web/index.php\n";
		echo "  $me\n";
		echo "\nDocs: https://github.com/Qbix/webserver\n";
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
	if ($arg === '--gui') {
		$opts['gui'] = true;
		continue;
	}
	if ($arg === '--open') {
		$opts['open'] = '/';
		continue;
	}
	if ($arg === '--watchdog') {
		Q_Config::set('Q', 'webserver', 'watchdog', true);
		continue;
	}
	if ($arg === '--sign-binary' || strpos($arg, '--sign-binary=') === 0) {
		$opts['signBinary'] = true;
		continue;
	}
	if (strpos($arg, '--key=') === 0) {
		$opts['keys'][] = substr($arg, 6);
		continue;
	}
	if (strpos($arg, '--signer=') === 0) {
		$opts['signers'][] = substr($arg, 9);
		continue;
	}
	if (strpos($arg, '--verify-binary') === 0) {
		$opts['verifyBinary'] = true;
		continue;
	}
	if ($arg === '--publish-rekor') {
		$opts['publishRekor'] = true;
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

// ── Self-contained phar detection ─────────────────────────
// When running as micro.sfx + phar (single binary), the phar contains
// a web/ directory with the app's static files and PHP scripts.
// If --root isn't specified, prefer the phar's web/ over the disk's.
$pharRoot = Phar::running(false); // '' if not in a phar
$servingFromPhar = false;
$servingFromZip = false;
$appendedZipPath = null;

// ── Detect appended zip (redbean-like: cp binary myapp && cd www && zip -r ../myapp .) ──
// When running as a phpmicro binary, users can append a zip to the executable.
// The server detects it by scanning for the ZIP End-of-Central-Directory signature
// near the end of its own executable. If found, files are extracted to a temp dir
// and served as the web root.
$selfPath = $_SERVER['SCRIPT_FILENAME'] ?? ($argv[0] ?? '');
if ($selfPath && !$opts['root'] && !$opts['app']) {
	$selfReal = realpath($selfPath) ?: $selfPath;
	if (is_file($selfReal) && filesize($selfReal) > 100) {
		$fh = fopen($selfReal, 'rb');
		if ($fh) {
			$fsize = filesize($selfReal);
			$scanLen = min($fsize, 65557);
			fseek($fh, -$scanLen, SEEK_END);
			$tail = fread($fh, $scanLen);
			fclose($fh);
			$eocdSig = "\x50\x4b\x05\x06";
			$eocdPos = strrpos($tail, $eocdSig);
			if ($eocdPos !== false) {
				$eocdAbsolute = $fsize - $scanLen + $eocdPos;
				$eocd = substr($tail, $eocdPos, 22);
				$d = unpack('Vsig/vdisk/vdiskCD/ventriesDisk/ventries/VcdSize/VcdOffset/vcommentLen', $eocd);
				if ($d && $d['entries'] > 0) {
					$zipStart = $eocdAbsolute - $d['cdOffset'] - $d['cdSize'];
					// Only treat as appended if the zip doesn't start at byte 0
					// (that would mean the whole file is a zip, not an appended one)
					if ($zipStart > 1000) {
						// Extract the zip portion to a temp file
						$zipData = file_get_contents($selfReal, false, null, $zipStart);
						$tmpZip = sys_get_temp_dir() . '/qbix_app_' . md5($selfReal) . '.zip';
						file_put_contents($tmpZip, $zipData);
						if (class_exists('ZipArchive')) {
							$za = new ZipArchive();
							if ($za->open($tmpZip) === true) {
								// Extract to a temp directory
								$extractDir = sys_get_temp_dir() . '/qbix_app_' . md5($selfReal);
								@mkdir($extractDir, 0755, true);
								$za->extractTo($extractDir);
								$za->close();
								// Use web/ subdir if it exists, otherwise the extract root
								if (is_dir($extractDir . '/web')) {
									$webDir = $extractDir . '/web';
								} else {
									$webDir = $extractDir;
								}
								$servingFromZip = true;
								$appendedZipPath = $extractDir;
								fwrite(STDERR, "  Loaded " . $d['entries'] . " files from appended zip\n");
								// Register cleanup
								register_shutdown_function(function() use ($extractDir, $tmpZip) {
									// Clean up on normal exit (not on kill)
									@unlink($tmpZip);
								});
							}
						}
						if (!$servingFromZip) @unlink($tmpZip);
					}
				}
			}
		}
	}
}

if ($pharRoot && !$opts['root'] && !$qbixMode && !$servingFromZip) {
	$pharWebDir = 'phar://' . $pharRoot . '/web';
	if (is_dir($pharWebDir)) {
		$webDir = $pharWebDir;
		$servingFromPhar = true;
	}
}

// ── Data directory for packed binaries ────────────────
// When running as a packed binary (appended zip), store data next to
// the binary at <binary>.data/ instead of ./local/ which is fragile.
$dataDir = null;
if ($servingFromZip || $servingFromPhar) {
	$binaryPath = realpath($_SERVER['SCRIPT_FILENAME'] ?? $argv[0]) ?: $argv[0];
	$dataDir = preg_replace('/\.(exe|phar)$/i', '', $binaryPath) . '.data';
	if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
	// Override local/ paths to use the data directory
	if (is_dir($dataDir)) {
		define('QBIX_DATA_DIR', $dataDir);
		@mkdir("$dataDir/logs", 0755, true);
		@mkdir("$dataDir/certs", 0755, true);
		@mkdir("$dataDir/keys", 0755, true);

		// Auto-provision SQLite database from bundled seed
		$dbFile = __DIR__ . '/src/Q/WebServer/Database.php';
		if (is_file($dbFile)) {
			require_once $dbFile;
			$appRoot = $appendedZipPath ?: dirname($webDir);
			$framework = null;
			$ahFile = __DIR__ . '/src/Q/WebServer/Autohost.php';
			if (is_file($ahFile)) {
				require_once $ahFile;
				$framework = Q_WebServer_Autohost::detectFramework($appRoot);
			}
			Q_WebServer_Database::provision($appRoot, $dataDir, $framework);
		}
	}
}

/**
 * Resolve a path relative to the data directory.
 * For packed binaries: <binary>.data/
 * For normal installs: local/ or the provided path as-is.
 */
function qbix_data_path($relativePath) {
	if (defined('QBIX_DATA_DIR')) {
		return QBIX_DATA_DIR . '/' . ltrim($relativePath, '/');
	}
	return $relativePath;
}

// ── Validate document root early ────────────────────────
// Check this BEFORE loading Q shim, because if neither the root
// nor src/Q.php exist, the user just downloaded the binary and
// ran it in an empty directory — show them how to get started.
if (!$servingFromPhar) {
	$resolvedWebDir = realpath($webDir);
	if (!$resolvedWebDir || !is_dir($resolvedWebDir)) {
		$target = $opts['root'] ?: './web';
		$me = basename($argv[0]);
		fwrite(STDERR, "\n");
		fwrite(STDERR, "  No document root found at: $target\n\n");
		fwrite(STDERR, "  Quick start:\n\n");
		fwrite(STDERR, "    mkdir -p web && echo '<?php echo \"Hello from Qbix Server!\";' > web/index.php\n");
		fwrite(STDERR, "    $me\n\n");
		fwrite(STDERR, "  Or point to an existing project:\n\n");
		fwrite(STDERR, "    $me --root=/path/to/your/public\n\n");
		fwrite(STDERR, "  Framework shortcuts:\n\n");
		fwrite(STDERR, "    $me --root=public --preset=laravel\n");
		fwrite(STDERR, "    $me --root=public --preset=symfony\n");
		fwrite(STDERR, "    $me --root=.     --preset=wordpress\n\n");
		fwrite(STDERR, "  Docs: https://github.com/Qbix/webserver\n\n");
		exit(1);
	}
	$webDir = $resolvedWebDir;
}

if (!$qbixMode) {
	// Standalone mode — load minimal Q shim
	if ($pharRoot) {
		// Phar bundles files under src/ to match the repo layout
		$pharQ = 'phar://' . $pharRoot . '/src/Q.php';
		if (!file_exists($pharQ)) {
			$pharQ = 'phar://' . $pharRoot . '/Q.php';
		}
		require_once $pharQ;
	} else {
		$shimPath = __DIR__ . '/src/Q.php';
		if (!file_exists($shimPath)) {
			fwrite(STDERR, "Error: src/Q.php not found. Run from the webserver repo directory,\n");
			fwrite(STDERR, "or use a self-contained binary from https://github.com/Qbix/webserver/releases\n");
			exit(1);
		}
		require_once $shimPath;
	}
}

// realpath doesn't work on phar:// paths
if ($servingFromPhar) {
	if (!is_dir($webDir)) {
		fwrite(STDERR, "Error: phar does not contain a web/ directory\n");
		exit(1);
	}
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

// Config from app directory (base config)
$appConfig = dirname($webDir) . '/config/server.json';
if (file_exists($appConfig)) {
	Q_Config::load($appConfig);
}

// User config file — loaded last so it overrides everything
if ($opts['config']) {
	Q_Config::load($opts['config']);
}

// Framework preset (--preset=laravel, etc.)
if ($opts['preset']) {
	require_once __DIR__ . '/src/Q/WebServer/Compat.php';
	Q_WebServer_Compat::loadPreset($opts['preset']);
}

// ── Trust: signing, verification, key generation ──
require_once __DIR__ . '/src/Q/WebServer/Trust.php';

// --generate-key=NAME → create ECDSA P-256 keypair and exit
if ($opts['generate-key']) {
	$name = $opts['generate-key'];
	$privPath = qbix_data_path("local/keys/{$name}.pem");
	$pubPath = qbix_data_path("local/keys/{$name}.pub.pem");
	@mkdir(dirname($privPath), 0755, true);
	if (Q_WebServer_Trust::generateKeypair($privPath, $pubPath, 'ec')) {
		fwrite(STDERR, "Generated ECDSA P-256 keypair:\n  Private: $privPath\n  Public:  $pubPath\n");
		fwrite(STDERR, "Share the .pub.pem with anyone who should verify your signatures.\n");
		fwrite(STDERR, "Keep the private .pem safe — it signs your code.\n");
	} else {
		fwrite(STDERR, "Failed to generate keypair. Check OpenSSL is available.\n");
	}
	exit(0);
}

// --sign=DIR --key=private.pem --key-id=NAME [--policy=N]
if ($opts['sign']) {
	$dir = realpath($opts['sign']);
	$keyPath = $opts['key'] ?? '';
	$keyId = $opts['key-id'] ?? '';
	if (!$dir || !is_dir($dir)) { fwrite(STDERR, "Directory not found: {$opts['sign']}\n"); exit(1); }

	$manifestPath = $dir . DIRECTORY_SEPARATOR . 'manifest.json';
	if (is_file($manifestPath) && $keyPath) {
		// Add signature to existing manifest
		$manifest = json_decode(file_get_contents($manifestPath), true);
		if (!$manifest) { fwrite(STDERR, "Invalid manifest.json\n"); exit(1); }
	} else {
		// Generate new manifest
		fwrite(STDERR, "Generating manifest for $dir...\n");
		$manifest = Q_WebServer_Trust::generateManifest($dir);
		fwrite(STDERR, "  " . count($manifest['files']) . " files hashed\n");
	}

	// Set policy if specified
	if ($opts['policy']) {
		$manifest['policy']['require'] = (int) $opts['policy'];
		fwrite(STDERR, "  Policy: require {$opts['policy']} signatures\n");
	}

	// Sign if key provided
	if ($keyPath && $keyId) {
		if (!is_file($keyPath)) { fwrite(STDERR, "Key not found: $keyPath\n"); exit(1); }
		$ok = Q_WebServer_Trust::signManifest($manifest, $keyPath, $keyId);
		if ($ok) {
			fwrite(STDERR, "  Signed by: $keyId\n");
		} else {
			fwrite(STDERR, "  Signing failed. Check your private key.\n"); exit(1);
		}
	}

	Q_WebServer_Trust::saveManifest($manifest, $dir);
	fwrite(STDERR, "  Saved: $manifestPath\n");
	$sigs = count($manifest['signatures']);
	$req = $manifest['policy']['require'] ?? 0;
	fwrite(STDERR, "  Signatures: $sigs" . ($req ? " (policy requires $req)" : "") . "\n");
	exit(0);
}

// --verify=DIR → verify and exit
if ($opts['verify']) {
	$dir = realpath($opts['verify']);
	if (!$dir) { fwrite(STDERR, "Directory not found: {$opts['verify']}\n"); exit(1); }
	Q_WebServer_Trust::init();
	$result = Q_WebServer_Trust::verifyDirectory($dir, basename($dir));
	if ($result['ok']) {
		$v = $result['verified'] ?? 0;
		$t = $result['total'] ?? 0;
		fwrite(STDERR, "✓ Verified: $v/$t files pass. " . ($result['skipped'] ?? false ? "(no manifest)" : "") . "\n");
		exit(0);
	} else {
		fwrite(STDERR, "✕ Verification failed:\n");
		foreach ($result['errors'] as $err) {
			fwrite(STDERR, "  - $err\n");
		}
		exit(1);
	}
}

// ── Sign binary (M-of-N) ──
if (!empty($opts['signBinary'])) {
	require_once __DIR__ . '/src/Q/WebServer/Trust.php';
	$selfPath = realpath($_SERVER['SCRIPT_FILENAME'] ?? $argv[0]);
	$keys = $opts['keys'] ?? [];
	$signers = $opts['signers'] ?? [];
	if (empty($keys)) {
		fwrite(STDERR, "Usage: --sign-binary --key=alice.pem [--key=bob.pem] [--signer=Alice]\n");
		exit(1);
	}
	if (count($keys) === 1) {
		$result = Q_WebServer_Trust::signBinary($selfPath, $keys[0], $signers[0] ?? null);
	} else {
		$result = null;
		foreach ($keys as $i => $k) {
			$result = Q_WebServer_Trust::signBinary($selfPath, $k, $signers[$i] ?? null);
		}
	}
	if ($result) {
		$n = count($result['signatures']);
		fwrite(STDERR, "Signed: {$result['binary_hash']}\n");
		fwrite(STDERR, "Signers: $n\n");
		foreach ($result['signatures'] as $s) {
			fwrite(STDERR, "  {$s['signer']} (key:{$s['key_id']})\n");
		}
		$sigPath = Q_WebServer_Trust::signaturesPath($selfPath);
		fwrite(STDERR, "Written: $sigPath\n");
	}
	exit(0);
}

// ── Verify binary signatures ──
if (!empty($opts['verifyBinary'])) {
	require_once __DIR__ . '/src/Q/WebServer/Trust.php';
	$selfPath = realpath($_SERVER['SCRIPT_FILENAME'] ?? $argv[0]);
	$m = isset($opts['m']) ? (int) $opts['m'] : null;
	$result = Q_WebServer_Trust::verifyBinary($selfPath, $m);
	fwrite(STDERR, "Binary: {$result['binary_hash']}\n");
	fwrite(STDERR, "Hash matches: " . ($result['hash_matches'] ? 'yes' : 'NO — binary was modified') . "\n");
	fwrite(STDERR, "Signatures: {$result['label']}\n");
	foreach ($result['details'] as $d) {
		$icon = $d['status'] === 'valid' ? '✓' : '✗';
		fwrite(STDERR, "  $icon {$d['signer']} ({$d['status']})\n");
	}
	fwrite(STDERR, "Result: " . ($result['valid'] ? 'VALID' : 'FAILED') . "\n");
	exit($result['valid'] ? 0 : 1);
}

// ── Publish to Rekor (optional transparency log) ──
if (!empty($opts['publishRekor'])) {
	require_once __DIR__ . '/src/Q/WebServer/Trust.php';
	$selfPath = realpath($_SERVER['SCRIPT_FILENAME'] ?? $argv[0]);
	fwrite(STDERR, "Publishing attestation to Sigstore Rekor...\n");
	$uuid = Q_WebServer_Trust::publishToRekor($selfPath);
	if ($uuid) {
		fwrite(STDERR, "Published! Rekor UUID: $uuid\n");
		fwrite(STDERR, "Verify: https://search.sigstore.dev/?uuid=$uuid\n");
	} else {
		fwrite(STDERR, "Failed to publish. Check that signatures.json exists and Rekor is reachable.\n");
	}
	exit($uuid ? 0 : 1);
}

// ── Pack: bundle app files into this binary ──
if ($opts['pack']) {
	$packDir = realpath($opts['pack']);
	if (!$packDir || !is_dir($packDir)) {
		fwrite(STDERR, "Directory not found: {$opts['pack']}\n");
		exit(1);
	}
	$selfPath = realpath($_SERVER['SCRIPT_FILENAME'] ?? $argv[0]);
	if (!$selfPath) {
		fwrite(STDERR, "Cannot determine own binary path\n");
		exit(1);
	}

	$isPhar = (bool) Phar::running(false);

	if ($isPhar) {
		// Running as a phar: use build-app.php approach (embed files into phar)
		$output = $opts['output'] ?? './app.phar';
		fwrite(STDERR, "Packing $packDir into $output (phar mode)...\n");
		if (ini_get('phar.readonly')) {
			fwrite(STDERR, "Error: phar.readonly is on. Run with: php -d phar.readonly=0 " . basename($selfPath) . " --pack=$packDir\n");
			exit(1);
		}
		$phar = new Phar($output);
		$phar->startBuffering();
		// Copy server files from current phar
		$currentPhar = new Phar($selfPath);
		foreach (new RecursiveIteratorIterator($currentPhar) as $file) {
			$rel = str_replace('phar://' . $selfPath . '/', '', $file->getPathname());
			if (strpos($rel, 'web/') === 0) continue; // skip default web/
			$phar->addFromString($rel, file_get_contents($file->getPathname()));
		}
		// Add app files
		$fileCount = 0;
		$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($packDir, FilesystemIterator::SKIP_DOTS)
		);
		foreach ($iter as $file) {
			$rel = substr($file->getPathname(), strlen($packDir) + 1);
			$rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
			$phar->addFile($file->getPathname(), $rel);
			$fileCount++;
		}
		$phar->setStub("#!/usr/bin/env php\n<?php Phar::mapPhar('qbixserver.phar');\nrequire 'phar://qbixserver.phar/qbixserver.php';\n__HALT_COMPILER();");
		$phar->stopBuffering();
		$sizeKb = round(filesize($output) / 1024);
		fwrite(STDERR, "Built: $output ({$sizeKb}KB, $fileCount app files)\n");
		fwrite(STDERR, "Run:   php $output\n");
		fwrite(STDERR, "Or:    spc micro:combine $output -O myapp && ./myapp\n");
	} else {
		// Running as a binary or plain PHP: append a zip
		$output = $opts['output'] ?? './myapp' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
		fwrite(STDERR, "Packing $packDir into $output (zip-append mode)...\n");
		copy($selfPath, $output);
		if (!class_exists('ZipArchive')) {
			fwrite(STDERR, "ZipArchive extension required for --pack\n");
			exit(1);
		}
		$tmpZip = sys_get_temp_dir() . '/qbix_pack_' . uniqid() . '.zip';
		$za = new ZipArchive();
		$za->open($tmpZip, ZipArchive::CREATE);
		$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($packDir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);
		$fileCount = 0;
		foreach ($iter as $file) {
			$relPath = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($packDir) + 1));
			if ($file->isDir()) {
				$za->addEmptyDir($relPath);
			} else {
				$za->addFile($file->getPathname(), $relPath);
				$fileCount++;
			}
		}
		$za->close();
		file_put_contents($output, file_get_contents($tmpZip), FILE_APPEND);
		@unlink($tmpZip);
		if (PHP_OS_FAMILY !== 'Windows') chmod($output, 0755);

		// --gui: create a launcher that hides the console window
		if ($opts['gui']) {
			if (PHP_OS_FAMILY === 'Windows') {
				$vbsPath = preg_replace('/\.exe$/i', '', $output) . '.vbs';
				$vbsContent = 'CreateObject("WScript.Shell").Run Chr(34) & Replace(WScript.ScriptFullName, ".vbs", ".exe") & Chr(34), 0';
				file_put_contents($vbsPath, $vbsContent);
				fwrite(STDERR, "Created: $vbsPath (double-click to run without console)\n");
			}
			// Bake open=/ into the config so the browser auto-opens
			// The packed binary will check Q.webserver.open at startup
		}

		$sizeKb = round(filesize($output) / 1024);
		fwrite(STDERR, "Built: $output ({$sizeKb}KB, $fileCount app files)\n");
		fwrite(STDERR, "Run:   " . (PHP_OS_FAMILY === 'Windows' ? $output : "./$output") . "\n");
	}
	exit(0);
}

// Initialize trust at startup (verify app and plugins)
if (Q_Config::get('Q', 'trust', 'enabled', false)) {
	Q_WebServer_Trust::init();
}

// Verify code integrity before loading (if trust is enabled)
if (Q_Config::get('Q', 'trust', 'enabled', false)) {
	// Verify the app directory
	$trustResult = Q_WebServer_Trust::verifyDirectory($webDir, 'app');
	if (!$trustResult['ok']) {
		fwrite(STDERR, "\n  ✕ Trust verification failed:\n");
		foreach ($trustResult['errors'] as $err) {
			fwrite(STDERR, "    - $err\n");
		}
		fwrite(STDERR, "\n  Server will not start with unverified code.\n");
		fwrite(STDERR, "  Fix the files, re-sign the manifest, or disable trust.\n\n");
		exit(1);
	} elseif (!empty($trustResult['manifest'])) {
		$v = $trustResult['verified'];
		$t = $trustResult['total'];
		fwrite(STDERR, "  Trust: ✓ $v/$t files verified\n");
	}
}

// Initialize framework compatibility layer if enabled
if (!Q_Config::get('Q', 'compat', 'skipSourceCodeTransform', false)) {
	if (!class_exists('Q_WebServer_Compat', false)) {
		require_once __DIR__ . '/src/Q/WebServer/Compat.php';
	}
	// Pre-warm transform cache in the parent process.
	// Fork children inherit via COW — zero per-request I/O.
	// Prewarm from the project root (parent of web root) so vendor/, app/,
	// src/ etc. are all cached. The web root is typically public/ or web/.
	$prewarmDir = Q_Config::get('Q', 'compat', 'prewarmDir', null)
		?: dirname($webDir);  // one level above --root
	if (!is_dir($prewarmDir)) $prewarmDir = $webDir;
	$prewarmCount = Q_WebServer_Compat::prewarm($prewarmDir);
	if ($prewarmCount > 0) {
		$stats = Q_WebServer_Compat::cacheStats();
		fwrite(STDERR, "  Compat: pre-warmed $prewarmCount files ("
			. $stats['transforms'] . " transformed, "
			. $stats['passthrough'] . " pass-through, "
			. round($stats['bytes'] / 1024) . "KB)\n");
	}
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
		// Kill the watchdog if it's running
		$watchdogPid = 'local/watchdog.pid';
		if (is_file($watchdogPid)) {
			$wPid = (int) trim(file_get_contents($watchdogPid));
			if ($wPid > 0) {
				if (function_exists('posix_kill')) {
					posix_kill($wPid, 15); // SIGTERM
				} elseif (PHP_OS_FAMILY === 'Windows') {
					exec("taskkill /PID $wPid /F 2>NUL");
				}
				@unlink($watchdogPid);
			}
		}
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

// Auto-detect workers if not specified
if (!$opts['workers'] && function_exists('pcntl_fork')) {
	$nproc = 1;
	$totalRAM = 0;
	if (is_file('/proc/cpuinfo')) {
		$nproc = max(1, (int) trim(shell_exec('nproc 2>/dev/null') ?: '1'));
		// /proc/meminfo reports in KB
		$memLine = shell_exec("grep MemTotal /proc/meminfo 2>/dev/null");
		if ($memLine && preg_match('/(\d+)/', $memLine, $m)) {
			$totalRAM = (int) $m[1] * 1024; // bytes
		}
	} elseif (PHP_OS_FAMILY === 'Darwin') {
		$nproc = max(1, (int) trim(shell_exec('sysctl -n hw.ncpu 2>/dev/null') ?: '1'));
		$totalRAM = (int) trim(shell_exec('sysctl -n hw.memsize 2>/dev/null') ?: '0');
	}

	$maxByCpu = $nproc * 200;

	if ($totalRAM > 0) {
		$reservedRAM = 1024 * 1024 * 1024; // 1GB for OS + PHP base + SQLite
		$availableRAM = max(0, $totalRAM - $reservedRAM);
		$perWorker = 200 * 1024; // ~200KB COW per worker (measured)
		$maxByRam = (int) ($availableRAM / $perWorker);
		$opts['workers'] = max(4, min($maxByRam, $maxByCpu));
	} else {
		$opts['workers'] = $nproc * 50; // fallback
	}
}

// ── System limits check ──────────────────────────────────────────────
// Workers need: 2 fds each (socket pair) + ~10 fds per worker for
// open files, plus the parent needs fds for the listen socket, logs, etc.
// Each worker is also a process, so nproc/maxproc limits apply.
if ($opts['workers'] > 0) {
	$needed = $opts['workers'];
	$neededFds = $needed * 2 + 128; // socket pairs + headroom
	$warnings = [];
	$fatal = false;

	// Read current soft limits
	$fdLimit = 0;
	$procLimit = 0;

	if (PHP_OS_FAMILY === 'Darwin') {
		// macOS: kern.maxprocperuid, kern.maxfilesperproc
		$procLimit = (int) trim(shell_exec('sysctl -n kern.maxprocperuid 2>/dev/null') ?: '0');
		$fdLimit = (int) trim(shell_exec('sysctl -n kern.maxfilesperproc 2>/dev/null') ?: '0');
		if (!$fdLimit) $fdLimit = (int) trim(shell_exec('ulimit -n 2>/dev/null') ?: '0');
		if (!$procLimit) $procLimit = (int) trim(shell_exec('ulimit -u 2>/dev/null') ?: '0');
	} else {
		// Linux: read soft limits from /proc/self/limits (more reliable than ulimit in scripts)
		$limitsFile = @file_get_contents('/proc/self/limits');
		if ($limitsFile) {
			if (preg_match('/Max open files\s+(\d+)/', $limitsFile, $m))
				$fdLimit = (int) $m[1];
			if (preg_match('/Max processes\s+(\d+)/', $limitsFile, $m))
				$procLimit = (int) $m[1];
		}
		if (!$fdLimit) $fdLimit = (int) trim(shell_exec('ulimit -n 2>/dev/null') ?: '0');
		if (!$procLimit) $procLimit = (int) trim(shell_exec('ulimit -u 2>/dev/null') ?: '0');
	}

	// Try to raise fd limit if needed
	if ($fdLimit > 0 && $neededFds > $fdLimit) {
		$raised = false;
		if (function_exists('posix_setrlimit') && defined('POSIX_RLIMIT_NOFILE')) {
			// Try to raise soft limit to hard limit
			$hard = 0;
			if (PHP_OS_FAMILY !== 'Darwin') {
				$limitsFile = $limitsFile ?? @file_get_contents('/proc/self/limits');
				if ($limitsFile && preg_match('/Max open files\s+\d+\s+(\d+)/', $limitsFile, $m))
					$hard = (int) $m[1];
			}
			$target = max($neededFds, 65536);
			if ($hard > 0 && $target <= $hard) {
				@posix_setrlimit(POSIX_RLIMIT_NOFILE, $target, $hard);
				// Re-read
				$newFd = (int) trim(shell_exec('ulimit -n 2>/dev/null') ?: '0');
				if ($newFd >= $neededFds) {
					$fdLimit = $newFd;
					$raised = true;
				}
			}
		}
		if (!$raised) {
			$warnings[] = [
				"File descriptor limit too low: {$fdLimit} (need ~{$neededFds} for {$needed} workers)",
				PHP_OS_FAMILY === 'Darwin'
					? "  sudo sysctl -w kern.maxfilesperproc=65536"
					: "  ulimit -n 65536\n  # Or add to /etc/security/limits.conf:\n  *  soft  nofile  65536\n  *  hard  nofile  65536"
			];
			$fatal = true;
		}
	}

	// Try to raise process limit if needed
	if ($procLimit > 0 && $needed > $procLimit - 64) { // leave 64 headroom
		$raised = false;
		if (function_exists('posix_setrlimit') && defined('POSIX_RLIMIT_NPROC')) {
			$target = max($needed + 256, 65536);
			@posix_setrlimit(POSIX_RLIMIT_NPROC, $target, $target);
			$newProc = (int) trim(shell_exec('ulimit -u 2>/dev/null') ?: '0');
			if ($newProc >= $needed + 64) {
				$procLimit = $newProc;
				$raised = true;
			}
		}
		if (!$raised) {
			$warnings[] = [
				"Process limit too low: {$procLimit} (need ~{$needed} workers + headroom)",
				PHP_OS_FAMILY === 'Darwin'
					? "  sudo sysctl -w kern.maxprocperuid=4096"
					: "  ulimit -u 65536\n  # Or add to /etc/security/limits.conf:\n  *  soft  nproc  65536\n  *  hard  nproc  65536"
			];
			$fatal = true;
		}
	}

	// Linux: check pid_max
	if (PHP_OS_FAMILY !== 'Darwin') {
		$pidMax = (int) @file_get_contents('/proc/sys/kernel/pid_max');
		if ($pidMax > 0 && $needed > $pidMax * 0.5) {
			$warnings[] = [
				"System PID limit is {$pidMax} — {$needed} workers would use over half",
				"  sudo sysctl -w kernel.pid_max=131072"
			];
			// Not fatal — just a warning
		}
	}

	if (!empty($warnings)) {
		fwrite(STDERR, "\n");
		foreach ($warnings as $w) {
			fwrite(STDERR, "  ⚠  {$w[0]}\n");
			fwrite(STDERR, "     Fix:\n{$w[1]}\n\n");
		}
		if ($fatal) {
			// Cap workers to what the system allows
			$safeFd = $fdLimit > 0 ? max(0, (int) (($fdLimit - 128) / 2)) : PHP_INT_MAX;
			$safeProc = $procLimit > 0 ? max(0, $procLimit - 64) : PHP_INT_MAX;
			$safeWorkers = max(4, min($safeFd, $safeProc));

			if ($safeWorkers < $opts['workers']) {
				fwrite(STDERR, "  Requested {$opts['workers']} workers but system limits allow ~{$safeWorkers}.\n");
				fwrite(STDERR, "  Start with {$safeWorkers} workers? [Y/n] ");
				$answer = trim(fgets(STDIN));
				if ($answer !== '' && strtolower($answer[0]) !== 'y') {
					fwrite(STDERR, "  Aborted. Raise the limits above and try again.\n");
					exit(1);
				}
				$opts['workers'] = $safeWorkers;
			}
		}
	}
}

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
$rootLabel = $servingFromPhar ? 'web (phar)' : ($servingFromZip ? 'web (appended zip)' : basename($webDir));
fwrite(STDERR, "  │" . str_pad("  Root: " . $rootLabel, $W) . "│\n");
fwrite(STDERR, "  │" . str_pad("  Mode: " . ($qbixMode ? 'Qbix Platform' : 'Standalone'), $W) . "│\n");
if (!Q_Config::get('Q', 'compat', 'skipSourceCodeTransform', false)) {
	$preset = $opts['preset'] ?: 'custom';
	fwrite(STDERR, "  │" . str_pad("  Compat: $preset (source transform active)", $W) . "│\n");
}
fwrite(STDERR, "  │" . str_pad("  PHP: " . ($opts['workers'] ? $opts['workers'] . ' workers' : 'in-process')
	. ' (' . (class_exists('Q_WebServer_Fork') ? Q_WebServer_Fork::method() : 'detecting...') . ')', $W) . "│\n");
$nClasses = count(get_declared_classes());
$nHandlers = property_exists('Q', 'preloadedHandlers') ? Q::$preloadedHandlers : 0;
$preloadLabel = $nHandlers > 0
	? "  Preloaded: {$nClasses} classes, {$nHandlers} handlers"
	: "  Preloaded: {$nClasses} classes (handlers: lazy)";
fwrite(STDERR, "  │" . str_pad($preloadLabel, $W) . "│\n");
fwrite(STDERR, "  ├" . str_repeat('─', $W) . "┤\n");
$evLoop = class_exists('Io\\Poll', false) ? 'epoll/kqueue (PHP 8.6 Io\\Poll)'
	: (class_exists('Revolt\\EventLoop') ? 'Revolt' : 'stream_select');
fwrite(STDERR, "  │" . str_pad("  Dashboard: /Q/dashboard", $W) . "│\n");
fwrite(STDERR, "  │" . str_pad("  Health:    /Q/health", $W) . "│\n");
fwrite(STDERR, "  │" . str_pad("  Docs:      /Q/docs", $W) . "│\n");
fwrite(STDERR, "  │" . str_pad("  I/O:       $evLoop", $W) . "│\n");
fwrite(STDERR, "  │" . str_pad("  Ctrl+C to stop", $W) . "│\n");
fwrite(STDERR, "  └" . str_repeat('─', $W) . "┘\n");
fwrite(STDERR, "\n");

// ── Open browser if requested ──
$openPath = $opts['open']
	?? Q_Config::get('Q', 'webserver', 'open', null);
if ($openPath !== null) {
	if ($openPath === true || $openPath === '' || $openPath === '1') {
		$openPath = '/';
	}
	$openUrl = "http://127.0.0.1:{$opts['port']}" . $openPath;
	if (PHP_OS_FAMILY === 'Windows') {
		pclose(popen("start \"\" " . escapeshellarg($openUrl), "r"));
	} elseif (PHP_OS_FAMILY === 'Darwin') {
		exec("open " . escapeshellarg($openUrl) . " >/dev/null 2>&1 &");
	} else {
		exec("xdg-open " . escapeshellarg($openUrl) . " >/dev/null 2>&1 &");
	}
	fwrite(STDERR, "  Opened: $openUrl\n\n");
}

// ── Watchdog — monitor and auto-restart on crash ──
$watchdogEnabled = Q_Config::get('Q', 'webserver', 'watchdog', null);
if ($watchdogEnabled) {
	require_once __DIR__ . '/src/Q/WebServer/Watchdog.php';
	$watchdogPid = Q_WebServer_Watchdog::start(getmypid(), $argv);
	if ($watchdogPid > 0) {
		fwrite(STDERR, "  Watchdog: PID $watchdogPid (auto-restart on crash)\n");
	}
}

// ── Cluster initialization ──
// If PEERS env or Q.cluster config is set, enable clustering
if (!$qbixMode) {
	require_once __DIR__ . '/src/Q/WebServer/Cluster.php';
	$clusterConfig = Q_Config::get('Q', 'cluster', array());
	$envPeers = getenv('PEERS') ?: '';
	if (!empty($clusterConfig['peers']) || $envPeers) {
		Q_WebServer_Cluster::init($clusterConfig);
		if (Q_WebServer_Cluster::isActive()) {
			$liveCount = count(Q_WebServer_Cluster::liveServers());
			fwrite(STDERR, "  Cluster: " . Q_WebServer_Cluster::self()
				. " ($liveCount servers)\n\n");
		}
	}
}

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
