# Qbix Server — End-to-End Test Results

Tested: September 18, 2026
Source: `git clone https://github.com/Qbix/webserver.git` (commit `8e69b26`)
Environment: PHP 8.3.6 (NTS), Linux (Ubuntu 24.04), single CPU
Extensions: pcntl, sockets, pdo_sqlite, sqlite3, openssl, mbstring, phar, tokenizer

## Unit Test Suite

**72/72 passing** — `bash tests/run.sh --quick`

Covers: static files, content types, ETag/304, keep-alive, HEAD, 404s, compression, path traversal, dotfiles, null bytes, oversized headers, blocked dirs, WebSocket frame limits, panel auth, PHP execution, query strings, PHP_SELF, SERVER_SOFTWARE, GATEWAY_INTERFACE, Q classes, cookies, Basic auth, POST form/JSON, HTTPS proxy detection, multipart uploads, response headers, custom status codes, crash isolation (exit + fatal), POST size limits, cookie roundtrip, redirects, content-type detection, config test, php://input, WebSocket upgrade, Socket.IO handshake, Engine.IO ping/pong, client JS.

## End-to-End Feature Tests

### 1. Static File Serving

| # | Test | Expected | Actual | ✓/✗ |
|---|---|---|---|---|
| 1.1 | GET /index.html | 200 OK | 200 OK | ✅ |
| 1.2 | CSS content-type | text/css | text/css; charset=utf-8 | ✅ |
| 1.3 | JSON content-type | application/json | application/json; charset=utf-8 | ✅ |
| 1.4 | Missing file | 404 | 404 Not Found | ✅ |
| 1.5 | Missing nested path | 404 | 404 Not Found | ✅ |
| 1.6 | ETag generated | present | `"6aadbbc3-3c"` | ✅ |
| 1.7 | Conditional GET with ETag | 304 | 304 Not Modified | ✅ |
| 1.8 | HEAD returns no body | 0 bytes | 0 bytes | ✅ |

### 2. PHP Execution (persistent workers)

| # | Test | Expected | Actual | ✓/✗ |
|---|---|---|---|---|
| 2.1 | GET method | GET | GET | ✅ |
| 2.2 | SERVER_SOFTWARE | QbixServer/* | QbixServer/1.1.0 | ✅ |
| 2.3 | GATEWAY_INTERFACE | CGI/1.1 | CGI/1.1 | ✅ |
| 2.4 | REQUEST_SCHEME | http | http | ✅ |
| 2.5 | PHP_SELF | /hello.php | /hello.php | ✅ |
| 2.6 | Q class loaded | True | True | ✅ |
| 2.7 | Query string ?foo=bar&n=42 | parsed | {'foo': 'bar', 'n': '42'} | ✅ |
| 2.8 | Basic auth → PHP_AUTH_USER | alice | alice | ✅ |
| 2.9 | X-Forwarded-Proto → scheme | https | https | ✅ |
| 2.10 | php://input returns raw body | rawbody123 | rawbody123 | ✅ |
| 2.11 | POST form data → $_POST | parsed | {'name': 'Greg', 'city': 'NYC'} | ✅ |

### 3. Multipart Uploads

| # | Test | Expected | Actual | ✓/✗ |
|---|---|---|---|---|
| 3.1 | POST field from multipart | Report | Report | ✅ |
| 3.2 | Uploaded file name | report.txt | report.txt | ✅ |
| 3.3 | Uploaded file content | file content here | file content here | ✅ |

### 4. Response Headers & Status Codes

| # | Test | Expected | Actual | ✓/✗ |
|---|---|---|---|---|
| 4.1 | Custom status code | 201 Created | HTTP/1.1 201 Created | ✅ |
| 4.2 | Custom response header | X-Custom-Header | X-Custom-Header: hello | ✅ |

### 5. Cookies

| # | Test | Expected | Actual | ✓/✗ |
|---|---|---|---|---|
| 5.1 | Cookie header → $_COOKIE | session=abc123 | {"session":"abc123","lang":"en"} | ✅ |

### 6. Crash Isolation

| # | Test | Expected | Actual | ✓/✗ |
|---|---|---|---|---|
| 6.1 | exit() in script | 502 (child died) | 502 Bad Gateway | ✅ |
| 6.2 | Server still alive after exit | responds | GET | ✅ |
| 6.3 | Fatal error in script | 500 | 500 Internal Server Error | ✅ |
| 6.4 | Server still alive after fatal | responds | GET | ✅ |

### 7. Security

| # | Test | Expected | Actual | ✓/✗ |
|---|---|---|---|---|
| 7.1 | Path traversal /../../../etc/passwd | blocked | 404 Not Found | ✅ |
| 7.2 | Dotfile .env | blocked | 403 Forbidden | ✅ |
| 7.3 | /handlers/ directory | blocked | 403 Forbidden | ✅ |
| 8.1 | Oversized POST (20MB) | 413 | 413 | ✅ |

### 9. Health & Dashboard

| # | Test | Expected | Actual | ✓/✗ |
|---|---|---|---|---|
| 9.1 | /Q/health status | ok | ok | ✅ |
| 9.2 | Health includes workers | yes | True | ✅ |
| 10.1 | /Q/dashboard loads | 200 | 200 OK | ✅ |
| 10.2 | Dashboard has charts | >0 | 4 chart references | ✅ |

### 11. WebSocket

| # | Test | Expected | Actual | ✓/✗ |
|---|---|---|---|---|
| 11.1 | Upgrade handshake | 101 | 101 Switching Protocols | ✅ |
| 11.2 | /Q/socket.js served | 200 | 200 OK | ✅ |
| 11.3 | /socket.io/socket.io.js served | 200 | 200 OK | ✅ |

### 12. API Discovery

| # | Test | Expected | Actual | ✓/✗ |
|---|---|---|---|---|
| 12.1 | OpenAPI version | 3.1.0 | 3.1.0 | ✅ |
| 12.2 | OpenAPI paths count | >0 | 5 | ✅ |
| 12.3 | MCP tools count | >0 | 4 | ✅ |
| 12.4 | Qbix manifest server | Qbix Server | Qbix Server | ✅ |
| 12.5 | Qbix fingerprint | 64-char hex | 630f19649d447c01... | ✅ |
| 13.1 | ACME challenge (no pending) | 404 | 404 Not Found | ✅ |

### 14. Control Panel

| # | Test | Expected | Actual | ✓/✗ |
|---|---|---|---|---|
| 14.1 | /Q/panel loads | has title | 1 match | ✅ |
| 14.2 | Tab count (GitHub code) | 6 | 6 | ✅ |

### 15. Todo Example (SQLite CRUD)

| # | Test | Expected | Actual | ✓/✗ |
|---|---|---|---|---|
| 15.1 | POST create todo | ok=True | True | ✅ |
| 15.2 | POST create second | returns id | id=2 | ✅ |
| 15.3 | GET list | 2 items | 2 | ✅ |
| 15.4 | PUT toggle done | done=True | True | ✅ |
| 15.5 | DELETE remove | 1 remaining | 1 | ✅ |
| 15.6 | GET index.html | 200 | 200 OK | ✅ |

### 16. Counter Example (SQLite persistence)

| # | Test | Expected | Actual | ✓/✗ |
|---|---|---|---|---|
| 16.1 | Initial count | 0 | 0 | ✅ |
| 16.2 | After 3 increments | 3 | 3 | ✅ |
| 16.3 | HTML page | 200 | 200 OK | ✅ |

### 17. Swarm Example (events + SQLite)

| # | Test | Expected | Actual | ✓/✗ |
|---|---|---|---|---|
| 17.1 | Create task | ok=True | True | ✅ |
| 17.2 | Task UUID returned | 8+ chars | 0087bc80 | ✅ |
| 17.3 | List tasks | JSON array | valid JSON array | ✅ |
| 17.4 | HTML page | 200 | 200 OK | ✅ |

### 18. Chat Example (WebSocket + rooms)

| # | Test | Expected | Actual | ✓/✗ |
|---|---|---|---|---|
| 18.1 | HTML page | 200 | 200 OK | ✅ |
| 18.2 | WebSocket upgrade | 101 | 101 Switching Protocols | ✅ |
| 18.3 | Handler files count | 8 | 8 (join, leave, message, typing, stop_typing, reaction, read, init) | ✅ |

### 19. CLI Flags

| # | Test | Expected | Actual | ✓/✗ |
|---|---|---|---|---|
| 19.1 | --version | Qbix Server v* | Qbix Server v1.1.0 | ✅ |
| 19.2 | --help | Usage info | Qbix Server v1.1.0 + Usage | ✅ |
| 19.3 | -t (config test) | Config: OK | Config: OK | ✅ |

### 20. Collab Example

| # | Test | Expected | Actual | ✓/✗ |
|---|---|---|---|---|
| 20.1 | HTML page | 200 | 200 OK | ✅ |

## Summary

| Category | Tests | Passed | Failed |
|---|---|---|---|
| Unit suite | 72 | 72 | 0 |
| Static files | 8 | 8 | 0 |
| PHP execution | 11 | 11 | 0 |
| Uploads | 3 | 3 | 0 |
| Headers & status | 2 | 2 | 0 |
| Cookies | 1 | 1 | 0 |
| Crash isolation | 4 | 4 | 0 |
| Security | 4 | 4 | 0 |
| Health & dashboard | 4 | 4 | 0 |
| WebSocket | 3 | 3 | 0 |
| API discovery | 6 | 6 | 0 |
| Control panel | 2 | 2 | 0 |
| Todo (SQLite) | 6 | 6 | 0 |
| Counter (SQLite) | 3 | 3 | 0 |
| Swarm (events) | 4 | 4 | 0 |
| Chat (WS + rooms) | 3 | 3 | 0 |
| CLI flags | 3 | 3 | 0 |
| Collab | 1 | 1 | 0 |
| **Total** | **140** | **140** | **0** |

## Known Limitations

| Issue | Detail |
|---|---|
| SSE in persistent workers | Output collected via `ob_start()` then sent as one response. SSE streaming requires fork-per-request mode (standalone dispatch uses chunked transfer). |
| `--workers=0` auto-detects | `!$opts['workers']` treats 0 as falsy. Use `forkPerRequest: true` in config instead. |
| ACME live provisioning | Requires publicly reachable port 80. Class methods and routing tested; protocol flow untested against real Let's Encrypt. |
| `define()` not shimmed | Constants defined during a request persist in persistent workers. |
| `stream_wrapper_register` not shimmed | Custom stream wrappers registered during a request persist. |
