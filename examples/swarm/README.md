# 🐝 Swarm

Self-healing distributed task list + chat with full-stack microservice isolation.

## Quick start

```bash
# Single server (all features, simple setup)
php qbixserver.php --root=examples/swarm/web --port=4001

# Or: authority + sandbox (microservice isolation)
php qbixserver.php --root=examples/swarm/web --config=examples/swarm/config/authority.json
php qbixserver.php --root=examples/swarm/web --config=examples/swarm/config/sandbox.json

# Or: multi-node cluster (peer replication)
PEERS=http://localhost:4001 php qbixserver.php --root=examples/swarm/web --port=4002
```

## What it demonstrates

### Distributed replication
- **`Q::event()` with handler files** — task and message mutations dispatch to `handlers/swarm/task_add.php`, `message.php`, etc.
- **`Q_WebServer_Cluster` replication** — events in the `Q.cluster.replicate` list automatically broadcast to all live peers after the local handler runs
- **Self-healing sync** — new servers pull full state from a peer on startup via `/api.php?action=sync`
- **Peer discovery** — servers register with each other, broadcast new peers
- **Loop prevention** — `_forwarded` flag prevents infinite re-replication

### Microservice isolation via `handleUsingRemote`
- **Same app code, different roles** — authority and sandbox run identical PHP files. The only difference is config.
- **Chokepoint pattern** — the sandbox forwards payments, email, and OAuth to the authority. The sandbox never loads the handler, never reads the secret.
- **HMAC-signed forwarding** — `handleUsingRemote` signs every request with a shared key. The authority verifies before processing.
- **Three isolated services** — payment (Stripe key), email (SMTP creds), OAuth (client_secret). Each secret exists only on the authority.

## Why this is meaningfully more secure

The standard approach is a monolith: one server, all secrets in environment variables or a config file. Every PHP process has every secret in memory. A vulnerability anywhere — an image upload bug, an SSRF, a deserialization flaw, a dependency with a backdoor — can read `$_ENV['STRIPE_SECRET']` or `/app/config/production.json` and exfiltrate everything.

The chokepoint pattern eliminates this by never putting the secrets on the public-facing server:

**What an attacker gets if they compromise the sandbox:** the HMAC signing key and the sandbox's fingerprint. They can make signed requests to the authority — but so can any legitimate sandbox request. The authority validates every event, checks the event name against a whitelist, and only runs the specific handler. The attacker cannot make arbitrary database queries, cannot send arbitrary emails, cannot charge arbitrary amounts. They can only call the same narrow API that the sandbox was already allowed to call.

**What they don't get:** payment API keys, SMTP passwords, OAuth client secrets, database credentials. These are never in the sandbox's memory, never in its config files, never in its environment variables. A Spectre/Meltdown side-channel attack on the sandbox process reads memory that contains none of these values. A file-read vulnerability finds no secrets on disk.

**What happens if the signing key leaks:** the attacker can forge requests to the authority. But the authority's handlers are the same narrow, validated event handlers — `swarm/payment` checks the amount, `swarm/email` sends to the specified address. The blast radius is the same as a legitimate user making requests. You rotate the signing key and redeploy. You don't need to rotate your Stripe key, your SMTP password, or your OAuth secrets — they were never exposed.

**The honest caveat:** if both servers run on the same machine, a kernel exploit (container escape, hypervisor bug) gives you both. The full security benefit comes when the authority runs on a separate machine, VM, or container with no inbound traffic except the signed `/Q/event` endpoint. But even on the same machine, process-level isolation prevents the most common attack vectors: memory reads, file reads, environment variable leaks, and dependency supply-chain attacks.

**What this costs you:** one extra process and one config file. The app code is identical. Switching from monolith to microservice is a config change, not a rewrite.

## Files

| File | What it does |
|---|---|
| `web/index.html` | Split-panel UI — tasks on the left, chat on the right |
| `web/api.php` | All endpoints — task CRUD, chat, payment, email, OAuth, sync, peers |
| `web/sync.php` | Startup sync — pulls state from a peer when `PEERS` env is set |
| `web/favicon.svg` | Three connected nodes |
| `config/server.json` | Default single-server config |
| `config/authority.json` | Authority config — has all secrets (payment, SMTP, OAuth, DB) |
| `config/sandbox.json` | Sandbox config — has `handlersUsingRemote`, no secrets |
| `handlers/swarm/task_add.php` | Inserts task into local SQLite |
| `handlers/swarm/task_update.php` | Updates task done status |
| `handlers/swarm/task_delete.php` | Deletes task by UUID |
| `handlers/swarm/message.php` | Inserts chat message into local SQLite |
| `handlers/swarm/payment.php` | Reads `paymentApiKey` from config, simulates charge |
| `handlers/swarm/email.php` | Reads SMTP credentials from config, simulates send |
| `handlers/swarm/oauth.php` | Reads OAuth `clientSecret` from config, simulates token exchange |

## How it works

### Write path with `Q::event()`

When the sandbox receives `POST /api.php?action=payment`:

1. `api.php` calls `Q::event('swarm/payment', ['amount' => 49.99])`
2. `Q::event()` checks `Q_Config::get('Q', 'handlersUsingRemote', 'swarm/payment')`
3. Config says `{"baseUrl": "http://localhost:4001"}` — forward, don't handle locally
4. `Q::handleUsingRemote()` signs the payload with HMAC, POSTs to `http://localhost:4001/Q/event`
5. Authority receives at `/Q/event`, verifies HMAC, checks `_msgId` for dedup
6. Authority calls `Q::event('swarm/payment', $params)` — no `handlersUsingRemote` for this event in its config
7. Local handler `handlers/swarm/payment.php` runs, reads `paymentApiKey`, processes the charge
8. Result returns to sandbox, sandbox returns to client

The sandbox never loaded `payment.php`. The function `swarm_payment()` never executed on the sandbox. `Q_Config::get('Q', 'internal', 'paymentApiKey')` was never called on the sandbox — the key doesn't exist in its config.

### Switching environments

```bash
# Development — everything on one server, no isolation
php qbixserver.php --root=examples/swarm/web --port=4001

# Staging — sandbox forwards to authority, both on localhost
php qbixserver.php --root=examples/swarm/web --config=examples/swarm/config/authority.json
php qbixserver.php --root=examples/swarm/web --config=examples/swarm/config/sandbox.json

# Production — authority on a private network, sandbox public-facing
# authority.json: port 4001, bind to 10.0.0.1 (private)
# sandbox.json: port 443, handlersUsingRemote baseUrl: http://10.0.0.1:4001
```

Same app code in all three. The config file is the only thing that changes.
