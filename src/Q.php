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
	 * Scripts can call either header() directly or Q::header() — both work.
	 * But Q::header() ensures capture in CLI SAPI mode.
	 * @method header
	 * @static
	 * @param {string} $header Full header string e.g. "Content-Type: text/html"
	 * @param {boolean} $replace Replace existing header of same name
	 * @param {integer} $code HTTP status code
	 */
	static function header($header, $replace = true, $code = 0)
	{
		// Delegate to Q_Response for proper tracking
		$colonPos = strpos($header, ':');
		if ($colonPos !== false) {
			$name = trim(substr($header, 0, $colonPos));
			$value = trim(substr($header, $colonPos + 1));
			if (class_exists('Q_Response', false)) {
				Q_Response::setHeader($name, $value, $replace);
			} else {
				// Fallback: direct capture
				if ($replace) {
					self::$_responseHeaders[$name] = $value;
				} elseif (!isset(self::$_responseHeaders[$name])) {
					self::$_responseHeaders[$name] = $value;
				}
			}
		}
		if ($code > 0) {
			self::$_responseCode = $code;
			if (class_exists('Q_Response', false)) {
				Q_Response::code($code);
			}
		}
		// Also call native header() (works in non-CLI SAPIs)
		@header($header, $replace, $code);
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
			$headers = array_merge($headers, Q_Response::getHeaders());
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
			$result = self::handle($eventName, $params, $skipIncludes, $result);
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
	 * Used for webhook-style handlers configured in Q.handlersAfterEvent.
	 * Non-blocking: uses a short timeout so it doesn't slow down the request.
	 * @method handleRemote
	 * @static
	 * @param {string} $url
	 * @param {array} &$params
	 * @param {mixed} &$result
	 * @return {mixed}
	 */
	protected static function handleRemote($url, &$params, &$result)
	{
		$json = json_encode($params, JSON_UNESCAPED_SLASHES);
		$opts = array('http' => array(
			'method'  => 'POST',
			'header'  => "Content-Type: application/json\r\n"
				. "Content-Length: " . strlen($json) . "\r\n"
				. "User-Agent: QbixServer/1.0\r\n",
			'content' => $json,
			'timeout' => 5,
			'ignore_errors' => true,
		));
		$ctx = stream_context_create($opts);
		$response = @file_get_contents($url, false, $ctx);
		if ($response !== false) {
			$decoded = json_decode($response, true);
			if ($decoded !== null) {
				$result = $decoded;
			}
		}
		return $result;
	}

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
		// Split on both \ and _ to get path parts
		$parts = array();
		foreach (explode('\\', $className) as $nsPart) {
			$parts = array_merge($parts, explode('_', $nsPart));
		}
		$relPath = implode(DS, $parts) . '.php';

		// 1. Search src/ directory (for Q_* server classes)
		$srcPath = dirname(__FILE__) . DS . $relPath;
		if (file_exists($srcPath)) {
			require_once $srcPath;
			return;
		}

		// 2. Search project classes/ directories
		foreach (self::$paths as $base) {
			$full = $base . DS . 'classes' . DS . $relPath;
			if (file_exists($full)) {
				require_once $full;
				// If loaded via underscore but also accessible via namespace, alias
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
	 * Called by the server at startup.
	 * @method init
	 * @static
	 * @param {string} $projectRoot The project root (parent of web/)
	 */
	static function init($projectRoot)
	{
		$projectRoot = rtrim($projectRoot, DS);
		if (!in_array($projectRoot, self::$paths)) {
			self::$paths[] = $projectRoot;
		}
	}

	/**
	 * Preload all handler files if Q.handlers.preload is true.
	 * Call this after config is loaded and before the server starts accepting
	 * connections. Handlers are included once in the parent process and shared
	 * via COW across all forked children.
	 *
	 * Off by default — handlers lazy-load via include_once on first call,
	 * which is fine with opcache (edit a file, refresh, see the change).
	 * Enable in production for full COW sharing of handler bytecode.
	 *
	 * @method preload
	 * @static
	 */
	static function preload()
	{
		if (!Q_Config::get('Q', 'handlers', 'preload', false)) {
			return;
		}
		foreach (self::$paths as $base) {
			$handlersDir = $base . DS . 'handlers';
			if (is_dir($handlersDir)) {
				self::preloadDir($handlersDir);
			}
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
 * Use Q::header() for simple cases, or Q_Response methods for full control.
 *
 * @class Q_Response
 */
class Q_Response
{
	/** @var array Response headers: name => value */
	protected static $headers = array();
	/** @var integer HTTP status code */
	protected static $statusCode = 200;
	/** @var string Status message */
	protected static $statusMessage = 'OK';
	/** @var array Cookies to set: name => [value, expires, path, domain, secure, httponly, samesite] */
	public static $cookies = array();
	/** @var array Cookies to remove */
	protected static $cookiesToRemove = array();
	/** @var string|null Redirect URL if set */
	public static $redirected = null;

	/**
	 * Set a response header. Compatible with Q_Response::setHeader() from the Platform.
	 * @method setHeader
	 * @static
	 * @param {string} $name Header name (e.g. 'Content-Type')
	 * @param {string} $value Header value
	 * @param {boolean} $replace Whether to replace existing header of same name
	 */
	static function setHeader($name, $value, $replace = true)
	{
		if ($replace || !isset(self::$headers[$name])) {
			self::$headers[$name] = $value;
		}
		// Also store in Q's header capture
		Q::$_responseHeaders[$name] = $value;
		// Call native header() for non-CLI SAPIs
		@header("$name: $value", $replace);
	}

	/**
	 * Get a response header that was set.
	 * @method getHeader
	 * @static
	 * @param {string} $name
	 * @return {string|null}
	 */
	static function getHeader($name)
	{
		return self::$headers[$name] ?? null;
	}

	/**
	 * Get all response headers.
	 * @method getHeaders
	 * @static
	 * @return {array}
	 */
	static function getHeaders()
	{
		return self::$headers;
	}

	/**
	 * Set the HTTP response status code.
	 * Compatible with Q_Response::code() from the Platform.
	 * @method code
	 * @static
	 * @param {integer} $code HTTP status code
	 * @param {string} $message Optional status message
	 */
	static function code($code, $message = null)
	{
		self::$statusCode = (int) $code;
		if ($message !== null) {
			self::$statusMessage = $message;
		}
		Q::$_responseCode = (int) $code;
		http_response_code($code);
	}

	/**
	 * Get the current status code.
	 * @method getStatusCode
	 * @static
	 * @return {integer}
	 */
	static function getStatusCode()
	{
		return self::$statusCode;
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
		self::$headers = array();
		self::$statusCode = 200;
		self::$statusMessage = 'OK';
		self::$cookies = array();
		self::$cookiesToRemove = array();
		self::$redirected = null;
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
