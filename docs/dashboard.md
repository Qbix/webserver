## 📊 Live Dashboard

Open `http://localhost/Q/dashboard` in your browser for a real-time server dashboard. Updates live via WebSocket — no polling, no page refreshes.

**What it shows:**

| Panel | Metrics |
|---|---|
| **Overview cards** | Total requests, current RPS (5-sec window), avg response time, slowest request, memory usage + peak, worker status, WebSocket connections, active rooms, data transferred, open connections |
| **Throughput sparkline** | Per-second request rate for the last 60 seconds — see traffic patterns at a glance |
| **Top paths** | Most-requested URLs with hit count and average response time — find your hot paths |
| **Active rooms** | WebSocket room workers with member count — monitor real-time features |
| **Live request log** | Scrolling feed of every request: timestamp, status code (color-coded), method, URI, response time in ms |

**Endpoints:**

| URL | Format | Use case |
|---|---|---|
| `/Q/dashboard` | HTML | Browser — the visual dashboard |
| `/Q/health` | JSON | Load balancers, uptime monitors (lightweight) |
| `/Q/stats` | JSON | Monitoring systems — full stats payload |

The `/Q/stats` JSON includes everything the dashboard shows, plus `sparkline` (60 data points), `topPaths`, `activeRooms`, `statusCodes` breakdown, and `cache` stats. Feed it to Grafana, Datadog, or your own monitoring.

---

## ⚙️ Control Panel

Password-protected admin panel at `/Q/panel`. First visit sets the password.

**Apps tab** — discovers sibling app directories (any folder with `web/` or `config/app.json`). Create new apps, serve them (hot-switches the document root), open in VS Code, run configure scripts. Editable apps directory path.

**Scripts tab** — list and run PHP scripts from `scripts/Q/` (configure, install, translate, etc.)

**Plugins tab** — reads the app's `config/app.json` for declared plugins, `local/plugins.json` for installed versions, and scans the Platform's `plugins/` directory. Shows version, dependencies, and DB connections for each.

**Playground tab** — PHP REPL with all Q classes preloaded. Write code, hit Run (or Ctrl+Enter), see output. Sandboxed in a forked process with disabled filesystem writes, no network, 32MB memory limit, 5 second timeout.

**System tab** — PHP version, OS, extensions, memory limit. One-click Platform install: clones `github.com/Qbix/Platform`, runs `git submodule update --recursive`, sets up `local/paths.json`.

**Config tab** — hierarchical tree view of all 33+ server configuration keys, organized by namespace (Q → dashboard, web, webserver → autohost, boot, clientMetrics, etc.). Each key shows its description, access level badge (safe/admin/system), type badge, and an appropriate input control (toggle for booleans, number input for integers, dropdown for selects, text for strings). Features:
- **Expand/collapse** — click any group node to show or hide its children; leaf counts shown next to each group
- **Level filter** — dropdown to show only safe, admin, or system-level keys
- **Search** — filter by key name or description
- **Overrides only** — checkbox to show only keys that have been changed from their defaults, with purple "override" badges and red "Revert" buttons
- **Custom keys** — admins and owners can set arbitrary config keys via a key/value form at the bottom

Config changes are applied live to the running server and persisted in `panel.json`. Overrides survive server restarts.

**Users tab** — multi-user management with a four-tier role hierarchy:
- **Owner** (level 100) — full control, can invite admins
- **Admin** (level 80) — can manage managers and users, access admin-level config
- **Manager** (level 60) — scoped permissions on specific apps or domains, can manage users within scope
- **User** (level 40) — can view and modify safe-level config only

Each role can only create or modify roles below it. Manager users have a configurable scope (e.g. `app:myapp.com`, `domain:example.com`) displayed as purple badges, editable via the "Scope" button. The tab shows user cards with role badges, branch access, and action buttons (change role, reset password, remove).

The panel is restricted to localhost by default. Set `Q.panel.remote: true` in config to allow remote access.

---

---
[← Back to README](../README.md)

