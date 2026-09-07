# Framework Compatibility

Qbix Server v1.1 includes a compatibility layer that intercepts PHP's built-in functions at include time, letting framework code run without modification. Drop in your Laravel, Symfony, WordPress, Drupal, Joomla, or Magento project and start the server.

## Quick Start

```bash
# Laravel
php qbixserver.php --root=public --preset=laravel

# Symfony
php qbixserver.php --root=public --preset=symfony

# WordPress
php qbixserver.php --root=. --preset=wordpress

# Drupal
php qbixserver.php --root=. --preset=drupal

# Any framework with .htaccess
php qbixserver.php --root=. --config=compat.json
```

Where `compat.json` is:
```json
{ "Q": { "compat": { "enabled": true } } }
```

The server reads `.htaccess` files automatically when compat mode is enabled. No preset needed if your project ships with `.htaccess` rewrite rules.

## How It Works

### Source Transformation

When compat mode is enabled, the server registers a custom `file://` stream wrapper. Every `include` and `require` passes through it. The wrapper uses `token_get_all()` to find global function calls and replaces them with compatibility shims. Only global calls are replaced — method calls (`$response->header()`), static calls on other classes (`Response::header()`), and function definitions (`function header()`) are left untouched.

Transformed files are cached to disk by `md5(path)`, invalidated by file modification time. The first request to each file has a small overhead for tokenization; subsequent requests serve from cache.

### 19 Intercepted Functions

| PHP Built-in | Replacement | What it does |
|---|---|---|
| `header()` | `Q_WebServer_Compat::_header()` | Stores headers in `Q_Response` for the server to emit |
| `setcookie()` | `Q_WebServer_Compat::_setcookie()` | Stores Set-Cookie headers (supports PHP 7.3+ options array) |
| `setrawcookie()` | `Q_WebServer_Compat::_setrawcookie()` | Same without URL encoding |
| `http_response_code()` | `Q_WebServer_Compat::_http_response_code()` | Get/set status code via `Q_Response::code()` |
| `headers_sent()` | `Q_WebServer_Compat::_headers_sent()` | Returns false until response is flushed |
| `headers_list()` | `Q_WebServer_Compat::_headers_list()` | Returns headers set via `Q_Response` |
| `header_remove()` | `Q_WebServer_Compat::_header_remove()` | Removes a stored header |
| `session_start()` | `Q_WebServer_Compat::_session_start()` | File-based sessions with exclusive `flock()` |
| `session_write_close()` | `Q_WebServer_Compat::_session_write_close()` | Flushes `$_SESSION` to file, releases lock |
| `session_regenerate_id()` | `Q_WebServer_Compat::_session_regenerate_id()` | New ID, renames file, updates cookie |
| `session_destroy()` | `Q_WebServer_Compat::_session_destroy()` | Deletes session file, clears `$_SESSION` |
| `session_status()` | `Q_WebServer_Compat::_session_status()` | Returns `PHP_SESSION_ACTIVE`/`NONE` correctly |
| `move_uploaded_file()` | `Q_WebServer_Compat::_move_uploaded_file()` | Validates against tracked uploads, then `rename()` |
| `is_uploaded_file()` | `Q_WebServer_Compat::_is_uploaded_file()` | Checks the upload tracking array |
| `ini_get()` | `Q_WebServer_Compat::_ini_get()` | Reads from config overrides, falls back to real `ini_get()` |
| `ini_set()` | `Q_WebServer_Compat::_ini_set()` | Stores in config + sets real value |
| `set_time_limit()` | `Q_WebServer_Compat::_set_time_limit()` | Enforced via `pcntl_alarm()` |
| `getallheaders()` | `Q_WebServer_Compat::_getallheaders()` | Returns parsed request headers |
| `apache_request_headers()` | `Q_WebServer_Compat::_getallheaders()` | Alias of `getallheaders()` |

### .htaccess Support

The server parses `.htaccess` files in the document root and subdirectories. Supported directives:

| Directive | Example | Support |
|---|---|---|
| `RewriteEngine On/Off` | `RewriteEngine On` | Full |
| `RewriteBase` | `RewriteBase /subdir` | Full |
| `RewriteCond %{REQUEST_FILENAME} !-f` | File doesn't exist | Full |
| `RewriteCond %{REQUEST_FILENAME} !-d` | Directory doesn't exist | Full |
| `RewriteCond %{REQUEST_URI} pattern` | URI pattern match | Full |
| `RewriteCond` with `[NC]` flag | Case-insensitive match | Full |
| `RewriteRule pattern target [L]` | Last rule, stop processing | Full |
| `RewriteRule ... [QSA]` | Append query string | Full |
| `RewriteRule ... [R=301]` | Redirect | Full |
| `RewriteRule ... [F]` | Forbidden (403) | Full |
| `RewriteRule ... [E=VAR:val]` | Set environment variable | Full |
| `RewriteRule target -` | No rewrite, but apply flags | Full |

`.htaccess` rules are cached in memory and invalidated by file modification time. The server checks the root `.htaccess` first, then walks subdirectories toward the requested path.

### Multipart Upload Handling

The server parses `multipart/form-data` request bodies and populates `$_FILES` with the standard PHP structure. Upload size limits are enforced per the `upload_max_filesize` and `post_max_size` config values. Temp files are tracked internally so `is_uploaded_file()` and `move_uploaded_file()` work correctly. All temp files are cleaned up after the request.

### Session Handling

Sessions use file-based storage with exclusive `flock()` held for the request duration. This prevents concurrent requests from corrupting session data. `session_write_close()` flushes the data and releases the lock early, which is important for long-running requests. Garbage collection runs probabilistically based on `session.gc_probability` / `session.gc_divisor`.

## Framework Presets

Presets set `compat.enabled`, `compat.rewrite`, and `compat.ini` values tuned for each framework:

| Preset | `upload_max` | `post_max` | `memory` | `max_execution` | Notes |
|---|---|---|---|---|---|
| `laravel` | 10M | 12M | 256M | 60s | Front controller: `index.php` |
| `symfony` | 10M | 12M | 256M | 60s | Front controller: `index.php` |
| `wordpress` | 64M | 64M | 256M | 300s | High limits for media uploads |
| `drupal` | 32M | 32M | 256M | 240s | `?q=` query param rewriting |

## Tested Scenarios

### Laravel
- Clean URL routing (all non-file URLs → `index.php`)
- `ini_get('upload_max_filesize')` returns preset value (10M)
- Session start, persistence across requests, regenerate ID, destroy
- File upload via `$_FILES`, `is_uploaded_file()`, `move_uploaded_file()`
- `headers_sent()` returns false before output
- `getallheaders()` returns parsed request headers
- JSON API responses with correct `Content-Type`
- 404 status code for unknown routes

### Symfony
- `session_status()` returns `PHP_SESSION_ACTIVE` after `session_start()`
- `session_write_close()` releases lock (Symfony calls this explicitly)
- `headers_list()` returns set headers
- `ini_get()` returns preset values
- 404 status for unknown routes

### WordPress
- Pretty permalinks via `.htaccess` or preset rewrite
- `ini_get('upload_max_filesize')` returns 64M
- Login form with `setcookie()` + 302 redirect
- Session-gated admin pages
- Media upload via multipart form

### Drupal
- `?q=` query parameter rewriting for clean URLs
- Node paths (`/node/1` → `index.php?q=node/1`)
- Admin path routing
- 404 for unknown paths

### Joomla
- `.htaccess` rewrite rules (no preset needed)
- PHP files served directly (`.php$ - [L]` rule)
- Clean URLs for articles

### Qbix Platform
- Full integration via `--app` mode (deep integration with control panel, installer, plugin management)
- The webserver was originally built for the Qbix Platform and shares its `Q::event()`, `Q_Config`, `Q_Response`, and handler conventions
- In `--app` mode, the Platform's own `Q_Dispatcher` handles routing — the compat layer is not needed
- In standalone mode with compat enabled, Qbix Platform apps can run via the front controller rewrite

## Configuration Reference

### JSON Config

```json
{
  "Q": {
    "compat": {
      "enabled": true,
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
        "session.gc_maxlifetime": "1440",
        "session.gc_probability": "1",
        "session.gc_divisor": "100"
      }
    }
  }
}
```

### Priority Order

1. `.htaccess` rules (checked first if file exists)
2. JSON `rewriteRules` (regex patterns)
3. JSON `rewrite` (front controller fallback)
4. JSON `rewriteQueryParam` (Drupal-style `?q=`)

### Startup Banner

When compat mode is active, the server shows it in the startup banner:

```
  ┌──────────────────────────────────────┐
  │  Qbix Server v1.1.0                  │
  │  http://0.0.0.0:8080                 │
  │  Root: public                        │
  │  Mode: Standalone                    │
  │  Compat: laravel (source transform)  │
  └──────────────────────────────────────┘
```

## Known Limitations

- **OPcache**: The source transformation requires OPcache to be disabled or set to `validate_timestamps=1` so transformed source isn't cached stale. In CLI mode (`opcache.enable_cli=0`), this is the default.
- **`\header()` (backslash-prefixed)**: Fully-qualified global calls like `\header()` are currently not transformed. This is rare in framework code but possible in vendor libraries.
- **Binary extensions**: PHP extensions that call `header()` internally (e.g., `session_start()` with the native handler) bypass the source transformer. The compat layer overrides `session_start()` itself to avoid this.
- **Output buffering**: Frameworks that use `ob_end_flush()` aggressively may interfere with the server's response capture. The server uses a non-removable output buffer to mitigate this.
- **Async workers**: In `--workers` mode, the snapshot restore already clears PHP statics between requests. The compat layer's session and upload cleanup also runs between requests.
