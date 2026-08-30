# 📋 Collab Board

Real-time collaborative kanban board. Share the URL to invite others.

```bash
php qbixserver.php --root=examples/collab/web
```

Open http://localhost:4000 — enter a name, start creating cards.

## What it demonstrates

- **WebSocket rooms** — all collaborators share one room process with in-memory state
- **Remote cursors** — see where other people's mouse pointers are in real-time
- **Distributed collaboration** — share the URL (includes a hash ID for the board)
- **"Who's editing"** — colored labels appear on cards when someone else is editing them
- **Conflict-free updates** — card operations (add, move, edit, delete) broadcast to all members
- **Cluster-aware** — handles `_redirect` for leader-based room routing across servers

## Files

| File | What it does |
|---|---|
| `web/index.html` | The entire app — board UI, cursor tracking, WebSocket logic. No dependencies. |
| `web/favicon.svg` | Blue document icon with an animated cursor and checkmark badge |
| `config/server.json` | Room configuration — defines the `board` room with tick interval for cursor updates |

## How it works

Each browser tab connects via Socket.IO and joins a room named after the URL hash (`#abc123`). If no hash exists, one is generated on join. The board state (cards, columns) lives in a JavaScript object. When anyone adds, moves, edits, or deletes a card, the change is broadcast to all room members.

Mouse position is tracked via `mousemove` and sent as normalized coordinates (0–1 range) so it works across different screen sizes. Other users' cursors render as colored arrows with name labels. The coordinates update via CSS transitions for smooth movement.

Card editing triggers a `card_editing` event so other users see a colored "who's editing" badge on the card for 3 seconds.
