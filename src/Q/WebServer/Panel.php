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
				Q_WebServer::sendResponse($client, self::httpStatus($result),
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
			if (!empty($result['_raw'])) {
				$rawHeaders = array();
				if (!empty($result['headers'])) $rawHeaders = $result['headers'];
				if (!isset($rawHeaders['Cache-Control'])) $rawHeaders['Cache-Control'] = 'public, max-age=3600';
				Q_WebServer::sendResponse($client, self::httpStatus($result),
					$result['body'], $result['contentType'],
					$rawHeaders);
			} else {
				Q_WebServer::sendResponse($client, self::httpStatus($result),
					json_encode($result), 'application/json');
			}
			return true;
		}

		return false;
	}

	/**
	 * HTTP status for an API result. Results use a numeric 'status' to set the
	 * response code, but some carry a descriptive 'status' of their own (a
	 * proxied peer response with {"status":"ok"}, a peer's "connected" state).
	 * Passing that string through produced "HTTP/1.1 ok" and crashed the
	 * metrics recorder, which took the whole server down.
	 */
	private static function httpStatus($result)
	{
		$s = is_array($result) ? ($result['status'] ?? null) : null;
		if (is_int($s) || (is_string($s) && ctype_digit($s))) {
			$s = (int) $s;
			if ($s >= 100 && $s <= 599) return $s;
		}
		return 200;
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
			// First-time setup: set owner password
			if (!empty($config['passwordHash']) || !empty($config['users'])) {
				return array('error' => 'Password already set. Use auth/login.',
					'needsSetup' => false);
			}
			$password = $body['password'] ?? '';
			if (strlen($password) < 6) {
				return array('error' => 'Password must be at least 6 characters');
			}
			// Store as the owner user in the new users structure
			$config['passwordHash'] = password_hash($password, PASSWORD_DEFAULT);
			$config['users'] = array(
				'owner' => array(
					'passwordHash' => $config['passwordHash'],
					'role' => 'owner',
					'created' => time(),
				),
			);
			$token = bin2hex(random_bytes(32));
			$config['sessions'][$token] = array(
				'user' => 'owner',
				'role' => 'owner',
				'expiry' => time() + 86400 * 7,
			);
			$dir = dirname($configPath);
			if (!is_dir($dir)) @mkdir($dir, 0700, true);
			if (!is_dir(dirname($configPath))) @mkdir(dirname($configPath), 0700, true);
			$written = @file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
			if ($written === false) {
				return array('error' => 'Failed to write config to ' . $configPath);
			}
			@chmod($configPath, 0600);
			clearstatcache(true, $configPath);
			return array('ok' => true, 'token' => $token, 'user' => 'owner', 'role' => 'owner');
		}

		if ($route === 'auth/login') {
			if (empty($config['passwordHash']) && empty($config['users'])) {
				return array('needsSetup' => true);
			}
			// SECURITY: brute force protection — block IP after 5 failed attempts
			$ip = $parsed['clientIp'] ?? $parsed['_remoteAddr'] ?? '0.0.0.0';
			$now = time();
			if (!isset(self::$loginAttempts[$ip])) {
				self::$loginAttempts[$ip] = array();
			}
			self::$loginAttempts[$ip] = array_filter(
				self::$loginAttempts[$ip],
				function ($t) use ($now) { return $t > $now - 300; }
			);
			if (count(self::$loginAttempts[$ip]) >= 5) {
				return array('error' => 'Too many attempts, try again later', 'status' => 429);
			}
			$password = $body['password'] ?? '';
			$username = $body['username'] ?? 'owner';

			// Migrate old single-password config to multi-user
			if (!empty($config['passwordHash']) && empty($config['users'])) {
				$config['users'] = array(
					'owner' => array(
						'passwordHash' => $config['passwordHash'],
						'role' => 'owner',
						'created' => time(),
					),
				);
				// Migrate old sessions (expiry int) to new format (user+expiry)
				if (!empty($config['sessions'])) {
					foreach ($config['sessions'] as $tk => $val) {
						if (is_int($val) || is_numeric($val)) {
							$config['sessions'][$tk] = array(
								'user' => 'owner',
								'role' => 'owner',
								'expiry' => (int) $val,
							);
						}
					}
				}
				self::savePanelConfig($configPath, $config);
			}

			// Look up the user
			$users = $config['users'] ?? array();
			if (!isset($users[$username])) {
				self::$loginAttempts[$ip][] = $now;
				return array('error' => 'Wrong username or password', 'status' => 401);
			}
			$userRec = $users[$username];
			if (!password_verify($password, $userRec['passwordHash'])) {
				self::$loginAttempts[$ip][] = $now;
				return array('error' => 'Wrong username or password', 'status' => 401);
			}
			// Success — clear attempts
			unset(self::$loginAttempts[$ip]);
			// Issue session token
			$token = bin2hex(random_bytes(32));
			if (!isset($config['sessions'])) $config['sessions'] = array();
			// Clean expired sessions
			foreach ($config['sessions'] as $t => $sess) {
				$exp = is_array($sess) ? ($sess['expiry'] ?? 0) : (int) $sess;
				if ($exp < $now) unset($config['sessions'][$t]);
			}
			$role = $userRec['role'] ?? 'user';
			$config['sessions'][$token] = array(
				'user' => $username,
				'role' => $role,
				'expiry' => $now + 86400 * 7,
			);
			self::savePanelConfig($configPath, $config);
			return array('ok' => true, 'token' => $token, 'user' => $username, 'role' => $role);
		}

		return array('error' => 'Unknown auth endpoint');
	}

	/**
	 * Save panel config atomically
	 */
	private static function savePanelConfig($configPath, $config)
	{
		$dir = dirname($configPath);
		if (!is_dir($dir)) @mkdir($dir, 0700, true);
		file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
		@chmod($configPath, 0600);
	}

	/**
	 * Check if the request has a valid auth token.
	 * Returns array with 'ok', and on success: 'user', 'role'.
	 */
	private static function checkAuth($parsed)
	{
		$configPath = self::panelConfigPath();
		clearstatcache(true, $configPath);
		if (!file_exists($configPath)) {
			return array('ok' => false, 'needsSetup' => true,
				'error' => 'No password set. Call auth/setup first.');
		}
		$config = json_decode(file_get_contents($configPath), true);
		if (empty($config['passwordHash']) && empty($config['users'])) {
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
		$sess = $sessions[$token] ?? null;
		if (!$sess) {
			return array('ok' => false, 'error' => 'Token expired or invalid');
		}
		// Support old format (int expiry) and new format (array)
		if (is_array($sess)) {
			if (($sess['expiry'] ?? 0) < time()) {
				return array('ok' => false, 'error' => 'Token expired or invalid');
			}
			return array(
				'ok' => true,
				'user' => $sess['user'] ?? 'owner',
				'role' => $sess['role'] ?? 'owner',
			);
		}
		// Legacy: integer expiry = owner session
		if ((int) $sess < time()) {
			return array('ok' => false, 'error' => 'Token expired or invalid');
		}
		return array('ok' => true, 'user' => 'owner', 'role' => 'owner');
	}

	/**
	 * Validate a session token (for WebSocket auth, etc.)
	 * Returns false if invalid, or array('user'=>..., 'role'=>...) if valid.
	 * @method validateToken
	 * @static
	 * @param {string} $token
	 * @return {boolean|array}
	 */
	static function validateToken($token)
	{
		if (empty($token)) return false;
		$configPath = self::panelConfigPath();
		if (!file_exists($configPath)) return false;
		$config = json_decode(file_get_contents($configPath), true);
		$sessions = $config['sessions'] ?? array();
		$sess = $sessions[$token] ?? null;
		if (!$sess) return false;
		if (is_array($sess)) {
			if (($sess['expiry'] ?? 0) < time()) return false;
			return array('user' => $sess['user'] ?? 'owner', 'role' => $sess['role'] ?? 'owner');
		}
		// Legacy integer expiry
		if ((int) $sess < time()) return false;
		return array('user' => 'owner', 'role' => 'owner');
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
			case 'apps/icon':
				return self::apiAppIcon($parsed);
			case 'apps/logs':
				return self::apiAppLogs($parsed);
			case 'apps/files':
				return self::apiAppFiles($parsed);
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
			case 'auth/me':
				return self::apiAuthMe($parsed);
			// ── User Management ──────────────
			case 'users':
				return self::apiListUsers($parsed);
			case 'users/add':
				return self::apiAddUser($parsed);
			case 'users/update':
				return self::apiUpdateUser($parsed);
			case 'users/remove':
				return self::apiRemoveUser($parsed);
			// ── Branch Management ──────────────
			case 'branches':
				return self::apiListBranches($parsed);
			case 'branches/create':
				return self::apiBranchCreate($parsed);
			case 'branches/delete':
				return self::apiBranchDelete($parsed);
			case 'branches/access':
				return self::apiBranchAccess($parsed);
			case 'branches/merge':
				return self::apiBranchMerge($parsed);
			case 'branches/switch-production':
				return self::apiBranchSwitchProduction($parsed);
			case 'branches/mergerequests':
				return self::apiBranchMergeRequests($parsed);
			case 'branches/db-config':
				return self::apiBranchDbConfig($parsed);
			case 'branches/lockdown':
				return self::apiBranchLockdown($parsed);
			case 'branches/defaults':
				return self::apiBranchDefaults($parsed);
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
				$qp = [];
				if (is_string($parsed['query'] ?? null)) parse_str($parsed['query'], $qp);
				elseif (is_array($parsed['query'] ?? null)) $qp = $parsed['query'];
				$m = (int) ($qp['m'] ?? 0) ?: null;
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
				$qp = self::queryParams($parsed);
				$minutes = (int) ($qp['minutes'] ?? 60);
				return ['stats' => Q_WebServer_Metrics::recentStats(min($minutes, 1440))];
			case 'metrics/summary':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$qp = self::queryParams($parsed);
				$hours = (int) ($qp['hours'] ?? 24);
				return Q_WebServer_Metrics::summary(min($hours, 720));
			case 'metrics/flow':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$qp = self::queryParams($parsed);
				$limit = (int) ($qp['limit'] ?? 50);
				return ['edges' => Q_WebServer_Metrics::flow(min($limit, 200))];
			case 'metrics/pages':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$qp = self::queryParams($parsed);
				$limit = (int) ($qp['limit'] ?? 20);
				return ['pages' => Q_WebServer_Metrics::topPages(min($limit, 100))];
			case 'metrics/pageflow':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$qp = self::queryParams($parsed);
				$path = $qp['path'] ?? '/';
				return Q_WebServer_Metrics::pageFlow($path);
			case 'metrics/analytics':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$qp = self::queryParams($parsed);
				$filters = self::analyticsFilters($qp);
				return Q_WebServer_Metrics::analyticsOverview($filters);
			case 'metrics/analytics/flow':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$qp = self::queryParams($parsed);
				$filters = self::analyticsFilters($qp);
				$limit = (int) ($qp['limit'] ?? 50);
				return ['edges' => Q_WebServer_Metrics::analyticsFlow($filters, min($limit, 200))];
			case 'metrics/analytics/drilldown':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$qp = self::queryParams($parsed);
				$filters = self::analyticsFilters($qp);
				$path = $qp['path'] ?? '/';
				$dir = $qp['direction'] ?? 'outgoing';
				return [
					'path' => $path,
					'direction' => $dir,
					'edges' => Q_WebServer_Metrics::analyticsDrilldown($path, $dir, $filters)
				];
			case 'metrics/analytics/sessions':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$qp = self::queryParams($parsed);
				$filters = self::analyticsFilters($qp);
				$limit = (int) ($qp['limit'] ?? 50);
				$offset = (int) ($qp['offset'] ?? 0);
				return ['sessions' => Q_WebServer_Metrics::analyticsSessions($filters, min($limit, 100), $offset)];
			case 'metrics/analytics/session':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$qp = self::queryParams($parsed);
				$sid = $qp['id'] ?? '';
				return ['requests' => Q_WebServer_Metrics::sessionPath($sid)];
			case 'metrics/analytics/dates':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				return Q_WebServer_Metrics::analyticsDateRange() ?? ['from' => null, 'to' => null, 'total' => 0];
			case 'cache/clear':
				return self::apiClearCache();
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
			// ── Mobile Build ──────────────
			case 'mobile/toolchains':
				return self::apiMobileToolchains();
			case 'mobile/toolchain/install':
				return self::apiMobileToolchainInstall($parsed);
			case 'mobile/prepare':
				return self::apiMobilePrepare($parsed);
			case 'mobile/build':
				return self::apiMobileBuild($parsed);
			case 'mobile/builds':
				return self::apiMobileBuilds($parsed);
			case 'mobile/config':
				return self::apiMobileConfig($parsed);
			// ── Transport / Nearby ──────────────
			case 'transport/peers':
			case 'transport/register':
			case 'transport/unregister':
			case 'transport/heartbeat':
			case 'transport/message':
			case 'transport/event':
			case 'transport/config':
			case 'transport/status':
			case 'transport/connect':
			case 'transport/request':
				require_once dirname(__DIR__) . '/WebServer/Transport.php';
				$tAction = substr($route, 10); // strip 'transport/'
				$tData = !empty($parsed['body']) ? json_decode($parsed['body'], true) : array();
				if (!is_array($tData)) $tData = array();
				// $parsed['query'] is the raw query string, not an array
				$tQuery = $parsed['query'] ?? array();
				if (!is_array($tQuery)) { $qp = array(); parse_str((string) $tQuery, $qp); $tQuery = $qp; }
				$tData = array_merge($tData, $tQuery);
				return Q_WebServer_Transport::handleApi($tAction, $tData);
			default:
				// Prefix-matched routes (sub-paths with variable segments)
				if (strpos($route, 'client-metrics') === 0) {
					$cmFile = dirname(__DIR__) . '/WebServer/ClientMetrics.php';
					if (!lstat($cmFile)) {
						return array('status' => 404, 'error' => 'Client metrics not available');
					}
					require_once $cmFile;
					$subPath = substr($route, 14); // strip "client-metrics"
					$cmResult = Q_WebServer_ClientMetrics::handlePanelApi($subPath, $parsed);
					// handlePanelApi returns {status, body, headers} with
					// pre-encoded body — convert to handleApi format
					$cmHeaders = $cmResult['headers'] ?? array();
					$ct = $cmHeaders['Content-Type'] ?? 'application/json';
					unset($cmHeaders['Content-Type']);
					return array(
						'_raw' => true,
						'body' => $cmResult['body'] ?? '',
						'contentType' => $ct,
						'status' => $cmResult['status'] ?? 200,
						'headers' => $cmHeaders ?: null
					);
				}
				return array('status' => 404, 'error' => 'Unknown endpoint');
		}
	}

	private static function apiChangePassword($parsed)
	{
		$body = !empty($parsed['body'])
			? json_decode($parsed['body'], true) : array();
		$configPath = self::panelConfigPath();
		$config = json_decode(file_get_contents($configPath), true);
		if (!is_array($config)) {
			return array('error' => 'Panel config file is missing or corrupted');
		}

		$authResult = self::checkAuth($parsed);
		$currentUser = $authResult['user'] ?? 'owner';

		$oldPw = $body['oldPassword'] ?? '';
		$newPw = $body['newPassword'] ?? '';

		// Verify against the user's own password
		$users = $config['users'] ?? array();
		$userRec = $users[$currentUser] ?? null;
		$hashToCheck = $userRec ? $userRec['passwordHash'] : ($config['passwordHash'] ?? '');
		if (!password_verify($oldPw, $hashToCheck)) {
			return array('error' => 'Current password is wrong');
		}
		if (strlen($newPw) < 6) {
			return array('error' => 'New password must be at least 6 characters');
		}

		$newHash = password_hash($newPw, PASSWORD_DEFAULT);
		if ($userRec) {
			$config['users'][$currentUser]['passwordHash'] = $newHash;
		}
		if ($currentUser === 'owner') {
			$config['passwordHash'] = $newHash;
		}

		// Invalidate all sessions for this user except current
		$currentToken = self::extractToken($parsed);
		foreach ($config['sessions'] as $tk => $sess) {
			$sessUser = is_array($sess) ? ($sess['user'] ?? 'owner') : 'owner';
			if ($sessUser === $currentUser && $tk !== $currentToken) {
				unset($config['sessions'][$tk]);
			}
		}
		self::savePanelConfig($configPath, $config);
		return array('ok' => true);
	}

	/**
	 * Extract session token from request headers/cookies
	 */
	private static function extractToken($parsed)
	{
		$authH = $parsed['headers']['authorization'] ?? '';
		if (strpos($authH, 'Bearer ') === 0) {
			return substr($authH, 7);
		}
		$token = $parsed['headers']['x-panel-token'] ?? '';
		if ($token) return $token;
		return $parsed['cookies']['Q_panel_token'] ?? '';
	}

	private static function apiLogout($parsed)
	{
		$configPath = self::panelConfigPath();
		$config = json_decode(file_get_contents($configPath), true);
		$token = self::extractToken($parsed);
		if ($token && isset($config['sessions'][$token])) {
			unset($config['sessions'][$token]);
			self::savePanelConfig($configPath, $config);
		}
		return array('ok' => true);
	}

	// ── Auth Info ────────────────────────────────────────

	private static function apiAuthMe($parsed)
	{
		$auth = self::checkAuth($parsed);
		if (empty($auth['ok'])) return $auth;
		return array('user' => $auth['user'], 'role' => $auth['role']);
	}

	// ── User Management API ─────────────────────────────
	// Only owner/admin can manage users.

	private static function requireOwner($parsed)
	{
		$auth = self::checkAuth($parsed);
		if (empty($auth['ok'])) return $auth;
		if ($auth['role'] !== 'owner' && $auth['role'] !== 'admin') {
			return array('error' => 'Only owners and admins can manage users', 'status' => 403);
		}
		return null; // no error
	}

	private static function apiListUsers($parsed)
	{
		$err = self::requireOwner($parsed);
		if ($err) return $err;

		$configPath = self::panelConfigPath();
		$config = json_decode(file_get_contents($configPath), true);
		$users = $config['users'] ?? array();
		$result = array();
		foreach ($users as $uname => $urec) {
			$result[] = array(
				'username' => $uname,
				'role' => $urec['role'] ?? 'user',
				'created' => $urec['created'] ?? null,
				'branches' => $urec['branches'] ?? array(),
			);
		}
		return array('users' => $result);
	}

	private static function apiAddUser($parsed)
	{
		$err = self::requireOwner($parsed);
		if ($err) return $err;

		$body = !empty($parsed['body'])
			? json_decode($parsed['body'], true) : array();
		$username = $body['username'] ?? '';
		$password = $body['password'] ?? '';
		$role = $body['role'] ?? 'user';

		if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$/', $username)) {
			return array('error' => 'Invalid username: alphanumeric, dots, hyphens, underscores; 1-63 chars');
		}
		if ($username === 'owner') {
			return array('error' => 'Cannot create user named "owner" — that name is reserved');
		}
		if (strlen($password) < 6) {
			return array('error' => 'Password must be at least 6 characters');
		}
		if (!in_array($role, array('user', 'admin'), true)) {
			return array('error' => 'Role must be "user" or "admin"');
		}

		$configPath = self::panelConfigPath();
		$config = json_decode(file_get_contents($configPath), true);
		if (!isset($config['users'])) $config['users'] = array();
		if (isset($config['users'][$username])) {
			return array('error' => 'User already exists: ' . $username);
		}

		$config['users'][$username] = array(
			'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
			'role' => $role,
			'created' => time(),
			'branches' => $body['branches'] ?? array(),
		);
		self::savePanelConfig($configPath, $config);
		return array('ok' => true, 'username' => $username);
	}

	private static function apiUpdateUser($parsed)
	{
		$err = self::requireOwner($parsed);
		if ($err) return $err;

		$body = !empty($parsed['body'])
			? json_decode($parsed['body'], true) : array();
		$username = $body['username'] ?? '';

		$configPath = self::panelConfigPath();
		$config = json_decode(file_get_contents($configPath), true);
		if (!isset($config['users'][$username])) {
			return array('error' => 'User not found: ' . $username);
		}

		// Update role
		if (isset($body['role'])) {
			if ($username === 'owner') {
				return array('error' => 'Cannot change owner role');
			}
			if (!in_array($body['role'], array('user', 'admin'), true)) {
				return array('error' => 'Role must be "user" or "admin"');
			}
			$config['users'][$username]['role'] = $body['role'];
		}

		// Update password
		if (!empty($body['password'])) {
			if (strlen($body['password']) < 6) {
				return array('error' => 'Password must be at least 6 characters');
			}
			$config['users'][$username]['passwordHash'] = password_hash(
				$body['password'], PASSWORD_DEFAULT
			);
			// Invalidate that user's sessions
			foreach ($config['sessions'] as $tk => $sess) {
				$su = is_array($sess) ? ($sess['user'] ?? 'owner') : 'owner';
				if ($su === $username) unset($config['sessions'][$tk]);
			}
		}

		// Update branch access list
		if (isset($body['branches'])) {
			$config['users'][$username]['branches'] = $body['branches'];
		}

		self::savePanelConfig($configPath, $config);
		return array('ok' => true);
	}

	private static function apiRemoveUser($parsed)
	{
		$err = self::requireOwner($parsed);
		if ($err) return $err;

		$body = !empty($parsed['body'])
			? json_decode($parsed['body'], true) : array();
		$username = $body['username'] ?? '';

		if ($username === 'owner') {
			return array('error' => 'Cannot remove the owner account');
		}

		$configPath = self::panelConfigPath();
		$config = json_decode(file_get_contents($configPath), true);
		if (!isset($config['users'][$username])) {
			return array('error' => 'User not found: ' . $username);
		}
		unset($config['users'][$username]);
		// Invalidate sessions
		foreach ($config['sessions'] as $tk => $sess) {
			$su = is_array($sess) ? ($sess['user'] ?? 'owner') : 'owner';
			if ($su === $username) unset($config['sessions'][$tk]);
		}
		self::savePanelConfig($configPath, $config);
		return array('ok' => true);
	}

	// ── Branch Management API ───────────────────────────

	private static function apiBranchRequireAuth($parsed)
	{
		$auth = self::checkAuth($parsed);
		if (empty($auth['ok'])) return array(null, $auth);
		return array($auth, null);
	}

	private static function apiListBranches($parsed)
	{
		list($auth, $err) = self::apiBranchRequireAuth($parsed);
		if ($err) return $err;

		require_once dirname(__DIR__) . '/WebServer/Branch.php';
		$all = Q_WebServer_Branch::listBranches();
		$result = array();
		foreach ($all as $key => $rec) {
			// Non-owner users see only branches they have access to
			if ($auth['role'] !== 'owner' && $auth['role'] !== 'admin') {
				$access = $rec['access'] ?? array();
				if (!isset($access[$auth['user']]) && !isset($access['*'])) {
					continue;
				}
			}
			$parts = explode('/', $key, 2);
			$result[] = array(
				'key' => $key,
				'appHost' => $rec['appHost'] ?? ($parts[0] ?? ''),
				'name' => $parts[1] ?? '',
				'root' => $rec['root'] ?? '',
				'created' => $rec['created'] ?? null,
				'createdBy' => $rec['createdBy'] ?? null,
				'access' => $rec['access'] ?? array(),
				'db' => !empty($rec['db']) ? array('name' => $rec['db']['name'] ?? null) : null,
			);
		}
		return array('branches' => $result);
	}

	private static function apiBranchCreate($parsed)
	{
		list($auth, $err) = self::apiBranchRequireAuth($parsed);
		if ($err) return $err;

		$body = !empty($parsed['body'])
			? json_decode($parsed['body'], true) : array();
		$appHost = $body['appHost'] ?? '';
		$branchName = $body['branchName'] ?? '';

		if (!$appHost || !$branchName) {
			return array('error' => 'appHost and branchName are required');
		}

		// Non-owner users: branch name defaults to their username
		// and they can only create branches for themselves
		if ($auth['role'] !== 'owner' && $auth['role'] !== 'admin') {
			if ($branchName !== $auth['user']) {
				return array('error' => 'Users can only create their own branch (name must match username)');
			}
		}

		require_once dirname(__DIR__) . '/WebServer/Branch.php';

		// Determine which database to clone
		$cloneDb = $body['cloneDb'] ?? null;
		if (!$cloneDb) {
			$dbConfig = Q_WebServer_Branch::getDbConfig($appHost);
			$cloneDb = $dbConfig['cloneDb'] ?? $dbConfig['defaultCloneDb'] ?? null;
		}

		$options = array(
			'createdBy' => $auth['user'],
			'access' => array(
				$auth['user'] => array('branch' => 'edit', 'files' => 'frontend'),
			),
		);
		if ($cloneDb) {
			$options['cloneDb'] = $cloneDb;
		}

		// Owner/admin get admin access to the branch
		if ($auth['role'] === 'owner' || $auth['role'] === 'admin') {
			$options['access'][$auth['user']] = 'admin';
		}

		// If creating a branch for another user, give that user edit access
		if ($branchName !== $auth['user']
			&& !isset($options['access'][$branchName])
		) {
			$options['access'][$branchName] = array(
				'branch' => 'edit', 'files' => 'frontend',
			);
		}

		// Merge any explicit access from the request
		if (!empty($body['access'])) {
			$options['access'] = array_merge($options['access'], $body['access']);
		}

		$result = Q_WebServer_Branch::create($appHost, $branchName, $options);
		if (is_string($result)) {
			return array('error' => $result);
		}
		return array('ok' => true, 'branch' => $result);
	}

	private static function apiBranchDelete($parsed)
	{
		list($auth, $err) = self::apiBranchRequireAuth($parsed);
		if ($err) return $err;

		$body = !empty($parsed['body'])
			? json_decode($parsed['body'], true) : array();
		$appHost = $body['appHost'] ?? '';
		$branchName = $body['branchName'] ?? '';

		if (!$appHost || !$branchName) {
			return array('error' => 'appHost and branchName are required');
		}

		// Only owner/admin can delete branches
		if ($auth['role'] !== 'owner' && $auth['role'] !== 'admin') {
			// Users can delete their own branch
			if ($branchName !== $auth['user']) {
				return array('error' => 'Only admins can delete other users\' branches');
			}
		}

		require_once dirname(__DIR__) . '/WebServer/Branch.php';
		$result = Q_WebServer_Branch::delete($appHost, $branchName);
		if (is_string($result)) {
			return array('error' => $result);
		}
		return array('ok' => true);
	}

	private static function apiBranchAccess($parsed)
	{
		list($auth, $err) = self::apiBranchRequireAuth($parsed);
		if ($err) return $err;

		// Only owner/admin can change access
		if ($auth['role'] !== 'owner' && $auth['role'] !== 'admin') {
			return array('error' => 'Only owners and admins can manage branch access', 'status' => 403);
		}

		$body = !empty($parsed['body'])
			? json_decode($parsed['body'], true) : array();
		$appHost = $body['appHost'] ?? '';
		$branchName = $body['branchName'] ?? '';
		$access = $body['access'] ?? null;

		if (!$appHost || !$branchName || !is_array($access)) {
			return array('error' => 'appHost, branchName, and access are required');
		}

		require_once dirname(__DIR__) . '/WebServer/Branch.php';
		$branchKey = $appHost . '/' . $branchName;
		$rec = Q_WebServer_Branch::get($appHost, $branchName);
		if (!$rec) {
			return array('error' => 'Branch not found: ' . $branchKey);
		}

		Q_WebServer_Branch::update($appHost, $branchName, array('access' => $access));
		return array('ok' => true);
	}

	private static function apiBranchMerge($parsed)
	{
		list($auth, $err) = self::apiBranchRequireAuth($parsed);
		if ($err) return $err;

		if ($auth['role'] !== 'owner' && $auth['role'] !== 'admin') {
			return array('error' => 'Only owners and admins can merge branches', 'status' => 403);
		}

		$body = !empty($parsed['body'])
			? json_decode($parsed['body'], true) : array();
		$appHost = $body['appHost'] ?? '';
		$branchName = $body['branchName'] ?? '';

		if (!$appHost || !$branchName) {
			return array('error' => 'appHost and branchName are required');
		}

		require_once dirname(__DIR__) . '/WebServer/Branch.php';
		$result = Q_WebServer_Branch::merge($appHost, $branchName);
		if (is_string($result)) {
			return array('error' => $result);
		}
		return array('ok' => true, 'merged' => $result);
	}

	private static function apiBranchSwitchProduction($parsed)
	{
		list($auth, $err) = self::apiBranchRequireAuth($parsed);
		if ($err) return $err;

		if ($auth['role'] !== 'owner' && $auth['role'] !== 'admin') {
			return array('error' => 'Only owners and admins can switch production', 'status' => 403);
		}

		$body = !empty($parsed['body'])
			? json_decode($parsed['body'], true) : array();
		$appHost = $body['appHost'] ?? '';
		$branchName = $body['branchName'] ?? '';

		if (!$appHost || !$branchName) {
			return array('error' => 'appHost and branchName are required');
		}

		require_once dirname(__DIR__) . '/WebServer/Branch.php';
		$result = Q_WebServer_Branch::switchProduction($appHost, $branchName);
		if (is_string($result)) {
			return array('error' => $result);
		}
		return array('ok' => true, 'production' => $branchName);
	}

	private static function apiBranchMergeRequests($parsed)
	{
		list($auth, $err) = self::apiBranchRequireAuth($parsed);
		if ($err) return $err;

		if ($auth['role'] !== 'owner' && $auth['role'] !== 'admin') {
			return array('error' => 'Only owners and admins can view merge requests', 'status' => 403);
		}

		$body = !empty($parsed['body'])
			? json_decode($parsed['body'], true) : array();
		$appHost = $body['appHost'] ?? '';

		require_once dirname(__DIR__) . '/WebServer/Branch.php';
		$requests = array();
		$branches = Q_WebServer_Branch::listBranches();
		foreach ($branches as $key => $rec) {
			if ($appHost && ($rec['appHost'] ?? '') !== $appHost) continue;
			$mr = $rec['mergeRequests'] ?? array();
			foreach ($mr as $id => $req) {
				$req['id'] = $id;
				$req['branchKey'] = $key;
				$requests[] = $req;
			}
		}
		return array('mergeRequests' => $requests);
	}

	private static function apiBranchDbConfig($parsed)
	{
		list($auth, $err) = self::apiBranchRequireAuth($parsed);
		if ($err) return $err;

		if ($auth['role'] !== 'owner' && $auth['role'] !== 'admin') {
			return array('error' => 'Only owners and admins can configure database settings', 'status' => 403);
		}

		$body = !empty($parsed['body'])
			? json_decode($parsed['body'], true) : array();
		$appHost = $body['appHost'] ?? '';

		if (!$appHost) {
			return array('error' => 'appHost is required');
		}

		require_once dirname(__DIR__) . '/WebServer/Branch.php';

		// Read current config
		if ($parsed['method'] === 'GET' || empty($body['cloneDb'])) {
			return Q_WebServer_Branch::getDbConfig($appHost);
		}

		// Update clone DB config
		Q_WebServer_Branch::setDbConfig($appHost, $body['cloneDb'], $auth['user']);
		return array('ok' => true, 'cloneDb' => $body['cloneDb']);
	}

	/**
	 * Get or update the lockdown settings for a specific branch.
	 * Admin can relax restrictions by adding allowPaths, changing
	 * the default file tier, or adjusting sandbox settings.
	 */
	private static function apiBranchLockdown($parsed)
	{
		list($auth, $err) = self::apiBranchRequireAuth($parsed);
		if ($err) return $err;

		if ($auth['role'] !== 'owner' && $auth['role'] !== 'admin') {
			return array('error' => 'Only owners and admins can manage lockdown settings', 'status' => 403);
		}

		$body = !empty($parsed['body'])
			? json_decode($parsed['body'], true) : array();
		$appHost = $body['appHost'] ?? '';
		$branchName = $body['branchName'] ?? '';

		if (!$appHost || !$branchName) {
			return array('error' => 'appHost and branchName are required');
		}

		require_once dirname(__DIR__) . '/WebServer/Branch.php';
		$rec = Q_WebServer_Branch::get($appHost, $branchName);
		if (!$rec) {
			return array('error' => 'Branch not found');
		}

		// GET: return current lockdown settings
		if ($parsed['method'] === 'GET' || (
			!isset($body['sandbox']) && !isset($body['denyPaths'])
			&& !isset($body['defaultFileTier'])
		)) {
			return array(
				'ok' => true,
				'lockdown' => array(
					'defaultFileTier' => $rec['defaultFileTier'] ?? 'styles',
					'sandbox' => $rec['sandbox'] ?? array(),
					'denyPaths' => $rec['denyPaths'] ?? array(),
				),
			);
		}

		// POST: update lockdown settings
		$update = array();

		if (isset($body['defaultFileTier'])) {
			$tier = $body['defaultFileTier'];
			if (!isset(Q_WebServer_Branch::$tierLevel[$tier])) {
				return array('error' => "Invalid file tier: $tier");
			}
			$update['defaultFileTier'] = $tier;
		}

		if (isset($body['sandbox'])) {
			// Merge over existing sandbox config
			$existing = $rec['sandbox'] ?? array();
			$update['sandbox'] = array_merge($existing, $body['sandbox']);
		}

		if (isset($body['denyPaths'])) {
			if (!is_array($body['denyPaths'])) {
				return array('error' => 'denyPaths must be an array of glob patterns');
			}
			$update['denyPaths'] = $body['denyPaths'];
		}

		Q_WebServer_Branch::update($appHost, $branchName, $update);
		return array('ok' => true);
	}

	/**
	 * Get or update default lockdown settings for new branches (per-app).
	 * These are stored in Q.webserver config and apply to all branches
	 * created for the given app from this point forward.
	 */
	private static function apiBranchDefaults($parsed)
	{
		list($auth, $err) = self::apiBranchRequireAuth($parsed);
		if ($err) return $err;

		if ($auth['role'] !== 'owner' && $auth['role'] !== 'admin') {
			return array('error' => 'Only owners and admins can manage branch defaults', 'status' => 403);
		}

		$body = !empty($parsed['body'])
			? json_decode($parsed['body'], true) : array();
		$appHost = $body['appHost'] ?? '';

		if (!$appHost) {
			return array('error' => 'appHost is required');
		}

		require_once dirname(__DIR__) . '/WebServer/Branch.php';

		// GET: return current defaults
		$defaults = Q_WebServer_Branch::getDefaults($appHost);
		if ($parsed['method'] === 'GET' || (
			!isset($body['fileTier']) && !isset($body['sandbox'])
			&& !isset($body['denyPaths'])
		)) {
			return array('ok' => true, 'defaults' => $defaults);
		}

		// POST: update defaults in config
		// This stores the overrides in the branches state so they
		// persist without modifying the config files
		if (!Q_WebServer_Branch::$state) {
			Q_WebServer_Branch::loadState();
		}
		$appDefaultsKey = '_defaults/' . $appHost;
		$existing = Q_WebServer_Branch::$state[$appDefaultsKey] ?? array();

		if (isset($body['fileTier'])) {
			$tier = $body['fileTier'];
			if (!isset(Q_WebServer_Branch::$tierLevel[$tier])) {
				return array('error' => "Invalid file tier: $tier");
			}
			$existing['fileTier'] = $tier;
		}
		if (isset($body['sandbox'])) {
			$existing['sandbox'] = array_merge(
				$existing['sandbox'] ?? array(), $body['sandbox']
			);
		}
		if (isset($body['denyPaths'])) {
			$existing['denyPaths'] = $body['denyPaths'];
		}

		Q_WebServer_Branch::$state[$appDefaultsKey] = $existing;
		Q_WebServer_Branch::saveState();
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

			$iconPath = self::resolveAppIcon($appDir, $config);
			$fileBrowse = Q_Config::get('Q', 'webserver', 'panel', 'fileBrowser', $name, false);

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
				'hasIcon' => $iconPath !== null,
				'fileBrowse' => $fileBrowse,
			);
		}

		return array('apps' => $apps, 'appsDir' => $appsDir);
	}

	/**
	 * Resolve the icon/logo for an app directory.
	 * Checks in order: config Q.icon → web/img/logo.png → web/img/logo/* →
	 * web/favicon.ico → web/favicon.png. Returns null if nothing found locally.
	 * @method resolveAppIcon
	 * @static
	 * @param {string} $appDir Absolute path to the app directory
	 * @param {array|null} $config Parsed config/app.json, or null
	 * @return {string|null} Relative path within the app dir (e.g. "web/img/logo.png"), or null
	 */
	static function resolveAppIcon($appDir, $config = null)
	{
		// 1. Config override (future: Q.icon in config)
		$iconPath = $config['Q']['icon'] ?? null;
		if ($iconPath) {
			$full = $appDir . DS . $iconPath;
			if (is_file($full)) return $iconPath;
		}

		// 2. web/img/logo.png (exact)
		$webDir = $appDir . DS . 'web';
		$logo = $webDir . DS . 'img' . DS . 'logo.png';
		if (is_file($logo)) return 'web/img/logo.png';

		// 3. web/img/logo/* (prefer logo.png, then first image found)
		$logoDir = $webDir . DS . 'img' . DS . 'logo';
		if (is_dir($logoDir)) {
			if (is_file($logoDir . DS . 'logo.png')) return 'web/img/logo/logo.png';
			$exts = array('png', 'jpg', 'jpeg', 'svg', 'gif', 'webp', 'ico');
			foreach (scandir($logoDir) as $f) {
				if ($f[0] === '.') continue;
				$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
				if (in_array($ext, $exts)) {
					return 'web/img/logo/' . $f;
				}
			}
		}

		// 4. web/favicon.ico or web/favicon.png
		foreach (array('favicon.ico', 'favicon.png') as $fav) {
			if (is_file($webDir . DS . $fav)) return 'web/' . $fav;
		}

		return null;
	}

	/**
	 * Serve an app's icon file. Returns the raw bytes with appropriate content-type.
	 * @method apiAppIcon
	 * @static
	 */
	static function apiAppIcon($parsed)
	{
		$query = $parsed['query'] ?? '';
		if (is_string($query)) parse_str($query, $params);
		else $params = $query;
		$appDirName = basename($params['app'] ?? '');
		if (!$appDirName) return array('status' => 400, 'error' => 'app parameter required');

		$appsDir = self::appsDir();
		$appDir = $appsDir . DS . $appDirName;
		if (!is_dir($appDir)) return array('status' => 404, 'error' => 'App not found');

		$configFile = $appDir . DS . 'config' . DS . 'app.json';
		$config = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : null;
		$iconPath = self::resolveAppIcon($appDir, $config);

		if (!$iconPath) {
			// Return a default SVG icon
			return array(
				'_raw' => true,
				'contentType' => 'image/svg+xml',
				'body' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect width="48" height="48" rx="10" fill="#334155"/><text x="24" y="30" text-anchor="middle" fill="#94a3b8" font-size="20" font-family="system-ui">Q</text></svg>'
			);
		}

		$file = $appDir . DS . str_replace('/', DS, $iconPath);
		if (!is_file($file)) return array('status' => 404, 'error' => 'Icon file missing');

		$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
		$types = array(
			'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
			'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
			'ico' => 'image/x-icon'
		);
		$ct = $types[$ext] ?? 'application/octet-stream';

		return array(
			'_raw' => true,
			'contentType' => $ct,
			'body' => file_get_contents($file)
		);
	}

	/**
	 * List log files available for an app.
	 * Scans the app's configured logs directory.
	 * @method apiAppLogs
	 * @static
	 */
	static function apiAppLogs($parsed)
	{
		$query = $parsed['query'] ?? '';
		if (is_string($query)) parse_str($query, $params);
		else $params = $query;
		$appDirName = basename($params['app'] ?? '');
		$logFile = $params['file'] ?? '';
		$lines = max(1, min((int)($params['lines'] ?? 50), 500));

		if (!$appDirName) return array('status' => 400, 'error' => 'app parameter required');

		$appsDir = self::appsDir();
		$appDir = $appsDir . DS . $appDirName;
		if (!is_dir($appDir)) return array('status' => 404, 'error' => 'App not found');

		// Look for logs in several standard locations
		$logDirs = array();
		foreach (array('files/Q/logs', 'local/logs', 'logs') as $sub) {
			$d = $appDir . DS . str_replace('/', DS, $sub);
			if (is_dir($d)) $logDirs[$sub] = $d;
		}

		// If a specific file is requested, tail it
		if ($logFile) {
			// Security: prevent path traversal
			$logFile = str_replace('\\', '/', $logFile);
			if (strpos($logFile, '..') !== false) {
				return array('status' => 400, 'error' => 'Invalid path');
			}
			// Find the file in one of the log directories
			$found = null;
			foreach ($logDirs as $sub => $d) {
				$candidate = $d . DS . str_replace('/', DS, $logFile);
				if (is_file($candidate)) {
					$full = realpath($candidate);
					// Ensure it's actually inside the app dir
					if (strpos($full, realpath($appDir)) === 0) {
						$found = $full;
					}
					break;
				}
			}
			if (!$found) return array('lines' => array(), 'exists' => false);

			// Tail efficiently
			$result = array();
			$fp = fopen($found, 'r');
			if ($fp) {
				$size = filesize($found);
				$chunk = min($size, $lines * 512);
				fseek($fp, max(0, $size - $chunk));
				$content = fread($fp, $chunk);
				fclose($fp);
				$allLines = explode("\n", trim($content));
				$result = array_slice($allLines, -$lines);
			}
			return array('lines' => $result, 'exists' => true, 'size' => filesize($found));
		}

		// List available log files
		$files = array();
		foreach ($logDirs as $sub => $d) {
			self::scanLogDir($d, $sub, $files, $appDir);
		}
		// Sort by modification time descending
		usort($files, function($a, $b) { return $b['mtime'] - $a['mtime']; });

		return array('logDirs' => array_keys($logDirs), 'files' => $files);
	}

	/**
	 * Recursively scan a directory for log files.
	 * @method scanLogDir
	 * @static
	 * @param {string} $dir Directory to scan
	 * @param {string} $prefix Prefix for display paths
	 * @param {array} &$files Accumulator for found files
	 * @param {string} $appDir Root app dir for security check
	 * @param {int} $depth Current recursion depth
	 */
	private static function scanLogDir($dir, $prefix, &$files, $appDir, $depth = 0)
	{
		if ($depth > 3) return; // limit depth
		$logExts = array('log', 'txt', 'err', 'out');
		foreach (scandir($dir) as $f) {
			if ($f[0] === '.') continue;
			$full = $dir . DS . $f;
			if (is_dir($full)) {
				self::scanLogDir($full, $prefix . '/' . $f, $files, $appDir, $depth + 1);
			} elseif (is_file($full)) {
				$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
				// Include .log files and common log naming patterns
				if (in_array($ext, $logExts) || preg_match('/\.log\.\d+$/', $f)) {
					$files[] = array(
						'name' => $f,
						'path' => $prefix . '/' . $f,
						'size' => filesize($full),
						'mtime' => filemtime($full),
					);
				}
			}
		}
	}

	/**
	 * Browse files in an app directory. Only works if enabled in config
	 * for the specific app: Q.webserver.panel.fileBrowser.<appDirName> = true
	 * @method apiAppFiles
	 * @static
	 */
	static function apiAppFiles($parsed)
	{
		$query = $parsed['query'] ?? '';
		if (is_string($query)) parse_str($query, $params);
		else $params = $query;
		$appDirName = basename($params['app'] ?? '');
		$subPath = $params['path'] ?? '';

		if (!$appDirName) return array('status' => 400, 'error' => 'app parameter required');

		// Check config permission
		$allowed = Q_Config::get('Q', 'webserver', 'panel', 'fileBrowser', $appDirName, false);
		if (!$allowed) {
			return array('status' => 403, 'error' => 'File browsing not enabled for this app. Set Q.webserver.panel.fileBrowser.' . $appDirName . ' = true in config.');
		}

		$appsDir = self::appsDir();
		$appDir = $appsDir . DS . $appDirName;
		if (!is_dir($appDir)) return array('status' => 404, 'error' => 'App not found');

		// Security: prevent path traversal
		$subPath = str_replace('\\', '/', $subPath);
		if (strpos($subPath, '..') !== false) {
			return array('status' => 400, 'error' => 'Invalid path');
		}

		$target = $subPath ? $appDir . DS . str_replace('/', DS, $subPath) : $appDir;
		$realTarget = realpath($target);
		if (!$realTarget || strpos($realTarget, realpath($appDir)) !== 0) {
			return array('status' => 400, 'error' => 'Invalid path');
		}

		// If it's a file, return file info and contents (for text files)
		if (is_file($realTarget)) {
			$size = filesize($realTarget);
			$ext = strtolower(pathinfo($realTarget, PATHINFO_EXTENSION));
			$textExts = array('php','js','json','css','html','htm','txt','md','xml','yaml','yml',
				'ini','cfg','conf','log','sql','sh','bat','env','htaccess','gitignore','handlebars');
			$isText = in_array($ext, $textExts) || $size === 0;
			$content = null;
			if ($isText && $size < 524288) { // 512KB limit for text preview
				$content = file_get_contents($realTarget);
			}
			return array(
				'type' => 'file',
				'name' => basename($realTarget),
				'path' => $subPath,
				'size' => $size,
				'mtime' => filemtime($realTarget),
				'ext' => $ext,
				'isText' => $isText,
				'content' => $content,
			);
		}

		// It's a directory: list contents
		if (!is_dir($realTarget)) {
			return array('status' => 404, 'error' => 'Path not found');
		}

		$items = array();
		foreach (scandir($realTarget) as $f) {
			if ($f === '.') continue;
			$full = $realTarget . DS . $f;
			$isDir = is_dir($full);
			$items[] = array(
				'name' => $f,
				'isDir' => $isDir,
				'size' => $isDir ? null : filesize($full),
				'mtime' => filemtime($full),
			);
		}
		// Sort: directories first, then by name
		usort($items, function($a, $b) {
			if ($a['isDir'] !== $b['isDir']) return $b['isDir'] ? 1 : -1;
			return strcasecmp($a['name'], $b['name']);
		});

		return array(
			'type' => 'dir',
			'path' => $subPath,
			'items' => $items,
		);
	}

	/**
	 * Set forkPerRequest mode for an app.
	 * Writes to the app's local/app.json so it persists.
	 */
	static function apiSetForkMode($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$appDirName = basename($body['app'] ?? '');
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
		$body = json_decode($parsed['body'] ?? '{}', true) ?: [];
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
		$appName = basename($body['app'] ?? '');

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

		// Prevent path traversal
		$appName = basename($appName);
		$scriptName = basename($scriptName);

		$appDir = self::appsDir() . DS . $appName;
		if (!is_dir($appDir)) {
			return array('status' => 404, 'error' => "App '$appName' not found");
		}

		// Check app scripts/Q/ first, then platform scripts/
		$scriptPath = $appDir . DS . 'scripts' . DS . 'Q' . DS . $scriptName . '.php';
		if (!file_exists($scriptPath) && defined('Q_DIR')) {
			$scriptPath = Q_DIR . DS . 'scripts' . DS . $scriptName . '.php';
		}
		if (!file_exists($scriptPath)) {
			return array('status' => 404, 'error' => "Script '$scriptName' not found");
		}

		// Run script as subprocess
		$argStr = '';
		foreach ($args as $k => $v) {
			if (!is_scalar($v)) continue; // skip arrays/objects
			if (is_numeric($k)) {
				$argStr .= ' ' . escapeshellarg((string) $v);
			} else {
				// Sanitize key to prevent shell injection
				$k = preg_replace('/[^a-zA-Z0-9_-]/', '', $k);
				if ($k === '') continue;
				$argStr .= ' --' . $k . '=' . escapeshellarg((string) $v);
			}
		}

		$cmd = 'cd ' . escapeshellarg($appDir) . ' && '
			. PHP_BINARY . ' ' . escapeshellarg($scriptPath) . $argStr . ' 2>&1';
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
		$body = json_decode($parsed['body'] ?? '{}', true) ?: [];
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
		$body = json_decode($parsed['body'] ?? '{}', true) ?: [];
		$name = $body['name'] ?? '';
		$config = self::deployConfig();
		unset($config['targets'][$name]);
		self::saveDeployConfig($config);
		return array('ok' => true);
	}

	static function apiDeploy($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true) ?: [];
		$target = $body['target'] ?? '';
		$config = self::deployConfig();
		$t = $config['targets'][$target] ?? null;
		if (!$t) return array('error' => "Unknown target: $target");

		$baseDir = defined('APP_DIR') ? APP_DIR : Q_WebServer::$rootDir . '..';
		$dirs = $t['dirs'] ?? array('web', 'handlers', 'classes', 'config');
		$sshKey = !empty($t['key']) ? " -e " . escapeshellarg('ssh -i ' . $t['key']) : '';
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
		$body = json_decode($parsed['body'] ?? '{}', true) ?: [];
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
		$body = json_decode($parsed['body'] ?? '{}', true) ?: [];
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

	/**
	 * Clear all in-memory caches and re-read config files.
	 * @method apiClearCache
	 * @static
	 */
	static function apiClearCache()
	{
		$cleared = Q_WebServer::clearCache();
		return array(
			'ok' => true,
			'cleared' => $cleared,
			'timestamp' => date('c'),
		);
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
		$body = json_decode($parsed['body'] ?? '{}', true) ?: [];
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
		$body = json_decode($parsed['body'] ?? '{}', true) ?: [];
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
		$body = json_decode($parsed['body'] ?? '{}', true) ?: [];
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
		if (!empty($parsed['query']) && is_string($parsed['query'])) parse_str($parsed['query'], $query);
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
				$pluginJson = shell_exec("cd " . escapeshellarg($wpDir) . " && " . escapeshellarg($wpCli) . " plugin list --format=json 2>/dev/null");
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

				$themeJson = shell_exec("cd " . escapeshellarg($wpDir) . " && " . escapeshellarg($wpCli) . " theme list --format=json 2>/dev/null");
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
				$moduleJson = shell_exec("cd " . escapeshellarg($projectDir) . " && " . escapeshellarg($drush) . " pm:list --format=json 2>/dev/null");
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
			$wpCliSafe = escapeshellarg($wpCli);
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
					$cmd = "$wpCliSafe $type install " . escapeshellarg($package) . "$pathFlag"; break;
				case 'activate':
					$cmd = "$wpCliSafe $type activate " . escapeshellarg($package) . "$pathFlag"; break;
				case 'deactivate':
					$cmd = "$wpCliSafe $type deactivate " . escapeshellarg($package) . "$pathFlag"; break;
				case 'remove':
				case 'delete':
					$cmd = "$wpCliSafe $type delete " . escapeshellarg($package) . "$pathFlag"; break;
				case 'update':
					$cmd = "$wpCliSafe $type update " . escapeshellarg($package) . "$pathFlag"; break;
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
			$drushSafe = $drush ? escapeshellarg($drush) : null;

			switch ($action) {
				case 'install':
				case 'enable':
					if ($drushSafe) {
						$cmd = "$drushSafe pm:install " . escapeshellarg($package) . " -y";
					} else {
						$cmd = "composer require " . escapeshellarg("drupal/$package") . " --no-interaction";
					}
					break;
				case 'remove':
				case 'uninstall':
					if ($drushSafe) {
						$cmd = "$drushSafe pm:uninstall " . escapeshellarg($package) . " -y";
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
		$target = basename($body['target'] ?? '');

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

		// Bug 4 workaround: single-plugin install via -p can fail because
		// $Q_Bootstrap_config_plugin_limit=1 prevents the target plugin's
		// config from loading. Fall back to --plugins if -p failed.
		if ($action === 'plugin' && $output !== null
			&& (stripos($output, 'error') !== false || stripos($output, 'fatal') !== false)
		) {
			$fallbackFlags = '--plugins';
			$fallbackCmd = "cd " . escapeshellarg($appDir)
				. " && php " . escapeshellarg($installScript) . " $fallbackFlags 2>&1";
			$fallbackOutput = shell_exec($fallbackCmd);
			return [
				'output' => $fallbackOutput,
				'cmd' => "php scripts/Q/install.php $fallbackFlags",
				'note' => "Single-plugin install (-p $plugin) failed; retried with --plugins",
				'originalOutput' => $output,
			];
		}

		return ['output' => $output, 'cmd' => "php scripts/Q/install.php $flags"];
	}

	/**
	 * Run npm commands for Qbix plugins or the app.
	 */
	static function apiQbixNpm($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$action = $body['action'] ?? 'install';
		$target = basename($body['target'] ?? '');  // plugin name or 'app' or 'platform'

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
		// Normalize control characters (tabs, etc.) that break json_decode
		$raw = str_replace(array("\t", "\r"), array('  ', ''), $raw);
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
				// Also scan db/ subdirectory (standard Qbix layout)
				foreach (glob($dir . '/db/*.db') as $dbFile) {
					$dbPaths[] = $dbFile;
				}
				foreach (glob($dir . '/db/*.sqlite') as $dbFile) {
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
				$wpBin = self::which('wp') ? 'wp' : $projectDir . '/vendor/bin/wp';
				$cli = escapeshellarg($wpBin);
				$cwd = is_file($rootDir . 'wp-config.php') ? rtrim($rootDir, '/') : $projectDir;
				$cli .= ' --path=' . escapeshellarg($cwd);
				break;
			case 'drupal':
				$drushBin = self::which('drush') ? 'drush' : $projectDir . '/vendor/bin/drush';
				$cli = escapeshellarg($drushBin);
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
		$query = $parsed['query'] ?? '';
		$params = [];
		if (is_string($query) && $query !== '') {
			parse_str($query, $params);
		}
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

	// ── Mobile Build API ─────────────────────────────────

	static function apiMobileToolchains()
	{
		$tools = [];
		$isMac = PHP_OS_FAMILY === 'Darwin';

		// Xcode CLI tools
		$xcode = ['name' => 'Xcode CLI Tools', 'id' => 'xcode', 'platform' => 'ios'];
		if ($isMac) {
			$path = self::which('xcodebuild');
			if ($path) {
				$ver = trim(shell_exec('xcodebuild -version 2>/dev/null | head -1') ?? '');
				$xcode['installed'] = true;
				$xcode['version'] = $ver ?: 'installed';
				$xcode['path'] = $path;
			} else {
				$xcode['installed'] = false;
				$xcode['hint'] = 'xcode-select --install';
			}
		} else {
			$xcode['installed'] = false;
			$xcode['hint'] = 'Requires macOS';
			$xcode['unavailable'] = true;
		}
		$tools[] = $xcode;

		// CocoaPods
		$pods = ['name' => 'CocoaPods', 'id' => 'cocoapods', 'platform' => 'ios'];
		if ($isMac) {
			$path = self::which('pod');
			if ($path) {
				$ver = trim(shell_exec('pod --version 2>/dev/null') ?? '');
				$pods['installed'] = true;
				$pods['version'] = $ver ?: 'installed';
			} else {
				$pods['installed'] = false;
				$pods['hint'] = 'sudo gem install cocoapods';
			}
		} else {
			$pods['installed'] = false;
			$pods['unavailable'] = true;
		}
		$tools[] = $pods;

		// JDK
		$jdk = ['name' => 'JDK', 'id' => 'jdk', 'platform' => 'android'];
		$javaPath = self::which('javac');
		if ($javaPath) {
			$ver = trim(shell_exec('javac -version 2>&1') ?? '');
			$jdk['installed'] = true;
			$jdk['version'] = $ver ?: 'installed';
		} else {
			$jdk['installed'] = false;
			$jdk['hint'] = $isMac ? 'brew install openjdk' : 'apt install default-jdk';
		}
		$tools[] = $jdk;

		// Android SDK
		$sdk = ['name' => 'Android SDK', 'id' => 'android-sdk', 'platform' => 'android'];
		$androidHome = getenv('ANDROID_HOME') ?: getenv('ANDROID_SDK_ROOT') ?: '';
		if ($androidHome && is_dir($androidHome)) {
			$sdk['installed'] = true;
			$sdk['path'] = $androidHome;
			// Check for build-tools
			$btDir = $androidHome . '/build-tools';
			if (is_dir($btDir)) {
				$versions = array_filter(scandir($btDir), function($d) use ($btDir) {
					return $d !== '.' && $d !== '..' && is_dir($btDir . '/' . $d);
				});
				rsort($versions);
				$sdk['version'] = $versions ? 'Build-tools ' . reset($versions) : 'installed';
			} else {
				$sdk['version'] = 'installed (no build-tools)';
			}
		} else {
			// Check for sdkmanager in common locations
			$sdkman = self::which('sdkmanager');
			if ($sdkman) {
				$sdk['installed'] = true;
				$sdk['version'] = 'sdkmanager found';
				$sdk['path'] = dirname(dirname($sdkman));
			} else {
				$sdk['installed'] = false;
				$sdk['hint'] = 'Install Android Studio or use sdkmanager';
			}
		}
		$tools[] = $sdk;

		// Gradle
		$gradle = ['name' => 'Gradle', 'id' => 'gradle', 'platform' => 'android'];
		$gPath = self::which('gradle');
		if ($gPath) {
			$ver = trim(shell_exec('gradle --version 2>/dev/null | grep "^Gradle "') ?? '');
			$gradle['installed'] = true;
			$gradle['version'] = $ver ?: 'installed';
		} else {
			$gradle['installed'] = false;
			$gradle['hint'] = 'gradlew wrapper is bundled with prepared projects';
			$gradle['optional'] = true;
		}
		$tools[] = $gradle;

		// Node.js (needed for both)
		$node = ['name' => 'Node.js', 'id' => 'node', 'platform' => 'both'];
		$nPath = self::which('node');
		if ($nPath) {
			$ver = trim(shell_exec('node --version 2>/dev/null') ?? '');
			$node['installed'] = true;
			$node['version'] = $ver ?: 'installed';
		} else {
			$node['installed'] = false;
			$node['hint'] = 'Required for JS bundling';
		}
		$tools[] = $node;

		return ['toolchains' => $tools, 'platform' => PHP_OS_FAMILY];
	}

	static function apiMobileToolchainInstall($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$id = $body['id'] ?? '';
		$cmds = [
			'xcode' => 'xcode-select --install',
			'cocoapods' => 'sudo gem install cocoapods',
			'jdk' => PHP_OS_FAMILY === 'Darwin' ? 'brew install openjdk' : 'sudo apt install -y default-jdk',
		];
		if (!isset($cmds[$id])) {
			return ['status' => 400, 'error' => 'No install command for ' . $id];
		}
		$output = shell_exec($cmds[$id] . ' 2>&1');
		return ['output' => $output, 'cmd' => $cmds[$id]];
	}

	static function apiMobileConfig($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$appDir = basename($body['appDir'] ?? '');
		if (!$appDir) return ['status' => 400, 'error' => 'Missing appDir'];

		$appsDir = self::appsDir();
		if (!$appsDir) return ['status' => 400, 'error' => 'Apps directory not set'];
		$fullDir = $appsDir . DS . $appDir;
		if (!is_dir($fullDir)) return ['status' => 404, 'error' => 'App not found'];

		$configFile = $fullDir . DS . 'local' . DS . 'mobile.json';

		// GET — return current config
		if (empty($body['config'])) {
			$cfg = [];
			if (file_exists($configFile)) {
				$cfg = json_decode(file_get_contents($configFile), true) ?: [];
			}
			// Defaults
			if (empty($cfg['bundleId'])) {
				$cfg['bundleId'] = 'com.example.' . preg_replace('/[^a-z0-9]/', '', strtolower($appDir));
			}
			if (empty($cfg['appName'])) {
				$cfg['appName'] = $appDir;
			}
			if (empty($cfg['version'])) $cfg['version'] = '1.0.0';
			if (empty($cfg['buildNumber'])) $cfg['buildNumber'] = 1;

			// Check for existing prepared projects
			$cfg['iosPrepared'] = is_dir($fullDir . DS . 'mobile' . DS . 'ios');
			$cfg['androidPrepared'] = is_dir($fullDir . DS . 'mobile' . DS . 'android');

			return ['config' => $cfg];
		}

		// POST — save config
		@mkdir(dirname($configFile), 0755, true);
		$cfg = $body['config'];
		$save = [
			'bundleId' => $cfg['bundleId'] ?? '',
			'appName' => $cfg['appName'] ?? $appDir,
			'version' => $cfg['version'] ?? '1.0.0',
			'buildNumber' => intval($cfg['buildNumber'] ?? 1),
		];
		file_put_contents($configFile, json_encode($save, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		return ['saved' => true, 'config' => $save];
	}

	static function apiMobilePrepare($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$appDir = basename($body['appDir'] ?? '');
		$platform = $body['platform'] ?? '';
		if (!$appDir || !in_array($platform, ['ios', 'android'])) {
			return ['status' => 400, 'error' => 'Missing appDir or platform'];
		}
		$appsDir = self::appsDir();
		if (!$appsDir) return ['status' => 400, 'error' => 'Apps directory not set'];
		$fullDir = $appsDir . DS . $appDir;
		if (!is_dir($fullDir)) return ['status' => 404, 'error' => 'App not found'];

		// Load config
		$configFile = $fullDir . DS . 'local' . DS . 'mobile.json';
		$cfg = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
		$bundleId = $cfg['bundleId'] ?? 'com.example.' . preg_replace('/[^a-z0-9]/', '', strtolower($appDir));
		$appName = $cfg['appName'] ?? $appDir;
		$version = $cfg['version'] ?? '1.0.0';
		$buildNum = intval($cfg['buildNumber'] ?? 1);

		$mobileDir = $fullDir . DS . 'mobile' . DS . $platform;
		@mkdir($mobileDir, 0755, true);

		$log = [];

		if ($platform === 'ios') {
			return self::prepareIos($mobileDir, $fullDir, $bundleId, $appName, $version, $buildNum);
		} else {
			return self::prepareAndroid($mobileDir, $fullDir, $bundleId, $appName, $version, $buildNum);
		}
	}

	private static function prepareIos($mobileDir, $appDir, $bundleId, $appName, $version, $buildNum)
	{
		$log = [];
		$safeName = preg_replace('/[^A-Za-z0-9_]/', '', $appName) ?: 'QbixApp';

		// Source dirs
		$srcDir = $mobileDir . DS . $safeName;
		$resDir = $srcDir . DS . 'Resources';
		$pharDir = $srcDir . DS . 'Server';
		@mkdir($srcDir, 0755, true);
		@mkdir($resDir, 0755, true);
		@mkdir($pharDir, 0755, true);

		// ── xcodegen project.yml ───────────────────────
		// Generates a real .xcodeproj via `xcodegen generate`
		$bundlePrefix = implode('.', array_slice(explode('.', $bundleId), 0, -1));
		$projectYml = "name: {$safeName}\noptions:\n"
			. "  bundleIdPrefix: {$bundlePrefix}\n"
			. "  deploymentTarget:\n    iOS: '15.0'\n"
			. "targets:\n  {$safeName}:\n    type: application\n    platform: iOS\n"
			. "    sources:\n      - path: {$safeName}\n"
			. "        excludes:\n          - '**/*.phar'\n          - Resources\n          - Server\n"
			. "    resources:\n      - path: {$safeName}/Resources\n      - path: {$safeName}/Server\n"
			. "    settings:\n      base:\n"
			. "        PRODUCT_BUNDLE_IDENTIFIER: {$bundleId}\n"
			. "        MARKETING_VERSION: '{$version}'\n"
			. "        CURRENT_PROJECT_VERSION: '{$buildNum}'\n"
			. "        INFOPLIST_FILE: {$safeName}/Info.plist\n"
			. "        SWIFT_VERSION: '5.9'\n"
			. "        GENERATE_INFOPLIST_FILE: false\n"
			. "        CODE_SIGN_STYLE: Automatic\n";
		file_put_contents($mobileDir . DS . 'project.yml', $projectYml);
		$log[] = 'Wrote project.yml (xcodegen spec)';

		// ── Info.plist ─────────────────────────────────
		$escapedName = htmlspecialchars($appName);
		$plist = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n"
			. '<plist version="1.0">' . "\n" . '<dict>' . "\n"
			. "\t<key>CFBundleDevelopmentRegion</key><string>en</string>\n"
			. "\t<key>CFBundleInfoDictionaryVersion</key><string>6.0</string>\n"
			. "\t<key>CFBundleIdentifier</key><string>$(PRODUCT_BUNDLE_IDENTIFIER)</string>\n"
			. "\t<key>CFBundleName</key><string>$(PRODUCT_NAME)</string>\n"
			. "\t<key>CFBundleDisplayName</key><string>{$escapedName}</string>\n"
			. "\t<key>CFBundleShortVersionString</key><string>$(MARKETING_VERSION)</string>\n"
			. "\t<key>CFBundleVersion</key><string>$(CURRENT_PROJECT_VERSION)</string>\n"
			. "\t<key>CFBundleExecutable</key><string>$(EXECUTABLE_NAME)</string>\n"
			. "\t<key>CFBundlePackageType</key><string>$(PRODUCT_BUNDLE_PACKAGE_TYPE)</string>\n"
			. "\t<key>LSRequiresIPhoneOS</key><true/>\n"
			. "\t<key>UILaunchStoryboardName</key><string>LaunchScreen</string>\n"
			. "\t<key>UIRequiredDeviceCapabilities</key>\n\t<array><string>arm64</string></array>\n"
			. "\t<key>UISupportedInterfaceOrientations</key>\n\t<array>\n"
			. "\t\t<string>UIInterfaceOrientationPortrait</string>\n"
			. "\t\t<string>UIInterfaceOrientationLandscapeLeft</string>\n"
			. "\t\t<string>UIInterfaceOrientationLandscapeRight</string>\n\t</array>\n"
			. "\t<key>UIBackgroundModes</key>\n\t<array>\n"
			. "\t\t<string>audio</string>\n"
			. "\t\t<string>bluetooth-central</string>\n"
			. "\t\t<string>bluetooth-peripheral</string>\n\t</array>\n"
			. "\t<key>NSLocalNetworkUsageDescription</key>\n"
			. "\t<string>Connects with nearby devices on your network.</string>\n"
			. "\t<key>NSBonjourServices</key>\n\t<array><string>_qbix-server._tcp</string></array>\n"
			. "\t<key>NSBluetoothAlwaysUsageDescription</key>\n"
			. "\t<string>Connects with nearby devices over Bluetooth.</string>\n"
			. "\t<key>NSBluetoothPeripheralUsageDescription</key>\n"
			. "\t<string>Advertises this device to nearby peers over Bluetooth.</string>\n"
			. "\t<key>NSAppTransportSecurity</key>\n\t<dict>\n"
			. "\t\t<key>NSAllowsLocalNetworking</key><true/>\n\t</dict>\n"
			. '</dict>' . "\n" . '</plist>';
		file_put_contents($srcDir . DS . 'Info.plist', $plist);
		$log[] = 'Wrote Info.plist';

		// ── LaunchScreen.storyboard ────────────────────
		$launch = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<document type="com.apple.InterfaceBuilder3.CocoaTouch.Storyboard.XIB" version="3.0"'
			. ' toolsVersion="21701" targetRuntime="AppleSDK" propertyAccessControl="none"'
			. ' useAutolayout="YES" launchScreen="YES" useTraitCollections="YES"'
			. ' useSafeAreas="YES" colorMatched="YES" initialViewController="01J-lp-oVM">' . "\n"
			. '<scenes><scene sceneID="EHf-IW-A2E"><objects>' . "\n"
			. '<viewController id="01J-lp-oVM" sceneMemberID="viewController">' . "\n"
			. '<view key="view" contentMode="scaleToFill" id="Ze5-6b-2t3">' . "\n"
			. '<rect key="frame" x="0" y="0" width="393" height="852"/>' . "\n"
			. '<autoresizingMask key="autoresizingMask" widthSizable="YES" heightSizable="YES"/>' . "\n"
			. '<color key="backgroundColor" systemColor="systemBackgroundColor"/>' . "\n"
			. '</view></viewController>' . "\n"
			. '<placeholder placeholderIdentifier="IBFirstResponder" id="iYj-Kq-Ea1"'
			. ' userLabel="First Responder" sceneMemberID="firstResponder"/>' . "\n"
			. '</objects></scene></scenes></document>';
		file_put_contents($resDir . DS . 'LaunchScreen.storyboard', $launch);
		$log[] = 'Wrote LaunchScreen.storyboard';

		// ── PhpBridge.swift ─────────────────────────────
		$phpBridge = 'import Foundation' . "\n"
			. '#if canImport(Darwin)' . "\n"
			. 'import Darwin' . "\n"
			. '#endif' . "\n\n"
			. '/// Unpacks the bundled Qbix Server phar and starts PHP on localhost.' . "\n"
			. '///' . "\n"
			. '/// The CI builds a `qbixserver-ios-arm64` micro binary (a self-executing' . "\n"
			. '/// PHP+phar combo produced by static-php-cli). This bridge copies it from' . "\n"
			. '/// the app bundle to a writable directory, then launches it via posix_spawn' . "\n"
			. '/// listening on 127.0.0.1:<port>.' . "\n"
			. '///' . "\n"
			. '/// Uses posix_spawn instead of Foundation.Process because Process is' . "\n"
			. '/// unavailable on iOS. posix_spawn is available on both iOS and macOS.' . "\n"
			. '///' . "\n"
			. '/// If the micro binary is not bundled (dev builds), falls back to looking' . "\n"
			. '/// for a system `php` on PATH (Simulator only).' . "\n"
			. 'class PhpBridge {' . "\n"
			. '    static let shared = PhpBridge()' . "\n\n"
			. '    private var pid: pid_t = 0' . "\n"
			. '    private(set) var port: UInt16 = 0' . "\n"
			. '    private let serverDir: URL' . "\n\n"
			. '    private init() {' . "\n"
			. '        let docs = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0]' . "\n"
			. '        serverDir = docs.appendingPathComponent("qbix-server", isDirectory: true)' . "\n"
			. '        try? FileManager.default.createDirectory(at: serverDir, withIntermediateDirectories: true)' . "\n"
			. '    }' . "\n\n"
			. '    /// Start the PHP server. Returns the port it listens on.' . "\n"
			. '    func start() -> UInt16 {' . "\n"
			. '        if pid > 0 { return port }' . "\n"
			. '        port = findFreePort()' . "\n\n"
			. '        let bundle = Bundle.main' . "\n"
			. '        var phpExe = ""' . "\n"
			. '        var args: [String] = []' . "\n\n"
			. '        // Option 1: micro binary (self-executing phar, production)' . "\n"
			. '        if let microPath = bundle.path(forResource: "qbixserver-ios-arm64", ofType: nil)' . "\n"
			. '            ?? bundle.path(forResource: "qbixserver", ofType: nil) {' . "\n"
			. '            let dest = serverDir.appendingPathComponent("qbixserver").path' . "\n"
			. '            try? FileManager.default.removeItem(atPath: dest)' . "\n"
			. '            try? FileManager.default.copyItem(atPath: microPath, toPath: dest)' . "\n"
			. '            _ = chmod(dest, 0o755)' . "\n"
			. '            phpExe = dest' . "\n"
			. '            args = [dest, "-S", "127.0.0.1:\\(port)"]' . "\n"
			. '        }' . "\n"
			. '        // Option 2: phar + system php (dev/simulator)' . "\n"
			. '        else if let pharPath = bundle.path(forResource: "qbixserver", ofType: "phar") {' . "\n"
			. '            let dest = serverDir.appendingPathComponent("qbixserver.phar").path' . "\n"
			. '            try? FileManager.default.removeItem(atPath: dest)' . "\n"
			. '            try? FileManager.default.copyItem(atPath: pharPath, toPath: dest)' . "\n"
			. '            phpExe = "/usr/bin/php"' . "\n"
			. '            args = [phpExe, dest, "-S", "127.0.0.1:\\(port)"]' . "\n"
			. '        } else {' . "\n"
			. '            print("[PhpBridge] No server binary or phar found in bundle")' . "\n"
			. '            return 0' . "\n"
			. '        }' . "\n\n"
			. '        // Copy web assets from bundle' . "\n"
			. '        if let srcPath = bundle.path(forResource: "src", ofType: nil) {' . "\n"
			. '            let destSrc = serverDir.appendingPathComponent("src").path' . "\n"
			. '            try? FileManager.default.removeItem(atPath: destSrc)' . "\n"
			. '            try? FileManager.default.copyItem(atPath: srcPath, toPath: destSrc)' . "\n"
			. '        }' . "\n\n"
			. '        // Launch via posix_spawn (Process is unavailable on iOS)' . "\n"
			. '        var cArgs = args.map { strdup($0) } + [nil]' . "\n"
			. '        defer { cArgs.forEach { if let p = $0 { free(p) } } }' . "\n\n"
			. '        // Set working directory via file actions' . "\n"
			. '        var fileActions: posix_spawn_file_actions_t?' . "\n"
			. '        posix_spawn_file_actions_init(&fileActions)' . "\n"
			. '        defer { posix_spawn_file_actions_destroy(&fileActions) }' . "\n\n"
			. '        // Redirect stdout/stderr to /dev/null in production' . "\n"
			. '        let devNull = open("/dev/null", O_WRONLY)' . "\n"
			. '        if devNull >= 0 {' . "\n"
			. '            posix_spawn_file_actions_adddup2(&fileActions, devNull, STDOUT_FILENO)' . "\n"
			. '            posix_spawn_file_actions_adddup2(&fileActions, devNull, STDERR_FILENO)' . "\n"
			. '        }' . "\n\n"
			. '        var spawnPid: pid_t = 0' . "\n"
			. '        let oldDir = FileManager.default.currentDirectoryPath' . "\n"
			. '        FileManager.default.changeCurrentDirectoryPath(serverDir.path)' . "\n"
			. '        let env = ProcessInfo.processInfo.environment.map { "\($0.key)=\($0.value)" }' . "\n"
			. '        var cEnv = env.map { strdup($0) } + [nil]' . "\n"
			. '        defer { cEnv.forEach { if let p = $0 { free(p) } } }' . "\n"
			. '        let result = posix_spawn(&spawnPid, phpExe, &fileActions, nil, &cArgs, &cEnv)' . "\n"
			. '        FileManager.default.changeCurrentDirectoryPath(oldDir)' . "\n"
			. '        if devNull >= 0 { close(devNull) }' . "\n\n"
			. '        if result != 0 {' . "\n"
			. '            print("[PhpBridge] posix_spawn failed: \\(result)")' . "\n"
			. '            return 0' . "\n"
			. '        }' . "\n"
			. '        pid = spawnPid' . "\n"
			. '        print("[PhpBridge] Started on 127.0.0.1:\\(port), pid=\\(pid)")' . "\n\n"
			. '        // Wait for server to accept connections (up to 3 seconds)' . "\n"
			. '        waitForServer()' . "\n"
			. '        return port' . "\n"
			. '    }' . "\n\n"
			. '    func stop() {' . "\n"
			. '        if pid > 0 {' . "\n"
			. '            kill(pid, SIGTERM)' . "\n"
			. '            pid = 0' . "\n"
			. '        }' . "\n"
			. '    }' . "\n\n"
			. '    /// Poll until the server accepts a TCP connection, or timeout.' . "\n"
			. '    private func waitForServer() {' . "\n"
			. '        for _ in 0..<30 {' . "\n"
			. '            let fd = socket(AF_INET, SOCK_STREAM, 0)' . "\n"
			. '            guard fd >= 0 else { Thread.sleep(forTimeInterval: 0.1); continue }' . "\n"
			. '            var addr = sockaddr_in()' . "\n"
			. '            addr.sin_len = UInt8(MemoryLayout<sockaddr_in>.size)' . "\n"
			. '            addr.sin_family = sa_family_t(AF_INET)' . "\n"
			. '            addr.sin_port = port.bigEndian' . "\n"
			. '            addr.sin_addr.s_addr = INADDR_LOOPBACK.bigEndian' . "\n"
			. '            let ok = withUnsafePointer(to: &addr) {' . "\n"
			. '                $0.withMemoryRebound(to: sockaddr.self, capacity: 1) {' . "\n"
			. '                    connect(fd, $0, socklen_t(MemoryLayout<sockaddr_in>.size))' . "\n"
			. '                }' . "\n"
			. '            }' . "\n"
			. '            close(fd)' . "\n"
			. '            if ok == 0 { return }' . "\n"
			. '            Thread.sleep(forTimeInterval: 0.1)' . "\n"
			. '        }' . "\n"
			. '        print("[PhpBridge] Timeout waiting for server on port \\(port)")' . "\n"
			. '    }' . "\n\n"
			. '    private func findFreePort() -> UInt16 {' . "\n"
			. '        var addr = sockaddr_in()' . "\n"
			. '        addr.sin_len = UInt8(MemoryLayout<sockaddr_in>.size)' . "\n"
			. '        addr.sin_family = sa_family_t(AF_INET)' . "\n"
			. '        addr.sin_port = 0' . "\n"
			. '        addr.sin_addr.s_addr = INADDR_LOOPBACK.bigEndian' . "\n"
			. '        let fd = socket(AF_INET, SOCK_STREAM, 0)' . "\n"
			. '        guard fd >= 0 else { return 8080 }' . "\n"
			. '        defer { close(fd) }' . "\n"
			. '        var bindAddr = addr' . "\n"
			. '        let bindResult = withUnsafePointer(to: &bindAddr) {' . "\n"
			. '            $0.withMemoryRebound(to: sockaddr.self, capacity: 1) {' . "\n"
			. '                bind(fd, $0, socklen_t(MemoryLayout<sockaddr_in>.size))' . "\n"
			. '            }' . "\n"
			. '        }' . "\n"
			. '        guard bindResult == 0 else { return 8080 }' . "\n"
			. '        var nameLen = socklen_t(MemoryLayout<sockaddr_in>.size)' . "\n"
			. '        withUnsafeMutablePointer(to: &bindAddr) {' . "\n"
			. '            $0.withMemoryRebound(to: sockaddr.self, capacity: 1) {' . "\n"
			. '                getsockname(fd, $0, &nameLen)' . "\n"
			. '            }' . "\n"
			. '        }' . "\n"
			. '        return UInt16(bigEndian: bindAddr.sin_port)' . "\n"
			. '    }' . "\n"
			. '}' . "\n";
		file_put_contents($srcDir . DS . 'PhpBridge.swift', $phpBridge);
		$log[] = 'Wrote PhpBridge.swift';

		// ── AppDelegate.swift ──────────────────────────
		$appDelegate = 'import UIKit' . "\n\n"
			. '@main' . "\n"
			. 'class AppDelegate: UIResponder, UIApplicationDelegate {' . "\n"
			. '    var window: UIWindow?' . "\n\n"
			. '    func application(_ application: UIApplication,' . "\n"
			. '                     didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?) -> Bool {' . "\n"
			. '        let port = PhpBridge.shared.start()' . "\n"
			. '        guard port > 0 else {' . "\n"
			. '            print("Failed to start PHP server")' . "\n"
			. '            return true' . "\n"
			. '        }' . "\n\n"
			. '        // Start transport manager for peer discovery' . "\n"
			. '        TransportManager.shared.start(port: Int(port))' . "\n\n"
			. '        // Keep alive in background via silent audio' . "\n"
			. '        BackgroundKeepAlive.shared.start()' . "\n\n"
			. '        let frame: CGRect' . "\n"
			. '        if let scene = UIApplication.shared.connectedScenes.first as? UIWindowScene {' . "\n"
			. '            frame = scene.screen.bounds' . "\n"
			. '        } else {' . "\n"
			. '            frame = UIScreen.main.bounds' . "\n"
			. '        }' . "\n"
			. '        window = UIWindow(frame: frame)' . "\n"
			. '        let vc = WebViewController(port: port)' . "\n"
			. '        window?.rootViewController = vc' . "\n"
			. '        window?.makeKeyAndVisible()' . "\n"
			. '        return true' . "\n"
			. '    }' . "\n\n"
			. '    func applicationWillTerminate(_ application: UIApplication) {' . "\n"
			. '        TransportManager.shared.stop()' . "\n"
			. '        PhpBridge.shared.stop()' . "\n"
			. '    }' . "\n"
			. '}' . "\n";
		file_put_contents($srcDir . DS . 'AppDelegate.swift', $appDelegate);
		$log[] = 'Wrote AppDelegate.swift';

		// ── WebViewController.swift ────────────────────
		$webVC = 'import UIKit' . "\n" . 'import WebKit' . "\n\n"
			. 'class WebViewController: UIViewController, WKNavigationDelegate {' . "\n"
			. '    private var webView: WKWebView!' . "\n"
			. '    private let port: UInt16' . "\n\n"
			. '    init(port: UInt16) {' . "\n"
			. '        self.port = port' . "\n"
			. '        super.init(nibName: nil, bundle: nil)' . "\n"
			. '    }' . "\n"
			. '    required init?(coder: NSCoder) { fatalError() }' . "\n\n"
			. '    override func viewDidLoad() {' . "\n"
			. '        super.viewDidLoad()' . "\n"
			. '        let config = WKWebViewConfiguration()' . "\n"
			. '        config.allowsInlineMediaPlayback = true' . "\n"
			. '        webView = WKWebView(frame: view.bounds, configuration: config)' . "\n"
			. '        webView.autoresizingMask = [.flexibleWidth, .flexibleHeight]' . "\n"
			. '        webView.navigationDelegate = self' . "\n"
			. '        webView.scrollView.contentInsetAdjustmentBehavior = .automatic' . "\n"
			. '        view.addSubview(webView)' . "\n"
			. '        let url = URL(string: "http://127.0.0.1:\\(port)/")!' . "\n"
			. '        webView.load(URLRequest(url: url))' . "\n"
			. '    }' . "\n\n"
			. '    override var prefersStatusBarHidden: Bool { false }' . "\n"
			. '    override var preferredStatusBarStyle: UIStatusBarStyle { .default }' . "\n\n"
			. '    // Open external links in Safari' . "\n"
			. '    func webView(_ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction,' . "\n"
			. '                 decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {' . "\n"
			. '        if let url = navigationAction.request.url,' . "\n"
			. '           url.host != "127.0.0.1" && url.scheme?.hasPrefix("http") == true {' . "\n"
			. '            UIApplication.shared.open(url)' . "\n"
			. '            decisionHandler(.cancel)' . "\n"
			. '        } else {' . "\n"
			. '            decisionHandler(.allow)' . "\n"
			. '        }' . "\n"
			. '    }' . "\n"
			. '}' . "\n";
		file_put_contents($srcDir . DS . 'WebViewController.swift', $webVC);
		$log[] = 'Wrote WebViewController.swift';

		// Copy transport files from mobile/ios/ if available
		$mobileRoot = defined('Q_DIR') ? Q_DIR . DS . 'mobile' . DS . 'ios' : null;
		$hasTransport = false;
		if ($mobileRoot && is_dir($mobileRoot)) {
			foreach (['TransportManager.swift', 'BackgroundKeepAlive.swift'] as $f) {
				if (file_exists($mobileRoot . DS . $f)) {
					copy($mobileRoot . DS . $f, $srcDir . DS . $f);
					$log[] = 'Copied ' . $f;
					$hasTransport = true;
				}
			}
		}
		if (!$hasTransport) {
			// Generate minimal stubs so AppDelegate compiles without the full transport layer
			$stubTM = 'import Foundation' . "\n\n"
				. '/// Minimal stub — replace with the full TransportManager from mobile/ios/' . "\n"
				. 'class TransportManager {' . "\n"
				. '    static let shared = TransportManager()' . "\n"
				. '    func start(port: Int) {}' . "\n"
				. '    func stop() {}' . "\n"
				. '}' . "\n";
			file_put_contents($srcDir . DS . 'TransportManager.swift', $stubTM);
			$stubKA = 'import Foundation' . "\n\n"
				. '/// Minimal stub — replace with the full BackgroundKeepAlive from mobile/ios/' . "\n"
				. 'class BackgroundKeepAlive {' . "\n"
				. '    static let shared = BackgroundKeepAlive()' . "\n"
				. '    func start() {}' . "\n"
				. '    func stop() {}' . "\n"
				. '}' . "\n";
			file_put_contents($srcDir . DS . 'BackgroundKeepAlive.swift', $stubKA);
			$log[] = 'Wrote TransportManager/BackgroundKeepAlive stubs (replace with full versions from mobile/ios/)';
		}

		// ── .gitignore ─────────────────────────────────
		file_put_contents($mobileDir . DS . '.gitignore',
			"*.xcodeproj\n*.xcworkspace\nPods/\nbuild/\nDerivedData/\n.DS_Store\n");
		$log[] = 'Wrote .gitignore';

		// Try to generate .xcodeproj via xcodegen
		$xcodegen = self::which('xcodegen');
		if ($xcodegen) {
			$out = shell_exec('cd ' . escapeshellarg($mobileDir) . ' && xcodegen generate 2>&1');
			$log[] = 'Ran xcodegen: ' . trim($out);
		} else {
			$log[] = 'Install xcodegen to auto-generate .xcodeproj: brew install xcodegen';
			$log[] = 'Then run: cd ' . $mobileDir . ' && xcodegen generate';
		}

		return ['prepared' => true, 'platform' => 'ios', 'path' => $mobileDir, 'log' => $log];
	}

	private static function prepareAndroid($mobileDir, $appDir, $bundleId, $appName, $version, $buildNum)
	{
		$log = [];

		// app/src/main/java/<package>/
		$pkgPath = str_replace('.', DS, $bundleId);
		$javaDir = $mobileDir . DS . 'app' . DS . 'src' . DS . 'main' . DS . 'java' . DS . $pkgPath;
		$resDir = $mobileDir . DS . 'app' . DS . 'src' . DS . 'main' . DS . 'res' . DS . 'values';
		$assetsDir = $mobileDir . DS . 'app' . DS . 'src' . DS . 'main' . DS . 'assets';
		@mkdir($javaDir, 0755, true);
		@mkdir($resDir, 0755, true);
		@mkdir($assetsDir, 0755, true);

		// settings.gradle.kts (Kotlin DSL — modern default)
		$settings = "pluginManagement {\n"
			. "    repositories {\n"
			. "        google()\n"
			. "        mavenCentral()\n"
			. "        gradlePluginPortal()\n"
			. "    }\n"
			. "}\n"
			. "dependencyResolutionManagement {\n"
			. "    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)\n"
			. "    repositories {\n"
			. "        google()\n"
			. "        mavenCentral()\n"
			. "    }\n"
			. "}\n\n"
			. "rootProject.name = " . json_encode($appName) . "\n"
			. "include(\":app\")\n";
		file_put_contents($mobileDir . DS . 'settings.gradle.kts', $settings);
		$log[] = 'Wrote settings.gradle.kts';

		// Top-level build.gradle.kts
		$topGradle = "plugins {\n"
			. "    id(\"com.android.application\") version \"8.2.2\" apply false\n"
			. "    id(\"org.jetbrains.kotlin.android\") version \"1.9.22\" apply false\n"
			. "}\n";
		file_put_contents($mobileDir . DS . 'build.gradle.kts', $topGradle);
		$log[] = 'Wrote build.gradle.kts (top-level)';

		// app/build.gradle.kts
		$appGradle = "plugins {\n"
			. "    id(\"com.android.application\")\n"
			. "    id(\"org.jetbrains.kotlin.android\")\n"
			. "}\n\n"
			. "android {\n"
			. "    namespace = " . json_encode($bundleId) . "\n"
			. "    compileSdk = 34\n"
			. "    defaultConfig {\n"
			. "        applicationId = " . json_encode($bundleId) . "\n"
			. "        minSdk = 26\n"
			. "        targetSdk = 34\n"
			. "        versionCode = " . $buildNum . "\n"
			. "        versionName = " . json_encode($version) . "\n"
			. "    }\n"
			. "    buildTypes {\n"
			. "        release { isMinifyEnabled = false }\n"
			. "    }\n"
			. "    compileOptions {\n"
			. "        sourceCompatibility = JavaVersion.VERSION_17\n"
			. "        targetCompatibility = JavaVersion.VERSION_17\n"
			. "    }\n"
			. "    kotlinOptions { jvmTarget = \"17\" }\n"
			. "    // Include the phar and src/ in APK assets\n"
			. "    sourceSets {\n"
			. "        getByName(\"main\") {\n"
			. "            assets.srcDirs(\"src/main/assets\")\n"
			. "        }\n"
			. "    }\n"
			. "}\n\n"
			. "dependencies {\n"
			. "    implementation(\"androidx.core:core-ktx:1.12.0\")\n"
			. "    implementation(\"androidx.appcompat:appcompat:1.6.1\")\n"
			. "    implementation(\"androidx.webkit:webkit:1.9.0\")\n"
			. "}\n";
		@mkdir($mobileDir . DS . 'app', 0755, true);
		file_put_contents($mobileDir . DS . 'app' . DS . 'build.gradle.kts', $appGradle);
		$log[] = 'Wrote app/build.gradle.kts';

		// AndroidManifest.xml
		$escapedName = htmlspecialchars($appName);
		$manifest = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
			. '<manifest xmlns:android="http://schemas.android.com/apk/res/android">' . "\n"
			. '    <uses-permission android:name="android.permission.INTERNET" />' . "\n"
			. '    <uses-permission android:name="android.permission.ACCESS_NETWORK_STATE" />' . "\n"
			. '    <uses-permission android:name="android.permission.BLUETOOTH" android:maxSdkVersion="30" />' . "\n"
			. '    <uses-permission android:name="android.permission.BLUETOOTH_ADMIN" android:maxSdkVersion="30" />' . "\n"
			. '    <uses-permission android:name="android.permission.ACCESS_FINE_LOCATION" android:maxSdkVersion="30" />' . "\n"
			. '    <uses-permission android:name="android.permission.BLUETOOTH_CONNECT" />' . "\n"
			. '    <uses-permission android:name="android.permission.BLUETOOTH_ADVERTISE" />' . "\n"
			. '    <uses-permission android:name="android.permission.BLUETOOTH_SCAN" android:usesPermissionFlags="neverForLocation" />' . "\n"
			. '    <uses-permission android:name="android.permission.NEARBY_WIFI_DEVICES" android:usesPermissionFlags="neverForLocation" />' . "\n"
			. '    <uses-permission android:name="android.permission.ACCESS_WIFI_STATE" />' . "\n"
			. '    <uses-permission android:name="android.permission.FOREGROUND_SERVICE" />' . "\n"
			. '    <uses-permission android:name="android.permission.FOREGROUND_SERVICE_DATA_SYNC" />' . "\n"
			. '    <uses-permission android:name="android.permission.POST_NOTIFICATIONS" />' . "\n"
			. '    <uses-feature android:name="android.hardware.bluetooth_le" android:required="false" />' . "\n"
			. '    <application' . "\n"
			. '        android:label="' . $escapedName . '"' . "\n"
			. '        android:icon="@mipmap/ic_launcher"' . "\n"
			. '        android:usesCleartextTraffic="true"' . "\n"
			. '        android:theme="@style/Theme.AppCompat.Light.NoActionBar">' . "\n"
			. '        <activity android:name=".MainActivity" android:exported="true">' . "\n"
			. '            <intent-filter>' . "\n"
			. '                <action android:name="android.intent.action.MAIN" />' . "\n"
			. '                <category android:name="android.intent.category.LAUNCHER" />' . "\n"
			. '            </intent-filter>' . "\n"
			. '        </activity>' . "\n"
			. '        <service android:name=".QbixServerService"' . "\n"
			. '            android:foregroundServiceType="dataSync" android:exported="false" />' . "\n"
			. '    </application>' . "\n"
			. '</manifest>';
		@mkdir($mobileDir . DS . 'app' . DS . 'src' . DS . 'main', 0755, true);
		file_put_contents($mobileDir . DS . 'app' . DS . 'src' . DS . 'main' . DS . 'AndroidManifest.xml', $manifest);
		$log[] = 'Wrote AndroidManifest.xml';

		// PhpBridge.kt — unpacks phar from assets and starts PHP process
		$phpBridge = "package {$bundleId}\n\n"
			. "import android.content.Context\n"
			. "import java.io.File\n"
			. "import java.io.FileOutputStream\n"
			. "import java.net.ServerSocket\n\n"
			. "/**\n"
			. " * Extracts the Qbix Server phar/binary from APK assets and starts it\n"
			. " * as a subprocess on 127.0.0.1:<port>.\n"
			. " *\n"
			. " * The CI produces either a standalone micro binary (qbixserver-android-arm64)\n"
			. " * or a .phar that needs a PHP runtime. The micro binary is self-contained.\n"
			. " */\n"
			. "object PhpBridge {\n"
			. "    private var process: java.lang.Process? = null\n"
			. "    var port: Int = 0\n"
			. "        private set\n\n"
			. "    fun start(context: Context): Int {\n"
			. "        if (process != null) return port\n"
			. "        port = findFreePort()\n\n"
			. "        val serverDir = File(context.filesDir, \"qbix-server\")\n"
			. "        serverDir.mkdirs()\n\n"
			. "        // Try micro binary first, then phar\n"
			. "        val binaryName = \"qbixserver-android-arm64\"\n"
			. "        val pharName = \"qbixserver.phar\"\n"
			. "        val exe = extractAsset(context, binaryName, serverDir)\n"
			. "            ?: extractAsset(context, pharName, serverDir)\n"
			. "        if (exe == null) {\n"
			. "            android.util.Log.e(\"PhpBridge\", \"No server binary or phar in assets\")\n"
			. "            return 0\n"
			. "        }\n"
			. "        exe.setExecutable(true)\n\n"
			. "        // Extract src/ and web/ directories\n"
			. "        extractDir(context, \"src\", serverDir)\n"
			. "        extractDir(context, \"web\", serverDir)\n\n"
			. "        val cmd = if (exe.name.endsWith(\".phar\")) {\n"
			. "            // phar needs a PHP runtime — look for one on PATH or bundled\n"
			. "            val phpBin = File(serverDir, \"php\").takeIf { it.exists() }?.absolutePath ?: \"php\"\n"
			. "            listOf(phpBin, exe.absolutePath, \"-S\", \"127.0.0.1:\$port\")\n"
			. "        } else {\n"
			. "            listOf(exe.absolutePath, \"-S\", \"127.0.0.1:\$port\")\n"
			. "        }\n\n"
			. "        val pb = ProcessBuilder(cmd)\n"
			. "            .directory(serverDir)\n"
			. "            .redirectErrorStream(true)\n"
			. "        process = pb.start()\n"
			. "        android.util.Log.i(\"PhpBridge\", \"Started on 127.0.0.1:\$port\")\n"
			. "        return port\n"
			. "    }\n\n"
			. "    fun stop() {\n"
			. "        process?.destroy()\n"
			. "        process = null\n"
			. "    }\n\n"
			. "    private fun extractAsset(context: Context, name: String, dir: File): File? {\n"
			. "        return try {\n"
			. "            val dest = File(dir, name)\n"
			. "            context.assets.open(name).use { input ->\n"
			. "                FileOutputStream(dest).use { output -> input.copyTo(output) }\n"
			. "            }\n"
			. "            dest\n"
			. "        } catch (e: Exception) { null }\n"
			. "    }\n\n"
			. "    private fun extractDir(context: Context, dirName: String, dest: File) {\n"
			. "        try {\n"
			. "            val files = context.assets.list(dirName) ?: return\n"
			. "            val targetDir = File(dest, dirName)\n"
			. "            targetDir.mkdirs()\n"
			. "            for (f in files) {\n"
			. "                val sub = \"\$dirName/\$f\"\n"
			. "                val subFiles = context.assets.list(sub)\n"
			. "                if (subFiles != null && subFiles.isNotEmpty()) {\n"
			. "                    extractDir(context, sub, dest)\n"
			. "                } else {\n"
			. "                    context.assets.open(sub).use { input ->\n"
			. "                        FileOutputStream(File(targetDir, f)).use { output -> input.copyTo(output) }\n"
			. "                    }\n"
			. "                }\n"
			. "            }\n"
			. "        } catch (_: Exception) {}\n"
			. "    }\n\n"
			. "    private fun findFreePort(): Int {\n"
			. "        return try {\n"
			. "            ServerSocket(0).use { it.localPort }\n"
			. "        } catch (_: Exception) { 8080 }\n"
			. "    }\n"
			. "}\n";
		file_put_contents($javaDir . DS . 'PhpBridge.kt', $phpBridge);
		$log[] = 'Wrote PhpBridge.kt';

		// MainActivity.kt
		$mainActivity = "package {$bundleId}\n\n"
			. "import android.content.Intent\n"
			. "import android.os.Bundle\n"
			. "import android.webkit.WebView\n"
			. "import android.webkit.WebViewClient\n"
			. "import android.webkit.WebChromeClient\n"
			. "import android.webkit.WebSettings\n"
			. "import androidx.appcompat.app.AppCompatActivity\n"
			. "import kotlin.concurrent.thread\n\n"
			. "class MainActivity : AppCompatActivity() {\n"
			. "    private lateinit var webView: WebView\n\n"
			. "    override fun onCreate(savedInstanceState: Bundle?) {\n"
			. "        super.onCreate(savedInstanceState)\n\n"
			. "        // Set up WebView first (shows blank while server starts)\n"
			. "        webView = WebView(this)\n"
			. "        webView.settings.javaScriptEnabled = true\n"
			. "        webView.settings.domStorageEnabled = true\n"
			. "        webView.settings.mixedContentMode = WebSettings.MIXED_CONTENT_ALWAYS_ALLOW\n"
			. "        webView.webViewClient = WebViewClient()\n"
			. "        webView.webChromeClient = WebChromeClient()\n"
			. "        setContentView(webView)\n\n"
			. "        // Start PHP server off the main thread to avoid ANR\n"
			. "        thread {\n"
			. "            val port = PhpBridge.start(this)\n"
			. "            if (port <= 0) return@thread\n\n"
			. "            // Wait for server to accept connections (up to 3s)\n"
			. "            for (i in 0 until 30) {\n"
			. "                try {\n"
			. "                    java.net.Socket(\"127.0.0.1\", port).close()\n"
			. "                    break\n"
			. "                } catch (_: Exception) { Thread.sleep(100) }\n"
			. "            }\n\n"
			. "            runOnUiThread {\n"
			. "                // Start foreground service + transport manager\n"
			. "                val intent = Intent(this, QbixServerService::class.java)\n"
			. "                intent.putExtra(QbixServerService.EXTRA_PORT, port)\n"
			. "                startForegroundService(intent)\n\n"
			. "                webView.loadUrl(\"http://127.0.0.1:\$port/\")\n"
			. "            }\n"
			. "        }\n"
			. "    }\n\n"
			. "    @Suppress(\"DEPRECATION\")\n"
			. "    override fun onBackPressed() {\n"
			. "        if (webView.canGoBack()) {\n"
			. "            webView.goBack()\n"
			. "        } else {\n"
			. "            super.onBackPressed()\n"
			. "        }\n"
			. "    }\n\n"
			. "    override fun onDestroy() {\n"
			. "        stopService(android.content.Intent(this, QbixServerService::class.java))\n"
			. "        PhpBridge.stop()\n"
			. "        super.onDestroy()\n"
			. "    }\n"
			. "}\n";
		file_put_contents($javaDir . DS . 'MainActivity.kt', $mainActivity);
		$log[] = 'Wrote MainActivity.kt';

		// Copy transport files from mobile/android/ if available
		$mobileRoot = defined('Q_DIR') ? Q_DIR . DS . 'mobile' . DS . 'android' : null;
		if ($mobileRoot && is_dir($mobileRoot)) {
			foreach (['TransportManager.kt', 'QbixServerService.kt'] as $f) {
				if (file_exists($mobileRoot . DS . $f)) {
					$content = file_get_contents($mobileRoot . DS . $f);
					// Rewrite package declaration
					$content = preg_replace('/^package\s+\S+/m', 'package ' . $bundleId, $content, 1);
					// Rewrite imports referencing the original package
					$content = str_replace('com.qbix.server.transport.', $bundleId . '.', $content);
					$content = str_replace('com.qbix.server.', $bundleId . '.', $content);
					file_put_contents($javaDir . DS . $f, $content);
					$log[] = 'Copied ' . $f;
				}
			}
		} else {
			$log[] = 'Note: Copy TransportManager.kt and QbixServerService.kt from mobile/android/ into ' . $pkgPath . '/';
		}

		// QbixServerService.kt (minimal version if not copied)
		if (!file_exists($javaDir . DS . 'QbixServerService.kt')) {
			$svc = "package {$bundleId}\n\n"
				. "import android.app.*\nimport android.content.Intent\n"
				. "import android.content.pm.ServiceInfo\nimport android.os.Build\n"
				. "import android.os.IBinder\n"
				. "import androidx.core.app.NotificationCompat\n\n"
				. "class QbixServerService : Service() {\n"
				. "    companion object {\n"
				. "        const val CHANNEL_ID = \"qbix_server\"\n"
				. "        const val NOTIFICATION_ID = 1\n"
				. "        const val EXTRA_PORT = \"port\"\n"
				. "    }\n\n"
				. "    override fun onCreate() {\n"
				. "        super.onCreate()\n"
				. "        val channel = NotificationChannel(CHANNEL_ID, \"Qbix Server\",\n"
				. "            NotificationManager.IMPORTANCE_LOW)\n"
				. "        getSystemService(NotificationManager::class.java)?.createNotificationChannel(channel)\n"
				. "    }\n\n"
				. "    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {\n"
				. "        val port = intent?.getIntExtra(EXTRA_PORT, 8080) ?: 8080\n"
				. "        val notification = NotificationCompat.Builder(this, CHANNEL_ID)\n"
				. "            .setContentTitle(\"Qbix Server\")\n"
				. "            .setContentText(\"Running on port \$port\")\n"
				. "            .setSmallIcon(android.R.drawable.ic_dialog_info)\n"
				. "            .setOngoing(true).build()\n"
				. "        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {\n"
				. "            startForeground(NOTIFICATION_ID, notification,\n"
				. "                ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC)\n"
				. "        } else {\n"
				. "            startForeground(NOTIFICATION_ID, notification)\n"
				. "        }\n"
				. "        return START_STICKY\n"
				. "    }\n\n"
				. "    override fun onBind(intent: Intent?): IBinder? = null\n"
				. "}\n";
			file_put_contents($javaDir . DS . 'QbixServerService.kt', $svc);
			$log[] = 'Wrote QbixServerService.kt (minimal)';
		}

		// strings.xml — Android XML escapes apostrophes with backslash, not HTML entities
		$androidName = str_replace(
			["&", "<", ">", "'", "\""],
			["&amp;", "&lt;", "&gt;", "\\'", "&quot;"],
			$appName
		);
		$strings = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
			. '<resources><string name="app_name">' . $androidName . '</string></resources>';
		file_put_contents($resDir . DS . 'strings.xml', $strings);
		$log[] = 'Wrote res/values/strings.xml';

		// Generate a default launcher icon (simple colored circle with first letter)
		$mipmapDir = dirname($resDir) . DS . 'mipmap-hdpi';
		@mkdir($mipmapDir, 0755, true);
		$iconXml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
			. '<vector xmlns:android="http://schemas.android.com/apk/res/android"' . "\n"
			. '    android:width="108dp" android:height="108dp"' . "\n"
			. '    android:viewportWidth="108" android:viewportHeight="108">' . "\n"
			. '    <path android:fillColor="#4A90D9"' . "\n"
			. '        android:pathData="M54,54m-40,0a40,40 0,1,1 80,0a40,40 0,1,1 -80,0" />' . "\n"
			. '</vector>';
		$drawableDir = dirname($resDir) . DS . 'drawable';
		@mkdir($drawableDir, 0755, true);
		file_put_contents($drawableDir . DS . 'ic_launcher.xml', $iconXml);
		// Provide a mipmap alias so @mipmap/ic_launcher resolves
		file_put_contents($mipmapDir . DS . 'ic_launcher.xml', $iconXml);
		$log[] = 'Wrote default launcher icon';

		// gradle.properties
		file_put_contents($mobileDir . DS . 'gradle.properties',
			"android.useAndroidX=true\norg.gradle.jvmargs=-Xmx2048m\n");
		$log[] = 'Wrote gradle.properties';

		// Gradle wrapper
		$wrapperDir = $mobileDir . DS . 'gradle' . DS . 'wrapper';
		@mkdir($wrapperDir, 0755, true);
		file_put_contents($wrapperDir . DS . 'gradle-wrapper.properties',
			"distributionBase=GRADLE_USER_HOME\ndistributionPath=wrapper/dists\n"
			. "distributionUrl=https\\://services.gradle.org/distributions/gradle-8.5-bin.zip\n"
			. "zipStoreBase=GRADLE_USER_HOME\nzipStorePath=wrapper/dists\n");
		$log[] = 'Wrote gradle-wrapper.properties';

		// gradlew script — lightweight wrapper that uses system Gradle or downloads it
		$gradlew = '#!/bin/sh' . "\n"
			. 'set -e' . "\n"
			. 'APP_HOME=$(cd "$(dirname "$0")" && pwd -P)' . "\n"
			. 'GRADLE_VERSION="8.5"' . "\n"
			. 'GRADLE_DIR="$HOME/.gradle/wrapper/dists/gradle-${GRADLE_VERSION}-bin"' . "\n"
			. 'GRADLE_ZIP_URL="https://services.gradle.org/distributions/gradle-${GRADLE_VERSION}-bin.zip"' . "\n"
			. '' . "\n"
			. '# Try system gradle first' . "\n"
			. 'if command -v gradle >/dev/null 2>&1; then' . "\n"
			. '    exec gradle --project-dir "$APP_HOME" "$@"' . "\n"
			. 'fi' . "\n"
			. '' . "\n"
			. '# Download Gradle if not cached' . "\n"
			. 'if [ ! -d "$GRADLE_DIR/gradle-${GRADLE_VERSION}" ]; then' . "\n"
			. '    echo "Downloading Gradle ${GRADLE_VERSION}..."' . "\n"
			. '    mkdir -p "$GRADLE_DIR"' . "\n"
			. '    TMPZIP=$(mktemp)' . "\n"
			. '    curl -fsSL "$GRADLE_ZIP_URL" -o "$TMPZIP" || wget -q "$GRADLE_ZIP_URL" -O "$TMPZIP"' . "\n"
			. '    unzip -qo "$TMPZIP" -d "$GRADLE_DIR"' . "\n"
			. '    rm -f "$TMPZIP"' . "\n"
			. 'fi' . "\n"
			. '' . "\n"
			. 'exec "$GRADLE_DIR/gradle-${GRADLE_VERSION}/bin/gradle" --project-dir "$APP_HOME" "$@"' . "\n";
		file_put_contents($mobileDir . DS . 'gradlew', $gradlew);
		chmod($mobileDir . DS . 'gradlew', 0755);
		$log[] = 'Wrote gradlew';

		// .gitignore
		file_put_contents($mobileDir . DS . '.gitignore',
			".gradle/\nbuild/\napp/build/\nlocal.properties\n*.apk\n*.aab\n.DS_Store\n");
		$log[] = 'Wrote .gitignore';

		return ['prepared' => true, 'platform' => 'android', 'path' => $mobileDir, 'log' => $log];
	}



	static function apiMobileBuild($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$appDir = basename($body['appDir'] ?? '');
		$platform = $body['platform'] ?? '';
		$buildType = $body['buildType'] ?? 'debug';
		if (!$appDir || !in_array($platform, ['ios', 'android'])) {
			return ['status' => 400, 'error' => 'Missing appDir or platform'];
		}
		$appsDir = self::appsDir();
		if (!$appsDir) return ['status' => 400, 'error' => 'Apps directory not set'];
		$fullDir = $appsDir . DS . $appDir;
		$mobileDir = $fullDir . DS . 'mobile' . DS . $platform;
		if (!is_dir($mobileDir)) {
			return ['status' => 400, 'error' => 'Project not prepared. Run Prepare first.'];
		}

		$configFile = $fullDir . DS . 'local' . DS . 'mobile.json';
		$cfg = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
		$appName = $cfg['appName'] ?? $appDir;
		$log = [];
		$artifact = null;

		if ($platform === 'ios') {
			if (!self::which('xcodebuild')) {
				return ['status' => 400, 'error' => 'xcodebuild not found — install Xcode CLI tools'];
			}
			$safeName = preg_replace('/[^A-Za-z0-9_]/', '', $appName) ?: 'QbixApp';
			$xcodeproj = $mobileDir . DS . $safeName . '.xcodeproj';
			if (!is_dir($xcodeproj)) {
				return ['status' => 400, 'error' => $safeName . '.xcodeproj not found. Run xcodegen first: cd ' . $mobileDir . ' && xcodegen generate'];
			}
			$scheme = $safeName;
			$archiveDir = $mobileDir . DS . 'build';
			@mkdir($archiveDir, 0755, true);

			$sdk = ($buildType === 'release') ? 'iphoneos' : 'iphonesimulator';
			$cmd = 'cd ' . escapeshellarg($mobileDir)
				. ' && xcodebuild -project ' . escapeshellarg($safeName . '.xcodeproj')
				. ' -scheme ' . escapeshellarg($scheme)
				. ' -configuration ' . ($buildType === 'release' ? 'Release' : 'Debug')
				. ' -sdk ' . $sdk
				. ' -derivedDataPath ' . escapeshellarg($archiveDir)
				. ' build 2>&1';
			$output = shell_exec($cmd);
			$success = strpos($output, '** BUILD SUCCEEDED **') !== false;

			if ($success) {
				// Find the .app
				$appPath = trim(shell_exec('find ' . escapeshellarg($archiveDir)
					. ' -name "*.app" -type d 2>/dev/null | head -1') ?? '');
				if ($appPath) $artifact = $appPath;
			}

			$log[] = $output;
			self::recordMobileBuild($fullDir, $platform, $buildType, $success, $artifact);
			return ['success' => $success, 'platform' => 'ios', 'output' => $output, 'artifact' => $artifact];

		} else {
			// Android — use gradlew if available, else gradle
			$gradlew = $mobileDir . DS . 'gradlew';
			if (file_exists($gradlew)) {
				chmod($gradlew, 0755);
			}
			$gradleCmd = file_exists($gradlew) ? './gradlew' : 'gradle';
			$task = $buildType === 'release' ? 'assembleRelease' : 'assembleDebug';

			$cmd = 'cd ' . escapeshellarg($mobileDir) . ' && ' . $gradleCmd . ' ' . $task . ' 2>&1';
			$output = shell_exec($cmd);
			$success = strpos($output, 'BUILD SUCCESSFUL') !== false;

			if ($success) {
				$apkPath = trim(shell_exec('find ' . escapeshellarg($mobileDir)
					. '/app/build/outputs -name "*.apk" -type f 2>/dev/null | head -1') ?? '');
				if ($apkPath) $artifact = $apkPath;
			}

			$log[] = $output;
			self::recordMobileBuild($fullDir, $platform, $buildType, $success, $artifact);
			return ['success' => $success, 'platform' => 'android', 'output' => $output, 'artifact' => $artifact];
		}
	}

	private static function recordMobileBuild($appFullDir, $platform, $buildType, $success, $artifact)
	{
		$histFile = $appFullDir . DS . 'local' . DS . 'mobile-builds.json';
		$history = file_exists($histFile) ? json_decode(file_get_contents($histFile), true) : [];
		if (!is_array($history)) $history = [];
		$history[] = [
			'platform' => $platform,
			'buildType' => $buildType,
			'success' => $success,
			'artifact' => $artifact,
			'timestamp' => date('c'),
		];
		// Keep last 50
		if (count($history) > 50) $history = array_slice($history, -50);
		@mkdir(dirname($histFile), 0755, true);
		file_put_contents($histFile, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	static function apiMobileBuilds($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$qp = self::queryParams($parsed);
		$appDir = basename($body['appDir'] ?? ($qp['appDir'] ?? ''));
		if (!$appDir) return ['status' => 400, 'error' => 'Missing appDir'];
		$appsDir = self::appsDir();
		if (!$appsDir) return ['builds' => [], 'artifacts' => []];
		$fullDir = $appsDir . DS . $appDir;

		$histFile = $fullDir . DS . 'local' . DS . 'mobile-builds.json';
		$history = file_exists($histFile) ? json_decode(file_get_contents($histFile), true) : [];

		// Gather current artifacts
		$artifacts = [];
		foreach (['ios', 'android'] as $p) {
			$buildDir = $fullDir . DS . 'mobile' . DS . $p . DS . 'build';
			if (!is_dir($buildDir)) {
				$buildDir = $fullDir . DS . 'mobile' . DS . $p . DS . 'app' . DS . 'build' . DS . 'outputs';
			}
			if (!is_dir($buildDir)) continue;
			$ext = $p === 'ios' ? '*.app' : '*.apk';
			$found = trim(shell_exec('find ' . escapeshellarg($buildDir) . ' -name "' . $ext . '" 2>/dev/null | head -5') ?? '');
			foreach (array_filter(explode("\n", $found)) as $path) {
				$artifacts[] = [
					'platform' => $p,
					'path' => $path,
					'size' => is_file($path) ? filesize($path) : null,
					'modified' => is_file($path) ? date('c', filemtime($path)) : null,
				];
			}
		}

		return ['builds' => $history ?: [], 'artifacts' => $artifacts];
	}

	/**
	 * Parse $parsed['query'] into an associative array regardless of whether
	 * the webserver layer passes it as a raw string or already-parsed array.
	 */
	static function queryParams($parsed)
	{
		$q = $parsed['query'] ?? '';
		if (is_array($q)) return $q;
		$p = [];
		if (is_string($q) && $q !== '') parse_str($q, $p);
		return $p;
	}

	/**
	 * Extract analytics filter parameters from query params.
	 */
	private static function analyticsFilters($qp)
	{
		$filters = [];
		if (!empty($qp['from'])) $filters['from'] = (int) $qp['from'];
		if (!empty($qp['to'])) $filters['to'] = (int) $qp['to'];
		if (!empty($qp['host'])) $filters['host'] = $qp['host'];
		if (!empty($qp['platform'])) $filters['platform'] = $qp['platform'];
		if (!empty($qp['browser'])) $filters['browser'] = $qp['browser'];
		if (!empty($qp['language'])) $filters['language'] = $qp['language'];
		if (!empty($qp['ip'])) $filters['ip'] = $qp['ip'];
		if (!empty($qp['path'])) $filters['path'] = $qp['path'];
		return $filters;
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
--red:#f87171;--cyn:#22d3ee;--glow:rgba(124,92,252,.08);
--fg:#e1e4ed;--card:rgba(22,24,40,.7);--border:rgba(255,255,255,.06);--brd:rgba(255,255,255,.06);--warn:#fbbf24}
@media(prefers-color-scheme:light){:root{--bg:#f4f5f7;--sfc:rgba(255,255,255,.85);--sfc-solid:#fff;--bdr:rgba(0,0,0,.08);
--txt:#1a1a2e;--dim:#6b7089;--glow:rgba(124,92,252,.05);
--fg:#1a1a2e;--card:rgba(255,255,255,.85);--border:rgba(0,0,0,.08);--brd:rgba(0,0,0,.08);--warn:#d97706}}
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
  transition:all .15s;cursor:pointer}
.app-row:hover{background:rgba(255,255,255,.04);border-color:var(--bdr)}
.app-icon{width:36px;height:36px;border-radius:8px;flex-shrink:0;object-fit:cover;
  background:rgba(255,255,255,.05);border:1px solid var(--bdr)}
.dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.dot.on{background:var(--grn);box-shadow:0 0 8px rgba(74,222,128,.4)}
.dot.off{background:var(--dim)}
.app-info{flex:1;min-width:0}
.app-name{font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.app-url{color:var(--dim);font-size:12px;font-family:'SF Mono',monospace;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:280px}
.app-badges{display:flex;gap:4px;flex-wrap:wrap;margin-top:3px}
.app-badge{font-size:10px;background:rgba(255,255,255,.06);padding:1px 6px;border-radius:3px;color:var(--dim)}
/* ── App detail panel ── */
.app-detail{background:var(--card);border:1px solid var(--bdr);border-radius:12px;padding:20px;margin-bottom:12px}
.app-detail h3{font-size:16px;margin-bottom:14px;display:flex;align-items:center;gap:10px}
.app-detail .back-btn{cursor:pointer;font-size:18px;opacity:.6;transition:opacity .15s}
.app-detail .back-btn:hover{opacity:1}
.detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px}
.detail-card{background:rgba(255,255,255,.03);border:1px solid var(--bdr);border-radius:8px;padding:12px}
.detail-card .label{font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.detail-card .val{font-size:18px;font-weight:700}
.detail-tabs{display:flex;gap:0;border-bottom:1px solid var(--bdr);margin-bottom:14px}
.detail-tab{padding:8px 14px;font-size:12px;cursor:pointer;border-bottom:2px solid transparent;color:var(--dim);transition:all .15s}
.detail-tab:hover{color:var(--txt)}
.detail-tab.active{color:var(--ac);border-bottom-color:var(--ac)}
/* ── Log viewer enhanced ── */
.log-tree{max-height:300px;overflow-y:auto;margin-bottom:12px}
.log-file{display:flex;align-items:center;gap:8px;padding:6px 10px;border-radius:6px;cursor:pointer;
  font-size:12px;font-family:'SF Mono',monospace;transition:background .1s}
.log-file:hover{background:rgba(255,255,255,.05)}
.log-file.active{background:rgba(124,92,252,.15);color:var(--ac)}
.log-size{color:var(--dim);font-size:10px;margin-left:auto}
/* ── File browser ── */
.file-breadcrumb{display:flex;flex-wrap:wrap;gap:2px;align-items:center;font-size:12px;margin-bottom:10px;color:var(--dim)}
.file-breadcrumb span{cursor:pointer;color:var(--ac);transition:opacity .15s}
.file-breadcrumb span:hover{opacity:.8}
.file-item{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:6px;cursor:pointer;
  font-size:13px;transition:background .1s}
.file-item:hover{background:rgba(255,255,255,.05)}
.file-item .icon{width:18px;text-align:center;flex-shrink:0}
.file-item .fname{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.file-item .fsize{font-size:11px;color:var(--dim);font-family:'SF Mono',monospace}
.file-preview{max-height:500px;overflow:auto;font-size:12px;padding:14px;
  background:rgba(0,0,0,.3);border-radius:8px;white-space:pre-wrap;word-break:break-all;
  font-family:'SF Mono',monospace;line-height:1.5}

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
  .app-icon{width:32px;height:32px;border-radius:6px}
  .app-info{width:calc(100% - 56px)}
  .app-name{font-size:14px}
  .app-url{max-width:none;font-size:11px}
  .btn-row{width:100%;justify-content:flex-start;margin-top:4px}
  .detail-grid{grid-template-columns:repeat(2,1fr)}
  .detail-tabs{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .file-breadcrumb{font-size:11px}
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
  .app-icon{width:28px;height:28px}
  .detail-grid{grid-template-columns:1fr}
  .detail-tab{padding:8px 10px;font-size:11px}
  .file-preview{font-size:10px;padding:10px}
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
  <div class="tab active" onclick="showTab('apps',event)">Apps</div>
  <div class="tab" onclick="showTab('domains',event)">Domains</div>
  <div class="tab" onclick="showTab('autohost',event)">Autohost</div>
  <div class="tab" onclick="showTab('security',event)">Security</div>
  <div class="tab" onclick="showTab('scripts',event)">Scripts</div>
  <div class="tab" onclick="showTab('plugins',event)">Plugins</div>
  <div class="tab" onclick="showTab('workers',event)">Workers</div>
  <div class="tab" onclick="showTab('logs',event)">Logs</div>
  <div class="tab" onclick="showTab('cron',event)">Cron</div>
  <div class="tab" onclick="showTab('frameworks',event)">Frameworks</div>
  <div class="tab" onclick="showTab('playground',event)">Playground</div>
  <div class="tab" onclick="showTab('system',event)">System</div>
  <div class="tab" onclick="showTab('servers',event)">Servers</div>
  <div class="tab" onclick="showTab('nearby',event)">Nearby</div>
  <div class="tab" onclick="showTab('mobile',event)">Mobile</div>
  <div class="tab" onclick="showTab('users',event)">Users</div>
  <div class="tab" onclick="showTab('branches',event)">Branches</div>
  <div class="tab" onclick="showTab('clientmetrics',event)">Client Metrics</div>
  <div class="tab" onclick="showTab('analytics',event)">Analytics</div>
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
  <div id="mini-sankey-wrap" style="margin-top:20px;display:none">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <h3 style="font-size:14px">Top Navigation Flows</h3>
      <a href="#" onclick="showTab('analytics',event);return false" style="font-size:12px;color:var(--ac)">Full Analytics →</a>
    </div>
    <svg id="mini-sankey" style="width:100%;min-height:180px"></svg>
  </div>
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

  <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
    <button class="btn btn-ghost" style="font-size:12px" onclick="clearServerCache()">Clear Cache</button>
    <button class="btn btn-ghost" style="font-size:12px" onclick="var f=document.getElementById('phpinfo-frame');f.style.display=f.style.display==='none'?'block':'none';if(f.style.display==='block')f.src='/Q/phpinfo'">Show phpinfo()</button>
  </div>
  <div id="cache-clear-result" style="display:none;margin-top:8px;padding:8px 12px;border-radius:6px;font-size:12px;background:var(--card);border:1px solid var(--brd)"></div>
  <div>
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

<!-- NEARBY TAB -->
<div id="tab-nearby" class="content hidden">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h2 style="font-size:16px">Nearby Peers</h2>
    <button class="btn btn-ghost" onclick="loadNearby()">Refresh</button>
  </div>
  <div class="card" style="margin-bottom:14px">
    <p style="font-size:12px;color:var(--dim);margin-bottom:8px">This server's mesh identity:</p>
    <div id="nearby-identity" style="font-family:monospace;font-size:11px;word-break:break-all;color:var(--accent)">Loading...</div>
  </div>
  <div id="nearby-transports" class="card" style="margin-bottom:14px">
    <h3 style="margin-bottom:8px">Transports</h3>
    <div id="nearby-transport-list">Loading...</div>
  </div>
  <div id="nearby-peers">
    <p style="color:var(--dim)">Scanning for nearby peers...</p>
  </div>
  <div id="nearby-sessions" class="card" style="margin-top:14px">
    <h3 style="margin-bottom:8px">Encrypted Sessions</h3>
    <div id="nearby-session-list">None</div>
  </div>
  <div id="nearby-routes" class="card" style="margin-top:14px">
    <h3 style="margin-bottom:8px">Routing Table</h3>
    <div id="nearby-route-list">No routes</div>
  </div>
  <div class="card" style="margin-top:14px">
    <h3 style="margin-bottom:8px">Connect to Peer</h3>
    <div style="display:flex;gap:8px">
      <input id="nearby-connect-addr" type="text" placeholder="http://192.168.1.50:8080"
        style="flex:1;padding:6px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--fg);font-size:13px">
      <button class="btn" onclick="connectToPeer()">Connect</button>
    </div>
    <div id="nearby-connect-result" style="font-size:12px;margin-top:6px;color:var(--dim)"></div>
  </div>
</div>

<!-- MOBILE TAB -->
<div id="tab-mobile" class="content hidden">
  <h2 style="font-size:16px;margin-bottom:16px">Mobile Builds</h2>

  <!-- Toolchain Status -->
  <div class="card" style="margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h3 style="font-size:14px">Toolchains</h3>
      <button class="btn btn-ghost" onclick="loadToolchains()" style="font-size:12px">↻ Refresh</button>
    </div>
    <div id="mobile-toolchains"><p style="color:var(--dim);font-size:12px">Detecting…</p></div>
  </div>

  <!-- App Selector -->
  <div class="card" style="margin-bottom:16px">
    <h3 style="font-size:14px;margin-bottom:10px">Build App</h3>
    <div class="form-row">
      <label>App</label>
      <select id="mobile-app" onchange="loadMobileApp(this.value)" style="flex:1">
        <option value="">Select an app…</option>
      </select>
    </div>
  </div>

  <!-- Per-App Mobile Panel (shown after app selection) -->
  <div id="mobile-app-panel" style="display:none">
    <!-- Mobile Config -->
    <div class="card" style="margin-bottom:16px">
      <h3 style="font-size:14px;margin-bottom:10px">App Configuration</h3>
      <div class="form-row"><label>Bundle ID</label><input id="mobile-bundle-id" placeholder="com.example.myapp" style="flex:1"></div>
      <div class="form-row"><label>App Name</label><input id="mobile-app-name" placeholder="My App" style="flex:1"></div>
      <div class="form-row"><label>Version</label><input id="mobile-version" placeholder="1.0.0" style="flex:1;max-width:120px"></div>
      <div class="form-row"><label>Build #</label><input id="mobile-build-num" type="number" placeholder="1" style="flex:1;max-width:80px"></div>
      <div style="margin-top:10px">
        <button class="btn btn-ghost" onclick="saveMobileConfig()" style="font-size:12px">Save Config</button>
        <span id="mobile-config-status" style="font-size:11px;color:var(--dim);margin-left:8px"></span>
      </div>
    </div>

    <!-- Platform Cards -->
    <div class="grid-2" style="margin-bottom:16px">
      <!-- iOS Card -->
      <div class="card" id="mobile-ios-card">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
          <span style="font-size:20px">🍎</span>
          <h3 style="font-size:14px;flex:1">iOS</h3>
          <span id="mobile-ios-status" style="font-size:11px;padding:2px 8px;border-radius:4px;background:rgba(255,255,255,.06);color:var(--dim)">—</span>
        </div>
        <div id="mobile-ios-info" style="font-size:12px;color:var(--dim);margin-bottom:10px">Requires macOS with Xcode CLI tools</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button class="btn btn-ghost" onclick="mobilePrepare('ios')" id="mobile-ios-prepare" style="font-size:12px">📁 Prepare Project</button>
          <button class="btn btn-ghost" onclick="mobileBuild('ios')" id="mobile-ios-build" style="font-size:12px">🔨 Build</button>
        </div>
        <pre id="mobile-ios-output" style="display:none;max-height:300px;overflow:auto;font-size:11px;padding:10px;background:rgba(0,0,0,.3);border-radius:6px;white-space:pre-wrap;word-break:break-all;margin-top:10px"></pre>
      </div>
      <!-- Android Card -->
      <div class="card" id="mobile-android-card">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
          <span style="font-size:20px">🤖</span>
          <h3 style="font-size:14px;flex:1">Android</h3>
          <span id="mobile-android-status" style="font-size:11px;padding:2px 8px;border-radius:4px;background:rgba(255,255,255,.06);color:var(--dim)">—</span>
        </div>
        <div id="mobile-android-info" style="font-size:12px;color:var(--dim);margin-bottom:10px">Requires Android SDK and JDK</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button class="btn btn-ghost" onclick="mobilePrepare('android')" id="mobile-android-prepare" style="font-size:12px">📁 Prepare Project</button>
          <button class="btn btn-ghost" onclick="mobileBuild('android')" id="mobile-android-build" style="font-size:12px">🔨 Build</button>
        </div>
        <pre id="mobile-android-output" style="display:none;max-height:300px;overflow:auto;font-size:11px;padding:10px;background:rgba(0,0,0,.3);border-radius:6px;white-space:pre-wrap;word-break:break-all;margin-top:10px"></pre>
      </div>
    </div>

    <!-- Build Artifacts -->
    <div class="card" style="margin-bottom:16px">
      <h3 style="font-size:14px;margin-bottom:10px">Build Artifacts</h3>
      <div id="mobile-artifacts"><p style="color:var(--dim);font-size:12px">No builds yet.</p></div>
    </div>

    <!-- Build History -->
    <div class="card">
      <h3 style="font-size:14px;margin-bottom:10px">Build History</h3>
      <div id="mobile-history"><p style="color:var(--dim);font-size:12px">No build history.</p></div>
    </div>
  </div>
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
  <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
    <button class="btn btn-primary" onclick="loadLogs('access')">Access Log</button>
    <button class="btn btn-ghost" onclick="loadLogs('error')">Error Log</button>
    <select id="log-app-select" style="padding:6px;background:var(--card);color:var(--txt);border:1px solid var(--bdr);border-radius:6px;font-size:12px" onchange="loadAppLogFiles(this.value)">
      <option value="">App logs…</option>
    </select>
    <select id="log-lines" style="margin-left:auto;padding:6px;background:var(--card);color:var(--txt);border:1px solid var(--bdr);border-radius:6px">
      <option value="50">50 lines</option>
      <option value="100">100 lines</option>
      <option value="200">200 lines</option>
      <option value="500">500 lines</option>
    </select>
  </div>
  <div id="log-file-tree" class="log-tree hidden"></div>
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

<!-- USERS TAB -->
<div id="tab-users" class="content hidden">
  <h2 style="font-size:16px;margin-bottom:16px">User Management</h2>
  <div id="users-list"></div>
  <div class="card" style="margin-top:16px" id="user-add-form">
    <h3 style="font-size:14px;margin-bottom:12px">Add User</h3>
    <div class="form-row"><label>Username</label><input type="text" id="user-add-name" placeholder="username (lowercase, no spaces)"></div>
    <div class="form-row"><label>Password</label><input type="password" id="user-add-pw" placeholder="6+ characters"></div>
    <div class="form-row"><label>Role</label><select id="user-add-role"><option value="user">user</option><option value="admin">admin</option></select></div>
    <button class="btn btn-primary" onclick="addUser()">Add User</button>
    <div id="user-add-error" style="color:var(--red);font-size:13px;margin-top:8px;display:none"></div>
  </div>
</div>

<!-- BRANCHES TAB -->
<div id="tab-branches" class="content hidden">
  <h2 style="font-size:16px;margin-bottom:16px">Branch Management</h2>
  <div id="branches-list"></div>
  <div class="card" style="margin-top:16px">
    <h3 style="font-size:14px;margin-bottom:12px">Create Branch</h3>
    <div class="form-row"><label>App Host</label><input type="text" id="br-app" placeholder="e.g. myapp.localhost"></div>
    <div class="form-row"><label>Branch Name</label><input type="text" id="br-name" placeholder="branch-name"></div>
    <button class="btn btn-primary" onclick="createBranch()">Create Branch</button>
    <div id="br-error" style="color:var(--red);font-size:13px;margin-top:8px;display:none"></div>
  </div>
  <div class="card" style="margin-top:16px" id="br-db-config">
    <h3 style="font-size:14px;margin-bottom:12px">Database Clone Config</h3>
    <div class="form-row"><label>App Host</label><input type="text" id="br-db-app" placeholder="e.g. myapp.localhost"></div>
    <div class="form-row"><label>Clone DB Name</label><input type="text" id="br-db-name" placeholder="test_database_name"></div>
    <button class="btn btn-primary" onclick="saveBranchDbConfig()">Save</button>
    <div id="br-db-info" style="font-size:12px;color:var(--dim);margin-top:8px"></div>
  </div>
  <div class="card" style="margin-top:16px">
    <h3 style="font-size:14px;margin-bottom:12px">Merge Requests</h3>
    <div id="br-merge-requests"><p style="color:var(--dim);font-size:12px">No pending merge requests.</p></div>
  </div>
</div>

<div id="tab-clientmetrics" class="content hidden">
  <h2 style="font-size:16px;margin-bottom:16px">Client-Side Metrics</h2>
  <div id="cm-status" style="margin-bottom:16px"></div>
  <div class="card" id="cm-overview" style="display:none">
    <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:12px" id="cm-stats"></div>
    <div style="margin-bottom:12px">
      <label style="font-size:13px;font-weight:600">Date</label>
      <select id="cm-date-select" onchange="loadClientMetricsDate(this.value)" style="margin-left:8px;padding:4px 8px;border-radius:4px;border:1px solid var(--brd);background:var(--card);color:var(--fg);font-size:13px"></select>
      <a id="cm-tsv-link" href="#" download style="margin-left:12px;font-size:13px;display:none">Download TSV</a>
    </div>
    <div id="cm-summary" style="margin-bottom:16px"></div>
    <div style="margin-bottom:8px">
      <input type="text" id="cm-filter-label" placeholder="Filter by label prefix" style="padding:4px 8px;border-radius:4px;border:1px solid var(--brd);background:var(--card);color:var(--fg);font-size:13px;width:180px">
      <input type="text" id="cm-filter-page" placeholder="Filter by page prefix" style="margin-left:8px;padding:4px 8px;border-radius:4px;border:1px solid var(--brd);background:var(--card);color:var(--fg);font-size:13px;width:180px">
      <button class="btn btn-sm btn-primary" onclick="applyClientMetricsFilter()" style="margin-left:8px">Filter</button>
    </div>
    <div id="cm-events" style="max-height:400px;overflow-y:auto"></div>
  </div>
</div>

<div id="tab-analytics" class="content hidden">
  <h2 style="font-size:16px;margin-bottom:16px">Navigation Analytics</h2>
  <div id="an-status" style="margin-bottom:16px"></div>
  <div class="card" id="an-main" style="display:none">
    <div id="an-filters" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;align-items:flex-end">
      <div><label style="font-size:11px;display:block;margin-bottom:2px">App / Host</label>
        <select id="an-host" style="padding:4px 8px;border-radius:4px;border:1px solid var(--brd);background:var(--card);color:var(--fg);font-size:13px"><option value="">All</option></select></div>
      <div><label style="font-size:11px;display:block;margin-bottom:2px">Period</label>
        <select id="an-period" style="padding:4px 8px;border-radius:4px;border:1px solid var(--brd);background:var(--card);color:var(--fg);font-size:13px">
          <option value="3600">Last hour</option><option value="86400" selected>Last 24h</option><option value="604800">Last 7 days</option><option value="2592000">Last 30 days</option></select></div>
      <div><label style="font-size:11px;display:block;margin-bottom:2px">Platform</label>
        <select id="an-platform" style="padding:4px 8px;border-radius:4px;border:1px solid var(--brd);background:var(--card);color:var(--fg);font-size:13px"><option value="">All</option></select></div>
      <div><label style="font-size:11px;display:block;margin-bottom:2px">Browser</label>
        <select id="an-browser" style="padding:4px 8px;border-radius:4px;border:1px solid var(--brd);background:var(--card);color:var(--fg);font-size:13px"><option value="">All</option></select></div>
      <div><label style="font-size:11px;display:block;margin-bottom:2px">Language</label>
        <input type="text" id="an-language" placeholder="e.g. en-US" style="padding:4px 8px;border-radius:4px;border:1px solid var(--brd);background:var(--card);color:var(--fg);font-size:13px;width:80px"></div>
      <div><label style="font-size:11px;display:block;margin-bottom:2px">IP prefix</label>
        <input type="text" id="an-ip" placeholder="e.g. 192.168" style="padding:4px 8px;border-radius:4px;border:1px solid var(--brd);background:var(--card);color:var(--fg);font-size:13px;width:100px"></div>
      <div><label style="font-size:11px;display:block;margin-bottom:2px">Path prefix</label>
        <input type="text" id="an-path" placeholder="e.g. /blog" style="padding:4px 8px;border-radius:4px;border:1px solid var(--brd);background:var(--card);color:var(--fg);font-size:13px;width:100px"></div>
      <button class="btn btn-sm btn-primary" onclick="loadAnalytics()" style="align-self:flex-end">Apply</button>
    </div>
    <div id="an-overview" style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:16px"></div>
    <div id="an-sankey-wrap" style="position:relative;width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;margin-bottom:16px">
      <div id="an-breadcrumb" style="font-size:12px;color:var(--dim);margin-bottom:6px;display:none">
        <a href="#" onclick="analyticsResetDrill();return false" style="color:var(--ac)">All flows</a> <span id="an-bc-text"></span>
      </div>
      <svg id="an-sankey" style="width:100%;min-height:300px"></svg>
    </div>
    <div style="display:flex;gap:16px;flex-wrap:wrap">
      <div style="flex:1;min-width:280px">
        <h3 style="font-size:14px;margin-bottom:8px">Top Pages</h3>
        <div id="an-pages" style="max-height:300px;overflow-y:auto"></div>
      </div>
      <div style="flex:1;min-width:280px">
        <h3 style="font-size:14px;margin-bottom:8px">Sessions <span id="an-sess-count" style="color:var(--dim);font-weight:normal;font-size:12px"></span></h3>
        <div id="an-sessions" style="max-height:400px;overflow-y:auto"></div>
        <button class="btn btn-sm" id="an-sess-more" onclick="loadMoreSessions()" style="display:none;margin-top:8px">Load more</button>
      </div>
    </div>
    <div id="an-session-detail" style="display:none;margin-top:16px">
      <h3 style="font-size:14px;margin-bottom:8px">Session Path <button class="btn btn-sm" onclick="document.getElementById('an-session-detail').style.display='none'" style="margin-left:8px;font-size:11px">Close</button></h3>
      <div id="an-session-path"></div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/d3/7.9.0/d3.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/d3-sankey/0.12.3/d3-sankey.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

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
  var headers = {};
  var t = getToken();
  if (t) headers['X-Panel-Token'] = t;
  var opts;
  if (body) {
    headers['Content-Type'] = 'application/json';
    opts = {method:'POST', headers:headers, body:JSON.stringify(body)};
  } else {
    opts = {headers:headers};
  }
  var r = await fetch(API+'/'+path, opts);
  var data;
  try { data = await r.json(); } catch(e) {
    throw new Error('Server returned non-JSON response (HTTP ' + r.status + ')');
  }
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
    + (isSetup ? '' : '<div class="form-row"><label>Username</label><input type="text" id="auth-user" placeholder="owner" value="owner"></div>')
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
  var payload = {password: pw};
  if (!isSetup) {
    var userEl = document.getElementById('auth-user');
    if (userEl && userEl.value) payload.username = userEl.value;
  }
  var r = await fetch(API + '/' + endpoint, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(payload)
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
function showTab(name, ev) {
  document.querySelectorAll('[id^=tab-]').forEach(function(el) { el.classList.add('hidden'); });
  document.getElementById('tab-'+name).classList.remove('hidden');
  document.querySelectorAll('.tab').forEach(function(el) { el.classList.remove('active'); });
  var t = (ev && ev.target) || document.querySelector('.tab[onclick*="\''+name+'\'"]');
  if (t) t.classList.add('active');
  if (name==='apps') loadApps();
  if (name==='plugins') loadPlugins();
  if (name==='system') loadSystem();
  if (name==='servers') loadServers();
  if (name==='nearby') loadNearby();
  if (name==='domains') loadDomains();
  if (name==='autohost') loadAutohost();
  if (name==='security') loadSecurity();
  if (name==='workers') loadWorkers();
  if (name==='logs') loadLogs('access');
  if (name==='cron') loadCron();
  if (name==='frameworks') loadFrameworks();
  if (name==='scripts') loadAppSelect();
  if (name==='mobile') { loadToolchains(); loadMobileAppSelect(); }
  if (name==='users') loadUsers();
  if (name==='branches') loadBranches();
  if (name==='clientmetrics') loadClientMetrics();
  if (name==='analytics') loadAnalytics();
}

// Apps
var _appsData = [];
async function loadApps() {
  var d = await api('apps');
  _appsData = d.apps || [];
  var el = document.getElementById('apps-list');
  document.getElementById('apps-dir-path').textContent = d.appsDir || '(not set)';
  // Also populate the logs app dropdown
  var logSel = document.getElementById('log-app-select');
  if (logSel) {
    logSel.innerHTML = '<option value="">App logs\u2026</option>' + _appsData.map(function(a){
      return '<option value="'+escHtml(a.dirName)+'">'+escHtml(a.name)+'</option>';
    }).join('');
  }
  if (!_appsData.length) {
    el.innerHTML = '<div class="card"><p style="color:var(--dim)">No apps found in this directory. Click + New App to create one.</p></div>';
    return;
  }
  renderAppList(el);
  // Load mini Sankey
  loadMiniSankey();
}
async function loadMiniSankey() {
  try {
    var from = Math.floor(Date.now()/1000) - 86400;
    var data = await api('metrics/analytics/flow?limit=20&from=' + from);
    var edges = data.edges || [];
    var wrap = document.getElementById('mini-sankey-wrap');
    if (!edges.length) { wrap.style.display = 'none'; return; }
    wrap.style.display = '';
    var svg = d3.select('#mini-sankey');
    svg.selectAll('*').remove();
    var width = Math.max(wrap.clientWidth - 16, 300);
    var nodeSet = {};
    edges.forEach(function(e) { nodeSet[e.from_path] = true; nodeSet[e.to_path] = true; });
    var nodeNames = Object.keys(nodeSet);
    var nodes = nodeNames.map(function(n) { return {name: n}; });
    var nameIdx = {};
    nodeNames.forEach(function(n, i) { nameIdx[n] = i; });
    var links = edges.map(function(e) {
      return {source: nameIdx[e.from_path], target: nameIdx[e.to_path], value: parseInt(e.count) || 1};
    }).filter(function(l) { return l.source !== l.target; });
    if (!links.length) { wrap.style.display = 'none'; return; }
    var height = Math.max(Math.min(nodes.length * 22, 250), 180);
    svg.attr('width', width).attr('height', height).attr('viewBox', '0 0 ' + width + ' ' + height);
    var sankey = d3.sankey().nodeWidth(10).nodePadding(8).nodeSort(null).extent([[1,1],[width-1,height-4]]);
    var graph;
    try {
      graph = sankey({nodes: nodes.map(function(d){return Object.assign({},d)}), links: links.map(function(d){return Object.assign({},d)})});
    } catch(e) { wrap.style.display = 'none'; return; }
    var color = d3.scaleOrdinal(d3.schemeTableau10);
    svg.append('g').attr('fill','none').attr('stroke-opacity',0.3)
      .selectAll('path').data(graph.links).join('path')
      .attr('d', d3.sankeyLinkHorizontal())
      .attr('stroke', function(d){return color(d.source.name)})
      .attr('stroke-width', function(d){return Math.max(1,d.width)});
    var node = svg.append('g').selectAll('g').data(graph.nodes).join('g');
    node.append('rect')
      .attr('x',function(d){return d.x0}).attr('y',function(d){return d.y0})
      .attr('height',function(d){return Math.max(1,d.y1-d.y0)})
      .attr('width',function(d){return d.x1-d.x0})
      .attr('fill',function(d){return color(d.name)}).attr('rx',2);
    node.append('text')
      .attr('x',function(d){return d.x0<width/2?d.x1+4:d.x0-4})
      .attr('y',function(d){return(d.y1+d.y0)/2}).attr('dy','0.35em')
      .attr('text-anchor',function(d){return d.x0<width/2?'start':'end'})
      .attr('font-size','10px').attr('fill','var(--fg)')
      .text(function(d){return d.name.length>30?d.name.substring(0,27)+'...':d.name});
  } catch(e) { /* no analytics data yet */ }
}
function renderAppList(el) {
  el.innerHTML = _appsData.map(function(a) {
    var isServing = a.serving;
    var badges = [];
    if (a.hasWeb) badges.push('web');
    if (a.hasHandlers) badges.push('handlers');
    if (a.hasClasses) badges.push('classes');
    if (a.isQbixApp) badges.push('qbix');
    var badgeHtml = badges.map(function(b){return '<span class="app-badge">'+b+'</span>'}).join('');
    var statusText = isServing ? '<span style="color:var(--grn)">serving</span>'
      : (a.url ? '<span>'+a.url+'</span>' : (a.configured ? 'configured' : '<span style="color:var(--yel)">not configured</span>'));
    var iconUrl = '/Q/api/apps/icon?app=' + encodeURIComponent(a.dirName);
    return ''
    + '<div class="app-row" onclick="showAppDetail(\''+a.dirName+'\')">'
    + '<img class="app-icon" src="'+iconUrl+'" alt="" onerror="this.style.display=\'none\'">'
    + '<span class="dot '+(isServing?'on':(a.configured?'on':'off'))+'"></span>'
    + '<div class="app-info">'
    + '<div class="app-name">'+escHtml(a.name)+'</div>'
    + '<div class="app-url">'+statusText+'</div>'
    + (badgeHtml ? '<div class="app-badges">'+badgeHtml+'</div>' : '')
    + '</div>'
    + '<div class="btn-row" onclick="event.stopPropagation()">'
    + (a.hasWeb && !isServing ? '<button class="btn btn-sm btn-primary" onclick="serveApp(\''+a.dirName+'\',true)">Serve</button>' : '')
    + (isServing ? '<button class="btn btn-sm btn-red" onclick="serveApp(\''+a.dirName+'\',false)">Stop</button>' : '')
    + '</div></div>';
  }).join('');
}
function fmtBytes(b) {
  if (b == null) return '';
  if (b < 1024) return b + ' B';
  if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
  if (b < 1073741824) return (b/1048576).toFixed(1) + ' MB';
  return (b/1073741824).toFixed(2) + ' GB';
}
async function showAppDetail(dirName) {
  var a = _appsData.find(function(x){return x.dirName===dirName});
  if (!a) return;
  var el = document.getElementById('apps-list');
  var isServing = a.serving;
  var iconUrl = '/Q/api/apps/icon?app=' + encodeURIComponent(a.dirName);
  var forkColor = a.forkPerRequest === true ? 'var(--yel)' : (a.forkPerRequest === false ? 'var(--grn)' : 'var(--dim)');
  var forkHtml = '<select style="font-size:11px;padding:2px 6px;background:var(--card);color:'+forkColor+';border:1px solid var(--bdr);border-radius:4px;cursor:pointer" onchange="setForkMode(\''+a.dirName+'\',this.value)">'
    + '<option value="auto"'+(a.forkPerRequest===null?' selected':'')+'>auto</option>'
    + '<option value="false"'+(a.forkPerRequest===false?' selected':'')+'>persistent</option>'
    + '<option value="true"'+(a.forkPerRequest===true?' selected':'')+'>fork</option></select>';
  var html = '<div class="app-detail">'
    + '<h3><span class="back-btn" onclick="loadApps()">\u2190</span>'
    + '<img class="app-icon" src="'+iconUrl+'" alt="" style="width:28px;height:28px" onerror="this.style.display=\'none\'">'
    + escHtml(a.name) + '</h3>'
    + '<div class="detail-grid">'
    + '<div class="detail-card"><div class="label">Status</div><div class="val" style="font-size:14px;color:'+(isServing?'var(--grn)':'var(--dim)')+'">'+(isServing?'Serving':'Idle')+'</div></div>'
    + '<div class="detail-card"><div class="label">URL</div><div class="val" style="font-size:13px;word-break:break-all">'+(a.url||'none')+'</div></div>'
    + '<div class="detail-card"><div class="label">Plugins</div><div class="val" style="font-size:14px">'+(a.plugins.length?a.plugins.join(', '):'none')+'</div></div>'
    + '<div class="detail-card"><div class="label">Fork mode</div><div class="val" style="font-size:14px">'+forkHtml+'</div></div>'
    + '</div>'
    + '<div class="detail-tabs">'
    + '<div class="detail-tab active" onclick="showDetailPane(this,\'detail-actions-'+dirName+'\')">Actions</div>'
    + (a.hasScripts ? '<div class="detail-tab" onclick="showDetailPane(this,\'detail-scripts-'+dirName+'\');loadDetailScripts(\''+dirName+'\')">Scripts</div>' : '')
    + '<div class="detail-tab" onclick="showDetailPane(this,\'detail-logs-'+dirName+'\');loadDetailLogs(\''+dirName+'\')">Logs</div>'
    + (a.fileBrowse ? '<div class="detail-tab" onclick="showDetailPane(this,\'detail-files-'+dirName+'\');browseFiles(\''+dirName+'\',\'\')">Files</div>' : '')
    + '</div>'
    + '<div id="detail-actions-'+dirName+'">'
    + '<div class="btn-row" style="gap:8px;margin-top:8px">'
    + (a.hasWeb && !isServing ? '<button class="btn btn-primary" onclick="serveApp(\''+a.dirName+'\',true)">Serve</button>' : '')
    + (isServing ? '<button class="btn btn-red" onclick="serveApp(\''+a.dirName+'\',false)">Stop Serving</button>' : '')
    + (a.isQbixApp && a.hasScripts && !a.configured ? '<button class="btn btn-grn" onclick="configureApp(\''+escHtml(a.dirName)+'\',\''+escHtml(a.name)+'\')">Configure</button>' : '')
    + '<button class="btn btn-ghost" onclick="openFolder(\''+a.dir.replace(/\\/g,'\\\\').replace(/'/g,"\\'")+'\',\'folder\')">\uD83D\uDCC2 Open Folder</button>'
    + '<button class="btn btn-ghost" onclick="openFolder(\''+a.dir.replace(/\\/g,'\\\\').replace(/'/g,"\\'")+'\',\'vscode\')">VS Code</button>'
    + '</div></div>'
    + (a.hasScripts ? '<div id="detail-scripts-'+dirName+'" class="hidden"></div>' : '')
    + '<div id="detail-logs-'+dirName+'" class="hidden"></div>'
    + (a.fileBrowse ? '<div id="detail-files-'+dirName+'" class="hidden"></div>' : '')
    + '</div>';
  el.innerHTML = html;
}
function showDetailPane(tab, paneId) {
  var parent = tab.closest('.app-detail');
  parent.querySelectorAll('.detail-tab').forEach(function(t){t.classList.remove('active')});
  tab.classList.add('active');
  // Hide all panes inside this detail
  parent.querySelectorAll('[id^="detail-"]').forEach(function(p){
    if (p.id.startsWith('detail-actions-') || p.id.startsWith('detail-scripts-') || p.id.startsWith('detail-logs-') || p.id.startsWith('detail-files-'))
      p.classList.add('hidden');
  });
  var pane = document.getElementById(paneId);
  if (pane) pane.classList.remove('hidden');
}
async function loadDetailLogs(dirName) {
  var pane = document.getElementById('detail-logs-'+dirName);
  if (!pane) return;
  pane.innerHTML = '<p style="color:var(--dim);font-size:12px">Loading log files\u2026</p>';
  try {
    var r = await api('apps/logs?app='+encodeURIComponent(dirName));
    if (r.error) { pane.innerHTML='<p style="color:var(--red)">'+escHtml(r.error)+'</p>'; return; }
    if (!r.files || !r.files.length) {
      pane.innerHTML='<p style="color:var(--dim);font-size:12px">No log files found. Checked: '+(r.logDirs||[]).join(', ')+'</p>';
      return;
    }
    var html = '<div class="log-tree">' + r.files.map(function(f){
      return '<div class="log-file" onclick="viewDetailLog(\''+dirName+'\',\''+f.path.replace(/\\/g,'\\\\').replace(/'/g,"\\'")+'\',this)">'
        + '<span>\uD83D\uDCC4</span><span>'+escHtml(f.path)+'</span><span class="log-size">'+fmtBytes(f.size)+'</span></div>';
    }).join('') + '</div>'
    + '<pre id="detail-log-output-'+dirName+'" style="max-height:400px;overflow:auto;font-size:11px;padding:12px;background:rgba(0,0,0,.3);border-radius:8px;white-space:pre-wrap;word-break:break-all;margin-top:10px"></pre>';
    pane.innerHTML = html;
  } catch(e) { pane.innerHTML='<p style="color:var(--red)">'+escHtml(e.message)+'</p>'; }
}
async function viewDetailLog(dirName, filePath, el) {
  // Highlight active
  if (el) {
    el.parentNode.querySelectorAll('.log-file').forEach(function(f){f.classList.remove('active')});
    el.classList.add('active');
  }
  var out = document.getElementById('detail-log-output-'+dirName);
  if (!out) return;
  out.textContent = 'Loading\u2026';
  var lines = document.getElementById('log-lines') ? document.getElementById('log-lines').value : 100;
  var r = await api('apps/logs?app='+encodeURIComponent(dirName)+'&file='+encodeURIComponent(filePath)+'&lines='+lines);
  if (!r.exists) { out.textContent = 'Log file not found'; return; }
  out.textContent = r.lines.join('\n');
  out.scrollTop = out.scrollHeight;
}
async function browseFiles(dirName, subPath) {
  var pane = document.getElementById('detail-files-'+dirName);
  if (!pane) return;
  pane.innerHTML = '<p style="color:var(--dim);font-size:12px">Loading\u2026</p>';
  try {
    var r = await api('apps/files?app='+encodeURIComponent(dirName)+'&path='+encodeURIComponent(subPath));
    if (r.error) { pane.innerHTML='<p style="color:var(--red);font-size:12px">'+escHtml(r.error)+'</p>'; return; }
    if (r.type === 'file') {
      // Show file contents
      var parts = subPath.split('/');
      parts.pop();
      var parentPath = parts.join('/');
      var html = '<div class="file-breadcrumb">'
        + '<span onclick="browseFiles(\''+dirName+'\',\'\')">root</span>';
      var crumbs = subPath.split('/').filter(Boolean);
      var cumul = '';
      for (var i=0;i<crumbs.length-1;i++) {
        cumul += (cumul?'/':'') + crumbs[i];
        html += ' / <span onclick="browseFiles(\''+dirName+'\',\''+cumul+'\')">'+crumbs[i]+'</span>';
      }
      html += ' / '+crumbs[crumbs.length-1]+'</div>';
      html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">'
        + '<span style="font-size:11px;color:var(--dim)">'+fmtBytes(r.size)+'</span>'
        + '<button class="btn btn-sm btn-ghost" onclick="browseFiles(\''+dirName+'\',\''+parentPath+'\')">\u2190 Back</button></div>';
      if (r.isText && r.content !== null) {
        html += '<pre class="file-preview">'+escHtml(r.content)+'</pre>';
      } else {
        html += '<p style="color:var(--dim);font-size:12px">Binary file ('+r.ext+'), '+fmtBytes(r.size)+'</p>';
      }
      pane.innerHTML = html;
      return;
    }
    // Directory listing
    var html = '<div class="file-breadcrumb">';
    if (subPath) {
      html += '<span onclick="browseFiles(\''+dirName+'\',\'\')">root</span>';
      var crumbs = subPath.split('/').filter(Boolean);
      var cumul = '';
      for (var i=0;i<crumbs.length;i++) {
        cumul += (cumul?'/':'') + crumbs[i];
        if (i < crumbs.length-1) html += ' / <span onclick="browseFiles(\''+dirName+'\',\''+cumul+'\')">'+crumbs[i]+'</span>';
        else html += ' / '+crumbs[i];
      }
    } else {
      html += 'root';
    }
    html += '</div>';
    html += r.items.map(function(f){
      var path = subPath ? subPath+'/'+f.name : f.name;
      var icon = f.name === '..' ? '\u2B06' : (f.isDir ? '\uD83D\uDCC1' : '\uD83D\uDCC4');
      return '<div class="file-item" onclick="browseFiles(\''+dirName+'\',\''+path.replace(/\\\\/g,"\\\\\\\\").replace(/'/g,"\\'")+'\');">'
        + '<span class="icon">'+icon+'</span>'
        + '<span class="fname">'+f.name+'</span>'
        + (f.isDir ? '' : '<span class="fsize">'+fmtBytes(f.size)+'</span>')
        + '</div>';
    }).join('');
    pane.innerHTML = html;
  } catch(e) { pane.innerHTML='<p style="color:var(--red)">'+escHtml(e.message)+'</p>'; }
}
// Script modules — dedicated UI with options for each common script
// t:'h'=header, 'c'=checkbox, 'x'=text, 's'=select; f=flag, l=label, p=placeholder, o=options
var _scriptModules = {
  install: {
    desc:'Install/upgrade database schemas, plugins, composer and npm packages', icon:'⚙️',
    opts:[
      {t:'h',label:'Quick'},
      {t:'c',f:'--all',l:'All (app + plugins + composer + npm)'},
      {t:'h',label:'Components'},
      {t:'c',f:'--app',l:'App schemas'},
      {t:'c',f:'--plugins',l:'All plugins'},
      {t:'c',f:'--composer',l:'Composer packages'},
      {t:'c',f:'--npm',l:'NPM packages'},
      {t:'x',f:'-p',l:'Specific plugin',p:'PluginName'},
      {t:'h',label:'Database'},
      {t:'x',f:'-s',l:'SQL connection',p:'connection name'},
      {t:'x',f:'--group',l:'File group',p:'group name or id'},
      {t:'h',label:'Debug'},
      {t:'c',f:'--noreq',l:'Skip requirements check'},
      {t:'c',f:'--trace',l:'Show stacktraces'}
    ]
  },
  urls: {
    desc:'Regenerate URL cache rewriting information for web assets', icon:'🔗',
    opts:[
      {t:'c',f:'--integrity',l:'Force SHA-256 content hashes (subresource integrity)'},
      {t:'c',f:'--timestamps',l:'Store timestamps even on first run'}
    ]
  },
  'static': {
    desc:'Regenerate static HTML/file snapshots from app config', icon:'📄',
    opts:[
      {t:'x',f:'--out',l:'Output directory',p:'path (default: web/)'},
      {t:'x',f:'--baseUrl',l:'Base URL override',p:'https://example.com'}
    ]
  },
  combine: {
    desc:'Combine and minify JS/CSS files for production', icon:'🗜',
    opts:[
      {t:'c',f:'--all',l:'All file types (default)'},
      {t:'c',f:'--css',l:'CSS only'},
      {t:'c',f:'--js',l:'JS only'},
      {t:'x',f:'--process',l:'Custom extension',p:'ext'}
    ]
  },
  models: {
    desc:'Generate ORM model classes from database schemas', icon:'🗃',
    opts:[
      {t:'c',f:'--all',l:'Include all plugins'},
      {t:'x',f:'--plugin',l:'Specific plugin',p:'PluginName'}
    ]
  },
  translate: {
    desc:'Translate app interface text into other languages', icon:'🌐',
    opts:[
      {t:'h',label:'Scope'},
      {t:'c',f:'--all',l:'All (app + plugins)'},
      {t:'c',f:'--app',l:'App only'},
      {t:'c',f:'--plugins',l:'All plugins'},
      {t:'x',f:'--plugin',l:'Specific plugin',p:'PluginName'},
      {t:'h',label:'Options'},
      {t:'s',f:'--format',l:'Format',o:[{v:'google',l:'Google Translate'},{v:'human',l:'Human translator'}]},
      {t:'x',f:'--source',l:'Source language',p:'en'},
      {t:'x',f:'--locales',l:'Target locales',p:'fr de es ja'},
      {t:'c',f:'--retranslate-all',l:'Retranslate everything'}
    ]
  },
  migrate: {
    desc:'Migrate data between MySQL, SQLite, and PostgreSQL', icon:'🔄',
    opts:[
      {t:'h',label:'Source'},
      {t:'x',f:'--source-config',l:'Source connection',p:'connection name'},
      {t:'x',f:'--source',l:'Source DSN',p:'sqlite:/path/to/db'},
      {t:'h',label:'Target'},
      {t:'x',f:'--target-config',l:'Target connection',p:'connection name'},
      {t:'x',f:'--target',l:'Target DSN',p:'pgsql:host=localhost;dbname=app'},
      {t:'x',f:'--target-user',l:'Target user',p:'username'},
      {t:'x',f:'--target-pass',l:'Target password',p:'password'},
      {t:'h',label:'Filters'},
      {t:'x',f:'--connections',l:'Connections',p:'conn1,conn2'},
      {t:'x',f:'--tables',l:'Tables only',p:'table1,table2'},
      {t:'x',f:'--exclude',l:'Exclude tables',p:'table1,table2'},
      {t:'h',label:'Mode'},
      {t:'c',f:'--schema-only',l:'Schema only (no data)'},
      {t:'c',f:'--data-only',l:'Data only (tables exist)'},
      {t:'c',f:'--truncate',l:'Truncate target tables first'},
      {t:'c',f:'--verify',l:'Verify row counts after'},
      {t:'c',f:'--dry-run',l:'Dry run (show plan only)'},
      {t:'c',f:'--verbose',l:'Verbose output'}
    ]
  },
  encryptdb: {
    desc:'Encrypt MySQL tables using table-level encryption', icon:'🔒',
    opts:[
      {t:'s',f:'_mode',l:'Mode',o:[{v:'--run',l:'Encrypt (run)'},{v:'--dry-run',l:'Dry run'},{v:'--rollback',l:'Rollback'},{v:'--drop',l:'Drop backups'}]},
      {t:'c',f:'--nobackup',l:'No backup (replace directly)'},
      {t:'x',f:'--only',l:'Only tables',p:'table1,table2'},
      {t:'x',f:'--log',l:'Log file',p:'path/to/log.json'}
    ]
  },
  configure: {
    desc:'Rename an app template to your desired app name', icon:'🔧',
    opts:[
      {t:'x',f:'_arg1',l:'Original app name',p:'OriginalApp'},
      {t:'x',f:'_arg2',l:'New app name',p:'MyNewApp'},
      {t:'c',f:'--verbose',l:'Verbose output'}
    ]
  },
  bundle: {
    desc:'Bundle app into a native application directory', icon:'📦',
    opts:[
      {t:'x',f:'_arg1',l:'Bundle output path',p:'/path/to/bundle'}
    ]
  },
  shards: {
    desc:'Split database sharding partitions online', icon:'🗄',
    opts:[
      {t:'x',f:'--part',l:'Partition',p:'PLUGIN/TABLE[/PART]'},
      {t:'x',f:'--connection',l:'Connection',p:'connection name'},
      {t:'x',f:'--class',l:'Class name',p:'Plugin_TableName'},
      {t:'x',f:'--fields',l:'Fields (JSON)',p:'{"field":"md5"}'},
      {t:'x',f:'--parts',l:'Parts (JSON)',p:'[{"host":"..."}]'},
      {t:'x',f:'--node',l:'Node.js IP',p:'127.0.0.1'},
      {t:'c',f:'--trace',l:'Show stacktraces'},
      {t:'c',f:'--log-process',l:'Recovery mode'}
    ]
  },
  tailwind: {
    desc:'Compile Tailwind CSS for the Q plugin', icon:'🎨',
    opts:[]
  }
};
function _sid(dirName, scriptName, flag) {
  return 'sopt-'+dirName+'-'+scriptName+'-'+flag.replace(/[^a-zA-Z0-9]/g,'');
}
function renderScriptModule(s, dirName) {
  var mod = _scriptModules[s.name];
  var icon = mod ? mod.icon : '📜';
  var desc = mod ? mod.desc : (s.scope+' script');
  var sn = s.name.replace(/[^a-zA-Z0-9]/g,'');
  var hasOpts = mod && mod.opts && mod.opts.some(function(o){return o.t!=='h';});
  var h = '<div style="border:1px solid var(--bdr);border-radius:8px;margin-bottom:6px;overflow:hidden">';
  h += '<div style="display:flex;align-items:center;gap:10px;padding:10px 12px;cursor:pointer" onclick="toggleScriptMod(\''+dirName+'\',\''+s.name+'\')">';
  h += '<span style="font-size:18px">'+icon+'</span>';
  h += '<div style="flex:1;min-width:0"><div style="font-size:13px;font-weight:500">'+s.name+'.php</div>';
  h += '<div style="font-size:11px;color:var(--dim)">'+desc+'</div></div>';
  h += '<span style="font-size:10px;padding:2px 6px;border-radius:3px;background:rgba(255,255,255,.06);color:var(--dim)">'+s.scope+'</span>';
  h += '<span id="smod-chev-'+dirName+'-'+sn+'" style="font-size:10px;color:var(--dim);transition:transform .2s">▶</span>';
  h += '</div>';
  h += '<div id="smod-body-'+dirName+'-'+sn+'" style="display:none;padding:0 12px 12px;border-top:1px solid var(--bdr)">';
  if (hasOpts) {
    h += '<div style="margin-top:8px">';
    mod.opts.forEach(function(opt) {
      if (opt.t==='h') {
        h += '<div style="font-size:10px;font-weight:600;text-transform:uppercase;color:var(--dim);margin:10px 0 4px;letter-spacing:.5px">'+opt.label+'</div>';
        return;
      }
      var id = _sid(dirName, s.name, opt.f);
      if (opt.t==='c') {
        h += '<label style="display:flex;align-items:center;gap:6px;font-size:12px;padding:3px 0;cursor:pointer">'
          + '<input type="checkbox" id="'+id+'"> '+opt.l+'</label>';
      } else if (opt.t==='x') {
        h += '<div style="display:flex;align-items:center;gap:8px;padding:3px 0">'
          + '<label style="font-size:12px;min-width:110px;color:var(--dim)" for="'+id+'">'+opt.l+'</label>'
          + '<input type="text" id="'+id+'" placeholder="'+(opt.p||'')+'" style="flex:1;font-size:12px;padding:4px 8px;background:rgba(0,0,0,.2);border:1px solid var(--bdr);border-radius:4px;color:var(--fg)">'
          + '</div>';
      } else if (opt.t==='s') {
        h += '<div style="display:flex;align-items:center;gap:8px;padding:3px 0">'
          + '<label style="font-size:12px;min-width:110px;color:var(--dim)" for="'+id+'">'+opt.l+'</label>'
          + '<select id="'+id+'" style="flex:1;font-size:12px;padding:4px 8px;background:rgba(0,0,0,.2);border:1px solid var(--bdr);border-radius:4px;color:var(--fg)">'
          + '<option value="">—</option>';
        (opt.o||[]).forEach(function(o){ h += '<option value="'+o.v+'">'+o.l+'</option>'; });
        h += '</select></div>';
      }
    });
    h += '</div>';
  }
  h += '<div style="margin-top:10px;display:flex;gap:8px;align-items:center">'
    + '<button class="btn btn-sm" id="smod-run-'+dirName+'-'+sn+'" onclick="runScriptModule(\''+dirName+'\',\''+s.name+'\',this)" style="font-size:12px;padding:5px 14px">▶ Run</button>'
    + '<span id="smod-st-'+dirName+'-'+sn+'" style="font-size:11px;color:var(--dim)"></span>'
    + '</div>';
  h += '<pre id="smod-out-'+dirName+'-'+sn+'" style="display:none;max-height:400px;overflow:auto;font-size:11px;padding:10px;background:rgba(0,0,0,.3);border-radius:6px;white-space:pre-wrap;word-break:break-all;margin-top:8px"></pre>';
  h += '</div></div>';
  return h;
}
function toggleScriptMod(dirName, scriptName) {
  var sn = scriptName.replace(/[^a-zA-Z0-9]/g,'');
  var body = document.getElementById('smod-body-'+dirName+'-'+sn);
  var chev = document.getElementById('smod-chev-'+dirName+'-'+sn);
  if (!body) return;
  var show = body.style.display === 'none';
  body.style.display = show ? 'block' : 'none';
  if (chev) chev.textContent = show ? '▼' : '▶';
}
function collectScriptArgs(scriptName, dirName) {
  var mod = _scriptModules[scriptName];
  if (!mod || !mod.opts) return [];
  var args = [];
  mod.opts.forEach(function(opt) {
    if (opt.t === 'h') return;
    var el = document.getElementById(_sid(dirName, scriptName, opt.f));
    if (!el) return;
    if (opt.t === 'c') {
      if (el.checked) args.push(opt.f);
    } else if (opt.t === 's') {
      var v = el.value;
      if (!v) return;
      if (opt.f.charAt(0) === '_') { args.push(v); } // value IS the flag
      else { args.push(opt.f, v); }
    } else if (opt.t === 'x') {
      var v = el.value.trim();
      if (!v) return;
      if (opt.f.charAt(0) === '_') { args.push(v); } // positional arg
      else { args.push(opt.f, v); }
    }
  });
  return args;
}
async function loadDetailScripts(dirName) {
  var pane = document.getElementById('detail-scripts-'+dirName);
  if (!pane) return;
  pane.innerHTML = '<p style="color:var(--dim);font-size:12px">Loading scripts…</p>';
  try {
    var r = await api('scripts', {app:dirName});
    if (r.error) { pane.innerHTML='<p style="color:var(--red)">'+escHtml(r.error)+'</p>'; return; }
    var scripts = r.scripts || [];
    if (!scripts.length) {
      pane.innerHTML='<p style="color:var(--dim);font-size:12px">No scripts found in scripts/Q/ directory.</p>';
      return;
    }
    var html = '<div style="margin-top:8px">';
    scripts.forEach(function(s) { html += renderScriptModule(s, dirName); });
    html += '</div>';
    pane.innerHTML = html;
  } catch(e) { pane.innerHTML='<p style="color:var(--red)">'+escHtml(e.message)+'</p>'; }
}
async function runScriptModule(dirName, scriptName, btn) {
  var args = collectScriptArgs(scriptName, dirName);
  var sn = scriptName.replace(/[^a-zA-Z0-9]/g,'');
  var out = document.getElementById('smod-out-'+dirName+'-'+sn);
  var st = document.getElementById('smod-st-'+dirName+'-'+sn);
  if (out) { out.style.display='block'; out.textContent='Running '+scriptName+'.php…\n'; out.style.color='var(--fg)'; }
  if (st) { st.textContent='Running…'; st.style.color='var(--dim)'; }
  if (btn) { btn.disabled=true; btn.textContent='⏳'; }
  try {
    var r = await api('scripts/run', {app:dirName, script:scriptName, args:args});
    if (r.error) {
      if (out) { out.textContent += '⚠ Error: '+r.error; out.style.color='var(--red)'; }
      if (st) { st.textContent='Error'; st.style.color='var(--red)'; }
    } else {
      var cmdStr = 'php scripts/Q/'+scriptName+'.php'+(args.length ? ' '+args.join(' ') : '');
      if (out) {
        out.textContent = '$ '+cmdStr+'\n\n'+(r.output||'(no output)')+'\n\nExit code: '+r.exitCode;
        out.style.color = r.exitCode === 0 ? 'var(--grn)' : 'var(--yel)';
      }
      if (st) {
        st.textContent = r.exitCode === 0 ? 'Done ✓' : 'Exit '+r.exitCode;
        st.style.color = r.exitCode === 0 ? 'var(--grn)' : 'var(--yel)';
      }
    }
  } catch(e) {
    if (out) { out.textContent += 'Error: '+e.message; out.style.color='var(--red)'; }
    if (st) { st.textContent='Error'; st.style.color='var(--red)'; }
  }
  if (btn) { btn.disabled=false; btn.textContent='▶ Run'; }
  if (out) out.scrollTop = out.scrollHeight;
}
// Legacy — still used by Actions tab
async function runAppScript(dirName, scriptName, btn) {
  runScriptModule(dirName, scriptName, btn);
}
function escHtml(s) {
  var d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
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
  if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && !document.getElementById('tab-playground').classList.contains('hidden')) {
    e.preventDefault(); runPlayground();
  }
});

// Scripts
async function loadAppSelect() {
  var d = await api('apps');
  var sel = document.getElementById('script-app');
  sel.innerHTML = (d.apps||[]).map(function(a) {
    return '<option value="'+escHtml(a.dirName)+'">'+escHtml(a.name)+'</option>';
  }).join('');
  loadScripts();
}
async function loadScripts() {
  var app = document.getElementById('script-app').value;
  if (!app) return;
  var d = await api('scripts', {app:app});
  var sel = document.getElementById('script-name');
  sel.innerHTML = (d.scripts||[]).map(function(s) {
    return '<option value="'+escHtml(s.name)+'">'+escHtml(s.name)+' ('+escHtml(s.scope)+')</option>';
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
      + (r.dbError ? '<div style="color:var(--red);font-size:12px;margin-top:4px">DB: ' + escHtml(r.dbError) + '</div>' : '')
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
      extra = '<details style="margin-top:4px"><summary style="font-size:11px;color:var(--dim);cursor:pointer">extra</summary><pre style="font-size:10px;margin-top:4px;max-height:80px;overflow:auto">' + escHtml(JSON.stringify(p.extra, null, 2)) + '</pre></details>';
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
// ── Nearby / Transport ──────────────────────────────
async function loadNearby() {
  try {
    const d = await api('transport/status');
    // Identity
    const idEl = document.getElementById('nearby-identity');
    if (d.mesh_id) {
      idEl.innerHTML = '<strong>' + escHtml(d.mesh_name || 'This device') + '</strong><br>' + escHtml(d.mesh_id);
    } else {
      idEl.textContent = 'Not initialized';
    }
    // Transports
    const tEl = document.getElementById('nearby-transport-list');
    const transports = d.transports || {};
    let tHtml = '';
    for (const [name, enabled] of Object.entries(transports)) {
      const dot = enabled ? '🟢' : '⚪';
      tHtml += '<span style="margin-right:14px">' + dot + ' ' + name + '</span>';
    }
    tEl.innerHTML = tHtml || 'None configured';
    // Peers
    const pEl = document.getElementById('nearby-peers');
    const peers = d.peers || [];
    if (peers.length === 0) {
      pEl.innerHTML = '<div class="card"><p style="color:var(--dim)">No nearby peers detected.</p></div>';
    } else {
      let html = '';
      for (const p of peers) {
        const ago = Math.floor(Date.now()/1000) - (p.lastSeen || 0);
        const transport = p.transport || '?';
        const hops = p.hops > 0 ? ' (' + p.hops + ' hops)' : ' (direct)';
        const encrypted = p.sessionEstablished ? ' 🔒' : '';
        html += '<div class="card" style="margin-bottom:8px;display:flex;justify-content:space-between;align-items:center">'
          + '<div><strong>' + escHtml(p.name || (p.peer_id || '').substring(0,8)) + '</strong>' + encrypted
          + '<br><span style="font-size:11px;color:var(--dim)">' + escHtml(transport) + hops
          + ' · ' + (ago < 5 ? 'just now' : ago + 's ago')
          + '</span><br><span style="font-size:10px;font-family:monospace;color:var(--dim)">' + escHtml((p.peer_id || '').substring(0,16)) + '…</span></div>'
          + '<button class="btn btn-ghost" onclick="disconnectPeer(\'' + (p.peer_id || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'") + '\')">Disconnect</button>'
          + '</div>';
      }
      pEl.innerHTML = html;
    }
    // Sessions
    const sEl = document.getElementById('nearby-session-list');
    const sessions = d.sessions || [];
    if (sessions.length === 0) {
      sEl.textContent = 'No encrypted sessions active';
    } else {
      sEl.innerHTML = sessions.map(s => '<code style="font-size:11px">' + (s || '').substring(0,16) + '…</code>').join(', ');
    }
    // Routing table
    const rEl = document.getElementById('nearby-route-list');
    const routes = d.routes || [];
    if (routes.length === 0) {
      rEl.textContent = 'No routes';
    } else {
      let rHtml = '<table style="width:100%;font-size:12px;border-collapse:collapse">'
        + '<tr style="color:var(--dim)"><td>Destination</td><td>Via</td><td>Hops</td><td>Name</td></tr>';
      for (const r of routes) {
        const dest = (r.destination || '').substring(0,12) + '…';
        const hop = r.next_hop === r.destination ? 'direct' : (r.next_hop || '').substring(0,12) + '…';
        rHtml += '<tr><td><code>' + escHtml(dest) + '</code></td><td>' + escHtml(hop) + '</td><td>' + (r.hops ?? '?') + '</td><td>' + escHtml(r.name || '') + '</td></tr>';
      }
      rHtml += '</table>';
      rEl.innerHTML = rHtml;
    }
  } catch (e) {
    document.getElementById('nearby-peers').innerHTML = '<div class="card"><p style="color:var(--warn)">Error loading: ' + escHtml(e.message) + '</p></div>';
  }
}
async function connectToPeer() {
  const addr = document.getElementById('nearby-connect-addr').value.trim();
  if (!addr) return;
  const el = document.getElementById('nearby-connect-result');
  el.textContent = 'Connecting…';
  try {
    const d = await api('transport/connect', {address: addr});
    if (d.connected) {
      el.innerHTML = '✓ Connected to <strong>' + escHtml(d.name || (d.peer_id||'').substring(0,12)) + '</strong>' + (d.encrypted ? ' 🔒' : '');
      loadNearby();
    } else {
      el.textContent = '✗ ' + (d.error || 'Connection failed');
    }
  } catch (e) { el.textContent = '✗ ' + e.message; }
}
async function disconnectPeer(peerId) {
  if (!confirm('Disconnect peer ' + peerId.substring(0,8) + '?')) return;
  await api('transport/unregister', {peer_id: peerId});
  loadNearby();
}

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
async function deployTo(name, ev) {
  var btn = ev && ev.target ? ev.target : null;
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Deploying...'; }
  var r = await api('servers/deploy', {target: name});
  if (btn) { btn.disabled = false; btn.textContent = '⬆ Deploy'; }
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
  el.innerHTML = d.servers.map(function(s) { var sn = s.name.replace(/\\/g,'\\\\').replace(/'/g,"\\'"); return ''
    + '<div class="app-row">'
    + '<span class="dot on"></span>'
    + '<span class="app-name">' + escHtml(s.name) + '</span>'
    + '<span class="app-url">' + escHtml(s.user + '@' + s.host + ':' + s.path) + '</span>'
    + '<div class="btn-row">'
    + '<button class="btn btn-sm btn-primary" onclick="deployTo(\'' + sn + '\',event)">⬆ Deploy</button>'
    + '<button class="btn btn-sm btn-red" onclick="removeServer(\'' + sn + '\')">✕</button>'
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

async function clearServerCache() {
  var el = document.getElementById('cache-clear-result');
  el.style.display = 'block';
  el.textContent = 'Clearing...';
  try {
    var r = await api('cache/clear');
    if (r.error) { el.textContent = '⚠ ' + r.error; el.style.color = 'var(--red)'; }
    else {
      var items = r.cleared && r.cleared.length ? r.cleared.join(', ') : 'nothing to clear';
      el.textContent = '✅ Cleared: ' + items;
      el.style.color = 'var(--grn)';
    }
  } catch(e) { el.textContent = 'Error: ' + e.message; el.style.color = 'var(--red)'; }
  setTimeout(function() { el.style.display = 'none'; el.style.color = ''; }, 5000);
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
      var dn = d.domain.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
      if (d.certStatus !== 'valid') btns += ' <button class="btn btn-primary" style="font-size:11px;padding:4px 10px" onclick="provisionCert(\'' + dn + '\')">Provision</button>';
      else btns += ' <button class="btn btn-ghost" style="font-size:11px;padding:4px 10px" onclick="provisionCert(\'' + dn + '\')">Renew</button>';
      btns += ' <button class="btn btn-ghost" style="font-size:11px;padding:4px 10px;color:var(--red)" onclick="removeDomain(\'' + dn + '\')">Remove</button>';
      return '<div class="card" style="margin-bottom:8px"><div style="display:flex;justify-content:space-between;align-items:center"><div><strong>' + escHtml(d.domain) + '</strong></div><div>' + badge + btns + '</div></div>'
        + (d.root ? '<div style="font-size:11px;color:var(--dim);margin-top:4px">Root: ' + escHtml(d.root) + '</div>' : '')
        + (d.certExpires ? '<div style="font-size:11px;color:var(--dim);margin-top:2px">Expires: ' + escHtml(d.certExpires) + '</div>' : '')
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
      html += '<div style="font-size:12px;margin-bottom:8px"><strong>Status:</strong> <span style="color:'+color+'">' + escHtml(v.label || '') + ' — ' + (v.valid?'VALID':'FAILED') + '</span></div>';
      if (v.hash_matches === false) {
        html += '<div style="font-size:12px;color:var(--red)">⚠ Binary was modified since signing</div>';
      }
    }
    if (r.signatures && r.signatures.length) {
      html += '<h4 style="font-size:13px;margin:12px 0 6px">Signatures</h4>';
      r.signatures.forEach(function(s) {
        html += '<div style="font-size:12px;padding:4px 0;border-top:1px solid var(--border)">';
        html += '<strong>' + escHtml(s.signer || '') + '</strong> <span style="color:var(--dim)">(key:' + escHtml((s.key_id||'?').slice(0,8)) + ')</span>';
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
  var html = '<div style="color:'+color+';font-weight:700">' + (r.valid ? '✓ VALID' : '✗ FAILED') + ' — ' + escHtml(r.label || '') + '</div>';
  if (r.details) {
    r.details.forEach(function(d) {
      var icon = d.status === 'valid' ? '✓' : '✗';
      html += '<div style="font-size:12px">' + icon + ' ' + escHtml(d.signer) + ' (' + escHtml(d.status) + ')</div>';
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
    ? '<div class="card" style="margin-bottom:12px"><h3 style="font-size:14px;margin-bottom:8px">Currently Provisioning</h3>' + prov.map(function(h){return '<div>\u23f3 '+escHtml(h)+'</div>';}).join('') + '</div>'
    : '';
  var logEl = document.getElementById('ah-log');
  var lines = r.recentLog || [];
  if (lines.length) {
    logEl.innerHTML = '<div class="card"><h3 style="font-size:14px;margin-bottom:8px">Recent Activity</h3>'
      + '<pre style="font-size:11px;max-height:200px;overflow-y:auto;margin:0;white-space:pre-wrap">' + escHtml(lines.join('\n')) + '</pre></div>';
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
  // Hide the app log file tree when viewing server logs
  var tree = document.getElementById('log-file-tree');
  if (tree) tree.classList.add('hidden');
  var r=await api('logs?type='+type+'&lines='+lines);
  var el=document.getElementById('logs-output');
  if(!r.exists){el.textContent='Log file not found: '+r.file;return;}
  el.textContent=r.lines.join('\n');
  el.scrollTop=el.scrollHeight;
}
async function loadAppLogFiles(dirName) {
  var tree = document.getElementById('log-file-tree');
  var out = document.getElementById('logs-output');
  if (!dirName) { if(tree)tree.classList.add('hidden'); return; }
  if(tree)tree.classList.remove('hidden');
  tree.innerHTML = '<p style="color:var(--dim);font-size:12px">Loading\u2026</p>';
  var r = await api('apps/logs?app='+encodeURIComponent(dirName));
  if (r.error) { tree.innerHTML='<p style="color:var(--red);font-size:12px">'+escHtml(r.error)+'</p>'; return; }
  if (!r.files || !r.files.length) {
    tree.innerHTML='<p style="color:var(--dim);font-size:12px">No log files found.</p>';
    return;
  }
  tree.innerHTML = r.files.map(function(f){
    return '<div class="log-file" onclick="viewAppLog(\''+dirName+'\',\''+f.path.replace(/\\/g,'\\\\').replace(/'/g,"\\'")+'\',this)">'
      + '<span>\uD83D\uDCC4</span><span style="flex:1">'+escHtml(f.path)+'</span><span class="log-size">'+fmtBytes(f.size)+'</span></div>';
  }).join('');
}
async function viewAppLog(dirName, filePath, el) {
  if (el) {
    el.parentNode.querySelectorAll('.log-file').forEach(function(f){f.classList.remove('active')});
    el.classList.add('active');
  }
  var out = document.getElementById('logs-output');
  out.textContent = 'Loading\u2026';
  var lines = document.getElementById('log-lines').value;
  var r = await api('apps/logs?app='+encodeURIComponent(dirName)+'&file='+encodeURIComponent(filePath)+'&lines='+lines);
  if (!r.exists) { out.textContent = 'Log file not found'; return; }
  out.textContent = r.lines.join('\n');
  out.scrollTop = out.scrollHeight;
}

// ── Cron ────────────────────────────────────────────
async function loadCron() {
  var r=await api('cron');
  var el=document.getElementById('cron-list');
  if(!r.tasks||!r.tasks.length){el.innerHTML='<div class="card"><p style="color:var(--dim)">No scheduled tasks configured.</p></div>';return;}
  el.innerHTML=r.tasks.map(function(t){
    var sched=t.every?'Every '+t.every+'s':t.times?t.times.join(', '):'manual';
    var tn=t.name.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
    return '<div class="card" style="margin-bottom:8px;display:flex;justify-content:space-between;align-items:center">'
      +'<div><strong>'+escHtml(t.name)+'</strong><div style="font-size:11px;color:var(--dim)">'+escHtml(t.handler)+' · '+escHtml(sched)+'</div></div>'
      +'<button class="btn btn-ghost" style="font-size:11px;padding:4px 10px" onclick="runCron(\''+tn+'\')">Run Now</button></div>';
  }).join('');
}
async function runCron(n){var r=await api('cron/run',{task:n});alert(r.error||'Dispatched '+n);}

// ── Users ──────────────────────────────────────────
async function loadUsers() {
  var r = await api('users');
  var el = document.getElementById('users-list');
  if (r.error) { el.innerHTML = '<div class="card"><p style="color:var(--dim)">' + escHtml(r.error) + '</p></div>'; return; }
  if (!r.users || !r.users.length) { el.innerHTML = '<div class="card"><p style="color:var(--dim)">No users.</p></div>'; return; }
  el.innerHTML = r.users.map(function(u) {
    var roleBadge = u.role === 'owner' ? '<span style="color:var(--grn)">owner</span>'
      : u.role === 'admin' ? '<span style="color:var(--yel)">admin</span>'
      : '<span style="color:var(--dim)">user</span>';
    var btns = '';
    if (u.role !== 'owner') {
      btns += ' <button class="btn btn-ghost" style="font-size:11px;padding:3px 8px" onclick="changeUserRole(\'' + escHtml(u.username) + '\')">Role</button>';
      btns += ' <button class="btn btn-ghost" style="font-size:11px;padding:3px 8px" onclick="resetUserPw(\'' + escHtml(u.username) + '\')">Reset PW</button>';
      btns += ' <button class="btn btn-ghost" style="font-size:11px;padding:3px 8px;color:var(--red)" onclick="removeUser(\'' + escHtml(u.username) + '\')">Remove</button>';
    }
    var branches = u.branches && u.branches.length ? u.branches.join(', ') : '<span style="color:var(--dim)">none</span>';
    return '<div class="card" style="margin-bottom:8px"><div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap">'
      + '<div><strong>' + escHtml(u.username) + '</strong> ' + roleBadge
      + '<div style="font-size:11px;color:var(--dim)">Created: ' + (u.created || '?') + ' · Branches: ' + branches + '</div></div>'
      + '<div>' + btns + '</div></div></div>';
  }).join('');
  // Hide add form for non-admin
  var me = await api('auth/me');
  if (me.role !== 'owner' && me.role !== 'admin') {
    document.getElementById('user-add-form').style.display = 'none';
  }
}
async function addUser() {
  var errEl = document.getElementById('user-add-error');
  errEl.style.display = 'none';
  var username = document.getElementById('user-add-name').value.trim();
  var password = document.getElementById('user-add-pw').value;
  var role = document.getElementById('user-add-role').value;
  if (!username || !password) { errEl.textContent = 'Username and password required'; errEl.style.display = 'block'; return; }
  var r = await api('users/add', {username: username, password: password, role: role});
  if (r.error) { errEl.textContent = r.error; errEl.style.display = 'block'; return; }
  document.getElementById('user-add-name').value = '';
  document.getElementById('user-add-pw').value = '';
  loadUsers();
}
async function changeUserRole(username) {
  var role = prompt('New role for ' + username + ' (admin or user):');
  if (!role) return;
  var r = await api('users/update', {username: username, role: role});
  if (r.error) { alert(r.error); return; }
  loadUsers();
}
async function resetUserPw(username) {
  var pw = prompt('New password for ' + username + ':');
  if (!pw) return;
  var r = await api('users/update', {username: username, password: pw});
  if (r.error) { alert(r.error); return; }
  alert('Password updated for ' + username);
}
async function removeUser(username) {
  if (!confirm('Remove user ' + username + '? This cannot be undone.')) return;
  var r = await api('users/remove', {username: username});
  if (r.error) { alert(r.error); return; }
  loadUsers();
}

// ── Branches ───────────────────────────────────────
async function loadBranches() {
  var r = await api('branches');
  var el = document.getElementById('branches-list');
  if (r.error) { el.innerHTML = '<div class="card"><p style="color:var(--dim)">' + escHtml(r.error) + '</p></div>'; return; }
  if (!r.branches || !r.branches.length) { el.innerHTML = '<div class="card"><p style="color:var(--dim)">No branches created yet.</p></div>'; return; }
  el.innerHTML = r.branches.map(function(b) {
    var db = b.db && b.db.name ? b.db.name : '<span style="color:var(--dim)">none</span>';
    var ek = b.key.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
    var parts = b.key.split('/');
    var btns = '<button class="btn btn-ghost" style="font-size:11px;padding:3px 8px" onclick="branchAccess(\'' + ek + '\')">Access</button>';
    btns += ' <button class="btn btn-ghost" style="font-size:11px;padding:3px 8px" onclick="mergeBranch(\'' + escHtml(b.appHost) + '\',\'' + escHtml(b.name) + '\')">Merge</button>';
    btns += ' <button class="btn btn-ghost" style="font-size:11px;padding:3px 8px;color:var(--yel)" onclick="switchProd(\'' + escHtml(b.appHost) + '\',\'' + escHtml(b.name) + '\')">Switch Prod</button>';
    btns += ' <button class="btn btn-ghost" style="font-size:11px;padding:3px 8px;color:var(--red)" onclick="deleteBranch(\'' + escHtml(b.appHost) + '\',\'' + escHtml(b.name) + '\')">Delete</button>';
    return '<div class="card" style="margin-bottom:8px">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap">'
      + '<div><strong>' + escHtml(b.name) + '</strong> <span style="font-size:11px;color:var(--dim)">(' + escHtml(b.appHost) + ')</span>'
      + '<div style="font-size:11px;color:var(--dim)">By: ' + escHtml(b.createdBy || '?') + ' · ' + (b.created || '') + ' · DB: ' + db + '</div></div>'
      + '<div style="margin-top:4px">' + btns + '</div></div></div>';
  }).join('');
  loadMergeRequests();
}
async function createBranch() {
  var errEl = document.getElementById('br-error');
  errEl.style.display = 'none';
  var appHost = document.getElementById('br-app').value.trim();
  var branchName = document.getElementById('br-name').value.trim();
  if (!appHost || !branchName) { errEl.textContent = 'App host and branch name required'; errEl.style.display = 'block'; return; }
  var r = await api('branches/create', {appHost: appHost, branchName: branchName});
  if (r.error) { errEl.textContent = r.error; errEl.style.display = 'block'; return; }
  document.getElementById('br-name').value = '';
  loadBranches();
}
async function deleteBranch(appHost, name) {
  if (!confirm('Delete branch ' + name + '? This removes all branch files and cannot be undone.')) return;
  var r = await api('branches/delete', {appHost: appHost, branchName: name});
  if (r.error) { alert(r.error); return; }
  loadBranches();
}
async function mergeBranch(appHost, name) {
  if (!confirm('Merge branch ' + name + ' into trunk? This copies all branch changes to the main app.')) return;
  var r = await api('branches/merge', {appHost: appHost, branchName: name});
  if (r.error) { alert(r.error); return; }
  var m = r.merged || {};
  alert('Merged: ' + (m.added||0) + ' added, ' + (m.changed||0) + ' changed, ' + (m.removed||0) + ' removed');
  loadBranches();
}
async function switchProd(appHost, name) {
  if (!confirm('Switch production to branch ' + name + '? This merges all branch changes into the live app.')) return;
  var r = await api('branches/switch-production', {appHost: appHost, branchName: name});
  if (r.error) { alert(r.error); return; }
  alert('Production switched to ' + name);
  loadBranches();
}
async function branchAccess(branchKey) {
  var parts = branchKey.split('/');
  var appHost = parts[0], name = parts.slice(1).join('/');
  var access = prompt('Access list as JSON, e.g. {"alice":{"branch":"edit","files":"frontend"}}');
  if (!access) return;
  try { access = JSON.parse(access); } catch(e) { alert('Invalid JSON'); return; }
  var r = await api('branches/access', {appHost: appHost, branchName: name, access: access});
  if (r.error) { alert(r.error); return; }
  loadBranches();
}
async function saveBranchDbConfig() {
  var appHost = document.getElementById('br-db-app').value.trim();
  var cloneDb = document.getElementById('br-db-name').value.trim();
  var infoEl = document.getElementById('br-db-info');
  if (!appHost) { infoEl.textContent = 'App host required'; infoEl.style.color = 'var(--red)'; return; }
  if (!cloneDb) {
    var r = await api('branches/db-config', {appHost: appHost});
    infoEl.textContent = 'Current: ' + (r.cloneDb || r.defaultCloneDb || 'not set');
    infoEl.style.color = 'var(--dim)';
    return;
  }
  var r = await api('branches/db-config', {appHost: appHost, cloneDb: cloneDb});
  if (r.error) { infoEl.textContent = r.error; infoEl.style.color = 'var(--red)'; return; }
  infoEl.textContent = 'Saved: ' + cloneDb;
  infoEl.style.color = 'var(--grn)';
}
async function loadMergeRequests() {
  var r = await api('branches/mergerequests', {});
  var el = document.getElementById('br-merge-requests');
  if (!r.mergeRequests || !r.mergeRequests.length) {
    el.innerHTML = '<p style="color:var(--dim);font-size:12px">No pending merge requests.</p>';
    return;
  }
  el.innerHTML = r.mergeRequests.map(function(mr) {
    return '<div class="card" style="margin-bottom:6px;padding:8px 12px">'
      + '<div style="display:flex;justify-content:space-between;align-items:center">'
      + '<div><strong>' + escHtml(mr.branchKey || '') + '</strong>'
      + '<div style="font-size:11px;color:var(--dim)">' + escHtml(mr.message || '') + ' · by ' + escHtml(mr.requestedBy || '?') + '</div></div>'
      + '<div><button class="btn btn-primary" style="font-size:11px;padding:3px 8px" onclick="approveMerge(\'' + escHtml(mr.branchKey) + '\')">Approve &amp; Merge</button></div>'
      + '</div></div>';
  }).join('');
}
async function approveMerge(branchKey) {
  var parts = branchKey.split('/');
  if (parts.length < 2) { alert('Invalid branch key'); return; }
  var appHost = parts[0], name = parts.slice(1).join('/');
  if (!confirm('Approve and merge ' + name + ' into trunk?')) return;
  var r = await api('branches/merge', {appHost: appHost, branchName: name});
  if (r.error) { alert(r.error); return; }
  var m = r.merged || {};
  alert('Merged: ' + (m.added||0) + ' added, ' + (m.changed||0) + ' changed, ' + (m.removed||0) + ' removed');
  loadBranches();
}

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
        info += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="runFwCmd(\'' + fw.framework + '\',\'' + cmd.cmd.replace(/\\/g,'\\\\').replace(/'/g,"\\'") + '\',this)">' + escHtml(cmd.name) + '</button>';
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

// ── Mobile Build ─────────────────────────────────

var _mobileToolchains = [];

async function loadToolchains() {
  var el = document.getElementById('mobile-toolchains');
  el.innerHTML = '<p style="color:var(--dim);font-size:12px">Detecting…</p>';
  try {
    var d = await api('mobile/toolchains');
    _mobileToolchains = d.toolchains || [];
    var html = '<table style="width:100%;font-size:12px"><tbody>';
    _mobileToolchains.forEach(function(t) {
      var badge = t.installed
        ? '<span style="color:var(--grn)">✓ ' + (t.version||'installed') + '</span>'
        : (t.unavailable
          ? '<span style="color:var(--dim)">n/a</span>'
          : '<span style="color:var(--yel)">✗ missing</span>');
      var hint = '';
      if (!t.installed && t.hint && !t.unavailable) {
        hint = ' <code style="font-size:10px;opacity:.7">' + t.hint + '</code>';
      }
      var plat = t.platform === 'both' ? '🍎🤖' : (t.platform === 'ios' ? '🍎' : '🤖');
      html += '<tr><td style="padding:4px 8px">' + plat + '</td>'
        + '<td style="padding:4px 8px">' + t.name + '</td>'
        + '<td style="padding:4px 8px">' + badge + hint + '</td></tr>';
    });
    html += '</tbody></table>';
    el.innerHTML = html;
    // Update platform card availability
    var hasXcode = _mobileToolchains.some(function(t){ return t.id==='xcode' && t.installed; });
    var hasAndroid = _mobileToolchains.some(function(t){ return t.id==='android-sdk' && t.installed; });
    var hasJdk = _mobileToolchains.some(function(t){ return t.id==='jdk' && t.installed; });
    document.getElementById('mobile-ios-status').textContent = hasXcode ? 'Ready' : 'Missing tools';
    document.getElementById('mobile-ios-status').style.color = hasXcode ? 'var(--grn)' : 'var(--yel)';
    document.getElementById('mobile-android-status').textContent = (hasAndroid && hasJdk) ? 'Ready' : 'Missing tools';
    document.getElementById('mobile-android-status').style.color = (hasAndroid && hasJdk) ? 'var(--grn)' : 'var(--yel)';
  } catch(e) {
    el.innerHTML = '<p style="color:var(--red);font-size:12px">Error: ' + e.message + '</p>';
  }
}

async function loadMobileAppSelect() {
  var sel = document.getElementById('mobile-app');
  if (!_appsData.length) {
    var d = await api('apps');
    _appsData = d.apps || [];
  }
  sel.innerHTML = '<option value="">Select an app…</option>' + _appsData.map(function(a) {
    return '<option value="' + escHtml(a.dirName) + '">' + escHtml(a.name||a.dirName) + '</option>';
  }).join('');
}

var _mobileConfig = {};

async function loadMobileApp(dirName) {
  var panel = document.getElementById('mobile-app-panel');
  if (!dirName) { panel.style.display = 'none'; return; }
  panel.style.display = '';
  try {
    var d = await api('mobile/config', {appDir: dirName});
    _mobileConfig = d.config || {};
    document.getElementById('mobile-bundle-id').value = _mobileConfig.bundleId || '';
    document.getElementById('mobile-app-name').value = _mobileConfig.appName || '';
    document.getElementById('mobile-version').value = _mobileConfig.version || '1.0.0';
    document.getElementById('mobile-build-num').value = _mobileConfig.buildNumber || 1;
    // Update prepare button labels
    document.getElementById('mobile-ios-prepare').textContent = _mobileConfig.iosPrepared ? '📁 Re-Prepare Project' : '📁 Prepare Project';
    document.getElementById('mobile-android-prepare').textContent = _mobileConfig.androidPrepared ? '📁 Re-Prepare Project' : '📁 Prepare Project';
    // Load build history
    loadMobileBuilds(dirName);
  } catch(e) {
    panel.innerHTML = '<p style="color:var(--red)">Error loading config: ' + e.message + '</p>';
  }
}

async function saveMobileConfig() {
  var dirName = document.getElementById('mobile-app').value;
  if (!dirName) return;
  var cfg = {
    bundleId: document.getElementById('mobile-bundle-id').value,
    appName: document.getElementById('mobile-app-name').value,
    version: document.getElementById('mobile-version').value,
    buildNumber: parseInt(document.getElementById('mobile-build-num').value) || 1
  };
  var st = document.getElementById('mobile-config-status');
  st.textContent = 'Saving…';
  try {
    await api('mobile/config', {appDir: dirName, config: cfg});
    st.textContent = 'Saved ✓';
    st.style.color = 'var(--grn)';
    setTimeout(function(){ st.textContent = ''; }, 2000);
  } catch(e) {
    st.textContent = 'Error: ' + e.message;
    st.style.color = 'var(--red)';
  }
}

async function mobilePrepare(platform) {
  var dirName = document.getElementById('mobile-app').value;
  if (!dirName) return;
  var btn = document.getElementById('mobile-' + platform + '-prepare');
  var out = document.getElementById('mobile-' + platform + '-output');
  btn.disabled = true; btn.textContent = '⏳ Preparing…';
  out.style.display = 'block'; out.textContent = 'Scaffolding ' + platform + ' project…\n';
  try {
    var d = await api('mobile/prepare', {appDir: dirName, platform: platform});
    if (d.log) {
      out.textContent = d.log.join('\n') + '\n\n' + (d.prepared ? '✅ Project prepared at:\n' + d.path : '❌ Failed');
    } else {
      out.textContent = JSON.stringify(d, null, 2);
    }
    btn.textContent = '📁 Re-Prepare Project';
    loadMobileBuilds(dirName);
  } catch(e) {
    out.textContent += '\n❌ Error: ' + e.message;
  }
  btn.disabled = false;
}

async function mobileBuild(platform) {
  var dirName = document.getElementById('mobile-app').value;
  if (!dirName) return;
  var btn = document.getElementById('mobile-' + platform + '-build');
  var out = document.getElementById('mobile-' + platform + '-output');
  btn.disabled = true; btn.textContent = '⏳ Building…';
  out.style.display = 'block'; out.textContent = 'Running ' + platform + ' build…\n';
  try {
    var d = await api('mobile/build', {appDir: dirName, platform: platform, buildType: 'debug'});
    out.textContent = (d.output || '') + '\n\n' + (d.success ? '✅ Build succeeded' : '❌ Build failed');
    if (d.artifact) out.textContent += '\nArtifact: ' + d.artifact;
    loadMobileBuilds(dirName);
  } catch(e) {
    out.textContent += '\n❌ Error: ' + e.message;
  }
  btn.disabled = false;
  btn.textContent = '🔨 Build';
}

async function loadMobileBuilds(dirName) {
  try {
    var d = await api('mobile/builds', {appDir: dirName});
    // Artifacts
    var aEl = document.getElementById('mobile-artifacts');
    if (d.artifacts && d.artifacts.length) {
      aEl.innerHTML = d.artifacts.map(function(a) {
        var plat = a.platform === 'ios' ? '🍎' : '🤖';
        var size = a.size ? ' (' + formatBytes(a.size) + ')' : '';
        var mod = a.modified ? ' — ' + new Date(a.modified).toLocaleString() : '';
        return '<div style="font-size:12px;padding:4px 0">' + plat + ' <code>' + a.path.split('/').pop() + '</code>' + size + mod + '</div>';
      }).join('');
    } else {
      aEl.innerHTML = '<p style="color:var(--dim);font-size:12px">No build artifacts yet.</p>';
    }
    // History
    var hEl = document.getElementById('mobile-history');
    if (d.builds && d.builds.length) {
      var rows = d.builds.slice().reverse().slice(0, 20);
      hEl.innerHTML = '<table style="width:100%;font-size:12px"><thead><tr>'
        + '<th style="text-align:left;padding:4px">Platform</th>'
        + '<th style="text-align:left;padding:4px">Type</th>'
        + '<th style="text-align:left;padding:4px">Result</th>'
        + '<th style="text-align:left;padding:4px">Time</th></tr></thead><tbody>'
        + rows.map(function(b) {
          var plat = b.platform === 'ios' ? '🍎 iOS' : '🤖 Android';
          var res = b.success ? '<span style="color:var(--grn)">✓</span>' : '<span style="color:var(--red)">✗</span>';
          var t = new Date(b.timestamp).toLocaleString();
          return '<tr><td style="padding:4px">' + plat + '</td>'
            + '<td style="padding:4px">' + (b.buildType||'debug') + '</td>'
            + '<td style="padding:4px">' + res + '</td>'
            + '<td style="padding:4px">' + t + '</td></tr>';
        }).join('') + '</tbody></table>';
    } else {
      hEl.innerHTML = '<p style="color:var(--dim);font-size:12px">No build history.</p>';
    }
  } catch(e) {
    // silent
  }
}

function formatBytes(b) {
  if (!b) return '0 B';
  var u = ['B','KB','MB','GB']; var i = 0;
  while (b >= 1024 && i < 3) { b /= 1024; i++; }
  return b.toFixed(1) + ' ' + u[i];
}

// ── Client Metrics ────────────────────────────────────
var _cmDates = [];
var _cmCurrentDate = '';
async function loadClientMetrics() {
  var statusEl = document.getElementById('cm-status');
  var overviewEl = document.getElementById('cm-overview');
  try {
    var d = await api('client-metrics');
    if (d.error) {
      statusEl.innerHTML = '<div class="card"><p style="color:var(--dim)">Client metrics not enabled. Set <code>Q.webserver.clientMetrics.enabled = true</code> in config.</p></div>';
      overviewEl.style.display = 'none';
      return;
    }
    _cmDates = d.dates || [];
    statusEl.innerHTML = '';
    overviewEl.style.display = '';

    // Populate date select (newest first)
    var sel = document.getElementById('cm-date-select');
    sel.innerHTML = _cmDates.slice().reverse().map(function(dt) {
      return '<option value="'+dt+'">'+dt+'</option>';
    }).join('');

    // Show today's summary if available
    if (d.today) {
      renderClientMetricsSummary(d.today);
      _cmCurrentDate = _cmDates[_cmDates.length - 1] || '';
    } else if (_cmDates.length) {
      _cmCurrentDate = _cmDates[_cmDates.length - 1];
      loadClientMetricsDate(_cmCurrentDate);
    } else {
      document.getElementById('cm-stats').innerHTML = '<p style="color:var(--dim);font-size:13px">No client metrics data yet. Events will appear once visitors interact with pages.</p>';
    }
  } catch(e) {
    statusEl.innerHTML = '<div class="card"><p style="color:var(--red)">Error loading client metrics: '+escHtml(e.message)+'</p></div>';
  }
}

async function loadClientMetricsDate(date) {
  _cmCurrentDate = date;
  var tsvLink = document.getElementById('cm-tsv-link');
  tsvLink.href = API + '/client-metrics/' + date + '/tsv';
  tsvLink.style.display = 'inline';
  try {
    var d = await api('client-metrics/' + date);
    renderClientMetricsSummary(d);
    loadClientMetricsEvents(date, '', '');
  } catch(e) { /* silent */ }
}

function renderClientMetricsSummary(s) {
  var statsEl = document.getElementById('cm-stats');
  statsEl.innerHTML =
    '<div style="text-align:center"><div style="font-size:24px;font-weight:700">'+
      (s.events||0)+'</div><div style="font-size:12px;color:var(--dim)">Events</div></div>'+
    '<div style="text-align:center"><div style="font-size:24px;font-weight:700">'+
      (s.visitors||0)+'</div><div style="font-size:12px;color:var(--dim)">Visitors</div></div>'+
    '<div style="text-align:center"><div style="font-size:24px;font-weight:700">'+
      (s.sessions||0)+'</div><div style="font-size:12px;color:var(--dim)">Sessions</div></div>';

  var sumEl = document.getElementById('cm-summary');
  var html = '';
  if (s.topLabels && Object.keys(s.topLabels).length) {
    html += '<div style="margin-bottom:12px"><strong style="font-size:13px">Top Event Types</strong>';
    html += '<table style="width:100%;font-size:12px;margin-top:4px;border-collapse:collapse">';
    var labels = Object.entries(s.topLabels).slice(0, 10);
    labels.forEach(function(kv) {
      html += '<tr><td style="padding:2px 8px 2px 0">'+escHtml(kv[0])+'</td><td style="padding:2px 0;text-align:right;color:var(--dim)">'+kv[1]+'</td></tr>';
    });
    html += '</table></div>';
  }
  if (s.topPages && Object.keys(s.topPages).length) {
    html += '<div><strong style="font-size:13px">Top Pages</strong>';
    html += '<table style="width:100%;font-size:12px;margin-top:4px;border-collapse:collapse">';
    var pages = Object.entries(s.topPages).slice(0, 10);
    pages.forEach(function(kv) {
      html += '<tr><td style="padding:2px 8px 2px 0">'+escHtml(kv[0])+'</td><td style="padding:2px 0;text-align:right;color:var(--dim)">'+kv[1]+'</td></tr>';
    });
    html += '</table></div>';
  }
  sumEl.innerHTML = html;
}

async function loadClientMetricsEvents(date, label, page) {
  var evEl = document.getElementById('cm-events');
  var params = '?limit=200';
  if (label) params += '&label=' + encodeURIComponent(label);
  if (page) params += '&page=' + encodeURIComponent(page);
  try {
    var d = await api('client-metrics/' + date + '/events' + params);
    if (!d.rows || !d.rows.length) {
      evEl.innerHTML = '<p style="color:var(--dim);font-size:12px">No events' +
        (label || page ? ' matching filter' : '') + '.</p>';
      return;
    }
    var cols = d.columns || Object.keys(d.rows[0]);
    var html = '<table style="width:100%;font-size:11px;border-collapse:collapse">';
    html += '<thead><tr>' + cols.map(function(c){
      return '<th style="padding:3px 6px;text-align:left;border-bottom:1px solid var(--brd);white-space:nowrap">'+escHtml(c)+'</th>';
    }).join('') + '</tr></thead><tbody>';
    d.rows.forEach(function(row) {
      html += '<tr>' + cols.map(function(c){
        var v = row[c] || '';
        if (v.length > 60) v = v.slice(0, 57) + '...';
        return '<td style="padding:2px 6px;border-bottom:1px solid var(--brd);white-space:nowrap;max-width:200px;overflow:hidden;text-overflow:ellipsis">'+escHtml(v)+'</td>';
      }).join('') + '</tr>';
    });
    html += '</tbody></table>';
    if (d.total > d.rows.length) {
      html += '<p style="font-size:11px;color:var(--dim);margin-top:4px">Showing '+d.rows.length+' of '+d.total+' events</p>';
    }
    evEl.innerHTML = html;
  } catch(e) {
    evEl.innerHTML = '<p style="color:var(--red);font-size:12px">Error loading events</p>';
  }
}

function applyClientMetricsFilter() {
  var label = document.getElementById('cm-filter-label').value.trim();
  var page = document.getElementById('cm-filter-page').value.trim();
  if (_cmCurrentDate) loadClientMetricsEvents(_cmCurrentDate, label, page);
}

// ── Analytics (Sankey) ─────────────────────────────

var _anSessionOffset = 0;
var _anDrillPath = null;
var _anOverviewData = null;

function anFilters() {
  var f = {};
  var period = document.getElementById('an-period').value;
  if (period) f.from = Math.floor(Date.now()/1000) - parseInt(period);
  var host = document.getElementById('an-host').value;
  if (host) f.host = host;
  var plat = document.getElementById('an-platform').value;
  if (plat) f.platform = plat;
  var br = document.getElementById('an-browser').value;
  if (br) f.browser = br;
  var lang = document.getElementById('an-language').value.trim();
  if (lang) f.language = lang;
  var ip = document.getElementById('an-ip').value.trim();
  if (ip) f.ip = ip;
  var path = document.getElementById('an-path').value.trim();
  if (path) f.path = path;
  return f;
}

function anQueryString(f) {
  var parts = [];
  for (var k in f) parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(f[k]));
  return parts.join('&');
}

async function loadAnalytics() {
  var statusEl = document.getElementById('an-status');
  var mainEl = document.getElementById('an-main');
  try {
    var f = anFilters();
    var qs = anQueryString(f);
    var data = await api('metrics/analytics' + (qs ? '?' + qs : ''));
    _anOverviewData = data;
    mainEl.style.display = '';
    statusEl.textContent = '';

    // Populate filter dropdowns from data
    var hostSel = document.getElementById('an-host');
    if (data.hosts && hostSel.options.length <= 1) {
      data.hosts.forEach(function(h) {
        var o = document.createElement('option'); o.value = h; o.textContent = h;
        hostSel.appendChild(o);
      });
    }
    var platSel = document.getElementById('an-platform');
    if (data.topPlatforms && platSel.options.length <= 1) {
      data.topPlatforms.forEach(function(p) {
        var o = document.createElement('option'); o.value = p.platform; o.textContent = p.platform + ' (' + p.count + ')';
        platSel.appendChild(o);
      });
    }
    var brSel = document.getElementById('an-browser');
    if (data.topBrowsers && brSel.options.length <= 1) {
      data.topBrowsers.forEach(function(b) {
        var o = document.createElement('option'); o.value = b.browser; o.textContent = b.browser + ' (' + b.count + ')';
        brSel.appendChild(o);
      });
    }

    // Overview stats
    var ov = document.getElementById('an-overview');
    ov.innerHTML = '<div><div style="font-size:22px;font-weight:700">' + (data.pageViews||0).toLocaleString() + '</div><div style="font-size:11px;color:var(--dim)">Page Views</div></div>'
      + '<div><div style="font-size:22px;font-weight:700">' + (data.sessions||0).toLocaleString() + '</div><div style="font-size:11px;color:var(--dim)">Sessions</div></div>'
      + '<div><div style="font-size:22px;font-weight:700">' + (data.uniqueIps||0).toLocaleString() + '</div><div style="font-size:11px;color:var(--dim)">Unique IPs</div></div>'
      + '<div><div style="font-size:22px;font-weight:700">' + (data.avgMs||0).toFixed(0) + ' ms</div><div style="font-size:11px;color:var(--dim)">Avg Response</div></div>';

    // Top pages
    var pagesEl = document.getElementById('an-pages');
    if (data.topPages && data.topPages.length) {
      var maxH = data.topPages[0].hits;
      pagesEl.innerHTML = data.topPages.map(function(p) {
        var pct = maxH > 0 ? (p.hits / maxH * 100) : 0;
        return '<div style="margin-bottom:4px;cursor:pointer" onclick="analyticsDrill(\'' + escAttr(p.path) + '\')">'
          + '<div style="display:flex;justify-content:space-between;font-size:12px"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:70%">' + escHtml(p.path) + '</span><span style="color:var(--dim)">' + p.hits + '</span></div>'
          + '<div style="height:3px;background:var(--brd);border-radius:2px;margin-top:2px"><div style="height:100%;background:var(--ac);border-radius:2px;width:' + pct + '%"></div></div></div>';
      }).join('');
    } else {
      pagesEl.innerHTML = '<div style="font-size:13px;color:var(--dim)">No data yet</div>';
    }

    // Load Sankey
    _anDrillPath = null;
    document.getElementById('an-breadcrumb').style.display = 'none';
    loadSankey(f);

    // Load sessions
    _anSessionOffset = 0;
    loadSessions(f, false);
  } catch(e) {
    if (e.message === 'auth') return;
    statusEl.innerHTML = '<div style="color:var(--dim);font-size:13px">No analytics data yet. Analytics are recorded automatically when server-side metrics are enabled.</div>';
  }
}

async function loadSankey(f, drillPath) {
  try {
    var qs = anQueryString(f);
    var url = drillPath
      ? 'metrics/analytics/drilldown?path=' + encodeURIComponent(drillPath) + '&direction=outgoing' + (qs ? '&' + qs : '')
      : 'metrics/analytics/flow?limit=100' + (qs ? '&' + qs : '');
    var data = await api(url);
    var edges = data.edges || [];
    renderSankey(edges, drillPath);
  } catch(e) {}
}

function renderSankey(edges, drillPath) {
  var svg = d3.select('#an-sankey');
  svg.selectAll('*').remove();
  if (!edges || !edges.length) {
    svg.attr('height', 60);
    svg.append('text').attr('x', 20).attr('y', 30).attr('fill', 'var(--dim)').attr('font-size', '13px').text('No flow data for the current filters.');
    return;
  }

  var wrap = document.getElementById('an-sankey-wrap');
  var width = Math.max(wrap.clientWidth - 16, 400);
  var nodeSet = {};
  edges.forEach(function(e) { nodeSet[e.from_path || e.from] = true; nodeSet[e.to_path || e.to] = true; });
  var nodeNames = Object.keys(nodeSet);
  var nodes = nodeNames.map(function(n) { return {name: n}; });
  var nameIdx = {};
  nodeNames.forEach(function(n, i) { nameIdx[n] = i; });
  var links = edges.map(function(e) {
    var src = e.from_path || e.from;
    var tgt = e.to_path || e.to;
    return {source: nameIdx[src], target: nameIdx[tgt], value: parseInt(e.count) || 1};
  }).filter(function(l) { return l.source !== l.target; }); // remove self-loops
  if (!links.length) {
    svg.attr('height', 60);
    svg.append('text').attr('x', 20).attr('y', 30).attr('fill', 'var(--dim)').attr('font-size', '13px').text('No transitions to display.');
    return;
  }

  var height = Math.max(nodes.length * 28, 300);
  svg.attr('width', width).attr('height', height).attr('viewBox', '0 0 ' + width + ' ' + height);

  var sankey = d3.sankey()
    .nodeWidth(14).nodePadding(10)
    .nodeSort(null)
    .extent([[1, 1], [width - 1, height - 6]]);

  var graph;
  try {
    graph = sankey({nodes: nodes.map(function(d) { return Object.assign({}, d); }), links: links.map(function(d) { return Object.assign({}, d); })});
  } catch(err) {
    svg.attr('height', 60);
    svg.append('text').attr('x', 20).attr('y', 30).attr('fill', 'var(--dim)').attr('font-size', '13px').text('Could not render Sankey (data may have circular references).');
    return;
  }

  var color = d3.scaleOrdinal(d3.schemeTableau10);

  // Links
  svg.append('g').attr('fill', 'none').attr('stroke-opacity', 0.35)
    .selectAll('path').data(graph.links).join('path')
    .attr('d', d3.sankeyLinkHorizontal())
    .attr('stroke', function(d) { return color(d.source.name); })
    .attr('stroke-width', function(d) { return Math.max(1, d.width); })
    .append('title').text(function(d) { return d.source.name + ' → ' + d.target.name + '\n' + d.value + ' transitions'; });

  // Nodes
  var node = svg.append('g').selectAll('g').data(graph.nodes).join('g');
  node.append('rect')
    .attr('x', function(d) { return d.x0; }).attr('y', function(d) { return d.y0; })
    .attr('height', function(d) { return Math.max(1, d.y1 - d.y0); })
    .attr('width', function(d) { return d.x1 - d.x0; })
    .attr('fill', function(d) { return color(d.name); })
    .attr('rx', 2)
    .style('cursor', 'pointer')
    .on('click', function(ev, d) { analyticsDrill(d.name); })
    .append('title').text(function(d) { return d.name + '\n' + d.value + ' views'; });

  // Labels
  node.append('text')
    .attr('x', function(d) { return d.x0 < width / 2 ? d.x1 + 6 : d.x0 - 6; })
    .attr('y', function(d) { return (d.y1 + d.y0) / 2; })
    .attr('dy', '0.35em')
    .attr('text-anchor', function(d) { return d.x0 < width / 2 ? 'start' : 'end'; })
    .attr('font-size', '11px').attr('fill', 'var(--fg)')
    .text(function(d) {
      var label = d.name.length > 40 ? d.name.substring(0, 37) + '...' : d.name;
      return label + ' (' + d.value + ')';
    });
}

function analyticsDrill(path) {
  _anDrillPath = path;
  var bc = document.getElementById('an-breadcrumb');
  bc.style.display = '';
  document.getElementById('an-bc-text').textContent = ' → ' + path;
  loadSankey(anFilters(), path);
}

function analyticsResetDrill() {
  _anDrillPath = null;
  document.getElementById('an-breadcrumb').style.display = 'none';
  loadSankey(anFilters());
}

async function loadSessions(f, append) {
  try {
    var qs = anQueryString(f);
    var data = await api('metrics/analytics/sessions?limit=50&offset=' + _anSessionOffset + (qs ? '&' + qs : ''));
    var sessions = data.sessions || [];
    var el = document.getElementById('an-sessions');
    if (!append) el.innerHTML = '';
    var countEl = document.getElementById('an-sess-count');
    if (_anOverviewData) countEl.textContent = '(' + (_anOverviewData.sessions||0).toLocaleString() + ' total)';

    if (!sessions.length && !append) {
      el.innerHTML = '<div style="font-size:13px;color:var(--dim)">No sessions found</div>';
      document.getElementById('an-sess-more').style.display = 'none';
      return;
    }
    sessions.forEach(function(s) {
      var div = document.createElement('div');
      div.style.cssText = 'padding:6px 8px;border-bottom:1px solid var(--brd);cursor:pointer;font-size:12px';
      div.onmouseover = function() { this.style.background = 'rgba(128,128,128,0.1)'; };
      div.onmouseout = function() { this.style.background = ''; };
      div.onclick = function() { loadSessionDetail(s.session_id); };
      var dt = new Date(s.first_seen * 1000);
      var dur = s.last_seen - s.first_seen;
      var durStr = dur < 60 ? dur + 's' : Math.round(dur / 60) + 'm';
      div.innerHTML = '<div style="display:flex;justify-content:space-between;margin-bottom:2px">'
        + '<span style="color:var(--ac)">' + escHtml(s.entry_path || '/') + '</span>'
        + '<span style="color:var(--dim)">' + s.page_count + ' pages · ' + durStr + '</span></div>'
        + '<div style="color:var(--dim)">' + escHtml(s.platform||'') + ' · ' + escHtml(s.browser||'') + ' · ' + escHtml(s.ip||'') + ' · ' + dt.toLocaleString() + '</div>';
      el.appendChild(div);
    });
    document.getElementById('an-sess-more').style.display = sessions.length >= 50 ? '' : 'none';
  } catch(e) {}
}

function loadMoreSessions() {
  _anSessionOffset += 50;
  loadSessions(anFilters(), true);
}

async function loadSessionDetail(sid) {
  try {
    var data = await api('metrics/analytics/session?id=' + encodeURIComponent(sid));
    var reqs = data.requests || [];
    var wrap = document.getElementById('an-session-detail');
    var el = document.getElementById('an-session-path');
    wrap.style.display = '';
    if (!reqs.length) {
      el.innerHTML = '<div style="font-size:13px;color:var(--dim)">No requests found</div>';
      return;
    }
    var html = '<div style="display:flex;flex-direction:column;gap:0">';
    reqs.forEach(function(r, i) {
      var dt = new Date(r.ts * 1000);
      var color = r.status < 400 ? 'var(--grn)' : 'var(--red, #e74c3c)';
      html += '<div style="display:flex;align-items:flex-start;gap:8px;padding:4px 0;font-size:12px">'
        + '<div style="min-width:18px;text-align:center">';
      if (i < reqs.length - 1) {
        html += '<div style="width:2px;height:24px;background:var(--brd);margin:2px auto"></div>';
      }
      html += '</div>'
        + '<div style="width:8px;height:8px;border-radius:50%;background:' + color + ';margin-top:4px;flex-shrink:0"></div>'
        + '<div style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + escHtml(r.path) + '</div>'
        + '<div style="color:var(--dim);white-space:nowrap">' + (r.duration_ms||0).toFixed(0) + 'ms · ' + dt.toLocaleTimeString() + '</div>'
        + '</div>';
    });
    html += '</div>';
    el.innerHTML = html;
    wrap.scrollIntoView({behavior: 'smooth', block: 'nearest'});
  } catch(e) {}
}

function escAttr(s) { return s.replace(/'/g, "\\'").replace(/"/g, '&quot;'); }

// Init
checkAuthAndInit();
</script></body></html>
HTML;
	}
}
