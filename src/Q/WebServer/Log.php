<?php
/**
 * @module Q
 */

/**
 * Request logging with file rotation for Q_WebServer.
 *
 * Writes access.log (Apache combined format) and error.log.
 * Rotates by size (default 10MB) or by date.
 *
 * Config:
 *   "Q": { "webserver": { "log": {
 *     "access": "logs/access.log",
 *     "error": "logs/error.log",
 *     "maxSize": 10485760,
 *     "rotate": true
 *   }}}
 *
 * @class Q_WebServer_Log
 */
class Q_WebServer_Log
{
	static $accessPath = null;
	static $errorPath = null;
	static $accessFp = null;
	static $errorFp = null;
	static $maxSize = 10485760; // 10MB

	/**
	 * Initialize logging. Opens files, sets up rotation check.
	 * @method init
	 * @static
	 */
	static function init()
	{
		$config = Q_Config::get('Q', 'webserver', 'log', array());
		self::$maxSize = (int) Q::ifset($config, 'maxSize', 10485760);

		self::$accessPath = Q::ifset($config, 'access', null);
		self::$errorPath = Q::ifset($config, 'error', null);

		if (self::$accessPath) {
			$dir = dirname(self::$accessPath);
			if (!is_dir($dir)) mkdir($dir, 0755, true);
			self::$accessFp = fopen(self::$accessPath, 'a');
		}
		if (self::$errorPath) {
			$dir = dirname(self::$errorPath);
			if (!is_dir($dir)) mkdir($dir, 0755, true);
			self::$errorFp = fopen(self::$errorPath, 'a');
		}

		// Periodic rotation check (every 60s)
		if (self::$accessPath || self::$errorPath) {
			Q_Evented::repeat(60.0, function () {
				Q_WebServer_Log::checkRotation();
			});
		}
	}

	/**
	 * Log a request in combined log format.
	 * @method access
	 * @static
	 */
	static function access($ip, $method, $uri, $status, $size, $referer, $ua, $ms)
	{
		if (!self::$accessFp) return;
		$time = date('d/M/Y:H:i:s O');
		$line = sprintf(
			"%s - - [%s] \"%s %s HTTP/1.1\" %d %d \"%s\" \"%s\" %.1fms\n",
			$ip, $time, $method, $uri, $status, $size,
			$referer ?: '-', $ua ?: '-', $ms
		);
		fwrite(self::$accessFp, $line);
	}

	/**
	 * Log an error.
	 * @method error
	 * @static
	 */
	static function error($message, $context = '')
	{
		if (self::$errorFp) {
			$time = date('Y-m-d H:i:s');
			fwrite(self::$errorFp, "[$time] $message $context\n");
		}
		// Always echo errors to stderr
		fwrite(STDERR, "[ERROR] $message $context\n");
	}

	/**
	 * Rotate log files if they exceed maxSize.
	 * @method checkRotation
	 * @static
	 */
	static function checkRotation()
	{
		if (self::$accessPath && file_exists(self::$accessPath)) {
			clearstatcache(true, self::$accessPath);
			if (filesize(self::$accessPath) > self::$maxSize) {
				self::rotate(self::$accessPath, self::$accessFp);
				self::$accessFp = fopen(self::$accessPath, 'a');
			}
		}
		if (self::$errorPath && file_exists(self::$errorPath)) {
			clearstatcache(true, self::$errorPath);
			if (filesize(self::$errorPath) > self::$maxSize) {
				self::rotate(self::$errorPath, self::$errorFp);
				self::$errorFp = fopen(self::$errorPath, 'a');
			}
		}
	}

	static function rotate($path, &$fp)
	{
		if ($fp) fclose($fp);
		$date = date('Y-m-d-His');
		$rotated = $path . '.' . $date;
		rename($path, $rotated);
		// Keep last 10 rotated files
		$pattern = $path . '.*';
		$files = glob($pattern);
		sort($files);
		while (count($files) > 10) {
			unlink(array_shift($files));
		}
	}

	static function shutdown()
	{
		if (self::$accessFp) { fclose(self::$accessFp); self::$accessFp = null; }
		if (self::$errorFp) { fclose(self::$errorFp); self::$errorFp = null; }
	}
}
