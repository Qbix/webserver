## 🔒 Server Headers — What Your PHP Can Send

Qbix Server understands special response headers from your PHP scripts. These are the same headers nginx understands (like `X-Accel-Redirect`) plus new ones for component-level caching. Your PHP sends them with `Q_Response::header()`, the server acts on them.

> **Use `Q_Response::header()`, not PHP's `header()`.** The server runs PHP in the CLI SAPI, where the built-in `header()` and `http_response_code()` are silently discarded. `Q_Response::header()`, `Q::header()`, and `Q_WebServer_State::header()` all work in both standalone and `--app` mode. See [Setting headers, status codes and cookies](#setting-headers-status-codes-and-cookies) for the full table.

### Quick reference

| Header | What it does | Example |
|---|---|---|
| `Cache-Control` | Server caches the response, serves without running PHP | `Q_Response::header('Cache-Control: public, max-age=300');` |
| `X-Accel-Redirect` | Server streams a file after PHP checks access | `Q_Response::header('X-Accel-Redirect: /uploads/private/doc.pdf');` |
| `X-Cache-Tree` | Registers page components with content hashes | `Q_Response::header('X-Cache-Tree: ' . json_encode([...]));` |
| `X-Cache-Deps` | Maps components to data dependency keys | `Q_Response::header('X-Cache-Deps: ' . json_encode([...]));` |
| `X-Cache-Invalidate` | Marks dependency keys as stale | `Q_Response::header('X-Cache-Invalidate: ' . json_encode([...]));` |
| `X-Cache-Stale` | Invalidates cached pages containing these components | `Q_Response::header('X-Cache-Stale: feed,sidebar');` |

All of these use `Q_Response::header()` instead of PHP's `header()`. This is because the server runs in CLI SAPI where `header()` calls are silently discarded — same as FrankenPHP worker mode and Workerman. `Q_Response::header()` has the same signature as `header()` but captures the values for the server to send. The server strips internal headers before sending the response to the client.

### Access-controlled static files

With a typical server, your uploaded files sit at public URLs. Anyone with the link can access them — and share the link with others. The usual workaround is "unguessable" URLs, which are just security through obscurity.

`X-Accel-Redirect` lets your PHP check access, then tells the server to serve the file. By convention, private files live in `files/` — a sibling of `web/`, outside the document root:

```
myproject/ ├── web/               ← public (accessible via URL) │   └── download.php   ← checks access, sends X-Accel-Redirect └── files/             ← private (NOT accessible via URL)
    └── private/
        └── doc.pdf    ← served only through download.php
```

```php
<?php
// web/download.php — access-controlled file serving session_start();

$fileId = $_GET['id'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

if (!$userId || !userCanAccess($userId, $fileId)) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

// Tell the server to serve from files/ directory.
// The client never sees the real path.
Q_Response::header("X-Accel-Redirect: /files/private/{$fileId}");
Q_Response::header("Content-Disposition: attachment; filename=\"document.pdf\"");
```

No config needed — `files/` is resolved automatically. For custom mappings:

```json
{
    "Q": {
        "webserver": {
            "accel": {
                "mappings": {
                    "/protected/": "/mnt/storage/protected/",
                    "/media/":     "/var/data/media/"
                }
            }
        }
    }
}
```

For nginx compatibility, mirror the mappings: `location /files/ { internal; alias /path/to/files/; }`

### Reverse proxy cache

Control how the server caches your PHP responses:

```php
<?php
// web/feed.php — cached for 5 minutes

// The server caches this response and serves it without
// running PHP again for the next 300 seconds.
Q_Response::header('Cache-Control: public, max-age=300');

echo renderFeed();
```

```php
<?php
// web/profile.php — cached, but revalidate with ETag

// The server generates an ETag from the response body.
// Browsers send If-None-Match on next request.
// Server returns 304 (no body) if nothing changed.
Q_Response::header('Cache-Control: public, max-age=0, must-revalidate');

echo renderProfile($userId);
```

```php
<?php
// web/admin.php — never cache

Q_Response::header('Cache-Control: no-store');

echo renderAdminPanel();
```

### Component-level cache invalidation

Most caching systems cache whole pages. When anything changes, you throw away the entire page and re-render everything. Qbix Server tracks which data each page depends on, so when data changes, only the affected pages are invalidated — not every page on the site.

**Step 1: Register components when rendering a page**

When PHP renders a page, it tells the server what data the page depends on. The server hashes each component and maps them to dependency keys. This lets the server know exactly which pages to invalidate when specific data changes.

```php
<?php
// web/community.php — a page with three components

$feedHtml    = renderFeed($communityId);
$sidebarHtml = renderSidebar($communityId);
$membersHtml = renderMembers($communityId);

// Tell the server about the component tree and what data each depends on Q_Response::header('X-Cache-Tree: ' . json_encode([
    'l' => [
        'feed'    => md5($feedHtml),
        'sidebar' => md5($sidebarHtml),
        'members' => md5($membersHtml),
    ]
]));

Q_Response::header('X-Cache-Deps: ' . json_encode([
    'feed'    => ["community/{$communityId}/feed"],
    'sidebar' => ["community/{$communityId}/about"],
    'members' => ["community/{$communityId}/participants"],
]));

Q_Response::header('Cache-Control: public, max-age=300');
echo $feedHtml . $sidebarHtml . $membersHtml;
```

**Step 2: Invalidate when data changes**

```php
<?php
// web/post.php — user posts to the feed saveNewPost($communityId, $content);

// Tell the server which dependency key changed Q_Response::header('X-Cache-Invalidate: ' . json_encode([
    "community/{$communityId}/feed"
]));

// The server walks its dependency graph:
//   community/123/feed → component 'feed' → page /community/123
// The FULL page cache for /community/123 is invalidated.
// Other pages (e.g. /community/456) stay cached.
// Next request to /community/123 → cache miss → PHP re-renders the full page.

echo json_encode(['ok' => true]);
```

The server tracks which pages depend on which data keys. When a dependency key is invalidated, it finds exactly which pages are affected and removes them from the cache. Pages with no stale dependencies continue serving from the in-memory cache without hitting PHP.

### Even more powerful with Qbix Platform

These headers work with `Q_Response::header()` calls as shown above. But with the [Qbix Platform](https://github.com/Qbix/Platform), it becomes automatic:

```php
// Tools call this during rendering — the framework handles the rest Q_Response::setCacheComponent('Streams/feed', $hash, [$depKey]);
Q_Response::invalidateCacheDeps($publisherId . '/' . $streamName);

// X-Accel-Redirect for access-controlled files Q_Response::redirect(['uri' => $internalPath, 'accel' => true]);

// Cache-Control with semantic options Q_Response::cacheFor(300);
```

The Platform's Streams plugin automatically invalidates cache dependencies when stream data changes — posts, relations, participant joins — so cached pages update themselves without any manual invalidation calls.

---

---
[← Back to README](../README.md)

