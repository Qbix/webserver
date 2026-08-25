<?php
/**
 * Standalone Q shim for Qbix Server.
 *
 * Provides the core Q framework functionality needed to run
 * the server and user PHP scripts without the full Qbix Platform.
 * When running inside the full Platform (--app mode), this file
 * is never loaded — the real Q class takes over.
 *
 * Includes:
 *   - Autoloader for both underscore (Q_WebServer) and namespace (MyApp\User) styles
 *   - Q::ifset() for safe nested array/object access
 *   - Q::event() with handlers/ folder convention
 *   - Q::view() for rendering PHP templates
 *   - Q_Config for JSON config file loading
 *
 * @module Q
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

class Q
{
	/**
	 * Directories to search for classes/ and handlers/
	 * Set by the server at startup based on --root and project structure
	 * @property $paths
	 * @type array
	 * @static
	 */
	static $paths = array();

	/**
	 * Safe nested array/object access. Returns $default if any key is missing.
	 *
	 *   Q::ifset($arr, 'key1', 'key2', $default)
	 *   Q::ifset($obj, 'prop', $default)
	 *
	 * @method ifset
	 * @static
	 * @param {&mixed} $ref The array or object to traverse
	 * @return {mixed}
	 */
	static function ifset(&$ref)
	{
		$count = func_num_args();
		if ($count <= 2) {
			$args = func_get_args();
			$def = isset($args[1]) ? $args[1] : null;
			return isset($ref) ? $ref : $def;
		}
		$args = func_get_args();
		$def = end($args);
		$path = array_slice($args, 1, -1);
		return self::getObject($ref, $path, $def);
	}

	/**
	 * Get a value deep inside an array or object.
	 *
	 *   Q::getObject($data, ['users', 'alice', 'email'], 'default')
	 *
	 * @method getObject
	 * @static
	 * @param {&mixed} $ref The array or object to traverse
	 * @param {array} $path Array of keys/properties to follow
	 * @param {mixed} $def Default if path not found
	 * @return {mixed}
	 */
	static function getObject(&$ref, $path, $def = null)
	{
		$cur = $ref;
		foreach ($path as $key) {
			if (is_array($cur)) {
				if (!array_key_exists($key, $cur)) return $def;
				$cur = $cur[$key];
			} elseif (is_object($cur)) {
				if (!isset($cur->$key)) return $def;
				$cur = $cur->$key;
			} else {
				return $def;
			}
		}
		return $cur;
	}

	/**
	 * Set a value deep inside a nested array, creating intermediate arrays as needed.
	 *
	 *   Q::setObject(['users', 'alice', 'email'], 'alice@example.com', $data)
	 *
	 * @method setObject
	 * @static
	 * @param {array} $path
	 * @param {mixed} $value
	 * @param {&array} $dest The target array (modified by reference)
	 */
	static function setObject($path, $value, &$dest)
	{
		if (is_string($path)) $path = array($path);
		$ref = &$dest;
		foreach ($path as $key) {
			if (!isset($ref[$key]) || !is_array($ref[$key])) {
				$ref[$key] = array();
			}
			$ref = &$ref[$key];
		}
		$ref = $value;
	}

	/**
	 * JSON encode with unescaped slashes
	 * @method json_encode
	 * @static
	 */
	static function json_encode($value, $options = 0)
	{
		return json_encode($value, $options | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * JSON decode wrapper
	 * @method json_decode
	 * @static
	 */
	static function json_decode($json, $assoc = false, $depth = 512, $options = 0)
	{
		return json_decode($json, $assoc, $depth, $options);
	}

	/**
	 * Captured response headers. PHP's headers_list() returns empty in CLI SAPI,
	 * so we capture headers ourselves when scripts call header().
	 * @property $_responseHeaders
	 * @static
	 */
	static $_responseHeaders = array();
	static $_responseCode = 200;

	/**
	 * Get the app name. Used to prefix handler function names.
	 * Set via config: {"Q": {"app": "MyApp"}}
	 * @method app
	 * @static
	 * @return {string} App name, or empty string if not set
	 */
	static function app()
	{
		return Q_Config::get('Q', 'app', '');
	}

	/**
	 * Set a response header. Wraps PHP's header() and captures it.
	 * Scripts should use Q_Response::header() to set response headers.
	 * Q::header() is a backward-compatible alias that delegates to it.
	 * Both ensure capture in CLI SAPI mode where native header() is discarded.
	 * @method header
	 * @static
	 * @param {string} $header Full header string e.g. "Content-Type: text/html"
	 * @param {boolean} $replace Replace existing header of same name
	 * @param {integer} $code HTTP status code
	 */
	/**
	 * Backward-compatible alias for Q_Response::header().
	 * Use Q_Response::header() in new code.
	 * @method header
	 * @static
	 */
	static function header($header, $replace = true, $code = 0)
	{
		// Q_WebServer_State, not Q_Response: the Platform's Q_Response has no
		// header() and wins in --app mode, so forwarding there is a fatal.
		Q_WebServer_State::header($header, $replace, $code);
	}

	/**
	 * Get all captured response headers.
	 * Falls back to headers_list() if available (non-CLI SAPI).
	 * @method getResponseHeaders
	 * @static
	 * @return {array}
	 */
	static function getResponseHeaders()
	{
		// Try PHP native first (works in non-CLI SAPIs)
		$native = headers_list();
		if (!empty($native)) {
			$result = array();
			foreach ($native as $h) {
				$p = strpos($h, ':');
				if ($p !== false) {
					$result[trim(substr($h, 0, $p))] = trim(substr($h, $p + 1));
				}
			}
			return $result;
		}
		// CLI SAPI: merge Q_Response headers over Q:: captured headers
		$headers = self::$_responseHeaders;
		if (class_exists('Q_Response', false)) {
			$headers = array_merge($headers, Q_WebServer_State::getHeaders());
		}
		return $headers;
	}

	/**
	 * Clear captured headers (called between requests).
	 * @method clearResponseHeaders
	 * @static
	 */
	static function clearResponseHeaders()
	{
		self::$_responseHeaders = array();
		self::$_responseCode = 200;
	}

	// ── Event system ────────────────────────────────────

	/**
	 * Fire an event. Looks for handler functions in handlers/ directory.
	 *
	 * Handler for "MyApp/feed/post" lives at:
	 *   handlers/MyApp/feed/post.php
	 * And defines:
	 *   function MyApp_feed_post($params) { ... }
	 *
	 * @method event
	 * @static
	 * @param {string} $eventName e.g. "MyApp/feed/post"
	 * @param {array} $params Parameters passed to the handler
	 * @param {string|boolean} $pure false=run handler, 'before'=before hooks only,
	 *   'after'=after hooks only, true=both hooks but skip main handler
	 * @param {boolean} $skipIncludes If true, only call already-defined functions
	 * @param {mixed} &$result Reference for handlers to modify
	 * @return {mixed} Whatever the handler returned
	 */
	static function event(
		$eventName,
		$params = array(),
		$pure = false,
		$skipIncludes = false,
		&$result = null)
	{
		if (!is_string($eventName) || !$eventName) return null;
		if (!is_array($params)) $params = array();

		// Check if this event should be handled by a remote server.
		// Uses the same config key as the Platform: Q/handlersUsingRemote.
		// Also checks Q/handlersRemote (simple URL string) for backward compat.
		$remote = Q_Config::get('Q', 'handlersUsingRemote', $eventName, null);
		if (!$remote) {
			// Backward compat: handlersRemote accepts a bare URL string
			$remoteUrl = Q_Config::get('Q', 'handlersRemote', $eventName, null);
			if ($remoteUrl) {
				$remote = is_string($remoteUrl)
					? array('baseUrl' => $remoteUrl)
					: $remoteUrl;
			}
		}
		if (is_array($remote)) {
			return self::handleUsingRemote($eventName, $params, $remote, $result);
		}

		// Before hooks
		if ($pure !== 'after') {
			$handlers = Q_Config::get('Q', 'handlersBeforeEvent', $eventName, array());
			if (is_string($handlers)) $handlers = array($handlers);
			if (is_array($handlers)) {
				foreach ($handlers as $handler) {
					$r = self::handle($handler, $params, $skipIncludes, $result);
					if ($r === false) return $result;
				}
			}
		}

		// Main handler
		if (!$pure) {
			$ret = self::handle($eventName, $params, $skipIncludes, $result);
			// If the handler returned a value explicitly, use it.
			// If not (null), keep whatever was set via &$result reference.
			if ($ret !== null) {
				$result = $ret;
			}
		}

		// After hooks
		if ($pure !== 'before') {
			$handlers = Q_Config::get('Q', 'handlersAfterEvent', $eventName, array());
			if (is_string($handlers)) $handlers = array($handlers);
			if (is_array($handlers)) {
				foreach ($handlers as $handler) {
					$r = self::handle($handler, $params, $skipIncludes, $result);
					if ($r === false) return $result;
				}
			}
		}

		return $result;
	}

	/**
	 * Executes a particular event handler remotely, if configured via Q_Config.
	 * Compatible with the Platform's Q::handleUsingRemote — same method name,
	 * same config key (Q/handlersUsingRemote), same $remote array shape.
	 *
	 * Supports Unix domain sockets for the chokepoint microservices pattern:
	 * set $remote['socket'] to a UDS path (e.g. "/run/qbix/authority.sock").
	 *
	 * @method handleUsingRemote
	 * @static
	 * @param {string} $eventName Event name (e.g. "Streams/Stream/save")
	 * @param {array} $params Parameters to forward
	 * @param {array} $remote Configuration array:
	 * @param {string} [$remote.baseUrl] Remote server URL (default: http://localhost)
	 * @param {string} [$remote.socket] Optional Unix domain socket path
	 * @param {integer} [$remote.timeout] Request timeout in seconds (default: 10)
	 * @param {string} [$remote.returnType] Cast return: bool, int, array, object, raw, or class name
	 * @param {mixed} &$result Result from remote
	 * @return {mixed}
	 */
	static function handleUsingRemote($eventName, $params = array(), $remote = array(), &$result = null)
	{
		$baseUrl = $remote['baseUrl'] ?? 'http://localhost';
		$socketPath = $remote['socket'] ?? null;
		$timeout = (int) ($remote['timeout']
			?? Q_Config::get('Q', 'handlersRemote', '_timeout', 10));

		$url = rtrim($baseUrl, '/') . '/Q/event';

		// Generate unique message ID for loop prevention
		$identity = null;
		try {
			if (class_exists('Q_WebServer_Identity', false)) {
				$identity = Q_WebServer_Identity::serverIdentity();
			}
		} catch (\Throwable $e) { /* not in server context */ }
		$msgId = substr($identity ? $identity['fingerprint'] : gethostname(), 0, 8)
			. '.' . str_replace('.', '', (string) microtime(true))
			. '.' . bin2hex(random_bytes(4));

		$payload = array(
			'event' => $eventName,
			'params' => $params,
			'_msgId' => $msgId,
			'_timestamp' => time(),
		);

		// Sign using Platform-compatible Q_Utils::sign (body signature)
		$payload = Q_Utils::sign($payload);
		$json = json_encode($payload);

		// HMAC header (Platform convention for quick verification)
		$hmac = hash_hmac('sha1', $json, Q_Config::get('Q', 'internal', 'secret', '')
			?: Q_Utils::generateLocalSecret());

		$httpHeaders = array(
			'Content-Type: application/json',
			'Content-Length: ' . strlen($json),
			'X-Q-HMAC: ' . $hmac,
			'User-Agent: QbixServer/1.0',
		);

		// Add server fingerprint for identification
		if ($identity) {
			$httpHeaders[] = 'X-Q-Fingerprint: ' . $identity['fingerprint'];
		}

		// Use curl for the HTTP call — supports Unix domain sockets
		// via CURLOPT_UNIX_SOCKET_PATH, which file_get_contents cannot do
		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			$curlOpts = array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POST => true,
				CURLOPT_HTTPHEADER => $httpHeaders,
				CURLOPT_POSTFIELDS => $json,
				CURLOPT_TIMEOUT => $timeout,
			);
			if ($socketPath) {
				$curlOpts[CURLOPT_UNIX_SOCKET_PATH] = $socketPath;
			}
			curl_setopt_array($ch, $curlOpts);

			$response = curl_exec($ch);
			if ($response === false) {
				$err = curl_error($ch);
				curl_close($ch);
				$result = array('error' => 'Remote handler unreachable: ' . $url
					. ($socketPath ? " (socket: $socketPath)" : '') . " — $err");
				return $result;
			}
			curl_close($ch);
		} else {
			// Fallback: file_get_contents (no UDS support)
			if ($socketPath) {
				$result = array('error' => 'curl extension required for Unix socket remote calls');
				return $result;
			}
			$ctx = stream_context_create(array('http' => array(
				'method' => 'POST',
				'header' => implode("\r\n", $httpHeaders) . "\r\n",
				'content' => $json,
				'timeout' => $timeout,
				'ignore_errors' => true,
			)));
			$response = @file_get_contents($url, false, $ctx);
			if ($response === false) {
				$result = array('error' => 'Remote handler unreachable: ' . $url);
				return $result;
			}
		}

		$decoded = json_decode($response, true);
		$result = $decoded ?? $response;

		// Platform-compatible returnType casting
		if (is_array($result) && isset($result['data']) && isset($remote['returnType'])) {
			switch ($remote['returnType']) {
				case 'bool': return (bool) $result['data'];
				case 'int': return (int) $result['data'];
				case 'array': return (array) $result['data'];
				case 'object': return (object) $result['data'];
				case 'raw': return $result['data'];
				default:
					if (class_exists($remote['returnType'])) {
						return new $remote['returnType']($result['data']);
					}
			}
		}

		return $result;
	}

	/**
	 * Check if a handler exists for an event name
	 * @method canHandle
	 * @static
	 * @param {string} $eventName
	 * @return {boolean}
	 */
	static function canHandle($eventName)
	{
		$parts = explode('/', $eventName);
		$baseName = str_replace('-', '_', implode('_', $parts));
		$app = Q::app();
		$funcName = ($app !== '' ? $app . '_' : '') . $baseName;
		if (function_exists($funcName)) return true;

		// Try to load from handlers/ directory
		$relPath = 'handlers' . DS . implode(DS, $parts) . '.php';
		foreach (self::$paths as $base) {
			$full = $base . DS . $relPath;
			if (file_exists($full)) {
				include_once $full;
				return function_exists($funcName);
			}
		}
		return false;
	}

	/**
	 * Execute a handler function. Loads from handlers/ directory if needed.
	 * If $eventName starts with http:// or https://, POSTs params as JSON
	 * to that URL (remote handler / webhook).
	 * @method handle
	 * @static
	 * @param {string} $eventName
	 * @param {array} &$params
	 * @param {boolean} $skipIncludes
	 * @param {mixed} &$result
	 * @return {mixed}
	 */
	protected static function handle(
		$eventName, &$params = array(), $skipIncludes = false, &$result = null)
	{
		if (!$eventName) return null;

		// SECURITY: reject event names that could cause path traversal or LFI
		if (strpos($eventName, '..') !== false
			|| strpos($eventName, "\0") !== false
			|| $eventName[0] === '/'
			|| $eventName[0] === '\\'
			|| preg_match('/[^a-zA-Z0-9_\-\/]/', $eventName)
		) {
			return null;
		}

		// Remote handler — POST params as JSON to URL
		if (strncmp($eventName, 'http://', 7) === 0
			|| strncmp($eventName, 'https://', 8) === 0
		) {
			return self::handleRemote($eventName, $params, $result);
		}

		$parts = explode('/', $eventName);
		$baseName = str_replace('-', '_', implode('_', $parts));
		$app = Q::app();
		$funcName = ($app !== '' ? $app . '_' : '') . $baseName;

		if (!function_exists($funcName)) {
			if ($skipIncludes) return null;

			// Try to load from handlers/ directory
			$relPath = 'handlers' . DS . implode(DS, $parts) . '.php';
			$loaded = false;
			foreach (self::$paths as $base) {
				$full = $base . DS . $relPath;
				if (file_exists($full)) {
					include_once $full;
					$loaded = true;
					break;
				}
			}
			if (!$loaded || !function_exists($funcName)) {
				return null; // no handler found — that's OK
			}
		}

		$args = array(&$params, &$result);
		return call_user_func_array($funcName, $args);
	}

	/**
	 * POST event params as JSON to a remote URL.
	/**
	 * Render a PHP view file. Searches views/ directories in $paths.
	 *
	 *   echo Q::view('MyApp/feed/page.php', ['items' => $items]);
	 *
	 * @method view
	 * @static
	 * @param {string} $viewName Path relative to views/ directory
	 * @param {array} $params Variables extracted into the view scope
	 * @return {string} Rendered HTML
	 */
	static function view($viewName, $params = array())
	{
		$viewPath = str_replace('/', DS, $viewName);
		foreach (self::$paths as $base) {
			$full = $base . DS . 'views' . DS . $viewPath;
			if (file_exists($full)) {
				extract($params);
				ob_start();
				include $full;
				return ob_get_clean();
			}
		}
		return "<!-- view not found: $viewName -->";
	}

	// ── Autoloader ──────────────────────────────────────

	/**
	 * Autoloader that handles both conventions:
	 *   Q_WebServer      → classes/Q/WebServer.php       (underscore)
	 *   MyApp\User       → classes/MyApp/User.php        (namespace)
	 *   MyApp_Helper     → classes/MyApp/Helper.php      (underscore)
	 *
	 * Searches the src/ directory (for Q_ server classes) and all
	 * directories in Q::$paths (for user classes).
	 *
	 * @method autoload
	 * @static
	 * @param {string} $className
	 */
	static function autoload($className)
	{
		// 1. PSR-4 from config: Q.autoload.psr-4
		//    "Chess\\": "src/" → Chess\Board → src/Board.php
		$psr4 = Q_Config::get('Q', 'autoload', 'psr-4', array());
		foreach ($psr4 as $prefix => $baseDir) {
			$prefix = rtrim($prefix, '\\') . '\\';
			if (strncmp($className, $prefix, strlen($prefix)) !== 0) continue;
			$relClass = substr($className, strlen($prefix));
			$relPath = str_replace('\\', DS, $relClass) . '.php';
			foreach (self::$paths as $base) {
				$full = $base . DS . rtrim(str_replace('/', DS, $baseDir), DS) . DS . $relPath;
				if (file_exists($full)) {
					require_once $full;
					return;
				}
			}
		}

		// 2. PSR-0 from config: Q.autoload.psr-0
		//    "Legacy_": "vendor/" → Legacy_Util → vendor/Legacy/Util.php
		$psr0 = Q_Config::get('Q', 'autoload', 'psr-0', array());
		foreach ($psr0 as $prefix => $baseDir) {
			if ($prefix !== '' && strncmp($className, $prefix, strlen($prefix)) !== 0) continue;
			$relPath = str_replace(array('\\', '_'), DS, $className) . '.php';
			foreach (self::$paths as $base) {
				$full = $base . DS . rtrim(str_replace('/', DS, $baseDir), DS) . DS . $relPath;
				if (file_exists($full)) {
					require_once $full;
					return;
				}
			}
		}

		// 3. Internal Q classes (src/ directory, underscore convention)
		$parts = array();
		foreach (explode('\\', $className) as $nsPart) {
			$parts = array_merge($parts, explode('_', $nsPart));
		}
		$relPath = implode(DS, $parts) . '.php';
		$srcPath = dirname(__FILE__) . DS . $relPath;
		if (file_exists($srcPath)) {
			require_once $srcPath;
			return;
		}

		// 4. Project classes/ directories (default — works without config)
		//    Supports both PSR-4 style (Chess\Game → classes/Chess/Game.php)
		//    and Qbix style (Chess_Game → classes/Chess/Game.php)
		foreach (self::$paths as $base) {
			$full = $base . DS . 'classes' . DS . $relPath;
			if (file_exists($full)) {
				require_once $full;
				// Cross-alias between underscore and namespace conventions
				$underscoreName = implode('_', $parts);
				$namespaceName = implode('\\', $parts);
				if ($underscoreName !== $namespaceName) {
					if (class_exists($underscoreName, false)
						&& !class_exists($namespaceName, false)
					) {
						class_alias($underscoreName, $namespaceName);
					} elseif (class_exists($namespaceName, false)
						&& !class_exists($underscoreName, false)
					) {
						class_alias($namespaceName, $underscoreName);
					}
				}
				return;
			}
		}
	}

	/**
	 * Initialize Q paths from the project root directory.
	 * Called by the server at startup. Loads Composer autoloader if present.
	 * @method init
	 * @static
	 * @param {string} $projectRoot The project root (parent of web/)
	 */
	/**
	 * Initialize a project root — registers paths and loads Composer autoloader.
	 * Safe to call even if Platform's Q is loaded (it's a no-op if Q::init exists on Platform).
	 * @method init
	 * @static
	 */
	static function init($projectRoot)
	{
		$projectRoot = rtrim($projectRoot, DS);
		if (!in_array($projectRoot, self::$paths)) {
			self::$paths[] = $projectRoot;
		}
		$composerAutoload = $projectRoot . DS . 'vendor' . DS . 'autoload.php';
		if (file_exists($composerAutoload)) {
			require_once $composerAutoload;
		}
	}

	/**
	 * Preload handler files for COW sharing across forks.
	 * @method preload
	 * @static
	 */
	static function preload()
	{
		if (!Q_Config::get('Q', 'handlers', 'preload', false)) return;
		foreach (self::$paths as $base) {
			$handlersDir = $base . DS . 'handlers';
			if (is_dir($handlersDir)) self::preloadDir($handlersDir);
		}
	}

	/**
	 * Recursively include all .php files in a directory.
	 * @method preloadDir
	 * @static
	 * @param {string} $dir Directory to scan
	 */
	static function preloadDir($dir)
	{
		$entries = @scandir($dir);
		if (!$entries) return;
		foreach ($entries as $entry) {
			if ($entry[0] === '.') continue;
			$path = $dir . DS . $entry;
			if (is_dir($path)) {
				self::preloadDir($path);
			} elseif (substr($entry, -4) === '.php') {
				include_once $path;
				self::$preloadedHandlers++;
			}
		}
	}

	/** @var integer Number of preloaded handler files */
	static $preloadedHandlers = 0;
}

spl_autoload_register(array('Q', 'autoload'));

// ── Q_Socket ────────────────────────────────────────

/**
 * WebSocket connection context. Passed to per-connection handlers as
 * $params['socket']. Use instance methods to communicate with clients.
 *
 *   function my_handler(&$params, &$result) {
 *       extract($params); // $socket, $event, $data
 *       $socket->reply(['hello' => 'world']);
 *       $socket->join('chat/general', ['name' => 'Alice']);
 *       $location = $socket->getLocation(); // RPC call to client
 *   }
 *
 * @class Q_Socket
 */
class Q_Socket
{
	/** @var integer This socket's ID */
	public $id;

	function __construct($id) { $this->id = $id; }

	/** Get a socket instance by ID */
	static function byId($id) { return new self($id); }

	/** Send data to this socket's client */
	function reply($data) { self::_cmd(array('cmd' => 'send', 'socketId' => $this->id, 'data' => $data)); }

	/** Send data to a specific client by socket ID */
	function send($socketId, $data) { self::_cmd(array('cmd' => 'send', 'socketId' => $socketId, 'data' => $data)); }

	/** Broadcast to all clients in a room */
	function broadcast($room, $data) { self::_cmd(array('cmd' => 'broadcast', 'room' => $room, 'data' => $data)); }

	/** Broadcast to ALL connected clients */
	function broadcastAll($data) { self::_cmd(array('cmd' => 'broadcastAll', 'data' => $data)); }

	/** Join a room, optionally forwarding data to the room's join handler */
	function join($room, $data = array()) { self::_cmd(array('cmd' => 'join', 'socketId' => $this->id, 'room' => $room, 'data' => $data)); }

	/** Leave a room, optionally forwarding data to the room's leave handler */
	function leave($room, $data = array()) { self::_cmd(array('cmd' => 'leave', 'socketId' => $this->id, 'room' => $room, 'data' => $data)); }

	/** Disconnect this client */
	function disconnect() { self::_cmd(array('cmd' => 'disconnect', 'socketId' => $this->id)); }

	/**
	 * Call a method on the remote client. Blocks until the client responds.
	 * The client must have registered a handler via qs.handle('methodName', fn).
	 *
	 * @method __call
	 * @param {string} $method Method name to invoke on the client
	 * @param {array} $args Arguments — first element is passed as data to client
	 * @return {mixed} Return value from the client handler, or null on timeout
	 */
	function __call($method, $args)
	{
		$rpcId = ++self::$_rpcCounter;
		$data = isset($args[0]) ? $args[0] : array();

		// Flush any pending commands first
		self::flush();

		// Write RPC request directly to pipe (not buffered — need immediate send)
		$cmd = json_encode(array(
			'cmd' => 'rpc', 'socketId' => $this->id,
			'method' => $method, 'data' => $data, 'rpcId' => $rpcId,
		), JSON_UNESCAPED_SLASHES) . "\n";
		@fwrite(self::$_pipe, $cmd);

		// Block reading pipe until we get our RPC response (timeout 5s)
		$deadline = microtime(true) + 5.0;
		while (microtime(true) < $deadline) {
			$remaining = $deadline - microtime(true);
			if ($remaining <= 0) break;

			$read = array(self::$_pipe);
			$w = $e = null;
			$sec = (int) $remaining;
			$usec = (int) (($remaining - $sec) * 1000000);
			if (@stream_select($read, $w, $e, $sec, $usec) < 1) break;

			$header = @fread(self::$_pipe, 4);
			if (!$header || strlen($header) < 4) break;
			$len = unpack('N', $header)[1];
			if ($len <= 0 || $len > 10485760) break;
			$json = '';
			while (strlen($json) < $len) {
				$chunk = @fread(self::$_pipe, $len - strlen($json));
				if ($chunk === false || $chunk === '') break 2;
				$json .= $chunk;
			}
			$msg = json_decode($json, true);
			if (!$msg) continue;

			// Is this our RPC response?
			if (isset($msg['_rpc']) && $msg['_rpc'] === $rpcId) {
				return isset($msg['result']) ? $msg['result'] : null;
			}

			// Not our response — buffer for the main loop
			self::$_messageQueue[] = $msg;
		}
		return null; // timeout
	}

	// ── Internal IPC plumbing (not part of the public API) ──

	/** @internal */ static $_pipe = null;
	/** @internal */ static $_ack = null;
	/** @internal */ static $_directMode = false;
	/** @internal */ static $_buffer = array();
	/** @internal */ static $_rpcCounter = 0;
	/** @internal */ static $_messageQueue = array();

	/** @internal */
	static function _cmd($cmd)
	{
		if (self::$_directMode) {
			Q_WebSocket::executeCommand($cmd);
		} else {
			self::$_buffer[] = $cmd;
		}
	}

	/** @internal Flush buffered commands to IPC pipe */
	static function flush()
	{
		if (!self::$_pipe || empty(self::$_buffer)) return;
		$out = '';
		foreach (self::$_buffer as $cmd) {
			$out .= json_encode($cmd, JSON_UNESCAPED_SLASHES) . "\n";
		}
		@fwrite(self::$_pipe, $out);
		self::$_buffer = array();
	}
}

// ── Q_Room ──────────────────────────────────────────

/**
 * Room context. Passed to room handlers as $params['room'].
 * Wraps IPC commands with room context for cleaner handler code.
 *
 *   function chat_room_message(&$params, &$result) {
 *       extract($params); // $room, $event, $data
 *       $room->broadcast(['event' => 'chat/message', 'data' => $data]);
 *   }
 *
 * @class Q_Room
 */
class Q_Room
{
	/** @var string Room name (e.g. 'chat/general') */
	public $name;
	/** @var integer Socket ID of the current message sender (0 for lifecycle events without a sender) */
	public $socketId;
	/** @var array Pattern params (e.g. ['room' => 'general'] from 'chat/$room') */
	public $params;

	function __construct($name, $socketId = 0, $params = array())
	{
		$this->name = $name;
		$this->socketId = $socketId;
		$this->params = $params;
	}

	/** Get a room instance by name */
	static function byName($name) { return new self($name); }

	/** Send to all members in this room */
	function broadcast($data) { Q_Socket::_cmd(array('cmd' => 'broadcast', 'room' => $this->name, 'data' => $data)); }

	/** Send to the member who sent the current message */
	function reply($data) { Q_Socket::_cmd(array('cmd' => 'send', 'socketId' => $this->socketId, 'data' => $data)); }

	/** Send to a specific member by socket ID */
	function send($socketId, $data) { Q_Socket::_cmd(array('cmd' => 'send', 'socketId' => $socketId, 'data' => $data)); }
}

// ── Q_Request ───────────────────────────────────────

/**
 * Minimal Q_Response — compatible subset of the Qbix Platform's Q_Response.
 * Manages response headers, status codes, and cookies in CLI SAPI mode
 * where PHP's header()/setcookie()/headers_list() don't work.
 *
 * Use Q_Response::header() for setting response headers, or Q_Response methods for full control.
 *
 * @class Q_Response
 */
class Q_Response
{
	/** @var array Cookies to set */
	public static $cookies = array();
	/** @var string|null Redirect URL if set */
	public static $redirected = null;
	/** @var array Internal header store */
	private static $_headers = array();
	/** @var integer Internal status code */
	private static $_code = 200;
	/** @var array Cookies to remove */
	private static $cookiesToRemove = array();
	/** @var array Response errors */
	private static $errors = array();

	/**
	 * Set a response header. Same signature as PHP's header().
	 * @method header
	 * @static
	 */
	static function header($header, $replace = true, $code = 0)
	{
		if (strncasecmp($header, 'HTTP/', 5) === 0) {
			if (preg_match('/HTTP\/\S+\s+(\d+)\s*(.*)/i', $header, $m)) {
				self::code((int) $m[1]);
			}
			return;
		}
		$colonPos = strpos($header, ':');
		if ($colonPos !== false) {
			$name = trim(substr($header, 0, $colonPos));
			$value = trim(substr($header, $colonPos + 1));
			self::setHeader($name, $value, $replace);
		}
		if ($code > 0) self::code($code);
	}

	/** @method setHeader */
	static function setHeader($name, $value, $replace = true)
	{
		// Delegate: Q_WebServer_State is the single store the server reads from.
		// Keeping a parallel self::$_headers here silently dropped every header
		// set through this path once the readers moved to State.
		Q_WebServer_State::setHeader($name, $value, $replace);
		if (class_exists('Q_WebServer', false)) {
			Q_WebServer::setResponseHeader($name, $value, $replace);
		}
		@header("$name: $value", $replace);
	}

	/** @method getHeader */
	static function getHeader($name) { return Q_WebServer_State::getHeader($name); }

	/** @method getHeaders */
	static function getHeaders() { return Q_WebServer_State::getHeaders(); }

	/** @method clearHeaders */
	static function clearHeaders()
	{
		Q_WebServer_State::clearHeaders();
		self::$_code = 200;
		if (class_exists('Q_WebServer', false)) {
			Q_WebServer::clearResponseState();
		}
	}

	/**
	 * Get or set the numeric status code (capture-only companion to code()).
	 * Present so this shim and the Platform's Q_Response expose the same surface.
	 * @method responseCode
	 * @static
	 */
	static function responseCode($code = null)
	{
		if ($code === null) return self::$_code;
		return self::code($code);
	}

	/** @method code */
	static function code($code = null)
	{
		if ($code === null) return self::$_code;
		self::$_code = (int) $code;
		// Write into Q_WebServer_State: that is the store Q_Sapi::capture()
		// reads when assembling the response. Writing only to Q_WebServer's
		// own field left capture() reading a store nobody had written, so
		// every status a script set came back as 200.
		// Assign the field directly rather than calling
		// Q_WebServer_State::responseCode(), which forwards back to this
		// method and would recurse.
		if (class_exists('Q_WebServer_State', false)) {
			Q_WebServer_State::setCode((int) $code);
		}
		if (class_exists('Q_WebServer', false)) {
			Q_WebServer::responseCode((int) $code);
		}
		@http_response_code($code);
	}

	/**
	 * Set a cookie. Compatible with Q_Response::setCookie() from the Platform.
	 * Prevents duplicate cookies — if the same name+value is already set
	 * and it's a session cookie, skips it.
	 * @method setCookie
	 * @static
	 * @param {string} $name
	 * @param {string} $value
	 * @param {integer} $expires Timestamp, 0 = session cookie
	 * @param {string} $path Cookie path (default: /)
	 * @param {string|null} $domain
	 * @param {boolean} $secure
	 * @param {boolean} $httponly
	 * @param {string|null} $samesite None, Lax, or Strict
	 * @return {string|false}
	 */
	static function setCookie(
		$name, $value, $expires = 0,
		$path = '/', $domain = null,
		$secure = false, $httponly = false,
		$samesite = null
	) {
		// Skip if already set with same value and is a session cookie
		if (isset($_COOKIE[$name]) && $_COOKIE[$name] === $value && !$expires) {
			return $value;
		}
		self::$cookies[$name] = array($value, $expires, $path, $domain, $secure, $httponly, $samesite);
		unset(self::$cookiesToRemove[$name]);
		return $value;
	}

	/**
	 * Get the value of a cookie that will be sent, falling back to $_COOKIE.
	 * @method cookie
	 * @static
	 * @param {string} $name
	 * @return {string|null}
	 */
	static function cookie($name)
	{
		return isset(self::$cookies[$name][0])
			? self::$cookies[$name][0]
			: ($_COOKIE[$name] ?? null);
	}

	/**
	 * Clear a cookie.
	 * @method clearCookie
	 * @static
	 * @param {string} $name
	 * @param {string} $path
	 */
	static function clearCookie($name, $path = '/')
	{
		self::$cookiesToRemove[$name] = array($path);
		unset(self::$cookies[$name]);
	}

	/**
	 * Clear all stored cookies and cookie-removal entries.
	 * Called by Q_WebServer_State::clear() between requests.
	 * @method clearAllCookies
	 * @static
	 */
	static function clearAllCookies()
	{
		self::$cookies = array();
		self::$cookiesToRemove = array();
	}

	/**
	 * Set redirect. Compatible with Q_Response::redirect() from the Platform.
	 * @method redirect
	 * @static
	 * @param {string} $url
	 * @param {array} $options
	 * @return {boolean}
	 */
	static function redirect($url, $options = array())
	{
		$permanently = !empty($options['permanently']);
		self::code($permanently ? 301 : 302);
		self::setHeader('Location', $url);
		self::$redirected = $url;
		return true;
	}

	/**
	 * Enable streaming mode (SSE, chunked responses).
	 * When enabled, the webserver writes output to the client incrementally
	 * instead of buffering the full response body.
	 * @method setStreaming
	 * @static
	 * @param {boolean} $enable true to enable streaming
	 */
	static function setStreaming($enable = true)
	{
		Q_WebServer_State::setStreaming($enable);
	}

	/**
	 * Build Set-Cookie header strings from stored cookies.
	 * Called by the server when assembling the response.
	 * @method cookieHeaders
	 * @static
	 * @return {array} Array of Set-Cookie header strings
	 */
	static function cookieHeaders()
	{
		$headers = array();
		// Remove cookies
		foreach (self::$cookiesToRemove as $name => $args) {
			$path = $args[0] ?? '/';
			$headers[] = "$name=; Path=$path; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0";
		}
		// Set cookies
		foreach (self::$cookies as $name => $args) {
			list($value, $expires, $path, $domain, $secure, $httponly, $samesite) = $args;
			$parts = array(urlencode($name) . '=' . urlencode($value));
			if ($expires) {
				$parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', $expires);
				$parts[] = 'Max-Age=' . max(0, $expires - time());
			}
			$parts[] = 'Path=' . ($path ?: '/');
			if ($domain) $parts[] = 'Domain=' . $domain;
			if ($secure) $parts[] = 'Secure';
			if ($httponly) $parts[] = 'HttpOnly';
			if ($samesite) $parts[] = 'SameSite=' . $samesite;
			$headers[] = implode('; ', $parts);
		}
		return $headers;
	}

	/**
	 * Clear all response state between requests (in-process mode).
	 * @method clear
	 * @static
	 */
	static function clear()
	{
		Q_WebServer_State::clearHeaders();
		self::$_code = 200;
		self::$cookies = array();
		self::$cookiesToRemove = array();
		self::$errors = array();
		self::$redirected = null;
	}

	/**
	 * Add an error to the response. Compatible with Q_Response::addError()
	 * from the Platform. Q_Dispatcher checks these during validation.
	 * @method addError
	 * @static
	 * @param {Exception|array} $exception Either an Exception or an array of them
	 */
	static function addError($exception)
	{
		if (is_array($exception)) {
			self::$errors = array_merge(self::$errors, $exception);
		} else {
			self::$errors[] = $exception;
		}
	}

	/**
	 * Returns all the errors added so far to the response.
	 * @method getErrors
	 * @static
	 * @return {array}
	 */
	static function getErrors()
	{
		return self::$errors;
	}

	/**
	 * Gets or sets whether the response is buffered.
	 * Compatible with Q_Response::isBuffered() from the Platform.
	 * @method isBuffered
	 * @static
	 * @param {boolean} [$new_value=null] If set, changes the buffering mode.
	 * @return {boolean}
	 */
	static function isBuffered($new_value = null)
	{
		static $buffered = true;
		$old = $buffered;
		if (isset($new_value)) {
			$buffered = $new_value;
		}
		return $old;
	}

	/**
	 * Emit Set-Cookie headers from stored cookies.
	 * Compatible with Q_Response::sendCookieHeaders() from the Platform.
	 * No artificial timing restriction — cookies can be set at any point
	 * during request handling. The server assembles them into the response.
	 * @method sendCookieHeaders
	 * @static
	 */
	static function sendCookieHeaders()
	{
		foreach (self::$cookiesToRemove as $name => $args) {
			$path = $args[0] ?? '/';
			@setcookie($name, '', 1, $path);
		}
		foreach (self::$cookies as $name => $args) {
			list($value, $expires, $path, $domain, $secure, $httponly, $samesite) = $args;
			if ($samesite && version_compare(PHP_VERSION, '7.3.0', '>=')) {
				@setcookie($name, $value, compact('expires', 'path', 'domain', 'secure', 'httponly', 'samesite'));
			} else {
				@setcookie($name, $value, $expires, $path ?: '/', $domain ?: '', $secure, $httponly);
			}
		}
		self::$cookies = array();
		self::$cookiesToRemove = array();
	}
}

// ── Q_Request ───────────────────────────────────────

/**
 * Minimal Q_Request — compatible subset of the Qbix Platform's Q_Request.
 * Provides convenient access to request data that the server has already parsed.
 *
 * @class Q_Request
 */
class Q_Request
{
	/**
	 * Raw request body. Set by the server before your script runs.
	 * Use this instead of php://input (which doesn't work in our model).
	 * @property $input
	 * @type string
	 * @static
	 */
	static $input = '';

	/**
	 * Get the HTTP method (GET, POST, PUT, DELETE, etc.)
	 * @method method
	 * @static
	 * @return {string}
	 */
	static function method()
	{
		return $_SERVER['REQUEST_METHOD'] ?? 'GET';
	}

	/**
	 * Get the raw request body.
	 * @method input
	 * @static
	 * @return {string}
	 */
	static function input()
	{
		return self::$input;
	}

	/**
	 * Get the request body parsed as JSON.
	 * @method json
	 * @static
	 * @param {boolean} $assoc Return associative array (default true)
	 * @return {array|object|null}
	 */
	static function json($assoc = true)
	{
		return json_decode(self::$input, $assoc);
	}

	/**
	 * Get the full request URL.
	 * @method url
	 * @static
	 * @param {boolean} $querystring Include query string (default true)
	 * @return {string}
	 */
	static function url($querystring = true)
	{
		$scheme = ($_SERVER['REQUEST_SCHEME'] ?? 'http');
		$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
		$uri = $querystring
			? ($_SERVER['REQUEST_URI'] ?? '/')
			: ($_SERVER['SCRIPT_NAME'] ?? '/');
		return $scheme . '://' . $host . $uri;
	}

	/**
	 * Get the URL path (without query string).
	 * @method path
	 * @static
	 * @return {string}
	 */
	static function path()
	{
		$uri = $_SERVER['REQUEST_URI'] ?? '/';
		$qPos = strpos($uri, '?');
		return $qPos !== false ? substr($uri, 0, $qPos) : $uri;
	}

	/**
	 * Get a request header value.
	 * @method header
	 * @static
	 * @param {string} $name Header name (case-insensitive)
	 * @return {string|null}
	 */
	static function header($name)
	{
		$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
		return $_SERVER[$key] ?? null;
	}

	/**
	 * Get the client's IP address (resolved through proxy headers by the server).
	 * @method ip
	 * @static
	 * @return {string}
	 */
	static function ip()
	{
		return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
	}

	/**
	 * Check if the request is an AJAX/XHR request.
	 * @method isAjax
	 * @static
	 * @return {boolean}
	 */
	static function isAjax()
	{
		return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
	}

	/**
	 * Get uploaded files. Convenience wrapper around $_FILES.
	 * @method files
	 * @static
	 * @param {string|null} $name Specific file input name, or null for all
	 * @return {array|null}
	 */
	static function files($name = null)
	{
		if ($name === null) return $_FILES;
		return $_FILES[$name] ?? null;
	}

	/**
	 * Check if running in CLI mode (command line, cron, not via web server).
	 * In Qbix Server, scripts run in CLI SAPI but are dispatched as web
	 * requests. This method returns false for server-dispatched requests
	 * (because $_SERVER['REQUEST_METHOD'] is set) and true for genuine
	 * CLI invocations.
	 * Compatible with Q_Request::isInternal() from the Platform.
	 * @method isInternal
	 * @static
	 * @return {boolean}
	 */
	static function isInternal()
	{
		// If REQUEST_METHOD is set, we're handling a web request
		// (even though php_sapi_name() === 'cli')
		if (!empty($_SERVER['REQUEST_METHOD']) && !empty($_SERVER['REQUEST_URI'])) {
			return false;
		}
		return (php_sapi_name() === 'cli'
			|| defined('STDIN')
			|| !isset($_SERVER['REQUEST_METHOD']));
	}

	/**
	 * Whether the server is running in CLI SAPI.
	 * Always true for Qbix Server (same as FrankenPHP worker mode, Workerman).
	 * Scripts should use isInternal() to check if they're handling a web request.
	 * @method isCli
	 * @static
	 * @return {boolean}
	 */
	static function isCli()
	{
		return php_sapi_name() === 'cli';
	}

	/**
	 * Get the Content-Type of the request.
	 * @method contentType
	 * @static
	 * @return {string}
	 */
	static function contentType()
	{
		return $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
	}

	/**
	 * Check if the request body is JSON.
	 * @method isJson
	 * @static
	 * @return {boolean}
	 */
	static function isJson()
	{
		return strpos(strtolower(self::contentType()), 'application/json') !== false;
	}

	/**
	 * Get a value from $_GET, $_POST, or $_REQUEST with a default.
	 * @method special
	 * @static
	 * @param {string} $name
	 * @param {mixed} $default
	 * @return {mixed}
	 */
	static function special($name, $default = null)
	{
		return $_REQUEST[$name] ?? $default;
	}

	/**
	 * Set the raw request body and make php://input return it.
	 * Called by Q_WebServer before dispatching each request.
	 * @method setInput
	 * @static
	 * @param {string} $body Raw request body
	 */
	static function setInput($body)
	{
		self::$input = $body;
		// Make file_get_contents('php://input') work
		// by writing the body to a temp php://memory stream
		// and overriding the input stream
		if (!self::$_inputRegistered) {
			@stream_wrapper_unregister('php');
			stream_wrapper_register('php', 'Q_PhpInputStream');
			self::$_inputRegistered = true;
		}
		Q_PhpInputStream::$data = $body;
	}

	/**
	 * Restore the native php:// stream wrapper after request.
	 * @method restoreInput
	 * @static
	 */
	static function restoreInput()
	{
		if (self::$_inputRegistered) {
			stream_wrapper_unregister('php');
			stream_wrapper_restore('php');
			self::$_inputRegistered = false;
		}
	}

	/** @internal */
	static $_inputRegistered = false;
}

/**
 * Stream wrapper that makes file_get_contents('php://input') return
 * the request body in our CLI-SAPI server model.
 * Also handles php://output, php://memory, php://temp transparently.
 * @internal
 */
class Q_PhpInputStream
{
	static $data = '';
	private $pos = 0;
	private $path = '';
	private $memory = '';

	function stream_open($path, $mode, $options, &$openedPath)
	{
		$this->path = str_replace('php://', '', $path);
		$this->pos = 0;
		$this->memory = '';
		return true;
	}

	function stream_read($count)
	{
		if ($this->path === 'input') {
			$chunk = substr(self::$data, $this->pos, $count);
			$this->pos += strlen($chunk);
			return $chunk;
		}
		if ($this->path === 'memory' || $this->path === 'temp') {
			$chunk = substr($this->memory, $this->pos, $count);
			$this->pos += strlen($chunk);
			return $chunk;
		}
		return false;
	}

	function stream_write($data)
	{
		if ($this->path === 'output') {
			echo $data;
			return strlen($data);
		}
		if ($this->path === 'memory' || $this->path === 'temp') {
			$this->memory .= $data;
			$this->pos += strlen($data);
			return strlen($data);
		}
		return 0;
	}

	function stream_eof()
	{
		if ($this->path === 'input') return $this->pos >= strlen(self::$data);
		if ($this->path === 'memory' || $this->path === 'temp') return $this->pos >= strlen($this->memory);
		return true;
	}

	function stream_stat() {
		if ($this->path === 'input') {
			return array('size' => strlen(self::$data));
		}
		if ($this->path === 'memory' || $this->path === 'temp') {
			return array('size' => strlen($this->memory));
		}
		return array();
	}
	function url_stat($path, $flags) {
		return array('size' => strlen(self::$data));
	}
	function stream_tell() { return $this->pos; }
	function stream_seek($offset, $whence) {
		if ($whence === SEEK_SET) $this->pos = $offset;
		elseif ($whence === SEEK_CUR) $this->pos += $offset;
		return true;
	}
}

// ── Q_Config ────────────────────────────────────────

/**
 * JSON config file loader with deep merge.
 * Compatible with the full Qbix Platform's Q_Config API.
 *
 * @class Q_Config
 */
class Q_Config
{
	private static $data = array();

	/**
	 * Load and merge a JSON config file
	 * @method load
	 * @static
	 * @param {string} $path Path to JSON file
	 */
	static function load($path)
	{
		if (!file_exists($path)) return;
		$json = json_decode(file_get_contents($path), true);
		if (is_array($json)) {
			self::$data = self::merge(self::$data, $json);
		}
	}

	/**
	 * Set a config value programmatically.
	 *   Q_Config::set('Q', 'webserver', 'port', 8080)
	 * @method set
	 * @static
	 */
	static function set(/* key1, key2, ..., value */)
	{
		$args = func_get_args();
		$value = array_pop($args);
		$ref = &self::$data;
		foreach ($args as $key) {
			if (!isset($ref[$key]) || !is_array($ref[$key])) {
				$ref[$key] = array();
			}
			$ref = &$ref[$key];
		}
		$ref = $value;
	}

	/**
	 * Get a config value with a default.
	 *   Q_Config::get('Q', 'webserver', 'keepAlive', 'max', 100)
	 * Last argument is the default.
	 * @method get
	 * @static
	 * @return {mixed}
	 */
	static function get(/* key1, key2, ..., default */)
	{
		$args = func_get_args();
		$default = array_pop($args);
		$ref = self::$data;
		foreach ($args as $key) {
			if (!is_array($ref) || !array_key_exists($key, $ref)) {
				return $default;
			}
			$ref = $ref[$key];
		}
		return $ref;
	}

	/**
	 * Get a config value or throw if missing.
	 *   Q_Config::expect('Q', 'app')
	 * @method expect
	 * @static
	 * @return {mixed}
	 * @throws {Exception}
	 */
	static function expect(/* key1, key2, ... */)
	{
		$args = func_get_args();
		$ref = self::$data;
		foreach ($args as $key) {
			if (!is_array($ref) || !array_key_exists($key, $ref)) {
				throw new Exception("Missing config: " . implode('.', $args));
			}
			$ref = $ref[$key];
		}
		return $ref;
	}

	/**
	 * Get all config data
	 * @method getAll
	 * @static
	 * @return {array}
	 */
	static function getAll()
	{
		return self::$data;
	}

	/**
	 * Deep merge: arrays merge recursively, scalars overwrite.
	 * @method merge
	 * @static
	 * @private
	 */
	private static function merge($base, $overlay)
	{
		foreach ($overlay as $key => $value) {
			if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
				$base[$key] = self::merge($base[$key], $value);
			} else {
				$base[$key] = $value;
			}
		}
		return $base;
	}
}

// ── Q_Sapi_Finalizer ────────────────────────────────

/**
 * Captures the response after EVERYTHING else has run.
 *
 * PHP's shutdown order is: exit() -> register_shutdown_function callbacks
 * (in registration order) -> object destructors. Because the SAPI shim
 * registers before any app code, a shutdown callback would fire FIRST --
 * before user callbacks had a chance to echo or set cookies. A destructor
 * is guaranteed to run last, and still runs on exit(), on uncaught
 * exceptions and on fatal errors.
 *
 * Held in a global by Q_Sapi::enter(); destroyed at script end.
 *
 * @class Q_Sapi_Finalizer
 */
class Q_Sapi_Finalizer
{
	function __destruct()
	{
		Q_Sapi::capture();
	}
}

// ── Q_Sapi ──────────────────────────────────────────

/**
 * SAPI emulation for forked children.
 *
 * A forked child of a CLI process has no SAPI: nothing populated the
 * superglobals, nothing captures output, and native header() is a no-op.
 * This class does what mod_php or php-fpm would do, per request.
 *
 * It is NOT Qbix-specific -- any PHP script can be run through it:
 *
 *   Q_Sapi::enter($parsed);
 *   include $scriptPath;
 *   list($status, $headers, $body) = Q_Sapi::leave();
 *
 * Native header() calls from third-party code still cannot be captured
 * (PHP offers no hook). Such scripts must be routed to php-cgi via the
 * Q.webserver.cgi.patterns config.
 *
 * @class Q_Sapi
 */
class Q_Sapi
{
	/** @internal */ static $captured = null;
	/** @internal */ static $entered = false;

	/**
	 * Where a captured response goes when the script ends without an explicit
	 * leave() -- i.e. normal end, exit(), uncaught exception, fatal error.
	 * The worker pool sets this to write the response down its pipe. If it is
	 * left null the child writes the body to STDOUT, so a forked child still
	 * behaves correctly when run standalone.
	 * @property $onCapture
	 * @type callable|null
	 * @static
	 */
	static $onCapture = null;

	/** @internal true once a response has been handed off */
	static $delivered = false;

	/**
	 * Populate superglobals from a parsed request and begin buffering.
	 * @method enter
	 * @static
	 * @param {array} $parsed method, path, query, headers, body
	 */
	static function enter($parsed)
	{
		self::$captured = null;
		self::$delivered = false;
		self::$entered = true;

		$headers = isset($parsed['headers']) ? $parsed['headers'] : array();
		$host = isset($headers['host']) ? $headers['host'] : 'localhost';

		$_GET = $_POST = $_REQUEST = $_COOKIE = $_FILES = array();
		if (!empty($parsed['query'])) {
			parse_str($parsed['query'], $_GET);
		}
		$ct = isset($headers['content-type']) ? $headers['content-type'] : '';
		if (!empty($parsed['body'])) {
			if (stripos($ct, 'application/json') !== false) {
				$_POST = json_decode($parsed['body'], true) ?: array();
			} else {
				parse_str($parsed['body'], $_POST);
			}
		}
		$_REQUEST = array_merge($_GET, $_POST);
		if (!empty($headers['cookie'])) {
			foreach (explode(';', $headers['cookie']) as $pair) {
				$eq = strpos($pair, '=');
				if ($eq === false) continue;
				$_COOKIE[urldecode(trim(substr($pair, 0, $eq)))]
					= urldecode(trim(substr($pair, $eq + 1)));
			}
		}

		// HTTP_HOST must be set before any app code runs: Q_Response::setCookie()
		// silently returns false without it, which would drop the session cookie.
		$_SERVER['HTTP_HOST']      = $host;
		$_SERVER['SERVER_NAME']    = explode(':', $host)[0];
		$_SERVER['REQUEST_METHOD'] = isset($parsed['method']) ? $parsed['method'] : 'GET';
		$_SERVER['REQUEST_URI']    = (isset($parsed['path']) ? $parsed['path'] : '/')
			. (!empty($parsed['query']) ? '?' . $parsed['query'] : '');
		$_SERVER['QUERY_STRING']   = isset($parsed['query']) ? $parsed['query'] : '';
		$_SERVER['SCRIPT_NAME']    = isset($parsed['_scriptName']) ? $parsed['_scriptName'] : '/index.php';
		$_SERVER['SCRIPT_FILENAME']= isset($parsed['_scriptPath']) ? $parsed['_scriptPath'] : '';
		$_SERVER['PATH_INFO']      = isset($parsed['_pathInfo']) ? $parsed['_pathInfo'] : '';
		$_SERVER['PHP_SELF']       = $_SERVER['SCRIPT_NAME'] . $_SERVER['PATH_INFO'];
		$_SERVER['REMOTE_ADDR']    = isset($parsed['remoteAddr']) ? $parsed['remoteAddr'] : '127.0.0.1';
		if ($ct !== '') $_SERVER['CONTENT_TYPE'] = $ct;
		foreach ($headers as $k => $v) {
			$_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
		}

		if (class_exists('Q_Request', false)
		and method_exists('Q_Request', 'setInput')) {
			Q_WebServer_State::setInput(isset($parsed['body']) ? $parsed['body'] : '');
		}

		ob_start();
		$GLOBALS['__q_sapi_finalizer'] = new Q_Sapi_Finalizer();
	}

	/**
	 * Capture the response. Idempotent -- safe to call from the finalizer
	 * even if leave() already ran, and safe after flushEarly() closed
	 * the buffer.
	 * @method capture
	 * @static
	 * @return {array} status, headers, body
	 */
	static function capture()
	{
		if (self::$captured !== null) {
			return self::$captured;
		}
		if (!self::$entered) {
			return array(200, array(), '');
		}
		// Sessions must be written BEFORE we assemble the response, so the
		// row and its cookie are settled by the time the parent replies.
		if (function_exists('session_status')
		and session_status() === PHP_SESSION_ACTIVE) {
			@session_write_close();
		}
		$body = '';
		while (ob_get_level() > 0) {
			$chunk = ob_get_clean();
			if ($chunk !== false) $body = $chunk . $body;
		}
		$status  = class_exists('Q_Response', false)
			? Q_WebServer_State::responseCode() : 200;
		$headers = class_exists('Q_Response', false)
			? Q_WebServer_State::getHeaders() : array();
		// Guard on the class only. Gating this on
		// method_exists('Q_Response','cookieHeaders') tied it to a method the
		// shim has and the Platform does not, so --app mode dropped every
		// cookie. State::cookieHeaders() reads the shared $cookies property
		// and works in both modes.
		if (class_exists('Q_Response', false)) {
			foreach (Q_WebServer_State::cookieHeaders() as $sc) {
				$headers['Set-Cookie'] = isset($headers['Set-Cookie'])
					? $headers['Set-Cookie'] . "\n" . $sc
					: $sc;
			}
		}
		self::$captured = array($status, $headers, $body);
		self::deliver();
		return self::$captured;
	}

	/**
	 * Hand the captured response off exactly once.
	 * @method deliver
	 * @static
	 */
	static function deliver()
	{
		if (self::$delivered or self::$captured === null) {
			return;
		}
		self::$delivered = true;
		if (is_callable(self::$onCapture)) {
			call_user_func(self::$onCapture, self::$captured);
		} else if (defined('STDOUT')) {
			// No consumer registered: emit the body so a standalone child
			// behaves like an ordinary PHP script. Buffers are already closed,
			// so write directly rather than echoing.
			@fwrite(STDOUT, self::$captured[2]);
		}
	}

	/**
	 * Finish the request explicitly. Equivalent to letting the finalizer run,
	 * but returns the response to the caller.
	 * @method leave
	 * @static
	 * @return {array} status, headers, body
	 */
	static function leave()
	{
		$r = self::capture();
		self::$entered = false;
		self::$delivered = false;
		self::$captured = null;
		unset($GLOBALS['__q_sapi_finalizer']);
		if (class_exists('Q_Request', false)
		and method_exists('Q_Request', 'restoreInput')) {
			Q_WebServer_State::restoreInput();
		}
		return $r;
	}
}
