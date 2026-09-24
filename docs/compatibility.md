# Running Existing PHP Apps

Qbix Server runs Laravel, Symfony, WordPress, Drupal, Joomla, Magento and other PHP code without modification. It does this by rewriting a small set of function calls in your PHP files as they are loaded, and by reading the URL rewrite rules your project already ships for Apache. This page explains what gets rewritten, why, and where the approach stops.

For the SAPI emulation and `--app` mode internals, see [app-mode.md](app-mode.md). For what gets reset between requests in persistent workers, see [reset.md](reset.md).

## Quick start

```bash
php qbixserver.php --root=public --preset=laravel
php qbixserver.php --root=public --preset=symfony
php qbixserver.php --root=.      --preset=wordpress
php qbixserver.php --root=web    --preset=drupal
```

A preset sets the front controller and a few `ini` values the framework expects (see [Presets](#presets)). If your project ships an `.htaccess`, you may not need a preset at all: the server reads it.

## Why anything needs rewriting

The server is a long-running PHP process started from the command line, so the code it runs executes under PHP's CLI SAPI. Under that SAPI, a group of built-in functions assume there is no web request and quietly do nothing useful:

- `header()` and `http_response_code()` are discarded, and `headers_list()` always returns an empty array.
- `setcookie()` has no response to attach the cookie to.
- `session_start()` has no cookie to read the session ID from and no response to send one on.
- `is_uploaded_file()` and `move_uploaded_file()` reject every file, because PHP itself did not receive the upload — the server parsed it.
- `getallheaders()` does not exist.

None of these fail loudly. A Laravel app would return `200` with no `Content-Type`, no cookies and no session, and every upload would be refused. PHP offers no hook for intercepting a built-in function at runtime, so the server changes the call before PHP ever compiles it.

## How the rewriter works

When the server starts, it replaces PHP's `file://` stream wrapper with its own. Every `include` and `require` of a `.php` file goes through that wrapper. The wrapper tokenizes the source with `token_get_all()`, finds calls to the functions listed below, and replaces each one with a call to a static method on `Q_WebServer_Compat`. The rewritten source is what PHP compiles; the file on disk is never touched.

A line in Symfony's `Response::sendHeaders()` (which Laravel and Drupal also use):

```php
header($name.': '.$value, $replace, $this->statusCode);
```

is compiled as:

```php
\Q_WebServer_Compat::_header($name.': '.$value, $replace, $this->statusCode);
```

The shim records the header in the server's response state instead of handing it to the CLI SAPI, and the server writes it to the socket when the script finishes.

The rewriter works on tokens, not text, so it only changes real calls to the global function:

| Source | Rewritten? |
|---|---|
| `header('X-A: 1')` | Yes |
| `\header('X-A: 1')` | Yes |
| `header('X-A: 1')` inside `namespace Foo;` | Yes — the shim is emitted fully qualified, so it resolves to the global class |
| `$response->header('X-A', 1)` | No — method call |
| `Response::header('X-A', 1)` | No — static method on another class |
| `function header() { ... }` | No — definition |
| `Foo\header('X-A: 1')` | No — a namespaced function, not the builtin |
| `'header'` as a string, e.g. `call_user_func('header', ...)` | No — see [below](#what-the-rewriter-does-not-reach) |

### Caching and startup cost

Tokenizing every file on every request would be slow, so the server does it once. At startup the parent process walks the project directory (by default, the directory one level above `--root`), tokenizes up to 5,000 `.php` files, and keeps the result in memory: the rewritten source for files that needed changes, and a marker meaning "load unchanged" for the rest. Directories named `tests` or `Tests` are skipped.

Workers are forked from that parent, so they inherit the cache through copy-on-write memory. A request that includes a pre-warmed file pays nothing for the rewrite: no tokenizing and, for rewritten files, no disk read. Files added after startup are rewritten on first include.

The startup banner reports the result. Serving an app built on Symfony's HttpFoundation component:

```
  Compat: pre-warmed 126 files (17 transformed, 109 pass-through, 210KB)
```

Most files never call any of the rewritten functions. They are marked pass-through and load normally.

Because rewritten source is cached in memory, an edit to a file that was rewritten at startup is not seen until the cache entry is dropped. With `Q.webserver.hotReload` enabled, the parent checks modification times every two seconds and drops stale entries. In production, restart after deploying, as you would for any server with persistent workers.

If `--root=.` sits inside a large directory, set `Q.compat.prewarmDir` to the project root so the walk does not wander into sibling projects.

## What gets rewritten, by framework

The same 27 functions are rewritten in every project. Which of them matter depends on the framework.

### Laravel

Laravel sends its response through Symfony's HttpFoundation, so response headers, status codes and cookies all pass through `header()` and `headers_sent()` in Symfony's `Response` class. File uploads go through Symfony's `UploadedFile`, which calls `is_uploaded_file()` to validate the upload, `move_uploaded_file()` to store it, and `ini_get('upload_max_filesize')` to report limits. Laravel's session layer does not use PHP's native sessions; it stores sessions itself and sets its own cookie, so the session shims are not involved.

During bootstrap, Laravel registers error and exception handlers and a shutdown function. In persistent workers those are per-request state: the rewriter routes `set_error_handler()`, `set_exception_handler()` and `register_shutdown_function()` through shims so the server can run the callbacks at the end of each request and restore the boot-time handlers afterwards. Without that, every request would stack another handler on top of the last one.

### Symfony

The same `Response` and `UploadedFile` paths as Laravel. Symfony's `NativeSessionStorage` does use PHP's native sessions, so `session_start()`, `session_status()`, `session_regenerate_id()` and `session_write_close()` are rewritten too. The shimmed session is file-based and holds an exclusive lock on the session file for the length of the request, which is what PHP's default handler does. Symfony calls `session_write_close()` explicitly to release that lock early, and the shim honours it.

### WordPress

WordPress calls the builtins directly and in many places: `status_header()` and `wp_redirect()` call `header()`, `nocache_headers()` calls `header()` and `header_remove()`, authentication cookies are set with `setcookie()`, and media uploads are validated with `is_uploaded_file()` and moved with `move_uploaded_file()`. WordPress raises its own memory limit with `ini_set()` and extends time limits with `set_time_limit()` during updates. Core does not use PHP sessions, but many plugins do, and those calls are rewritten like any other.

Plugins and themes are ordinary `.php` files loaded with `include`, so they are rewritten on the same terms as core. Under the WordPress preset, `ini_get('upload_max_filesize')` returns `64M`, which is what the media uploader displays.

### Drupal

Drupal 8 and later is built on Symfony components, so response headers go through Symfony's `Response`. Its `SessionManager` extends Symfony's native session storage and calls `session_start()` and `session_regenerate_id()`. The Drupal preset also passes the request path as `?q=`, which older Drupal routing and some contributed modules still read.

### Joomla, Magento, ownCloud and others

These frameworks ship `.htaccess` files with front-controller rewrite rules, which the server reads directly, and use PHP's native session storage and `header()`, both of which are rewritten. No preset is needed when the `.htaccess` is present.

### Qbix Platform

The server was built for the Qbix Platform, which uses `Q_Response::header()` and friends rather than the builtins. In `--app` mode the Platform's own dispatcher handles routing and the rewriter has nothing to do for Platform code, though it still covers any third-party libraries the app includes. See [app-mode.md](app-mode.md).

## The 27 rewritten functions

**Response**

| Built-in | What the shim does |
|---|---|
| `header()` | Records the header; `HTTP/1.1 404 ...` lines and the third argument set the status code |
| `http_response_code()` | Gets or sets the status code |
| `headers_sent()` | Returns `false` until the server has flushed the response |
| `headers_list()` | Returns the headers recorded so far |
| `header_remove()` | Removes a recorded header |
| `setcookie()` | Records a `Set-Cookie` header; accepts the PHP 7.3+ options array |
| `setrawcookie()` | Same, without URL-encoding the value |

**Request**

| Built-in | What the shim does |
|---|---|
| `getallheaders()` | Returns the parsed request headers |
| `apache_request_headers()` | Same as `getallheaders()` |

**Sessions**

| Built-in | What the shim does |
|---|---|
| `session_start()` | Reads the session ID from the cookie, opens the session file under an exclusive `flock()`, fills `$_SESSION`, sets the cookie if new |
| `session_write_close()` | Writes `$_SESSION` and releases the lock |
| `session_regenerate_id()` | Issues a new ID, renames the file, updates the cookie |
| `session_destroy()` | Deletes the session file and clears `$_SESSION` |
| `session_status()` | Returns `PHP_SESSION_ACTIVE` or `PHP_SESSION_NONE` correctly |

**Uploads**

| Built-in | What the shim does |
|---|---|
| `is_uploaded_file()` | Checks the path against the files the server parsed from this request's `multipart/form-data` body |
| `move_uploaded_file()` | Same check, then `rename()` |

**Settings and time limits**

| Built-in | What the shim does |
|---|---|
| `ini_get()` | Returns the preset or config value if one is set, otherwise the real value |
| `ini_set()` | Sets the value for this request and restores it afterwards |
| `set_time_limit()` | Enforced with `pcntl_alarm()` |

**Per-request lifecycle** (these matter in persistent workers, where the same process serves many requests)

| Built-in | What the shim does |
|---|---|
| `register_shutdown_function()` | Runs the callback at the end of this request, not when the worker exits |
| `set_error_handler()`, `restore_error_handler()` | Tracks handlers pushed by the request; the boot-time handler is restored afterwards |
| `set_exception_handler()`, `restore_exception_handler()` | Same, for exception handlers |
| `spl_autoload_register()`, `spl_autoload_unregister()` | Autoloaders added during a request are removed afterwards |
| `putenv()` | Environment changes are reverted afterwards |

## URL rewriting

Frameworks expect every URL that is not a real file to reach a front controller, usually `index.php`. The server resolves this in order:

1. **`.htaccess`.** If the document root or any directory on the path has an `.htaccess`, its `mod_rewrite` rules are applied. Supported: `RewriteEngine`, `RewriteBase`, `RewriteCond` with `%{REQUEST_FILENAME}` (`-f`, `-d`, negated), `%{REQUEST_URI}`, `%{HTTP_HOST}` and `%{QUERY_STRING}` patterns and the `[NC]` flag, and `RewriteRule` with `[L]`, `[QSA]`, `[R=301]`, `[F]`, `[E=VAR:val]` and the `-` target. Parsed rules are cached and re-read when the file changes.
2. **Config.** If there is no applicable `.htaccess` rule and `Q.compat.rewrite` names a front controller, a request for a path that is not an existing file is handled as follows: the first matching entry in `Q.compat.rewriteRules` wins (a rule with `"static": true` serves the file as-is); otherwise the request goes to the front controller. `SCRIPT_NAME`, `SCRIPT_FILENAME` and `PATH_INFO` are set as a front controller expects. If `Q.compat.rewriteQueryParam` is set, the path is also passed in that query parameter.

### Presets

| Preset | Front controller | `upload_max_filesize` | `post_max_size` | `memory_limit` | `max_execution_time` | Other |
|---|---|---|---|---|---|---|
| `laravel` | `index.php` | 10M | 12M | 256M | 60 | session GC tuned for a 2-hour lifetime |
| `symfony` | `index.php` | 10M | 12M | 256M | 60 | |
| `wordpress` | `index.php` | 64M | 64M | 256M | 300 | |
| `drupal` | `index.php` | 32M | 32M | 256M | 240 | `?q=` path parameter |

## What the rewriter does not reach

The rewriter sees source code that is loaded from disk through `include` or `require`. It does not see:

- **Calls through a string.** `call_user_func('header', ...)`, `array_map('setcookie', ...)`, `$fn = 'header'; $fn(...)`. The function name is a string token, not a call, and rewriting strings would break far more code than it fixes.
- **`eval()`.** Evaluated code never passes through the file wrapper.
- **Code inside a `.phar`.** Only `file://` includes are rewritten.
- **Calls made from C.** A PHP extension that sends headers internally bypasses PHP-level code entirely.

For code that does any of these, route it to `php-cgi`. Scripts whose paths match `Q.webserver.cgi.patterns` run in a `php-cgi` subprocess, where the builtins behave exactly as they do under Apache or php-fpm:

```json
{ "Q": { "webserver": { "cgi": { "patterns": ["wp-admin/.*", "legacy/.*"] } } } }
```

This costs the preload and copy-on-write benefits for those scripts, so use it for the few that need it rather than for the whole app.

One more edge: a file that declares `namespace Foo;` and defines its own function named `header()`, then calls it unqualified, will have that call rewritten to the shim. This is rare enough that the rewriter does not attempt the namespace-resolution analysis needed to tell the two apart.

## Turning it off

The rewriter is on by default. If your code already uses `Q_Response`, `Q_Request` and the other Qbix APIs and never calls the builtins, you can skip the startup pass and the wrapper:

```json
{ "Q": { "compat": { "skipSourceCodeTransform": true } } }
```

## Configuration reference

```json
{
  "Q": {
    "compat": {
      "skipSourceCodeTransform": false,
      "prewarmDir": "/var/www/myapp",
      "rewrite": "index.php",
      "rewriteQueryParam": "q",
      "rewriteRules": [
        {"match": "^/api/(.*)$", "to": "/api.php/$1"},
        {"match": "^/assets/", "static": true}
      ],
      "ini": {
        "upload_max_filesize": "10M",
        "post_max_size": "12M",
        "memory_limit": "256M",
        "max_execution_time": "60",
        "session.gc_maxlifetime": "1440"
      }
    }
  }
}
```

| Key | Default | Meaning |
|---|---|---|
| `skipSourceCodeTransform` | `false` | Set `true` to disable the rewriter |
| `prewarmDir` | parent of `--root` | Directory walked at startup |
| `rewrite` | none | Front controller for paths that are not files |
| `rewriteQueryParam` | none | Also pass the path in this query parameter |
| `rewriteRules` | none | Regex rules checked before the front controller |
| `ini` | none | Values returned by `ini_get()` |

## Known limitations

- **OPcache.** PHP compiles the rewritten source, so OPcache's file-based validation does not apply to it. The CLI SAPI disables OPcache by default (`opcache.enable_cli=0`); leave it that way, or set `validate_timestamps=1`.
- **Aggressive output buffering.** Code that calls `ob_end_flush()` in a loop to empty every buffer can interfere with response capture. The server's own buffer cannot be removed, which covers the common cases.
- **Edits to rewritten files** are not seen until restart unless hot reload is on (see [Caching](#caching-and-startup-cost)).

## Tests

`tests/test_compat_namespace.php` checks the rewriter against namespaced code, which is how Symfony, Laravel and Drupal are written. Against a live server, a front controller that builds a Symfony `Response` with a status of 201, a custom header and a cookie, then calls `send()`, returns all three to the client. `tests/testSnapshot.sh` runs a real server and checks that headers, cookies, sessions and handlers from one request are invisible to the next. See [app-mode.md](app-mode.md#testing) for the full list of suites.

---
[← Back to README](../README.md)
