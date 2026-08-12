<?php
/**
 * @module Q
 */
/**
 * Server dashboard: comprehensive stats, live HTML display at /Q/dashboard,
 * real-time updates via Q_WebSocket on the 'dashboard' channel.
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

		// Push stats every 2 seconds so the dashboard stays live
		// even when no requests are coming in
		Q_Evented::repeat(2.0, function () {
			if (empty(Q_WebSocket::$channels['dashboard'])) return;
			Q_WebSocket::broadcastTo('dashboard', array(
				'type' => 'heartbeat', 'stats' => Q_WebServer_Dashboard::getStats()
			));
		});
	}

	static function recordRequest($method, $uri, $status, $ms, $bytes = 0, $isPhp = false, $contentType = '')
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
		$entry = array('time' => date('H:i:s'), 'method' => $method,
			'uri' => $uri, 'status' => $status, 'ms' => $ms, 'kind' => $kind);
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
		);
	}

	static function handle($client, $parsed)
	{
		$p = $parsed['path'];
		if ($p === '/Q/dashboard' || $p === '/Q/dashboard/') {
			Q_WebServer::sendResponse($client, 200, self::renderHtml($parsed), 'text/html; charset=utf-8');
			return true;
		}
		if ($p === '/Q/stats') {
			Q_WebServer::sendResponse($client, 200, json_encode(self::getStats()), 'application/json');
			return true;
		}
		return false;
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
		$recent = json_encode(array_slice(self::$recentRequests, -50));
		$host = $parsed['headers']['host'] ?? 'localhost';

		// Get auth token for WebSocket connection
		$wsToken = '';
		// Try panel session cookie first
		$cookie = $parsed['cookies']['Q_panel_token'] ?? '';
		if ($cookie && Q_WebServer_Panel::validateToken($cookie)) {
			$wsToken = $cookie;
		}
		// Try dashboard query token
		if (!$wsToken) {
			$qp = array();
			if (!empty($parsed['query'])) parse_str($parsed['query'], $qp);
			$wsToken = $qp['token'] ?? '';
		}
		// Try static dashboard token from config
		if (!$wsToken) {
			$wsToken = Q_Config::get('Q', 'dashboard', 'token', '');
		}

		$tokenParam = $wsToken ? "?token=$wsToken" : '';
		$wsUrl = "ws://$host/Q/ws$tokenParam";
		return <<<HTML
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark">
<title>Qbix Server Dashboard</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0f1117;--sfc:#1a1d27;--sfc2:#222533;--bdr:#2a2d3a;--txt:#e1e4ed;--dim:#6b7089;
--ac:#7c8aff;--grn:#4ade80;--yel:#fbbf24;--red:#f87171;--cyn:#22d3ee;--pur:#a78bfa}
@media(prefers-color-scheme:light){:root{--bg:#f4f5f7;--sfc:#fff;--sfc2:#f0f1f3;--bdr:rgba(0,0,0,.1);--txt:#1a1a2e;--dim:#6b7089}}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
background:var(--bg);color:var(--txt);padding:24px;font-size:13px;max-width:1200px;margin:0 auto}
h1{font-size:20px;font-weight:600;margin-bottom:4px;color:var(--ac);display:flex;align-items:center;gap:10px}
h1 .dot{width:8px;height:8px;border-radius:50%;background:var(--grn);animation:pulse 2s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.sub{font-size:12px;color:var(--dim);margin-bottom:20px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:20px}
.card{background:var(--sfc);border:1px solid var(--bdr);border-radius:8px;padding:14px}
.card .l{font-size:10px;color:var(--dim);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px}
.card .v{font-size:22px;font-weight:700;line-height:1.2}
.card .s{font-size:11px;color:var(--dim);margin-top:4px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}
@media(max-width:700px){.row{grid-template-columns:1fr}}
.panel{background:var(--sfc);border:1px solid var(--bdr);border-radius:8px;overflow:hidden}
.ph{padding:10px 14px;border-bottom:1px solid var(--bdr);font-weight:600;font-size:12px;
display:flex;justify-content:space-between;align-items:center}
.pb{padding:8px 14px;max-height:260px;overflow-y:auto}
.spark{height:40px;display:flex;align-items:flex-end;gap:1px;margin:8px 14px}
.spark div{flex:1;background:var(--ac);border-radius:1px 1px 0 0;min-height:1px;opacity:.7;transition:height .3s}
.le{padding:3px 14px;font-size:12px;display:flex;gap:10px;border-bottom:1px solid rgba(255,255,255,.03);font-family:'SF Mono','Fira Code',Consolas,monospace}
.le:hover{background:rgba(255,255,255,.03)}
.lk{min-width:18px;text-align:center;font-size:12px}
.lt{color:var(--dim);min-width:58px}.ls{min-width:28px;font-weight:700;text-align:right}
.lm{min-width:42px;color:var(--cyn)}.lu{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ld{color:var(--dim);min-width:54px;text-align:right}
.s2{color:var(--grn)}.s3{color:var(--yel)}.s4,.s5{color:var(--red)}
.tp{display:flex;justify-content:space-between;padding:4px 0;font-size:12px;border-bottom:1px solid rgba(255,255,255,.03)}
.tp .p{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:'SF Mono',monospace}
.tp .c{min-width:50px;text-align:right;color:var(--ac)}.tp .a{min-width:50px;text-align:right;color:var(--dim)}
.bar{height:4px;border-radius:2px;margin-top:3px}
.ws{display:inline-flex;align-items:center;gap:6px;font-size:11px}
.wd{width:6px;height:6px;border-radius:50%;background:var(--red)}.wd.on{background:var(--grn)}
.pill{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600}
.pill.g{background:rgba(74,222,128,.15);color:var(--grn)}
.pill.y{background:rgba(251,191,36,.15);color:var(--yel)}
.pill.r{background:rgba(248,113,113,.15);color:var(--red)}
.pill.b{background:rgba(124,138,255,.15);color:var(--ac)}
.room{display:flex;justify-content:space-between;padding:4px 0;font-size:12px}
.room .n{font-family:'SF Mono',monospace;color:var(--pur)}
</style></head><body>
<h1><span class="dot"></span>Qbix Server <span style="font-size:12px;color:var(--dim);font-weight:400" id="ver"></span></h1>
<div class="sub"><span id="up"></span> · PHP <span id="phpv"></span> · <span id="os"></span> · <span class="ws"><span class="wd" id="wd"></span><span id="wl">connecting</span></span></div>

<div class="grid">
<div class="card"><div class="l">Total requests</div><div class="v" id="sr">0</div><div class="s" id="srps">0 avg req/s</div></div>
<div class="card"><div class="l">Current RPS</div><div class="v" id="crps" style="color:var(--cyn)">0</div><div class="s">last 5 sec</div></div>
<div class="card"><div class="l">Avg response</div><div class="v" id="avg">0<span style="font-size:12px;font-weight:400">ms</span></div><div class="s">slowest: <span id="slow">0ms</span></div></div>
<div class="card"><div class="l">Memory</div><div class="v" id="sm">—</div><div class="s">peak <span id="smp">—</span></div></div>
<div class="card"><div class="l">Workers</div><div class="v" id="sw">—</div><div class="s" id="phpn">0 PHP / 0 static</div></div>
<div class="card"><div class="l">WebSocket</div><div class="v" id="wsc" style="color:var(--pur)">0</div><div class="s"><span id="wsr">0</span> rooms</div></div>
<div class="card"><div class="l">Data out</div><div class="v" id="bout">0</div><div class="s"><span id="conn">0</span> connections · <span id="ka">0</span> keep-alive</div></div>
<div class="card"><div class="l">Status codes</div><div class="v" style="font-size:12px;line-height:1.8">
<span class="s2" id="s2">0</span> ok · <span class="s3" id="s3">0</span> redir · <span class="s4" id="s4">0</span> 4xx · <span class="s5" id="s5">0</span> 5xx</div></div>
</div>

<div class="panel" style="margin-bottom:16px"><div class="ph">Throughput <span style="font-size:11px;color:var(--dim)">last 60s</span></div>
<div class="spark" id="spark"></div></div>

<div class="row">
<div class="panel"><div class="ph">Top paths</div><div class="pb" id="paths"></div></div>
<div class="panel"><div class="ph">Active rooms</div><div class="pb" id="rooms"><div style="color:var(--dim);padding:8px;font-size:12px">No active rooms</div></div></div>
</div>

<div class="panel"><div class="ph">Live requests <span style="font-size:11px;color:var(--dim)" id="reqc">0 total</span></div>
<div class="pb" style="max-height:50vh" id="log"></div></div>

<script>
var S=$stats,R=$recent,L=document.getElementById('log'),SP=document.getElementById('spark');
function U(s){S=s;
el('sr',s.requests.toLocaleString());
el('crps',s.currentRps);
el('avg',s.avgMs+'<span style="font-size:12px;font-weight:400">ms</span>');
el('slow',s.slowest+'ms');
el('sm',s.memory+' MB');el('smp',s.memoryPeak+' MB');
el('sw',s.workers);el('wsc',s.wsConnections);el('wsr',s.wsRooms);
el('s2',s.status2xx);el('s3',s.status3xx);el('s4',s.status4xx);el('s5',s.status5xx);
el('up','up '+s.uptime);el('phpv',s.php);el('os',s.os);
el('bout',s.bytesFormatted);el('conn',s.connections);el('ka',s.keepAlive||0);
el('srps',(s.rps)+' avg req/s');
el('phpn',s.phpRequests+' PHP / '+s.staticRequests+' static');
el('reqc',s.requests.toLocaleString()+' total');
// Sparkline
if(s.sparkline){var mx=Math.max.apply(null,s.sparkline)||1;
SP.innerHTML=s.sparkline.map(function(v){return'<div style="height:'+Math.max(1,v/mx*36)+'px" title="'+v+' req/s"></div>'}).join('')}
// Top paths
var pp=document.getElementById('paths');
if(s.topPaths&&s.topPaths.length){var mx2=s.topPaths[0].count;
pp.innerHTML=s.topPaths.map(function(p){return'<div class="tp"><span class="p">'+esc(p.path)+
'</span><span class="c">'+p.count+'</span><span class="a">'+p.avgMs+'ms</span></div>'}).join('')}
// Rooms
var rm=document.getElementById('rooms');
if(s.activeRooms&&s.activeRooms.length){rm.innerHTML=s.activeRooms.map(function(r){
return'<div class="room"><span class="n">'+esc(r.name)+'</span><span>'+r.members+' members</span></div>'}).join('')}
else{rm.innerHTML='<div style="color:var(--dim);padding:8px;font-size:12px">No active rooms</div>'}}
function el(id,v){var e=document.getElementById(id);if(e)e.innerHTML=v}
function esc(s){return s.replace(/</g,'&lt;').replace(/>/g,'&gt;')}
var K={php:'\u{1F418}',html:'\u{1F310}',css:'\u{1F3A8}',js:'\u26A1',img:'\u{1F5BC}',font:'\u{1F524}',json:'\u{1F4CB}',xml:'\u{1F4C4}',doc:'\u{1F4D1}',media:'\u{1F3AC}',file:'\u{1F4E6}'};
function A(e){var d=document.createElement('div');d.className='le';
var c=e.status<300?'s2':e.status<400?'s3':e.status<500?'s4':'s5';
var k=K[e.kind]||'\u{1F4C2}';
d.innerHTML='<span class="lk">'+k+'</span><span class="lt">'+e.time+'</span><span class="ls '+c+'">'+e.status+
'</span><span class="lm">'+e.method+'</span><span class="lu">'+
esc(e.uri)+'</span><span class="ld">'+e.ms+'ms</span>';
L.appendChild(d);if(L.children.length>200)L.removeChild(L.firstChild);L.scrollTop=L.scrollHeight}
U(S);R.forEach(A);
var ws;function C(){ws=new WebSocket('$wsUrl');
ws.onopen=function(){document.getElementById('wd').className='wd on';el('wl','live')};
ws.onmessage=function(e){var m=JSON.parse(e.data);if(m.type==='request'){A(m.entry);U(m.stats)}else if(m.type==='heartbeat'){U(m.stats)}};
ws.onclose=function(){document.getElementById('wd').className='wd';el('wl','reconnecting');setTimeout(C,2000)}}
C();
</script></body></html>
HTML;
	}
}
