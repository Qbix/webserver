<?php
/**
 * Q_WebServer_Branch — branch management for collaborative development.
 *
 * A branch is a copy-on-write clone of a running app: its own filesystem
 * tree (symlinks to trunk, real copies for changes), its own database,
 * and its own injected credentials. Multiple people can work on a branch
 * independently, with changes merged back to the original when ready.
 *
 * State is stored in a JSON file (default: data/branches.json) that the
 * parent process reads and updates. Branch workers never write to it.
 *
 * When the Qbix Platform is loaded, delegates to Q_Utils::symlink() and
 * Q_Utils::rmdir() for cross-platform filesystem operations. Falls back
 * to native PHP calls when running standalone.
 *
 * NOTE: This is app-level branching (the entire document root). The
 * Platform's Q_Branch class handles content-level branching (stream
 * uploads, file stores) and uses a ~store sibling pattern. The two are
 * complementary: Q_Branch forks content within a branch, while this class
 * forks the app around it.
 *
 * @class Q_WebServer_Branch
 * @static
 */
class Q_WebServer_Branch
{
	/**
	 * The currently active branch name for this request, or null for trunk.
	 * Set during resolve() in handleRequest().
	 * @property $current
	 * @type string|null
	 * @static
	 */
	static $current = null;

	/**
	 * The full branch record for the current request, or null.
	 * @property $currentRecord
	 * @type array|null
	 * @static
	 */
	static $currentRecord = null;

	/**
	 * The authenticated user's identity for the current request.
	 * Set during auth check. Used for permission lookups.
	 * @property $currentUser
	 * @type string|null
	 * @static
	 */
	static $currentUser = null;

	/**
	 * Loaded branch state. Cached after first read.
	 * @property $state
	 * @type array|null
	 * @static
	 */
	static $state = null;

	/**
	 * Path to the state file.
	 * @property $stateFile
	 * @type string|null
	 * @static
	 */
	private static $stateFile = null;

	/**
	 * File permission tiers — each is a superset of the previous.
	 * Maps tier name to the set of extensions allowed at that tier.
	 * @property $tiers
	 * @type array
	 * @static
	 */
	static $tiers = array(
		'styles' => array(
			'css', 'scss', 'less', 'sass',
		),
		'markup' => array(
			'css', 'scss', 'less', 'sass',
			'html', 'htm', 'svg', 'md', 'txt',
			'handlebars', 'hbs', 'mustache', 'twig', 'blade', 'ejs', 'pug', 'njk',
			'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp',
			'woff', 'woff2', 'ttf', 'otf', 'eot',
			'json', 'xml', 'yaml', 'yml', 'toml',
		),
		'frontend' => array(
			'css', 'scss', 'less', 'sass',
			'html', 'htm', 'svg', 'md', 'txt',
			'handlebars', 'hbs', 'mustache', 'twig', 'blade', 'ejs', 'pug', 'njk',
			'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp',
			'woff', 'woff2', 'ttf', 'otf', 'eot',
			'json', 'xml', 'yaml', 'yml', 'toml',
			'js', 'ts', 'jsx', 'tsx', 'vue', 'svelte', 'mjs', 'cjs',
		),
		'code' => null, // null means all extensions allowed
	);

	/**
	 * Tier hierarchy for comparison: higher number = more permissive.
	 * @property $tierLevel
	 * @type array
	 * @static
	 */
	static $tierLevel = array(
		'styles' => 0,
		'markup' => 1,
		'frontend' => 2,
		'code' => 3,
	);

	/**
	 * Known API key prefixes that definitively identify a secret value.
	 * @property $secretPrefixes
	 * @type array
	 * @static
	 */
	static $secretPrefixes = array(
		'sk_live_', 'sk_test_', 'pk_live_', 'pk_test_',  // Stripe
		'rk_live_', 'rk_test_',                           // Stripe restricted
		'whsec_',                                          // Stripe webhook
		'AKIA',                                            // AWS
		'ghp_', 'gho_', 'ghs_', 'github_pat_',           // GitHub
		'xoxb-', 'xoxp-', 'xapp-',                       // Slack
		'SG.',                                             // SendGrid
		'sk-',                                             // OpenAI
		'glpat-',                                          // GitLab
	);

	/**
	 * Key name substrings that indicate the value is likely a credential.
	 * @property $secretKeywords
	 * @type array
	 * @static
	 */
	static $secretKeywords = array(
		'password', 'secret', 'token', 'auth',
		'credential', 'apikey', 'api_key', 'passphrase',
		'private_key', 'privatekey', 'access_key', 'accesskey',
	);

	// ─── Default lockdown ──────────────────────────────────────────

	/**
	 * Get the default lockdown configuration for new branches.
	 *
	 * Reads Q.webserver.branches.defaults from config and merges it
	 * over built-in safe defaults. The result controls:
	 *
	 *   fileTier    — default file permission tier for regular users
	 *   sandbox     — sandbox config applied to all branch workers
	 *   denyPaths   — glob patterns always denied even if tier allows
	 *   preset      — framework preset name (auto-detected from app if null)
	 *
	 * Admins can relax these per-branch via the access list, the
	 * branch sandbox config, or per-user path/config overrides.
	 *
	 * @method getDefaults
	 * @static
	 * @param {string|null} $appHost  If given, merge app-specific defaults
	 * @return {array}
	 */
	static function getDefaults($appHost = null)
	{
		// Built-in safe defaults — locked down
		$defaults = array(
			'fileTier' => 'markup',      // no JS/PHP by default
			'sandbox' => array(
				'allowShell' => false,   // no shell access
				'allowPaths' => array(), // no extra filesystem access
			),
			'denyPaths' => array(
				'.env', '.env.*',          // environment files
				'**/.git/**',              // git internals
				'**/node_modules/**',      // vendor code
				'**/vendor/**',
				'**/.ssh/**',
				'**/.gnupg/**',
			),
		);

		// Merge config overrides: Q.webserver.branches.defaults
		$configDefaults = Q_Config::get(
			'Q', 'webserver', 'branches', 'defaults', array()
		);
		if ($configDefaults) {
			if (isset($configDefaults['fileTier'])
				&& isset(self::$tierLevel[$configDefaults['fileTier']])
			) {
				$defaults['fileTier'] = $configDefaults['fileTier'];
			}
			if (isset($configDefaults['sandbox'])) {
				$defaults['sandbox'] = array_merge(
					$defaults['sandbox'], $configDefaults['sandbox']
				);
			}
			if (isset($configDefaults['denyPaths'])) {
				$defaults['denyPaths'] = array_merge(
					$defaults['denyPaths'], $configDefaults['denyPaths']
				);
			}
			if (isset($configDefaults['preset'])) {
				$defaults['preset'] = $configDefaults['preset'];
			}
		}

		// Merge per-app overrides from config
		if ($appHost) {
			$appDefaults = Q_Config::get(
				'Q', 'webserver', 'hosts', $appHost, 'branches', 'defaults',
				null
			);
			if (!$appDefaults) {
				$appDefaults = Q_Config::get(
					'Q', 'webserver', 'domains', $appHost, 'branches', 'defaults',
					null
				);
			}
			if (is_array($appDefaults)) {
				$defaults = self::mergeDefaults($defaults, $appDefaults);
			}

			// Also check state file for admin-set per-app defaults
			if (!self::$state) self::loadState();
			$stateDefaults = self::$state['_defaults/' . $appHost] ?? null;
			if (is_array($stateDefaults)) {
				$defaults = self::mergeDefaults($defaults, $stateDefaults);
			}
		}

		return $defaults;
	}

	/**
	 * Merge override defaults over base defaults.
	 * @method mergeDefaults
	 * @static
	 * @private
	 */
	private static function mergeDefaults($base, $overrides)
	{
		if (isset($overrides['fileTier'])
			&& isset(self::$tierLevel[$overrides['fileTier']])
		) {
			$base['fileTier'] = $overrides['fileTier'];
		}
		if (isset($overrides['sandbox'])) {
			$base['sandbox'] = array_merge(
				$base['sandbox'], $overrides['sandbox']
			);
		}
		if (isset($overrides['denyPaths'])) {
			$base['denyPaths'] = array_merge(
				$base['denyPaths'], $overrides['denyPaths']
			);
		}
		if (isset($overrides['preset'])) {
			$base['preset'] = $overrides['preset'];
		}
		return $base;
	}

	/**
	 * Check whether a path matches any of the default deny patterns.
	 * Used by apiPush and the stream wrapper to enforce lockdown even
	 * when the file extension tier would allow the file.
	 *
	 * @method isDeniedByDefault
	 * @static
	 * @param {string} $relPath  Relative file path
	 * @param {array} $denyPaths  Glob patterns from getDefaults()
	 * @return {boolean}
	 */
	static function isDeniedByDefault($relPath, $denyPaths = null)
	{
		if ($denyPaths === null) {
			$defaults = self::getDefaults();
			$denyPaths = $defaults['denyPaths'];
		}
		foreach ($denyPaths as $pattern) {
			if (self::globMatch($relPath, $pattern)) {
				return true;
			}
		}
		return false;
	}

	// ─── State file management ──────────────────────────────────────

	/**
	 * Initialize the branch system. Call once at server startup.
	 * @method init
	 * @static
	 */
	static function init()
	{
		self::$stateFile = Q_Config::get(
			'Q', 'webserver', 'branches', 'stateFile',
			'data/branches.json'
		);
		// Resolve relative to the server's working directory
		if (self::$stateFile[0] !== '/') {
			self::$stateFile = getcwd() . '/' . self::$stateFile;
		}
		self::loadState();
	}

	/**
	 * Load branch state from the state file.
	 * @method loadState
	 * @static
	 */
	static function loadState()
	{
		if (!self::$stateFile) {
			self::$state = self::emptyState();
			return;
		}
		if (is_file(self::$stateFile)) {
			$json = file_get_contents(self::$stateFile);
			$data = json_decode($json, true);
			if (is_array($data)) {
				self::$state = $data;
				return;
			}
		}
		self::$state = self::emptyState();
	}

	/**
	 * Save the current state to the state file.
	 * @method saveState
	 * @static
	 * @return {boolean}
	 */
	static function saveState()
	{
		if (!self::$stateFile) return false;
		$dir = dirname(self::$stateFile);
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$json = json_encode(self::$state,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		$tmp = self::$stateFile . '.tmp.' . getmypid();
		if (file_put_contents($tmp, $json . "\n") === false) {
			return false;
		}
		return rename($tmp, self::$stateFile);
	}

	/**
	 * @method emptyState
	 * @static
	 * @private
	 */
	private static function emptyState()
	{
		$uidBase = (int) Q_Config::get(
			'Q', 'webserver', 'sandbox', 'uidBase', 60000
		);
		return array(
			'uidNext' => $uidBase,
			'uidMap' => array(),
			'branches' => array(),
		);
	}

	// ─── Branch CRUD ────────────────────────────────────────────────

	/**
	 * Create a new branch of an app.
	 *
	 * @method create
	 * @static
	 * @param {string} $appHost  The app's virtual host (e.g. "myapp.example.com")
	 * @param {string} $branchName  Branch name (alphanumeric + hyphens)
	 * @param {array} $options  Optional settings:
	 *   - createdBy: username of the creator
	 *   - access: initial access list
	 *   - sandbox: per-branch sandbox overrides
	 * @return {array|string} Branch record on success, error string on failure
	 */
	static function create($appHost, $branchName, $options = array())
	{
		if (!self::$state) self::loadState();

		// Validate branch name
		if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,62}[a-zA-Z0-9]$/', $branchName)
			&& !preg_match('/^[a-zA-Z0-9]$/', $branchName)
		) {
			return 'Invalid branch name: must be 1-64 alphanumeric characters, hyphens, or underscores';
		}

		$branchKey = $appHost . '/' . $branchName;
		if (isset(self::$state['branches'][$branchKey])) {
			return 'Branch already exists: ' . $branchKey;
		}

		// Find the app's host config and document root
		$hostConfig = Q_Config::get('Q', 'webserver', 'hosts', $appHost, null);
		if (!$hostConfig) {
			$hostConfig = Q_Config::get('Q', 'webserver', 'domains', $appHost, null);
		}
		if (!$hostConfig || empty($hostConfig['root'])) {
			return 'App host not found or has no root: ' . $appHost;
		}
		$appRoot = realpath($hostConfig['root']);
		if (!$appRoot || !is_dir($appRoot)) {
			return 'App root directory not found: ' . $hostConfig['root'];
		}

		// Determine branch storage directory
		$branchesDir = Q_Config::get(
			'Q', 'webserver', 'branches', 'dir',
			'data/branches'
		);
		if ($branchesDir[0] !== '/') {
			$branchesDir = getcwd() . '/' . $branchesDir;
		}

		$safeHost = preg_replace('/[^a-zA-Z0-9._-]/', '_', $appHost);
		$branchRoot = $branchesDir . '/' . $safeHost . '/' . $branchName;

		if (is_dir($branchRoot)) {
			return 'Branch directory already exists: ' . $branchRoot;
		}

		// Create the CoW directory tree
		$cowResult = self::createCoW($appRoot, $branchRoot);
		if (is_string($cowResult)) {
			return $cowResult; // error message
		}

		// Assign a UID for OS-level isolation
		$uid = self::assignUid($branchKey);

		// Set file ownership so the branch worker (running as $uid) owns
		// its CoW root but cannot modify trunk files.  Trunk files keep
		// their existing ownership (typically root or the app uid); the
		// branch worker reads them through symlinks.
		if (Q_WebServer_Sandbox::$canSetuid && $uid > 0) {
			self::chownBranchRoot($branchRoot, $uid);

			// Also assign a trunk uid for this app if it doesn't have one.
			// The trunk uid is used for chown on trunk files to prevent
			// branch workers from modifying them.
			$trunkKey = $appHost . '/_trunk';
			$trunkUid = self::assignUid($trunkKey);
			$sandbox = $hostConfig['sandbox'] ?? array();
			// Explicit sandbox.uid overrides the auto-assigned trunk uid
			if (!empty($sandbox['uid'])) {
				$trunkUid = (int) $sandbox['uid'];
				self::$state['uidMap'][$trunkKey] = $trunkUid;
			}
			self::chownTrunkFiles($appRoot, $trunkUid);
		}

		// Clone the database (if configured)
		$dbInfo = self::cloneDatabase($appHost, $hostConfig, $branchName);

		// Build credentials map: shared dev credentials from config,
		// overridden by auto-generated per-branch values (e.g. DB name).
		// These get injected into {{placeholder}} tokens at runtime.
		$credentials = Q_Config::get(
			'Q', 'webserver', 'branches', 'credentials', array()
		);
		if ($dbInfo) {
			$dbCreds = self::dbCredentialKeys($dbInfo, $hostConfig);
			$credentials = array_merge($credentials, $dbCreds);
		}

		// Scrub config files in the CoW directory: replace trunk
		// credential values with {{KEY}} placeholders that the stream
		// wrapper will fill at runtime via injectCredentials().
		if ($credentials) {
			self::scrubCoWConfigs($branchRoot, $appRoot, $credentials, $hostConfig);
		}

		// Apply default lockdown. The defaults set the floor —
		// explicit options can only RELAX restrictions for specific
		// users, never weaken the base sandbox.
		$defaults = self::getDefaults($appHost);

		// Merge sandbox: explicit options override defaults per-key,
		// but the base deny/sandbox is always present
		$sandbox = array_merge(
			$defaults['sandbox'],
			$options['sandbox'] ?? array()
		);

		// Build access: start with defaults, overlay explicit entries
		$access = $options['access'] ?? array();

		// Store the default deny paths in the branch record so they
		// can be enforced at push/write time
		$denyPaths = $defaults['denyPaths'];
		if (!empty($options['denyPaths'])) {
			$denyPaths = array_unique(array_merge(
				$denyPaths, $options['denyPaths']
			));
		}

		// Determine subdomain slug.  Defaults to the branch name
		// (already DNS-safe from validation above).  Can be overridden
		// via options or changed later via setSubdomain().
		$subdomain = $options['subdomain'] ?? strtolower($branchName);
		$subdomain = preg_replace('/[^a-z0-9-]/', '-', $subdomain);
		$subdomain = trim($subdomain, '-');
		if ($subdomain === '') {
			$subdomain = strtolower($branchName);
		}
		// Ensure uniqueness within this app
		$existingOwner = self::findBranchBySubdomain($appHost, $subdomain);
		if ($existingOwner) {
			// Append random suffix to make it unique
			$subdomain .= '-' . substr(bin2hex(random_bytes(4)), 0, 8);
		}

		// Build the branch record
		$record = array(
			'uid' => $uid,
			'root' => $branchRoot,
			'appRoot' => $appRoot,
			'appHost' => $appHost,
			'subdomain' => $subdomain,
			'db' => $dbInfo,
			'credentials' => $credentials,
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'createdBy' => $options['createdBy'] ?? null,
			'access' => $access,
			'sandbox' => $sandbox,
			'denyPaths' => $denyPaths,
			'defaultFileTier' => $defaults['fileTier'],
		);

		self::$state['branches'][$branchKey] = $record;
		self::saveState();
		clearstatcache();

		// Signal the parent process to provision a TLS cert for this subdomain
		self::requestCertProvision($subdomain . '.' . $appHost);

		return $record;
	}

	/**
	 * Delete a branch and clean up its resources.
	 *
	 * @method delete
	 * @static
	 * @param {string} $appHost
	 * @param {string} $branchName
	 * @return {boolean|string} True on success, error string on failure
	 */
	static function delete($appHost, $branchName)
	{
		if (!self::$state) self::loadState();

		$branchKey = $appHost . '/' . $branchName;
		if (!isset(self::$state['branches'][$branchKey])) {
			return 'Branch not found: ' . $branchKey;
		}

		$record = self::$state['branches'][$branchKey];

		// Remove the CoW directory
		if (!empty($record['root']) && is_dir($record['root'])) {
			self::removeDir($record['root']);
		}

		// Drop the cloned database
		if (!empty($record['db']) && !empty($record['db']['name'])) {
			self::dropDatabase($record['db']);
		}

		// Release the UID
		if (isset(self::$state['uidMap'][$branchKey])) {
			unset(self::$state['uidMap'][$branchKey]);
		}

		unset(self::$state['branches'][$branchKey]);
		self::saveState();

		return true;
	}

	/**
	 * List all branches of an app, or all branches if no app specified.
	 *
	 * @method listBranches
	 * @static
	 * @param {string|null} $appHost  Filter by app host, or null for all
	 * @return {array} Map of branchKey => branch record
	 */
	static function listBranches($appHost = null)
	{
		if (!self::$state) self::loadState();

		if ($appHost === null) {
			return self::$state['branches'] ?? array();
		}

		$prefix = $appHost . '/';
		$result = array();
		foreach (self::$state['branches'] as $key => $record) {
			if (strpos($key, $prefix) === 0) {
				$result[$key] = $record;
			}
		}
		return $result;
	}

	/**
	 * Get a single branch record.
	 *
	 * @method get
	 * @static
	 * @param {string} $appHost
	 * @param {string} $branchName
	 * @return {array|null}
	 */
	static function get($appHost, $branchName)
	{
		if (!self::$state) self::loadState();
		$branchKey = $appHost . '/' . $branchName;
		return self::$state['branches'][$branchKey] ?? null;
	}

	/**
	 * Find which branch owns a given subdomain slug for an app.
	 *
	 * @method findBranchBySubdomain
	 * @static
	 * @param {string} $appHost
	 * @param {string} $subdomain
	 * @return {string|null}  Branch key (appHost/branchName) or null
	 */
	static function findBranchBySubdomain($appHost, $subdomain)
	{
		if (!self::$state) self::loadState();
		$prefix = $appHost . '/';
		foreach (self::$state['branches'] as $key => $record) {
			if (strpos($key, $prefix) !== 0) continue;
			$slug = $record['subdomain'] ?? null;
			// Legacy branches without a subdomain field: match on branch name
			if ($slug === null) {
				$slug = strtolower(substr($key, strlen($prefix)));
			}
			if ($slug === $subdomain) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Set a branch's subdomain slug.  Validates uniqueness within the app.
	 *
	 * @method setSubdomain
	 * @static
	 * @param {string} $appHost
	 * @param {string} $branchName
	 * @param {string} $subdomain  The desired slug (lowercase, alphanumeric + hyphens)
	 * @return {true|string}  true on success, error string on failure
	 */
	static function setSubdomain($appHost, $branchName, $subdomain)
	{
		if (!self::$state) self::loadState();

		$branchKey = $appHost . '/' . $branchName;
		if (!isset(self::$state['branches'][$branchKey])) {
			return 'Branch not found: ' . $branchKey;
		}

		// Validate format: lowercase alphanumeric + hyphens, 1-63 chars (DNS label limit)
		$subdomain = strtolower(trim($subdomain));
		if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $subdomain)) {
			return 'Invalid subdomain: must be 1-63 lowercase alphanumeric characters or hyphens, cannot start/end with a hyphen';
		}

		// Check uniqueness
		$owner = self::findBranchBySubdomain($appHost, $subdomain);
		if ($owner && $owner !== $branchKey) {
			return 'Subdomain already in use by branch: ' . $owner;
		}

		self::$state['branches'][$branchKey]['subdomain'] = $subdomain;
		self::saveState();

		// Signal the parent process to provision a TLS cert for the new subdomain
		self::requestCertProvision($subdomain . '.' . $appHost);

		return true;
	}

	/**
	 * Update a branch record (access list, sandbox overrides, etc.).
	 *
	 * @method update
	 * @static
	 * @param {string} $appHost
	 * @param {string} $branchName
	 * @param {array} $updates  Fields to merge into the branch record
	 * @return {boolean|string}
	 */
	static function update($appHost, $branchName, $updates)
	{
		if (!self::$state) self::loadState();
		$branchKey = $appHost . '/' . $branchName;
		if (!isset(self::$state['branches'][$branchKey])) {
			return 'Branch not found: ' . $branchKey;
		}
		foreach ($updates as $k => $v) {
			// Don't allow overwriting structural fields
			if (in_array($k, array('uid', 'root', 'appRoot', 'appHost', 'created'), true)) {
				continue;
			}
			self::$state['branches'][$branchKey][$k] = $v;
		}
		self::saveState();
		return true;
	}

	/**
	 * Merge a branch's changes into trunk by copying modified files
	 * from the branch CoW directory back to the app root, and removing
	 * files from trunk that were deleted in the branch.
	 *
	 * @method merge
	 * @static
	 * @param {string} $appHost
	 * @param {string} $branchName
	 * @return {array|string} Merge result with counts, or error string
	 */
	static function merge($appHost, $branchName)
	{
		if (!self::$state) self::loadState();

		$branchKey = $appHost . '/' . $branchName;
		if (!isset(self::$state['branches'][$branchKey])) {
			return 'Branch not found: ' . $branchKey;
		}

		$record = self::$state['branches'][$branchKey];
		$branchRoot = $record['root'];
		$trunkRoot = $record['appRoot'];

		if (!is_dir($branchRoot) || !is_dir($trunkRoot)) {
			return 'Branch or trunk directory missing';
		}

		$changes = self::diff($branchRoot, $trunkRoot);
		$copied = 0;
		$removed = 0;
		$errors = array();

		// Copy added and changed files from branch to trunk
		foreach (array_merge($changes['added'], $changes['changed']) as $rel) {
			$src = $branchRoot . '/' . $rel;
			$dst = $trunkRoot . '/' . $rel;
			$dstDir = dirname($dst);
			if (!is_dir($dstDir)) {
				@mkdir($dstDir, 0755, true);
			}
			if (copy($src, $dst)) {
				$copied++;
			} else {
				$errors[] = 'Failed to copy: ' . $rel;
			}
		}

		// Remove files from trunk that were deleted in branch
		foreach ($changes['removed'] as $rel) {
			$dst = $trunkRoot . '/' . $rel;
			if (file_exists($dst)) {
				if (unlink($dst)) {
					$removed++;
				} else {
					$errors[] = 'Failed to remove: ' . $rel;
				}
			}
		}

		// Clear merge requests for this branch
		if (!empty(self::$state['branches'][$branchKey]['mergeRequests'])) {
			self::$state['branches'][$branchKey]['mergeRequests'] = array();
			self::saveState();
		}

		return array(
			'added' => count($changes['added']),
			'changed' => count($changes['changed']),
			'removed' => count($changes['removed']),
			'copied' => $copied,
			'deleted' => $removed,
			'errors' => $errors,
		);
	}

	/**
	 * Switch production to a branch by swapping symlinks.
	 *
	 * Creates a backup of the current trunk, then copies the branch
	 * files into trunk. This is a code-level switch; databases are
	 * separate.
	 *
	 * @method switchProduction
	 * @static
	 * @param {string} $appHost
	 * @param {string} $branchName
	 * @return {array|string} Result or error string
	 */
	static function switchProduction($appHost, $branchName)
	{
		if (!self::$state) self::loadState();

		$branchKey = $appHost . '/' . $branchName;
		if (!isset(self::$state['branches'][$branchKey])) {
			return 'Branch not found: ' . $branchKey;
		}

		$record = self::$state['branches'][$branchKey];
		$branchRoot = $record['root'];
		$trunkRoot = $record['appRoot'];

		if (!is_dir($branchRoot) || !is_dir($trunkRoot)) {
			return 'Branch or trunk directory missing';
		}

		// First merge the branch into trunk
		$mergeResult = self::merge($appHost, $branchName);
		if (is_string($mergeResult)) {
			return $mergeResult;
		}

		return array(
			'merged' => $mergeResult,
			'production' => $branchName,
			'switchedAt' => gmdate('Y-m-d\TH:i:s\Z'),
		);
	}

	/**
	 * Get the database clone configuration for an app.
	 *
	 * @method getDbConfig
	 * @static
	 * @param {string} $appHost
	 * @return {array} Configuration with cloneDb, defaultCloneDb
	 */
	static function getDbConfig($appHost)
	{
		if (!self::$state) self::loadState();

		$stateConfig = self::$state['dbConfig'][$appHost] ?? null;
		$defaultDb = Q_Config::get(
			'Q', 'webserver', 'branches', 'cloneDb', 'default', null
		);

		return array(
			'appHost' => $appHost,
			'cloneDb' => $stateConfig ? ($stateConfig['cloneDb'] ?? null) : null,
			'defaultCloneDb' => $defaultDb,
		);
	}

	/**
	 * Set the database clone configuration for an app.
	 *
	 * @method setDbConfig
	 * @static
	 * @param {string} $appHost
	 * @param {string} $cloneDb  Database name to clone for new branches
	 * @param {string|null} $updatedBy  Username of who made the change
	 * @return {boolean}
	 */
	static function setDbConfig($appHost, $cloneDb, $updatedBy = null)
	{
		if (!self::$state) self::loadState();

		if (!isset(self::$state['dbConfig'])) {
			self::$state['dbConfig'] = array();
		}
		self::$state['dbConfig'][$appHost] = array(
			'cloneDb' => $cloneDb,
			'updatedBy' => $updatedBy,
			'updatedAt' => time(),
		);
		self::saveState();
		return true;
	}

	// ─── Branch resolution (routing) ────────────────────────────────

	/**
	 * Resolve a request to a branch.
	 *
	 * Checks subdomain first, then X-Q-Branch header, then _q_branch cookie.
	 * If a branch is found, sets self::$current and self::$currentRecord.
	 *
	 * @method resolve
	 * @static
	 * @param {string} $host  The Host header value (lowercase, port stripped)
	 * @param {array} $headers  All request headers
	 * @param {array} $cookies  Parsed cookies
	 * @return {array|null} Branch record if resolved, null for trunk
	 */
	static function resolve($host, $headers = array(), $cookies = array())
	{
		if (!self::$state) self::loadState();

		self::$current = null;
		self::$currentRecord = null;

		// 1. Check if this host IS a known app host — serve trunk
		$directConfig = Q_Config::get('Q', 'webserver', 'hosts', $host, null);
		if (!$directConfig) {
			$directConfig = Q_Config::get('Q', 'webserver', 'domains', $host, null);
		}

		// 2. Check subdomain routing: subdomain.app-host
		//    Matches on the branch's `subdomain` field (which defaults
		//    to the branch name).  This allows vanity slugs like
		//    staging.myapp.com to route to a branch named "staging-v2".
		if (!$directConfig) {
			$dot = strpos($host, '.');
			if ($dot !== false) {
				$subdomain = strtolower(substr($host, 0, $dot));
				$parentHost = substr($host, $dot + 1);

				// Check if parentHost is a known app
				$parentConfig = Q_Config::get('Q', 'webserver', 'hosts', $parentHost, null);
				if (!$parentConfig) {
					$parentConfig = Q_Config::get('Q', 'webserver', 'domains', $parentHost, null);
				}

				if ($parentConfig) {
					// Search by subdomain field (covers vanity slugs)
					$branchKey = self::findBranchBySubdomain($parentHost, $subdomain);
					if ($branchKey && isset(self::$state['branches'][$branchKey])) {
						$name = substr($branchKey, strlen($parentHost) + 1);
						self::$current = $name;
						self::$currentRecord = self::$state['branches'][$branchKey];
						return self::$currentRecord;
					}
				}
			}
		}

		// 3. Check X-Q-Branch header
		$branchHeader = $headers['x-q-branch'] ?? null;
		if ($branchHeader) {
			$appHost = $directConfig ? $host : null;
			if ($appHost) {
				$branchKey = $appHost . '/' . $branchHeader;
				if (isset(self::$state['branches'][$branchKey])) {
					self::$current = $branchHeader;
					self::$currentRecord = self::$state['branches'][$branchKey];
					return self::$currentRecord;
				}
			}
		}

		// 4. Check _q_branch cookie
		$branchCookie = $cookies['_q_branch'] ?? null;
		if ($branchCookie) {
			$appHost = $directConfig ? $host : null;
			if ($appHost) {
				$branchKey = $appHost . '/' . $branchCookie;
				if (isset(self::$state['branches'][$branchKey])) {
					self::$current = $branchCookie;
					self::$currentRecord = self::$state['branches'][$branchKey];
					return self::$currentRecord;
				}
			}
		}

		return null;
	}

	/**
	 * Reset branch state between requests (for persistent workers).
	 * @method reset
	 * @static
	 */
	static function reset()
	{
		self::$current = null;
		self::$currentRecord = null;
		self::$currentUser = null;
	}

	// ─── Access control ─────────────────────────────────────────────

	/**
	 * Check a user's branch permission (view/edit/admin).
	 *
	 * @method getBranchPermission
	 * @static
	 * @param {array} $branchRecord  The branch record
	 * @param {string} $user  Username
	 * @return {string|null} "view", "edit", "admin", or null (no access)
	 */
	static function getBranchPermission($branchRecord, $user)
	{
		// Panel sessions always have admin access to all branches
		if ($user === 'panel') return 'admin';

		$access = $branchRecord['access'] ?? array();

		// Check user-specific entry first
		$entry = $access[$user] ?? ($access['*'] ?? null);

		if ($entry === null) return null;

		if (is_string($entry)) {
			// Short form: "view", "admin", "edit", "edit:markup", etc.
			if ($entry === 'view' || $entry === 'admin') return $entry;
			if ($entry === 'edit') return 'edit';
			if (strpos($entry, 'edit:') === 0) return 'edit';
			return null;
		}

		if (is_array($entry)) {
			return $entry['branch'] ?? null;
		}

		return null;
	}

	/**
	 * Check a user's file permission tier.
	 *
	 * @method getFileTier
	 * @static
	 * @param {array} $branchRecord  The branch record
	 * @param {string} $user  Username
	 * @return {string} "styles", "markup", "frontend", "code"
	 */
	static function getFileTier($branchRecord, $user)
	{
		// Panel sessions always have full code access
		if ($user === 'panel') return 'code';

		$access = $branchRecord['access'] ?? array();
		$entry = $access[$user] ?? ($access['*'] ?? null);

		// Default tier from branch defaults (safe floor)
		$defaultTier = $branchRecord['defaultFileTier'] ?? 'styles';

		if ($entry === null) return $defaultTier;

		if (is_string($entry)) {
			if ($entry === 'admin') return 'code';
			if ($entry === 'view') return 'styles';
			if (strpos($entry, 'edit:') === 0) {
				$tier = substr($entry, 5);
				return isset(self::$tierLevel[$tier]) ? $tier : $defaultTier;
			}
			return $defaultTier; // "edit" without tier defaults to branch default
		}

		if (is_array($entry)) {
			$tier = $entry['files'] ?? $defaultTier;
			if (($entry['branch'] ?? null) === 'admin') return 'code';
			return isset(self::$tierLevel[$tier]) ? $tier : $defaultTier;
		}

		return $defaultTier;
	}

	/**
	 * Get per-user path overrides from the access entry.
	 *
	 * @method getUserPathOverrides
	 * @static
	 * @param {array} $branchRecord
	 * @param {string} $user
	 * @return {array} Array with 'allow' and 'deny' glob arrays
	 */
	static function getUserPathOverrides($branchRecord, $user)
	{
		$access = $branchRecord['access'] ?? array();
		$entry = $access[$user] ?? ($access['*'] ?? null);

		if (!is_array($entry)) return array('allow' => array(), 'deny' => array());

		$paths = $entry['paths'] ?? array();
		return array(
			'allow' => $paths['allow'] ?? array(),
			'deny' => $paths['deny'] ?? array(),
		);
	}

	/**
	 * Get per-user config key overrides from the access entry.
	 *
	 * @method getUserConfigOverrides
	 * @static
	 * @param {array} $branchRecord
	 * @param {string} $user
	 * @return {array} Array with 'allow' and 'deny' config path arrays
	 */
	static function getUserConfigOverrides($branchRecord, $user)
	{
		$access = $branchRecord['access'] ?? array();
		$entry = $access[$user] ?? ($access['*'] ?? null);

		if (!is_array($entry)) return array('allow' => array(), 'deny' => array());

		$config = $entry['config'] ?? array();
		return array(
			'allow' => $config['allow'] ?? array(),
			'deny' => $config['deny'] ?? array(),
		);
	}

	/**
	 * Check whether a file write is permitted for the current user.
	 *
	 * Evaluates: extension tier → preset tierPaths → user overrides → default.
	 *
	 * @method checkFilePermission
	 * @static
	 * @param {string} $filePath  Path relative to branch root
	 * @param {string} $userTier  The user's file permission tier
	 * @param {array} $preset  The framework preset (with tierPaths, templatePaths)
	 * @param {array} $userPaths  Per-user path overrides (allow/deny arrays)
	 * @return {boolean|string} True if allowed, error message string if denied
	 */
	static function checkFilePermission($filePath, $userTier, $preset = array(), $userPaths = array())
	{
		// Admin (code tier) bypasses all file checks
		if ($userTier === 'code') {
			// Even code tier respects explicit denials from preset
			$presetDeny = $preset['tierPaths']['code']['deny'] ?? array();
			foreach ($presetDeny as $pattern) {
				if (self::globMatch($filePath, $pattern)) {
					return "Path denied by preset: $pattern";
				}
			}
			return true;
		}

		$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

		// Check if the extension requires a higher tier
		$requiredTier = self::extensionTier($ext, $filePath, $preset);
		if ($requiredTier !== null) {
			$required = self::$tierLevel[$requiredTier] ?? 0;
			$has = self::$tierLevel[$userTier] ?? 0;
			if ($required > $has) {
				return "File type .$ext requires '$requiredTier' permission (you have '$userTier')";
			}
		}

		// Check preset tierPaths deny
		$presetDeny = $preset['tierPaths'][$userTier]['deny'] ?? array();
		$presetAllow = $preset['tierPaths'][$userTier]['allow'] ?? array();
		$userDeny = $userPaths['deny'] ?? array();
		$userAllow = $userPaths['allow'] ?? array();

		// Evaluation order:
		// 1. Preset deny
		$presetDenied = false;
		foreach ($presetDeny as $pattern) {
			if (self::globMatch($filePath, $pattern)) {
				$presetDenied = true;
				break;
			}
		}

		// 2. User deny (always wins)
		foreach ($userDeny as $pattern) {
			if (self::globMatch($filePath, $pattern)) {
				return "Path denied by user override: $pattern";
			}
		}

		// 3. User allow (can override preset deny)
		if ($presetDenied) {
			$userAllowed = false;
			foreach ($userAllow as $pattern) {
				if (self::globMatch($filePath, $pattern)) {
					$userAllowed = true;
					break;
				}
			}
			if (!$userAllowed) {
				// 4. Check preset allow
				foreach ($presetAllow as $pattern) {
					if (self::globMatch($filePath, $pattern)) {
						$presetDenied = false;
						break;
					}
				}
			} else {
				$presetDenied = false;
			}
		}

		if ($presetDenied) {
			return "Path denied by preset for tier '$userTier'";
		}

		return true;
	}

	/**
	 * Check whether config key changes are permitted.
	 *
	 * @method checkConfigPermission
	 * @static
	 * @param {array} $changedKeys  List of dot-notation config paths that changed
	 * @param {string} $userTier
	 * @param {array} $tierConfig  Preset's tierConfig for this tier
	 * @param {array} $userConfig  Per-user config overrides (allow/deny)
	 * @return {boolean|string} True if all changes allowed, error message if denied
	 */
	static function checkConfigPermission($changedKeys, $userTier, $tierConfig = array(), $userConfig = array())
	{
		if ($userTier === 'code') return true;

		$tierAllow = $tierConfig[$userTier]['allow'] ?? array();
		$tierDeny = $tierConfig[$userTier]['deny'] ?? array();
		$userAllow = $userConfig['allow'] ?? array();
		$userDeny = $userConfig['deny'] ?? array();

		$denied = array();
		foreach ($changedKeys as $key) {
			// User deny always wins
			if (self::configPathMatches($key, $userDeny)) {
				$denied[] = $key;
				continue;
			}
			// User allow overrides tier deny
			if (self::configPathMatches($key, $userAllow)) {
				continue;
			}
			// Tier deny
			if (self::configPathMatches($key, $tierDeny)) {
				$denied[] = $key;
				continue;
			}
			// Tier allow
			if (!empty($tierAllow) && !self::configPathMatches($key, $tierAllow)) {
				// If tierAllow is specified, anything NOT in it is denied
				$denied[] = $key;
				continue;
			}
		}

		if (!empty($denied)) {
			return "Config keys denied for tier '$userTier': " . implode(', ', $denied);
		}
		return true;
	}

	/**
	 * Diff two parsed config trees and return the list of changed dot-notation paths.
	 *
	 * @method diffConfigKeys
	 * @static
	 * @param {array} $original
	 * @param {array} $modified
	 * @param {string} $prefix  Internal recursion prefix
	 * @return {array} List of dot-notation paths that changed
	 */
	static function diffConfigKeys($original, $modified, $prefix = '')
	{
		$changed = array();
		$allKeys = array_unique(array_merge(
			array_keys($original),
			array_keys($modified)
		));
		foreach ($allKeys as $key) {
			$path = $prefix === '' ? $key : $prefix . '.' . $key;
			$oldVal = $original[$key] ?? null;
			$newVal = $modified[$key] ?? null;

			if ($oldVal === $newVal) continue;

			if (is_array($oldVal) && is_array($newVal)) {
				// Recurse into sub-trees
				$changed = array_merge($changed, self::diffConfigKeys($oldVal, $newVal, $path));
			} else {
				$changed[] = $path;
			}
		}
		return $changed;
	}

	// ─── Credential detection ───────────────────────────────────────

	/**
	 * Check whether a config key name and/or value looks like a credential.
	 *
	 * Returns one of: "key-listed", "key-name", "value-prefix", "entropy", or false.
	 *
	 * @method looksLikeCredential
	 * @static
	 * @param {string} $keyName  The config key name (last segment or full path)
	 * @param {string} $value  The string value
	 * @param {array} $sensitiveKeys  Preset's list of known-sensitive key paths
	 * @return {string|false} Detection layer name, or false
	 */
	static function looksLikeCredential($keyName, $value, $sensitiveKeys = array())
	{
		if (!is_string($value) || $value === '') return false;

		// Layer 1: config-key matching (preset sensitive paths)
		foreach ($sensitiveKeys as $pattern) {
			if (self::configPathMatches($keyName, array($pattern))) {
				return 'key-listed';
			}
		}

		// Layer 2: key-name heuristic
		$lowerKey = strtolower($keyName);
		// Get just the last segment for nested keys
		$parts = explode('.', $lowerKey);
		$lastSegment = end($parts);
		// Also check the full flattened key
		$flatKey = str_replace(array('.', '_', '-'), '', $lowerKey);
		$flatLast = str_replace(array('.', '_', '-'), '', $lastSegment);

		foreach (self::$secretKeywords as $keyword) {
			$flatKeyword = str_replace('_', '', $keyword);
			if (strpos($flatKey, $flatKeyword) !== false
				|| strpos($flatLast, $flatKeyword) !== false
			) {
				return 'key-name';
			}
		}

		// Layer 3: value prefix matching
		foreach (self::$secretPrefixes as $prefix) {
			if (strpos($value, $prefix) === 0) {
				return 'value-prefix';
			}
		}

		// Layer 4: entropy heuristic (flagging only)
		if (strlen($value) >= 20 && strpos($value, ' ') === false) {
			$entropy = self::shannonEntropy($value);
			if ($entropy > 4.0) {
				// Additional check: looks like base64/hex/url-safe
				if (preg_match('/^[A-Za-z0-9+\/=_\-.:]+$/', $value)) {
					return 'entropy';
				}
			}
		}

		return false;
	}

	/**
	 * Calculate Shannon entropy of a string in bits per character.
	 *
	 * @method shannonEntropy
	 * @static
	 * @param {string} $str
	 * @return {float}
	 */
	static function shannonEntropy($str)
	{
		$len = strlen($str);
		if ($len === 0) return 0.0;

		$freq = array();
		for ($i = 0; $i < $len; $i++) {
			$c = $str[$i];
			$freq[$c] = ($freq[$c] ?? 0) + 1;
		}

		$entropy = 0.0;
		foreach ($freq as $count) {
			$p = $count / $len;
			$entropy -= $p * log($p, 2);
		}
		return $entropy;
	}

	/**
	 * Scrub credentials from a parsed config array.
	 * Returns the scrubbed array and a manifest of what was replaced.
	 *
	 * @method scrubCredentials
	 * @static
	 * @param {array} $config  Parsed config tree
	 * @param {array} $sensitiveKeys  Preset's sensitive key paths
	 * @param {string} $prefix  Internal recursion prefix
	 * @return {array} ['config' => scrubbed array, 'placeholders' => [...], 'flagged' => [...]]
	 */
	static function scrubCredentials($config, $sensitiveKeys = array(), $prefix = '')
	{
		$placeholders = array();
		$flagged = array();
		$result = array();

		foreach ($config as $key => $value) {
			$path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

			if (is_array($value)) {
				$sub = self::scrubCredentials($value, $sensitiveKeys, $path);
				$result[$key] = $sub['config'];
				$placeholders = array_merge($placeholders, $sub['placeholders']);
				$flagged = array_merge($flagged, $sub['flagged']);
			} elseif (is_string($value)) {
				$detection = self::looksLikeCredential($path, $value, $sensitiveKeys);
				if ($detection && $detection !== 'entropy') {
					$placeholder = '{{' . $path . '}}';
					$result[$key] = $placeholder;
					$placeholders[] = array(
						'key' => $path,
						'placeholder' => $placeholder,
						'detection' => $detection,
					);
				} elseif ($detection === 'entropy') {
					$result[$key] = $value; // keep original
					$flagged[] = array(
						'key' => $path,
						'reason' => 'High entropy value — possible credential',
					);
				} else {
					$result[$key] = $value;
				}
			} else {
				$result[$key] = $value;
			}
		}

		return array(
			'config' => $result,
			'placeholders' => $placeholders,
			'flagged' => $flagged,
		);
	}

	// ─── CoW config scrubbing ──────────────────────────────────────

	/**
	 * Scrub config files in a CoW branch directory so that trunk
	 * credential values are replaced with {{KEY}} placeholders.
	 *
	 * Called after createCoW() and after the credentials map is built.
	 * For each config file (identified by preset patterns or common
	 * extensions), breaks the symlink and writes a copy with DB
	 * credential values replaced by placeholders that match the keys
	 * in the branch credentials map.
	 *
	 * Uses value-based matching: parses each config file, walks the
	 * tree to find keys whose names indicate DB credentials (password,
	 * username, host, name/database), then replaces those values with
	 * the corresponding {{CREDENTIAL_KEY}} placeholder.
	 *
	 * @method scrubCoWConfigs
	 * @static
	 * @param {string} $branchRoot  Absolute path to the branch CoW root
	 * @param {string} $appRoot  Absolute path to the trunk app root
	 * @param {array} $credentials  The branch credential map (KEY => value)
	 * @param {array} $hostConfig  The host config array
	 * @return {array} List of files that were scrubbed
	 */
	static function scrubCoWConfigs($branchRoot, $appRoot, $credentials, $hostConfig)
	{
		if (empty($credentials)) return array();

		$preset = $hostConfig['preset'] ?? null;
		$presetInfo = $preset ? self::getFrameworkPreset($preset) : null;

		$configFormat = null;
		$configPatterns = array();

		if ($presetInfo) {
			$configFormat = $presetInfo['configFormat'] ?? null;
			$configPatterns = $presetInfo['configFiles'] ?? array();
		}

		// Fallback: scan for common config file extensions
		if (empty($configPatterns)) {
			$configPatterns = array(
				'*.json', '*.yaml', '*.yml',
				'config/*.json', 'config/*.yaml', 'config/*.yml',
				'config/**/*.json', 'config/**/*.yaml', 'config/**/*.yml',
			);
		}

		// Build the reverse credential-key map: concept => credential key
		// We map config key-name patterns to credential map keys
		$credKeyMap = self::buildCredentialKeyMap($credentials);

		// Scan branch directory for config files
		$scrubbed = array();
		$branchRoot = rtrim($branchRoot, '/');
		$files = self::scanDirRecursive($branchRoot);

		foreach ($files as $absPath) {
			$relPath = substr($absPath, strlen($branchRoot) + 1);

			// Check if this file matches any config pattern
			$isConfig = false;
			foreach ($configPatterns as $pattern) {
				if (self::globMatch($relPath, $pattern)) {
					$isConfig = true;
					break;
				}
			}
			if (!$isConfig) continue;

			// Must be a symlink (CoW) — real files were already modified.
			// Use readlink() instead of is_link() because PHP's is_link()
			// goes through the stream wrapper which may return wrong
			// results during branch creation (same-request stat cache).
			// Fall back to shell test -L if readlink also fails.
			$linkTarget = @readlink($absPath);
			if ($linkTarget === false) {
				$shellResult = @exec("test -L " . escapeshellarg($absPath) . " && echo LINK || echo FILE");
				if ($shellResult !== 'LINK') continue;
			}

			// Determine format from extension if not set by preset
			$ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
			$format = $configFormat;
			if (!$format) {
				if ($ext === 'json') $format = 'json';
				elseif ($ext === 'yaml' || $ext === 'yml') $format = 'yaml';
				else continue; // skip non-parseable formats
			}

			// Read the content (follows symlink to trunk)
			$content = file_get_contents($absPath);
			if ($content === false || $content === '') continue;

			$parsed = self::parseConfigContent($content, $format);
			if ($parsed === null) continue;

			// Walk the parsed config and replace credential values
			$result = self::replaceCredentialValues($parsed, $credKeyMap);
			if (empty($result['replaced'])) continue;

			// Re-encode
			if ($format === 'json') {
				$output = json_encode(
					$result['config'],
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				);
			} elseif ($format === 'yaml') {
				if (function_exists('yaml_emit')) {
					$output = yaml_emit($result['config']);
				} else {
					// Fallback: string replacement on original content
					$output = $content;
					foreach ($result['replaced'] as $r) {
						if ($r['original'] !== '') {
							$output = str_replace($r['original'], $r['placeholder'], $output);
						}
					}
				}
			} else {
				continue;
			}

			// Break symlink and write scrubbed content
			$target = readlink($absPath);
			unlink($absPath);
			file_put_contents($absPath, $output);

			$scrubbed[] = array(
				'file' => $relPath,
				'replaced' => count($result['replaced']),
				'placeholders' => $result['replaced'],
			);
		}

		return $scrubbed;
	}

	/**
	 * Build a map from DB credential concepts to credential-map keys.
	 *
	 * Inspects the credential map to determine which keys are available
	 * for each concept (dbname, dbhost, dbuser, dbpass, dbport).
	 * Returns a map: concept => credentialKey.
	 *
	 * @method buildCredentialKeyMap
	 * @static
	 * @private
	 * @param {array} $credentials  The branch credential map
	 * @return {array}  concept => credentialKey
	 */
	private static function buildCredentialKeyMap($credentials)
	{
		$map = array();

		// Prefer the most common key names
		// DB name
		if (isset($credentials['DB_NAME'])) {
			$map['dbname'] = 'DB_NAME';
		} elseif (isset($credentials['DB_DATABASE'])) {
			$map['dbname'] = 'DB_DATABASE';
		}
		// DB host
		if (isset($credentials['DB_HOST'])) {
			$map['dbhost'] = 'DB_HOST';
		}
		// DB username
		if (isset($credentials['DB_USERNAME'])) {
			$map['dbuser'] = 'DB_USERNAME';
		} elseif (isset($credentials['DB_USER'])) {
			$map['dbuser'] = 'DB_USER';
		}
		// DB password
		if (isset($credentials['DB_PASSWORD'])) {
			$map['dbpass'] = 'DB_PASSWORD';
		}
		// DB port
		if (isset($credentials['DB_PORT'])) {
			$map['dbport'] = 'DB_PORT';
		}

		return $map;
	}

	/**
	 * Walk a parsed config tree, replacing DB credential values with
	 * {{KEY}} placeholders based on key-name heuristics.
	 *
	 * @method replaceCredentialValues
	 * @static
	 * @private
	 * @param {array} $config  Parsed config tree
	 * @param {array} $credKeyMap  concept => credentialKey map
	 * @param {string} $prefix  Internal recursion prefix
	 * @return {array} ['config' => modified tree, 'replaced' => [...]]
	 */
	private static function replaceCredentialValues($config, $credKeyMap, $prefix = '')
	{
		$replaced = array();
		$result = array();

		foreach ($config as $key => $value) {
			$path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

			if (is_array($value)) {
				$sub = self::replaceCredentialValues($value, $credKeyMap, $path);
				$result[$key] = $sub['config'];
				$replaced = array_merge($replaced, $sub['replaced']);
			} elseif (is_string($value)) {
				$concept = self::detectCredentialConcept($path, $key);
				if ($concept && isset($credKeyMap[$concept])) {
					$credKey = $credKeyMap[$concept];
					$placeholder = '{{' . $credKey . '}}';
					$result[$key] = $placeholder;
					$replaced[] = array(
						'path' => $path,
						'concept' => $concept,
						'credentialKey' => $credKey,
						'placeholder' => $placeholder,
						'original' => $value,
					);
				} else {
					$result[$key] = $value;
				}
			} else {
				$result[$key] = $value;
			}
		}

		return array('config' => $result, 'replaced' => $replaced);
	}

	/**
	 * Detect what DB credential concept a config key path represents.
	 *
	 * Returns a concept string ('dbname', 'dbhost', 'dbuser', 'dbpass',
	 * 'dbport') or null if the key doesn't look like a DB credential.
	 *
	 * @method detectCredentialConcept
	 * @static
	 * @private
	 * @param {string} $path  Full dotted key path (e.g. "database.password")
	 * @param {string} $key  The immediate key name
	 * @return {string|null}
	 */
	private static function detectCredentialConcept($path, $key)
	{
		$lowerKey = strtolower((string) $key);
		$lowerPath = strtolower($path);

		// Password variants
		if (in_array($lowerKey, array('password', 'passwd', 'pass', 'db_password'))) {
			return 'dbpass';
		}

		// Username variants
		if (in_array($lowerKey, array('username', 'user', 'db_username', 'db_user'))) {
			return 'dbuser';
		}

		// Host variants
		if (in_array($lowerKey, array('host', 'hostname', 'db_host', 'server'))) {
			// Only if under a db-like parent
			if (self::pathLooksDbRelated($lowerPath)) {
				return 'dbhost';
			}
		}

		// Port — only under a db-like parent
		if ($lowerKey === 'port' || $lowerKey === 'db_port') {
			if (self::pathLooksDbRelated($lowerPath)) {
				return 'dbport';
			}
		}

		// Database name variants
		if (in_array($lowerKey, array('database', 'dbname', 'db_name', 'db_database', 'db'))) {
			return 'dbname';
		}
		// "name" is ambiguous — only treat as dbname if under a db-like parent
		if ($lowerKey === 'name' && self::pathLooksDbRelated($lowerPath)) {
			return 'dbname';
		}

		return null;
	}

	/**
	 * Check whether a dotted config path looks like it's under a
	 * database-related parent key.
	 *
	 * @method pathLooksDbRelated
	 * @static
	 * @private
	 * @param {string} $lowerPath  Lowercased dotted path
	 * @return {boolean}
	 */
	private static function pathLooksDbRelated($lowerPath)
	{
		$dbParents = array(
			'database', 'db', 'databases', 'datasource', 'datasources',
			'connection', 'connections', 'dbal', 'pdo', 'mysql', 'pgsql',
			'postgres', 'postgresql', 'sqlite', 'mariadb',
		);
		$parts = explode('.', $lowerPath);
		// Check all parts except the last (which is the key itself)
		array_pop($parts);
		foreach ($parts as $part) {
			if (in_array($part, $dbParents)) return true;
			// Also check if any part contains "db" or "database"
			if (strpos($part, 'database') !== false) return true;
			if (strpos($part, 'datasource') !== false) return true;
		}
		return false;
	}

	/**
	 * Recursively scan a directory for files, following symlinks.
	 *
	 * @method scanDirRecursive
	 * @static
	 * @private
	 * @param {string} $dir
	 * @return {array}  List of absolute file paths
	 */
	private static function scanDirRecursive($dir)
	{
		$files = array();
		$entries = @scandir($dir);
		if ($entries === false) return $files;

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') continue;
			$path = $dir . '/' . $entry;

			if (is_link($path) && is_dir(readlink($path))) {
				// Symlinked directory (e.g. node_modules) — skip
				continue;
			}

			if (is_dir($path)) {
				$files = array_merge($files, self::scanDirRecursive($path));
			} else {
				$files[] = $path;
			}
		}
		return $files;
	}

	// ─── Copy-on-Write directory ────────────────────────────────────

	/**
	 * Directories to symlink wholesale at the top level rather than recursing.
	 * These are large, don't need per-file branching, and contain nothing
	 * a branch collaborator would edit.
	 * @property $skipDirs
	 * @type array
	 * @static
	 */
	static $skipDirs = array('.git', 'node_modules', 'vendor');

	/**
	 * Create a CoW directory tree: symlinks to all trunk files,
	 * real directories for the structure.
	 *
	 * This is app-level branching — the trunk stays in place and the branch
	 * gets symlinks pointing directly at trunk files. Editing a file in the
	 * branch breaks its symlink and creates a real copy (done by the stream
	 * wrapper in Compat.php). Trunk is never modified.
	 *
	 * Compare with Q_Branch::fork(), which moves real files into a ~store
	 * and replaces both source and fork with symlinks. That pattern is
	 * correct for content-level branching but would break a live app root.
	 *
	 * @method createCoW
	 * @static
	 * @param {string} $source  Trunk directory (absolute path)
	 * @param {string} $dest  Branch directory to create (absolute path)
	 * @return {boolean|string} True on success, error string on failure
	 */
	static function createCoW($source, $dest)
	{
		$source = rtrim($source, '/');
		$dest = rtrim($dest, '/');

		if (!is_dir($source)) {
			return "Source directory not found: $source";
		}

		if (!@mkdir($dest, 0755, true)) {
			return "Cannot create branch directory: $dest";
		}

		return self::cowRecursive($source, $dest, $source);
	}

	/**
	 * Recursively create CoW symlinks.
	 * @method cowRecursive
	 * @static
	 * @private
	 */
	private static function cowRecursive($sourceDir, $destDir, $sourceRoot)
	{
		$entries = @scandir($sourceDir);
		if ($entries === false) {
			return "Cannot read directory: $sourceDir";
		}

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') continue;

			$sourcePath = $sourceDir . '/' . $entry;
			$destPath = $destDir . '/' . $entry;

			if (is_dir($sourcePath) && !is_link($sourcePath)) {
				if (in_array($entry, self::$skipDirs, true)
					&& $sourceDir === $sourceRoot
				) {
					self::makeSymlink($sourcePath, $destPath);
					continue;
				}
				@mkdir($destPath, 0755, true);
				$result = self::cowRecursive($sourcePath, $destPath, $sourceRoot);
				if (is_string($result)) return $result;
			} else {
				self::makeSymlink($sourcePath, $destPath);
			}
		}

		return true;
	}

	// ─── Database cloning ───────────────────────────────────────────

	/**
	 * Clone the app's database for a branch.
	 *
	 * @method cloneDatabase
	 * @static
	 * @param {string} $appHost
	 * @param {array} $hostConfig
	 * @param {string} $branchName
	 * @return {array|null} Database info array, or null if no cloning configured
	 */
	static function cloneDatabase($appHost, $hostConfig, $branchName)
	{
		$branchDbConfig = Q_Config::get('Q', 'webserver', 'branches', 'db', null);
		if (!$branchDbConfig) {
			return null;
		}

		$adapter = $branchDbConfig['adapter'] ?? 'sqlite';
		$preset = Q_Config::get('Q', 'webserver', 'hosts', $appHost, 'preset', null);

		// Try to detect the source database name from the app's config
		$sourceDb = self::detectSourceDatabase($hostConfig, $preset);
		if (!$sourceDb) return null;

		$safeHost = preg_replace('/[^a-zA-Z0-9]/', '_', $appHost);
		$safeBranch = preg_replace('/[^a-zA-Z0-9]/', '_', $branchName);
		$targetDb = $safeHost . '_' . $safeBranch;

		// For SQLite, the target is a file path, not a database name
		if ($adapter === 'sqlite') {
			$targetDir = dirname($sourceDb);
			$targetDb = $targetDir . '/branch_' . $branchName . '.sqlite';
		}

		return Db_Branch::fork($sourceDb, $targetDb, $branchDbConfig, $adapter);
	}

	/**
	 * Detect the source database name from the app's config files.
	 * @method detectSourceDatabase
	 * @static
	 * @private
	 */
	private static function detectSourceDatabase($hostConfig, $preset)
	{
		// Check if the host config specifies a database name explicitly
		$configuredDb = $hostConfig['db']['name'] ?? null;
		if ($configuredDb) return $configuredDb;

		$root = $hostConfig['root'] ?? '';
		if (!$root) {
			return null;
		}
		$root = rtrim($root, '/');

		// For SQLite, find the database file
		if (class_exists('Q_WebServer_Database', false)) {
			$sqliteFile = Q_WebServer_Database::findSeed($root);
			if ($sqliteFile) return $sqliteFile;
		} else {
			// Fallback: look for common SQLite file patterns
			$sqliteGlob = glob("$root/*.sqlite");
			foreach ($sqliteGlob ?: array() as $f) {
				clearstatcache(true, $f);
				$ft = @filetype($f);
				if ($ft === 'file' || $ft === 'link') return $f;
			}
			foreach (glob("$root/database/*.sqlite") ?: array() as $f) {
				clearstatcache(true, $f);
				$ft = @filetype($f);
				if ($ft === 'file' || $ft === 'link') return $f;
			}
		}

		// For MySQL/Postgres, try to read the config
		// This is framework-dependent and handled by presets
		// For now, return null and let the admin configure it
		return null;
	}

	/**
	 * Drop a forked database and clean up resources.
	 * Delegates to Db_Branch::drop().
	 * @see Db_Branch::drop()
	 */
	static function dropDatabase($dbInfo)
	{
		Db_Branch::drop($dbInfo);
	}

	/**
	 * Build credential injection keys for a forked database.
	 * Delegates to Db_Branch::credentialKeys().
	 * @see Db_Branch::credentialKeys()
	 */
	private static function dbCredentialKeys($dbInfo, $hostConfig)
	{
		$preset = $hostConfig['preset'] ?? null;
		return Db_Branch::credentialKeys($dbInfo, $preset);
	}

	// ─── UID management ─────────────────────────────────────────────

	/**
	 * Assign or retrieve a UID for a branch.
	 * @method assignUid
	 * @static
	 * @private
	 */
	private static function assignUid($branchKey)
	{
		if (isset(self::$state['uidMap'][$branchKey])) {
			return self::$state['uidMap'][$branchKey];
		}

		$uidBase = (int) Q_Config::get(
			'Q', 'webserver', 'sandbox', 'uidBase', 60000
		);
		$uidRange = (int) Q_Config::get(
			'Q', 'webserver', 'sandbox', 'uidRange', 5000
		);
		$uid = self::$state['uidNext'] ?? $uidBase;

		// Check that we haven't exhausted the range
		if ($uid >= $uidBase + $uidRange) {
			error_log("Q_WebServer_Branch: UID range exhausted "
				. "(base=$uidBase, range=$uidRange, next=$uid). "
				. "Increase Q.webserver.sandbox.uidRange.");
			// Return the uid anyway — better to overlap than to fail
			// to create a branch entirely.  Admin should expand range.
		}

		self::$state['uidMap'][$branchKey] = $uid;
		self::$state['uidNext'] = $uid + 1;

		return $uid;
	}

	// ─── File ownership helpers ────────────────────────────────────

	/**
	 * Set ownership of the branch CoW root to the branch uid.
	 * This ensures files created by the branch worker are owned by
	 * the branch uid, and other branch workers cannot modify them.
	 *
	 * Only the top-level directory and its immediate real directories
	 * are chowned.  Symlinks (pointing at trunk files) are not chowned
	 * because lchown would change the link itself, not the target,
	 * and the branch uid should not own trunk files.
	 *
	 * @method chownBranchRoot
	 * @static
	 * @private
	 * @param {string} $branchRoot  Absolute path to the branch CoW root
	 * @param {int} $uid  The branch uid
	 */
	private static function chownBranchRoot($branchRoot, $uid)
	{
		@chown($branchRoot, $uid);
		@chgrp($branchRoot, $uid);
		// Restrict so only the branch's own UID can access (0750).
		// Group access allowed so the server process can still read
		// when it needs to (e.g. for merge operations).
		@chmod($branchRoot, 0750);

		// Recurse into real directories (not symlinks) and chown them
		// so the branch worker can create files inside.
		self::chownDirsRecursive($branchRoot, $uid);
	}

	/**
	 * Recursively chown real directories (not symlinks) under $dir.
	 * @method chownDirsRecursive
	 * @static
	 * @private
	 */
	private static function chownDirsRecursive($dir, $uid)
	{
		$entries = @scandir($dir);
		if ($entries === false) return;

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') continue;
			$path = $dir . '/' . $entry;
			if (is_dir($path) && !is_link($path)) {
				@chown($path, $uid);
				@chgrp($path, $uid);
				self::chownDirsRecursive($path, $uid);
			}
			// Symlinks are left alone — they point at trunk files
			// which keep trunk ownership.
		}
	}

	/**
	 * Set ownership of trunk files to the trunk uid.
	 *
	 * This is called once when the first branch is created, to ensure
	 * that trunk files are owned by the trunk uid (not root).  Branch
	 * workers running under their own uid can read these files (mode 0644)
	 * but cannot write to them, giving kernel-enforced immutability of
	 * trunk content from the branch worker's perspective.
	 *
	 * Only runs when the server has setuid capability (running as root).
	 *
	 * @method chownTrunkFiles
	 * @static
	 * @private
	 * @param {string} $appRoot  Absolute path to the app's root directory
	 * @param {int} $trunkUid  The trunk app's assigned uid
	 */
	private static function chownTrunkFiles($appRoot, $trunkUid)
	{
		// Check if already owned by the trunk uid (avoid unnecessary work)
		$stat = @stat($appRoot);
		if ($stat && $stat['uid'] === $trunkUid) {
			return; // Already owned by trunk uid
		}

		// Recursively chown the app root to the trunk uid.
		// Use a subprocess for efficiency on large trees.
		$cmd = 'chown -R ' . (int)$trunkUid . ':' . (int)$trunkUid
			. ' ' . escapeshellarg($appRoot) . ' 2>&1';
		$output = array();
		$ret = 0;
		exec($cmd, $output, $ret);
		if ($ret !== 0) {
			error_log("Q_WebServer_Branch: chown trunk files failed: "
				. implode("\n", $output));
		}

		// Ensure files are world-readable (mode 0644 for files, 0755
		// for directories) so branch workers can read through symlinks.
		$cmd = 'chmod -R a+rX ' . escapeshellarg($appRoot) . ' 2>&1';
		exec($cmd, $output, $ret);
		if ($ret !== 0) {
			error_log("Q_WebServer_Branch: chmod trunk files failed: "
				. implode("\n", $output));
		}
	}

	// ─── Platform-compatible filesystem helpers ────────────────────

	/**
	 * Create a symlink, delegating to Q_Utils::symlink() when the
	 * Platform is loaded for cross-platform support (Windows mklink).
	 *
	 * @method makeSymlink
	 * @static
	 * @param {string} $target  What the symlink points to
	 * @param {string} $link  Where to place the symlink
	 * @param {boolean} [$skipIfExists=false]
	 */
	static function makeSymlink($target, $link, $skipIfExists = false)
	{
		if (class_exists('Q_Utils', false)
			&& method_exists('Q_Utils', 'symlink')
		) {
			Q_Utils::symlink($target, $link, $skipIfExists);
			return;
		}
		// Standalone fallback
		if (is_link($link)) {
			if ($skipIfExists) return;
			unlink($link);
		}
		$dir = dirname($link);
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}
		@symlink($target, $link);
	}

	/**
	 * Recursively remove a directory and all its contents.
	 * Delegates to Q_Utils::rmdir() when the Platform is loaded.
	 * Handles symlinks (removes the link, not the target).
	 *
	 * @method removeDir
	 * @static
	 * @param {string} $dir
	 * @return {boolean}
	 */
	static function removeDir($dir)
	{
		if (class_exists('Q_Utils', false)
			&& method_exists('Q_Utils', 'rmdir')
		) {
			return Q_Utils::rmdir($dir);
		}
		// Standalone fallback
		if (!file_exists($dir) && !is_link($dir)) return true;
		if (!is_dir($dir) || is_link($dir)) return unlink($dir);

		$entries = scandir($dir);
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') continue;
			$path = $dir . '/' . $entry;
			if (is_link($path)) {
				unlink($path);
			} elseif (is_dir($path)) {
				self::removeDir($path);
			} else {
				unlink($path);
			}
		}
		return rmdir($dir);
	}

	/**
	 * Copy a directory recursively. Delegates to Q_Utils::cp()
	 * when the Platform is loaded.
	 *
	 * @method copyDir
	 * @static
	 * @param {string} $src
	 * @param {string} $dest
	 * @return {boolean}
	 */
	static function copyDir($src, $dest)
	{
		if (class_exists('Q_Utils', false)
			&& method_exists('Q_Utils', 'cp')
		) {
			return Q_Utils::cp($src, $dest);
		}
		// Standalone fallback
		if (is_file($src)) return copy($src, $dest);
		if (!is_dir($src)) return false;
		@mkdir($dest, 0755, true);
		foreach (scandir($src) as $entry) {
			if ($entry === '.' || $entry === '..') continue;
			if (is_dir($src . '/' . $entry)) {
				self::copyDir($src . '/' . $entry, $dest . '/' . $entry);
			} else {
				copy($src . '/' . $entry, $dest . '/' . $entry);
			}
		}
		return true;
	}

	// ─── Utilities ──────────────────────────────────────────────────

	/**
	 * Determine which permission tier an extension requires.
	 *
	 * @method extensionTier
	 * @static
	 * @param {string} $ext  Lowercase file extension
	 * @param {string} $filePath  Full relative path (for template detection)
	 * @param {array} $preset  Framework preset
	 * @return {string|null} Required tier, or null if extension not recognized
	 */
	static function extensionTier($ext, $filePath = '', $preset = array())
	{
		// PHP files: check templatePaths first
		if ($ext === 'php' || $ext === 'phtml') {
			$templatePaths = $preset['templatePaths'] ?? array();
			foreach ($templatePaths as $pattern) {
				if (self::globMatch($filePath, $pattern)) {
					return 'markup';
				}
			}
			return 'code';
		}

		// Check each tier from most restrictive to least
		foreach (array('styles', 'markup', 'frontend') as $tier) {
			$exts = self::$tiers[$tier];
			if ($exts !== null && in_array($ext, $exts, true)) {
				return $tier;
			}
		}

		// Unknown extensions default to code tier
		return 'code';
	}

	/**
	 * Simple glob matching supporting * and ** patterns.
	 *
	 * @method globMatch
	 * @static
	 * @param {string} $path  Path to test
	 * @param {string} $pattern  Glob pattern
	 * @return {boolean}
	 */
	static function globMatch($path, $pattern)
	{
		// Normalize separators
		$path = str_replace('\\', '/', $path);
		$pattern = str_replace('\\', '/', $pattern);

		// Remove leading slashes for consistency
		$path = ltrim($path, '/');
		$pattern = ltrim($pattern, '/');

		// Convert glob pattern to regex
		$regex = '';
		$len = strlen($pattern);
		for ($i = 0; $i < $len; $i++) {
			$c = $pattern[$i];
			if ($c === '*') {
				if ($i + 1 < $len && $pattern[$i + 1] === '*') {
					// ** matches any path including separators
					$i++;
					if ($i + 1 < $len && $pattern[$i + 1] === '/') {
						$i++; // consume the trailing /
						$regex .= '(?:.+/)?';
					} else {
						$regex .= '.*';
					}
				} else {
					// * matches anything except /
					$regex .= '[^/]*';
				}
			} elseif ($c === '?') {
				$regex .= '[^/]';
			} elseif ($c === '.') {
				$regex .= '\\.';
			} else {
				$regex .= preg_quote($c, '#');
			}
		}

		return (bool) preg_match('#^' . $regex . '$#', $path);
	}

	/**
	 * Check if a dot-notation config path matches any pattern in a list.
	 * Pattern "Q.theme" matches "Q.theme" and "Q.theme.colors" (anything under it).
	 *
	 * @method configPathMatches
	 * @static
	 * @param {string} $path  Dot-notation config path
	 * @param {array} $patterns  List of dot-notation patterns
	 * @return {boolean}
	 */
	static function configPathMatches($path, $patterns)
	{
		foreach ($patterns as $pattern) {
			if ($path === $pattern) return true;
			// Pattern is a prefix: Q.theme matches Q.theme.colors
			if (strpos($path, $pattern . '.') === 0) return true;
			// Wildcard at end: Q.database.* matches Q.database.host
			if (substr($pattern, -2) === '.*') {
				$base = substr($pattern, 0, -2);
				if ($path === $base || strpos($path, $base . '.') === 0) {
					return true;
				}
			}
		}
		return false;
	}

	// ─── Framework presets for file permissions ─────────────────────

	/**
	 * Return the file-permission preset for a framework.
	 *
	 * Each preset declares:
	 *  - tierPaths: per-tier deny/allow path globs
	 *  - templatePaths: globs identifying PHP files that count as markup
	 *  - configFormat: "json", "yaml", or null (PHP-based config)
	 *  - configFiles: globs identifying config files for key-level gating
	 *  - tierConfig: per-tier allow/deny config key paths
	 *
	 * @method getFrameworkPreset
	 * @static
	 * @param {string} $presetName  Framework name (laravel, wordpress, etc.)
	 * @return {array} Preset array, or empty array if unknown
	 */
	static function getFrameworkPreset($presetName)
	{
		$presets = array(
			'laravel' => array(
				'tierPaths' => array(
					'styles' => array(
						'deny' => array('.env', 'config/**', 'storage/**', 'bootstrap/**'),
					),
					'markup' => array(
						'deny' => array('.env', 'config/database.php', 'config/app.php', 'bootstrap/**'),
					),
					'frontend' => array(
						'deny' => array('.env', 'config/database.php'),
					),
					'code' => array(
						'deny' => array('.env'),
					),
				),
				'templatePaths' => array(
					'resources/views/**/*.blade.php',
				),
				'configFormat' => null, // PHP arrays
				'configFiles' => array(),
				'tierConfig' => array(),
				'sensitiveKeys' => array(
					'DB_PASSWORD', 'DB_USERNAME', 'DB_HOST', 'DB_DATABASE',
					'APP_KEY', '*_SECRET', 'MAIL_PASSWORD', 'REDIS_PASSWORD',
					'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY',
					'PUSHER_APP_SECRET', 'STRIPE_SECRET',
				),
			),
			'symfony' => array(
				'sensitiveKeys' => array(
					'doctrine.dbal.url', 'doctrine.dbal.password',
					'doctrine.dbal.user', 'framework.secret',
					'mailer.dsn', 'DATABASE_URL', 'APP_SECRET',
				),
				'tierPaths' => array(
					'styles' => array(
						'deny' => array('.env', '.env.local', 'config/**'),
					),
					'markup' => array(
						'deny' => array('.env', '.env.local', 'config/packages/doctrine.yaml', 'config/packages/security.yaml'),
					),
					'frontend' => array(
						'deny' => array('.env', '.env.local', 'config/packages/doctrine.yaml'),
					),
					'code' => array(
						'deny' => array('.env', '.env.local'),
					),
				),
				'templatePaths' => array(
					'templates/**/*.html.twig',
					'templates/**/*.twig',
				),
				'configFormat' => 'yaml',
				'configFiles' => array('config/**/*.yaml', 'config/**/*.yml'),
				'tierConfig' => array(
					'styles' => array(
						'allow' => array('twig.globals.theme'),
					),
					'markup' => array(
						'allow' => array('twig', 'framework.router'),
						'deny' => array('doctrine.dbal.url', 'doctrine.dbal.password'),
					),
					'frontend' => array(
						'allow' => array('twig', 'framework.router', 'framework.assets'),
						'deny' => array('doctrine.dbal.url', 'doctrine.dbal.password'),
					),
					'code' => array(
						'deny' => array('doctrine.dbal.url', 'doctrine.dbal.password'),
					),
				),
			),
			'wordpress' => array(
				'sensitiveKeys' => array(
					'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST',
					'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
					'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
					'*_KEY', '*_SALT',
				),
				'tierPaths' => array(
					'styles' => array(
						'deny' => array('wp-config.php', 'wp-includes/**', 'wp-admin/**'),
					),
					'markup' => array(
						'deny' => array('wp-config.php', 'wp-includes/**', 'wp-admin/**', 'wp-content/plugins/**/*.php'),
					),
					'frontend' => array(
						'deny' => array('wp-config.php', 'wp-includes/**', 'wp-admin/**'),
					),
					'code' => array(
						'deny' => array('wp-config.php'),
					),
				),
				'templatePaths' => array(
					'wp-content/themes/**/*.php',
				),
				'configFormat' => null,
				'configFiles' => array(),
				'tierConfig' => array(),
			),
			'drupal' => array(
				'sensitiveKeys' => array(
					'databases.default.default.database',
					'databases.default.default.username',
					'databases.default.default.password',
					'databases.default.default.host',
					'settings.hash_salt',
				),
				'tierPaths' => array(
					'styles' => array(
						'deny' => array('sites/*/settings.php', 'sites/*/services.yml', 'core/**'),
					),
					'markup' => array(
						'deny' => array('sites/*/settings.php', 'sites/*/services.yml', 'core/**'),
					),
					'frontend' => array(
						'deny' => array('sites/*/settings.php', 'sites/*/services.yml'),
					),
					'code' => array(
						'deny' => array('sites/*/settings.php'),
					),
				),
				'templatePaths' => array(
					'themes/**/*.html.twig',
					'modules/custom/**/templates/**/*.html.twig',
				),
				'configFormat' => null,
				'configFiles' => array(),
				'tierConfig' => array(),
			),
			'cakephp' => array(
				'sensitiveKeys' => array(
					'Datasources.default.host', 'Datasources.default.username',
					'Datasources.default.password', 'Datasources.default.database',
					'Security.salt',
				),
				'tierPaths' => array(
					'styles' => array(
						'deny' => array('.env', 'config/app.php', 'config/app_local.php', 'config/**'),
					),
					'markup' => array(
						'deny' => array('.env', 'config/app.php', 'config/app_local.php'),
					),
					'frontend' => array(
						'deny' => array('.env', 'config/app.php', 'config/app_local.php'),
					),
					'code' => array(
						'deny' => array('.env'),
					),
				),
				'templatePaths' => array(
					'templates/**/*.php',
				),
				'configFormat' => null,
				'configFiles' => array(),
				'tierConfig' => array(),
			),
			'codeigniter' => array(
				'sensitiveKeys' => array(
					'database.default.hostname', 'database.default.username',
					'database.default.password', 'database.default.database',
					'encryption.key',
				),
				'tierPaths' => array(
					'styles' => array(
						'deny' => array('.env', 'app/Config/**'),
					),
					'markup' => array(
						'deny' => array('.env', 'app/Config/Database.php', 'app/Config/App.php'),
					),
					'frontend' => array(
						'deny' => array('.env', 'app/Config/Database.php'),
					),
					'code' => array(
						'deny' => array('.env'),
					),
				),
				'templatePaths' => array(
					'app/Views/**/*.php',
				),
				'configFormat' => null,
				'configFiles' => array(),
				'tierConfig' => array(),
			),
			'yii' => array(
				'sensitiveKeys' => array(
					'components.db.dsn', 'components.db.username',
					'components.db.password', 'cookieValidationKey',
				),
				'tierPaths' => array(
					'styles' => array(
						'deny' => array('.env', 'config/**'),
					),
					'markup' => array(
						'deny' => array('.env', 'config/db.php', 'config/params.php'),
					),
					'frontend' => array(
						'deny' => array('.env', 'config/db.php'),
					),
					'code' => array(
						'deny' => array('.env'),
					),
				),
				'templatePaths' => array(
					'views/**/*.php',
				),
				'configFormat' => null,
				'configFiles' => array(),
				'tierConfig' => array(),
			),
			'qbix' => array(
				'sensitiveKeys' => array(
					'Q.database.*', 'Q.secrets.*',
					'Q.Users.oAuth.*', 'Q.Users.stripe.*',
					'Q.Streams.push.*',
				),
				'tierPaths' => array(
					'styles' => array(
						'deny' => array('local/**', 'config/**'),
					),
					'markup' => array(
						'deny' => array('local/app.json', 'config/Q/database.json'),
						'allow' => array('config/Q/text/**'),
					),
					'frontend' => array(
						'deny' => array('local/app.json'),
					),
					'code' => array(
						'deny' => array(),
					),
				),
				'templatePaths' => array(
					'views/**/*.php',
				),
				'configFormat' => 'json',
				'configFiles' => array('local/app.json', 'config/**/*.json'),
				'tierConfig' => array(
					'styles' => array(
						'allow' => array('Q.theme.colors', 'Q.theme.fonts'),
					),
					'markup' => array(
						'allow' => array('Q.theme', 'Q.menus', 'Q.text'),
						'deny' => array('Q.database'),
					),
					'frontend' => array(
						'allow' => array('Q.theme', 'Q.menus', 'Q.text', 'Q.routes'),
						'deny' => array('Q.database'),
					),
					'code' => array(
						'deny' => array(),
					),
				),
			),
			'joomla' => array(
				'sensitiveKeys' => array(
					'host', 'user', 'password', 'db', 'dbprefix',
					'secret', 'smtpuser', 'smtppass',
					'ftp_user', 'ftp_pass',
				),
				'tierPaths' => array(
					'styles' => array(
						'deny' => array('configuration.php', 'administrator/**', 'libraries/**'),
					),
					'markup' => array(
						'deny' => array('configuration.php', 'administrator/**', 'libraries/**'),
					),
					'frontend' => array(
						'deny' => array('configuration.php', 'administrator/**'),
					),
					'code' => array(
						'deny' => array('configuration.php'),
					),
				),
				'templatePaths' => array(
					'templates/**/*.php',
					'components/**/tmpl/**/*.php',
				),
				'configFormat' => null,
				'configFiles' => array(),
				'tierConfig' => array(),
			),
		);

		return $presets[$presetName] ?? array();
	}

	// ─── Branch authentication ──────────────────────────────────────

	/**
	 * Authenticate a request for branch access.
	 *
	 * Checks (in order):
	 * 1. Panel session cookie (Q_panel_token)
	 * 2. Bearer token in Authorization header
	 * 3. Basic auth (branch-level password)
	 *
	 * If authenticated, looks up the user's branch permission and file
	 * tier.  Returns an array with user info, or null if no branch
	 * access (the caller should return 403).
	 *
	 * @method authenticateForBranch
	 * @static
	 * @param {array} $branchRecord  The resolved branch record
	 * @param {array} $headers  Parsed HTTP headers (lowercase keys)
	 * @param {array} $cookies  Parsed cookies
	 * @return {array|null} Array with keys: user, branchPerm, fileTier,
	 *   preset, userPaths, userConfig.  Null if access denied.
	 */
	static function authenticateForBranch($branchRecord, $headers = array(), $cookies = array())
	{
		$user = null;

		// 1. Panel session cookie — uses the same session store as the
		//    control panel.  The panel login returns a token that is
		//    stored in Q_panel_token cookie.
		$panelToken = $cookies['Q_panel_token'] ?? '';
		if ($panelToken
			&& class_exists('Q_WebServer_Panel', false)
			&& Q_WebServer_Panel::validateToken($panelToken)
		) {
			// Panel sessions are admin — they have the panel password
			$user = 'panel';
		}

		// 2. Bearer token — look up in the branch's access list.
		//    Branch tokens are stored in the branch record.
		if (!$user) {
			$authHeader = $headers['authorization'] ?? '';
			if (strpos($authHeader, 'Bearer ') === 0) {
				$token = substr($authHeader, 7);
				// Check branch-level token list
				$tokens = $branchRecord['tokens'] ?? array();
				if (isset($tokens[$token])) {
					$user = $tokens[$token]; // maps token => username
				}
				// Also check panel session as Bearer
				if (!$user && $panelToken === ''
					&& class_exists('Q_WebServer_Panel', false)
					&& Q_WebServer_Panel::validateToken($token)
				) {
					$user = 'panel';
				}
			}
		}

		// 3. Basic auth — branch can have a shared password
		if (!$user) {
			$authHeader = $headers['authorization'] ?? '';
			if (strpos($authHeader, 'Basic ') === 0) {
				$decoded = base64_decode(substr($authHeader, 6));
				if ($decoded !== false) {
					$parts = explode(':', $decoded, 2);
					$user = $parts[0] ?? '';
					$pass = $parts[1] ?? '';
					// Verify against branch password
					$branchPass = $branchRecord['password'] ?? null;
					if ($branchPass !== null) {
						if (!password_verify($pass, $branchPass)) {
							$user = null; // wrong password
						}
					} else {
						// No branch password set — basic auth not available
						$user = null;
					}
				}
			}
		}

		// 4. Check wildcard access (public branches)
		if (!$user) {
			$access = $branchRecord['access'] ?? array();
			$wildcardEntry = $access['*'] ?? null;
			if ($wildcardEntry !== null) {
				$user = '*';
			}
		}

		if (!$user) return null;

		// Look up branch permission
		$branchPerm = self::getBranchPermission($branchRecord, $user);
		if (!$branchPerm) return null;

		// Look up file tier
		$fileTier = self::getFileTier($branchRecord, $user);

		// Get framework preset for file permissions
		$presetName = $branchRecord['preset']
			?? Q_Config::get('Q', 'webserver', 'hosts',
				$branchRecord['appHost'] ?? '', 'preset', null);
		$preset = $presetName ? self::getFrameworkPreset($presetName) : array();

		// Get per-user path and config overrides
		$userPaths = self::getUserPathOverrides($branchRecord, $user);
		$userConfig = self::getUserConfigOverrides($branchRecord, $user);

		self::$currentUser = $user;

		return array(
			'user' => $user,
			'branchPerm' => $branchPerm,
			'fileTier' => $fileTier,
			'preset' => $preset,
			'userPaths' => $userPaths,
			'userConfig' => $userConfig,
		);
	}

	// ─── Interop with Q_Branch ──────────────────────────────────────

	/**
	 * Get a Q_Branch instance for a branch's content files, if the
	 * Platform is loaded and Q_Branch is available.
	 *
	 * This enables content-level forking (stream uploads, file stores)
	 * within an app-level branch. App-level branching (this class) and
	 * content-level branching (Q_Branch) are complementary.
	 *
	 * @method contentBranch
	 * @static
	 * @param {string} $branchName
	 * @param {array} [$options]
	 * @return {Q_Branch|null}  Q_Branch instance if available, null otherwise
	 */
	static function contentBranch($branchName, $options = array())
	{
		if (!class_exists('Q_Branch', false)) {
			return null;
		}
		return Q_Branch::of($branchName, $options);
	}

	/**
	 * Diff this branch against its trunk using Q_Branch::diff() semantics
	 * when the Platform is loaded, or a standalone implementation.
	 *
	 * Returns paths from the branch's perspective:
	 *   added   — in branch but not in trunk (new files)
	 *   removed — in trunk but removed from branch
	 *   changed — in both but content differs
	 *
	 * @method diff
	 * @static
	 * @param {string} $branchRoot  Absolute path to the branch directory
	 * @param {string} $trunkRoot  Absolute path to the trunk directory
	 * @return {array}  ['added' => [...], 'removed' => [...], 'changed' => [...]]
	 */
	static function diff($branchRoot, $trunkRoot)
	{
		$added = $removed = $changed = array();

		// Collect branch files: only real files (not symlinks) are changes
		$branchFiles = self::listFiles($branchRoot);
		$trunkFiles = self::listFiles($trunkRoot);

		foreach ($branchFiles as $rel => $dummy) {
			$branchPath = $branchRoot . '/' . $rel;
			$trunkPath = $trunkRoot . '/' . $rel;
			if (is_link($branchPath)) {
				// Still a symlink — inherited from trunk, not changed
				continue;
			}
			if (!isset($trunkFiles[$rel])) {
				$added[] = $rel;
			} else {
				// Real file in branch — compare content
				if (md5_file($branchPath) !== md5_file($trunkPath)) {
					$changed[] = $rel;
				}
			}
		}

		// Check for removals: trunk files whose symlinks were deleted from branch
		foreach ($trunkFiles as $rel => $dummy) {
			$branchPath = $branchRoot . '/' . $rel;
			if (!file_exists($branchPath) && !is_link($branchPath)) {
				$removed[] = $rel;
			}
		}

		return compact('added', 'removed', 'changed');
	}

	/**
	 * List all files in a directory tree, returning relative paths.
	 * @method listFiles
	 * @static
	 * @private
	 * @param {string} $dir
	 * @return {array}  Map of relativePath => true
	 */
	private static function listFiles($dir)
	{
		$result = array();
		if (!is_dir($dir)) return $result;

		$baseLen = strlen($dir) + 1;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
		);
		foreach ($iterator as $item) {
			$pathname = $item->getPathname();
			if ($item->isFile() || is_link($pathname)) {
				$rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($pathname, $baseLen));
				// Skip the wholesale-symlinked directories
				$first = explode('/', $rel)[0];
				if (in_array($first, self::$skipDirs, true) && is_link($dir . '/' . $first)) {
					continue;
				}
				$result[$rel] = true;
			}
		}
		return $result;
	}

	// ─── AI collaboration API ──────────────────────────────────────

	/**
	 * Export a branch (or trunk) as an archive with credential scrubbing.
	 *
	 * Builds a file tree respecting the caller's file-permission tier,
	 * scrubs credentials from config files, attaches a manifest with
	 * framework metadata and placeholder list, creates an archive, and
	 * returns a single-use download URL.
	 *
	 * @method apiExport
	 * @static
	 * @param {array} $params  Keys: appHost (required), branchName (optional),
	 *   format ('tar.gz'|'zip', default 'tar.gz')
	 * @param {array} $authResult  From authenticateForBranch()
	 * @return {array} Result with downloadUrl, manifest, fileCount
	 */
	static function apiExport($params, $authResult)
	{
		$appHost = $params['appHost'] ?? '';
		$branchName = $params['branchName'] ?? null;
		$format = $params['format'] ?? 'tar.gz';

		if (!$appHost) {
			return array('error' => 'appHost is required');
		}
		if (!in_array($format, array('tar.gz', 'zip'))) {
			return array('error' => 'format must be tar.gz or zip');
		}

		// Resolve the root directory
		$root = null;
		if ($branchName) {
			if (!self::$state) self::loadState();
			$key = $appHost . '/' . $branchName;
			$branch = self::$state['branches'][$key] ?? null;
			if (!$branch) {
				return array('error' => "Branch not found: $branchName");
			}
			$root = $branch['root'];
		} else {
			// Trunk — use the vhost's document root
			$root = Q_Config::get('Q', 'webserver', 'hosts', $appHost, 'root', null);
			if (!$root) {
				return array('error' => "No root configured for host: $appHost");
			}
		}

		if (!is_dir($root)) {
			return array('error' => "Root directory not found: $root");
		}

		$fileTier = $authResult['fileTier'] ?? 'styles';
		$preset = $authResult['preset'] ?? array();
		$tierPaths = $preset['tierPaths'] ?? array();
		$sensitiveKeys = $preset['sensitiveKeys'] ?? array();
		$configFiles = $preset['configFiles'] ?? array();
		$configFormat = $preset['configFormat'] ?? null;
		$userPaths = $authResult['userPaths'] ?? array();

		// Collect files respecting tier permissions
		$allFiles = self::listFiles($root);
		$includedFiles = array();
		$skippedFiles = array();

		foreach ($allFiles as $relPath => $dummy) {
			$check = self::checkFilePermission($relPath, $fileTier, $tierPaths, $userPaths, $preset);
			if ($check === true) {
				$includedFiles[] = $relPath;
			} else {
				$skippedFiles[] = $relPath;
			}
		}

		// Build the archive in a temp directory
		$exportId = bin2hex(random_bytes(16));
		$exportDir = sys_get_temp_dir() . '/qbix_export_' . $exportId;
		mkdir($exportDir, 0755, true);

		$allPlaceholders = array();
		$allFlagged = array();

		foreach ($includedFiles as $relPath) {
			$srcPath = $root . '/' . $relPath;
			$destPath = $exportDir . '/' . $relPath;
			$destDir = dirname($destPath);
			if (!is_dir($destDir)) {
				mkdir($destDir, 0755, true);
			}

			// Check if this is a config file that needs scrubbing
			$isConfig = false;
			if ($configFormat) {
				foreach ($configFiles as $pattern) {
					if (self::globMatch($relPath, $pattern)) {
						$isConfig = true;
						break;
					}
				}
			}

			if ($isConfig && $configFormat) {
				$content = file_get_contents($srcPath);
				$parsed = self::parseConfigContent($content, $configFormat);
				if ($parsed !== null) {
					$scrubbed = self::scrubCredentials($parsed, $sensitiveKeys);
					$allPlaceholders = array_merge($allPlaceholders, $scrubbed['placeholders']);
					$allFlagged = array_merge($allFlagged, $scrubbed['flagged']);
					// Re-encode
					if ($configFormat === 'json') {
						file_put_contents($destPath,
							json_encode($scrubbed['config'],
								JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
					} elseif ($configFormat === 'yaml') {
						if (function_exists('yaml_emit')) {
							file_put_contents($destPath, yaml_emit($scrubbed['config']));
						} else {
							// Fallback: copy original with inline replacements
							$output = $content;
							foreach ($scrubbed['placeholders'] as $ph) {
								$keyParts = explode('.', $ph['key']);
								$origValue = $parsed;
								foreach ($keyParts as $k) {
									$origValue = $origValue[$k] ?? null;
								}
								if ($origValue !== null) {
									$output = str_replace($origValue, $ph['placeholder'], $output);
								}
							}
							file_put_contents($destPath, $output);
						}
					}
				} else {
					copy($srcPath, $destPath);
				}
			} else {
				// Non-config file or no structured format — check for credential
				// values in any file that looks like it could contain secrets
				$ext = pathinfo($relPath, PATHINFO_EXTENSION);
				if (in_array($ext, array('env', 'ini', 'conf', 'cfg', 'properties'))) {
					// Line-based key=value files (like .env)
					$content = file_get_contents($srcPath);
					$lines = explode("\n", $content);
					$modified = false;
					foreach ($lines as &$line) {
						$trimmed = ltrim($line);
						if ($trimmed === '' || $trimmed[0] === '#') continue;
						if (strpos($trimmed, '=') === false) continue;
						list($k, $v) = explode('=', $trimmed, 2);
						$k = trim($k);
						$v = trim($v, " \t\n\r\0\x0B\"'");
						$detection = self::looksLikeCredential($k, $v, $sensitiveKeys);
						if ($detection && $detection !== 'entropy') {
							$placeholder = '{{' . $k . '}}';
							$line = $k . '=' . $placeholder;
							$allPlaceholders[] = array(
								'key' => $k,
								'placeholder' => $placeholder,
								'detection' => $detection,
							);
							$modified = true;
						} elseif ($detection === 'entropy') {
							$allFlagged[] = array(
								'key' => $k,
								'reason' => 'High entropy value — possible credential',
							);
						}
					}
					unset($line);
					if ($modified) {
						file_put_contents($destPath, implode("\n", $lines));
					} else {
						copy($srcPath, $destPath);
					}
				} else {
					copy($srcPath, $destPath);
				}
			}
		}

		// Write the manifest
		$manifest = array(
			'version' => '1.0',
			'appHost' => $appHost,
			'branchName' => $branchName,
			'exportedAt' => gmdate('c'),
			'exportedBy' => $authResult['user'] ?? 'unknown',
			'fileTier' => $fileTier,
			'framework' => array(
				'preset' => array_search($preset, self::getFrameworkPreset('_all_') ?: array()) ?: null,
				'configFormat' => $configFormat,
			),
			'files' => array(
				'included' => count($includedFiles),
				'skipped' => count($skippedFiles),
			),
			'placeholders' => $allPlaceholders,
			'flagged' => $allFlagged,
		);

		// Determine the preset name properly
		$presetName = null;
		$presets = array('qbix', 'laravel', 'symfony', 'wordpress', 'drupal',
			'cakephp', 'codeigniter', 'yii', 'joomla');
		foreach ($presets as $pn) {
			if (self::getFrameworkPreset($pn) === $preset) {
				$presetName = $pn;
				break;
			}
		}
		$manifest['framework']['preset'] = $presetName;

		file_put_contents($exportDir . '/MANIFEST.json',
			json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

		// Create the archive
		$archiveExt = $format === 'zip' ? '.zip' : '.tar.gz';
		$archiveName = ($branchName ?: 'trunk') . '_' . date('Ymd_His') . $archiveExt;
		$archivePath = sys_get_temp_dir() . '/qbix_archive_' . $exportId . $archiveExt;

		if ($format === 'zip') {
			$zip = new \ZipArchive();
			if ($zip->open($archivePath, \ZipArchive::CREATE) !== true) {
				return array('error' => 'Failed to create ZIP archive');
			}
			$exportFiles = self::listFiles($exportDir);
			foreach ($exportFiles as $rel => $d) {
				$zip->addFile($exportDir . '/' . $rel, $rel);
			}
			$zip->close();
		} else {
			// tar.gz using PharData
			$tarPath = sys_get_temp_dir() . '/qbix_archive_' . $exportId . '.tar';
			$phar = new \PharData($tarPath);
			$phar->buildFromDirectory($exportDir);
			$phar->compress(\Phar::GZ);
			$archivePath = $tarPath . '.gz';
			@unlink($tarPath);
		}

		// Generate a single-use download token
		$downloadToken = bin2hex(random_bytes(32));
		$downloadFile = sys_get_temp_dir() . '/qbix_download_' . $downloadToken . '.meta';
		file_put_contents($downloadFile, json_encode(array(
			'path' => $archivePath,
			'name' => $archiveName,
			'created' => time(),
			'expires' => time() + 3600, // 1 hour
			'used' => false,
		)));

		// Clean up the export directory
		self::removeDir($exportDir);

		return array(
			'downloadUrl' => '/api/branch/download/' . $downloadToken,
			'fileName' => $archiveName,
			'manifest' => $manifest,
		);
	}

	/**
	 * Parse config file content into an array.
	 *
	 * @method parseConfigContent
	 * @static
	 * @param {string} $content  File content
	 * @param {string} $format  'json' or 'yaml'
	 * @return {array|null}  Parsed config, or null on parse error
	 */
	static function parseConfigContent($content, $format)
	{
		if ($format === 'json') {
			$result = json_decode($content, true);
			return is_array($result) ? $result : null;
		}
		if ($format === 'yaml') {
			if (function_exists('yaml_parse')) {
				$result = yaml_parse($content);
				return is_array($result) ? $result : null;
			}
			// Fallback: try json_decode in case it's also valid JSON
			return null;
		}
		return null;
	}

	/**
	 * Push files to a branch's CoW overlay.
	 *
	 * Validates every file against the caller's tier and tierConfig
	 * permissions before writing. Returns a summary of accepted and
	 * rejected files.
	 *
	 * @method apiPush
	 * @static
	 * @param {array} $params  Keys: appHost (required), branchName (required),
	 *   files (array of ['path' => relPath, 'content' => base64content])
	 * @param {array} $authResult  From authenticateForBranch()
	 * @return {array} Result with accepted, rejected arrays and previewUrl
	 */
	static function apiPush($params, $authResult)
	{
		$appHost = $params['appHost'] ?? '';
		$branchName = $params['branchName'] ?? '';
		$files = $params['files'] ?? array();

		if (!$appHost) {
			return array('error' => 'appHost is required');
		}
		if (!$branchName) {
			return array('error' => 'branchName is required');
		}
		if (empty($files)) {
			return array('error' => 'files array is required and must not be empty');
		}

		// Require at least edit permission
		$branchPerm = $authResult['branchPerm'] ?? 'view';
		if ($branchPerm === 'view') {
			return array('error' => 'Push requires edit or admin branch permission');
		}

		// Resolve the branch
		if (!self::$state) self::loadState();
		$key = $appHost . '/' . $branchName;
		$branch = self::$state['branches'][$key] ?? null;
		if (!$branch) {
			return array('error' => "Branch not found: $branchName");
		}

		$branchRoot = $branch['root'];
		clearstatcache();
		// Use lstat to check directory existence — stat() can return
		// stale results on overlay/container filesystems even after
		// clearstatcache()
		$branchStat = @lstat($branchRoot);
		if (!$branchStat || ($branchStat['mode'] & 0040000) === 0) {
			return array('error' => "Branch root directory not found");
		}

		$fileTier = $authResult['fileTier'] ?? 'styles';
		$preset = $authResult['preset'] ?? array();
		$tierPaths = $preset['tierPaths'] ?? array();
		$tierConfig = $preset['tierConfig'] ?? array();
		$configFiles = $preset['configFiles'] ?? array();
		$configFormat = $preset['configFormat'] ?? null;
		$sensitiveKeys = $preset['sensitiveKeys'] ?? array();
		$userPaths = $authResult['userPaths'] ?? array();
		$userConfig = $authResult['userConfig'] ?? array();

		$accepted = array();
		$rejected = array();

		foreach ($files as $fileEntry) {
			$relPath = $fileEntry['path'] ?? '';
			$content = $fileEntry['content'] ?? '';
			$encoding = $fileEntry['encoding'] ?? 'base64';

			if (!$relPath) {
				$rejected[] = array('path' => '(empty)', 'reason' => 'Missing file path');
				continue;
			}

			// Sanitize path — no directory traversal
			$relPath = ltrim($relPath, '/');
			if (strpos($relPath, '..') !== false || strpos($relPath, "\0") !== false) {
				$rejected[] = array('path' => $relPath, 'reason' => 'Invalid path');
				continue;
			}

			// Enforce branch deny paths (default lockdown).
			// Code-tier users bypass deny checks — they have full access.
			$branchDenyPaths = $branch['denyPaths'] ?? array();
			if ($fileTier !== 'code' && $branchDenyPaths) {
				if (self::isDeniedByDefault($relPath, $branchDenyPaths)) {
					$rejected[] = array('path' => $relPath, 'reason' => 'Path denied by branch lockdown policy');
					continue;
				}
			}

			// Check file permission (extension + tierPaths)
			$permCheck = self::checkFilePermission($relPath, $fileTier, $tierPaths, $userPaths, $preset);
			if ($permCheck !== true) {
				$rejected[] = array('path' => $relPath, 'reason' => $permCheck);
				continue;
			}

			// Decode content
			if ($encoding === 'base64') {
				$decoded = base64_decode($content, true);
				if ($decoded === false) {
					$rejected[] = array('path' => $relPath, 'reason' => 'Invalid base64 content');
					continue;
				}
			} else {
				$decoded = $content;
			}

			// Check config-key permissions for structured config files
			if ($configFormat && $fileTier !== 'code') {
				$isConfig = false;
				foreach ($configFiles as $pattern) {
					if (self::globMatch($relPath, $pattern)) {
						$isConfig = true;
						break;
					}
				}

				if ($isConfig) {
					$newParsed = self::parseConfigContent($decoded, $configFormat);
					if ($newParsed !== null) {
						// Load original if it exists
						$origPath = $branchRoot . '/' . $relPath;
						$origParsed = array();
						if (file_exists($origPath)) {
							$origContent = file_get_contents($origPath);
							$origParsed = self::parseConfigContent($origContent, $configFormat) ?: array();
						}

						$changedKeys = self::diffConfigKeys($origParsed, $newParsed);
						if (!empty($changedKeys)) {
							$configCheck = self::checkConfigPermission(
								$changedKeys, $fileTier, $tierConfig, $userConfig
							);
							if ($configCheck !== true) {
								$rejected[] = array('path' => $relPath, 'reason' => $configCheck);
								continue;
							}
						}
					}
				}
			}

			// Write the file to the branch CoW overlay
			$destPath = $branchRoot . '/' . $relPath;
			$destDir = dirname($destPath);
			if (!is_dir($destDir)) {
				mkdir($destDir, 0755, true);
			}

			// If this is a symlink (inherited from trunk), remove it first
			// to create a real file (the CoW break)
			if (is_link($destPath)) {
				unlink($destPath);
			}

			if (file_put_contents($destPath, $decoded) !== false) {
				$accepted[] = array('path' => $relPath, 'size' => strlen($decoded));
			} else {
				$rejected[] = array('path' => $relPath, 'reason' => 'Write failed');
			}
		}

		// Build the preview URL
		$previewUrl = 'https://' . $branchName . '.' . $appHost . '/';

		return array(
			'accepted' => $accepted,
			'rejected' => $rejected,
			'previewUrl' => $previewUrl,
			'summary' => count($accepted) . ' accepted, ' . count($rejected) . ' rejected',
		);
	}

	/**
	 * Detect the best available VCS or patch tool on this system.
	 *
	 * Preference order: git > hg > patch. On Windows, the `patch`
	 * command is not available natively, so only git or hg are
	 * returned.
	 *
	 * @method detectVcs
	 * @static
	 * @return {string|null}  'git', 'hg', 'patch', or null if none available
	 */
	static function detectVcs()
	{
		// Check git
		$out = array();
		$code = 0;
		@exec('git --version 2>&1', $out, $code);
		if ($code === 0 && !empty($out[0]) && strpos($out[0], 'git version') !== false) {
			return 'git';
		}

		// Check mercurial
		$out = array();
		@exec('hg --version 2>&1', $out, $code);
		if ($code === 0 && !empty($out[0])) {
			return 'hg';
		}

		// On Windows, there's no native patch command
		if (DIRECTORY_SEPARATOR === '\\') {
			return null;
		}

		// Check patch
		$out = array();
		@exec('patch --version 2>&1', $out, $code);
		if ($code === 0 && !empty($out[0])) {
			return 'patch';
		}

		return null;
	}

	/**
	 * Apply a unified diff (patch) to a branch.
	 *
	 * Validates every file path in the diff against the caller's tier
	 * and deny-path permissions. Uses the best available VCS or patch
	 * tool: git (with optional commit), hg (with optional commit), or
	 * the `patch` command (no VCS history). On Windows, git or hg is
	 * required.
	 *
	 * @method apiPatch
	 * @static
	 * @param {array} $params  Keys: appHost (required), branchName (required),
	 *   patch (string, unified diff), commitMessage (optional string)
	 * @param {array} $authResult  From authenticateForBranch()
	 * @return {array} Result with vcs, filesChanged, commit info
	 */
	static function apiPatch($params, $authResult)
	{
		$appHost = $params['appHost'] ?? '';
		$branchName = $params['branchName'] ?? '';
		$patch = $params['patch'] ?? '';
		$commitMessage = $params['commitMessage'] ?? '';

		if (!$appHost) {
			return array('error' => 'appHost is required');
		}
		if (!$branchName) {
			return array('error' => 'branchName is required');
		}
		if (!$patch) {
			return array('error' => 'patch (unified diff) is required');
		}

		// Require at least edit permission
		$branchPerm = $authResult['branchPerm'] ?? 'view';
		if ($branchPerm === 'view') {
			return array('error' => 'Patch requires edit or admin branch permission');
		}

		// Check sandbox — shell must be allowed
		$sandbox = $authResult['sandbox'] ?? array();
		// We need shell access for VCS/patch commands
		// The branch's own sandbox setting is checked here
		if (!self::$state) self::loadState();
		$key = $appHost . '/' . $branchName;
		$branch = self::$state['branches'][$key] ?? null;
		if (!$branch) {
			return array('error' => "Branch not found: $branchName");
		}

		$branchRoot = $branch['root'];
		clearstatcache();
		$branchStat = @lstat($branchRoot);
		if (!$branchStat || ($branchStat['mode'] & 0040000) === 0) {
			return array('error' => 'Branch root directory not found');
		}

		// Detect available VCS
		$vcs = self::detectVcs();
		if (!$vcs) {
			$msg = 'No patch tool available.';
			if (DIRECTORY_SEPARATOR === '\\') {
				$msg .= ' On Windows, install git or mercurial to use patch-based updates.';
			} else {
				$msg .= ' Install git, mercurial, or the patch command.';
			}
			return array('error' => $msg);
		}

		$fileTier = $authResult['fileTier'] ?? 'styles';
		$preset = $authResult['preset'] ?? array();
		$tierPaths = $preset['tierPaths'] ?? array();
		$userPaths = $authResult['userPaths'] ?? array();
		$branchDenyPaths = $branch['denyPaths'] ?? array();

		// Parse the diff to extract affected file paths.
		// Unified diff headers: --- a/path and +++ b/path
		$affectedPaths = array();
		$rejected = array();
		$lines = explode("\n", $patch);
		foreach ($lines as $line) {
			// Match +++ b/path or --- a/path (skip /dev/null for new/deleted)
			if (preg_match('/^(?:\+\+\+|---)\s+[ab]\/(.+)$/', $line, $m)) {
				$relPath = $m[1];
				if ($relPath === '/dev/null' || $relPath === 'dev/null') {
					continue;
				}
				$affectedPaths[$relPath] = true;
			}
		}

		if (empty($affectedPaths)) {
			return array('error' => 'No file paths found in patch. Ensure it is a unified diff with a/ b/ prefixes.');
		}

		// Validate every affected path against permissions
		foreach ($affectedPaths as $relPath => $_) {
			// Sanitize
			if (strpos($relPath, '..') !== false || strpos($relPath, "\0") !== false) {
				$rejected[] = array('path' => $relPath, 'reason' => 'Invalid path');
				continue;
			}

			// Deny path check (code tier bypasses)
			if ($fileTier !== 'code' && $branchDenyPaths) {
				if (self::isDeniedByDefault($relPath, $branchDenyPaths)) {
					$rejected[] = array('path' => $relPath, 'reason' => 'Path denied by branch lockdown policy');
					continue;
				}
			}

			// File tier check
			$permCheck = self::checkFilePermission($relPath, $fileTier, $tierPaths, $userPaths, $preset);
			if ($permCheck !== true) {
				$rejected[] = array('path' => $relPath, 'reason' => $permCheck);
			}
		}

		if (!empty($rejected)) {
			return array(
				'error' => 'Patch rejected: some files are outside your permissions',
				'rejected' => $rejected,
			);
		}

		// Before applying, break CoW symlinks for any affected files
		foreach ($affectedPaths as $relPath => $_) {
			$destPath = $branchRoot . '/' . $relPath;
			if (is_link($destPath)) {
				// Read the symlink target, copy it to a real file
				$target = readlink($destPath);
				if ($target && file_exists($target)) {
					unlink($destPath);
					copy($target, $destPath);
				} else {
					// Symlink to missing file — just remove it
					unlink($destPath);
				}
			}
			// Ensure parent directories exist
			$destDir = dirname($destPath);
			if (!is_dir($destDir)) {
				mkdir($destDir, 0755, true);
			}
		}

		// Write the patch to a temp file
		$patchFile = sys_get_temp_dir() . '/qbix_patch_' . bin2hex(random_bytes(8)) . '.patch';
		file_put_contents($patchFile, $patch);

		$result = array(
			'vcs' => $vcs,
			'filesChanged' => array_keys($affectedPaths),
		);

		$output = array();
		$exitCode = 0;

		try {
			switch ($vcs) {
				case 'git':
					// Initialize git repo if not already one
					if (!is_dir($branchRoot . '/.git')) {
						@exec('git -C ' . escapeshellarg($branchRoot) . ' init 2>&1', $output, $exitCode);
						if ($exitCode !== 0) {
							return array('error' => 'Failed to initialize git repository: ' . implode("\n", $output));
						}
						// Initial commit of existing files
						@exec('git -C ' . escapeshellarg($branchRoot) . ' add -A 2>&1', $output, $exitCode);
						@exec('git -C ' . escapeshellarg($branchRoot) . ' commit -m ' . escapeshellarg('Initial branch state') . ' --allow-empty 2>&1', $output, $exitCode);
						$result['gitInitialized'] = true;
					}

					// Apply the patch
					$output = array();
					@exec('git -C ' . escapeshellarg($branchRoot) . ' apply --stat ' . escapeshellarg($patchFile) . ' 2>&1', $output, $exitCode);
					$result['stat'] = implode("\n", $output);

					$output = array();
					@exec('git -C ' . escapeshellarg($branchRoot) . ' apply ' . escapeshellarg($patchFile) . ' 2>&1', $output, $exitCode);
					if ($exitCode !== 0) {
						return array(
							'error' => 'git apply failed',
							'detail' => implode("\n", $output),
							'vcs' => 'git',
						);
					}

					// Commit if message provided
					if ($commitMessage) {
						$output = array();
						@exec('git -C ' . escapeshellarg($branchRoot) . ' add -A 2>&1', $output, $exitCode);
						$output = array();
						@exec('git -C ' . escapeshellarg($branchRoot) . ' commit -m ' . escapeshellarg($commitMessage) . ' 2>&1', $output, $exitCode);
						if ($exitCode === 0) {
							// Get the commit hash
							$hashOut = array();
							@exec('git -C ' . escapeshellarg($branchRoot) . ' rev-parse HEAD 2>&1', $hashOut, $exitCode);
							$result['commit'] = array(
								'hash' => trim($hashOut[0] ?? ''),
								'message' => $commitMessage,
							);
						}
					}
					break;

				case 'hg':
					// Initialize hg repo if not already one
					if (!is_dir($branchRoot . '/.hg')) {
						@exec('hg init ' . escapeshellarg($branchRoot) . ' 2>&1', $output, $exitCode);
						if ($exitCode !== 0) {
							return array('error' => 'Failed to initialize mercurial repository: ' . implode("\n", $output));
						}
						@exec('hg -R ' . escapeshellarg($branchRoot) . ' add 2>&1', $output, $exitCode);
						@exec('hg -R ' . escapeshellarg($branchRoot) . ' commit -m ' . escapeshellarg('Initial branch state') . ' 2>&1', $output, $exitCode);
						$result['hgInitialized'] = true;
					}

					if ($commitMessage) {
						// hg import applies and commits in one step
						$output = array();
						@exec('hg -R ' . escapeshellarg($branchRoot) . ' import --no-commit ' . escapeshellarg($patchFile) . ' 2>&1', $output, $exitCode);
						if ($exitCode !== 0) {
							return array(
								'error' => 'hg import failed',
								'detail' => implode("\n", $output),
								'vcs' => 'hg',
							);
						}
						$output = array();
						@exec('hg -R ' . escapeshellarg($branchRoot) . ' commit -m ' . escapeshellarg($commitMessage) . ' 2>&1', $output, $exitCode);
						if ($exitCode === 0) {
							$hashOut = array();
							@exec('hg -R ' . escapeshellarg($branchRoot) . ' log -r . --template {node|short} 2>&1', $hashOut, $exitCode);
							$result['commit'] = array(
								'hash' => trim($hashOut[0] ?? ''),
								'message' => $commitMessage,
							);
						}
					} else {
						$output = array();
						@exec('hg -R ' . escapeshellarg($branchRoot) . ' import --no-commit ' . escapeshellarg($patchFile) . ' 2>&1', $output, $exitCode);
						if ($exitCode !== 0) {
							return array(
								'error' => 'hg import failed',
								'detail' => implode("\n", $output),
								'vcs' => 'hg',
							);
						}
					}
					break;

				case 'patch':
					// Use the patch command directly
					$output = array();
					@exec('patch -d ' . escapeshellarg($branchRoot) . ' -p1 < ' . escapeshellarg($patchFile) . ' 2>&1', $output, $exitCode);
					if ($exitCode !== 0) {
						return array(
							'error' => 'patch command failed',
							'detail' => implode("\n", $output),
							'vcs' => 'patch',
						);
					}
					if ($commitMessage) {
						$result['note'] = 'Commit message ignored: no VCS available. Install git or mercurial for commit support.';
					}
					break;
			}
		} finally {
			@unlink($patchFile);
		}

		$result['output'] = implode("\n", $output);
		$previewUrl = 'https://' . $branchName . '.' . $appHost . '/';
		$result['previewUrl'] = $previewUrl;
		$result['summary'] = count($affectedPaths) . ' file(s) patched via ' . $vcs;

		return $result;
	}

	/**
	 * Request a merge of a branch back to trunk.
	 *
	 * Creates a merge request record in the branch metadata and
	 * dispatches notifications to app admins.
	 *
	 * @method apiRequestMerge
	 * @static
	 * @param {array} $params  Keys: appHost (required), branchName (required),
	 *   title (optional), description (optional)
	 * @param {array} $authResult  From authenticateForBranch()
	 * @return {array} Result with mergeRequestId, status
	 */
	static function apiRequestMerge($params, $authResult)
	{
		$appHost = $params['appHost'] ?? '';
		$branchName = $params['branchName'] ?? '';
		$title = $params['title'] ?? "Merge $branchName";
		$description = $params['description'] ?? '';

		if (!$appHost) {
			return array('error' => 'appHost is required');
		}
		if (!$branchName) {
			return array('error' => 'branchName is required');
		}

		// Require at least edit permission
		$branchPerm = $authResult['branchPerm'] ?? 'view';
		if ($branchPerm === 'view') {
			return array('error' => 'Merge requests require edit or admin branch permission');
		}

		// Resolve the branch
		if (!self::$state) self::loadState();
		$key = $appHost . '/' . $branchName;
		$branch = self::$state['branches'][$key] ?? null;
		if (!$branch) {
			return array('error' => "Branch not found: $branchName");
		}

		// Generate the merge request
		$mergeRequestId = 'mr-' . bin2hex(random_bytes(8));
		$mergeRequest = array(
			'id' => $mergeRequestId,
			'branchName' => $branchName,
			'appHost' => $appHost,
			'title' => $title,
			'description' => $description,
			'requestedBy' => $authResult['user'] ?? 'unknown',
			'requestedAt' => gmdate('c'),
			'status' => 'pending',
		);

		// Get the diff summary
		$trunkRoot = Q_Config::get('Q', 'webserver', 'hosts', $appHost, 'root', null);
		if ($trunkRoot && is_dir($trunkRoot) && is_dir($branch['root'])) {
			$diffResult = self::diff($branch['root'], $trunkRoot);
			$mergeRequest['changes'] = array(
				'added' => count($diffResult['added']),
				'changed' => count($diffResult['changed']),
				'removed' => count($diffResult['removed']),
			);
		}

		// Store the merge request in state
		if (!isset(self::$state['branches'][$key]['mergeRequests'])) {
			self::$state['branches'][$key]['mergeRequests'] = array();
		}
		self::$state['branches'][$key]['mergeRequests'][$mergeRequestId] = $mergeRequest;
		self::saveState();

		// Notify admins — dispatch an event if the Platform is loaded
		if (class_exists('Q', false) && method_exists('Q', 'event')) {
			try {
				Q::event('Q/WebServer/Branch/mergeRequest', $mergeRequest);
			} catch (\Exception $e) {
				// Non-fatal — the merge request is recorded even if
				// notification dispatch fails
			}
		}

		return array(
			'mergeRequestId' => $mergeRequestId,
			'status' => 'pending',
			'changes' => $mergeRequest['changes'] ?? null,
		);
	}

	/**
	 * Serve a single-use download created by apiExport.
	 *
	 * @method serveDownload
	 * @static
	 * @param {string} $token  The download token from the export URL
	 * @return {array|null}  ['path' => archivePath, 'name' => fileName] or null
	 */
	static function serveDownload($token)
	{
		if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
			return null;
		}
		$metaFile = sys_get_temp_dir() . '/qbix_download_' . $token . '.meta';
		if (!file_exists($metaFile)) {
			return null;
		}

		$meta = json_decode(file_get_contents($metaFile), true);
		if (!$meta) return null;

		// Check expiration
		if (time() > ($meta['expires'] ?? 0)) {
			@unlink($metaFile);
			@unlink($meta['path'] ?? '');
			return null;
		}

		// Mark as used (single-use)
		if (!empty($meta['used'])) {
			return null;
		}
		$meta['used'] = true;
		file_put_contents($metaFile, json_encode($meta));

		return array(
			'path' => $meta['path'],
			'name' => $meta['name'],
		);
	}

	/**
	 * Write a pending-cert marker file so the parent process knows to
	 * provision a TLS certificate for a branch subdomain.  The parent's
	 * _branchCertCheck timer picks these up every 30 seconds.
	 *
	 * @method requestCertProvision
	 * @static
	 * @param {string} $fqdn  Fully qualified domain name, e.g. "feature.myapp.com"
	 */
	static function requestCertProvision($fqdn)
	{
		$certDir = Q_Config::get('Q', 'webserver', 'tls', 'certDir', 'local/certs');
		$pendingDir = rtrim($certDir, '/') . '/.pending';
		@mkdir($pendingDir, 0700, true);
		// Write the FQDN into a marker file named after the domain
		$markerPath = $pendingDir . '/' . $fqdn;
		file_put_contents($markerPath, $fqdn . "\n" . gmdate('Y-m-d\TH:i:s\Z'));
	}

	/**
	 * API: List branches for an app host.
	 *
	 * @method apiBranchList
	 * @static
	 * @param {array} $params  appHost (required)
	 * @param {array} $authResult  from authenticateForBranch()
	 * @return {array}  branches array or error
	 */
	static function apiBranchList($params, $authResult)
	{
		$appHost = $params['appHost'] ?? '';
		if (!$appHost) {
			return array('error' => 'appHost is required');
		}

		$all = self::listBranches($appHost);
		$branches = array();
		foreach ($all as $key => $rec) {
			$name = substr($key, strlen($appHost) + 1);
			$entry = array(
				'name' => $name,
				'created' => $rec['created'] ?? null,
				'createdBy' => $rec['createdBy'] ?? null,
				'subdomain' => $rec['subdomain'] ?? null,
			);
			// Detect VCS in the branch root
			if (!empty($rec['root']) && is_dir($rec['root'])) {
				if (is_dir($rec['root'] . '/.git')) {
					$entry['vcs'] = 'git';
				} elseif (is_dir($rec['root'] . '/.hg')) {
					$entry['vcs'] = 'hg';
				}
			}
			$branches[] = $entry;
		}

		return array('branches' => $branches);
	}

	/**
	 * API: Create a new branch.
	 *
	 * @method apiBranchCreate
	 * @static
	 * @param {array} $params  appHost, branchName (required), subdomain (optional)
	 * @param {array} $authResult  from authenticateForBranch()
	 * @return {array}  new branch record or error
	 */
	static function apiBranchCreate($params, $authResult)
	{
		$appHost = $params['appHost'] ?? '';
		$branchName = $params['branchName'] ?? '';

		if (!$appHost) {
			return array('error' => 'appHost is required');
		}
		if (!$branchName) {
			return array('error' => 'branchName is required');
		}

		$options = array();
		if (!empty($authResult['username'])) {
			$options['createdBy'] = $authResult['username'];
		}
		if (isset($params['subdomain'])) {
			$options['subdomain'] = $params['subdomain'];
		}

		$result = self::create($appHost, $branchName, $options);
		if (is_string($result)) {
			return array('error' => $result);
		}

		return array(
			'branch' => $branchName,
			'root' => $result['root'] ?? null,
			'subdomain' => $result['subdomain'] ?? null,
			'created' => $result['created'] ?? null,
		);
	}

	/**
	 * API: List files in a branch or trunk directory.
	 *
	 * @method apiFileList
	 * @static
	 * @param {array} $params  appHost (required), branchName (optional), path (optional), recursive (optional)
	 * @param {array} $authResult  from authenticateForBranch()
	 * @return {array}  files array or error
	 */
	static function apiFileList($params, $authResult)
	{
		$appHost = $params['appHost'] ?? '';
		$branchName = $params['branchName'] ?? null;
		$relPath = $params['path'] ?? '';
		$recursive = !empty($params['recursive']);

		if (!$appHost) {
			return array('error' => 'appHost is required');
		}

		// Resolve root
		$root = null;
		if ($branchName) {
			if (!self::$state) self::loadState();
			$key = $appHost . '/' . $branchName;
			$branch = self::$state['branches'][$key] ?? null;
			if (!$branch) {
				return array('error' => "Branch not found: $branchName");
			}
			$root = $branch['root'];
		} else {
			$root = Q_Config::get('Q', 'webserver', 'hosts', $appHost, 'root', null);
			if (!$root) {
				return array('error' => "No root configured for host: $appHost");
			}
		}

		// Sanitize path — prevent traversal
		$relPath = ltrim($relPath, '/');
		if (strpos($relPath, '..') !== false) {
			return array('error' => 'Path traversal not allowed');
		}

		$dir = $root;
		if ($relPath !== '') {
			$dir = $root . '/' . $relPath;
		}

		if (!is_dir($dir)) {
			return array('error' => "Directory not found: $relPath");
		}

		$files = array();
		$maxFiles = 500; // prevent huge listings

		if ($recursive) {
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ($it as $item) {
				if (count($files) >= $maxFiles) break;
				$itemRel = substr($item->getPathname(), strlen($root) + 1);
				// Skip hidden dirs like .git
				if (preg_match('#(^|/)\.#', $itemRel)) continue;
				$files[] = array(
					'path' => $itemRel,
					'type' => $item->isDir() ? 'directory' : 'file',
					'size' => $item->isDir() ? null : $item->getSize(),
					'modified' => date('c', $item->getMTime()),
				);
			}
		} else {
			$entries = @scandir($dir);
			if ($entries === false) {
				return array('error' => "Cannot read directory: $relPath");
			}
			foreach ($entries as $entry) {
				if ($entry === '.' || $entry === '..') continue;
				if ($entry[0] === '.') continue; // skip hidden
				if (count($files) >= $maxFiles) break;
				$fullPath = $dir . '/' . $entry;
				$itemRel = ($relPath !== '' ? $relPath . '/' : '') . $entry;
				$files[] = array(
					'path' => $itemRel,
					'type' => is_dir($fullPath) ? 'directory' : 'file',
					'size' => is_dir($fullPath) ? null : filesize($fullPath),
					'modified' => date('c', filemtime($fullPath)),
				);
			}
		}

		return array('files' => $files);
	}

	/**
	 * API: Read a single file from a branch or trunk.
	 *
	 * @method apiFileRead
	 * @static
	 * @param {array} $params  appHost (required), path (required), branchName (optional), encoding (optional: utf8|base64)
	 * @param {array} $authResult  from authenticateForBranch()
	 * @return {array}  file content or error
	 */
	static function apiFileRead($params, $authResult)
	{
		$appHost = $params['appHost'] ?? '';
		$branchName = $params['branchName'] ?? null;
		$relPath = $params['path'] ?? '';
		$encoding = $params['encoding'] ?? 'utf8';

		if (!$appHost) {
			return array('error' => 'appHost is required');
		}
		if ($relPath === '') {
			return array('error' => 'path is required');
		}

		// Resolve root
		$root = null;
		if ($branchName) {
			if (!self::$state) self::loadState();
			$key = $appHost . '/' . $branchName;
			$branch = self::$state['branches'][$key] ?? null;
			if (!$branch) {
				return array('error' => "Branch not found: $branchName");
			}
			$root = $branch['root'];
		} else {
			$root = Q_Config::get('Q', 'webserver', 'hosts', $appHost, 'root', null);
			if (!$root) {
				return array('error' => "No root configured for host: $appHost");
			}
		}

		// Sanitize path
		$relPath = ltrim($relPath, '/');
		if (strpos($relPath, '..') !== false) {
			return array('error' => 'Path traversal not allowed');
		}

		$fullPath = $root . '/' . $relPath;

		// Block sensitive paths
		$blocked = array('config/', '.env', '.git/', '.hg/', 'local/');
		foreach ($blocked as $prefix) {
			if (strpos($relPath, $prefix) === 0 || $relPath === rtrim($prefix, '/')) {
				return array('error' => "Access denied: $relPath");
			}
		}

		if (!is_file($fullPath)) {
			return array('error' => "File not found: $relPath");
		}

		// Size limit: 2 MB for reads
		$size = filesize($fullPath);
		if ($size > 2 * 1024 * 1024) {
			return array('error' => "File too large to read ($size bytes). Use branch_export for large files.");
		}

		$content = file_get_contents($fullPath);
		if ($content === false) {
			return array('error' => "Cannot read file: $relPath");
		}

		$result = array(
			'path' => $relPath,
			'size' => $size,
			'modified' => date('c', filemtime($fullPath)),
		);

		if ($encoding === 'base64') {
			$result['content'] = base64_encode($content);
			$result['encoding'] = 'base64';
		} else {
			// Check if content is valid UTF-8
			if (mb_check_encoding($content, 'UTF-8')) {
				$result['content'] = $content;
				$result['encoding'] = 'utf8';
			} else {
				// Binary file — return as base64
				$result['content'] = base64_encode($content);
				$result['encoding'] = 'base64';
			}
		}

		return $result;
	}

}
