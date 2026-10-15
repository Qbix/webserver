<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>Qbix Server</title>
<link rel="stylesheet" href="/Q/prism.css">
<link rel="api-catalog" href="/.well-known/api-catalog">
<link rel="alternate" type="application/ai-catalog+json" href="/.well-known/ai-catalog.json">
<style>
:root{--bg:#f8f9fa;--fg:#333;--hero-bg:linear-gradient(135deg,#1a1a2e,#16213e,#0f3460);--card-bg:#fff;--card-sh:0 1px 3px rgba(0,0,0,.08);--card-h:#1a1a2e;--card-p:#777;--h2:#1a1a2e;--h3:#555;--p:#666;--code-bg:#eee;--code-fg:#333;--tag-bg:#e8f4ff;--tag-fg:#2a7fff;--tree-fg:#555;--tree-b:#1a1a2e;--note-bg:#fff8e1;--note-bd:#ffc107;--foot-fg:#bbb;--foot-bd:#eee;--pre-bg:#1a1a2e}
@media(prefers-color-scheme:dark){:root{--bg:#111520;--fg:#d0d4dc;--card-bg:#1a1f2e;--card-sh:0 1px 4px rgba(0,0,0,.4);--card-h:#e0e4ec;--card-p:#8a9ab4;--h2:#e0e4ec;--h3:#8a9ab4;--p:#8a9ab4;--code-bg:#1a1f2e;--code-fg:#c0c8d8;--tag-bg:#1a2e4c;--tag-fg:#5aadff;--tree-fg:#8a9ab4;--tree-b:#e0e4ec;--note-bg:#1a2a1a;--note-bd:#4caf50;--foot-fg:#444;--foot-bd:#1a1f2e;--pre-bg:#0d1117}}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--fg);line-height:1.6}
.hero{background:var(--hero-bg);color:#fff;padding:48px 20px;text-align:center}
.hero h1{font-size:32px;font-weight:700;margin-bottom:6px}
.hero p{color:#a0b4c8;font-size:15px}
.hero .v{color:#4a9eff;font-size:12px;margin-top:6px}
.w{max-width:760px;margin:0 auto;padding:24px 16px}
h2{font-size:18px;margin:28px 0 10px;color:var(--h2)}
h3{font-size:14px;margin:18px 0 6px;color:var(--h3)}
p{margin:6px 0;font-size:13px;color:var(--p)}
.note{margin:12px 0;font-size:13px;color:var(--p);background:var(--note-bg);border-left:3px solid var(--note-bd);padding:8px 12px;border-radius:0 4px 4px 0}
pre{border-radius:6px;overflow-x:auto;margin:8px 0;-webkit-overflow-scrolling:touch}
pre[class*="language-"]{background:var(--pre-bg);padding:14px;font-size:12px}
code{font-family:"SF Mono",Monaco,Consolas,monospace;font-size:12px}
code[class*="language-"]{font-size:12px}
p code{background:var(--code-bg);padding:1px 5px;border-radius:3px;color:var(--code-fg)}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:16px 0}
.card{background:var(--card-bg);border-radius:8px;padding:16px;box-shadow:var(--card-sh)}
.card h3{margin:0 0 6px;color:var(--card-h);font-size:15px}
.card p{font-size:12px;margin:0;color:var(--card-p)}
.tag{display:inline-block;background:var(--tag-bg);color:var(--tag-fg);padding:2px 7px;border-radius:4px;font-size:10px;font-weight:600;margin-top:8px}
.tree{font-size:12px;line-height:1.7;color:var(--tree-fg);overflow-x:auto}
.tree b{color:var(--tree-b)}
.cmt{color:#888}
.links{margin:20px 0 0;display:flex;flex-wrap:wrap;justify-content:center;gap:8px}
.links a{color:#fff;background:rgba(255,255,255,.15);text-decoration:none;padding:6px 14px;border-radius:6px;font-size:13px;white-space:nowrap;backdrop-filter:blur(4px);transition:background .2s}
.links a:hover{background:rgba(255,255,255,.28)}
.foot{text-align:center;color:var(--foot-fg);font-size:11px;padding:24px;border-top:1px solid var(--foot-bd);margin-top:24px}
.here{background:var(--card-bg);border-radius:8px;padding:16px;box-shadow:var(--card-sh);border-left:3px solid var(--note-bd);margin:20px 0 0}
.here h2{margin:0 0 6px;font-size:17px}
.here ol{margin:8px 0 0 20px;font-size:13px;color:var(--p)}
.here li{margin:3px 0}
.here .go{display:inline-block;margin-top:8px;font-size:13px}
@media(max-width:500px){
  .hero{padding:32px 16px}
  .hero h1{font-size:24px}
  pre{font-size:11px;padding:10px}
  .cards{grid-template-columns:1fr}
}
</style>
</head>
<body>

<div class="hero">
  <img src="/Q/logo.png" alt="Qbix" width="56" height="49" style="margin-bottom:10px">
  <h1>Qbix Server</h1>
  <p>Your server is running. Now build something.</p>
  <div class="v">PHP <?= PHP_VERSION ?> · PID <?= getmypid() ?></div>
  <div class="links">
    <a href="/Q/dashboard">📊 Dashboard</a>
    <a href="/Q/health">💚 Health</a>
    <a href="/Q/panel">⚙️ Panel</a>
    <a href="https://github.com/Qbix/webserver">📖 GitHub</a>
  </div>
</div>

<?php
$W = isset(Q_WebServer::$welcome) ? Q_WebServer::$welcome : array();
$wReason = $W['reason'] ?? 'default';
$wHost = htmlspecialchars($W['host'] ?? '', ENT_QUOTES, 'UTF-8');
$wBranch = $W['branch'] ?? null;
$wMain = $wBranch ? htmlspecialchars($wBranch['main'] ?? '', ENT_QUOTES, 'UTF-8') : '';
$wName = $wBranch ? htmlspecialchars($wBranch['name'], ENT_QUOTES, 'UTF-8') : '';
?>
<?php if ($wReason === 'default' && !$wBranch): ?>
<div class="w" style="padding-bottom:0"><div class="here">
  <h2>Set up your first app</h2>
  <p>The server is running and the control panel is ready. To serve a real site, create an app and point a domain at it.</p>
  <ol>
    <li>Open the <a href="/Q/panel#apps">Control Panel → Apps</a> and create your first app. It will live in the <code>apps/</code> folder beside <code>qbixserver.php</code>.</li>
    <li>Point a domain at this server in the <a href="/Q/panel#domains">Domains</a> tab, or just keep browsing <code>localhost</code>.</li>
    <li>Put your files in the app's <code>web/</code> folder — <code>index.html</code> replaces this page.</li>
  </ol>
  <a class="go" href="/Q/panel#apps">Open control panel &rarr;</a>
</div></div>
<?php endif ?>
<?php if ($wBranch || $wReason !== 'default'): ?>
<div class="w" style="padding-bottom:0"><div class="here">
<?php if ($wBranch): ?>
  <h2>This is a branch<?= $wName ? ': <code>' . $wName . '</code>' : '' ?></h2>
  <p>A branch is an isolated copy of a site, used to try changes without touching the live one.<?php if ($wMain): ?> The live site is <a href="https://<?= $wMain ?>/"><?= $wMain ?></a>.<?php endif ?><?php if ($wReason === 'empty'): ?> This branch has no home page yet.<?php endif ?></p>
  <ol>
    <li>Change the branch's files from the <a href="/Q/panel#branches">Control Panel</a><?= $wReason === 'empty' ? ', starting with an <code>index.html</code>' : '' ?>.</li>
    <li>Look at the result on this address.</li>
    <li>When it is right, request a merge into <?= $wMain ? '<a href="https://' . $wMain . '/">' . $wMain . '</a>' : 'the main site' ?> from the Branches tab.</li>
  </ol>
  <?php if ($wMain): ?><a class="go" href="https://<?= $wMain ?>/">Go to the main site &rarr;</a><?php endif ?>
<?php elseif ($wReason === 'empty'): ?>
  <h2>This site has no home page yet</h2>
  <p><?= $wHost ?> is set up with an app, but the app has no <code>index.html</code> or <code>index.php</code>.</p>
  <ol>
    <li>Add an <code>index.html</code> to the app's web folder, or ask an assistant to write the first page (see below).</li>
    <li>Or choose a different app for this domain in the <a href="/Q/panel#domains">Control Panel</a>.</li>
  </ol>
<?php else: ?>
  <h2>No app is set up for <?= $wHost ?> yet</h2>
  <p>The server answers for this domain, but nothing has been assigned to it.</p>
  <ol>
    <li>Open the <a href="/Q/panel#domains">Control Panel</a>, go to Domains, and choose an app for <?= $wHost ?>, or add an app first under Apps.</li>
    <li>Or ask an assistant to set it up (see below).</li>
  </ol>
<?php endif ?>
</div></div>
<?php endif ?>

<div class="w">

<h2>Execution models</h2>

<div class="cards">
  <div class="card">
    <h3>🌐 HTTP</h3>
    <p>Drop files in <code>web/</code>. Static files served from an in-memory cache, PHP scripts forked and executed. Add <code>--workers=N</code> for persistent workers with 100× more concurrent capacity.</p>
    <span class="tag">fork · octane mode</span>
  </div>
  <div class="card">
    <h3>🔌 WebSocket</h3>
    <p>One process per connection. User isolation, auth, private state. Socket.IO compatible.</p>
    <span class="tag">process per connection</span>
  </div>
  <div class="card">
    <h3>🏠 Rooms</h3>
    <p>One process per room. Shared state for chat, games, collaboration. No Redis needed.</p>
    <span class="tag">process per room</span>
  </div>
</div>

<h2>Project structure</h2>

<pre class="tree"><b>your-project/</b>
├── qbixserver.php
├── config/
│   └── server.json        <span class="cmt">← optional config</span>
├── <b>web/</b>                    <span class="cmt">← document root</span>
│   ├── index.html          <span class="cmt">← replaces this page</span>
│   └── api/hello.php       <span class="cmt">← HTTP endpoint</span>
├── <b>handlers/</b>               <span class="cmt">← WebSocket + room handlers</span>
│   ├── chat/
│   │   ├── join.php        <span class="cmt">← per-connection</span>
│   │   └── room/
│   │       ├── join.php    <span class="cmt">← room lifecycle</span>
│   │       ├── message.php <span class="cmt">← room event</span>
│   │       └── leave.php
│   └── connect.php         <span class="cmt">← on WebSocket connect</span>
├── <b>classes/</b>                <span class="cmt">← autoloaded</span>
│   └── ChatRoom.php        <span class="cmt">← shared room state</span>
└── <b>errors/</b>                 <span class="cmt">← custom error pages (optional)</span>
    └── 404.php</pre>

<h2>Examples</h2>

<h3>1. HTTP endpoint</h3>
<p>Any file in <code>web/</code> is served. PHP files are executed in a forked process.</p>
<pre class="language-php"><code class="language-php">&lt;?php
// web/api/hello.php — visit /api/hello.php?name=Alice
Q_WebServer_State::header('Content-Type: application/json');
echo json_encode([
    'message' =&gt; 'Hello, ' . ($_GET['name'] ?? 'world')
]);</code></pre>

<h3>2. WebSocket handler</h3>
<p>Each connection gets its own process. Configure in <code>config/server.json</code>:</p>
<pre class="language-json"><code class="language-json">{
  "Q": {
    "webserver": {
      "sockets": {
        "events": {
          "_connect": "connect",
          "chat/join": "chat/join"
        },
        "rooms": {
          "chat/$room": {"handler": "chat/room"}
        }
      }
    }
  }
}</code></pre>
<pre class="language-php"><code class="language-php">&lt;?php
// handlers/chat/join.php
function chat_join(&amp;$params, &amp;$result) {
    extract($params); // $socket, $event, $data
    $socket-&gt;join('chat/' . $data['room'], [
        'name' =&gt; $data['name']
    ]);
    $result = ['joined' =&gt; $data['room']];
}</code></pre>

<h3>3. Rooms — shared state</h3>
<p>All members share one process. State lives in class statics.</p>
<pre class="language-php"><code class="language-php">&lt;?php
// handlers/chat/room/message.php
function chat_room_message(&amp;$params, &amp;$result) {
    extract($params); // $room, $event, $data
    $name = ChatRoom::$names[$room-&gt;socketId] ?? 'anon';
    $room-&gt;broadcast([
        'event' =&gt; 'chat/message',
        'data'  =&gt; ['name' =&gt; $name, 'text' =&gt; $data['text']]
    ]);
}</code></pre>

<h3>4. Browser client</h3>
<pre class="language-html"><code class="language-html">&lt;script src="/socket.io/socket.io.js"&gt;&lt;/script&gt;
&lt;script&gt;
const socket = io({transports: ['websocket']});
socket.emit('chat/join', {room: 'general', name: 'Alice'});
socket.on('chat/message', (d) =&gt; console.log(d.name + ': ' + d.text));
socket.emit('chat/message', {text: 'Hello!'});
&lt;/script&gt;</code></pre>

<h2>For AI agents</h2>

<p>This server describes itself for AI agents and API clients: <a href="/llms.txt">/llms.txt</a>, the <a href="/.well-known/ai-catalog.json">AI catalog</a>, the <a href="/.well-known/api-catalog">API catalog</a> and the <a href="/.well-known/openapi.json">OpenAPI description</a>. They are generated from the server's endpoints and the app's handlers.</p>

<div class="note">
  <strong>Get started:</strong> Open the <a href="/Q/panel#apps">control panel</a> to create your first app, or drop files in <code>web/</code> to serve them directly.
  Use <code>--workers=40</code> for production. HTTPS starts automatically on port 443
  when certificates are present.
</div>

</div>

<div class="foot">
  Qbix Server · <a href="/Q/panel#apps" style="color:inherit">set up an app</a> or drop <code>web/index.html</code> to replace this page
</div>

<script src="/Q/prism.js"></script>

</body>
</html>
