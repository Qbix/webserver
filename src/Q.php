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
		$funcName = str_replace('-', '_', implode('_', $parts));
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
		$funcName = str_replace('-', '_', implode('_', $parts));

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
}

spl_autoload_register(array('Q', 'autoload'));

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
