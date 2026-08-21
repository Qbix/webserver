# ⚡ Qbix Server

### Run your PHP scripts over 100× faster than nginx + php-fpm

A pure PHP web server. No nginx, no Apache, no php-fpm.
One process serves static files, PHP scripts, WebSocket connections, and a
live dashboard. With `--workers=N`, persistent workers match fpm's per-request
speed at over 100× the concurrent capacity.

## Three things we measured

**1. Over 100× more concurrent capacity.** Each fpm worker loads the framework
independently (~42MB). Our workers fork after loading, sharing the framework
via copy-on-write. A typical handler adds only ~200KB of private pages
(measured via `/proc/smaps_rollup`). On 1GB of RAM:

```
php-fpm:       1GB ÷ 42MB per worker  =      24 concurrent PHP requests
Qbix Server:   1GB ÷ 200KB per child  =   5,000 concurrent PHP requests
```

Measured across Qbix (380 classes), Laravel-sim (761), Symfony-sim (761):

| Request type | Private delta | Ratio |
|---|---|---|
| Typical handler | 150–236 KB | **182–306×** |
| Heavy response (1K items) | 772 KB–1.0 MB | **41–52×** |
| Bulk data (10K rows) | ~8.6 MB | **5×** |

**2. PHP reuses the parent's heap.** Children do not allocate new memory
chunks. The parent pre-allocates a 4MB heap; children use it via COW. Only the
specific 4KB pages the child writes to are copied by the kernel. This is why
the per-child cost is ~200KB, not the ~5MB you'd expect from PHP's allocator.

**3. Same speed as Swoole and fpm.** Head-to-head at 4 workers each: octane
439 req/s vs fpm 469 vs Swoole 483. Within 7% — the gap is IPC overhead, same
as fpm's FastCGI socket. But with 100× more workers on the same RAM, the
effective throughput under load is dramatically higher.

### Under real load

200 concurrent requests, I/O increasing as the database gets busier:

| I/O latency | fpm (4w) | octane (200w, same RAM) | Throughput | Latency |
|---|---|---|---|---|
| 10ms | 382/s, p50: 516ms | **1,316/s, p50: 18ms** | **3.4×** | **29×** |
| 50ms | 79/s, p50: 2.5s | **358/s, p50: 58ms** | **4.5×** | **44×** |
| 100ms | 40/s, p50: 5.0s | **318/s, p50: 226ms** | **8.0×** | **22×** |
| 200ms | 20/s, p50: 10.0s | **105/s, p50: 212ms** | **5.3×** | **47×** |

At 200ms I/O (a database under contention), fpm users wait **10 seconds**.
Octane users wait **212 milliseconds**. Same hardware, same code, same RAM.

### What it replaces

| | nginx + php-fpm | Qbix Server |
|---|---|---|
| 💾 **Memory per worker** | 30–60MB (duplicated) | ~200KB (COW, measured) |
| 👥 **Concurrent PHP** (1GB) | ~24 workers | **~5,000** (typical) |
| 🔒 **Isolation** | Statics leak between requests | Snapshot reset — no leaks |
| 🚀 **Throughput** (4 workers) | 469 req/s | 439 req/s (within 7%) |
| 🚀 **Throughput** (same memory) | 78 req/s (4w) | **520 req/s** (40w) |
| 🌐 **WebSocket** | Needs a separate server | Built in |
| 🧩 **Cache invalidation** | Whole-page only | `X-Cache-Tree` — per-component |
| ⚙️ **Setup** | nginx + fpm pools + sockets | `php qbixserver.php` |

See [BENCHMARKS.md](docs/BENCHMARKS.md) for full methodology and
[reset.md](docs/reset.md) for what gets reset between requests.

### Deployment

**Development** — one command, everything included:

```bash
php qbixserver.php --app=/path/to/myapp --workers=40
```

Listens on port 80 by default. If TLS certificates exist (or Let's Encrypt is
configured), HTTPS on port 443 starts automatically — no extra flags needed.

```bash
php qbixserver.php --app=/path/to/myapp --workers=40
# → http on :80, https on :443 (if certs found)

# Override ports via CLI
php qbixserver.php --port=8080 --https-port=8443

# Or via config/server.json (CLI overrides config)
# { "Q": { "webserver": { "port": 8080 }, "web": { "https": { "port": 8443 } } } }
```

HTTP, HTTPS, static files, PHP, WebSocket, cron — one process, no external
dependencies.

**Production** — put a CDN in front, no nginx needed:

```
  Client → CDN (Cloudflare / CloudFront) → Qbix Server
```

The CDN caches static files at the edge, terminates HTTPS, and handles
HTTP/2+3. Qbix is the origin for PHP execution with `--workers=N` giving
100× more concurrent capacity than fpm. The built-in scheduler replaces cron:

```json
{ "Q": { "scheduler": {
    "cleanup":  { "handler": "tasks/cleanup", "every": 3600 },
    "report":   { "handler": "tasks/report", "times": ["09:00"] },
    "invoice":  { "handler": "tasks/invoice", "times": ["00:00"], "monthdays": [1] }
}}}
```

Tasks fork child processes — they don't block the event loop or PHP workers.

**With nginx** — optional, for access-controlled file downloads:

```nginx
upstream qbix { server 127.0.0.1:9000; }
server {
    listen 443 ssl;
    location /files/ { internal; alias /path/to/myapp/files/; }
    location / { proxy_pass http://qbix; proxy_set_header Host $host; }
}
```

```json
{ "Q": { "webserver": { "accel": { "passthrough": true } } } }
```

PHP checks permissions, sends `X-Accel-Redirect: /files/report.pdf`, and
nginx serves the file with `sendfile()` — zero-copy, never enters PHP memory.

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
php qbixserver.php
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

## 🧹 What it replaces

| You used to need | Now |
|---|---|
| **nginx** | Built in — static files, keep-alive, ETag, gzip, directory listing |
| **php-fpm** | Built in — fork-per-request from preloaded parent, no framework bootstrap |
| **Node.js + socket.io** | Built in — Socket.IO v5 wire protocol, per-connection workers |
| **Redis** (pub/sub, shared state) | Built in — room processes with shared PHP arrays |
| **certbot + cron** | Built in — ACME auto-renewal, hot-swaps certs, no restart |
| **Supervisor / systemd** | Built in — graceful shutdown, `--stop`, `--reload`, PID file |
| **Cron** (scheduled tasks) | Built in — scheduler with `every`, `times`, `weekdays`, `monthdays` |
| **Separate WebSocket server** | Built in — same handlers, same deploy, same process |
| **Load balancer config** | Works behind nginx/HAProxy/Cloudflare, or standalone |
| **Process manager** | Built in — request timeout, worker tracking, crash isolation |

One PHP file. One command. One port.

```bash
php qbixserver.php  # HTTPS on 443 auto-starts if certs exist
```

Static files, PHP execution, WebSocket, rooms, Socket.IO, TLS, auto-renewing
certs, scheduled tasks, live dashboard, hot reload — all from a single process
that fits in a 300KB PHAR.

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
php qbixserver.php --port=80
```

Open [http://localhost](http://localhost). That's it.

```bash
# Or serve an existing directory
php qbixserver.php --root=/var/www/mysite

# Or use the PHAR (single file, ~280KB)
php bin/qbixserver.phar --root=./public
```

---

## 📊 Performance

Benchmarked on a single-core container, PHP 8.3, Ubuntu 24. Each server ran
alone. Zero failed requests.

### Head-to-head (4 workers each)

| | Swoole | fpm | Qbix octane | FrankenPHP |
|---|---|---|---|---|
| **CPU ~2ms** (c=4) | **483** | 469 | 439 | 350 |
| **50ms I/O** (c=40) | 78 | 78 | **77** | 39 |
| **Static 13KB** (c=100) | 26,974 | 50,742 | **18,311** | 10,038 |
| **Memory / worker** | ~42 MB | ~42 MB | **~200 KB** (typical) | ~42 MB |

Octane matches Swoole and fpm on I/O workloads — within 7% on CPU. The
advantage is memory: on the same 200MB budget, octane runs 40 workers where
the others run 4.

### Same memory budget (200MB, 50ms I/O, c=40)

| | fpm (4w × 50MB) | octane (40w × 5MB) |
|---|---|---|
| req/s | 78 | **520** |
| p50 | 505ms | **56ms** |

**6.7× throughput, 9× lower latency** — same RAM.

See [BENCHMARKS.md](docs/BENCHMARKS.md) for full methodology and
[reset.md](docs/reset.md) for what gets reset between requests.

---

## 🏎️ Why Not php-fpm?

php-fpm is the standard PHP execution model, and it has a real throughput
advantage: persistent workers handle requests without forking, so a lightweight
API call that takes 5ms of actual work costs 5ms total. The same call under Qbix
Server costs ~12ms (5ms work + 7ms fork overhead).

**The trade-off is throughput for capacity and safety.**

```
php-fpm:
  Request → nginx → FastCGI socket → php-fpm worker
                                       ↓
                                     Load PHP
                                     Include autoloader
                                     Boot framework (10–50ms)
                                     Run your code
                                     Send response
                                       ↓
                                     Worker resets (or leaks state)

Qbix Server:
  Startup:
    Load PHP → include autoloader → load ALL classes → parse config

  Request:
    pcntl_fork() → child inherits everything via COW
                   → run your code → die (no state leaks)
```

The key insight is **fork after preload**. Unix `fork()` uses copy-on-write, so
forked workers share the parent's memory pages for all those preloaded classes.
Each worker starts with ~30MB shared (read-only) and allocates only the
per-request data.

### The numbers, honestly

| | php-fpm | Qbix fork | Qbix octane |
|---|---|---|---|
| Per-request throughput (4w) | 469 req/s | 120 req/s | **439 req/s** |
| Memory per worker | ~42MB | ~200KB (COW, typical) | ~200KB (COW, typical) |
| Concurrent capacity (1GB) | ~24 workers | ~5,000 forks | **~5,000 workers** |
| State isolation | Statics leak | Process dies | **Snapshot reset** |
| Per-request overhead | 0.002ms | 8ms (fork) | **0.05ms** (restore) |
| Same budget, 50ms I/O | 78 req/s (4w) | 115 req/s | **520 req/s** (40w) |

With octane mode, Qbix matches fpm's per-request speed while using 1/10th the
memory and resetting statics between requests (which fpm doesn't do). Fork
mode is still available for scripts that need bulletproof isolation.

> **Important:** Database connections must NOT be opened before `fork()`. A TCP
> connection is a single file descriptor — two processes writing to the same
> socket would interleave packets and corrupt the protocol. Each forked child
> opens its own connection. This is the same model as php-fpm (one connection
> per request) and is what connection poolers like PgBouncer or ProxySQL are
> designed for.

### Why it's actually better in practice

The throughput gap narrows significantly for real applications. A framework like
Qbix with 20 loaded plugins spends 10–50ms on bootstrap per fpm request — time
that Qbix Server eliminates entirely because the forked child inherits all
loaded classes. For a request that does 5ms of actual work:

```
nginx + fpm:    30ms bootstrap + 5ms work           =  35ms  (29 req/s per worker)
Qbix Server:    7ms fork      + 0ms bootstrap + 5ms =  12ms  (83 req/s per fork)
```

The heavier the framework, the more the fork model catches up. And the memory
savings let you run 100× more of them simultaneously.

---

## ⚖️ vs FrankenPHP and Swoole

If you're looking beyond php-fpm, you've probably seen FrankenPHP and Swoole. Here's how they compare:

| | FrankenPHP | Swoole | Qbix Server |
|---|---|---|---|
| **Language** | Go + C (embeds PHP) | C extension for PHP | Pure PHP |
| **Install** | Download Go binary or Docker | `pecl install swoole` (compiles C) | `php qbixserver.php` — nothing to install |
| **Architecture** | Worker mode (persistent) | Coroutine-based (persistent) | Two modes: fork-per-request (shared-nothing) or octane (persistent workers with snapshot restore) |
| **State leaks** | ⚠️ Possible — workers persist, must audit statics | ⚠️ Possible — must manage globals carefully | ✅ Fork mode: impossible (process dies). Octane: snapshot restores all statics/globals/superglobals between requests |
| **PHP compatibility** | Most code works, some edge cases | Many extensions incompatible, blocking I/O breaks coroutines | ✅ 100% — standard PHP, nothing unusual |
| **Memory safety** | Go runtime + PHP = complex interaction | C extension = segfault risk | PHP only = memory-safe by default |
| **Access control** | No X-Accel-Redirect equivalent | Manual implementation | ✅ Built-in X-Accel-Redirect |
| **Component cache** | No | No | ✅ X-Cache-Tree — sub-page invalidation |
| **Early hints / 103** | ✅ Yes | No | Via amphp |
| **HTTP/2** | ✅ Built-in (Caddy) | ✅ Built-in | ✅ Via amphp |
| **WebSocket** | Via Mercure | ✅ Built-in | ✅ Built-in |
| **PHP throughput** (4w, CPU) | 350 req/s | **483 req/s** | 439 req/s (octane) |
| **PHP throughput** (same 200MB, I/O) | 78 req/s | 78 req/s | **520 req/s** (40w octane) |
| **Static throughput** | 10,038 req/s | 26,974 req/s | **18,311 req/s** |
| **Concurrent capacity** | Limited by worker memory | Limited by worker memory | **100–300× more** (COW, measured) |

### The state isolation advantage

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

Qbix Server offers two modes, both of which avoid this:

**Fork mode (default):** each request forks from the preloaded parent, inherits
loaded classes and config (read-only, shared via COW), runs in its own process,
and dies when done. No state leaks. No audit needed. Your existing PHP code works
exactly as it does on php-fpm.

**Octane mode (`--workers=N`):** persistent workers handle multiple requests, but
a snapshot restore resets all class statics, `$GLOBALS`, `$_GET`, `$_POST`,
`$_COOKIE`, `$_SERVER`, `$_FILES`, response headers, and error state between
every request — verified by 13 dedicated snapshot isolation tests. The code above
would work correctly because `$cached` is restored to `null` between requests.
See [Octane Mode](#-octane-mode---workersn) for the full reset table.

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
php qbixserver.php  # done
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
| **Rooms** | Process-per-room shared state, tick timers, broadcasting. Members join/leave, room state in PHP arrays. |
| **Images** | On-the-fly resize (`?w=300`), auto format conversion (JPEG→WebP), `Save-Data` support, disk-cached with LRU eviction |
| **Directory listing** | Grid/list toggle, lazy thumbnails, lightbox with download-at-size, multi-select, bulk ZIP download. Overridable with `listing.php` |
| **Q.js frontend** | Bundled Q.min.js (187KB), jQuery shim (5.7KB), minimal Handlebars (6.1KB), 43 UI tools, 107 languages + translations — served at `/Q/plugins/` |
| **Dashboard** | Live at `/Q/dashboard` — request log, throughput, top paths, response times, memory, WebSocket connections, active rooms |
| **Health check** | JSON at `/Q/health` — stats for load balancers and monitoring |
| **Control panel** | Password-protected at `/Q/panel` — six tabs: Apps, Scripts, Plugins, Playground, System, Servers |
| **Deploy** | `--deploy=production` CLI or one-click from Panel. rsync to remote servers via SSH. |
| **Federation** | `Q::event()` forwarding between servers. HMAC-signed (Platform-compatible), per-message loop prevention, fingerprint pinning |
| **API discovery** | `/.well-known/openapi.json` (Swagger/Postman), `/.well-known/mcp.json` (Claude/AI tools), `/.well-known/qbix.json` (server-to-server) |
| **PHPDoc→API specs** | Handlers auto-documented from PHPDoc and YUIDoc blocks. `@private`/`@internal` to hide. |
| **OpenClaiming** | Auto-generated ES256-signed server identity. Claims-in-folders: JSON templates auto-signed, PHP dynamic, pre-signed static. OCP wire format. |
| **Shortcuts** | Windows `.lnk` files and Mac aliases resolved transparently. Platform plugin symlinks just work. |
| **Self-signed certs** | Auto-generated P-256 key pair + TLS cert for server identity and inter-server trust |
| **Rate limiting** | Per-IP with configurable windows and burst limits |
| **Security** | Path traversal blocked, dotfiles blocked (except `.well-known/`), 431 for oversized headers, upload limits enforced |
| **Graceful shutdown** | SIGTERM/SIGINT drain in-flight requests before closing |
| **TLS** | Optional HTTPS with auto-certbot or manual certs |
| **Logging** | Colored terminal output + file-based access logs |
| **Access control** | X-Accel-Redirect support — PHP enforces access, server serves the file |
| **Component cache** | X-Cache-Tree headers — invalidate parts of a page, not the whole thing |
| **Platform compatible** | `Q_Utils::sign()`, `Q::event()`, handler conventions, config paths — all match Qbix Platform. Upgrade without code changes. |

---

## 🔒 Server Headers — What Your PHP Can Send

Qbix Server understands special response headers from your PHP scripts. These are
the same headers nginx understands (like `X-Accel-Redirect`) plus new ones for
component-level caching. Your PHP sends them with `Q_Response::header()`, the server acts on them.

> **Use `Q_Response::header()`, not PHP's `header()`.** The server runs PHP in
> the CLI SAPI, where the built-in `header()` and `http_response_code()` are
> silently discarded. `Q_Response::header()`, `Q::header()`, and
> `Q_WebServer_State::header()` all work in both standalone and `--app` mode.
> See [Setting headers, status codes and cookies](#setting-headers-status-codes-and-cookies)
> for the full table.

### Quick reference

| Header | What it does | Example |
|---|---|---|
| `Cache-Control` | Server caches the response, serves without running PHP | `Q_Response::header('Cache-Control: public, max-age=300');` |
| `X-Accel-Redirect` | Server streams a file after PHP checks access | `Q_Response::header('X-Accel-Redirect: /uploads/private/doc.pdf');` |
| `X-Cache-Tree` | Registers page components with content hashes | `Q_Response::header('X-Cache-Tree: ' . json_encode([...]));` |
| `X-Cache-Deps` | Maps components to data dependency keys | `Q_Response::header('X-Cache-Deps: ' . json_encode([...]));` |
| `X-Cache-Invalidate` | Marks dependency keys as stale | `Q_Response::header('X-Cache-Invalidate: ' . json_encode([...]));` |
| `X-Cache-Stale` | Invalidates cached pages containing these components | `Q_Response::header('X-Cache-Stale: feed,sidebar');` |

All of these use `Q_Response::header()` instead of PHP's `header()`. This is because
the server runs in CLI SAPI where `header()` calls are silently discarded —
same as FrankenPHP worker mode and Workerman. `Q_Response::header()` has the same
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
Q_Response::header("X-Accel-Redirect: /files/private/{$fileId}");
Q_Response::header("Content-Disposition: attachment; filename=\"document.pdf\"");
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
Q_Response::header('Cache-Control: public, max-age=300');

echo renderFeed();
```

```php
<?php
// web/profile.php — cached, but revalidate with ETag

// The server generates an ETag from the response body.
// Browsers send If-None-Match on next request.
// Server returns 304 (no body) if nothing changed.
Q_Response::header('Cache-Control: public, max-age=0, must-revalidate');

echo renderProfile($userId);
```

```php
<?php
// web/admin.php — never cache

Q_Response::header('Cache-Control: no-store');

echo renderAdminPanel();
```

### Component-level cache invalidation

Most caching systems cache whole pages. When anything changes, you throw away the
entire page and re-render everything. Qbix Server tracks which data each page
depends on, so when data changes, only the affected pages are invalidated — not
every page on the site.

**Step 1: Register components when rendering a page**

When PHP renders a page, it tells the server what data the page depends on.
The server hashes each component and maps them to dependency keys. This lets
the server know exactly which pages to invalidate when specific data changes.

```php
<?php
// web/community.php — a page with three components

$feedHtml    = renderFeed($communityId);
$sidebarHtml = renderSidebar($communityId);
$membersHtml = renderMembers($communityId);

// Tell the server about the component tree and what data each depends on
Q_Response::header('X-Cache-Tree: ' . json_encode([
    'l' => [
        'feed'    => md5($feedHtml),
        'sidebar' => md5($sidebarHtml),
        'members' => md5($membersHtml),
    ]
]));

Q_Response::header('X-Cache-Deps: ' . json_encode([
    'feed'    => ["community/{$communityId}/feed"],
    'sidebar' => ["community/{$communityId}/about"],
    'members' => ["community/{$communityId}/participants"],
]));

Q_Response::header('Cache-Control: public, max-age=300');
echo $feedHtml . $sidebarHtml . $membersHtml;
```

**Step 2: Invalidate when data changes**

```php
<?php
// web/post.php — user posts to the feed
saveNewPost($communityId, $content);

// Tell the server which dependency key changed
Q_Response::header('X-Cache-Invalidate: ' . json_encode([
    "community/{$communityId}/feed"
]));

// The server walks its dependency graph:
//   community/123/feed → component 'feed' → page /community/123
// The FULL page cache for /community/123 is invalidated.
// Other pages (e.g. /community/456) stay cached.
// Next request to /community/123 → cache miss → PHP re-renders the full page.

echo json_encode(['ok' => true]);
```

The server tracks which pages depend on which data keys. When a dependency
key is invalidated, it finds exactly which pages are affected and removes
them from the cache. Pages with no stale dependencies continue serving from
the in-memory cache without hitting PHP.

### Even more powerful with Qbix Platform

These headers work with `Q_Response::header()` calls as shown above. But with the
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
Q_Response::header('Content-Type: application/json');
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
    Q_Response::header('Content-Type: application/json');
    echo json_encode(MyApp\Users::recent(20));
}
```

### What happens per request

```
Browser: GET /api/users
  → Parent forks child process (COW — ~200KB delta for typical handlers)
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
Browser: connects to ws://localhost/ws
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
const socket = io('http://localhost', {transports: ['websocket']});

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
const socket = io('http://localhost', {transports: ['websocket']});

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
| `$socket->disconnect()` | Close this connection |
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
var socket = io('http://localhost', {transports: ['websocket']});
socket.emit('chat/message', {text: 'hello'});
socket.on('chat/message', function(data) { console.log(data); });
</script>
```

Or use the npm package:

```javascript
import { io } from 'socket.io-client';
const socket = io('http://localhost', {transports: ['websocket']});
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
const ws = new WebSocket('ws://localhost/ws');
ws.send(JSON.stringify({event: 'chat/message', data: {text: 'hello'}}));
ws.send(JSON.stringify({event: 'chat/message', data: {text: 'hi'}, ack: 1}));
```

```python
# Any language — just JSON over WebSocket
import websocket, json
ws = websocket.WebSocket()
ws.connect("ws://localhost/ws")
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
const main = io('http://localhost');          // default /
const chat = io('http://localhost/chat');     // /chat
const admin = io('http://localhost/admin');   // /admin

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
const socket = io('http://localhost', {transports: ['websocket']});

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

### Autoloading

Classes in `classes/` are autoloaded by default — `Chess\Game` or `Chess_Game`
both resolve to `classes/Chess/Game.php`. No config needed.

For PSR-4 compliant layouts, configure the namespace mapping:

```json
{
    "Q": {
        "autoload": {
            "psr-4": {
                "App\\": "src/",
                "App\\Models\\": "src/Models/"
            }
        }
    }
}
```

`App\Http\Controller` → `src/Http/Controller.php`. Underscores are literal
(PSR-4 compliant). Paths are relative to the project root.

If you use Composer, its autoloader is loaded automatically —
`vendor/autoload.php` is included at startup if it exists. Composer's own
PSR-4, classmap, and files entries all work. The `Q.autoload` config and
Composer coexist: Q's autoloader runs first, Composer catches anything it
misses.

The resolution order:

1. `Q.autoload.psr-4` — config-driven PSR-4 mappings
2. `Q.autoload.psr-0` — config-driven PSR-0 mappings (underscores = separators)
3. Internal Q classes — `src/Q/*.php`
4. Project `classes/` directory — the Qbix convention (both `\` and `_` as separators)
5. Composer — `vendor/autoload.php` (if present)

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
const socket = io('http://localhost', {transports: ['websocket']});

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
php qbixserver.php
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
    Q_Response::header('Content-Type: application/json');
    echo json_encode(MyApp\Users::list($_GET));
}
```

```php
<?php
// handlers/api/users/post.php — handles POST /api/users
function api_users_post(&$params, &$result) {
    $user = MyApp\Users::create($_POST);
    http_response_code(201);
    Q_Response::header('Content-Type: application/json');
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
    Q_Response::header('Content-Type: text/html');
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

Q_Response::header('Content-Type: application/json');
echo json_encode($feed);
```

### The `Q` class — available in every script

The server injects the `Q` class into every PHP script automatically. Here's
what you get:

| Method | What it does |
|---|---|
| `Q::event($name, $params)` | Fire an event — runs the handler from `handlers/` |
| `Q::canHandle($name)` | Check if a handler exists for an event |
| `Q_Response::header($str, $replace, $code)` | Set a response header (use instead of `header()`) |
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

### Why `Q_Response::header()` instead of `header()`?

The server runs PHP in CLI SAPI (same as FrankenPHP worker mode and Workerman).
PHP's built-in `header()` is silently discarded in CLI mode. `Q_Response::header()` has
the exact same signature but captures headers so the server can send them:

```php
Q_Response::header('Content-Type: application/json');   // same as header() but works
Q_Response::header('HTTP/1.1 201 Created');             // status line
Q_Response::code(201);                                  // or set status directly

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
php qbixserver.php --workers=4
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

Q_Response::header('Content-Type: application/json');
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

For higher throughput, switch to [Octane Mode](#-octane-mode---workersn) with
`--workers=N`. Octane reuses workers across requests but resets all statics,
globals, superglobals, and response headers between requests via a snapshot
restore (~0.05ms). See that section for what resets, what doesn't, and how to
write scripts that work in both modes.

### How PHP requests are handled

**Fork mode (default, no `--workers`):** On Linux and macOS (where `pcntl_fork`
is available), every PHP request is forked — the server forks a child, the child
handles the request and exits, the parent continues serving. This means:

- `exit()` / `die()` in a script only kills the child — the server survives
- Long-running scripts don't block static file serving
- Each request is truly isolated

**Octane mode (`--workers=N`):** Persistent workers handle requests in a loop
with snapshot restore between them. See [Octane Mode](#-octane-mode---workersn)
for what resets, what persists, and how to write scripts for both modes.

The `--workers=N` flag pre-forks N idle workers for faster dispatch (no fork
latency per request). Without it, the server forks on demand. Both modes are
shared-nothing.

**Windows** doesn't have `pcntl_fork`, so PHP scripts run in a subprocess
via `proc_open`. This is safe — `exit()` can't crash the server — but each
subprocess starts a fresh PHP interpreter (~50ms), so you don't get the
preload speed benefit. Static files, WebSocket, caching, and everything
else work identically. Good for development; use Linux/macOS for the full
100–300× concurrent capacity (measured) advantage.

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
| `dashboard` | (enabled) | Set to `false` to disable `/Q/dashboard`, `/Q/health`, and `/Q/ws` entirely |
| `dashboard.token` | (none) | When set, dashboard requires `?token=VALUE` in the URL |
| `autoload.psr-4` | `{}` | PSR-4 namespace mappings: `{"App\\": "src/"}` |
| `autoload.psr-0` | `{}` | PSR-0 prefix mappings: `{"Legacy_": "vendor/"}` |
| `socket.io` | `"/socket.io"` | Socket.IO endpoint. Protocol detection + client JS at `{path}/socket.io.js`. `false` to disable. |
| `socket.js` | `"/Q/socket.js"` | Path to serve the minimal bare-WebSocket client (3KB). `false` to disable. |
| `app` | `""` | App name — prefixes handler function names (e.g. `"Chess"` → `Chess_chat_message()`) |
| `webserver.fallback` | null | Catch-all: `"index.html"`, `{"handler":"app/notfound"}`, or `{"file":"404.html"}` |
| `webserver.hotReload` | `false` | Watch `classes/`, `handlers/`, `config/` for changes. Auto-restarts on class/config changes. |
| `webserver.cgi.patterns` | [] | Regex patterns for scripts that use php-cgi (legacy compatibility) |
| `webserver.cgi.binary` | auto | Path to php-cgi binary (auto-detected if not set) |

### Virtual hosts

Serve multiple domains from one server. Each host can have its own document root:

```json
{
    "Q": {
        "webserver": {
            "hosts": {
                "example.com": {
                    "root": "/var/www/example/web"
                },
                "api.example.com": {
                    "root": "/var/www/api/web"
                },
                "staging.example.com": {
                    "root": "/var/www/staging/web"
                }
            }
        }
    }
}
```

The `Host` header selects the root. Requests for unconfigured hosts use the
default `--root` directory. WebSocket, rooms, handlers, and static files all
respect the per-host root.

### Hot reload

Watch `classes/`, `handlers/`, and `config/` for file changes:

```bash
php qbixserver.php --hotreload
```

Or via config:

```json
{
    "Q": {
        "webserver": {
            "hotReload": true
        }
    }
}
```

Handler changes take effect immediately — handlers are lazy-loaded, so the
next request or connection picks up the new code. Class or config changes
trigger a graceful restart (the server re-execs itself with the same arguments).

Changes are logged to stderr:

```
14:32:07 hot-reload: ~ handlers/chat/message.php
14:32:09 hot-reload: + classes/MyApp/NewFeature.php
14:32:09 hot-reload: restarting server...
```

Polls every 2 seconds. Recommended for development.

Even without `--hotreload`, handler changes take effect naturally: HTTP
requests fork fresh and load handlers on demand, so the next request gets the
new file. WebSocket connections and rooms keep the old code for their lifetime
— new connections pick up the change. A natural rolling deploy with no
interruption. The `--hotreload` flag adds automatic restart for class and
config changes, which are preloaded in the parent process.

If `Q.handlers.preload` is `true` (production mode), handlers are also loaded
in the parent — use `--reload` to pick up handler changes in that case.

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
                    "/wp-admin/.*\\.php$",
                    "/wp-login\\.php$",
                    "/legacy/.*\\.php$"
                ]
            }
        }
    }
}
```

The tradeoff: CGI mode starts a fresh PHP interpreter per request (~50ms), so you
don't get the preload speed benefit. Static files, caching, and everything else
still work at full speed. Use this for third-party code you can't modify — your
own code should use `Q_Response::header()` and the fork path for 100–300× concurrent capacity (measured).

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
                "patterns": ["\.php$"]
            },
            "fallback": "index.php"
        }
    }
}
```

The pattern `\.php$` sends all PHP files through `php-cgi`. The fallback
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
                "patterns": ["\.php$"]
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
{ "Q": { "webserver": { "cgi": { "patterns": ["\.php$"] } } } }
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
                    "/admin/.*\.php$",
                    "/legacy/.*\.php$"
                ]
            }
        }
    }
}
```

Only specific paths use CGI. New code and simple scripts use fork mode
(100–300× concurrent capacity (measured)). Legacy code that calls `header()` directly stays
in CGI mode.

**Option 3: Find-replace (one-time effort, full performance)**

In your PHP files, replace:
```
header(       →  Q_Response::header(
setcookie(    →  Q_Response::setCookie(
```

Two find-replaces. Your code now uses fork mode everywhere — 30× concurrent capacity
capacity, preloaded classes, shared-nothing safety.

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
php qbixserver.php
```

### 2. PHAR — single ~280KB file (needs PHP)

```bash
php bin/qbixserver.phar --port=80

# Or make it executable
chmod +x bin/qbixserver.phar
./bin/qbixserver.phar --port=80
```

### 3. Static binary — no PHP needed

```bash
# Download from GitHub Releases
chmod +x qbixserver-linux-x86_64
./qbixserver-linux-x86_64 --port=80
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
php qbixserver.php --app=/path/to/myapp --port=80
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
Workers are forked after class preloading, so they share the base memory
footprint via copy-on-write pages.

**Static files** are served at ~20K req/s (pure PHP, in-memory cache, single
`fwrite`). nginx is ~2.5× faster (50K req/s) because it uses `sendfile()`
(kernel-space file→socket copy) and compiled C. For production, put nginx or a
CDN in front for static files and let Qbix Server handle PHP execution,
WebSocket, and access-controlled file serving.

---

## 📊 Live Dashboard

Open `http://localhost/Q/dashboard` in your browser for a real-time server
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

## ⚙️ Control Panel

Password-protected admin panel at `/Q/panel`. First visit sets the password.

**Apps tab** — discovers sibling app directories (any folder with `web/` or
`config/app.json`). Create new apps, serve them (hot-switches the document
root), open in VS Code, run configure scripts. Editable apps directory path.

**Scripts tab** — list and run PHP scripts from `scripts/Q/` (configure,
install, translate, etc.)

**Plugins tab** — reads the app's `config/app.json` for declared plugins,
`local/plugins.json` for installed versions, and scans the Platform's
`plugins/` directory. Shows version, dependencies, and DB connections for
each.

**Playground tab** — PHP REPL with all Q classes preloaded. Write code, hit
Run (or Ctrl+Enter), see output. Sandboxed in a forked process with disabled
filesystem writes, no network, 32MB memory limit, 5 second timeout.

**System tab** — PHP version, OS, extensions, memory limit. One-click
Platform install: clones `github.com/Qbix/Platform`, runs
`git submodule update --recursive`, sets up `local/paths.json`.

The panel is restricted to localhost by default. Set `Q.panel.remote: true`
in config to allow remote access.

---

## 🚀 Deploy

Push your app to a remote server with one command:

```bash
./qbixserver.php --deploy=production
```

Configure targets in `config/deploy.json`:

```json
{
    "targets": {
        "production": {
            "host": "myserver.com",
            "user": "deploy",
            "path": "/var/www/myapp",
            "key": "~/.ssh/deploy_key",
            "dirs": ["web", "handlers", "classes", "config"]
        }
    }
}
```

The command rsyncs each directory to the remote server. If the remote runs
Qbix Server, it can be configured to hot-reload on deploy.

Servers can also be managed from the Panel's **Servers** tab — add, deploy,
and remove remote servers through the browser.

---

## 🔗 Federation

Qbix servers can forward events to each other. Any `Q::event()` call can be
handled locally or routed to a remote server — same dispatch path, same
handler signature, transparent to the app code.

### How it works

**1. Server identity.** On first run, each server generates a self-signed
certificate and stores it in `local/server.crt`. The SHA-256 fingerprint
is the server's identity — like SSH `known_hosts`, no certificate authority
needed.

**2. Discovery.** Every server exposes `/.well-known/qbix.json`:

```json
{
    "server": "Qbix Server",
    "version": "1.0.0",
    "fingerprint": "11cf953679b80d04...",
    "endpoints": {"event": "/Q/event", "health": "/Q/health"},
    "plugins": [{"name": "Users", "version": "1.0"}]
}
```

**3. Event forwarding.** Configure which events route to which server:

```json
{
    "Q": {
        "handlersRemote": {
            "Users/login": "https://auth.example.com",
            "Streams/stream": "https://streams.example.com"
        }
    }
}
```

When Server A receives a `Users/login` event, it forwards it to
`auth.example.com/Q/event` via HMAC-signed POST. The receiving server
verifies the signature, dispatches the event locally, and returns the result.
Loop prevention is built in — a forwarded event is never re-forwarded.

**4. Signing.** All inter-server requests are signed using `Q_Utils::sign()`,
which is compatible with the Qbix Platform's signing. The signature uses
HMAC-SHA1 over recursively key-sorted, URL-encoded data — the same format
the Platform uses. Servers upgrading to the full Platform keep working
without changes.

### Trust levels

Servers authenticate each other at three levels:

- **Pinned peer** — fingerprint stored in config. Events accepted, signature
  verified. For known partners.
- **Owned server** — shared `Q.internal.secret`. Full trust, can forward
  user sessions. For your own infrastructure.
- **Public** — no fingerprint, just HTTPS. Read-only access via
  `/.well-known/qbix.json`. For open APIs.

### Logging

Access and error logs with buffered writes, daily rotation, gzip archiving, and
retention management. Enable by adding a `log` section to config:

```json
{
    "Q": {
        "webserver": {
            "log": {
                "dir": "logs",
                "access": true,
                "error": true,
                "bufferSize": 65536,
                "flushInterval": 1,
                "maxSize": 52428800,
                "archiveAfterDays": 2,
                "deleteAfterDays": 30
            }
        }
    }
}
```

| Setting | Default | Description |
|---|---|---|
| `dir` | `logs/` (relative to app root) | Log directory. Absolute paths work too. |
| `access` | `true` | Write access.log in combined format + response time. |
| `error` | `true` | Write error.log with timestamps. |
| `bufferSize` | `65536` (64 KB) | Buffer log lines in memory, flush when full. 0 = write every line immediately. |
| `flushInterval` | `1` | Seconds between timer flushes. Buffer contents hit disk at most this late. |
| `maxSize` | `52428800` (50 MB) | Rotate mid-day if a log file exceeds this. |
| `archiveAfterDays` | `2` | Compress rotated logs to .gz after this many days. |
| `deleteAfterDays` | `30` | Delete archived logs older than this. |

**Buffered writes** accumulate log lines in memory and flush them in a single
`write()` syscall — either when the buffer fills or on the timer. This cuts the
per-request overhead roughly in half vs writing every line:

| Mode | Throughput | Overhead vs no logging |
|---|---|---|
| No logging | 9,276 req/s | — |
| Buffered (64KB, 1s) | 8,671 req/s | 6.5% |
| Unbuffered | 8,186 req/s | 11.7% |

Error lines always flush immediately (they're rare and you want them on disk
before a crash). Set `access` to `false` to skip access logging entirely.

**Rotation:** Logs rotate daily at midnight. The current day's log is always
`access.log` and `error.log`. Yesterday's becomes `access.2026-08-11.log`. After
2 days that file is gzipped to `access.2026-08-11.log.gz`. After 30 days it's
deleted. If a log exceeds 50 MB mid-day, it rotates early with a timestamp suffix.

Access log format (nginx-compatible combined + response time):

```
192.168.1.1 - - [11/Aug/2026:14:30:00 +0000] "GET /api/users HTTP/1.1" 200 1234 "-" "Mozilla/5.0" 3.2ms
```

Errors also go to stderr, so `php qbixserver.php 2>err.log` works without config.

### Configuration

```json
{
    "Q": {
        "internal": {
            "secret": "your-shared-secret-here"
        },
        "federation": {
            "advertise": true,
            "advertiseApps": false,
            "advertisePlugins": true,
            "requireKnownPeers": false,
            "peers": [
                {
                    "name": "auth-server",
                    "url": "https://auth.example.com",
                    "fingerprint": "11cf953679b80d04..."
                }
            ]
        }
    }
}
```

### Full-stack microservices

Each Qbix server is a complete, independent app server. Federation lets
you split your app across multiple servers without changing your code:

```
Server A (auth.example.com)     Server B (app.example.com)
├── Users plugin                ├── App handlers
├── handlers/Users/*            ├── handlers/MyApp/*
└── handles Users/ events       └── forwards Users/ → Server A
```

Server B's handlers call `Q::event('Users/login', $params)` as if Users
were installed locally. The server transparently forwards it to Server A,
gets the result, and returns it. The handler never knows the difference.

### Loop prevention

Every forwarded event carries a unique `_msgId`. Each server tracks seen
IDs in memory (1-hour TTL). If a message ripples through A→B→C→A, server A
recognizes the ID and drops it. This is per-message, not per-peer — works
for any topology.

### Signing

Inter-server requests are signed two ways, both compatible with the
Qbix Platform:

- **Body signature** — `Q_Utils::sign()` adds a `Q.sig` field using
  HMAC-SHA1 over recursively key-sorted data. Same format the Platform uses.
- **Header signature** — `X-Q-HMAC` header over the raw JSON body. Same
  as the Platform's curl-based `handleUsingRemote`.

The receiving server accepts either. A Platform server and a standalone
Qbix Server can forward events to each other without configuration changes.

---

## 🔍 API Discovery

The server auto-generates three discovery endpoints from its actual
handlers and configuration. No manual documentation needed — add a
handler file, the specs update automatically.

### `/.well-known/qbix.json` — Server manifest

Qbix-native discovery. Returns the server's identity, fingerprint,
installed plugins, and links to other specs.

```json
{
    "server": "Qbix Server",
    "version": "1.0.0",
    "fingerprint": "4af468e461fc2022...",
    "endpoints": {
        "event": "/Q/event",
        "health": "/Q/health",
        "openapi": "/.well-known/openapi.json",
        "mcp": "/.well-known/mcp.json",
        "websocket": "/Q/ws"
    },
    "plugins": [
        {"name": "Users", "version": "1.0"}
    ]
}
```

Other Qbix servers use this for federation — pin the fingerprint, discover
endpoints, forward events.

### `/.well-known/openapi.json` — OpenAPI 3.1

Standard API spec compatible with Swagger UI, Postman, Redoc, Insomnia,
and any OpenAPI-compatible tool.

- Paste the URL into **Postman** → Import → complete API documentation
- Point **Swagger UI** at it → interactive API explorer
- Feed it to **Redoc** → polished reference docs

The spec includes built-in endpoints (`/Q/health`, `/Q/event`) and
auto-discovers handlers from the `handlers/` directory. Each handler
becomes a documented path with its event name, tags, and schema.

### `/.well-known/mcp.json` — MCP (Model Context Protocol)

Lets AI tools (Claude, GPT, Cursor, etc.) discover and call this server's
APIs as tools. Each handler becomes an MCP tool:

```json
{
    "tools": [
        {"name": "health", "description": "Check server health and uptime"},
        {"name": "event", "description": "Dispatch a Q::event() on this server"},
        {"name": "chat_join", "description": "Dispatch event: chat/join"},
        {"name": "chat_message", "description": "Dispatch event: chat/message"}
    ]
}
```

An AI assistant connected to your Qbix server can call your handlers
directly — no glue code, no adapters.

### Compatibility matrix

| Tool | Endpoint | How |
|---|---|---|
| Postman | `/.well-known/openapi.json` | Import → Collections |
| Swagger UI | `/.well-known/openapi.json` | Point URL → interactive docs |
| Redoc | `/.well-known/openapi.json` | Static reference docs |
| Claude / AI | `/.well-known/mcp.json` | MCP server discovery |
| Other Qbix | `/.well-known/qbix.json` | Federation + fingerprint pinning |
| curl | `/Q/health` | `curl https://host/Q/health` |
| Monitoring | `/Q/health` | Uptime checks, Prometheus, etc. |

All three endpoints are configurable. Set `Q.federation.advertise: false`
to disable, or selectively hide apps and plugins.

### `/.well-known/openclaiming/{hostname}/server.json` — OpenClaiming

Every Qbix server auto-generates a signed [OpenClaim](https://openclaiming.org)
for its identity. The claim is signed with ES256 (P-256) and verifiable by
anyone with the public key.

```json
{
    "ocp": 1,
    "iss": "myserver.com/server",
    "stm": {
        "type": "server",
        "software": "Qbix Server",
        "version": "1.0.0",
        "fingerprint": "4af468e461fc2022...",
        "endpoints": {
            "event": "/Q/event",
            "health": "/Q/health",
            "openapi": "/.well-known/openapi.json",
            "mcp": "/.well-known/mcp.json"
        }
    },
    "key": ["data:key/es256;base64,MFkw..."],
    "sig": ["MEQCIH7C..."]
}
```

The key pair (P-256) is generated on first run and stored in `local/claim.pub`
and `local/claim.key`. The server's TLS fingerprint is embedded in the claim's
`stm.fingerprint` field, binding the two identity systems together.

### Publishing claims — files in folders

The same convention as handlers: drop a file in `claims/`, it becomes a
signed OpenClaim. Three sources, checked in priority order:

**1. PHP (dynamic, auto-signed)** — `claims/{domain}/{name}.php`

```php
<?php // claims/example.com/session.php
return array(
    'ocp' => 1,
    'iss' => 'example.com/server',
    'sub' => $params['userId'] ?? 'anonymous',
    'stm' => array('role' => 'viewer'),
    'exp' => time() + 3600,
);
```

Evaluated per-request. The server adds `key[]` and `sig[]` automatically.
Served at `/.well-known/openclaiming/example.com/session.json`.

**2. JSON template (static, auto-signed, cached)** — `claims/{domain}/{name}.json`

```json
{
    "ocp": 1,
    "iss": "example.com/server",
    "sub": "alice",
    "stm": {"role": "editor", "scope": "blog"}
}
```

Write the claim body without crypto fields. The server signs it with its
P-256 key and caches the result in `files/Q/cached/claims/`. When you
edit the template, the cache invalidates automatically (keyed by mtime).

**3. Pre-signed (as-is)** — `web/.well-known/openclaiming/{domain}/{name}.json`

For claims signed by someone else — a user's wallet, a partner server, a
smart contract. The server serves them unchanged.

### Signature format

All server-signed claims use OCP wire format:

- **Canonicalization:** RFC 8785 / JCS (sorted keys, `sig` stripped)
- **Algorithm:** ES256 (P-256 + SHA-256)
- **Signature encoding:** raw r||s (64 bytes, base64)
- **Key URI:** `data:key/es256;base64,{SPKI-DER}`

This is byte-compatible with the Qbix Platform's `Q_Crypto_OpenClaim::sign()`
and the JavaScript reference implementation's `Q.Crypto.OpenClaim.sign()`.
Claims signed by the server verify with either library, and vice versa.

### Multisig

If a template already has `key[]` and `sig[]` (partially signed by
another party), the server appends its own key and signature. Keys are
sorted lexicographically per OCP convention. This enables co-signed
claims where multiple authorities attest to the same statement.

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
the full 100–300× concurrent capacity (measured) advantage, use Linux or macOS (or WSL).

---

## ⚡ Octane Mode (`--workers=N`)

Persistent workers with automatic state reset. Combines fpm's throughput with
fork-per-request's memory isolation.

```bash
php qbixserver.php --app=/path/to/myapp --workers=40
```

The parent preloads your framework (classes, config, routes, autoloader), takes
a snapshot of every static property on every user-defined class, then forks N
workers. Each worker handles requests in a loop. Between requests, the snapshot
is restored — all statics, globals, superglobals, and response state are reset
to their preloaded values. Cost: ~0.05ms, vs ~8ms for a full fork.

### What gets reset between requests

| Category | Reset method | Cost |
|---|---|---|
| Class static properties | `ReflectionProperty::setValue()` from snapshot | 0.05ms |
| `$GLOBALS` (user-defined) | Removed entirely | < 0.01ms |
| `$_GET`, `$_POST`, `$_REQUEST` | Cleared, repopulated from new request | 0ms |
| `$_COOKIE` | Cleared, repopulated from `Cookie:` header | 0ms |
| `$_SERVER` | All `HTTP_*` keys stripped, core keys repopulated | 0ms |
| `$_FILES` | Cleared | 0ms |
| `php://input` | Rewired to new request body | 0ms |
| Response headers (`Q_WebServer_State`) | Cleared via `State::clear()` | 0ms |
| `error_get_last()` | Cleared via `error_clear_last()` | 0ms |
| DB transactions | `ROLLBACK` on all connections (safe no-op if none active) | 0ms |
| Output buffers | Non-removable buffer drained by `executeScript()` | 0ms |

After reset, the next request sees exactly the same state as the first
request this worker ever handled — the same static values, the same empty
globals, the same clean superglobals. Secrets from request A (cookies,
Authorization headers, POST passwords, session tokens) are guaranteed
invisible to request B.

### What does NOT reset

These are inherent PHP limitations, not something the snapshot can work around:

| Category | Why | Mitigation |
|---|---|---|
| **Class declarations** | PHP has no `unclass()` — once a class is loaded, it stays in the class table for the lifetime of the process | Guard inline classes with `class_exists()` (see below) |
| **`require`/`include` state** | A file included once stays in `get_included_files()` | Use `require_once` — it's already idempotent |
| **C extension internals** | Redis connections, libcurl handles, ext-level caches | Close/reset in destructors or shutdown functions |
| **Closures capturing references** | A closure that captured `&$static` holds a live reference that bypasses the snapshot | Rare in practice; avoid capturing statics by reference |
| **File descriptors** | An opened file handle persists in the process | Close file handles when done — same as fpm |

For anything the snapshot can't reach, `maxRequests` (default 1000) recycles
the worker after N requests — the process exits and a clean one is forked.
This is the same safety net fpm uses via `pm.max_requests`.

### ⚠️ The class declaration gotcha

This is the most common octane pitfall. In fork-per-request mode, every
request gets a fresh process, so inline class declarations always work:

```php
// WORKS in fork mode (process dies after each request)
// FATAL in octane mode on the SECOND request to this script:
//   "Cannot declare class Counter, because the name is already in use"
class Counter {
    public static $n = 0;
}
Counter::$n++;
echo Counter::$n;
```

The fix is a one-line guard:

```php
// WORKS in both modes
if (!class_exists('Counter', false)) {
    class Counter {
        public static $n = 0;
    }
}
Counter::$n++;  // always 1 — the snapshot resets $n to 0 between requests
echo Counter::$n;
```

The `false` parameter prevents autoloading — it checks only whether the
class is already declared in this process. On the first request, the class
is declared. On the second request in the same worker, `class_exists` returns
true and the declaration is skipped. The snapshot still resets `$n` to its
default value (`0`) between requests, so the counter always reads 1.

**Classes in `classes/` are fine.** The autoloader loads each class file
via `require_once`, which is already idempotent. This gotcha only affects
classes declared inline inside scripts (e.g. in `web/` PHP files or
handler files).

**The Qbix Platform is octane-safe.** All Platform classes live in `classes/`
and are autoloaded with `require_once`. Inline classes in handlers are rare
and already guarded.

### What it means in practice

On a 200MB memory budget with 50ms I/O workloads:

| Mode | Workers | req/s | p50 | Memory |
|---|---|---|---|---|
| fpm | 4 × 42MB | 78 | 505ms | 168MB |
| **octane** | **40 × ~200KB** | **520** | **56ms** | **~8MB** |

Octane uses **3× less RAM** for **6.7× more throughput** at **9× lower latency.**

### Auto-introspection

Scripts that define inline classes (with the `class_exists` guard) are handled
automatically. The snapshot system detects newly declared classes via
`get_declared_classes()` after each request and adds their static properties
to the snapshot using `ReflectionProperty::getDefaultValue()`. No manual
registration, no interface to implement. This is what makes it strictly
better than Laravel Octane's `ResetScope`, which depends on package authors
opting in.

### Writing octane-safe scripts

A few rules of thumb:

```php
// ✅ DO: guard inline class declarations
if (!class_exists('MyCache', false)) {
    class MyCache { public static $data = []; }
}

// ✅ DO: use statics for per-request state — they reset automatically
MyCache::$data['key'] = compute();

// ✅ DO: close resources when done
$fh = fopen('/tmp/report.csv', 'w');
fwrite($fh, $csv);
fclose($fh);  // don't leave it open

// ❌ DON'T: declare classes without the guard
class Foo {}  // fatal on second request

// ❌ DON'T: store secrets in $GLOBALS expecting them to persist
$GLOBALS['api_key'] = getenv('API_KEY');  // cleared between requests

// ❌ DON'T: rely on static accumulators across requests
// MyCounter::$total++ will always be 1, not 1, 2, 3...

// ✅ DO: use external storage for cross-request state
// Redis, memcached, database, files — same as fpm
```

### Choosing the right mode

| Flag | Model | Best for |
|---|---|---|
| (default) | fork per request | maximum isolation, simple scripts |
| `--workers=N` | persistent workers + snapshot | production: throughput + memory efficiency |

Both modes preload your framework before handling requests. The difference is
whether the preloaded state is inherited via fork (8ms) or reused in a loop
with snapshot restore (0.05ms).

### Configuration

```json
{
  "Q": {
    "webserver": {
      "workers": 40,
      "octane": true,
      "maxRequests": 1000
    }
  }
}
```

- `workers` — number of persistent workers (0 = fork per request)
- `octane` — enable snapshot restore in the worker loop (default: true when workers > 0)
- `maxRequests` — recycle workers after this many requests (default: 1000, 0 = unlimited)

## 🗺️ Roadmap

**Coming next:**

- **Clustering** — multi-process worker pool with shared socket for horizontal scaling
- **HTTP long-polling** — Socket.IO polling transport for environments that block WebSocket

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

---

## Benchmarks — Qbix Server vs nginx+fpm vs Swoole vs FrankenPHP

Full results in [BENCHMARKS.md](docs/BENCHMARKS.md). Key findings:

**Head-to-head (4 workers each, same scripts, one server at a time):**

| | Swoole | fpm | Qbix octane | FrankenPHP |
|---|---|---|---|---|
| CPU ~2ms (c=4) | **483**/s | 469/s | 439/s | 350/s |
| 50ms I/O (c=40) | 78/s | 78/s | 77/s | 39/s |
| Static 13KB | 26,974/s | 50,742/s | 18,311/s | 10,038/s |

Octane matches Swoole and fpm on I/O. The 7% CPU gap is IPC overhead (parent
dispatches to workers via Unix sockets, same as fpm's FastCGI).

**Same memory budget (200MB, 50ms I/O, c=40):**

| | fpm 4w (200MB) | octane 40w (200MB) |
|---|---|---|
| req/s | 78 | **520** |
| p50 | 505ms | 56ms |

**6.7× throughput, 9× lower latency** — because octane fits 100–300× more workers (measured for typical handlers)
on the same RAM.

## Execution model

One request, one PHP process. Qbix and ordinary PHP both assume a process
handles a single request and then dies; this server preserves that.

| Platform | Mode | How |
|---|---|---|
| Unix (`pcntl`) | fork | Parent preloads classes, config and DB; forks per request; child runs the script and `exit(0)`s. Copy-on-write means no interpreter startup and no framework bootstrap — the memory and isolation advantage comes from this model. The fork itself costs ~7ms, which is the throughput trade-off vs. persistent workers. |
| Windows / no `pcntl` | `php-cgi` | A real SAPI process per request, spawned via `Q.webserver.cgi.patterns`. Slower (full interpreter startup) but handles arbitrary PHP. |

The parent never runs application code. It owns the socket, the reverse cache
and static files, and forks. Because no request state is ever populated in the
parent, children inherit a clean slate and nothing needs to be reset between
requests.

## Q_Sapi — SAPI emulation for forked children

A forked child of a CLI process has no SAPI. Nothing populated the
superglobals, nothing captures output, and native `header()` is a silent no-op.
`Q_Sapi` does what mod_php or php-fpm would do:

```php
Q_Sapi::enter($parsed);      // superglobals + ob_start
include $scriptPath;         // any PHP file, not just a front controller
list($status, $headers, $body) = Q_Sapi::leave();
```

`enter()` populates `$_GET`, `$_POST` (form-encoded or JSON), `$_COOKIE`,
`$_FILES` and `$_SERVER` — including `HTTP_HOST`, `SCRIPT_NAME`, `PATH_INFO`
and `REMOTE_ADDR`. `HTTP_HOST` matters more than it looks:
`Q_Response::setCookie()` returns false without it, which silently drops the
session cookie.

### Shutdown ordering

PHP runs `register_shutdown_function` callbacks in registration order, then
object destructors. Since `Q_Sapi` registers before any application code, a
shutdown callback would fire *first* — before user callbacks had a chance to
echo or set cookies. So capture happens in a **destructor**
(`Q_Sapi_Finalizer`), which is guaranteed to run last and still runs on
`exit()`, on uncaught exceptions and on fatal errors.

Before assembling the response, `capture()` calls `session_write_close()`
explicitly, so the session row and its cookie are settled rather than racing
a response the parent has already sent.

`capture()` is idempotent, and `deliver()` hands the response off exactly once
— to `Q_Sapi::$onCapture` if the worker pool registered a consumer, otherwise
to `STDOUT` so a child run standalone behaves like an ordinary PHP script.

### What fork mode cannot do

Native `header()` cannot be intercepted: in the CLI SAPI it does nothing, and
PHP offers no hook. Fork mode therefore fully supports code that goes through
`Q_Response::header()` / `Q::header()`. Third-party or legacy scripts that call
`header()` directly should be routed to `php-cgi` with
`Q.webserver.cgi.patterns` — that config is the supported escape hatch, not a
workaround.

## Setting headers, status codes and cookies

**Use `Q_Response`.** It works in both standalone and `--app` mode and has the
same signature as PHP's built-in `header()`:

```php
Q_Response::header('Content-Type: application/json');
Q_Response::header('X-Custom: hello');
Q_Response::header('HTTP/1.1 201 Created');   // status line form
Q_Response::code(201);                         // or set it directly
Q_Response::setCookie('session', $token, 0, '/');
Q_Response::redirect('/dashboard');
```

`Q_Response::header()` also works — `Q_Response::header()` delegates to
it — but `Q_Response` is the higher-level API that scripts should prefer.

### Why not `header()`?

PHP's built-in `header()` and `http_response_code()` are **silently discarded**
under the CLI SAPI, which is what the server runs in. `headers_list()` always
returns an empty array and `http_response_code()` returns `false`, and PHP
offers no hook to intercept the builtins. A script calling them gets a `200`
with none of its headers, and no error to explain why.

### What works where

| API | standalone | `--app` | notes |
|---|---|---|---|
| `Q_Response::header()` | ✅ | ✅ | **recommended** — delegates to `Q_WebServer_State`, same signature as PHP's `header()` |
| `Q_Response::code()` | ✅ | ✅ | get or set the HTTP status code |
| `Q_Response::setCookie()` | ✅ | ✅ | cookies are assembled into `Set-Cookie` headers by the server |
| `Q_Response::header()` | ✅ | ✅ | low-level — `Q_Response::header()` delegates here |
| `Q::header()` | ✅ | ✅ | alias for `Q_Response::header()` |
| `header()` (built-in) | ❌ | ❌ | silently discarded by the CLI SAPI |
| `http_response_code()` | ❌ | ❌ | silently discarded by the CLI SAPI |

### Scripts you don't control

Third-party code — WordPress, a vendored SDK, anything not written for Qbix —
will call native `header()`. Route it to `php-cgi`, which runs it in a real CGI
process where the builtins work normally:

```json
{ "Q": { "webserver": { "cgi": { "patterns": ["wp-.*\\.php", "legacy/.*"] } } } }
```

`tests/run-cgi.sh` proves that path preserves both status codes and headers.

### Cookies

`Q_Response::setCookie()` works in both modes (the Platform declares it too).
The server reads `Q_Response::$cookies` — a `public static` property on both
implementations — and emits the `Set-Cookie` headers itself.

## Tests

Five test suites, 152 tests total:

```bash
# Core — static files, MIME, PHP dispatch, superglobals, POST/JSON, cookies, auth, stress
bash tests/run.sh --quick                    # 72 tests

# SAPI — superglobal population, output capture, status codes, sessions, shutdown ordering
php tests/testSapi.php                       # 19 tests

# HTTP protocol — keep-alive, path traversal, ETags, encoding, logging, panel auth
bash tests/testHttp.sh [port]                # 37 tests

# WebSocket — RFC 6455, Socket.IO handshake, events+acks, rooms, dashboard broadcast
node tests/testWebSocket.js [port]           # 12 tests

# Snapshot reset — octane state isolation, memory leaks, secret leaks
bash tests/testSnapshot.sh [port]            # 12 tests
```

The snapshot tests start both a fork-mode server and an octane-mode server,
then verify that statics, globals, `$_COOKIE`, `$_SERVER`, `$_POST`,
Authorization headers, and response headers from request A are invisible to
request B. The fork-mode server acts as a control group. See
[Octane Mode](#-octane-mode---workersn) for what the snapshot resets.

## Class ownership in `--app` mode

The webserver owns its own classes; the Platform does not carry copies.

`Q::autoload()` resolves `Q_WebServer_Proxy` to `classes/Q/WebServer/Proxy.php`
against PHP's include_path, which covers only the Platform. So `qbixserver.php`
registers a prepended autoloader that serves these names from this repo's `src/`:

    Q_WebServer, Q_WebServer_*, Q_WebSocket, Q_Scheduler, Q_FileCache, Q_HotReload

It is deliberately **selective**. `src/Q/` also contains `Utils`, `Uri`,
`Evented` and `Snapshot`, which the Platform also defines. Claiming those would
shadow the Platform's versions with the standalone ones. In `--app` mode the
Platform wins for anything it defines; we claim only what is ours alone.

Do **not** copy `Q/WebServer*.php` into `platform/classes/`. Nothing in the
Platform references `Q_WebServer` except one comment, and a Platform running
behind nginx or php-fpm should not ship code it never loads. A partial copy
there is worse than none: it shadows this repo's complete set and fails with
`Class "Q_WebServer_Proxy" not found`.

### `Q::$paths`

`Q::$paths` is declared by this repo's **standalone shim** (`src/Q.php`), not by
the Platform's `Q.php`. Use `Q_WebServer::paths()` instead of touching the
property — it falls back to `APP_DIR`/`Q_DIR` when the property is absent.
Dereferencing it directly in `--app` mode raised
`Access to undeclared static property Q::$paths` and made every request a 500.

### The webserver is a strict, overridable subset

In `--app` mode the Platform wins twice over: its **classes** override ours for
any shared name, and its **config** (routing, etc.) overrides ours. That is the
intended direction, and it only works if we never depend on anything the
Platform lacks.

The rule: **any member the Platform does not define must live on a
webserver-only class** — `Q_WebServer`, `Q_WebServer_*`, `Q_WebSocket`,
`Q_Scheduler`, `Q_FileCache`, `Q_HotReload` — never on a shared name like `Q`,
`Q_Utils`, `Q_Uri`, `Q_Evented` or `Q_Snapshot`.

`tests/platform-compat.php` enforces this. It maps both class trees, finds the
shared names, and fails if we touch a static member the Platform's version does
not declare. Guarded calls (`method_exists('Q','init') && Q::init(...)`) are
allowed, since they degrade cleanly.

    php tests/platform-compat.php /path/to/Qbix/platform

Fixed under this rule so far:

- `Q::$paths` — declared by our standalone shim, not by the Platform. Now read
  through `Q_WebServer::paths()`, which falls back to `APP_DIR`/`Q_DIR`.
- `Q_Utils::serverIdentity()`, `serverClaim()`, `signClaim()`, `verify()` —
  four methods on a shared class name the Platform also defines (without them).
  Moved to **`Q_WebServer_Identity`**.

#### Known violations still open (app mode is NOT clean yet)

`php tests/platform-compat.php <platform>` currently reports **7**. All are
webserver-only extensions to `Q_Request` / `Q_Response`, which are declared
inside `src/Q.php` and are therefore SHARED names — in `--app` mode the
Platform's versions win and these methods do not exist:

| Member | Used in |
|---|---|
| `Q_Request::setInput()` | `src/Q/WebServer.php`, `src/Q.php` |
| `Q_Request::restoreInput()` | `src/Q/WebServer.php`, `src/Q.php` |
| `Q_Response::getHeaders()` | `src/Q/WebServer.php`, `src/Q.php` |
| `Q_Response::cookieHeaders()` | `src/Q/WebServer/Headers.php`, `src/Q.php` |
| `Q_Response::clear()` | `src/Q/WebServer.php` |
| `Q_Response::header()` | `src/Q.php` |
| `Q_Response::responseCode()` | `src/Q.php` |

Observed effect: `GET /index.php` in app mode returns **500 — Call to undefined
method Q_Request::setInput()**. Static files serve correctly (200); it is the
PHP dispatch path that fails.

These need more than relocation. In app mode the *Platform's* `Q_Response` is
what the application actually writes to, so the webserver must read headers and
response state through the Platform's own API rather than move its private state
to a new class. Relocating the methods without rewiring the state would compile
and still be wrong.

### Why the webserver keeps its own Q_Uri (measured)

| | bytes | dependencies |
|---|---|---|
| Platform `Q_Uri` | 41,394 (1,472 lines) | `Q`, `Q_Config`, `Q_Request`, `Q_Utils`, `Q_Valid` |
| webserver `Q_Uri` | 7,554 | `Q`, `Q_Config` |

Adopting the Platform's outright means its **transitive closure**:
Uri 41K + Request 55K + Utils 84K + Valid 17K = **~197KB**, versus 7.5KB — and
the webserver has no `Q_Valid` at all. A standalone static server does not need
slots, mobile detection or validation to match `AI/webhook/:type/:task`.

In `--app` mode the calculus reverses: the Platform is already loaded, so its
`Q_Uri` is free and ours is dead weight. Hence `Q_WebServer_Router`, which uses
whichever is present.

**Resolved.** `Q_Uri::from()` *is* the path→route matcher — it dispatches
internally to the protected `fromUrl()`. The catch is that it must be given an
**absolute URL**, not a bare path.

Passing a bare path is worse than an error. `from()` then treats it as a URI
string (`"Module/action/..."`) and merely SPLITS it, returning a wrong answer
with no exception. Measured against a live app:

    bare path  AI/webhook/slack/ingest                    -> AI / webhook/slack/ingest   (split)
    full URL   http://host/App/AI/webhook/slack/ingest    -> AI / webhook                (routed)

`Q_WebServer_Router` now builds `Q_Request::baseUrl() + path` before calling
`from()`, and returns null rather than guessing when no base URL is available.
Verified with the Platform's `Q_Uri` loaded (`which Q_Uri: PLATFORM`):

    /AI/webhook/slack/ingest  -> AI/webhook
    /Safebox/action           -> Safebox/action
    /Users/login              -> null      (no catch-all route in that app's config)
    /nope                     -> null

## Testing

Seven suites. Everything runs against a real server over a real socket — no mocks.

    bash tests/run.sh --quick                    # 71 functional + security tests
    php  tests/testSapi.php                      # 19 SAPI emulation tests
    bash tests/run-probe.sh [platform-dir]       # 58 wire-level probes per mode
    bash tests/run-cgi.sh                        # php-cgi carveout
    php  tests/platform-compat.php <platform>    # --app compatibility audit
    php  tests/routing-parity.php  <platform>    # our matcher vs the Platform's
    bash tests/run-modes.sh <app> <platform>     # dual-mode acceptance

CI runs all of them on every push (`.github/workflows/test.yml`), in three
jobs: standalone, php-cgi, and `--app` against a fresh checkout of
[Qbix/Platform](https://github.com/Qbix/Platform).

### Testing `--app` mode

The Platform will not bootstrap without a real app — it needs a config with
`Q/plugins` and `Q/web/appRootUrl`, and its `Q_Uri` is not loadable on its own.
Build a minimal, plugin-free fixture:

    bash tests/fixtures/make-app.sh /path/to/Platform/platform /tmp/TestApp 20099
    bash tests/run-probe.sh /path/to/Platform/platform

Without a Platform path, `platform-compat.php` and `routing-parity.php` exit 0
with `SKIP`. **In CI that is indistinguishable from passing**, so the workflow
greps their output and fails the job if they did not actually report success.

### `tests/probe.php` — the wire-level suite

Unit tests miss whole classes of bug. Two examples this suite caught that
nothing else did:

- **`exit()` sent the client zero bytes.** The forked child unwound past the
  response-writing code, so nothing reached the socket — while the access log
  recorded `200`, because the parent had already assumed success.
- **Headers were silently dropped in `--app` mode.** They were captured
  correctly, then discarded by a guard that tested for a method only the
  standalone shim declares.

Both looked fine from inside the process. Only reading the socket revealed them.

### `tests/platform-compat.php` — the invariant that matters

The webserver is a *subset* the Platform must be able to override. In `--app`
mode the Platform's classes win for every name it defines, so any member the
webserver touches on a shared class must exist in the Platform's version too —
otherwise it works standalone and dies under a real app.

This audit walks `src/` **and the test fixtures** (they run under both modes too)
and fails on any member a shared class does not declare. Anything the Platform
lacks belongs on a webserver-only class: `Q_WebServer`, `Q_WebServer_State`,
`Q_WebServer_Router`, `Q_WebServer_Identity`, `Q_WebSocket`, `Q_Scheduler`,
`Q_FileCache`, `Q_HotReload`.

### Two behaviours worth knowing

**`php://input` works, via a stream wrapper.** A forking server reads the
request off the socket itself, so the real `php://input` is already consumed and
would stay empty for the life of the process. `Q_WebServer_State::setInput()`
registers a wrapper over `php` so `file_get_contents('php://input')` returns
*this* request's body, and `restoreInput()` unregisters it afterwards.

The wrapper class exists twice on purpose: `Q_PhpInputStream` in `src/Q.php` for
standalone, and `Q_WebServer_PhpInput` for `--app`, because `src/Q.php` is the
standalone shim and is not loaded when the Platform's `Q` wins. Without the
webserver-owned copy, `php://input` returned an empty string for every request
under `--app`.

**Native `header()` is discarded under the CLI SAPI.** `headers_list()` always
returns empty and `http_response_code()` returns `false`; PHP offers no hook to
intercept the builtin. Scripts written for Qbix should use
`Q_Response::header()`, which works in both modes. Scripts that must use
native `header()` — WordPress, third-party code — are routed to `php-cgi` via
`Q.webserver.cgi.patterns`; `tests/run-cgi.sh` proves that path preserves both
status and headers.

**The app enforces its own baseUrl.** If the app is configured for
`http://host/App` and you serve it on another port, the Platform returns
`{"error":"bad url ..."}`. That is the application refusing, not the server
failing. Serve at the configured address, or point the app's baseUrl at the
listening one — which is what `make-app.sh`'s port argument does.

### Fixed: `/` returned 403 in `--app` mode

`GET /index.php` returns 200 and renders the app. `GET /` returns 403.

Narrowed to the directory-index lookup: for `/` the server resolves the docroot
directory and then tries `index.html`, `index.php` in turn. That lookup is not
finding `web/index.php` even though the file exists and serves correctly when
requested directly — so the request falls past the index branch and is refused.
Suspect the `$fsPath . DS . $idx` join against `self::$rootDir` (the startup
banner reports `Root: web`, a relative value).

Everything else in `--app` mode passes: static files, `index.php`, an
`action.php` route, no class-loading / undeclared-property / undefined-method
errors, and no leading NUL byte.

**Root cause (fixed).** The extension used to pick the static-vs-PHP branch was
read from `$path` (the URL) instead of `$fsPath` (the resolved file). `/` has no
extension in the URL, but `$fsPath` had already been resolved to the directory
index `.../index.php`. So `/` fell past the PHP branch into `serveStaticFile()`,
which rejects any extension not in `$allowedExtensions` — 403 on the app's own
home page, while `/index.php` served fine. The same mistake appeared in **two**
places (`route()` and the serve path); both now read `$fsPath`.

**Also fixed: missing `Content-Type` on PHP-dispatched 200s.** A script that
never calls `header()` left none set, so successful dispatches went out with no
`Content-Type` at all — browsers sniff, strict clients reject. Error paths set it
explicitly, which is why it only bit the success path. `dispatchToQ()` now
defaults to `text/html; charset=utf-8` unless the script set one (and never on
204/304).

**Status: 32/32 passing in both modes**, stable across repeated runs.

**Native `header()` is not captured — use `Q_Response::setHeader()`.** Under the
CLI SAPI PHP's `header()` is a no-op and `headers_list()` returns nothing, so a
long-running CLI server cannot see those calls. This is a PHP constraint, not a
server one, and unlike `php://input` it cannot be worked around with a stream
wrapper. Set response headers through `Q_Response::setHeader()` or
`Q::header()`; both are captured and reach the client. The suite asserts this
explicitly so it will report if a future SAPI changes the behaviour.

**One header store.** `Q_Response`'s accessors delegate to
`Q_WebServer_State`. An earlier refactor left `Q_Response` keeping a parallel
`$_headers` array while the server read State's — so every header set through the
Qbix API silently vanished from the response. The suite now round-trips a header
set via `Q_Response::setHeader()` and asserts it arrives.
