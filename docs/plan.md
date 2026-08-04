# U WebServer — Port Plan

## Source: Qbix PHP Server (~7,200 lines across 15 files)

## Phase 1: Standard Library Additions (stdlib/)

These are general-purpose types that belong in U's stdlib, not
in the WebServer application.

### 1.1 filesystem.u — File system operations
Source: PHP's file functions used throughout the server
- stat(path) → FileStat (size, mtime, is_dir, is_file, permissions)
- read(path) → [B]
- read_text(path) → S
- write(path, data: [B]) → none
- write_text(path, text: S) → none
- exists(path) → L
- is_dir(path) → L
- is_file(path) → L
- readdir(path) → [DirEntry]
- mkdir(path) → none
- delete(path) → none
- realpath(path) → S
- extension(path) → S
- basename(path) → S
- dirname(path) → S
- join(parts: [S]) → S (path joining)

### 1.2 net.u additions — TCP listener, TLS
Source: PHP's stream_socket_server, stream_socket_accept
- Net.listen(host, port) → Listener
- Listener.accept() → Conn
- Conn.read(max) → [B], Conn.write(data) → none, Conn.close()
- TLS.wrap(conn, cert, key) → Conn
- Net.set_nonblocking(conn)

### 1.3 event.u — Event loop (epoll/kqueue/select)
Source: Q_Evented, Q_Evented_StreamSelect
- EventLoop.on_readable(conn, handler)
- EventLoop.on_writable(conn, handler)
- EventLoop.delay(ms, handler) → TimerId
- EventLoop.repeat(sec, handler) → TimerId
- EventLoop.cancel(id)
- EventLoop.defer(handler)
- EventLoop.run() — main loop
- EventLoop.stop()

### 1.4 time.u — Timestamps, durations
Source: PHP's microtime, time, date
- Time.now() → D
- Time.now_ms() → I
- Time.format(d, fmt) → S

### 1.5 encoding.u — Base64, hex, URL encoding
Source: PHP's base64_encode, urlencode, sha1
- Encoding.base64_encode(data) → S
- Encoding.base64_decode(s) → [B]
- Encoding.sha1(data) → S (for WebSocket accept key)
- Encoding.percent_encode(s) → S
- Encoding.percent_decode(s) → S

### 1.6 mime.u — MIME type lookup (already in HTTP.u, expand)

### 1.7 json.u — JSON encode/decode
- JSON.encode(tree) → S
- JSON.decode(s) → Tree +N

## Phase 2: WebServer Application (src/)

Port structure matching Qbix but in U's file layout:

### 2.1 webserver.u — Main server (from Q_WebServer)
Core: accept loop, routing, static file serving, response sending
- WebServer.start(dir, host, port)
- WebServer.run() — event loop
- WebServer.stop()
- WebServer.accept(conn)
- WebServer.on_client_data(conn)
- WebServer.route(request) → Route
- WebServer.serve_static(conn, path, req)
- WebServer.handle_request(conn, parsed)
- WebServer.send_response(conn, status, body, headers)

### 2.2 http.u — HTTP parsing (from parseRequest, processPhpResponse)
- parse_request(raw) → Request
- parse_multipart(content_type, body) → ({S:S}, {S:FormField})
- build_response(status, body, headers) → S

### 2.3 websocket.u — WebSocket server (from Q_WebSocket)
- WebSocket.upgrade(conn, headers)
- WebSocket.on_data(conn, data)
- WebSocket.send(conn, msg)
- WebSocket.broadcast(room, msg)
- Socket.IO framing (encode/decode)

### 2.4 router.u — URL routing (from Q_Uri, route())
- Router.match(path) → Route +N
- Route patterns with $params
- File-based routing (scan directory)

### 2.5 cache.u — File cache (from Q_WebServer_Cache, Q_FileCache)
- FileCache with LRU eviction
- ETag generation
- mtime checking

### 2.6 headers.u — HTTP header processing (from Q_WebServer_Headers)
- Content-Type detection
- Keep-Alive handling
- CORS headers
- Compression (gzip/brotli)

### 2.7 certs.u — TLS certificate management (from Q_WebServer_Certs)
- Load cert + key
- Auto-renew check

### 2.8 handler.u — Request handler sandbox
- The U equivalent of "include PHP script"
- f+E(db, fs) handler(req, ...) → Response
- File-based routing: scan web/ directory for .u files

## Phase 3: Documentation (docs/)
- implementation.html — design decisions
- README.md — usage

## Execution Order
1. stdlib filesystem.u (most critical — needed everywhere)
2. stdlib event.u (the event loop)
3. stdlib encoding.u, json.u, time.u (small utilities)
4. src/http.u (HTTP parsing)
5. src/cache.u (file cache)
6. src/router.u (URL routing)
7. src/headers.u (header processing)
8. src/webserver.u (main server, ties it all together)
9. src/websocket.u (WebSocket upgrade)
10. src/handler.u (request handler sandbox)
11. docs/
