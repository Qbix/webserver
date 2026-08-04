# ⚡ U WebServer

A native web server compiled from the [U programming language](https://ulanguage.pages.dev). No nginx, no Apache, no runtime. One 32KB binary serves static files, `.u` handlers, WebSocket connections, and rooms.

Inspired by the [Qbix PHP Server](https://github.com/Qbix/webserver), which proved that an in-process cache can beat nginx on keep-alive. U takes the same architecture and removes every remaining bottleneck: no interpreter, no GC, no fork, no COW page faults, no process table growth.

### 🔟🔟✖️ 100x more concurrent requests on the same hardware

The biggest bottleneck in PHP hosting is memory — each php-fpm worker loads your entire framework independently (30–60MB). The biggest bottleneck in Node.js is GC pauses — V8's garbage collector can freeze your event loop for 5–50ms. U has neither problem. Handlers are function calls, not processes. Memory is stack-allocated, not heap-managed. There is no GC, no fork, no interpreter.

```
nginx + php-fpm:   8GB ÷ 50MB per worker  =    160 concurrent PHP requests
Node.js (Express): 8GB ÷ 50MB per instance =    160 instances (with GC pauses)
U WebServer:       8GB ÷ 18MB total        =    1 process, 100K+ req/s
```

Same hardware. One process instead of 160.

### 📊 Measured performance (single-threaded, 32KB binary)

| Test | Connections | Req/s | Latency | Failures |
|------|------------|-------|---------|----------|
| Sequential (no keep-alive) | 1 | **21,090** | 0.05ms | 0 |
| Keep-alive | 100 | **103,547** | 0.010ms | 0 |
| Keep-alive (warm cache) | 100 | **104,576** | 0.010ms | 0 |
| 900 concurrent | 900 | **85,687** | 0.012ms | 0 |

290,000 requests served. Zero failures. 100% cache hit rate. Memory stable at 18MB baseline.

### Why it's faster than nginx + php-fpm for real apps

| | nginx + php-fpm | Qbix PHP Server | **U WebServer** |
|---|---|---|---|
| 🚀 Static files (keep-alive) | 80K–120K req/s | 36,300 req/s | **104,000 req/s** |
| ⚡ Handler/PHP speed | 3K req/s (FastCGI) | 8K req/s (fork) | **104,000 req/s** (compiled) |
| 💾 Memory total | 1.3MB + 30MB × N | 30MB + 5MB × N | **18MB fixed** |
| 🔄 Per-request cost | FastCGI roundtrip | fork (~500μs) | function call (~100ns) |
| 🗑️ Cleanup | GC + process exit | process exit | stack unwinding (free) |
| 🌐 WebSocket | separate server | built in (fork/conn) | **built in (epoll)** |
| 📦 Binary | 1.3MB + 30MB PHP | 280KB PHAR + PHP | **32KB** |

---

## 🚀 Quick Start

```bash
git clone https://github.com/ULanguageOrg/webserver.git
cd uwebserver

# Run (32KB binary, no dependencies)
./bin/uwebserver --port=8080
```

Open [http://localhost:8080](http://localhost:8080) — you'll see the welcome page with example endpoints. The `web/` directory has a starter project: an index page, example handlers, and a CSS file. Drop your own `.u` handlers and static files in `web/` and they're served automatically.

```bash
# HTTPS
./bin/uwebserver --port=8080 --tls-port=8443 --cert=cert.pem --key=key.pem
```

### 🎯 Drop files in folders. Get a server.

```
web/
├── index.html              ← GET / (served from memory cache)
├── style.css               ← GET /style.css
├── hello.u                 ← GET /hello (compiled U handler)
├── api/
│   └── echo.u              ← GET /api/echo?msg=hi (JSON handler)
├── ws/
│   └── chat.u              ← WebSocket handler
└── rooms/
    └── game.u              ← Room with shared state + tick timer
```

That's it. No routing config. No middleware chain. No framework. A `.u` file in a folder handles that URL path.

```u
// web/api/echo.u — GET /api/echo?msg=hello
f handle(req: Request) -> Response
    msg = req.query.get("msg") ?? ""
    msg.len == 0 ? r => Response.json({ "error": "missing msg parameter" })
    r => Response.json({ "echo": msg, "server": "U WebServer" })
```

---

## ✨ Features

| Category | What you get |
|---|---|
| **Static files** | ETag, 304 Not Modified, in-memory LRU cache, mtime validation, directory listing, sendfile for large files |
| **Keep-alive** | HTTP/1.1, TCP_NODELAY, connection reuse |
| **Handlers** | `.u` files in folders = URL routes, compiled to native |
| **Compression** | gzip/deflate for text responses >1KB |
| **WebSocket** | RFC 6455 + Socket.IO v5 wire protocol |
| **Rooms** | Shared state, tick timer, broadcast, join/leave |
| **TLS** | OpenSSL 3.x, TLSv1.3, ALPN (h2/http1.1), cert hot-reload |
| **Response cache** | Hash-indexed, TTL-based, 100% hit rate on warm paths |
| **Component cache** | Merkle-tree invalidation — change one component, keep the rest cached |
| **Security** | Path traversal blocked, dotfiles blocked |
| **Health** | `/health` JSON endpoint with live stats for load balancers |
| **Multi-core** | `SO_REUSEPORT` — one event loop per core, linear scaling |

---

## 🧹 What it replaces

| You used to need | Now |
|---|---|
| **nginx** | Built in — static files, keep-alive, ETag, sendfile, gzip, directory listing |
| **php-fpm** | Built in — `.u` handlers execute as native function calls |
| **Node.js + socket.io** | Built in — Socket.IO v5 wire protocol, rooms |
| **Redis** (pub/sub) | Built in — room state in memory, broadcast to members |
| **Varnish** (cache) | Built in — response cache + Merkle-tree component invalidation |
| **Process manager** | Built in — single binary, graceful shutdown |

One binary. One command. One port. **32 kilobytes.**

---

## 🏎️ Why no fork()?

PHP forks a process per request because it has no isolation model — every global is shared mutable state. U's type system provides the same isolation through the `-E` modifier:

```
PHP:    fork() → run script → echo output → exit() → OS cleans up
        Cost: ~500μs + 15-30MB COW pages + TLB flush

U:      call handler(req) → return Response → stack freed
        Cost: ~100ns + 0 bytes + 0 page faults
```

That's **5,000x cheaper per request**. Same isolation: a handler declared `+E(db)` can only access the database through the injected `db` capability. No filesystem, no network, no globals. The compiler verifies it.

### Determinism bonus

Because handlers are `-E` (pure) and optionally `-D` (deterministic), U can memoize responses automatically: same input → same output, serve from cache. `fork()` can't do this.

---

## 🧩 Merkle-Tree Cache Invalidation

Most caching systems cache whole pages. When anything changes, you purge the entire page. U WebServer (like the Qbix PHP Server it was ported from) caches individual page **components** and only invalidates what changed.

**How it works:** Your handler returns response headers describing its components:

```
X-Cache-Tree: {"l":{"feed":"a3f2","sidebar":"b8c1","header":"d4e5"}}
X-Cache-Deps: {"feed":["community/123/feed"],"sidebar":["community/123/about"]}
```

The server stores a Merkle tree of component hashes (~200 bytes per page, no HTML). When a dependency changes, only the affected components are invalidated:

```
Stream "community/123/feed" updated
→ walk dependency index: affects page /community/123, component "feed"
→ mark "feed" leaf as stale, keep "sidebar" and "header" cached
→ next request re-renders only the feed component
```

This is the same system from the [Qbix PHP Server](https://github.com/Qbix/webserver), where it's driven by the Streams plugin. In U, any handler can use it by setting the response headers.

---

## 🌐 WebSocket + Rooms

### WebSocket (Socket.IO compatible)

```javascript
// Standard socket.io-client works without changes
import { io } from 'socket.io-client';
const socket = io('http://localhost:8080', {transports: ['websocket']});
socket.emit('chat/message', {text: 'hello'});
socket.on('chat/message', (data) => console.log(data));
```

### Rooms — shared state without processes

```u
// web/rooms/game.u — room with tick timer
f on_join(room: Room, conn: WsConnection) -> none
    Rooms.broadcast(room.name, "{\"type\":\"join\"}", conn.key)
    none

f on_tick(room: Room) -> none
    Rooms.broadcast(room.name, json.encode(room.state), 0)
    none
```

| | PHP Qbix Server | U WebServer |
|---|---|---|
| Room cost | fork() per room (~5MB) | data structure (~150 bytes) |
| Max rooms (8GB) | ~1,600 | **~100,000** |
| IPC | socketpair | direct function call |

---


## 🧩 Q Framework — Qbix Platform Parity

U WebServer includes a port of the Qbix Platform's micro-framework, so PHP developers can use familiar patterns:

| PHP (Qbix Platform) | U (Q framework) |
|---|---|
| `Q::event('MyApp/feed/post', $params)` | `Q.event("MyApp/feed/post", params)` |
| `Q::canHandle('MyApp/feed/post')` | `Q.canHandle("MyApp/feed/post")` |
| `Q::view('MyApp/feed/item.php', $data)` | `Q.view("MyApp/feed/item", data)` |
| `Q_Config::get('Q', 'webserver', 'port')` | `Q.Config.get(["Q", "webserver", "port"])` |
| `Q_Uri::fromPath('api/users/42')` | `Q.Uri.from_path("api/users/42")` |
| `Q_Request::method()` | `Q.Request.method(req)` |
| `Q_Response::code(201)` | `Q.Response.error(201, body)` |

### Dispatch pipeline

Same three-stage pipeline as the Qbix Platform:



### Routing

Same config format, same pattern syntax (`$var` and `:var`):



### Scheduler

Same config format as the PHP server:



### Dashboard

Built-in at `/Q/dashboard` — live stats, request count, top paths, data transferred. JSON stats at `/health` for load balancers.

---

## 📂 For PHP Developers

| PHP | U |
|---|---|
| `$_GET['name']` | `req.query.get("name")` |
| `$_POST['email']` | `Http_parse.parse_form(req).get("email")` |
| `echo json_encode($x)` | `r => Response.json(x)` |
| `header('Location: ...')` | `r => Response.redirect(url, 302)` |
| `$pdo->query(...)` | `db.query(sql`...`)` |
| `apcu_store($k, $v)` | `cache.set(k, v, ttl)` |

The key differences: no globals (everything is a parameter or capability), return instead of echo, compile-time SQL validation, no GC.

---

## ⚙️ Configuration

```json
{
    "server": {
        "host": "0.0.0.0",
        "port": 8080,
        "tls_port": 0,
        "tls_cert": "",
        "tls_key": "",
        "keep_alive": true,
        "file_cache_max_bytes": 67108864,
        "file_cache_max_file": 1048576,
        "blocked_prefixes": ["/.", "/_"],
        "compress_min_size": 1024
    },
    "rooms": {
        "chat/$room": {"handler": "rooms/chat", "tick": 0},
        "game/$id": {"handler": "rooms/game", "tick": 100}
    }
}
```

---

## 🏗️ Architecture

```
                    ┌──────────────────────┐
 HTTP request ────→ │  epoll event loop    │
                    │  (32KB binary)       │
                    └────────┬─────────────┘
                             │
             ┌───────────────┼───────────────┐
             │               │               │
        ┌────▼─────┐   ┌────▼─────┐   ┌────▼─────┐
        │  Static  │   │    .u    │   │ WebSocket │
        │  files   │   │ handler  │   │ + rooms   │
        │          │   │          │   │           │
        │ LRU cache│   │ compiled │   │ Socket.IO │
        │ sendfile │   │ sandbox  │   │ RFC 6455  │
        └──────────┘   └──────────┘   └──────────┘
```

Small responses (<1MB) are served from the in-memory LRU cache. Large files use sendfile() for zero-copy transfer. Handlers are compiled native code called directly — no subprocess, no VM. Multi-core via `SO_REUSEPORT` with linear scaling.

---

## 📦 Running

```bash
# Pre-built binary (no dependencies)
./bin/uwebserver --port=8080

# With HTTPS
./bin/uwebserver --port=8080 --tls-port=8443 --cert=cert.pem --key=key.pem

# Compile from C source
gcc -O2 -o uwebserver bin/uwebserver.c -lssl -lcrypto -lm
```

---

## 📄 License

MIT — see [LICENSE](LICENSE).

Built with [U](https://ulanguage.pages.dev). Inspired by [Qbix Server](https://github.com/Qbix/webserver).
