# ✅ Todo

CRUD todo list backed by SQLite.

```bash
php qbixserver.php --root=examples/todo/web
```

Open http://localhost:4000 — add tasks, check them off, delete them.

## What it demonstrates

- **SQLite** — persistent storage with PDO, auto-creates database on first run
- **REST API** — GET/POST/PUT/DELETE on a single PHP endpoint
- **JSON request/response** — `Content-Type: application/json` throughout
- **`Q_Response::header()`** — setting response headers in the CLI SAPI
- **Graceful fallback** — handles missing SQLite extension with an error message

## Files

| File | What it does |
|---|---|
| `web/index.html` | Todo list UI with animated transitions. Fetches from the API, renders client-side. |
| `web/api.php` | REST endpoint — routes by `$_SERVER['REQUEST_METHOD']`, reads JSON body from `php://input` |
| `web/favicon.svg` | Green checkmark in a circle |

## How it works

The API uses a single `api.php` file that switches on the HTTP method:

| Method | Action | Body |
|---|---|---|
| `GET` | List all todos | — |
| `POST` | Create a todo | `{"text": "Buy milk"}` |
| `PUT` | Toggle done status | `{"id": 1, "done": 1}` |
| `DELETE` | Delete a todo | `{"id": 1}` |

The SQLite database is created at `data/todos.db` (sibling of `web/`). The schema auto-creates on first request. Tasks have `id`, `text`, `done`, and `created_at` columns.

The frontend is vanilla JavaScript — `fetch()` calls, DOM manipulation, CSS animations. No build step, no framework.
