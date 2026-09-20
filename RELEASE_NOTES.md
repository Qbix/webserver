# Qbix Server v1.3.0 – Boldly Go

## Cross-Platform Release

This release adds Windows support, a built-in ACME client, a control panel with framework management, and the ability to bundle your app into a single distributable binary.

### Windows COW Fork

Qbix Server now runs on Windows with copy-on-write process forking — the same memory model that makes it fast on Linux and macOS. A small DLL (`qbix_fork.dll`, 15KB) calls the Windows kernel's `RtlCloneUserProcess` via FFI to fork the server process with COW page sharing. Each worker shares the parent's loaded PHP classes and only allocates memory for pages it writes to during the request.

If the DLL isn't present or the FFI extension isn't available, the server falls back to php-cgi subprocess mode — still functional, just without COW memory savings.

### Download

| Platform | Binary | COW Fork |
|---|---|---|
| Linux x86_64 | `qbixserver-linux-x86_64` | Yes (pcntl) |
| Linux ARM64 | `qbixserver-linux-aarch64` | Yes (pcntl) |
| macOS ARM64 | `qbixserver-macos-arm64` | Yes (pcntl) |
| Windows x64 | `qbixserver-windows-x64.exe` + `qbix_fork.dll` | Yes (RtlCloneUserProcess) |

### What's New

**ACME v2 Client** — built-in Let's Encrypt integration. Set `"tls": "auto"` on a domain and the server provisions certificates on startup via HTTP-01 challenges. No certbot needed.

**Control Panel** — 11 tabs managing every aspect of the server:
- **Apps** — list, create, serve apps. Per-app fork mode toggle (persistent / fork-per-request / auto).
- **Domains** — add domains, view cert status, one-click ACME provisioning.
- **Workers** — live pool stats (count, active, memory, PID).
- **Logs** — real-time access and error log viewer.
- **Cron** — scheduled tasks with "Run Now".
- **Frameworks** — auto-detects Laravel, Symfony, WordPress, Drupal, Joomla. Shows packages from composer.lock, wp-cli, drush, or filesystem scanning. Install, update, remove, activate/deactivate from the panel. Clone plugins from GitHub URLs.
- **Plugins** (Qbix) — three-layer version tracking: declared (config/app.json), installed (local/plugins.json), schema (Q_plugin table + extra JSON). npm/composer badges per plugin. Run the Qbix installer (`install.php --all`) from the panel.
- **System** — PHP version, extensions, disk space, phpinfo() iframe. Key extensions highlighted.

**Docs Viewer** — browse all 18 markdown docs at `/Q/docs`. Dark theme, sidebar nav, marked.js bundled locally (no CDN). Links between docs work. Offline fallback parser if marked.js is missing.

**`--pack` Flag** — bundle your app into the binary: `./qbixserver --pack=./my-app -o myapp`. The binary detects the appended zip at startup and serves from it. Standard zip tools can list and extract the contents. Distribute a single executable file containing the PHP runtime, the server, and your entire application.

**System Limits Check** — on startup, reads `ulimit -n`, `ulimit -u`, `pid_max`. Tries to raise limits automatically. Warns with platform-specific fix commands if it can't.

**Dashboard Enhancements** — system RAM usage (Linux/macOS/Windows), per-worker RSS from `/proc`/`ps`/`tasklist`, COW savings comparison, fork mode indicator.

### Framework Presets

```bash
php qbixserver.php --root=public --preset=laravel
php qbixserver.php --root=public --preset=symfony
php qbixserver.php --root=.     --preset=wordpress
php qbixserver.php --root=web   --preset=drupal
```

### Two Modes

**Persistent workers (default)** — workers stay alive across requests. 28 PHP functions shimmed via source transformation. Static properties restored in 0.03ms. Best performance: 2,294 req/s on CPU-bound work.

**Fork-per-request** — set `forkPerRequest: true` for code with internal static variables or untrackable global state. Each worker costs ~120KB (COW), so you run 100× more workers than php-fpm on the same hardware.

### Bug Fixes

- Pool superglobals (SERVER_SOFTWARE, PHP_SELF, etc.) not set in persistent workers
- Multipart parsing passed lowercased content-type
- Health endpoint 500 after docs route insertion
- Safe worker calculation returned PHP_INT_MAX when fd limit was low
- `//` comment fallback missing in API doc generation

### Stats

- 72 unit tests + 55 end-to-end tests, 0 failures
- README split into 18 doc files with relative links
- Panel: 3,882 lines
- WebServer: 5,554 lines
- Total server code: ~13,000 lines of PHP + 165 lines of C
