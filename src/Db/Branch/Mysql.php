<?php
/**
 * MySQL / MariaDB adapter for Db_Branch.
 *
 * Handles database forking via two paths:
 *   - PDO (default): portable, no CLI tools required. Clones tables via
 *     CREATE TABLE ... LIKE + INSERT SELECT, then copies views, triggers,
 *     routines, and events.
 *   - CLI fallback: uses mysqldump | mysql. Handles complex schemas
 *     (partitions, generated columns) better. Activated by setting
 *     'useCli' => true in $config.
 *
 * @class Db_Branch_Mysql
 */
class Db_Branch_Mysql
{
	/**
	 * Fork a MySQL/MariaDB database.
	 *
	 * @param string $source  Source database name
	 * @param string $target  Target database name
	 * @param array  $config  Connection config: host, user, password, port.
	 *                        Set 'useCli' => true to prefer mysqldump.
	 * @return array|null  Database info array, or null on failure
	 */
	static function fork($source, $target, $config)
	{
		$host = $config['host'] ?? 'localhost';
		$user = $config['user'] ?? 'root';
		$pass = $config['password'] ?? '';
		$port = $config['port'] ?? '3306';

		// Try CLI path first if configured and available
		if (!empty($config['useCli'])) {
			$result = self::forkCli($source, $target, $host, $user, $pass);
			if ($result !== null) return $result;
			// CLI failed or unavailable — fall through to PDO
		}

		// PDO path: portable, works everywhere PHP runs
		try {
			$pdo = self::connect($config);

			// Create target database
			$pdo->exec("CREATE DATABASE IF NOT EXISTS "
				. self::quoteIdentifier($target)
				. " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

			$src = self::quoteIdentifier($source);
			$tgt = self::quoteIdentifier($target);

			// Disable FK checks for the duration of the copy
			$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

			// 1. Clone tables (structure + data)
			$tables = $pdo->query("SHOW FULL TABLES IN $src WHERE Table_type = 'BASE TABLE'")
				->fetchAll(\PDO::FETCH_NUM);
			foreach ($tables as $row) {
				$table = self::quoteIdentifier($row[0]);
				$pdo->exec("CREATE TABLE $tgt.$table LIKE $src.$table");
				$pdo->exec("INSERT INTO $tgt.$table SELECT * FROM $src.$table");
			}

			// 2. Clone views
			$views = $pdo->query("SHOW FULL TABLES IN $src WHERE Table_type = 'VIEW'")
				->fetchAll(\PDO::FETCH_NUM);
			foreach ($views as $row) {
				$viewName = $row[0];
				$create = $pdo->query("SHOW CREATE VIEW $src."
					. self::quoteIdentifier($viewName))
					->fetch();
				$viewSql = $create['Create View'] ?? '';
				if ($viewSql) {
					// Rewrite DEFINER and database references
					$viewSql = preg_replace(
						'/\bDEFINER\s*=\s*\S+\s*/i', '', $viewSql
					);
					$viewSql = str_replace(
						"$src.", "$tgt.", $viewSql
					);
					$viewSql = preg_replace(
						'/^CREATE\s+VIEW/i', 'CREATE OR REPLACE VIEW', $viewSql
					);
					try {
						$pdo->exec("USE " . $tgt);
						$pdo->exec($viewSql);
					} catch (\PDOException $e) {
						error_log("Db_Branch_Mysql: failed to clone view $viewName: "
							. $e->getMessage());
					}
				}
			}

			// 3. Clone triggers
			$triggers = $pdo->query(
				"SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, "
				. "ACTION_TIMING, ACTION_STATEMENT, ACTION_ORIENTATION "
				. "FROM INFORMATION_SCHEMA.TRIGGERS "
				. "WHERE TRIGGER_SCHEMA = " . $pdo->quote($source)
			)->fetchAll();
			foreach ($triggers as $trig) {
				$trigName = self::quoteIdentifier($trig['TRIGGER_NAME']);
				$tableName = self::quoteIdentifier($trig['EVENT_OBJECT_TABLE']);
				$timing = $trig['ACTION_TIMING'];
				$event = $trig['EVENT_MANIPULATION'];
				$body = $trig['ACTION_STATEMENT'];
				try {
					$pdo->exec("USE " . $tgt);
					$pdo->exec(
						"CREATE TRIGGER $trigName $timing $event "
						. "ON $tableName FOR EACH ROW $body"
					);
				} catch (\PDOException $e) {
					error_log("Db_Branch_Mysql: failed to clone trigger "
						. $trig['TRIGGER_NAME'] . ": " . $e->getMessage());
				}
			}

			// 4. Clone routines (stored procedures and functions)
			foreach (array('PROCEDURE', 'FUNCTION') as $routineType) {
				$routines = $pdo->query(
					"SELECT ROUTINE_NAME FROM INFORMATION_SCHEMA.ROUTINES "
					. "WHERE ROUTINE_SCHEMA = " . $pdo->quote($source)
					. " AND ROUTINE_TYPE = " . $pdo->quote($routineType)
				)->fetchAll();
				foreach ($routines as $r) {
					$rName = $r['ROUTINE_NAME'];
					$showCmd = $routineType === 'PROCEDURE'
						? "SHOW CREATE PROCEDURE" : "SHOW CREATE FUNCTION";
					$create = $pdo->query(
						"$showCmd $src." . self::quoteIdentifier($rName)
					)->fetch();
					$key = $routineType === 'PROCEDURE'
						? 'Create Procedure' : 'Create Function';
					$sql = $create[$key] ?? '';
					if ($sql) {
						$sql = preg_replace(
							'/\bDEFINER\s*=\s*\S+\s*/i', '', $sql
						);
						try {
							$pdo->exec("USE " . $tgt);
							$pdo->exec($sql);
						} catch (\PDOException $e) {
							error_log("Db_Branch_Mysql: failed to clone $routineType $rName: "
								. $e->getMessage());
						}
					}
				}
			}

			// 5. Clone events
			$events = $pdo->query(
				"SELECT EVENT_NAME FROM INFORMATION_SCHEMA.EVENTS "
				. "WHERE EVENT_SCHEMA = " . $pdo->quote($source)
			)->fetchAll();
			foreach ($events as $ev) {
				$evName = $ev['EVENT_NAME'];
				$create = $pdo->query(
					"SHOW CREATE EVENT $src." . self::quoteIdentifier($evName)
				)->fetch();
				$sql = $create['Create Event'] ?? '';
				if ($sql) {
					$sql = preg_replace(
						'/\bDEFINER\s*=\s*\S+\s*/i', '', $sql
					);
					try {
						$pdo->exec("USE " . $tgt);
						$pdo->exec($sql);
					} catch (\PDOException $e) {
						error_log("Db_Branch_Mysql: failed to clone event $evName: "
							. $e->getMessage());
					}
				}
			}

			$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

			// Create per-branch database user with access only to this database
			$branchUser = Db_Branch::generateUsername($target);
			$branchPass = Db_Branch::generatePassword();
			$pdo->exec(
				"CREATE USER " . $pdo->quote($branchUser) . "@'%' "
				. "IDENTIFIED BY " . $pdo->quote($branchPass)
			);
			$pdo->exec(
				"GRANT ALL PRIVILEGES ON " . self::quoteIdentifier($target) . ".* "
				. "TO " . $pdo->quote($branchUser) . "@'%'"
			);
			$pdo->exec("FLUSH PRIVILEGES");

		} catch (\PDOException $e) {
			error_log("Db_Branch_Mysql: fork failed: " . $e->getMessage());
			// Try to clean up the partially created database and user
			try {
				if (isset($branchUser)) {
					$pdo->exec("DROP USER IF EXISTS "
						. $pdo->quote($branchUser) . "@'%'");
				}
				$pdo->exec("DROP DATABASE IF EXISTS " . self::quoteIdentifier($target));
			} catch (\PDOException $ignore) {}
			return null;
		}

		return array(
			'adapter' => 'mysql',
			'name' => $target,
			'host' => $host,
			'port' => $port,
			'user' => $branchUser,
			'password' => $branchPass,
			'sourceDb' => $source,
			'adminUser' => $user,
			'adminPassword' => $pass,
		);
	}

	/**
	 * Fork a MySQL database using CLI tools (mysqldump | mysql).
	 *
	 * Handles complex schemas (partitions, generated columns) better
	 * than the PDO path, but requires mysqldump and mysql on PATH.
	 *
	 * @param string $source  Source database name
	 * @param string $target  Target database name
	 * @param string $host    MySQL host
	 * @param string $user    MySQL username
	 * @param string $pass    MySQL password
	 * @return array|null  Database info or null if CLI unavailable/failed
	 */
	static function forkCli($source, $target, $host, $user, $pass)
	{
		exec('which mysqldump 2>/dev/null', $out, $ret);
		if ($ret !== 0) return null;

		$passArg = $pass !== '' ? '-p' . escapeshellarg($pass) : '';
		$hostArg = escapeshellarg($host);
		$userArg = escapeshellarg($user);
		$sourceArg = escapeshellarg($source);
		$targetArg = escapeshellarg($target);

		// Create target database
		$cmd = "mysql -h $hostArg -u $userArg $passArg -e "
			. escapeshellarg("CREATE DATABASE IF NOT EXISTS $target");
		exec($cmd, $output, $exitCode);
		if ($exitCode !== 0) {
			error_log("Db_Branch_Mysql: failed to create database via CLI: $target");
			return null;
		}

		// Dump and restore
		$cmd = "mysqldump --single-transaction --routines --triggers --events"
			. " -h $hostArg -u $userArg $passArg $sourceArg"
			. " | mysql -h $hostArg -u $userArg $passArg $targetArg";
		exec($cmd, $output, $exitCode);
		if ($exitCode !== 0) {
			error_log("Db_Branch_Mysql: mysqldump failed for $source -> $target");
			return null;
		}

		// Create per-branch database user
		$branchUser = Db_Branch::generateUsername($target);
		$branchPass = Db_Branch::generatePassword();
		$grantCmd = "mysql -h $hostArg -u $userArg $passArg -e "
			. escapeshellarg(
				"CREATE USER '$branchUser'@'%' IDENTIFIED BY '$branchPass'; "
				. "GRANT ALL PRIVILEGES ON " . str_replace('`', '\\`', $target)
				. ".* TO '$branchUser'@'%'; FLUSH PRIVILEGES"
			);
		exec($grantCmd, $output, $exitCode);
		if ($exitCode !== 0) {
			error_log("Db_Branch_Mysql: failed to create branch user via CLI: $branchUser");
			// DB was cloned but user creation failed — fall back to admin credentials
			return array(
				'adapter' => 'mysql',
				'name' => $target,
				'host' => $host,
				'user' => $user,
				'password' => $pass,
				'sourceDb' => $source,
			);
		}

		return array(
			'adapter' => 'mysql',
			'name' => $target,
			'host' => $host,
			'user' => $branchUser,
			'password' => $branchPass,
			'sourceDb' => $source,
			'adminUser' => $user,
			'adminPassword' => $pass,
		);
	}

	/**
	 * Drop a MySQL database and its per-branch user.
	 *
	 * Uses admin credentials (stored as adminUser/adminPassword in
	 * the dbInfo) to connect and drop both the user and database.
	 * Falls back to the branch user credentials if admin credentials
	 * are not present (legacy dbInfo from before user isolation).
	 *
	 * @param array $dbInfo  Database info (must include name, host, user, password)
	 */
	static function drop($dbInfo)
	{
		try {
			// Use admin credentials if available (needed to DROP USER)
			$connectInfo = $dbInfo;
			if (!empty($dbInfo['adminUser'])) {
				$connectInfo['user'] = $dbInfo['adminUser'];
				$connectInfo['password'] = $dbInfo['adminPassword'] ?? '';
			}
			$pdo = self::connect($connectInfo);

			// Drop the per-branch user first (if it's a per-branch user, not the admin)
			$branchUser = $dbInfo['user'] ?? '';
			$adminUser = $dbInfo['adminUser'] ?? '';
			if ($branchUser && $branchUser !== $adminUser) {
				try {
					$pdo->exec("DROP USER IF EXISTS "
						. $pdo->quote($branchUser) . "@'%'");
				} catch (\PDOException $e) {
					error_log("Db_Branch_Mysql: failed to drop user "
						. $branchUser . ": " . $e->getMessage());
				}
			}

			$pdo->exec("DROP DATABASE IF EXISTS "
				. self::quoteIdentifier($dbInfo['name']));
		} catch (\PDOException $e) {
			error_log("Db_Branch_Mysql: failed to drop database "
				. $dbInfo['name'] . ": " . $e->getMessage());
		}
	}

	/**
	 * Create a PDO connection to a MySQL/MariaDB server.
	 *
	 * @param array $dbInfo  Connection info: host, user, password, port
	 * @return \PDO
	 */
	static function connect($dbInfo)
	{
		$host = $dbInfo['host'] ?? 'localhost';
		$port = $dbInfo['port'] ?? '3306';
		$user = $dbInfo['user'] ?? 'root';
		$pass = $dbInfo['password'] ?? '';

		return new \PDO(
			"mysql:host=$host;port=$port;charset=utf8mb4",
			$user, $pass, array(
				\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
				\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
			)
		);
	}

	/**
	 * Remove orphaned per-branch MySQL users whose databases no longer exist.
	 *
	 * Finds all users matching the `br_%` pattern, checks whether
	 * each user's granted database still exists, and drops the user
	 * if the database is gone.
	 *
	 * @param array $config  Connection config: host, user, password, port
	 * @return array  List of removed usernames
	 */
	static function cleanupOrphanedUsers($config)
	{
		$removed = array();

		try {
			$pdo = self::connect($config);

			// Find all br_* users
			$stmt = $pdo->query(
				"SELECT DISTINCT User FROM mysql.user WHERE User LIKE 'br\\_%'"
			);
			$branchUsers = $stmt->fetchAll(\PDO::FETCH_COLUMN);

			if (empty($branchUsers)) return $removed;

			// Get list of existing databases
			$dbStmt = $pdo->query("SHOW DATABASES");
			$existingDbs = array_flip($dbStmt->fetchAll(\PDO::FETCH_COLUMN));

			foreach ($branchUsers as $branchUser) {
				// Check what database this user has grants on
				$grantStmt = $pdo->query(
					"SHOW GRANTS FOR " . $pdo->quote($branchUser) . "@'%'"
				);
				$grants = $grantStmt->fetchAll(\PDO::FETCH_COLUMN);

				$hasLiveDb = false;
				foreach ($grants as $grant) {
					// Extract database name from GRANT ... ON `dbname`.*
					if (preg_match('/ON\s+`([^`]+)`\.\*/', $grant, $m)) {
						if (isset($existingDbs[$m[1]])) {
							$hasLiveDb = true;
							break;
						}
					}
				}

				if (!$hasLiveDb) {
					try {
						$pdo->exec("DROP USER IF EXISTS "
							. $pdo->quote($branchUser) . "@'%'");
						$removed[] = $branchUser;
					} catch (\PDOException $e) {
						error_log("Db_Branch_Mysql: failed to drop orphan user "
							. $branchUser . ": " . $e->getMessage());
					}
				}
			}
		} catch (\PDOException $e) {
			error_log("Db_Branch_Mysql: orphan cleanup failed: "
				. $e->getMessage());
		}

		return $removed;
	}

	/**
	 * Quote a MySQL/MariaDB identifier (database name, table name, etc.)
	 * using backticks.
	 *
	 * @param string $name
	 * @return string
	 */
	static function quoteIdentifier($name)
	{
		return '`' . str_replace('`', '``', $name) . '`';
	}
}
