<?php
/**
 * Finds which process is listening on a TCP port, so that "address already in use" can say who has it.
 * Uses what the system has: lsof (macOS, Linux), ss (Linux), /proc, netstat and PowerShell (Windows).
 * A process that belongs to another user may be invisible without sudo; the report says so.
 *
 * @class Q_WebServer_PortOwner
 */
class Q_WebServer_PortOwner
{
	/**
	 * @method find
	 * @static
	 * @param {integer} $port
	 * @return {array} list of array(pid, name, user, command, cwd), possibly empty
	 */
	static function find($port)
	{
		$port = (int) $port;
		if ($port < 1 || $port > 65535) return array();
		$pids = PHP_OS_FAMILY === 'Windows' ? self::pidsWindows($port) : self::pidsUnix($port);
		if (PHP_OS_FAMILY !== 'Windows' && count($pids) > 1) {
			// Worker processes inherit the listening socket: report the parent that owns it, not each child
			foreach (array_keys($pids) as $pid) {
				$pp = trim(self::run('ps -p ' . (int) $pid . ' -o ppid='));
				if ($pp === '') { unset($pids[$pid]); continue; } // already gone: the shell that ran lsof
				$pp = (int) $pp;
				// (also the shell that ran lsof for us, which inherited our open sockets)
				if ($pp && (isset($pids[$pp]) || $pp === getmypid())) unset($pids[$pid]);
			}
		}
		$out = array();
		foreach ($pids as $pid => $name) {
			$out[] = PHP_OS_FAMILY === 'Windows' ? self::detailsWindows($pid, $name) : self::detailsUnix($pid, $name);
		}
		return $out;
	}

	/**
	 * A few lines of text for the terminal.
	 * @method report
	 * @static
	 * @param {integer} $port
	 * @param {string} [$script] How the user started the server, for the "use another port" hint
	 * @return {string}
	 */
	static function report($port, $script = 'php qbixserver.php')
	{
		$port = (int) $port;
		$found = self::find($port);
		$txt = "\n  Port $port is already in use";
		if (!$found) {
			$txt .= ", but this account cannot see which process has it.\n";
			if (PHP_OS_FAMILY === 'Windows') {
				$txt .= "  From an administrator prompt:  netstat -ano | findstr :$port\n";
			} else {
				$txt .= "  Try:  sudo lsof -nP -iTCP:$port -sTCP:LISTEN\n";
			}
		} else {
			$txt .= " by:\n";
			foreach ($found as $p) {
				$txt .= "\n    PID $p[pid]   $p[name]" . ($p['user'] !== '' ? "   (user $p[user])" : '') . "\n";
				if ($p['command'] !== '') $txt .= "    Command:  $p[command]\n";
				if ($p['cwd'] !== '') $txt .= "    Started in:  $p[cwd]\n";
				if (stripos($p['command'] . ' ' . $p['name'], 'qbixserver') !== false) {
					$txt .= "    That looks like another Qbix Server.\n";
				}
				if (PHP_OS_FAMILY === 'Windows') {
					$txt .= "    Stop it:  taskkill /PID $p[pid] /F\n";
				} else {
					$mine = function_exists('posix_geteuid') && ($u = @posix_getpwuid(posix_geteuid())) && $u['name'] === $p['user'];
					$txt .= "    Stop it:  " . ($mine ? '' : 'sudo ') . "kill $p[pid]\n";
				}
			}
		}
		$next = $port < 65000 ? ($port >= 1024 ? $port + 1 : 8080) : 8080;
		$txt .= "\n  Or use another port:  $script --port $next\n";
		return $txt;
	}

	/** @return boolean whether the error text from a failed bind means the port is taken */
	static function isInUse($message, $code = 0)
	{
		if (in_array((int) $code, array(98, 48, 10048), true)) return true;
		return (bool) preg_match('/already in use|address in use|only one usage of each socket/i', (string) $message);
	}

	private static function run($cmd)
	{
		if (!function_exists('shell_exec')) return '';
		$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
		if (in_array('shell_exec', $disabled, true)) return '';
		return (string) @shell_exec($cmd . ' 2>/dev/null');
	}

	private static function have($bin)
	{
		return trim(self::run('command -v ' . escapeshellarg($bin))) !== '';
	}

	private static function pidsUnix($port)
	{
		$pids = array();
		if (self::have('lsof')) {
			// p<pid>, c<command> per process
			$o = self::run('lsof -nP -iTCP:' . $port . ' -sTCP:LISTEN -Fpc');
			$pid = 0;
			foreach (preg_split('/\R/', $o) as $line) {
				if ($line === '') continue;
				if ($line[0] === 'p') { $pid = (int) substr($line, 1); $pids[$pid] = ''; }
				elseif ($line[0] === 'c' && $pid) $pids[$pid] = substr($line, 1);
			}
		}
		if (!$pids && self::have('ss')) {
			$o = self::run('ss -H -ltnp "sport = :' . $port . '"');
			if (preg_match_all('/\("([^"]*)",pid=(\d+)/', $o, $m, PREG_SET_ORDER)) {
				foreach ($m as $x) $pids[(int) $x[2]] = $x[1];
			}
		}
		if (!$pids && is_readable('/proc/net/tcp')) {
			// Without lsof or ss: find the socket inode, then the process that holds it
			$inode = null;
			foreach (array('/proc/net/tcp', '/proc/net/tcp6') as $f) {
				foreach ((array) @file($f, FILE_IGNORE_NEW_LINES) as $line) {
					$c = preg_split('/\s+/', trim($line));
					if (count($c) > 9 && $c[3] === '0A' && hexdec(substr($c[1], strrpos($c[1], ':') + 1)) === $port) {
						$inode = $c[9];
						break 2;
					}
				}
			}
			if ($inode) {
				foreach ((array) glob('/proc/[0-9]*/fd/*') as $fd) {
					if (@readlink($fd) === "socket:[$inode]") {
						$pid = (int) explode('/', $fd)[2];
						$pids[$pid] = trim((string) @file_get_contents("/proc/$pid/comm"));
						break;
					}
				}
			}
		}
		return $pids;
	}

	private static function detailsUnix($pid, $name)
	{
		$pid = (int) $pid;
		$cmd = trim(self::run('ps -p ' . $pid . ' -o args='));
		if ($cmd === '' && is_readable("/proc/$pid/cmdline")) {
			$cmd = trim(str_replace("\0", ' ', (string) @file_get_contents("/proc/$pid/cmdline")));
		}
		$user = trim(self::run('ps -p ' . $pid . ' -o user='));
		$cwd = '';
		if (is_link("/proc/$pid/cwd")) {
			$cwd = (string) @readlink("/proc/$pid/cwd");
		} elseif (self::have('lsof')) {
			foreach (preg_split('/\R/', self::run('lsof -a -p ' . $pid . ' -d cwd -Fn')) as $l) {
				if ($l !== '' && $l[0] === 'n') $cwd = substr($l, 1);
			}
		}
		if ($name === '' && $cmd !== '') $name = basename(strtok($cmd, ' '));
		return array('pid' => $pid, 'name' => $name, 'user' => $user, 'command' => $cmd, 'cwd' => $cwd);
	}

	private static function pidsWindows($port)
	{
		$pids = array();
		foreach (preg_split('/\R/', self::run('netstat -ano -p TCP')) as $line) {
			if (preg_match('/^\s*TCP\s+\S+:' . $port . '\s+\S+\s+LISTENING\s+(\d+)/i', $line, $m)) {
				$pids[(int) $m[1]] = '';
			}
		}
		return $pids;
	}

	private static function detailsWindows($pid, $name)
	{
		$pid = (int) $pid;
		$t = trim(self::run('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH'));
		if (preg_match('/^"([^"]+)"/', $t, $m)) $name = $m[1];
		$cmd = trim(self::run('powershell -NoProfile -Command "(Get-CimInstance Win32_Process -Filter \'ProcessId=' . $pid . '\').CommandLine"'));
		return array('pid' => $pid, 'name' => $name, 'user' => '', 'command' => $cmd, 'cwd' => '');
	}
}
