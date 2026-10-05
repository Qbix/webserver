<?php
/**
 * @module Q
 */
/**
 * Server dashboard: comprehensive stats, live HTML display at /Q/dashboard,
 * real-time updates via Q_WebSocket on the 'dashboard' channel.
 * Tabbed interface: HTTP, WebSocket, Email, Mobile — each with real-time
 * analytics and interactive Sankey flow diagrams.
 * @class Q_WebServer_Dashboard
 */
class Q_WebServer_Dashboard
{
	static $stats = array(
		'startTime' => 0, 'requests' => 0,
		'status2xx' => 0, 'status3xx' => 0, 'status4xx' => 0, 'status5xx' => 0,
		'phpRequests' => 0, 'staticRequests' => 0,
		'bytesOut' => 0, 'totalMs' => 0,
		'slowest' => 0, 'slowestUri' => '',
	);
	static $recentRequests = array();
	static $topPaths = array(); // path => [count, totalMs]
	static $statusCodes = array(); // code => count
	static $rpsHistory = array(); // [timestamp => count] for sparkline

	static function init()
	{
		self::$stats['startTime'] = time();
		self::$maxSessions = (int) Q_Config::get('Q', 'dashboard', 'maxSessions', 20);

		// Push stats every 2 seconds so the dashboard stays live
		// even when no requests are coming in
		Q_Evented::repeat(2.0, function () {
			if (empty(Q_WebSocket::$channels['dashboard'])) return;
			Q_WebSocket::broadcastTo('dashboard', array(
				'type' => 'heartbeat', 'stats' => Q_WebServer_Dashboard::getStats()
			));
		});
	}

	static $sessions = array();  // sessionId => lastSeen timestamp (MRU order)
	static $maxSessions = 20;    // configurable cap

	static function recordRequest($method, $uri, $status, $ms, $bytes = 0,
		$isPhp = false, $contentType = '', $memUsed = 0, $cookies = array())
	{
		self::$stats['requests']++;
		self::$stats['totalMs'] += $ms;
		self::$stats['bytesOut'] += $bytes;
		if ($isPhp) self::$stats['phpRequests']++;
		else self::$stats['staticRequests']++;

		if ($ms > self::$stats['slowest']) {
			self::$stats['slowest'] = $ms;
			self::$stats['slowestUri'] = $uri;
		}

		if ($status < 300) self::$stats['status2xx']++;
		elseif ($status < 400) self::$stats['status3xx']++;
		elseif ($status < 500) self::$stats['status4xx']++;
		else self::$stats['status5xx']++;

		if (!isset(self::$statusCodes[$status])) self::$statusCodes[$status] = 0;
		self::$statusCodes[$status]++;

		$pathKey = $method . ' ' . strtok($uri, '?');
		if (!isset(self::$topPaths[$pathKey])) self::$topPaths[$pathKey] = array(0, 0);
		self::$topPaths[$pathKey][0]++;
		self::$topPaths[$pathKey][1] += $ms;

		$sec = time();
		if (!isset(self::$rpsHistory[$sec])) self::$rpsHistory[$sec] = 0;
		self::$rpsHistory[$sec]++;
		$cutoff = $sec - 60;
		foreach (self::$rpsHistory as $t => $c) {
			if ($t < $cutoff) unset(self::$rpsHistory[$t]);
			else break;
		}

		$kind = $isPhp ? 'php' : self::mimeKind($uri, $contentType);

		// Detect session ID from cookies
		$sessionId = '';
		foreach ($cookies as $name => $val) {
			if ($name === 'PHPSESSID' || strpos($name, 'sessionId') === 0
				|| strpos($name, 'Q_sessionId') === 0
			) {
				$sessionId = substr($val, 0, 12); // short prefix for display
				break;
			}
		}
		if ($sessionId !== '') {
			self::$sessions[$sessionId] = time();
			// Evict beyond cap
			if (count(self::$sessions) > self::$maxSessions) {
				asort(self::$sessions);
				self::$sessions = array_slice(self::$sessions,
					-self::$maxSessions, null, true);
			}
		}

		$entry = array('time' => date('H:i:s'), 'method' => $method,
			'uri' => $uri, 'status' => $status, 'ms' => $ms, 'kind' => $kind,
			'mem' => $memUsed, 'sid' => $sessionId);
		self::$recentRequests[] = $entry;
		if (count(self::$recentRequests) > 200) array_shift(self::$recentRequests);

		// Only build stats + broadcast if a dashboard client is connected
		if (!empty(Q_WebSocket::$channels['dashboard'])) {
			Q_WebSocket::broadcastTo('dashboard', array(
				'type' => 'request', 'entry' => $entry, 'stats' => self::getStats()
			));
		}
	}

	/**
	 * Map URI extension or content-type to a kind for dashboard icons.
	 * @return {string} php, html, css, js, img, font, json, xml, doc, media, file
	 */
	static function mimeKind($uri, $contentType = '')
	{
		$ext = strtolower(pathinfo(strtok($uri, '?') ?: '', PATHINFO_EXTENSION));
		static $map = array(
			'html' => 'html', 'htm' => 'html',
			'css' => 'css', 'less' => 'css', 'scss' => 'css',
			'js' => 'js', 'mjs' => 'js', 'ts' => 'js',
			'png' => 'img', 'jpg' => 'img', 'jpeg' => 'img', 'gif' => 'img',
			'svg' => 'img', 'webp' => 'img', 'ico' => 'img', 'avif' => 'img',
			'woff' => 'font', 'woff2' => 'font', 'ttf' => 'font', 'otf' => 'font', 'eot' => 'font',
			'json' => 'json', 'xml' => 'xml', 'rss' => 'xml',
			'pdf' => 'doc', 'doc' => 'doc', 'docx' => 'doc', 'txt' => 'doc', 'md' => 'doc',
			'mp4' => 'media', 'webm' => 'media', 'mp3' => 'media', 'ogg' => 'media',
			'zip' => 'file', 'gz' => 'file', 'tar' => 'file',
		);
		if (isset($map[$ext])) return $map[$ext];
		if ($contentType) {
			if (strpos($contentType, 'html') !== false) return 'html';
			if (strpos($contentType, 'css') !== false) return 'css';
			if (strpos($contentType, 'javascript') !== false) return 'js';
			if (strpos($contentType, 'image/') !== false) return 'img';
			if (strpos($contentType, 'json') !== false) return 'json';
		}
		return 'file';
	}


	static function getStats()
	{
		$up = time() - self::$stats['startTime'];
		$pool = Q_WebServer::$pool;
		$reqs = self::$stats['requests'];
		$avgMs = $reqs > 0 ? round(self::$stats['totalMs'] / $reqs, 1) : 0;
		$rps = $up > 0 ? round($reqs / $up, 1) : 0;

		// Current RPS (last 5 seconds)
		$now = time();
		$recent5 = 0;
		for ($i = 1; $i <= 5; $i++) {
			$recent5 += self::$rpsHistory[$now - $i] ?? 0;
		}
		$currentRps = round($recent5 / 5, 1);

		// Top 10 paths by count
		$topPaths = self::$topPaths;
		uasort($topPaths, function($a, $b) { return $b[0] - $a[0]; });
		$topPaths = array_slice($topPaths, 0, 10, true);
		$topFormatted = array();
		foreach ($topPaths as $path => $data) {
			$topFormatted[] = array(
				'path' => $path,
				'count' => $data[0],
				'avgMs' => $data[0] > 0 ? round($data[1] / $data[0], 1) : 0,
			);
		}

		// RPS sparkline data (last 60 seconds)
		$sparkline = array();
		for ($i = 59; $i >= 0; $i--) {
			$sparkline[] = self::$rpsHistory[$now - $i] ?? 0;
		}

		// Connection counts
		$keepAlive = count(Q_WebServer::$keepAliveCount);
		$wsConnections = count(Q_WebSocket::$workers);
		$wsRooms = count(Q_WebSocket::$roomWorkers);
		$activeRooms = array();
		foreach (Q_WebSocket::$roomWorkers as $name => $rw) {
			$activeRooms[] = array(
				'name' => $name,
				'members' => count($rw['members'] ?? array()),
			);
		}

		// Relay status (read from file written by qbixrelay.php)
		$relayStatus = null;
		$relayStatusPath = qbix_data_path('local/relay-status.json');
		if (is_file($relayStatusPath)) {
			$raw = @file_get_contents($relayStatusPath);
			if ($raw) {
				$relayStatus = json_decode($raw, true);
				// Mark stale if status file is older than 120 seconds
				if ($relayStatus && isset($relayStatus['timestamp'])) {
					$relayStatus['stale'] = (time() - $relayStatus['timestamp']) > 120;
				}
			}
		}

		return array(
			'uptime' => self::fmtUp($up), 'uptimeSec' => $up,
			'requests' => $reqs,
			'rps' => $rps, 'currentRps' => $currentRps,
			'avgMs' => $avgMs,
			'slowest' => self::$stats['slowest'],
			'slowestUri' => self::$stats['slowestUri'],
			'status2xx' => self::$stats['status2xx'],
			'status3xx' => self::$stats['status3xx'],
			'status4xx' => self::$stats['status4xx'],
			'status5xx' => self::$stats['status5xx'],
			'statusCodes' => self::$statusCodes,
			'phpRequests' => self::$stats['phpRequests'],
			'staticRequests' => self::$stats['staticRequests'],
			'bytesOut' => self::$stats['bytesOut'],
			'bytesFormatted' => self::fmtBytes(self::$stats['bytesOut']),
			'memory' => round(memory_get_usage(true)/1048576, 1),
			'memoryPeak' => round(memory_get_peak_usage(true)/1048576, 1),
			'workers' => $pool ? $pool->idleCount().'/'.$pool->targetSize : 'fork',
			'workerStats' => $pool ? self::cachedWorkerStats($pool) : null,
			'systemRam' => self::cachedSystemRam(),
			'parentPid' => getmypid(),
			'forkMode' => !$pool,
			'wsClients' => Q_WebSocket::clientCount(),
			'wsConnections' => $wsConnections,
			'wsRooms' => $wsRooms,
			'activeRooms' => $activeRooms,
			'connections' => count(Q_WebServer::$clients),
			'keepAlive' => $keepAlive,
			'topPaths' => $topFormatted,
			'sparkline' => $sparkline,
			'cache' => Q_WebServer_Cache::stats(),
			'log' => Q_WebServer_Log::stats(),
			'components' => Q_WebServer_Cache_Components::enabled()
				? Q_WebServer_Cache_Components::stats() : null,
			'php' => PHP_VERSION,
			'os' => PHP_OS,
			'sessions' => self::getSessionList(),
			'relay' => $relayStatus,
		);
	}

	/**
	 * Return sessions sorted by most recently used (newest first).
	 */
	static function getSessionList()
	{
		if (empty(self::$sessions)) return array();
		arsort(self::$sessions); // highest timestamp first = most recent
		$list = array();
		foreach (self::$sessions as $sid => $ts) {
			$list[] = array('id' => $sid, 'last' => date('H:i:s', $ts));
		}
		return $list;
	}

	static function handle($client, $parsed)
	{
		$p = $parsed['path'];
		if ($p === '/Q/dashboard' || $p === '/Q/dashboard/') {
			// If panel password is set, require auth (cookie or query token)
			if (Q_WebServer_Panel::hasPassword()) {
				$cookie = $parsed['cookies']['Q_panel_token'] ?? '';
				$qp = array();
				if (!empty($parsed['query'])) parse_str($parsed['query'], $qp);
				$qToken = $qp['token'] ?? '';
				if (!Q_WebServer_Panel::validateToken($cookie)
					&& !Q_WebServer_Panel::validateToken($qToken)
				) {
					Q_WebServer::sendRedirect($client,
						'/Q/panel?next=' . urlencode('/Q/dashboard'));
					return true;
				}
			}
			Q_WebServer::sendResponse($client, 200, self::renderHtml($parsed), 'text/html; charset=utf-8');
			return true;
		}
		if ($p === '/Q/stats') {
			Q_WebServer::sendResponse($client, 200, json_encode(self::getStats()), 'application/json');
			return true;
		}
		return false;
	}

	// ── Cached stats (avoid shell calls every heartbeat) ──

	private static $cachedRam = null;
	private static $cachedRamTime = 0;
	private static $cachedWs = null;
	private static $cachedWsTime = 0;

	static function cachedSystemRam()
	{
		$now = time();
		if (self::$cachedRam && ($now - self::$cachedRamTime) < 5) {
			return self::$cachedRam;
		}
		self::$cachedRam = self::getSystemRam();
		self::$cachedRamTime = $now;
		return self::$cachedRam;
	}

	static function cachedWorkerStats($pool)
	{
		$now = time();
		if (self::$cachedWs && ($now - self::$cachedWsTime) < 3) {
			// Update idle count (cheap) even on cache hit
			self::$cachedWs['idle'] = $pool->idleCount();
			return self::$cachedWs;
		}
		self::$cachedWs = $pool->getWorkerStats();
		self::$cachedWsTime = $now;
		return self::$cachedWs;
	}

	static function getSystemRam()
	{
		// Linux: read /proc/meminfo
		$meminfo = @file_get_contents('/proc/meminfo');
		if ($meminfo) {
			$info = array();
			foreach (explode("\n", $meminfo) as $line) {
				if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
					$info[$m[1]] = (int) $m[2]; // kB
				}
			}
			$total = ($info['MemTotal'] ?? 0) / 1024;
			$available = ($info['MemAvailable'] ?? 0) / 1024;
			$used = $total - $available;
			return array(
				'totalMb' => round($total),
				'usedMb' => round($used),
				'availableMb' => round($available),
				'percent' => $total > 0 ? round($used / $total * 100) : 0,
			);
		}
		// macOS: sysctl + vm_stat
		if (PHP_OS_FAMILY === 'Darwin') {
			$totalBytes = (int) trim(shell_exec('sysctl -n hw.memsize 2>/dev/null') ?? '0');
			$vmstat = @shell_exec('vm_stat 2>/dev/null');
			if ($totalBytes && $vmstat) {
				$pageSize = 4096;
				if (preg_match('/page size of (\d+)/', $vmstat, $ps)) $pageSize = (int) $ps[1];
				preg_match('/Pages free:\s+(\d+)/', $vmstat, $mf);
				preg_match('/Pages active:\s+(\d+)/', $vmstat, $ma);
				preg_match('/Pages inactive:\s+(\d+)/', $vmstat, $mi);
				preg_match('/Pages speculative:\s+(\d+)/', $vmstat, $ms);
				preg_match('/Pages wired down:\s+(\d+)/', $vmstat, $mw);
				preg_match('/Pages occupied by compressor:\s+(\d+)/', $vmstat, $mc);
				$total = $totalBytes / 1048576;
				$used = ((int)($ma[1] ?? 0) + (int)($mw[1] ?? 0) + (int)($mc[1] ?? 0)) * $pageSize / 1048576;
				$available = $total - $used;
				return array(
					'totalMb' => round($total),
					'usedMb' => round($used),
					'availableMb' => round($available),
					'percent' => $total > 0 ? round($used / $total * 100) : 0,
				);
			}
		}
		// Windows: wmic or powershell
		if (PHP_OS_FAMILY === 'Windows') {
			$out = @shell_exec('wmic OS get TotalVisibleMemorySize,FreePhysicalMemory /VALUE 2>NUL');
			if ($out) {
				$total = 0;
				$free = 0;
				if (preg_match('/TotalVisibleMemorySize=(\d+)/', $out, $m)) $total = (int) $m[1] / 1024;
				if (preg_match('/FreePhysicalMemory=(\d+)/', $out, $m)) $free = (int) $m[1] / 1024;
				$used = $total - $free;
				return array(
					'totalMb' => round($total),
					'usedMb' => round($used),
					'availableMb' => round($free),
					'percent' => $total > 0 ? round($used / $total * 100) : 0,
				);
			}
			// Fallback: PowerShell
			$out = @shell_exec('powershell -Command "Get-CimInstance Win32_OperatingSystem | Select TotalVisibleMemorySize,FreePhysicalMemory | ConvertTo-Json" 2>NUL');
			if ($out) {
				$d = json_decode($out, true);
				if ($d) {
					$total = ($d['TotalVisibleMemorySize'] ?? 0) / 1024;
					$free = ($d['FreePhysicalMemory'] ?? 0) / 1024;
					return array(
						'totalMb' => round($total),
						'usedMb' => round($total - $free),
						'availableMb' => round($free),
						'percent' => $total > 0 ? round(($total - $free) / $total * 100) : 0,
					);
				}
			}
		}
		return null;
	}

	static function fmtUp($s) {
		if ($s < 60) return "{$s}s";
		$d = floor($s/86400); $h = floor(($s%86400)/3600);
		$m = floor(($s%3600)/60);
		if ($d > 0) return "{$d}d {$h}h {$m}m";
		if ($h > 0) return "{$h}h {$m}m";
		return "{$m}m ".($s%60).'s';
	}

	static function fmtBytes($b) {
		if ($b < 1024) return $b . ' B';
		if ($b < 1048576) return round($b/1024, 1) . ' KB';
		if ($b < 1073741824) return round($b/1048576, 1) . ' MB';
		return round($b/1073741824, 2) . ' GB';
	}

	static function renderHtml($parsed)
	{
		$stats = json_encode(self::getStats());
		$recent = json_encode(array_reverse(array_slice(self::$recentRequests, -50)));
		$host = $parsed['headers']['host'] ?? 'localhost';
		$hasPassword = Q_WebServer_Panel::hasPassword();
		$relayEnabled = class_exists('Q_Relay') && Q_Config::get('Q', 'relay', 'enabled', false);
		$smtpPort = Q_Config::get('Q', 'relay', 'smtp', 'port', 2525);
		$mobileEnabled = Q_Config::get('Q', 'relay', 'mobile', 'enabled', false);
		$clientMetricsEnabled = class_exists('Q_WebServer_ClientMetrics', false)
			&& Q_WebServer_ClientMetrics::enabled();

		// Get auth token for WebSocket connection
		$wsToken = '';
		$cookie = $parsed['cookies']['Q_panel_token'] ?? '';
		if ($cookie && Q_WebServer_Panel::validateToken($cookie)) {
			$wsToken = $cookie;
		}
		if (!$wsToken) {
			$qp = array();
			if (!empty($parsed['query'])) parse_str($parsed['query'], $qp);
			$wsToken = $qp['token'] ?? '';
		}
		if (!$wsToken) {
			$wsToken = Q_Config::get('Q', 'dashboard', 'token', '');
		}

		$tokenParam = $wsToken ? "?token=$wsToken" : '';
		$jsTokenParam = $wsToken ? addslashes($wsToken) : '';
		$baseUrl = "http://$host";

		$html = '<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark">
<title>Qbix Server Dashboard</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/d3/7.8.5/d3.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/d3-sankey/0.12.3/d3-sankey.min.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0f1117;--sfc:#1a1d27;--sfc2:#222533;--bdr:#2a2d3a;--txt:#e1e4ed;--dim:#6b7089;
--ac:#7c8aff;--grn:#4ade80;--yel:#fbbf24;--red:#f87171;--cyn:#22d3ee;--pur:#a78bfa}
@media(prefers-color-scheme:light){:root{--bg:#f4f5f7;--sfc:#fff;--sfc2:#f0f1f3;--bdr:rgba(0,0,0,.1);--txt:#1a1a2e;--dim:#6b7089}}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
background:var(--bg);color:var(--txt);padding:24px;font-size:13px;max-width:1400px;margin:0 auto}
h1{font-size:20px;font-weight:600;margin-bottom:4px;color:var(--ac);display:flex;align-items:center;gap:10px}
h1 .dot{width:8px;height:8px;border-radius:50%;background:var(--grn);animation:pulse 2s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.sub{font-size:12px;color:var(--dim);margin-bottom:16px}
.nav-links{display:flex;gap:12px;margin-bottom:16px;font-size:12px}
.nav-links a{color:var(--ac);text-decoration:none;padding:4px 10px;border:1px solid var(--bdr);border-radius:4px}
.nav-links a:hover{border-color:var(--ac);background:rgba(124,138,255,.1)}
.banner{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:10px}
.banner a{color:inherit;font-weight:600;text-decoration:underline}
.banner-warn{background:rgba(251,191,36,.15);border:1px solid rgba(251,191,36,.3);color:var(--yel)}

/* Tab bar */
.tab-bar{display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid var(--bdr);overflow-x:auto}
.tab-btn{padding:10px 24px;font-size:13px;font-weight:600;color:var(--dim);background:none;border:none;
cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;font-family:inherit;
transition:color .15s,border-color .15s}
.tab-btn:hover{color:var(--txt)}
.tab-btn.active{color:var(--ac);border-bottom-color:var(--ac)}
.tab-btn .badge-count{font-size:10px;background:var(--sfc2);color:var(--dim);padding:1px 6px;border-radius:10px;margin-left:6px}
.tab-btn.active .badge-count{background:rgba(124,138,255,.15);color:var(--ac)}
.tab-content{display:none}.tab-content.active{display:block}

/* Cards grid */
.grid{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;justify-content:center}
.grid .card{flex:1 1 130px;max-width:220px;min-width:120px}
.card{background:var(--sfc);border:1px solid var(--bdr);border-radius:8px;padding:14px}
.card .l{font-size:10px;color:var(--dim);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px}
.card .v{font-size:22px;font-weight:700;line-height:1.2}
.card .s{font-size:11px;color:var(--dim);margin-top:4px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}
@media(max-width:700px){.row{grid-template-columns:1fr}.grid .card{flex:1 1 100px;min-width:90px}}
.panel{background:var(--sfc);border:1px solid var(--bdr);border-radius:8px;overflow:hidden;margin-bottom:16px}
.ph{padding:10px 14px;border-bottom:1px solid var(--bdr);font-weight:600;font-size:12px;
display:flex;justify-content:space-between;align-items:center}
.ph-btns{display:flex;gap:6px;align-items:center}
.ph-btn{background:none;border:1px solid var(--bdr);color:var(--dim);border-radius:4px;
padding:2px 8px;font-size:10px;cursor:pointer;font-family:inherit;transition:all .15s}
.ph-btn:hover{color:var(--txt);border-color:var(--txt)}
.ph-btn.active{color:var(--ac);border-color:var(--ac)}
.ph-sel{background:var(--sfc2);border:1px solid var(--bdr);color:var(--txt);border-radius:4px;
padding:2px 6px;font-size:10px;font-family:inherit;cursor:pointer;max-width:140px}
.pb{padding:8px 14px;max-height:260px;overflow-y:auto}
.spark{height:40px;display:flex;align-items:flex-end;gap:1px;margin:8px 14px}
.spark div{flex:1;background:var(--ac);border-radius:1px 1px 0 0;min-height:1px;opacity:.7;transition:height .3s}

/* Log entries */
.le{padding:4px 14px;font-size:12px;display:flex;gap:8px;align-items:center;
border-bottom:1px solid rgba(255,255,255,.03);font-family:"SF Mono","Fira Code",Consolas,monospace;transition:background .1s}
.le:hover{background:rgba(255,255,255,.04)}
.le .lk{min-width:18px;text-align:center;font-size:13px;flex-shrink:0}
.le .lt{color:var(--dim);min-width:58px;flex-shrink:0}
.le .ls{min-width:28px;font-weight:700;text-align:right;flex-shrink:0}
.le .lm{min-width:36px;color:var(--cyn);flex-shrink:0}
.le .lu{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.le .lu a{color:inherit;text-decoration:none}.le .lu a:hover{text-decoration:underline}
.le .ld{color:var(--dim);min-width:48px;text-align:right;flex-shrink:0}
.le .lmem{color:var(--pur);min-width:56px;text-align:right;flex-shrink:0;font-size:11px}
.s2{color:var(--grn)}.s3{color:var(--yel)}.s4,.s5{color:var(--red)}
.tp{display:flex;justify-content:space-between;padding:4px 0;font-size:12px;border-bottom:1px solid rgba(255,255,255,.03)}
.tp .p{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:"SF Mono",monospace}
.tp .c{min-width:50px;text-align:right;color:var(--ac)}.tp .a{min-width:50px;text-align:right;color:var(--dim)}
.ws{display:inline-flex;align-items:center;gap:6px;font-size:11px}
.wd{width:6px;height:6px;border-radius:50%;background:var(--red)}.wd.on{background:var(--grn)}
.room{display:flex;justify-content:space-between;padding:4px 0;font-size:12px}
.room .n{font-family:"SF Mono",monospace;color:var(--pur)}
.log-wrap{max-height:50vh;overflow-y:auto;display:flex;flex-direction:column-reverse}

/* Sankey diagrams */
.sankey-container{min-height:300px;position:relative;padding:16px}
.sankey-container svg{width:100%;height:100%}
.sankey-container .empty{display:flex;align-items:center;justify-content:center;height:280px;color:var(--dim);font-size:13px}
.sankey-node rect{cursor:pointer;transition:opacity .2s}
.sankey-node rect:hover{opacity:.8}
.sankey-node text{font-size:11px;fill:var(--txt);pointer-events:none}
.sankey-link{fill:none;stroke-opacity:.25;transition:stroke-opacity .2s}
.sankey-link:hover{stroke-opacity:.5}
.sankey-tooltip{position:absolute;background:var(--sfc2);border:1px solid var(--bdr);border-radius:6px;
padding:8px 12px;font-size:11px;pointer-events:none;z-index:10;white-space:nowrap;box-shadow:0 4px 12px rgba(0,0,0,.3)}
</style></head><body>';

		// Warning banner if no password set
		if (!$hasPassword) {
			$html .= '<div class="banner banner-warn">&#9888; No admin password set &#8212; anyone can access this dashboard and the control panel. <a href="/Q/panel">Set a password in the Control Panel</a></div>';
		}

		$html .= '<h1><span class="dot"></span>Qbix Server</h1>
<div class="sub" id="sub"></div>
<div class="nav-links">
<a href="/Q/panel">&#9881; Control Panel</a>
<a href="/Q/docs">&#128214; Docs</a>
<a href="/Q/dashboard">&#128200; Dashboard</a>
</div>

<!-- Tab bar -->
<div class="tab-bar">
<button class="tab-btn active" onclick="switchTab(\'http\')" id="tab-btn-http">&#127760; HTTP</button>
<button class="tab-btn" onclick="switchTab(\'ws\')" id="tab-btn-ws">&#128268; WebSocket <span class="badge-count" id="ws-badge">0</span></button>
<button class="tab-btn" onclick="switchTab(\'email\')" id="tab-btn-email">&#128231; Email</button>
<button class="tab-btn" onclick="switchTab(\'mobile\')" id="tab-btn-mobile">&#128241; Mobile</button>
</div>';

		// ── HTTP Tab ──
		$html .= '<div class="tab-content active" id="tab-http">
<div class="grid">
<div class="card"><div class="l">Total requests</div><div class="v" id="sr">0</div><div class="s" id="srps">0 avg req/s</div></div>
<div class="card"><div class="l">Current RPS</div><div class="v" id="crps" style="color:var(--cyn)">0</div><div class="s">last 5 sec</div></div>
<div class="card"><div class="l">Avg response</div><div class="v" id="avg">0<span style="font-size:12px;font-weight:400">ms</span></div><div class="s">slowest: <span id="slow">0ms</span></div></div>
<div class="card"><div class="l">Parent Memory</div><div class="v" id="sm">&#8212;</div><div class="s">peak <span id="smp">&#8212;</span></div></div>
<div class="card"><div class="l">Workers</div><div class="v" id="sw">&#8212;</div><div class="s" id="phpn">0 PHP / 0 static</div></div>
<div class="card"><div class="l">System RAM</div><div class="v" id="sysram">&#8212;</div><div class="s" id="sysram-detail">&#8212;</div></div>
<div class="card"><div class="l">Worker Memory (COW)</div><div class="v" id="cow-total">&#8212;</div><div class="s" id="cow-detail">&#8212;</div></div>
<div class="card"><div class="l">Data out</div><div class="v" id="bout">0</div><div class="s"><span id="conn">0</span> conn &#183; <span id="ka">0</span> keep-alive</div></div>
<div class="card"><div class="l">Status codes</div><div class="v" style="font-size:12px;line-height:1.8">
<span class="s2" id="s2">0</span> ok &#183; <span class="s3" id="s3">0</span> redir &#183; <span class="s4" id="s4">0</span> 4xx &#183; <span class="s5" id="s5">0</span> 5xx</div></div>
</div>

<div class="panel"><div class="ph">Throughput <span style="font-size:11px;color:var(--dim)">last 60s</span></div>
<div class="spark" id="spark"></div></div>

<div class="row">
<div class="panel"><div class="ph">Top paths</div><div class="pb" id="paths"></div></div>
<div class="panel"><div class="ph">Active rooms</div><div class="pb" id="rooms"><div style="color:var(--dim);padding:8px;font-size:12px">No active rooms</div></div></div>
</div>

<div class="panel"><div class="ph">Page Navigation Flow</div>
<div class="sankey-container" id="sankey-http"><div class="empty">Loading page flow data...</div></div></div>

<div class="panel"><div class="ph"><span>Live requests <span style="font-size:11px;color:var(--dim)" id="reqc">0 total</span></span>
<div class="ph-btns">
<select id="sc-filter" onchange="filterStatus()" title="Filter by status code" class="ph-sel">
<option value="">All status codes</option>
</select>
<select id="sid-filter" onchange="filterSession()" title="Filter by session" class="ph-sel">
<option value="">All sessions</option>
</select>
<button class="ph-btn" id="btn-pause" onclick="togglePause()" title="Pause/resume">&#9646;&#9646;</button>
<button class="ph-btn" onclick="clearLog()" title="Clear log">&times;</button>
</div></div>
<div class="log-wrap" id="log-wrap"><div id="log"></div></div></div>
</div>';

		// ── WebSocket Tab ──
		$html .= '<div class="tab-content" id="tab-ws">
<div class="grid">
<div class="card"><div class="l">Connections</div><div class="v" id="ws-conn" style="color:var(--pur)">0</div><div class="s">active WebSocket clients</div></div>
<div class="card"><div class="l">Rooms</div><div class="v" id="ws-rooms" style="color:var(--cyn)">0</div><div class="s">active pub/sub rooms</div></div>
<div class="card"><div class="l">Clients</div><div class="v" id="ws-clients">0</div><div class="s">total registered</div></div>
</div>
<div class="panel"><div class="ph">Active Rooms</div><div class="pb" id="ws-room-list"><div style="color:var(--dim);padding:8px;font-size:12px">No active rooms</div></div></div>
<div class="panel"><div class="ph">Section Navigation Flow</div>
<div class="sankey-container" id="sankey-ws"><div class="empty">Loading section flow data...</div></div></div>
</div>';

		// ── Email Tab ──
		$relayBadge = $relayEnabled
			? '<span style="font-size:10px;padding:2px 6px;border-radius:4px;font-weight:600;background:rgba(74,222,128,.15);color:var(--grn)">Active</span>'
			: '<span style="font-size:10px;padding:2px 6px;border-radius:4px;font-weight:600;background:rgba(107,112,137,.15);color:var(--dim)">Not configured</span>';
		$html .= '<div class="tab-content" id="tab-email">
<div class="grid">
<div class="card"><div class="l">Status</div><div class="v" style="font-size:14px">' . $relayBadge . '</div><div class="s">SMTP port ' . (int)$smtpPort . '</div></div>
<div class="card"><div class="l">Emails Sent</div><div class="v" id="em-sent" style="color:var(--cyn)">0</div><div class="s">total tracked</div></div>
<div class="card"><div class="l">Opened</div><div class="v" id="em-opened" style="color:var(--grn)">0</div><div class="s" id="em-open-rate">0% open rate</div></div>
<div class="card"><div class="l">Clicked</div><div class="v" id="em-clicked" style="color:var(--ac)">0</div><div class="s" id="em-click-rate">0% click rate</div></div>
<div class="card" id="relay-card" style="display:none"><div class="l">Relay Throughput</div><div class="v" id="relay-in" style="color:var(--cyn)">0</div><div class="s"><span id="relay-out">0</span> out &#183; <span id="relay-fail" style="color:var(--red)">0</span> failed</div></div>
</div>
<div class="panel"><div class="ph">Email Funnel &#8212; Sent &#8594; Opened &#8594; Clicked</div>
<div class="sankey-container" id="sankey-email"><div class="empty">Loading email flow data...</div></div></div>
</div>';

		// ── Mobile Tab ──
		$mobileBadge = $mobileEnabled
			? '<span style="font-size:10px;padding:2px 6px;border-radius:4px;font-weight:600;background:rgba(74,222,128,.15);color:var(--grn)">Active</span>'
			: '<span style="font-size:10px;padding:2px 6px;border-radius:4px;font-weight:600;background:rgba(107,112,137,.15);color:var(--dim)">Not configured</span>';
		$html .= '<div class="tab-content" id="tab-mobile">
<div class="grid">
<div class="card"><div class="l">Status</div><div class="v" style="font-size:14px">' . $mobileBadge . '</div><div class="s">Push notifications</div></div>
<div class="card"><div class="l">Push Sent</div><div class="v" id="mob-sent" style="color:var(--cyn)">0</div><div class="s">total delivered</div></div>
<div class="card"><div class="l">Opened</div><div class="v" id="mob-opened" style="color:var(--grn)">0</div><div class="s" id="mob-open-rate">0% open rate</div></div>
</div>
<div class="panel"><div class="ph">Push Notification Flow</div>
<div class="sankey-container" id="sankey-mobile"><div class="empty">' . ($mobileEnabled ? 'Loading push notification flow...' : 'Enable mobile push to see notification flow data') . '</div></div></div>
</div>';

		// ── JavaScript ──
		$html .= '<script>
var S=' . $stats . ',R=' . $recent . ',BASE=location.origin,
    L=document.getElementById("log"),SP=document.getElementById("spark"),
    LW=document.getElementById("log-wrap"),paused=false,MAX_LOG=300,
    sidFilter="",scFilter="",knownSids={},knownCodes={},currentTab="http";

var SC={"200":"OK","201":"Created","204":"No Content","206":"Partial",
"301":"Moved","302":"Found","304":"Not Modified","307":"Redirect","308":"Permanent",
"400":"Bad Request","401":"Unauthorized","403":"Forbidden","404":"Not Found",
"405":"Method Not Allowed","408":"Timeout","413":"Too Large","414":"URI Too Long",
"429":"Too Many Requests","500":"Internal Error","502":"Bad Gateway",
"503":"Unavailable","504":"Gateway Timeout"};

// ── Tab switching ──
function switchTab(tab){
currentTab=tab;
document.querySelectorAll(".tab-btn").forEach(function(b){b.classList.remove("active")});
document.querySelectorAll(".tab-content").forEach(function(c){c.classList.remove("active")});
document.getElementById("tab-btn-"+tab).classList.add("active");
document.getElementById("tab-"+tab).classList.add("active");
loadSankey(tab);
}

// ── Uptime ticker ──
var upSec=0;
function fmtUp(s){
if(s<60)return s+"s";
var d=Math.floor(s/86400),h=Math.floor((s%86400)/3600),m=Math.floor((s%3600)/60),ss=s%60;
if(d>0)return d+"d "+h+"h "+m+"m";
if(h>0)return h+"h "+m+"m "+ss+"s";
return m+"m "+ss+"s";
}
var wsLive=false;
function tickUp(){upSec++;el("sub","up "+fmtUp(upSec)+" · PHP "+(S.php||"")+" · "+(S.os||"")+" · <span class=\"ws\"><span class=\"wd"+(wsLive?" on":"")+"\" id=\"wd\"></span><span id=\"wl\">"+(wsLive?"live":"connecting")+"</span></span>")}

// ── Sparkline ticker ──
var spData=new Array(60).fill(0);
function tickSpark(){spData.push(0);if(spData.length>60)spData.shift();renderSpark()}
function renderSpark(){
var mx=Math.max.apply(null,spData)||1;
SP.innerHTML=spData.map(function(v){return\'<div style="height:\'+Math.max(1,v/mx*36)+\'px" title="\'+v+\' req/s"></div>\'}).join("");
}

// ── Main stats updater ──
function U(s){S=s;
el("sr",s.requests.toLocaleString());
el("crps",s.currentRps);
el("avg",s.avgMs+\'<span style="font-size:12px;font-weight:400">ms</span>\');
el("slow",s.slowest+"ms");
el("sm",s.memory+" MB");el("smp",s.memoryPeak+" MB");
el("sw",s.workers+(s.forkMode?\' <span style="font-size:10px;color:var(--yel)">(fork mode)</span>\':""));
// System RAM
if(s.systemRam){
  el("sysram",s.systemRam.percent+"%");
  el("sysram-detail",Math.round(s.systemRam.usedMb/1024*10)/10+" / "+Math.round(s.systemRam.totalMb/1024*10)/10+" GB");
  var re=document.getElementById("sysram");
  if(re)re.style.color=s.systemRam.percent>85?"var(--red)":(s.systemRam.percent>70?"var(--yel)":"var(--grn)");
}
// Worker COW stats
if(s.workerStats){
  var ws=s.workerStats;
  var totalKb=ws.totalRssKb;
  var avgKb=ws.count>0?Math.round(totalKb/ws.count):0;
  var fpmEquiv=ws.count*50;
  el("cow-total",fmtMem(totalKb*1024));
  var cowParts=[];
  cowParts.push(ws.idle+"/"+ws.count+" idle");
  if(avgKb>0)cowParts.push(fmtMem(avgKb*1024)+" avg/worker");
  cowParts.push("php-fpm equiv ≈"+fpmEquiv+"MB");
  el("cow-detail",cowParts.join(" · "));
  var ce=document.getElementById("cow-total");
  if(ce)ce.style.color="var(--grn)";
}else if(s.forkMode){
  el("cow-total","fork");
  el("cow-detail","each request forks a fresh process (~120KB COW)");
}
el("s2",s.status2xx);el("s3",s.status3xx);el("s4",s.status4xx);el("s5",s.status5xx);
el("bout",s.bytesFormatted);el("conn",s.connections);el("ka",s.keepAlive||0);
el("srps",(s.rps)+" avg req/s");
el("phpn",s.phpRequests+" PHP / "+s.staticRequests+" static");
el("reqc",s.requests.toLocaleString()+" total");
upSec=s.uptimeSec||0;
if(s.sparkline){spData=s.sparkline.slice();renderSpark()}
// Top paths
var pp=document.getElementById("paths");
if(s.topPaths&&s.topPaths.length){pp.innerHTML=s.topPaths.map(function(p){return\'<div class="tp"><span class="p">\'+esc(p.path)+
\'</span><span class="c">\'+p.count+\'</span><span class="a">\'+p.avgMs+\'ms</span></div>\'}).join("")}
// Rooms (both tabs)
var rm=document.getElementById("rooms"),rm2=document.getElementById("ws-room-list");
var roomHtml;
if(s.activeRooms&&s.activeRooms.length){roomHtml=s.activeRooms.map(function(r){
return\'<div class="room"><span class="n">\'+esc(r.name)+\'</span><span>\'+r.members+\' members</span></div>\'}).join("")}
else{roomHtml=\'<div style="color:var(--dim);padding:8px;font-size:12px">No active rooms</div>\'}
if(rm)rm.innerHTML=roomHtml;if(rm2)rm2.innerHTML=roomHtml;
// WebSocket tab stats
el("ws-conn",s.wsConnections);el("ws-rooms",s.wsRooms);el("ws-clients",s.wsClients);
var wsBadge=document.getElementById("ws-badge");if(wsBadge)wsBadge.textContent=s.wsConnections;
if(s.sessions&&s.sessions.length){updateSidDropdown(s.sessions)}
// Relay stats
var rc=document.getElementById("relay-card");
if(s.relay&&rc){
  rc.style.display="";
  var c=s.relay.counters||{};
  el("relay-in",(c.msg_in||0)+" in");
  el("relay-out",(c.msg_out||0));
  el("relay-fail",(c.msg_failed||0));
  if(s.relay.stale){rc.style.opacity="0.5";rc.title="Relay status stale (>120s)"}
  else{rc.style.opacity="1";rc.title=""}
}}

function el(id,v){var e=document.getElementById(id);if(e)e.innerHTML=v}
function esc(s){return s.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;")}
function fmtMem(b){
if(b<=0)return"—";
if(b<1024)return b+" B";
if(b<1048576)return(b/1024).toFixed(1)+" KB";
return(b/1048576).toFixed(1)+" MB";
}

var K={php:"\u{1F418}",html:"\u{1F310}",css:"\u{1F3A8}",js:"⚡",img:"\u{1F5BC}",
font:"\u{1F524}",json:"\u{1F4CB}",xml:"\u{1F4C4}",doc:"\u{1F4D1}",media:"\u{1F3AC}",file:"\u{1F4E6}"};

function shouldShow(e){
if(sidFilter&&(e.sid||"")!==sidFilter)return false;
if(scFilter&&String(e.status)!==scFilter)return false;
return true;
}

function A(e){
if(paused)return;
var vis=shouldShow(e);
var d=document.createElement("div");d.className="le";
if(!vis)d.style.display="none";
d.setAttribute("data-sid",e.sid||"");
d.setAttribute("data-sc",e.status);
d.innerHTML=mkRow(e);
L.insertBefore(d,L.firstChild);
while(L.children.length>MAX_LOG)L.removeChild(L.lastChild);
if(e.sid&&!knownSids[e.sid]){knownSids[e.sid]=1;addSidOption(e.sid)}
var sc=String(e.status);
if(!knownCodes[sc]){knownCodes[sc]=1;addScOption(sc)}
}

function mkRow(e){
var c=e.status<300?"s2":e.status<400?"s3":e.status<500?"s4":"s5";
var k=K[e.kind]||"\u{1F4C2}";
var uri=esc(e.uri);
if(e.method==="GET"){uri=\'<a href="\'+BASE+esc(e.uri)+\'" target="_blank">\'+uri+"</a>"}
return \'<span class="lk">\'+k+\'</span><span class="lt">\'+e.time+\'</span><span class="ls \'+c+\'">\'+e.status+
\'</span><span class="lm">\'+e.method+\'</span><span class="lu">\'+uri+
\'</span><span class="ld">\'+e.ms+\'ms</span><span class="lmem">\'+fmtMem(e.mem)+"</span>";
}

function togglePause(){
paused=!paused;
var btn=document.getElementById("btn-pause");
btn.innerHTML=paused?"&#9654;":"&#9646;&#9646;";
btn.classList.toggle("active",paused);
btn.title=paused?"Resume":"Pause";
}
function clearLog(){L.innerHTML=""}

function updateSidDropdown(sessions){
sessions.forEach(function(s){if(!knownSids[s.id]){knownSids[s.id]=1;addSidOption(s.id)}});
}
function addSidOption(sid){
var sel=document.getElementById("sid-filter");
var o=document.createElement("option");o.value=sid;o.textContent=sid;sel.appendChild(o);
}
function filterSession(){sidFilter=document.getElementById("sid-filter").value;refilterRows()}
function addScOption(sc){
var sel=document.getElementById("sc-filter");
var o=document.createElement("option");
o.value=sc;o.textContent=sc+(SC[sc]?" — "+SC[sc]:"");
var opts=sel.options;
for(var i=1;i<opts.length;i++){if(parseInt(opts[i].value)>parseInt(sc)){sel.insertBefore(o,opts[i]);return}}
sel.appendChild(o);
}
function filterStatus(){scFilter=document.getElementById("sc-filter").value;refilterRows()}
function refilterRows(){
var rows=L.children;
for(var i=0;i<rows.length;i++){
var r=rows[i],sid=r.getAttribute("data-sid")||"",sc=r.getAttribute("data-sc")||"";
var vis=(!sidFilter||sid===sidFilter)&&(!scFilter||sc===scFilter);
r.style.display=vis?"":"none";
}}

// ── Sankey diagram rendering ──
var COLORS=["#7c8aff","#4ade80","#22d3ee","#fbbf24","#a78bfa","#f87171","#fb923c","#34d399","#818cf8","#e879f9"];
function renderSankey(containerId,data){
var container=document.getElementById(containerId);
if(!container)return;
if(!data||!data.nodes||!data.nodes.length||!data.links||!data.links.length){
container.innerHTML=\'<div class="empty">No flow data available yet</div>\';return;
}
container.innerHTML="";
var w=container.clientWidth-32,h=Math.max(300,Math.min(data.nodes.length*28,500));
var svg=d3.select(container).append("svg").attr("width",w).attr("height",h);
var nodeMap={};
data.nodes.forEach(function(n,i){nodeMap[n.id]=i;n.index=i});
var links=[];
data.links.forEach(function(l){
var si=nodeMap[l.source],ti=nodeMap[l.target];
if(si!==undefined&&ti!==undefined&&si!==ti){links.push({source:si,target:ti,value:l.value})}
});
if(!links.length){container.innerHTML=\'<div class="empty">No flow connections found</div>\';return;}
var sankey=d3.sankey().nodeWidth(16).nodePadding(10)
.extent([[1,1],[w-1,h-6]])
.nodeSort(null);
var graph;
try{graph=sankey({nodes:data.nodes.map(function(n){return Object.assign({},n)}),links:links})}
catch(e){container.innerHTML=\'<div class="empty">Flow data insufficient</div>\';return;}
// Tooltip
var tip=document.createElement("div");tip.className="sankey-tooltip";tip.style.display="none";
container.appendChild(tip);
// Links
svg.append("g").selectAll(".sankey-link")
.data(graph.links).enter().append("path")
.attr("class","sankey-link")
.attr("d",d3.sankeyLinkHorizontal())
.attr("stroke",function(d){return COLORS[d.source.index%COLORS.length]})
.attr("stroke-width",function(d){return Math.max(1,d.width)})
.on("mouseover",function(ev,d){
tip.style.display="block";
tip.innerHTML=esc(d.source.name||d.source.label||d.source.id)+" → "+esc(d.target.name||d.target.label||d.target.id)+"<br><b>"+d.value+"</b> transitions";
}).on("mousemove",function(ev){
var r=container.getBoundingClientRect();
tip.style.left=(ev.clientX-r.left+12)+"px";
tip.style.top=(ev.clientY-r.top-10)+"px";
}).on("mouseout",function(){tip.style.display="none"});
// Nodes
var node=svg.append("g").selectAll(".sankey-node")
.data(graph.nodes).enter().append("g").attr("class","sankey-node");
node.append("rect")
.attr("x",function(d){return d.x0}).attr("y",function(d){return d.y0})
.attr("height",function(d){return Math.max(1,d.y1-d.y0)}).attr("width",sankey.nodeWidth())
.attr("fill",function(d){return COLORS[d.index%COLORS.length]}).attr("rx",2);
node.append("text")
.attr("x",function(d){return d.x0<w/2?d.x1+6:d.x0-6})
.attr("y",function(d){return(d.y0+d.y1)/2})
.attr("dy","0.35em")
.attr("text-anchor",function(d){return d.x0<w/2?"start":"end"})
.text(function(d){var label=d.name||d.label||d.id;return label.length>30?label.substr(0,28)+"..":label})
.style("font-size","11px");
}

// ── Load Sankey data from API ──
var sankeyCache={},sankeyLoading={};
function loadSankey(tab){
if(sankeyLoading[tab])return;
var url,containerId;
var today=new Date().toISOString().slice(0,10);
if(tab==="http"){url="/Q/panel/api/client-metrics/"+today+"/flow?type=page";containerId="sankey-http"}
else if(tab==="ws"){url="/Q/panel/api/client-metrics/"+today+"/flow?type=section";containerId="sankey-ws"}
else if(tab==="email"){url="/Q/panel/api/email-tracking/flow";containerId="sankey-email"}
else if(tab==="mobile"){containerId="sankey-mobile";
document.getElementById(containerId).innerHTML=\'<div class="empty">\'+(' . json_encode($mobileEnabled ? 'Loading...' : 'Enable mobile push to see notification flow data') . ')+\'</div>\';return}
if(!url)return;
sankeyLoading[tab]=true;
var sep=url.indexOf("?")>=0?"&":"?";
fetch(url+sep+"_t="+Date.now()' . ($wsToken ? '+"&token=' . addslashes($wsToken) . '"' : '') . ',{credentials:"same-origin"})
.then(function(r){return r.json()})
.then(function(d){sankeyCache[tab]=d;renderSankey(containerId,d);sankeyLoading[tab]=false})
.catch(function(){
document.getElementById(containerId).innerHTML=\'<div class="empty">Could not load flow data</div>\';
sankeyLoading[tab]=false});
}

// Load email stats
function loadEmailStats(){
fetch("/Q/panel/api/email-tracking/summary?_t="+Date.now()' . ($wsToken ? '+"&token=' . addslashes($wsToken) . '"' : '') . ',{credentials:"same-origin"})
.then(function(r){return r.json()})
.then(function(d){
el("em-sent",d.total||0);el("em-opened",d.opened||0);el("em-clicked",d.clicked||0);
el("em-open-rate",(d.openRate||0)+"%  open rate");el("em-click-rate",(d.clickRate||0)+"% click rate");
}).catch(function(){});
}

U(S);R.forEach(A);
setInterval(function(){tickUp();tickSpark()},1000);
tickUp();

// Initial Sankey loads
loadSankey("http");
loadEmailStats();
// Refresh Sankeys every 30 seconds
setInterval(function(){loadSankey(currentTab);if(currentTab==="email")loadEmailStats()},30000);

// WebSocket connection
var ws;function C(){var wsp=(location.protocol==="https:"?"wss:":"ws:");var wsUrl=wsp+"//"+location.host+"/Q/ws"' . ($jsTokenParam ? '+"?token=' . $jsTokenParam . '"' : '') . ';ws=new WebSocket(wsUrl);
ws.onopen=function(){wsLive=true;tickUp()};
ws.onmessage=function(e){var m=JSON.parse(e.data);if(m.type==="request"){A(m.entry);U(m.stats)}else if(m.type==="heartbeat"){U(m.stats)}};
ws.onclose=function(){wsLive=false;tickUp();setTimeout(C,2000)}}
C();
</script></body></html>';

		return $html;
	}
}
