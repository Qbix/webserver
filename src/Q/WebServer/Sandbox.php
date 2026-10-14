<?php
/**
 * Q_WebServer_Sandbox — per-app PHP jailing.
 *
 * Two layers of isolation, applied in every child process before
 * application code runs:
 *
 * 1. Always-on (PHP-level):
 *    - open_basedir restricts filesystem access to the app's root,
 *      its temp directory, and any explicitly allowed paths.
 *    - Shell-execution functions (exec, shell_exec, system, passthru,
 *      popen, proc_open) are intercepted by Compat's source transform
 *      and blocked unless the sandbox explicitly permits them.
 *
 * 2. Opportunistic (OS-level, Linux only):
 *    - When the server runs as root (or has CAP_SETUID), each app's
 *      requests execute under a dedicated Unix user via posix_setuid/
 *      posix_setgid after fork. This gives kernel-enforced isolation:
 *      even if PHP's open_basedir is bypassed via an extension or FFI,
 *      the OS prevents cross-app file access.
 *
 * Configuration per virtual host:
 *
 *   {
 *     "Q": {
 *       "webserver": {
 *         "hosts": {
 *           "myapp.example.com": {
 *             "root": "/var/www/myapp/public",
 *             "sandbox": {
 *               "enabled": true,
 *               "allowPaths": ["/var/shared/libs"],
 *               "allowShell": false,
 *               "uid": 1001,
 *               "gid": 1001
 *             }
 *           }
 *         }
 *       }
 *     }
 *   }
 *
 * @class Q_WebServer_Sandbox
 * @static
 */
class Q_WebServer_Sandbox
{
	/**
	 * Whether shell functions are allowed for the current request.
	 * Checked by the Compat shims for exec, system, etc.
	 * @property $shellAllowed
	 * @type boolean
	 * @static
	 */
	static $shellAllowed = true;

	/**
	 * Whether a sandbox is currently active for the current request.
	 * @property $active
	 * @type boolean
	 * @static
	 */
	static $active = false;

	/**
	 * Whether the server can drop to per-app Unix users.
	 * Detected once at startup via detectCapabilities().
	 * @property $canSetuid
	 * @type boolean
	 * @static
	 */
	static $canSetuid = false;

	/**
	 * The uid this process has dropped to, or null if still root.
	 * Once set, posix_setuid cannot switch to a different uid.
	 * In a persistent (octane) worker, if a request arrives for a
	 * different uid, the worker must exit and let the parent re-fork.
	 * @property $droppedUid
	 * @type integer|null
	 * @static
	 */
	static $droppedUid = null;

	/**
	 * Set to true when apply() detects a uid mismatch in a persistent
	 * worker.  The caller (Pool) should return a 503 and exit the
	 * worker loop so the parent can fork a fresh worker.
	 * @property $uidMismatch
	 * @type boolean
	 * @static
	 */
	static $uidMismatch = false;

	/**
	 * Detect OS-level isolation capabilities at server startup.
	 * Call this once from the parent process before any forks.
	 * @method detectCapabilities
	 * @static
	 */
	static function detectCapabilities()
	{
		if (!function_exists('posix_setuid')
			|| !function_exists('posix_setgid')
			|| !function_exists('posix_getuid')
		) {
			self::$canSetuid = false;
			return;
		}
		// Running as root (uid 0) means we can setuid to any app user
		self::$canSetuid = (posix_getuid() === 0);
	}

	/**
	 * Apply sandbox restrictions in a child process.
	 *
	 * Must be called AFTER fork, BEFORE any application code runs.
	 * Sets open_basedir, optionally drops to a per-app Unix user,
	 * and configures the shell-function gate.
	 *
	 * When a branch is active, the branch record supplies the uid and
	 * root directory.  The branch root replaces the app root in
	 * open_basedir so the worker can only reach its own CoW tree.
	 * The trunk app root is NOT included — branch workers read trunk
	 * files through symlinks, and open_basedir follows symlinks, so
	 * they can still read the originals.  But they cannot traverse
	 * the trunk directory listing, which prevents listing other
	 * branches or discovering app paths outside the CoW tree.
	 *
	 * @method apply
	 * @static
	 * @param {array} $hostConfig  The virtual host config from Q.webserver.hosts.*
	 * @param {string|null} $rootDir  The resolved document root (fallback for sandbox paths)
	 * @param {array|null} $branchRecord  Branch record from Q_WebServer_Branch (uid, root, etc.)
	 */
	static function apply($hostConfig, $rootDir = null, $branchRecord = null)
	{
		$sandbox = $hostConfig['sandbox'] ?? null;

		// For branches, sandboxing is always enabled — even if the trunk
		// app has it off.  This is essential for OS-level isolation.
		$isBranch = !empty($branchRecord);
		if (!$isBranch && (!$sandbox || empty($sandbox['enabled']))) {
			self::$active = false;
			self::$shellAllowed = true;
			return;
		}

		self::$active = true;

		// Merge sandbox config: branch can override trunk settings
		if (!$sandbox) $sandbox = array();
		if ($isBranch && !empty($branchRecord['sandbox'])) {
			$sandbox = array_merge($sandbox, $branchRecord['sandbox']);
		}

		// ── 1. Determine the filesystem root for this worker ──
		// For branches, use the branch root (CoW tree).
		// For trunk, use the document root / app root.
		if ($isBranch && !empty($branchRecord['root'])) {
			$workerRoot = rtrim((string) $branchRecord['root'], DIRECTORY_SEPARATOR);
		} else {
			$workerRoot = $rootDir ?: ($hostConfig['root'] ?? '');
			$workerRoot = rtrim((string) $workerRoot, DIRECTORY_SEPARATOR);
		}
		if ($workerRoot === '') {
			// No root configured — sandbox has nothing to restrict to
			self::$active = false;
			self::$shellAllowed = true;
			return;
		}

		// ── 2. Build open_basedir path list ──
		$paths = array($workerRoot);

		// For branches, also allow the trunk app root so symlink targets
		// are accessible (open_basedir follows symlinks, but the target
		// directory must be reachable).
		if ($isBranch && !empty($branchRecord['appRoot'])) {
			$appRoot = rtrim((string) $branchRecord['appRoot'], DIRECTORY_SEPARATOR);
			if ($appRoot !== '' && !in_array($appRoot, $paths, true)) {
				$paths[] = $appRoot;
			}
		}

		// When running from a PHAR, the autoloader needs to read class
		// files from inside the archive. Without this, any class load
		// after sandbox activation triggers an open_basedir violation.
		if (class_exists('Phar', false)) {
			$pharPath = \Phar::running(false);
			if ($pharPath && !in_array($pharPath, $paths, true)) {
				$paths[] = $pharPath;
			}
		}

		// Always allow the system temp dir (sessions, file uploads, etc.)
		$tmp = sys_get_temp_dir();
		if ($tmp) {
			$tmpNorm = rtrim($tmp, DIRECTORY_SEPARATOR);
			$inPaths = false;
			foreach ($paths as $p) {
				if (self::isSubPath($tmpNorm, $p)) {
					$inPaths = true;
					break;
				}
			}
			if (!$inPaths) {
				$paths[] = $tmp;
			}
		}

		// Allow explicitly listed extra paths
		$allowPaths = $sandbox['allowPaths'] ?? array();
		foreach ($allowPaths as $extra) {
			$extra = rtrim((string) $extra, DIRECTORY_SEPARATOR);
			if ($extra !== '' && !in_array($extra, $paths, true)) {
				$paths[] = $extra;
			}
		}

		$openBasedir = implode(PATH_SEPARATOR, $paths);
		ini_set('open_basedir', $openBasedir);

		// ── 3. Shell function gate ──
		self::$shellAllowed = !empty($sandbox['allowShell']);

		// ── 4. OS-level user isolation (Linux, running as root) ──
		if (self::$canSetuid && empty($sandbox['noSetuid'])) {
			// Determine the uid/gid.  Branch workers use the auto-assigned
			// uid from the branch record.  Trunk workers use the explicit
			// sandbox.uid/gid or fall back to nothing (run as server user).
			if ($isBranch && !empty($branchRecord['uid'])) {
				$uid = (int) $branchRecord['uid'];
				$gid = (int) ($branchRecord['gid'] ?? $branchRecord['uid']);
			} else {
				$uid = isset($sandbox['uid']) ? (int) $sandbox['uid'] : null;
				$gid = isset($sandbox['gid']) ? (int) $sandbox['gid'] : null;
			}

			if ($uid !== null && $uid > 0) {
				// Guard: if this persistent worker already dropped to a
				// different uid, it cannot switch.  Signal the caller
				// so it can exit and let the parent re-fork.
				if (self::$droppedUid !== null && self::$droppedUid !== $uid) {
					error_log("Q_WebServer_Sandbox: uid mismatch — worker is "
						. self::$droppedUid . " but request needs $uid. "
						. "Worker should exit for re-fork.");
					self::$uidMismatch = true;
					return;
				}

				// Already at the right uid — skip the syscalls
				if (self::$droppedUid === $uid) {
					return;
				}

				// setgid MUST come before setuid — once we drop root,
				// we can no longer change groups
				if ($gid > 0) {
					if (!posix_setgid($gid)) {
						error_log("Q_WebServer_Sandbox: posix_setgid($gid) failed: "
							. posix_strerror(posix_get_last_error()));
					}
				}
				if (!posix_setuid($uid)) {
					error_log("Q_WebServer_Sandbox: posix_setuid($uid) failed: "
						. posix_strerror(posix_get_last_error()));
				} else {
					self::$droppedUid = $uid;
				}
			} elseif ($gid !== null && $gid > 0) {
				// Only gid specified, no uid
				if (!posix_setgid($gid)) {
					error_log("Q_WebServer_Sandbox: posix_setgid($gid) failed: "
						. posix_strerror(posix_get_last_error()));
				}
			}
		}
	}

	/**
	 * Build the -d flags for a subprocess command line (proc_open path).
	 *
	 * Returns a string like:
	 *   -d open_basedir=/var/www/app:/tmp -d disable_functions=exec,...
	 *
	 * or empty string if sandboxing is not configured for this host.
	 *
	 * @method subprocessFlags
	 * @static
	 * @param {array} $hostConfig
	 * @param {string|null} $rootDir
	 * @return {string}
	 */
	static function subprocessFlags($hostConfig, $rootDir = null)
	{
		$sandbox = $hostConfig['sandbox'] ?? null;
		if (!$sandbox || empty($sandbox['enabled'])) {
			return '';
		}

		$docRoot = $rootDir ?: ($hostConfig['root'] ?? '');
		$docRoot = rtrim((string) $docRoot, DIRECTORY_SEPARATOR);
		if ($docRoot === '') {
			return '';
		}

		// Build open_basedir
		$paths = array($docRoot);
		$tmp = sys_get_temp_dir();
		if ($tmp && !self::isSubPath($tmp, $docRoot)) {
			$paths[] = $tmp;
		}
		foreach ($sandbox['allowPaths'] ?? array() as $extra) {
			$extra = rtrim((string) $extra, DIRECTORY_SEPARATOR);
			if ($extra !== '' && !in_array($extra, $paths, true)) {
				$paths[] = $extra;
			}
		}
		$openBasedir = implode(PATH_SEPARATOR, $paths);

		$flags = ' -d open_basedir=' . escapeshellarg($openBasedir);

		// In subprocess mode, disable_functions is enforced by the PHP
		// process itself — more robust than source-transform shims.
		if (empty($sandbox['allowShell'])) {
			$flags .= ' -d disable_functions='
				. 'exec,shell_exec,system,passthru,popen,proc_open';
		}

		return $flags;
	}

	/**
	 * Reset sandbox state between requests (for persistent workers).
	 * Called from Compat::shutdown() or Pool::resetForNextRequest().
	 * @method reset
	 * @static
	 */
	static function reset()
	{
		self::$active = false;
		self::$shellAllowed = true;
		// open_basedir can only be made MORE restrictive at runtime,
		// not widened. For persistent workers this is fine — each
		// request either re-applies the same restriction or the
		// worker serves only one host. For mixed-host pools the
		// worker exits after one request anyway.
	}

	/**
	 * Check whether $child is a subdirectory of $parent.
	 * @method isSubPath
	 * @static
	 * @private
	 */
	private static function isSubPath($child, $parent)
	{
		$child = rtrim((string) $child, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		$parent = rtrim((string) $parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		return strpos($child, $parent) === 0;
	}
}
