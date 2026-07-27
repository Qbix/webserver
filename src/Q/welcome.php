<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Qbix Server</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f8f9fa;color:#333;line-height:1.6}
.hero{background:linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%);color:#fff;padding:48px 20px;text-align:center}
.hero h1{font-size:32px;font-weight:700;margin-bottom:6px}
.hero p{color:#a0b4c8;font-size:15px}
.hero .v{color:#4a9eff;font-size:12px;margin-top:6px}
.w{max-width:760px;margin:0 auto;padding:24px 16px}
h2{font-size:18px;margin:28px 0 10px;color:#1a1a2e}
h3{font-size:14px;margin:18px 0 6px;color:#555}
p,.note{margin:6px 0;font-size:13px;color:#666}
.note{background:#fff8e1;border-left:3px solid #ffc107;padding:8px 12px;border-radius:0 4px 4px 0;margin:12px 0}
pre{background:#1a1a2e;color:#a0e8af;padding:14px;border-radius:6px;overflow-x:auto;font-size:12px;line-height:1.5;margin:8px 0;-webkit-overflow-scrolling:touch}
code{font-family:"SF Mono",Monaco,Consolas,monospace;font-size:12px}
p code{background:#eee;padding:1px 5px;border-radius:3px;color:#333}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:16px 0}
.card{background:#fff;border-radius:8px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.card h3{margin:0 0 6px;color:#1a1a2e;font-size:15px}
.card p{font-size:12px;margin:0;color:#777}
.tag{display:inline-block;background:#e8f4ff;color:#2a7fff;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:600;margin-top:8px}
.tree{font-size:12px;line-height:1.7;color:#555;overflow-x:auto}
.tree b{color:#1a1a2e}
.cmt{color:#888}
.links{margin:24px 0;text-align:center;display:flex;flex-wrap:wrap;justify-content:center;gap:8px}
.links a{color:#fff;background:#2a7fff;text-decoration:none;padding:6px 14px;border-radius:6px;font-size:13px;white-space:nowrap}
.links a:hover{background:#1a6fe8}
.foot{text-align:center;color:#bbb;font-size:11px;padding:24px;border-top:1px solid #eee;margin-top:24px}
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
</div>

<div class="w">

<h2>Three execution models</h2>

<div class="cards">
  <div class="card">
    <h3>🌐 HTTP</h3>
    <p>Drop files in <code>web/</code>. Static files served directly, PHP scripts forked and executed.</p>
    <span class="tag">fork per request</span>
  </div>
  <div class="card">
    <h3>🔌 WebSocket</h3>
    <p>One process per connection. User isolation, auth, private state. Socket.IO compatible.</p>
    <span class="tag">process per connection</span>
  </div>
  <div class="card">
    <h3>🏠 Rooms</h3>
    <p>One process per room. Shared state for chat, games, collaboration. No Redis.</p>
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

<h2>Examples — copy and paste</h2>

<h3>1. HTTP — static files and PHP scripts</h3>
<p>Any file in <code>web/</code> is served directly. PHP files are executed.</p>
<pre><code>&lt;?php
// web/api/hello.php — visit /api/hello.php?name=Alice
header('Content-Type: application/json');
echo json_encode([
    'message' =&gt; 'Hello, ' . ($_GET['name'] ?? 'world')
]);</code></pre>

<h3>2. WebSocket — per-connection handlers</h3>
<p>Each connection gets its own process. Configure events in <code>config/server.json</code>:</p>
<pre><code>{
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
<pre><code>&lt;?php
// handlers/chat/join.php — user joins a room
function chat_join(&amp;$params, &amp;$result) {
    extract($params); // $socket, $event, $data
    $socket-&gt;join('chat/' . $data['room'], [
        'name' =&gt; $data['name']
    ]);
    $result = ['joined' =&gt; $data['room']];
}</code></pre>

<h3>3. Rooms — shared in-memory state</h3>
<p>All members share one process. State lives in class statics — COW handles cleanup.</p>
<pre><code>&lt;?php
// classes/ChatRoom.php
class ChatRoom {
    static $names = [];   // socketId =&gt; name
    static $history = []; // recent messages
}</code></pre>
<pre><code>&lt;?php
// handlers/chat/room/join.php — member enters room
function chat_room_join(&amp;$params, &amp;$result) {
    extract($params); // $room, $event, $data
    ChatRoom::$names[$room-&gt;socketId] = $data['name'];
    $room-&gt;broadcast([
        'event' =&gt; 'chat/joined',
        'data'  =&gt; ['name' =&gt; $data['name']]
    ]);
}</code></pre>
<pre><code>&lt;?php
// handlers/chat/room/message.php — member sends message
function chat_room_message(&amp;$params, &amp;$result) {
    extract($params); // $room, $event, $data
    $name = ChatRoom::$names[$room-&gt;socketId] ?? 'anon';
    $msg = ['name' =&gt; $name, 'text' =&gt; $data['text']];
    ChatRoom::$history[] = $msg;
    $room-&gt;broadcast([
        'event' =&gt; 'chat/message', 'data' =&gt; $msg
    ]);
    $result = ['sent' =&gt; true];
}</code></pre>

<h3>4. Client — connect from the browser</h3>
<pre><code>&lt;script src="/socket.io/socket.io.js"&gt;&lt;/script&gt;
&lt;script&gt;
const socket = io({transports: ['websocket']});
socket.emit('chat/join', {room: 'general', name: 'Alice'}, (res) =&gt; {
    console.log('Joined:', res.joined);
});
socket.on('chat/message', (data) =&gt; {
    console.log(data.name + ': ' + data.text);
});
socket.emit('chat/message', {text: 'Hello!'});
&lt;/script&gt;</code></pre>

<h3>5. Custom error pages</h3>
<p>Drop <code>errors/404.php</code> in your project to override the built-in page:</p>
<pre><code>&lt;?php // errors/404.php
?&gt;
&lt;!DOCTYPE html&gt;
&lt;html&gt;&lt;body&gt;
  &lt;h1&gt;Page not found&lt;/h1&gt;
  &lt;p&gt;&lt;?= htmlspecialchars($_path) ?&gt; doesn't exist.&lt;/p&gt;
  &lt;a href="/"&gt;Go home&lt;/a&gt;
&lt;/body&gt;&lt;/html&gt;</code></pre>

<div class="note">
  <strong>Get started:</strong> Create <code>web/index.html</code> to replace this page.
  All examples above can be copy-pasted into the directories shown.
</div>

<div class="links">
  <a href="/Q/dashboard">📊 Dashboard</a>
  <a href="/Q/health">💚 Health</a>
  <a href="/Q/panel">⚙️ Panel</a>
  <a href="https://github.com/Qbix/Server">📖 Docs</a>
</div>

</div>

<div class="foot">
  Qbix Server · Create <code>web/index.html</code> or <code>web/index.php</code> to replace this page
</div>

</body>
</html>
