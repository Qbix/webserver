<?php
/**
 * @module Q
 */
/**
 * Server dashboard: stats tracking, live HTML display at /Q/dashboard,
 * real-time updates via Q_WebSocket on the 'dashboard' channel.
 * @class Q_WebServer_Dashboard
 */
class Q_WebServer_Dashboard
{
	static $stats = array(
		'startTime' => 0, 'requests' => 0,
		'status2xx' => 0, 'status3xx' => 0, 'status4xx' => 0, 'status5xx' => 0,
	);
	static $recentRequests = array();

	static function init() { self::$stats['startTime'] = time(); }

	static function recordRequest($method, $uri, $status, $ms)
	{
		self::$stats['requests']++;
		if ($status < 300) self::$stats['status2xx']++;
		elseif ($status < 400) self::$stats['status3xx']++;
		elseif ($status < 500) self::$stats['status4xx']++;
		else self::$stats['status5xx']++;

		$entry = array('time' => date('H:i:s'), 'method' => $method,
			'uri' => $uri, 'status' => $status, 'ms' => $ms);
		self::$recentRequests[] = $entry;
		if (count(self::$recentRequests) > 200) array_shift(self::$recentRequests);

		Q_WebSocket::broadcastTo('dashboard', array(
			'type' => 'request', 'entry' => $entry, 'stats' => self::getStats()
		));
	}

	static function getStats()
	{
		$up = time() - self::$stats['startTime'];
		$pool = Q_WebServer::$pool;
		return array(
			'uptime' => self::fmtUp($up), 'uptimeSec' => $up,
			'requests' => self::$stats['requests'],
			'status2xx' => self::$stats['status2xx'],
			'status3xx' => self::$stats['status3xx'],
			'status4xx' => self::$stats['status4xx'],
			'status5xx' => self::$stats['status5xx'],
			'memory' => round(memory_get_usage(true)/1048576, 1),
			'memoryPeak' => round(memory_get_peak_usage(true)/1048576, 1),
			'workers' => $pool ? $pool->idleCount().'/'.$pool->targetSize : 'in-process',
			'wsClients' => Q_WebSocket::clientCount(),
			'cache' => Q_WebServer_Cache::stats(),
			'components' => Q_WebServer_Cache_Components::enabled()
				? Q_WebServer_Cache_Components::stats() : null,
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
		if ($s < 3600) return floor($s/60).'m '.($s%60).'s';
		return floor($s/3600).'h '.floor(($s%3600)/60).'m';
	}

	static function renderHtml($parsed)
	{
		$stats = json_encode(self::getStats());
		$recent = json_encode(array_slice(self::$recentRequests, -50));
		$host = $parsed['headers']['host'] ?? 'localhost:8080';
		$wsUrl = "ws://$host/Q/ws";
		return <<<HTML
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Qbix Server</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0f1117;--sfc:#1a1d27;--bdr:#2a2d3a;--txt:#e1e4ed;--dim:#6b7089;
--ac:#7c8aff;--grn:#4ade80;--yel:#fbbf24;--red:#f87171;--cyn:#22d3ee}
body{font-family:'SF Mono','Fira Code',Consolas,monospace;background:var(--bg);
color:var(--txt);padding:24px;font-size:13px}
h1{font-size:18px;font-weight:600;margin-bottom:24px;color:var(--ac)}
h1 span{color:var(--dim);font-weight:400;font-size:13px;margin-left:12px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:24px}
.card{background:var(--sfc);border:1px solid var(--bdr);border-radius:8px;padding:16px}
.card .l{font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.card .v{font-size:24px;font-weight:700}
.card .s{font-size:11px;color:var(--dim);margin-top:4px}
.lc{background:var(--sfc);border:1px solid var(--bdr);border-radius:8px;overflow:hidden}
.lh{padding:12px 16px;border-bottom:1px solid var(--bdr);display:flex;justify-content:space-between;align-items:center}
.lh h2{font-size:13px;font-weight:600}
.lb{height:50vh;overflow-y:auto;padding:4px 0}
.le{padding:3px 16px;font-size:12px;display:flex;gap:12px;border-bottom:1px solid rgba(255,255,255,.03)}
.le:hover{background:rgba(255,255,255,.02)}
.lt{color:var(--dim);min-width:64px}.ls{min-width:28px;font-weight:700;text-align:right}
.lm{min-width:48px;color:var(--cyn)}.lu{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ld{color:var(--dim);min-width:60px;text-align:right}
.s2{color:var(--grn)}.s3{color:var(--yel)}.s4,.s5{color:var(--red)}
.ws{display:inline-flex;align-items:center;gap:6px;font-size:11px}
.wd{width:6px;height:6px;border-radius:50%;background:var(--red)}.wd.on{background:var(--grn)}
@media(max-width:600px){body{padding:12px}.grid{grid-template-columns:repeat(2,1fr)}.lb{height:60vh}}
</style></head><body>
<h1>Qbix Server <span id="up"></span></h1>
<div class="grid">
<div class="card"><div class="l">Requests</div><div class="v" id="sr">0</div><div class="s" id="srps"></div></div>
<div class="card"><div class="l">Workers</div><div class="v" id="sw">—</div></div>
<div class="card"><div class="l">Memory</div><div class="v" id="sm">—</div><div class="s" id="smp"></div></div>
<div class="card"><div class="l">Status</div><div class="v" style="font-size:13px;line-height:1.8">
<span class="s2" id="s2">0</span> ok <span class="s3" id="s3">0</span> redir <span class="s4" id="s4">0</span> err</div></div>
</div>
<div class="lc"><div class="lh"><h2>Live Requests</h2>
<div class="ws"><span class="wd" id="wd"></span><span id="wl">connecting</span></div></div>
<div class="lb" id="log"></div></div>
<script>
var S=$stats,R=$recent,L=document.getElementById('log');
function U(s){S=s;document.getElementById('sr').textContent=s.requests.toLocaleString();
document.getElementById('sw').textContent=s.workers;
document.getElementById('sm').textContent=s.memory+' MB';
document.getElementById('smp').textContent='peak '+s.memoryPeak+' MB';
document.getElementById('s2').textContent=s.status2xx;
document.getElementById('s3').textContent=s.status3xx;
document.getElementById('s4').textContent=s.status4xx;
document.getElementById('up').textContent=s.uptime;
document.getElementById('srps').textContent=(s.uptimeSec>0?(s.requests/s.uptimeSec).toFixed(1):'0')+' req/s'}
function A(e){var d=document.createElement('div');d.className='le';
var c=e.status<300?'s2':e.status<400?'s3':e.status<500?'s4':'s5';
d.innerHTML='<span class="lt">'+e.time+'</span><span class="ls '+c+'">'+e.status+
'</span><span class="lm">'+e.method+'</span><span class="lu">'+
e.uri.replace(/</g,'&lt;')+'</span><span class="ld">'+e.ms+'ms</span>';
L.appendChild(d);if(L.children.length>200)L.removeChild(L.firstChild);L.scrollTop=L.scrollHeight}
U(S);R.forEach(A);
var ws;function C(){ws=new WebSocket('$wsUrl');
ws.onopen=function(){document.getElementById('wd').className='wd on';document.getElementById('wl').textContent='live'};
ws.onmessage=function(e){var m=JSON.parse(e.data);if(m.type==='request'){A(m.entry);U(m.stats)}};
ws.onclose=function(){document.getElementById('wd').className='wd';
document.getElementById('wl').textContent='reconnecting';setTimeout(C,2000)}}C();
</script></body></html>
HTML;
	}
}
