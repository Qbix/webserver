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
	/** @internal IP => [timestamps] for brute force protection */
	static $loginAttempts = array();
	/** @internal Currently served app dirName, or null */
	static $servingApp = null;
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

		// SECURITY: Panel is restricted to localhost by default.
		// Set Q.panel.remote = true in config to allow remote access.
		if (strpos($path, '/Q/panel') === 0 || strpos($path, '/Q/api/') === 0) {
			$allowRemote = Q_Config::get('Q', 'panel', 'remote', false);
			if (!$allowRemote) {
				$ip = $parsed['clientIp'] ?? $parsed['_remoteAddr'] ?? '';
				if ($ip !== '127.0.0.1' && $ip !== '::1' && $ip !== '') {
					Q_WebServer::sendResponse($client, 403,
						'Panel is restricted to localhost. Set Q.panel.remote = true in config to allow remote access.',
						'text/plain');
					return true;
				}
			}
		}

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
			$dir = dirname($configPath);
			if (!is_dir($dir)) @mkdir($dir, 0700, true);
			if (!is_dir(dirname($configPath))) @mkdir(dirname($configPath), 0700, true);
			$written = @file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
			if ($written === false) {
				return array('error' => 'Failed to write config to ' . $configPath);
			}
			@chmod($configPath, 0600);
			return array('ok' => true, 'token' => $token);
		}

		if ($route === 'auth/login') {
			if (empty($config['passwordHash'])) {
				return array('needsSetup' => true);
			}
			// SECURITY: brute force protection — block IP after 5 failed attempts
			$ip = $parsed['clientIp'] ?? $parsed['_remoteAddr'] ?? '0.0.0.0';
			$now = time();
			if (!isset(self::$loginAttempts[$ip])) {
				self::$loginAttempts[$ip] = array();
			}
			// Clean old attempts (older than 5 minutes)
			self::$loginAttempts[$ip] = array_filter(
				self::$loginAttempts[$ip],
				function ($t) use ($now) { return $t > $now - 300; }
			);
			if (count(self::$loginAttempts[$ip]) >= 5) {
				return array('error' => 'Too many attempts, try again later', 'status' => 429);
			}
			$password = $body['password'] ?? '';
			if (!password_verify($password, $config['passwordHash'])) {
				self::$loginAttempts[$ip][] = $now;
				return array('error' => 'Wrong password', 'status' => 401);
			}
			// Success — clear attempts
			unset(self::$loginAttempts[$ip]);
			// Issue session token
			$token = bin2hex(random_bytes(32));
			if (!isset($config['sessions'])) $config['sessions'] = array();
			// Clean expired sessions
			$now = time();
			foreach ($config['sessions'] as $t => $exp) {
				if ($exp < $now) unset($config['sessions'][$t]);
			}
			$config['sessions'][$token] = $now + 86400 * 7;
			if (!is_dir(dirname($configPath))) @mkdir(dirname($configPath), 0700, true);
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

	/**
	 * Validate a session token (for WebSocket auth, etc.)
	 * @method validateToken
	 * @static
	 * @param {string} $token
	 * @return {boolean}
	 */
	static function validateToken($token)
	{
		if (empty($token)) return false;
		$configPath = self::panelConfigPath();
		if (!file_exists($configPath)) return false;
		$config = json_decode(file_get_contents($configPath), true);
		$sessions = $config['sessions'] ?? array();
		return isset($sessions[$token]) && $sessions[$token] > time();
	}

	/**
	 * Check whether a panel password has been set
	 * @method hasPassword
	 * @static
	 * @return {boolean}
	 */
	static function hasPassword()
	{
		$configPath = self::panelConfigPath();
		if (!file_exists($configPath)) return false;
		$config = json_decode(file_get_contents($configPath), true);
		return !empty($config['passwordHash']);
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
			case 'apps/serve':
				return self::apiServeApp($parsed);
			case 'apps/setdir':
				return self::apiSetAppsDir($parsed);
			case 'scripts':
				return self::apiListScripts($parsed);
			case 'scripts/run':
				return self::apiRunScript($parsed);
			case 'plugins':
				return self::apiListPlugins();
			case 'plugins/add':
				return self::apiAddPlugin($parsed);
			case 'servers':
				return self::apiListServers();
			case 'servers/add':
				return self::apiAddServer($parsed);
			case 'servers/remove':
				return self::apiRemoveServer($parsed);
			case 'servers/deploy':
				return self::apiDeploy($parsed);
			case 'system':
				return self::apiSystemInfo();
			case 'auth/password':
				return self::apiChangePassword($parsed);
			case 'auth/logout':
				return self::apiLogout($parsed);
			case 'playground/run':
				return self::apiPlaygroundRun($parsed);
			case 'platform/install':
				return self::apiInstallPlatform($parsed);
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
			if (!is_dir(dirname($configPath))) @mkdir(dirname($configPath), 0700, true);
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
			if (!is_dir(dirname($configPath))) @mkdir(dirname($configPath), 0700, true);
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

			// Include any directory that has web/ or config/app.json
			$hasWeb = is_dir($appDir . DS . 'web');
			$configFile = $appDir . DS . 'config' . DS . 'app.json';
			$hasConfig = file_exists($configFile);
			if (!$hasWeb && !$hasConfig) continue;

			$config = $hasConfig
				? json_decode(file_get_contents($configFile), true)
				: array();
			$localConfig = null;
			$localFile = $appDir . DS . 'local' . DS . 'app.json';
			if (file_exists($localFile)) {
				$localConfig = json_decode(file_get_contents($localFile), true);
			}

			$appName = $config['Q']['app'] ?? $name;
			$plugins = $config['Q']['plugins'] ?? array();
			$configured = is_dir($appDir . DS . 'local');
			$url = $localConfig['Q']['web']['appRootUrl'] ?? '';
			$hasHandlers = is_dir($appDir . DS . 'handlers');
			$hasClasses = is_dir($appDir . DS . 'classes');
			$hasScripts = is_dir($appDir . DS . 'scripts');
			$isQbixApp = $hasConfig && isset($config['Q']);

			$apps[] = array(
				'name' => $appName,
				'dir' => $appDir,
				'dirName' => $name,
				'plugins' => $plugins,
				'configured' => $configured,
				'url' => $url,
				'hasWeb' => $hasWeb,
				'hasHandlers' => $hasHandlers,
				'hasClasses' => $hasClasses,
				'hasScripts' => $hasScripts,
				'isQbixApp' => $isQbixApp,
				'serving' => (self::$servingApp === $name),
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
			defined('Q_DIR') ? Q_DIR . DS . '..' . DS . $template : null,
		);
		foreach ($candidates as $c) {
			if (is_dir($c) && file_exists($c . DS . 'config' . DS . 'app.json')) {
				$templateDir = realpath($c);
				break;
			}
		}
		if (!$templateDir) {
			// No template found — create a minimal standalone app
			@mkdir($targetDir . DS . 'web', 0755, true);
			@mkdir($targetDir . DS . 'handlers', 0755, true);
			@mkdir($targetDir . DS . 'classes', 0755, true);
			@mkdir($targetDir . DS . 'config', 0755, true);

			file_put_contents($targetDir . DS . 'config' . DS . 'app.json',
				json_encode(array('Q' => array('app' => $name)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			file_put_contents($targetDir . DS . 'web' . DS . 'index.html',
				"<!DOCTYPE html>\n<html><head><title>{$name}</title></head>\n"
				. "<body><h1>{$name}</h1><p>Edit web/index.html to get started.</p></body></html>\n");

			return array('created' => $name, 'dir' => $targetDir);
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
		$platformScripts = defined('Q_DIR') ? Q_DIR . DS . 'scripts' : null;
		if ($platformScripts && is_dir($platformScripts)) {
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
		$platformDir = null;
		$pluginsDir = null;

		// 1. Find platform via local/paths.json
		if (defined('APP_DIR')) {
			$pathsFile = APP_DIR . DS . 'local' . DS . 'paths.json';
			if (file_exists($pathsFile)) {
				$paths = json_decode(file_get_contents($pathsFile), true);
				if (!empty($paths['platform'])) {
					$platformDir = realpath($paths['platform']);
				}
			}
		}
		if (!$platformDir && defined('Q_DIR')) {
			$platformDir = Q_DIR;
		}

		// 2. Read app's plugin list from config/app.json
		$appPlugins = array();
		if (defined('APP_DIR')) {
			$appConfig = APP_DIR . DS . 'config' . DS . 'app.json';
			if (file_exists($appConfig)) {
				$config = json_decode(file_get_contents($appConfig), true);
				$appPlugins = $config['Q']['plugins'] ?? array();
			}
		}

		// 3. Read installed versions from local/plugins.json
		$installedVersions = array();
		if (defined('APP_DIR')) {
			$localPlugins = APP_DIR . DS . 'local' . DS . 'plugins.json';
			if (file_exists($localPlugins)) {
				$lp = json_decode(file_get_contents($localPlugins), true);
				$installedVersions = $lp['Q']['pluginLocal'] ?? array();
			}
		}

		// 4. Find plugins directory
		if ($platformDir) {
			$pluginsDir = $platformDir . DS . 'plugins';
			if (!is_dir($pluginsDir)) {
				$pluginsDir = dirname($platformDir) . DS . 'plugins';
			}
		}

		// 5. Build plugin list — prefer app's declared plugins, fall back to scanning
		$pluginNames = !empty($appPlugins) ? $appPlugins : array();
		if (empty($pluginNames) && $pluginsDir && is_dir($pluginsDir)) {
			foreach (scandir($pluginsDir) as $name) {
				if ($name[0] === '.' || !is_dir($pluginsDir . DS . $name)) continue;
				$pluginNames[] = $name;
			}
		}

		foreach ($pluginNames as $name) {
			$pDir = $pluginsDir ? $pluginsDir . DS . $name : null;
			$configFile = $pDir ? $pDir . DS . 'config' . DS . 'plugin.json' : null;
			$pConfig = ($configFile && file_exists($configFile))
				? json_decode(file_get_contents($configFile), true) : null;

			$info = $installedVersions[$name] ?? array();
			$pluginInfo = $pConfig['Q']['pluginInfo'][$name] ?? array();

			$plugins[] = array(
				'name' => $name,
				'dir' => $pDir,
				'installed' => isset($info['version']),
				'version' => $info['version'] ?? $pluginInfo['version'] ?? null,
				'compatible' => $info['compatible'] ?? $pluginInfo['compatible'] ?? null,
				'requires' => $pluginInfo['requires'] ?? $info['requires'] ?? array(),
				'connections' => $pluginInfo['connections'] ?? $info['connections'] ?? array(),
				'hasConfig' => $configFile && file_exists($configFile),
				'inApp' => in_array($name, $appPlugins),
			);
		}

		return array(
			'plugins' => $plugins,
			'pluginsDir' => $pluginsDir,
			'platformDir' => $platformDir,
			'appPlugins' => $appPlugins,
		);
	}

	// ── System API ───────────────────────────────────────

	/**
	 * Run PHP code in an isolated forked child. 5 second timeout.
	 * The child has no filesystem write access and no network.
	 */
	/**
	 * Add a plugin by cloning from github.com/Qbix/{name}.
	 * If the repo is private or doesn't exist, returns {private: true}.
	 */
	// ── Server management ─────────────────────────────

	static function apiListServers()
	{
		$config = self::deployConfig();
		$servers = array();
		foreach (($config['targets'] ?? array()) as $name => $t) {
			$servers[] = array_merge(array('name' => $name), $t);
		}
		return array('servers' => $servers);
	}

	static function apiAddServer($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$name = preg_replace('/[^a-zA-Z0-9_-]/', '', $body['name'] ?? '');
		if (!$name) return array('status' => 400, 'error' => 'Name required');
		if (empty($body['host'])) return array('status' => 400, 'error' => 'Host required');

		$config = self::deployConfig();
		$config['targets'][$name] = array(
			'host' => $body['host'],
			'user' => $body['user'] ?? 'deploy',
			'path' => $body['path'] ?? '/var/www/' . $name,
			'key' => $body['key'] ?? '',
			'dirs' => array('web', 'handlers', 'classes', 'config'),
		);
		self::saveDeployConfig($config);
		return array('ok' => true, 'name' => $name);
	}

	static function apiRemoveServer($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$name = $body['name'] ?? '';
		$config = self::deployConfig();
		unset($config['targets'][$name]);
		self::saveDeployConfig($config);
		return array('ok' => true);
	}

	static function apiDeploy($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$target = $body['target'] ?? '';
		$config = self::deployConfig();
		$t = $config['targets'][$target] ?? null;
		if (!$t) return array('error' => "Unknown target: $target");

		$baseDir = defined('APP_DIR') ? APP_DIR : Q_WebServer::$rootDir . '..';
		$dirs = $t['dirs'] ?? array('web', 'handlers', 'classes', 'config');
		$sshKey = !empty($t['key']) ? " -e 'ssh -i " . escapeshellarg($t['key']) . "'" : '';
		$remote = $t['user'] . '@' . $t['host'] . ':' . rtrim($t['path'], '/') . '/';

		$total = 0;
		$log = '';
		foreach ($dirs as $dir) {
			$localDir = $baseDir . DS . $dir;
			if (!is_dir($localDir)) continue;
			$cmd = "rsync -avz --delete{$sshKey} "
				. escapeshellarg(rtrim($localDir, '/') . '/') . " "
				. escapeshellarg($remote . $dir . '/') . " 2>&1";
			$output = shell_exec($cmd);
			$log .= "rsync $dir/\n" . $output . "\n";
			$lines = array_filter(explode("\n", trim($output)), function ($l) {
				return $l && $l[0] !== '.' && substr($l, -1) !== '/'
					&& strpos($l, 'sending') === false && strpos($l, 'total') === false;
			});
			$total += count($lines);
		}

		return array('ok' => true, 'files' => $total, 'output' => $log);
	}

	private static function deployConfigPath()
	{
		$base = defined('APP_DIR') ? APP_DIR : dirname(Q_WebServer::$rootDir);
		return $base . DS . 'config' . DS . 'deploy.json';
	}

	private static function deployConfig()
	{
		$path = self::deployConfigPath();
		return file_exists($path) ? json_decode(file_get_contents($path), true) : array('targets' => array());
	}

	private static function saveDeployConfig($config)
	{
		$path = self::deployConfigPath();
		$dir = dirname($path);
		if (!is_dir($dir)) @mkdir($dir, 0755, true);
		file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Add a plugin by cloning from github.com/Qbix/{name}.
	 */
	static function apiAddPlugin($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$name = preg_replace('/[^A-Za-z0-9_-]/', '', $body['name'] ?? '');
		if (!$name) return array('status' => 400, 'error' => 'Plugin name required');

		// Find plugins directory
		$platformDir = null;
		if (defined('APP_DIR')) {
			$pathsFile = APP_DIR . DS . 'local' . DS . 'paths.json';
			if (file_exists($pathsFile)) {
				$paths = json_decode(file_get_contents($pathsFile), true);
				if (!empty($paths['platform'])) $platformDir = realpath($paths['platform']);
			}
		}
		if (!$platformDir && defined('Q_DIR')) $platformDir = Q_DIR;
		if (!$platformDir) {
			return array('error' => 'Qbix Platform not installed. Install it from the System tab first.');
		}

		$pluginsDir = $platformDir . DS . 'plugins';
		if (!is_dir($pluginsDir)) {
			$pluginsDir = dirname($platformDir) . DS . 'plugins';
		}
		if (!is_dir($pluginsDir)) {
			return array('error' => 'Plugins directory not found at ' . $pluginsDir);
		}

		$targetDir = $pluginsDir . DS . $name;
		if (is_dir($targetDir)) {
			return array('error' => "$name is already installed at $targetDir");
		}

		if (!self::which('git')) {
			return array('error' => 'git not found. Install git first.');
		}

		// Try to clone — test if accessible first with git ls-remote
		$testCmd = 'git ls-remote https://github.com/Qbix/' . escapeshellarg($name) . '.git HEAD 2>&1';
		$testOutput = shell_exec($testCmd);

		if (strpos($testOutput, 'fatal') !== false
			|| strpos($testOutput, 'not found') !== false
			|| strpos($testOutput, 'could not read') !== false
		) {
			return array('private' => true, 'name' => $name);
		}

		// Clone into plugins directory
		$cmd = 'cd ' . escapeshellarg($pluginsDir)
			. ' && git clone https://github.com/Qbix/' . escapeshellarg($name) . '.git'
			. ' ' . escapeshellarg($name) . ' 2>&1'
			. ' && cd ' . escapeshellarg($name)
			. ' && git submodule init 2>&1'
			. ' && git submodule update --recursive 2>&1';
		$output = shell_exec($cmd);

		if (!is_dir($targetDir)) {
			return array('error' => 'Clone failed', 'output' => $output);
		}

		return array('ok' => true, 'name' => $name, 'dir' => $targetDir, 'output' => $output);
	}

	static function apiPlaygroundRun($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$code = $body['code'] ?? '';
		if (!$code) return array('output' => '', 'ms' => 0);

		// Strip opening <?php tag if present
		$code = preg_replace('/^\s*<\?php\s*/i', '', $code);

		$start = microtime(true);

		// Build a wrapper that loads Q.php for access to Q::, Q_Config, etc.
		$qPath = dirname(dirname(__DIR__)) . DS . 'Q.php';
		$bootstrap = "error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);\n"
			. "require_once " . var_export($qPath, true) . ";\n";
		if (defined('APP_DIR')) {
			$bootstrap .= "if (method_exists('Q','init')) Q::init(" . var_export(APP_DIR, true) . ");\n";
		}
		$fullCode = "<?php\n" . $bootstrap . $code;

		// Write to temp file (safer than -r for complex code)
		$tmpFile = tempnam(sys_get_temp_dir(), 'qplay_');
		file_put_contents($tmpFile, $fullCode);

		$descriptors = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$cmd = PHP_BINARY . ' -d disable_functions=exec,shell_exec,system,passthru,popen,proc_open'
			. ',file_put_contents,fwrite,unlink,rmdir,mkdir,rename,chmod,chown'
			. ',curl_init,fsockopen,stream_socket_client'
			. ' -d disable_classes=SplFileObject'
			. ' -d open_basedir=' . escapeshellarg(sys_get_temp_dir() . ':' . dirname(dirname(__DIR__)))
			. ' -d memory_limit=32M -d max_execution_time=5'
			. ' ' . escapeshellarg($tmpFile);

		$proc = @proc_open($cmd, $descriptors, $pipes);
		if (!is_resource($proc)) {
			@unlink($tmpFile);
			return array('output' => '', 'error' => 'Failed to start process', 'ms' => 0);
		}
		fclose($pipes[0]);

		stream_set_timeout($pipes[1], 5);
		stream_set_timeout($pipes[2], 5);
		$output = stream_get_contents($pipes[1], 65536);
		$stderr = stream_get_contents($pipes[2], 65536);
		fclose($pipes[1]);
		fclose($pipes[2]);

		$exitCode = proc_close($proc);
		@unlink($tmpFile);
		$ms = round((microtime(true) - $start) * 1000, 1);

		// Filter out xdebug noise from stderr
		if ($stderr) {
			$stderr = preg_replace('/^Xdebug:.*\n?/m', '', $stderr);
			$stderr = preg_replace('/^Cannot load Xdebug.*\n?/m', '', $stderr);
			$stderr = trim($stderr);
		}

		$result = array('output' => $output, 'ms' => $ms);
		if ($stderr) $result['error'] = $stderr;
		if ($exitCode !== 0 && !$stderr) $result['error'] = "Exit code: $exitCode";

		return $result;
	}

	static function apiSystemInfo()
	{
		$platformDir = defined('Q_DIR') ? Q_DIR : null;
		if (!$platformDir && defined('APP_DIR')) {
			$pathsFile = APP_DIR . DS . 'local' . DS . 'paths.json';
			if (file_exists($pathsFile)) {
				$paths = json_decode(file_get_contents($pathsFile), true);
				if (!empty($paths['platform'])) {
					$platformDir = realpath($paths['platform']) ?: $paths['platform'];
				}
			}
		}
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
			'platform' => $platformDir,
			'appDir' => defined('APP_DIR') ? APP_DIR : null,
			'hasGit' => self::which('git') !== null,
		);
	}

	/**
	 * Clone Qbix Platform from GitHub and set up local/paths.json
	 */
	static function apiInstallPlatform($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$dir = $body['dir'] ?? '';
		if (!$dir) return array('status' => 400, 'error' => 'Directory required');

		// Safety: don't overwrite existing
		if (is_dir($dir) && file_exists($dir . DS . 'Q.php')) {
			return array('error' => 'Platform already exists at ' . $dir);
		}

		// Check git
		if (!self::which('git')) {
			return array('error' => 'git not found. Install git first.');
		}

		// Clone
		$parentDir = dirname($dir);
		if (!is_dir($parentDir)) {
			@mkdir($parentDir, 0755, true);
		}
		$dirName = basename($dir);
		$cmd = 'cd ' . escapeshellarg($parentDir)
			. ' && git clone https://github.com/Qbix/Platform.git '
			. escapeshellarg($dirName) . ' 2>&1'
			. ' && cd ' . escapeshellarg($dirName)
			. ' && git submodule init 2>&1'
			. ' && git submodule update --recursive 2>&1';
		$output = shell_exec($cmd);

		if (!is_dir($dir)) {
			return array('error' => 'Clone failed', 'output' => $output);
		}

		// Set up local/paths.json pointing to the platform
		if (defined('APP_DIR')) {
			$localDir = APP_DIR . DS . 'local';
			if (!is_dir($localDir)) @mkdir($localDir, 0755, true);
			$pathsFile = $localDir . DS . 'paths.json';
			$platformPath = realpath($dir) ?: $dir;
			// Use the platform subdirectory if it exists (Qbix convention)
			if (is_dir($platformPath . DS . 'platform')) {
				$platformPath = $platformPath . DS . 'platform';
			}
			$paths = file_exists($pathsFile)
				? json_decode(file_get_contents($pathsFile), true) : array();
			$paths['platform'] = $platformPath;
			file_put_contents($pathsFile, json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		}

		return array(
			'ok' => true,
			'dir' => realpath($dir),
			'output' => $output,
		);
	}

	static function apiServeApp($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$appName = preg_replace('/[^A-Za-z0-9_]/', '', $body['app'] ?? '');
		$enable = !empty($body['enable']);

		if (!$appName) return array('status' => 400, 'error' => 'App name required');

		$appsDir = self::appsDir();
		$appDir = $appsDir . DS . $appName;
		$webDir = $appDir . DS . 'web';

		if ($enable) {
			if (!is_dir($webDir)) {
				return array('status' => 404, 'error' => "No web/ directory in $appName");
			}
			$root = realpath($webDir);
			if (!$root) return array('status' => 500, 'error' => 'Cannot resolve path');
			Q_WebServer::$rootDir = rtrim(str_replace(array('/', '\\'), DS, $root), DS) . DS;
			self::$servingApp = $appName;
			return array('ok' => true, 'serving' => $appName, 'rootDir' => Q_WebServer::$rootDir);
		} else {
			if (defined('APP_DIR')) {
				$orig = APP_DIR . DS . 'web';
				if (is_dir($orig)) {
					Q_WebServer::$rootDir = rtrim(str_replace(array('/', '\\'), DS, realpath($orig)), DS) . DS;
				}
			}
			self::$servingApp = null;
			return array('ok' => true, 'serving' => null, 'rootDir' => Q_WebServer::$rootDir);
		}
	}

	/**
	 * Change the apps directory. Persisted in panel config.
	 */
	static function apiSetAppsDir($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$dir = $body['dir'] ?? '';
		if (!$dir || !is_dir($dir)) {
			return array('status' => 400, 'error' => 'Directory does not exist: ' . $dir);
		}
		// Persist in config
		Q_Config::set('Q', 'webserver', 'panel', 'appsDir', realpath($dir));
		// Also save to panel config file
		$configPath = self::panelConfigPath();
		$config = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : array();
		$config['appsDir'] = realpath($dir);
		$d = dirname($configPath);
		if (!is_dir($d)) @mkdir($d, 0700, true);
		@file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
		return array('ok' => true, 'appsDir' => realpath($dir));
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
		// 1. Explicit Q config
		$dir = Q_Config::get('Q', 'webserver', 'panel', 'appsDir', null);
		if ($dir && is_dir($dir)) return $dir;
		// 2. Saved in panel config file
		$configPath = self::panelConfigPath();
		if (file_exists($configPath)) {
			$config = json_decode(file_get_contents($configPath), true);
			if (!empty($config['appsDir']) && is_dir($config['appsDir'])) {
				return $config['appsDir'];
			}
		}
		// 3. Platform mode: parent of APP_DIR
		if (defined('APP_DIR')) return dirname(APP_DIR);
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
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark">
<title>Qbix Control Panel</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0a0b14;--sfc:rgba(22,24,40,.7);--sfc-solid:#161828;--bdr:rgba(255,255,255,.06);
--txt:#e1e4ed;--dim:#6b7089;--ac:#7c5cfc;--ac2:#a78bfa;--grn:#4ade80;--yel:#fbbf24;
--red:#f87171;--cyn:#22d3ee;--glow:rgba(124,92,252,.08)}
@media(prefers-color-scheme:light){:root{--bg:#f4f5f7;--sfc:rgba(255,255,255,.85);--sfc-solid:#fff;--bdr:rgba(0,0,0,.08);
--txt:#1a1a2e;--dim:#6b7089;--glow:rgba(124,92,252,.05)}}
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
.top h1 img{vertical-align:middle}
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
.btn-red{background:rgba(239,68,68,.12);color:var(--red);border:1px solid rgba(239,68,68,.15)}
.btn-red:hover{background:rgba(239,68,68,.2)}
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
  <h1><img src="/Q/logo.png" alt="" style="width:24px;height:auto;vertical-align:middle;margin-right:6px">Qbix Server</h1>
  <div class="status"><span class="pulse"></span> Running</div>
</div>
<div class="tabs">
  <div class="tab active" onclick="showTab('apps')">Apps</div>
  <div class="tab" onclick="showTab('scripts')">Scripts</div>
  <div class="tab" onclick="showTab('plugins')">Plugins</div>
  <div class="tab" onclick="showTab('playground')">Playground</div>
  <div class="tab" onclick="showTab('system')">System</div>
  <div class="tab" onclick="showTab('servers')">Servers</div>
</div>

<!-- APPS TAB -->
<div id="tab-apps" class="content">
  <!-- Suggestions -->
  <div id="suggestions" style="margin-bottom:16px"></div>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h2 style="font-size:16px">Your Apps</h2>
    <button class="btn btn-primary" onclick="showCreate()">+ New App</button>
  </div>
  <div id="apps-dir-row" style="font-size:12px;color:var(--dim);margin-bottom:14px">
    Scanning: <span id="apps-dir-path" style="cursor:pointer;border-bottom:1px dashed var(--dim)" onclick="editAppsDir()"></span>
    <span id="apps-dir-edit" class="hidden" style="margin-left:4px">
      <input id="apps-dir-input" style="font-size:12px;padding:2px 6px;width:260px;background:var(--card);border:1px solid var(--border);color:var(--txt);border-radius:4px">
      <button class="btn btn-sm btn-primary" onclick="saveAppsDir()" style="font-size:11px;padding:2px 8px">Save</button>
      <button class="btn btn-sm btn-ghost" onclick="cancelAppsDir()" style="font-size:11px;padding:2px 8px">✕</button>
    </span>
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
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
    <h2 style="font-size:16px">Plugins</h2>
    <button class="btn btn-primary" onclick="showAddPlugin()">+ Add Plugin</button>
  </div>
  <div id="add-plugin-form" class="card hidden" style="margin-bottom:14px">
    <h3>Add Plugin</h3>
    <div class="form-row"><label>Plugin name</label><input id="plugin-name" placeholder="e.g. Calendars, Communities, AI"></div>
    <div class="btn-row">
      <button class="btn btn-primary" onclick="addPlugin()">Install from GitHub</button>
      <button class="btn btn-ghost" onclick="hideAddPlugin()">Cancel</button>
    </div>
    <pre id="plugin-log" style="display:none;margin-top:10px;font-size:11px;color:var(--dim);max-height:200px;overflow:auto;background:rgba(0,0,0,.2);padding:8px;border-radius:4px"></pre>
  </div>
  <div id="plugin-private-dialog" class="card hidden" style="margin-bottom:14px;text-align:center;padding:24px">
    <div style="font-size:36px;margin-bottom:12px">🔒</div>
    <h3 style="margin-bottom:8px">Private Plugin</h3>
    <p style="color:var(--dim);font-size:13px;margin-bottom:16px">
      The <strong id="plugin-private-name"></strong> plugin is in a private repository.
      Contact the Qbix team to request access.
    </p>
    <div class="btn-row" style="justify-content:center">
      <a id="plugin-contact-link" href="#" class="btn btn-primary" target="_blank" style="text-decoration:none">✉ Contact Us</a>
      <button class="btn btn-ghost" onclick="hidePrivateDialog()">Close</button>
    </div>
  </div>
  <div id="plugins-platform" style="font-size:12px;color:var(--dim);margin-bottom:14px"></div>
  <div id="plugins-list"></div>
</div>

<!-- PLAYGROUND TAB -->
<div id="tab-playground" class="content hidden">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h2 style="font-size:16px">PHP Playground</h2>
    <div>
      <button class="btn btn-primary" onclick="runPlayground()" id="pg-run">▶ Run</button>
      <button class="btn btn-ghost" onclick="clearPlayground()">Clear</button>
    </div>
  </div>
  <p style="font-size:12px;color:var(--dim);margin-bottom:10px">
    Q classes are preloaded. Try <code style="font-size:11px">Q::app()</code>, <code style="font-size:11px">Q_Config::getAll()</code>, <code style="font-size:11px">Q_Request::url()</code>. Ctrl+Enter to run.
  </p>
  <div style="display:grid;grid-template-rows:1fr auto;gap:10px;min-height:400px">
    <div class="card" style="padding:0;overflow:hidden;display:flex;flex-direction:column">
      <div style="padding:6px 12px;font-size:11px;color:var(--dim);border-bottom:1px solid var(--border)">editor</div>
      <textarea id="pg-code" spellcheck="false" style="
        flex:1;width:100%;border:none;background:transparent;color:var(--txt);
        font-family:'SF Mono',Monaco,Consolas,monospace;font-size:13px;line-height:1.5;
        padding:12px;resize:none;outline:none;tab-size:4;
      "></textarea>
      <script>document.getElementById('pg-code').value="<?php\necho \"App: \" . Q::app() . \"\\n\";\necho \"Config: \" . json_encode(Q_Config::getAll(), JSON_PRETTY_PRINT) . \"\\n\";\n\n// Available classes:\n// Q, Q_Config, Q_Request, Q_Response, Q_Socket, Q_Room\necho \"\\nLoaded classes:\\n\";\nforeach (get_declared_classes() as $c) {\n    if (strpos($c, 'Q_') === 0) echo \"  $c\\n\";\n}";</script>
    </div>
    <div class="card" style="padding:0;overflow:hidden;display:flex;flex-direction:column;min-height:120px">
      <div style="padding:6px 12px;font-size:11px;color:var(--dim);border-bottom:1px solid var(--border)">
        output <span id="pg-time" style="float:right"></span>
      </div>
      <pre id="pg-output" style="
        flex:1;margin:0;padding:12px;font-size:13px;line-height:1.5;
        background:transparent;color:var(--grn);overflow:auto;white-space:pre-wrap;
      "></pre>
    </div>
  </div>
</div>

<!-- SYSTEM TAB -->
<div id="tab-system" class="content hidden">
  <h2 style="font-size:16px;margin-bottom:16px">System Info</h2>
  <div class="grid-2" id="system-info"></div>

  <div id="platform-install" style="margin-top:20px">
    <h2 style="font-size:16px;margin-bottom:12px">Qbix Platform</h2>
    <div id="platform-status" class="card"></div>
  </div>
</div>

<!-- SERVERS TAB -->
<div id="tab-servers" class="content hidden">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h2 style="font-size:16px">Remote Servers</h2>
    <button class="btn btn-primary" onclick="showAddServer()">+ Add Server</button>
  </div>
  <p style="font-size:12px;color:var(--dim);margin-bottom:14px">
    Deploy your app to remote Qbix servers, or federate events across nodes.
  </p>
  <div id="add-server-form" class="card hidden" style="margin-bottom:14px">
    <h3>Add Server</h3>
    <div class="form-row"><label>Name</label><input id="srv-name" placeholder="e.g. production, staging"></div>
    <div class="form-row"><label>Host</label><input id="srv-host" placeholder="myserver.com"></div>
    <div class="form-row"><label>User</label><input id="srv-user" placeholder="deploy" value="deploy"></div>
    <div class="form-row"><label>Path</label><input id="srv-path" placeholder="/var/www/myapp"></div>
    <div class="form-row"><label>SSH Key</label><input id="srv-key" placeholder="~/.ssh/deploy_key (optional)"></div>
    <div class="btn-row">
      <button class="btn btn-primary" onclick="saveServer()">Save</button>
      <button class="btn btn-ghost" onclick="hideAddServer()">Cancel</button>
    </div>
  </div>
  <div id="servers-list"></div>
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
    // If we were redirected here from another page, go back
    var next = new URLSearchParams(window.location.search).get('next');
    if (next) { window.location.href = next; return; }
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
  if (name==='servers') loadServers();
  if (name==='scripts') loadAppSelect();
}

// Apps
async function loadApps() {
  var d = await api('apps');
  var el = document.getElementById('apps-list');
  // Show appsDir
  document.getElementById('apps-dir-path').textContent = d.appsDir || '(not set)';
  if (!d.apps || !d.apps.length) {
    el.innerHTML = '<div class="card"><p style="color:var(--dim)">No apps found in this directory. Click + New App to create one.</p></div>';
    return;
  }
  el.innerHTML = d.apps.map(function(a) {
    var isServing = a.serving;
    var badges = [];
    if (a.hasWeb) badges.push('web');
    if (a.hasHandlers) badges.push('handlers');
    if (a.hasClasses) badges.push('classes');
    if (a.isQbixApp) badges.push('qbix');
    var badgeHtml = badges.map(function(b){return '<span style="font-size:10px;background:rgba(255,255,255,.06);padding:1px 5px;border-radius:3px;color:var(--dim)">'+b+'</span>'}).join(' ');
    var statusText = isServing ? '<span style="color:var(--grn)">serving on this port</span>'
      : (a.url ? a.url : (a.configured ? 'configured' : 'not configured'));
    return ''
    + '<div class="app-row">'
    + '<span class="dot '+(isServing?'on':(a.configured?'on':'off'))+'"></span>'
    + '<span class="app-name">'+a.name+'</span>'
    + '<span class="app-url">'+statusText+' '+badgeHtml+'</span>'
    + '<div class="btn-row">'
    + (a.hasWeb && !isServing ? '<button class="btn btn-sm btn-primary" onclick="serveApp(\''+a.dirName+'\',true)">Serve</button>' : '')
    + (isServing ? '<button class="btn btn-sm btn-red" onclick="serveApp(\''+a.dirName+'\',false)">Stop</button>' : '')
    + (a.isQbixApp && a.hasScripts && !a.configured ? '<button class="btn btn-sm btn-grn" onclick="configureApp(\''+a.dirName+'\',\''+a.name+'\')">Configure</button>' : '')
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
async function configureApp(dirName, appName) {
  var name = prompt('App name for configuration:', appName || dirName);
  if (!name) return;
  var r = await api('apps/configure', {app: dirName, name: name});
  if (r.error) alert(r.error);
  else if (r.output) alert(r.output);
  loadApps();
}
async function serveApp(name, enable) {
  var r = await api('apps/serve', {app:name, enable:enable});
  if (r.error) return alert(r.error);
  loadApps();
}
function editAppsDir() {
  document.getElementById('apps-dir-input').value = document.getElementById('apps-dir-path').textContent;
  document.getElementById('apps-dir-edit').classList.remove('hidden');
  document.getElementById('apps-dir-path').style.display = 'none';
  document.getElementById('apps-dir-input').focus();
}
function cancelAppsDir() {
  document.getElementById('apps-dir-edit').classList.add('hidden');
  document.getElementById('apps-dir-path').style.display = '';
}
async function saveAppsDir() {
  var dir = document.getElementById('apps-dir-input').value.trim();
  var r = await api('apps/setdir', {dir: dir});
  if (r.error) return alert(r.error);
  cancelAppsDir();
  loadApps();
}
async function openFolder(dir, editor) {
  await api('apps/open', {dir:dir, editor:editor});
}

// Playground
async function runPlayground() {
  var code = document.getElementById('pg-code').value;
  var outEl = document.getElementById('pg-output');
  var timeEl = document.getElementById('pg-time');
  var btn = document.getElementById('pg-run');
  btn.disabled = true; btn.textContent = '⏳ Running...';
  outEl.textContent = '';
  outEl.style.color = 'var(--grn)';
  timeEl.textContent = '';
  try {
    var r = await api('playground/run', {code: code});
    outEl.textContent = r.output || '(no output)';
    if (r.error) { outEl.textContent += '\n\n⚠ ' + r.error; outEl.style.color = 'var(--red)'; }
    if (r.ms) timeEl.textContent = r.ms + 'ms';
  } catch(e) {
    outEl.textContent = 'Error: ' + e.message;
    outEl.style.color = 'var(--red)';
  }
  btn.disabled = false; btn.textContent = '▶ Run';
}
function clearPlayground() {
  document.getElementById('pg-output').textContent = '';
  document.getElementById('pg-time').textContent = '';
}
// Ctrl+Enter to run
document.addEventListener('keydown', function(e) {
  if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && document.getElementById('tab-playground').style.display !== 'none') {
    e.preventDefault(); runPlayground();
  }
});

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
function showAddPlugin() {
  document.getElementById('add-plugin-form').classList.remove('hidden');
  document.getElementById('plugin-name').focus();
}
function hideAddPlugin() {
  document.getElementById('add-plugin-form').classList.add('hidden');
  document.getElementById('plugin-log').style.display = 'none';
}
function hidePrivateDialog() {
  document.getElementById('plugin-private-dialog').classList.add('hidden');
}
async function addPlugin() {
  var name = document.getElementById('plugin-name').value.trim();
  if (!name) return alert('Enter a plugin name');
  var log = document.getElementById('plugin-log');
  log.style.display = 'block';
  log.style.color = 'var(--dim)';
  log.textContent = 'Cloning https://github.com/Qbix/' + name + '...\n';
  try {
    var r = await api('plugins/add', {name: name});
    if (r.private) {
      hideAddPlugin();
      var d = document.getElementById('plugin-private-dialog');
      document.getElementById('plugin-private-name').textContent = name;
      var subject = encodeURIComponent('Access to ' + name + ' plugin');
      var body = encodeURIComponent('Hi Qbix team,\n\nI would like access to the ' + name + ' plugin for my project.\n\nThanks!');
      document.getElementById('plugin-contact-link').href = 'mailto:team@qbix.com?subject=' + subject + '&body=' + body;
      d.classList.remove('hidden');
    } else if (r.error) {
      log.textContent += '\n⚠ ' + r.error;
      log.style.color = 'var(--red)';
    } else {
      log.textContent += (r.output || '') + '\n✅ Installed!';
      log.style.color = 'var(--grn)';
      setTimeout(function() { hideAddPlugin(); loadPlugins(); }, 1500);
    }
  } catch(e) {
    log.textContent += '\nError: ' + e.message;
    log.style.color = 'var(--red)';
  }
}

async function loadPlugins() {
  var d = await api('plugins');
  var pEl = document.getElementById('plugins-platform');
  pEl.textContent = d.platformDir
    ? 'Platform: ' + d.platformDir + ' · ' + (d.plugins||[]).length + ' plugins'
    : 'No Qbix Platform detected. Plugins show when connected to a Platform app.';
  var el = document.getElementById('plugins-list');
  if (!d.plugins || !d.plugins.length) {
    el.innerHTML = '<div class="card"><p style="color:var(--dim)">No plugins found.</p></div>';
    return;
  }
  el.innerHTML = (d.plugins||[]).map(function(p) {
    var ver = p.version ? ' v' + p.version : '';
    var compat = p.compatible ? ' (compat: ' + p.compatible + ')' : '';
    var status = p.installed ? 'installed' : (p.inApp ? 'in config' : 'available');
    var deps = (p.requires && Object.keys(p.requires).length)
      ? '<div style="font-size:11px;color:var(--dim);margin-top:4px">requires: ' + Object.keys(p.requires).join(', ') + '</div>'
      : '';
    var conns = (p.connections && p.connections.length)
      ? '<div style="font-size:11px;color:var(--dim)">db: ' + p.connections.join(', ') + '</div>'
      : '';
    return ''
    + '<div class="app-row" style="flex-wrap:wrap">'
    + '<span class="dot '+(p.installed?'on':(p.inApp?'on':'off'))+'"></span>'
    + '<span class="app-name">'+p.name+ver+'</span>'
    + '<span class="app-url">'+status+compat+'</span>'
    + '<div class="btn-row">'
    + (p.dir ? '<button class="btn btn-sm btn-ghost" onclick="openFolder(\''+p.dir+'\',\'folder\')">📂</button>' : '')
    + (p.dir ? '<button class="btn btn-sm btn-ghost" onclick="openFolder(\''+p.dir+'\',\'vscode\')">VS</button>' : '')
    + '</div>'
    + deps + conns
    + '</div>';
  }).join('');
}

// System
// Servers
function showAddServer() { document.getElementById('add-server-form').classList.remove('hidden'); document.getElementById('srv-name').focus(); }
function hideAddServer() { document.getElementById('add-server-form').classList.add('hidden'); }
async function saveServer() {
  var s = { name: document.getElementById('srv-name').value.trim(), host: document.getElementById('srv-host').value.trim(),
    user: document.getElementById('srv-user').value.trim(), path: document.getElementById('srv-path').value.trim(),
    key: document.getElementById('srv-key').value.trim() };
  if (!s.name || !s.host) return alert('Name and host required');
  var r = await api('servers/add', s);
  if (r.error) return alert(r.error);
  hideAddServer(); loadServers();
}
async function deployTo(name) {
  var btn = event.target; btn.disabled = true; btn.textContent = '⏳ Deploying...';
  var r = await api('servers/deploy', {target: name});
  btn.disabled = false; btn.textContent = '⬆ Deploy';
  if (r.error) alert(r.error);
  else alert('✨ Deployed ' + (r.files||0) + ' files to ' + name);
}
async function removeServer(name) {
  if (!confirm('Remove server "' + name + '"?')) return;
  await api('servers/remove', {name: name});
  loadServers();
}
async function loadServers() {
  var d = await api('servers');
  var el = document.getElementById('servers-list');
  if (!d.servers || !d.servers.length) {
    el.innerHTML = '<div class="card"><p style="color:var(--dim)">No remote servers configured. Add one to deploy your app.</p></div>';
    return;
  }
  el.innerHTML = d.servers.map(function(s) { return ''
    + '<div class="app-row">'
    + '<span class="dot on"></span>'
    + '<span class="app-name">' + s.name + '</span>'
    + '<span class="app-url">' + s.user + '@' + s.host + ':' + s.path + '</span>'
    + '<div class="btn-row">'
    + '<button class="btn btn-sm btn-primary" onclick="deployTo(\'' + s.name + '\')">⬆ Deploy</button>'
    + '<button class="btn btn-sm btn-red" onclick="removeServer(\'' + s.name + '\')">✕</button>'
    + '</div></div>';
  }).join('');
}

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
  if (d.platform) items.push(['Platform', d.platform]);
  if (d.appDir) items.push(['App Dir', d.appDir]);
  el.innerHTML = items.map(function(i) {
    return '<div class="card"><div class="stat-lbl">'+i[0]+'</div><div class="stat-val" style="font-size:16px">'+i[1]+'</div></div>';
  }).join('');

  // Platform install section
  var pEl = document.getElementById('platform-status');
  if (d.platform) {
    pEl.innerHTML = '<p style="color:var(--grn)">✅ Platform installed at <code style="font-size:12px">'+d.platform+'</code></p>';
  } else {
    pEl.innerHTML = ''
      + '<p style="color:var(--dim);margin-bottom:12px">Qbix Platform adds user accounts, real-time streams, assets, and 20+ plugins to your app.</p>'
      + '<div class="form-row"><label>Install to</label>'
      + '<input id="platform-dir" value="'+(d.appDir ? d.appDir.replace(/[/\\][^/\\]*$/,'') : '')+'/platform" '
      + 'style="font-size:12px" placeholder="/path/to/install/platform"></div>'
      + '<div class="btn-row">'
      + '<button class="btn btn-primary" onclick="installPlatform()" id="platform-btn">Clone from GitHub</button>'
      + '</div>'
      + '<pre id="platform-log" style="display:none;margin-top:12px;font-size:11px;color:var(--dim);max-height:200px;overflow:auto;background:rgba(0,0,0,.2);padding:8px;border-radius:4px"></pre>';
  }
}

async function installPlatform() {
  var dir = document.getElementById('platform-dir').value.trim();
  if (!dir) return alert('Enter a directory path');
  var btn = document.getElementById('platform-btn');
  var log = document.getElementById('platform-log');
  btn.disabled = true; btn.textContent = '⏳ Cloning...';
  log.style.display = 'block'; log.textContent = 'git clone https://github.com/Qbix/Platform.git ' + dir + '\n';
  try {
    var r = await api('platform/install', {dir: dir});
    if (r.error) { log.textContent += '\n⚠ ' + r.error; log.style.color = 'var(--red)'; }
    else { log.textContent += r.output + '\n✅ Done! Refresh to see plugins.'; log.style.color = 'var(--grn)'; }
  } catch(e) { log.textContent += '\nError: ' + e.message; log.style.color = 'var(--red)'; }
  btn.disabled = false; btn.textContent = 'Clone from GitHub';
}

// Init
checkAuthAndInit();
</script></body></html>
HTML;
	}
}
