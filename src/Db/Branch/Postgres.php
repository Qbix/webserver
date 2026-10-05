<?php
/**
 * PostgreSQL adapter for Db_Branch.
 *
 * Forks a database using CREATE DATABASE ... TEMPLATE, which is an
 * atomic filesystem-level copy of the source database's data directory.
 * Requires that no other sessions are connected to the source database
 * during the copy (this adapter terminates them).
 *
 * Uses PDO — no psql CLI required.
 *
 * @class Db_Branch_Postgres
 */
class Db_Branch_Postgres
{
	/**
	 * Fork a PostgreSQL database.
	 *
	 * @param string $source  Source database name
	 * @param string $target  Target database name
	 * @param array  $config  Connection config: host, user, password, port
	 * @return array|null  Database info array, or null on failure
	 */
	static function fork($source, $target, $config)
	{
		$host = $config['host'] ?? 'localhost';
		$user = $config['user'] ?? 'postgres';
		$pass = $config['password'] ?? '';
		$port = $config['port'] ?? '5432';

		try {
			// Connect to the 'postgres' maintenance database, not the source.
			// CREATE DATABASE cannot run inside a transaction, so we use
			// the maintenance database which supports autocommit.
			$pdo = self::connect($config);

			// Terminate other connections to the source database so
			// TEMPLATE can proceed (Postgres requires exclusive access)
			$pdo->exec(
				"SELECT pg_terminate_backend(pid) "
				. "FROM pg_stat_activity "
				. "WHERE datname = " . $pdo->quote($source)
				. " AND pid <> pg_backend_pid()"
			);

			$pdo->exec(
				"CREATE DATABASE " . self::quoteIdentifier($target)
				. " TEMPLATE " . self::quoteIdentifier($source)
			);

			// Create per-branch role with access only to this database
			$branchUser = Db_Branch::generateUsername($target);
			$branchPass = Db_Branch::generatePassword();
			$pdo->exec(
				"CREATE ROLE " . self::quoteIdentifier($branchUser)
				. " WITH LOGIN PASSWORD " . $pdo->quote($branchPass)
			);
			$pdo->exec(
				"GRANT CONNECT ON DATABASE " . self::quoteIdentifier($target)
				. " TO " . self::quoteIdentifier($branchUser)
			);
			// GRANT schema-level privileges (must connect to the target database)
			$targetPdo = new \PDO(
				"pgsql:host=$host;port=$port;dbname=$target",
				$user, $pass,
				array(\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION)
			);
			$targetPdo->exec(
				"GRANT USAGE, CREATE ON SCHEMA public TO "
				. self::quoteIdentifier($branchUser)
			);
			$targetPdo->exec(
				"GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO "
				. self::quoteIdentifier($branchUser)
			);
			$targetPdo->exec(
				"GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO "
				. self::quoteIdentifier($branchUser)
			);
			$targetPdo->exec(
				"ALTER DEFAULT PRIVILEGES IN SCHEMA public "
				. "GRANT ALL PRIVILEGES ON TABLES TO "
				. self::quoteIdentifier($branchUser)
			);
			$targetPdo->exec(
				"ALTER DEFAULT PRIVILEGES IN SCHEMA public "
				. "GRANT ALL PRIVILEGES ON SEQUENCES TO "
				. self::quoteIdentifier($branchUser)
			);
			$targetPdo = null;

			// Revoke CONNECT on the source database (defense in depth)
			$pdo->exec(
				"REVOKE CONNECT ON DATABASE " . self::quoteIdentifier($source)
				. " FROM " . self::quoteIdentifier($branchUser)
			);

		} catch (\PDOException $e) {
			error_log("Db_Branch_Postgres: fork failed: " . $e->getMessage());
			// Clean up on failure
			try {
				if (isset($branchUser)) {
					$pdo->exec("DROP ROLE IF EXISTS "
						. self::quoteIdentifier($branchUser));
				}
				$pdo->exec("DROP DATABASE IF EXISTS "
					. self::quoteIdentifier($target));
			} catch (\PDOException $ignore) {}
			return null;
		}

		return array(
			'adapter' => 'postgres',
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
	 * Drop a PostgreSQL database and its per-branch role.
	 *
	 * Uses admin credentials (stored as adminUser/adminPassword in
	 * the dbInfo) to connect and drop both the role and database.
	 *
	 * @param array $dbInfo  Database info (must include name, host, user, password)
	 */
	static function drop($dbInfo)
	{
		try {
			// Use admin credentials if available
			$connectInfo = $dbInfo;
			if (!empty($dbInfo['adminUser'])) {
				$connectInfo['user'] = $dbInfo['adminUser'];
				$connectInfo['password'] = $dbInfo['adminPassword'] ?? '';
			}
			$pdo = self::connect($connectInfo);

			// Terminate connections first
			$pdo->exec(
				"SELECT pg_terminate_backend(pid) "
				. "FROM pg_stat_activity "
				. "WHERE datname = " . $pdo->quote($dbInfo['name'])
				. " AND pid <> pg_backend_pid()"
			);
			$pdo->exec("DROP DATABASE IF EXISTS "
				. self::quoteIdentifier($dbInfo['name']));

			// Drop the per-branch role (if it's a per-branch role, not the admin)
			$branchUser = $dbInfo['user'] ?? '';
			$adminUser = $dbInfo['adminUser'] ?? '';
			if ($branchUser && $branchUser !== $adminUser) {
				try {
					$pdo->exec("DROP ROLE IF EXISTS "
						. self::quoteIdentifier($branchUser));
				} catch (\PDOException $e) {
					error_log("Db_Branch_Postgres: failed to drop role "
						. $branchUser . ": " . $e->getMessage());
				}
			}
		} catch (\PDOException $e) {
			error_log("Db_Branch_Postgres: failed to drop database "
				. $dbInfo['name'] . ": " . $e->getMessage());
		}
	}

	/**
	 * Create a PDO connection to a PostgreSQL server.
	 * Connects to the 'postgres' maintenance database.
	 *
	 * @param array $dbInfo  Connection info: host, user, password, port
	 * @return \PDO
	 */
	static function connect($dbInfo)
	{
		$host = $dbInfo['host'] ?? 'localhost';
		$port = $dbInfo['port'] ?? '5432';
		$user = $dbInfo['user'] ?? 'postgres';
		$pass = $dbInfo['password'] ?? '';

		return new \PDO(
			"pgsql:host=$host;port=$port;dbname=postgres",
			$user, $pass, array(
				\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
			)
		);
	}

	/**
	 * Remove orphaned per-branch PostgreSQL roles whose databases
	 * no longer exist.
	 *
	 * Finds all roles matching the `br_%` pattern, checks whether
	 * each role's database still exists, and drops the role if the
	 * database is gone.
	 *
	 * @param array $config  Connection config: host, user, password, port
	 * @return array  List of removed role names
	 */
	static function cleanupOrphanedUsers($config)
	{
		$removed = array();

		try {
			$pdo = self::connect($config);

			// Find all br_* roles that can login
			$stmt = $pdo->query(
				"SELECT rolname FROM pg_roles "
				. "WHERE rolname LIKE 'br\\_%' AND rolcanlogin = true"
			);
			$branchRoles = $stmt->fetchAll(\PDO::FETCH_COLUMN);

			if (empty($branchRoles)) return $removed;

			// Get list of existing databases
			$dbStmt = $pdo->query(
				"SELECT datname FROM pg_database WHERE datistemplate = false"
			);
			$existingDbs = array_flip($dbStmt->fetchAll(\PDO::FETCH_COLUMN));

			foreach ($branchRoles as $roleName) {
				// Check whether this role has an explicit CONNECT grant
				// on any existing non-template database. We must check
				// datacl (the ACL array) directly rather than using
				// has_database_privilege(), because the latter includes
				// inherited grants from the public role — which means
				// every role appears to have CONNECT on every database
				// with default permissions.
				//
				// datacl format: {rolename=privs/grantor,...}
				// 'c' = CONNECT privilege
				$aclStmt = $pdo->prepare(
					"SELECT datname FROM pg_database "
					. "WHERE datistemplate = false "
					. "AND datname <> 'postgres' "
					. "AND datacl::text LIKE '%' || :role || '=%'"
				);
				$aclStmt->execute(array(':role' => $roleName));
				$connectedDbs = $aclStmt->fetchAll(\PDO::FETCH_COLUMN);

				$hasLiveDb = false;
				foreach ($connectedDbs as $db) {
					if (isset($existingDbs[$db])) {
						$hasLiveDb = true;
						break;
					}
				}

				if (!$hasLiveDb) {
					try {
						$pdo->exec("DROP ROLE IF EXISTS "
							. self::quoteIdentifier($roleName));
						$removed[] = $roleName;
					} catch (\PDOException $e) {
						error_log("Db_Branch_Postgres: failed to drop orphan role "
							. $roleName . ": " . $e->getMessage());
					}
				}
			}
		} catch (\PDOException $e) {
			error_log("Db_Branch_Postgres: orphan cleanup failed: "
				. $e->getMessage());
		}

		return $removed;
	}

	/**
	 * Quote a PostgreSQL identifier using double quotes.
	 *
	 * @param string $name
	 * @return string
	 */
	static function quoteIdentifier($name)
	{
		return '"' . str_replace('"', '""', $name) . '"';
	}
}
