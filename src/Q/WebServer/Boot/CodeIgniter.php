<?php
/**
 * @module Q
 */

/**
 * CodeIgniter 4 (4.5+): the framework's services, autoloader, helpers and
 * exception handler are bootstrapped once in the boot master.  Each worker
 * creates a fresh CodeIgniter application instance and runs it.
 *
 * CI4 accumulates static state in Services, Events, Factories and the
 * Autoloader, so each worker handles exactly one request (COW fork, ~0.1ms).
 *
 * Fork point: after autoloader + service registration.
 * Request-specific: CodeIgniter app, IncomingRequest, Response.
 *
 * @class Q_WebServer_Boot_CodeIgniter
 */
class Q_WebServer_Boot_CodeIgniter extends Q_WebServer_Boot_Adapter
{
	function name()
	{
		return 'CodeIgniter';
	}

	function detect($root)
	{
		// CI4 has spark (CLI tool) and app/Config/App.php
		$project = $this->findProject($root, 'spark');
		if (!$project || !is_file("$root/index.php")
			|| !is_file("$project/vendor/autoload.php")
			|| !is_dir("$project/vendor/codeigniter4/framework")
			|| !is_file("$project/app/Config/App.php")
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

		$p = $this->project;

		// Pre-define is_cli() to return false BEFORE CI4 loads Common.php.
		// CI4's is_cli() checks PHP_SAPI which is 'cli' in our server,
		// causing it to route as console instead of HTTP. We can't change
		// the PHP_SAPI constant, so we define the function first.
		if (!function_exists('is_cli')) {
			function is_cli(): bool {
				return false;
			}
		}

		// ── Path constants (same as Boot::definePathConstants) ──
		if (!defined('FCPATH'))     define('FCPATH', $root . '/');
		if (!defined('APPPATH'))    define('APPPATH', realpath("$p/app") . DIRECTORY_SEPARATOR);
		if (!defined('ROOTPATH'))   define('ROOTPATH', realpath(APPPATH . '../') . DIRECTORY_SEPARATOR);
		if (!defined('WRITEPATH'))  define('WRITEPATH', realpath("$p/writable") . DIRECTORY_SEPARATOR);
		if (!defined('TESTPATH'))   define('TESTPATH', "$p/tests" . DIRECTORY_SEPARATOR);
		if (!defined('SYSTEMPATH')) {
			$sys = "$p/vendor/codeigniter4/framework/system";
			if (!is_dir($sys)) $sys = "$p/system";
			define('SYSTEMPATH', realpath($sys) . DIRECTORY_SEPARATOR);
		}

		// ── Constants (APP_NAMESPACE, etc.) ──
		if (!defined('APP_NAMESPACE')) {
			require_once APPPATH . 'Config/Constants.php';
		}

		// ── Composer autoloader ──
		require_once "$p/vendor/autoload.php";

		// ── DotEnv ──
		require_once SYSTEMPATH . 'Config/DotEnv.php';
		$envDir = is_dir("$p/env") ? "$p/env" : "$p/";
		(new \CodeIgniter\Config\DotEnv($envDir))->load();

		// ── Environment ──
		if (!defined('ENVIRONMENT')) {
			$env = $_ENV['CI_ENVIRONMENT'] ?? $_SERVER['CI_ENVIRONMENT']
				?? getenv('CI_ENVIRONMENT') ?: 'production';
			define('ENVIRONMENT', $env);
		}

		// ── Environment bootstrap (sets CI_DEBUG, error_reporting, etc.) ──
		$bootFile = APPPATH . 'Config/Boot/' . ENVIRONMENT . '.php';
		if (is_file($bootFile)) {
			require_once $bootFile;
		}
		// Fallback if boot file didn't define it
		if (!defined('CI_DEBUG')) define('CI_DEBUG', false);

		// ── Common functions ──
		if (is_file(APPPATH . 'Common.php')) {
			require_once APPPATH . 'Common.php';
		}
		require_once SYSTEMPATH . 'Common.php';

		// ── Autoloader + Services ──
		if (!class_exists('Config\\Autoload', false)) {
			require_once SYSTEMPATH . 'Config/AutoloadConfig.php';
			require_once APPPATH . 'Config/Autoload.php';
			require_once SYSTEMPATH . 'Modules/Modules.php';
			require_once APPPATH . 'Config/Modules.php';
		}
		require_once SYSTEMPATH . 'Autoloader/Autoloader.php';
		require_once SYSTEMPATH . 'Config/BaseService.php';
		require_once SYSTEMPATH . 'Config/Services.php';
		require_once APPPATH . 'Config/Services.php';

		\Config\Services::autoloader()
			->initialize(new \Config\Autoload(), new \Config\Modules())
			->register();

		// ── Exception handler ──
		\Config\Services::exceptions()->initialize();

		// ── Kint (debug = false in production) ──
		\Config\Services::autoloader()->initializeKint(CI_DEBUG);

		// ── Helpers ──
		\Config\Services::autoloader()->loadHelpers();
	}

	function afterBoot()
	{
		// Close any DB connections opened during boot
		try {
			$db = \Config\Database::connect();
			if (method_exists($db, 'close')) {
				$db->close();
			}
		} catch (\Throwable $e) {
			// no DB configured — fine
		}
	}

	function handle($req)
	{
		// Remove CI4's pre_system listener that does
		// `while (ob_get_level() > 0) ob_end_flush()` —
		// that loop spins forever on Qbix's non-removable buffer.
		\CodeIgniter\Events\Events::removeAllListeners('pre_system');

		$app = \Config\Services::codeigniter();
		$app->initialize();
		$app->setContext('web');

		// Let CI4 output directly into Qbix's capture buffer.
		// With returnResponse=false (default), CI4 sends the response
		// body to stdout, which Qbix's output buffer captures.
		$app->run();
	}

	/**
	 * CI4 is stateful: Services statics, Events listeners, Factories cache,
	 * output buffering — all accumulate across requests. Use single-request
	 * workers (one fork per request, ~0.1ms COW overhead).
	 */
	function defaultRequestsPerWorker()
	{
		return 1;
	}

	function watchPaths($root)
	{
		$p = $this->project;
		$paths = array("$p/.env", "$p/composer.lock",
			"$p/app/Config", "$p/app/Config/App.php",
			"$p/app/Config/Routes.php", "$p/app/Config/Autoload.php");
		foreach (glob("$p/app/Config/*.php") ?: array() as $f) $paths[] = $f;
		return $this->existing($paths);
	}
}
