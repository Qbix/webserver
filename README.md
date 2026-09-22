# ⚡ Qbix Server

### Run your existing PHP codebase 10–100× faster than nginx + php-fpm

A pure PHP web server. No nginx, no Apache, no php-fpm. One process serves static files, PHP scripts, WebSocket connections, and a live dashboard.

### The problem with php-fpm

Whether opcache is enabled or not, the vast majority of production PHP code is I/O-bound. Workers wait for the database, the filesystem, an API call, a cache server. During that wait, the worker is doing nothing — but it's still holding 30–60MB of RAM. That's the bottleneck. On a 4GB server, php-fpm gets maybe 80 workers. Each one blocks on a 200ms query, so you get ~400 req/s. That's the ceiling.

### The problem with Swoole, RoadRunner, and FrankenPHP

They try to solve this by making PHP evented, like Node.js. Swoole's coroutines can multiplex I/O within a single worker — but only if you rewrite your code to use `Swoole\Coroutine\MySQL`, `Swoole\Coroutine\Http\Client`, and so on. Every `PDO::query()`, every `file_get_contents()`, every `curl_exec()` in every WordPress plugin, Laravel package, and Drupal module uses blocking I/O. It doesn't yield. Swoole can't help with code that doesn't cooperate. RoadRunner and FrankenPHP don't even try coroutines — they use the same worker-count-limited model as fpm.

### How Qbix solves it

Instead of making each worker do more, Qbix runs more workers. The server loads your entire framework into a parent process, then calls `pcntl_fork()`. The kernel marks every page copy-on-write. Each worker shares the parent's loaded classes and only pays for pages it actually writes to during the request. A WordPress-like request dirties 30 pages = 120KB. So the same 4GB that gives fpm 80 workers gives Qbix thousands.

Your code runs unmodified, in two modes:

**Persistent workers (default)** — workers stay alive across requests. Between each request, a Reflection-based snapshot restores all static properties in 0.03ms. 28 PHP functions (`header()`, `session_start()`, `ini_set()`, `set_error_handler()`, etc.) are shimmed via source transformation so they reset correctly. This is how you get 2,294 req/s on CPU-bound work and 1,060 req/s under I/O.

**Fork-per-request** — if persistent mode doesn't work for your code (functions with internal static variables, plugins that register global state in ways the shim can't track), set `forkPerRequest: true`. Each request gets a fresh fork. It's slower than persistent mode, but each forked worker still costs only 120KB instead of 50MB, so you can run 100× more of them than fpm on the same hardware. That's the whole point — blocking I/O doesn't matter when you have enough workers, and COW makes "enough workers" nearly free.

### What it replaces

| | nginx + php-fpm | Qbix Server |
|---|---|---|
| 💾 **Memory per worker** | 30–60MB (duplicated) | ~200KB (COW, measured) |
| 👥 **Concurrent PHP** (1GB) | ~24 workers | **~5,000** (typical) |
| 🔒 **Isolation** | Statics leak between requests | Snapshot reset — no leaks |
| 🚀 **Throughput** (CPU-bound) | ~400 req/s (Swoole 4w) | **2,294 req/s** (100w) |
| 🚀 **Throughput** (I/O, same RAM) | 78 req/s (fpm/Swoole 4w) | **1,060 req/s** (100w) |
| 🌐 **WebSocket** | Needs a separate server | Built in |
| 🧩 **Cache invalidation** | Whole-page only | `X-Cache-Tree` — per-component |
| ⚙️ **Setup** | nginx + fpm pools + sockets | `php qbixserver.php` |

See [BENCHMARKS.md](docs/BENCHMARKS.md) for full methodology and [reset.md](docs/reset.md) for what gets restored between requests.

### What a "real-time PHP app" used to require

**nginx** for reverse proxy and static files. **php-fpm** to run PHP. **Node.js** for a Socket.IO server. **Redis** for pub/sub between fpm and Node. **supervisor** to keep it all running. **Docker** to make it deployable. Six processes, three languages, two runtimes.

Qbix Server replaces all six with one process. HTTP, WebSocket (with Socket.IO protocol), SSE, sessions, uploads, static files, .htaccess — same port, same file. No Redis, no Node, no pub/sub glue. Download a 4.5MB binary, run it, done. Pure PHP.

You can also package your entire app — code, assets, SQLite database — into that binary and distribute it as a single file. Double-click on Windows, `./myapp --open` on Mac or Linux, the browser opens and the app is there. No PHP to install, no web server to configure, no database to set up. 5 MB, not 200 — because we open the browser that's already there instead of shipping Chromium like Electron does. [How it works →](#single-binary-distribution)

---


## Documentation

| | Topic | What it covers |
|---|---|---|
| 🏎️ | [Why Not php-fpm?](docs/why.md) | COW memory model, comparison with Swoole and FrankenPHP |
| 🔒 | [Server Headers](docs/headers.md) | Cache-Control, X-Cache-Tree, X-Accel-Redirect, ETag |
| 🌐 | [HTTP](docs/http.md) | Fork-per-request mode, request lifecycle |
| 🔌 | [WebSocket & Rooms](docs/websocket.md) | Process per connection, rooms, Socket.IO, SSE, chat example |
| 🛤️ | [Routing](docs/routing.md) | Clean URLs, .htaccess, DirectoryIndex |
| 📂 | [PHP Framework](docs/framework.md) | The micro-framework: handlers, events, Q classes |
| ⚙️ | [Configuration](docs/configuration.md) | JSON config, CLI options, presets |
| 📦 | [Running & Building](docs/running.md) | Source, phar, binary. Building static binaries. Requirements |
| 📀 | [Binaries & Signing](docs/binaries.md) | Pack apps, manage like zip, ECDSA M-of-N signing, Rekor, platform signing |
| 🏗️ | [Architecture](docs/architecture.md) | Persistent workers, COW, execution model, mental model, benchmarks |
| 📊 | [Dashboard & Panel](docs/dashboard.md) | Live stats, control panel tabs |
| 🚀 | [Deploy & Federation](docs/deploy.md) | Rsync deploy, cluster replication, inter-server trust |
| 🔍 | [API Discovery](docs/api-discovery.md) | OpenAPI, MCP, qbix.json, HTTP/2 |
| 🧩 | [Compatibility](docs/compatibility.md) | SAPI emulation, 28 shimmed functions, class ownership, tests |
| 📈 | [Benchmarks](docs/BENCHMARKS.md) | Full methodology and numbers |
| 🔄 | [State Reset](docs/reset.md) | What gets restored between requests |
| 🔀 | [Migrate from nginx](docs/migrate-nginx.md) | Server blocks, try_files, proxy_pass, gzip |
| 🔀 | [Migrate from Apache](docs/migrate-apache.md) | .htaccess unchanged, VirtualHost mapping |
| 🔀 | [Migrate from Caddy](docs/migrate-caddy.md) | Automatic HTTPS, on-demand TLS → autohost |
| ✅ | [Test Results](docs/TestResults.md) | 140 end-to-end tests |
| 🗺️ | [Roadmap](docs/roadmap.md) | What's next |
| 📄 | [License](docs/license.md) | MIT |

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

## Use With Your Existing Codebase

If you already have a PHP app running on nginx + php-fpm, switching is one command. The server reads your `.htaccess`, rewrites URLs to your front controller, and runs your code with 28 functions shimmed so static variables, sessions, and headers work correctly between requests.

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

The server intercepts 28 PHP functions (`header()`, `session_start()`, `setcookie()`, `ini_set()`, etc.) via source transformation at include time. Your code calls `header()` and it works — the server captures it. Between requests, all static properties are restored from a snapshot in 0.03ms. See [Compatibility](docs/compatibility.md) for the full list.

### What to watch for

Most apps work immediately. A few things to be aware of:

- **`define()` constants** persist between requests in persistent workers. If a plugin defines a constant conditionally, the second request sees it already defined. Rare in practice.
- **`stream_wrapper_register()`** persists. Uncommon outside testing frameworks.
- **Long-running scripts** (migrations, imports) should use `--workers=1` or run via CLI directly.
- **Extensions that store C-level state** (e.g. some custom PECL modules) won't reset between requests. Standard extensions (PDO, curl, mbstring) are fine.

## Platform Support

| Platform | Workers | COW | Mode |
|---|---|---|---|
| **Linux** x86_64, aarch64 | pcntl_fork | Yes — 120KB per worker | Persistent or fork-per-request |
| **macOS** Intel, Apple Silicon | pcntl_fork | Yes | Persistent or fork-per-request |
| **FreeBSD** | pcntl_fork | Yes | Persistent or fork-per-request |
| **Windows** x64 | php-cgi subprocess | No | Persistent workers with source-transform shimming |

On Linux and macOS, the server runs thousands of COW-forked workers at ~120KB each. On Windows, pcntl doesn't exist, so the server spawns `php-cgi` subprocesses for process isolation. Workers are still persistent and shimmed — the same code runs, you just don't get the COW memory savings. The 28-function source transform still clears state between requests.

**PHP 8.6+ (epoll/kqueue):** The server auto-detects PHP 8.6's native `Io\Poll` API and uses `epoll` on Linux or `kqueue` on macOS for event notification — no PECL extensions needed. On older PHP versions, the server uses `stream_select` (which works fine, just O(n) per tick instead of O(1)). Revolt is also supported if installed.

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

## Migrating from another server

Already running nginx, Apache, or Caddy? These guides show the config mapping:

- [Migrating from nginx](docs/migrate-nginx.md) — server blocks, try_files, proxy_pass, gzip
- [Migrating from Apache](docs/migrate-apache.md) — .htaccess works unchanged, VirtualHost → domains config
- [Migrating from Caddy](docs/migrate-caddy.md) — automatic HTTPS, on-demand TLS → autohost

## Single-Binary Distribution

Package your app into one executable file — PHP runtime, web server, and all your code. The binary includes SQLite auto-provisioning: if your app bundles a `.sqlite` file, the server copies it to the data directory on first run and writes the framework config to point at it. No external database needed.

Supported out of the box: Qbix (detects plugins, writes `local/app.json` with per-plugin prefixes), Laravel (`.env`), Symfony (`.env`), WordPress (`wp-config.php` + wp-sqlite-db), Craft CMS, and Drupal.

Sign binaries with ECDSA P-256 keys (M-of-N threshold), publish to Sigstore Rekor for independent verification, and customize by editing the binary as a zip file.

- [Building and distributing binaries](docs/binaries.md) — pack, sign, verify, customize, platform code signing

## License

[MIT](LICENSE) — use it however you want.

We [proposed `switch_global_context()` for PHP core](https://discourse.thephp.foundation/t/php-dev-three-proposals-for-php-9/2113). While that works its way through the RFC process, the server does it in userland today.
