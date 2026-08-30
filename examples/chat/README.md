# 💬 Chat

Real-time multi-room chat using WebSocket with Socket.IO protocol and server-side PHP room handlers.

```bash
php qbixserver.php --root=examples/chat/web
```

Open http://localhost:4000 — enter a name, join the conversation.

## What it demonstrates

- **Room handlers in PHP** — each event (join, leave, message, typing) has a PHP handler file that runs in the room process
- **`Q::event()`** — the server calls `Q::event('chat/message', $params)` which loads `handlers/chat/message.php`
- **`Q_Socket::broadcast()`** — server-side broadcasting to all room members
- **`Q_Socket::broadcastExcept()`** — broadcast to everyone except the sender
- **`Q_Socket::emit()`** — send to a specific socket (e.g. presence list to the joiner only)
- **`Q_Room` state** — shared in-memory state (`$room->state['members']`) persists across events within the room process
- **Ephemeral state** — typing indicators and cursor data are broadcast but never persisted
- **Cluster-aware** — handles `_redirect` events for leader-based room routing

## Files

| File | What it does |
|---|---|
| `web/index.html` | Chat UI — rooms, typing indicators, read receipts, emoji reactions, presence sidebar |
| `web/favicon.svg` | Purple speech-bubble icon |
| `config/server.json` | Room config — defines `chat` room pattern with max 100 members |
| `handlers/chat/init.php` | Room startup — initializes `$room->state` with members list and message counter |
| `handlers/chat/join.php` | User joins — adds to member list, broadcasts arrival, sends presence to joiner |
| `handlers/chat/leave.php` | User leaves — removes from member list, broadcasts departure |
| `handlers/chat/message.php` | Chat message — broadcasts to all members except sender |
| `handlers/chat/typing.php` | Typing indicator — broadcasts ephemeral event to others |
| `handlers/chat/stop_typing.php` | Typing stopped — clears the indicator |
| `handlers/chat/reaction.php` | Emoji reaction — broadcasts to others |
| `handlers/chat/read.php` | Read receipt — notifies the message author |

## How it works

When a WebSocket client emits `message`, the server's room process calls `Q::event('chat/message', $params)`. This loads `handlers/chat/message.php` and calls `chat_message($params)`. The handler uses `Q_Socket::broadcastExcept()` to send the message to all room members except the sender.

The room process is a forked PHP child that runs a message loop. It holds `$room->state` in memory — member names, message count. This state lives as long as the room has members. When the last member leaves, the process exits and the state is gone. No database, no cleanup.

Typing indicators follow the same path: client emits `typing` → server calls `Q::event('chat/typing')` → handler broadcasts to others. The handler is 6 lines of PHP. The entire chat backend is 8 handler files totaling ~80 lines.
