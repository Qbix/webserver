<?php
/**
 * @module Q
 */

/**
 * HTTP response header processing for Q_WebServer.
 *
 * Handles special headers that control server behavior
 * (like nginx does), plus compression negotiation:
 *
 * - X-Accel-Redirect: serve a file from an internal path
 *   instead of sending the PHP response body. PHP checks
 *   permissions, sets Content-Type, then the server does
 *   the efficient file I/O. The header is stripped from
 *   the client response.
 *
 * - X-Accel-Buffering: yes/no — controls output buffering
 *
 * - X-Accel-Expires: override Cache-Control for the proxy
 *
 * - Content-Encoding: gzip/br negotiation based on
 *   Accept-Encoding and content type. For static files,
 *   checks for pre-compressed .gz/.br siblings first.
 *
 * @class Q_WebServer_Headers
 */
class Q_WebServer_Headers
{
	/**
	 * Headers that are server directives — never sent to client.
	 * @property $internalHeaders
	 * @static
	 */
	static $internalHeaders = array(
		'x-accel-redirect',
		'x-accel-buffering',
		'x-accel-charset',
	);

	/**
	 * Content types eligible for compression.
	 * @property $compressibleTypes
	 * @static
	 */
	static $compressibleTypes = array(
		'text/html', 'text/css', 'text/plain', 'text/xml',
		'text/csv', 'text/yaml',
		'application/javascript', 'application/json',
		'application/xml', 'application/rss+xml',
		'application/atom+xml', 'image/svg+xml',
	);

	/**
	 * Minimum body size to bother compressing.
	 * @property $compressMinSize
	 * @static
	 */
	static $compressMinSize = 1024;

	/**
	 * Process a response from PHP (worker pool or in-process).
	 * Handles X-Accel-Redirect and compression. Returns the
	 * final response to send to the client.
	 *
	 * @method processResponse
	 * @static
	 * @param {resource} $client Socket to write to
	 * @param {array} $response [status, body, headers] from PHP
	 * @param {array} $requestHeaders Original request headers
	 *  (needed for Accept-Encoding)
	 * @return {boolean} true if response was fully handled
	 */
	static function processResponse($client, $response, $requestHeaders)
	{
		$status = $response['status'] ?? 200;
		$body = $response['body'] ?? '';
		$headers = $response['headers'] ?? array();

		// ── X-Accel-Redirect ─────────────────────────────
		// PHP script says "serve this internal file instead"
		$accelPath = null;
		foreach ($headers as $k => $v) {
			if (strtolower($k) === 'x-accel-redirect') {
				$accelPath = $v;
			}
		}

		if ($accelPath) {
			// Strip internal headers from response
			$headers = self::stripInternal($headers);

			// Resolve the internal path
			$fsPath = self::resolveAccelPath($accelPath);
			if ($fsPath && is_file($fsPath)) {
				// Serve the file, keeping Content-Type and other
				// headers the PHP script set
				self::serveAccelFile($client, $fsPath, $headers, $requestHeaders);
				return true;
			}

			// Path not found — send 404
			Q_WebServer::sendResponse($client, 404, 'X-Accel-Redirect: file not found');
			return true;
		}

		// ── Strip internal headers ───────────────────────
		$headers = self::stripInternal($headers);

		// ── Compression ──────────────────────────────────
		$ct = '';
		foreach ($headers as $k => $v) {
			if (strtolower($k) === 'content-type') $ct = $v;
		}
		$body = self::maybeCompress($body, $ct, $requestHeaders, $headers);

		// ── Send response ────────────────────────────────
		$headers['Content-Length'] = strlen($body);
		$headers['Connection'] = 'close';

		static $reasons = array(
			200=>'OK', 201=>'Created', 204=>'No Content',
			301=>'Moved Permanently', 302=>'Found', 304=>'Not Modified',
			400=>'Bad Request', 401=>'Unauthorized', 403=>'Forbidden',
			404=>'Not Found', 405=>'Method Not Allowed',
			413=>'Payload Too Large', 500=>'Internal Server Error',
			502=>'Bad Gateway', 503=>'Service Unavailable',
		);

		$reason = $reasons[$status] ?? 'OK';
		$out = "HTTP/1.1 $status $reason\r\n";
		foreach ($headers as $k => $v) {
			$out .= "$k: $v\r\n";
		}
		@fwrite($client, $out . "\r\n" . $body);
		return true;
	}

	/**
	 * Serve a file via X-Accel-Redirect, applying compression
	 * if appropriate. Merges headers the PHP script set
	 * (Content-Type, Cache-Control, etc.) with file serving headers.
	 *
	 * @method serveAccelFile
	 * @static
	 */
	static function serveAccelFile($client, $fsPath, $phpHeaders, $requestHeaders)
	{
		clearstatcache(true, $fsPath);
		$size = filesize($fsPath);
		$mtime = filemtime($fsPath);
		$ext = strtolower(pathinfo($fsPath, PATHINFO_EXTENSION));

		// Start with headers from PHP, fill in defaults
		$headers = $phpHeaders;
		if (!self::hasHeader($headers, 'Content-Type')) {
			$headers['Content-Type'] = Q_WebServer::mimeType($ext);
		}
		if (!self::hasHeader($headers, 'Cache-Control')) {
			$headers['Cache-Control'] = 'public, max-age=0, must-revalidate';
		}

		// ETag / Last-Modified
		$etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';
		$headers['ETag'] = $etag;
		$headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

		// Check for pre-compressed version
		$compressed = self::findPreCompressed($fsPath, $requestHeaders);
		if ($compressed) {
			$headers['Content-Encoding'] = $compressed['encoding'];
			$headers['Content-Length'] = $compressed['size'];
			$headers['Vary'] = 'Accept-Encoding';
			$headers['Connection'] = 'close';

			$out = "HTTP/1.1 200 OK\r\n";
			foreach ($headers as $k => $v) $out .= "$k: $v\r\n";
			fwrite($client, $out . "\r\n");

			$fp = fopen($compressed['path'], 'rb');
			while (!feof($fp)) {
				$data = fread($fp, 65536);
				if ($data === false || @fwrite($client, $data) === false) break;
			}
			fclose($fp);
			return;
		}

		// Check if we should compress on-the-fly
		$ct = '';
		foreach ($headers as $k => $v) {
			if (strtolower($k) === 'content-type') $ct = $v;
		}
		$shouldCompress = self::shouldCompress($ct, $size, $requestHeaders);

		if ($shouldCompress && $size < 5242880) { // < 5MB: read + compress + send
			$body = file_get_contents($fsPath);
			$body = self::maybeCompress($body, $ct, $requestHeaders, $headers);
			$headers['Content-Length'] = strlen($body);
			$headers['Connection'] = 'close';

			$out = "HTTP/1.1 200 OK\r\n";
			foreach ($headers as $k => $v) $out .= "$k: $v\r\n";
			@fwrite($client, $out . "\r\n" . $body);
			return;
		}

		// No compression — stream directly
		$headers['Content-Length'] = $size;
		$headers['Connection'] = 'close';

		$out = "HTTP/1.1 200 OK\r\n";
		foreach ($headers as $k => $v) $out .= "$k: $v\r\n";
		fwrite($client, $out . "\r\n");

		$fp = fopen($fsPath, 'rb');
		while (!feof($fp)) {
			$data = fread($fp, 65536);
			if ($data === false || @fwrite($client, $data) === false) break;
		}
		fclose($fp);
	}

	/**
	 * Check for pre-compressed .gz or .br sibling files
	 * (like nginx gzip_static / brotli_static).
	 *
	 * @method findPreCompressed
	 * @static
	 * @param {string} $fsPath Original file path
	 * @param {array} $requestHeaders
	 * @return {array|null} [path, encoding, size] or null
	 */
	static function findPreCompressed($fsPath, $requestHeaders)
	{
		$accept = strtolower($requestHeaders['accept-encoding'] ?? '');

		// Prefer brotli over gzip
		if (strpos($accept, 'br') !== false) {
			$brPath = $fsPath . '.br';
			if (file_exists($brPath)) {
				clearstatcache(true, $brPath);
				// Only use if not older than original
				if (filemtime($brPath) >= filemtime($fsPath)) {
					return array(
						'path' => $brPath,
						'encoding' => 'br',
						'size' => filesize($brPath)
					);
				}
			}
		}

		if (strpos($accept, 'gzip') !== false) {
			$gzPath = $fsPath . '.gz';
			if (file_exists($gzPath)) {
				clearstatcache(true, $gzPath);
				if (filemtime($gzPath) >= filemtime($fsPath)) {
					return array(
						'path' => $gzPath,
						'encoding' => 'gzip',
						'size' => filesize($gzPath)
					);
				}
			}
		}

		return null;
	}

	/**
	 * Maybe compress a response body (gzip on-the-fly).
	 * Modifies $headers by reference to add Content-Encoding + Vary.
	 *
	 * @method maybeCompress
	 * @static
	 * @param {string} $body
	 * @param {string} $contentType
	 * @param {array} $requestHeaders
	 * @param {array} &$headers Response headers (modified)
	 * @return {string} Possibly compressed body
	 */
	static function maybeCompress($body, $contentType, $requestHeaders, &$headers)
	{
		if (!self::shouldCompress($contentType, strlen($body), $requestHeaders)) {
			return $body;
		}

		$accept = strtolower($requestHeaders['accept-encoding'] ?? '');

		// Try gzip (universally supported, no ext needed)
		if (strpos($accept, 'gzip') !== false && function_exists('gzencode')) {
			$compressed = gzencode($body, 6);
			if ($compressed !== false && strlen($compressed) < strlen($body)) {
				$headers['Content-Encoding'] = 'gzip';
				$headers['Vary'] = 'Accept-Encoding';
				return $compressed;
			}
		}

		return $body;
	}

	/**
	 * Check whether a response should be compressed.
	 *
	 * @method shouldCompress
	 * @static
	 * @param {string} $contentType
	 * @param {integer} $bodySize
	 * @param {array} $requestHeaders
	 * @return {boolean}
	 */
	static function shouldCompress($contentType, $bodySize, $requestHeaders)
	{
		if ($bodySize < self::$compressMinSize) return false;
		if (empty($requestHeaders['accept-encoding'])) return false;

		// Check content type (strip charset parameter)
		$baseType = strtolower(strtok($contentType, ';'));
		return in_array($baseType, self::$compressibleTypes);
	}

	/**
	 * Resolve an X-Accel-Redirect path to a filesystem path.
	 *
	 * Supports two patterns:
	 *   /Q/internal/...  → maps to configured internal directory
	 *   /absolute/path   → maps relative to document root
	 *
	 * Config: Q.webserver.accel.mappings = { "/Q/internal": "/path/on/disk" }
	 *
	 * @method resolveAccelPath
	 * @static
	 * @param {string} $accelPath The X-Accel-Redirect value
	 * @return {string|null} Filesystem path or null
	 */
	static function resolveAccelPath($accelPath)
	{
		// Check configured mappings first
		$mappings = Q_Config::get('Q', 'webserver', 'accel', 'mappings', array());
		foreach ($mappings as $prefix => $diskPath) {
			if (strpos($accelPath, $prefix) === 0) {
				$relative = substr($accelPath, strlen($prefix));
				$fsPath = rtrim($diskPath, DS) . DS
					. ltrim(str_replace('/', DS, $relative), DS);
				$real = realpath($fsPath);
				// Ensure we don't escape the mapped directory
				if ($real && strpos($real, realpath($diskPath)) === 0) {
					return $real;
				}
				return null;
			}
		}

		// Default: resolve relative to APP_DIR (not web root —
		// the point is to serve files OUTSIDE the web root)
		if (defined('APP_DIR')) {
			$fsPath = APP_DIR . DS . ltrim(str_replace('/', DS, $accelPath), DS);
			$real = realpath($fsPath);
			if ($real && strpos($real, realpath(APP_DIR)) === 0) {
				return $real;
			}
		}

		return null;
	}

	/**
	 * Strip internal/server-directive headers from a response.
	 *
	 * @method stripInternal
	 * @static
	 * @param {array} $headers
	 * @return {array} Cleaned headers
	 */
	static function stripInternal($headers)
	{
		$result = array();
		foreach ($headers as $k => $v) {
			if (!in_array(strtolower($k), self::$internalHeaders)) {
				$result[$k] = $v;
			}
		}
		return $result;
	}

	/**
	 * Check if a header exists (case-insensitive).
	 */
	static function hasHeader($headers, $name)
	{
		$lower = strtolower($name);
		foreach ($headers as $k => $v) {
			if (strtolower($k) === $lower) return true;
		}
		return false;
	}
}
