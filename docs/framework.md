## 📂 For PHP Developers — The Micro-Framework

Qbix Server isn't just a static file server with PHP bolted on. It's a micro-framework where you **drop files into conventional directories** and things just work — classes autoload, events fire handlers, views render templates. No configuration needed for the basics.

### Project layout

```
myproject/ ├── qbixserver.php              ← server entry point (or use the PHAR) ├── config/ │   └── server.json             ← server + app configuration ├── web/                        ← document root (publicly accessible) │   ├── index.html              ← static files served directly │   ├── style.css │   ├── api.php                 ← PHP scripts executed on request │   └── uploads/ ├── classes/                    ← your PHP classes (autoloaded when first used) │   ├── MyApp/ │   │   ├── User.php            ← MyApp\User or MyApp_User │   │   ├── Feed.php │   │   └── Auth.php │   └── vendor/ │       └── autoload.php        ← Composer autoloader (optional) ├── handlers/                   ← event handlers (loaded on demand) │   └── MyApp/ │       └── feed/ │           ├── post.php        ← handles "MyApp/feed/post" event │           └── validate.php    ← handles "MyApp/feed/validate" event └── views/                      ← PHP templates for Q::view()
    └── MyApp/
        └── feed/
            ├── page.php
            └── item.php
```

Only `web/` is accessible via HTTP. Everything else is server-side only.

**Your PHP scripts don't need to `require` or `include` anything.** The server has already loaded the `Q` class, the autoloader, and the event system before your script runs. Classes from `classes/`, events via `Q::event()`, views via `Q::view()` — all available immediately. Just write your code:

```php
<?php
// web/api.php — no require, no include, no bootstrap use MyApp\User;

$user = User::find($_GET['id']);
$feed = Q::event('MyApp/feed/get', ['userId' => $user->id]);

Q_Response::header('Content-Type: application/json');
echo json_encode($feed);
```

### The `Q` class — available in every script

The server injects the `Q` class into every PHP script automatically. Here's what you get:

| Method | What it does |
|---|---|
| `Q::event($name, $params)` | Fire an event — runs the handler from `handlers/` |
| `Q::canHandle($name)` | Check if a handler exists for an event |
| `Q_Response::header($str, $replace, $code)` | Set a response header (use instead of `header()`) |
| `Q::view($name, $params)` | Render a PHP template from `views/` |
| `Q::ifset($arr, 'key1', 'key2', $default)` | Safe nested array/object access without isset chains |
| `Q::getObject($data, ['path', 'to', 'key'], $default)` | Deep access into nested arrays/objects |
| `Q::setObject(['path', 'to', 'key'], $value, $data)` | Deep set into nested arrays, creating intermediates |
| `Q::json_encode($value)` | `json_encode` with unescaped slashes |
| `Q::json_decode($json, true)` | `json_decode` wrapper |
| `Q_Config::get('section', 'key', $default)` | Read from `config/server.json` |
| `Q_Config::set('section', 'key', $value)` | Set a config value at runtime |
| `Q_Config::expect('section', 'key')` | Read config or throw if missing |
| `Q_Request::method()` | HTTP method: GET, POST, PUT, DELETE |
| `Q_Request::input()` | Raw request body (replaces `php://input`) |
| `Q_Request::json()` | Request body parsed as JSON |
| `Q_Request::header('X-Custom')` | Get any request header |
| `Q_Request::ip()` | Client IP (proxy-resolved) |
| `Q_Request::files('avatar')` | Uploaded files from `$_FILES` |
| `Q_Request::isAjax()` | True if X-Requested-With: XMLHttpRequest |
| `Q_Request::isJson()` | True if Content-Type is application/json |
| `Q_Request::isInternal()` | True if genuine CLI, false if server-dispatched |
| `Q_Response::setHeader($name, $value)` | Set a response header |
| `Q_Response::code(201)` | Set HTTP status code |
| `Q_Response::setCookie($name, $val, ...)` | Set a cookie (prevents duplicates) |
| `Q_Response::redirect($url)` | 302 redirect (or 301 with `permanently`) |

```php
<?php
// web/settings.php — using Q utilities

// Safe deep access (no "undefined index" warnings) $theme = Q::ifset($_COOKIE, 'theme', 'light');

// Read app config from config/server.json $maxUpload = Q_Config::get('MyApp', 'upload', 'maxSize', 10485760);

// Fire an event with before/after hooks $result = Q::event('MyApp/settings/save', [
    'userId' => $_SESSION['user_id'],
    'theme'  => $_POST['theme'],
]);

// Render a view echo Q::view('MyApp/settings/page.php', [
    'result' => $result,
    'theme'  => $theme,
]);
```

### Why `Q_Response::header()` instead of `header()`?

The server runs PHP in CLI SAPI (same as FrankenPHP worker mode and Workerman). PHP's built-in `header()` is silently discarded in CLI mode. `Q_Response::header()` has the exact same signature but captures headers so the server can send them:

```php
Q_Response::header('Content-Type: application/json');   // same as header() but works Q_Response::header('HTTP/1.1 201 Created')
;             // status line Q_Response::code(201)
;                                  // or set status directly

Q_Response::setHeader('X-Custom', 'value');    // named method Q_Response::code(201)
;                         // status code Q_Response::setCookie('session', $id)
;         // cookies Q_Response::redirect('/login')
;                // redirect
```

For existing code that calls `header()` directly, use CGI carveout mode — configure URL patterns in `server.json` under `Q.webserver.cgi.patterns` to run those scripts via `php-cgi` where native `header()` works (see Configuration).

When you upgrade to the full [Qbix Platform](https://github.com/Qbix/Platform), the `Q` class expands with hundreds more methods — but everything above continues to work identically. Your scripts don't need to change.

### Classes — autoloaded and optionally preloaded

Drop a PHP file in `classes/` and it's **autoloaded** — found automatically the first time your code references it. No `require` needed. Both naming conventions work:

```php
<?php
// classes/MyApp/User.php — namespace style (PSR-4) namespace MyApp;

class User {
    public static function fromSession(): ?self { /* ... */ }
    public static function find(string $id): ?self { /* ... */ }
}
```

```php
<?php
// classes/MyApp/Auth.php — underscore style (Qbix convention) class MyApp_Auth {
    static function check(): bool { return !empty($_SESSION['user_id']); }
}
```

Both are available immediately in your `web/*.php` scripts:

```php
<?php
// web/profile.php — both class styles work, no require needed use MyApp\User;

$user = User::fromSession();
$isAdmin = MyApp_Auth::check();
```

The autoloader maps class names to file paths (`MyApp\User` → `classes/MyApp/User.php`, `MyApp_Auth` → `classes/MyApp/Auth.php`) and bridges between conventions with `class_alias` — if you define `MyApp_Auth`, it's also accessible as `MyApp\Auth`, and vice versa. If you have a Composer `autoload.php`, that works too — list it in the preload config and both autoloaders coexist.

**Preloading** is optional but recommended for `--workers=N` mode. It loads specific classes into memory *before* forking workers, so the autoloader never runs during requests — classes are already there via copy-on-write:

```json
{
    "Q": {
        "webserver": {
            "preload": {
                "autoload": "classes/vendor/autoload.php",
                "classes": [
                    "MyApp\\User",
                    "MyApp\\Feed",
                    "MyApp_Auth"
                ]
            }
        }
    }
}
```

```bash
php qbixserver.php --workers=4
#  Autoloader: autoload.php
#  Preloaded: 3 classes
```

Classes are **eager** — loaded once at startup, shared across all workers via copy-on-write. This is the "hot path" code that handles every request.

### Handlers — loaded on demand

Handlers are the opposite of classes: they're loaded **only when their event fires**. Drop a file in `handlers/` and it's available as an event:

```php
<?php
// handlers/MyApp/feed/post.php
// Handles the "MyApp/feed/post" event
// Function name = path with slashes replaced by underscores

function MyApp_feed_post(&$params, &$result) {
    $title = $params['title'] ?? 'Untitled';
    $userId = $params['userId'] ?? null;

    // Validate, save to DB, whatever
    $id = saveFeedPost($userId, $title);

    $result = ['id' => $id, 'title' => $title, 'saved' => true];
    return $result;
}
```

Fire it from anywhere:

```php
<?php
// web/api.php $result = Q::event('MyApp/feed/post', [
    'title'  => $_POST['title'],
    'userId' => $_SESSION['user_id'],
]);

Q_Response::header('Content-Type: application/json');
echo json_encode($result);
```

The handler file is `include`'d the first time the event fires, then the function stays in memory. If the event never fires, the file is never loaded. This is ideal for things like webhooks, admin actions, and error handlers — code that runs rarely but needs to be available.

**Check if a handler exists:**

```php
if (Q::canHandle('MyApp/feed/post')) {
    Q::event('MyApp/feed/post', $params);
}
```

### Before/after hooks

You can attach hooks to any event via config — useful for validation, logging, access control, or cross-cutting concerns:

```json
{
    "Q": {
        "handlersBeforeEvent": {
            "MyApp/feed/post": ["MyApp/feed/validate"]
        },
        "handlersAfterEvent": {
            "MyApp/feed/post": ["MyApp/feed/notify"]
        }
    }
}
```

```php
<?php
// handlers/MyApp/feed/validate.php function MyApp_feed_validate(&$params, &$result) {
    if (empty($params['title'])) {
        $result = ['error' => 'Title required'];
        return false; // stops the event chain — main handler won't fire
    }
}
```

```php
<?php
// handlers/MyApp/feed/notify.php function MyApp_feed_notify(&$params, &$result) {
    // Runs after the main handler
    if (!empty($result['saved'])) {
        sendNotification($params['userId'], "Post published: " . $result['title']);
    }
}
```

The chain is: **before hooks → main handler → after hooks**. Any before hook returning `false` stops the chain. This is the same pattern the full [Qbix Platform](https://github.com/Qbix/Platform) uses — your handlers work identically when you upgrade.

### Remote handlers

Handlers can also be URLs. If a handler name in the config starts with `http://` or `https://`, the server POSTs the event parameters as JSON to that URL instead of loading a local PHP file:

```json
{
    "Q": {
        "handlersAfterEvent": {
            "MyApp/user/register": ["https://hooks.example.com/new-user"]
        }
    }
}
```

When `Q::event('MyApp/user/register', $params)` fires, the local handler runs first, then the server POSTs `$params` as JSON to the remote URL. This is webhooks built into the event system — no separate webhook infrastructure needed.

### Views — PHP templates

Render PHP templates from the `views/` directory:

```php
<?php
// views/MyApp/feed/item.php
// Variables are extracted into scope from the $params array ?>
<article>
    <h2><?= htmlspecialchars($title) ?></h2>
    <p><?= htmlspecialchars($body) ?></p>
    <time><?= $time ?></time>
</article>
```

```php
<?php
// web/feed.php $items = MyApp\Feed::latest(10);
$html = ''; foreach ($items as $item) {
    $html .= Q::view('MyApp/feed/item.php', $item);
} echo Q::view('MyApp/feed/page.php', ['content' => $html]);
```

Views are just PHP files — full language access, no template DSL to learn.

### The philosophy

| | Loaded when | Lives in | Purpose |
|---|---|---|---|
| **Classes** | Startup (preloaded) | `classes/` | Models, services, utilities — your core code |
| **Handlers** | First event fire (on demand) | `handlers/` | Actions, hooks, webhooks — code that responds to events |
| **Views** | When rendered | `views/` | Templates — HTML with PHP |
| **Scripts** | When requested via HTTP | `web/` | Entry points — the "controller" layer |
| **Config** | Startup | `config/` | Settings, handler hooks, preload lists |

Classes are **eager**. Handlers are **lazy**. Scripts are **per-request**. Views are **on-demand**. This gives you the right loading strategy for each kind of code without thinking about it — just put files in the right directory.

### Workers: fork-per-request (truly shared-nothing)

Each worker handles exactly **one request**, then exits. The parent immediately forks a replacement. This means:

- Static variables — **wiped** (process dies)
- Global state — **wiped** (process dies)
- Memory leaks — **impossible** (OS reclaims everything)
- Secrets in memory — **gone** (no persistence between requests)

This is safer than php-fpm, which reuses workers across requests and relies on `pm.max_requests` to periodically recycle them. With Qbix Server, every request gets a clean process. The fork cost (~0.5ms) is negligible compared to the bootstrap savings (~10–50ms).

This is the default mode. Workers persist across requests, with all statics, globals, superglobals, and response headers reset between requests via a snapshot restore (~0.03ms). See [reset.md](docs/reset.md) for what resets, what doesn't, and how to write scripts that work in both modes.

### How PHP requests are handled

**Fork mode (default, no `--workers`):** On Linux and macOS (where `pcntl_fork` is available), every PHP request is forked — the server forks a child, the child handles the request and exits, the parent continues serving. This means:

- `exit()` / `die()` in a script only kills the child — the server survives
- Long-running scripts don't block static file serving
- Each request is truly isolated

**Default mode:** Persistent workers handle requests in a loop with snapshot restore between them. See [reset.md](docs/reset.md) for what resets, what persists, and how to write scripts for both modes.

The `--workers=N` flag pre-forks N idle workers for faster dispatch (no fork latency per request). Without it, the server forks on demand. Both modes are shared-nothing.

**Windows** doesn't have `pcntl_fork`, so PHP scripts run in a subprocess via `proc_open`. This is safe — `exit()` can't crash the server — but each subprocess starts a fresh PHP interpreter (~50ms), so you don't get the preload speed benefit. Static files, WebSocket, caching, and everything else work identically. Good for development; use Linux/macOS for the full 100–300× concurrent capacity (measured) advantage.

### Growing into the full Qbix Platform

The conventions above — `classes/`, `handlers/`, `views/`, `config/` — are the same ones the [Qbix Platform](https://github.com/Qbix/Platform) uses. When your project outgrows the micro-framework and you need user accounts, real-time streams, access control, payments, or a plugin system, you switch to `--app` mode and everything you've written keeps working. Your classes stay in `classes/`, your handlers stay in `handlers/`, your views stay in `views/`. You just gain access to Streams, Users, Assets, and the rest of the plugin ecosystem — without rewriting anything.

---

---
[← Back to README](../README.md)

