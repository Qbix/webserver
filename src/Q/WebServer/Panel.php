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
	static function panelConfigPath()
	{
		return defined('APP_DIR')
			? APP_DIR . '/local/panel.json'
			: qbix_data_path('local/panel.json');
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
			case 'apps/fork-mode':
				return self::apiSetForkMode($parsed);
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
			case 'domains':
				return self::apiListDomains();
			case 'domains/add':
				return self::apiAddDomain($parsed);
			case 'domains/remove':
				return self::apiRemoveDomain($parsed);
			case 'domains/provision':
				return self::apiProvisionCert($parsed);
			case 'domains/hosts':
				return self::apiHostsFile();
			case 'domains/hosts/add':
				return self::apiHostsAdd($parsed);
			case 'autohost':
				require_once dirname(__DIR__) . '/WebServer/Autohost.php';
				return Q_WebServer_Autohost::status();
			case 'autohost/toggle':
				return self::apiAutohostToggle($parsed);
			case 'watchdog':
				require_once dirname(__DIR__) . '/WebServer/Watchdog.php';
				return Q_WebServer_Watchdog::status();
			case 'attestation':
				require_once dirname(__DIR__) . '/WebServer/Trust.php';
				return Q_WebServer_Trust::attestation();
			case 'attestation/sign':
				return self::apiAttestationSign($parsed);
			case 'attestation/verify':
				require_once dirname(__DIR__) . '/WebServer/Trust.php';
				$m = (int) ($parsed['query']['m'] ?? 0) ?: null;
				return Q_WebServer_Trust::verifyBinary(null, $m);
			case 'attestation/publish-rekor':
				require_once dirname(__DIR__) . '/WebServer/Trust.php';
				$bp = realpath($_SERVER['SCRIPT_FILENAME'] ?? $GLOBALS['argv'][0]);
				$uuid = Q_WebServer_Trust::publishToRekor($bp);
				return $uuid
					? ['published' => true, 'uuid' => $uuid, 'url' => "https://search.sigstore.dev/?uuid=$uuid"]
					: ['status' => 500, 'error' => 'Failed. Sign the binary first.'];
			case 'trust':
				require_once dirname(__DIR__) . '/WebServer/Trust.php';
				return Q_WebServer_Trust::status();
			case 'trust/verify':
				return self::apiTrustVerify($parsed);
			case 'metrics':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				return Q_WebServer_Metrics::status();
			case 'metrics/history':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$minutes = (int) ($parsed['query']['minutes'] ?? 60);
				return ['stats' => Q_WebServer_Metrics::recentStats(min($minutes, 1440))];
			case 'metrics/summary':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$hours = (int) ($parsed['query']['hours'] ?? 24);
				return Q_WebServer_Metrics::summary(min($hours, 720));
			case 'metrics/flow':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$limit = (int) ($parsed['query']['limit'] ?? 50);
				return ['edges' => Q_WebServer_Metrics::flow(min($limit, 200))];
			case 'metrics/pages':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$limit = (int) ($parsed['query']['limit'] ?? 20);
				return ['pages' => Q_WebServer_Metrics::topPages(min($limit, 100))];
			case 'metrics/pageflow':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$path = $parsed['query']['path'] ?? '/';
				return Q_WebServer_Metrics::pageFlow($path);
			case 'workers':
				return self::apiWorkerStatus();
			case 'workers/resize':
				return self::apiWorkerResize($parsed);
			case 'workers/recycle':
				return self::apiWorkerRecycle($parsed);
			case 'workers/detail':
				return self::apiWorkerDetail();
			case 'logs':
				return self::apiLogs($parsed);
			case 'cron':
				return self::apiCronStatus();
			case 'cron/run':
				return self::apiCronRun($parsed);
			case 'frameworks':
				return self::apiFrameworks();
			case 'frameworks/run':
				return self::apiFrameworkRun($parsed);
			case 'frameworks/packages':
				return self::apiFrameworkPackages($parsed);
			case 'frameworks/composer':
				return self::apiFrameworkComposer($parsed);
			case 'frameworks/pkg-action':
				return self::apiFrameworkPkgAction($parsed);
			case 'frameworks/pkg-download':
				return self::apiFrameworkPkgDownload($parsed);
			case 'qbix/installer':
				return self::apiQbixInstaller($parsed);
			case 'qbix/npm':
				return self::apiQbixNpm($parsed);
			case 'qbix/plugins':
				return self::apiQbixPlugins();
			case 'qbix/plugins/install':
				return self::apiQbixPluginInstall($parsed);
			case 'qbix/plugins/schema':
				return self::apiQbixPluginSchema($parsed);
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
				'forkPerRequest' => $localConfig['Q']['webserver']['forkPerRequest'] ?? $config['Q']['webserver']['forkPerRequest'] ?? null,
			);
		}

		return array('apps' => $apps, 'appsDir' => $appsDir);
	}

	/**
	 * Set forkPerRequest mode for an app.
	 * Writes to the app's local/app.json so it persists.
	 */
	static function apiSetForkMode($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$appDirName = $body['app'] ?? '';
		$forkMode = $body['forkPerRequest'] ?? null;

		if (!$appDirName) return array('status' => 400, 'error' => 'App name required');

		$appsDir = self::appsDir();
		$appDir = $appsDir . DS . $appDirName;
		if (!is_dir($appDir)) return array('status' => 404, 'error' => 'App not found');

		$localDir = $appDir . DS . 'local';
		@mkdir($localDir, 0755, true);
		$localFile = $localDir . DS . 'app.json';

		$config = array();
		if (is_file($localFile)) {
			$config = json_decode(file_get_contents($localFile), true) ?: array();
		}

		if ($forkMode === null || $forkMode === 'auto') {
			// Remove the setting (use server default)
			unset($config['Q']['webserver']['forkPerRequest']);
			// Clean up empty nesting
			if (empty($config['Q']['webserver'])) unset($config['Q']['webserver']);
			if (empty($config['Q'])) unset($config['Q']);
		} else {
			$config['Q']['webserver']['forkPerRequest'] = (bool) $forkMode;
		}

		file_put_contents($localFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		return array('ok' => true, 'forkPerRequest' => $forkMode, 'note' => 'Restart the server for changes to take effect.');
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
			'diskFree' => self::formatBytes(disk_free_space(Q_WebServer::$rootDir ?: '.')),
			'serverVersion' => defined('QBIX_SERVER_VERSION') ? 'QbixServer/' . QBIX_SERVER_VERSION : null,
			'sapi' => php_sapi_name(),
			'pid' => getmypid(),
			'uid' => function_exists('posix_getuid') ? posix_getuid() : null,
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




	// ── Framework Package Management ─────────────────────

	/**
	 * Get packages/plugins for a detected framework.
	 * Reads composer.lock, wp plugin dirs, etc.
	 */
	static function apiFrameworkPackages($parsed)
	{
		$query = [];
		if (!empty($parsed['query'])) parse_str($parsed['query'], $query);
		$body = json_decode($parsed['body'] ?? '{}', true);
		$framework = $body['framework'] ?? $query['framework'] ?? '';

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));
		$packages = [];

		switch ($framework) {

		case 'laravel':
		case 'symfony':
			// Read composer.lock for installed packages
			$lockFile = $projectDir . '/composer.lock';
			$jsonFile = $projectDir . '/composer.json';

			$required = [];
			if (is_file($jsonFile)) {
				$cj = json_decode(file_get_contents($jsonFile), true);
				foreach (($cj['require'] ?? []) as $pkg => $ver) {
					$required[$pkg] = $ver;
				}
				foreach (($cj['require-dev'] ?? []) as $pkg => $ver) {
					$required[$pkg] = $ver . ' (dev)';
				}
			}

			if (is_file($lockFile)) {
				$lock = json_decode(file_get_contents($lockFile), true);
				foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $pkg) {
					$name = $pkg['name'] ?? '';
					$isDev = in_array($pkg, $lock['packages-dev'] ?? []);
					$packages[] = [
						'name' => $name,
						'version' => $pkg['version'] ?? '',
						'description' => $pkg['description'] ?? '',
						'type' => $pkg['type'] ?? 'library',
						'constraint' => $required[$name] ?? null,
						'dev' => $isDev,
						'homepage' => $pkg['homepage'] ?? null,
					];
				}
			} elseif (!empty($required)) {
				// No lock file, show requirements
				foreach ($required as $pkg => $ver) {
					$packages[] = [
						'name' => $pkg,
						'version' => null,
						'constraint' => $ver,
						'description' => '(not installed — run composer install)',
					];
				}
			}
			return ['framework' => $framework, 'packages' => $packages, 'source' => is_file($lockFile) ? 'composer.lock' : 'composer.json'];

		case 'wordpress':
			// Try wp-cli first
			$wpDir = is_file($rootDir . 'wp-config.php') ? rtrim($rootDir, '/') : $projectDir;
			$wpCli = null;
			foreach (['wp', $projectDir . '/vendor/bin/wp'] as $p) {
				if (self::which($p)) {
					$wpCli = $p; break;
				}
			}

			if ($wpCli) {
				// wp-cli gives structured JSON
				$pluginJson = shell_exec("cd " . escapeshellarg($wpDir) . " && $wpCli plugin list --format=json 2>/dev/null");
				$plugins = json_decode($pluginJson ?: '[]', true) ?: [];
				foreach ($plugins as $p) {
					$packages[] = [
						'name' => $p['name'] ?? '',
						'version' => $p['version'] ?? '',
						'status' => $p['status'] ?? '',
						'update' => $p['update'] ?? 'none',
						'type' => 'plugin',
					];
				}

				$themeJson = shell_exec("cd " . escapeshellarg($wpDir) . " && $wpCli theme list --format=json 2>/dev/null");
				$themes = json_decode($themeJson ?: '[]', true) ?: [];
				foreach ($themes as $t) {
					$packages[] = [
						'name' => $t['name'] ?? '',
						'version' => $t['version'] ?? '',
						'status' => $t['status'] ?? '',
						'update' => $t['update'] ?? 'none',
						'type' => 'theme',
					];
				}
				return ['framework' => 'wordpress', 'packages' => $packages, 'source' => 'wp-cli'];
			}

			// Fallback: scan wp-content/plugins/ directory
			$pluginsDir = $wpDir . '/wp-content/plugins';
			if (is_dir($pluginsDir)) {
				foreach (scandir($pluginsDir) as $d) {
					if ($d === '.' || $d === '..' || !is_dir($pluginsDir . '/' . $d)) continue;
					// Read plugin header from main PHP file
					$mainFile = $pluginsDir . '/' . $d . '/' . $d . '.php';
					if (!is_file($mainFile)) {
						// Try first .php file
						foreach (glob($pluginsDir . '/' . $d . '/*.php') as $f) {
							$mainFile = $f; break;
						}
					}
					$info = ['name' => $d, 'type' => 'plugin'];
					if (is_file($mainFile)) {
						$header = file_get_contents($mainFile, false, null, 0, 4096);
						if (preg_match('/Plugin Name:\s*(.+)/i', $header, $m)) $info['title'] = trim($m[1]);
						if (preg_match('/Version:\s*(.+)/i', $header, $m)) $info['version'] = trim($m[1]);
						if (preg_match('/Description:\s*(.+)/i', $header, $m)) $info['description'] = trim($m[1]);
					}
					$packages[] = $info;
				}
			}

			// Scan themes too
			$themesDir = $wpDir . '/wp-content/themes';
			if (is_dir($themesDir)) {
				foreach (scandir($themesDir) as $d) {
					if ($d === '.' || $d === '..' || !is_dir($themesDir . '/' . $d)) continue;
					$styleFile = $themesDir . '/' . $d . '/style.css';
					$info = ['name' => $d, 'type' => 'theme'];
					if (is_file($styleFile)) {
						$header = file_get_contents($styleFile, false, null, 0, 2048);
						if (preg_match('/Theme Name:\s*(.+)/i', $header, $m)) $info['title'] = trim($m[1]);
						if (preg_match('/Version:\s*(.+)/i', $header, $m)) $info['version'] = trim($m[1]);
					}
					$packages[] = $info;
				}
			}
			return ['framework' => 'wordpress', 'packages' => $packages, 'source' => 'filesystem'];

		case 'drupal':
			$drush = null;
			foreach (['drush', $projectDir . '/vendor/bin/drush'] as $p) {
				if (self::which($p)) {
					$drush = $p; break;
				}
			}
			if ($drush) {
				$moduleJson = shell_exec("cd " . escapeshellarg($projectDir) . " && $drush pm:list --format=json 2>/dev/null");
				$modules = json_decode($moduleJson ?: '{}', true) ?: [];
				foreach ($modules as $name => $info) {
					$packages[] = [
						'name' => $name,
						'version' => $info['version'] ?? '',
						'status' => $info['status'] ?? '',
						'type' => $info['type'] ?? 'module',
						'description' => $info['display_name'] ?? $name,
					];
				}
				return ['framework' => 'drupal', 'packages' => $packages, 'source' => 'drush'];
			}
			// Fallback to composer
			return self::apiFrameworkPackages(array_merge($parsed, ['body' => json_encode(['framework' => 'symfony'])]));

		case 'joomla':
			// Read administrator/cache or manifest files
			$extDir = rtrim($rootDir, '/') . '/administrator/manifests/packages';
			if (is_dir($extDir)) {
				foreach (glob($extDir . '/*.xml') as $xml) {
					$info = ['name' => basename($xml, '.xml'), 'type' => 'package'];
					$content = file_get_contents($xml, false, null, 0, 4096);
					if (preg_match('/<version>(.+?)<\/version>/i', $content, $m)) $info['version'] = $m[1];
					if (preg_match('/<name>(.+?)<\/name>/i', $content, $m)) $info['title'] = $m[1];
					$packages[] = $info;
				}
			}
			return ['framework' => 'joomla', 'packages' => $packages, 'source' => 'manifests'];

		default:
			return ['status' => 400, 'error' => 'Unknown framework'];
		}
	}

	/**
	 * Run a composer command (require, update, remove).
	 */
	static function apiFrameworkComposer($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$action = $body['action'] ?? '';
		$package = $body['package'] ?? '';

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));

		if (!is_file($projectDir . '/composer.json')) {
			return ['status' => 400, 'error' => 'No composer.json found'];
		}

		$allowed = ['update', 'install', 'dump-autoload'];
		if ($package && in_array($action, ['require', 'remove', 'update'])) {
			// Validate package name (vendor/package format)
			if (!preg_match('#^[a-z0-9]([a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*$#i', $package)) {
				return ['status' => 400, 'error' => 'Invalid package name'];
			}
			$cmd = "cd " . escapeshellarg($projectDir) . " && composer $action " . escapeshellarg($package) . " --no-interaction 2>&1";
		} elseif (in_array($action, $allowed)) {
			$cmd = "cd " . escapeshellarg($projectDir) . " && composer $action --no-interaction 2>&1";
		} else {
			return ['status' => 400, 'error' => 'Invalid action'];
		}

		$output = shell_exec($cmd);
		return ['output' => $output, 'cmd' => "composer $action" . ($package ? " $package" : '')];
	}

	/**
	 * Unified package management: install, remove, enable, disable, update.
	 * Each framework maps these to its own CLI tool.
	 */
	static function apiFrameworkPkgAction($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$framework = $body['framework'] ?? '';
		$action = $body['action'] ?? '';
		$package = $body['package'] ?? '';

		if (!$framework || !$action || !$package) {
			return ['status' => 400, 'error' => 'Missing framework, action, or package'];
		}

		// Validate package name to prevent injection
		if (!preg_match('#^[a-zA-Z0-9/_.:@^~>=<*-]+$#', $package)) {
			return ['status' => 400, 'error' => 'Invalid package name'];
		}

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));
		$cmd = null;
		$cwd = $projectDir;

		switch ($framework) {

		case 'laravel':
		case 'symfony':
			// Composer-based
			switch ($action) {
				case 'install':
				case 'require':
					$cmd = "composer require " . escapeshellarg($package) . " --no-interaction"; break;
				case 'remove':
					$cmd = "composer remove " . escapeshellarg($package) . " --no-interaction"; break;
				case 'update':
					$cmd = "composer update " . escapeshellarg($package) . " --no-interaction"; break;
				default:
					return ['status' => 400, 'error' => "Unknown action '$action' for $framework"];
			}
			break;

		case 'wordpress':
			$wpDir = is_file($rootDir . 'wp-config.php') ? rtrim($rootDir, '/') : $projectDir;
			$wpCli = null;
			foreach (['wp', $projectDir . '/vendor/bin/wp'] as $p) {
				if (self::which($p)) {
					$wpCli = $p; break;
				}
			}
			if (!$wpCli) {
				return ['status' => 400, 'error' => 'wp-cli not found. Install it: https://wp-cli.org/'];
			}
			$cwd = $wpDir;
			$pathFlag = ' --path=' . escapeshellarg($wpDir);

			// Determine if it's a theme or plugin from package name prefix
			$type = 'plugin';
			if (strpos($package, 'theme:') === 0) {
				$type = 'theme';
				$package = substr($package, 6);
			}

			switch ($action) {
				case 'install':
					$cmd = "$wpCli $type install " . escapeshellarg($package) . "$pathFlag"; break;
				case 'activate':
					$cmd = "$wpCli $type activate " . escapeshellarg($package) . "$pathFlag"; break;
				case 'deactivate':
					$cmd = "$wpCli $type deactivate " . escapeshellarg($package) . "$pathFlag"; break;
				case 'remove':
				case 'delete':
					$cmd = "$wpCli $type delete " . escapeshellarg($package) . "$pathFlag"; break;
				case 'update':
					$cmd = "$wpCli $type update " . escapeshellarg($package) . "$pathFlag"; break;
				default:
					return ['status' => 400, 'error' => "Unknown action '$action' for WordPress"];
			}
			break;

		case 'drupal':
			$drush = null;
			foreach (['drush', $projectDir . '/vendor/bin/drush'] as $p) {
				if (self::which($p)) {
					$drush = $p; break;
				}
			}

			switch ($action) {
				case 'install':
				case 'enable':
					if ($drush) {
						$cmd = "$drush pm:install " . escapeshellarg($package) . " -y";
					} else {
						$cmd = "composer require " . escapeshellarg("drupal/$package") . " --no-interaction";
					}
					break;
				case 'remove':
				case 'uninstall':
					if ($drush) {
						$cmd = "$drush pm:uninstall " . escapeshellarg($package) . " -y";
					} else {
						$cmd = "composer remove " . escapeshellarg("drupal/$package") . " --no-interaction";
					}
					break;
				case 'update':
					$cmd = "composer update " . escapeshellarg("drupal/$package") . " --no-interaction"; break;
				default:
					return ['status' => 400, 'error' => "Unknown action '$action' for Drupal"];
			}
			break;

		case 'joomla':
			switch ($action) {
				case 'install':
					$cmd = "php cli/joomla.php extension:install --package=" . escapeshellarg($package); break;
				case 'remove':
					$cmd = "php cli/joomla.php extension:remove " . escapeshellarg($package); break;
				default:
					return ['status' => 400, 'error' => "Unknown action '$action' for Joomla"];
			}
			$cwd = rtrim($rootDir, '/');
			break;

		default:
			return ['status' => 400, 'error' => "Unknown framework '$framework'"];
		}

		$fullCmd = "cd " . escapeshellarg($cwd) . " && $cmd 2>&1";
		$output = shell_exec($fullCmd);
		return ['output' => $output, 'cmd' => $cmd];
	}


	// ── Package Download (all frameworks) ────────────────

	/**
	 * Download a plugin/package from a URL (GitHub, zip, etc.)
	 * or install via composer/npm.
	 */
	static function apiFrameworkPkgDownload($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$framework = $body['framework'] ?? '';
		$source = trim($body['source'] ?? '');
		$target = $body['target'] ?? '';

		if (!$source) return ['status' => 400, 'error' => 'No source URL provided'];

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));
		$output = '';
		$cmd = '';

		// Determine target directory based on framework
		switch ($framework) {
			case 'qbix':
				// Qbix plugins go into platform/plugins/
				$pluginsDir = null;
				foreach ([
					$projectDir . '/platform/plugins',
					dirname($projectDir) . '/platform/plugins',
				] as $pd) {
					if (is_dir($pd)) { $pluginsDir = $pd; break; }
				}
				if (!$pluginsDir) {
					return ['status' => 400, 'error' => 'Cannot find platform/plugins directory'];
				}
				$targetDir = $pluginsDir . '/' . ($target ?: basename($source, '.git'));
				break;
			case 'wordpress':
				$wpDir = is_file($rootDir . 'wp-config.php') ? rtrim($rootDir, '/') : $projectDir;
				$targetDir = $wpDir . '/wp-content/plugins/' . ($target ?: basename($source, '.git'));
				break;
			case 'drupal':
				$targetDir = $projectDir . '/web/modules/custom/' . ($target ?: basename($source, '.git'));
				if (!is_dir(dirname($targetDir))) {
					$targetDir = $projectDir . '/modules/custom/' . ($target ?: basename($source, '.git'));
				}
				break;
			case 'joomla':
				$targetDir = rtrim($rootDir, '/') . '/plugins/' . ($target ?: basename($source, '.git'));
				break;
			default:
				// Laravel/Symfony: use composer require instead of git clone
				if (preg_match('#^[a-z0-9]([a-z0-9._-]*/)[a-z0-9][a-z0-9._-]*$#i', $source)) {
					$cmd = "cd " . escapeshellarg($projectDir) . " && composer require " . escapeshellarg($source) . " --no-interaction 2>&1";
					$output = shell_exec($cmd);
					return ['output' => $output, 'cmd' => "composer require $source"];
				}
				$targetDir = $projectDir . '/plugins/' . ($target ?: basename($source, '.git'));
		}

		// If it's a GitHub URL or git URL, clone it
		if (preg_match('#^(https?://|git@)#', $source)) {
			if (is_dir($targetDir)) {
				// Already exists — try git pull
				$cmd = "cd " . escapeshellarg($targetDir) . " && git pull 2>&1";
			} else {
				$cmd = "git clone --depth 1 " . escapeshellarg($source) . " " . escapeshellarg($targetDir) . " 2>&1";
			}
			$output = shell_exec($cmd);

			// Check for package.json and composer.json in the downloaded plugin
			$extras = [];
			if (is_file($targetDir . '/package.json')) {
				$extras[] = 'Has package.json — run npm install from the panel';
			}
			if (is_file($targetDir . '/composer.json')) {
				$extras[] = 'Has composer.json — run composer install from the panel';
			}
			if (is_file($targetDir . '/config/plugin.json')) {
				$extras[] = 'Qbix plugin detected — run the installer to set up DB schema';
			}
			if ($extras) {
				$output .= "\n\n" . implode("\n", $extras);
			}

			return ['output' => $output, 'cmd' => $cmd, 'dir' => $targetDir];
		}

		// If it looks like a composer package name
		if (preg_match('#^[a-z0-9]([a-z0-9._-]*/)[a-z0-9][a-z0-9._-]*$#i', $source)) {
			$cmd = "cd " . escapeshellarg($projectDir) . " && composer require " . escapeshellarg($source) . " --no-interaction 2>&1";
			$output = shell_exec($cmd);
			return ['output' => $output, 'cmd' => "composer require $source"];
		}

		return ['status' => 400, 'error' => 'Source must be a git URL (https:// or git@) or a composer package name (vendor/package)'];
	}

	// ── Qbix Installer & NPM ────────────────────────────

	/**
	 * Run the Qbix installer (install.php) with various flags.
	 */
	static function apiQbixInstaller($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$action = $body['action'] ?? '';
		$plugin = $body['plugin'] ?? '';

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));

		// Find install.php
		$installScript = null;
		foreach ([
			$projectDir . '/scripts/Q/install.php',
			dirname($projectDir) . '/scripts/Q/install.php',
		] as $p) {
			if (is_file($p)) { $installScript = $p; break; }
		}

		if (!$installScript) {
			return ['status' => 400, 'error' => 'Cannot find scripts/Q/install.php. Is this a Qbix app?'];
		}

		$appDir = dirname(dirname($installScript));
		$allowed = ['--all', '--plugins', '--app', '--composer', '--npm'];

		switch ($action) {
			case 'all':
				$flags = '--all';
				break;
			case 'plugins':
				$flags = '--plugins';
				break;
			case 'app':
				$flags = '--app';
				break;
			case 'plugin':
				if (!$plugin || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $plugin)) {
					return ['status' => 400, 'error' => 'Invalid plugin name'];
				}
				$flags = '-p ' . escapeshellarg($plugin);
				break;
			case 'composer':
				$flags = '--composer';
				break;
			case 'npm':
				$flags = '--npm';
				break;
			case 'plugin-full':
				// Install a single plugin with its SQL + composer + npm
				if (!$plugin || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $plugin)) {
					return ['status' => 400, 'error' => 'Invalid plugin name'];
				}
				$flags = '-p ' . escapeshellarg($plugin) . ' --composer --npm';
				break;
			default:
				return ['status' => 400, 'error' => "Unknown action: $action. Use: all, plugins, app, plugin, composer, npm, plugin-full"];
		}

		$cmd = "cd " . escapeshellarg($appDir) . " && php " . escapeshellarg($installScript) . " $flags 2>&1";
		$output = shell_exec($cmd);
		return ['output' => $output, 'cmd' => "php scripts/Q/install.php $flags"];
	}

	/**
	 * Run npm commands for Qbix plugins or the app.
	 */
	static function apiQbixNpm($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$action = $body['action'] ?? 'install';
		$target = $body['target'] ?? '';  // plugin name or 'app' or 'platform'

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));

		// Determine directory
		$dir = null;
		if ($target === 'app' || $target === '') {
			$dir = $projectDir;
		} elseif ($target === 'platform') {
			foreach ([
				$projectDir . '/platform',
				dirname($projectDir) . '/platform',
			] as $pd) {
				if (is_dir($pd)) { $dir = $pd; break; }
			}
		} else {
			// Plugin name
			foreach ([
				$projectDir . '/platform/plugins/' . $target,
				dirname($projectDir) . '/platform/plugins/' . $target,
			] as $pd) {
				if (is_dir($pd)) { $dir = $pd; break; }
			}
		}

		if (!$dir || !is_dir($dir)) {
			return ['status' => 400, 'error' => "Directory not found for target: $target"];
		}

		if (!is_file($dir . '/package.json')) {
			return ['status' => 400, 'error' => "No package.json in $dir"];
		}

		$hasNpm = (bool) self::which('npm');
		if (!$hasNpm) {
			return ['status' => 400, 'error' => 'npm is not installed on this system'];
		}

		$allowed = ['install', 'update', 'audit', 'ls'];
		if (!in_array($action, $allowed)) {
			return ['status' => 400, 'error' => "Invalid npm action: $action"];
		}

		$cmd = "cd " . escapeshellarg($dir) . " && npm $action --ignore-scripts 2>&1";
		$output = shell_exec($cmd);
		return ['output' => $output, 'cmd' => "npm $action", 'dir' => $dir];
	}

	// ── Qbix Plugin Management API ──────────────────────

	/**
	 * Parse a Qbix JSON file (tolerant of comments and trailing commas).
	 */
	private static function parseQbixJson($path)
	{
		if (!is_file($path)) return null;
		$raw = file_get_contents($path);
		// Remove block comments
		$raw = preg_replace('#/\*.*?\*/#s', '', $raw);
		// Remove line comments (outside strings)
		$lines = explode("\n", $raw);
		$cleaned = [];
		foreach ($lines as $line) {
			$inStr = false;
			$out = '';
			for ($i = 0; $i < strlen($line); $i++) {
				$ch = $line[$i];
				if ($ch === '"' && ($i === 0 || $line[$i-1] !== '\\'))
					$inStr = !$inStr;
				if (!$inStr && $ch === '/' && $i+1 < strlen($line) && $line[$i+1] === '/')
					break;
				$out .= $ch;
			}
			$cleaned[] = $out;
		}
		$raw = implode("\n", $cleaned);
		// Remove trailing commas before ] or }
		$raw = preg_replace('/,\s*([\]\}])/', '$1', $raw);
		return json_decode($raw, true);
	}

	/**
	 * Scan all plugin sources and return a unified view.
	 */
	static function apiQbixPlugins()
	{
		$rootDir = Q_WebServer::$rootDir;
		$serverDir = Q_WebServer::$serverDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));

		// 1. Find the app config
		$appJson = null;
		$appDir = null;
		foreach ([
			$projectDir . '/config/app.json',
			$rootDir . '../config/app.json',
		] as $p) {
			if (is_file($p)) {
				$appJson = self::parseQbixJson($p);
				$appDir = dirname(dirname($p));
				break;
			}
		}

		$declaredPlugins = [];
		$appName = null;
		$appVersion = null;
		if ($appJson) {
			$declaredPlugins = $appJson['Q']['plugins'] ?? [];
			$appName = $appJson['Q']['app'] ?? basename($appDir);
			$appVersion = $appJson['Q']['appInfo']['version'] ?? null;
		}

		// 2. Find local/plugins.json (installed filesystem versions)
		$localPlugins = [];
		foreach ([
			$appDir . '/local/plugins.json',
			$projectDir . '/local/plugins.json',
		] as $p) {
			if (is_file($p)) {
				$lp = self::parseQbixJson($p);
				if ($lp) {
					$localPlugins = $lp['Q']['pluginLocal'] ?? $lp;
				}
				break;
			}
		}

		// 3. Scan platform/plugins/ for available plugins
		$available = [];
		$platformDirs = [
			$projectDir . '/platform/plugins',
			$appDir . '/../platform/plugins',
			dirname($serverDir) . '/Platform/platform/plugins',
		];
		$pluginsDir = null;
		foreach ($platformDirs as $pd) {
			if (is_dir($pd)) {
				$pluginsDir = realpath($pd);
				break;
			}
		}

		if ($pluginsDir) {
			foreach (scandir($pluginsDir) as $pName) {
				if ($pName === '.' || $pName === '..') continue;
				$pDir = $pluginsDir . '/' . $pName;
				if (!is_dir($pDir)) continue;
				$pJson = self::parseQbixJson($pDir . '/config/plugin.json');
				if ($pJson) {
					$pi = $pJson['Q']['pluginInfo'][$pName] ?? [];
					$available[$pName] = [
						'version' => $pi['version'] ?? null,
						'compatible' => $pi['compatible'] ?? null,
						'requires' => $pi['requires'] ?? [],
						'connections' => $pi['connections'] ?? [],
						'dir' => $pDir,
					];
				} else {
					// Directory exists but no parseable plugin.json
					$available[$pName] = [
						'version' => null,
						'dir' => $pDir,
						'noConfig' => true,
					];
				}
			}
		}

		// 4. Check database for schema versions
		$dbPlugins = [];
		$dbError = null;
		try {
			// Try to find SQLite databases in the app
			$dbPaths = [];
			foreach ([
				$appDir . '/local',
				$projectDir . '/local',
				$projectDir . '/data',
				$rootDir . '../data',
			] as $dir) {
				if (!is_dir($dir)) continue;
				foreach (glob($dir . '/*.db') as $dbFile) {
					$dbPaths[] = $dbFile;
				}
				foreach (glob($dir . '/*.sqlite') as $dbFile) {
					$dbPaths[] = $dbFile;
				}
			}

			foreach ($dbPaths as $dbPath) {
				try {
					$pdo = new \PDO('sqlite:' . $dbPath);
					$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

					// Check for Q_plugin table (with any prefix)
					$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%Q_plugin'")->fetchAll(\PDO::FETCH_COLUMN);
					foreach ($tables as $table) {
						$rows = $pdo->query("SELECT * FROM \"$table\"")->fetchAll(\PDO::FETCH_ASSOC);
						foreach ($rows as $row) {
							$name = $row['plugin'] ?? null;
							if (!$name) continue;
							$dbPlugins[$name] = [
								'schemaVersion' => $row['version'] ?? null,
								'schemaPHPVersion' => $row['versionPHP'] ?? null,
								'extra' => json_decode($row['extra'] ?? '{}', true),
								'db' => basename($dbPath),
								'table' => $table,
							];
						}
					}
				} catch (\Exception $e) {
					// Skip this database
				}
			}
		} catch (\Exception $e) {
			$dbError = $e->getMessage();
		}

		// 5. Build unified plugin list
		$plugins = [];
		$allNames = array_unique(array_merge(
			$declaredPlugins,
			array_keys($localPlugins),
			array_keys($available),
			array_keys($dbPlugins)
		));
		sort($allNames);

		foreach ($allNames as $name) {
			$entry = [
				'name' => $name,
				'declared' => in_array($name, $declaredPlugins),
				'availableVersion' => $available[$name]['version'] ?? null,
				'installedVersion' => $localPlugins[$name]['version'] ?? null,
				'schemaVersion' => $dbPlugins[$name]['schemaVersion'] ?? null,
				'schemaPHPVersion' => $dbPlugins[$name]['schemaPHPVersion'] ?? null,
				'extra' => $dbPlugins[$name]['extra'] ?? null,
				'db' => $dbPlugins[$name]['db'] ?? null,
				'requires' => $available[$name]['requires'] ?? [],
				'connections' => $available[$name]['connections'] ?? [],
				'hasDir' => isset($available[$name]['dir']),
				'hasPackageJson' => $available[$name]['hasPackageJson'] ?? false,
				'hasComposerJson' => $available[$name]['hasComposerJson'] ?? false,
				'hasNodeModules' => $available[$name]['hasNodeModules'] ?? false,
				'hasVendor' => $available[$name]['hasVendor'] ?? false,
			];
			// Status
			if (!$entry['availableVersion'] && !$entry['hasDir']) {
				$entry['status'] = 'missing';  // declared but not on filesystem
			} elseif (!$entry['installedVersion']) {
				$entry['status'] = 'available';  // on filesystem, not installed
			} elseif ($entry['availableVersion']
				&& version_compare($entry['installedVersion'], $entry['availableVersion'], '<')) {
				$entry['status'] = 'upgradable';
			} else {
				$entry['status'] = 'installed';
			}
			// Schema status
			if ($entry['schemaVersion'] && $entry['availableVersion']
				&& version_compare($entry['schemaVersion'], $entry['availableVersion'], '<')) {
				$entry['schemaStatus'] = 'outdated';
			} elseif ($entry['schemaVersion']) {
				$entry['schemaStatus'] = 'current';
			} else {
				$entry['schemaStatus'] = 'none';
			}
			$plugins[] = $entry;
		}

		return [
			'app' => $appName,
			'appVersion' => $appVersion,
			'pluginsDir' => $pluginsDir,
			'plugins' => $plugins,
			'dbError' => $dbError,
		];
	}

	static function apiQbixPluginInstall($parsed)
	{
		// Placeholder — full install requires Q_Plugin::installPlugin()
		$body = json_decode($parsed['body'] ?? '{}', true);
		$name = $body['plugin'] ?? '';
		if (!$name) return ['status' => 400, 'error' => 'Missing plugin name'];
		return ['status' => 501, 'error' => 'Plugin installation from the panel requires the Qbix Platform. Use: php scripts/Q/install.php --plugin=' . $name];
	}

	static function apiQbixPluginSchema($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$name = $body['plugin'] ?? '';
		if (!$name) return ['status' => 400, 'error' => 'Missing plugin name'];

		// Find the plugin's SQL scripts
		$result = self::apiQbixPlugins();
		$scripts = [];
		foreach ($result['plugins'] as $p) {
			if ($p['name'] === $name && $p['hasDir']) {
				$pluginsDir = $result['pluginsDir'];
				$scriptsDir = $pluginsDir . '/' . $name . '/scripts/' . $name;
				if (is_dir($scriptsDir)) {
					foreach (scandir($scriptsDir) as $f) {
						if ($f === '.' || $f === '..') continue;
						$scripts[] = $f;
					}
					sort($scripts);
				}
				break;
			}
		}

		return [
			'plugin' => $name,
			'scripts' => $scripts,
			'schemaVersion' => $result['plugins'][array_search($name, array_column($result['plugins'], 'name'))]['schemaVersion'] ?? null,
		];
	}


	// ── Frameworks API ───────────────────────────────────

	static function apiFrameworks()
	{
		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));
		$detected = [];

		// Laravel: artisan file at project root, public/ as web root
		$artisan = $projectDir . '/artisan';
		if (is_file($artisan)) {
			$version = '';
			$envFile = $projectDir . '/.env';
			$env = is_file($envFile) ? parse_ini_file($envFile) : [];
			$detected[] = [
				'framework' => 'laravel',
				'name' => 'Laravel',
				'dir' => $projectDir,
				'webRoot' => $projectDir . '/public',
				'appName' => $env['APP_NAME'] ?? basename($projectDir),
				'appEnv' => $env['APP_ENV'] ?? 'unknown',
				'debug' => ($env['APP_DEBUG'] ?? 'false') === 'true',
				'commands' => [
					['name' => 'Clear cache', 'cmd' => 'cache:clear'],
					['name' => 'Clear config', 'cmd' => 'config:clear'],
					['name' => 'Clear routes', 'cmd' => 'route:clear'],
					['name' => 'Clear views', 'cmd' => 'view:clear'],
					['name' => 'Migrate', 'cmd' => 'migrate --force'],
					['name' => 'Migrate status', 'cmd' => 'migrate:status'],
					['name' => 'Route list', 'cmd' => 'route:list --compact'],
					['name' => 'Queue restart', 'cmd' => 'queue:restart'],
					['name' => 'Storage link', 'cmd' => 'storage:link'],
					['name' => 'Optimize', 'cmd' => 'optimize'],
				],
			];
		}

		// Symfony: bin/console at project root
		$console = $projectDir . '/bin/console';
		if (is_file($console)) {
			$detected[] = [
				'framework' => 'symfony',
				'name' => 'Symfony',
				'dir' => $projectDir,
				'webRoot' => $projectDir . '/public',
				'commands' => [
					['name' => 'Clear cache', 'cmd' => 'cache:clear'],
					['name' => 'Cache warmup', 'cmd' => 'cache:warmup'],
					['name' => 'Route list', 'cmd' => 'debug:router --no-interaction'],
					['name' => 'Container', 'cmd' => 'debug:container --no-interaction'],
					['name' => 'Migrate', 'cmd' => 'doctrine:migrations:migrate --no-interaction'],
					['name' => 'Migration status', 'cmd' => 'doctrine:migrations:status'],
					['name' => 'Assets install', 'cmd' => 'assets:install'],
				],
			];
		}

		// WordPress: wp-config.php in web root or project root
		$wpConfig = is_file($rootDir . 'wp-config.php') ? $rootDir : null;
		if (!$wpConfig && is_file($projectDir . '/wp-config.php')) $wpConfig = $projectDir . '/';
		if ($wpConfig) {
			$wpCli = null;
			foreach (['wp', $projectDir . '/vendor/bin/wp'] as $p) {
				if (is_executable($p) || self::which($p)) {
					$wpCli = $p; break;
				}
			}
			$detected[] = [
				'framework' => 'wordpress',
				'name' => 'WordPress',
				'dir' => rtrim($wpConfig, '/'),
				'webRoot' => $wpConfig,
				'hasCli' => (bool) $wpCli,
				'cliPath' => $wpCli,
				'commands' => $wpCli ? [
					['name' => 'Plugin list', 'cmd' => 'plugin list'],
					['name' => 'Theme list', 'cmd' => 'theme list'],
					['name' => 'Core version', 'cmd' => 'core version --extra'],
					['name' => 'Cache flush', 'cmd' => 'cache flush'],
					['name' => 'Rewrite flush', 'cmd' => 'rewrite flush'],
					['name' => 'DB check', 'cmd' => 'db check'],
					['name' => 'Cron list', 'cmd' => 'cron event list'],
					['name' => 'User list', 'cmd' => 'user list --fields=ID,user_login,user_email,roles'],
				] : [],
			];
		}

		// Drupal: drush or vendor/bin/drush
		$drush = null;
		foreach (['drush', $projectDir . '/vendor/bin/drush'] as $p) {
			if (is_executable($p) || self::which($p)) {
				$drush = $p; break;
			}
		}
		if ($drush || is_dir($projectDir . '/core/modules')) {
			$detected[] = [
				'framework' => 'drupal',
				'name' => 'Drupal',
				'dir' => $projectDir,
				'webRoot' => $projectDir . '/web',
				'hasCli' => (bool) $drush,
				'commands' => $drush ? [
					['name' => 'Cache rebuild', 'cmd' => 'cache:rebuild'],
					['name' => 'Status', 'cmd' => 'status'],
					['name' => 'Module list', 'cmd' => 'pm:list --status=enabled'],
					['name' => 'Update DB', 'cmd' => 'updatedb'],
					['name' => 'Cron run', 'cmd' => 'cron'],
				] : [],
			];
		}

		// Joomla: configuration.php in web root
		if (is_file($rootDir . 'configuration.php') && is_dir($rootDir . 'administrator')) {
			$detected[] = [
				'framework' => 'joomla',
				'name' => 'Joomla',
				'dir' => rtrim($rootDir, '/'),
				'webRoot' => $rootDir,
				'commands' => [
					['name' => 'Clear cache', 'cmd' => 'cache:clean'],
					['name' => 'Extension list', 'cmd' => 'extension:list'],
					['name' => 'Check updates', 'cmd' => 'update:extensions:check'],
					['name' => 'Site info', 'cmd' => 'site:info'],
				],
			];
		}

		return ['frameworks' => $detected];
	}

	static function apiFrameworkRun($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$framework = $body['framework'] ?? '';
		$cmd = $body['cmd'] ?? '';
		if (!$framework || !$cmd) return ['status' => 400, 'error' => 'Missing framework or cmd'];

		// Detect the CLI tool
		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));
		$cli = '';
		$cwd = $projectDir;
		switch ($framework) {
			case 'laravel':
				$cli = 'php artisan';
				break;
			case 'symfony':
				$cli = 'php bin/console';
				break;
			case 'wordpress':
				$cli = self::which('wp') ? 'wp' : $projectDir . '/vendor/bin/wp';
				$cwd = is_file($rootDir . 'wp-config.php') ? rtrim($rootDir, '/') : $projectDir;
				$cli .= ' --path=' . escapeshellarg($cwd);
				break;
			case 'drupal':
				$cli = self::which('drush') ? 'drush' : $projectDir . '/vendor/bin/drush';
				break;
			case 'joomla':
				$cli = 'php cli/joomla.php';
				break;
			default:
				return ['status' => 400, 'error' => 'Unknown framework'];
		}

		// Whitelist check: only allow commands from the detected list
		$allowed = false;
		$fwData = self::apiFrameworks();
		foreach ($fwData['frameworks'] as $fw) {
			if ($fw['framework'] === $framework) {
				foreach ($fw['commands'] as $c) {
					if ($c['cmd'] === $cmd) { $allowed = true; break 2; }
				}
			}
		}
		if (!$allowed) return ['status' => 403, 'error' => 'Command not in allowed list'];

		$fullCmd = "cd " . escapeshellarg($cwd) . " && " . $cli . " " . $cmd . " 2>&1";
		$output = shell_exec($fullCmd);
		return ['output' => $output, 'cmd' => $cli . ' ' . $cmd];
	}


	// ── Helpers ──────────────────────────────────────────

	// ── Domains API ──────────────────────────────────────

	static function apiListDomains()
	{
		$domains = Q_Config::get('Q', 'webserver', 'domains', array());
		// Merge domains from panel config (added via UI)
		$configPath = self::panelConfigPath();
		if (file_exists($configPath)) {
			$panelConfig = json_decode(file_get_contents($configPath), true);
			if (!empty($panelConfig['domains'])) {
				$domains = array_merge($domains, $panelConfig['domains']);
			}
		}
		$certDir = Q_Config::get('Q', 'webserver', 'tls', 'certDir', 'local/certs');
		$result = [];
		foreach ($domains as $name => $conf) {
			$certPath = rtrim($certDir, '/') . '/' . $name . '/fullchain.pem';
			$entry = [
				'domain' => $name,
				'root' => $conf['root'] ?? null,
				'app' => $conf['app'] ?? null,
				'tls' => $conf['tls'] ?? 'none',
				'aliases' => $conf['aliases'] ?? [],
			];
			if (is_file($certPath)) {
				$expiry = Q_WebServer_Acme::certExpiry($certPath);
				$entry['certExpires'] = $expiry ? date('Y-m-d', $expiry) : null;
				$entry['certDaysLeft'] = $expiry ? max(0, (int) (($expiry - time()) / 86400)) : null;
				$entry['certDomains'] = Q_WebServer_Acme::certDomains($certPath);
				$entry['certStatus'] = ($expiry && $expiry > time())
					? ($expiry - time() < 30 * 86400 ? 'expiring' : 'valid')
					: 'expired';
			} else {
				$entry['certStatus'] = 'none';
			}
			$result[] = $entry;
		}
		// Also check for certs without config entries
		if (is_dir($certDir)) {
			foreach (scandir($certDir) as $d) {
				if ($d === '.' || $d === '..' || $d === 'account.pem') continue;
				if (!is_dir($certDir . '/' . $d)) continue;
				if (isset($domains[$d])) continue; // already listed
				$certPath = $certDir . '/' . $d . '/fullchain.pem';
				if (!is_file($certPath)) continue;
				$expiry = Q_WebServer_Acme::certExpiry($certPath);
				$result[] = [
					'domain' => $d,
					'tls' => 'manual',
					'certExpires' => $expiry ? date('Y-m-d', $expiry) : null,
					'certDaysLeft' => $expiry ? max(0, (int) (($expiry - time()) / 86400)) : null,
					'certStatus' => ($expiry && $expiry > time()) ? 'valid' : 'expired',
					'certDomains' => Q_WebServer_Acme::certDomains($certPath),
					'unconfigured' => true,
				];
			}
		}
		return ['domains' => $result];
	}

	static function apiAddDomain($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$domain = $body['domain'] ?? '';
		if (!$domain || !preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/i', $domain)) {
			return ['status' => 400, 'error' => 'Invalid domain name'];
		}
		$configPath = self::panelConfigPath();
		$config = file_exists($configPath)
			? json_decode(file_get_contents($configPath), true) : [];
		$config['domains'][$domain] = [
			'root' => $body['root'] ?? null,
			'app' => $body['app'] ?? null,
			'tls' => $body['tls'] ?? 'auto',
			'aliases' => $body['aliases'] ?? [],
		];
		file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
		return ['added' => $domain];
	}

	static function apiRemoveDomain($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$domain = $body['domain'] ?? '';
		$configPath = self::panelConfigPath();
		$config = file_exists($configPath)
			? json_decode(file_get_contents($configPath), true) : [];
		unset($config['domains'][$domain]);
		file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
		return ['removed' => $domain];
	}

	static function apiProvisionCert($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$domain = $body['domain'] ?? '';
		if (!$domain) return ['status' => 400, 'error' => 'Missing domain'];

		$email = Q_Config::get('Q', 'webserver', 'tls', 'acmeEmail', '');
		if (!$email) return ['status' => 400, 'error' => 'Set Q.webserver.tls.acmeEmail first'];

		$certDir = Q_Config::get('Q', 'webserver', 'tls', 'certDir', 'local/certs');
		$staging = (bool) Q_Config::get('Q', 'webserver', 'tls', 'acmeStaging', false);

		$domains = [$domain];
		$conf = Q_Config::get('Q', 'webserver', 'domains', $domain, []);
		if (!empty($conf['aliases'])) $domains = array_merge($domains, $conf['aliases']);

		$result = Q_WebServer_Acme::provision($domains, $certDir, $email, $staging);
		return $result;
	}

	// ── Hosts File API ──────────────────────────────────

	/**
	 * Get the system hosts file path for the current OS.
	 */
	static function hostsFilePath()
	{
		return PHP_OS_FAMILY === 'Windows'
			? 'C:\\Windows\\System32\\drivers\\etc\\hosts'
			: '/etc/hosts';
	}

	/**
	 * Read and parse the system hosts file.
	 * Returns entries as [{ip, hostname, line}] and the raw content.
	 */
	static function apiHostsFile()
	{
		$path = self::hostsFilePath();
		if (!is_readable($path)) {
			return ['error' => "Cannot read $path", 'entries' => [], 'writable' => false];
		}
		$raw = file_get_contents($path);
		$entries = [];
		foreach (explode("\n", $raw) as $i => $line) {
			$trimmed = trim($line);
			if ($trimmed === '' || $trimmed[0] === '#') continue;
			$parts = preg_split('/\s+/', $trimmed);
			if (count($parts) >= 2) {
				$ip = array_shift($parts);
				foreach ($parts as $host) {
					if ($host === '' || $host[0] === '#') break;
					$entries[] = ['ip' => $ip, 'hostname' => $host, 'line' => $i + 1];
				}
			}
		}

		// Cross-reference with configured domains
		$domains = Q_Config::get('Q', 'webserver', 'domains', array());
		$configPath = self::panelConfigPath();
		if (file_exists($configPath)) {
			$panelConfig = json_decode(file_get_contents($configPath), true);
			if (!empty($panelConfig['domains'])) {
				$domains = array_merge($domains, $panelConfig['domains']);
			}
		}

		$mapped = [];
		$hostsMap = [];
		foreach ($entries as $e) {
			$hostsMap[$e['hostname']] = $e['ip'];
		}
		foreach ($domains as $name => $conf) {
			$mapped[] = [
				'domain' => $name,
				'inHosts' => isset($hostsMap[$name]),
				'hostsIp' => $hostsMap[$name] ?? null,
			];
		}

		return [
			'entries' => $entries,
			'domains' => $mapped,
			'path' => $path,
			'writable' => is_writable($path),
			'needsElevation' => !is_writable($path),
		];
	}

	/**
	 * Add an entry to /etc/hosts. Returns a shell command for elevation
	 * if the server doesn't have write access (which is the normal case).
	 */
	static function apiHostsAdd($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$hostname = $body['hostname'] ?? '';
		$ip = $body['ip'] ?? '127.0.0.1';

		if (!$hostname || !preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/i', $hostname)) {
			return ['status' => 400, 'error' => 'Invalid hostname'];
		}
		if (!filter_var($ip, FILTER_VALIDATE_IP)) {
			return ['status' => 400, 'error' => 'Invalid IP'];
		}

		// Check if already in hosts
		$path = self::hostsFilePath();
		if (is_readable($path)) {
			$existing = file_get_contents($path);
			if (preg_match('/^\s*' . preg_quote($ip, '/') . '\s+.*\b' . preg_quote($hostname, '/') . '\b/m', $existing)) {
				return ['already' => true, 'hostname' => $hostname, 'ip' => $ip];
			}
			// Check for conflicting entry (different IP, same hostname)
			if (preg_match('/^\s*(\S+)\s+.*\b' . preg_quote($hostname, '/') . '\b/m', $existing, $m)) {
				return [
					'conflict' => true,
					'hostname' => $hostname,
					'existingIp' => trim($m[1]),
					'requestedIp' => $ip,
				];
			}
		}

		$entry = "$ip\t$hostname";

		// Try direct write first
		if (is_writable($path)) {
			file_put_contents($path, "\n$entry\n", FILE_APPEND);
			return ['added' => true, 'hostname' => $hostname, 'ip' => $ip];
		}

		// Return platform-specific elevation commands
		$cmds = [];
		if (PHP_OS_FAMILY === 'Darwin') {
			$cmds['command'] = "sudo -- sh -c 'echo \"$entry\" >> /etc/hosts'";
			$cmds['gui'] = "osascript -e 'do shell script \"echo \\\"$entry\\\" >> /etc/hosts\" with administrator privileges'";
		} elseif (PHP_OS_FAMILY === 'Windows') {
			$psCmd = "Add-Content -Path '$path' -Value '$entry'";
			$cmds['command'] = "powershell -Command \"Start-Process powershell -Verb RunAs -ArgumentList '-Command $psCmd'\"";
		} else {
			$cmds['command'] = "sudo -- sh -c 'echo \"$entry\" >> /etc/hosts'";
			$cmds['gui'] = "pkexec sh -c 'echo \"$entry\" >> /etc/hosts'";
		}

		return [
			'needsElevation' => true,
			'hostname' => $hostname,
			'ip' => $ip,
			'entry' => $entry,
			'commands' => $cmds,
		];
	}

	// ── Attestation & Trust API ─────────────────────────

	static function apiAttestationSign($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$keyPem = $body['key'] ?? '';
		$signer = $body['signer'] ?? 'panel-user';

		if (!$keyPem) {
			return ['status' => 400, 'error' => 'Provide a PEM private key in the "key" field'];
		}

		// Write key to temp file
		$tmpKey = tempnam(sys_get_temp_dir(), 'qbix_sign_');
		file_put_contents($tmpKey, $keyPem);

		require_once dirname(__DIR__) . '/WebServer/Trust.php';
		$binaryPath = realpath($_SERVER['SCRIPT_FILENAME'] ?? $GLOBALS['argv'][0]);
		$result = Q_WebServer_Trust::signBinary($binaryPath, $tmpKey, $signer);
		@unlink($tmpKey);

		if (!$result) {
			return ['status' => 500, 'error' => 'Signing failed — check key format'];
		}
		return [
			'signed' => true,
			'hash' => $result['binary_hash'],
			'signers' => count($result['signatures']),
		];
	}

	static function apiTrustVerify($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$dir = $body['dir'] ?? null;

		require_once dirname(__DIR__) . '/WebServer/Trust.php';
		if ($dir) {
			$dir = realpath($dir);
			if (!$dir || !is_dir($dir)) {
				return ['status' => 400, 'error' => 'Directory not found'];
			}
			return Q_WebServer_Trust::verifyDirectory($dir);
		}
		// Verify all known directories
		return Q_WebServer_Trust::status();
	}

	// ── Autohost API ────────────────────────────────────

	static function apiAutohostToggle($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$configPath = self::panelConfigPath();
		$config = is_file($configPath)
			? json_decode(file_get_contents($configPath), true) : [];

		if (isset($body['enabled'])) {
			$config['autohost']['enabled'] = (bool) $body['enabled'];
		}
		if (isset($body['authorize'])) {
			$config['autohost']['authorize'] = $body['authorize'];
		}
		if (isset($body['dnsCheck'])) {
			$config['autohost']['dnsCheck'] = (bool) $body['dnsCheck'];
		}
		if (isset($body['acmeEmail'])) {
			$config['autohost']['acmeEmail'] = $body['acmeEmail'];
		}
		if (isset($body['allowlist'])) {
			$config['autohost']['allowlist'] = array_values(array_filter(
				array_map('trim', explode("\n", $body['allowlist']))
			));
		}

		file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

		// Apply to runtime config
		foreach ($config['autohost'] ?? [] as $k => $v) {
			Q_Config::set('Q', 'webserver', 'autohost', $k, $v);
		}

		return ['saved' => true, 'autohost' => $config['autohost'] ?? []];
	}

	// ── Workers API ──────────────────────────────────────

	static function apiWorkerStatus()
	{
		$pool = Q_WebServer::$pool ?? null;
		if (!$pool) {
			return ['mode' => 'in-process', 'workers' => 0];
		}
		$stats = Q_WebServer_Dashboard::getStats();
		return [
			'mode' => 'persistent',
			'workers' => $stats['workers'] ?? 0,
			'activeWorkers' => $stats['activeWorkers'] ?? 0,
			'totalRequests' => $stats['totalRequests'] ?? 0,
			'uptime' => $stats['uptime'] ?? 0,
			'memoryUsage' => memory_get_usage(true),
			'memoryPeak' => memory_get_peak_usage(true),
			'pid' => getmypid(),
		];
	}

	static function apiWorkerResize($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$count = (int) ($body['workers'] ?? 0);
		if ($count < 1 || $count > 10000) {
			return ['status' => 400, 'error' => 'Worker count must be 1-10000'];
		}
		$pool = Q_WebServer::$pool ?? null;
		if (!$pool) {
			return ['status' => 400, 'error' => 'No worker pool (in-process mode)'];
		}
		if (method_exists($pool, 'resize')) {
			$pool->resize($count);
			return ['resized' => $count];
		}
		return ['status' => 501, 'error' => 'Pool does not support dynamic resize yet'];
	}

	static function apiWorkerRecycle($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$pool = Q_WebServer::$pool ?? null;
		if (!$pool) {
			return ['status' => 400, 'error' => 'No worker pool'];
		}
		$index = $body['index'] ?? null;
		if ($index !== null) {
			// Recycle a specific worker
			$result = $pool->recycleWorker((int) $index);
			return ['worker' => (int) $index, 'result' => $result];
		}
		// Recycle all workers (rolling restart)
		$result = $pool->recycleAll();
		return ['recycled' => $result];
	}

	static function apiWorkerDetail()
	{
		$pool = Q_WebServer::$pool ?? null;
		if (!$pool) {
			return ['mode' => 'in-process', 'workers' => []];
		}
		return $pool->workerStats();
	}

	// ── Logs API ─────────────────────────────────────────

	static function apiLogs($parsed)
	{
		$query = $parsed['query'] ?? [];
		parse_str($query, $params);
		$lines = (int) ($params['lines'] ?? 50);
		$lines = max(1, min($lines, 500));
		$type = $params['type'] ?? 'access'; // access or error

		$logDir = Q_Config::get('Q', 'webserver', 'log', 'dir', 'logs');
		$file = $logDir . '/' . ($type === 'error' ? 'error.log' : 'access.log');

		if (!is_file($file)) {
			return ['lines' => [], 'file' => $file, 'exists' => false];
		}

		// Tail the file efficiently
		$result = [];
		$fp = fopen($file, 'r');
		if ($fp) {
			$size = filesize($file);
			$chunk = min($size, $lines * 512); // rough estimate
			fseek($fp, max(0, $size - $chunk));
			$content = fread($fp, $chunk);
			fclose($fp);
			$allLines = explode("\n", trim($content));
			$result = array_slice($allLines, -$lines);
		}

		return ['lines' => $result, 'file' => $file, 'exists' => true, 'size' => filesize($file)];
	}

	// ── Cron / Scheduler API ─────────────────────────────

	static function apiCronStatus()
	{
		$tasks = Q_Config::get('Q', 'scheduler', array());
		$result = [];
		foreach ($tasks as $name => $conf) {
			$entry = [
				'name' => $name,
				'handler' => $conf['handler'] ?? $name,
				'every' => $conf['every'] ?? null,
				'times' => $conf['times'] ?? null,
				'weekdays' => $conf['weekdays'] ?? null,
				'monthdays' => $conf['monthdays'] ?? null,
			];
			$result[] = $entry;
		}
		return ['tasks' => $result];
	}

	static function apiCronRun($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$name = $body['task'] ?? '';
		$tasks = Q_Config::get('Q', 'scheduler', array());
		if (!isset($tasks[$name])) {
			return ['status' => 404, 'error' => "Task '{$name}' not found"];
		}
		$handler = $tasks[$name]['handler'] ?? $name;
		// Dispatch in a forked process
		if (function_exists('pcntl_fork')) {
			$pid = pcntl_fork();
			if ($pid === 0) {
				Q::event($handler);
				exit(0);
			}
			return ['dispatched' => $name, 'handler' => $handler, 'pid' => $pid];
		}
		return ['status' => 501, 'error' => 'pcntl_fork not available'];
	}

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

	static function formatBytes($bytes)
	{
		if ($bytes === false) return 'N/A';
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];
		$i = 0;
		while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
		return round($bytes, 1) . ' ' . $units[$i];
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
  <div class="tab" onclick="showTab('domains')">Domains</div>
  <div class="tab" onclick="showTab('autohost')">Autohost</div>
  <div class="tab" onclick="showTab('security')">Security</div>
  <div class="tab" onclick="showTab('scripts')">Scripts</div>
  <div class="tab" onclick="showTab('plugins')">Plugins</div>
  <div class="tab" onclick="showTab('workers')">Workers</div>
  <div class="tab" onclick="showTab('logs')">Logs</div>
  <div class="tab" onclick="showTab('cron')">Cron</div>
  <div class="tab" onclick="showTab('frameworks')">Frameworks</div>
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

<!-- DOMAINS TAB -->
<div id="tab-domains" class="content hidden">
  <h2 style="font-size:16px;margin-bottom:16px">Domains &amp; Certificates</h2>
  <div id="domains-list"></div>
  <div class="card" style="margin-top:16px">
    <h3 style="font-size:14px;margin-bottom:12px">Add Domain</h3>
    <div class="form-row"><label>Domain</label><input id="dom-name" placeholder="example.com"></div>
    <div class="form-row"><label>Root</label><input id="dom-root" placeholder="/var/www/myapp/web"></div>
    <div class="form-row"><label>App dir</label><input id="dom-app" placeholder="/var/www/myapp (optional)"></div>
    <div class="form-row"><label>TLS</label><select id="dom-tls"><option value="auto">Auto (ACME)</option><option value="manual">Manual (drop certs)</option><option value="self-signed">Self-signed</option><option value="">HTTP only</option></select></div>
    <button onclick="addDomain()">Add Domain</button>
  </div>
  <div id="hosts-info"></div>
</div>

<!-- SECURITY TAB -->
<div id="tab-security" class="content hidden">
  <h2 style="font-size:16px;margin-bottom:16px">Security &amp; Attestation</h2>

  <div id="sec-attestation"></div>

  <div class="card" style="margin-top:16px">
    <h3 style="font-size:14px;margin-bottom:12px">Sign Binary</h3>
    <p style="font-size:12px;color:var(--dim);margin-bottom:12px">Paste a PEM private key to add your signature to this binary. Multiple signers can sign independently for M-of-N verification.</p>
    <div class="form-row"><label>Signer name</label><input id="sec-signer" placeholder="alice@example.com"></div>
    <div class="form-row"><label>Private key (PEM)</label><textarea id="sec-key" rows="4" placeholder="-----BEGIN PRIVATE KEY-----&#10;..." style="font-size:11px;font-family:monospace"></textarea></div>
    <button class="btn btn-primary" onclick="signBinary()">Sign</button>
  </div>

  <div class="card" style="margin-top:16px">
    <h3 style="font-size:14px;margin-bottom:12px">Verify</h3>
    <div class="form-row"><label>Required signatures (M)</label><input id="sec-m" type="number" min="1" value="1" style="width:60px"></div>
    <button class="btn btn-primary" onclick="verifyBinary()">Verify</button>
    <div id="sec-verify-result" style="margin-top:12px"></div>
  </div>

  <div id="sec-trust" style="margin-top:16px"></div>
</div>

<!-- AUTOHOST TAB -->
<div id="tab-autohost" class="content hidden">
  <h2 style="font-size:16px;margin-bottom:16px">Autohost</h2>
  <p style="font-size:13px;color:var(--dim);margin-bottom:16px">Auto-provision domains when a new Host header arrives. Customer points DNS at your server, first request triggers cert provisioning and config setup.</p>
  <div class="card" style="margin-bottom:16px">
    <div class="form-row"><label>Enabled</label><select id="ah-enabled" onchange="saveAutohost()"><option value="0">Off</option><option value="1">On</option></select></div>
    <div class="form-row"><label>Authorization</label><select id="ah-authorize" onchange="saveAutohost()"><option value="open">Open (any hostname)</option><option value="allowlist">Allowlist (patterns)</option></select></div>
    <div class="form-row" id="ah-allowlist-row" style="display:none"><label>Allowlist</label><textarea id="ah-allowlist" rows="3" placeholder="*.example.com&#10;app.acme.com" style="font-size:12px"></textarea></div>
    <div class="form-row"><label>DNS check</label><select id="ah-dns"><option value="1">Verify DNS points here</option><option value="0">Skip (trust all)</option></select></div>
    <div class="form-row"><label>ACME email</label><input id="ah-email" placeholder="admin@example.com"></div>
    <button class="btn btn-primary" onclick="saveAutohost()">Save</button>
  </div>
  <div id="ah-status"></div>
  <div id="ah-log"></div>
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
  <h2 style="font-size:16px;margin-bottom:16px">Qbix Plugins</h2>
  <div id="qbix-plugins-info"></div>
  <div id="qbix-plugins-list"></div>
</div>

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

  <div style="margin-top:16px">
    <button class="btn btn-ghost" style="font-size:12px" onclick="var f=document.getElementById('phpinfo-frame');f.style.display=f.style.display==='none'?'block':'none';if(f.style.display==='block')f.src='/Q/phpinfo'">Show phpinfo()</button>
    <iframe id="phpinfo-frame" style="display:none;width:100%;height:500px;border:1px solid var(--brd);border-radius:6px;margin-top:8px;background:#fff"></iframe>
  </div>

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

<!-- FRAMEWORKS TAB -->
<div id="tab-frameworks" class="content hidden">
  <h2 style="font-size:16px;margin-bottom:16px">Framework Management</h2>
  <div id="fw-list"><p style="color:var(--dim)">Detecting frameworks...</p></div>
</div>

<!-- WORKERS TAB -->
<div id="tab-workers" class="content hidden">
  <h2 style="font-size:16px;margin-bottom:16px">Worker Pool</h2>
  <div id="workers-info"></div>
  <div class="card" style="margin-top:16px">
    <h3 style="font-size:14px;margin-bottom:12px">Resize Pool</h3>
    <div class="form-row"><label>Workers</label><input id="worker-count" type="number" min="1" max="10000" placeholder="200"></div>
    <button onclick="resizeWorkers()">Resize</button>
    <p style="font-size:11px;color:var(--dim);margin-top:8px">Takes effect gradually as workers finish their current requests.</p>
  </div>
  <div id="worker-detail"></div>
</div>

<!-- LOGS TAB -->
<div id="tab-logs" class="content hidden">
  <h2 style="font-size:16px;margin-bottom:16px">Logs</h2>
  <div style="display:flex;gap:8px;margin-bottom:12px">
    <button class="btn btn-primary" onclick="loadLogs('access')">Access Log</button>
    <button class="btn btn-ghost" onclick="loadLogs('error')">Error Log</button>
    <select id="log-lines" style="margin-left:auto;padding:6px;background:var(--card);color:var(--txt);border:1px solid var(--bdr);border-radius:6px">
      <option value="50">50 lines</option>
      <option value="100">100 lines</option>
      <option value="200">200 lines</option>
      <option value="500">500 lines</option>
    </select>
  </div>
  <pre id="logs-output" style="max-height:500px;overflow:auto;font-size:11px;padding:12px;background:rgba(0,0,0,.3);border-radius:8px;white-space:pre-wrap;word-break:break-all"></pre>
</div>

<!-- CRON TAB -->
<div id="tab-cron" class="content hidden">
  <h2 style="font-size:16px;margin-bottom:16px">Scheduled Tasks</h2>
  <p style="font-size:12px;color:var(--dim);margin-bottom:14px">
    Tasks configured in <code>Q.scheduler</code>. Each runs as a forked process via <code>Q::event()</code>.
  </p>
  <div id="cron-list"></div>
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
  if (name==='domains') loadDomains();
  if (name==='autohost') loadAutohost();
  if (name==='security') loadSecurity();
  if (name==='workers') loadWorkers();
  if (name==='logs') loadLogs('access');
  if (name==='cron') loadCron();
  if (name==='frameworks') loadFrameworks();
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
    var forkLabel = a.forkPerRequest === true ? 'fork' : (a.forkPerRequest === false ? 'persistent' : 'auto');
    var forkColor = a.forkPerRequest === true ? 'var(--yel)' : (a.forkPerRequest === false ? 'var(--grn)' : 'var(--dim)');
    var forkHtml = '<select style="font-size:10px;padding:1px 4px;background:var(--card);color:' + forkColor + ';border:1px solid var(--brd);border-radius:3px;cursor:pointer" onchange="setForkMode(\'' + a.dirName + '\',this.value)">'
      + '<option value="auto"' + (a.forkPerRequest === null ? ' selected' : '') + '>auto</option>'
      + '<option value="false"' + (a.forkPerRequest === false ? ' selected' : '') + '>persistent workers</option>'
      + '<option value="true"' + (a.forkPerRequest === true ? ' selected' : '') + '>fork per request</option>'
      + '</select>';
    return ''
    + '<div class="app-row">'
    + '<span class="dot '+(isServing?'on':(a.configured?'on':'off'))+'"></span>'
    + '<span class="app-name">'+a.name+'</span>'
    + '<span class="app-url">'+statusText+' '+badgeHtml+' '+forkHtml+'</span>'
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

async function setForkMode(app, value) {
  var forkVal = value === 'true' ? true : (value === 'false' ? false : null);
  var r = await api('apps/fork-mode', {app: app, forkPerRequest: forkVal});
  if (r.note) {
    var out = document.getElementById('fw-output-' + app) || null;
    if (!out) alert(r.note);
  }
  loadApps();
}
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
  var r = await api('qbix/plugins');
  var info = document.getElementById('qbix-plugins-info');
  var list = document.getElementById('qbix-plugins-list');
  
  var topHtml = '';
  if (r.app) {
    topHtml += '<div class="card" style="margin-bottom:12px"><strong>' + r.app + '</strong> v' + (r.appVersion||'?')
      + (r.pluginsDir ? '<span style="color:var(--dim);font-size:11px;margin-left:8px">' + r.pluginsDir + '</span>' : '')
      + (r.dbError ? '<div style="color:var(--red);font-size:12px;margin-top:4px">DB: ' + r.dbError + '</div>' : '')
      + '</div>';
  } else {
    topHtml += '<div class="card" style="margin-bottom:12px;color:var(--dim)">No Qbix app detected. Point --app or --root at a Qbix app directory.</div>';
  }
  
  // Download from URL
  topHtml += '<div class="card" style="margin-bottom:12px">';
  topHtml += '<div style="font-size:12px;margin-bottom:6px;color:var(--dim)">Download plugin from GitHub or URL</div>';
  topHtml += '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">';
  topHtml += '<input id="qbix-dl-url" type="text" placeholder="https://github.com/Qbix/PluginName" style="flex:1;min-width:200px;padding:5px 8px;font-size:12px;background:var(--card);border:1px solid var(--brd);color:var(--fg);border-radius:4px">';
  topHtml += '<input id="qbix-dl-name" type="text" placeholder="PluginName (optional)" style="width:140px;padding:5px 8px;font-size:12px;background:var(--card);border:1px solid var(--brd);color:var(--fg);border-radius:4px">';
  topHtml += '<button class="btn btn-primary" style="font-size:11px;padding:5px 12px" onclick="downloadPlugin()">Clone</button>';
  topHtml += '</div></div>';
  
  // Installer controls
  topHtml += '<div class="card" style="margin-bottom:12px">';
  topHtml += '<div style="font-size:12px;margin-bottom:6px;color:var(--dim)">Qbix Installer (scripts/Q/install.php)</div>';
  topHtml += '<div style="display:flex;gap:6px;flex-wrap:wrap">';
  topHtml += '<button class="btn btn-primary" style="font-size:11px;padding:5px 12px" onclick="qbixInstall(\'all\')">Install All (--all)</button>';
  topHtml += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="qbixInstall(\'plugins\')">--plugins</button>';
  topHtml += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="qbixInstall(\'app\')">--app</button>';
  topHtml += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="qbixInstall(\'composer\')">--composer</button>';
  topHtml += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="qbixInstall(\'npm\')">--npm</button>';
  topHtml += '</div>';
  topHtml += '<pre id="qbix-install-output" style="display:none;margin-top:8px;font-size:11px;max-height:300px;overflow:auto;white-space:pre-wrap"></pre>';
  topHtml += '</div>';
  
  info.innerHTML = topHtml;
  
  if (!r.plugins || !r.plugins.length) {
    list.innerHTML = '<div class="card"><p style="color:var(--dim)">No plugins found.</p></div>';
    return;
  }
  
  list.innerHTML = r.plugins.map(function(p) {
    var statusBadge = {
      'installed': '<span style="color:var(--grn)">\u2713 installed</span>',
      'available': '<span style="color:var(--dim)">available</span>',
      'upgradable': '<span style="color:var(--yel)">\u2191 upgrade</span>',
      'missing': '<span style="color:var(--red)">\u2717 missing</span>'
    }[p.status] || p.status;
    
    var schemaBadge = '';
    if (p.schemaVersion) {
      schemaBadge = p.schemaStatus === 'current'
        ? ' <span style="color:var(--grn);font-size:11px">schema ' + p.schemaVersion + '</span>'
        : ' <span style="color:var(--yel);font-size:11px">schema ' + p.schemaVersion + ' \u2191</span>';
      if (p.db) schemaBadge += ' <span style="color:var(--dim);font-size:10px">(' + p.db + ')</span>';
    }
    
    var versions = '';
    if (p.availableVersion) versions += '<span style="font-size:11px;color:var(--dim)">v' + p.availableVersion + '</span> ';
    if (p.installedVersion && p.installedVersion !== p.availableVersion) versions += '<span style="font-size:11px;color:var(--dim)">installed: ' + p.installedVersion + '</span> ';
    
    // Package manager badges
    var pkgBadges = '';
    if (p.hasPackageJson) pkgBadges += ' <span style="font-size:9px;padding:1px 4px;border-radius:2px;background:#cb3837;color:#fff" title="Has package.json">npm</span>';
    if (p.hasComposerJson) pkgBadges += ' <span style="font-size:9px;padding:1px 4px;border-radius:2px;background:#885630;color:#fff" title="Has composer.json">composer</span>';
    if (p.hasNodeModules) pkgBadges += ' <span style="font-size:9px;color:var(--grn)" title="node_modules exists">\u2713npm</span>';
    if (p.hasVendor) pkgBadges += ' <span style="font-size:9px;color:var(--grn)" title="vendor exists">\u2713vendor</span>';
    
    var requires = '';
    if (p.requires && Object.keys(p.requires).length) {
      requires = ' <span style="font-size:10px;color:var(--dim)">needs ' + Object.keys(p.requires).join(', ') + '</span>';
    }
    
    var extra = '';
    if (p.extra && Object.keys(p.extra).length) {
      extra = '<details style="margin-top:4px"><summary style="font-size:11px;color:var(--dim);cursor:pointer">extra</summary><pre style="font-size:10px;margin-top:4px;max-height:80px;overflow:auto">' + JSON.stringify(p.extra, null, 2) + '</pre></details>';
    }
    
    // Action buttons
    var bs = 'font-size:10px;padding:2px 7px;margin-left:3px';
    var btns = '';
    if (p.hasDir) {
      btns += '<button class="btn btn-ghost" style="' + bs + '" onclick="viewPluginSchema(\'' + p.name + '\')">Scripts</button>';
      if (p.status === 'available' || p.status === 'upgradable') {
        btns += '<button class="btn btn-primary" style="' + bs + '" onclick="qbixInstall(\'plugin-full\',\'' + p.name + '\')">' + (p.status === 'upgradable' ? 'Upgrade' : 'Install') + '</button>';
      }
      if (p.hasPackageJson && !p.hasNodeModules) {
        btns += '<button class="btn btn-ghost" style="' + bs + '" onclick="qbixNpm(\'install\',\'' + p.name + '\')">npm install</button>';
      }
      if (p.hasPackageJson && p.hasNodeModules) {
        btns += '<button class="btn btn-ghost" style="' + bs + '" onclick="qbixNpm(\'update\',\'' + p.name + '\')">npm update</button>';
      }
    }
    
    return '<div class="card" style="margin-bottom:4px;padding:8px 12px">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:4px">'
      + '<div><strong>' + p.name + '</strong>'
      + (p.declared ? ' <span style="font-size:9px;background:var(--dim);color:var(--bg);padding:1px 4px;border-radius:2px">declared</span>' : '')
      + ' ' + statusBadge + schemaBadge + pkgBadges + ' ' + versions + requires + '</div>'
      + '<div>' + btns + '</div>'
      + '</div>'
      + extra
      + '<pre id="plugin-scripts-' + p.name + '" style="display:none;margin-top:4px;font-size:10px;max-height:120px;overflow:auto"></pre>'
      + '</div>';
  }).join('');
}

async function downloadPlugin() {
  var url = document.getElementById('qbix-dl-url').value.trim();
  var name = document.getElementById('qbix-dl-name').value.trim();
  if (!url) { alert('Enter a URL'); return; }
  var out = document.getElementById('qbix-install-output');
  out.style.display = 'block';
  out.textContent = 'Downloading ' + url + '...';
  var r = await api('frameworks/pkg-download', {framework: 'qbix', source: url, target: name});
  out.textContent = (r.cmd ? '$ ' + r.cmd + '\n\n' : '') + (r.output || r.error || 'Done');
  if (!r.error) setTimeout(function(){ loadPlugins(); }, 500);
}

async function qbixInstall(action, plugin) {
  var out = document.getElementById('qbix-install-output');
  out.style.display = 'block';
  out.textContent = 'Running installer (' + action + (plugin ? ' ' + plugin : '') + ')...';
  var r = await api('qbix/installer', {action: action, plugin: plugin || ''});
  out.textContent = (r.cmd ? '$ ' + r.cmd + '\n\n' : '') + (r.output || r.error || 'Done');
  if (!r.error) setTimeout(function(){ loadPlugins(); }, 500);
}

async function qbixNpm(action, target) {
  var out = document.getElementById('qbix-install-output');
  out.style.display = 'block';
  out.textContent = 'Running npm ' + action + ' for ' + target + '...';
  var r = await api('qbix/npm', {action: action, target: target});
  out.textContent = (r.cmd ? '$ ' + r.cmd + '\n\n' : '') + (r.output || r.error || 'Done');
  if (!r.error) setTimeout(function(){ loadPlugins(); }, 500);
}

async function installQbixPlugin(name) {
  qbixInstall('plugin-full', name);
}

async function viewPluginSchema(name) {
  var el = document.getElementById('plugin-scripts-' + name);
  if (el.style.display !== 'none') { el.style.display = 'none'; return; }
  el.style.display = 'block';
  el.textContent = 'Loading...';
  var r = await api('qbix/plugins/schema', {plugin: name});
  if (r.scripts && r.scripts.length) {
    el.textContent = 'Schema version: ' + (r.schemaVersion || 'none') + '\n\nInstall scripts:\n' + r.scripts.join('\n');
  } else {
    el.textContent = 'No install scripts found for ' + name;
  }
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
  
  // Key extensions to highlight
  var keyExts = ['pdo_sqlite','pdo_mysql','pdo_pgsql','openssl','curl','mbstring','gd','zip','sockets','pcntl','posix','readline'];
  var extStatus = keyExts.map(function(e) {
    var has = d.extensions && d.extensions.indexOf(e) !== -1;
    return (has ? '<span style="color:var(--grn)">✅</span>' : '<span style="color:var(--red)">❌</span>') + ' ' + e;
  }).join('&nbsp;&nbsp;');
  var extCount = d.extensions ? d.extensions.length : 0;
  
  var items = [
    ['PHP', d.php + ' <span style="font-size:11px;color:var(--dim)">' + extCount + ' extensions</span>'],
    ['OS', d.os + ' ' + d.arch],
    ['Memory Limit', d.memoryLimit],
    ['pcntl', d.hasPcntl ? '✅' : '❌'],
    ['APCu', d.hasApcu ? '✅' : '❌'],
    ['Composer', d.hasComposer ? '✅ installed' : '❌ not found'],
    ['Node.js', d.hasNode ? '✅ installed' : '<span style="color:var(--red)">❌ not found</span>'],
    ['npm', d.hasNpm ? '✅ installed' : '❌ requires Node.js'],
    ['Git', d.hasGit ? '✅ installed' : '❌ not found'],
  ];
  if (d.platform) items.push(['Platform', d.platform]);
  if (d.appDir) items.push(['App Dir', d.appDir]);
  if (d.diskFree) items.push(['Disk Free', d.diskFree]);
  if (d.serverVersion) items.push(['Server', d.serverVersion]);
  
  el.innerHTML = items.map(function(i) {
    return '<div class="card"><div class="stat-lbl">'+i[0]+'</div><div class="stat-val" style="font-size:16px">'+i[1]+'</div></div>';
  }).join('');
  
  // Extensions detail
  el.innerHTML += '<div class="card" style="grid-column:1/-1"><div class="stat-lbl">Key Extensions</div><div style="font-size:12px;line-height:2;margin-top:4px">' + extStatus + '</div>'
    + '<details style="margin-top:8px"><summary style="font-size:11px;color:var(--dim);cursor:pointer">All ' + extCount + ' extensions</summary>'
    + '<div style="font-size:11px;color:var(--dim);margin-top:4px;column-count:3;column-gap:12px">' + (d.extensions||[]).sort().join('<br>') + '</div></details></div>';

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

// ── Domains ─────────────────────────────────────────
async function loadDomains() {
  var r = await api('domains');
  var el = document.getElementById('domains-list');
  if (!r.domains || !r.domains.length) {
    el.innerHTML = '<div class="card"><p style="color:var(--dim)">No domains configured. Add one below, or set <code>Q.webserver.domains</code> in config.</p></div>';
  } else {
    el.innerHTML = r.domains.map(function(d) {
      var badge = d.certStatus === 'valid' ? '<span style="color:var(--grn)">\u2713 valid</span>'
        : d.certStatus === 'expiring' ? '<span style="color:var(--yel)">\u26a0 ' + d.certDaysLeft + ' days</span>'
        : d.certStatus === 'expired' ? '<span style="color:var(--red)">\u2717 expired</span>'
        : '<span style="color:var(--dim)">no cert</span>';
      var btns = '';
      if (d.certStatus !== 'valid') btns += ' <button class="btn btn-primary" style="font-size:11px;padding:4px 10px" onclick="provisionCert(\'' + d.domain + '\')">Provision</button>';
      else btns += ' <button class="btn btn-ghost" style="font-size:11px;padding:4px 10px" onclick="provisionCert(\'' + d.domain + '\')">Renew</button>';
      btns += ' <button class="btn btn-ghost" style="font-size:11px;padding:4px 10px;color:var(--red)" onclick="removeDomain(\'' + d.domain + '\')">Remove</button>';
      return '<div class="card" style="margin-bottom:8px"><div style="display:flex;justify-content:space-between;align-items:center"><div><strong>' + d.domain + '</strong></div><div>' + badge + btns + '</div></div>'
        + (d.root ? '<div style="font-size:11px;color:var(--dim);margin-top:4px">Root: ' + d.root + '</div>' : '')
        + (d.certExpires ? '<div style="font-size:11px;color:var(--dim);margin-top:2px">Expires: ' + d.certExpires + '</div>' : '')
        + '</div>';
    }).join('');
  }
  loadHosts();
}
async function loadHosts() {
  var r = await api('domains/hosts');
  var el = document.getElementById('hosts-info');
  if (!el) return;
  if (r.error) { el.innerHTML = '<div class="card"><p style="color:var(--dim)">' + r.error + '</p></div>'; return; }
  var html = '<h3 style="font-size:14px;margin:16px 0 8px">System Hosts <span style="font-size:11px;color:var(--dim)">(' + r.path + ')</span></h3>';
  if (r.domains && r.domains.length) {
    html += r.domains.map(function(d) {
      if (d.inHosts) {
        return '<div class="card" style="margin-bottom:6px;padding:8px 12px"><span style="color:var(--grn)">\u2713</span> <strong>' + d.domain + '</strong> \u2192 ' + d.hostsIp + '</div>';
      }
      var isLocalhost = d.domain.endsWith('.localhost');
      if (isLocalhost) {
        return '<div class="card" style="margin-bottom:6px;padding:8px 12px"><span style="color:var(--grn)">\u2713</span> <strong>' + d.domain + '</strong> <span style="color:var(--dim)">(resolves via .localhost)</span></div>';
      }
      return '<div class="card" style="margin-bottom:6px;padding:8px 12px"><span style="color:var(--yel)">\u26a0</span> <strong>' + d.domain + '</strong> <span style="color:var(--dim)">not in hosts</span>'
        + ' <button class="btn btn-primary" style="font-size:11px;padding:3px 8px;margin-left:8px" onclick="addHostsEntry(\'' + d.domain + '\')">Add to hosts</button></div>';
    }).join('');
  } else {
    html += '<div class="card"><p style="color:var(--dim)">No domains configured.</p></div>';
  }
  el.innerHTML = html;
}
async function addHostsEntry(hostname, ip) {
  ip = ip || '127.0.0.1';
  var r = await api('domains/hosts/add', {hostname: hostname, ip: ip});
  if (r.already) { alert(hostname + ' is already in your hosts file.'); return; }
  if (r.conflict) { alert(hostname + ' is mapped to ' + r.existingIp + ' (not ' + r.requestedIp + '). Edit your hosts file manually to change it.'); return; }
  if (r.added) { alert('Added ' + hostname + ' \u2192 ' + ip); loadHosts(); return; }
  if (r.needsElevation) {
    var cmd = r.commands.gui || r.commands.command;
    if (confirm(hostname + ' needs admin access to add to ' + r.entry + '.\n\nRun this command in your terminal:\n\n' + r.commands.command + '\n\nCopy to clipboard?')) {
      try { navigator.clipboard.writeText(r.commands.command); } catch(e) {}
    }
  }
}
async function addDomain() {
  var name = document.getElementById('dom-name').value.trim();
  if (!name) return alert('Enter a domain');
  await api('domains/add', {domain:name, root:document.getElementById('dom-root').value.trim()||null, app:document.getElementById('dom-app').value.trim()||null, tls:document.getElementById('dom-tls').value});
  document.getElementById('dom-name').value=''; loadDomains();
}
async function removeDomain(n) { if(!confirm('Remove '+n+'?'))return; await api('domains/remove',{domain:n}); loadDomains(); }
async function provisionCert(n) { alert('Provisioning '+n+'...'); var r=await api('domains/provision',{domain:n}); alert(r.success?'Done!':r.error||'Failed'); loadDomains(); }

// ── Security & Attestation ──────────────────────────
async function loadSecurity() {
  var el = document.getElementById('sec-attestation');
  try {
    var r = await api('attestation');
    var html = '<div class="card"><h3 style="font-size:14px;margin-bottom:8px">Binary Attestation</h3>';
    html += '<div style="font-size:12px;margin-bottom:8px"><strong>Hash:</strong> <code style="font-size:11px">' + (r.binary_hash||'unknown') + '</code></div>';
    html += '<div style="font-size:12px;margin-bottom:8px"><strong>Size:</strong> ' + ((r.binary_size||0)/1024).toFixed(0) + ' KB</div>';
    if (r.verification) {
      var v = r.verification;
      var color = v.valid ? 'var(--grn)' : 'var(--red)';
      html += '<div style="font-size:12px;margin-bottom:8px"><strong>Status:</strong> <span style="color:'+color+'">' + v.label + ' — ' + (v.valid?'VALID':'FAILED') + '</span></div>';
      if (v.hash_matches === false) {
        html += '<div style="font-size:12px;color:var(--red)">⚠ Binary was modified since signing</div>';
      }
    }
    if (r.signatures && r.signatures.length) {
      html += '<h4 style="font-size:13px;margin:12px 0 6px">Signatures</h4>';
      r.signatures.forEach(function(s) {
        html += '<div style="font-size:12px;padding:4px 0;border-top:1px solid var(--border)">';
        html += '<strong>' + s.signer + '</strong> <span style="color:var(--dim)">(key:' + (s.key_id||'?').slice(0,8) + ')</span>';
        if (s.signed_at) html += ' <span style="color:var(--dim)">' + s.signed_at.slice(0,10) + '</span>';
        html += '</div>';
      });
    } else {
      html += '<div style="font-size:12px;color:var(--dim)">No signatures. Use the form below or the CLI to sign.</div>';
    }
    if (r.rekor && r.rekor.uuid) {
      html += '<div style="font-size:12px;margin-top:10px;padding-top:8px;border-top:1px solid var(--border)"><strong>Transparency log:</strong> <a href="' + (r.rekor.url||'#') + '" target="_blank" style="color:#4a9eff">' + r.rekor.uuid.slice(0,24) + '...</a> <span style="color:var(--grn)">✓ on Rekor</span></div>';
    } else if (r.signed) {
      html += '<div style="font-size:12px;margin-top:10px;padding-top:8px;border-top:1px solid var(--border)"><strong>Transparency log:</strong> <span style="color:var(--dim)">not published</span> <button class="btn btn-ghost" style="font-size:10px;padding:2px 8px;margin-left:6px" onclick="publishRekor()">Publish to Sigstore Rekor</button></div>';
    }
    html += '</div>';
    el.innerHTML = html;
  } catch(e) {
    el.innerHTML = '<div class="card"><p style="color:var(--dim)">Attestation data unavailable.</p></div>';
  }
  // Trust status
  var trustEl = document.getElementById('sec-trust');
  try {
    var t = await api('trust');
    if (t.enabled) {
      var thtml = '<div class="card"><h3 style="font-size:14px;margin-bottom:8px">Code Trust (File Manifests)</h3>';
      thtml += '<div style="font-size:12px">Trusted keys: ' + (t.keys||[]).length + '</div>';
      if (t.verified && t.verified.length) {
        thtml += '<div style="font-size:12px;margin-top:6px">';
        t.verified.forEach(function(v) {
          var icon = v.ok ? '<span style="color:var(--grn)">✓</span>' : '<span style="color:var(--red)">✗</span>';
          thtml += '<div>' + icon + ' ' + v.dir + (v.errors && v.errors.length ? ' (' + v.errors.length + ' errors)' : '') + '</div>';
        });
        thtml += '</div>';
      }
      thtml += '</div>';
      trustEl.innerHTML = thtml;
    } else {
      trustEl.innerHTML = '<div class="card"><p style="font-size:12px;color:var(--dim)">Code trust not enabled. Set <code>Q.trust.enabled: true</code> and add trusted keys.</p></div>';
    }
  } catch(e) {}
}
async function signBinary() {
  var key = document.getElementById('sec-key').value.trim();
  var signer = document.getElementById('sec-signer').value.trim() || 'panel-user';
  if (!key) return alert('Paste a PEM private key');
  var r = await api('attestation/sign', {key: key, signer: signer});
  if (r.error) { alert(r.error); return; }
  alert('Signed! ' + r.signers + ' total signature(s)');
  document.getElementById('sec-key').value = '';
  loadSecurity();
}
async function verifyBinary() {
  var m = parseInt(document.getElementById('sec-m').value) || 1;
  var r = await api('attestation/verify?m=' + m);
  var el = document.getElementById('sec-verify-result');
  var color = r.valid ? 'var(--grn)' : 'var(--red)';
  var html = '<div style="color:'+color+';font-weight:700">' + (r.valid ? '✓ VALID' : '✗ FAILED') + ' — ' + r.label + '</div>';
  if (r.details) {
    r.details.forEach(function(d) {
      var icon = d.status === 'valid' ? '✓' : '✗';
      html += '<div style="font-size:12px">' + icon + ' ' + d.signer + ' (' + d.status + ')</div>';
    });
  }
  el.innerHTML = html;
}
async function publishRekor() {
  if (!confirm('Publish this binary\'s attestation to the public Sigstore Rekor transparency log?\n\nThis is permanent and publicly visible.')) return;
  var r = await api('attestation/publish-rekor', {});
  if (r.published) {
    alert('Published to Rekor!\n\nUUID: ' + r.uuid + '\n\nVerify at: ' + r.url);
    loadSecurity();
  } else {
    alert(r.error || 'Failed to publish');
  }
}

// ── Autohost ────────────────────────────────────────
async function loadAutohost() {
  var r = await api('autohost');
  document.getElementById('ah-enabled').value = r.enabled ? '1' : '0';
  document.getElementById('ah-authorize').value = r.authorize || 'open';
  document.getElementById('ah-dns').value = r.dnsCheck !== false ? '1' : '0';
  document.getElementById('ah-allowlist-row').style.display = r.authorize === 'allowlist' ? '' : 'none';
  var el = document.getElementById('ah-status');
  var prov = r.provisioning || [];
  el.innerHTML = prov.length
    ? '<div class="card" style="margin-bottom:12px"><h3 style="font-size:14px;margin-bottom:8px">Currently Provisioning</h3>' + prov.map(function(h){return '<div>\u23f3 '+h+'</div>';}).join('') + '</div>'
    : '';
  var logEl = document.getElementById('ah-log');
  var lines = r.recentLog || [];
  if (lines.length) {
    logEl.innerHTML = '<div class="card"><h3 style="font-size:14px;margin-bottom:8px">Recent Activity</h3>'
      + '<pre style="font-size:11px;max-height:200px;overflow-y:auto;margin:0;white-space:pre-wrap">' + lines.join('\n') + '</pre></div>';
  } else {
    logEl.innerHTML = '<div class="card"><p style="color:var(--dim)">No autohost activity yet.</p></div>';
  }
  document.getElementById('ah-authorize').onchange = function() {
    document.getElementById('ah-allowlist-row').style.display = this.value === 'allowlist' ? '' : 'none';
  };
}
async function saveAutohost() {
  var data = {
    enabled: document.getElementById('ah-enabled').value === '1',
    authorize: document.getElementById('ah-authorize').value,
    dnsCheck: document.getElementById('ah-dns').value === '1',
    acmeEmail: document.getElementById('ah-email').value.trim()
  };
  if (data.authorize === 'allowlist') {
    data.allowlist = document.getElementById('ah-allowlist').value;
  }
  await api('autohost/toggle', data);
  loadAutohost();
}

// ── Workers ─────────────────────────────────────────
async function loadWorkers() {
  var r = await api('workers');
  var el = document.getElementById('workers-info');
  if (r.mode==='in-process') { el.innerHTML='<div class="card"><p>In-process mode (no pool).</p></div>'; return; }
  function s(l,v){return '<div><div style="font-size:18px;font-weight:700">'+v+'</div><div style="font-size:11px;color:var(--dim)">'+l+'</div></div>';}
  function fmt(b){return b>1048576?(b/1048576).toFixed(1)+' MB':(b/1024).toFixed(0)+' KB';}
  function fmtT(s){var h=Math.floor(s/3600),m=Math.floor((s%3600)/60);return h?h+'h '+m+'m':m+'m';}
  el.innerHTML='<div class="card"><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px">'
    +s('Workers',r.workers)+s('Active',r.activeWorkers||0)+s('Requests',(r.totalRequests||0).toLocaleString())
    +s('Memory',fmt(r.memoryUsage||0))+s('Peak',fmt(r.memoryPeak||0))+s('Uptime',fmtT(r.uptime||0))
    +'</div></div>';
  document.getElementById('worker-count').value=r.workers;
  // Load worker detail
  var d = await api('workers/detail');
  var detailEl = document.getElementById('worker-detail');
  if (detailEl && d.workers) {
    var tbl = '<table style="width:100%;font-size:12px;border-collapse:collapse"><tr style="color:var(--dim)">'
      + '<th style="text-align:left;padding:4px">PID</th><th>Status</th><th>Requests</th><th></th></tr>';
    d.workers.forEach(function(w) {
      var status = w.busy ? '<span style="color:var(--yel)">\u25cf busy</span>'
        : w.recycleAfter ? '<span style="color:var(--red)">\u21bb recycling</span>'
        : '<span style="color:var(--grn)">\u25cf idle</span>';
      tbl += '<tr style="border-top:1px solid var(--border);padding:4px"><td style="padding:4px">' + w.pid + '</td><td style="text-align:center">' + status
        + '</td><td style="text-align:center">' + (w.requests||0)
        + '</td><td style="text-align:right"><button class="btn btn-ghost" style="font-size:10px;padding:2px 6px" onclick="recycleWorker(' + w.index + ')">\u21bb</button></td></tr>';
    });
    tbl += '</table>';
    detailEl.innerHTML = '<div class="card" style="margin-top:12px"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">'
      + '<h3 style="font-size:14px;margin:0">Worker Detail</h3>'
      + '<button class="btn btn-primary" style="font-size:11px;padding:4px 10px" onclick="recycleAll()">Recycle All</button></div>'
      + '<p style="font-size:11px;color:var(--dim);margin:0 0 8px">Mode: ' + d.mode + ' \u00b7 Max requests: ' + d.maxRequests + ' \u00b7 Queue: ' + (d.pending||0) + '</p>'
      + tbl + '</div>';
  }
}
async function resizeWorkers() {
  var c=parseInt(document.getElementById('worker-count').value);
  if(!c||c<1)return alert('Enter a number');
  var r=await api('workers/resize',{workers:c});
  alert(r.error||'Resizing to '+c); loadWorkers();
}
async function recycleWorker(idx) {
  var r = await api('workers/recycle', {index: idx});
  loadWorkers();
}
async function recycleAll() {
  if (!confirm('Recycle all workers? Busy workers finish their current request first.')) return;
  var r = await api('workers/recycle', {});
  alert('Recycled: ' + (r.recycled ? r.recycled.immediate + ' immediate, ' + r.recycled.pending + ' pending' : 'done'));
  loadWorkers();
}

// ── Logs ────────────────────────────────────────────
async function loadLogs(type) {
  type=type||'access';
  var lines=document.getElementById('log-lines').value;
  var r=await api('logs?type='+type+'&lines='+lines);
  var el=document.getElementById('logs-output');
  if(!r.exists){el.textContent='Log file not found: '+r.file;return;}
  el.textContent=r.lines.join('\n');
  el.scrollTop=el.scrollHeight;
}

// ── Cron ────────────────────────────────────────────
async function loadCron() {
  var r=await api('cron');
  var el=document.getElementById('cron-list');
  if(!r.tasks||!r.tasks.length){el.innerHTML='<div class="card"><p style="color:var(--dim)">No scheduled tasks configured.</p></div>';return;}
  el.innerHTML=r.tasks.map(function(t){
    var sched=t.every?'Every '+t.every+'s':t.times?t.times.join(', '):'manual';
    return '<div class="card" style="margin-bottom:8px;display:flex;justify-content:space-between;align-items:center">'
      +'<div><strong>'+t.name+'</strong><div style="font-size:11px;color:var(--dim)">'+t.handler+' · '+sched+'</div></div>'
      +'<button class="btn btn-ghost" style="font-size:11px;padding:4px 10px" onclick="runCron(\''+t.name+'\')">Run Now</button></div>';
  }).join('');
}
async function runCron(n){var r=await api('cron/run',{task:n});alert(r.error||'Dispatched '+n);}


// ── Frameworks ──────────────────────────────────────
async function loadFrameworks() {
  var r = await api('frameworks');
  var el = document.getElementById('fw-list');
  if (!r.frameworks || !r.frameworks.length) {
    el.innerHTML = '<div class="card"><p style="color:var(--dim)">No known frameworks detected in the current document root.</p><p style="font-size:12px;color:var(--dim);margin-top:8px">Supported: Laravel, Symfony, WordPress, Drupal, Joomla</p></div>';
    return;
  }
  el.innerHTML = r.frameworks.map(function(fw) {
    var info = '<div class="card" style="margin-bottom:12px">';
    info += '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap">';
    info += '<h3 style="font-size:15px;margin-bottom:0">' + fw.name + '</h3>';
    info += '<button class="btn btn-ghost" style="font-size:11px;padding:4px 10px" onclick="loadFwPackages(\'' + fw.framework + '\')">Packages</button>';
    info += '</div>';
    info += '<div style="font-size:12px;color:var(--dim);margin:6px 0">' + fw.dir + '</div>';
    if (fw.appName) info += '<div style="font-size:12px;margin-bottom:4px">App: <strong>' + fw.appName + '</strong> (' + (fw.appEnv||'') + ')' + (fw.debug ? ' <span style="color:var(--yel)">DEBUG ON</span>' : '') + '</div>';
    if (fw.hasCli === false) {
      info += '<div style="color:var(--yel);font-size:12px;margin:8px 0">CLI tool not found. Install it for full management.</div>';
    }
    if (fw.commands && fw.commands.length) {
      info += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px">';
      fw.commands.forEach(function(cmd) {
        info += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="runFwCmd(\'' + fw.framework + '\',\'' + cmd.cmd.replace(/'/g,"\\'") + '\',this)">' + cmd.name + '</button>';
      });
      info += '</div>';
    }
    info += '<pre id="fw-output-' + fw.framework + '" style="display:none;margin-top:12px;max-height:300px;overflow:auto;font-size:11px;white-space:pre-wrap"></pre>';
    info += '<div id="fw-packages-' + fw.framework + '" style="display:none;margin-top:12px"></div>';
    info += '</div>';
    return info;
  }).join('');
}

async function runFwCmd(framework, cmd, btn) {
  var el = document.getElementById('fw-output-' + framework);
  el.style.display = 'block';
  el.textContent = 'Running ' + cmd + '...';
  btn.disabled = true;
  try {
    var r = await api('frameworks/run', {framework: framework, cmd: cmd});
    el.textContent = (r.cmd ? '$ ' + r.cmd + '\n\n' : '') + (r.output || r.error || 'Done');
  } catch(e) {
    el.textContent = 'Error: ' + e.message;
  }
  btn.disabled = false;
}

async function loadFwPackages(framework) {
  var el = document.getElementById('fw-packages-' + framework);
  if (el.style.display !== 'none' && el.innerHTML && !el.dataset.reload) {
    el.style.display = 'none';
    return;
  }
  delete el.dataset.reload;
  el.style.display = 'block';
  el.innerHTML = '<p style="color:var(--dim);font-size:12px">Loading packages...</p>';
  
  var r = await api('frameworks/packages?framework=' + framework);
  if (r.error) { el.innerHTML = '<p style="color:var(--red);font-size:12px">' + r.error + '</p>'; return; }
  
  var isComposer = (framework === 'laravel' || framework === 'symfony');
  var isWP = (framework === 'wordpress');
  var isDrupal = (framework === 'drupal');
  
  var html = '<div style="font-size:11px;color:var(--dim);margin-bottom:6px">' + (r.packages||[]).length + ' packages (source: ' + (r.source||'?') + ')</div>';
  
  // Add new package form
  if (isComposer) {
    html += '<div style="display:flex;gap:6px;margin-bottom:8px;align-items:center">';
    html += '<input id="fw-add-pkg-' + framework + '" type="text" placeholder="vendor/package" style="flex:1;padding:5px 8px;font-size:12px;background:var(--card);border:1px solid var(--brd);color:var(--fg);border-radius:4px">';
    html += '<button class="btn btn-primary" style="font-size:11px;padding:5px 12px" onclick="pkgAction(\'' + framework + '\',\'require\',document.getElementById(\'fw-add-pkg-' + framework + '\').value)">composer require</button>';
    html += '</div>';
    html += '<div style="display:flex;gap:6px;margin-bottom:8px;align-items:center">';
    html += '<input id="fw-dl-url-' + framework + '" type="text" placeholder="https://github.com/author/package" style="flex:1;padding:5px 8px;font-size:12px;background:var(--card);border:1px solid var(--brd);color:var(--fg);border-radius:4px">';
    html += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="fwDownload(\'' + framework + '\')">Clone from URL</button>';
    html += '</div>';
  } else if (isWP) {
    html += '<div style="display:flex;gap:6px;margin-bottom:8px;align-items:center">';
    html += '<input id="fw-add-pkg-' + framework + '" type="text" placeholder="plugin-slug" style="flex:1;padding:5px 8px;font-size:12px;background:var(--card);border:1px solid var(--brd);color:var(--fg);border-radius:4px">';
    html += '<button class="btn btn-primary" style="font-size:11px;padding:5px 12px" onclick="pkgAction(\'' + framework + '\',\'install\',document.getElementById(\'fw-add-pkg-' + framework + '\').value)">Install Plugin</button>';
    html += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="pkgAction(\'' + framework + '\',\'install\',\'theme:\'+document.getElementById(\'fw-add-pkg-' + framework + '\').value)">Install Theme</button>';
    html += '</div>';
    html += '<div style="display:flex;gap:6px;margin-bottom:8px;align-items:center">';
    html += '<input id="fw-dl-url-' + framework + '" type="text" placeholder="https://github.com/author/plugin-name" style="flex:1;padding:5px 8px;font-size:12px;background:var(--card);border:1px solid var(--brd);color:var(--fg);border-radius:4px">';
    html += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="fwDownload(\'' + framework + '\')">Clone from URL</button>';
    html += '</div>';
  } else if (isDrupal) {
    html += '<div style="display:flex;gap:6px;margin-bottom:8px;align-items:center">';
    html += '<input id="fw-add-pkg-' + framework + '" type="text" placeholder="module_name" style="flex:1;padding:5px 8px;font-size:12px;background:var(--card);border:1px solid var(--brd);color:var(--fg);border-radius:4px">';
    html += '<button class="btn btn-primary" style="font-size:11px;padding:5px 12px" onclick="pkgAction(\'' + framework + '\',\'install\',document.getElementById(\'fw-add-pkg-' + framework + '\').value)">Install Module</button>';
    html += '</div>';
    html += '<div style="display:flex;gap:6px;margin-bottom:8px;align-items:center">';
    html += '<input id="fw-dl-url-' + framework + '" type="text" placeholder="https://github.com/author/module" style="flex:1;padding:5px 8px;font-size:12px;background:var(--card);border:1px solid var(--brd);color:var(--fg);border-radius:4px">';
    html += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="fwDownload(\'' + framework + '\')">Clone from URL</button>';
    html += '</div>';
  }
  
  if (!r.packages || !r.packages.length) {
    html += '<p style="color:var(--dim);font-size:12px">No packages found.</p>';
    el.innerHTML = html;
    return;
  }
  
  html += '<div style="max-height:400px;overflow:auto">';
  html += '<table style="width:100%;font-size:11px;border-collapse:collapse">';
  html += '<tr style="background:rgba(255,255,255,.05)"><th style="text-align:left;padding:5px 8px">Name</th><th style="padding:5px 8px">Version</th>';
  if (isWP) html += '<th style="padding:5px 8px">Status</th>';
  html += '<th style="padding:5px 8px;text-align:right">Actions</th></tr>';
  
  r.packages.forEach(function(p) {
    var name = p.title || p.name;
    var pkgId = p.name;
    var rowStyle = 'border-bottom:1px solid rgba(255,255,255,.06)';
    html += '<tr style="' + rowStyle + '">';
    html += '<td style="padding:4px 8px">' + name;
    if (p.dev) html += ' <span style="color:var(--yel);font-size:10px">dev</span>';
    if (p.constraint) html += ' <span style="color:var(--dim);font-size:10px">' + p.constraint + '</span>';
    html += '</td>';
    html += '<td style="padding:4px 8px;text-align:center">' + (p.version||'-');
    if (p.update && p.update !== 'none') html += ' <span style="color:var(--yel)">→ ' + p.update + '</span>';
    html += '</td>';
    
    // Status column for WP
    if (isWP) {
      var sBadge = p.status === 'active' ? '<span style="color:var(--grn)">active</span>' : '<span style="color:var(--dim)">' + (p.status||'?') + '</span>';
      html += '<td style="padding:4px 8px;text-align:center">' + sBadge + '</td>';
    }
    
    // Action buttons
    html += '<td style="padding:3px 8px;text-align:right;white-space:nowrap">';
    var bs = 'font-size:10px;padding:2px 7px;margin-left:3px';
    
    if (isWP) {
      var wpPkg = (p.type === 'theme' ? 'theme:' : '') + pkgId;
      if (p.status === 'active') {
        html += '<button class="btn btn-ghost" style="' + bs + '" onclick="pkgAction(\'' + framework + '\',\'deactivate\',\'' + wpPkg + '\')">Deactivate</button>';
      } else if (p.status === 'inactive') {
        html += '<button class="btn btn-ghost" style="' + bs + '" onclick="pkgAction(\'' + framework + '\',\'activate\',\'' + wpPkg + '\')">Activate</button>';
      }
      if (p.update && p.update !== 'none') {
        html += '<button class="btn btn-primary" style="' + bs + '" onclick="pkgAction(\'' + framework + '\',\'update\',\'' + wpPkg + '\')">Update</button>';
      }
      html += '<button class="btn btn-ghost" style="' + bs + ';color:var(--red)" onclick="if(confirm(\'Delete ' + pkgId + '?\'))pkgAction(\'' + framework + '\',\'delete\',\'' + wpPkg + '\')">Delete</button>';
    } else if (isComposer) {
      html += '<button class="btn btn-ghost" style="' + bs + '" onclick="pkgAction(\'' + framework + '\',\'update\',\'' + pkgId + '\')">Update</button>';
      if (pkgId.indexOf('/') !== -1) {
        html += '<button class="btn btn-ghost" style="' + bs + ';color:var(--red)" onclick="if(confirm(\'Remove ' + pkgId + '?\'))pkgAction(\'' + framework + '\',\'remove\',\'' + pkgId + '\')">Remove</button>';
      }
    } else if (isDrupal) {
      if (p.status === 'Enabled' || p.status === 'enabled') {
        html += '<button class="btn btn-ghost" style="' + bs + ';color:var(--red)" onclick="if(confirm(\'Uninstall ' + pkgId + '?\'))pkgAction(\'' + framework + '\',\'uninstall\',\'' + pkgId + '\')">Uninstall</button>';
      } else {
        html += '<button class="btn btn-ghost" style="' + bs + '" onclick="pkgAction(\'' + framework + '\',\'enable\',\'' + pkgId + '\')">Enable</button>';
      }
    }
    html += '</td></tr>';
  });
  html += '</table></div>';
  
  // Global actions
  html += '<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">';
  if (isComposer) {
    html += '<button class="btn btn-ghost" style="font-size:11px;padding:4px 10px" onclick="pkgAction(\'' + framework + '\',\'update\',\'--all\')">Update All</button>';
    html += '<button class="btn btn-ghost" style="font-size:11px;padding:4px 10px" onclick="composerAction(\'dump-autoload\',\'\',\'' + framework + '\')">Dump Autoload</button>';
  }
  if (isWP) {
    html += '<button class="btn btn-ghost" style="font-size:11px;padding:4px 10px" onclick="runFwCmd(\'' + framework + '\',\'plugin update --all\',this)">Update All Plugins</button>';
  }
  html += '</div>';
  html += '<pre id="fw-pkg-output-' + framework + '" style="display:none;margin-top:8px;font-size:11px;max-height:200px;overflow:auto;white-space:pre-wrap"></pre>';
  
  el.innerHTML = html;
}

async function pkgAction(framework, action, pkg) {
  if (!pkg) { alert('Enter a package name'); return; }
  var isUpdateAll = (pkg === '--all');
  
  var output = document.getElementById('fw-pkg-output-' + framework);
  if (!output) output = document.getElementById('fw-output-' + framework);
  output.style.display = 'block';
  output.textContent = (isUpdateAll ? 'Updating all packages' : action + ' ' + pkg) + '...';
  
  var r;
  if (isUpdateAll) {
    r = await api('frameworks/composer', {action: 'update', package: ''});
  } else {
    r = await api('frameworks/pkg-action', {framework: framework, action: action, package: pkg});
  }
  output.textContent = (r.cmd ? '$ ' + r.cmd + '\n\n' : '') + (r.output || r.error || 'Done');
  
  // Reload package list
  var el = document.getElementById('fw-packages-' + framework);
  if (el) { el.dataset.reload = '1'; setTimeout(function(){ loadFwPackages(framework); }, 500); }
}

async function composerAction(action, pkg, framework) {
  var output = document.getElementById('fw-pkg-output-' + framework) || document.getElementById('fw-output-' + framework);
  output.style.display = 'block';
  output.textContent = 'Running composer ' + action + (pkg ? ' ' + pkg : '') + '...';
  var r = await api('frameworks/composer', {action: action, package: pkg || ''});
  output.textContent = (r.cmd ? '$ ' + r.cmd + '\n\n' : '') + (r.output || r.error || 'Done');
}

async function fwDownload(framework) {
  var urlEl = document.getElementById('fw-dl-url-' + framework);
  if (!urlEl || !urlEl.value.trim()) { alert('Enter a URL'); return; }
  var out = document.getElementById('fw-pkg-output-' + framework) || document.getElementById('fw-output-' + framework);
  out.style.display = 'block';
  out.textContent = 'Cloning ' + urlEl.value.trim() + '...';
  var r = await api('frameworks/pkg-download', {framework: framework, source: urlEl.value.trim()});
  out.textContent = (r.cmd ? '$ ' + r.cmd + '\n\n' : '') + (r.output || r.error || 'Done');
  if (!r.error) {
    var el = document.getElementById('fw-packages-' + framework);
    if (el) { el.dataset.reload = '1'; setTimeout(function(){ loadFwPackages(framework); }, 500); }
  }
}

// Init
checkAuthAndInit();
</script></body></html>
HTML;
	}
}
