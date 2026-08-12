<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>Qbix Server</title>
<link rel="stylesheet" href="/Q/prism.css">
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

<div class="note">
  <strong>Get started:</strong> Create <code>web/index.html</code> to replace this page.
  Use <code>--workers=40</code> for production. HTTPS starts automatically on port 443
  when certificates are present.
</div>

</div>

<div class="foot">
  Qbix Server · <code>web/index.html</code> replaces this page
</div>

<script src="/Q/prism.js"></script>

</body>
</html>
