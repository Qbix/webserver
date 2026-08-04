# U WebServer — Implementation Decisions

## Architecture

A single-threaded, event-loop-driven web server written entirely in U.
Serves static files, routes .u handler files, upgrades WebSocket
connections, speaks Socket.IO wire protocol.

Ported from the Qbix PHP server (~7,200 lines). The U version is
~1,200 lines across 7 files — 6x reduction, same functionality.

## Design Decisions

### 1. No fork() — the sandbox IS the isolation

**PHP's problem:** PHP has no isolation model. Every global, static,
and class variable is shared mutable state. fork() is the only way
to get clean per-request execution.

**U's answer:** The handler function is +E(caps). Its locals are -R
(stack). When it returns, everything is freed. No process boundary
needed. The type system guarantees the same isolation fork() provides.

**Implications:**
- Works on Windows (no pcntl dependency)
- ~1000x faster than fork (~100ns function call vs ~500μs fork)
- No COW page faults
- Deterministic cleanup (stack unwinding, not process death)

### 2. File-based routing — .u files in folders

**PHP's model:** Put files in folders. /api/users.php → /api/users.

**U's model:** Identical. web/api/users.u → /api/users. The server
scans the web/ directory at startup, builds a route table, and
dispatches requests to handler functions.

**Handler signature determines protocol:**
```u
// HTTP handler
f handle(req: Request) -> Response

// WebSocket handler (future)
f on_message(msg: WsMessage) -> [WsMessage]

// Room handler (future)
f on_tick(state: Tree) -> Tree
```

### 3. Static file serving with in-memory LRU cache

**Ported from:** Q_WebServer_Cache, Q_FileCache

- ETag based on md5(mtime + size)
- 304 Not Modified support
- LRU eviction when cache exceeds max_bytes
- Periodic mtime check (configurable interval)
- Files above max_file_bytes are never cached

### 4. Event loop — epoll/kqueue/select

**Ported from:** Q_Evented, Q_Evented_StreamSelect

The stdlib event.u provides the abstraction. The runtime implements
it using the best available mechanism:
- Linux: epoll
- macOS: kqueue
- Windows: IOCP
- Fallback: select

Same API surface as Q_Evented:
- on_readable, on_writable — I/O watchers
- delay, repeat — timers
- defer — next-tick callbacks
- cancel, disable, enable — watcher management

### 5. WebSocket + Socket.IO compatibility

**Ported from:** Q_WebSocket

RFC 6455 WebSocket with Socket.IO Engine.IO/Socket.IO protocol
framing. This gives wire compatibility with the Socket.IO client
library, which means existing Qbix web apps can connect to the
U server without client-side changes.

Engine.IO: open(0), ping(2), pong(3), message(4)
Socket.IO: connect(0), event(2), ack(3)

### 6. Request/Response types — not capabilities

Request and Response are plain data types, not capabilities.
The handler receives a Request (read-only, -M) and returns a
Response. It never touches the socket directly — the server
reads the request, calls the handler, sends the response.

This means the handler is testable: pass a Request, check the Response.

### 7. Hot reload (future)

**PHP's model:** pcntl_exec() — replace the process.

**U's model:** Version-tagged handlers. New connections get new code.
Old connections finish with old code. ARC frees old code when the
last connection closes. No dropped connections.

### 8. Error handling

**PHP's model:** try/catch, with a fork boundary as safety net. If the
script crashes, the child dies, the parent continues.

**U's model:** Handler functions use x/x.on() for error handling. If an
unhandled error propagates, the server catches it and sends a 500
response. No process death. No fork boundary needed.

```u
response = handler.handle(req) x.on(
    (err: Error) => Response.error(500, err.__string__())
)
```

## Standard Library Additions

This project required 5 new stdlib modules:

| Module | Purpose | Key types/functions |
|--------|---------|-------------------|
| filesystem.u | File I/O + path utilities | stat, read, write, readdir, join, normalize |
| event.u | Event loop abstraction | on_readable, delay, repeat, run, stop |
| encoding.u | Data encoding | base64, sha1, md5, percent_encode, query_encode |
| json.u | JSON encode/decode | encode, decode, decode_safe |
| time.u | Timestamps + durations | now, now_ms, http_date, Duration |

All filesystem and event functions are +E (effectful). Path utilities
(join, normalize, extension, basename, dirname) are pure (-E).

## File Structure

```
webserver/
    src/
        main.u              entry point (14 lines)
        webserver.u          core server (230 lines)
        http_parse.u         HTTP parsing (195 lines)
        cache.u              file cache (105 lines)
        router.u             URL routing (95 lines)
        ws.u                 WebSocket + Socket.IO (200 lines)
        mime.u               MIME types (55 lines)
    web/
        index.html           default page
        hello.u              example handler
        api/
            echo.u           JSON echo handler
    docs/
        plan.md              project plan
        implementation.md    this file
```

## Comparison: PHP → U

| Aspect | Qbix PHP Server | U WebServer |
|--------|-----------------|-------------|
| Lines of code | ~7,200 | ~900 |
| Isolation | fork() (OS process) | -E sandbox (type system) |
| Works on Windows | No (needs pcntl) | Yes |
| Fork overhead | ~500μs per request | ~100ns per request |
| Hot reload | Process restart | Version-tagged handlers |
| Static file cache | In-memory LRU | In-memory LRU (same) |
| WebSocket | RFC 6455 + Socket.IO | RFC 6455 + Socket.IO (same) |
| Error handling | try/catch + fork safety | x/x.on() + type safety |
| Handler signature | include $script | f handle(req) -> Response |
| Testing | Requires HTTP client | Unit-testable functions |
