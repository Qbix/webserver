<?php
/**
 * @module Q
 */

/**
 * ownCloud and Nextcloud: both are forks sharing the same architecture.
 * The app framework is bootstrapped once — apps loaded, hooks registered,
 * config read. Each worker handles a request through the front controller.
 *
 * Fork point: after app loading and hook registration.
 * Request-specific: Session, User, CSRF token.
 *
 * @class Q_WebServer_Boot_OwnCloud
 */
class Q_WebServer_Boot_OwnCloud extends Q_WebServer_Boot_Adapter
{
	protected $flavor = 'ownCloud';

	function name()
	{
		return $this->flavor;
	}

	function detect($root)
	{
		if (!is_file("$root/index.php") || !is_file("$root/status.php")) {
			return false;
		}
		// Both have lib/base.php and config/config.php
		if (!is_file("$root/lib/base.php") && !is_dir("$root/lib/private")) {
			return false;
		}
		$configFile = "$root/config/config.php";
		if (!is_file($configFile)) return false;

		// Detect Nextcloud vs ownCloud
		if (is_file("$root/core/css/server.css") || is_dir("$root/core/Command")) {
			$version = '';
			if (is_file("$root/version.php")) {
				$src = (string) @file_get_contents("$root/version.php");
				if (strpos($src, 'Nextcloud') !== false) {
					$this->flavor = 'Nextcloud';
				}
			}
		}

		$this->root = $root;
		$this->project = $root;
		$this->front = "$root/index.php";
		return true;
	}

	function boot($root)
	{
		$this->fakeBootRequest();

		// ownCloud/Nextcloud base bootstrap
		if (is_file("$root/lib/base.php")) {
			if (!defined('OC_LOADED')) {
				// The base.php sets up autoloading, config, and the app framework
				require_once "$root/lib/base.php";
			}
		}
	}

	/** Front controller runs at global scope. */
	function handleFile()
	{
		return $this->front;
	}

	function handle($req)
	{
		include $this->front;
	}

	function afterBoot()
	{
		// Close DB connections from the boot phase
		if (class_exists('OC_DB', false) || class_exists('OC\\DB\\ConnectionFactory', false)) {
			try {
				\OC::$server->getDatabaseConnection()->close();
			} catch (\Throwable $e) {}
		}
	}

	function handles($scriptPath, $parsed)
	{
		if (!$this->samePath($scriptPath, $this->front)) return false;
		// Logged-in users need their own session; gate on cookies
		return !$this->hasCookie($parsed, array('oc_', 'nc_'));
	}

	function watchPaths($root)
	{
		$paths = array("$root/config/config.php", "$root/config",
			"$root/apps", "$root/apps-extra",
			"$root/lib/base.php", "$root/version.php");
		return $this->existing($paths);
	}

	function defaultTtl()
	{
		return 30;
	}
}
