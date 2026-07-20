# ⚡ Qbix Server

A pure PHP web server. No nginx, no Apache, no php-fpm.  
One process serves static files, PHP scripts, WebSocket connections, and a live dashboard.

**55–73% of nginx throughput** on static files. Zero dependencies beyond PHP itself.

---

## 📑 Table of Contents

- [Quick Start](#-quick-start)
- [Performance](#-performance)
- [Features](#-features)
- [Configuration](#-configuration)
- [PHP Scripts](#-php-scripts)
- [Three Ways to Run](#-three-ways-to-run)
- [Building](#-building)
- [With Qbix Platform](#-with-qbix-platform)
- [Architecture](#-architecture)
- [Requirements](#-requirements)
- [License](#-license)

---

## 🚀 Quick Start

```bash
# Clone
git clone https://github.com/Qbix/webserver.git
cd webserver

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

## ✨ Features

| Category | What you get |
|---|---|
| **Static files** | ETag, 304 Not Modified, Last-Modified, MIME type detection, in-memory response cache |
| **Keep-alive** | HTTP/1.0 and 1.1, TCP_NODELAY, configurable limits |
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
Copy it to any Linux machine and run. No dependencies.

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

GitHub Actions automatically builds binaries for **x86_64** and **aarch64** on every tagged release.

---

## 🔌 With Qbix Platform

Qbix Server is extracted from the [Qbix Platform](https://github.com/Qbix/Platform) — a full-stack
framework for building social apps with real-time streams, user management, and plugin architecture.

When you have a Qbix app, the server uses the full framework:

```bash
php server.php --app=/path/to/myapp --port=8080
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
  HTTP request ────→ │   Event Loop     │  stream_select (zero deps)
                     │   (single thread)│  or amphp/revolt (optional)
                     └────────┬─────────┘
                              │
              ┌───────────────┼───────────────┐
              │               │               │
         ┌────▼─────┐   ┌────▼─────┐   ┌────▼─────┐
         │  Static   │   │   PHP    │   │ WebSocket │
         │  Files    │   │  Dispatch│   │  Upgrade  │
         │           │   │          │   │           │
         │ In-memory │   │ In-proc  │   │ RFC 6455  │
         │ response  │   │ or fork  │   │ frames    │
         │ cache     │   │ pool     │   │           │
         └──────────┘   └──────────┘   └──────────┘
```

**Static files** are served from an in-memory response cache. The full HTTP response
(headers + body) is pre-built and sent in a single `fwrite()` call. The cache is
mtime-validated with configurable check intervals. Combined with `TCP_NODELAY`,
this delivers sub-millisecond response times.

**PHP scripts** run in-process (single-threaded, suitable for lightweight APIs)
or in a pre-fork worker pool (`--workers=N`) for concurrent PHP execution.
Workers are forked after class preloading, so they share the base memory footprint.

**The remaining gap** versus nginx (Qbix at 55-73%) is inherent: nginx uses
`sendfile()` (kernel-space file→socket copy), `epoll` (O(1) event notification),
and compiled C. PHP's `stream_select` is `select(2)`, file serving goes through
userspace, and every operation has interpreter overhead. Getting to 55-73% of C
performance from pure interpreted PHP is about as good as it gets.

---

## 📋 Requirements

**For server.php and PHAR:**

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
