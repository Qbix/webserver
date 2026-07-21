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

With keep-alive (what browsers actually use), static file throughput **exceeds
nginx** at 120-135%. Without keep-alive, nginx is faster on raw I/O — but
keep-alive is the default for all modern browsers. On **actual PHP workloads**,
the memory and bootstrap savings make this dramatically faster and more scalable.

> 💡 You can always put nginx, a reverse proxy, or a CDN (Cloudflare, CloudFront)
> in front of this for faster HTTPS and edge caching. Qbix Server handles the
> PHP execution, access control, and intelligent caching behind it.

### 🎯 Drop files in folders. Get a real-time server.

Three execution models — HTTP, WebSocket, and rooms — all shared-nothing,
all just PHP files in folders:

```
handlers/
├── api/users/
│   ├── get.php          ← HTTP: GET /api/users (fork, serve, die)
│   └── post.php         ← HTTP: POST /api/users
├── chat/
│   ├── message.php      ← WebSocket: one process per connection
│   └── join.php         ←   static vars persist across messages
└── game/
    └── room.php         ← Room: one process per room, shared state
                              with configurable tick timer

classes/
└── MyApp/               ← Preloaded and shared across all three models
    ├── Auth.php
    └── Chat.php
```

| Model | Process lifetime | State | Cleanup |
|---|---|---|---|
| **HTTP** | Fork → handle one request → die | None (shared-nothing) | Automatic — process exits |
| **WebSocket** | Fork → handle all messages from one user → die on disconnect | `static` vars persist across messages | Automatic — process exits |
| **Room** | Fork → handle messages from all users in room → die when empty | `static` vars shared across all members | Automatic — process exits |

No cleanup code. No memory leaks. No state leaking between users.
Every model uses `handlers/`, `classes/`, and `Q::event()`.
Try it — one command, zero config:

```bash
php qbixserver.php --root=./web --port=8080
```

---

## 📑 Table of Contents

- [Quick Start](#-quick-start)
- [Performance](#-performance)
- [Why Not php-fpm?](#-why-not-php-fpm)
- [vs FrankenPHP and Swoole](#️-vs-frankenphp-and-swoole)
- [Features](#-features)
- [Server Headers](#-server-headers--what-your-php-can-send)
- [HTTP — Fork Per Request](#-http--fork-per-request)
- [WebSocket — Process Per Connection](#-websocket--process-per-connection)
- [Rooms — Process Per Room](#-rooms--process-per-room)
- [Complete Example: Chat App With Rooms](#-complete-example-chat-app-with-rooms)
- [Clean URL Routing](#️-clean-url-routing-optional)
- [For PHP Developers](#-for-php-developers--the-micro-framework)
- [Configuration](#-configuration)
- [Running Legacy PHP](#running-legacy-php--wordpress-laravel-symfony)
- [Three Ways to Run](#-three-ways-to-run)
- [Building](#-building)
- [With Qbix Platform](#-with-qbix-platform)
- [Architecture](#-architecture)
- [Live Dashboard](#-live-dashboard)
- [HTTP/2 Support](#-http2-support)
- [Requirements](#-requirements)
- [Roadmap](#️-roadmap)
- [The mental model](#-the-mental-model)
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

# Or use the PHAR (single file, ~280KB)
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
| Keep-alive (c=10) | 26,858 req/s | 36,300 req/s | **135%** |
| Keep-alive (c=50) | 30,158 req/s | 36,300 req/s | **120%** |

Zero failed requests across 50,000+ requests at concurrency 50. Server never crashed.

> For context: 36K req/s means the server handles **1,800 simultaneous page loads per second**
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
| **WebSocket** | Socket.IO v5 compatible + bare WebSocket. Server→client RPC. Client JS served at `/Q/socket.js` and `/socket.io/socket.io.js`. |
| **Dashboard** | Live dashboard at `/Q/dashboard` — real-time request log, throughput sparkline, top paths, response times, memory, WebSocket connections, active rooms, status breakdown. Updates live via WebSocket. |
| **Health check** | JSON at `/Q/health` — all stats for load balancers and monitoring. Also available at `/Q/stats` with full detail. |
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
component-level caching. Your PHP sends them with `Q::header()`, the server acts on them.

### Quick reference

| Header | What it does | Example |
|---|---|---|
| `Cache-Control` | Server caches the response, serves without running PHP | `Q::header('Cache-Control: public, max-age=300');` |
| `X-Accel-Redirect` | Server streams a file after PHP checks access | `Q::header('X-Accel-Redirect: /uploads/private/doc.pdf');` |
| `X-Cache-Tree` | Registers page components with content hashes | `Q::header('X-Cache-Tree: ' . json_encode([...]));` |
| `X-Cache-Deps` | Maps components to data dependency keys | `Q::header('X-Cache-Deps: ' . json_encode([...]));` |
| `X-Cache-Invalidate` | Marks dependency keys as stale | `Q::header('X-Cache-Invalidate: ' . json_encode([...]));` |
| `X-Cache-Stale` | Marks specific components as needing re-render | `Q::header('X-Cache-Stale: feed,sidebar');` |

All of these use `Q::header()` instead of PHP's `header()`. This is because
the server runs in CLI SAPI where `header()` calls are silently discarded —
same as FrankenPHP worker mode and Workerman. `Q::header()` has the same
signature as `header()` but captures the values for the server to send.
The server strips internal headers before sending the response to the client.

### Access-controlled static files

With a typical server, your uploaded files sit at public URLs. Anyone with the link can
access them — and share the link with others. The usual workaround is "unguessable" URLs,
which are just security through obscurity.

`X-Accel-Redirect` lets your PHP check access, then tells the server to serve the file.
By convention, private files live in `files/` — a sibling of `web/`, outside the document root:

```
myproject/
├── web/               ← public (accessible via URL)
│   └── download.php   ← checks access, sends X-Accel-Redirect
└── files/             ← private (NOT accessible via URL)
    └── private/
        └── doc.pdf    ← served only through download.php
```

```php
<?php
// web/download.php — access-controlled file serving
session_start();

$fileId = $_GET['id'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

if (!$userId || !userCanAccess($userId, $fileId)) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

// Tell the server to serve from files/ directory.
// The client never sees the real path.
Q::header("X-Accel-Redirect: /files/private/{$fileId}");
Q::header("Content-Disposition: attachment; filename=\"document.pdf\"");
```

No config needed — `files/` is resolved automatically. For custom mappings:

```json
{
    "Q": {
        "webserver": {
            "accel": {
                "mappings": {
                    "/protected/": "/mnt/storage/protected/",
                    "/media/":     "/var/data/media/"
                }
            }
        }
    }
}
```

For nginx compatibility, mirror the mappings: `location /files/ { internal; alias /path/to/files/; }`

### Reverse proxy cache

Control how the server caches your PHP responses:

```php
<?php
// web/feed.php — cached for 5 minutes

// The server caches this response and serves it without
// running PHP again for the next 300 seconds.
Q::header('Cache-Control: public, max-age=300');

echo renderFeed();
```

```php
<?php
// web/profile.php — cached, but revalidate with ETag

// The server generates an ETag from the response body.
// Browsers send If-None-Match on next request.
// Server returns 304 (no body) if nothing changed.
Q::header('Cache-Control: public, max-age=0, must-revalidate');

echo renderProfile($userId);
```

```php
<?php
// web/admin.php — never cache

Q::header('Cache-Control: no-store');

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
Q::header('X-Cache-Tree: ' . json_encode([
    'l' => [
        'feed'    => md5($feedHtml),
        'sidebar' => md5($sidebarHtml),
        'members' => md5($membersHtml),
    ]
]));

Q::header('X-Cache-Deps: ' . json_encode([
    'feed'    => ["community/{$communityId}/feed"],
    'sidebar' => ["community/{$communityId}/about"],
    'members' => ["community/{$communityId}/participants"],
]));

Q::header('Cache-Control: public, max-age=300');
echo $feedHtml . $sidebarHtml . $membersHtml;
```

**Step 2: Invalidate when data changes**

```php
<?php
// web/post.php — user posts to the feed
saveNewPost($communityId, $content);

// Tell the server which dependency key changed
Q::header('X-Cache-Invalidate: ' . json_encode([
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

These headers work with `Q::header()` calls as shown above. But with the
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

## 🌐 HTTP — Fork Per Request

Every PHP request forks from the preloaded parent, handles the request, and dies.
No cleanup needed — the OS reclaims everything.

### Static files

Drop files in `web/`. They're served directly:

```
web/
├── index.html        ← GET /index.html
├── style.css         ← GET /style.css
└── app.js            ← GET /app.js
```

### PHP scripts

PHP files in `web/` execute as scripts — same as Apache or nginx + php-fpm:

```php
<?php
// web/api/users.php — GET /api/users.php
Q::header('Content-Type: application/json');
$users = MyApp\Users::recent(20);
echo json_encode($users);
```

### Clean URL handlers

With [routing configured](#️-clean-url-routing-optional), handlers in `handlers/`
map to clean URLs:

```php
<?php
// handlers/api/users/get.php — GET /api/users
function api_users_get(&$params, &$result) {
    Q::header('Content-Type: application/json');
    echo json_encode(MyApp\Users::recent(20));
}
```

### What happens per request

```
Browser: GET /api/users
  → Parent forks child process (COW — ~5MB delta)
  → Child runs handler (classes already loaded)
  → Child sends response and exits
  → OS reclaims all memory
```

No memory leaks. No state from one request bleeding into the next.
`exit()` only kills the child — the server keeps running.

---

## 🔌 WebSocket — Process Per Connection

Each WebSocket connection gets **one PHP process**. It stays alive for the entire
connection. Static variables persist across messages. When the client disconnects,
the process dies — all state wiped.

### How it works

```
Browser: connects to ws://localhost:8080/ws
  → Parent forks a child process for this connection
  → Every message the client sends goes to this child
  → Child runs Q::event('chat/message', ...) for each message
  → Static variables persist between messages (same process!)
  → Client disconnects → child process exits
  → OS reclaims all memory
```

### A simple counter

```php
<?php
// handlers/counter/increment.php
function counter_increment(&$params, &$result) {
    static $count = 0;  // persists across messages from THIS client
    $count++;
    $result = ['count' => $count];
}
```

```javascript
// Client — standard socket.io-client
import { io } from 'socket.io-client';
const socket = io('http://localhost:8080', {transports: ['websocket']});

socket.emit('counter/increment', {}, (res) => {
    console.log(res.count); // 1
});
socket.emit('counter/increment', {}, (res) => {
    console.log(res.count); // 2 — same process, same static var
});
```

### Authentication

The per-connection process is the natural place for auth. Validate once,
store in a static variable, use for every subsequent message:

```php
<?php
// handlers/auth/login.php
function auth_login(&$params, &$result) {
    static $user = null;

    if ($user) {
        $result = ['error' => 'already authenticated'];
        return;
    }

    $user = MyApp\Auth::validate($params['data']['token']);
    if (!$user) {
        $result = ['error' => 'invalid token'];
        return;
    }

    $result = ['userId' => $user['id'], 'name' => $user['name']];
}
```

### Joining rooms

A per-connection handler decides when to join a room. This is your access control —
the client can't join a room directly, only ask:

```php
<?php
// handlers/chat/join.php
function chat_join(&$params, &$result) {
    static $user = null;  // set by auth/login handler (shared static scope)
    $socket = $params['socket']; // Q_Socket instance

    $room = $params['data']['room'] ?? '';
    if (!$room) return;

    // Your access control logic
    if (!MyApp\Rooms::canAccess($user, $room)) {
        $result = ['error' => 'forbidden'];
        return;
    }

    // Pass user info to the room — the room's join handler gets this in $params['data']
    $socket->join("chat/$room", [
        'userId' => $user['id'],
        'name'   => $user['name'],
    ]);
    $result = ['joined' => $room];
}
```

The third argument to `$socket->join()` is forwarded to the room's `join`
handler as `$params['data']`. This is how the per-connection handler (which did
auth) passes identity to the room process (which doesn't know who anyone is).

Leaving works the same way — call `$socket->leave()` from a handler, or it
happens automatically on disconnect:

### Config

Map WebSocket event names to handler files:

```json
{
    "Q": {
        "webserver": {
            "sockets": {
                "events": {
                    "_connect":    "auth/login",
                    "_disconnect": "chat/leave",
                    "chat/join":   "chat/join",
                    "chat/message":"chat/message",
                    "chat/typing": "chat/typing"
                }
            }
        }
    }
}
```

If no mapping is configured, the event name is used directly as the handler path.
`_connect` and `_disconnect` are lifecycle events fired automatically.

### The client

```javascript
import { io } from 'socket.io-client';
const socket = io('http://localhost:8080', {transports: ['websocket']});

socket.on('connect', () => {
    socket.emit('auth/login', {token: myToken}, (res) => {
        if (res.userId) {
            socket.emit('chat/join', {room: 'general'});
        }
    });
});

socket.on('chat/message', (data) => {
    console.log(data.user + ': ' + data.text);
});

socket.emit('chat/message', {text: 'hello'}, (res) => {
    console.log('Saved as message #' + res.id);
});
```
### Context objects

Every handler receives context objects in `$params`. Use `extract($params)` to
get clean variables:

```php
function chat_message(&$params, &$result) {
    extract($params); // $socket, $event, $data
    $socket->reply(['received' => true]);
}
```

**Per-connection handlers** get `$socket` — a `Q_Socket` instance:

| Method / Property | What it does |
|---|---|
| `$socket->id` | This socket's numeric ID |
| `$socket->reply($data)` | Send to this client (fire and forget) |
| `$socket->send($socketId, $data)` | Send to a specific client |
| `$socket->broadcast($room, $data)` | Send to all clients in a room |
| `$socket->broadcastAll($data)` | Send to ALL connected clients |
| `$socket->join($room, $data)` | Join a room, forwarding `$data` to the room's join handler |
| `$socket->leave($room, $data)` | Leave a room, forwarding `$data` to the room's leave handler |
| `$socket->anyMethod($data)` | **RPC** — calls a method on the client, blocks until response (5s timeout) |

**Room handlers** get `$room` — a `Q_Room` instance:

| Method / Property | What it does |
|---|---|
| `$room->name` | Room name (e.g. `'chat/general'`) |
| `$room->socketId` | Current sender's socket ID |
| `$room->params` | Pattern params (e.g. `['room' => 'general']`) |
| `$room->broadcast($data)` | Send to all members (fire and forget) |
| `$room->reply($data)` | Send to the member who sent the current message |
| `$room->send($socketId, $data)` | Send to a specific member |

All send methods (`reply`, `broadcast`, `send`, `broadcastAll`) are **fire and
forget** — they queue the message and return immediately. Only `__call` (RPC)
blocks.

### Protocol

Two wire formats, auto-detected by path:

**Socket.IO** (connect to `/socket.io/`) — full Socket.IO v5 wire protocol.
The server bundles the client JS — no npm needed:

```html
<script src="/socket.io/socket.io.js"></script>
<script>
var socket = io('http://localhost:8080', {transports: ['websocket']});
socket.emit('chat/message', {text: 'hello'});
socket.on('chat/message', function(data) { console.log(data); });
</script>
```

Or use the npm package:

```javascript
import { io } from 'socket.io-client';
const socket = io('http://localhost:8080', {transports: ['websocket']});
```

Acks work both directions. Server→client RPC uses native ack callbacks:

```javascript
socket.emit('game/score', {id: 42}, (response) => console.log(response.rank));
socket.on('getLocation', (data, callback) => callback({lat: 40.7, lng: -74.0}));
```

Supported: events, acks (both directions), namespaces, ping/pong.
Not supported: HTTP long-polling, binary attachments.

**Bare WebSocket** (connect to any other path) — plain JSON, no framing.
Works with any language's WebSocket library.

The server serves a minimal client at `/Q/socket.js` (~100 lines, no
dependencies). Drop it in a `<script>` tag:

```html
<script src="/Q/socket.js"></script>
<script>
var socket = new QSocket('/ws');

socket.on('chat/message', function(data) {
    console.log(data.text);
});
socket.emit('chat/message', {text: 'hello'}, function(res) {
    console.log('sent, id=' + res.id);
});

// Server→client RPC
socket.handle('getLocation', function() {
    return {lat: 40.7, lng: -74.0};
});
</script>
```

Same API as `socket.io-client` — `on()`, `emit()`, `handle()`. Auto-reconnect
with backoff. Or use raw `WebSocket` directly:

```javascript
const ws = new WebSocket('ws://localhost:8080/ws');
ws.send(JSON.stringify({event: 'chat/message', data: {text: 'hello'}}));
ws.send(JSON.stringify({event: 'chat/message', data: {text: 'hi'}, ack: 1}));
```

```python
# Any language — just JSON over WebSocket
import websocket, json
ws = websocket.WebSocket()
ws.connect("ws://localhost:8080/ws")
ws.send(json.dumps({"event": "chat/message", "data": {"text": "hello"}}))
```

Handlers don't know which protocol the client is using — the server
translates at the wire level. Same handlers, same rooms, same everything.

### Namespaces

Socket.IO namespaces map to handler path prefixes. The default namespace `/`
maps to the root `handlers/` directory:

```
Namespace    Client emit              Handler path            Room "general"
─────────   ────────────             ────────────            ──────────────
/           emit('message', ...)     message                 general
/chat       emit('message', ...)     chat/message            chat/general
/admin      emit('auth', ...)        admin/auth              admin/general
```

```javascript
// Client connects to namespaces
const main = io('http://localhost:8080');          // default /
const chat = io('http://localhost:8080/chat');     // /chat
const admin = io('http://localhost:8080/admin');   // /admin

chat.emit('message', {text: 'hello'});   // → handlers/chat/message.php
admin.emit('auth', {token: '...'});      // → handlers/admin/auth.php
```

Namespace connect/disconnect handlers are optional. If you define one, it runs
as access control. If you don't, the namespace auto-accepts:

```php
<?php
// handlers/admin/connect.php — optional, runs on namespace connect
function MyApp_admin_connect(&$params, &$result) {
    extract($params); // $socket, $data
    if (!MyApp\Auth::isAdmin($data['token'] ?? '')) {
        $result = ['error' => 'forbidden'];
        return false; // reject namespace connection
    }
}
```

### Server→Client RPC

PHP handlers can call methods on the client using `$socket->methodName()`.
The call blocks until the client responds (5s timeout):

```php
<?php
// handlers/location/check.php
function MyApp_location_check(&$params, &$result) {
    extract($params); // $socket, $event, $data

    $location = $socket->getLocation();
    $prefs = $socket->getPreferences(['keys' => ['theme', 'lang']]);

    $result = [
        'lat' => $location['lat'],
        'theme' => $prefs['theme'],
    ];
}
```

Any method name that isn't `reply`, `send`, `broadcast`, `broadcastAll`,
`join`, or `leave` goes through `__call` → IPC → WebSocket → client → response.

**With `socket.io-client`** — server→client RPC uses native ack callbacks:

```javascript
const socket = io('http://localhost:8080', {transports: ['websocket']});

socket.on('getLocation', (data, callback) => {
    callback({lat: 40.7, lng: -74.0});
});

socket.on('getPreferences', (data, callback) => {
    callback({theme: 'dark', lang: data.keys});
});
```

**With `/Q/socket.js`** — use `handle()`:

```javascript
var socket = new QSocket('/ws');

socket.handle('getLocation', function() {
    return {lat: 40.7, lng: -74.0};
});

// Async handlers work too
socket.handle('getPosition', async function() {
    var pos = await new Promise(function(resolve) {
        navigator.geolocation.getCurrentPosition(resolve);
    });
    return {lat: pos.coords.latitude, lng: pos.coords.longitude};
});
```

**With bare WebSocket** — the client receives `{"event":"getLocation","data":{},"ack":7}`
and responds with `{"ack":7,"data":{"lat":40.7}}`.

### App namespacing

When building an app, prefix your handler functions with your app name to
avoid collisions. Set the app name in config:

```json
{
    "Q": {
        "app": "Chess"
    }
}
```

```
handlers/game/move.php    →  function Chess_game_move(&$params, &$result)
handlers/chat/message.php →  function Chess_chat_message(&$params, &$result)
handlers/connect.php      →  function Chess_connect(&$params, &$result)
```

Handler file paths stay the same — the app prefix is only on the function name.
Read it at runtime with `Q::app()`. Same for classes — use PHP namespaces:

```php
<?php
// classes/Chess/Game.php
namespace Chess;
class Game { /* ... */ }
```

If `Q.app` is not set, functions use no prefix: `game_move`, `chat_message`.
Small standalone projects don't need it.

### When to use per-connection

Use per-connection processes for **user-specific state**: authentication,
preferences, per-user rate limiting, message history, notification
subscriptions. Each user's data lives in their own process and can never
leak to another user.

---

## 🏠 Rooms — Process Per Room

For use cases where multiple connections need **shared in-memory state** — chat
messages, game positions, cursor aggregation, live vote tallies — use room
processes.

One process per active room. All members' messages go to the same process.
State is shared across all of them. When the last member leaves, the process
dies.

### The lifecycle

```
1. Client A's handler calls $socket->join('chat/general', ['userId'=>1, 'name'=>'Alice'])
2. Parent sees 'chat/general' matches pattern 'chat/$room'
3. Parent forks a room process → init handler fires
4. Parent sends _join to room → join handler fires (with socketId + data)
5. Client B joins the same room → join fires again (no new fork)
6. Both clients' messages are forwarded to the room process
7. Client A disconnects → leave fires (data is empty — unplanned disconnect)
8. Client B disconnects → leave fires → room is empty
9. destroy fires → room process exits
```

The client never talks to the room process directly. Per-connection handlers
call `$socket->join()` — that's the gateway. Access control lives there.
User identity flows through the third argument.

### Config

```json
{
    "Q": {
        "webserver": {
            "sockets": {
                "rooms": {
                    "chat/$room":  {"handler": "chat/room"},
                    "game/$id":    {"handler": "game/room", "tick": 100},
                    "collab/$doc": {"handler": "collab/room", "tick": 50}
                }
            }
        }
    }
}
```

The pattern uses `$name` placeholders — `chat/$room` matches `chat/general`,
`chat/dev`, etc. The `tick` option (in ms) fires `tick` events on a timer,
even when no messages arrive.

The `handler` value is a path prefix. Each event dispatches to its own handler
file under that prefix — just like HTTP handlers:

```
"chat/$room": {"handler": "chat/room"}

handlers/chat/room/
├── init.php          ← room created (first user joins)
├── join.php          ← user enters
├── leave.php         ← user exits or disconnects
├── tick.php          ← timer fired (if configured)
├── destroy.php       ← room shutting down (last user left)
├── message.php       ← "message" event from a member
└── typing.php        ← "typing" event from a member
```

Same pattern as HTTP: one file per event, function name matches the path.

### Room events

| Event | Handler file | `$params` has |
|---|---|---|
| `_init` | `handler/init.php` | `room`, `event`, `data` |
| `_join` | `handler/join.php` | `room`, `event`, `data` (from `$socket->join()`) |
| `_leave` | `handler/leave.php` | `room`, `event`, `data` (from `$socket->leave()`, or empty on disconnect) |
| `_tick` | `handler/tick.php` | `room`, `event`, `data` |
| `_destroy` | `handler/destroy.php` | `room`, `event`, `data` |
| *user event* | `handler/eventname.php` | `room`, `event`, `data` |

### Example: chat room handlers

```php
<?php
// handlers/chat/room/join.php
function chat_room_join(&$params, &$result) {
    $room   = $params['room']; // Q_Room instance
    $sid    = $room->socketId;
    $userId = $params['data']['userId'] ?? null;
    $name   = $params['data']['name'] ?? 'anon';

    ChatRoom::$names[$sid] = $name;

    // Track multiple sockets per user (tabs, devices)
    $isNew = true;
    if ($userId) {
        if (!isset(ChatRoom::$users[$userId])) ChatRoom::$users[$userId] = [];
        $isNew = empty(ChatRoom::$users[$userId]);
        ChatRoom::$users[$userId][$sid] = true;
    }

    // Send history to the new socket
    $room->reply([
        'event' => 'chat/history',
        'data'  => ['messages' => ChatRoom::$history],
    ]);

    if ($isNew) {
        $room->broadcast([
            'event' => 'chat/joined',
            'data'  => ['name' => $name],
        ]);
    }
}
```

```php
<?php
// handlers/chat/room/message.php
function chat_room_message(&$params, &$result) {
    $room = $params['room'];
    $name = ChatRoom::$names[$room->socketId] ?? 'anon';
    $text = $params['data']['text'] ?? '';
    if (!$text) return;

    $msg = ['name' => $name, 'text' => $text, 'time' => date('c')];
    ChatRoom::$history[] = $msg;
    if (count(ChatRoom::$history) > 50) array_shift(ChatRoom::$history);

    $room->broadcast([
        'event' => 'chat/message',
        'data'  => $msg,
    ]);
    $result = ['sent' => true];
}
```

```php
<?php
// handlers/chat/room/leave.php
function chat_room_leave(&$params, &$result) {
    $room = $params['room'];
    $sid  = $room->socketId;
    $name = ChatRoom::$names[$sid] ?? 'anon';
    unset(ChatRoom::$names[$sid]);

    $reallyGone = true;
    foreach (ChatRoom::$users as $uid => &$sockets) {
        if (isset($sockets[$sid])) {
            unset($sockets[$sid]);
            if (!empty($sockets)) $reallyGone = false;
            else unset(ChatRoom::$users[$uid]);
            break;
        }
    }
    if ($reallyGone) {
        $room->broadcast([
            'event' => 'chat/left',
            'data'  => ['name' => $name],
        ]);
    }
}
```

```php
<?php
// classes/ChatRoom.php — static properties for room state
// Preloaded into the parent process, shared via COW.
// Each room process gets its own copy-on-write fork —
// static properties start fresh and accumulate room-specific state.
// When the room process dies, everything is reclaimed. No cleanup needed.
class ChatRoom
{
    static $users = [];    // userId => [socketId => true, ...]
    static $names = [];    // socketId => name
    static $history = [];  // recent messages
}
```

Why class statics instead of `static` variables inside functions? Because each
handler is now a separate file. A `static $users` in `join.php` wouldn't be
visible in `leave.php`. Class statics (or globals) are shared across all
handlers in the same room process.

Copy-on-write handles the rest: the parent's `ChatRoom::$users` starts as `[]`.
When a room process forks and writes to it, only that room's pages are copied.
When the room dies, the OS reclaims everything. No `unset()`, no destructors,
no cleanup.

### Example: game with tick timer

```php
<?php
// handlers/game/room/join.php
function game_room_join(&$params, &$result) {
    $room = $params['room'];
    GameRoom::$players[$room->socketId] = [
        'x' => 0, 'y' => 0, 'hp' => 100,
    ];
    $room->reply([
        'event' => 'game/state',
        'data'  => ['players' => GameRoom::$players],
    ]);
}
```

```php
<?php
// handlers/game/room/move.php — client sends "move" event
function game_room_move(&$params, &$result) {
    $room = $params['room'];
    GameRoom::$players[$room->socketId]['x'] = $params['data']['x'];
    GameRoom::$players[$room->socketId]['y'] = $params['data']['y'];
    $result = ['ok' => true];
}
```

```php
<?php
// handlers/game/room/tick.php — called every 100ms
function game_room_tick(&$params, &$result) {
    $room = $params['room'];
    GameRoom::$tick++;
    $room->broadcast([
        'event' => 'game/state',
        'data'  => ['players' => GameRoom::$players, 'tick' => GameRoom::$tick],
    ]);
}
```

```php
<?php
// handlers/game/room/leave.php
function game_room_leave(&$params, &$result) {
    unset(GameRoom::$players[$params['room']->socketId]);
}
```

```php
<?php
// classes/GameRoom.php
class GameRoom
{
    static $players = [];
    static $tick = 0;
}
```

### Per-connection vs rooms

Both use the same handler pattern, same `Q_Socket` API, same directory structure.

| Use case | Model | Why |
|---|---|---|
| Auth, user prefs | Per-connection | Private to each user |
| Chat messages | Room | All members see all messages |
| Game state | Room + tick | Shared positions, periodic broadcast |
| Typing indicators | Either | Stateless — just relay |
| Notifications | Per-connection | User-specific subscriptions |
| Collaborative editing | Room + tick | Shared document state |
| Live voting/polling | Room | Shared tally, instant broadcast |

---

## 📖 Complete Example: Chat App With Rooms

All three models in one project. HTTP handles pages and login.
Per-connection WebSocket handles auth and room joining. Room processes
handle the actual chat.

### Project structure

```
chat/
├── qbixserver.php
├── config/
│   └── server.json
├── web/
│   ├── index.html              ← static: the chat UI
│   └── api/
│   └── api/
│       ├── messages.php        ← HTTP: GET recent messages from DB
│       └── login.php           ← HTTP: POST authenticate, return token
├── classes/
│   ├── Chat/
│   │   ├── Auth.php            ← shared: token validation
│   │   └── Messages.php        ← shared: DB read/write
│   └── ChatRoom.php            ← room state: static properties
└── handlers/
    ├── auth/
    │   └── login.php           ← per-connection: authenticate
    ├── chat/
    │   ├── join.php            ← per-connection: access control + join room
    │   └── room/
    │       ├── join.php        ← room: new member arrived
    │       ├── message.php     ← room: broadcast a message
    │       ├── typing.php      ← room: relay typing indicator
    │       └── leave.php       ← room: member left
    └── user/
        └── disconnect.php      ← per-connection: cleanup
```

### Config

```json
{
    "Q": {
        "webserver": {
            "sockets": {
                "events": {
                    "_connect":    "auth/login",
                    "_disconnect": "user/disconnect",
                    "chat/join":   "chat/join"
                },
                "rooms": {
                    "chat/$room": {"handler": "chat/room"}
                }
            }
        }
    }
}
```

Note: `message`, `typing` are NOT in the events map. Once a user joins a room,
their messages are forwarded directly to the room process and dispatched as
`chat/room/message`, `chat/room/typing`, etc.

### Per-connection handlers

```php
<?php
// handlers/auth/login.php — authenticate on connect
function auth_login(&$params, &$result) {
    $token = $params['data']['token'] ?? '';
    $user = Chat\Auth::validateToken($token);
    if (!$user) {
        $result = ['error' => 'invalid token'];
        return;
    }
    // Store for later use by chat/join (same process, shared globals)
    $GLOBALS['user'] = $user;
    $result = ['userId' => $user['id'], 'name' => $user['name']];
}
```

```php
<?php
// handlers/chat/join.php — access control, then join room
function chat_join(&$params, &$result) {
    $socket = $params['socket']; // Q_Socket instance
    $user = $GLOBALS['user'] ?? null;
    if (!$user) {
        $result = ['error' => 'not authenticated'];
        return;
    }
    $room = $params['data']['room'] ?? 'general';

    // Pass user identity to the room process
    $socket->join("chat/$room", [
        'userId' => $user['id'],
        'name'   => $user['name'],
    ]);
    $result = ['joined' => $room];
}
```

### Room handlers

```php
<?php
// handlers/chat/room/join.php
function chat_room_join(&$params, &$result) {
    $room   = $params['room']; // Q_Room instance
    $sid    = $room->socketId;
    $userId = $params['data']['userId'] ?? null;
    $name   = $params['data']['name'] ?? 'anon';
    ChatRoom::$names[$sid] = $name;

    $isNew = true;
    if ($userId) {
        if (!isset(ChatRoom::$users[$userId])) ChatRoom::$users[$userId] = [];
        $isNew = empty(ChatRoom::$users[$userId]);
        ChatRoom::$users[$userId][$sid] = true;
    }

    $room->reply([
        'event' => 'chat/history',
        'data'  => ['messages' => ChatRoom::$history],
    ]);
    if ($isNew) {
        $room->broadcast([
            'event' => 'chat/joined',
            'data'  => ['name' => $name],
        ]);
    }
}
```

```php
<?php
// handlers/chat/room/message.php
function chat_room_message(&$params, &$result) {
    $room = $params['room'];
    $name = ChatRoom::$names[$room->socketId] ?? 'anon';
    $text = $params['data']['text'] ?? '';
    if (!$text) return;

    $msg = ['name' => $name, 'text' => $text, 'time' => date('c')];
    ChatRoom::$history[] = $msg;
    if (count(ChatRoom::$history) > 50) array_shift(ChatRoom::$history);

    Chat\Messages::save($name, $text, $room->name);

    $room->broadcast([
        'event' => 'chat/message',
        'data'  => $msg,
    ]);
    $result = ['sent' => true];
}
```

```php
<?php
// handlers/chat/room/leave.php
function chat_room_leave(&$params, &$result) {
    $room = $params['room'];
    $sid  = $room->socketId;
    $name = ChatRoom::$names[$sid] ?? 'anon';
    unset(ChatRoom::$names[$sid]);

    $reallyGone = true;
    foreach (ChatRoom::$users as $uid => &$sockets) {
        if (isset($sockets[$sid])) {
            unset($sockets[$sid]);
            if (!empty($sockets)) $reallyGone = false;
            else unset(ChatRoom::$users[$uid]);
            break;
        }
    }
    if ($reallyGone) {
        $room->broadcast([
            'event' => 'chat/left',
            'data'  => ['name' => $name],
        ]);
    }
}
```

### The client

```javascript
import { io } from 'socket.io-client';
const socket = io('http://localhost:8080', {transports: ['websocket']});

socket.on('connect', () => {
    socket.emit('auth/login', {token: myToken}, (res) => {
        if (res.userId) socket.emit('chat/join', {room: 'general'});
    });
});

socket.on('chat/history', (data) => {
    data.messages.forEach(renderMessage);
});
socket.on('chat/message', (data) => {
    renderMessage(data);
});
socket.on('chat/joined', (data) => {
    showNotice(data.name + ' joined');
});
socket.on('chat/left', (data) => {
    showNotice(data.name + ' left');
});

document.getElementById('send').onclick = () => {
    socket.emit('message', {text: input.value});
};
```

### The three models in action

```
HTTP:           GET /api/messages   → fork → query DB → respond → die
Per-connection: auth/login          → validate token → store in $GLOBALS
                chat/join           → check access → $socket->join() with user data
Room:           chat/room/join      → ChatRoom::$users, $names, $history
                chat/room/message   → broadcast to all, persist to DB
                chat/room/leave     → multi-tab aware departure
```

### Run it

```bash
php qbixserver.php --root=./web --port=8080
```

One command. Static files, REST API, authentication, access-controlled rooms,
multi-tab awareness, and shared real-time chat — all from one PHP server.

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
    Q::header('Content-Type: application/json');
    echo json_encode(MyApp\Users::list($_GET));
}
```

```php
<?php
// handlers/api/users/post.php — handles POST /api/users
function api_users_post(&$params, &$result) {
    $user = MyApp\Users::create($_POST);
    http_response_code(201);
    Q::header('Content-Type: application/json');
    echo json_encode($user);
}
```

### Priority

```
1. Static files           /style.css           → web/style.css
2. PHP scripts            /legacy.php          → web/legacy.php
3. Routed handlers        /api/users           → handlers/api/users/get.php
4. index.php fallback     /anything            → web/index.php (if exists)
5. Configurable fallback  /anything            → see below
6. 404
```

Static files and `.php` scripts take priority. Routing only activates when
`Q.routes` is configured and no file matches. This means you can mix
routed handlers with direct PHP scripts — migrate gradually.

### Fallback — SPA routing, custom 404, catch-all

When nothing matches, the server checks `Q.webserver.fallback` in config.
Three options:

**SPA catch-all** — serve `index.html` for all unmatched routes (React, Vue, etc.):

```json
{ "Q": { "webserver": { "fallback": "index.html" } } }
```

**Custom 404 handler** — PHP processes the 404 (logging, custom pages):

```json
{ "Q": { "webserver": { "fallback": {"handler": "app/notfound"} } } }
```

```php
<?php
// handlers/app/notfound/get.php
function app_notfound_get(&$params, &$result) {
    Q_Response::code(404);
    Q::header('Content-Type: text/html');
    echo Q::view('app/404.php', ['path' => $_SERVER['REQUEST_URI']]);
}
```

**Static 404 page** — serve a file without invoking PHP:

```json
{ "Q": { "webserver": { "fallback": {"file": "404.html"} } } }
```

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

Q::header('Content-Type: application/json');
echo json_encode($feed);
```

### The `Q` class — available in every script

The server injects the `Q` class into every PHP script automatically. Here's
what you get:

| Method | What it does |
|---|---|
| `Q::event($name, $params)` | Fire an event — runs the handler from `handlers/` |
| `Q::canHandle($name)` | Check if a handler exists for an event |
| `Q::header($str, $replace, $code)` | Set a response header (use instead of `header()`) |
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
| `Q_Request::isInternal()` | True if genuine CLI, false if server-dispatched |
| `Q_Response::setHeader($name, $value)` | Set a response header |
| `Q_Response::code(201)` | Set HTTP status code |
| `Q_Response::setCookie($name, $val, ...)` | Set a cookie (prevents duplicates) |
| `Q_Response::redirect($url)` | 302 redirect (or 301 with `permanently`) |

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

### Why `Q::header()` instead of `header()`?

The server runs PHP in CLI SAPI (same as FrankenPHP worker mode and Workerman).
PHP's built-in `header()` is silently discarded in CLI mode. `Q::header()` has
the exact same signature but captures headers so the server can send them:

```php
Q::header('Content-Type: application/json');   // same as header() but works
Q::header('HTTP/1.1 201 Created', true, 201);  // status code

Q_Response::setHeader('X-Custom', 'value');    // named method
Q_Response::code(201);                         // status code
Q_Response::setCookie('session', $id);         // cookies
Q_Response::redirect('/login');                // redirect
```

For existing code that calls `header()` directly, use CGI carveout mode —
configure URL patterns in `server.json` under `Q.webserver.cgi.patterns` to run
those scripts via `php-cgi` where native `header()` works (see Configuration).

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

Q::header('Content-Type: application/json');
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
| `webserver.requestTimeout` | 30 | Seconds before killing a hung HTTP worker (0 = no limit) |
| `socket.io` | `"/socket.io"` | Socket.IO endpoint. Protocol detection + client JS at `{path}/socket.io.js`. `false` to disable. |
| `socket.js` | `"/Q/socket.js"` | Path to serve the minimal bare-WebSocket client (3KB). `false` to disable. |
| `app` | `""` | App name — prefixes handler function names (e.g. `"Chess"` → `Chess_chat_message()`) |
| `webserver.fallback` | null | Catch-all: `"index.html"`, `{"handler":"app/notfound"}`, or `{"file":"404.html"}` |
| `webserver.cgi.patterns` | [] | Regex patterns for scripts that use php-cgi (legacy compatibility) |
| `webserver.cgi.binary` | auto | Path to php-cgi binary (auto-detected if not set) |

### Scheduler

Run tasks on intervals or at specific times. Handlers are forked like HTTP
requests — they don't block the event loop and respect `requestTimeout`.

```json
{
    "Q": {
        "scheduler": {
            "cleanup": {
                "handler": "tasks/cleanup",
                "every": 3600
            },
            "daily-report": {
                "handler": "tasks/report",
                "times": ["09:00"]
            },
            "business-check": {
                "handler": "tasks/check",
                "times": ["09:00", "12:00", "17:00"],
                "weekdays": ["mon", "wed", "fri"]
            },
            "monthly-invoice": {
                "handler": "tasks/invoice",
                "times": ["00:00"],
                "monthdays": [1]
            }
        }
    }
}
```

| Field | What it does |
|---|---|
| `handler` | Handler path — dispatched via `Q::event()`, same as HTTP handlers |
| `every` | Run every N seconds from startup |
| `times` | Run at specific `HH:MM` times (24h format) |
| `weekdays` | Only fire on these days: `mon`, `tue`, `wed`, `thu`, `fri`, `sat`, `sun` |
| `monthdays` | Only fire on these days of the month: `[1]`, `[1, 15]`, etc. |

The handler receives `$params['task']` (the task name) and `$params['scheduled'] = true`:

```php
<?php
// handlers/tasks/cleanup.php
function tasks_cleanup(&$params, &$result) {
    MyApp\Sessions::expireOld();
    MyApp\Logs::rotate();
}
```

On restart, tasks scheduled for the current minute are skipped to avoid
double-firing. Interval tasks wait one full interval before their first run.

### CGI carveout mode — legacy PHP compatibility

Scripts matching `Q.webserver.cgi.patterns` run via `php-cgi` subprocess instead
of fork. Native `header()`, `setcookie()`, `session_start()` all work — full
compatibility with WordPress, Laravel, or any PHP code that calls `header()` directly.

```json
{
    "Q": {
        "webserver": {
            "cgi": {
                "patterns": [
                    "#^/wp-admin/.*\\.php$#",
                    "#^/wp-login\\.php$#",
                    "#^/legacy/.*\\.php$#"
                ]
            }
        }
    }
}
```

The tradeoff: CGI mode starts a fresh PHP interpreter per request (~50ms), so you
don't get the preload speed benefit. Static files, caching, and everything else
still work at full speed. Use this for third-party code you can't modify — your
own code should use `Q::header()` and the fork path for 10x performance.

The server auto-detects `php-cgi` on your system. Override with `cgi.binary`:

```json
{ "Q": { "webserver": { "cgi": { "binary": "/usr/bin/php-cgi8.3" } } } }
```

### Running legacy PHP — WordPress, Laravel, Symfony

You can run existing PHP applications on Qbix Server without modifying their code.
The key: put the framework's public directory as `web/`, and use CGI carveout
patterns to match all PHP files.

**WordPress:**

```
wordpress-site/
├── qbixserver.php          ← copy here
├── src/                    ← copy here
├── config/
│   └── server.json
└── web/                    ← symlink or copy of WordPress root
    ├── wp-admin/
    ├── wp-content/
    ├── wp-includes/
    ├── wp-login.php
    ├── index.php
    └── wp-config.php
```

```json
{
    "Q": {
        "webserver": {
            "cgi": {
                "patterns": ["#\\.php$#"]
            },
            "fallback": "index.php"
        }
    }
}
```

The pattern `#\\.php$#` sends all PHP files through `php-cgi`. The fallback
sends unmatched URLs to `index.php` (WordPress permalink routing). Static
files (images, CSS, JS) are served directly at full speed.

**Laravel:**

```
laravel-app/
├── qbixserver.php
├── src/
├── config/
│   └── server.json
├── web/                    ← symlink to Laravel's public/
│   ├── index.php
│   └── .htaccess           ← ignored (no Apache)
├── app/
├── routes/
├── storage/
└── vendor/
```

```json
{
    "Q": {
        "webserver": {
            "cgi": {
                "patterns": ["#\\.php$#"]
            },
            "fallback": "index.php"
        }
    }
}
```

All requests that don't match a static file go to `index.php`. Laravel's
router takes over from there. The `app/`, `vendor/`, and `storage/`
directories are outside `web/` — inaccessible via URL by default.

**Symfony:**

```
symfony-app/
├── qbixserver.php
├── src/
├── config/
│   ├── server.json
│   └── ...                 ← Symfony config files
├── web/                    ← symlink to Symfony's public/
│   └── index.php
├── src/                    ← Symfony source (separate from Qbix src/)
├── var/
└── vendor/
```

Same config pattern. Symfony's front controller (`public/index.php`) handles
all routing internally.

**Porting your own legacy code:**

For code you control, you have three options — from least effort to best performance:

**Option 1: Full CGI (zero changes, slower)**

```json
{ "Q": { "webserver": { "cgi": { "patterns": ["#\\.php$#"] } } } }
```

Every PHP file runs through `php-cgi`. Native `header()`, `setcookie()`,
`session_start()` all work. No code changes. Performance is comparable
to nginx + php-fpm (no preload benefit).

**Option 2: Targeted carveouts (minimal changes, mostly fast)**

```json
{
    "Q": {
        "webserver": {
            "cgi": {
                "patterns": [
                    "#^/admin/.*\\.php$#",
                    "#^/legacy/.*\\.php$#"
                ]
            }
        }
    }
}
```

Only specific paths use CGI. New code and simple scripts use fork mode
(10x performance). Legacy code that calls `header()` directly stays
in CGI mode.

**Option 3: Find-replace (one-time effort, full performance)**

In your PHP files, replace:
```
header(       →  Q::header(
setcookie(    →  Q_Response::setCookie(
```

Two find-replaces. Your code now uses fork mode everywhere — 10x concurrent
performance, preloaded classes, shared-nothing safety.

### Installing php-cgi

CGI carveout mode requires the `php-cgi` binary:

```bash
# Ubuntu/Debian
sudo apt install php-cgi

# macOS
brew install php    # includes php-cgi

# CentOS/RHEL
sudo yum install php-cgi

# Verify
php-cgi --version
```

---

## 📦 Three Ways to Run

### 1. From source (needs PHP 8.1+)

```bash
php qbixserver.php --root=./web --port=8080
```

### 2. PHAR — single ~280KB file (needs PHP)

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

**The remaining gap** versus nginx on non-keep-alive requests (55–73%) is inherent:
nginx uses `sendfile()` (kernel-space file→socket copy) and compiled C. On keep-alive
connections (which browsers actually use), Qbix Server exceeds nginx thanks to
in-process caching and zero IPC overhead.

---

## 📊 Live Dashboard

Open `http://localhost:8080/Q/dashboard` in your browser for a real-time server
dashboard. Updates live via WebSocket — no polling, no page refreshes.

**What it shows:**

| Panel | Metrics |
|---|---|
| **Overview cards** | Total requests, current RPS (5-sec window), avg response time, slowest request, memory usage + peak, worker status, WebSocket connections, active rooms, data transferred, open connections |
| **Throughput sparkline** | Per-second request rate for the last 60 seconds — see traffic patterns at a glance |
| **Top paths** | Most-requested URLs with hit count and average response time — find your hot paths |
| **Active rooms** | WebSocket room workers with member count — monitor real-time features |
| **Live request log** | Scrolling feed of every request: timestamp, status code (color-coded), method, URI, response time in ms |

**Endpoints:**

| URL | Format | Use case |
|---|---|---|
| `/Q/dashboard` | HTML | Browser — the visual dashboard |
| `/Q/health` | JSON | Load balancers, uptime monitors (lightweight) |
| `/Q/stats` | JSON | Monitoring systems — full stats payload |

The `/Q/stats` JSON includes everything the dashboard shows, plus `sparkline`
(60 data points), `topPaths`, `activeRooms`, `statusCodes` breakdown, and
`cache` stats. Feed it to Grafana, Datadog, or your own monitoring.

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

## 🗺️ Roadmap

**Coming next:**

- **Virtual hosts** — `Q.web.hosts.$hostname` config overrides for multi-domain serving
- **Hot reload** — watch `classes/`, `handlers/`, `config/` for changes, auto-restart workers

---

## 💡 The mental model

Three files for a complete real-time app:

```
handlers/game/join.php       ← adds player to static $players
handlers/game/move.php       ← updates static $positions, broadcasts
handlers/game/leave.php      ← removes player, notifies room
```

No Redis. No message queue. No pub/sub infrastructure. No WebSocket library.
No event loop to learn. Just PHP files in a folder.

The developer's decision tree:

```
Does this data matter after disconnect?
  No  → static variable              (cursors, typing, game positions)
  Yes → database call                (messages, scores, transactions)

Does anyone else need to see it?
  No  → just update your static var
  Yes → $room->broadcast()
```

Ephemeral state lives in RAM — static variables in the per-connection process.
It's fast (no I/O), isolated (per-user process boundary), and self-cleaning
(process dies on disconnect, OS reclaims everything). When you need durability,
call your preloaded classes to write to a database. When you need to notify
others, call `$room->broadcast()`.

The same `handlers/` directory serves HTTP requests, WebSocket messages, and
routed clean URLs. The same `classes/` directory is preloaded and shared across
all of them. One server, one codebase, one mental model.

```
Static files:    GET /style.css            → web/style.css
PHP scripts:     GET /page.php             → web/page.php
Routed:          GET /api/users            → handlers/api/users/get.php
Socket.IO:       42["chat/message",{...}]  → handlers/chat/message.php
Bare WebSocket:  {"event":"chat/message"}  → handlers/chat/message.php
Legacy:          GET /wp-admin/post.php    → php-cgi (full compatibility)
```

When you outgrow it — when you need the full dispatch pipeline, Streams for
real-time data synchronization, or the component-level cache invalidation
with Merkle trees — the same handlers run on the
[Qbix Platform](https://github.com/Qbix/Platform) without changes. The upgrade
path is adding capability, not rewriting architecture.

---

## 📄 License

MIT — see [LICENSE](LICENSE).

Part of the [Qbix Platform](https://github.com/Qbix/Platform).
