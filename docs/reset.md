# Octane Mode: What Gets Reset Between Requests

Qbix Server's persistent-worker mode ("octane mode") keeps workers alive across
requests, like php-fpm and Laravel Octane. Unlike fork-per-request, no process
creation happens per request — the worker loops, handling one request after
another with a snapshot restore between them.

## What gets reset (automatic)

| State | How | Cost |
|---|---|---|
| **Static class properties** | Snapshot at startup, restore via `ReflectionProperty::setValue` | 0.05ms (201 properties, 25 classes) |
| **$_GET, $_POST, $_COOKIE, $_FILES** | Re-populated from the request | 0 (overwritten) |
| **$_SERVER** | Re-populated from the request | 0 (overwritten) |
| **$_REQUEST** | Rebuilt from $_GET + $_POST | 0 |
| **$_SESSION** | `session_write_close()` + `$_SESSION = []` | ~0 |
| **Output buffers** | `ob_end_clean()` to level 0, then `ob_start()` | ~0 |
| **Error state** | `error_clear_last()` | ~0 |
| **Custom globals** | Snapshot + restore (skipping superglobals) | ~0.01ms |

**Total reset cost: ~0.06ms** — compared to 8ms for `pcntl_fork()`.

## What persists (by design)

| State | Why | Risk | Mitigation |
|---|---|---|---|
| **OPcache** | Bytecode is read-only, shared | None | Same as fpm |
| **Loaded classes** | That's the whole point — COW sharing | None | Immutable after load |
| **APCu cache** | Shared memory, not per-process | None | Same as fpm |
| **Interned strings** | PHP internal optimization | None | Read-only |
| **Autoloader maps** | Registered once at startup | None | Same as fpm |

## What persists (needs care)

| State | Risk | What we do | What the developer should do |
|---|---|---|---|
| **Database connections** | Previous request's transaction state | Flush: `ROLLBACK` if in transaction, `RESET SESSION` on MySQL | Close and reopen per request, or use a connection pooler (PgBouncer, ProxySQL) |
| **File handles** | Open descriptors leak across requests | Close all non-server handles | Use `fclose()` in shutdown handlers |
| **Stream contexts** | Custom SSL/proxy settings persist | Reset default context | Avoid `stream_context_set_default()` |
| **Registered shutdown functions** | Accumulate across requests | Cannot be unregistered in PHP | Avoid `register_shutdown_function()` in request code; use destructors instead |
| **Signal handlers** | Previous request's handlers persist | Restore server's handlers | Don't call `pcntl_signal()` in request code |
| **Singletons with state** | Object persists, internal state leaks | **Covered by static reset** if stored in a static property | If stored elsewhere (closures, globals), manually reset |
| **cURL handles** | Cookies, auth headers persist | Close per request | Don't reuse `curl_init()` across requests |

## How it compares

### vs php-fpm

| | php-fpm | Qbix octane mode |
|---|---|---|
| Static properties | **Persist** — leak between requests | **Reset** via snapshot restore |
| Globals | **Persist** — leak between requests | **Reset** via snapshot restore |
| Superglobals | Reset by the SAPI | Reset by the server |
| Memory per worker | ~50 MB (independent bootstrap) | ~5 MB (COW from parent) |
| DB connections | Persist (risk) | Persist (same risk, same mitigation) |
| OPcache | Shared across workers | Shared via parent process |
| `max_requests` recycling | Worker dies and respawns periodically | Not needed — statics are reset, not accumulated |

php-fpm's `pm.max_requests` exists specifically because statics and globals
leak. Octane mode doesn't need it because the snapshot restore cleans them.

### vs Laravel Octane (Swoole/RoadRunner)

| | Laravel Octane | Qbix octane mode |
|---|---|---|
| Reset mechanism | App-level: `$app->flush()`, `Container::forgetInstances()` | Language-level: `ReflectionProperty::setValue` on all statics |
| Coverage | Only what Laravel's flusher knows about | **All user-defined classes**, automatically |
| Third-party packages | Must implement `ResetScope` interface | **Covered automatically** — their statics are reset too |
| Memory model | Swoole: shared memory, coroutines | COW fork: isolated memory per worker |
| New class detection | Manual registration | Automatic via `get_declared_classes()` |
| Risk of missing a reset | High — depends on package authors | Low — reflection covers everything PHP can introspect |

Laravel Octane's biggest problem is that third-party packages often DON'T
implement the reset interface, causing subtle state leaks. The reflection-based
approach resets everything regardless of whether the package author thought
about it.

### What reflection CAN'T reset

These are the same in all persistent-worker systems (fpm, Octane, Swoole):

1. **C extension internal state** — PHP has no API to introspect it
2. **Closures capturing references** — if a closure captured `&$static`,
   resetting the property doesn't affect the closure's binding
3. **Resources** — file handles, DB connections, sockets are kernel objects;
   PHP can only close them, not "reset" them
4. **State in external services** — Redis keys, database rows, message queues

For (1) and (2), `pcntl_fork()` is the only bulletproof solution. For (3) and
(4), no execution model helps — the developer must manage external state.

## Configuration

```json
{
    "Q": {
        "webserver": {
            "workers": 40,
            "workerMode": "persistent",
            "snapshot": {
                "resetStatics": true,
                "resetGlobals": true,
                "flushDb": true,
                "maxRequests": 0
            }
        }
    }
}
```

Setting `maxRequests` to a non-zero value recycles workers after N requests,
as a safety net against any state the snapshot can't reach (C extension
internals, accumulated closures). Set to 0 to disable recycling.

## The escape hatch

If a specific script is known to leak state that snapshot restore can't clean
(e.g., a C extension that caches aggressively), route it to fork-per-request
mode:

```json
{
    "Q": {
        "webserver": {
            "fork": { "patterns": ["legacy/.*", "unsafe-extension.php"] }
        }
    }
}
```

These scripts still get the preloaded classes via COW, but each request forks
a fresh child process — guaranteed clean state at the cost of ~8ms fork overhead.
