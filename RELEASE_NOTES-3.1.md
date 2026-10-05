# Qbix Server v3.1 — Branch Subdomains & Auto-TLS

v3.0 added observability. v3.1 gives every branch its own subdomain and automatically provisions a TLS certificate for it — no manual cert management, no DNS provider API.

## Branch Subdomain Routing

Each branch is now accessible at `subdomain.apphost.com`. The subdomain defaults to the branch name (DNS-sanitized, lowercase, 1–63 characters). It's unique within each app and can be changed at any time through the Panel API or control panel UI.

Routing works by matching the incoming `Host` header: when the host is `feature.myapp.com` and `feature` maps to a known branch of `myapp.com`, the request goes to that branch's copy-on-write directory and database. The existing header (`X-Q-Branch`) and cookie (`_q_branch`) routing still work alongside subdomain routing.

**Panel API:**
- `POST /Q/panel/api/branches/subdomain` — get or set a branch's subdomain

**DNS setup:** Point `*.yourapp.com` to the server's IP with a wildcard A record. That's the only DNS configuration needed.

## Per-Subdomain TLS Certificate Provisioning

When `Q.webserver.tls.acmeEmail` is configured, the server provisions a Let's Encrypt certificate for each branch subdomain automatically using HTTP-01 challenges. No DNS provider credentials are needed.

### How it works

1. **At startup**, the server iterates all existing branches and provisions certs for any subdomain that doesn't have a valid one.

2. **On branch creation or subdomain change**, the worker process writes a marker file to `local/certs/.pending/`. The parent process checks for markers every 30 seconds and provisions certs in-process — this is where `$pendingChallenges` lives and where `handleRequest()` serves the ACME validation responses.

3. **Renewal** happens on the existing 12-hour timer. Branch subdomain certs are renewed alongside primary domain certs when they have fewer than 30 days remaining.

4. **SNI routing** registers each branch cert in the server's SNI map. HTTPS connections to `feature.myapp.com` get the correct certificate automatically, with no restart needed.

5. **Retry logic** retries failed provisioning up to 5 times (once per 30-second tick), then gives up and logs the failure.

### Prerequisites

- A wildcard DNS A record: `*.yourapp.com → server IP`
- Port 80 reachable from the internet (for ACME HTTP-01 validation)
- Config: `Q.webserver.tls.acmeEmail` set to a valid email address

### Rate limits

Let's Encrypt allows 50 certificates per registered domain per week. For development, set `Q.webserver.tls.acmeStaging: true` to use the staging environment (higher limits, untrusted certs).

### Cert storage

```
local/certs/
  account.pem                     # ACME account key (shared)
  myapp.com/
    fullchain.pem                  # Primary domain cert
    privkey.pem
  feature.myapp.com/
    fullchain.pem                  # Branch subdomain cert
    privkey.pem
  .pending/                        # Marker files (transient)
```

## Per-Branch Access Control

Each branch now has an access map that controls who can do what:

```json
{
  "alice": {"branch": "admin", "files": "code"},
  "bob":   {"branch": "edit",  "files": "frontend"},
  "carol": {"branch": "view",  "files": "none"}
}
```

**Branch permission** (`view` / `edit` / `admin`) controls whether a user can see the branch, push changes to it, or configure it.

**Files permission** (`none` / `styles` / `markup` / `frontend` / `code`) controls which file types the user can push, using the same tier system as default lockdown.

**Panel API:**
- `POST /Q/panel/api/branches/access` — get or set the access map

The control panel's Branches tab includes an access dialog for managing permissions per user.

## Role-Based Permissions

Four-tier role hierarchy with cascading permissions:

| Role | Level | Can Manage | Config Access |
|---|---|---|---|
| Owner | 100 | Everyone | All (including system) |
| Admin | 80 | Managers, Users | Admin + Safe |
| Manager | 60 | Users (within scope) | Safe only |
| User | 40 | — | Safe only |

Each role can only create or modify roles below it in the hierarchy. Managers have an optional **scope** — a list of app/domain restrictions (e.g. `app:myapp.com`, `domain:example.com`) that limits which resources they can administer.

**Panel API:**
- `POST /Q/panel/api/users/update` — change role, set scope, reset password (respects hierarchy)
- `POST /Q/panel/api/users/add` — create user (role must be below caller's)
- `GET /Q/panel/api/auth/me` — returns current user's role and scope

## Runtime Config Editor

The Config tab exposes all 33+ server configuration keys in a hierarchical tree view organized by namespace (`Q` → `dashboard`, `web`, `webserver` → `autohost`, `boot`, `clientMetrics`, etc.). Each key shows its description, access level, type, and an appropriate input control.

Features:
- **Expand/collapse tree** — click group headers to show/hide children; leaf counts on each group
- **Level filter** — show only safe, admin, or system keys
- **Search** — filter by key name or description
- **Overrides only** — show only keys changed from defaults, with "Revert" buttons
- **Custom keys** — admins/owners can set arbitrary config keys
- **Live application** — changes take effect immediately via `Q_Config::set()`
- **Persistence** — overrides stored in `panel.json`, survive restarts

**Panel API:**
- `GET /Q/panel/api/config` — all config values (filtered by caller's role)
- `POST /Q/panel/api/config/update` — set a config value (validates type and role)
- `POST /Q/panel/api/config/delete` — revert an override to default
- `GET /Q/panel/api/config/schema` — full schema with types, levels, descriptions

## Control Panel Updates

The Branches tab has been redesigned:

- **Branch cards** show the subdomain URL, creation date, creator, database name, access count, and TLS status
- **Subdomain editing** — click the pencil icon to change a branch's subdomain inline
- **Copy URL** — one-click copy of the branch's full URL
- **TLS badge** — green checkmark when provisioned, yellow "pending" during provisioning, gray "no TLS" otherwise
- **Access dialog** — add/remove users, set branch and file permissions per user
- **Mobile-responsive** — cards stack vertically on small screens with full-width action buttons

The Users tab now shows:

- **Role badges** — color-coded by role
- **Scope badges** — purple pills showing manager scopes (e.g. `app:myapp.com`)
- **Manager role** in the add-user dropdown
- **Scope button** — set or change a manager's scope

The Config tab is new — see "Runtime Config Editor" above.

## API Tokens for AI Assistants

Long-lived API tokens allow AI assistants and integrations to authenticate with the panel API without going through the login flow. Tokens are stored in `panel.json` under `apiTokens`, separate from short-lived session tokens.

**Panel API:**
- `POST /Q/api/auth/token` — create a token (label, expiryDays 1–365, default 90)
- `GET /Q/api/auth/tokens` — list active tokens (admins see all, users see their own)
- `POST /Q/api/auth/token/revoke` — revoke by prefix (at least 8 characters)

Tokens use the same Bearer authentication as session tokens — pass them in the `Authorization: Bearer <token>` header or the `X-Panel-Token` header.

## Panel API Discovery

The full panel API (17 endpoints covering auth, config, users, and branches) is now discoverable through standard well-known endpoints, enabling AI assistants to configure sites without custom integration:

- **OpenAPI** (`/.well-known/openapi.json`) — panel endpoints appear alongside app handler endpoints, tagged by category (Panel Auth, Panel Config, Panel Users, Panel Branches)
- **MCP** (`/.well-known/mcp.json` and `/mcp`) — panel tools use the `panel_*` prefix (e.g. `panel_config_get`, `panel_users_add`). MCP tool calls are dispatched directly to the panel API handlers.
- **llms.txt** (`/llms.txt`) — human-readable summary of all tools with workflows for code editing and site configuration
- **AI Plugin** (`/.well-known/ai-plugin.json`) — OpenAI-compatible plugin manifest referencing the OpenAPI spec

All endpoints are generated from a single source of truth (`panelApiEndpoints()` in Panel.php), so they stay in sync automatically.

## Configuration

All under `Q.webserver`:

| Key | Default | Purpose |
|---|---|---|
| `tls.acmeEmail` | `""` | ACME contact email (required for auto-TLS) |
| `tls.certDir` | `"local/certs"` | Where certs are stored |
| `tls.acmeStaging` | `false` | Use Let's Encrypt staging environment |

## Upgrading from v3.0

No breaking changes. All v3.0 configuration, APIs, and features are preserved. Branch subdomain routing and auto-TLS activate automatically when branches exist and `acmeEmail` is configured. Existing branches get subdomains defaulting to their branch name.
