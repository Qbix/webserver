# Getting Started

This guide walks you through going from zero to a running server with users, branches, and AI assistant access. Each section links to a deeper doc when there's more to know.

## 1. Run the server

The fastest way:

```bash
php qbixserver.php
```

You'll see a startup banner with the port number (default 8080). Open that in a browser and you'll see the welcome page with links to the Dashboard, Control Panel, and Docs.

If you downloaded the PHAR or a static binary, substitute that:

```bash
php bin/qbixserver.phar --port=8080
# or
./qbixserver --port=8080
```

See [Running & Building](running.md) for all the options — Unix sockets, custom roots, HTTPS, static binaries.

## 2. Set up the control panel

Open `/Q/panel` in your browser. On first visit you'll set the **owner password**. This is the root account — it can do everything, including creating other users.

The panel has tabs for:

- **Apps** — discover and serve app directories
- **Branches** — create copy-on-write branches with their own subdomains
- **Users** — add people and assign roles
- **Config** — edit every server setting live, with immediate effect
- **Scripts** — run PHP scripts from `scripts/Q/`
- **Plugins** — manage Qbix Platform plugins (if installed)
- **Playground** — sandboxed PHP REPL
- **System** — PHP info, one-click Platform install

The panel is localhost-only by default. To access it remotely, set `Q.panel.remote` to `true` in your config or via the Config tab.

See [Dashboard & Panel](dashboard.md) for details on each tab.

## 3. Serve your app

**Static site or custom PHP:** Drop files in `web/`. An `index.html` there replaces the welcome page. PHP files are executed in forked processes — just write normal PHP.

**Existing framework app:** Qbix Server runs WordPress, Laravel, Symfony, Drupal, CakePHP, CodeIgniter, Yii, and more — unmodified. Point the server at your framework's public directory and add a CGI carveout pattern:

```bash
php qbixserver.php --root=./public --preset=laravel
```

Or without a preset:

```json
{
    "Q": {
        "webserver": {
            "cgi": { "patterns": ["\\.php$"] },
            "fallback": "index.php"
        }
    }
}
```

See [Supported Frameworks](FRAMEWORKS.md) for framework-specific setup and benchmarks.

## 4. Add users

From the Users tab in the control panel, click **Add User**. There are four roles:

| Role | What they can do |
|---|---|
| **Owner** | Everything — manage all users, all config, system settings |
| **Admin** | Manage managers and users, access admin-level config |
| **Manager** | Manage users within a scope (e.g. specific apps or domains) |
| **User** | View and modify safe-level config only |

Each role can only create roles below it. Managers can be given a **scope** — a list of apps or domains they administer — via the Scope button on their user card.

## 5. Create branches

Branches are copy-on-write overlays of your production site. Each branch gets its own subdomain (e.g. `feature.yoursite.com`), its own database clone, and its own file layer. Nothing in a branch affects production until an admin approves a merge.

From the Branches tab, click **Create Branch**. You'll specify a name (which becomes the subdomain) and optionally a database to clone.

You can also create branches via the API:

```bash
curl -X POST http://localhost:8080/Q/panel/api/branches/create \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"appHost": "yoursite.com", "branchName": "feature"}'
```

**Access control:** Each branch has a per-user permission map controlling who can view, edit, or admin the branch, and which file types they can push (styles, markup, frontend, or full code access).

**TLS:** If `Q.webserver.tls.acmeEmail` is configured and you have a wildcard DNS record (`*.yoursite.com`), the server provisions a Let's Encrypt certificate for each branch subdomain automatically.

See [AI-Assisted Collaboration](COLLABORATION.md) for the full branch workflow including merge requests.

## 6. Connect an AI assistant

AI assistants (Claude, ChatGPT, Cursor, etc.) can discover and configure your server through standard protocols. Two steps: create a token, then connect.

### Create an API token

From the panel, log in and create a long-lived token via the API:

```bash
# Log in to get a session token
SESSION=$(curl -s -X POST http://localhost:8080/Q/panel/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username": "owner", "password": "your-password"}' | jq -r .token)

# Create an API token (valid for 90 days)
curl -s -X POST http://localhost:8080/Q/panel/api/auth/token \
  -H "Authorization: Bearer $SESSION" \
  -H "Content-Type: application/json" \
  -d '{"label": "Claude Assistant", "expiryDays": 90}'
```

Save the returned token — it won't be shown again. You can list active tokens and revoke by prefix:

```bash
# List tokens
curl http://localhost:8080/Q/panel/api/auth/tokens \
  -H "Authorization: Bearer $SESSION"

# Revoke by prefix (at least 8 characters)
curl -X POST http://localhost:8080/Q/panel/api/auth/token/revoke \
  -H "Authorization: Bearer $SESSION" \
  -H "Content-Type: application/json" \
  -d '{"prefix": "a1b2c3d4"}'
```

### Connect via MCP

Point your MCP client at the server's endpoint:

```
Endpoint: https://yoursite.com/mcp
Transport: Streamable HTTP (JSON-RPC 2.0 over POST)
Authentication: Bearer token in Authorization header
```

The assistant gets tools for everything: reading and editing files on branches, managing config, creating users, provisioning branches — all through the same permission model as human users.

### Discovery endpoints

Your server advertises its capabilities at:

| Endpoint | Format | For |
|---|---|---|
| `/mcp` | MCP protocol | Claude, MCP-compatible tools |
| `/.well-known/openapi.json` | OpenAPI 3.1 | ChatGPT, Postman, Swagger UI |
| `/llms.txt` | Plain text | LLM context windows |
| `/.well-known/ai-plugin.json` | OpenAI plugin | ChatGPT custom GPTs |
| `/.well-known/mcp.json` | JSON manifest | MCP server discovery |

See [API Discovery](api-discovery.md) for the full spec and compatibility matrix.

## 7. Configure the server

There are two ways to change settings:

**Config tab** in the control panel — a tree view of every setting, organized by namespace. Changes take effect immediately and persist across restarts. Search, filter by access level, and see which values differ from defaults.

**config/server.json** — a JSON file next to your `web/` directory. Good for version-controlled defaults:

```json
{
    "Q": {
        "webserver": {
            "maxConnections": 1024,
            "rateLimit": { "enabled": true, "requests": 100, "window": 60 },
            "hotReload": true
        }
    }
}
```

Panel overrides take precedence over file-based config.

Key settings to know about:

| Setting | What it does |
|---|---|
| `webserver.boot.workers` | Number of persistent workers (0 = fork-per-request) |
| `webserver.tls.acmeEmail` | Enable automatic HTTPS via Let's Encrypt |
| `webserver.hotReload` | Watch for file changes and restart automatically |
| `webserver.rateLimit.enabled` | Per-IP rate limiting |
| `panel.remote` | Allow control panel access from non-localhost |

See [Configuration](configuration.md) for the complete reference.

## 8. Next steps

- [Dashboard & Panel](dashboard.md) — live metrics, request log, WebSocket monitoring
- [Client Metrics](METRICS.md) — opt-in client-side telemetry (scroll depth, media tracking, SPA navigation)
- [Deploy & Federation](deploy.md) — reverse proxy setup, federation between servers
- [WebSocket & Rooms](websocket.md) — real-time features with per-connection and per-room processes
- [API Discovery](api-discovery.md) — OpenAPI, MCP, OpenClaiming, HTTP/2

---

---
[← Back to README](../README.md)
