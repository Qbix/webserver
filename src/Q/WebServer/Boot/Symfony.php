<?php
/**
 * @module Q
 */

/**
 * Symfony 6 and 7: the kernel is booted once in the master — bundles loaded,
 * container compiled, routes matched. Each worker creates a Request from the
 * superglobals and runs it through HttpKernel::handle().
 *
 * Fork point: after Kernel::boot() and container compilation.
 * Request-specific: Request object, session, security token, profiler.
 *
 * @class Q_WebServer_Boot_Symfony
 */
class Q_WebServer_Boot_Symfony extends Q_WebServer_Boot_Adapter
{
	protected $kernel;

	function name()
	{
		return 'Symfony';
	}

	function detect($root)
	{
		$project = $this->findProject($root, 'symfony.lock');
		if (!$project) {
			$project = $this->findProject($root, 'composer.json');
			if ($project && !is_dir("$project/vendor/symfony/http-kernel")) {
				$project = null;
			}
		}
		if (!$project || !is_file("$root/index.php")
			|| !is_file("$project/vendor/autoload.php")
		) {
			return false;
		}
		// Must have a Symfony Kernel class
		if (!is_file("$project/src/Kernel.php")
			&& !is_file("$project/app/AppKernel.php")
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
		require_once $this->project . '/vendor/autoload.php';

		// Symfony 6/7: src/Kernel.php; Symfony 4/5: same or app/AppKernel.php
		$env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod';
		$debug = (bool) ($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? false);

		if (is_file($this->project . '/src/Kernel.php')) {
			require_once $this->project . '/src/Kernel.php';
			$kernelClass = 'App\\Kernel';
		} elseif (is_file($this->project . '/app/AppKernel.php')) {
			require_once $this->project . '/app/AppKernel.php';
			$kernelClass = 'AppKernel';
		} else {
			throw new \Exception('Symfony kernel class not found');
		}

		$kernel = new $kernelClass($env, $debug);
		$kernel->boot();
		$this->kernel = $kernel;
	}

	function afterBoot()
	{
		$container = $this->kernel->getContainer();
		// Close database connections opened during boot
		if ($container->has('doctrine.dbal.default_connection')) {
			try {
				$container->get('doctrine.dbal.default_connection')->close();
			} catch (\Throwable $e) {}
		}
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
		$p = $this->project;
		$paths = array("$p/.env", "$p/.env.local", "$p/composer.lock",
			"$p/config", "$p/src/Kernel.php",
			"$p/var/cache");
		foreach (glob("$p/config/*.yaml") ?: array() as $f) $paths[] = $f;
		foreach (glob("$p/config/*.php") ?: array() as $f) $paths[] = $f;
		foreach (glob("$p/config/packages/*.yaml") ?: array() as $f) $paths[] = $f;
		foreach (glob("$p/config/routes/*.yaml") ?: array() as $f) $paths[] = $f;
		return $this->existing($paths);
	}
}
