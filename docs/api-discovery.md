## 🔍 API Discovery

The server auto-generates three discovery endpoints from its actual handlers and configuration. No manual documentation needed — add a handler file, the specs update automatically.

### `/.well-known/qbix.json` — Server manifest

Qbix-native discovery. Returns the server's identity, fingerprint, installed plugins, and links to other specs.

```json
{
    "server": "Qbix Server",
    "version": "1.0.0",
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

### `/.well-known/openapi.json` — OpenAPI 3.1

Standard API spec compatible with Swagger UI, Postman, Redoc, Insomnia, and any OpenAPI-compatible tool.

- Paste the URL into **Postman** → Import → complete API documentation
- Point **Swagger UI** at it → interactive API explorer
- Feed it to **Redoc** → polished reference docs

The spec includes built-in endpoints (`/Q/health`, `/Q/event`) and auto-discovers handlers from the `handlers/` directory. Each handler becomes a documented path with its event name, tags, and schema.

### `/.well-known/mcp.json` — MCP (Model Context Protocol)

Lets AI tools (Claude, GPT, Cursor, etc.) discover and call this server's APIs as tools. Each handler becomes an MCP tool:

```json
{
    "tools": [
        {"name": "health", "description": "Check server health and uptime"},
        {"name": "event", "description": "Dispatch a Q::event() on this server"},
        {"name": "chat_join", "description": "Dispatch event: chat/join"},
        {"name": "chat_message", "description": "Dispatch event: chat/message"}
    ]
}
```

An AI assistant connected to your Qbix server can call your handlers directly — no glue code, no adapters.

### Compatibility matrix

| Tool | Endpoint | How |
|---|---|---|
| Postman | `/.well-known/openapi.json` | Import → Collections |
| Swagger UI | `/.well-known/openapi.json` | Point URL → interactive docs |
| Redoc | `/.well-known/openapi.json` | Static reference docs |
| Claude / AI | `/.well-known/mcp.json` | MCP server discovery |
| Other Qbix | `/.well-known/qbix.json` | Federation + fingerprint pinning |
| curl | `/Q/health` | `curl https://host/Q/health` |
| Monitoring | `/Q/health` | Uptime checks, Prometheus, etc. |

All three endpoints are configurable. Set `Q.federation.advertise: false` to disable, or selectively hide apps and plugins.

### `/.well-known/openclaiming/{hostname}/server.json` — OpenClaiming

Every Qbix server auto-generates a signed [OpenClaim](https://openclaiming.org) for its identity. The claim is signed with ES256 (P-256) and verifiable by anyone with the public key.

```json
{
    "ocp": 1,
    "iss": "myserver.com/server",
    "stm": {
        "type": "server",
        "software": "Qbix Server",
        "version": "1.0.0",
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

