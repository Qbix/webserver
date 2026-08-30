# Examples

Each example is a self-contained app. Run any of them:

```bash
php qbixserver.php --root=examples/<name>/web
```

Then open http://localhost:4000 in your browser.

## 💬 Chat

Full-featured chat with multiple rooms (#general, #random, #dev), typing indicators with animated dots, read receipts (✓ sent / ✓✓ read), emoji reactions on messages, user presence sidebar with online/idle status, member avatar dots in the channel header, auto-resizing textarea input. Uses Socket.IO protocol.

```bash
php qbixserver.php --root=examples/chat/web
```

**Demonstrates:** WebSocket, Socket.IO v5, rooms, ephemeral typing state, read receipts, presence tracking, emoji reactions

## 📋 Collab Board

Real-time collaborative kanban board. Share the URL to invite others. Four columns (Todo / Doing / Review / Done), card creation with random tags, inline card editing, move arrows between columns, live remote cursor tracking (see where others are pointing), "who's editing" indicator on cards, share-link button with clipboard toast.

```bash
php qbixserver.php --root=examples/collab/web
```

**Demonstrates:** WebSocket rooms, distributed collaboration, remote cursors, conflict-free editing, URL sharing for ad-hoc teams

## ⚡ Stream

Server-Sent Events with four streaming modes: AI token simulation (word-by-word with variable delays), server log stream (colored status codes), structured JSON sensor data feed, and a simple counter. Includes a live metrics bar (events, bytes, duration, rate), connection status indicator with colored dot, and clear button.

```bash
php qbixserver.php --root=examples/stream/web
```

**Demonstrates:** SSE, `Content-Type: text/event-stream`, chunked transfer, `ob_flush() + flush()`, multiple streaming patterns

## ✅ Todo

CRUD todo list backed by SQLite. Add, complete, and delete tasks. Clean responsive light theme with animated transitions. Falls back gracefully if SQLite is unavailable.

```bash
php qbixserver.php --root=examples/todo/web
```

**Demonstrates:** SQLite, REST API, JSON request/response, `Q_Response::header()`, `Q_Response::code()`

## 🔢 Counter

Live page view counter with SQLite persistence and real-time updates via WebSocket heartbeat. Every visitor sees the count update instantly when someone new visits. Shows connected viewer dots, requests/sec, and uptime pulled from the server's dashboard heartbeat. Gradient background with floating particle animation.

```bash
php qbixserver.php --root=examples/counter/web
```

**Demonstrates:** WebSocket heartbeat, SQLite persistence, real-time multi-viewer sync, server stats via `/Q/ws`

## 🐝 Swarm

Self-healing distributed task list + chat. Run multiple servers, each with its own SQLite. Tasks and messages sync across all nodes via HTTP forwarding. Kill a node, add data elsewhere, restart it — catches up automatically from any peer.

```bash
# Terminal 1 — first node
php qbixserver.php --root=examples/swarm/web --port=4001

# Terminal 2 — second node, syncs from first
PEERS=http://localhost:4001 php qbixserver.php --root=examples/swarm/web --port=4002

# Terminal 3 — third node
PEERS=http://localhost:4001 php qbixserver.php --root=examples/swarm/web --port=4003
```

Open all three in browser tabs. Add a task or send a message in any one — appears in all within 2 seconds. Kill :4002, keep using :4001 and :4003, restart :4002 — it pulls all missed tasks and messages from a peer on startup.

The left panel is a shared task list (CRUD replication). The right panel is a distributed chat showing which server each message came from. Both persist to local SQLite, both replicate to peers with `X-Forwarded-From` loop prevention.

**Demonstrates:** HTTP event forwarding, peer discovery, self-healing sync, SQLite per-node, `X-Forwarded-From` loop prevention, distributed CRUD + chat without Redis or message queues
