<?php
/**
 * @module Q
 */

/**
 * Access and error logging with buffered writes, daily rotation,
 * and automatic gzip archiving.
 *
 * Writes access.log (combined format + response time) and error.log.
 * Buffered: log lines accumulate in memory and flush on a timer or
 * when the buffer is full — one write() syscall per flush instead of
 * one per request. Daily rotation at midnight. Logs older than
 * `archiveAfterDays` (default 2) are compressed to .gz. Logs older
 * than `deleteAfterDays` (default 30) are deleted.
 *
 * Config:
 *   "Q": { "webserver": { "log": {
 *     "dir":              "logs",
 *     "access":           true,
 *     "error":            true,
 *     "bufferSize":       65536,
 *     "flushInterval":    1,
 *     "maxSize":          52428800,
 *     "archiveAfterDays": 2,
 *     "deleteAfterDays":  30
 *   }}}
 *
 *   bufferSize: 0 = unbuffered (flush every line), default 64KB.
 *   flushInterval: seconds between timer flushes, default 1.
 *   Set access/error to false to disable that log entirely.
 *
 * @class Q_WebServer_Log
 */
class Q_WebServer_Log
{
	static $accessFp = null;
	static $errorFp = null;
	static $accessPath = null;
	static $errorPath = null;
	static $dir = null;
	static $maxSize = 52428800;       // 50MB
	static $archiveAfterDays = 2;
	static $deleteAfterDays = 30;
	static $currentDate = null;
	static $enabled = false;

	// ── Buffer ──────────────────────────────────────────
	static $accessBuf = '';
	static $errorBuf = '';
	static $bufferSize = 65536;       // 64KB default
	static $flushInterval = 1.0;      // seconds

	/**
	 * Initialize from config.
	 * @method init
	 * @static
	 */
	static function init()
	{
		$config = Q_Config::get('Q', 'webserver', 'log', array());
		if (empty($config)) return;

		$dir = Q::ifset($config, 'dir', null);
		if (!$dir) {
			$dir = defined('APP_DIR') ? APP_DIR . '/logs' : 'logs';
		}
		if ($dir[0] !== '/') {
			$base = defined('APP_DIR') ? APP_DIR : getcwd();
			$dir = $base . '/' . $dir;
		}
		if (!is_dir($dir)) @mkdir($dir, 0755, true);
		self::$dir = $dir;

		self::$maxSize = (int) Q::ifset($config, 'maxSize', 52428800);
		self::$archiveAfterDays = (int) Q::ifset($config, 'archiveAfterDays', 2);
		self::$deleteAfterDays = (int) Q::ifset($config, 'deleteAfterDays', 30);
		self::$bufferSize = (int) Q::ifset($config, 'bufferSize', 65536);
		self::$flushInterval = (float) Q::ifset($config, 'flushInterval', 1.0);

		$accessEnabled = Q::ifset($config, 'access', true);
		$errorEnabled = Q::ifset($config, 'error', true);

		self::$currentDate = date('Y-m-d');

		if ($accessEnabled) {
			self::$accessPath = $dir . '/access.log';
			self::$accessFp = fopen(self::$accessPath, 'a');
		}
		if ($errorEnabled) {
			self::$errorPath = $dir . '/error.log';
			self::$errorFp = fopen(self::$errorPath, 'a');
		}

		self::$enabled = true;

		// Flush timer — write accumulated buffer to disk
		if (self::$flushInterval > 0) {
			Q_Evented::repeat(self::$flushInterval, function () {
				Q_WebServer_Log::flush();
			});
		}

		// Check rotation every 60 seconds
		Q_Evented::repeat(60.0, function () {
			Q_WebServer_Log::checkRotation();
		});

		// Archive/prune on startup and daily
		self::archiveAndPrune();
		Q_Evented::repeat(86400.0, function () {
			Q_WebServer_Log::archiveAndPrune();
		});

		$mode = self::$bufferSize > 0
			? 'buffered (' . round(self::$bufferSize / 1024) . 'KB, '
			  . self::$flushInterval . 's)'
			: 'unbuffered';
		fwrite(STDERR, "  Logging to $dir/ ($mode)\n");
	}

	/**
	 * Log a request. Line goes into the buffer (or directly to disk
	 * if bufferSize=0).
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

		if (self::$bufferSize <= 0) {
			// Unbuffered — write immediately
			@fwrite(self::$accessFp, $line);
			return;
		}

		self::$accessBuf .= $line;
		if (strlen(self::$accessBuf) >= self::$bufferSize) {
			self::flushAccess();
		}
	}

	/**
	 * Log an error. Errors always flush immediately (they're rare
	 * and you want them on disk before a crash).
	 * @method error
	 * @static
	 */
	static function error($message, $context = '')
	{
		$time = date('Y-m-d H:i:s');
		$line = "[$time] $message $context\n";
		if (self::$errorFp) {
			// Errors bypass the buffer — flush immediately
			self::flushAccess(); // flush any pending access lines too
			@fwrite(self::$errorFp, $line);
		}
		fwrite(STDERR, "[ERROR] $message $context\n");
	}

	/**
	 * Flush all buffered log lines to disk.
	 * Called by the timer and on shutdown.
	 * @method flush
	 * @static
	 */
	static function flush()
	{
		self::flushAccess();
	}

	private static function flushAccess()
	{
		if (self::$accessBuf !== '' && self::$accessFp) {
			@fwrite(self::$accessFp, self::$accessBuf);
			self::$accessBuf = '';
		}
	}

	/**
	 * Daily rotation + mid-day size rotation.
	 * @method checkRotation
	 * @static
	 */
	static function checkRotation()
	{
		if (!self::$enabled) return;

		// Flush before checking sizes
		self::flush();

		$today = date('Y-m-d');
		if ($today !== self::$currentDate) {
			$yesterday = self::$currentDate;
			self::$currentDate = $today;
			if (self::$accessFp) {
				self::rotateTo(self::$accessPath, self::$accessFp,
					self::$dir . "/access.$yesterday.log");
				self::$accessFp = fopen(self::$accessPath, 'a');
			}
			if (self::$errorFp) {
				self::rotateTo(self::$errorPath, self::$errorFp,
					self::$dir . "/error.$yesterday.log");
				self::$errorFp = fopen(self::$errorPath, 'a');
			}
			self::archiveAndPrune();
			return;
		}

		// Size-based mid-day rotation
		if (self::$accessPath && self::exceedsMax(self::$accessPath)) {
			$stamp = date('Y-m-d-His');
			self::rotateTo(self::$accessPath, self::$accessFp,
				self::$dir . "/access.$stamp.log");
			self::$accessFp = fopen(self::$accessPath, 'a');
		}
		if (self::$errorPath && self::exceedsMax(self::$errorPath)) {
			$stamp = date('Y-m-d-His');
			self::rotateTo(self::$errorPath, self::$errorFp,
				self::$dir . "/error.$stamp.log");
			self::$errorFp = fopen(self::$errorPath, 'a');
		}
	}

	private static function rotateTo($currentPath, &$fp, $targetPath)
	{
		if ($fp) @fclose($fp);
		if (file_exists($currentPath) && filesize($currentPath) > 0) {
			@rename($currentPath, $targetPath);
		}
	}

	private static function exceedsMax($path)
	{
		if (!file_exists($path)) return false;
		clearstatcache(true, $path);
		return filesize($path) > self::$maxSize;
	}

	/**
	 * Compress old logs to .gz, delete expired ones.
	 * @method archiveAndPrune
	 * @static
	 */
	static function archiveAndPrune()
	{
		if (!self::$dir || !is_dir(self::$dir)) return;

		$now = time();
		$archiveCutoff = $now - (self::$archiveAfterDays * 86400);
		$deleteCutoff = $now - (self::$deleteAfterDays * 86400);

		foreach (glob(self::$dir . '/*.log') as $file) {
			$base = basename($file);
			if ($base === 'access.log' || $base === 'error.log') continue;
			$mtime = filemtime($file);
			if ($mtime < $deleteCutoff) { @unlink($file); continue; }
			if ($mtime < $archiveCutoff && function_exists('gzopen')) {
				$gzPath = $file . '.gz';
				if (!file_exists($gzPath)) {
					$in = fopen($file, 'rb');
					$out = gzopen($gzPath, 'wb9');
					if ($in && $out) {
						while (!feof($in)) gzwrite($out, fread($in, 65536));
						fclose($in); gzclose($out);
						@unlink($file); @touch($gzPath, $mtime);
					} else {
						if ($in) fclose($in);
						if ($out) gzclose($out);
					}
				}
			}
		}

		foreach (glob(self::$dir . '/*.log.gz') as $file) {
			if (filemtime($file) < $deleteCutoff) @unlink($file);
		}
	}

	/**
	 * Log stats for the dashboard.
	 * @method stats
	 * @static
	 */
	static function stats()
	{
		if (!self::$enabled) return null;
		$accessSize = self::$accessPath && file_exists(self::$accessPath)
			? filesize(self::$accessPath) : 0;
		$errorSize = self::$errorPath && file_exists(self::$errorPath)
			? filesize(self::$errorPath) : 0;
		$archives = self::$dir ? count(glob(self::$dir . '/*.gz')) : 0;
		return array(
			'dir' => self::$dir,
			'accessSize' => $accessSize,
			'errorSize' => $errorSize,
			'archives' => $archives,
			'bufferBytes' => strlen(self::$accessBuf),
		);
	}

	static function shutdown()
	{
		self::flush();
		if (self::$accessFp) { @fclose(self::$accessFp); self::$accessFp = null; }
		if (self::$errorFp) { @fclose(self::$errorFp); self::$errorFp = null; }
	}
}
