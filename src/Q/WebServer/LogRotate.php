<?php
/**
 * @module Q
 */
/**
 * Rotation, compression and deletion for the server's other log files.
 *
 * Q_WebServer_Log already does this for access.log and error.log. The rest (the relay's log, the
 * autohost log, the watchdog log, and any file you add) grew for ever. This runs hourly in the server
 * and applies one policy to each:
 *
 *   rotate    when the file is bigger than maxSize, or when rotateDays have passed since the last
 *             rotation (a note of when is kept in .rotated.json beside the log): the file is renamed
 *             to <name>.<date>.log and a new one starts. Writers that open the file for each write
 *             need nothing; the relay's writer notices the rename and reopens.
 *   archive   rotated files older than archiveAfterDays are compressed to .gz.
 *   delete    rotated files and archives older than deleteAfterDays are removed.
 *
 * Only files this class made (<name>.<YYYY-MM-DD>[-HHMMSS].log[.gz]) are ever compressed or deleted;
 * the live file is never deleted.
 *
 * Config, Q/webserver/logs:
 *   maxSize           bytes before a rotation (default 20971520)
 *   rotateDays        days between rotations (default 1; 0 = size only)
 *   archiveAfterDays  default 2
 *   deleteAfterDays   default 30 (0 = keep archives)
 *   files             extra log files to manage: {"name": "/path/to/file.log"}
 *   managed           set to false to turn all of this off
 *
 * @class Q_WebServer_LogRotate
 */
class Q_WebServer_LogRotate
{
	/** @var mixed */
	static $timer = null;

	/** Start the hourly run (and a first one shortly after startup). */
	static function boot()
	{
		if (self::$timer !== null || !class_exists('Q_Evented', false)) return;
		if (Q_Config::get('Q', 'webserver', 'logs', 'managed', true) === false) return;
		Q_Evented::delay(45.0, function () { Q_WebServer_LogRotate::housekeeping(); });
		self::$timer = Q_Evented::repeat(3600.0, function () { Q_WebServer_LogRotate::housekeeping(); });
	}

	/** Everything periodic: the logs and the relay database's retention. @return array What was done */
	static function housekeeping($now = null)
	{
		$done = array('logs' => array(), 'retention' => array());
		try {
			$done['logs'] = self::run($now);
		} catch (Throwable $e) {
			error_log('[logs] rotation failed: ' . $e->getMessage());
		}
		try {
			if (class_exists('Q_Relay_Db') && is_file(Q_Config::get('Q', 'relay', 'db', 'path', qbix_data_path('local/relay.db')))) {
				$done['retention'] = Q_Relay_Retention::prune($now);
			}
		} catch (Throwable $e) {
			error_log('[logs] relay retention failed: ' . $e->getMessage());
		}
		return $done;
	}

	/** name => absolute path of the logs this server manages */
	static function files()
	{
		$out = array();
		$relay = class_exists('Q_Relay') ? Q_Relay::logPath() : 'local/relay.log';
		$out['relay'] = self::absolute($relay);
		$out['autohost'] = self::absolute(Q_Config::get('Q', 'webserver', 'autohost', 'log', qbix_data_path('local/autohost.log')));
		$out['watchdog'] = self::absolute(Q_Config::get('Q', 'webserver', 'watchdog', 'log', qbix_data_path('local/watchdog.log')));
		$extra = Q_Config::get('Q', 'webserver', 'logs', 'files', array());
		if (is_array($extra)) {
			foreach ($extra as $name => $path) {
				if (is_string($name) && preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $name) && is_string($path) && $path !== '') {
					$out[$name] = self::absolute($path);
				}
			}
		}
		return $out;
	}

	protected static function absolute($path)
	{
		if ($path !== '' && ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path))) return $path;
		$cwd = getcwd() . '/' . $path;
		return (is_file($cwd) || !function_exists('qbix_data_path')) ? $cwd : qbix_data_path($path);
	}

	/**
	 * @param int|null $now
	 * @return array name => ['rotated' => ?string, 'archived' => int, 'deleted' => int]
	 */
	static function run($now = null)
	{
		if ($now === null) $now = time();
		$cfg = Q_Config::get('Q', 'webserver', 'logs', array());
		if (!is_array($cfg)) $cfg = array();
		if (isset($cfg['managed']) && $cfg['managed'] === false) return array();
		$maxSize = max(1024, (int) (isset($cfg['maxSize']) ? $cfg['maxSize'] : 20971520));
		$rotateDays = max(0, (int) (isset($cfg['rotateDays']) ? $cfg['rotateDays'] : 1));
		$archiveDays = max(0, (int) (isset($cfg['archiveAfterDays']) ? $cfg['archiveAfterDays'] : 2));
		$deleteDays = max(0, (int) (isset($cfg['deleteAfterDays']) ? $cfg['deleteAfterDays'] : 30));
		$out = array();
		foreach (self::files() as $name => $path) {
			$r = array('rotated' => null, 'archived' => 0, 'deleted' => 0);
			$dir = dirname($path);
			if (!is_dir($dir)) continue;
			$state = self::state($dir);
			$last = isset($state[$path]) ? (int) $state[$path] : null;
			if (is_file($path)) {
				clearstatcache(true, $path);
				$size = (int) @filesize($path);
				if ($last === null) {
					// first sight: start the clock, from the file's own age if it has one
					$last = (int) min($now, @filemtime($path) ?: $now);
					$state[$path] = $last;
					self::saveState($dir, $state);
				}
				$due = $size > 0 && ($size > $maxSize || ($rotateDays > 0 && $now - $last >= $rotateDays * 86400));
				if ($due) {
					$to = self::rotatedName($path, $now);
					if (@rename($path, $to)) {
						$r['rotated'] = basename($to);
						$state[$path] = $now;
						self::saveState($dir, $state);
					}
				}
			}
			list($r['archived'], $r['deleted']) = self::age($path, $now, $archiveDays, $deleteDays);
			$out[$name] = $r;
		}
		return $out;
	}

	/** <dir>/<name>.<date>.log, with the time added if that exists. */
	static function rotatedName($path, $now)
	{
		$base = preg_replace('/\.log$/', '', $path);
		$to = $base . '.' . date('Y-m-d', $now) . '.log';
		if (file_exists($to) || file_exists($to . '.gz')) $to = $base . '.' . date('Y-m-d-His', $now) . '.log';
		$n = 1;
		while (file_exists($to) || file_exists($to . '.gz')) $to = $base . '.' . date('Y-m-d-His', $now) . '-' . (++$n) . '.log';
		return $to;
	}

	/** Compress and delete this log's rotated files. @return array [archived, deleted] */
	static function age($path, $now, $archiveDays, $deleteDays)
	{
		$base = preg_replace('/\.log$/', '', $path);
		$archived = $deleted = 0;
		$rx = '/^' . preg_quote(basename($base), '/') . '\.\d{4}-\d{2}-\d{2}(-\d{6}(-\d+)?)?\.log(\.gz)?$/D';
		foreach ((array) glob($base . '.*') as $f) {
			if (!is_file($f) || !preg_match($rx, basename($f))) continue;
			$age = $now - (int) @filemtime($f);
			if ($deleteDays > 0 && $age > $deleteDays * 86400) {
				if (@unlink($f)) ++$deleted;
				continue;
			}
			if ($archiveDays > 0 && substr($f, -4) === '.log' && $age > $archiveDays * 86400 && function_exists('gzopen')) {
				if (self::gzip($f)) ++$archived;
			}
		}
		return array($archived, $deleted);
	}

	protected static function gzip($file)
	{
		$gz = $file . '.gz';
		if (file_exists($gz)) return false;
		$tmp = $gz . '.tmp';
		$in = @fopen($file, 'rb');
		$out = @gzopen($tmp, 'wb9');
		if (!$in || !$out) {
			if ($in) fclose($in);
			if ($out) gzclose($out);
			return false;
		}
		while (!feof($in)) {
			$chunk = fread($in, 65536);
			if ($chunk === false) break;
			gzwrite($out, $chunk);
		}
		fclose($in);
		gzclose($out);
		$mtime = @filemtime($file);
		if (!@rename($tmp, $gz)) { @unlink($tmp); return false; }
		if ($mtime) @touch($gz, $mtime);
		@unlink($file);
		return true;
	}

	protected static function state($dir)
	{
		$f = $dir . '/.rotated.json';
		$d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
		return is_array($d) ? $d : array();
	}

	protected static function saveState($dir, array $state)
	{
		$f = $dir . '/.rotated.json';
		$tmp = $f . '.' . getmypid();
		if (@file_put_contents($tmp, json_encode($state)) !== false) @rename($tmp, $f);
	}
}
