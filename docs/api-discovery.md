## 🔍 API Discovery

The server auto-generates discovery endpoints from its actual handlers, panel API, and configuration. No manual documentation needed — add a handler file or panel endpoint, and every spec updates automatically.

### Overview

| Endpoint | Format | Primary consumers |
|---|---|---|
| `/.well-known/openapi.json` | OpenAPI 3.1 | Postman, Swagger UI, Redoc, ChatGPT |
| `/.well-known/mcp.json` | MCP manifest | Claude, MCP-compatible tools |
| `/mcp` | MCP protocol (Streamable HTTP) | Claude, MCP clients (live tool calls) |
| `/llms.txt` | Plain text | LLM context windows, agent prompts |
| `/.well-known/ai-plugin.json` | OpenAI plugin manifest | ChatGPT custom GPTs |
| `/.well-known/qbix.json` | Qbix manifest | Federation between Qbix servers |
| `/.well-known/openclaiming/...` | OpenClaim (signed JSON) | Identity verification |

All discovery endpoints (except OpenClaiming) are generated from a single source of truth — the handler directory and `panelApiEndpoints()` in Panel.php — so they stay in sync automatically.

### Authentication

Discovery endpoints themselves are unauthenticated — anyone can read the specs. To **call** the APIs they describe, you need a Bearer token:

**Session token** (short-lived, from login):
```bash
TOKEN=$(curl -s -X POST https://yoursite.com/Q/panel/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username": "owner", "password": "your-password"}' | jq -r .token)
```

**API token** (long-lived, for AI assistants and integrations):
```bash
curl -s -X POST https://yoursite.com/Q/panel/api/auth/token \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"label": "Claude Assistant", "expiryDays": 90}'
```

Both token types use the same `Authorization: Bearer <token>` header (or `X-Panel-Token` header). API tokens inherit the creating user's role and are stored in `local/panel.json`.

See [Configuration — API Tokens](configuration.md#api-tokens) for token management (list, revoke).

---

### `/.well-known/openapi.json` — OpenAPI 3.1

Standard API spec compatible with Swagger UI, Postman, Redoc, Insomnia, and any OpenAPI-compatible tool.

The spec includes:

- **App handler endpoints** — auto-discovered from the `handlers/` directory. Each handler becomes a documented path with its event name, tags, and schema.
- **Built-in endpoints** — `/Q/health`, `/Q/event`
- **Panel API endpoints** — 17+ endpoints organized into four tag groups:

| Tag | Endpoints | Purpose |
|---|---|---|
| **Panel Auth** | login, logout, me, token, tokens, token/revoke | Authentication and API tokens |
| **Panel Config** | config, config/update, config/delete, config/schema | Runtime configuration |
| **Panel Users** | users/list, users/add, users/update, users/delete | User management |
| **Panel Branches** | branches/list, branches/create, branches/delete, branches/subdomain, branches/access | Branch management |

Usage:
- Paste the URL into **Postman** → Import → complete API documentation
- Point **Swagger UI** at it → interactive API explorer
- Feed it to **Redoc** → polished reference docs

---

### `/.well-known/mcp.json` — MCP Manifest

Static manifest that lets MCP-compatible tools discover the server's available tools. Each handler and panel endpoint becomes an MCP tool:

**App handler tools** — named after the handler path (e.g., `chat_join`, `chat_message`):
```json
{
    "tools": [
        {"name": "health", "description": "Check server health and uptime"},
        {"name": "event", "description": "Dispatch a Q::event() on this server"},
        {"name": "chat_join", "description": "Dispatch event: chat/join"}
    ]
}
```

**Panel tools** — prefixed with `panel_` (e.g., `panel_config_get`, `panel_users_add`):
```json
{
    "tools": [
        {"name": "panel_login", "description": "Authenticate and get a session token"},
        {"name": "panel_config_get", "description": "Get all config values"},
        {"name": "panel_config_update", "description": "Set a config value"},
        {"name": "panel_users_add", "description": "Create a new user"},
        {"name": "panel_branch_list", "description": "List branches for an app host"},
        {"name": "panel_branch_create", "description": "Create a new branch"}
    ]
}
```

**Branch collaboration tools** — for file-level operations on branches:
```json
{
    "tools": [
        {"name": "branch_list", "description": "List branches for an app host"},
        {"name": "branch_create", "description": "Create a new branch"},
        {"name": "file_list", "description": "List files in a branch directory"},
        {"name": "file_read", "description": "Read a file from a branch"}
    ]
}
```

The manifest includes input schemas for each tool, so AI assistants know what parameters to pass.

---

### `/mcp` — MCP Protocol Endpoint

The live MCP protocol endpoint. Unlike the static manifest at `/.well-known/mcp.json`, this is a working protocol endpoint that accepts tool calls via Streamable HTTP (JSON-RPC 2.0 over POST).

**Transport:** Streamable HTTP — each request is a JSON-RPC 2.0 message sent as a POST body, with the response returned in the POST response.

**Supported methods:**

| Method | Purpose |
|---|---|
| `initialize` | Start a session, negotiate capabilities |
| `tools/list` | List available tools (same as manifest) |
| `tools/call` | Execute a tool and get results |

**Example — list tools:**
```bash
curl -X POST https://yoursite.com/mcp \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

**Example — call a tool:**
```bash
curl -X POST https://yoursite.com/mcp \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "jsonrpc": "2.0",
    "id": 2,
    "method": "tools/call",
    "params": {
      "name": "panel_config_get",
      "arguments": {}
    }
  }'
```

**Connecting an AI assistant:** Point your MCP client at `https://yoursite.com/mcp` with a Bearer token. The assistant gets tools for everything: reading and editing files on branches, managing config, creating users, provisioning branches — all through the same permission model as human users.

---

### `/llms.txt` — LLM Context

A plain-text summary designed to fit in an LLM's context window. Contains:

- Server identity and version
- Available tools grouped by category (branch operations, panel management)
- Workflows — step-by-step instructions for common tasks like "edit code on a branch" or "configure the server"
- Authentication instructions

Useful for agent prompts and LLM system messages where a structured spec (OpenAPI/MCP) would be too verbose.

---

### `/.well-known/ai-plugin.json` — OpenAI Plugin Manifest

An OpenAI-compatible plugin manifest for ChatGPT custom GPTs and other tools that use the plugin ecosystem:

```json
{
    "schema_version": "v1",
    "name_for_human": "Qbix Server",
    "name_for_model": "qbix_server",
    "description_for_human": "Manage branches, files, config, and users on a Qbix Server",
    "description_for_model": "Qbix Server instance at yoursite.com. Provides branch-based collaboration...",
    "auth": {"type": "service_http", "authorization_type": "bearer"},
    "api": {"type": "openapi", "url": "https://yoursite.com/.well-known/openapi.json"}
}
```

The `description_for_model` field gives AI assistants context about how to use the server — always create or select a branch before making changes, use panel tools for configuration, etc.

---

### `/.well-known/qbix.json` — Server Manifest

Qbix-native discovery. Returns the server's identity, fingerprint, installed plugins, and links to other specs:

```json
{
    "server": "Qbix Server",
    "version": "3.1.0",
    "fingerprint": "4af468e461fc2022...",
    "endpoints": {
        "event": "/Q/event",
        "health": "/Q/health",
        "openapi": "/.well-known/openapi.json",
        "mcp": "/.well-known/mcp.json",
        "websocket": "/Q/ws"
    },
    "plugins": [
        {"name": "Users", "version": "1.0"}
    ]
}
```

Other Qbix servers use this for federation — pin the fingerprint, discover endpoints, forward events.

---

### Compatibility matrix

| Tool | Endpoint | How |
|---|---|---|
| **Claude** | `/mcp` | MCP protocol — direct tool calls |
| **Claude** (discovery) | `/.well-known/mcp.json` | MCP manifest — tool discovery |
| **ChatGPT** | `/.well-known/ai-plugin.json` | OpenAI plugin manifest |
| **ChatGPT** | `/.well-known/openapi.json` | OpenAPI spec for custom GPTs |
| **Cursor / Copilot** | `/.well-known/openapi.json` | OpenAPI-based tool use |
| **Any MCP client** | `/mcp` | Streamable HTTP transport |
| **Postman** | `/.well-known/openapi.json` | Import → Collections |
| **Swagger UI** | `/.well-known/openapi.json` | Interactive API explorer |
| **Redoc** | `/.well-known/openapi.json` | Static reference docs |
| **Other Qbix servers** | `/.well-known/qbix.json` | Federation + fingerprint pinning |
| **LLM agents** | `/llms.txt` | Context window / system prompt |
| **curl / monitoring** | `/Q/health` | `curl https://host/Q/health` |

All discovery endpoints are configurable. Set `Q.federation.advertise: false` to disable, or selectively hide apps and plugins.

---

### `/.well-known/openclaiming/{hostname}/server.json` — OpenClaiming

Every Qbix server auto-generates a signed [OpenClaim](https://openclaiming.org) for its identity. The claim is signed with ES256 (P-256) and verifiable by anyone with the public key.

```json
{
    "ocp": 1,
    "iss": "myserver.com/server",
    "stm": {
        "type": "server",
        "software": "Qbix Server",
        "version": "3.1.0",
        "fingerprint": "4af468e461fc2022...",
        "endpoints": {
            "event": "/Q/event",
            "health": "/Q/health",
            "openapi": "/.well-known/openapi.json",
            "mcp": "/.well-known/mcp.json"
        }
    },
    "key": ["data:key/es256;base64,MFkw..."],
    "sig": ["MEQCIH7C..."]
}
```

The key pair (P-256) is generated on first run and stored in `local/claim.pub` and `local/claim.key`. The server's TLS fingerprint is embedded in the claim's `stm.fingerprint` field, binding the two identity systems together.

### Publishing claims — files in folders

The same convention as handlers: drop a file in `claims/`, it becomes a signed OpenClaim. Three sources, checked in priority order:

**1. PHP (dynamic, auto-signed)** — `claims/{domain}/{name}.php`

```php
<?php // claims/example.com/session.php
return array(
    'ocp' => 1,
    'iss' => 'example.com/server',
    'sub' => $params['userId'] ?? 'anonymous',
    'stm' => array('role' => 'viewer'),
    'exp' => time() + 3600,
);
```

Evaluated per-request. The server adds `key[]` and `sig[]` automatically. Served at `/.well-known/openclaiming/example.com/session.json`.

**2. JSON template (static, auto-signed, cached)** — `claims/{domain}/{name}.json`

```json
{
    "ocp": 1,
    "iss": "example.com/server",
    "sub": "alice",
    "stm": {"role": "editor", "scope": "blog"}
}
```

Write the claim body without crypto fields. The server signs it with its P-256 key and caches the result in `files/Q/cached/claims/`. When you edit the template, the cache invalidates automatically (keyed by mtime).

**3. Pre-signed (as-is)** — `web/.well-known/openclaiming/{domain}/{name}.json`

For claims signed by someone else — a user's wallet, a partner server, a smart contract. The server serves them unchanged.

### Signature format

All server-signed claims use OCP wire format:

- **Canonicalization:** RFC 8785 / JCS (sorted keys, `sig` stripped)
- **Algorithm:** ES256 (P-256 + SHA-256)
- **Signature encoding:** raw r||s (64 bytes, base64)
- **Key URI:** `data:key/es256;base64,{SPKI-DER}`

This is byte-compatible with the Qbix Platform's `Q_Crypto_OpenClaim::sign()` and the JavaScript reference implementation's `Q.Crypto.OpenClaim.sign()`. Claims signed by the server verify with either library, and vice versa.

### Multisig

If a template already has `key[]` and `sig[]` (partially signed by another party), the server appends its own key and signature. Keys are sorted lexicographically per OCP convention. This enables co-signed claims where multiple authorities attest to the same statement.

---

## 🌐 HTTP/2 Support

The built-in event loop uses `stream_select` — zero dependencies, works everywhere. But if you install [amphp](https://amphp.org/), the server upgrades to a full HTTP/2 server with no code changes:

```bash
composer require amphp/http-server amphp/socket php qbixserver.php --port=8443
```

The server detects amphp automatically and switches to its event loop and HTTP driver. You get:

| | HTTP/1.1 (built-in) | HTTP/2 (amphp) |
|---|---|---|
| Connections per page load | ~6 parallel | 1 multiplexed |
| Header overhead | Full headers per request | HPACK compressed |
| Event loop | `stream_select` (portable) | `epoll`/`kqueue` via Revolt |
| TLS | `stream_socket_enable_crypto` | amphp native TLS |
| Server push | No | Yes (push static assets before browser asks) |

### How it works

The server has a clean two-layer architecture. `Q_WebServer::route()` handles all request logic (static files, PHP dispatch, cache, access control) and returns a `[status, headers, body]` array. The transport layer is pluggable:

```
Built-in:   stream_select → accept → fread → route() → fwrite amphp:      Revolt loop → amphp HTTP server → route() → amphp response
```

All the server's features — response cache, X-Accel-Redirect, component cache invalidation, keep-alive, compression — work identically on both transports. The `Q_Evented` facade abstracts the event loop, so timers, signals, and socket watchers work the same way whether you're on `stream_select` or Revolt.

### When to use which

**Built-in (default):** Zero dependencies. Works on any PHP 8.1+ installation. Good for development, small-to-medium sites, and environments where you can't install Composer packages.

**amphp:** Better performance under high concurrency thanks to `epoll`/`kqueue`. HTTP/2 multiplexing reduces connection overhead for asset-heavy pages. Required if you need server push or HTTP/2-only clients.

**Either way:** You can always put Cloudflare, CloudFront, or nginx in front as a reverse proxy. The CDN terminates HTTP/2 (and HTTP/3) for you, forwarding HTTP/1.1 to the backend. In that configuration, the built-in transport is all you need — the CDN handles the protocol upgrade.

---

---
[← Back to README](../README.md)
