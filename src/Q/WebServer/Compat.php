<?php
/**
 * Q_WebServer_Compat — framework compatibility layer.
 *
 * In CLI SAPI (which the webserver uses), PHP's built-in header(),
 * setcookie(), http_response_code(), session_start() etc. either
 * silently fail or go nowhere. This class provides two mechanisms
 * to make framework code work without modification:
 *
 * 1. Source transformation: token_get_all() rewrites global function
 *    calls in included files (header() → Q_Response::header(), etc.)
 *    Transformed files are cached to disk for performance.
 *
 * 2. URL rewriting: routes all requests to a front controller
 *    (e.g., index.php for Laravel/WordPress) based on config.
 *
 * 3. $_FILES population from multipart/form-data bodies.
 *
 * 4. Session handling with file locking.
 *
 * Enable in config:
 *   { "Q": { "compat": { "enabled": true, "rewrite": "index.php" } } }
 *
 * @class Q_WebServer_Compat
 */
class Q_WebServer_Compat
{
	/**
	 * Function replacements: built-in → Q_Response/Q_Request equivalent.
	 * Keys are lowercase function names.
	 */
	private static $replacements = array(
		'header'               => 'Q_WebServer_Compat::_header',
		'setcookie'            => 'Q_WebServer_Compat::_setcookie',
		'setrawcookie'         => 'Q_WebServer_Compat::_setrawcookie',
		'http_response_code'   => 'Q_WebServer_Compat::_http_response_code',
		'headers_sent'         => 'Q_WebServer_Compat::_headers_sent',
		'headers_list'         => 'Q_WebServer_Compat::_headers_list',
		'header_remove'        => 'Q_WebServer_Compat::_header_remove',
		'session_start'        => 'Q_WebServer_Compat::_session_start',
		'session_write_close'  => 'Q_WebServer_Compat::_session_write_close',
		'session_regenerate_id'=> 'Q_WebServer_Compat::_session_regenerate_id',
		'session_destroy'      => 'Q_WebServer_Compat::_session_destroy',
		'session_status'       => 'Q_WebServer_Compat::_session_status',
		'move_uploaded_file'   => 'Q_WebServer_Compat::_move_uploaded_file',
		'is_uploaded_file'     => 'Q_WebServer_Compat::_is_uploaded_file',
		'ini_get'              => 'Q_WebServer_Compat::_ini_get',
		'ini_set'              => 'Q_WebServer_Compat::_ini_set',
		'set_time_limit'       => 'Q_WebServer_Compat::_set_time_limit',
		'getallheaders'        => 'Q_WebServer_Compat::_getallheaders',
		'apache_request_headers' => 'Q_WebServer_Compat::_getallheaders',
	);

	/** @var array In-memory transform cache: realpath → ['source' => ..., 'mtime' => ...] */
	private static $transformCache = array();

	/** @var bool Whether compat mode is active */
	private static $enabled = false;

	/** @var array Uploaded file paths (for move_uploaded_file validation) */
	static $uploadedFiles = array();

	/** @var bool Whether headers have been flushed to the client */
	private static $headersSent = false;

	/** @var bool Whether a session is currently active */
	private static $sessionActive = false;

	/** @var string Current session file path */
	private static $sessionFile = '';

	/** @var resource|null Session file handle (held for locking) */
	private static $sessionFp = null;

	/** @var array Request headers (set by the server before dispatch) */
	private static $requestHeaders = array();

	/**
	 * Initialize the compatibility layer.
	 * Call this before dispatching any user PHP.
	 */
	static function init()
	{
		$enabled = Q_Config::get('Q', 'compat', 'enabled', false);
		if (!$enabled) return;

		self::$enabled = true;

		// Register custom include wrapper
		stream_wrapper_unregister('file');
		stream_wrapper_register('file', 'Q_WebServer_CompatFileWrapper');
	}

	/**
	 * Shut down the compatibility layer.
	 * Cleans up temp files, closes sessions, restores file:// wrapper.
	 */
	static function shutdown()
	{
		if (!self::$enabled) return;

		// Close any open session
		if (self::$sessionActive) {
			self::_session_write_close();
		}

		// Clean up temp upload files
		foreach (self::$uploadedFiles as $path => $flag) {
			if (file_exists($path)) @unlink($path);
		}

		self::$headersSent = false;
		self::$uploadedFiles = array();
		self::$sessionActive = false;
		self::$sessionFile = '';
		self::$sessionFp = null;
		self::$requestHeaders = array();

		@stream_wrapper_restore('file');
	}

	/**
	 * Set the request headers (called by the server before dispatch).
	 * @param array $headers Associative array of headers
	 */
	static function setRequestHeaders($headers)
	{
		self::$requestHeaders = $headers;
	}

	/**
	 * Check if compat mode is active.
	 * @return bool
	 */
	static function isEnabled()
	{
		return self::$enabled;
	}

	/**
	 * Transform PHP source code by replacing built-in function calls
	 * with our compatibility shims.
	 *
	 * Uses token_get_all() for safe, accurate replacement. Only replaces
	 * global function calls — method calls ($obj->header()) and
	 * fully-qualified calls (\header()) are left alone unless they're
	 * the built-in.
	 *
	 * @param string $source PHP source code
	 * @param string $filePath Original file path (for cache key)
	 * @return string Transformed source
	 */
	static function transformSource($source, $filePath = '')
	{
		// Check cache first
		if ($filePath) {
			$cached = self::getCachedTransform($filePath);
			if ($cached !== null) return $cached;
		}

		$tokens = token_get_all($source);
		$count = count($tokens);
		$out = '';
		$changed = false;

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];

			if (!is_array($token)) {
				$out .= $token;
				continue;
			}

			// Only look at T_STRING (function/constant names)
			// PHP 8+: \header() is T_NAME_FULLY_QUALIFIED, Ns\header() is T_NAME_QUALIFIED
			if ($token[0] === T_STRING) {
				$name = strtolower($token[1]);
			} elseif (defined('T_NAME_FULLY_QUALIFIED') && $token[0] === T_NAME_FULLY_QUALIFIED) {
				// \header(), \setcookie(), etc. — strip the leading backslash
				$name = strtolower(ltrim($token[1], '\\'));
			} else {
				$out .= $token[1];
				continue;
			}

			if (!isset(self::$replacements[$name])) {
				$out .= $token[1];
				continue;
			}

			// For T_NAME_FULLY_QUALIFIED, it's always a global call — skip the method/function check
			if (defined('T_NAME_FULLY_QUALIFIED') && $token[0] === T_NAME_FULLY_QUALIFIED) {
				// Still verify it's followed by '('
				$hasParen = false;
				for ($j = $i + 1; $j < $count; $j++) {
					if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
					if ($tokens[$j] === '(') $hasParen = true;
					break;
				}
				if ($hasParen) {
					$out .= self::$replacements[$name];
					$changed = true;
					continue;
				}
				$out .= $token[1];
				continue;
			}

			// Check: is this a global function call?
			// Must have '(' after it (skipping whitespace)
			// Must NOT have '->' or '::' before it (that's a method call)
			// Must NOT have 'function' before it (that's a definition)
			if (!self::isGlobalFunctionCall($tokens, $i, $count)) {
				$out .= $token[1];
				continue;
			}

			// If preceded by '\' (T_NS_SEPARATOR), strip it from output
			// since we're replacing \header() → Q_WebServer_Compat::_header()
			$prevIdx = $i - 1;
			while ($prevIdx >= 0 && is_array($tokens[$prevIdx]) && $tokens[$prevIdx][0] === T_WHITESPACE) $prevIdx--;
			if ($prevIdx >= 0 && is_array($tokens[$prevIdx]) && $tokens[$prevIdx][0] === T_NS_SEPARATOR) {
				$out = substr($out, 0, -1); // remove the trailing '\'
			}

			$out .= self::$replacements[$name];
			$changed = true;
		}

		$result = $changed ? $out : $source;

		// Save to cache
		if ($changed && $filePath) {
			self::saveCache($filePath, $result);
		}

		return $result;
	}

	/**
	 * Check if token at position $i is a global function call.
	 */
	private static function isGlobalFunctionCall($tokens, $i, $count)
	{
		// Must be followed by '('
		$foundParen = false;
		for ($j = $i + 1; $j < $count; $j++) {
			if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
			if ($tokens[$j] === '(') { $foundParen = true; }
			break;
		}
		if (!$foundParen) return false;

		// Must NOT be preceded by '->', '::', 'function', 'new'
		// Special case: '\' (T_NS_SEPARATOR) means \header() — that IS global
		// UNLESS there's a T_STRING before the '\' (Namespace\header() — NOT global)
		for ($j = $i - 1; $j >= 0; $j--) {
			if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
			if (is_array($tokens[$j])) {
				$type = $tokens[$j][0];
				if ($type === T_OBJECT_OPERATOR  // ->header()
				 || $type === T_DOUBLE_COLON     // Class::header()
				 || $type === T_FUNCTION          // function header()
				 || $type === T_NEW               // new header()
				) {
					return false;
				}
				if ($type === T_NS_SEPARATOR) {
					// \header() — check if it's standalone or Namespace\header()
					for ($k = $j - 1; $k >= 0; $k--) {
						if (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) continue;
						if (is_array($tokens[$k]) && $tokens[$k][0] === T_STRING) {
							return false; // Namespace\header() — not our target
						}
						break; // standalone \header() — IS global
					}
					// It's \header() with no namespace prefix — treat as global
					// Mark position so the caller can strip the backslash from output
					return true;
				}
			}
			break;
		}

		return true;
	}

	/**
	 * Get cached entry from memory.
	 * Returns: string (transformed source), false (sentinel — no transform needed),
	 * or null (not in cache, file added after prewarm).
	 */
	static function getCachedTransform($filePath)
	{
		if (!isset(self::$transformCache[$filePath])) return null;
		$entry = self::$transformCache[$filePath];
		return $entry['source'];  // string, false (sentinel), never null
	}

	/**
	 * Store transformed source in the in-memory cache.
	 */
	private static function saveCache($filePath, $transformed)
	{
		self::$transformCache[$filePath] = array(
			'source' => $transformed,
			'mtime' => filemtime($filePath),
		);
	}

	/**
	 * Pre-warm the transform cache by walking a directory.
	 * Call from the parent process before accepting connections.
	 * Fork children inherit the cache via COW — zero per-request cost.
	 *
	 * @param string $dir Directory to walk (e.g., the app root)
	 * @param int $maxFiles Safety limit (default 5000)
	 * @return int Number of files pre-warmed
	 */
	static function prewarm($dir, $maxFiles = 5000)
	{
		$count = 0;
		$dir = rtrim($dir, DIRECTORY_SEPARATOR);
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ($iterator as $file) {
			if ($count >= $maxFiles) break;
			if (!$file->isFile()) continue;
			if ($file->getExtension() !== 'php') continue;
			$path = $file->getRealPath();
			// Skip vendor test files (large, rarely included)
			if (strpos($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR) !== false) continue;
			if (strpos($path, DIRECTORY_SEPARATOR . 'Tests' . DIRECTORY_SEPARATOR) !== false) continue;
			$source = file_get_contents($path);
			if ($source === false) continue;
			$transformed = self::transformSource($source, $path);
			if ($transformed !== $source) {
				// Transformed — cache the new source
				self::$transformCache[$path] = array(
					'source' => $transformed,
					'mtime' => $file->getMTime(),
				);
			} else {
				// No transforms needed — cache a sentinel so we skip this file
				// entirely at request time (no tokenization, no disk read)
				self::$transformCache[$path] = array(
					'source' => false,  // sentinel: means "pass through unchanged"
					'mtime' => $file->getMTime(),
				);
			}
			$count++;
		}
		return $count;
	}

	/**
	 * Invalidate cached entries whose files changed on disk.
	 * Call from the parent process on a timer (e.g., every 2 seconds)
	 * for hot-reload during development.
	 *
	 * @return int Number of entries invalidated
	 */
	static function invalidateStale()
	{
		$invalidated = 0;
		foreach (self::$transformCache as $path => $entry) {
			if (!file_exists($path) || filemtime($path) > $entry['mtime']) {
				unset(self::$transformCache[$path]);
				$invalidated++;
			}
		}
		return $invalidated;
	}

	/**
	 * Get cache stats (for dashboard/debugging).
	 * @return array
	 */
	static function cacheStats()
	{
		$totalBytes = 0;
		$transforms = 0;
		$passthrough = 0;
		foreach (self::$transformCache as $entry) {
			if ($entry['source'] === false) {
				$passthrough++;
			} else {
				$transforms++;
				$totalBytes += strlen($entry['source']);
			}
		}
		return array(
			'entries' => count(self::$transformCache),
			'transforms' => $transforms,
			'passthrough' => $passthrough,
			'bytes' => $totalBytes,
		);
	}

	// ── Shim functions ──────────────────────────────────
	// These replace the built-in functions in transformed source.
	// They delegate to Q_Response which the server reads after script execution.

	/**
	 * Replacement for header().
	 * @param string $string The header string
	 * @param bool $replace Whether to replace a previous similar header
	 * @param int|null $response_code Optional HTTP status code
	 */
	static function _header($string, $replace = true, $response_code = null)
	{
		if ($response_code !== null) {
			Q_Response::code($response_code);
		}

		// Parse "HTTP/1.1 404 Not Found" style headers
		if (preg_match('/^HTTP\/[\d.]+\s+(\d+)/i', $string, $m)) {
			Q_Response::code((int) $m[1]);
			return;
		}

		Q_Response::header($string, $replace);
	}

	/**
	 * Replacement for setcookie().
	 */
	static function _setcookie(
		$name, $value = '', $expires_or_options = 0,
		$path = '', $domain = '', $secure = false, $httponly = false
	) {
		// Handle PHP 7.3+ options array form
		if (is_array($expires_or_options)) {
			$opts = $expires_or_options;
			$expires  = $opts['expires'] ?? 0;
			$path     = $opts['path'] ?? '';
			$domain   = $opts['domain'] ?? '';
			$secure   = $opts['secure'] ?? false;
			$httponly  = $opts['httponly'] ?? false;
			$samesite = $opts['samesite'] ?? '';
		} else {
			$expires = $expires_or_options;
			$samesite = '';
		}

		Q_Response::setCookie($name, $value, $expires, $path, $domain, $secure, $httponly, $samesite);
	}

	/**
	 * Replacement for setrawcookie().
	 */
	static function _setrawcookie(
		$name, $value = '', $expires_or_options = 0,
		$path = '', $domain = '', $secure = false, $httponly = false
	) {
		// Same as setcookie but without URL encoding — delegate and let Q_Response handle it
		self::_setcookie($name, $value, $expires_or_options, $path, $domain, $secure, $httponly);
	}

	/**
	 * Replacement for http_response_code().
	 * @param int|null $code
	 * @return int
	 */
	static function _http_response_code($code = null)
	{
		if ($code !== null) {
			Q_Response::code($code);
		}
		return Q_Response::code() ?: 200;
	}

	/**
	 * Replacement for headers_sent().
	 * @return bool
	 */
	static function _headers_sent(&$file = null, &$line = null)
	{
		return self::$headersSent;
	}

	/**
	 * Replacement for headers_list().
	 * @return array
	 */
	static function _headers_list()
	{
		$result = array();
		foreach (Q_WebServer_State::getHeaders() as $name => $value) {
			$result[] = "$name: $value";
		}
		return $result;
	}

	/**
	 * Replacement for header_remove().
	 * @param string|null $name
	 */
	static function _header_remove($name = null)
	{
		if ($name === null) {
			// Remove all headers — reset
			Q_WebServer_State::clear();
		} else {
			Q_Response::header("$name:");  // empty value removes it
		}
	}

	/**
	 * Replacement for session_start().
	 * File-based sessions with proper locking for concurrent requests.
	 */
	static function _session_start($options = array())
	{
		if (self::$sessionActive) return true;

		$savePath = $options['save_path']
			?? self::_ini_get('session.save_path')
			?: sys_get_temp_dir();
		$name = $options['name']
			?? session_name()
			?: 'PHPSESSID';
		$maxLifetime = (int) ($options['gc_maxlifetime']
			?? self::_ini_get('session.gc_maxlifetime')
			?: 1440);
		$id = $_COOKIE[$name] ?? '';

		if (!$id || !preg_match('/^[a-zA-Z0-9,-]{22,256}$/', $id)) {
			$id = bin2hex(random_bytes(16));
			self::_setcookie($name, $id, 0, '/');
		}

		session_id($id);
		self::$sessionFile = $savePath . DIRECTORY_SEPARATOR . 'sess_' . $id;

		// Read with exclusive lock (held until write_close)
		$_SESSION = array();
		self::$sessionFp = fopen(self::$sessionFile, 'c+');
		if (self::$sessionFp) {
			flock(self::$sessionFp, LOCK_EX);
			$data = stream_get_contents(self::$sessionFp);
			if ($data) {
				$_SESSION = self::unserializeSession($data);
			}
		}

		self::$sessionActive = true;

		// Garbage collection (probabilistic)
		$gcProb = (int) self::_ini_get('session.gc_probability') ?: 1;
		$gcDiv = (int) self::_ini_get('session.gc_divisor') ?: 100;
		if (mt_rand(1, $gcDiv) <= $gcProb) {
			self::sessionGc($savePath, $maxLifetime);
		}

		return true;
	}

	/**
	 * Replacement for session_write_close().
	 * Flushes $_SESSION to file and releases the lock.
	 */
	static function _session_write_close()
	{
		if (!self::$sessionActive) return;
		if (self::$sessionFp) {
			$data = self::serializeSession($_SESSION);
			ftruncate(self::$sessionFp, 0);
			rewind(self::$sessionFp);
			fwrite(self::$sessionFp, $data);
			fflush(self::$sessionFp);
			flock(self::$sessionFp, LOCK_UN);
			fclose(self::$sessionFp);
			self::$sessionFp = null;
		}
		self::$sessionActive = false;
	}

	/**
	 * Replacement for session_regenerate_id().
	 * Generates a new session ID, optionally deletes the old file.
	 */
	static function _session_regenerate_id($delete_old = false)
	{
		if (!self::$sessionActive) return false;

		$oldFile = self::$sessionFile;
		$oldId = session_id();
		$newId = bin2hex(random_bytes(16));

		// Write current data and release lock on old file
		if (self::$sessionFp) {
			$data = self::serializeSession($_SESSION);
			ftruncate(self::$sessionFp, 0);
			rewind(self::$sessionFp);
			fwrite(self::$sessionFp, $data);
			fflush(self::$sessionFp);
			flock(self::$sessionFp, LOCK_UN);
			fclose(self::$sessionFp);
			self::$sessionFp = null;
		}

		if ($delete_old && file_exists($oldFile)) {
			@unlink($oldFile);
		}

		// Set new ID
		session_id($newId);
		$savePath = dirname(self::$sessionFile);
		self::$sessionFile = $savePath . DIRECTORY_SEPARATOR . 'sess_' . $newId;

		// Open new file with lock
		self::$sessionFp = fopen(self::$sessionFile, 'c+');
		if (self::$sessionFp) {
			flock(self::$sessionFp, LOCK_EX);
			$data = self::serializeSession($_SESSION);
			fwrite(self::$sessionFp, $data);
			fflush(self::$sessionFp);
		}

		// Update cookie
		$name = session_name() ?: 'PHPSESSID';
		self::_setcookie($name, $newId, 0, '/');

		return true;
	}

	/**
	 * Replacement for session_destroy().
	 */
	static function _session_destroy()
	{
		if (!self::$sessionActive) return false;

		$_SESSION = array();

		if (self::$sessionFp) {
			flock(self::$sessionFp, LOCK_UN);
			fclose(self::$sessionFp);
			self::$sessionFp = null;
		}

		if (file_exists(self::$sessionFile)) {
			@unlink(self::$sessionFile);
		}

		self::$sessionActive = false;
		return true;
	}

	/**
	 * Replacement for session_status().
	 */
	static function _session_status()
	{
		if (self::$sessionActive) return PHP_SESSION_ACTIVE;
		return PHP_SESSION_NONE;  // Sessions are always available, just not started yet
	}

	/**
	 * Session garbage collection — delete expired session files.
	 */
	private static function sessionGc($savePath, $maxLifetime)
	{
		$cutoff = time() - $maxLifetime;
		$files = glob($savePath . DIRECTORY_SEPARATOR . 'sess_*');
		if (!$files) return;
		foreach ($files as $file) {
			if (filemtime($file) < $cutoff) {
				@unlink($file);
			}
		}
	}

	/**
	 * Serialize session data in PHP's session format.
	 */
	private static function serializeSession($data)
	{
		$result = '';
		foreach ($data as $key => $value) {
			$result .= $key . '|' . serialize($value);
		}
		return $result;
	}

	/**
	 * Unserialize PHP session format.
	 */
	private static function unserializeSession($data)
	{
		$result = array();
		$offset = 0;
		while ($offset < strlen($data)) {
			$pipe = strpos($data, '|', $offset);
			if ($pipe === false) break;
			$key = substr($data, $offset, $pipe - $offset);
			$offset = $pipe + 1;
			$value = unserialize(substr($data, $offset), ['allowed_classes' => true]);
			$result[$key] = $value;
			// Find the end of the serialized value
			$serialized = serialize($value);
			$offset += strlen($serialized);
		}
		return $result;
	}

	/**
	 * Replacement for move_uploaded_file().
	 * Validates the source was actually an uploaded file.
	 */
	static function _move_uploaded_file($from, $to)
	{
		if (!isset(self::$uploadedFiles[$from])) {
			return false;
		}
		$result = rename($from, $to);
		if ($result) {
			unset(self::$uploadedFiles[$from]);
		}
		return $result;
	}

	/**
	 * Replacement for is_uploaded_file().
	 */
	static function _is_uploaded_file($filename)
	{
		return isset(self::$uploadedFiles[$filename]);
	}

	// ── ini_get / ini_set ───────────────────────────────

	/**
	 * Replacement for ini_get().
	 * Returns config override first, falls back to real ini_get().
	 */
	static function _ini_get($key)
	{
		$override = Q_Config::get('Q', 'compat', 'ini', $key, null);
		if ($override !== null) return (string) $override;
		return \ini_get($key);
	}

	/**
	 * Replacement for ini_set().
	 * Stores in config and also sets the real value where possible.
	 */
	static function _ini_set($key, $value)
	{
		$old = self::_ini_get($key);
		Q_Config::set('Q', 'compat', 'ini', $key, (string) $value);
		@\ini_set($key, $value);
		return $old;
	}

	/**
	 * Replacement for set_time_limit().
	 * Uses pcntl_alarm() to enforce execution timeout.
	 */
	static function _set_time_limit($seconds)
	{
		Q_Config::set('Q', 'compat', 'ini', 'max_execution_time', (string) $seconds);
		if (function_exists('pcntl_alarm')) {
			pcntl_alarm((int) $seconds);
		}
		return true;
	}

	// ── Request headers ─────────────────────────────────

	/**
	 * Replacement for getallheaders() / apache_request_headers().
	 * Returns headers from the parsed request.
	 */
	static function _getallheaders()
	{
		$result = array();
		foreach (self::$requestHeaders as $key => $value) {
			// Convert 'content-type' → 'Content-Type'
			$key = str_replace(' ', '-', ucwords(str_replace('-', ' ', $key)));
			$result[$key] = $value;
		}
		return $result;
	}

	// ── Multipart form-data parser ──────────────────────

	/**
	 * Parse a multipart/form-data body into $_POST and $_FILES.
	 *
	 * @param string $body Raw request body
	 * @param string $contentType The Content-Type header (contains boundary)
	 */
	static function parseMultipart($body, $contentType)
	{
		if (!preg_match('/boundary=(?:"([^"]+)"|([^\s;]+))/i', $contentType, $m)) {
			return;
		}
		$boundary = $m[1] ?: $m[2];
		$parts = explode('--' . $boundary, $body);

		// Remove first (empty) and last (--) parts
		array_shift($parts);
		array_pop($parts);

		$tmpDir = sys_get_temp_dir();
		$maxFileSize = self::parseIniSize(self::_ini_get('upload_max_filesize') ?: '8M');
		$maxPostSize = self::parseIniSize(self::_ini_get('post_max_size') ?: '32M');

		// Check total body size
		if (strlen($body) > $maxPostSize) {
			// POST body exceeds limit — frameworks check $_SERVER['CONTENT_LENGTH']
			return;
		}

		foreach ($parts as $part) {
			$part = ltrim($part, "\r\n");
			$split = strpos($part, "\r\n\r\n");
			if ($split === false) continue;

			$headerBlock = substr($part, 0, $split);
			$content = substr($part, $split + 4);
			// Remove trailing \r\n
			if (substr($content, -2) === "\r\n") {
				$content = substr($content, 0, -2);
			}

			// Parse headers
			$headers = array();
			foreach (explode("\r\n", $headerBlock) as $line) {
				$colon = strpos($line, ':');
				if ($colon === false) continue;
				$key = strtolower(trim(substr($line, 0, $colon)));
				$headers[$key] = trim(substr($line, $colon + 1));
			}

			$disposition = $headers['content-disposition'] ?? '';
			if (!preg_match('/name="([^"]*)"/', $disposition, $nm)) continue;
			$fieldName = $nm[1];

			if (preg_match('/filename="([^"]*)"/', $disposition, $fn)) {
				// File upload with size enforcement
				$filename = $fn[1];
				$contentLength = strlen($content);
				$type = $headers['content-type'] ?? 'application/octet-stream';

				if ($filename === '') {
					$error = UPLOAD_ERR_NO_FILE;
					$tmpPath = '';
				} elseif ($contentLength > $maxFileSize) {
					$error = UPLOAD_ERR_INI_SIZE;
					$tmpPath = '';
				} else {
					$tmpPath = tempnam($tmpDir, 'qbix_upload_');
					file_put_contents($tmpPath, $content);
					self::$uploadedFiles[$tmpPath] = true;
					$error = UPLOAD_ERR_OK;
				}

				$entry = array(
					'name'     => $filename,
					'type'     => $type,
					'tmp_name' => $tmpPath,
					'error'    => $error,
					'size'     => $contentLength,
				);
				self::setNestedValue($_FILES, $fieldName, $entry, true);
			} else {
				// Regular form field
				self::setNestedValue($_POST, $fieldName, $content, false);
			}
		}
	}

	/**
	 * Set a nested value in $_POST or $_FILES from form field names
	 * like "user[name]" or "files[]".
	 */
	private static function setNestedValue(&$array, $fieldName, $value, $isFile)
	{
		// Simple name (no brackets)
		if (strpos($fieldName, '[') === false) {
			if ($isFile) {
				$array[$fieldName] = $value;
			} else {
				$array[$fieldName] = $value;
			}
			return;
		}

		// Parse bracketed keys: "user[address][city]" → ['user', 'address', 'city']
		preg_match('/^([^\[]+)/', $fieldName, $base);
		$keys = array($base[1]);
		preg_match_all('/\[([^\]]*)\]/', $fieldName, $brackets);
		$keys = array_merge($keys, $brackets[1]);

		$ref = &$array;
		$lastIdx = count($keys) - 1;
		foreach ($keys as $idx => $key) {
			if ($key === '' && $idx === $lastIdx) {
				// [] means append
				if ($isFile) {
					// $_FILES array notation is special — each property is an array
					foreach ($value as $prop => $val) {
						$ref[$prop][] = $val;
					}
				} else {
					$ref[] = $value;
				}
				return;
			}
			if (!isset($ref[$key]) || !is_array($ref[$key])) {
				$ref[$key] = array();
			}
			$ref = &$ref[$key];
		}
		$ref = $value;
	}

	// ── URL Rewrite Engine ──────────────────────────────

	/**
	 * Apply URL rewriting rules.
	 * Returns the rewritten script path, or null if no rewrite matched.
	 *
	 * Config:
	 *   { "Q": { "compat": { "rewrite": "index.php" } } }
	 *
	 * This routes all requests that don't match a static file to index.php,
	 * which is what Laravel, Symfony, WordPress, and most modern PHP
	 * frameworks expect from their nginx/Apache rewrite rules.
	 *
	 * @param string $path The request URL path
	 * @param string $rootDir Document root
	 * @return string|null Rewritten script path, or null
	 */
	static function rewriteUrl($path, $rootDir)
	{
		// 1. Try .htaccess rules first (if .htaccess exists)
		$htaccessResult = self::applyHtaccessChain($path, $rootDir);
		if ($htaccessResult !== null) {
			return $htaccessResult;
		}

		// 2. JSON config-based rewrite (fallback when no .htaccess)
		$frontController = Q_Config::get('Q', 'compat', 'rewrite', null);
		if (!$frontController) return null;

		// If the path matches an actual file, don't rewrite
		$fsPath = $rootDir . str_replace('/', DIRECTORY_SEPARATOR, $path);
		if (is_file($fsPath)) return null;

		// Route to front controller
		$scriptPath = $rootDir . $frontController;
		if (!is_file($scriptPath)) return null;

		// Set up server vars that frameworks expect
		$_SERVER['SCRIPT_NAME'] = '/' . $frontController;
		$_SERVER['SCRIPT_FILENAME'] = $scriptPath;
		$_SERVER['PATH_INFO'] = $path;

		// Drupal-style query param rewriting: ?q=/the/path
		$queryParam = Q_Config::get('Q', 'compat', 'rewriteQueryParam', null);
		if ($queryParam) {
			$_GET[$queryParam] = ltrim($path, '/');
			$_SERVER['QUERY_STRING'] = http_build_query($_GET);
			// Keep REQUEST_URI as the original path — Drupal reads it
		}

		// Custom rewrite rules (regex → target)
		$rules = Q_Config::get('Q', 'compat', 'rewriteRules', null);
		if (is_array($rules)) {
			foreach ($rules as $rule) {
				$pattern = $rule['match'] ?? '';
				$target = $rule['to'] ?? '';
				$isStatic = !empty($rule['static']);
				if (!$pattern) continue;
				if (preg_match('#' . $pattern . '#', $path, $matches)) {
					if ($isStatic) return null; // serve as static file
					$rewritten = preg_replace('#' . $pattern . '#', $target, $path);
					// Parse the rewritten path for query string
					$parts = explode('?', $rewritten, 2);
					$scriptPath = $rootDir . ltrim($parts[0], '/');
					if (isset($parts[1])) {
						parse_str($parts[1], $extra);
						$_GET = array_merge($_GET, $extra);
						$_SERVER['QUERY_STRING'] = http_build_query($_GET);
					}
					$_SERVER['SCRIPT_NAME'] = $parts[0];
					$_SERVER['SCRIPT_FILENAME'] = $scriptPath;
					return $scriptPath;
				}
			}
		}

		return $scriptPath;
	}

	// ── Presets ─────────────────────────────────────────

	/**
	 * Load a framework preset config.
	 * Presets set compat.enabled, compat.rewrite, compat.ini, etc.
	 *
	 * @param string $preset Preset name: 'laravel', 'symfony', 'wordpress', 'drupal'
	 */
	static function loadPreset($preset)
	{
		$presets = array(
			'laravel' => array(
				'enabled' => true,
				'rewrite' => 'index.php',
				'ini' => array(
					'upload_max_filesize' => '10M',
					'post_max_size' => '12M',
					'memory_limit' => '256M',
					'max_execution_time' => '60',
					'session.gc_maxlifetime' => '7200',
					'session.gc_probability' => '2',
					'session.gc_divisor' => '100',
				),
			),
			'symfony' => array(
				'enabled' => true,
				'rewrite' => 'index.php',
				'ini' => array(
					'upload_max_filesize' => '10M',
					'post_max_size' => '12M',
					'memory_limit' => '256M',
					'max_execution_time' => '60',
					'session.gc_maxlifetime' => '1440',
				),
			),
			'wordpress' => array(
				'enabled' => true,
				'rewrite' => 'index.php',
				'ini' => array(
					'upload_max_filesize' => '64M',
					'post_max_size' => '64M',
					'memory_limit' => '256M',
					'max_execution_time' => '300',
					'session.gc_maxlifetime' => '1440',
				),
			),
			'drupal' => array(
				'enabled' => true,
				'rewrite' => 'index.php',
				'rewriteQueryParam' => 'q',
				'ini' => array(
					'upload_max_filesize' => '32M',
					'post_max_size' => '32M',
					'memory_limit' => '256M',
					'max_execution_time' => '240',
				),
			),
		);

		if (!isset($presets[$preset])) {
			fwrite(STDERR, "Unknown preset: $preset (available: "
				. implode(', ', array_keys($presets)) . ")\n");
			return false;
		}

		Q_Config::merge(array('Q' => array('compat' => $presets[$preset])));
		return true;
	}

	// ── .htaccess Parser ───────────────────────────────

	/**
	 * Parse and apply .htaccess rewrite rules from a file.
	 *
	 * Supports the subset of mod_rewrite used by WordPress, Laravel,
	 * Symfony, Drupal, Joomla, Magento, and most PHP frameworks:
	 *
	 *   RewriteEngine On
	 *   RewriteBase /subdir
	 *   RewriteCond %{REQUEST_FILENAME} !-f
	 *   RewriteCond %{REQUEST_FILENAME} !-d
	 *   RewriteCond %{REQUEST_URI} pattern [NC]
	 *   RewriteRule pattern target [L,QSA,R=301,F,E=VAR:val]
	 *
	 * @param string $htaccessPath Path to .htaccess file
	 * @param string $requestPath The request URL path
	 * @param string $rootDir Document root
	 * @return array|null ['script' => path, 'redirect' => url, 'forbidden' => bool] or null
	 */
	static function applyHtaccess($htaccessPath, $requestPath, $rootDir)
	{
		if (!is_file($htaccessPath)) return null;

		static $cache = array();
		$cacheKey = $htaccessPath;
		if (!isset($cache[$cacheKey]) || filemtime($htaccessPath) > ($cache[$cacheKey]['mtime'] ?? 0)) {
			$cache[$cacheKey] = array(
				'mtime' => filemtime($htaccessPath),
				'rules' => self::parseHtaccess(file_get_contents($htaccessPath)),
			);
		}

		$parsed = $cache[$cacheKey]['rules'];
		if (!$parsed['engine']) return null;

		$base = $parsed['base'] ?: '/';
		$env = array();

		foreach ($parsed['blocks'] as $block) {
			// Each block is: conditions[] + rule
			$conditions = $block['conditions'];
			$rule = $block['rule'];

			// Check all conditions
			$condsMet = true;
			foreach ($conditions as $cond) {
				if (!self::evalRewriteCond($cond, $requestPath, $rootDir, $env)) {
					$condsMet = false;
					break;
				}
			}
			if (!$condsMet) continue;

			// Apply the rule
			$result = self::evalRewriteRule($rule, $requestPath, $rootDir, $base, $env);
			if ($result !== null) return $result;
		}

		return null;
	}

	/**
	 * Parse .htaccess content into structured rules.
	 */
	private static function parseHtaccess($content)
	{
		$result = array('engine' => false, 'base' => '', 'blocks' => array());
		$lines = preg_split('/\r?\n/', $content);
		$pendingConds = array();

		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#') continue;

			// RewriteEngine On/Off
			if (preg_match('/^RewriteEngine\s+(On|Off)/i', $line, $m)) {
				$result['engine'] = (strtolower($m[1]) === 'on');
				continue;
			}

			// RewriteBase
			if (preg_match('/^RewriteBase\s+(\S+)/i', $line, $m)) {
				$result['base'] = rtrim($m[1], '/');
				continue;
			}

			// RewriteCond
			if (preg_match('/^RewriteCond\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?/i', $line, $m)) {
				$pendingConds[] = array(
					'testString' => $m[1],
					'pattern' => $m[2],
					'flags' => isset($m[3]) ? strtoupper($m[3]) : '',
				);
				continue;
			}

			// RewriteRule
			if (preg_match('/^RewriteRule\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?/i', $line, $m)) {
				$result['blocks'][] = array(
					'conditions' => $pendingConds,
					'rule' => array(
						'pattern' => $m[1],
						'target' => $m[2],
						'flags' => isset($m[3]) ? strtoupper($m[3]) : '',
					),
				);
				$pendingConds = array();
				continue;
			}
		}

		return $result;
	}

	/**
	 * Evaluate a RewriteCond.
	 */
	private static function evalRewriteCond($cond, $requestPath, $rootDir, &$env)
	{
		$testString = $cond['testString'];
		$pattern = $cond['pattern'];
		$negate = false;

		// Variable substitution
		$testString = self::htaccessVarReplace($testString, $requestPath, $rootDir, $env);

		// Negation
		if (isset($pattern[0]) && $pattern[0] === '!') {
			$negate = true;
			$pattern = substr($pattern, 1);
		}

		// Special tests
		if ($pattern === '-f') {
			$result = is_file($testString);
		} elseif ($pattern === '-d') {
			$result = is_dir($testString);
		} elseif ($pattern === '-l') {
			$result = is_link($testString);
		} elseif ($pattern === '-s') {
			$result = is_file($testString) && filesize($testString) > 0;
		} else {
			// Regex match
			$flags = $cond['flags'];
			$modifiers = strpos($flags, 'NC') !== false ? 'i' : '';
			$result = (bool) preg_match('#' . $pattern . '#' . $modifiers, $testString);
		}

		return $negate ? !$result : $result;
	}

	/**
	 * Evaluate a RewriteRule and return result or null.
	 */
	private static function evalRewriteRule($rule, $requestPath, $rootDir, $base, &$env)
	{
		$pattern = $rule['pattern'];
		$target = $rule['target'];
		$flags = $rule['flags'];

		// Strip base from request path for matching
		$matchPath = $requestPath;
		if ($base && strpos($matchPath, $base) === 0) {
			$matchPath = substr($matchPath, strlen($base));
		}
		$matchPath = ltrim($matchPath, '/');

		// Match the pattern
		if (!preg_match('#' . $pattern . '#', $matchPath, $matches)) {
			return null;
		}

		// Target "-" means don't rewrite, but flags still apply
		if ($target === '-') {
			// Handle [E=VAR:val] flag
			if (preg_match_all('/E=([^:,\]]+):([^,\]]*)/i', $flags, $em, PREG_SET_ORDER)) {
				foreach ($em as $e) {
					$env[$e[1]] = preg_replace_callback(
						'/%(\d)/', function ($m) use ($matches) { return $matches[(int)$m[1]] ?? ''; }, $e[2]
					);
				}
			}
			// [L] means stop processing
			if (strpos($flags, 'L') !== false) return null;
			return null;  // continue to next rule
		}

		// [F] = Forbidden
		if (strpos($flags, 'F') !== false) {
			return array('forbidden' => true);
		}

		// Backreference substitution in target
		$rewritten = preg_replace_callback('/(\\$|%)(\d)/', function ($m) use ($matches) {
			return $matches[(int)$m[2]] ?? '';
		}, $target);

		// ENV variable substitution
		$rewritten = self::htaccessVarReplace($rewritten, $requestPath, $rootDir, $env);

		// Ensure leading slash, avoid double slashes
		if ($rewritten !== '' && $rewritten[0] !== '/' && strpos($rewritten, '://') === false) {
			$rewritten = rtrim($base, '/') . '/' . $rewritten;
		}
		// Normalize double slashes
		$rewritten = preg_replace('#//+#', '/', $rewritten);

		// [R] or [R=301] = Redirect
		if (preg_match('/R(?:=(\d+))?/i', $flags, $rm)) {
			$code = isset($rm[1]) ? (int)$rm[1] : 302;
			return array('redirect' => $rewritten, 'code' => $code);
		}

		// [QSA] = Query String Append
		if (strpos($flags, 'QSA') !== false && !empty($_SERVER['QUERY_STRING'])) {
			$sep = strpos($rewritten, '?') !== false ? '&' : '?';
			$rewritten .= $sep . $_SERVER['QUERY_STRING'];
		}

		// Parse query string from rewritten URL
		$parts = explode('?', $rewritten, 2);
		$scriptPath = $rootDir . ltrim($parts[0], '/');

		if (isset($parts[1])) {
			parse_str($parts[1], $extra);
			$_GET = array_merge($_GET, $extra);
			$_SERVER['QUERY_STRING'] = http_build_query($_GET);
		}

		$_SERVER['SCRIPT_NAME'] = $parts[0];
		$_SERVER['SCRIPT_FILENAME'] = $scriptPath;

		return array('script' => $scriptPath);
	}

	/**
	 * Replace Apache variables in a string.
	 */
	private static function htaccessVarReplace($str, $requestPath, $rootDir, $env)
	{
		$replacements = array(
			'%{REQUEST_FILENAME}' => $rootDir . ltrim($requestPath, '/'),
			'%{REQUEST_URI}' => $requestPath,
			'%{DOCUMENT_ROOT}' => $rootDir,
			'%{SERVER_NAME}' => $_SERVER['SERVER_NAME'] ?? 'localhost',
			'%{HTTP_HOST}' => $_SERVER['HTTP_HOST'] ?? 'localhost',
			'%{QUERY_STRING}' => $_SERVER['QUERY_STRING'] ?? '',
			'%{THE_REQUEST}' => ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . $requestPath . ' HTTP/1.1',
			'%{HTTPS}' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'on' : 'off',
			'%{SERVER_PORT}' => $_SERVER['SERVER_PORT'] ?? '80',
		);

		// ENV variables: %{ENV:VARNAME}
		$str = preg_replace_callback('/%\{ENV:([^}]+)\}/', function ($m) use ($env) {
			return $env[$m[1]] ?? '';
		}, $str);

		return strtr($str, $replacements);
	}

	/**
	 * Parse and apply .htaccess files.
	 * Checks the document root and each directory up to the script for .htaccess files.
	 *
	 * @param string $requestPath
	 * @param string $rootDir
	 * @return string|null Rewritten script path, or null
	 */
	static function applyHtaccessChain($requestPath, $rootDir)
	{
		// Check root .htaccess first
		$htaccessPath = $rootDir . '.htaccess';
		$result = self::applyHtaccess($htaccessPath, $requestPath, $rootDir);

		if ($result === null) {
			// Check directory-specific .htaccess
			$parts = explode('/', trim($requestPath, '/'));
			$dir = $rootDir;
			for ($i = 0; $i < count($parts) - 1; $i++) {
				$dir .= $parts[$i] . DIRECTORY_SEPARATOR;
				$subHtaccess = $dir . '.htaccess';
				$result = self::applyHtaccess($subHtaccess, $requestPath, $dir);
				if ($result !== null) break;
			}
		}

		if ($result === null) return null;

		if (!empty($result['forbidden'])) {
			http_response_code(403);
			echo "Forbidden";
			return '__FORBIDDEN__';
		}

		if (!empty($result['redirect'])) {
			Q_WebServer_Compat::_header('Location: ' . $result['redirect'], true, $result['code'] ?? 302);
			return '__REDIRECT__';
		}

		if (!empty($result['script'])) {
			return $result['script'];
		}

		return null;
	}

	// ── Helpers ─────────────────────────────────────────

	/**
	 * Parse PHP ini size values (8M, 1G, 512K) to bytes.
	 * @param string $value
	 * @return int
	 */
	static function parseIniSize($value)
	{
		$value = trim($value);
		$num = (int) $value;
		$suffix = strtoupper(substr($value, -1));
		switch ($suffix) {
			case 'G': return $num * 1073741824;
			case 'M': return $num * 1048576;
			case 'K': return $num * 1024;
			default:  return $num;
		}
	}
}

/**
 * Stream wrapper that transforms PHP source at include time.
 * Only transforms .php files in the app directory.
 * Non-PHP files and files outside the app pass through unchanged.
 */
class Q_WebServer_CompatFileWrapper
{
	/** @var resource The underlying file handle */
	private $handle;
	/** @var string Buffered transformed content for reading */
	private $buffer = '';
	/** @var int Read position in buffer */
	private $position = 0;
	/** @var bool Whether this file was transformed (reading from buffer) */
	private $transformed = false;
	/** @var resource Directory handle */
	private $dirHandle;

	// We need to restore the real file:// wrapper for actual file ops,
	// then re-register ours. This prevents infinite recursion.
	private static function unwrap()
	{
		stream_wrapper_restore('file');
	}
	private static function rewrap()
	{
		stream_wrapper_unregister('file');
		stream_wrapper_register('file', __CLASS__);
	}

	public function stream_open($path, $mode, $options, &$opened_path)
	{
		// Strip file:// prefix if present
		$realPath = preg_replace('/^file:\/\//', '', $path);

		// Only transform PHP files opened for reading (include/require)
		$shouldTransform = (
			$mode === 'r' || $mode === 'rb'
		) && preg_match('/\.php$/i', $realPath)
		  && Q_WebServer_Compat::isEnabled();

		if ($shouldTransform) {
			// Check in-memory cache — covers both transforms and sentinels.
			$cached = Q_WebServer_Compat::getCachedTransform($realPath);
			if ($cached !== null) {
				if ($cached === false) {
					// Sentinel: this file doesn't need transforms.
					// Open it normally — no tokenization, no transform.
					self::unwrap();
					$this->handle = fopen($realPath, $mode);
					self::rewrap();
					return $this->handle !== false;
				}
				// Cached transform — serve from memory, no disk I/O
				$this->buffer = $cached;
				$this->position = 0;
				$this->transformed = true;
				$opened_path = $realPath;
				return true;
			}
		}

		self::unwrap();

		if ($shouldTransform && is_file($realPath)) {
			// Runtime trust check — reject files that fail integrity verification
			if (class_exists('Q_WebServer_Trust', false) && Q_WebServer_Trust::isEnabled()) {
				if (!Q_WebServer_Trust::checkFile($realPath)) {
					self::rewrap();
					trigger_error("Trust: blocked untrusted file: $realPath", E_USER_WARNING);
					return false;
				}
			}

			// Cache miss (file added after prewarm) — read, transform, cache
			$source = file_get_contents($realPath);
			if ($source !== false) {
				$transformed = Q_WebServer_Compat::transformSource($source, $realPath);
				if ($transformed !== $source) {
					$this->buffer = $transformed;
					$this->position = 0;
					$this->transformed = true;
					self::rewrap();
					$opened_path = $realPath;
					return true;
				}
				// No changes — open the original normally
			}
		}

		// Pass through — non-PHP, write mode, or no transforms needed
		$this->handle = fopen($realPath, $mode);
		self::rewrap();
		return $this->handle !== false;
	}

	public function stream_read($count)
	{
		if ($this->transformed) {
			$data = substr($this->buffer, $this->position, $count);
			$this->position += strlen($data);
			return $data;
		}
		return fread($this->handle, $count);
	}

	public function stream_write($data)
	{
		if ($this->transformed) return 0;
		return fwrite($this->handle, $data);
	}

	public function stream_tell()
	{
		if ($this->transformed) return $this->position;
		return ftell($this->handle);
	}

	public function stream_eof()
	{
		if ($this->transformed) return $this->position >= strlen($this->buffer);
		return feof($this->handle);
	}

	public function stream_seek($offset, $whence = SEEK_SET)
	{
		if ($this->transformed) {
			switch ($whence) {
				case SEEK_SET: $this->position = $offset; break;
				case SEEK_CUR: $this->position += $offset; break;
				case SEEK_END: $this->position = strlen($this->buffer) + $offset; break;
			}
			return true;
		}
		return fseek($this->handle, $offset, $whence) === 0;
	}

	public function stream_stat()
	{
		if ($this->transformed) {
			return array('size' => strlen($this->buffer));
		}
		self::unwrap();
		$stat = fstat($this->handle);
		self::rewrap();
		return $stat;
	}

	public function stream_close()
	{
		if ($this->transformed) {
			$this->buffer = '';
			return;
		}
		if ($this->handle) fclose($this->handle);
	}

	public function stream_set_option($option, $arg1, $arg2)
	{
		return false;
	}

	public function stream_lock($operation)
	{
		if ($this->transformed) return true;
		return flock($this->handle, $operation);
	}

	public function stream_flush()
	{
		if ($this->transformed) return true;
		return fflush($this->handle);
	}

	// ── Required for file_exists, is_file, stat, etc. ──

	public function url_stat($path, $flags)
	{
		$realPath = preg_replace('/^file:\/\//', '', $path);
		self::unwrap();
		if ($flags & STREAM_URL_STAT_QUIET) {
			$stat = @stat($realPath);
		} else {
			$stat = stat($realPath);
		}
		self::rewrap();
		return $stat ?: false;
	}

	// ── Directory operations ──

	public function dir_opendir($path, $options)
	{
		$realPath = preg_replace('/^file:\/\//', '', $path);
		self::unwrap();
		$this->dirHandle = opendir($realPath);
		self::rewrap();
		return $this->dirHandle !== false;
	}

	public function dir_readdir()
	{
		return readdir($this->dirHandle);
	}

	public function dir_rewinddir()
	{
		return rewinddir($this->dirHandle);
	}

	public function dir_closedir()
	{
		return closedir($this->dirHandle);
	}

	// ── File operations (rename, unlink, mkdir, rmdir) ──

	public function rename($from, $to)
	{
		self::unwrap();
		$result = rename(preg_replace('/^file:\/\//', '', $from),
		                 preg_replace('/^file:\/\//', '', $to));
		self::rewrap();
		return $result;
	}

	public function unlink($path)
	{
		self::unwrap();
		$result = unlink(preg_replace('/^file:\/\//', '', $path));
		self::rewrap();
		return $result;
	}

	public function mkdir($path, $mode, $options)
	{
		self::unwrap();
		$result = mkdir(preg_replace('/^file:\/\//', '', $path), $mode,
			$options & STREAM_MKDIR_RECURSIVE);
		self::rewrap();
		return $result;
	}

	public function rmdir($path, $options)
	{
		self::unwrap();
		$result = rmdir(preg_replace('/^file:\/\//', '', $path));
		self::rewrap();
		return $result;
	}

	public function stream_metadata($path, $option, $value)
	{
		$realPath = preg_replace('/^file:\/\//', '', $path);
		self::unwrap();
		switch ($option) {
			case STREAM_META_TOUCH:
				$result = touch($realPath, $value[0] ?? time(), $value[1] ?? time());
				break;
			case STREAM_META_OWNER_NAME:
			case STREAM_META_OWNER:
				$result = chown($realPath, $value);
				break;
			case STREAM_META_GROUP_NAME:
			case STREAM_META_GROUP:
				$result = chgrp($realPath, $value);
				break;
			case STREAM_META_ACCESS:
				$result = chmod($realPath, $value);
				break;
			default:
				$result = false;
		}
		self::rewrap();
		return $result;
	}
}
