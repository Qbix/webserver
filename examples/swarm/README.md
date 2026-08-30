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
- **Self-healing sync** — new servers pull full state from a peer on startup
- **Peer discovery** — servers register with each other, broadcast new peers to existing ones
- **Loop prevention** — `X-Forwarded-From` header prevents infinite forwarding cycles
- **Per-node SQLite** — each server has its own database, no shared storage required
- **No Redis, no message queue** — just HTTP calls between PHP servers

## Files

| File | What it does |
|---|---|
| `web/index.html` | Split-panel UI — tasks on the left, chat on the right. Polls every 2 seconds for cross-server updates. |
| `web/api.php` | All endpoints — task CRUD, chat messages, sync, peer management. One file, ~180 lines. |
| `web/sync.php` | Startup script — when `PEERS` env is set, pulls tasks + messages from a peer and registers with them. |
| `web/favicon.svg` | Three connected nodes forming a triangle |

## How it works

### Write path

When you add a task on Server A:

1. `api.php` saves it to A's local SQLite (INSERT OR IGNORE with a UUID)
2. `api.php` loops through known peers and POSTs the same data to each
3. Each POST includes `X-Forwarded-From: http://localhost:4001`
4. When Server B receives the forwarded POST, it saves to its SQLite but does NOT re-forward (it sees the `X-Forwarded-From` header)

Chat messages use the same path. The `server` field on each message records which node it originated from, so the UI can show `:4001` or `:4002` next to each message.

### Startup sync

When Server C starts with `PEERS=http://localhost:4001`:

1. `sync.php` calls `GET http://localhost:4001/api.php?action=sync`
2. Server A returns all tasks and messages as JSON
3. Server C inserts them with `INSERT OR REPLACE` (UUIDs make this idempotent)
4. Server C calls `POST http://localhost:4001/api.php?action=join` to register itself
5. Server A broadcasts C's URL to Server B via the same join endpoint

After sync, C has the full dataset and is a known peer to all other servers.

### Failover demo

1. Start :4001, :4002, :4003 — all synced
2. Kill :4002 (Ctrl+C)
3. Add tasks and chat messages on :4001 and :4003
4. Restart :4002 with `PEERS=http://localhost:4001`
5. :4002 syncs everything it missed, back to full consistency

### Why no WebSocket for cross-server sync?

Tasks and messages are durable — they persist in SQLite and survive reboots. HTTP forwarding is the right tool: fire-and-forget POST, 5-20ms latency, simple to debug (`curl` to test), no persistent connections to maintain between servers.

Ephemeral state (cursors, typing indicators, presence) stays local to each server's WebSocket rooms. On failover, clients reconnect and re-send their current state. The 2 seconds of lost cursor data is invisible to users.
