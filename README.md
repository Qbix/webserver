# ⚡ Qbix Server v2

https://qbixserver.com is an all-in-one server that handles everything for you. Drop files in folders. Get real-time applications that can handle millions of users. Produce and distribute [standalone binaries](#single-binary-distribution) that can run on Linux, Mac, Windows, and now - iOS and Android too. Qbix Server v2 lets you build secure decentralized apps that can even work offline, over WiFi and Bluetooth.

When serving millions of people, safety becomes very important. [Learn why the server is written in PHP.](#why-php). Today's PHP ecosystem has also produced thousands of useful web frameworks including OwnCloud, Wordpress, Magento, Drupal, Symfony, Laravel, and more. Qbix server can run them all, unmodified. It also gives you a dashboard and visual control panel to manage all your apps, domains, certificates, etc. in one place.

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


## Documentation

| | Topic | What it covers |
|---|---|---|
| 🏎️ | [Why Not php-fpm?](docs/why.md) | COW memory model, comparison with Swoole and FrankenPHP |
| 🔒 | [Server Headers](docs/headers.md) | Cache-Control, X-Cache-Tree, X-Accel-Redirect, ETag |
| 🗂️ | [Static Files](docs/static-files.md) | ETag/304, compression, precompression cache |
| 🖼️ | [Image Processing](docs/images.md) | Resize with `?w=`, AVIF/WebP negotiation, Save-Data, disk cache, limits |
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
| 🧩 | [Compatibility](docs/compatibility.md) | Running Laravel, Symfony, WordPress, Drupal unmodified: what gets rewritten and why |
| 🧬 | [--app Mode & SAPI Internals](docs/app-mode.md) | SAPI emulation, class ownership, test suites |
| 🌐 | [Mesh Protocol](docs/Mesh.md) | Identity, handshake, routing, encryption |
| 🔄 | [Data Sync](docs/sync.md) | Bloom filters, prolly trees, conflict resolution, current limits |
| 📱 | [iOS & Android](docs/mobile.md) | Running on phones, transports, permissions |
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

### What to watch for

Most apps work immediately. A few things to be aware of:

- **`define()` constants** persist between requests in persistent workers. If a plugin defines a constant conditionally, the second request sees it already defined. Rare in practice.
- **`stream_wrapper_register()`** persists. Uncommon outside testing frameworks.
- **Long-running scripts** (migrations, imports) should use `--workers=1` or run via CLI directly.
- **Extensions that store C-level state** (e.g. some custom PECL modules) won't reset between requests. Standard extensions (PDO, curl, mbstring) are fine.

## Platform Support

| Platform | Workers | COW | Transport |
|---|---|---|---|
| **Linux** x86_64, aarch64 | pcntl_fork | Yes — 120KB per worker | TCP, mDNS |
| **macOS** Intel, Apple Silicon | pcntl_fork | Yes | TCP, mDNS |
| **FreeBSD** | pcntl_fork | Yes | TCP, mDNS |
| **Windows** x64 | php-cgi subprocess | No | TCP |
| **iOS** arm64 | NativePHP / php-ios | — | TCP, MultipeerConnectivity, BLE GATT |
| **Android** arm64 | Phphone / NativePHP | — | TCP, BLE GATT, NSD |

On Linux and macOS, the server runs thousands of COW-forked workers at ~120KB each. On Windows, pcntl doesn't exist, so the server spawns `php-cgi` subprocesses for process isolation. On iOS and Android, the PHP runtime is embedded in a native app shell; the TransportManager handles peer discovery and transport negotiation automatically.

**PHP 8.6+ (epoll/kqueue):** The server auto-detects PHP 8.6's native `Io\Poll` API and uses `epoll` on Linux or `kqueue` on macOS for event notification — no PECL extensions needed. On older PHP versions, the server uses `stream_select` (which works fine, just O(n) per tick instead of O(1)). Revolt is also supported if installed.

**GitHub Actions CI** builds and tests on Linux x86_64, Linux aarch64, macOS arm64, Windows x64, plus experimental Android and iOS targets. 464 tests, 0 failures.

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

## Mobile

Qbix Server runs on iOS and Android via NativePHP Mobile, php-ios, or Phphone. The native TransportManager discovers nearby peers over every available channel and picks the best transport automatically:

| Priority | Transport | Bandwidth | Platforms |
|---|---|---|---|
| 1 | TCP (LAN) | 100+ Mbps | iOS + Android |
| 2 | MultipeerConnectivity | 2–25 Mbps | iOS only |
| 3 | BLE GATT | ~2 Mbps | iOS + Android |

If Wi-Fi drops, traffic falls back to BLE seamlessly. The PHP server sees HTTP on localhost regardless of transport.

Background persistence: iOS uses a silent AVAudioEngine session (App Store precedent: PocketServer, BitChat). Android uses a Foreground Service with `START_STICKY`.

See [mobile/README.md](mobile/README.md) for the native code, GATT service definition, and platform requirements.

### Why PHP?

Every web server faces a tradeoff between performance and isolation. Persistent-process servers (Node, Go, Java, Python WSGI, PHP-FPM, Swoole) keep workers alive across requests — fast, but memory leaks accumulate and one request's data (including secrets) can reach another through shared address space. Process-per-request servers (CGI) give each request a clean process — safe, but each process costs 30–60MB and takes milliseconds to start.

The only OS mechanism that eliminates this tradeoff is copy-on-write `fork()`: the parent pre-loads the application, each request gets a forked child with its own address space, and the kernel *shares memory pages* until the child writes to them. Per-worker cost: ~120KB. Startup time: microseconds. Isolation: absolute — the child's address space is reclaimed by the OS when it exits, so leaks can't accumulate and secrets can't cross requests.

COW fork requires an interpreter with a compact heap and shared-nothing per-request semantics. PHP is the only major language where both properties hold, and millions of existing web applications already assume the one-request-then-die model. That's why this server is written in PHP. It's able to run thousands of existing web apps including OwnCloud, Wordpress, Magento, Drupal, Symfony, Laravel, and Qbix.

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


## License

[MIT](LICENSE) — use it however you want.

We [proposed `switch_global_context()` for PHP core](https://discourse.thephp.foundation/t/php-dev-three-proposals-for-php-9/2113). While that works its way through the RFC process, the server does it in userland today.
