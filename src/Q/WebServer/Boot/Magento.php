<?php
/**
 * @module Q
 */

/**
 * Magento 2 / Adobe Commerce (2.4+): built on a large Symfony-style DI
 * container with compiled interception (generated interceptors / plugins).
 * The application is bootstrapped once — area loaded, DI compiled, modules
 * initialised. Each worker creates a Request and runs it through the front
 * controller.
 *
 * Fork point: after Bootstrap::create() and Application construction.
 * Request-specific: Request, Session, Customer session, Quote.
 *
 * Magento's bootstrap is heavy (1500+ classes, 300+ modules) — the boot
 * master saves 200-500ms per request compared to loading from scratch.
 *
 * @class Q_WebServer_Boot_Magento
 */
class Q_WebServer_Boot_Magento extends Q_WebServer_Boot_Adapter
{
	protected $bootstrap;
	protected $app;

	function name()
	{
		return 'Magento';
	}

	function detect($root)
	{
		// Magento 2: app/etc/env.php + app/bootstrap.php + bin/magento
		$project = $this->findProject($root, 'bin/magento');
		if (!$project || !is_file("$root/index.php")
			|| !is_file("$project/app/bootstrap.php")
			|| !is_file("$project/app/etc/env.php")
			|| !is_file("$project/vendor/autoload.php")
		) {
			return false;
		}
		$this->root = $root;
		$this->project = $project;
		$this->front = "$root/index.php";
		return true;
	}

	function boot($root)
	{
		$this->fakeBootRequest();

		require_once $this->project . '/app/bootstrap.php';
		$this->bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
		$this->app = $this->bootstrap->createApplication(
			'Magento\Framework\App\Http'
		);
	}

	function afterBoot()
	{
		// Magento uses a connection pool through the resource model;
		// close all connections so workers get their own.
		if (class_exists('Magento\Framework\App\ObjectManager', false)) {
			try {
				$om = \Magento\Framework\App\ObjectManager::getInstance();
				$resource = $om->get('Magento\Framework\App\ResourceConnection');
				$resource->closeConnection();
			} catch (\Throwable $e) {}
		}
	}

	function handles($scriptPath, $parsed)
	{
		if (!$this->samePath($scriptPath, $this->front)) return false;
		// Magento admin uses a separate front name; let those through
		// the booted worker since the kernel handles routing
		return true;
	}

	function handle($req)
	{
		if ($this->bootstrap && $this->app) {
			$this->bootstrap->run($this->app);
		} else {
			include $this->front;
		}
	}

	function watchPaths($root)
	{
		$p = $this->project;
		$paths = array("$p/app/etc/env.php", "$p/app/etc/config.php",
			"$p/app/etc/di.xml", "$p/composer.lock",
			"$p/generated/metadata", "$p/var/di", "$p/var/generation");
		foreach (glob("$p/app/etc/*.php") ?: array() as $f) $paths[] = $f;
		return $this->existing($paths);
	}

	function defaultTtl()
	{
		return 60; // Magento config changes are less frequent
	}
}
