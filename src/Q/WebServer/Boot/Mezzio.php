<?php
/**
 * @module Q
 */

/**
 * Mezzio (formerly Zend Expressive) and Slim 4: PSR-15 middleware pipelines.
 * The application is created, the container built, middleware piped and routes
 * configured once in the master. Each worker creates a ServerRequest and runs
 * it through the pipeline.
 *
 * Fork point: after Application::pipe() / route configuration.
 * Request-specific: ServerRequest, Response, Session.
 *
 * Also handles Slim 4+, which follows the same PSR-15 pattern.
 *
 * @class Q_WebServer_Boot_Mezzio
 */
class Q_WebServer_Boot_Mezzio extends Q_WebServer_Boot_Adapter
{
	protected $app;
	protected $flavor = 'Mezzio'; // or 'Slim'

	function name()
	{
		return $this->flavor;
	}

	function detect($root)
	{
		$project = $this->findProject($root, 'composer.json');
		if (!$project || !is_file("$root/index.php")
			|| !is_file("$project/vendor/autoload.php")
		) {
			return false;
		}
		// Check for Mezzio or Slim
		if (is_dir("$project/vendor/mezzio/mezzio")) {
			$this->flavor = 'Mezzio';
		} elseif (is_dir("$project/vendor/slim/slim")) {
			$this->flavor = 'Slim';
		} else {
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

		if ($this->flavor === 'Mezzio') {
			// Mezzio: config/config.php returns a ConfigProvider merger,
			// config/pipeline.php pipes middleware, config/routes.php adds routes.
			$config = is_file($this->project . '/config/config.php')
				? require $this->project . '/config/config.php'
				: array();
			if (is_array($config) && class_exists('Mezzio\\Application', true)) {
				$container = (new \Laminas\ServiceManager\ServiceManager(
					$config['dependencies'] ?? array()
				));
				$container->setService('config', $config);
				$this->app = $container->get(\Mezzio\Application::class);
				$factory = $container->get(\Mezzio\MiddlewareFactory::class);
				// Run pipeline and route configuration closures
				if (is_file($this->project . '/config/pipeline.php')) {
					(require $this->project . '/config/pipeline.php')($this->app, $factory, $container);
				}
				if (is_file($this->project . '/config/routes.php')) {
					(require $this->project . '/config/routes.php')($this->app, $factory, $container);
				}
			}
		} else {
			// Slim 4: typically app/settings.php, app/dependencies.php,
			// app/middleware.php, app/routes.php
			if (class_exists('Slim\\Factory\\AppFactory', true)) {
				$this->app = \Slim\Factory\AppFactory::create();
				foreach (array('settings', 'dependencies', 'middleware', 'routes') as $f) {
					$file = $this->project . "/app/$f.php";
					if (is_file($file)) {
						$fn = require $file;
						if (is_callable($fn)) $fn($this->app);
					}
				}
			}
		}
	}

	function handle($req)
	{
		if ($this->app) {
			// Use PSR-15 handle() to get a Response, then emit it ourselves.
			// app->run() uses RequestHandlerRunner + SapiEmitter which may
			// conflict with Qbix's capture buffer. handle() is cleaner.
			//
			// Filter $_SERVER: Qbix may set some values to bool which
			// Diactoros rejects when marshalling HTTP headers.
			$server = array_filter($_SERVER, function ($v) {
				return is_string($v) || is_numeric($v);
			});
			$request = \Laminas\Diactoros\ServerRequestFactory::fromGlobals($server);
			$response = $this->app->handle($request);

			http_response_code($response->getStatusCode());
			foreach ($response->getHeaders() as $name => $values) {
				foreach ($values as $value) {
					if (class_exists('Q_WebServer_State', false)) {
						\Q_WebServer_State::header("$name: $value");
					} else {
						header("$name: $value");
					}
				}
			}
			echo $response->getBody();
		} else {
			include $this->front;
		}
	}

	function watchPaths($root)
	{
		$p = $this->project;
		$paths = array("$p/composer.lock", "$p/config");
		foreach (glob("$p/config/*.php") ?: array() as $f) $paths[] = $f;
		foreach (glob("$p/app/*.php") ?: array() as $f) $paths[] = $f;
		return $this->existing($paths);
	}
}
