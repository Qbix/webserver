## 🏎️ Why Not php-fpm?

php-fpm re-bootstraps the framework on every request (10–50ms), uses ~42MB per worker, and leaks statics between requests. Qbix Server loads the framework once at startup, keeps workers persistent, resets all state in 0.03ms via Reflection + 28 function shims, and fits each worker in ~120KB thanks to copy-on-write.

```
php-fpm:
  Every request:
    Load PHP → include autoloader → boot framework (10–50ms)
    → run your code → send response → worker resets (statics leak)

  Cost: 42MB per worker. 4 workers on 200MB. 78 req/s at 50ms I/O.

Qbix Server:
  Once at startup:
    Load PHP → include autoloader → load ALL classes → snapshot statics
    → fork workers (inherit everything via COW)

  Every request:
    Worker receives request → run your code → restore snapshot (0.03ms)

  Cost: 120KB per worker. 400+ workers on 200MB. 1,060 req/s at 50ms I/O.
```

Three things fpm can't do: (1) persistent workers that don't leak state — Qbix resets 28 shimmed functions + all statics between requests, fpm resets nothing. (2) Thousands of workers on the same RAM — COW means each worker only pays for the pages it writes, not the 30MB of loaded framework. (3) Run unmodified blocking PHP code at high concurrency — when workers cost 120KB each, you can have enough of them that blocking I/O doesn't matter.

**The trick that makes it all work: fork after preload.** The server loads your entire framework — every class, every route, every config file — into a single parent process. Then it calls `pcntl_fork()` to create workers. The kernel doesn't copy the parent's 30MB of memory; it marks the pages copy-on-write. Workers share every loaded class, every compiled route, every cached config. They only pay for the pages they actually write to during the request — measured at 30 pages = 120KB for a WordPress-like workload. This is pure userland PHP. No kernel module, no C extension, no custom allocator. Just `pcntl_fork()` after loading everything, and the OS does the rest.

### The numbers, honestly

| | php-fpm (4w) | Swoole (4w) | **Qbix** (100w) |
|---|---|---|---|
| **CPU-bound (WP-like)** | ~350 req/s | ~400 req/s | **2,294 req/s** |
| **I/O 50ms (c=200)** | 78 req/s | ~300* req/s | **1,060 req/s** |
| **I/O 200ms (c=400)** | 20 req/s | ~200–500* req/s | **488 req/s** (200w) |
| **Memory / worker** | ~42MB | ~42MB | **~200KB** (COW) |
| **Workers on 200MB** | 4 | 4 | **100–400** |
| **State isolation** | Statics leak | Statics leak | **Snapshot reset (28 shims)** |
| **Unmodified WordPress** | ✅ | ❌ no adapter | **✅** (source transform) |
| **Requires extension** | — | **Yes** (PECL) | **No** |

\* Swoole numbers with `Runtime::enableCoroutine()`. Without coroutine hooks, Swoole matches fpm. WordPress and most Laravel packages use blocking I/O.

With `"forkPerRequest": true`, Qbix falls back to fork-per-request mode (7ms fork overhead) — still 2–5× fpm on I/O workloads because each child uses ~200KB instead of 42MB. Useful for shared hosting with untrusted code.

> **Important:** Database connections must NOT be opened before `fork()`. A TCP connection is a single file descriptor — two processes writing to the same socket would interleave packets and corrupt the protocol. Each forked child opens its own connection. This is the same model as php-fpm (one connection per request) and is what connection poolers like PgBouncer or ProxySQL are designed for.

### Why it's actually better in practice

The throughput gap narrows significantly for real applications. A framework like Qbix with 20 loaded plugins spends 10–50ms on bootstrap per fpm request — time that Qbix Server eliminates entirely because the forked child inherits all loaded classes. For a request that does 5ms of actual work:

```
nginx + fpm:    30ms bootstrap + 5ms work           =  35ms  (29 req/s per worker) Qbix Server:    7ms fork      + 0ms bootstrap + 5ms =  12ms  (83 req/s per fork)
```

The heavier the framework, the more the fork model catches up. And the memory savings let you run 100× more of them simultaneously.

---

## ⚖️ vs FrankenPHP and Swoole

If you're looking beyond php-fpm, you've probably seen FrankenPHP and Swoole. Here's how they compare:

| | FrankenPHP | Swoole | Qbix Server |
|---|---|---|---|
| **Language** | Go + C (embeds PHP) | C extension for PHP | Pure PHP |
| **Install** | Download Go binary or Docker | `pecl install swoole` (compiles C) | `php qbixserver.php` — nothing to install |
| **Architecture** | Worker mode (persistent) | Coroutine-based (persistent) | Persistent workers with snapshot restore (default). Fork-per-request available for maximum isolation |
| **State leaks** | ⚠️ Possible — workers persist, must audit statics | ⚠️ Possible — must manage globals carefully | ✅ Fork mode: impossible (process dies). Octane: snapshot restores all statics/globals/superglobals between requests |
| **PHP compatibility** | Most code works, some edge cases | Many extensions incompatible, blocking I/O breaks coroutines | ✅ 100% — standard PHP, nothing unusual |
| **Memory safety** | Go runtime + PHP = complex interaction | C extension = segfault risk | PHP only = memory-safe by default |
| **Access control** | No X-Accel-Redirect equivalent | Manual implementation | ✅ Built-in X-Accel-Redirect |
| **Component cache** | No | No | ✅ X-Cache-Tree — sub-page invalidation |
| **Early hints / 103** | ✅ Yes | No | Via amphp |
| **HTTP/2** | ✅ Built-in (Caddy) | ✅ Built-in | ✅ Via amphp |
| **WebSocket** | Via Mercure | ✅ Built-in | ✅ Built-in |
| **PHP throughput** (CPU-bound) | 350 req/s | ~400 req/s | **2,294 req/s** (octane 100w) |
| **PHP throughput** (same 200MB, I/O 50ms) | 78 req/s | ~300 req/s (coroutines) | **151 req/s** (fork) / **1,060 req/s** (octane 100w) |
| **PHP throughput** (same 200MB, I/O 200ms) | 20 req/s | ~200–500 req/s (coroutines) | **94 req/s** (fork) / **488 req/s** (octane 200w) |
| **Static throughput** | 10,038 req/s | 26,974 req/s | **18,311 req/s** |
| **Concurrent capacity** | Limited by worker memory | Limited by worker memory | **100–300× more** (COW, measured) |
| **WebSocket rooms** | No | Manual | ✅ Built-in — rooms are forked processes |
| **Code signing** | No | No | ✅ M-of-N manifest signing |
| **Unmodified WordPress** | ✅ | ❌ | ✅ (28-function shim) |

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

**Fork mode (default):** each request forks from the preloaded parent, inherits loaded classes and config (read-only, shared via COW), runs in its own process, and dies when done. No state leaks. No audit needed. Your existing PHP code works exactly as it does on php-fpm.

**Default mode:** persistent workers handle multiple requests, but a snapshot restore resets all class statics, `$GLOBALS`, `$_GET`, `$_POST`, `$_COOKIE`, `$_SERVER`, `$_FILES`, response headers, and error state between every request — verified by 13 dedicated snapshot isolation tests. The code above would work correctly because `$cached` is restored to `null` between requests. See [reset.md](docs/reset.md) for the full reset table.

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

**php-fpm** is the standard, and there's nothing wrong with it for small apps. But it tops out at ~24 workers per GB of RAM, leaks statics between requests, and every request re-bootstraps the framework. Qbix fork mode doubles its I/O throughput on the same hardware with no configuration.

**FrankenPHP** gives you HTTP/3 and automatic HTTPS via Caddy. If you need HTTP/3 today, that's the one reason. Everything else — throughput, memory, state isolation, WordPress compatibility — Qbix does better.

**Swoole** has one unique trick: coroutine fan-out within a single worker (one request making 50 parallel HTTP calls). If that's your architecture, it helps. But it requires a C extension, breaks many PHP extensions, needs framework-specific adapters, leaks state, and will never run unmodified WordPress.

**Qbix Server** beats fpm on I/O (14× throughput on the same RAM), beats Swoole on CPU-bound work (2,294 vs ~400 req/s), runs unmodified WordPress/Laravel/Symfony/Drupal with 28 shimmed functions, and adds WebSocket rooms, SSE, microservice isolation, cluster replication, code signing, and a live dashboard. No extensions, no Docker, no Go. One PHP file.

---

---
[← Back to README](../README.md)

