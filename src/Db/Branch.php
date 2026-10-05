<?php
/**
 * Database forking and credential mapping for branch-based workflows.
 *
 * This base class provides the public API and shared utilities.
 * Engine-specific logic lives in adapter subclasses:
 *
 *   Db_Branch_Mysql    — MySQL / MariaDB (PDO + optional CLI fallback)
 *   Db_Branch_Postgres — PostgreSQL (CREATE DATABASE ... TEMPLATE)
 *   Db_Branch_Sqlite   — SQLite (file copy)
 *
 * Self-contained — depends only on PDO. No Qbix Platform or WebServer
 * dependency. The webserver's Branch class delegates here, but you can
 * also call these methods directly from a Platform plugin, a deployment
 * script, or any PHP environment with PDO.
 *
 * Typical usage:
 *
 *   $dbInfo = Db_Branch::fork('myapp', 'myapp_feature_x', $config, 'mysql');
 *
 *   // Later, when the branch is deleted:
 *   Db_Branch::drop($dbInfo);
 *
 * @class Db_Branch
 */
class Db_Branch
{
	/**
	 * Map of DBMS name to adapter class.
	 * @var array
	 */
	protected static $adapters = array(
		'mysql'    => 'Db_Branch_Mysql',
		'postgres' => 'Db_Branch_Postgres',
		'pgsql'    => 'Db_Branch_Postgres',
		'sqlite'   => 'Db_Branch_Sqlite',
	);

	// ─── Public API ───────────────────────────────────────────────

	/**
	 * Fork (clone) a database.
	 *
	 * Dispatches to the appropriate adapter based on $dbms.
	 * When $zfs is true, uses ZFS snapshot/clone instead of the
	 * adapter's native copy method (future — not yet implemented).
	 *
	 * @param string $source  Source database name (or file path for SQLite)
	 * @param string $target  Target database name (or file path for SQLite)
	 * @param array  $config  Connection config: host, user, password, port.
	 *                        Contents vary by adapter.
	 * @param string $dbms    Database engine: mysql, postgres, pgsql, sqlite
	 * @param bool   $zfs     Use ZFS snapshot path (default false)
	 * @return array|null  Database info array, or null on failure
	 */
	static function fork($source, $target, $config, $dbms, $zfs = false)
	{
		$adapterClass = self::adapter($dbms);
		if (!$adapterClass) {
			error_log("Db_Branch: unsupported dbms: $dbms");
			return null;
		}

		if ($zfs) {
			// Future: ZFS snapshot path
			// Engine-agnostic snapshot/clone logic here, calling
			// $adapterClass::lockForSnapshot() and
			// $adapterClass::unlockAfterSnapshot()
			error_log("Db_Branch: ZFS fork not yet implemented");
			return null;
		}

		return $adapterClass::fork($source, $target, $config);
	}

	/**
	 * Drop a forked database and clean up resources.
	 *
	 * Dispatches to the appropriate adapter based on the 'adapter'
	 * field in $dbInfo.
	 *
	 * @param array $dbInfo  The database info array returned by fork()
	 *                       (must include 'adapter' and 'name')
	 */
	static function drop($dbInfo)
	{
		$dbms = $dbInfo['adapter'] ?? '';
		$adapterClass = self::adapter($dbms);
		if (!$adapterClass) {
			error_log("Db_Branch: cannot drop — unsupported adapter: $dbms");
			return;
		}

		$adapterClass::drop($dbInfo);
	}

	// ─── Credential mapping ───────────────────────────────────────

	/**
	 * Build credential injection keys for a forked database.
	 *
	 * Maps the dbInfo to key names each framework expects, so
	 * {{DB_DATABASE}}, {{DB_HOST}} etc. resolve in .env files,
	 * and framework-specific paths like {{Q.database.main.name}}
	 * resolve in JSON configs.
	 *
	 * @param array       $dbInfo   Return value from fork()
	 * @param string|null $preset   Framework preset name (laravel, qbix, drupal, etc.)
	 * @return array  key => value pairs for credential injection
	 */
	static function credentialKeys($dbInfo, $preset = null)
	{
		$keys = array();
		$name = $dbInfo['name'] ?? '';
		$host = $dbInfo['host'] ?? '';
		$port = $dbInfo['port'] ?? '';
		$user = $dbInfo['user'] ?? '';
		$pass = $dbInfo['password'] ?? '';

		// Common .env-style keys (Laravel, Symfony, CodeIgniter, etc.)
		if ($name) {
			$keys['DB_DATABASE'] = $name;
			$keys['DB_NAME'] = $name;
			$keys['DATABASE_URL'] = self::buildDatabaseUrl($dbInfo);
		}
		if ($host) $keys['DB_HOST'] = $host;
		if ($port) $keys['DB_PORT'] = (string) $port;
		if ($user) {
			$keys['DB_USERNAME'] = $user;
			$keys['DB_USER'] = $user;
		}
		if ($pass) $keys['DB_PASSWORD'] = $pass;

		// Framework-specific config path keys
		switch ($preset) {
			case 'qbix':
				if ($name) $keys['Q.database.main.name'] = $name;
				if ($host) $keys['Q.database.main.host'] = $host;
				if ($port) $keys['Q.database.main.port'] = (string) $port;
				if ($user) $keys['Q.database.main.username'] = $user;
				if ($pass) $keys['Q.database.main.password'] = $pass;
				break;

			case 'drupal':
				if ($name) $keys['databases.default.default.database'] = $name;
				if ($host) $keys['databases.default.default.host'] = $host;
				if ($user) $keys['databases.default.default.username'] = $user;
				if ($pass) $keys['databases.default.default.password'] = $pass;
				break;

			case 'cakephp':
				if ($name) $keys['Datasources.default.database'] = $name;
				if ($host) $keys['Datasources.default.host'] = $host;
				if ($user) $keys['Datasources.default.username'] = $user;
				if ($pass) $keys['Datasources.default.password'] = $pass;
				break;

			case 'yii':
				if ($name || $host) {
					$keys['components.db.dsn'] = self::buildDsn($dbInfo);
				}
				if ($user) $keys['components.db.username'] = $user;
				if ($pass) $keys['components.db.password'] = $pass;
				break;

			case 'codeigniter':
				if ($name) $keys['database.default.database'] = $name;
				if ($host) $keys['database.default.hostname'] = $host;
				if ($user) $keys['database.default.username'] = $user;
				if ($pass) $keys['database.default.password'] = $pass;
				break;
		}

		return $keys;
	}

	// ─── Shared utilities ─────────────────────────────────────────

	/**
	 * Build a DATABASE_URL connection string from dbInfo.
	 * Used by Symfony and other frameworks that expect a single URL.
	 *
	 * @param array $dbInfo
	 * @return string
	 */
	static function buildDatabaseUrl($dbInfo)
	{
		$adapter = $dbInfo['adapter'] ?? 'mysql';
		$scheme = $adapter === 'postgres' ? 'postgresql' : 'mysql';
		$user = rawurlencode($dbInfo['user'] ?? 'root');
		$pass = rawurlencode($dbInfo['password'] ?? '');
		$host = $dbInfo['host'] ?? 'localhost';
		$port = $dbInfo['port'] ?? ($adapter === 'postgres' ? '5432' : '3306');
		$name = $dbInfo['name'] ?? '';
		$auth = $pass !== '' ? "$user:$pass" : $user;
		return "$scheme://$auth@$host:$port/$name";
	}

	/**
	 * Build a PDO-style DSN from dbInfo (for Yii and similar).
	 *
	 * @param array $dbInfo
	 * @return string
	 */
	static function buildDsn($dbInfo)
	{
		$adapter = $dbInfo['adapter'] ?? 'mysql';
		$host = $dbInfo['host'] ?? 'localhost';
		$port = $dbInfo['port'] ?? ($adapter === 'postgres' ? '5432' : '3306');
		$name = $dbInfo['name'] ?? '';
		$driver = $adapter === 'postgres' ? 'pgsql' : 'mysql';
		return "$driver:host=$host;port=$port;dbname=$name";
	}

	// ─── User isolation utilities ─────────────────────────────────

	/**
	 * Generate a random password for a per-branch database user.
	 * Uses cryptographically secure random bytes, base64-encoded.
	 *
	 * @param int $length  Desired password length (default 32)
	 * @return string
	 */
	static function generatePassword($length = 32)
	{
		return substr(
			str_replace(array('+', '/', '='), '', base64_encode(random_bytes($length))),
			0, $length
		);
	}

	/**
	 * Generate a database username from a target database name.
	 * Sanitizes to alphanumeric + underscore, truncated to fit
	 * MySQL's 32-character limit with a short random suffix for
	 * uniqueness.
	 *
	 * @param string $targetDb  Target database name
	 * @return string  Username like "br_myapp_feature_x_a1b2"
	 */
	static function generateUsername($targetDb)
	{
		$base = preg_replace('/[^a-zA-Z0-9_]/', '_', $targetDb);
		$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
		// MySQL <= 5.7 has 32 char limit; 3 for prefix + 6 for suffix + 1 underscore = 10
		$base = substr($base, 0, 22);
		return 'br_' . $base . '_' . $suffix;
	}

	// ─── Orphan cleanup ──────────────────────────────────────────

	/**
	 * Remove orphaned per-branch database users/roles whose databases
	 * no longer exist.
	 *
	 * Branch forking creates a `br_*` user (MySQL) or role (PostgreSQL)
	 * scoped to each branch's database. Under normal operation, `drop()`
	 * removes both. But if the server crashes, or someone manually
	 * deletes branch files without calling `drop()`, the user/role is
	 * left behind. This method finds and removes them.
	 *
	 * Safe to call periodically (e.g. on server startup or a cron job).
	 * Only removes users/roles matching the `br_` prefix whose
	 * granted database does not exist.
	 *
	 * @param array  $config  Connection config: host, user, password, port
	 *                        (must have admin privileges)
	 * @param string $dbms    Database engine: mysql, postgres
	 * @return array  List of removed usernames/roles, or empty array
	 */
	static function cleanupOrphanedUsers($config, $dbms)
	{
		$adapterClass = self::adapter($dbms);
		if (!$adapterClass) {
			error_log("Db_Branch: unsupported dbms for cleanup: $dbms");
			return array();
		}

		if (!method_exists($adapterClass, 'cleanupOrphanedUsers')) {
			return array();
		}

		return $adapterClass::cleanupOrphanedUsers($config);
	}

	// ─── Internal ─────────────────────────────────────────────────

	/**
	 * Resolve a DBMS name to its adapter class.
	 * Autoloads the adapter file if needed.
	 *
	 * @param string $dbms  Database engine name (mysql, postgres, sqlite, etc.)
	 * @return string|null  Adapter class name, or null if unsupported
	 */
	protected static function adapter($dbms)
	{
		$dbms = strtolower($dbms);
		if (!isset(self::$adapters[$dbms])) {
			return null;
		}

		$class = self::$adapters[$dbms];
		if (!class_exists($class, false)) {
			// Try to load the adapter file relative to this file
			$rel = str_replace('_', DIRECTORY_SEPARATOR, $class) . '.php';
			$file = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . $rel;
			if (file_exists($file)) {
				require_once $file;
			}
		}

		return class_exists($class, false) ? $class : null;
	}
}
