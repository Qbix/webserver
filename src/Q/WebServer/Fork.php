<?php
/**
 * Cross-platform fork abstraction.
 *
 * Linux/macOS: uses pcntl_fork() (native COW).
 * Windows:     uses qbix_fork.dll via FFI (RtlCloneUserProcess COW).
 * Fallback:    returns false if neither is available.
 *
 * Usage:
 *   $pid = Q_WebServer_Fork::fork();
 *   if ($pid === 0)      -- child
 *   elseif ($pid > 0)    -- parent, $pid = child PID
 *   else                 -- fork failed or unavailable
 */
class Q_WebServer_Fork
{
	private static $ffi = null;
	private static $method = null; // 'pcntl', 'ffi', or null

	/**
	 * Detect the best available fork method.
	 * Call once at startup to cache the result.
	 */
	static function init()
	{
		if (self::$method !== null) return self::$method;

		// Prefer native pcntl (Linux, macOS, FreeBSD)
		if (function_exists('pcntl_fork')) {
			self::$method = 'pcntl';
			return 'pcntl';
		}

		// Try FFI with qbix_fork.dll (Windows)
		if (PHP_OS_FAMILY === 'Windows' && extension_loaded('ffi')) {
			try {
				// Look for the DLL next to the server binary, or in the current dir
				$dllPaths = array();
				if (defined('QBIX_SERVER_DIR')) {
					$dllPaths[] = QBIX_SERVER_DIR . '\\qbix_fork.dll';
					$dllPaths[] = QBIX_SERVER_DIR . '\\bin\\qbix_fork.dll';
				}
				$dllPaths[] = 'qbix_fork.dll';
				$dllPaths[] = dirname(PHP_BINARY) . '\\qbix_fork.dll';

				$dllPath = null;
				foreach ($dllPaths as $p) {
					if (is_file($p)) { $dllPath = $p; break; }
				}

				if ($dllPath) {
					self::$ffi = \FFI::cdef("
						int qbix_fork(void);
						int qbix_fork_available(void);
						int qbix_getpid(void);
						int qbix_waitpid(int pid, int timeout_ms);
					", $dllPath);

					if (self::$ffi->qbix_fork_available()) {
						self::$method = 'ffi';
						return 'ffi';
					}
				}
			} catch (\Throwable $e) {
				// FFI failed — fall through
			}
		}

		self::$method = false;
		return false;
	}

	/**
	 * Fork the current process. Returns:
	 *   > 0  in parent (child PID)
	 *     0  in child
	 *    -1  fork failed
	 *  false fork not available on this platform
	 */
	static function fork()
	{
		if (self::$method === null) self::init();

		switch (self::$method) {
			case 'pcntl':
				return pcntl_fork();
			case 'ffi':
				return self::$ffi->qbix_fork();
			default:
				return false;
		}
	}

	/**
	 * Whether fork (with COW) is available.
	 */
	static function available()
	{
		if (self::$method === null) self::init();
		return self::$method !== false;
	}

	/**
	 * Human-readable description of the fork method.
	 */
	static function method()
	{
		if (self::$method === null) self::init();
		switch (self::$method) {
			case 'pcntl': return 'pcntl_fork (native COW)';
			case 'ffi':   return 'qbix_fork.dll (Windows COW via RtlCloneUserProcess)';
			default:      return 'none (using php-cgi fallback)';
		}
	}

	/**
	 * Wait for a child process (non-blocking if $block = false).
	 */
	static function waitpid($pid, &$status, $options = 0)
	{
		if (self::$method === 'pcntl') {
			return pcntl_waitpid($pid, $status, $options);
		}
		if (self::$method === 'ffi') {
			$timeout = ($options & 1) ? 0 : 5000; // WNOHANG = 1
			$result = self::$ffi->qbix_waitpid($pid, $timeout);
			if ($result >= 0) {
				$status = $result;
				return $pid;
			}
			if ($result === -2) return 0; // still running (WNOHANG)
			return -1;
		}
		return -1;
	}

	/**
	 * Get current process ID.
	 */
	static function getpid()
	{
		if (self::$method === 'ffi') {
			return self::$ffi->qbix_getpid();
		}
		return getmypid();
	}
}
