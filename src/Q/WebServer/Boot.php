<?php
/**
 * @module Q
 */

/**
 * Framework boot master: run a framework's bootstrap once, then serve each
 * request from a fresh fork of the booted process.
 *
 * php-fpm and a plain fork-per-request server both rebuild the framework's
 * runtime state on every request: Laravel's container and providers,
 * Symfony's kernel, WordPress's hooks and options. OPcache shares compiled
 * code between workers but not that state. Here the state is built once, in
 * a boot master, and every worker is a copy-on-write fork of it, so the work
 * is not repeated and the memory is shared.
 *
 * Each booted worker serves exactly one request and exits. That keeps the
 * isolation guarantee: whatever a request changes in the framework's state
 * disappears with its process, so the next request starts from the pristine
 * booted copy. This is the difference from persistent-worker servers
 * (Octane, FrankenPHP worker mode, RoadRunner), where requests share one
 * mutable copy and state leaks are the application's problem.
 *
 *   Server (event loop)
 *     ├── Pool workers ............ normal requests (front controller included per request)
 *     └── Boot master ............. framework booted once; forks spares
 *           ├── booted worker ─┐
 *           ├── booted worker ─┼─ connected back to the server over a unix
 *           └── booted worker ─┘  socket, each waiting for one request
 *
 * The boot master is a separate process, not the server itself, so that
 * requests a booted copy cannot serve correctly (WordPress admin screens,
 * logged-in users, POSTs that plugins handle during 'init') still go to the
 * ordinary pool and load the framework from scratch. Each adapter decides
 * which requests it can take.
 *
 * Config (all under Q.webserver.boot):
 *   skip         (bool,   default false) turn booting off
 *   adapter      (string, default: preset name, else autodetect)
 *   workers      (int,    default: pool size) idle booted workers to keep ready
 *   ttl          (int,    default: adapter's) seconds before re-booting to pick up
 *                state changed elsewhere (0 = only when watched files change)
 *   host         (string, default 'localhost') Host header for the boot request
 *   skipHotFiles (bool,   default false) disable hot-file learning (workers
 *                recording which files they load for compile-ahead on next boot)
 *
 * @class Q_WebServer_Boot
 */
class Q_WebServer_Boot
{
	/** @var Q_WebServer_Boot_Adapter|null */
	static $adapter = null;
	static $root = '';
	static $workers = 0;
	static $socketPath = '';
	static $server = null;
	static $serverWatcher = null;
	static $masterPid = 0;
	static $masterStarted = 0.0;
	static $failures = 0;
	static $disabled = false;
	static $ready = false;
	static $idle = array();
	static $busy = array();
	static $pending = array();
	static $nextId = 0;
	static $stats = array('booted' => 0, 'fallback' => 0, 'reboots' => 0);
	static $serverPid = 0;

	/** Exit code a boot master uses to ask for a re-boot (state went stale). */
	const EXIT_STALE = 75;
	/** Exit code for a failed boot. */
	const EXIT_BOOT_FAILED = 70;

	/** Built-in adapters: preset / adapter name => class suffix. */
	static $adapters = array(
		'laravel'    => 'Laravel',
		'symfony'    => 'Symfony',
		'drupal'     => 'Drupal',
		'wordpress'  => 'WordPress',
		'joomla'     => 'Joomla',
		'magento'    => 'Magento',
		'cakephp'    => 'CakePHP',
		'codeigniter'=> 'CodeIgniter',
		'yii'        => 'Yii',
		'slim'       => 'Mezzio',
		'mezzio'     => 'Mezzio',
		'owncloud'   => 'OwnCloud',
		'nextcloud'  => 'OwnCloud',
		'custom'     => 'Custom',
	);

	// ── Parent (server) side ────────────────────────────

	/**
	 * Resolve the adapter and start the boot master.
	 * @method start
	 * @static
	 * @param {string} $rootDir Document root
	 * @param {integer} $poolSize Size of the ordinary worker pool
	 * @return {boolean} Whether booting started
	 */
	static function start($rootDir, $poolSize)
	{
		if (!function_exists('pcntl_fork') || !function_exists('posix_getppid')
		) {
			return false;
		}
		$adapter = self::resolveAdapter($rootDir);
		if (!$adapter) return false;

		self::$adapter = $adapter;
		self::$serverPid = getmypid();
		self::$root = rtrim($rootDir, '/\\') . DIRECTORY_SEPARATOR;
		self::$workers = max(1, (int) Q_Config::get('Q', 'webserver', 'boot', 'workers',
			max(4, (int) $poolSize)));

		$dir = rtrim(sys_get_temp_dir(), '/');
		self::$socketPath = $dir . '/qbix-boot-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sock';
		@unlink(self::$socketPath);
		$server = @stream_socket_server('unix://' . self::$socketPath, $errno, $errstr);
		if (!$server) {
			fwrite(STDERR, "  Boot: could not listen on " . self::$socketPath . ": $errstr\n");
			return false;
		}
		@chmod(self::$socketPath, 0600);
		stream_set_blocking($server, false);
		self::$server = $server;
		self::$serverWatcher = Q_Evented::onReadable($server, function () {
			Q_WebServer_Boot::acceptWorkers();
		});

		self::spawnMaster();
		Q_Evented::repeat(0.25, function () {
			Q_WebServer_Boot::checkMaster();
		});
		register_shutdown_function(function () {
			Q_WebServer_Boot::shutdown();
		});
		fwrite(STDERR, "  Boot:       " . $adapter->name() . " (" . self::$workers . " booted workers)\n");
		return true;
	}

	/**
	 * Pick the adapter: explicit config, then preset, then autodetect.
	 * @method resolveAdapter
	 * @static
	 * @return {Q_WebServer_Boot_Adapter|null}
	 */
	static function resolveAdapter($rootDir)
	{
		require_once __DIR__ . '/Boot/Adapter.php';
		$name = Q_Config::get('Q', 'webserver', 'boot', 'adapter', null)
			?: Q_Config::get('Q', 'compat', 'preset', null);
		$root = rtrim($rootDir, '/\\');
		if ($name) {
			$name = strtolower($name);
			if (!isset(self::$adapters[$name])) return null;
			$a = self::load(self::$adapters[$name]);
			return ($a && $a->detect($root)) ? $a : null;
		}
		if (Q_Config::get('Q', 'webserver', 'boot', 'skipAutodetect', false)) {
			return null;
		}
		foreach (array_unique(self::$adapters) as $suffix) {
			if ($suffix === 'Custom') continue;
			$a = self::load($suffix);
			if ($a && $a->detect($root)) return $a;
		}
		return null;
	}

	protected static function load($suffix)
	{
		$file = __DIR__ . '/Boot/' . $suffix . '.php';
		if (!is_file($file)) return null;
		require_once $file;
		$class = 'Q_WebServer_Boot_' . $suffix;
		return class_exists($class, false) ? new $class() : null;
	}

	/**
	 * Hand a request to a booted worker if the adapter accepts it.
	 * @method tryDispatch
	 * @static
	 * @return {boolean} false to let the ordinary pool handle it
	 */
	static function tryDispatch($client, $parsed, $scriptPath)
	{
		if (!self::$adapter || self::$disabled) {
			return false;
		}
		if (!self::$ready) {
			try {
				if (self::$adapter->invalidatedBy($scriptPath, $parsed)) {
					self::$invalidating[(int) $client] = true;
				}
			} catch (\Throwable $e) {}
			return false;
		}
		try {
			if (!self::$adapter->handles($scriptPath, $parsed)) {
				self::$stats['fallback']++;
				if (self::$adapter->invalidatedBy($scriptPath, $parsed)) {
					// Re-boot once this write's response has gone out, so
					// the new master reads what it committed.
					self::$invalidating[(int) $client] = true;
				}
				return false;
			}
		} catch (\Throwable $e) {
			return false;
		}
		$req = array($client, $parsed, $scriptPath, 0);
		$conn = self::takeIdle();
		if (!$conn) {
			if (count(self::$pending) >= 10000) return false;
			self::$pending[] = $req;
			return true;
		}
		self::send($conn, $req);
		return true;
	}

	protected static function takeIdle()
	{
		while (self::$idle) {
			$conn = array_shift(self::$idle);
			if (is_resource($conn) && !feof($conn)) return $conn;
			if (is_resource($conn)) @fclose($conn);
		}
		return null;
	}

	/**
	 * Accept connections from booted workers that are ready for a request.
	 * @method acceptWorkers
	 * @static
	 */
	static function acceptWorkers()
	{
		while ($conn = @stream_socket_accept(self::$server, 0)) {
			stream_set_blocking($conn, false);
			self::$ready = true;
			if (self::$pending) {
				self::send($conn, array_shift(self::$pending));
			} else {
				self::$idle[] = $conn;
			}
		}
	}

	protected static function send($conn, $req)
	{
		list($client, $parsed, $scriptPath) = $req;
		if (!is_resource($client)) {
			self::$idle[] = $conn;
			return;
		}
		$msg = Q_WebServer_Pool::encodeRequest($parsed, $scriptPath);
		stream_set_blocking($conn, true);
		$written = 0;
		$len = strlen($msg);
		while ($written < $len) {
			$n = @fwrite($conn, substr($msg, $written));
			if ($n === false || $n === 0) break;
			$written += $n;
		}
		stream_set_blocking($conn, false);
		if ($written < $len) {
			// Worker went away before taking the request: try another
			@fclose($conn);
			self::retry($req);
			return;
		}
		$id = self::$nextId++;
		self::$busy[$id] = array(
			'conn' => $conn, 'req' => $req, 'buf' => '', 'watcher' => null,
			'dispatchTime' => hrtime(true)
		);
		self::$busy[$id]['watcher'] = Q_Evented::onReadable($conn, function () use ($id) {
			Q_WebServer_Boot::onWorkerData($id);
		});
		self::$stats['booted']++;
	}

	protected static function retry($req)
	{
		$req[3]++;
		if ($req[3] > 2 || !self::$ready) {
			// Give up on booted workers for this request: ordinary pool
			if (Q_WebServer::$pool) {
				Q_WebServer::$pool->dispatch($req[0], $req[1], $req[2]);
			} elseif (is_resource($req[0])) {
				Q_WebServer::sendResponse($req[0], 502, 'Worker unavailable');
				@fclose($req[0]);
			}
			return;
		}
		$conn = self::takeIdle();
		if ($conn) {
			self::send($conn, $req);
		} else {
			array_unshift(self::$pending, $req);
		}
	}

	/**
	 * Read a booted worker's response and relay it to the client.
	 * @method onWorkerData
	 * @static
	 */
	static function onWorkerData($id)
	{
		if (!isset(self::$busy[$id])) return;
		$b =& self::$busy[$id];
		$chunk = @fread($b['conn'], 65536);
		if ($chunk === false || $chunk === '') {
			if (!feof($b['conn'])) return;
			$req = $b['req'];
			$hadData = $b['buf'] !== '';
			self::finish($id);
			if (!$hadData) {
				self::retry($req);   // worker exited without answering
			} elseif (is_resource($req[0])) {
				Q_WebServer::sendResponse($req[0], 502, 'Worker died');
				Q_WebServer::closeClient((int) $req[0]);
			}
			return;
		}
		$b['buf'] .= $chunk;
		if (strlen($b['buf']) < 4) return;
		$len = unpack('N', substr($b['buf'], 0, 4))[1];
		if (strlen($b['buf']) < 4 + $len) return;

		$response = json_decode(substr($b['buf'], 4, $len), true);
		$client = $b['req'][0];
		$reqHeaders = $b['req'][1]['headers'] ?? array();
		$__dispatchDt = ($b['dispatchTime'] ?? 0) ? (hrtime(true) - $b['dispatchTime']) / 1e6 : 0;
		fwrite(STDERR, sprintf("  WORKER-RT: %.1fms (dispatch→response)\n", $__dispatchDt));
		self::finish($id, true);   // recycle persistent worker connection
		if ($response && !empty($response['_cacheMessages'])
			&& class_exists('Q_WebServer_Cache_Components', false)
		) {
			foreach ($response['_cacheMessages'] as $m) {
				Q_WebServer_Cache_Components::processChildMessage($m);
			}
			unset($response['_cacheMessages']);
		}
		if ($response && is_resource($client)) {
			$reqHeaders['_keepAlive'] = false;
			Q_WebServer_Headers::processResponse($client, $response, $reqHeaders);
			Q_WebServer::closeClient((int) $client);
		}
	}

	protected static function finish($id, $recycle = false)
	{
		if (!isset(self::$busy[$id])) return;
		if (self::$busy[$id]['watcher'] !== null) {
			Q_Evented::cancel(self::$busy[$id]['watcher']);
		}
		$conn = self::$busy[$id]['conn'];
		unset(self::$busy[$id]);
		if ($recycle && is_resource($conn)) {
			// Return persistent worker to the idle pool
			self::$idle[] = $conn;
		} elseif (is_resource($conn)) {
			@fclose($conn);
		}
	}

	/** @var array Client sockets whose requests change booted state */
	static $invalidating = array();

	/**
	 * Called by the pool after it sends a response.
	 * @method requestFinished
	 * @static
	 */
	static function requestFinished($client)
	{
		$key = (int) $client;
		if (!isset(self::$invalidating[$key])) return;
		unset(self::$invalidating[$key]);
		self::reboot();
	}

	/**
	 * Ask the boot master to boot again (it exits with EXIT_STALE).
	 * @method reboot
	 * @static
	 */
	static function reboot()
	{
		if (is_resource(self::$ctl)) @fwrite(self::$ctl, 'R');
	}

	/**
	 * Fork the boot master.
	 * @method spawnMaster
	 * @static
	 */
	/** @var resource Server's end of the control socket to the boot master */
	static $ctl = null;
	/** @var resource Boot master's end */
	protected static $ctlMaster = null;

	static function spawnMaster()
	{
		if (is_resource(self::$ctl)) @fclose(self::$ctl);
		$family = defined('STREAM_PF_UNIX') ? STREAM_PF_UNIX : STREAM_PF_INET;
		$pair = stream_socket_pair($family, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		$pid = pcntl_fork();
		if ($pid === -1) {
			fwrite(STDERR, "  Boot: fork failed\n");
			self::$disabled = true;
			return;
		}
		if ($pid === 0) {
			fclose($pair[0]);
			self::$ctl = null;
			self::$ctlMaster = $pair[1];
			throw new Q_WebServer_Role(__DIR__ . '/roles/bootmaster.php');
		}
		fclose($pair[1]);
		self::$ctl = $pair[0];
		stream_set_blocking(self::$ctl, false);
		self::$masterPid = $pid;
		self::$masterStarted = microtime(true);
	}

	/**
	 * Watch the boot master; re-boot when it asks, back off when it fails.
	 * @method checkMaster
	 * @static
	 */
	static function checkMaster()
	{
		if (!self::$masterPid) return;
		$r = pcntl_waitpid(self::$masterPid, $status, WNOHANG);
		if ($r === self::$masterPid) {
			self::masterExited($status);
		} elseif ($r === -1 && !@posix_kill(self::$masterPid, 0)) {
			// Reaped elsewhere without telling us: assume it asked to re-boot
			self::masterExited(null);
		}
	}

	/**
	 * The boot master exited: re-boot, back off, or give up.
	 * @method masterExited
	 * @static
	 * @param {integer|null} $status waitpid() status, null if unknown
	 */
	static function masterExited($status)
	{
		if (!self::$masterPid) return;
		$code = $status === null ? self::EXIT_STALE
			: (pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1);
		self::$masterPid = 0;

		// Workers of the old master still waiting for a request hold the old
		// state. Closing their connections makes them exit unused.
		foreach (self::$idle as $c) if (is_resource($c)) @fclose($c);
		self::$idle = array();
		self::$ready = false;

		if ($code === self::EXIT_STALE) {
			self::$stats['reboots']++;
			self::$failures = 0;
			self::spawnMaster();
			return;
		}
		self::$failures++;
		$quick = (microtime(true) - self::$masterStarted) < 10;
		if ($code === self::EXIT_BOOT_FAILED || ($quick && self::$failures >= 3)) {
			self::$disabled = true;
			fwrite(STDERR, "  Boot: disabled for " . self::$adapter->name()
				. " after a failed boot; requests use the ordinary pool\n");
			while (self::$pending) {
				$req = array_shift(self::$pending);
				if (Q_WebServer::$pool) Q_WebServer::$pool->dispatch($req[0], $req[1], $req[2]);
			}
			return;
		}
		self::spawnMaster();
	}

	/**
	 * Stop the boot master and its workers.
	 * @method shutdown
	 * @static
	 */
	static function shutdown()
	{
		// Registered in the server, but inherited by every fork: only the
		// server itself may remove the socket. An exiting boot master used to
		// delete it, so after the first re-boot no worker could connect.
		if (getmypid() !== self::$serverPid) return;
		if (self::$masterPid) {
			@posix_kill(self::$masterPid, SIGTERM);
			self::$masterPid = 0;
		}
		foreach (self::$idle as $c) if (is_resource($c)) @fclose($c);
		self::$idle = array();
		if (self::$socketPath) @unlink(self::$socketPath);
	}

	/**
	 * Status for the dashboard and control panel.
	 * @method status
	 * @static
	 */
	static function status()
	{
		$hotCount = 0;
		if (self::$hotFilePath && is_file(self::$hotFilePath)) {
			$list = @json_decode((string) @file_get_contents(self::$hotFilePath), true);
			$hotCount = is_array($list) ? count($list) : 0;
		}
		return array(
			'adapter' => self::$adapter ? self::$adapter->name() : null,
			'enabled' => self::$adapter && !self::$disabled,
			'ready' => self::$ready,
			'idle' => count(self::$idle),
			'busy' => count(self::$busy),
			'pending' => count(self::$pending),
			'bootFiles' => count(self::$bootFiles),
			'hotFiles' => $hotCount,
		) + self::$stats;
	}

	// ── Boot master ─────────────────────────────────────

	protected static $workerPids = array();

	protected static $bootT0 = 0.0;
	protected static $bootM0 = 0;

	/** Files included during boot (set once booted), used to diff hot files. */
	protected static $bootFiles = array();

	/** Path to the learned hot-files cache for this root + adapter. */
	protected static $hotFilePath = '';

	/**
	 * Boot master, step 1 (roles/bootmaster.php): shed the server's state,
	 * then run the adapter's function-scope boot.
	 * @method masterStart
	 * @static
	 */
	static function masterStart()
	{
		if (function_exists('cli_set_process_title')) {
			@cli_set_process_title('qbixserver: boot master (' . self::$adapter->name() . ')');
		}
		self::$parentPid = posix_getppid();
		// Nothing of the server's event loop belongs to this process
		if (is_resource(self::$server)) @fclose(self::$server);
		foreach (self::$idle as $c) if (is_resource($c)) @fclose($c);
		foreach (self::$busy as $b) if (is_resource($b['conn'])) @fclose($b['conn']);
		foreach (Q_WebServer::$clients as $c) if (is_resource($c)) @fclose($c);
		Q_WebServer::$clients = array();
		self::$idle = self::$busy = self::$pending = array();
		if (class_exists('Q_WebServer_Pool', false)) Q_WebServer_Pool::clearGlobals();

		// The server's PHP signal handlers came along with the fork; the
		// master and its workers must not run them.
		if (function_exists('pcntl_async_signals')) pcntl_async_signals(false);
		foreach (array(SIGTERM, SIGINT, SIGHUP, SIGQUIT, SIGUSR1, SIGUSR2, SIGCHLD, SIGALRM) as $sig) {
			pcntl_signal($sig, SIG_DFL);
		}

		// The framework's own files must go through the source rewriter,
		// or header(), setcookie() etc. in them would be lost.
		if (class_exists('Q_WebServer_Compat', false)
			&& !Q_Config::get('Q', 'compat', 'skipSourceCodeTransform', false)
		) {
			Q_WebServer_Compat::init();
		}
		self::$bootT0 = microtime(true);
		self::$bootM0 = memory_get_usage();
		try {
			self::$adapter->setHost(Q_Config::get('Q', 'webserver', 'boot', 'host', 'localhost'));
			self::$adapter->boot(rtrim(self::$root, '/\\'));
		} catch (\Throwable $e) {
			self::bootFailed($e);
		}
		// A fatal error in the framework's files kills the master with a
		// normal exit status; make it read as a failed boot instead.
		register_shutdown_function(function () {
			if (!Q_WebServer_Boot::$booted) { exit(Q_WebServer_Boot::EXIT_BOOT_FAILED); }
		});
	}

	/** @var boolean Set once the master has finished booting */
	static $booted = false;
	protected static $parentPid = 0;

	protected static function bootFailed($e)
	{
		fwrite(STDERR, "  Boot: " . self::$adapter->name() . " failed: "
			. $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n");
		exit(self::EXIT_BOOT_FAILED);
	}

	/**
	 * Boot master, step 2: after the global-scope includes.
	 * @method masterBooted
	 * @static
	 */
	static function masterBooted()
	{
		try {
			self::$adapter->booted();
			self::$adapter->afterBoot();
		} catch (\Throwable $e) {
			self::bootFailed($e);
		}
		// Output produced while booting belongs to no request
		while (ob_get_level() > 0 && @ob_end_clean()) {}
		// Collect garbage now, so no worker's first allocation triggers a
		// collection that writes to (and copies) pages shared with the master.
		gc_collect_cycles();
		self::$booted = true;
		self::$bootFiles = get_included_files();
		self::compileAhead();
		fwrite(STDERR, sprintf("  Boot: %s booted in %.1f ms, %.1f MB of state shared by all workers\n",
			self::$adapter->name(), (microtime(true) - self::$bootT0) * 1000,
			(memory_get_usage() - self::$bootM0) / 1048576));
	}

	/**
	 * Compile files into OPcache's shared memory without executing them.
	 * Workers forked after this get cache hits instead of disk reads.
	 *
	 * Compiles: 1) all files loaded during boot, 2) learned hot files from
	 * previous request cycles. Safe for any file because opcache_compile_file()
	 * compiles opcodes only — no code executes, no side effects.
	 * @method compileAhead
	 * @static
	 */
	protected static function compileAhead()
	{
		if (!function_exists('opcache_compile_file')
			|| !function_exists('opcache_get_status')
		) {
			return;
		}
		$status = @opcache_get_status(false);
		if (!$status || empty($status['opcache_enabled'])) {
			fwrite(STDERR, "  Boot: OPcache not enabled for CLI; add opcache.enable_cli=1 to php.ini for faster workers\n");
			return;
		}

		// Unique path for this root + adapter combination
		$key = md5(self::$root . '|' . self::$adapter->name());
		self::$hotFilePath = rtrim(sys_get_temp_dir(), '/') . '/qbix-hotfiles-' . $key . '.json';

		// When the Compat stream wrapper has replaced the native file://
		// handler, opcache_compile_file() re-evaluates (executes) source
		// through the wrapper instead of just compiling opcodes, which causes
		// "Cannot redeclare" fatals. Skip OPcache precompilation in this case;
		// forked workers still get fast starts because they inherit the boot
		// master's loaded classes and opcache entries via COW memory sharing.
		if (class_exists('Q_WebServer_CompatFileWrapper', false)) {
			return;
		}

		$compiled = 0;
		$skipped = 0;

		// 1. Compile everything loaded during boot
		foreach (self::$bootFiles as $file) {
			if (@opcache_compile_file($file)) {
				$compiled++;
			} else {
				$skipped++;
			}
		}

		// 2. Compile learned hot files from previous boot cycles
		$hotFiles = self::loadHotFiles();
		foreach ($hotFiles as $file) {
			if (!is_file($file)) continue;
			if (@opcache_compile_file($file)) {
				$compiled++;
			} else {
				$skipped++;
			}
		}

		if ($compiled > 0) {
			fwrite(STDERR, "  Boot: compiled $compiled files into OPcache shared memory"
				. ($skipped ? " ($skipped skipped)" : '') . "\n");
		}
	}

	/**
	 * Load the learned hot-file list from disk.
	 * @method loadHotFiles
	 * @static
	 * @return {array} Absolute file paths
	 */
	protected static function loadHotFiles()
	{
		if (!self::$hotFilePath || !is_file(self::$hotFilePath)) {
			return array();
		}
		$data = @file_get_contents(self::$hotFilePath);
		if ($data === false) return array();
		$list = @json_decode($data, true);
		if (!is_array($list)) return array();

		// Prune files that no longer exist (app was updated, cache is stale)
		$pruned = array_values(array_filter($list, 'is_file'));
		if (count($pruned) < count($list)) {
			$tmp = self::$hotFilePath . '.prune';
			if (@file_put_contents($tmp, json_encode($pruned)) !== false) {
				@rename($tmp, self::$hotFilePath);
			} else {
				@unlink($tmp);
			}
		}
		return $pruned;
	}

	/**
	 * Record files a worker included beyond the boot set. Called in the
	 * worker process after handling its request, before exiting.
	 * @method recordHotFiles
	 * @static
	 */
	static function recordHotFiles()
	{
		if (!self::$hotFilePath) return;
		$all = get_included_files();
		$bootSet = array_flip(self::$bootFiles);
		$new = array();
		foreach ($all as $f) {
			if (!isset($bootSet[$f])) {
				$new[] = $f;
			}
		}
		if (empty($new)) return;

		// Merge with existing learned files (union, no duplicates).
		// Multiple workers may race here; flock serialises them, and a
		// lost race just means a redundant union — no data is lost.
		$existing = self::loadHotFiles();
		$merged = array_values(array_unique(array_merge($existing, $new)));
		if (count($merged) === count($existing)) return; // nothing new

		// Atomic write: write to temp, rename over the target
		$tmp = self::$hotFilePath . '.' . getmypid();
		if (@file_put_contents($tmp, json_encode($merged)) !== false) {
			@rename($tmp, self::$hotFilePath);
		} else {
			@unlink($tmp);
		}
	}

	/**
	 * Boot master, step 3: keep the configured number of booted workers
	 * waiting, and exit to be re-booted when watched files change or the TTL
	 * passes. In a forked worker this throws Q_WebServer_Role instead.
	 * @method masterLoop
	 * @static
	 */
	static function masterLoop()
	{
		$parent = self::$parentPid;
		$watch = self::$adapter->watchPaths(rtrim(self::$root, '/\\'));
		$fingerprint = self::fingerprint($watch);
		$ttl = (int) Q_Config::get('Q', 'webserver', 'boot', 'ttl', self::$adapter->defaultTtl());
		$born = time();
		$lastCheck = time();

		// No signals here: PHP's signal layer in a process forked from the
		// server re-applied its own mask, so blocking SIGCHLD for
		// pcntl_sigtimedwait() silently did nothing. Poll instead: the control
		// socket for re-boot requests (EOF means the server is gone) and
		// waitpid() for finished workers, every 10 ms.
		$ctl = self::$ctlMaster;
		stream_set_blocking($ctl, false);
		while (true) {
			if (!file_exists(self::$socketPath)) {
				exit(0); // the server is gone (or never listened): nothing to serve
			}
			while (count(self::$workerPids) < self::$workers) {
				$pid = self::spawnWorker();
				if (!$pid) break;
				self::$workerPids[$pid] = true;
			}
			$r = array($ctl);
			$w = $e = null;
			if (@stream_select($r, $w, $e, 0, 10000) > 0) {
				$msg = @fread($ctl, 64);
				if ($msg === '' || $msg === false) {
					if (feof($ctl)) exit(0); // server is gone
				} elseif (strpos($msg, 'R') !== false) {
					exit(self::EXIT_STALE); // the server saw a write: boot again
				}
			}
			while (($p = pcntl_waitpid(-1, $st, WNOHANG)) > 0) {
				unset(self::$workerPids[$p]);
			}
			if (posix_getppid() !== $parent) {
				exit(0); // server is gone
			}
			if (time() - $lastCheck >= 2) {
				$lastCheck = time();
				if (($ttl > 0 && time() - $born >= $ttl)
					|| self::fingerprint($watch) !== $fingerprint
				) {
					exit(self::EXIT_STALE);
				}
			}
		}
	}

	protected static function spawnWorker()
	{
		$pid = pcntl_fork();
		if ($pid > 0) return $pid;
		if ($pid < 0) return 0;

		// ── booted worker ──
		if (is_resource(self::$ctlMaster)) @fclose(self::$ctlMaster);
		if (function_exists('cli_set_process_title')) {
			@cli_set_process_title('qbixserver: booted worker (' . self::$adapter->name() . ')');
		}
		// Every fork inherits the master's random state; without reseeding,
		// all workers would produce the same mt_rand()/rand() sequence.
		mt_srand();
		srand();
		try {
			self::$adapter->afterFork();
		} catch (\Throwable $e) {
			exit(1);
		}
		// Record which files this request loaded beyond the boot set,
		// so the next boot cycle can compile them ahead of time.
		if (!Q_Config::get('Q', 'webserver', 'boot', 'skipHotFiles', false)) {
			register_shutdown_function(array(__CLASS__, 'recordHotFiles'));
		}
		$conn = @stream_socket_client('unix://' . self::$socketPath, $errno, $errstr, 5);
		if (!$conn) exit(1);
		$adapter = self::$adapter;
		// A handler file runs at global scope (WordPress templates expect it);
		// otherwise the adapter's handle() method is called.
		$file = $adapter->handleFile();
		Q_WebServer_Pool::$scriptRunner = $file ?: function ($req) use ($adapter) {
			$adapter->handle($req);
		};
		Q_WebServer_Pool::$roleSocket = $conn;
		Q_WebServer_Pool::$roleOctane = true;
		// Adapter may override: e.g. Drupal returns 1 (single-request
		// workers) because its compiled container can't be snapshot-restored.
		$adapterMax = $adapter->defaultRequestsPerWorker();
		Q_WebServer_Pool::$roleMax = $adapterMax > 0
			? $adapterMax
			: (int) Q_Config::get(
				'Q', 'webserver', 'boot', 'requestsPerWorker', 500
			);
		Q_WebServer_Pool::$roleKeepsGlobals = true;
		// Take a fresh snapshot of the post-boot state. The Pool constructor
		// took one before boot, but that captured pre-boot globals (no $wp,
		// $wpdb, etc.). This overwrites it with the booted state so
		// restoreStatics() and restoreGlobals() reset to the right baseline.
		if (class_exists('Q_WebServer_Snapshot', false)) {
			Q_WebServer_Snapshot::take();
		}
		throw new Q_WebServer_Role(__DIR__ . '/roles/worker.php');
	}

	protected static function fingerprint($paths)
	{
		$parts = array();
		foreach ($paths as $p) {
			clearstatcache(true, $p);
			$parts[] = $p . '@' . (@filemtime($p) ?: 0);
		}
		return md5(implode("\n", $parts));
	}
}
