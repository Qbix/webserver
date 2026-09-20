## 🌐 HTTP — Fork Per Request

Every PHP request forks from the preloaded parent, handles the request, and dies. No cleanup needed — the OS reclaims everything.

### Static files

Drop files in `web/`. They're served directly:

```
web/ ├── index.html        ← GET /index.html ├── style.css         ← GET /style.css └── app.js            ← GET /app.js
```

### PHP scripts

PHP files in `web/` execute as scripts — same as Apache or nginx + php-fpm:

```php
<?php
// web/api/users.php — GET /api/users.php Q_Response::header('Content-Type: application/json');
$users = MyApp\Users::recent(20);
echo json_encode($users);
```

### Clean URL handlers

With [routing configured](#️-clean-url-routing-optional), handlers in `handlers/` map to clean URLs:

```php
<?php
// handlers/api/users/get.php — GET /api/users function api_users_get(&$params, &$result) {
    Q_Response::header('Content-Type: application/json');
    echo json_encode(MyApp\Users::recent(20));
}
```

### What happens per request

```
Browser: GET /api/users
  → Parent forks child process (COW — ~200KB delta for typical handlers)
  → Child runs handler (classes already loaded)
  → Child sends response and exits
  → OS reclaims all memory
```

No memory leaks. No state from one request bleeding into the next. `exit()` only kills the child — the server keeps running.

---

---
[← Back to README](../README.md)

