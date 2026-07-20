# ⚡ Qbix Server

A pure PHP web server. No nginx, no Apache, no php-fpm.  
One process serves static files, PHP scripts, WebSocket connections, and a live dashboard.

### Why it's faster than nginx + php-fpm for real apps

| | nginx + php-fpm | Qbix Server |
|---|---|---|
| 🚀 **PHP request speed** | 10–50ms bootstrap on *every* request | **0ms** — workers fork after classes are loaded |
| 💾 **Memory** | 30–60MB × N workers (duplicated) | 30MB shared + ~5MB per worker (copy-on-write) |
| 🔒 **Access-controlled files** | Public URLs or hacky rewrites | `X-Accel-Redirect` — PHP checks access, server streams the file |
| 🧩 **Cache invalidation** | Whole-page only (purge everything) | `X-Cache-Tree` — invalidate one component, keep the rest cached |
| 🌐 **WebSocket** | Needs a separate server | Built in |
| ⚙️ **Setup** | Install nginx, configure proxy_pass, php-fpm pool, sockets... | `php qbixserver.php --port=8080` |

Static file throughput is 55–73% of nginx (C will always beat PHP on raw I/O).  
But on **actual PHP workloads**, the bootstrap savings make this **2–5x faster**.

> 💡 You can always put nginx, a reverse proxy, or a CDN (Cloudflare, CloudFront)
> in front of this for faster HTTPS and edge caching. Qbix Server handles the
> PHP execution, access control, and intelligent caching behind it.

---

## 📑 Table of Contents

- [Quick Start](#-quick-start)
- [Performance](#-performance)
- [Why Not php-fpm?](#-why-not-php-fpm)
- [Features](#-features)
- [Server Powers](#-server-powers--what-your-php-can-do)
- [Configuration](#-configuration)
- [PHP Scripts](#-php-scripts)
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

# Or use the PHAR (single file, 196KB)
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

## 🔒 Server Powers — What Your PHP Can Do

Qbix Server understands special response headers from your PHP scripts, giving you
capabilities that normally require complex nginx configurations or aren't possible at all.

### Access-controlled static files

With a typical server, your uploaded files sit at public URLs. Anyone with the link can
access them — and share the link with others. The usual workaround is "unguessable" URLs,
which are just security through obscurity.

Qbix Server supports `X-Accel-Redirect`: your PHP checks access, then tells the server
to serve the file directly — fast, streamed, with no public URL exposed:

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
$realPath = "/uploads/private/{$fileId}";
header("X-Accel-Redirect: {$realPath}");
header("Content-Disposition: attachment; filename=\"document.pdf\"");

// The server takes over from here — streams the file
// with correct Content-Type, ETag, compression, etc.
// Your PHP process is already done.
```

No public URL for the file. No redirect the user can bookmark. The server streams
the file after your PHP has verified access and exited. This works for PDFs, images,
videos, ZIPs — anything.

### Built-in reverse proxy cache

The server caches responses and serves them without running PHP again.
Control it with standard `Cache-Control` headers:

```php
<?php
// web/feed.php — cached for 5 minutes
header('Cache-Control: public, max-age=300');

// This runs once, then the server serves the cached
// response for the next 5 minutes. Zero PHP cost.
echo renderFeed();
```

The server also generates `ETag` headers from your response content. On subsequent
requests with `If-None-Match`, it returns `304 Not Modified` with no body — saving
bandwidth for both you and your users.

### Component-level cache invalidation

This is the big one. Most caching systems cache whole pages — when anything changes,
you throw away the entire page and re-render everything.

Qbix Server supports `X-Cache-Tree` and `X-Cache-Deps` headers that let your PHP
register individual components of a page and what data they depend on:

```php
<?php
// web/community.php — a page with multiple components

// Render the feed (depends on the feed stream)
$feedHtml = renderFeed($communityId);
$feedHash = md5($feedHtml);

// Render the sidebar (depends on the about stream)
$sidebarHtml = renderSidebar($communityId);
$sidebarHash = md5($sidebarHtml);

// Render members list (depends on participants)
$membersHtml = renderMembers($communityId);
$membersHash = md5($membersHtml);

// Register components and what they depend on
header('X-Cache-Tree: ' . json_encode([
    'l' => [
        'feed'    => $feedHash,
        'sidebar' => $sidebarHash,
        'members' => $membersHash,
    ]
]));

header('X-Cache-Deps: ' . json_encode([
    'feed'    => ["community/{$communityId}/feed"],
    'sidebar' => ["community/{$communityId}/about"],
    'members' => ["community/{$communityId}/participants"],
]));

// When someone posts to the feed, only 'feed' is invalidated.
// The sidebar and members list are still served from cache.
// The server re-renders only the stale component.
```

When data changes, tell the server which dependency key was affected:

```php
<?php
// web/post.php — user posts to the feed
saveNewPost($communityId, $content);

// Invalidate only pages that depend on this feed
header('X-Cache-Invalidate: ' . json_encode([
    "community/{$communityId}/feed"
]));

// The server walks its dependency graph:
//   community/123/feed → page /community/123 component 'feed'
// Only that component is stale. Sidebar, members = still cached.
```

The server maintains a Merkle tree of component hashes. When any dependency key
is invalidated, it walks the tree to find exactly which components on which pages
are affected — and only those are re-rendered on the next request. Everything else
is served from the in-memory cache.

### Even more powerful with Qbix Platform

These headers work with plain PHP as shown above. But with the
[Qbix Platform](https://github.com/Qbix/Platform), it becomes automatic:

```php
// Tools call this during rendering — the framework handles the rest
Q_Response::setCacheComponent('Streams/feed', $hash, [$depKey]);
Q_Response::invalidateCacheDeps($publisherId . '/' . $streamName);

// X-Accel-Redirect for access-controlled files
Q_Response::setHeader('X-Accel-Redirect', $path);

// Cache-Control with semantic options
Q_Response::setCachePolicy([
    'public' => true,
    'maxAge' => 300,
    'mustRevalidate' => true,
]);
```

The Platform's Streams plugin automatically invalidates cache dependencies when
stream data changes — posts, relations, participant joins — so cached pages
update themselves without manual invalidation calls. Combined with the server's
Merkle tree, this gives you fine-grained, data-driven cache invalidation across
your entire app, with zero configuration.

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

## 🐘 PHP Scripts

Any `.php` file in your document root is executed when requested:

```
web/
  index.html      ← served as static file
  style.css       ← served as static file
  api.php         ← executed as PHP
  webhook.php     ← executed as PHP
```

PHP scripts have full access to `$_SERVER`, `$_GET`, `$_POST`, `$_REQUEST`:

```php
<?php
// web/api.php
header('Content-Type: application/json');
echo json_encode([
    'time'   => date('c'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'query'  => $_GET,
]);
```

For concurrent PHP execution, use `--workers=N` to pre-fork a worker pool.

---

## 📦 Three Ways to Run

### 1. From source (needs PHP 8.1+)

```bash
php qbixserver.php --root=./web --port=8080
```

### 2. PHAR — single 196KB file (needs PHP)

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

**For qbixserver.php and PHAR:**

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

---

## 📄 License

MIT — see [LICENSE](LICENSE).

Part of the [Qbix Platform](https://github.com/Qbix/Platform).