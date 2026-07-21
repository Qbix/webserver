<?php
/**
 * @module Q
 */

/**
 * Represents an internal URI, routed from a URL via config patterns.
 * Compatible subset of the full Qbix Platform's Q_Uri class.
 * Uses the same config format, pattern syntax, and compiled-pattern caching.
 *
 * @class Q_Uri
 */
class Q_Uri
{
	/**
	 * @property $fields
	 * @type array
	 */
	public $fields = array();

	/**
	 * @property $route
	 * @type string|null
	 */
	public $route = null;

	protected $querystring = null;
	protected $anchorstring = null;

	/**
	 * Variable prefixes recognised in route patterns.
	 * Supports both $var and :var (same as Platform).
	 * @property $variablePrefixes
	 * @static
	 */
	public static $variablePrefixes = array('$', ':');
	public static $escapedVariablePrefixes = array('\$', '\:');

	/**
	 * Memoized compiled patterns, merged routes, and path→URI cache.
	 * Survives across forked children via COW — parent compiles once.
	 */
	protected static $compiledPatterns = array();
	protected static $routesCache = null;
	protected static $pathCache = array();

	function __get($name)
	{
		return $this->fields[$name] ?? null;
	}

	function __set($name, $value)
	{
		$this->fields[$name] = $value;
	}

	function __isset($name)
	{
		return isset($this->fields[$name]);
	}

	function toArray()
	{
		return $this->fields;
	}

	/**
	 * Create a Q_Uri from an array of fields.
	 * @method from
	 * @static
	 */
	static function from($fields)
	{
		$uri = new self();
		if (is_array($fields)) {
			$uri->fields = $fields;
		}
		return $uri;
	}

	/**
	 * Get merged routes from Q/routes@start, Q/routes, Q/routes@end.
	 * Same merge order as the full Platform. Memoized.
	 * @method getRoutes
	 * @static
	 * @return {array}
	 */
	static function getRoutes()
	{
		if (isset(self::$routesCache)) {
			return self::$routesCache;
		}
		$routesStart = Q_Config::get('Q', 'routes@start', array());
		$routes = Q_Config::get('Q', 'routes', array());
		$routesEnd = Q_Config::get('Q', 'routes@end', array());
		// Reverse order within each block (later plugins override earlier)
		$result = array();
		foreach (array($routesStart, $routes, $routesEnd) as $source) {
			if (!is_array($source)) continue;
			$keys = array_keys($source);
			$vals = array_values($source);
			$keys = array_reverse($keys);
			$vals = array_reverse($vals);
			foreach ($keys as $i => $k) {
				if (!isset($result[$k])) {
					$result[$k] = $vals[$i];
				}
			}
		}
		self::$routesCache = $result;
		return $result;
	}

	/**
	 * Clear all memoized routing state.
	 * Call when config changes (e.g. --hot reload).
	 * @method clearRouteCache
	 * @static
	 */
	static function clearRouteCache()
	{
		self::$routesCache = null;
		self::$compiledPatterns = array();
		self::$pathCache = array();
	}

	/**
	 * Route a URL path to a Q_Uri using configured routes.
	 * Results are memoized — the same path always returns the same URI.
	 * @method fromPath
	 * @static
	 * @param {string} $path URL path (e.g. "api/users/42")
	 * @return {Q_Uri|null}
	 */
	static function fromPath($path)
	{
		$path = trim($path, '/');
		if (isset(self::$pathCache[$path])) {
			return self::$pathCache[$path];
		}

		$segments = $path !== '' ? explode('/', $path) : array();
		$routes = self::getRoutes();
		if (empty($routes)) {
			self::$pathCache[$path] = null;
			return null;
		}

		foreach ($routes as $pattern => $fields) {
			if (!isset($fields)) continue; // disabled route

			$matched = self::matchSegments($pattern, $segments);
			if ($matched === false) continue;

			// Check regex constraints on matched values
			$valid = true;
			foreach ($matched as $k => $v) {
				if (isset($fields[$k]) && is_string($fields[$k])) {
					if (!preg_match('/' . $fields[$k] . '/', $v)) {
						$valid = false;
						break;
					}
				}
			}
			// Special condition handler (same as Platform)
			if ($valid && !empty($fields[''])) {
				$params = array(
					'uriFields' => $matched,
					'routeFields' => $fields,
					'fields' => array_merge($fields, $matched),
					'pattern' => $pattern,
				);
				if (false === Q::event($fields[''], $params, false, false, $params)) {
					$valid = false;
				}
			}
			if (!$valid) continue;

			// Merge route defaults with matched values
			$uriFields = array();
			foreach ($fields as $k => $v) {
				if ($k === '' || is_int($k)) continue;
				$uriFields[$k] = $v;
			}
			$uriFields = array_merge($uriFields, $matched);

			$uri = new self();
			$uri->fields = $uriFields;
			$uri->route = $pattern;
			self::$pathCache[$path] = $uri;
			return $uri;
		}

		self::$pathCache[$path] = null;
		return null;
	}

	/**
	 * Compile a route pattern into a reusable structure.
	 * Same implementation as the full Platform's Q_Uri::compilePattern().
	 * Memoized by pattern string — compiled once, reused forever.
	 * @method compilePattern
	 * @static
	 * @protected
	 */
	protected static function compilePattern($pattern)
	{
		if (isset(self::$compiledPatterns[$pattern])) {
			return self::$compiledPatterns[$pattern];
		}
		$route_segments = explode('/', $pattern);
		$tailArray = false;
		$tailField = null;
		$valid = true;
		if (substr($pattern, -2) === '[]') {
			$tailArray = true;
			$last_rs = end($route_segments);
			if (!isset($last_rs[0]) || !in_array($last_rs[0], self::$variablePrefixes)) {
				$valid = false;
			} else {
				$tailField = substr($last_rs, 1, -2);
			}
			$route_segments = array_slice($route_segments, 0, -1);
		}
		$segments = array();
		foreach ($route_segments as $rs) {
			$rs_parts = explode('.', $rs);
			$parts = array();
			foreach ($rs_parts as $part) {
				if (!isset($part[0]) || !in_array($part[0], self::$variablePrefixes)) {
					$parts[] = array(
						'var' => false,
						'literal' => str_replace(
							self::$escapedVariablePrefixes,
							self::$variablePrefixes,
							$part
						)
					);
				} else {
					$parts[] = array(
						'var' => true,
						'field' => substr($part, 1)
					);
				}
			}
			$segments[] = $parts;
		}
		$compiled = array(
			'valid' => $valid,
			'segments' => $segments,
			'count' => count($segments),
			'tailArray' => $tailArray,
			'tailField' => $tailField,
		);
		self::$compiledPatterns[$pattern] = $compiled;
		return $compiled;
	}

	/**
	 * Match URL segments against a compiled route pattern.
	 * Same implementation as the full Platform's Q_Uri::matchSegments().
	 * @method matchSegments
	 * @static
	 * @protected
	 */
	protected static function matchSegments($pattern, $segments)
	{
		if (!$pattern && $pattern !== '0') {
			return count($segments) === 0 ? array() : false;
		}
		$compiled = self::compilePattern($pattern);
		if (!$compiled['valid']) return false;

		$count = $compiled['count'];
		$segCount = count($segments);

		if ($compiled['tailArray']) {
			if ($count >= $segCount) return false;
		} else {
			if ($count !== $segCount) return false;
		}

		$args = array();
		$cs = $compiled['segments'];
		for ($i = 0; $i < $count; $i++) {
			$rs_parts = $cs[$i];
			$rs_parts_count = count($rs_parts);
			$segment = urldecode($segments[$i]);
			$s_parts = explode('.', $segment, $rs_parts_count);
			if (count($s_parts) < $rs_parts_count) return false;

			for ($j = 0; $j < $rs_parts_count; $j++) {
				$p = $rs_parts[$j];
				if (!$p['var']) {
					if ($s_parts[$j] !== $p['literal']) return false;
					continue;
				}
				$args[$p['field']] = $s_parts[$j];
			}
		}

		if ($compiled['tailArray']) {
			$args[$compiled['tailField']] = array();
			for (; $i < $segCount; $i++) {
				$args[$compiled['tailField']][] = urldecode($segments[$i]);
			}
		}

		return $args;
	}
}
