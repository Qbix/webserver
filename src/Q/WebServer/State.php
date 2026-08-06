<?php

/**
 * Per-request state for a LONG-RUNNING server.
 *
 * A classic PHP SAPI ends the process after each request, so nothing needs to
 * swap the request body back or reset accumulated response state. A forking
 * server does. That is why these methods exist — and why they must NOT live on
 * Q_Request / Q_Response, which the Platform also defines. In --app mode the
 * Platform's versions win and have none of them, so every call was a fatal.
 *
 * Where the Platform offers an equivalent we defer to it; the rest is ours.
 *
 * @class Q_WebServer_State
 */
class Q_WebServer_State
{
	protected static $savedInput = null;
	protected static $headers = array();
	protected static $code = 200;

	protected static $inputRegistered = false;

	/**
	 * Swap in the raw body for this request.
	 *
	 * A forking server reads the request off the socket itself, so the real
	 * php://input stream is already consumed and stays empty for the rest of the
	 * process. We therefore register Q_PhpInputStream over the `php` wrapper so
	 * file_get_contents('php://input') returns THIS request's body — the same
	 * trick as capturing header() output, applied to the input side.
	 */
	static function setInput($body)
	{
		self::$savedInput = isset($GLOBALS['HTTP_RAW_POST_DATA'])
			? $GLOBALS['HTTP_RAW_POST_DATA'] : null;
		$GLOBALS['HTTP_RAW_POST_DATA'] = $body;

		// Q_PhpInputStream lives in src/Q.php, which --app mode deliberately
		// does NOT load (the Platform's Q wins). Without it the wrapper was
		// never registered there, so file_get_contents('php://input') returned
		// empty for every request under --app while working standalone.
		// Q_WebServer_PhpInput is webserver-owned and loads in BOTH modes.
		$streamClass = class_exists('Q_PhpInputStream', false)
			? 'Q_PhpInputStream'
			: 'Q_WebServer_PhpInput';
		if (!self::$inputRegistered) {
			@stream_wrapper_unregister('php');
			stream_wrapper_register('php', $streamClass);
			self::$inputRegistered = true;
		}
		$streamClass::$data = $body;

		// Let the Platform re-parse the input if it knows how.
		if (method_exists('Q_Request', 'handleInput')) {
			try { Q_Request::handleInput(); } catch (Exception $e) { /* non-fatal */ }
		}
	}

	/** Restore the native php:// wrapper and the previous input. */
	static function restoreInput()
	{
		if (self::$inputRegistered) {
			stream_wrapper_unregister('php');
			stream_wrapper_restore('php');
			self::$inputRegistered = false;
		}
		if (self::$savedInput === null) {
			unset($GLOBALS['HTTP_RAW_POST_DATA']);
		} else {
			$GLOBALS['HTTP_RAW_POST_DATA'] = self::$savedInput;
		}
		self::$savedInput = null;
	}

	/** Reset accumulated response state between requests. */
	static function clear()
	{
		self::$headers = array();
		self::$code = 200;
		if (method_exists('Q_Response', 'clearAllCookies')) {
			try { Q_Response::clearAllCookies(); } catch (Exception $e) { /* non-fatal */ }
		}
	}

	/** Set a header by name/value (what Q_Response::setHeader() did). */
	static function setHeader($name, $value, $replace = true)
	{
		if ($replace || !isset(self::$headers[$name])) {
			self::$headers[$name] = $value;
		} else {
			self::$headers[$name] .= ', ' . $value;
		}
	}

	/** Read one header back. */
	static function getHeader($name)
	{
		return isset(self::$headers[$name]) ? self::$headers[$name] : null;
	}

	/** Drop all accumulated headers. */
	static function clearHeaders()
	{
		self::$headers = array();
	}

	/** Record a header emitted during this request. */
	static function header($header, $replace = true, $code = 0)
	{
		// A status line ("HTTP/1.1 201 Created") sets the code, it is not a
		// header. PHP's own header() works this way; splitting it on ':' would
		// emit a bogus header named after the whole line.
		if (strncasecmp($header, 'HTTP/', 5) === 0) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
				self::$code = (int) $m[1];
			}
			return;
		}
		$parts = explode(':', $header, 2);
		$name = trim($parts[0]);
		$value = isset($parts[1]) ? trim($parts[1]) : '';
		if ($replace || !isset(self::$headers[$name])) {
			self::$headers[$name] = $value;
		} else {
			self::$headers[$name] .= ', ' . $value;
		}
		if ($code) { self::$code = $code; }
	}

	/** Headers accumulated for this request. */
	static function getHeaders()
	{
		return self::$headers;
	}

	/** Cookie headers, from the Platform when it can produce them. */
	static function cookieHeaders()
	{
		// Build the Set-Cookie strings HERE, from Q_Response::$cookies.
		//
		// That property is public static in BOTH the Platform's Q_Response and
		// this repo's shim, with the same tuple layout, so reading it is safe
		// in either mode. Calling a method instead is not: cookieHeaders()
		// exists only on the shim (fatal under --app), and sendCookieHeaders()
		// only invokes native setcookie() -- a no-op under CLI -- and returns
		// nothing, so it yields no headers at all.
		if (!property_exists('Q_Response', 'cookies')) {
			return array();
		}
		$cookies = Q_Response::$cookies;
		if (!is_array($cookies)) {
			return array();
		}
		$headers = array();
		foreach ($cookies as $name => $args) {
			if (!is_array($args)) continue;
			$value    = $args[0] ?? '';
			$expires  = $args[1] ?? 0;
			$path     = $args[2] ?? '/';
			$domain   = $args[3] ?? '';
			$secure   = $args[4] ?? false;
			$httponly = $args[5] ?? false;
			$samesite = $args[6] ?? null;

			$parts = array(urlencode($name) . '=' . urlencode($value));
			if ($expires) {
				$parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', $expires);
				$parts[] = 'Max-Age=' . max(0, $expires - time());
			}
			$parts[] = 'Path=' . ($path ?: '/');
			if ($domain)   $parts[] = 'Domain=' . ($domain === true ? '' : $domain);
			if ($secure)   $parts[] = 'Secure';
			if ($httponly) $parts[] = 'HttpOnly';
			if ($samesite) $parts[] = 'SameSite=' . $samesite;
			$headers[] = implode('; ', $parts);
		}
		return $headers;
	}

	/**
	 * Set the code WITHOUT forwarding to Q_Response::code().
	 * responseCode() forwards, so callers reached from Q_Response::code()
	 * must use this instead or the two would call each other forever.
	 */
	static function setCode($code)
	{
		self::$code = (int) $code;
	}

	/** Get or set the response code. Defers to the Platform's code() if present. */
	static function responseCode($code = null)
	{
		if ($code !== null) {
			self::$code = $code;
			if (method_exists('Q_Response', 'code')) {
				try { Q_Response::code($code); } catch (Exception $e) { /* non-fatal */ }
			}
		}
		return self::$code;
	}
}
