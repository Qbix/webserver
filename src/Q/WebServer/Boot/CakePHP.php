<?php
/**
 * @module Q
 */

/**
 * CakePHP 4 and 5: the Application is created, plugins loaded, middleware
 * built and the DI container compiled once in the master. Each worker creates
 * a ServerRequest and runs it through the Server.
 *
 * Fork point: after Application::bootstrap() and plugin loading.
 * Request-specific: ServerRequest, Session, Authentication identity.
 *
 * @class Q_WebServer_Boot_CakePHP
 */
class Q_WebServer_Boot_CakePHP extends Q_WebServer_Boot_Adapter
{
	protected $app;

	function name()
	{
		return 'CakePHP';
	}

	function detect($root)
	{
		$project = $this->findProject($root, 'config/app.php');
		if (!$project || !is_file("$root/index.php")
			|| !is_file("$project/vendor/autoload.php")
			|| !is_dir("$project/vendor/cakephp/cakephp")
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

		// CakePHP 5: src/Application.php
		if (is_file($this->project . '/src/Application.php')) {
			require_once $this->project . '/src/Application.php';
		}
		if (!defined('CONFIG')) define('CONFIG', $this->project . '/config/');
		if (!defined('ROOT')) define('ROOT', $this->project . '/');
		if (!defined('APP_DIR')) define('APP_DIR', 'src');
		if (!defined('APP')) define('APP', $this->project . '/src/');
		if (!defined('TMP')) define('TMP', $this->project . '/tmp/');
		if (!defined('LOGS')) define('LOGS', $this->project . '/logs/');
		if (!defined('CACHE')) define('CACHE', $this->project . '/tmp/cache/');
		if (!defined('CAKE_CORE_INCLUDE_PATH')) {
			define('CAKE_CORE_INCLUDE_PATH', $this->project . '/vendor/cakephp/cakephp');
		}
		if (!defined('WWW_ROOT')) define('WWW_ROOT', $root . '/');

		// Load CakePHP bootstrap which sets up Configure, error handlers, etc.
		if (is_file($this->project . '/config/bootstrap.php')) {
			require_once $this->project . '/config/bootstrap.php';
		}

		if (class_exists('App\\Application', true)) {
			$this->app = new \App\Application($this->project . '/config');
			$this->app->bootstrap();
		}
	}

	function afterBoot()
	{
		// Close any DB connections opened during bootstrap
		if (class_exists('Cake\\Datasource\\ConnectionManager', false)) {
			try {
				\Cake\Datasource\ConnectionManager::get('default')->disconnect();
			} catch (\Throwable $e) {}
		}
	}

	function handle($req)
	{
		if ($this->app && method_exists($this->app, 'middleware')) {
			$server = new \Cake\Http\Server($this->app);
			$server->emit($server->run());
		} else {
			// Fallback: include the front controller
			include $this->front;
		}
	}

	function watchPaths($root)
	{
		$p = $this->project;
		$paths = array("$p/config/app.php", "$p/config/bootstrap.php",
			"$p/config/routes.php", "$p/composer.lock",
			"$p/config", "$p/plugins");
		foreach (glob("$p/config/*.php") ?: array() as $f) $paths[] = $f;
		return $this->existing($paths);
	}
}
