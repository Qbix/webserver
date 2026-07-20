<?php
/**
 * @module Q
 */

/**
 * Web-based control panel for managing Qbix apps.
 *
 * Serves at /Q/panel. Provides:
 * - List/create/start/stop apps
 * - Run scripts (configure, install, urls, etc.) via web
 * - Open app folders in Finder/Explorer/VS Code
 * - Plugin management
 * - System info
 *
 * No CLI needed. Everything a normie needs to manage
 * their server from a browser.
 *
 * @class Q_WebServer_Panel
 */
class Q_WebServer_Panel
{
	/**
	 * Handle panel requests with authentication.
	 * First visitor sets a password. All subsequent requests require it.
	 * Password stored in APP_DIR/local/panel.json (gitignored).
	 * @method handle
	 * @static
	 * @param {resource} $client
	 * @param {array} $parsed
	 * @return {boolean} true if handled
	 */
	static function handle($client, $parsed)
	{
		$path = $parsed['path'];

		if ($path === '/Q/panel' || $path === '/Q/panel/') {
			Q_WebServer::sendResponse($client, 200,
				self::renderPanel($parsed), 'text/html; charset=utf-8');
			return true;
		}

		// API endpoints — require authentication
		if (strpos($path, '/Q/api/') === 0) {
			// Password setup endpoint — no auth needed
			$route = substr($path, 7);
			if ($route === 'auth/setup' || $route === 'auth/login') {
				$result = self::handleAuthApi($route, $parsed);
				Q_WebServer::sendResponse($client, $result['status'] ?? 200,
					json_encode($result), 'application/json');
				return true;
			}

			// All other API calls require a valid session token
			$authResult = self::checkAuth($parsed);
			if (!$authResult['ok']) {
				Q_WebServer::sendResponse($client, 401,
					json_encode($authResult), 'application/json');
				return true;
			}

			$result = self::handleApi($path, $parsed);
			Q_WebServer::sendResponse($client, $result['status'] ?? 200,
				json_encode($result), 'application/json');
			return true;
		}

		return false;
	}

	/**
	 * Get the panel config file path
	 */
	private static function panelConfigPath()
	{
		return defined('APP_DIR')
			? APP_DIR . '/local/panel.json'
			: sys_get_temp_dir() . '/qbix-panel.json';
	}

	/**
	 * Handle auth API endpoints
	 */
	private static function handleAuthApi($route, $parsed)
	{
		$configPath = self::panelConfigPath();
		$config = file_exists($configPath)
			? json_decode(file_get_contents($configPath), true)
			: array();

		$body = !empty($parsed['body'])
			? json_decode($parsed['body'], true)
			: array();

		if ($route === 'auth/setup') {
			// First-time setup: set password
			if (!empty($config['passwordHash'])) {
				return array('error' => 'Password already set. Use auth/login.',
					'needsSetup' => false);
			}
			$password = $body['password'] ?? '';
			if (strlen($password) < 6) {
				return array('error' => 'Password must be at least 6 characters');
			}
			$config['passwordHash'] = password_hash($password, PASSWORD_DEFAULT);
			$token = bin2hex(random_bytes(32));
			$config['sessions'][$token] = time() + 86400 * 7; // 7 day expiry
			file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
			@chmod($configPath, 0600);
			return array('ok' => true, 'token' => $token);
		}

		if ($route === 'auth/login') {
			if (empty($config['passwordHash'])) {
				return array('needsSetup' => true);
			}
			$password = $body['password'] ?? '';
			if (!password_verify($password, $config['passwordHash'])) {
				usleep(500000); // 500ms delay to slow brute force
				return array('error' => 'Wrong password', 'status' => 401);
			}
			// Issue session token
			$token = bin2hex(random_bytes(32));
			if (!isset($config['sessions'])) $config['sessions'] = array();
			// Clean expired sessions
			$now = time();
			foreach ($config['sessions'] as $t => $exp) {
				if ($exp < $now) unset($config['sessions'][$t]);
			}
			$config['sessions'][$token] = $now + 86400 * 7;
			file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
			return array('ok' => true, 'token' => $token);
		}

		return array('error' => 'Unknown auth endpoint');
	}

	/**
	 * Check if the request has a valid auth token
	 */
	private static function checkAuth($parsed)
	{
		$configPath = self::panelConfigPath();
		if (!file_exists($configPath)) {
			return array('ok' => false, 'needsSetup' => true,
				'error' => 'No password set. Call auth/setup first.');
		}
		$config = json_decode(file_get_contents($configPath), true);
		if (empty($config['passwordHash'])) {
			return array('ok' => false, 'needsSetup' => true,
				'error' => 'No password set. Call auth/setup first.');
		}

		// Check Authorization: Bearer <token> header
		$authHeader = $parsed['headers']['authorization'] ?? '';
		$token = '';
		if (strpos($authHeader, 'Bearer ') === 0) {
			$token = substr($authHeader, 7);
		}
		// Also check X-Panel-Token header
		if (empty($token)) {
			$token = $parsed['headers']['x-panel-token'] ?? '';
		}
		// Also check cookie
		if (empty($token)) {
			$token = $parsed['cookies']['Q_panel_token'] ?? '';
		}

		if (empty($token)) {
			return array('ok' => false, 'error' => 'No auth token provided');
		}

		$sessions = $config['sessions'] ?? array();
		$expiry = $sessions[$token] ?? 0;
		if ($expiry < time()) {
			return array('ok' => false, 'error' => 'Token expired or invalid');
		}

		return array('ok' => true);
	}

	static function handleApi($path, $parsed)
	{
		$route = substr($path, 7); // strip /Q/api/

		switch ($route) {
			case 'apps':
				return self::apiListApps();
			case 'apps/create':
				return self::apiCreateApp($parsed);
			case 'apps/configure':
				return self::apiRunScript($parsed, 'configure');
			case 'apps/install':
				return self::apiRunScript($parsed, 'install');
			case 'apps/open':
				return self::apiOpenFolder($parsed);
			case 'scripts':
				return self::apiListScripts($parsed);
			case 'scripts/run':
				return self::apiRunScript($parsed);
			case 'plugins':
				return self::apiListPlugins();
			case 'system':
				return self::apiSystemInfo();
			case 'auth/password':
				return self::apiChangePassword($parsed);
			case 'auth/logout':
				return self::apiLogout($parsed);
			default:
				return array('status' => 404, 'error' => 'Unknown endpoint');
		}
	}

	private static function apiChangePassword($parsed)
	{
		$body = !empty($parsed['body'])
			? json_decode($parsed['body'], true) : array();
		$configPath = self::panelConfigPath();
		$config = json_decode(file_get_contents($configPath), true);

		$oldPw = $body['oldPassword'] ?? '';
		$newPw = $body['newPassword'] ?? '';

		if (!password_verify($oldPw, $config['passwordHash'])) {
			return array('error' => 'Current password is wrong');
		}
		if (strlen($newPw) < 6) {
			return array('error' => 'New password must be at least 6 characters');
		}

		$config['passwordHash'] = password_hash($newPw, PASSWORD_DEFAULT);
		// Invalidate all other sessions
		$currentToken = $parsed['headers']['x-panel-token']
			?? $parsed['cookies']['Q_panel_token'] ?? '';
		$config['sessions'] = array();
		if ($currentToken) {
			$config['sessions'][$currentToken] = time() + 86400 * 7;
		}
		file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
		return array('ok' => true);
	}

	private static function apiLogout($parsed)
	{
		$configPath = self::panelConfigPath();
		$config = json_decode(file_get_contents($configPath), true);
		$token = $parsed['headers']['x-panel-token']
			?? $parsed['cookies']['Q_panel_token'] ?? '';
		if ($token && isset($config['sessions'][$token])) {
			unset($config['sessions'][$token]);
			file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
		}
		return array('ok' => true);
	}

	// ── Apps API ─────────────────────────────────────────

	static function apiListApps()
	{
		$appsDir = self::appsDir();
		$apps = array();
		if (!$appsDir || !is_dir($appsDir)) {
			return array('apps' => $apps, 'appsDir' => $appsDir);
		}

		foreach (scandir($appsDir) as $name) {
			if ($name[0] === '.' || !is_dir($appsDir . DS . $name)) continue;
			$appDir = $appsDir . DS . $name;
			$configFile = $appDir . DS . 'config' . DS . 'app.json';
			if (!file_exists($configFile)) continue;

			$config = json_decode(file_get_contents($configFile), true);
			$localConfig = null;
			$localFile = $appDir . DS . 'local' . DS . 'app.json';
			if (file_exists($localFile)) {
				$localConfig = json_decode(file_get_contents($localFile), true);
			}

			$appName = $config['Q']['app'] ?? $name;
			$plugins = $config['Q']['plugins'] ?? array();
			$configured = is_dir($appDir . DS . 'local');
			$url = $localConfig['Q']['web']['appRootUrl'] ?? '';

			$apps[] = array(
				'name' => $appName,
				'dir' => $appDir,
				'dirName' => $name,
				'plugins' => $plugins,
				'configured' => $configured,
				'url' => $url,
				'hasWeb' => is_dir($appDir . DS . 'web'),
			);
		}

		return array('apps' => $apps, 'appsDir' => $appsDir);
	}

	static function apiCreateApp($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$name = preg_replace('/[^A-Za-z0-9_]/', '', $body['name'] ?? '');
		if (!$name) return array('status' => 400, 'error' => 'App name required');

		$template = $body['template'] ?? 'MyApp';
		$appsDir = self::appsDir();
		$targetDir = $appsDir . DS . $name;

		if (file_exists($targetDir)) {
			return array('status' => 409, 'error' => "App '$name' already exists");
		}

		// Find template
		$templateDir = null;
		$candidates = array(
			$appsDir . DS . $template,
			dirname($appsDir) . DS . $template,
			Q_DIR . DS . '..' . DS . $template,
		);
		foreach ($candidates as $c) {
			if (is_dir($c) && file_exists($c . DS . 'config' . DS . 'app.json')) {
				$templateDir = realpath($c);
				break;
			}
		}
		if (!$templateDir) {
			return array('status' => 404, 'error' => "Template '$template' not found");
		}

		// Copy template
		self::copyDir($templateDir, $targetDir);

		// Rename references
		$oldName = basename($templateDir);
		self::renameInApp($targetDir, $oldName, $name);

		return array('created' => $name, 'dir' => $targetDir);
	}

	// ── Scripts API ──────────────────────────────────────

	static function apiListScripts($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$appName = $body['app'] ?? '';

		$scripts = array();

		// Platform scripts
		$platformScripts = Q_DIR . DS . 'scripts';
		if (is_dir($platformScripts)) {
			foreach (glob($platformScripts . DS . '*.php') as $f) {
				$scripts[] = array(
					'name' => basename($f, '.php'),
					'path' => $f,
					'scope' => 'platform'
				);
			}
		}

		// App scripts
		if ($appName) {
			$appDir = self::appsDir() . DS . $appName;
			$appScripts = $appDir . DS . 'scripts' . DS . 'Q';
			if (is_dir($appScripts)) {
				foreach (glob($appScripts . DS . '*.php') as $f) {
					$scripts[] = array(
						'name' => basename($f, '.php'),
						'path' => $f,
						'scope' => 'app'
					);
				}
			}
		}

		return array('scripts' => $scripts);
	}

	static function apiRunScript($parsed, $scriptName = null)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$appName = $body['app'] ?? '';
		$scriptName = $scriptName ?: ($body['script'] ?? '');
		$args = $body['args'] ?? array();

		if (!$appName || !$scriptName) {
			return array('status' => 400, 'error' => 'app and script required');
		}

		$appDir = self::appsDir() . DS . $appName;
		if (!is_dir($appDir)) {
			return array('status' => 404, 'error' => "App '$appName' not found");
		}

		$scriptPath = $appDir . DS . 'scripts' . DS . 'Q' . DS . $scriptName . '.php';
		if (!file_exists($scriptPath)) {
			return array('status' => 404, 'error' => "Script '$scriptName' not found");
		}

		// Run script as subprocess
		$argStr = '';
		foreach ($args as $k => $v) {
			if (is_numeric($k)) {
				$argStr .= ' ' . escapeshellarg($v);
			} else {
				$argStr .= ' --' . $k . '=' . escapeshellarg($v);
			}
		}

		$cmd = PHP_BINARY . ' ' . escapeshellarg($scriptPath) . $argStr . ' 2>&1';
		$output = array();
		$code = 0;
		exec($cmd, $output, $code);

		return array(
			'script' => $scriptName,
			'app' => $appName,
			'exitCode' => $code,
			'output' => implode("\n", $output)
		);
	}

	// ── Plugins API ──────────────────────────────────────

	static function apiListPlugins()
	{
		$plugins = array();
		$pluginsDir = Q_DIR . DS . 'plugins';
		if (!is_dir($pluginsDir)) {
			$pluginsDir = Q_DIR . DS . '..' . DS . 'plugins';
		}

		if (is_dir($pluginsDir)) {
			foreach (scandir($pluginsDir) as $name) {
				if ($name[0] === '.') continue;
				$pDir = $pluginsDir . DS . $name;
				if (!is_dir($pDir)) continue;

				$configFile = $pDir . DS . 'config' . DS . 'plugin.json';
				$config = file_exists($configFile)
					? json_decode(file_get_contents($configFile), true) : array();

				$plugins[] = array(
					'name' => $name,
					'dir' => $pDir,
					'hasConfig' => file_exists($configFile),
				);
			}
		}

		return array('plugins' => $plugins, 'pluginsDir' => $pluginsDir);
	}

	// ── System API ───────────────────────────────────────

	static function apiSystemInfo()
	{
		return array(
			'php' => PHP_VERSION,
			'os' => PHP_OS,
			'arch' => php_uname('m'),
			'extensions' => get_loaded_extensions(),
			'hasComposer' => self::which('composer') !== null,
			'hasNode' => self::which('node') !== null,
			'hasNpm' => self::which('npm') !== null,
			'hasPcntl' => function_exists('pcntl_fork'),
			'hasApcu' => function_exists('apcu_fetch'),
			'memoryLimit' => ini_get('memory_limit'),
			'platform' => defined('Q_DIR') ? Q_DIR : null,
		);
	}

	static function apiOpenFolder($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$dir = $body['dir'] ?? '';
		$editor = $body['editor'] ?? 'folder'; // folder, vscode, textmate

		if (!$dir || !is_dir($dir)) {
			return array('status' => 400, 'error' => 'Invalid directory');
		}

		$os = PHP_OS_FAMILY;
		switch ($editor) {
			case 'vscode':
				$cmd = 'code ' . escapeshellarg($dir);
				break;
			case 'textmate':
				$cmd = 'mate ' . escapeshellarg($dir);
				break;
			default: // open in file manager
				if ($os === 'Darwin') {
					$cmd = 'open ' . escapeshellarg($dir);
				} elseif ($os === 'Windows') {
					$cmd = 'explorer ' . escapeshellarg(str_replace('/', '\\', $dir));
				} else {
					$cmd = 'xdg-open ' . escapeshellarg($dir);
				}
		}

		exec($cmd . ' 2>&1 &');
		return array('opened' => $dir, 'editor' => $editor);
	}

	// ── Helpers ──────────────────────────────────────────

	static function appsDir()
	{
		// Apps directory: configurable, defaults to sibling of platform
		$dir = Q_Config::get('Q', 'webserver', 'panel', 'appsDir', null);
		if ($dir) return $dir;
		if (defined('APP_DIR')) return dirname(APP_DIR);
		if (defined('Q_DIR')) return dirname(Q_DIR);
		return null;
	}

	static function which($cmd)
	{
		$path = trim(shell_exec((PHP_OS_FAMILY === 'Windows' ? 'where' : 'which')
			. ' ' . escapeshellarg($cmd) . ' 2>/dev/null') ?? '');
		return $path ?: null;
	}

	static function copyDir($src, $dst)
	{
		$dir = opendir($src);
		@mkdir($dst, 0755, true);
		while (($file = readdir($dir)) !== false) {
			if ($file === '.' || $file === '..') continue;
			$srcPath = $src . DS . $file;
			$dstPath = $dst . DS . $file;
			if (is_dir($srcPath)) {
				self::copyDir($srcPath, $dstPath);
			} else {
				copy($srcPath, $dstPath);
			}
		}
		closedir($dir);
	}

	static function renameInApp($dir, $oldName, $newName)
	{
		// Rename in config/app.json
		$configFile = $dir . DS . 'config' . DS . 'app.json';
		if (file_exists($configFile)) {
			$content = file_get_contents($configFile);
			$content = str_replace($oldName, $newName, $content);
			file_put_contents($configFile, $content);
		}

		// Rename handler/class directories
		foreach (array('handlers', 'classes', 'views', 'text') as $sub) {
			$oldDir = $dir . DS . $sub . DS . $oldName;
			$newDir = $dir . DS . $sub . DS . $newName;
			if (is_dir($oldDir)) {
				rename($oldDir, $newDir);
			}
		}

		// Rename script directories
		$oldScripts = $dir . DS . 'scripts' . DS . $oldName;
		$newScripts = $dir . DS . 'scripts' . DS . $newName;
		if (is_dir($oldScripts)) {
			rename($oldScripts, $newScripts);
		}
	}

	// ── Panel HTML ───────────────────────────────────────

	static function renderPanel($parsed)
	{
		$host = $parsed['headers']['host'] ?? 'localhost:8080';
		$wsUrl = "ws://$host/Q/ws";
		// The panel HTML is too large for inline — load from file
		// or generate. For now, inline a functional SPA.
		return self::panelHtml($host, $wsUrl);
	}

	static function panelHtml($host, $wsUrl)
	{
		return <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Qbix Control Panel</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0a0b14;--sfc:rgba(22,24,40,.7);--sfc-solid:#161828;--bdr:rgba(255,255,255,.06);
--txt:#e1e4ed;--dim:#6b7089;--ac:#7c5cfc;--ac2:#a78bfa;--grn:#4ade80;--yel:#fbbf24;
--red:#f87171;--cyn:#22d3ee;--glow:rgba(124,92,252,.08)}
body{font-family:-apple-system,system-ui,'Segoe UI',sans-serif;
  background:var(--bg);color:var(--txt);font-size:14px;min-height:100vh;
  background-image:
    radial-gradient(ellipse 80% 60% at 20% 0%, rgba(124,92,252,.12) 0%, transparent 60%),
    radial-gradient(ellipse 60% 50% at 80% 100%, rgba(34,211,238,.06) 0%, transparent 50%);
  background-attachment:fixed}

/* ── Header ── */
.top{padding:16px 20px;display:flex;justify-content:space-between;align-items:center;
  background:rgba(10,11,20,.8);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
  border-bottom:1px solid var(--bdr);position:sticky;top:0;z-index:50}
.top h1{font-size:17px;color:#fff;font-weight:700;letter-spacing:-.3px}
.top h1 span{color:var(--ac);font-weight:800}
.status{display:flex;gap:6px;align-items:center;font-size:12px;font-weight:500;
  padding:4px 12px;border-radius:20px;background:rgba(34,197,94,.1);color:var(--grn)}
.status .pulse{width:6px;height:6px;border-radius:50%;background:var(--grn);
  animation:pulse 2s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

/* ── Tabs ── */
.tabs{display:flex;gap:0;padding:0 20px;background:rgba(22,24,40,.6);
  backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);
  border-bottom:1px solid var(--bdr);position:sticky;top:53px;z-index:40;
  overflow-x:auto;-webkit-overflow-scrolling:touch}
.tab{padding:13px 18px;cursor:pointer;font-size:13px;font-weight:600;color:var(--dim);
  border-bottom:2px solid transparent;white-space:nowrap;transition:color .15s;
  -webkit-tap-highlight-color:transparent}
.tab:hover{color:var(--txt)}.tab.active{color:var(--ac);border-bottom-color:var(--ac)}

/* ── Content ── */
.content{padding:20px;max-width:960px;margin:0 auto}

/* ── Cards (glass) ── */
.card{background:var(--sfc);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
  border:1px solid var(--bdr);border-radius:12px;padding:18px;margin-bottom:14px;
  box-shadow:0 2px 12px rgba(0,0,0,.2)}
.card h3{font-size:14px;font-weight:700;margin-bottom:10px;color:var(--txt)}

/* ── App rows ── */
.app-row{display:flex;align-items:center;gap:12px;padding:14px 16px;border-radius:10px;
  margin-bottom:6px;background:rgba(255,255,255,.02);border:1px solid transparent;
  transition:all .15s}
.app-row:hover{background:rgba(255,255,255,.04);border-color:var(--bdr)}
.dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.dot.on{background:var(--grn);box-shadow:0 0 8px rgba(74,222,128,.4)}
.dot.off{background:var(--dim)}
.app-name{font-weight:700;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.app-url{color:var(--dim);font-size:12px;font-family:'SF Mono',monospace;
  flex-shrink:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px}

/* ── Buttons ── */
.btn{padding:7px 16px;border-radius:8px;font-size:12px;font-weight:600;border:none;
  cursor:pointer;transition:all .15s;-webkit-tap-highlight-color:transparent;touch-action:manipulation}
.btn-sm{padding:5px 12px;font-size:11px;border-radius:6px}
.btn-primary{background:linear-gradient(135deg,var(--ac),var(--ac2));color:#fff;
  box-shadow:0 2px 8px rgba(124,92,252,.3)}
.btn-primary:hover{box-shadow:0 4px 16px rgba(124,92,252,.4);transform:translateY(-1px)}
.btn-primary:active{transform:translateY(0)}
.btn-ghost{background:rgba(255,255,255,.05);color:var(--txt);border:1px solid var(--bdr)}
.btn-ghost:hover{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.1)}
.btn-grn{background:rgba(34,197,94,.12);color:var(--grn);border:1px solid rgba(34,197,94,.15)}
.btn-grn:hover{background:rgba(34,197,94,.2)}
.btn-row{display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap}
.btn.disabled{opacity:.3;cursor:not-allowed;pointer-events:none}

/* ── Dialog (glass modal) ── */
.dialog-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);
  backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);
  display:flex;align-items:center;justify-content:center;z-index:100;padding:20px}
.dialog{background:var(--sfc-solid);border:1px solid var(--bdr);border-radius:16px;
  padding:28px;max-width:420px;width:100%;box-shadow:0 24px 48px rgba(0,0,0,.4)}
.dialog h3{font-size:17px;margin-bottom:10px;color:#fff}
.dialog p{font-size:14px;color:var(--dim);margin-bottom:20px;line-height:1.6}
.dialog .btn-row{justify-content:flex-end}

/* ── Forms ── */
input,select{background:rgba(255,255,255,.04);border:1px solid var(--bdr);color:var(--txt);
  padding:10px 14px;border-radius:8px;font-size:13px;width:100%;transition:border .15s;
  -webkit-appearance:none}
input:focus,select:focus{outline:none;border-color:var(--ac);box-shadow:0 0 0 3px var(--glow)}
.form-row{display:flex;gap:12px;margin-bottom:14px;align-items:center}
.form-row label{min-width:70px;font-size:12px;color:var(--dim);font-weight:600;letter-spacing:.3px}

/* ── Output console ── */
.output{background:rgba(0,0,0,.3);border:1px solid var(--bdr);border-radius:10px;padding:14px;
  font-family:'SF Mono','Fira Code',monospace;font-size:12px;line-height:1.6;
  white-space:pre-wrap;max-height:300px;overflow-y:auto;color:var(--grn);margin-top:14px;
  -webkit-overflow-scrolling:touch}

/* ── Grid ── */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.stat-val{font-size:20px;font-weight:700;margin-bottom:2px;letter-spacing:-.3px}
.stat-lbl{font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px}
.hidden{display:none}

/* ── Suggestion cards ── */
.suggest{display:flex;gap:10px;align-items:center;padding:12px 16px;border-radius:10px;
  margin-bottom:8px;cursor:pointer;transition:all .15s;-webkit-tap-highlight-color:transparent}
.suggest:hover{transform:translateY(-1px)}
.suggest-icon{font-size:22px;flex-shrink:0;width:36px;height:36px;border-radius:8px;
  display:flex;align-items:center;justify-content:center}
.suggest-body{flex:1;min-width:0}
.suggest-title{font-size:13px;font-weight:700;margin-bottom:2px}
.suggest-desc{font-size:12px;line-height:1.4}
.suggest-action{flex-shrink:0;font-size:11px;font-weight:700;padding:5px 12px;border-radius:6px}
.suggest-hotspot{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.12)}
.suggest-hotspot .suggest-icon{background:rgba(34,197,94,.12)}
.suggest-hotspot .suggest-title{color:var(--grn)}
.suggest-hotspot .suggest-desc{color:rgba(34,197,94,.6)}
.suggest-hotspot .suggest-action{background:rgba(34,197,94,.15);color:var(--grn)}
.suggest-app{background:rgba(124,92,252,.06);border:1px solid rgba(124,92,252,.1)}
.suggest-app .suggest-icon{background:rgba(124,92,252,.12)}
.suggest-app .suggest-title{color:var(--ac2)}
.suggest-app .suggest-desc{color:rgba(167,139,250,.5)}
.suggest-app .suggest-action{background:rgba(124,92,252,.15);color:var(--ac2)}
.suggest-warn{background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.1)}
.suggest-warn .suggest-icon{background:rgba(245,158,11,.12)}
.suggest-warn .suggest-title{color:var(--yel)}
.suggest-warn .suggest-desc{color:rgba(245,158,11,.5)}
.suggest-warn .suggest-action{background:rgba(245,158,11,.15);color:var(--yel)}

/* ── Responsive ── */
@media(max-width:768px){
  .top{padding:14px 16px}
  .top h1{font-size:15px}
  .tabs{padding:0 12px;gap:0}
  .tab{padding:12px 14px;font-size:12px}
  .content{padding:16px}
  .card{padding:14px;border-radius:10px}
  .app-row{flex-wrap:wrap;gap:8px;padding:12px}
  .app-name{width:100%;flex:none}
  .app-url{width:100%;flex:none;max-width:none;margin-top:-4px}
  .btn-row{width:100%;justify-content:flex-start;margin-top:4px}
  .form-row{flex-direction:column;gap:6px}
  .form-row label{min-width:0}
  .grid-2{grid-template-columns:1fr}
  .dialog{padding:20px;border-radius:12px}
}
@media(max-width:380px){
  .top h1{font-size:14px}
  .tab{padding:10px 10px;font-size:11px}
  .btn{padding:6px 12px;font-size:11px}
  .stat-val{font-size:17px}
}
/* safe area for notched phones */
@supports(padding-top: env(safe-area-inset-top)){
  .top{padding-top:calc(16px + env(safe-area-inset-top))}
  body{padding-bottom:env(safe-area-inset-bottom)}
}
</style></head><body>
<div class="top">
  <h1><span>Q</span>bix Server</h1>
  <div class="status"><span class="pulse"></span> Running</div>
</div>
<div class="tabs">
  <div class="tab active" onclick="showTab('apps')">Apps</div>
  <div class="tab" onclick="showTab('scripts')">Scripts</div>
  <div class="tab" onclick="showTab('plugins')">Plugins</div>
  <div class="tab" onclick="showTab('system')">System</div>
</div>

<!-- APPS TAB -->
<div id="tab-apps" class="content">
  <!-- Suggestions -->
  <div id="suggestions" style="margin-bottom:16px"></div>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <h2 style="font-size:16px">Your Apps</h2>
    <button class="btn btn-primary" onclick="showCreate()">+ New App</button>
  </div>
  <div id="create-form" class="card hidden">
    <h3>Create New App</h3>
    <div class="form-row"><label>Name</label><input id="new-name" placeholder="MyNewApp (alphanumeric)"></div>
    <div class="form-row"><label>Template</label>
      <select id="new-template"><option>MyApp</option><option>SimpleHostedPHP</option></select>
    </div>
    <div class="btn-row"><button class="btn btn-primary" onclick="createApp()">Create</button>
    <button class="btn btn-ghost" onclick="hideCreate()">Cancel</button></div>
  </div>
  <div id="apps-list"></div>
</div>

<!-- SCRIPTS TAB -->
<div id="tab-scripts" class="content hidden">
  <h2 style="font-size:16px;margin-bottom:16px">Run Scripts</h2>
  <div class="card">
    <div class="form-row"><label>App</label><select id="script-app" onchange="loadScripts()"></select></div>
    <div class="form-row"><label>Script</label><select id="script-name"></select></div>
    <div class="form-row"><label>Args</label><input id="script-args" placeholder="--all or --plugins --composer"></div>
    <button class="btn btn-primary" onclick="runScript()">Run</button>
    <div id="script-output" class="output hidden"></div>
  </div>
  <div class="card" style="margin-top:16px">
    <h3>Common tasks</h3>
    <div class="btn-row" style="flex-wrap:wrap;gap:8px;margin-top:8px">
      <button class="btn btn-ghost" onclick="quickScript('configure')">Configure</button>
      <button class="btn btn-ghost" onclick="quickScript('install','--all')" id="btn-install-all">Install All</button>
      <button class="btn btn-ghost" onclick="quickScript('install','--plugins --composer')">Install Plugins</button>
      <button class="btn btn-ghost" onclick="quickScript('urls')">Rebuild URLs</button>
      <button class="btn btn-ghost" id="btn-npm" onclick="requireNode(function(){quickScript('install','--npm')})">Install npm packages</button>
      <button class="btn btn-ghost" id="btn-bundle" onclick="requireNode(function(){quickScript('bundle')})">Bundle JS/CSS</button>
    </div>
  </div>
</div>

<!-- PLUGINS TAB -->
<div id="tab-plugins" class="content hidden">
  <h2 style="font-size:16px;margin-bottom:16px">Installed Plugins</h2>
  <div id="plugins-list"></div>
</div>

<!-- SYSTEM TAB -->
<div id="tab-system" class="content hidden">
  <h2 style="font-size:16px;margin-bottom:16px">System Info</h2>
  <div class="grid-2" id="system-info"></div>
</div>

<script>
const API = '/Q/api';
let hasNode = false;
let hasComposer = false;
let authToken = null;

// ── Auth ─────────────────────────────────────────────

function getToken() {
  if (authToken) return authToken;
  try { authToken = sessionStorage.getItem('Q_panel_token'); } catch(e) {}
  return authToken;
}
function setToken(t) {
  authToken = t;
  try { sessionStorage.setItem('Q_panel_token', t); } catch(e) {}
  // Also set as cookie for WebSocket auth
  document.cookie = 'Q_panel_token=' + t + '; path=/; SameSite=Strict';
}

async function api(path, body) {
  var headers = {'Content-Type':'application/json'};
  var t = getToken();
  if (t) headers['X-Panel-Token'] = t;
  var r = await fetch(API+'/'+path, body
    ? {method:'POST', headers:headers, body:JSON.stringify(body)}
    : {headers:headers});
  var data = await r.json();
  if (data.error && (data.needsSetup || r.status === 401)) {
    showAuthScreen(data.needsSetup);
    throw new Error('auth');
  }
  return data;
}

function showAuthScreen(isSetup) {
  var main = document.getElementById('main-content');
  if (!main) {
    // Wrap everything after tabs in a container
    var tabs = document.querySelector('.tabs');
    var els = [];
    var sib = tabs.nextElementSibling;
    while (sib) { els.push(sib); sib = sib.nextElementSibling; }
    main = document.createElement('div');
    main.id = 'main-content';
    els.forEach(function(el) { main.appendChild(el); });
    tabs.parentNode.insertBefore(main, tabs.nextSibling);
  }
  main.style.display = 'none';
  document.querySelector('.tabs').style.display = 'none';

  var existing = document.getElementById('auth-screen');
  if (existing) existing.remove();

  var screen = document.createElement('div');
  screen.id = 'auth-screen';
  screen.className = 'content';
  screen.style.maxWidth = '380px';
  screen.style.margin = '40px auto';
  screen.innerHTML = '<div class="card">'
    + '<h3 style="margin-bottom:12px">' + (isSetup ? 'Set Panel Password' : 'Panel Login') + '</h3>'
    + (isSetup ? '<p style="font-size:13px;color:var(--dim);margin-bottom:16px">You\'re the first person to access this panel. Set a password to secure it.</p>' : '')
    + '<div class="form-row"><label>Password</label><input type="password" id="auth-pw" placeholder="' + (isSetup ? 'Choose a password (6+ chars)' : 'Enter password') + '"></div>'
    + (isSetup ? '<div class="form-row"><label>Confirm</label><input type="password" id="auth-pw2" placeholder="Confirm password"></div>' : '')
    + '<button class="btn btn-primary" onclick="doAuth(' + (isSetup ? 'true' : 'false') + ')" style="width:100%">' + (isSetup ? 'Set Password' : 'Login') + '</button>'
    + '<div id="auth-error" style="color:var(--red);font-size:13px;margin-top:8px;display:none"></div>'
    + '</div>';
  document.body.insertBefore(screen, document.querySelector('.tabs').nextSibling);

  // Enter key
  screen.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') doAuth(isSetup);
  });
  document.getElementById('auth-pw').focus();
}

async function doAuth(isSetup) {
  var pw = document.getElementById('auth-pw').value;
  var errEl = document.getElementById('auth-error');
  errEl.style.display = 'none';

  if (isSetup) {
    var pw2 = document.getElementById('auth-pw2').value;
    if (pw !== pw2) { errEl.textContent = 'Passwords don\'t match'; errEl.style.display = 'block'; return; }
    if (pw.length < 6) { errEl.textContent = 'Must be at least 6 characters'; errEl.style.display = 'block'; return; }
  }

  var endpoint = isSetup ? 'auth/setup' : 'auth/login';
  var r = await fetch(API + '/' + endpoint, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({password: pw})
  });
  var data = await r.json();
  if (data.error) {
    errEl.textContent = data.error;
    errEl.style.display = 'block';
    return;
  }
  if (data.token) {
    setToken(data.token);
    document.getElementById('auth-screen').remove();
    document.querySelector('.tabs').style.display = '';
    document.getElementById('main-content').style.display = '';
    initPanel();
  }
}

async function checkAuthAndInit() {
  try {
    // Quick auth check — system endpoint requires auth
    var r = await fetch(API + '/auth/login', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({})
    });
    var data = await r.json();
    if (data.needsSetup) {
      showAuthScreen(true);
      return;
    }
    // Has password — check if we have a valid token
    var t = getToken();
    if (!t) {
      showAuthScreen(false);
      return;
    }
    // Validate token by calling a real endpoint
    try { await api('system'); initPanel(); }
    catch (e) { /* showAuthScreen already called by api() */ }
  } catch (e) {
    showAuthScreen(false);
  }
}

function initPanel() {
  detectTools();
  loadApps();
}

// Node detection + suggestions
async function detectTools() {
  var d = await api('system');
  hasNode = d.hasNode;
  hasComposer = d.hasComposer;
  document.querySelectorAll('[id=btn-npm],[id=btn-bundle]').forEach(function(el) {
    el.classList.toggle('disabled', !hasNode);
  });
  renderSuggestions(d);
  return d;
}

function renderSuggestions(sys) {
  var el = document.getElementById('suggestions');
  if (!el) return;
  var html = '';
  var isIOS = /iPhone|iPad/.test(navigator.userAgent);
  var isAndroid = /Android/.test(navigator.userAgent);
  var isMobile = isIOS || isAndroid;

  if (isMobile) {
    html += '<div class="suggest suggest-hotspot" onclick="showHotspotTip()">'
      + '<div class="suggest-icon">' + String.fromCodePoint(0x1F4E1) + '</div><div class="suggest-body">'
      + '<div class="suggest-title">Share with nearby people</div>'
      + '<div class="suggest-desc">Create a Personal Hotspot so others can connect</div>'
      + '</div><div class="suggest-action">How &rarr;</div></div>';
  }
  if (isIOS) {
    html += '<a href="https://apps.apple.com/us/app/groups/id407855546" target="_blank" style="text-decoration:none">'
      + '<div class="suggest suggest-app"><div class="suggest-icon">' + String.fromCodePoint(0x1F465) + '</div><div class="suggest-body">'
      + '<div class="suggest-title">Get the Groups app</div>'
      + '<div class="suggest-desc">Community app with mesh networking</div>'
      + '</div><div class="suggest-action">App Store &rarr;</div></div></a>';
  } else if (isAndroid) {
    html += '<div class="suggest suggest-app" style="opacity:.6;cursor:default">'
      + '<div class="suggest-icon">' + String.fromCodePoint(0x1F465) + '</div><div class="suggest-body">'
      + '<div class="suggest-title">Groups for Android</div>'
      + '<div class="suggest-desc">Coming soon</div></div></div>';
  }
  if (!sys.hasNode) {
    html += '<div class="suggest suggest-warn" onclick="showNodeDialog()">'
      + '<div class="suggest-icon">' + String.fromCodePoint(0x26A0) + '</div><div class="suggest-body">'
      + '<div class="suggest-title">Node.js not installed</div>'
      + '<div class="suggest-desc">Optional &mdash; needed for npm and JS/CSS bundling</div>'
      + '</div><div class="suggest-action">Install &rarr;</div></div>';
  }
  el.innerHTML = html;
}

function showHotspotTip() {
  var isIOS = /iPhone|iPad/.test(navigator.userAgent);
  var steps = isIOS
    ? 'Open <b>Settings &rarr; Personal Hotspot</b> and turn it on.'
    : 'Open <b>Settings &rarr; Hotspot & tethering</b> and enable WiFi hotspot.';
  var overlay = document.createElement('div');
  overlay.className = 'dialog-overlay';
  overlay.onclick = function(e) { if (e.target === overlay) overlay.remove(); };
  overlay.innerHTML = '<div class="dialog"><h3>Share via Hotspot</h3>'
    + '<p>' + steps + ' Others connect to your hotspot, then scan the QR code to access your server.</p>'
    + '<p style="color:var(--dim);font-size:13px">Once someone connects, their device remembers it. Next time they auto-reconnect.</p>'
    + '<div class="btn-row"><button class="btn btn-ghost" onclick="this.closest(\'.dialog-overlay\').remove()">Got it</button></div></div>';
  document.body.appendChild(overlay);
}

function requireNode(callback) {
  if (hasNode) return callback();
  showNodeDialog();
}

function showNodeDialog() {
  var overlay = document.createElement('div');
  overlay.className = 'dialog-overlay';
  overlay.onclick = function(e) { if (e.target === overlay) overlay.remove(); };
  overlay.innerHTML = '<div class="dialog">'
    + '<h3>Node.js Required</h3>'
    + '<p>This action needs Node.js for npm package management and JS/CSS bundling. '
    + 'Install Node.js, then refresh this page — the buttons will activate automatically.</p>'
    + '<div class="btn-row">'
    + '<a href="https://nodejs.org/" target="_blank" class="btn btn-primary" '
    + 'style="text-decoration:none">Download Node.js ↗</a>'
    + '<button class="btn btn-ghost" onclick="this.closest(\'.dialog-overlay\').remove()">Cancel</button>'
    + '</div></div>';
  document.body.appendChild(overlay);
}

// Tabs
function showTab(name) {
  document.querySelectorAll('[id^=tab-]').forEach(function(el) { el.classList.add('hidden'); });
  document.getElementById('tab-'+name).classList.remove('hidden');
  document.querySelectorAll('.tab').forEach(function(el) { el.classList.remove('active'); });
  event.target.classList.add('active');
  if (name==='apps') loadApps();
  if (name==='plugins') loadPlugins();
  if (name==='system') loadSystem();
  if (name==='scripts') loadAppSelect();
}

// Apps
async function loadApps() {
  var d = await api('apps');
  var el = document.getElementById('apps-list');
  if (!d.apps || !d.apps.length) {
    el.innerHTML = '<div class="card"><p style="color:var(--dim)">No apps found. Create one to get started.</p></div>';
    return;
  }
  el.innerHTML = d.apps.map(function(a) { return ''
    + '<div class="app-row">'
    + '<span class="dot '+(a.configured?'on':'off')+'"></span>'
    + '<span class="app-name">'+a.name+'</span>'
    + '<span class="app-url">'+(a.url||'not configured')+'</span>'
    + '<div class="btn-row">'
    + (!a.configured ? '<button class="btn btn-sm btn-grn" onclick="configureApp(\''+a.dirName+'\')">Configure</button>' : '')
    + '<button class="btn btn-sm btn-ghost" onclick="openFolder(\''+a.dir+'\',\'folder\')">📂</button>'
    + '<button class="btn btn-sm btn-ghost" onclick="openFolder(\''+a.dir+'\',\'vscode\')">VS</button>'
    + '</div></div>';
  }).join('');
}

function showCreate(){document.getElementById('create-form').classList.remove('hidden')}
function hideCreate(){document.getElementById('create-form').classList.add('hidden')}
async function createApp() {
  var name = document.getElementById('new-name').value.trim();
  var template = document.getElementById('new-template').value;
  if (!name) return alert('Enter an app name');
  var r = await api('apps/create', {name:name, template:template});
  if (r.error) return alert(r.error);
  hideCreate();
  loadApps();
}
async function configureApp(name) {
  var r = await api('apps/configure', {app:name});
  alert(r.output||'Done');
  loadApps();
}
async function openFolder(dir, editor) {
  await api('apps/open', {dir:dir, editor:editor});
}

// Scripts
async function loadAppSelect() {
  var d = await api('apps');
  var sel = document.getElementById('script-app');
  sel.innerHTML = (d.apps||[]).map(function(a) {
    return '<option value="'+a.dirName+'">'+a.name+'</option>';
  }).join('');
  loadScripts();
}
async function loadScripts() {
  var app = document.getElementById('script-app').value;
  if (!app) return;
  var d = await api('scripts', {app:app});
  var sel = document.getElementById('script-name');
  sel.innerHTML = (d.scripts||[]).map(function(s) {
    return '<option value="'+s.name+'">'+s.name+' ('+s.scope+')</option>';
  }).join('');
}
async function runScript() {
  var app = document.getElementById('script-app').value;
  var script = document.getElementById('script-name').value;
  var args = document.getElementById('script-args').value.split(/\s+/).filter(Boolean);
  var out = document.getElementById('script-output');
  out.classList.remove('hidden');
  out.textContent = 'Running '+script+'...';
  var r = await api('scripts/run', {app:app, script:script, args:args});
  out.textContent = (r.output||'(no output)') + '\n\nExit code: '+(r.exitCode||'0');
}
function quickScript(name, args) {
  var app = document.getElementById('script-app').value;
  if (!app) return alert('Select an app first');
  document.getElementById('script-name').value = name;
  document.getElementById('script-args').value = args||'';
  runScript();
}

// Plugins
async function loadPlugins() {
  var d = await api('plugins');
  var el = document.getElementById('plugins-list');
  el.innerHTML = (d.plugins||[]).map(function(p) { return ''
    + '<div class="app-row">'
    + '<span class="dot on"></span>'
    + '<span class="app-name">'+p.name+'</span>'
    + '<div class="btn-row">'
    + '<button class="btn btn-sm btn-ghost" onclick="openFolder(\''+p.dir+'\',\'folder\')">📂</button>'
    + '</div></div>';
  }).join('');
}

// System
async function loadSystem() {
  var d = await detectTools();
  var el = document.getElementById('system-info');
  var items = [
    ['PHP', d.php], ['OS', d.os+' '+d.arch], ['Memory Limit', d.memoryLimit],
    ['pcntl', d.hasPcntl?'✅':'❌'], ['APCu', d.hasApcu?'✅':'❌'],
    ['Composer', d.hasComposer?'✅ installed':'❌ not found'],
    ['Node.js', d.hasNode?'✅ installed':'<span style="color:var(--red)">❌ not found</span> — <a href="https://nodejs.org/" target="_blank" style="color:var(--ac)">install</a>'],
    ['npm', d.hasNpm?'✅ installed':'❌ requires Node.js'],
  ];
  el.innerHTML = items.map(function(i) {
    return '<div class="card"><div class="stat-lbl">'+i[0]+'</div><div class="stat-val" style="font-size:16px">'+i[1]+'</div></div>';
  }).join('');
}

// Init
checkAuthAndInit();
</script></body></html>
HTML;
	}
}
