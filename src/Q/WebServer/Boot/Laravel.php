<?php
/**
 * @module Q
 */

/**
 * Laravel 10, 11 and 12: the application is created and bootstrapped once in
 * the boot master — environment, configuration, facades, every service
 * provider registered and booted. That is the work Octane exists to avoid
 * repeating. Each worker only captures the request and runs it through the
 * HTTP kernel's middleware and router.
 *
 * Unlike Octane, nothing needs flushing between requests: each request runs
 * in its own fork of the booted application and is thrown away afterwards,
 * so singletons, static properties and container bindings cannot leak from
 * one request into the next.
 *
 * Every request can be booted: the session, authentication and the request
 * itself are all resolved per request by middleware.
 *
 * @class Q_WebServer_Boot_Laravel
 */
class Q_WebServer_Boot_Laravel extends Q_WebServer_Boot_Adapter
{
	protected $app;
	protected $kernel;

	function name()
	{
		return 'Laravel';
	}

	function detect($root)
	{
		$project = $this->findProject($root, 'artisan');
		if (!$project || !is_file("$project/bootstrap/app.php")
			|| !is_file("$project/vendor/autoload.php") || !is_file("$root/index.php")
			|| !is_dir("$project/vendor/laravel/framework")
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
		if (!defined('LARAVEL_START')) define('LARAVEL_START', microtime(true));
		if (is_file($this->project . '/storage/framework/maintenance.php')) {
			// In maintenance mode every request must check the file itself
			throw new Exception('application is in maintenance mode');
		}
		require_once $this->project . '/vendor/autoload.php';
		$app = require $this->project . '/bootstrap/app.php';
		$kernel = $app->make('Illuminate\Contracts\Http\Kernel');
		// Some providers ask for the request while booting (URL generation,
		// trusted proxies). Give them a neutral one; each worker binds the
		// real request before handling it.
		$app->instance('request', \Illuminate\Http\Request::create('http://' . $this->host . '/'));
		$kernel->bootstrap();
		$this->app = $app;
		$this->kernel = $kernel;
	}

	function afterBoot()
	{
		$app = $this->app;
		// Connections opened while booting would be shared by every worker
		if ($app->resolved('db')) {
			foreach (array_keys($app['db']->getConnections()) as $name) {
				$app['db']->purge($name);
			}
		}
		if ($app->resolved('redis') && method_exists($app['redis'], 'connections')) {
			foreach (array_keys((array) $app['redis']->connections()) as $name) {
				$app['redis']->purge($name);
			}
		}
		// Drop the neutral boot request so nothing resolved later sees it
		$app->forgetInstance('request');
	}

	function handles($scriptPath, $parsed)
	{
		if (!$this->samePath($scriptPath, $this->front)) return false;
		// Maintenance mode was switched on after boot
		return !is_file($this->project . '/storage/framework/maintenance.php');
	}

	function handle($req)
	{
		$request = \Illuminate\Http\Request::capture();
		$response = $this->kernel->handle($request);
		$response->send();
		$this->kernel->terminate($request, $response);
	}

	function watchPaths($root)
	{
		$p = $this->project;
		$paths = array("$p/.env", "$p/composer.lock", "$p/vendor/composer/installed.json",
			"$p/bootstrap/app.php", "$p/bootstrap/providers.php", "$p/bootstrap/cache",
			"$p/bootstrap/cache/config.php", "$p/bootstrap/cache/packages.php",
			"$p/bootstrap/cache/services.php", "$p/bootstrap/cache/events.php",
			"$p/config", "$p/routes", "$p/storage/framework");
		foreach (glob("$p/bootstrap/cache/routes*.php") ?: array() as $f) $paths[] = $f;
		foreach (glob("$p/config/*.php") ?: array() as $f) $paths[] = $f;
		foreach (glob("$p/routes/*.php") ?: array() as $f) $paths[] = $f;
		foreach (glob("$p/app/Providers/*.php") ?: array() as $f) $paths[] = $f;
		return $this->existing($paths);
	}
}
