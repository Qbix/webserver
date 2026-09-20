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

**Static files** are served from an in-memory response cache. The full HTTP response (headers + body) is pre-built and sent in a single `fwrite()` call. The cache is mtime-validated with configurable check intervals. Combined with `TCP_NODELAY`, this delivers sub-millisecond response times.

**PHP scripts** run in-process (single-threaded, suitable for lightweight APIs) or in a pre-fork worker pool (`--workers=N`) for concurrent PHP execution. Workers are forked after class preloading, so they share the base memory footprint via copy-on-write pages.

**Static files** are served at ~20K req/s (pure PHP, in-memory cache, single `fwrite`). nginx is ~2.5× faster (50K req/s) because it uses `sendfile()` (kernel-space file→socket copy) and compiled C. For production, put nginx or a CDN in front for static files and let Qbix Server handle PHP execution, WebSocket, and access-controlled file serving.

---

## ⚡ Persistent Workers (default)

Persistent workers with automatic state reset. Combines fpm's throughput with fork-per-request's memory isolation.

```bash
php qbixserver.php --app=/path/to/myapp --workers=40
```

The parent preloads your framework (classes, config, routes, autoloader), takes a snapshot of every static property on every user-defined class, then forks N workers. Each worker handles requests in a loop. Between requests, the snapshot is restored — all statics, globals, superglobals, and response state are reset to their preloaded values. Cost: ~0.05ms, vs ~8ms for a full fork.

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

After reset, the next request sees exactly the same state as the first request this worker ever handled — the same static values, the same empty globals, the same clean superglobals. Secrets from request A (cookies, Authorization headers, POST passwords, session tokens) are guaranteed invisible to request B.

### What does NOT reset

These are inherent PHP limitations, not something the snapshot can work around:

| Category | Why | Mitigation |
|---|---|---|
| **Class declarations** | PHP has no `unclass()` — once a class is loaded, it stays in the class table for the lifetime of the process | Guard inline classes with `class_exists()` (see below) |
| **`require`/`include` state** | A file included once stays in `get_included_files()` | Use `require_once` — it's already idempotent |
| **C extension internals** | Redis connections, libcurl handles, ext-level caches | Close/reset in destructors or shutdown functions |
| **Closures capturing references** | A closure that captured `&$static` holds a live reference that bypasses the snapshot | Rare in practice; avoid capturing statics by reference |
| **File descriptors** | An opened file handle persists in the process | Close file handles when done — same as fpm |

For anything the snapshot can't reach, `maxRequests` (default 1000) recycles the worker after N requests — the process exits and a clean one is forked. This is the same safety net fpm uses via `pm.max_requests`.

### ⚠️ The class declaration gotcha

This is the most common octane pitfall. In fork-per-request mode, every request gets a fresh process, so inline class declarations always work:

```php
// WORKS in fork mode (process dies after each request)
// FATAL on the SECOND request (persistent workers) to this script:
//   "Cannot declare class Counter, because the name is already in use" class Counter {
    public static $n = 0;
} Counter::$n++;
echo Counter::$n;
```

The fix is a one-line guard:

```php
// WORKS in both modes if (!class_exists('Counter', false)) {
    class Counter {
        public static $n = 0;
    }
} Counter::$n++;  // always 1 — the snapshot resets $n to 0 between requests echo Counter::$n;
```

The `false` parameter prevents autoloading — it checks only whether the class is already declared in this process. On the first request, the class is declared. On the second request in the same worker, `class_exists` returns true and the declaration is skipped. The snapshot still resets `$n` to its default value (`0`) between requests, so the counter always reads 1.

**Classes in `classes/` are fine.** The autoloader loads each class file via `require_once`, which is already idempotent. This gotcha only affects classes declared inline inside scripts (e.g. in `web/` PHP files or handler files).

**The Qbix Platform is octane-safe.** All Platform classes live in `classes/` and are autoloaded with `require_once`. Inline classes in handlers are rare and already guarded.

### What it means in practice

On a 200MB memory budget with 50ms I/O workloads:

| Mode | Workers | req/s | p50 | Memory |
|---|---|---|---|---|
| fpm | 4 × 42MB | 78 | 505ms | 168MB |
| **octane** | **100 × ~200KB** | **1,060** | **56ms** | **~8MB** |

Octane uses **3× less RAM** for **14× more throughput** at **9× lower latency.**

### Auto-introspection

Scripts that define inline classes (with the `class_exists` guard) are handled automatically. The snapshot system detects newly declared classes via `get_declared_classes()` after each request and adds their static properties to the snapshot using `ReflectionProperty::getDefaultValue()`. No manual registration, no interface to implement. This is what makes it strictly better than Laravel Octane's `ResetScope`, which depends on package authors opting in.

### Writing octane-safe scripts

A few rules of thumb:

```php
// ✅ DO: guard inline class declarations if (!class_exists('MyCache', false)) {
    class MyCache { public static $data = []; }
}

// ✅ DO: use statics for per-request state — they reset automatically MyCache::$data['key'] = compute();

// ✅ DO: close resources when done $fh = fopen('/tmp/report.csv', 'w'); fwrite($fh, $csv); fclose($fh)
;  // don't leave it open

// ❌ DON'T: declare classes without the guard class Foo {}
// fatal on second request

// ❌ DON'T: store secrets in $GLOBALS expecting them to persist $GLOBALS['api_key'] = getenv('API_KEY')
;  // cleared between requests

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

Both modes preload your framework before handling requests. The difference is whether the preloaded state is inherited via fork (8ms) or reused in a loop with snapshot restore (0.05ms).

### Configuration

```json
{
  "Q": {
    "webserver": {
      "workers": 40,
      "forkPerRequest": false,
      "maxRequests": 1000
    }
  }
}
```

- `workers` — number of persistent workers (0 = fork per request)
- `octane` — enable snapshot restore in the worker loop (default: true when workers > 0)
- `maxRequests` — recycle workers after this many requests (default: 1000, 0 = unlimited)

## 💡 The mental model

Three files for a complete real-time app:

```
handlers/game/join.php       ← adds player to static $players handlers/game/move.php       ← updates static $positions, broadcasts handlers/game/leave.php      ← removes player, notifies room
```

No Redis. No message queue. No pub/sub infrastructure. No WebSocket library. No event loop to learn. Just PHP files in a folder.

The developer's decision tree:

```
Does this data matter after disconnect?
  No  → static variable              (cursors, typing, game positions)
  Yes → database call                (messages, scores, transactions)

Does anyone else need to see it?
  No  → just update your static var
  Yes → $room->broadcast()
```

Ephemeral state lives in RAM — static variables in the per-connection process. It's fast (no I/O), isolated (per-user process boundary), and self-cleaning (process dies on disconnect, OS reclaims everything). When you need durability, call your preloaded classes to write to a database. When you need to notify others, call `$room->broadcast()`.

The same `handlers/` directory serves HTTP requests, WebSocket messages, and routed clean URLs. The same `classes/` directory is preloaded and shared across all of them. One server, one codebase, one mental model.

```
Static files:    GET /style.css            → web/style.css PHP scripts:     GET /page.php             → web/page.php Routed:          GET /api/users            → handlers/api/users/get.php Socket.IO:       42["chat/message",{...}]  → handlers/chat/message.php Bare WebSocket:  {"event":"chat/message"}  → handlers/chat/message.php Legacy:          GET /wp-admin/post.php    → php-cgi (full compatibility)
```

When you outgrow it — when you need the full dispatch pipeline, Streams for real-time data synchronization, or the component-level cache invalidation with Merkle trees — the same handlers run on the [Qbix Platform](https://github.com/Qbix/Platform) without changes. The upgrade path is adding capability, not rewriting architecture.

---

## Execution model

One request, one PHP process. Qbix and ordinary PHP both assume a process handles a single request and then dies; this server preserves that.

| Platform | Mode | How |
|---|---|---|
| Unix (`pcntl`) | fork | Parent preloads classes, config and DB; forks per request; child runs the script and `exit(0)`s. Copy-on-write means no interpreter startup and no framework bootstrap — the memory and isolation advantage comes from this model. The fork itself costs ~7ms, which is the throughput trade-off vs. persistent workers. |
| Windows / no `pcntl` | `php-cgi` | A real SAPI process per request, spawned via `Q.webserver.cgi.patterns`. Slower (full interpreter startup) but handles arbitrary PHP. |

The parent never runs application code. It owns the socket, the reverse cache and static files, and forks. Because no request state is ever populated in the parent, children inherit a clean slate and nothing needs to be reset between requests.

## Benchmarks — Qbix Server vs nginx+fpm vs Swoole vs FrankenPHP

Full results in [BENCHMARKS.md](docs/BENCHMARKS.md). Key findings:

**Head-to-head (4 workers each, same scripts, one server at a time):**

| | Swoole | fpm | Qbix octane | FrankenPHP |
|---|---|---|---|---|
| CPU ~2ms (c=4) | **483**/s | 469/s | 439/s | 350/s |
| 50ms I/O (c=40) | 78/s | 78/s | 77/s | 39/s |
| Static 13KB | 26,974/s | 50,742/s | 18,311/s | 10,038/s |

Octane matches Swoole and fpm on I/O. The 7% CPU gap is IPC overhead (parent dispatches to workers via Unix sockets, same as fpm's FastCGI).

**Same memory budget (200MB, 50ms I/O, c=40):**

| | fpm 4w (200MB) | octane 40w (200MB) |
|---|---|---|
| req/s | 78 | **1,060** |
| p50 | 505ms | 56ms |

**9× throughput, 9× lower latency** — because octane fits 100–300× more workers (measured for typical handlers) on the same RAM.

---
[← Back to README](../README.md)

