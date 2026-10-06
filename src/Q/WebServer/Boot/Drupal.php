<?php
/**
 * @module Q
 */

/**
 * Drupal 10 and 11: built on Symfony's HttpKernel. The DrupalKernel is booted
 * once — modules discovered, services compiled, route collection built. Each
 * worker creates a Request and runs it through the kernel.
 *
 * Fork point: after DrupalKernel::boot() and container compilation.
 * Request-specific: Request, Session, current user, CSRF token, page cache.
 *
 * Like WordPress, Drupal modules can act on the request during boot (hook_init
 * in older versions), but Drupal 10+ defers most per-request work to event
 * subscribers and middleware, making the boot/handle split clean.
 *
 * @class Q_WebServer_Boot_Drupal
 */
class Q_WebServer_Boot_Drupal extends Q_WebServer_Boot_Adapter
{
	protected $kernel;

	function name()
	{
		return 'Drupal';
	}

	function detect($root)
	{
		// Drupal 10/11: core/lib/Drupal.php + sites/default/settings.php
		if (!is_file("$root/index.php") || !is_file("$root/core/lib/Drupal.php")) {
			return false;
		}
		// settings.php can be in sites/default or above the docroot
		$hasSettings = is_file("$root/sites/default/settings.php")
			|| is_file("$root/sites/default/default.settings.php");
		if (!$hasSettings) return false;
		// Must have autoloader
		if (!is_file("$root/autoload.php")
			&& !is_file("$root/vendor/autoload.php")
		) {
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

		$autoloader = is_file("$root/autoload.php")
			? require_once "$root/autoload.php"
			: require_once "$root/vendor/autoload.php";

		// Drupal bootstrap expects a request object
		$request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();

		$kernel = \Drupal\Core\DrupalKernel::createFromRequest(
			$request,
			$autoloader,
			'prod'
		);
		$kernel->boot();
		$this->kernel = $kernel;
	}

	function afterBoot()
	{
		// Close database connections from the boot phase
		if (class_exists('Drupal', false) && \Drupal::hasContainer()) {
			try {
				$db = \Drupal\Core\Database\Database::getConnection();
				// Drupal wraps PDO; close it so workers get their own
				\Drupal\Core\Database\Database::closeConnection();
			} catch (\Throwable $e) {}
		}
	}

	function afterFork()
	{
		// Workers will reconnect on first query via lazy initialization
	}

	function handles($scriptPath, $parsed)
	{
		if (!$this->samePath($scriptPath, $this->front)) return false;
		// Drupal admin paths should go through the regular pool to be safe,
		// but unlike WordPress, Drupal's admin is just routes handled by the
		// kernel, so booted workers can serve them. Gate only on maintenance.
		$maintenanceFile = $this->root . '/sites/default/settings.php';
		// TODO: check Drupal maintenance mode via state API if needed
		return true;
	}

	function handle($req)
	{
		$request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
		$response = $this->kernel->handle($request);
		$response->send();
		$this->kernel->terminate($request, $response);
	}

	function watchPaths($root)
	{
		$paths = array("$root/sites/default/settings.php",
			"$root/sites/default/services.yml",
			"$root/composer.lock",
			"$root/core", "$root/modules", "$root/themes",
			"$root/sites/default/files/php");
		foreach (glob("$root/modules/*/") ?: array() as $d) $paths[] = $d;
		foreach (glob("$root/modules/*/*.info.yml") ?: array() as $f) $paths[] = $f;
		return $this->existing($paths);
	}

	function defaultTtl()
	{
		return 30;
	}

	function defaultRequestsPerWorker()
	{
		// Drupal's compiled service container, Twig environment, and
		// cache backends hold deep object graphs that can't be reliably
		// snapshot-restored between requests. Use single-request workers:
		// each fork from the booted master is ~0.1ms (COW), so the cost
		// is negligible while avoiding state corruption.
		return 1;
	}
}
