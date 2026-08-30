# ⚡ Stream

Server-Sent Events (SSE) with four streaming modes.

```bash
php qbixserver.php --root=examples/stream/web
```

Open http://localhost:4000 — pick a mode and click Start.

## What it demonstrates

- **SSE streaming** — `Content-Type: text/event-stream` with chunked transfer encoding
- **Multiple streaming patterns** — AI tokens, server logs, JSON data, counter
- **Connection lifecycle** — connect, stream, complete, error, reconnect
- **Live metrics** — event count, byte count, duration, events/sec

## Files

| File | What it does |
|---|---|
| `web/index.html` | Stream viewer UI with mode selector, metrics bar, connection status indicator |
| `web/stream.php` | SSE endpoint — sets `Content-Type: text/event-stream`, streams data with `ob_flush() + flush()` |
| `web/favicon.svg` | Yellow lightning bolt on dark background |

## How it works

The PHP script sets `Content-Type: text/event-stream`, which the server auto-detects as a streaming response. Instead of buffering the entire output, each `ob_flush(); flush();` call sends a chunked frame immediately to the client.

The four modes demonstrate different streaming patterns:

| Mode | Pattern | Delay |
|---|---|---|
| **AI tokens** | Word-by-word text, simulating an LLM completion | 50–200ms per word, longer for punctuation |
| **Server logs** | Formatted log lines with timestamps and status codes | 100–600ms random |
| **JSON data** | Structured sensor readings with host, value, unit | 200–800ms random |
| **Counter** | Simple incrementing number | 1 second fixed |

The client uses the browser's native `EventSource` API. The `done` custom event signals completion. On error, `EventSource` auto-reconnects.
