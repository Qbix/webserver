<?php
/**
 * Minimal Q shim for standalone Qbix Server.
 *
 * Provides just enough of the Q framework for Q_WebServer and its
 * dependencies to function without the full Qbix Platform.
 * When running inside the full Platform, this file is never loaded —
 * the real Q class takes over.
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

class Q
{
	/**
	 * Safe nested array access. Returns $default if any key is missing.
	 * Signature: Q::ifset($arr, 'key1', 'key2', ..., $default)
	 */
	static function ifset(&$arr)
	{
		$args = func_get_args();
		array_shift($args); // remove $arr
		$default = array_pop($args); // last arg is default
		$ref = &$arr;
		foreach ($args as $key) {
			if (!is_array($ref) || !array_key_exists($key, $ref)) {
				return $default;
			}
			$ref = &$ref[$key];
		}
		return $ref;
	}

	/**
	 * JSON encode with error handling
	 */
	static function json_encode($value, $options = 0)
	{
		return json_encode($value, $options | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * Fire an event. No-op in standalone mode.
	 */
	static function event($name, $params = array(), $type = '')
	{
		// No event system in standalone mode
		return null;
	}

	/**
	 * Autoloader for Q_* classes
	 */
	static function autoload($className)
	{
		if (strpos($className, 'Q_') !== 0 && $className !== 'Q_Config') return;
		$path = str_replace('_', DS, $className) . '.php';
		$full = dirname(__FILE__) . DS . $path;
		if (file_exists($full)) {
			require_once $full;
		}
	}
}

spl_autoload_register(array('Q', 'autoload'));

/**
 * Minimal Q_Config — reads JSON config files merged together.
 */
class Q_Config
{
	private static $data = array();
	private static $loaded = false;

	/**
	 * Load config from JSON file(s)
	 */
	static function load($path)
	{
		if (!file_exists($path)) return;
		$json = json_decode(file_get_contents($path), true);
		if (is_array($json)) {
			self::$data = self::merge(self::$data, $json);
		}
		self::$loaded = true;
	}

	/**
	 * Set a config value programmatically
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
	 * Get a config value with default.
	 * Q_Config::get('Q', 'webserver', 'keepAlive', 'max', 100)
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
	 * Get a config value or throw.
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
	 */
	static function getAll()
	{
		return self::$data;
	}

	/**
	 * Deep merge arrays (scalars overwrite, arrays merge recursively)
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
