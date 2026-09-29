<?php
/**
 * @module Q
 */

/**
 * Base class for framework boot adapters. See Q_WebServer_Boot.
 *
 * An adapter splits a framework's front controller in two:
 *   boot()    runs once, in the boot master: everything that does not depend
 *             on the request (autoloader, config, container, providers, hooks)
 *   handle()  runs in a fresh fork for each request: build the request object
 *             from the superglobals, dispatch, send the response
 *
 * Rules every adapter follows:
 *   - Never leave a network connection open in the master. Every worker would
 *     inherit the same socket. afterBoot() closes them; afterFork() lets the
 *     worker open its own.
 *   - handles() says no to any request the booted state would get wrong.
 *     Those requests go to the ordinary pool, which loads the framework from
 *     scratch, so declining is always safe.
 *
 * @class Q_WebServer_Boot_Adapter
 */
abstract class Q_WebServer_Boot_Adapter
{
	/** @var string Document root, without trailing slash */
	protected $root = '';
	/** @var string Project root (usually the parent of the document root) */
	protected $project = '';
	/** @var string Absolute path of the front controller */
	protected $front = '';
	/** @var string Host header used for the boot request */
	protected $host = 'localhost';

	/** Human-readable framework name. */
	abstract function name();

	/**
	 * Whether this document root holds this framework. Sets $root, $project
	 * and $front when it does.
	 * @return {boolean}
	 */
	abstract function detect($root);

	/** Run the request-independent part of the bootstrap. */
	abstract function boot($root);

	/** Handle one request. Superglobals are already set for it. */
	abstract function handle($req);

	/**
	 * Files the boot master includes at global scope, after boot(). Use this
	 * for bootstrap files that define top-level variables other code reads
	 * with `global` (wp-config.php's $table_prefix, plugins' globals).
	 * @return {array}
	 */
	function globalIncludes()
	{
		return array();
	}

	/**
	 * Runs in the boot master after globalIncludes().
	 */
	function booted()
	{
	}

	/**
	 * A PHP file that handles a request at global scope, used instead of
	 * handle() when the framework's request code expects global scope.
	 * @return {string|null}
	 */
	function handleFile()
	{
		return null;
	}

	function setHost($host)
	{
		$this->host = $host ?: 'localhost';
	}

	/**
	 * Close anything in the master that workers must not share.
	 */
	function afterBoot()
	{
	}

	/**
	 * Runs in each worker right after the fork, before it takes a request.
	 */
	function afterFork()
	{
	}

	/**
	 * Whether a booted worker can serve this request. Default: requests
	 * routed to the framework's front controller.
	 * @return {boolean}
	 */
	function handles($scriptPath, $parsed)
	{
		return $this->samePath($scriptPath, $this->front);
	}

	/**
	 * Whether a request (one this adapter declined) changes state the boot
	 * master holds. When it finishes, the master boots again.
	 * @return {boolean}
	 */
	function invalidatedBy($scriptPath, $parsed)
	{
		return false;
	}

	/**
	 * Files whose modification means the booted state is out of date: config,
	 * environment, installed packages. Checked every two seconds by the master;
	 * a change triggers a re-boot. Directories are checked by their own mtime
	 * (entries added or removed), not recursively.
	 * @return {array}
	 */
	function watchPaths($root)
	{
		return array();
	}

	/**
	 * Seconds after which to re-boot regardless, for frameworks whose booted
	 * state includes data that can change in the database. 0 = never.
	 */
	function defaultTtl()
	{
		return 0;
	}

	/**
	 * How many requests each booted worker handles before recycling.
	 * 0 means "use the global Q.webserver.boot.requestsPerWorker config".
	 * Return 1 for frameworks whose internal state can't be reliably
	 * reset between requests (Drupal's compiled container, etc.).
	 * Workers forked from the boot master are cheap (~0.1ms COW fork).
	 */
	function defaultRequestsPerWorker()
	{
		return 0;  // use global config
	}

	// ── Helpers ─────────────────────────────────────────

	/**
	 * Fill $_SERVER with a neutral GET request to / for code that reads it
	 * while booting.
	 */
	protected function fakeBootRequest()
	{
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI'] = '/';
		$_SERVER['QUERY_STRING'] = '';
		$_SERVER['HTTP_HOST'] = $this->host;
		$_SERVER['SERVER_NAME'] = $this->host;
		$_SERVER['SERVER_PORT'] = $_SERVER['SERVER_PORT'] ?? '80';
		$_SERVER['SCRIPT_FILENAME'] = $this->front;
		$_SERVER['SCRIPT_NAME'] = '/' . basename($this->front);
		$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
		$_SERVER['DOCUMENT_ROOT'] = $this->root;
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$_GET = $_POST = $_COOKIE = $_FILES = $_REQUEST = array();
	}

	protected function samePath($a, $b)
	{
		if (!$a || !$b) return false;
		$ra = realpath($a);
		$rb = realpath($b);
		return $ra !== false && $ra === $rb;
	}

	protected function isFile($path)
	{
		return is_file($path);
	}

	/**
	 * Find the project root: the directory above the document root that holds
	 * $marker, or the document root itself.
	 */
	protected function findProject($root, $marker)
	{
		foreach (array($root, dirname($root), dirname(dirname($root))) as $dir) {
			if (file_exists($dir . '/' . $marker)) return $dir;
		}
		return null;
	}

	/** Whether the request carries any cookie whose name starts with a prefix. */
	protected function hasCookie($parsed, $prefixes)
	{
		$cookie = $parsed['headers']['cookie'] ?? '';
		if ($cookie === '') return false;
		foreach (explode(';', $cookie) as $pair) {
			$name = strtolower(trim(explode('=', $pair, 2)[0]));
			foreach ((array) $prefixes as $p) {
				if (strpos($name, $p) === 0) return true;
			}
		}
		return false;
	}

	protected function isSafeMethod($parsed)
	{
		$m = strtoupper($parsed['method'] ?? 'GET');
		return $m === 'GET' || $m === 'HEAD';
	}

	/** Existing files, for watchPaths(). */
	protected function existing($paths)
	{
		return array_values(array_filter($paths, 'file_exists'));
	}
}
