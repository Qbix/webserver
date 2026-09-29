<?php
/**
 * @module Q
 */

/**
 * Joomla 4 and 5: built on a Symfony-style DI container. The application is
 * bootstrapped once — extensions discovered, plugins loaded, container
 * compiled. Each worker executes the dispatch/render cycle.
 *
 * Fork point: after container and extension loading.
 * Request-specific: Input, Session, User, Document.
 *
 * @class Q_WebServer_Boot_Joomla
 */
class Q_WebServer_Boot_Joomla extends Q_WebServer_Boot_Adapter
{
	function name()
	{
		return 'Joomla';
	}

	function detect($root)
	{
		// Joomla has configuration.php (or installation/) and libraries/src/Application
		if (!is_file("$root/index.php")
			|| !is_file("$root/configuration.php")
			|| !is_dir("$root/libraries/src")
		) {
			return false;
		}
		// Confirm it's Joomla, not some other PHP app
		if (!is_dir("$root/administrator") || !is_dir("$root/components")) {
			return false;
		}
		$this->root = $root;
		$this->project = $root;
		$this->front = "$root/index.php";
		return true;
	}

	function boot($root)
	{
		$this->fakeBootRequest();

		// Joomla 4/5 defines constants in defines.php
		if (is_file("$root/defines.php")) {
			require_once "$root/defines.php";
		}
		if (!defined('_JEXEC')) define('_JEXEC', 1);
		if (!defined('JPATH_BASE')) define('JPATH_BASE', $root);

		// Load framework
		if (is_file("$root/includes/defines.php")) {
			require_once "$root/includes/defines.php";
		}
		if (is_file("$root/includes/framework.php")) {
			require_once "$root/includes/framework.php";
		}
	}

	/** Joomla's index.php must run at global scope. */
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
		// Close the DB connection Joomla opens during framework bootstrap
		if (class_exists('Joomla\\CMS\\Factory', false)
			&& method_exists('Joomla\\CMS\\Factory', 'getDbo')
		) {
			try {
				$db = \Joomla\CMS\Factory::getDbo();
				if ($db) $db->disconnect();
			} catch (\Throwable $e) {}
		}
	}

	function watchPaths($root)
	{
		$paths = array("$root/configuration.php", "$root/administrator/components",
			"$root/components", "$root/plugins", "$root/templates",
			"$root/media", "$root/libraries");
		return $this->existing($paths);
	}

	function defaultTtl()
	{
		return 30;
	}
}
