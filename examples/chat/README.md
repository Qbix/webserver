# 💬 Chat

Real-time multi-room chat using WebSocket with Socket.IO protocol.

```bash
php qbixserver.php --root=examples/chat/web
```

Open http://localhost:4000 — enter a name, join the conversation.

## What it demonstrates

- **WebSocket rooms** — three channels (#general, #random, #dev), switch between them
- **Typing indicators** — animated dots show who's typing, throttled to one event per 2 seconds
- **Read receipts** — ✓ sent when your message leaves, ✓✓ read when someone else sees it
- **Emoji reactions** — click to react, reactions aggregate with counts
- **Presence** — sidebar shows who's online with colored status dots
- **Member avatars** — channel header shows colored initials of current members
- **Cluster-aware** — handles `_redirect` events for leader-based room routing

## Files

| File | What it does |
|---|---|
| `web/index.html` | The entire app — HTML, CSS, and JavaScript in one file. No build step, no dependencies. |
| `web/favicon.svg` | Purple speech-bubble icon |
| `config/server.json` | Room configuration — defines the `chat` room pattern with max 100 members |

## How it works

The client connects via Socket.IO WebSocket protocol (`/socket.io/?EIO=4&transport=websocket`). All state is client-side — the server broadcasts events between connected clients using the room process. Message history, typing state, and reactions live in JavaScript objects. Nothing persists to disk, which is the point: rooms are lightweight, ephemeral, and self-cleaning.

When a user types, the client emits a `typing` event (throttled). After 3 seconds of no typing, it emits `stop_typing`. Other clients animate the dot indicator. This is pure ephemeral state — lost on refresh, never persisted.

Read receipts work by having each client emit a `read` event with the message ID when a new message appears. The sender's client updates ✓ to ✓✓.
