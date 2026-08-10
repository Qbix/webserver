<?php
/**
 * Provides getallheaders() for scripts running under Qbix Server.
 *
 * Under Apache and FrankenPHP, getallheaders() is a built-in function that
 * returns all HTTP request headers as an associative array with their
 * original names (not the HTTP_* mangled $_SERVER keys).
 *
 * Under CLI SAPI (which Qbix Server uses), getallheaders() does not exist.
 * This class provides it, plus access to raw (non-concatenated) header
 * values for headers that appear multiple times in the request.
 *
 * @class Q_WebServer_GetAllHeaders
 */
class Q_WebServer_GetAllHeaders
{
	/**
	 * The current request's headers: ['Header-Name' => 'value', ...]
	 * Values for duplicate headers are comma-concatenated per RFC 9110 §5.3.
	 * @var array
	 */
	private static $headers = array();

	/**
	 * Raw header values without concatenation: ['header-name' => ['val1', 'val2'], ...]
	 * Keys are lowercased for lookup; original-case names stored in $headerNames.
	 * @var array
	 */
	private static $headersRaw = array();

	/**
	 * Original-case header names: ['header-name' => 'Header-Name', ...]
	 * @var array
	 */
	private static $headerNames = array();

	/**
	 * Set headers for the current request. Called by the server before
	 * dispatching to a PHP script.
	 *
	 * @param array $headers  Parsed headers ['header-name' => 'value']
	 *   with duplicate values already comma-concatenated.
	 * @param array $headersRaw  Raw header lines ['Header-Name: value', ...]
	 *   preserving original case and duplicate entries.
	 */
	static function set($headers, $headersRaw = array())
	{
		self::$headers = array();
		self::$headersRaw = array();
		self::$headerNames = array();

		// Build the original-case map from raw lines if available
		if ($headersRaw) {
			foreach ($headersRaw as $line) {
				$pos = strpos($line, ':');
				if ($pos === false) continue;
				$name = trim(substr($line, 0, $pos));
				$value = trim(substr($line, $pos + 1));
				$lower = strtolower($name);
				self::$headerNames[$lower] = $name;
				self::$headersRaw[$lower][] = $value;
			}
		}

		// Build the getallheaders() result using original case
		foreach ($headers as $k => $v) {
			$lower = strtolower($k);
			// Use the original-case name if we have it, else Title-Case
			$origName = self::$headerNames[$lower]
				?? str_replace(' ', '-', ucwords(str_replace('-', ' ', $k)));
			self::$headers[$origName] = $v;

			// If we don't have raw entries, create them from the parsed value
			if (!isset(self::$headersRaw[$lower])) {
				self::$headersRaw[$lower] = array($v);
				self::$headerNames[$lower] = $origName;
			}
		}
	}

	/**
	 * Get all headers for the current request (like Apache's getallheaders).
	 * Returns ['Content-Type' => 'text/html', 'Accept' => 'text/html, application/json', ...]
	 * Duplicate headers are comma-concatenated per RFC 9110 §5.3.
	 *
	 * @return array
	 */
	static function getAll()
	{
		return self::$headers;
	}

	/**
	 * Get raw (non-concatenated) values for a specific header.
	 * Returns an array of individual values, one per occurrence in the request.
	 * Useful for headers like Cookie where comma-concatenation is lossy.
	 *
	 * @param string $name  Header name (case-insensitive)
	 * @return array  Individual values, or empty array if header not present
	 */
	static function getRaw($name)
	{
		return self::$headersRaw[strtolower($name)] ?? array();
	}

	/**
	 * Get all headers with raw (non-concatenated) values.
	 * Returns ['Header-Name' => ['value1', 'value2'], ...]
	 *
	 * @return array
	 */
	static function getAllRaw()
	{
		$result = array();
		foreach (self::$headersRaw as $lower => $values) {
			$name = self::$headerNames[$lower] ?? $lower;
			$result[$name] = $values;
		}
		return $result;
	}

	/**
	 * Register the global getallheaders() function if it doesn't already exist.
	 * Called once at server startup.
	 */
	static function register()
	{
		if (!function_exists('getallheaders')) {
			function getallheaders() {
				return Q_WebServer_GetAllHeaders::getAll();
			}
		}
		if (!function_exists('apache_request_headers')) {
			function apache_request_headers() {
				return Q_WebServer_GetAllHeaders::getAll();
			}
		}
	}
}
