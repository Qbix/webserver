# `--app` Mode and SAPI Internals

This page covers how the server stands in for a PHP SAPI inside forked workers, how it coexists with the Qbix Platform in `--app` mode, and how both modes are tested. For running Laravel, Symfony, WordPress, Drupal and other unmodified code, see [compatibility.md](compatibility.md).

> **Native `header()` and the source transform.** Several sections below say that PHP's built-in `header()`, `http_response_code()` and friends are discarded under the CLI SAPI. That is true of the builtins themselves. With the source transform on (the default), calls to those functions in any `.php` file loaded from disk are rewritten to shims before PHP compiles the file, so they do reach the client. The `php-cgi` carveout is for code the transform cannot see: `eval()`'d strings, files included from a `phar://` archive, and calls made through a string such as `call_user_func('header', ...)`. See [compatibility.md](compatibility.md#what-the-rewriter-does-not-reach).

## Q_Sapi — SAPI emulation for forked children

A forked child of a CLI process has no SAPI. Nothing populated the superglobals, nothing captures output, and native `header()` is a silent no-op. `Q_Sapi` does what mod_php or php-fpm would do:

```php
Q_Sapi::enter($parsed);      // superglobals + ob_start
include $scriptPath;         // any PHP file, not just a front controller
list($status, $headers, $body) = Q_Sapi::leave();
```

`enter()` populates `$_GET`, `$_POST` (form-encoded or JSON), `$_COOKIE`, `$_FILES` and `$_SERVER` — including `HTTP_HOST`, `SCRIPT_NAME`, `PATH_INFO` and `REMOTE_ADDR`. `HTTP_HOST` matters more than it looks: `Q_Response::setCookie()` returns false without it, which silently drops the session cookie.

### Shutdown ordering

PHP runs `register_shutdown_function` callbacks in registration order, then object destructors. Since `Q_Sapi` registers before any application code, a shutdown callback would fire *first* — before user callbacks had a chance to echo or set cookies. So capture happens in a **destructor** (`Q_Sapi_Finalizer`), which is guaranteed to run last and still runs on `exit()`, on uncaught exceptions and on fatal errors.

Before assembling the response, `capture()` calls `session_write_close()` explicitly, so the session row and its cookie are settled rather than racing a response the parent has already sent.

`capture()` is idempotent, and `deliver()` hands the response off exactly once — to `Q_Sapi::$onCapture` if the worker pool registered a consumer, otherwise to `STDOUT` so a child run standalone behaves like an ordinary PHP script.

### What fork mode cannot do

Native `header()` cannot be intercepted: in the CLI SAPI it does nothing, and PHP offers no hook. Fork mode therefore fully supports code that goes through `Q_Response::header()` / `Q::header()`. Third-party or legacy scripts that call `header()` directly should be routed to `php-cgi` with `Q.webserver.cgi.patterns` — that config is the supported escape hatch, not a workaround.

## Setting headers, status codes and cookies

**Use `Q_Response`.** It works in both standalone and `--app` mode and has the same signature as PHP's built-in `header()`:

```php
Q_Response::header('Content-Type: application/json');
Q_Response::header('X-Custom: hello');
Q_Response::header('HTTP/1.1 201 Created');   // status line form
Q_Response::code(201);                         // or set it directly
Q_Response::setCookie('session', $token, 0, '/');
Q_Response::redirect('/dashboard');
```

`Q_WebServer_State::header()` also works — `Q_Response::header()` delegates to it — but `Q_Response` is the higher-level API that scripts should prefer.

### Why not `header()`?

PHP's built-in `header()` and `http_response_code()` are **silently discarded** under the CLI SAPI, which is what the server runs in. `headers_list()` always returns an empty array and `http_response_code()` returns `false`, and PHP offers no hook to intercept the builtins. A script calling them gets a `200` with none of its headers, and no error to explain why.

### What works where

| API | standalone | `--app` | notes |
|---|---|---|---|
| `Q_Response::header()` | ✅ | ✅ | **recommended** — delegates to `Q_WebServer_State`, same signature as PHP's `header()` |
| `Q_Response::code()` | ✅ | ✅ | get or set the HTTP status code |
| `Q_Response::setCookie()` | ✅ | ✅ | cookies are assembled into `Set-Cookie` headers by the server |
| `Q_WebServer_State::header()` | ✅ | ✅ | low-level — `Q_Response::header()` delegates here |
| `Q::header()` | ✅ | ✅ | alias for `Q_Response::header()` |
| `header()` (built-in) | ❌ | ❌ | silently discarded by the CLI SAPI |
| `http_response_code()` | ❌ | ❌ | silently discarded by the CLI SAPI |

### Scripts you don't control

Third-party code — WordPress, a vendored SDK, anything not written for Qbix — will call native `header()`. Route it to `php-cgi`, which runs it in a real CGI process where the builtins work normally:

```json
{ "Q": { "webserver": { "cgi": { "patterns": ["wp-.*\\.php", "legacy/.*"] } } } }
```

`tests/run-cgi.sh` proves that path preserves both status codes and headers.

### Cookies

`Q_Response::setCookie()` works in both modes (the Platform declares it too). The server reads `Q_Response::$cookies` — a `public static` property on both implementations — and emits the `Set-Cookie` headers itself.

## Tests

Five test suites, 152 tests total:

```bash
# Core — static files, MIME, PHP dispatch, superglobals, POST/JSON, cookies, auth, stress
bash tests/run.sh --quick                    # 72 tests

# SAPI — superglobal population, output capture, status codes, sessions, shutdown ordering
php tests/testSapi.php                       # 19 tests

# HTTP protocol — keep-alive, path traversal, ETags, encoding, logging, panel auth
bash tests/testHttp.sh [port]                # 37 tests

# WebSocket — RFC 6455, Socket.IO handshake, events+acks, rooms, dashboard broadcast
node tests/testWebSocket.js [port]           # 12 tests

# Snapshot reset — octane state isolation, memory leaks, secret leaks
bash tests/testSnapshot.sh [port]            # 12 tests
```

The snapshot tests start both a fork-mode server and a persistent-worker server, then verify that statics, globals, `$_COOKIE`, `$_SERVER`, `$_POST`, Authorization headers, and response headers from request A are invisible to request B. The fork-mode server acts as a control group. See [reset.md](reset.md) for what the snapshot resets.

## Class ownership in `--app` mode

The webserver owns its own classes; the Platform does not carry copies.

`Q::autoload()` resolves `Q_WebServer_Proxy` to `classes/Q/WebServer/Proxy.php` against PHP's include_path, which covers only the Platform. So `qbixserver.php` registers a prepended autoloader that serves these names from this repo's `src/`:

    Q_WebServer, Q_WebServer_*, Q_WebSocket, Q_Scheduler, Q_FileCache, Q_HotReload

It is deliberately **selective**. `src/Q/` also contains `Utils`, `Uri`, `Evented` and `Snapshot`, which the Platform also defines. Claiming those would shadow the Platform's versions with the standalone ones. In `--app` mode the Platform wins for anything it defines; we claim only what is ours alone.

Do **not** copy `Q/WebServer*.php` into `platform/classes/`. Nothing in the Platform references `Q_WebServer` except one comment, and a Platform running behind nginx or php-fpm should not ship code it never loads. A partial copy there is worse than none: it shadows this repo's complete set and fails with `Class "Q_WebServer_Proxy" not found`.

### `Q::$paths`

`Q::$paths` is declared by this repo's **standalone shim** (`src/Q.php`), not by the Platform's `Q.php`. Use `Q_WebServer::paths()` instead of touching the property — it falls back to `APP_DIR`/`Q_DIR` when the property is absent. Dereferencing it directly in `--app` mode raised `Access to undeclared static property Q::$paths` and made every request a 500.

### The webserver is a strict, overridable subset

In `--app` mode the Platform wins twice over: its **classes** override ours for any shared name, and its **config** (routing, etc.) overrides ours. That is the intended direction, and it only works if we never depend on anything the Platform lacks.

The rule: **any member the Platform does not define must live on a webserver-only class** — `Q_WebServer`, `Q_WebServer_*`, `Q_WebSocket`, `Q_Scheduler`, `Q_FileCache`, `Q_HotReload` — never on a shared name like `Q`, `Q_Utils`, `Q_Uri`, `Q_Evented` or `Q_Snapshot`.

`tests/platform-compat.php` enforces this. It maps both class trees, finds the shared names, and fails if we touch a static member the Platform's version does not declare. Guarded calls (`method_exists('Q','init') && Q::init(...)`) are allowed, since they degrade cleanly.

    php tests/platform-compat.php /path/to/Qbix/platform

Fixed under this rule so far:

- `Q::$paths` — declared by our standalone shim, not by the Platform. Now read through `Q_WebServer::paths()`, which falls back to `APP_DIR`/`Q_DIR`.
- `Q_Utils::serverIdentity()`, `serverClaim()`, `signClaim()`, `verify()` —
  four methods on a shared class name the Platform also defines (without them). Moved to **`Q_WebServer_Identity`**.

#### Platform compatibility: PASS

`php tests/platform-compat.php <platform>` reports **0 violations**. All webserver-only methods (`setInput`, `restoreInput`, `getHeaders`, `cookieHeaders`, `clear`, `responseCode`) are called on `Q_WebServer_State` (webserver-only class), never on shared classes that the Platform overrides. The webserver's internal code uses `Q_WebServer_State` for state management; user-facing APIs (`Q_Response::header()`, `Q_Request::method()`, etc.) exist in both the standalone shim and the Platform.

### Why the webserver keeps its own Q_Uri (measured)

| | bytes | dependencies |
|---|---|---|
| Platform `Q_Uri` | 41,394 (1,472 lines) | `Q`, `Q_Config`, `Q_Request`, `Q_Utils`, `Q_Valid` |
| webserver `Q_Uri` | 7,554 | `Q`, `Q_Config` |

Adopting the Platform's outright means its **transitive closure**: Uri 41K + Request 55K + Utils 84K + Valid 17K = **~197KB**, versus 7.5KB — and the webserver has no `Q_Valid` at all. A standalone static server does not need slots, mobile detection or validation to match `AI/webhook/:type/:task`.

In `--app` mode the calculus reverses: the Platform is already loaded, so its `Q_Uri` is free and ours is dead weight. Hence `Q_WebServer_Router`, which uses whichever is present.

**Resolved.** `Q_Uri::from()` *is* the path→route matcher — it dispatches internally to the protected `fromUrl()`. The catch is that it must be given an **absolute URL**, not a bare path.

Passing a bare path is worse than an error. `from()` then treats it as a URI string (`"Module/action/..."`) and merely SPLITS it, returning a wrong answer with no exception. Measured against a live app:

    bare path  AI/webhook/slack/ingest                    -> AI / webhook/slack/ingest   (split)
    full URL   http://host/App/AI/webhook/slack/ingest    -> AI / webhook                (routed)

`Q_WebServer_Router` now builds `Q_Request::baseUrl() + path` before calling `from()`, and returns null rather than guessing when no base URL is available. Verified with the Platform's `Q_Uri` loaded (`which Q_Uri: PLATFORM`):

    /AI/webhook/slack/ingest  -> AI/webhook
    /Safebox/action           -> Safebox/action
    /Users/login              -> null      (no catch-all route in that app's config)
    /nope                     -> null

## Testing

Eight suites. Everything runs against a real server over a real socket — no mocks.

    bash tests/run.sh --quick                    # 71 functional + security tests
    php  tests/testSapi.php                      # 19 SAPI emulation tests
    php  tests/test_compat_namespace.php         # source transform in namespaced code
    bash tests/run-probe.sh [platform-dir]       # 58 wire-level probes per mode
    bash tests/run-cgi.sh                        # php-cgi carveout
    php  tests/platform-compat.php <platform>    # --app compatibility audit
    php  tests/routing-parity.php  <platform>    # our matcher vs the Platform's
    bash tests/run-modes.sh <app> <platform>     # dual-mode acceptance

CI runs all of them on every push (`.github/workflows/test.yml`), in three jobs: standalone, php-cgi, and `--app` against a fresh checkout of [Qbix/Platform](https://github.com/Qbix/Platform).

### Testing `--app` mode

The Platform will not bootstrap without a real app — it needs a config with `Q/plugins` and `Q/web/appRootUrl`, and its `Q_Uri` is not loadable on its own. Build a minimal, plugin-free fixture:

    bash tests/fixtures/make-app.sh /path/to/Platform/platform /tmp/TestApp 20099
    bash tests/run-probe.sh /path/to/Platform/platform

Without a Platform path, `platform-compat.php` and `routing-parity.php` exit 0 with `SKIP`. **In CI that is indistinguishable from passing**, so the workflow greps their output and fails the job if they did not actually report success.

### `tests/probe.php` — the wire-level suite

Unit tests miss whole classes of bug. Two examples this suite caught that nothing else did:

- **`exit()` sent the client zero bytes.** The forked child unwound past the response-writing code, so nothing reached the socket — while the access log recorded `200`, because the parent had already assumed success.
- **Headers were silently dropped in `--app` mode.** They were captured correctly, then discarded by a guard that tested for a method only the standalone shim declares.

Both looked fine from inside the process. Only reading the socket revealed them.

### `tests/platform-compat.php` — the invariant that matters

The webserver is a *subset* the Platform must be able to override. In `--app` mode the Platform's classes win for every name it defines, so any member the webserver touches on a shared class must exist in the Platform's version too — otherwise it works standalone and dies under a real app.

This audit walks `src/` **and the test fixtures** (they run under both modes too) and fails on any member a shared class does not declare. Anything the Platform lacks belongs on a webserver-only class: `Q_WebServer`, `Q_WebServer_State`, `Q_WebServer_Router`, `Q_WebServer_Identity`, `Q_WebSocket`, `Q_Scheduler`, `Q_FileCache`, `Q_HotReload`.

### Two behaviours worth knowing

**`php://input` works, via a stream wrapper.** A forking server reads the request off the socket itself, so the real `php://input` is already consumed and would stay empty for the life of the process. `Q_WebServer_State::setInput()` registers a wrapper over `php` so `file_get_contents('php://input')` returns *this* request's body, and `restoreInput()` unregisters it afterwards.

The wrapper class exists twice on purpose: `Q_PhpInputStream` in `src/Q.php` for standalone, and `Q_WebServer_PhpInput` for `--app`, because `src/Q.php` is the standalone shim and is not loaded when the Platform's `Q` wins. Without the webserver-owned copy, `php://input` returned an empty string for every request under `--app`.

**Native `header()` is discarded under the CLI SAPI.** `headers_list()` always returns empty and `http_response_code()` returns `false`; PHP offers no hook to intercept the builtin. Scripts written for Qbix should use `Q_Response::header()`, which works in both modes. Scripts that must use native `header()` — WordPress, third-party code — are routed to `php-cgi` via `Q.webserver.cgi.patterns`; `tests/run-cgi.sh` proves that path preserves both status and headers.

**The app enforces its own baseUrl.** If the app is configured for `http://host/App` and you serve it on another port, the Platform returns `{"error":"bad url ..."}`. That is the application refusing, not the server failing. Serve at the configured address, or point the app's baseUrl at the listening one — which is what `make-app.sh`'s port argument does.

### Fixed: `/` returned 403 in `--app` mode

`GET /index.php` returns 200 and renders the app. `GET /` returns 403.

Narrowed to the directory-index lookup: for `/` the server resolves the docroot directory and then tries `index.html`, `index.php` in turn. That lookup is not finding `web/index.php` even though the file exists and serves correctly when requested directly — so the request falls past the index branch and is refused. Suspect the `$fsPath . DS . $idx` join against `self::$rootDir` (the startup banner reports `Root: web`, a relative value).

Everything else in `--app` mode passes: static files, `index.php`, an `action.php` route, no class-loading / undeclared-property / undefined-method errors, and no leading NUL byte.

**Root cause (fixed).** The extension used to pick the static-vs-PHP branch was read from `$path` (the URL) instead of `$fsPath` (the resolved file). `/` has no extension in the URL, but `$fsPath` had already been resolved to the directory index `.../index.php`. So `/` fell past the PHP branch into `serveStaticFile()`, which rejects any extension not in `$allowedExtensions` — 403 on the app's own home page, while `/index.php` served fine. The same mistake appeared in **two** places (`route()` and the serve path); both now read `$fsPath`.

**Also fixed: missing `Content-Type` on PHP-dispatched 200s.** A script that never calls `header()` left none set, so successful dispatches went out with no `Content-Type` at all — browsers sniff, strict clients reject. Error paths set it explicitly, which is why it only bit the success path. `dispatchToQ()` now defaults to `text/html; charset=utf-8` unless the script set one (and never on 204/304).

**Status: 32/32 passing in both modes**, stable across repeated runs.

**Native `header()` is not captured — use `Q_Response::setHeader()`.** Under the CLI SAPI PHP's `header()` is a no-op and `headers_list()` returns nothing, so a long-running CLI server cannot see those calls. This is a PHP constraint, not a server one, and unlike `php://input` it cannot be worked around with a stream wrapper. Set response headers through `Q_Response::setHeader()` or `Q::header()`; both are captured and reach the client. The suite asserts this explicitly so it will report if a future SAPI changes the behaviour.

**One header store.** `Q_Response`'s accessors delegate to `Q_WebServer_State`. An earlier refactor left `Q_Response` keeping a parallel `$_headers` array while the server read State's — so every header set through the Qbix API silently vanished from the response. The suite now round-trips a header set via `Q_Response::setHeader()` and asserts it arrives.

---
[← Compatibility](compatibility.md) · [← Back to README](../README.md)

