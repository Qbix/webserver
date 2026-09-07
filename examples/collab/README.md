# 📋 Collab Board

Real-time collaborative kanban board with server-side PHP room handlers and live cursor tracking.

```bash
php qbixserver.php --root=examples/collab/web
```

Open http://localhost:4000 — enter a name, share the URL to invite others.

## What it demonstrates

- **Room handlers with shared state** — the board state (cards, columns) lives in `$room->state` in the PHP room process
- **`Q::event()`** — card operations dispatch to `handlers/board/card_add.php`, `card_move.php`, etc.
- **Server-authoritative state** — the room handler stores cards in `$room->state['cards']`, new joiners get the full state via `sync` event
- **Remote cursors** — mouse positions broadcast via `handlers/board/cursor.php`
- **Conflict-free editing** — the server holds the canonical card list; edits broadcast to all members
- **Cluster-aware** — handles `_redirect` for leader-based room routing across servers

## Files

| File | What it does |
|---|---|
| `web/index.html` | Board UI — four columns, card CRUD, cursor tracking, share button |
| `web/favicon.svg` | Blue document icon with animated cursor |
| `config/server.json` | Room config — defines `board` room with 1ms tick interval for cursor updates |
| `handlers/board/init.php` | Room startup — initializes cards list, member list, card ID counter |
| `handlers/board/join.php` | User joins — adds to members, sends full board state (`sync` event) to joiner |
| `handlers/board/leave.php` | User leaves — removes from members, broadcasts departure |
| `handlers/board/card_add.php` | Add card — stores in `$room->state['cards']`, broadcasts to others |
| `handlers/board/card_move.php` | Move card between columns — updates state, broadcasts |
| `handlers/board/card_edit.php` | Edit card text — updates state, broadcasts |
| `handlers/board/card_delete.php` | Delete card — removes from state, broadcasts |
| `handlers/board/card_editing.php` | "Who's editing" indicator — ephemeral broadcast |
| `handlers/board/cursor.php` | Remote cursor position — ephemeral broadcast to others |

## How it works

Each board URL hash (`#abc123`) maps to a room. When the first user joins, the server forks a room process that runs `handlers/board/init.php` → sets up empty `$room->state`. When more users join, `handlers/board/join.php` sends them the full current state via `Q_Socket::emit()`.

Card operations are server-authoritative: `card_add` stores the card in `$room->state['cards'][$id]` before broadcasting. If two users add cards simultaneously, both end up in the state — no conflicts because each has a unique ID.

Cursor tracking uses `handlers/board/cursor.php` — 4 lines that broadcast normalized coordinates to other members. The client sends positions as 0–1 ratios so it works across screen sizes.
