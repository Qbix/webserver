# ⚡ Qbix Server v3.2

https://qbixserver.com is an all-in-one server that handles everything for you. Drop files in folders. Get real-time applications that can handle millions of users. Produce and distribute [standalone binaries](#single-binary-distribution) that run on Linux, Mac, Windows, and now iOS and Android too. Qbix Server v2 lets you build secure decentralized apps that can even work offline, over WiFi and Bluetooth.

When serving millions of people, safety becomes very important. [Learn why the server is written in PHP.](#why-php) Today's PHP ecosystem has also produced thousands of useful web frameworks including OwnCloud, WordPress, Magento, Drupal, Symfony, Laravel, and more. Qbix Server can [run them all, unmodified](#supported-frameworks). It also gives you a dashboard and visual control panel to manage all your apps, domains, certificates, etc. in one place.

### What it replaces

| | nginx + php-fpm | Qbix Server |
|---|---|---|
| 💾 **Memory per worker** | 30–60MB (duplicated) | ~120KB (COW, measured) |
| 👥 **Concurrent PHP** (1GB) | ~24 workers | **~5,000** (typical) |
| 🔒 **Isolation** | Statics leak between requests | **OS-enforced**: separate address space per request |
| 🚀 **Throughput** (I/O, same RAM) | 78 req/s (fpm/Swoole 4w) | **1,060 req/s** (100w) |
| 🌐 **WebSocket** | Needs a separate server | Built in, same port |
| 🖼️ **Image resize** | Needs image_filter module + config | `?w=300` on any image URL, auto AVIF/WebP |
| 🔗 **Peer-to-peer** | Not possible | Encrypted mesh over BLE + Wi-Fi |
| 📱 **Mobile** | Not possible | iOS + Android with background persistence |
| 🔄 **Data sync** | Not possible | Bloom filters + prolly trees between peers |
| ⚙️ **Setup** | nginx + fpm pools + sockets | `php qbixserver.php` |

See [BENCHMARKS.md](docs/BENCHMARKS.md) for full methodology and [reset.md](docs/reset.md) for what gets restored between requests.

### What a "real-time PHP app" used to require

**nginx** for reverse proxy and static files. **php-fpm** to run PHP. **Node.js** for a Socket.IO server. **Redis** for pub/sub between fpm and Node. **supervisor** to keep it all running. **Docker** to make it deployable. Six processes, three languages, two runtimes.

Qbix Server replaces all six with one process. HTTP, WebSocket (with Socket.IO protocol), SSE, sessions, uploads, static files, image resizing, .htaccess — same port, same file. No Redis, no Node, no pub/sub glue.

In v2.0, the same server also discovers nearby devices over Bluetooth and Wi-Fi, establishes ECDH-encrypted sessions, routes messages through multi-hop mesh, and synchronizes data peer-to-peer with Bloom filters and prolly trees. It runs on iOS and Android alongside Linux, macOS, and Windows. A classroom of phones running the same PHP app, syncing data with no internet, no central server — one `php qbixserver.php`.

You can also package your entire app — code, assets, SQLite database — into a single binary and distribute it as a single file. Double-click on Windows, `./myapp --open` on Mac or Linux, the browser opens and the app is there. No PHP to install, no web server to configure, no database to set up. [How it works →](#single-binary-distribution)

---

## 📑 Table of Contents

- [Documentation](#documentation)
- [Quick Start](#quick-start)
- [Use With Your Existing Codebase](#use-with-your-existing-codebase)
- [Performance](#-performance)
- [vs FrankenPHP and Swoole](#️-vs-frankenphp-and-swoole)
- [Features](#-features)
- [Server Headers](#-server-headers--what-your-php-can-send)
- [For PHP Developers](#-for-php-developers--the-micro-framework)
- [Configuration](#️-configuration)
- [Platform Support](#platform-support)
- [Three Ways to Run](#-three-ways-to-run)
- [Building](#-building)
- [Examples](#examples)
- [Migrating from another server](#migrating-from-another-server)
- [Single-Binary Distribution](#single-binary-distribution)
- [Mesh Networking](#mesh-networking)
- [Mobile](#mobile)
- [Autohost](#-autohost--automatic-domain-provisioning)
- [Collaborative Branches](#-collaborative-branches)
- [Metrics & Analytics](#-metrics--analytics)
- [With Qbix Platform](#-with-qbix-platform)
- [Email & SMS Relay](#-email--sms-relay)
- [Architecture](#️-architecture)
- [HTTP/2 Support](#-http2-support)
- [Why PHP](#why-php)
- [License](#-license)

---

## Documentation

| | Topic | What it covers |
|---|---|---|
| 🏎️ | [Why Not php-fpm?](docs/why.md) | COW memory model, comparison with Swoole and FrankenPHP |
| 🔒 | [Server Headers](#-server-headers--what-your-php-can-send) | Cache-Control, X-Cache-Tree, X-Accel-Redirect, ETag ([full doc](docs/headers.md)) |
| 🗂️ | [Static Files](docs/static-files.md) | ETag/304, compression, precompression cache |
| 🖼️ | [Image Processing](docs/images.md) | Resize with `?w=`, AVIF/WebP negotiation, Save-Data, disk cache, limits |
| 🌐 | [HTTP](docs/http.md) | Fork-per-request mode, request lifecycle |
| 🔌 | [WebSocket & Rooms](docs/websocket.md) | Process per connection, rooms, Socket.IO, SSE, chat example |
| 🛤️ | [Routing](docs/routing.md) | Clean URLs, .htaccess, DirectoryIndex |
| 📂 | [PHP Framework](#-for-php-developers--the-micro-framework) | The micro-framework: handlers, events, Q classes ([full doc](docs/framework.md)) |
| ⚙️ | [Configuration](#️-configuration) | JSON config, CLI options, presets ([full doc](docs/configuration.md)) |
| 📦 | [Running & Building](#-three-ways-to-run) | Source, phar, binary. Building static binaries ([full doc](docs/running.md)) |
| 📀 | [Binaries & Signing](#single-binary-distribution) | Pack apps, manage like zip, ECDSA M-of-N signing ([full doc](docs/binaries.md)) |
| 🏗️ | [Architecture](#️-architecture) | Persistent workers, COW, execution model, mental model ([full doc](docs/architecture.md)) |
| 📊 | [Dashboard & Panel](docs/dashboard.md) | Live stats, control panel tabs |
| 🚀 | [Deploy & Federation](docs/deploy.md) | Rsync deploy, cluster replication, inter-server trust |
| 🔍 | [API Discovery](docs/api-discovery.md) | OpenAPI, MCP, qbix.json, HTTP/2 |
| 🧩 | [Compatibility](docs/compatibility.md) | Running Laravel, Symfony, WordPress, Drupal unmodified: what gets rewritten and why |
| 🏢 | [Frameworks](#use-with-your-existing-codebase) | All 13 supported frameworks, presets, boot adapters ([full doc](docs/FRAMEWORKS.md)) |
| 🧬 | [--app Mode & SAPI Internals](docs/app-mode.md) | SAPI emulation, class ownership, test suites |
| 🌐 | [Mesh Networking](#mesh-networking) | Identity, handshake, routing, encryption ([full doc](docs/Mesh.md)) |
| 🔄 | [Data Sync](docs/sync.md) | Bloom filters, prolly trees, conflict resolution, current limits |
| 📱 | [Mobile](#mobile) | Running on phones, transports, permissions ([full doc](docs/mobile.md)) |
| 📈 | [Performance](#-performance) | Full methodology and numbers ([full doc](docs/BENCHMARKS.md)) |
| 📊 | [Metrics & Analytics](#-metrics--analytics) | Client-side telemetry, server-side analytics portal, Sankey flow ([full doc](docs/METRICS.md)) |
| 🔄 | [State Reset](docs/reset.md) | What gets restored between requests |
| 🔀 | [Migrate from nginx](docs/migrate-nginx.md) | Server blocks, try_files, proxy_pass, gzip |
| 🔀 | [Migrate from Apache](docs/migrate-apache.md) | .htaccess unchanged, VirtualHost mapping |
| 🔀 | [Migrate from Caddy](docs/migrate-caddy.md) | Automatic HTTPS, on-demand TLS → autohost |
| ✅ | [Test Results](docs/TestResults.md) | 197 end-to-end tests |
| 🌿 | [Collaborative Branches](#-collaborative-branches) | Collaborative branching, permissions, database cloning ([full doc](docs/branches-plan.md)) |
| 🌐 | [Autohost](#-autohost--automatic-domain-provisioning) | Automatic domain provisioning, TLS, DNS verification ([full doc](docs/AUTOHOST.md)) |
| 🤖 | [AI Collaboration](docs/COLLABORATION.md) | MCP workflow, patch-based editing, VCS integration, multi-server sync |
| 📧 | [Email & SMS Relay](#-email--sms-relay) | Inbound SMTP, outbound delivery, digest batching, Twilio SMS ([full doc](docs/RELAY.md)) |
| 📧 | [Relay Architecture](docs/RELAY-PLAN.md) | Design document: process model, config fallback, digest algorithm, dashboard panels |
| 🔌 | [Qbix Platform](#-with-qbix-platform) | Integration with the full Qbix Platform, Streams, plugin system |
| 🌐 | [HTTP/2](#-http2-support) | Binary framing, multiplexing, HPACK, server push |
| 🗺️ | [Roadmap](docs/roadmap.md) | What's next |
| 📄 | [License](#-license) | MIT ([full doc](docs/license.md)) |

---

## Quick Start

```bash
git clone https://github.com/Qbix/webserver
cd webserver
php qbixserver.php
```

Or grab a self-contained binary (PHP bundled, nothing to install):

```bash
# Linux
curl -LO https://github.com/Qbix/webserver/releases/latest/download/qbixserver-linux-x86_64
chmod +x qbixserver-linux-x86_64
./qbixserver-linux-x86_64

# macOS (Apple Silicon)
curl -LO https://github.com/Qbix/webserver/releases/latest/download/qbixserver-macos-arm64
chmod +x qbixserver-macos-arm64
./qbixserver-macos-arm64

# Windows
curl -LO https://github.com/Qbix/webserver/releases/latest/download/qbixserver-windows-x64.exe
qbixserver-windows-x64.exe
```

Or use the PHAR (single file, no extensions to compile):

```bash
php bin/qbixserver.phar --root=./public --port=8080
```

---

## Use With Your Existing Codebase

If you already have a PHP app running on nginx + php-fpm, switching is one command. The server reads your `.htaccess`, rewrites URLs to your front controller, and runs your code with 27 functions shimmed so static variables, sessions, and headers work correctly between requests.

**Laravel:**

```bash
cd my-laravel-app
php /path/to/qbixserver.php --root=public --preset=laravel --port=8080
```

**Symfony:**

```bash
cd my-symfony-app
php /path/to/qbixserver.php --root=public --preset=symfony --port=8080
```

**WordPress:**

```bash
cd my-wordpress-site
php /path/to/qbixserver.php --root=. --preset=wordpress --port=8080
```

**Drupal:**

```bash
cd my-drupal-site
php /path/to/qbixserver.php --root=web --preset=drupal --port=8080
```

**Any PHP app with a front controller:**

```bash
php /path/to/qbixserver.php --root=public --port=8080
```

If the root directory has an `index.php`, all clean URLs automatically route to it (the same behavior as `try_files $uri $uri/ /index.php` in nginx). If there's a `.htaccess`, its `RewriteRule` and `RewriteCond` directives are applied.

### What `--preset` does

Each preset sets framework-appropriate defaults: the front controller path, upload limits, memory limits, and session GC settings. You can override any of these in a JSON config file. The preset is a convenience — without it, the server still works if your `.htaccess` handles routing.

### What gets shimmed

The server intercepts 27 PHP functions (`header()`, `session_start()`, `setcookie()`, `ini_set()`, etc.) via source transformation at include time. Your code calls `header()` and it works — the server captures it. Between requests, all static properties are restored from a snapshot in 0.03ms. See [Compatibility](docs/compatibility.md) for the full list.

### Supported Frameworks

Qbix Server ships presets and boot adapters for 14 frameworks. Every framework runs unmodified — no plugins or code changes needed.

| Framework | Preset | Speedup vs php-builtin |
|---|---|---|
| **Qbix** | `--preset=qbix` (auto-detected) | — |
| [Laravel](docs/FRAMEWORKS.md#laravel) | `--preset=laravel` | [3.4×](docs/BENCHMARKS.md#laravel) |
| [Symfony](docs/FRAMEWORKS.md#symfony) | `--preset=symfony` | [—](docs/BENCHMARKS.md#symfony) |
| [WordPress](docs/FRAMEWORKS.md#wordpress) | `--preset=wordpress` | — |
| [Drupal](docs/FRAMEWORKS.md#drupal) | `--preset=drupal` | [4.0×](docs/BENCHMARKS.md#drupal-10) |
| [CakePHP](docs/FRAMEWORKS.md#cakephp) | `--preset=cakephp` | [2.0×](docs/BENCHMARKS.md#cakephp-5) |
| [CodeIgniter](docs/FRAMEWORKS.md#codeigniter-4) | `--preset=codeigniter` | — |
| [Yii](docs/FRAMEWORKS.md#yii-2) | `--preset=yii` | [—](docs/BENCHMARKS.md#yii-2) |
| [Mezzio](docs/FRAMEWORKS.md#mezzio-laminas) | `--preset=mezzio` | [1.5×](docs/BENCHMARKS.md#mezzio) |
| [Slim](docs/FRAMEWORKS.md#slim) | `--preset=slim` | — |
| [Joomla](docs/FRAMEWORKS.md#joomla) | `--preset=joomla` | — |
| [Magento](docs/FRAMEWORKS.md#magento) | `--preset=magento` | — |
| [Nextcloud](docs/FRAMEWORKS.md#nextcloud--owncloud) | `--preset=nextcloud` | — |
| [ownCloud](docs/FRAMEWORKS.md#nextcloud--owncloud) | `--preset=owncloud` | — |

"—" = not benchmarked or no significant speedup on CPU-bound "hello world". Real-world I/O-bound workloads see much larger gains from the COW memory model (see [Benchmarks](docs/BENCHMARKS.md#under-real-load-c200-concurrent-requests-escalating-io)).

See [FRAMEWORKS.md](docs/FRAMEWORKS.md) for full details, preset reference, and boot adapter documentation. See [BENCHMARKS.md](docs/BENCHMARKS.md#framework-benchmarks) for full benchmark methodology and per-framework numbers.

### What to watch for

Most apps work immediately. A few things to be aware of:

- **`define()` constants** persist between requests in persistent workers. If a plugin defines a constant conditionally, the second request sees it already defined. Rare in practice.
- **`stream_wrapper_register()`** persists. Uncommon outside testing frameworks.
- **Long-running scripts** (migrations, imports) should use `--workers=1` or run via CLI directly.
- **Extensions that store C-level state** (e.g. some custom PECL modules) won't reset between requests. Standard extensions (PDO, curl, mbstring) are fine.

---

## 📊 Performance

Benchmarked against nginx on the same single-core container, PHP 8.3, Ubuntu 24. 13KB static file, best-of-3 runs, warm caches.

| Scenario | nginx | Qbix Server | Ratio |
|---|---|---|---|
| Sequential (c=1) | 10,154 req/s | 6,376 req/s | **63%** |
| Concurrent (c=10) | 12,300 req/s | 6,876 req/s | **56%** |
| High concurrency (c=50) | 12,919 req/s | 7,253 req/s | **56%** |
| Keep-alive (c=10) | 26,858 req/s | 19,700 req/s | **73%** |
| Keep-alive (c=50) | 30,158 req/s | 20,369 req/s | **67%** |

Zero failed requests across 50,000+ requests at concurrency 50. Server never crashed.

> For context: 20K req/s means the server handles **1,000 simultaneous page loads per second** (assuming ~20 static assets per page), all from a single PHP process.

For **PHP application workloads**, the story flips — the memory and bootstrap savings matter more than static file throughput:

- **nginx + php-fpm:** 0.1ms static file + 30ms PHP bootstrap + 5ms actual work = **35ms**
- **Qbix Server:** 0.15ms static file + 0ms bootstrap + 5ms actual work = **5ms**

See [BENCHMARKS.md](docs/BENCHMARKS.md) for full methodology, and the [framework benchmarks](docs/BENCHMARKS.md#framework-benchmarks) for per-framework numbers.

> 💡 You can always put nginx, a reverse proxy, or a CDN (Cloudflare, CloudFront) in front of this for faster HTTPS and edge caching. Qbix Server handles the PHP execution, access control, and intelligent caching behind it.

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
| [**Static files**](docs/static-files.md) | ETag, 304 Not Modified, Last-Modified, MIME type detection, in-memory response cache |
| [**Keep-alive**](docs/http.md) | HTTP/1.0 and 1.1, TCP_NODELAY, configurable limits |
| [**HTTP/2**](#-http2-support) | Via amphp — multiplexed streams, header compression, TLS (optional) |
| [**PHP execution**](#-for-php-developers--the-micro-framework) | `.php` files in document root run in-process or via pre-fork worker pool |
| [**Compression**](docs/static-files.md) | On-the-fly gzip/brotli + pre-compressed `.gz`/`.br` siblings |
| [**WebSocket**](docs/websocket.md) | RFC 6455 upgrade on any path |
| [**Dashboard**](docs/dashboard.md) | Tabbed dashboard at `/Q/dashboard` — HTTP, WebSocket, Email, Mobile tabs with real-time stats, Sankey flow diagrams, and live request log |
| [**Health check**](docs/dashboard.md) | JSON at `/Q/health` — for load balancers and monitoring |
| [**Control panel**](docs/dashboard.md) | Password-protected at `/Q/panel` — manage apps and scripts |
| [**Rate limiting**](#️-configuration) | Per-IP with configurable windows and burst limits |
| [**Security**](docs/headers.md) | Path traversal blocked, dotfiles blocked, 431 for oversized headers, 400 for malformed requests |
| [**Graceful shutdown**](docs/running.md) | SIGTERM/SIGINT drain in-flight requests before closing |
| [**TLS**](docs/running.md) | Optional HTTPS with auto-certbot or manual certs |
| [**Logging**](docs/running.md) | Colored terminal output + file-based access logs |
| [**Access control**](#-server-headers--what-your-php-can-send) | X-Accel-Redirect support — PHP enforces access, server serves the file |
| [**Component cache**](#-server-headers--what-your-php-can-send) | X-Cache-Tree headers — invalidate parts of a page, not the whole thing |
| [**Image processing**](docs/images.md) | Resize with `?w=`, automatic AVIF/WebP negotiation, disk cache |
| [**Framework presets**](docs/FRAMEWORKS.md) | Built-in presets for [13 frameworks](docs/FRAMEWORKS.md) — Laravel, Symfony, WordPress, Drupal, and more |
| [**Autohost**](#-autohost--automatic-domain-provisioning) | Automatic domain provisioning — unknown `Host:` triggers DNS verification, ACME TLS, and config in one request. Multi-tenant SaaS, white-label, customer-owned domains |
| [**Collaborative branches**](#-collaborative-branches) | Copy-on-write branches with per-user permissions, database cloning, subdomain routing, and merge review |
| [**Branch auto-TLS**](docs/COLLABORATION.md) | Automatic Let's Encrypt certificate provisioning per branch subdomain via HTTP-01 — no DNS API needed |
| [**Default lockdown**](docs/COLLABORATION.md) | Branches locked down by default — file-tier permissions, deny paths, optional OS-level UID isolation |
| [**MCP integration**](docs/api-discovery.md) | Model Context Protocol endpoint for AI-assisted editing with branch push, patch, export, and merge requests |
| [**Client metrics**](docs/METRICS.md) | Opt-in [script injection](docs/METRICS.md) for client-side telemetry — scroll depth, media tracking, SPA navigation, click tracking — stored as daily TSV, viewable in panel |
| [**Analytics portal**](docs/METRICS.md#analytics-portal) | Server-side [per-request analytics](docs/METRICS.md#analytics-portal) with Sankey flow visualization, session replay, UA parsing, and filterable drill-down — no client-side opt-in needed |
| [**Email relay**](#-email--sms-relay) | Built-in SMTP relay — inbound receiving, outbound delivery (SES, Mailgun), MIME parsing, conversation threading, digest batching, rate limiting with circuit breaker, open/click tracking with Sankey funnel visualization |
| [**SMS relay**](docs/RELAY.md) | Twilio integration — send/receive SMS, webhook validation, template support |
| [**Mesh networking**](#mesh-networking) | Encrypted P2P over BLE + Wi-Fi with multi-hop routing |
| [**Data sync**](docs/sync.md) | Bloom filter + prolly tree sync between peers |

---

## 🔒 Server Headers — What Your PHP Can Send

Qbix Server understands special response headers from your PHP scripts. These are the same headers nginx understands (like `X-Accel-Redirect`) plus new ones for component-level caching. Your PHP sends them with `header()`, the server acts on them.

### Quick reference

| Header | What it does | Example |
|---|---|---|
| `Cache-Control` | Server caches the response, serves without running PHP | `header('Cache-Control: public, max-age=300');` |
| `X-Accel-Redirect` | Server streams a file after PHP checks access | `header('X-Accel-Redirect: /uploads/private/doc.pdf');` |
| `X-Cache-Tree` | Registers page components with content hashes | `header('X-Cache-Tree: ' . json_encode([...]));` |
| `X-Cache-Deps` | Maps components to data dependency keys | `header('X-Cache-Deps: ' . json_encode([...]));` |
| `X-Cache-Invalidate` | Marks dependency keys as stale | `header('X-Cache-Invalidate: ' . json_encode([...]));` |
| `X-Cache-Stale` | Marks specific components as needing re-render | `header('X-Cache-Stale: feed,sidebar');` |

All of these are standard PHP `header()` calls. No SDK, no framework needed. The server strips them before sending the response to the client.

### Access-controlled static files

With a typical server, your uploaded files sit at public URLs. Anyone with the link can access them — and share the link with others. The usual workaround is "unguessable" URLs, which are just security through obscurity.

`X-Accel-Redirect` lets your PHP check access, then tells the server to serve the file directly — fast, streamed, with no public URL exposed:

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

No public URL for the file. No redirect the user can bookmark. The server streams the file after your PHP has verified access and exited.

### Component-level cache invalidation

Most caching systems cache whole pages. When anything changes, you throw away the entire page and re-render everything. Qbix Server can cache individual components and only re-render what changed.

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

The server maintains a Merkle tree of component hashes. When a dependency key is invalidated, it walks the tree to find exactly which components on which pages are affected. Everything else is served from the in-memory cache.

---

## 📂 For PHP Developers — The Micro-Framework

Qbix Server isn't just a static file server with PHP bolted on. It's a micro-framework where you **drop files into conventional directories** and things just work — classes autoload, events fire handlers, views render templates. No configuration needed for the basics.

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

**Your PHP scripts don't need to `require` or `include` anything.** The server has already loaded the `Q` class, the autoloader, and the event system before your script runs. Classes from `classes/`, events via `Q::event()`, views via `Q::view()` — all available immediately:

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

### Handlers — event-driven dispatch

Drop a file in `handlers/` and it's available as an event:

```php
<?php
// handlers/MyApp/feed/post.php
function MyApp_feed_post(&$params, &$result) {
    $title = $params['title'] ?? 'Untitled';
    $userId = $params['userId'] ?? null;
    $id = saveFeedPost($userId, $title);
    $result = ['id' => $id, 'title' => $title, 'saved' => true];
    return $result;
}
```

```php
<?php
// web/api.php — fire it from anywhere
$result = Q::event('MyApp/feed/post', [
    'title'  => $_POST['title'],
    'userId' => $_SESSION['user_id'],
]);
header('Content-Type: application/json');
echo json_encode($result);
```

The handler file is `include`'d the first time the event fires, then the function stays in memory. If the event never fires, the file is never loaded.

**Before/after hooks** via config — useful for validation, logging, access control:

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

Any before hook returning `false` stops the chain. Handlers can also be URLs — the server POSTs event parameters as JSON to remote endpoints, giving you webhooks built into the event system.

### The philosophy

| | Loaded when | Lives in | Purpose |
|---|---|---|---|
| **Classes** | Startup (preloaded) | `classes/` | Models, services, utilities — your core code |
| **Handlers** | First event fire (on demand) | `handlers/` | Actions, hooks, webhooks — code that responds to events |
| **Views** | When rendered | `views/` | Templates — HTML with PHP |
| **Scripts** | When requested via HTTP | `web/` | Entry points — the "controller" layer |
| **Config** | Startup | `config/` | Settings, handler hooks, preload lists |

Classes are **eager**. Handlers are **lazy**. Scripts are **per-request**. Views are **on-demand**. This gives you the right loading strategy for each kind of code without thinking about it — just put files in the right directory.

When your project outgrows the micro-framework and you need user accounts, real-time streams, access control, payments, or a plugin system, switch to `--app` mode and everything you've written keeps working. See [With Qbix Platform](#-with-qbix-platform).

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

See [Configuration](docs/configuration.md) for the full reference.

---

## Platform Support

| Platform | Workers | COW | Transport |
|---|---|---|---|
| **Linux** x86_64, aarch64 | pcntl_fork | Yes — 120KB per worker | TCP, mDNS |
| **macOS** Intel, Apple Silicon | pcntl_fork | Yes | TCP, mDNS |
| **FreeBSD** / OpenBSD / NetBSD | pcntl_fork | Yes | TCP, mDNS |
| **Windows** x64 | qbix_fork.dll (FFI) or php-cgi | Yes (FFI) / No (php-cgi) | TCP |
| **iOS** arm64 | NativePHP / php-ios | — | TCP, MultipeerConnectivity, BLE GATT |
| **Android** arm64 | Phphone / NativePHP | — | TCP, BLE GATT, NSD |

On Linux, macOS and BSD, the server runs thousands of COW-forked workers at ~120KB each. On Windows, the server ships `qbix_fork.dll` which uses `RtlCloneUserProcess` via PHP's FFI extension to give full COW fork semantics — same memory efficiency as POSIX platforms. If FFI or the DLL isn't available, the server falls back to `php-cgi` subprocesses (no COW, higher memory per worker, but fully functional). On iOS and Android, the PHP runtime is embedded in a native app shell; the TransportManager handles peer discovery and transport negotiation automatically.

**PHP 8.6+ (epoll/kqueue):** The server auto-detects PHP 8.6's native `Io\Poll` API and uses `epoll` on Linux or `kqueue` on macOS for event notification — no PECL extensions needed. On older PHP versions, the server uses `stream_select` (which works fine, just O(n) per tick instead of O(1)). Revolt is also supported if installed.

**GitHub Actions CI** builds and tests on Linux x86_64, Linux aarch64, macOS arm64, Windows x64, FreeBSD 14, plus experimental Android and iOS targets. 464 tests, 0 failures.

### Requirements

**Linux / macOS (recommended):**

- PHP 8.1 or later
- Extensions: `sockets`, `pcntl` (for signals + workers), `openssl` (for HTTPS)

```bash
# Check
php -m | grep -E 'sockets|pcntl|openssl'

# Install on Ubuntu/Debian
sudo apt install php-cli php-sockets
```

**For the static binary:** Nothing. The PHP runtime is included.

**Windows:** The server runs in single-threaded mode (`--workers=0` only) unless `qbix_fork.dll` + FFI is available. Static files, PHP scripts, WebSocket, caching, compression, access control — everything works. You lose fork-per-request isolation and signal-based graceful shutdown without `pcntl`. Good for development; for production use Linux or macOS (or WSL).

---

## 📦 Three Ways to Run

### 1. From source (needs PHP 8.1+)

```bash
php qbixserver.php --root=./web --port=8080
```

### 2. PHAR — single file (needs PHP)

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

The binary bundles PHP 8.3 + extensions into a single ~15MB executable. Copy it to any Linux or macOS machine and run. No dependencies.

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

The binary is built using [static-php-cli](https://github.com/crazywhalecc/static-php-cli), which compiles PHP + extensions into a statically linked binary.

GitHub Actions automatically builds binaries for **Linux x86_64**, **Linux ARM64**, **macOS x86_64**, and **macOS Apple Silicon** on every tagged release.

---

## Examples

Six example apps are included in `examples/`:

| App | What it demonstrates |
|---|---|
| [todo](examples/todo) | SQLite CRUD, REST API, static HTML |
| [counter](examples/counter) | SQLite persistence, GET/POST |
| [chat](examples/chat) | WebSocket rooms, Socket.IO, 8 handler files |
| [stream](examples/stream) | Server-Sent Events, AI token streaming |
| [swarm](examples/swarm) | Q::event() dispatch, cluster replication |
| [collab](examples/collab) | Collaborative editing |

```bash
php qbixserver.php --root=examples/todo/web --port=8080
```

---

## Migrating from another server

Already running nginx, Apache, or Caddy? These guides show the config mapping:

- [Migrating from nginx](docs/migrate-nginx.md) — server blocks, try_files, proxy_pass, gzip
- [Migrating from Apache](docs/migrate-apache.md) — .htaccess works unchanged, VirtualHost → domains config
- [Migrating from Caddy](docs/migrate-caddy.md) — automatic HTTPS, on-demand TLS → autohost

---

## Single-Binary Distribution

Package your app into one executable file — PHP runtime, web server, and all your code. The binary includes SQLite auto-provisioning: if your app bundles a `.sqlite` file, the server copies it to the data directory on first run and writes the framework config to point at it. No external database needed.

Supported out of the box: Qbix (detects plugins, writes `local/app.json` with per-plugin prefixes), Laravel (`.env`), Symfony (`.env`), WordPress (`wp-config.php` + wp-sqlite-db), Craft CMS, and Drupal.

Sign binaries with ECDSA P-256 keys (M-of-N threshold), publish to Sigstore Rekor for independent verification, and customize by editing the binary as a zip file.

- [Building and distributing binaries](docs/binaries.md) — pack, sign, verify, customize, platform code signing

---

## Mesh Networking

Every Qbix Server instance has a cryptographic identity (ECDSA P-256, same security model as Ethereum). When two servers discover each other — over Bluetooth, Wi-Fi, or TCP — they perform an ECDH handshake and establish an AES-256-GCM encrypted session. All traffic is encrypted end-to-end, even through relay nodes.

```php
// Talk to a nearby server (transport is automatic)
$response = Q::handleUsingRemote('qbix-peer://' . $peerId . '/api/data');

// React to peers
Q_WebServer_Transport::onPeerOnline(function ($peer) {
    // Sync data, exchange messages, coordinate
});
```

Multi-hop routing extends range beyond direct connections. The router uses distance-vector routing with HELLO/BYE/HEARTBEAT propagation, TTL limits, and deduplication. Intermediate nodes relay encrypted payloads they cannot read.

Data sync runs automatically when peers connect: Bloom filter exchange identifies what's different, then only the missing records transfer. For large datasets (10,000+ records), the protocol switches to prolly tree comparison — a deterministic content-addressed tree where identical subtrees are skipped entirely.

See [docs/Mesh.md](docs/Mesh.md) for the full protocol specification, edge cases, and security analysis.

---

## Mobile

Qbix Server runs on iOS and Android as a native app. A Swift (iOS) or Kotlin (Android) shell starts the embedded PHP server on `127.0.0.1`, points a WebView at it, and handles peer-to-peer transport. The PHP process runs your Qbix app exactly as it would on a desktop or VPS — no Cordova, no Capacitor, no JavaScript bridge.

The native TransportManager discovers nearby peers over every available channel and picks the best transport automatically:

| Priority | Transport | Bandwidth | Platforms |
|---|---|---|---|
| 1 | TCP (LAN) | 100+ Mbps | iOS + Android |
| 2 | MultipeerConnectivity | 2–25 Mbps | iOS only |
| 3 | BLE GATT | ~2 Mbps | iOS + Android |

If Wi-Fi drops, traffic falls back to BLE seamlessly. The PHP server sees HTTP on localhost regardless of transport.

Background persistence: iOS uses a silent AVAudioEngine session (App Store precedent: PocketServer, BitChat). Android uses a Foreground Service with `START_STICKY`.

### Preparing a mobile project

In the control panel, select your app and click **Prepare** with the iOS or Android platform selected. This generates a native project scaffold under `apps/YourApp/mobile/ios/` or `apps/YourApp/mobile/android/` with all the Swift/Kotlin source files, manifest, build config, and a `Server/` (iOS) or `assets/` (Android) directory where you place the PHP binary or phar.

### Building for iOS

1. Get the `qbixserver-ios-arm64` micro binary from the CI release artifacts, or build it locally with [static-php-cli](https://github.com/crazywhalecc/static-php-cli). For Simulator testing, just place `qbixserver.phar` in the Server directory — PhpBridge falls back to the system PHP on macOS.
2. Generate the Xcode project: `cd apps/YourApp/mobile/ios && xcodegen generate`
3. Open the `.xcodeproj`, set your signing team, and build.

For distribution, archive in Xcode and upload to App Store Connect via the Organizer or `xcodebuild -exportArchive`. See [mobile/iOS.md](mobile/iOS.md) for the full walkthrough including signing, TestFlight, and App Store submission.

### Building for Android

1. Get the `qbixserver-android-arm64` micro binary from the CI release artifacts, or cross-compile it locally with static-php-cli and the Android NDK. For debug testing, `qbixserver.phar` also works.
2. Place the binary (or phar) in `app/src/main/assets/`.
3. Build: `cd apps/YourApp/mobile/android && ./gradlew assembleDebug` (or `bundleRelease` for Play Store).

For distribution, sign the release AAB and upload to the Google Play Console. See [mobile/Android.md](mobile/Android.md) for the full walkthrough including keystore setup, signing, and Play Store submission.

### More details

- [mobile/README.md](mobile/README.md) — transport layer architecture, BLE GATT service definition, chunking protocol, platform requirements
- [mobile/iOS.md](mobile/iOS.md) — PhpBridge, xcodegen, background persistence, signing, TestFlight, App Store
- [mobile/Android.md](mobile/Android.md) — PhpBridge, Gradle, Foreground Service, signing, Play Store

---

## 🌐 Autohost — Automatic Domain Provisioning

Point a domain at your Qbix Server and it's live. No manual setup, no config files to edit — the server validates the hostname, verifies DNS, provisions a TLS certificate via Let's Encrypt, and starts serving, all on the first request.

This enables multi-tenant SaaS, white-label hosting, and customer-owned domains. A customer points their DNS, the server does the rest. Combined with [collaborative branches](#-collaborative-branches), you can build a site on a branch, promote it, and have autohost serve it the moment DNS arrives — or let autohost provision first and build after.

```json
{
    "Q": {
        "webserver": {
            "autohost": {
                "enabled": true,
                "authorize": "allowlist",
                "allowlist": ["*.myplatform.com", "app.acme.com"],
                "acmeEmail": "admin@example.com"
            }
        }
    }
}
```

Three authorization modes control which domains are accepted: **open** (any hostname that passes DNS verification), **allowlist** (wildcard patterns like `*.myplatform.com`), or a **custom PHP hook** for database-driven authorization. Rate limiting and DNS mismatch caching prevent abuse.

The server auto-detects which PHP framework is installed (Laravel, WordPress, Symfony, Drupal, and [11 more](docs/FRAMEWORKS.md)) and selects the appropriate document root preset.

See [AUTOHOST.md](docs/AUTOHOST.md) for configuration, self-serve provisioning flows, and planned domain claim verification.

---

## 🌿 Collaborative Branches

Any Qbix Server on the network becomes a workspace. Multiple people — or AI assistants — can work on a running PHP app at the same time, each on their own branch, without touching the live site. Each branch is a full copy-on-write clone: its own filesystem, its own database, its own credentials. Preview it on a subdomain, edit it through the panel or an AI tool, merge it when ready.

This is what makes Qbix Server more than a web server — it's a development and collaboration platform built into the infrastructure layer.

### How it works

```bash
# Create a branch from the control panel or API
curl -X POST http://localhost:8080/Q/panel/api/branches/create \
  -d '{"appHost":"myapp.test","branchName":"feature-redesign"}' \
  -H "Authorization: Bearer $TOKEN"

# The branch is immediately accessible at its own subdomain
# https://feature-redesign.myapp.test  (auto-provisioned TLS)
# Or via header: X-Q-Branch: feature-redesign
# Or via cookie: _q_branch=feature-redesign
```

Each branch gets:

- **Copy-on-write filesystem** — symlinks to trunk; writes create real copies in the branch directory
- **Cloned database** — SQLite file copy, MySQL PDO clone (or `mysqldump` fallback), PostgreSQL `CREATE DATABASE ... TEMPLATE`
- **Its own subdomain** — `{branch}.{apphost}` with automatic TLS certificate provisioning
- **Isolated credentials** — per-branch database users with access only to the branch's own data

The branch is a fully working copy of the app that can be previewed, edited, and tested independently — and it costs almost nothing because the filesystem is CoW and the database clone is fast.

### Permissions and lockdown

Branches use a two-axis permission model:

| Axis | Values | Controls |
|---|---|---|
| **Branch permission** | `view`, `edit`, `admin` | Who can see, push to, or configure the branch |
| **File tier** | `styles`, `markup`, `frontend`, `code` | Which file types the user can push |

The `styles` tier allows only CSS/SCSS/LESS/SASS. `markup` adds HTML, SVG, Markdown, templates, images, fonts, JSON, XML, YAML. `frontend` adds JS/TS/JSX/TSX/Vue/Svelte. `code` allows everything including PHP. Tiers are cumulative.

**Branches are locked down by default.** New branches get `markup` tier (no JavaScript or PHP), `.env*`/`.git/`/`vendor/`/`node_modules/` deny paths, and shell execution disabled. Admins relax restrictions per-branch through the API. On Linux, optional UID/GID isolation gives each branch its own system user for OS-level sandboxing.

### AI-assisted editing

Any Qbix Server exposed to the network becomes a workspace that AI coding assistants can safely edit. Claude, Cursor, Windsurf, ChatGPT, or any MCP-compatible tool connects to the server's [MCP endpoint](/mcp), authenticates with a scoped token, and reads/writes files on a branch — never trunk.

```
POST /mcp
Authorization: Bearer <token>
Content-Type: application/json

{"jsonrpc":"2.0","method":"tools/call","params":{
  "name":"branch_patch",
  "arguments":{"appHost":"myapp.test","branchName":"feature",
    "patch":"<unified diff>","commitMessage":"Fix header layout"}
}}
```

The server enforces file-tier permissions and deny-path rules on every write. When git is installed, each patch becomes a real VCS commit, so merge requests show a proper commit log. Branches can push to or pull from repos on other servers — staging-to-production promotion, distributed editing across multiple Qbix instances, and CI integration all work out of the box.

Available MCP tools: `branch_list`, `branch_create`, `file_list`, `file_read`, `branch_export`, `branch_push`, `branch_patch`, `branch_request_merge`, plus full panel management tools. See [AI Collaboration](docs/COLLABORATION.md) for the full workflow.

### The end-to-end story

1. **Build** — an admin or AI assistant creates a branch, customizes a template app, iterates
2. **Review** — a merge request goes to the control panel; admins review the diff and commit log
3. **Promote** — on approval, branch files replace trunk
4. **Serve** — if autohost is enabled, the customer points their domain and the site is live with TLS in seconds

This workflow runs entirely on Qbix Server — no external CI, no separate git hosting, no deployment pipeline to configure. Add more servers and they federate: push a branch from staging to production, replicate config, pin each other's identity via [OpenClaiming](docs/api-discovery.md#well-knownopenclaiminghostnameserverjson).

---

## 📊 Metrics & Analytics

Qbix Server includes two complementary observability systems. Both are zero-config — no external services, no agents to install.

### Client-side metrics (opt-in)

Set `Q.webserver.clientMetrics.enabled` to `true` and the server injects telemetry scripts into every HTML page it serves. The bundled trackers report scroll depth, section reads, media playback, SPA navigation, outbound clicks, and dwell time. Events arrive as JSON via `sendBeacon` and are stored as daily TSV files. A "Client Metrics" tab in the control panel shows daily event counts, top pages, and top event types, with raw TSV download for offline analysis.

The injection system also supports arbitrary JS/CSS via `extraScripts` and `extraStyles`, and respects `Sec-Fetch-Dest` so subresource fetches and iframes are never injected.

Metrics.js works standalone on any website — include the script, point it at a POST endpoint, and it runs without Qbix Server. Minified builds (`.min.js`) ship alongside the full versions.

### Server-side analytics (automatic)

Every HTTP request is recorded in SQLite with session tracking, navigation flow, user agent parsing (platform + browser), and primary language. No client-side opt-in is needed — the server records requests as part of its normal metrics collection.

The "Analytics" tab in the control panel provides:

- **Sankey flow diagram** — interactive d3 visualization of how users navigate between pages. Click any node to drill down into its incoming and outgoing flows.
- **Session replay** — browse individual sessions with their full request timeline: entry page, every subsequent navigation, timestamps, response times, and status codes.
- **Filters** — narrow any view by host (app), time period, platform, browser, language, IP prefix, or path prefix.
- **Overview stats** — page views, unique sessions, unique IPs, average response time, top pages, top platforms, top browsers, and top languages.

A mini Sankey also appears in the Apps tab for a quick traffic overview.

Six API endpoints expose the analytics data programmatically for integration with external dashboards or custom reporting.

See [METRICS.md](docs/METRICS.md) for the full reference — event format, tracker options, standalone usage, analytics API endpoints, SQLite schema, and Q framework integration.

---

## 🔌 With Qbix Platform

Qbix Server is extracted from the [Qbix Platform](https://github.com/Qbix/Platform) — a full-stack framework for building social apps with real-time streams, user management, and plugin architecture.

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

The standalone mode (without `--app`) runs as a plain web server — no framework, no plugins. PHP files execute directly, static files serve from memory. Use this for simple sites, APIs, or any project that doesn't need the full Qbix stack.

See [--app Mode & SAPI Internals](docs/app-mode.md) for details.

---

## 📧 Email & SMS Relay

Qbix Server v3.2 adds email and SMS as first-class transports — the same way it handles HTTP and WebSocket. Add provider config, run the same `php qbixserver.php`, and the server spawns a relay process that handles everything: inbound receiving, outbound delivery, MIME parsing, conversation threading, digest batching, rate limiting, and SMS.

No relay config = no relay process = no overhead. When config is present, the relay starts automatically as a managed sibling process.

```json
{
  "Q": {
    "relay": {
      "smtp": {
        "host": "smtp-relay.gmail.com",
        "port": 587
      },
      "mobile": {
        "provider": "twilio",
        "accountSid": "ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
        "authToken": "your_auth_token",
        "fromNumber": "+15551234567"
      }
    }
  }
}
```

### Email

The relay includes a full **inbound SMTP server** (default port 2525) and an **outbound SMTP client** that works with any provider. Point your MTA or SES receipt rules at `127.0.0.1:2525` and the relay parses MIME, extracts text/HTML/attachments, threads conversations by Message-ID, stores everything in SQLite, and fires `Q::event()` hooks — so handling incoming email is the same pattern as handling an HTTP request.

Outbound delivery works with **Gmail/Google Workspace** (SMTP relay for up to 10,000 recipients/day, or Gmail SMTP with app passwords), **Amazon SES**, **SendGrid**, **Mailgun**, or any SMTP-compatible service. The relay handles TLS negotiation, authentication, and delivery logging.

```php
<?php
// Send an email
Q_Relay_SmtpClient::deliver('app@yoursite.com', 'user@example.com', $rawMime);

// Handle incoming email
Q::event('Q/relay/email/incoming', [
    'from'    => 'sender@example.com',
    'to'      => 'recipient@example.com',
    'subject' => 'Re: Hello',
    'text'    => 'Plain text body',
    'html'    => '<p>HTML body</p>',
    'parsed'  => [/* full MIME parse result */],
]);
```

### SMS

Twilio integration gives your app a phone number for sending and receiving SMS. Inbound webhooks are validated with HMAC-SHA1 — invalid signatures get a 403. The same `Q::event()` pattern:

```php
<?php
Q_Relay_Mobile::send('+15559876543', 'Your code is 123456');

Q::event('Q/relay/sms/incoming', [
    'from' => '+15551234567',
    'body' => 'Message text',
]);
```

### Digest batching and rate limiting

When a sender sends multiple messages to the same recipient in quick succession (notification storms), the relay batches them into a single digest email. The first message delivers immediately; subsequent messages queue with exponential backoff and flush as one digest. Transactional emails (password resets, verification codes) bypass this with an `X-Qbix-No-Digest: true` header.

A **token bucket** rate limiter controls outbound throughput (default 60/minute), and an **hourly circuit breaker** (default 1,000/hour) prevents runaway sending. Rate-limiter state persists to SQLite — crashes and restarts don't reset the counters.

### Storage

All messages, threads, delivery logs, and rate-limiter state live in a local SQLite database at `local/relay.db` (8 tables, WAL mode, created automatically). The relay tracks conversation threading, per-user inbox with read/unread state, and a full delivery audit trail.

### Process model

The relay runs as a separate process from the web server — a bug in SMTP parsing can't crash HTTP serving, and provider credentials (SMTP passwords, Twilio tokens) never enter the web server's memory. Each inbound message forks a ~120KB COW child process, the same model as HTTP requests. The relay can also run standalone (`php bin/qbixrelay.php`) with its own systemd service for production deployments that want separate process management.

See [RELAY.md](docs/RELAY.md) for provider-specific setup guides (Google Workspace with sender avatars, SES, SendGrid, Mailgun), the incoming-email vs. WebSocket comparison, Twilio configuration, database table schemas, Qbix Platform integration, and the full configuration reference. See [RELAY-PLAN.md](docs/RELAY-PLAN.md) for the architecture design document.

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

                    ┌──────────────────┐
 SMTP / SMS  ────→ │  Relay Process   │ auto-spawned sibling
                    │ (qbixrelay.php) │ stream_select loop
                    └────────┬─────────┘
                             │
             ┌───────────────┼───────────────┐
             │               │               │
        ┌────▼─────┐   ┌────▼─────┐   ┌────▼─────┐
        │ Inbound  │   │ Outbound │   │  Mobile  │
        │ SMTP     │   │ SMTP     │   │  (SMS)   │
        │          │   │          │   │          │
        │ :2525    │   │ SES etc  │   │ Twilio   │
        └──────────┘   └──────────┘   └──────────┘
```

**Static files** are served from an in-memory response cache. The full HTTP response (headers + body) is pre-built and sent in a single `fwrite()` call. The cache is mtime-validated with configurable check intervals. Combined with `TCP_NODELAY`, this delivers sub-millisecond response times.

**PHP scripts** run in-process (single-threaded, suitable for lightweight APIs) or in a pre-fork worker pool (`--workers=N`) for concurrent PHP execution. Workers are forked after class preloading, so they share the base memory footprint via copy-on-write pages.

**The remaining gap** versus nginx (55–73%) is inherent: nginx uses `sendfile()` (kernel-space file→socket copy), `epoll` (O(1) event notification), and compiled C. PHP's `stream_select` is `select(2)`, file serving goes through userspace, and every operation has interpreter overhead. Getting to 55–73% of C performance from pure interpreted PHP is about as good as it gets.

---

## 🌐 HTTP/2 Support

The built-in event loop uses `stream_select` — zero dependencies, works everywhere. But if you install [amphp](https://amphp.org/), the server upgrades to a full HTTP/2 server with no code changes:

```bash
composer require amphp/http-server amphp/socket
php qbixserver.php --port=8443
```

| | HTTP/1.1 (built-in) | HTTP/2 (amphp) |
|---|---|---|
| Connections per page load | ~6 parallel | 1 multiplexed |
| Header overhead | Full headers per request | HPACK compressed |
| Event loop | `stream_select` (portable) | `epoll`/`kqueue` via Revolt |
| TLS | `stream_socket_enable_crypto` | amphp native TLS |
| Server push | No | Yes (push static assets before browser asks) |

The server has a clean two-layer architecture. `Q_WebServer::route()` handles all request logic (static files, PHP dispatch, cache, access control) and returns a `[status, headers, body]` array. The transport layer is pluggable — both transports use the same routing, caching, and access control logic.

**Either way:** You can always put Cloudflare, CloudFront, or nginx in front as a reverse proxy. The CDN terminates HTTP/2 (and HTTP/3) for you, forwarding HTTP/1.1 to the backend.

---

## Why PHP

Every web server faces a tradeoff between performance and isolation. Persistent-process servers (Node, Go, Java, Python WSGI, PHP-FPM, Swoole) keep workers alive across requests — fast, but memory leaks accumulate and one request's data (including secrets) can reach another through shared address space. Process-per-request servers (CGI) give each request a clean process — safe, but each process costs 30–60MB and takes milliseconds to start.

The only OS mechanism that eliminates this tradeoff is copy-on-write `fork()`: the parent pre-loads the application, each request gets a forked child with its own address space, and the kernel *shares memory pages* until the child writes to them. Per-worker cost: ~120KB. Startup time: microseconds. Isolation: absolute — the child's address space is reclaimed by the OS when it exits, so leaks can't accumulate and secrets can't cross requests.

COW fork requires an interpreter with a compact heap and shared-nothing per-request semantics. PHP is the only major language where both properties hold, and millions of existing web applications already assume the one-request-then-die model. That's why this server is written in PHP. It's able to run thousands of existing web apps including OwnCloud, WordPress, Magento, Drupal, Symfony, Laravel, and Qbix.

### The problem with php-fpm

Whether opcache is enabled or not, the vast majority of production PHP code is I/O-bound. Workers wait for the database, the filesystem, an API call, a cache server. During that wait, the worker is doing nothing — but it's still holding 30–60MB of RAM. That's the bottleneck. On a 4GB server, php-fpm gets maybe 80 workers. Each one blocks on a 200ms query, so you get ~400 req/s. That's the ceiling. Qbix is a pure PHP server. Each request runs in its own process via COW fork — OS-enforced isolation means memory leaks can't accumulate and secrets can't leak between requests, at 120KB per worker instead of 50MB.

### The problem with Swoole, RoadRunner, and FrankenPHP

They try to solve this by making PHP evented, like Node.js. Swoole's coroutines can multiplex I/O within a single worker — but only if you rewrite your code to use `Swoole\Coroutine\MySQL`, `Swoole\Coroutine\Http\Client`, and so on. Every `PDO::query()`, every `file_get_contents()`, every `curl_exec()` in every WordPress plugin, Laravel package, and Drupal module uses blocking I/O. It doesn't yield. Swoole can't help with code that doesn't cooperate. RoadRunner and FrankenPHP don't even try coroutines — they use the same worker-count-limited model as fpm.

### How Qbix solves it

Instead of making each worker do more, Qbix runs more workers. The server loads your entire framework into a parent process, then calls `pcntl_fork()`. The kernel marks every page copy-on-write. Each worker shares the parent's loaded classes and only pays for pages it actually writes to during the request. A WordPress-like request dirties 30 pages = 120KB. So the same 4GB that gives fpm 80 workers gives Qbix thousands.

Your code runs unmodified, in two modes:

**Persistent workers (default)** — workers stay alive across requests. Between each request, a Reflection-based snapshot restores all static properties in 0.03ms. 27 PHP functions (`header()`, `session_start()`, `ini_set()`, `set_error_handler()`, etc.) are shimmed via source transformation so they reset correctly. This is how you get 2,294 req/s on CPU-bound work and 1,060 req/s under I/O.

**Fork-per-request** — if persistent mode doesn't work for your code (functions with internal static variables, plugins that register global state in ways the shim can't track), set `forkPerRequest: true`. Each request gets a fresh fork. It's slower than persistent mode, but each forked worker still costs only 120KB instead of 50MB, so you can run 100× more of them than fpm on the same hardware. That's the whole point — blocking I/O doesn't matter when you have enough workers, and COW makes "enough workers" nearly free.

### Why this matters more than speed

The fork model gives you something no persistent-process server can: hardware-enforced isolation between requests.

**Memory leaks can't accumulate.** In Swoole, RoadRunner, or any persistent-process server (Node, Go, Python WSGI), a memory leak in request handling grows with every request until the worker is recycled. If a Laravel controller allocates an array it forgets to unset, that memory stays allocated for the next request, and the next, and the next. Worker recycling limits how bad it gets, but it doesn't prevent it. In Qbix Server, the child process handles one request and calls `exit()`. The OS reclaims the entire address space. There is no "next request" in the same process. The parent's memory is untouched — COW means the child's writes never propagate back.

**Secrets can't leak across requests.** In a persistent-process server, request A's variables live in the same address space as request B. If request A processes a credit card number and the handler has a bug — doesn't clear the variable, stores it in a class property, logs it to a debug buffer — request B's handler can read it. A memory dump contains both requests' data intermixed. With fork-per-request, request A runs in process 17432 and request B runs in process 17433. They have separate virtual address spaces. There is no mechanism — no bug, no misconfiguration, no race condition — by which B can read A's memory, because A's address space was reclaimed by the kernel when the process exited. This is the same isolation principle behind Chrome's site isolation and why operating systems use separate address spaces for separate users.

Every other PHP execution model makes you choose: FastCGI/Swoole/RoadRunner give you performance but sacrifice isolation, traditional CGI gives you isolation but sacrifices performance. The COW fork trick is the only architecture that provides both — the performance of a persistent server (pre-loaded parent, microsecond fork, 120KB per worker) with the safety of CGI (each request gets a clean process that dies after).

This is the real argument for security-conscious deployments. You can run untrusted PHP plugins, WordPress with unaudited third-party themes, legacy code nobody has reviewed — and a bug in one request's handling cannot affect another request's data because the OS enforces the boundary.

---

## 📄 License

[MIT](LICENSE) — use it however you want.

Part of the [Qbix Platform](https://github.com/Qbix/Platform).

We [proposed `switch_global_context()` for PHP core](https://discourse.thephp.foundation/t/php-dev-three-proposals-for-php-9/2113). While that works its way through the RFC process, the server does it in userland today.
