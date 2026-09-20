## 🚀 Deploy

Push your app to a remote server with one command:

```bash
./qbixserver.php --deploy=production
```

Configure targets in `config/deploy.json`:

```json
{
    "targets": {
        "production": {
            "host": "myserver.com",
            "user": "deploy",
            "path": "/var/www/myapp",
            "key": "~/.ssh/deploy_key",
            "dirs": ["web", "handlers", "classes", "config"]
        }
    }
}
```

The command rsyncs each directory to the remote server. If the remote runs Qbix Server, it can be configured to hot-reload on deploy.

Servers can also be managed from the Panel's **Servers** tab — add, deploy, and remove remote servers through the browser.

---

## 🔗 Federation

Qbix servers can forward events to each other. Any `Q::event()` call can be handled locally or routed to a remote server — same dispatch path, same handler signature, transparent to the app code.

### How it works

**1. Server identity.** On first run, each server generates a self-signed certificate and stores it in `local/server.crt`. The SHA-256 fingerprint is the server's identity — like SSH `known_hosts`, no certificate authority needed.

**2. Discovery.** Every server exposes `/.well-known/qbix.json`:

```json
{
    "server": "Qbix Server",
    "version": "1.0.0",
    "fingerprint": "11cf953679b80d04...",
    "endpoints": {"event": "/Q/event", "health": "/Q/health"},
    "plugins": [{"name": "Users", "version": "1.0"}]
}
```

**3. Event forwarding.** Configure which events route to which server:

```json
{
    "Q": {
        "handlersUsingRemote": {
            "Users/login": {"baseUrl": "https://auth.example.com"},
            "Streams/stream": {"baseUrl": "https://streams.example.com"}
        }
    }
}
```

When Server A receives a `Users/login` event, it forwards it to `auth.example.com/Q/event` via HMAC-signed POST. The receiving server verifies the signature, dispatches the event locally, and returns the result. Loop prevention is built in — a forwarded event is never re-forwarded.

**4. Signing.** All inter-server requests are signed using `Q_Utils::sign()`, which is compatible with the Qbix Platform's signing. The signature uses HMAC-SHA1 over recursively key-sorted, URL-encoded data — the same format the Platform uses. Servers upgrading to the full Platform keep working without changes.

### Trust levels

Servers authenticate each other at three levels:

- **Pinned peer** — fingerprint stored in config. Events accepted, signature
  verified. For known partners.
- **Owned server** — shared `Q.internal.secret`. Full trust, can forward
  user sessions. For your own infrastructure.
- **Public** — no fingerprint, just HTTPS. Read-only access via
  `/.well-known/qbix.json`. For open APIs.

### Logging

Access and error logs with buffered writes, daily rotation, gzip archiving, and retention management. Enable by adding a `log` section to config:

```json
{
    "Q": {
        "webserver": {
            "log": {
                "dir": "logs",
                "access": true,
                "error": true,
                "bufferSize": 65536,
                "flushInterval": 1,
                "maxSize": 52428800,
                "archiveAfterDays": 2,
                "deleteAfterDays": 30
            }
        }
    }
}
```

| Setting | Default | Description |
|---|---|---|
| `dir` | `logs/` (relative to app root) | Log directory. Absolute paths work too. |
| `access` | `true` | Write access.log in combined format + response time. |
| `error` | `true` | Write error.log with timestamps. |
| `bufferSize` | `65536` (64 KB) | Buffer log lines in memory, flush when full. 0 = write every line immediately. |
| `flushInterval` | `1` | Seconds between timer flushes. Buffer contents hit disk at most this late. |
| `maxSize` | `52428800` (50 MB) | Rotate mid-day if a log file exceeds this. |
| `archiveAfterDays` | `2` | Compress rotated logs to .gz after this many days. |
| `deleteAfterDays` | `30` | Delete archived logs older than this. |

**Buffered writes** accumulate log lines in memory and flush them in a single `write()` syscall — either when the buffer fills or on the timer. This cuts the per-request overhead roughly in half vs writing every line:

| Mode | Throughput | Overhead vs no logging |
|---|---|---|
| No logging | 9,276 req/s | — |
| Buffered (64KB, 1s) | 8,671 req/s | 6.5% |
| Unbuffered | 8,186 req/s | 11.7% |

Error lines always flush immediately (they're rare and you want them on disk before a crash). Set `access` to `false` to skip access logging entirely.

**Rotation:** Logs rotate daily at midnight. The current day's log is always `access.log` and `error.log`. Yesterday's becomes `access.2026-08-11.log`. After 2 days that file is gzipped to `access.2026-08-11.log.gz`. After 30 days it's deleted. If a log exceeds 50 MB mid-day, it rotates early with a timestamp suffix.

Access log format (nginx-compatible combined + response time):

```
192.168.1.1 - - [11/Aug/2026:14:30:00 +0000] "GET /api/users HTTP/1.1" 200 1234 "-" "Mozilla/5.0" 3.2ms
```

Errors also go to stderr, so `php qbixserver.php 2>err.log` works without config.

### Configuration

```json
{
    "Q": {
        "internal": {
            "secret": "your-shared-secret-here"
        },
        "federation": {
            "advertise": true,
            "advertiseApps": false,
            "advertisePlugins": true,
            "requireKnownPeers": false,
            "peers": [
                {
                    "name": "auth-server",
                    "url": "https://auth.example.com",
                    "fingerprint": "11cf953679b80d04..."
                }
            ]
        }
    }
}
```

### Full-stack microservices

Each Qbix server is a complete, independent app server. Federation lets you split your app across multiple servers without changing your code:

```
Server A (auth.example.com)     Server B (app.example.com) ├── Users plugin                ├── App handlers ├── handlers/Users/*            ├── handlers/MyApp/* └── handles Users/ events       └── forwards Users/ → Server A
```

Server B's handlers call `Q::event('Users/login', $params)` as if Users were installed locally. The server transparently forwards it to Server A, gets the result, and returns it. The handler never knows the difference.

### Loop prevention

Every forwarded event carries a unique `_msgId`. Each server tracks seen IDs in memory (1-hour TTL). If a message ripples through A→B→C→A, server A recognizes the ID and drops it. This is per-message, not per-peer — works for any topology.

### Signing

Inter-server requests are signed two ways, both compatible with the Qbix Platform:

- **Body signature** — `Q_Utils::sign()` adds a `Q.sig` field using
  HMAC-SHA1 over recursively key-sorted data. Same format the Platform uses.
- **Header signature** — `X-Q-HMAC` header over the raw JSON body. Same
  as the Platform's curl-based `handleUsingRemote`.

The receiving server accepts either. A Platform server and a standalone Qbix Server can forward events to each other without configuration changes.

---

---
[← Back to README](../README.md)

