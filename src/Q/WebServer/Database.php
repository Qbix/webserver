<?php
/**
 * SQLite auto-provisioning for packed binaries.
 *
 * When the app bundles a .sqlite file at a known location, the server
 * copies it to the data directory on first run and writes the framework
 * config to point at it. The app works out of the box with no external
 * database.
 *
 * Conventional locations for the seed database:
 *   database/database.sqlite   (Laravel)
 *   db/app.sqlite              (generic)
 *   data/db.sqlite             (generic)
 *   *.sqlite in app root       (any single .sqlite file)
 *
 * The file is copied, not moved — the original stays in the zip/phar
 * so a fresh data directory recreates from the seed.
 */
class Q_WebServer_Database
{
	private static $provisioned = false;

	/**
	 * Check for a bundled SQLite database and provision it.
	 * Called once at startup when QBIX_DATA_DIR is set (packed binary).
	 *
	 * @param string $appDir The app's root directory (extracted from zip/phar)
	 * @param string $dataDir The data directory (e.g. myapp.data/)
	 * @param string|null $framework Detected framework name
	 */
	static function provision($appDir, $dataDir, $framework = null)
	{
		if (self::$provisioned) return;
		self::$provisioned = true;

		$dbDest = $dataDir . '/db.sqlite';

		// If the database already exists in the data dir, don't overwrite
		// but still set the env var so the app can find it
		if (is_file($dbDest)) {
			putenv("QBIX_DB_PATH=$dbDest");
			$_ENV['QBIX_DB_PATH'] = $dbDest;
			return;
		}

		// Find the seed database
		$seedPath = self::findSeed($appDir);
		if (!$seedPath) return;

		// Copy the seed to the data directory
		@mkdir(dirname($dbDest), 0755, true);
		copy($seedPath, $dbDest);
		chmod($dbDest, 0660);

		// Set env var so apps can find the provisioned database
		putenv("QBIX_DB_PATH=$dbDest");
		$_ENV['QBIX_DB_PATH'] = $dbDest;

		fwrite(STDERR, "  Database: provisioned from " . basename($seedPath) . "\n");

		// Write framework-specific config pointing at the database
		if ($framework) {
			self::writeConfig($framework, $appDir, $dataDir, $dbDest);
		}
	}

	/**
	 * Find a seed .sqlite file in the app directory.
	 * Checks conventional locations in priority order.
	 */
	static function findSeed($appDir)
	{
		$appDir = rtrim($appDir, '/\\');

		// Framework-specific conventional locations
		$candidates = [
			"$appDir/database/database.sqlite",     // Laravel
			"$appDir/var/data.db",                   // Symfony
			"$appDir/sites/default/files/.sqlite",   // Drupal
			"$appDir/db/app.sqlite",                 // generic
			"$appDir/data/db.sqlite",                // generic
			"$appDir/data/app.db",                   // generic
			"$appDir/local/db.sqlite",               // Qbix
		];

		foreach ($candidates as $path) {
			if (is_file($path)) return $path;
		}

		// Fallback: any single .sqlite or .db file in common directories
		foreach (['', '/data', '/database', '/db', '/local', '/var'] as $sub) {
			$dir = $appDir . $sub;
			if (!is_dir($dir)) continue;
			$found = array_merge(
				glob("$dir/*.sqlite") ?: [],
				glob("$dir/*.db") ?: []
			);
			// Filter out non-SQLite files (e.g. .db could be BerkeleyDB)
			$sqliteFiles = array_filter($found, function($f) {
				if (!is_file($f) || filesize($f) < 16) return false;
				// Check SQLite magic bytes: "SQLite format 3\000"
				$h = file_get_contents($f, false, null, 0, 16);
				return strpos($h, 'SQLite format 3') === 0;
			});
			if (count($sqliteFiles) === 1) return reset($sqliteFiles);
			// If multiple found, prefer the largest (likely the main DB)
			if (count($sqliteFiles) > 1) {
				usort($sqliteFiles, function($a, $b) { return filesize($b) - filesize($a); });
				return $sqliteFiles[0];
			}
		}

		return null;
	}

	/**
	 * Write framework-specific database config pointing at the SQLite file.
	 */
	static function writeConfig($framework, $appDir, $dataDir, $dbPath)
	{
		switch ($framework) {
			case 'laravel':
				self::writeLaravelConfig($appDir, $dbPath);
				break;
			case 'symfony':
				self::writeSymfonyConfig($appDir, $dbPath);
				break;
			case 'drupal':
				self::writeDrupalConfig($appDir, $dbPath);
				break;
			case 'wordpress':
				self::writeWordPressConfig($appDir, $dbPath);
				break;
			case 'craftcms':
				self::writeCraftConfig($appDir, $dbPath);
				break;
			case 'qbix':
				self::writeQbixConfig($appDir, $dataDir, $dbPath);
				break;
		}
	}

	private static function writeLaravelConfig($appDir, $dbPath)
	{
		$envFile = "$appDir/.env";
		$dbPath = realpath($dbPath) ?: $dbPath;
		if (is_file($envFile)) {
			$env = file_get_contents($envFile);
			$env = preg_replace('/^DB_CONNECTION=.*/m', 'DB_CONNECTION=sqlite', $env);
			$env = preg_replace('/^DB_DATABASE=.*/m', "DB_DATABASE=$dbPath", $env);
			if (strpos($env, 'DB_CONNECTION') === false) {
				$env .= "\nDB_CONNECTION=sqlite\nDB_DATABASE=$dbPath\n";
			}
			file_put_contents($envFile, $env);
		} else {
			file_put_contents($envFile,
				"APP_KEY=base64:" . base64_encode(random_bytes(32)) . "\n"
				. "DB_CONNECTION=sqlite\nDB_DATABASE=$dbPath\n"
			);
		}
		fwrite(STDERR, "  Config: wrote Laravel .env → sqlite\n");
	}

	private static function writeSymfonyConfig($appDir, $dbPath)
	{
		$envFile = "$appDir/.env";
		$dbPath = realpath($dbPath) ?: $dbPath;
		$url = "sqlite:///$dbPath";
		if (is_file($envFile)) {
			$env = file_get_contents($envFile);
			$env = preg_replace('/^DATABASE_URL=.*/m', "DATABASE_URL=$url", $env);
			if (strpos($env, 'DATABASE_URL') === false) {
				$env .= "\nDATABASE_URL=$url\n";
			}
			file_put_contents($envFile, $env);
		} else {
			file_put_contents($envFile, "DATABASE_URL=$url\n");
		}
		fwrite(STDERR, "  Config: wrote Symfony .env → sqlite\n");
	}

	private static function writeDrupalConfig($appDir, $dbPath)
	{
		$settingsFile = "$appDir/sites/default/settings.php";
		if (!is_file($settingsFile)) return;
		$dbPath = realpath($dbPath) ?: $dbPath;
		$snippet = "\n\$databases['default']['default'] = [\n"
			. "  'driver' => 'sqlite',\n"
			. "  'database' => '$dbPath',\n"
			. "];\n";
		$existing = file_get_contents($settingsFile);
		if (strpos($existing, "'driver' => 'sqlite'") === false) {
			file_put_contents($settingsFile, $existing . $snippet);
			fwrite(STDERR, "  Config: appended Drupal settings.php → sqlite\n");
		}
	}

	private static function writeWordPressConfig($appDir, $dbPath)
	{
		// WordPress SQLite requires the wp-sqlite-db drop-in plugin
		$dropIn = "$appDir/wp-content/db.php";
		if (is_file($dropIn)) {
			// Plugin already installed — set the path via constant
			$configFile = "$appDir/wp-config.php";
			if (is_file($configFile)) {
				$config = file_get_contents($configFile);
				if (strpos($config, 'DB_DIR') === false) {
					$dbDir = dirname(realpath($dbPath) ?: $dbPath);
					$dbFile = basename($dbPath);
					$insert = "define('DB_DIR', '$dbDir');\ndefine('DB_FILE', '$dbFile');\n";
					$config = preg_replace(
						'/(<\?php)/',
						"$1\n$insert",
						$config, 1
					);
					file_put_contents($configFile, $config);
					fwrite(STDERR, "  Config: wrote WordPress wp-config.php → sqlite (wp-sqlite-db)\n");
				}
			}
		} else {
			fwrite(STDERR, "  Note: WordPress needs wp-sqlite-db plugin for SQLite support\n");
			fwrite(STDERR, "        Install: wp plugin install sqlite-database-integration\n");
		}
	}

	private static function writeCraftConfig($appDir, $dbPath)
	{
		$envFile = "$appDir/.env";
		$dbPath = realpath($dbPath) ?: $dbPath;
		if (is_file($envFile)) {
			$env = file_get_contents($envFile);
			$env = preg_replace('/^CRAFT_DB_DRIVER=.*/m', 'CRAFT_DB_DRIVER=sqlite', $env);
			if (strpos($env, 'CRAFT_DB_DRIVER') === false) {
				$env .= "\nCRAFT_DB_DRIVER=sqlite\nCRAFT_DB_DSN=sqlite:$dbPath\n";
			}
			file_put_contents($envFile, $env);
		}
		fwrite(STDERR, "  Config: wrote Craft .env → sqlite\n");
	}

	private static function writeQbixConfig($appDir, $dataDir, $dbPath)
	{
		$dbPath = realpath($dbPath) ?: $dbPath;

		// Find existing config: local/app.json or config/app.json
		$configFile = null;
		foreach (["$appDir/local/app.json", "$appDir/config/app.json"] as $candidate) {
			if (is_file($candidate)) {
				$configFile = $candidate;
				break;
			}
		}

		// Read existing config or start fresh
		$config = [];
		$originalJson = '';
		if ($configFile) {
			$originalJson = file_get_contents($configFile);
			$config = json_decode($originalJson, true) ?: [];
		} else {
			// No existing config — write to local/app.json
			$configFile = "$appDir/local/app.json";
			@mkdir(dirname($configFile), 0755, true);
		}

		// Set the wildcard connection to SQLite
		if (!isset($config['Db'])) $config['Db'] = [];
		if (!isset($config['Db']['connections'])) $config['Db']['connections'] = [];

		$config['Db']['connections']['*'] = [
			'dsn' => "sqlite:$dbPath",
			'driver_options' => ['3' => 2]
		];

		// Detect installed plugins and ensure each has a connection entry
		// with the correct table prefix (lowercased plugin name + underscore)
		$plugins = self::detectQbixPlugins($appDir);
		foreach ($plugins as $pluginName) {
			if (!isset($config['Db']['connections'][$pluginName])) {
				$prefix = strtolower($pluginName) . '_';
				$config['Db']['connections'][$pluginName] = [
					'prefix' => $prefix,
					'shards' => new \stdClass()  // {} in JSON
				];
			}
		}

		// Detect the indentation style from the original file
		$indent = "\t"; // default to tabs (Qbix convention)
		if ($originalJson && preg_match('/\n(\s+)"/', $originalJson, $m)) {
			$indent = $m[1];
		}

		// Write with matching formatting
		$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		// Fix: json_decode(true) turns {} into [] — convert empty arrays
		// back to objects so they serialize as {} not []
		$json = json_encode(self::fixEmptyArrays($config), $flags);

		// json_encode uses 4 spaces — convert to original indent if it was tabs
		if ($indent === "\t") {
			$json = preg_replace_callback('/^( +)/m', function ($m) {
				return str_repeat("\t", (int)(strlen($m[1]) / 4));
			}, $json);
		}

		file_put_contents($configFile, $json . "\n");
		fwrite(STDERR, "  Config: wrote Qbix $configFile → sqlite");
		if ($plugins) {
			fwrite(STDERR, " (" . implode(', ', $plugins) . ")");
		}
		fwrite(STDERR, "\n");
	}

	/**
	 * Detect installed Qbix plugins by scanning the plugin directories.
	 */
	private static function detectQbixPlugins($appDir)
	{
		$plugins = [];
		// Standard Qbix plugin locations
		$pluginDirs = [
			"$appDir/plugins",
			dirname($appDir) . '/Platform/platform/plugins',  // monorepo layout
		];
		foreach ($pluginDirs as $dir) {
			if (!is_dir($dir)) continue;
			foreach (scandir($dir) as $entry) {
				if ($entry[0] === '.') continue;
				$pluginDir = "$dir/$entry";
				if (!is_dir($pluginDir)) continue;
				// A Qbix plugin has a config/plugin.json
				if (is_file("$pluginDir/config/plugin.json")) {
					$plugins[] = $entry;
				}
			}
		}
		// Also check local/plugins.json or config/plugins.json for the list
		foreach (["$appDir/local/app.json", "$appDir/config/app.json"] as $cf) {
			if (!is_file($cf)) continue;
			$c = json_decode(file_get_contents($cf), true);
			if (!$c) continue;
			// Plugins may be listed under Q.plugins
			$listed = $c['Q']['plugins'] ?? [];
			if (is_array($listed)) {
				foreach ($listed as $p) {
					if (is_string($p) && !in_array($p, $plugins)) {
						$plugins[] = $p;
					}
				}
			}
		}
		return $plugins;
	}

	/**
	 * Get the provisioned database path, or null if not provisioned.
	 */
	static function dbPath()
	{
		if (!defined('QBIX_DATA_DIR')) return null;
		$path = QBIX_DATA_DIR . '/db.sqlite';
		return is_file($path) ? $path : null;
	}

	/**
	 * Recursively convert empty PHP arrays to stdClass objects
	 * so json_encode outputs {} instead of [].
	 * Keys like "shards", "driver_options" that were {} in the
	 * original JSON get turned into [] by json_decode(assoc:true).
	 */
	private static function fixEmptyArrays($data)
	{
		if (!is_array($data)) return $data;
		if (empty($data)) return new \stdClass();
		// Check if it's an associative array (object) or sequential (array)
		$isAssoc = array_keys($data) !== range(0, count($data) - 1);
		if ($isAssoc) {
			$result = new \stdClass();
			foreach ($data as $k => $v) {
				$result->$k = self::fixEmptyArrays($v);
			}
			return $result;
		}
		return array_map([self::class, 'fixEmptyArrays'], $data);
	}
}
