<?php
/**
 * @module Q
 */

/**
 * Yii 2 and 3: the Application is created and bootstrapped once — components
 * registered, modules loaded, URL manager compiled. Each worker runs the
 * request through Application::run() or handleRequest().
 *
 * Fork point: after Application::bootstrap().
 * Request-specific: Request component, Session, User identity.
 *
 * Yii 2 uses a global Yii::$app singleton. Yii 3 uses a DI container.
 * Both separate bootstrap from request handling.
 *
 * @class Q_WebServer_Boot_Yii
 */
class Q_WebServer_Boot_Yii extends Q_WebServer_Boot_Adapter
{
	protected $app;
	/** @var bool Whether this is Yii 3 */
	protected $v3 = false;

	function name()
	{
		return 'Yii';
	}

	function detect($root)
	{
		// Yii 2: yii (console entry), config/web.php
		// Yii 3: vendor/yiisoft/yii-runner-http
		$project = $this->findProject($root, 'yii');
		if (!$project) {
			$project = $this->findProject($root, 'composer.json');
		}
		if (!$project || !is_file("$root/index.php")
			|| !is_file("$project/vendor/autoload.php")
		) {
			return false;
		}
		// Check for Yii 2 or Yii 3
		if (is_dir("$project/vendor/yiisoft/yii2")) {
			$this->v3 = false;
		} elseif (is_dir("$project/vendor/yiisoft/yii-runner-http")
			|| is_dir("$project/vendor/yiisoft/app")
		) {
			$this->v3 = true;
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

		if (!$this->v3) {
			// Yii 2: require Yii class and create the application.
			// Standard Composer installs put Yii.php directly in the
			// package root; git-cloned repos keep it under framework/.
			$yiiFile = $this->project . '/vendor/yiisoft/yii2/Yii.php';
			if (!is_file($yiiFile)) {
				$yiiFile = $this->project . '/vendor/yiisoft/yii2/framework/Yii.php';
			}
			if (is_file($yiiFile)) require_once $yiiFile;
			$config = array();
			foreach (array('config/web.php', 'config/main.php') as $c) {
				if (is_file($this->project . '/' . $c)) {
					$config = require $this->project . '/' . $c;
					break;
				}
			}
			$this->app = new \yii\web\Application($config);
			// bootstrap() is called inside the constructor
		}
		// Yii 3 doesn't have a simple boot-then-handle split we can hook
		// from outside; its runner is the entry point. We preload what we
		// can (autoloader, container config) and let handle() run the rest.
	}

	function afterBoot()
	{
		if (!$this->v3 && class_exists('Yii', false) && isset(\Yii::$app)) {
			$db = \Yii::$app->get('db', false);
			if ($db && $db instanceof \yii\db\Connection && $db->getIsActive()) {
				$db->close();
			}
		}
	}

	function afterFork()
	{
		if (!$this->v3 && class_exists('Yii', false) && isset(\Yii::$app)) {
			$db = \Yii::$app->get('db', false);
			if ($db && $db instanceof \yii\db\Connection) {
				$db->open();
			}
		}
	}

	function handle($req)
	{
		if (!$this->v3 && $this->app) {
			// Yii2's Application accumulates state across run() calls
			// (Request component, state property, event handlers).
			// With single-request workers, each fork gets a clean copy
			// of the booted Application, so run() works on fresh state.
			$this->app->run();
		} else {
			include $this->front;
		}
	}

	function defaultRequestsPerWorker()
	{
		// Yii2's Application holds per-request state (Request component,
		// session, user identity) that can't be reliably reset between
		// requests. Use single-request workers: each COW fork from the
		// boot master is ~0.1ms, so the cost is negligible.
		return 1;
	}

	function watchPaths($root)
	{
		$p = $this->project;
		$paths = array("$p/composer.lock", "$p/config");
		foreach (array('config/web.php', 'config/main.php', 'config/params.php',
			'config/db.php', 'config/routes.php', '.env') as $f
		) {
			$paths[] = "$p/$f";
		}
		return $this->existing($paths);
	}
}
