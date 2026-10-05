<?php
/**
 * SQLite adapter for Db_Branch.
 *
 * Forks a database by copying the .sqlite file. No PDO connection
 * needed — SQLite databases are single files.
 *
 * @class Db_Branch_Sqlite
 */
class Db_Branch_Sqlite
{
	/**
	 * Fork an SQLite database file.
	 *
	 * @param string $source  Path to the source .sqlite file
	 * @param string $target  Path where the clone should be written
	 * @param array  $config  (unused for SQLite — kept for interface consistency)
	 * @return array|null  Database info array, or null on failure
	 */
	static function fork($source, $target, $config = array())
	{
		$st = @lstat($source);
		if (!$st || ($st['mode'] & 0100000) === 0) {
			error_log("Db_Branch_Sqlite: source not found: $source");
			return null;
		}

		if (!copy($source, $target)) {
			error_log("Db_Branch_Sqlite: failed to copy: $source -> $target");
			return null;
		}

		return array(
			'adapter' => 'sqlite',
			'name' => $target,
			'sourceFile' => $source,
		);
	}

	/**
	 * Drop an SQLite database (delete the file).
	 *
	 * @param array $dbInfo  Database info (must include 'name' — the file path)
	 */
	static function drop($dbInfo)
	{
		if (!empty($dbInfo['name']) && is_file($dbInfo['name'])) {
			@unlink($dbInfo['name']);
		}
	}
}
