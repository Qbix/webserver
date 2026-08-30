# 🐝 Swarm

Self-healing distributed task list + chat. Multiple servers, each with its own SQLite, staying in sync via HTTP forwarding.

```bash
# Terminal 1 — first node
php qbixserver.php --root=examples/swarm/web --port=4001

# Terminal 2 — second node, syncs from first on startup
PEERS=http://localhost:4001 php qbixserver.php --root=examples/swarm/web --port=4002

# Terminal 3 — third node
PEERS=http://localhost:4001 php qbixserver.php --root=examples/swarm/web --port=4003
```

Open all three in browser tabs. Add tasks and chat — everything syncs.

## What it demonstrates

- **HTTP event forwarding** — every write (task or message) is forwarded to all known peers
- **Self-healing sync** — new servers pull full state from a peer on startup via `/api.php?action=sync`
- **Peer discovery** — servers register with each other via `/api.php?action=join`, broadcast new peers to existing ones
- **Loop prevention** — `X-Forwarded-From` header prevents infinite forwarding cycles (same pattern as `handleUsingRemote`'s `_msgId`)
- **Per-node SQLite** — each server has its own database, no shared storage
- **`Q::event()` compatible** — the forwarding uses the same pattern as `Q::event()` + `handleUsingRemote`: POST JSON to peer, check for forwarding header, skip re-forwarding. The only difference is this example calls `file_get_contents` directly instead of going through `Q::event()`, so it works without any config file. In a production deployment, you'd configure `handlersUsingRemote` in `config/server.json` and let `Q::event()` handle the forwarding automatically.

## Files

| File | What it does |
|---|---|
| `web/index.html` | Split-panel UI — tasks on the left, chat on the right. Polls every 2 seconds for cross-server updates. |
| `web/api.php` | All endpoints — task CRUD, chat messages, sync, peer management. Handles forwarding with `X-Forwarded-From` loop prevention. |
| `web/sync.php` | Startup sync — when `PEERS` env is set, pulls tasks + messages from a peer and registers with them. |
| `web/favicon.svg` | Three connected nodes forming a triangle |

## How it works

### Write path

When you add a task on Server A:

1. `api.php` saves it to A's local SQLite with a UUID (`INSERT OR IGNORE`)
2. `api.php` loops through known peers and POSTs the same data to each
3. Each POST includes `X-Forwarded-From: http://localhost:4001`
4. When Server B receives the forwarded POST, it saves to its own SQLite but does NOT re-forward (it sees the `X-Forwarded-From` header)

Chat messages use the same path. The `server` field records which node the message originated from.

### How this maps to `handleUsingRemote`

The raw `file_get_contents` forwarding in this example is the manual version of what `handleUsingRemote` does automatically. In production:

```json
{
  "Q": {
    "handlersUsingRemote": {
      "swarm/task_add": {
        "baseUrl": "http://server-b:4002",
        "socket": "/run/qbix/server-b.sock"
      }
    }
  }
}
```

Then `Q::event('swarm/task_add', $params)` automatically POSTs to server-b with HMAC signing, `_msgId` deduplication, and UDS socket support. The example does it manually to avoid requiring config files — the demo should work with just `PEERS=` env var and nothing else.

### Startup sync

When Server C starts with `PEERS=http://localhost:4001`:

1. `sync.php` calls `GET /api.php?action=sync` on the peer
2. Peer returns all tasks and messages as JSON
3. Server C inserts them with `INSERT OR REPLACE` (UUIDs make this idempotent)
4. Server C registers itself via `POST /api.php?action=join`
5. The peer broadcasts C's URL to all other known peers

### Failover

1. Start :4001, :4002, :4003 — all synced
2. Kill :4002
3. Add tasks and chat on :4001 and :4003 — they forward to each other, skip dead :4002
4. Restart :4002 with `PEERS=http://localhost:4001` — syncs everything it missed
