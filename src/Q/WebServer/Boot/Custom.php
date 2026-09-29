<?php
/**
 * @module Q
 */

/**
 * Custom boot adapter: configure via Q.webserver.boot.custom.
 *
 * For any PHP application whose front controller can be split into a
 * bootstrap phase and a request-handling phase. Configure:
 *
 *   "Q": { "webserver": { "boot": {
 *     "adapter": "custom",
 *     "custom": {
 *       "bootstrap": "bootstrap.php",
 *       "front": "index.php"
 *     }
 *   }}}
 *
 * bootstrap.php is included once in the boot master (relative to the
 * document root or its parent). It should load the autoloader, read config,
 * register services — everything that does not depend on the request.
 *
 * front (index.php) is included per request in each worker.
 *
 * @class Q_WebServer_Boot_Custom
 */
class Q_WebServer_Boot_Custom extends Q_WebServer_Boot_Adapter
{
	protected $bootstrapFile;

	function name()
	{
		return 'Custom';
	}

	function detect($root)
	{
		$conf = Q_Config::get('Q', 'webserver', 'boot', 'custom', null);
		if (!$conf || empty($conf['bootstrap'])) return false;

		$bootstrap = $conf['bootstrap'];
		if ($bootstrap[0] !== '/' && $bootstrap[0] !== '\\') {
			// Resolve relative to document root, then its parent
			foreach (array($root, dirname($root)) as $base) {
				$try = "$base/$bootstrap";
				if (is_file($try)) { $bootstrap = $try; break; }
			}
		}
		if (!is_file($bootstrap)) return false;

		$front = $conf['front'] ?? 'index.php';
		if ($front[0] !== '/') $front = "$root/$front";
		if (!is_file($front)) return false;

		$this->root = $root;
		$this->project = dirname($bootstrap);
		$this->front = realpath($front);
		$this->bootstrapFile = realpath($bootstrap);
		return true;
	}

	function boot($root)
	{
		$this->fakeBootRequest();
		require_once $this->bootstrapFile;
	}

	/** The front controller runs at global scope. */
	function handleFile()
	{
		return $this->front;
	}

	function handle($req)
	{
		include $this->front;
	}

	function watchPaths($root)
	{
		return $this->existing(array($this->bootstrapFile, $this->front));
	}
}
