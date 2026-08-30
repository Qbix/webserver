# 🔢 Counter

Live page view counter with real-time updates.

```bash
php qbixserver.php --root=examples/counter/web
```

Open http://localhost:4000 in multiple tabs — watch the count and viewer dots update.

## What it demonstrates

- **WebSocket heartbeat** — uses the server's built-in `/Q/ws` dashboard WebSocket for live stats
- **SQLite persistence** — page views survive server restarts
- **Real-time multi-viewer sync** — every connected browser sees updates instantly
- **Server stats** — requests/sec and uptime from the heartbeat payload

## Files

| File | What it does |
|---|---|
| `web/index.html` | Counter display with gradient background, animated viewer dots, stats panel |
| `web/count.php` | API endpoint — POST increments the counter, GET returns current count |
| `web/favicon.svg` | Animated spinner with `#` symbol |

## How it works

On page load, the browser POSTs to `/count.php` to increment the view count. It also opens a WebSocket connection to `/Q/ws` — the server's built-in dashboard WebSocket. The server sends `heartbeat` messages every few seconds with stats including total requests, current connections, and uptime.

The viewer dots represent connected WebSocket clients. Each dot is a `div` with a `popIn` CSS animation, staggered by 40ms. Your own dot is brighter than the others.

The count display uses `font-variant-numeric: tabular-nums` so digits don't shift when the number changes, and a brief `scale(1.08)` CSS transition on each update for a satisfying bump effect.

If SQLite isn't available, the counter falls back to an in-memory variable (resets on restart but still works for the demo).
