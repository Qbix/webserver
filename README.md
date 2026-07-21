# ⚡ Qbix Server

A pure PHP web server. No nginx, no Apache, no php-fpm.  
One process serves static files, PHP scripts, WebSocket connections, and a live dashboard.

### 🔟✖️ 10x more concurrent PHP on the same hardware

The biggest bottleneck in PHP hosting is **memory**. Each php-fpm worker loads your
entire framework independently — 30–60MB per worker. On an 8GB server, that's ~160
workers max. That's your ceiling for concurrent PHP requests.

Qbix Server forks workers **after** loading your classes. Thanks to copy-on-write,
all that shared code (framework, config, autoloader) uses memory only once. Each
worker adds only ~5MB for its per-request data:

```
php-fpm:       8GB ÷ 50MB per worker  =    160 concurrent PHP requests
Qbix Server:   8GB ÷  5MB per worker  =  1,600 concurrent PHP requests
```

Same hardware. Same PHP code. **10x more users served.**

### Why it's faster than nginx + php-fpm for real apps

| | nginx + php-fpm | Qbix Server |
|---|---|---|
| 🚀 **PHP request speed** | 10–50ms bootstrap on *every* request | **0ms** — workers fork after classes are loaded |
| 💾 **Memory per worker** | 30–60MB each (duplicated) | ~5MB each (shared base via copy-on-write) |
| 👥 **Concurrent PHP** (8GB) | ~160 workers | **~1,600 workers** |
| 🔒 **Access-controlled files** | Public URLs or hacky rewrites | `X-Accel-Redirect` — PHP checks access, server streams the file |
| 🧩 **Cache invalidation** | Whole-page only (purge everything) | `X-Cache-Tree` — invalidate one component, keep the rest cached |
| 🌐 **WebSocket** | Needs a separate server | Built in — 40K+ concurrent connections per server |
| ⚙️ **Setup** | Install nginx, configure proxy_pass, php-fpm pool, sockets... | `php qbixserver.php --port=8080` |

Static file throughput is 55–73% of nginx (C will always beat PHP on raw I/O).  
But on **actual PHP workloads**, the memory and bootstrap savings make this
dramatically faster and more scalable.

> 💡 You can always put nginx, a reverse proxy, or a CDN (Cloudflare, CloudFront)
> in front of this for faster HTTPS and edge caching. Qbix Server handles the
> PHP execution, access control, and intelligent caching behind it.

---

## 📑 Table of Contents

- [Quick Start](#-quick-start)
- [Performance](#-performance)
- [Why Not php-fpm?](#-why-not-php-fpm)
- [vs FrankenPHP and Swoole](#️-vs-frankenphp-and-swoole)
- [Features](#-features)
- [Server Headers](#-server-headers--what-your-php-can-send)
- [WebSocket — Real-Time PHP](#-websocket--real-time-php)
- [Example: A Complete Chat App](#-example-a-complete-chat-app)
- [Clean URL Routing](#️-clean-url-routing-optional)
- [For PHP Developers](#-for-php-developers--the-micro-framework)
- [Configuration](#-configuration)
- [Three Ways to Run](#-three-ways-to-run)
- [Building](#-building)
- [With Qbix Platform](#-with-qbix-platform)
- [Architecture](#-architecture)
- [HTTP/2 Support](#-http2-support)
- [Requirements](#-requirements)
- [License](#-license)

---

## 🚀 Quick Start

```bash
# Clone
git clone https://github.com/Qbix/Server.git
cd Server

# Create a web directory
mkdir web
echo '<h1>Hello World</h1>' > web/index.html

# Run
php qbixserver.php --port=8080
```

Open [http://localhost:8080](http://localhost:8080). That's it.

```bash
# Or serve an existing directory
php qbixserver.php --root=/var/www/mysite --port=80

# Or use the PHAR (single file, ~250KB)
php bin/qbixserver.phar --root=./public --port=8080
```

---

## 📊 Performance

Benchmarked against nginx on the same single-core container, PHP 8.3, Ubuntu 24.  
13KB static file, best-of-3 runs, warm caches.

| Scenario | nginx | Qbix Server | Ratio |
|---|---|---|---|
| Sequential (c=1) | 10,154 req/s | 6,376 req/s | **63%** |
| Concurrent (c=10) | 12,300 req/s | 6,876 req/s | **56%** |
| High concurrency (c=50) | 12,919 req/s | 7,253 req/s | **56%** |
| Keep-alive (c=10) | 26,858 req/s | 19,700 req/s | **73%** |
| Keep-alive (c=50) | 30,158 req/s | 20,369 req/s | **67%** |

Zero failed requests across 50,000+ requests at concurrency 50. Server never crashed.

> For context: 20K req/s means the server handles **1,000 simultaneous page loads per second**
> (assuming ~20 static assets per page), all from a single PHP process.

---

## 🏎️ Why Not php-fpm?

The traditional stack — nginx + php-fpm — works like this:

```
Request → nginx → FastCGI socket → php-fpm worker
                                     ↓
                                   Load PHP
                                   Include autoloader
                                   Boot framework
                                   Connect to DB
                                   Run your code
                                   Send response
                                     ↓
                                   Worker resets or dies
```

Every PHP request pays the bootstrap cost. Even with OPcache, each php-fpm worker re-initializes your framework's class instances, config trees, and DB connections on every request. For a framework like Qbix (or Laravel, Symfony, etc.), this bootstrap takes **10–50ms** — often longer than the actual work.

**Qbix Server eliminates this entirely:**

```
Startup:
  1. Load PHP
  2. Include autoloader
  3. Load ALL framework classes into memory
  4. Parse ALL config files
  5. Connect to database
  6. pcntl_fork() → workers inherit everything
                     ↓
Request:
  Worker already has classes, config, DB connections.
  Just run your code. 0ms bootstrap.
```

The key insight is **fork after preload**. Unix `fork()` uses copy-on-write, so forked workers share the parent's memory pages for all those preloaded classes. Each worker starts with ~30MB shared (read-only) and allocates only the per-request data. Compare this to php-fpm where each worker loads everything independently, using 30MB × N workers of duplicated memory.

### Preloading classes

Use the `--workers=N` flag and configure which classes to preload:

```json
{
    "Q": {
        "webserver": {
            "preload": [
                "Q_Dispatcher", "Q_Request", "Q_Response",
                "Q_Config", "Q_Cache", "Q_Session",
                "Db", "Db_Mysql", "Db_Row", "Db_Query",
                "Users", "Users_User", "Users_Session",
                "Streams", "Streams_Stream", "Streams_Message"
            ]
        }
    }
}
```

```bash
# Start with 4 workers (classes loaded once, shared across all)
php qbixserver.php --app=/path/to/myapp --port=8080 --workers=4
```

The parent process loads and parses every class in the `preload` list, then forks. Workers inherit the entire loaded state — OPcache entries, class definitions, parsed config trees, autoloader maps. The first PHP request in each worker runs at full speed, no cold start.

### The numbers

| | php-fpm | Qbix Server |
|---|---|---|
| Bootstrap per request | 10–50ms | **0ms** |
| Memory per worker | 30–60MB each | 30MB shared + ~5MB per worker |
| IPC overhead | FastCGI socket + serialization | Direct function call or Unix fork |
| Static files | Separate nginx process | Same process, memory-cached, single `fwrite` |
| Config reload | Restart all workers | `SIGHUP`, zero downtime |
| WebSocket | Needs separate server | Built in |

For a Qbix app with 20 loaded plugins, the bootstrap savings alone make the server **2–5x faster** on PHP requests compared to nginx + php-fpm.

### Why it's actually faster in practice

The benchmarks above measure static file throughput, where nginx's C implementation and `sendfile()` syscall give it an inherent edge. But for **real PHP applications**, the story flips:

- **nginx + php-fpm:** 0.1ms static file + 30ms PHP bootstrap + 5ms actual work = **35ms**
- **Qbix Server:** 0.15ms static file + 0ms bootstrap + 5ms actual work = **5ms**

The 0.05ms you lose on static files, you gain back 30ms on every PHP request. And you can always put nginx or a CDN in front for the static file edge.

---

## ⚖️ vs FrankenPHP and Swoole

If you're looking beyond php-fpm, you've probably seen FrankenPHP and Swoole. Here's how they compare:

| | FrankenPHP | Swoole | Qbix Server |
|---|---|---|---|
| **Language** | Go + C (embeds PHP) | C extension for PHP | Pure PHP |
| **Install** | Download Go binary or Docker | `pecl install swoole` (compiles C) | `php qbixserver.php` — nothing to install |
| **Architecture** | Worker mode (persistent) | Coroutine-based (persistent) | Shared-nothing with fork-after-preload |
| **State leaks** | ⚠️ Possible — workers persist between requests | ⚠️ Possible — must manage globals carefully | ✅ Impossible — each request gets a clean fork |
| **PHP compatibility** | Most code works, some edge cases | Many extensions incompatible, blocking I/O breaks coroutines | ✅ 100% — standard PHP, nothing unusual |
| **Memory safety** | Go runtime + PHP = complex interaction | C extension = segfault risk | PHP only = memory-safe by default |
| **Access control** | No X-Accel-Redirect equivalent | Manual implementation | ✅ Built-in X-Accel-Redirect |
| **Component cache** | No | No | ✅ X-Cache-Tree — sub-page invalidation |
| **Early hints / 103** | ✅ Yes | No | Via amphp |
| **HTTP/2** | ✅ Built-in (Caddy) | ✅ Built-in | ✅ Via amphp |
| **WebSocket** | Via Mercure | ✅ Built-in | ✅ Built-in |

### The shared-nothing advantage

FrankenPHP and Swoole keep PHP workers alive across requests. This is fast, but it means global state, static variables, database connections, and in-memory caches **persist between unrelated requests**. This causes subtle bugs:

```php
// This leaks between requests in FrankenPHP/Swoole:
class UserService {
    private static ?User $cached = null;
    
    public static function current(): User {
        if (!self::$cached) {
            self::$cached = User::fromSession();
        }
        return self::$cached; // Returns previous user's data!
    }
}
```

Every PHP framework, library, and snippet that uses static variables, singletons, or global state becomes a potential security hole. You have to audit everything.

Qbix Server avoids this entirely. Workers fork from a preloaded parent, so they inherit loaded classes and parsed config (read-only, shared via copy-on-write). But each request runs in its own process — when it's done, everything is gone. No state leaks. No audit needed. Your existing PHP code works exactly as it does on php-fpm.

### The "just PHP" advantage

FrankenPHP requires Go tooling to build or a pre-built binary that bundles Caddy. Swoole requires compiling a C extension, which can conflict with other extensions and doesn't work on all hosting environments.

Qbix Server is a PHP file. If you can run `php -v`, you can run the server. It uses standard PHP extensions (`sockets`, `pcntl`) that come pre-installed on most systems. There's no compilation step, no foreign runtime, no binary compatibility issues.

```bash
# FrankenPHP
docker pull dunglas/frankenphp  # 150MB+ image, or build from Go source

# Swoole
pecl install swoole             # compiles C, may fail on some systems
# Then edit php.ini, restart php...

# Qbix Server
php qbixserver.php --port=8080  # done
```

### When to choose what

**Choose FrankenPHP** if you want Caddy's ecosystem (automatic HTTPS, HTTP/3) and don't mind Go as a dependency. Good for Laravel projects that already use Octane.

**Choose Swoole** if you need coroutines for high-concurrency I/O (thousands of simultaneous HTTP client requests, database queries). Good for async-heavy microservices.

**Choose Qbix Server** if you want shared-nothing safety, zero-install deployment, access-controlled file serving, component-level cache invalidation, and full compatibility with existing PHP code. Good for apps that serve pages (not just APIs), need fine-grained caching, and want the simplest possible deployment.

---

## ✨ Features

| Category | What you get |
|---|---|
| **Static files** | ETag, 304 Not Modified, Last-Modified, MIME type detection, in-memory response cache |
| **Keep-alive** | HTTP/1.0 and 1.1, TCP_NODELAY, configurable limits |
| **HTTP/2** | Via amphp — multiplexed streams, header compression, TLS (optional) |
| **PHP execution** | `.php` files in document root run in-process or via pre-fork worker pool |
| **Compression** | On-the-fly gzip/brotli + pre-compressed `.gz`/`.br` siblings |
| **WebSocket** | RFC 6455 upgrade on any path |
| **Dashboard** | Live stats at `/Q/dashboard` — request rates, memory, status codes |
| **Health check** | JSON at `/Q/health` — for load balancers and monitoring |
| **Control panel** | Password-protected at `/Q/panel` — manage apps and scripts |
| **Rate limiting** | Per-IP with configurable windows and burst limits |
| **Security** | Path traversal blocked, dotfiles blocked, 431 for oversized headers, 400 for malformed requests |
| **Graceful shutdown** | SIGTERM/SIGINT drain in-flight requests before closing |
| **TLS** | Optional HTTPS with auto-certbot or manual certs |
| **Logging** | Colored terminal output + file-based access logs |
| **Access control** | X-Accel-Redirect support — PHP enforces access, server serves the file |
| **Component cache** | X-Cache-Tree headers — invalidate parts of a page, not the whole thing |

---

## 🔒 Server Headers — What Your PHP Can Send

Qbix Server understands special response headers from your PHP scripts. These are
the same headers nginx understands (like `X-Accel-Redirect`) plus new ones for
component-level caching. Your PHP sends them with `header()`, the server acts on them.

### Quick reference

| Header | What it does | Example |
|---|---|---|
| `Cache-Control` | Server caches the response, serves without running PHP | `header('Cache-Control: public, max-age=300');` |
| `X-Accel-Redirect` | Server streams a file after PHP checks access | `header('X-Accel-Redirect: /uploads/private/doc.pdf');` |
| `X-Cache-Tree` | Registers page components with content hashes | `header('X-Cache-Tree: ' . json_encode([...]));` |
| `X-Cache-Deps` | Maps components to data dependency keys | `header('X-Cache-Deps: ' . json_encode([...]));` |
| `X-Cache-Invalidate` | Marks dependency keys as stale | `header('X-Cache-Invalidate: ' . json_encode([...]));` |
| `X-Cache-Stale` | Marks specific components as needing re-render | `header('X-Cache-Stale: feed,sidebar');` |

All of these are standard PHP `header()` calls. No SDK, no framework needed.
The server strips them before sending the response to the client.

### Access-controlled static files

With a typical server, your uploaded files sit at public URLs. Anyone with the link can
access them — and share the link with others. The usual workaround is "unguessable" URLs,
which are just security through obscurity.

`X-Accel-Redirect` lets your PHP check access, then tells the server to serve the file
directly — fast, streamed, with no public URL exposed:

```php
<?php
// web/download.php — access-controlled file serving
session_start();

$fileId = $_GET['id'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

// Your access control logic
if (!$userId || !userCanAccess($userId, $fileId)) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

// Tell the server to serve the file directly.
// The client never sees the real path.
header("X-Accel-Redirect: /uploads/private/{$fileId}");
header("Content-Disposition: attachment; filename=\"document.pdf\"");

// The server takes over from here — streams the file
// with correct Content-Type, ETag, compression, etc.
// Your PHP process is already done.
```

No public URL for the file. No redirect the user can bookmark. The server streams
the file after your PHP has verified access and exited.

### Reverse proxy cache

Control how the server caches your PHP responses:

```php
<?php
// web/feed.php — cached for 5 minutes

// The server caches this response and serves it without
// running PHP again for the next 300 seconds.
header('Cache-Control: public, max-age=300');

echo renderFeed();
```

```php
<?php
// web/profile.php — cached, but revalidate with ETag

// The server generates an ETag from the response body.
// Browsers send If-None-Match on next request.
// Server returns 304 (no body) if nothing changed.
header('Cache-Control: public, max-age=0, must-revalidate');

echo renderProfile($userId);
```

```php
<?php
// web/admin.php — never cache

header('Cache-Control: no-store');

echo renderAdminPanel();
```

### Component-level cache invalidation

Most caching systems cache whole pages. When anything changes, you throw away the
entire page and re-render everything. Qbix Server can cache individual components
and only re-render what changed.

**Step 1: Register components when rendering a page**

```php
<?php
// web/community.php — a page with three components

$feedHtml    = renderFeed($communityId);
$sidebarHtml = renderSidebar($communityId);
$membersHtml = renderMembers($communityId);

// Tell the server about the component tree and what data each depends on
header('X-Cache-Tree: ' . json_encode([
    'l' => [
        'feed'    => md5($feedHtml),
        'sidebar' => md5($sidebarHtml),
        'members' => md5($membersHtml),
    ]
]));

header('X-Cache-Deps: ' . json_encode([
    'feed'    => ["community/{$communityId}/feed"],
    'sidebar' => ["community/{$communityId}/about"],
    'members' => ["community/{$communityId}/participants"],
]));

header('Cache-Control: public, max-age=300');
echo $feedHtml . $sidebarHtml . $membersHtml;
```

**Step 2: Invalidate when data changes**

```php
<?php
// web/post.php — user posts to the feed
saveNewPost($communityId, $content);

// Tell the server which dependency key changed
header('X-Cache-Invalidate: ' . json_encode([
    "community/{$communityId}/feed"
]));

// The server walks its dependency graph:
//   community/123/feed → page /community/123 component 'feed'
// Only 'feed' is stale. Sidebar, members = still cached.
// Next request re-renders only the feed component.

echo json_encode(['ok' => true]);
```

The server maintains a Merkle tree of component hashes. When a dependency key is
invalidated, it walks the tree to find exactly which components on which pages are
affected. Everything else is served from the in-memory cache.

### Even more powerful with Qbix Platform

These headers work with plain PHP `header()` calls as shown above. But with the
[Qbix Platform](https://github.com/Qbix/Platform), it becomes automatic:

```php
// Tools call this during rendering — the framework handles the rest
Q_Response::setCacheComponent('Streams/feed', $hash, [$depKey]);
Q_Response::invalidateCacheDeps($publisherId . '/' . $streamName);

// X-Accel-Redirect for access-controlled files
Q_Response::redirect(['uri' => $internalPath, 'accel' => true]);

// Cache-Control with semantic options
Q_Response::cacheFor(300);
```

The Platform's Streams plugin automatically invalidates cache dependencies when
stream data changes — posts, relations, participant joins — so cached pages
update themselves without any manual invalidation calls.

---

## 🔌 WebSocket — Real-Time PHP

Each WebSocket connection gets **one PHP process** — forked from the preloaded
parent, stays alive for the entire connection. The server dispatches each message
to a handler via `Q::event()`. Static variables in handlers persist across
messages. When the client disconnects, the process exits — all state wiped.

Same mental model as HTTP handlers, same `handlers/` directory, same `Q::event()`.
The only difference: the process lives longer.

### Handlers

```php
<?php
// handlers/chat/message.php
function chat_message(&$params, &$result) {
    // Static vars persist across messages (same process!)
    // Wiped on disconnect (process dies)
    static $messageCount = 0;
    $messageCount++;

    $text = $params['data']['text'];
    $userId = $params['data']['userId'];

    MyApp\Chat::save($userId, $text);

    Q_Socket::broadcast('chat/main', [
        'event' => 'chat/message',
        'data'  => ['user' => $userId, 'text' => $text],
    ]);

    $result = ['count' => $messageCount];
}
```

```php
<?php
// handlers/chat/join.php
function chat_join(&$params, &$result) {
    Q_Socket::join($params['_socketId'], $params['data']['room']);
    $result = ['joined' => $params['data']['room']];
}
```

```php
<?php
// handlers/auth/connect.php — authenticate on first message
function auth_connect(&$params, &$result) {
    $userId = MyApp\Auth::validate($params['data']['token']);
    if (!$userId) {
        Q_Socket::reply(['error' => 'invalid token']);
        return;
    }
    Q_Socket::join($params['_socketId'], "user/$userId");
    $result = ['authenticated' => true];
}
```

### Config

Map event names to handlers. Also supports `_connect` and `_disconnect` lifecycle events:

```json
{
    "Q": {
        "webserver": {
            "sockets": {
                "events": {
                    "_connect": "auth/connect",
                    "_disconnect": "chat/leave",
                    "chat/message": "chat/message",
                    "chat/join": "chat/join",
                    "chat/typing": "chat/typing"
                }
            }
        }
    }
}
```

If no mapping is configured, the event name is used directly as the handler path.

### The JS client (qbix-socket.js)

```html
<script src="/qbix-socket.js"></script>
<script>
var qs = new QSocket('ws://' + location.host + '/ws/chat');

qs.on('connect', function() {
    qs.emit('auth/connect', {token: myToken}, function(ack) {
        if (ack.authenticated) qs.emit('chat/join', {room: 'lobby'});
    });
});

qs.on('chat/message', function(data) {
    console.log(data.user + ': ' + data.text);
});

qs.emit('chat/message', {text: 'hello', userId: myId}, function(ack) {
    console.log('Message #' + ack.count);
});
</script>
```

Auto-reconnects with exponential backoff. Ack callbacks for request-response.

### Q_Socket API

| Method | What it does |
|---|---|
| `Q_Socket::reply($data)` | Send to this connection's client |
| `Q_Socket::send($socketId, $data)` | Send to a specific client |
| `Q_Socket::broadcast($room, $data)` | Send to all clients in a room |
| `Q_Socket::broadcastAll($data)` | Send to ALL connected clients |
| `Q_Socket::join($socketId, $room)` | Subscribe a client to a room |
| `Q_Socket::leave($socketId, $room)` | Unsubscribe from a room |

### Protocol

```
Client → Server:  {"event": "chat/message", "data": {...}, "ack": 42}
Server → Client:  {"ack": 42, "data": {...}}                (callback)
Server → Client:  {"event": "chat/message", "data": {...}}   (broadcast)
```

### Architecture

```
Browser ←─WebSocket─→ Parent (event loop)
                           │
               connect:    fork child, create IPC pipe
               message:    parent writes to child's pipe
                           child runs Q::event() handler
                           child calls Q_Socket::broadcast()
                           parent reads pipe, sends to sockets
               disconnect: parent signals, child exits
```

One process per connection. Each handler is a thin wrapper calling preloaded
class methods — the per-connection COW delta is typically ~40-200KB (just
static variables, call stack, and IPC buffer). The 30MB+ class base is shared.
On an 8GB server, that's **40,000+ concurrent WebSocket users**. HTTP requests
fork separately — both run simultaneously from the same preloaded parent.

---

## 📖 Example: A Complete Chat App

Everything below fits in one small project. HTTP handles pages and REST.
WebSocket handles real-time messaging. Both use the same `classes/` and
`handlers/` directories.

### Project structure

```
chat/
├── qbixserver.php
├── config/
│   └── server.json
├── web/
│   ├── index.html              ← static: the chat UI
│   ├── qbix-socket.js          ← static: WebSocket client
│   ├── api/
│   │   ├── messages.php        ← HTTP: GET recent messages
│   │   └── login.php           ← HTTP: POST authenticate, return token
│   └── style.css
├── classes/
│   └── Chat/
│       ├── Auth.php            ← shared: token validation
│       ├── Messages.php        ← shared: DB read/write
│       └── Rooms.php           ← shared: room membership
└── handlers/
    └── chat/
        ├── connect.php         ← socket: authenticate on connect
        ├── disconnect.php      ← socket: set user offline
        ├── message.php         ← socket: broadcast a message
        ├── join.php            ← socket: join a room
        └── typing.php          ← socket: broadcast typing indicator
```

### Config

```json
{
    "Q": {
        "webserver": {
            "preload": {
                "classes": ["Chat\\Auth", "Chat\\Messages", "Chat\\Rooms"]
            },
            "sockets": {
                "events": {
                    "_connect":    "chat/connect",
                    "_disconnect": "chat/disconnect",
                    "chat/message":"chat/message",
                    "chat/join":   "chat/join",
                    "chat/typing": "chat/typing"
                }
            }
        }
    }
}
```

### HTTP scripts — pages and REST

```php
<?php
// web/api/login.php — authenticate, return a token
$email    = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

$user = Chat\Auth::login($email, $password);
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials']);
    exit;
}

header('Content-Type: application/json');
echo json_encode([
    'token'  => Chat\Auth::createToken($user['id']),
    'userId' => $user['id'],
    'name'   => $user['name'],
]);
```

```php
<?php
// web/api/messages.php — recent messages (REST)
$room  = $_GET['room'] ?? 'general';
$limit = min((int)($_GET['limit'] ?? 50), 200);

header('Content-Type: application/json');
header('Cache-Control: public, max-age=5');
echo json_encode(Chat\Messages::recent($room, $limit));
```

Simple PHP scripts. `Chat\Auth` and `Chat\Messages` are autoloaded from `classes/`.
Preloaded into memory — zero autoloader cost per request.

### WebSocket handlers — real-time events

```php
<?php
// handlers/chat/connect.php — runs when WebSocket connects
function chat_connect(&$params, &$result) {
    // _connect fires automatically — authenticate via token
    // (called from client's first emit after connect)
}
```

```php
<?php
// handlers/chat/message.php — runs on each "chat/message" event
function chat_message(&$params, &$result) {
    static $userId = null;   // persists across messages
    static $userName = null; // same process = same state

    // First message includes auth token
    if (!$userId) {
        $token = $params['data']['token'] ?? '';
        $user = Chat\Auth::validateToken($token);
        if (!$user) {
            Q_Socket::reply(['error' => 'not authenticated']);
            return;
        }
        $userId = $user['id'];
        $userName = $user['name'];
    }

    $text = $params['data']['text'] ?? '';
    if (!$text) return;

    // Save to database
    $id = Chat\Messages::save($userId, $params['data']['room'] ?? 'general', $text);

    // Broadcast to everyone in the room
    Q_Socket::broadcast($params['data']['room'] ?? 'general', [
        'event' => 'chat/message',
        'data'  => [
            'id'   => $id,
            'user' => $userName,
            'text' => $text,
            'time' => date('c'),
        ],
    ]);

    $result = ['id' => $id]; // ack back to sender
}
```

```php
<?php
// handlers/chat/join.php
function chat_join(&$params, &$result) {
    $room = $params['data']['room'] ?? 'general';
    Q_Socket::join($params['_socketId'], $room);
    $result = ['joined' => $room];
}
```

```php
<?php
// handlers/chat/typing.php — lightweight, no DB
function chat_typing(&$params, &$result) {
    Q_Socket::broadcast($params['data']['room'] ?? 'general', [
        'event' => 'chat/typing',
        'data'  => ['user' => $params['data']['user']],
    ]);
}
```

```php
<?php
// handlers/chat/disconnect.php — cleanup on WebSocket close
function chat_disconnect(&$params, &$result) {
    // Process is about to die — do any cleanup
    // e.g. set user offline, leave all rooms
}
```

### The symmetry

```
HTTP request:     browser → GET /api/messages.php → fork → run → respond → die
WebSocket event:  browser → {"event":"chat/message"} → same process → handler → persist

Both use:
  classes/Chat/Auth.php         ← autoloaded, preloaded
  classes/Chat/Messages.php     ← autoloaded, preloaded
  handlers/chat/message.php     ← loaded on first use

HTTP scripts live in:    web/          (direct execution)
Socket handlers live in: handlers/     (inverted control — server calls you)
Shared code lives in:    classes/      (used by both)
```

### Run it

```bash
php qbixserver.php --root=./web --port=8080 --workers=4
```

One command. Static files, REST API, and WebSocket chat — all from one PHP server.

---

## 🛤️ Clean URL Routing (Optional)

Add `Q.routes` to your config and the server maps clean URLs to handlers —
same event pipeline as the [Qbix Platform](https://github.com/Qbix/Platform).
No `.php` suffixes, no rewrite rules.

### Config

```json
{
    "Q": {
        "routes": {
            "":                {"module": "app", "action": "welcome"},
            "$module/$action": {}
        }
    }
}
```

Route patterns use `$variable` for dynamic segments. Literal segments match
exactly. The matched `module` and `action` determine which handlers fire.

### Handler directory structure

```
handlers/
└── api/
    └── users/
        ├── validate.php    ← runs first (validate input)
        ├── get.php         ← runs on GET requests
        ├── post.php        ← runs on POST requests
        ├── put.php         ← runs on PUT requests
        ├── delete.php      ← runs on DELETE requests
        └── response.php    ← runs last (transform output)
```

### Dispatch pipeline

For `GET /api/users`, the server fires three events in order:

```
1.  api/users/validate   ← validate input, check auth
2.  api/users/get        ← handle the GET method
3.  api/users/response   ← post-process, add headers
```

This is the same pipeline as `Q_Dispatcher` in the full Qbix Platform.
Your handlers work identically when you upgrade.

### Example handlers

```php
<?php
// handlers/api/users/validate.php — runs before every method
function api_users_validate(&$params, &$result) {
    if (empty($_SERVER['HTTP_AUTHORIZATION'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit; // safe — forked process
    }
}
```

```php
<?php
// handlers/api/users/get.php — handles GET /api/users
function api_users_get(&$params, &$result) {
    header('Content-Type: application/json');
    echo json_encode(MyApp\Users::list($_GET));
}
```

```php
<?php
// handlers/api/users/post.php — handles POST /api/users
function api_users_post(&$params, &$result) {
    $user = MyApp\Users::create($_POST);
    http_response_code(201);
    header('Content-Type: application/json');
    echo json_encode($user);
}
```

### Priority

```
1. Static files           /style.css           → web/style.css
2. PHP scripts            /legacy.php          → web/legacy.php
3. Routed handlers        /api/users           → handlers/api/users/get.php
4. index.php fallback     /anything            → web/index.php (if exists)
5. 404
```

Static files and `.php` scripts take priority. Routing only activates when
`Q.routes` is configured and no file matches. This means you can mix
routed handlers with direct PHP scripts — migrate gradually.

### The full symmetry

```
Static files:    GET /style.css            → web/style.css
PHP scripts:     GET /page.php             → web/page.php (direct execution)
HTTP routed:     GET /api/users            → handlers/api/users/get.php
WebSocket:       {"event":"chat/message"}  → handlers/chat/message.php

All four use classes/ (preloaded, shared)
The last three use handlers/ (loaded on demand)
```

Drop files. They work. No framework to learn, no boilerplate to write.
When you outgrow it, the same handlers run on the full Qbix Platform.

---

## 📂 For PHP Developers — The Micro-Framework

Qbix Server isn't just a static file server with PHP bolted on. It's a micro-framework
where you **drop files into conventional directories** and things just work — classes
autoload, events fire handlers, views render templates. No configuration needed for
the basics.

### Project layout

```
myproject/
├── qbixserver.php              ← server entry point (or use the PHAR)
├── config/
│   └── server.json             ← server + app configuration
├── web/                        ← document root (publicly accessible)
│   ├── index.html              ← static files served directly
│   ├── style.css
│   ├── api.php                 ← PHP scripts executed on request
│   └── uploads/
├── classes/                    ← your PHP classes (autoloaded when first used)
│   ├── MyApp/
│   │   ├── User.php            ← MyApp\User or MyApp_User
│   │   ├── Feed.php
│   │   └── Auth.php
│   └── vendor/
│       └── autoload.php        ← Composer autoloader (optional)
├── handlers/                   ← event handlers (loaded on demand)
│   └── MyApp/
│       └── feed/
│           ├── post.php        ← handles "MyApp/feed/post" event
│           └── validate.php    ← handles "MyApp/feed/validate" event
└── views/                      ← PHP templates for Q::view()
    └── MyApp/
        └── feed/
            ├── page.php
            └── item.php
```

Only `web/` is accessible via HTTP. Everything else is server-side only.

**Your PHP scripts don't need to `require` or `include` anything.** The server
has already loaded the `Q` class, the autoloader, and the event system before
your script runs. Classes from `classes/`, events via `Q::event()`, views via
`Q::view()` — all available immediately. Just write your code:

```php
<?php
// web/api.php — no require, no include, no bootstrap
use MyApp\User;

$user = User::find($_GET['id']);
$feed = Q::event('MyApp/feed/get', ['userId' => $user->id]);

header('Content-Type: application/json');
echo json_encode($feed);
```

### The `Q` class — available in every script

The server injects the `Q` class into every PHP script automatically. Here's
what you get:

| Method | What it does |
|---|---|
| `Q::event($name, $params)` | Fire an event — runs the handler from `handlers/` |
| `Q::canHandle($name)` | Check if a handler exists for an event |
| `Q::view($name, $params)` | Render a PHP template from `views/` |
| `Q::ifset($arr, 'key1', 'key2', $default)` | Safe nested array/object access without isset chains |
| `Q::getObject($data, ['path', 'to', 'key'], $default)` | Deep access into nested arrays/objects |
| `Q::setObject(['path', 'to', 'key'], $value, $data)` | Deep set into nested arrays, creating intermediates |
| `Q::json_encode($value)` | `json_encode` with unescaped slashes |
| `Q::json_decode($json, true)` | `json_decode` wrapper |
| `Q_Config::get('section', 'key', $default)` | Read from `config/server.json` |
| `Q_Config::set('section', 'key', $value)` | Set a config value at runtime |
| `Q_Config::expect('section', 'key')` | Read config or throw if missing |
| `Q_Request::method()` | HTTP method: GET, POST, PUT, DELETE |
| `Q_Request::input()` | Raw request body (replaces `php://input`) |
| `Q_Request::json()` | Request body parsed as JSON |
| `Q_Request::header('X-Custom')` | Get any request header |
| `Q_Request::ip()` | Client IP (proxy-resolved) |
| `Q_Request::files('avatar')` | Uploaded files from `$_FILES` |
| `Q_Request::isAjax()` | True if X-Requested-With: XMLHttpRequest |
| `Q_Request::isJson()` | True if Content-Type is application/json |

```php
<?php
// web/settings.php — using Q utilities

// Safe deep access (no "undefined index" warnings)
$theme = Q::ifset($_COOKIE, 'theme', 'light');

// Read app config from config/server.json
$maxUpload = Q_Config::get('MyApp', 'upload', 'maxSize', 10485760);

// Fire an event with before/after hooks
$result = Q::event('MyApp/settings/save', [
    'userId' => $_SESSION['user_id'],
    'theme'  => $_POST['theme'],
]);

// Render a view
echo Q::view('MyApp/settings/page.php', [
    'result' => $result,
    'theme'  => $theme,
]);
```

When you upgrade to the full [Qbix Platform](https://github.com/Qbix/Platform),
the `Q` class expands with hundreds more methods — but everything above
continues to work identically. Your scripts don't need to change.

### Classes — autoloaded and optionally preloaded

Drop a PHP file in `classes/` and it's **autoloaded** — found automatically the first
time your code references it. No `require` needed. Both naming conventions work:

```php
<?php
// classes/MyApp/User.php — namespace style (PSR-4)
namespace MyApp;

class User {
    public static function fromSession(): ?self { /* ... */ }
    public static function find(string $id): ?self { /* ... */ }
}
```

```php
<?php
// classes/MyApp/Auth.php — underscore style (Qbix convention)
class MyApp_Auth {
    static function check(): bool { return !empty($_SESSION['user_id']); }
}
```

Both are available immediately in your `web/*.php` scripts:

```php
<?php
// web/profile.php — both class styles work, no require needed
use MyApp\User;

$user = User::fromSession();
$isAdmin = MyApp_Auth::check();
```

The autoloader maps class names to file paths (`MyApp\User` → `classes/MyApp/User.php`,
`MyApp_Auth` → `classes/MyApp/Auth.php`) and bridges between conventions with
`class_alias` — if you define `MyApp_Auth`, it's also accessible as `MyApp\Auth`,
and vice versa. If you have a Composer `autoload.php`, that works too — list it
in the preload config and both autoloaders coexist.

**Preloading** is optional but recommended for `--workers=N` mode. It loads specific
classes into memory *before* forking workers, so the autoloader never runs during
requests — classes are already there via copy-on-write:

```json
{
    "Q": {
        "webserver": {
            "preload": {
                "autoload": "classes/vendor/autoload.php",
                "classes": [
                    "MyApp\\User",
                    "MyApp\\Feed",
                    "MyApp_Auth"
                ]
            }
        }
    }
}
```

```bash
php qbixserver.php --root=./web --port=8080 --workers=4
#  Autoloader: autoload.php
#  Preloaded: 3 classes
```

Classes are **eager** — loaded once at startup, shared across all workers via
copy-on-write. This is the "hot path" code that handles every request.

### Handlers — loaded on demand

Handlers are the opposite of classes: they're loaded **only when their event fires**.
Drop a file in `handlers/` and it's available as an event:

```php
<?php
// handlers/MyApp/feed/post.php
// Handles the "MyApp/feed/post" event
// Function name = path with slashes replaced by underscores

function MyApp_feed_post(&$params, &$result) {
    $title = $params['title'] ?? 'Untitled';
    $userId = $params['userId'] ?? null;

    // Validate, save to DB, whatever
    $id = saveFeedPost($userId, $title);

    $result = ['id' => $id, 'title' => $title, 'saved' => true];
    return $result;
}
```

Fire it from anywhere:

```php
<?php
// web/api.php
$result = Q::event('MyApp/feed/post', [
    'title'  => $_POST['title'],
    'userId' => $_SESSION['user_id'],
]);

header('Content-Type: application/json');
echo json_encode($result);
```

The handler file is `include`'d the first time the event fires, then the function
stays in memory. If the event never fires, the file is never loaded. This is ideal
for things like webhooks, admin actions, and error handlers — code that runs rarely
but needs to be available.

**Check if a handler exists:**

```php
if (Q::canHandle('MyApp/feed/post')) {
    Q::event('MyApp/feed/post', $params);
}
```

### Before/after hooks

You can attach hooks to any event via config — useful for validation, logging,
access control, or cross-cutting concerns:

```json
{
    "Q": {
        "handlersBeforeEvent": {
            "MyApp/feed/post": ["MyApp/feed/validate"]
        },
        "handlersAfterEvent": {
            "MyApp/feed/post": ["MyApp/feed/notify"]
        }
    }
}
```

```php
<?php
// handlers/MyApp/feed/validate.php
function MyApp_feed_validate(&$params, &$result) {
    if (empty($params['title'])) {
        $result = ['error' => 'Title required'];
        return false; // stops the event chain — main handler won't fire
    }
}
```

```php
<?php
// handlers/MyApp/feed/notify.php
function MyApp_feed_notify(&$params, &$result) {
    // Runs after the main handler
    if (!empty($result['saved'])) {
        sendNotification($params['userId'], "Post published: " . $result['title']);
    }
}
```

The chain is: **before hooks → main handler → after hooks**. Any before hook
returning `false` stops the chain. This is the same pattern the full
[Qbix Platform](https://github.com/Qbix/Platform) uses — your handlers
work identically when you upgrade.

### Remote handlers

Handlers can also be URLs. If a handler name in the config starts with
`http://` or `https://`, the server POSTs the event parameters as JSON
to that URL instead of loading a local PHP file:

```json
{
    "Q": {
        "handlersAfterEvent": {
            "MyApp/user/register": ["https://hooks.example.com/new-user"]
        }
    }
}
```

When `Q::event('MyApp/user/register', $params)` fires, the local handler
runs first, then the server POSTs `$params` as JSON to the remote URL.
This is webhooks built into the event system — no separate webhook
infrastructure needed.

### Views — PHP templates

Render PHP templates from the `views/` directory:

```php
<?php
// views/MyApp/feed/item.php
// Variables are extracted into scope from the $params array
?>
<article>
    <h2><?= htmlspecialchars($title) ?></h2>
    <p><?= htmlspecialchars($body) ?></p>
    <time><?= $time ?></time>
</article>
```

```php
<?php
// web/feed.php
$items = MyApp\Feed::latest(10);
$html = '';
foreach ($items as $item) {
    $html .= Q::view('MyApp/feed/item.php', $item);
}
echo Q::view('MyApp/feed/page.php', ['content' => $html]);
```

Views are just PHP files — full language access, no template DSL to learn.

### The philosophy

| | Loaded when | Lives in | Purpose |
|---|---|---|---|
| **Classes** | Startup (preloaded) | `classes/` | Models, services, utilities — your core code |
| **Handlers** | First event fire (on demand) | `handlers/` | Actions, hooks, webhooks — code that responds to events |
| **Views** | When rendered | `views/` | Templates — HTML with PHP |
| **Scripts** | When requested via HTTP | `web/` | Entry points — the "controller" layer |
| **Config** | Startup | `config/` | Settings, handler hooks, preload lists |

Classes are **eager**. Handlers are **lazy**. Scripts are **per-request**.
Views are **on-demand**. This gives you the right loading strategy for each
kind of code without thinking about it — just put files in the right directory.

### Workers: fork-per-request (truly shared-nothing)

Each worker handles exactly **one request**, then exits. The parent immediately
forks a replacement. This means:

- Static variables — **wiped** (process dies)
- Global state — **wiped** (process dies)
- Memory leaks — **impossible** (OS reclaims everything)
- Secrets in memory — **gone** (no persistence between requests)

This is safer than php-fpm, which reuses workers across requests and relies on
`pm.max_requests` to periodically recycle them. With Qbix Server, every request
gets a clean process. The fork cost (~0.5ms) is negligible compared to the
bootstrap savings (~10–50ms).

### How PHP requests are handled

On Linux and macOS (where `pcntl_fork` is available), **every PHP request
is forked** — even without `--workers`. The server forks a child, the child
handles the request and exits, the parent continues serving. This means:

- `exit()` / `die()` in a script only kills the child — the server survives
- Long-running scripts don't block static file serving
- Each request is truly isolated

The `--workers=N` flag pre-forks N idle workers for faster dispatch (no fork
latency per request). Without it, the server forks on demand. Both modes are
shared-nothing.

**Windows** doesn't have `pcntl_fork`, so PHP scripts run in a subprocess
via `proc_open`. This is safe — `exit()` can't crash the server — but each
subprocess starts a fresh PHP interpreter (~50ms), so you don't get the
preload speed benefit. Static files, WebSocket, caching, and everything
else work identically. Good for development; use Linux/macOS for the full
10x performance advantage.

### Growing into the full Qbix Platform

The conventions above — `classes/`, `handlers/`, `views/`, `config/` — are
the same ones the [Qbix Platform](https://github.com/Qbix/Platform) uses.
When your project outgrows the micro-framework and you need user accounts,
real-time streams, access control, payments, or a plugin system, you switch
to `--app` mode and everything you've written keeps working. Your classes
stay in `classes/`, your handlers stay in `handlers/`, your views stay in
`views/`. You just gain access to Streams, Users, Assets, and the rest of
the plugin ecosystem — without rewriting anything.

---

## ⚙️ Configuration

Create `config/server.json` next to your `web/` directory, or pass `--config=path/to/config.json`:

```json
{
    "Q": {
        "webserver": {
            "keepAlive": {
                "max": 100,
                "timeout": 15
            },
            "maxConnections": 1024,
            "fileCache": {
                "maxSize": 67108864,
                "maxFile": 1048576,
                "checkInterval": 1
            },
            "rateLimit": {
                "enabled": true,
                "requests": 100,
                "window": 60
            }
        }
    }
}
```

| Key | Default | What it does |
|---|---|---|
| `keepAlive.max` | 100 | Max requests per keep-alive connection |
| `keepAlive.timeout` | 15 | Seconds before closing idle connection |
| `maxConnections` | 1024 | Max simultaneous connections |
| `fileCache.maxSize` | 64MB | Total memory for cached file responses |
| `fileCache.maxFile` | 1MB | Largest file to cache in memory |
| `fileCache.checkInterval` | 1 | Seconds between file modification checks |
| `rateLimit.enabled` | false | Enable per-IP rate limiting |
| `rateLimit.requests` | 100 | Requests per window |
| `rateLimit.window` | 60 | Window in seconds |

---

## 📦 Three Ways to Run

### 1. From source (needs PHP 8.1+)

```bash
php qbixserver.php --root=./web --port=8080
```

### 2. PHAR — single ~250KB file (needs PHP)

```bash
php bin/qbixserver.phar --root=./web --port=8080

# Or make it executable
chmod +x bin/qbixserver.phar
./bin/qbixserver.phar --port=8080
```

### 3. Static binary — no PHP needed

```bash
# Download from GitHub Releases
chmod +x qbixserver-linux-x86_64
./qbixserver-linux-x86_64 --root=./web --port=8080
```

The binary bundles PHP 8.3 + extensions into a single ~15MB executable.  
Copy it to any Linux or macOS machine and run. No dependencies.

---

## 🔨 Building

### Build the PHAR

```bash
php -d phar.readonly=0 build-phar.php
# Output: bin/qbixserver.phar
```

### Build the static binary

```bash
# With Docker (easiest):
./build-binary.sh --docker

# With static-php-cli installed locally:
./build-binary.sh

# Output: bin/qbixserver (~15MB)
```

The binary is built using [static-php-cli](https://github.com/crazywhalecc/static-php-cli),
which compiles PHP + extensions into a statically linked binary.

GitHub Actions automatically builds binaries for **Linux x86_64**, **Linux ARM64**,
**macOS x86_64**, and **macOS Apple Silicon** on every tagged release.

---

## 🔌 With Qbix Platform

Qbix Server is extracted from the [Qbix Platform](https://github.com/Qbix/Platform) — a full-stack
framework for building social apps with real-time streams, user management, and plugin architecture.

When you have a Qbix app, the server uses the full framework:

```bash
php qbixserver.php --app=/path/to/myapp --port=8080
```

In this mode:

- Requests route through `Q_Dispatcher` — the full Qbix event pipeline
- Plugins load automatically (Users, Streams, Assets, etc.)
- Clean URLs work (`/community/123` → module routing)
- Static files still use the fast path (no framework overhead)
- The dashboard shows Qbix-specific stats

The standalone mode (without `--app`) runs as a plain web server — no framework, no plugins.
PHP files execute directly, static files serve from memory. Use this for simple sites,
APIs, or any project that doesn't need the full Qbix stack.

### Qbix Platform scripts

The full Platform includes additional server scripts like `static.php` for
CDN-style static file serving with versioned URLs. See the
[Platform repository](https://github.com/Qbix/Platform) for details.

---

## 🏗️ Architecture

```
                    ┌──────────────────┐
 HTTP request ────→ │  Event Loop      │ stream_select (zero deps)
                    │  (single thread) │ or amphp/revolt (optional)
                    └────────┬─────────┘
                             │
             ┌───────────────┼───────────────┐
             │               │               │
        ┌────▼─────┐   ┌────▼─────┐   ┌────▼─────┐
        │  Static  │   │   PHP    │   │ WebSocket │
        │  Files   │   │ Dispatch │   │  Upgrade  │
        │          │   │          │   │           │
        │ In-memory│   │ In-proc  │   │ RFC 6455  │
        │ response │   │ or fork  │   │ frames    │
        │ cache    │   │ pool     │   │           │
        └──────────┘   └──────────┘   └──────────┘
```

**Static files** are served from an in-memory response cache. The full HTTP response
(headers + body) is pre-built and sent in a single `fwrite()` call. The cache is
mtime-validated with configurable check intervals. Combined with `TCP_NODELAY`,
this delivers sub-millisecond response times.

**PHP scripts** run in-process (single-threaded, suitable for lightweight APIs)
or in a pre-fork worker pool (`--workers=N`) for concurrent PHP execution.
Workers are forked after class preloading, so they share the base memory footprint
via copy-on-write pages.

**The remaining gap** versus nginx (55–73%) is inherent: nginx uses
`sendfile()` (kernel-space file→socket copy), `epoll` (O(1) event notification),
and compiled C. PHP's `stream_select` is `select(2)`, file serving goes through
userspace, and every operation has interpreter overhead. Getting to 55–73% of C
performance from pure interpreted PHP is about as good as it gets.

---

## 🌐 HTTP/2 Support

The built-in event loop uses `stream_select` — zero dependencies, works everywhere.
But if you install [amphp](https://amphp.org/), the server upgrades to a full
HTTP/2 server with no code changes:

```bash
composer require amphp/http-server amphp/socket
php qbixserver.php --port=8443
```

The server detects amphp automatically and switches to its event loop and HTTP
driver. You get:

| | HTTP/1.1 (built-in) | HTTP/2 (amphp) |
|---|---|---|
| Connections per page load | ~6 parallel | 1 multiplexed |
| Header overhead | Full headers per request | HPACK compressed |
| Event loop | `stream_select` (portable) | `epoll`/`kqueue` via Revolt |
| TLS | `stream_socket_enable_crypto` | amphp native TLS |
| Server push | No | Yes (push static assets before browser asks) |

### How it works

The server has a clean two-layer architecture. `Q_WebServer::route()` handles
all request logic (static files, PHP dispatch, cache, access control) and returns
a `[status, headers, body]` array. The transport layer is pluggable:

```
Built-in:   stream_select → accept → fread → route() → fwrite
amphp:      Revolt loop → amphp HTTP server → route() → amphp response
```

All the server's features — response cache, X-Accel-Redirect, component cache
invalidation, keep-alive, compression — work identically on both transports.
The `Q_Evented` facade abstracts the event loop, so timers, signals, and socket
watchers work the same way whether you're on `stream_select` or Revolt.

### When to use which

**Built-in (default):** Zero dependencies. Works on any PHP 8.1+ installation.
Good for development, small-to-medium sites, and environments where you can't
install Composer packages.

**amphp:** Better performance under high concurrency thanks to `epoll`/`kqueue`.
HTTP/2 multiplexing reduces connection overhead for asset-heavy pages.
Required if you need server push or HTTP/2-only clients.

**Either way:** You can always put Cloudflare, CloudFront, or nginx in front
as a reverse proxy. The CDN terminates HTTP/2 (and HTTP/3) for you, forwarding
HTTP/1.1 to the backend. In that configuration, the built-in transport is all
you need — the CDN handles the protocol upgrade.

---

## 📋 Requirements

**Linux / macOS (recommended):**

- PHP 8.1 or later
- Extensions: `sockets`, `pcntl` (for signals + workers), `openssl` (for HTTPS)

```bash
# Check
php -m | grep -E 'sockets|pcntl|openssl'

# Install on Ubuntu/Debian
sudo apt install php-cli php-sockets
```

**For the static binary:**

- Nothing. The PHP runtime is included.

**Windows:** The server works without `pcntl`. Static files, PHP scripts,
WebSocket, caching, compression, access control — everything works. PHP
scripts run in isolated subprocesses via `proc_open`, so `exit()` and
crashes won't bring down the server. You lose the preload speed benefit
(each subprocess starts fresh) and signal-based graceful shutdown. For
the full 10x performance advantage, use Linux or macOS (or WSL).

---

## 📄 License

MIT — see [LICENSE](LICENSE).

Part of the [Qbix Platform](https://github.com/Qbix/Platform).
