## ⚙️ Configuration

Create `config/server.json` next to your `web/` directory, or pass `--config=path/to/config.json`:

```json
{
    "Q": {
        "webserver": {
            "keepAlive": {
                "max": 100,
                "timeout": 15
            },
            "maxConnections": 1024,
            "fileCache": {
                "maxSize": 67108864,
                "maxFile": 1048576,
                "checkInterval": 1
            },
            "rateLimit": {
                "enabled": true,
                "requests": 100,
                "window": 60
            }
        }
    }
}
```

| Key | Default | What it does |
|---|---|---|
| `keepAlive.max` | 100 | Max requests per keep-alive connection |
| `keepAlive.timeout` | 15 | Seconds before closing idle connection |
| `maxConnections` | 1024 | Max simultaneous connections |
| `fileCache.maxSize` | 64MB | Total memory for cached file responses |
| `fileCache.maxFile` | 1MB | Largest file to cache in memory |
| `fileCache.checkInterval` | 1 | Seconds between file modification checks |
| `rateLimit.enabled` | false | Enable per-IP rate limiting |
| `rateLimit.requests` | 100 | Requests per window |
| `rateLimit.window` | 60 | Window in seconds |
| `webserver.requestTimeout` | 30 | Seconds before killing a hung HTTP worker (0 = no limit) |
| `dashboard` | (enabled) | Set to `false` to disable `/Q/dashboard`, `/Q/health`, and `/Q/ws` entirely |
| `dashboard.token` | (none) | When set, dashboard requires `?token=VALUE` in the URL |
| `autoload.psr-4` | `{}` | PSR-4 namespace mappings: `{"App\\": "src/"}` |
| `autoload.psr-0` | `{}` | PSR-0 prefix mappings: `{"Legacy_": "vendor/"}` |
| `socket.io` | `"/socket.io"` | Socket.IO endpoint. Protocol detection + client JS at `{path}/socket.io.js`. `false` to disable. |
| `socket.js` | `"/Q/socket.js"` | Path to serve the minimal bare-WebSocket client (3KB). `false` to disable. |
| `app` | `""` | App name — prefixes handler function names (e.g. `"Chess"` → `Chess_chat_message()`) |
| `webserver.fallback` | null | Catch-all: `"index.html"`, `{"handler":"app/notfound"}`, or `{"file":"404.html"}` |
| `webserver.hotReload` | `false` | Watch `classes/`, `handlers/`, `config/` for changes. Auto-restarts on class/config changes. |
| `webserver.cgi.patterns` | [] | Regex patterns for scripts that use php-cgi (legacy compatibility) |
| `webserver.cgi.binary` | auto | Path to php-cgi binary (auto-detected if not set) |

### Virtual hosts

Serve multiple domains from one server. Each host can have its own document root:

```json
{
    "Q": {
        "webserver": {
            "hosts": {
                "example.com": {
                    "root": "/var/www/example/web"
                },
                "api.example.com": {
                    "root": "/var/www/api/web"
                },
                "staging.example.com": {
                    "root": "/var/www/staging/web"
                }
            }
        }
    }
}
```

The `Host` header selects the root. Requests for unconfigured hosts use the default `--root` directory. WebSocket, rooms, handlers, and static files all respect the per-host root.

### Hot reload

Watch `classes/`, `handlers/`, and `config/` for file changes:

```bash
php qbixserver.php --hotreload
```

Or via config:

```json
{
    "Q": {
        "webserver": {
            "hotReload": true
        }
    }
}
```

Handler changes take effect immediately — handlers are lazy-loaded, so the next request or connection picks up the new code. Class or config changes trigger a graceful restart (the server re-execs itself with the same arguments).

Changes are logged to stderr:

```
14:32:07 hot-reload: ~ handlers/chat/message.php 14:32:09 hot-reload: + classes/MyApp/NewFeature.php 14:32:09 hot-reload: restarting server...
```

Polls every 2 seconds. Recommended for development.

Even without `--hotreload`, handler changes take effect naturally: HTTP requests fork fresh and load handlers on demand, so the next request gets the new file. WebSocket connections and rooms keep the old code for their lifetime — new connections pick up the change. A natural rolling deploy with no interruption. The `--hotreload` flag adds automatic restart for class and config changes, which are preloaded in the parent process.

If `Q.handlers.preload` is `true` (production mode), handlers are also loaded in the parent — use `--reload` to pick up handler changes in that case.

### Scheduler

Run tasks on intervals or at specific times. Handlers are forked like HTTP requests — they don't block the event loop and respect `requestTimeout`.

```json
{
    "Q": {
        "scheduler": {
            "cleanup": {
                "handler": "tasks/cleanup",
                "every": 3600
            },
            "daily-report": {
                "handler": "tasks/report",
                "times": ["09:00"]
            },
            "business-check": {
                "handler": "tasks/check",
                "times": ["09:00", "12:00", "17:00"],
                "weekdays": ["mon", "wed", "fri"]
            },
            "monthly-invoice": {
                "handler": "tasks/invoice",
                "times": ["00:00"],
                "monthdays": [1]
            }
        }
    }
}
```

| Field | What it does |
|---|---|
| `handler` | Handler path — dispatched via `Q::event()`, same as HTTP handlers |
| `every` | Run every N seconds from startup |
| `times` | Run at specific `HH:MM` times (24h format) |
| `weekdays` | Only fire on these days: `mon`, `tue`, `wed`, `thu`, `fri`, `sat`, `sun` |
| `monthdays` | Only fire on these days of the month: `[1]`, `[1, 15]`, etc. |

The handler receives `$params['task']` (the task name) and `$params['scheduled'] = true`:

```php
<?php
// handlers/tasks/cleanup.php function tasks_cleanup(&$params, &$result) {
    MyApp\Sessions::expireOld();
    MyApp\Logs::rotate();
}
```

On restart, tasks scheduled for the current minute are skipped to avoid double-firing. Interval tasks wait one full interval before their first run.

### CGI carveout mode — legacy PHP compatibility

Scripts matching `Q.webserver.cgi.patterns` run via `php-cgi` subprocess instead of fork. Native `header()`, `setcookie()`, `session_start()` all work — full compatibility with WordPress, Laravel, or any PHP code that calls `header()` directly.

```json
{
    "Q": {
        "webserver": {
            "cgi": {
                "patterns": [
                    "/wp-admin/.*\\.php$",
                    "/wp-login\\.php$",
                    "/legacy/.*\\.php$"
                ]
            }
        }
    }
}
```

The tradeoff: CGI mode starts a fresh PHP interpreter per request (~50ms), so you don't get the preload speed benefit. Static files, caching, and everything else still work at full speed. Use this for third-party code you can't modify — your own code should use `Q_Response::header()` and the fork path for 100–300× concurrent capacity (measured).

The server auto-detects `php-cgi` on your system. Override with `cgi.binary`:

```json
{ "Q": { "webserver": { "cgi": { "binary": "/usr/bin/php-cgi8.3" } } } }
```

### Running legacy PHP — WordPress, Laravel, Symfony

You can run existing PHP applications on Qbix Server without modifying their code. The key: put the framework's public directory as `web/`, and use CGI carveout patterns to match all PHP files.

**WordPress:**

```
wordpress-site/ ├── qbixserver.php          ← copy here ├── src/                    ← copy here ├── config/ │   └── server.json └── web/                    ← symlink or copy of WordPress root
    ├── wp-admin/
    ├── wp-content/
    ├── wp-includes/
    ├── wp-login.php
    ├── index.php
    └── wp-config.php
```

```json
{
    "Q": {
        "webserver": {
            "cgi": {
                "patterns": ["\.php$"]
            },
            "fallback": "index.php"
        }
    }
}
```

The pattern `\.php$` sends all PHP files through `php-cgi`. The fallback sends unmatched URLs to `index.php` (WordPress permalink routing). Static files (images, CSS, JS) are served directly at full speed.

**Laravel:**

```
laravel-app/ ├── qbixserver.php ├── src/ ├── config/ │   └── server.json ├── web/                    ← symlink to Laravel's public/ │   ├── index.php │   └── .htaccess           ← ignored (no Apache) ├── app/ ├── routes/ ├── storage/ └── vendor/
```

```json
{
    "Q": {
        "webserver": {
            "cgi": {
                "patterns": ["\.php$"]
            },
            "fallback": "index.php"
        }
    }
}
```

All requests that don't match a static file go to `index.php`. Laravel's router takes over from there. The `app/`, `vendor/`, and `storage/` directories are outside `web/` — inaccessible via URL by default.

**Symfony:**

```
symfony-app/ ├── qbixserver.php ├── src/ ├── config/ │   ├── server.json │   └── ...                 ← Symfony config files ├── web/                    ← symlink to Symfony's public/ │   └── index.php ├── src/                    ← Symfony source (separate from Qbix src/) ├── var/ └── vendor/
```

Same config pattern. Symfony's front controller (`public/index.php`) handles all routing internally.

**Porting your own legacy code:**

For code you control, you have three options — from least effort to best performance:

**Option 1: Full CGI (zero changes, slower)**

```json
{ "Q": { "webserver": { "cgi": { "patterns": ["\.php$"] } } } }
```

Every PHP file runs through `php-cgi`. Native `header()`, `setcookie()`, `session_start()` all work. No code changes. Performance is comparable to nginx + php-fpm (no preload benefit).

**Option 2: Targeted carveouts (minimal changes, mostly fast)**

```json
{
    "Q": {
        "webserver": {
            "cgi": {
                "patterns": [
                    "/admin/.*\.php$",
                    "/legacy/.*\.php$"
                ]
            }
        }
    }
}
```

Only specific paths use CGI. New code and simple scripts use fork mode (100–300× concurrent capacity (measured)). Legacy code that calls `header()` directly stays in CGI mode.

**Option 3: Find-replace (one-time effort, full performance)**

In your PHP files, replace:
```
header(       →  Q_Response::header( setcookie(    →  Q_Response::setCookie(
```

Two find-replaces. Your code now uses fork mode everywhere — 30× concurrent capacity capacity, preloaded classes, shared-nothing safety.

### Installing php-cgi

CGI carveout mode requires the `php-cgi` binary:

```bash
# Ubuntu/Debian
sudo apt install php-cgi

# macOS
brew install php    # includes php-cgi

# CentOS/RHEL
sudo yum install php-cgi

# Verify
php-cgi --version
```

---

---
[← Back to README](../README.md)

