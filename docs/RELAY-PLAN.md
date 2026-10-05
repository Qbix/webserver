# Relay — Unified Message Transport

**Status:** Implemented
**Version:** 3.2

Qbix Server handles HTTP and WebSocket as first-class transports with the same handler pattern: drop a file in a folder, the server dispatches to it. The relay adds email and mobile (SMS) as the third and fourth transports, managed by a dedicated sibling process (`qbixrelay.php`) that handles inbound receiving, outbound delivery, threading, rate limiting, and logging — all under the `Q/relay` config namespace.

The relay works standalone (any PHP app gets managed messaging) and integrates with Qbix Platform when present (firing existing `Users/email/sendMessage` and `Users/mobile/sendMessage` events, resolving identity through `Users_User`).

---

## 1. Process architecture

### Separate process: qbixrelay.php

The relay runs as its own long-lived process, separate from the web server (`qbixserver.php`). This is a transport concern that benefits from process isolation:

- **Fault isolation** — a bug in SMTP parsing doesn't bring down HTTP serving
- **Independent lifecycle** — restart the relay without dropping web connections, and vice versa
- **Credential isolation** — provider secrets (Twilio tokens, SMTP passwords) live only in the relay process; the web server delegates via `handleUsingRemote` and never sees them
- **Resource isolation** — SMTP connections, digest timers, and rate-limit state don't compete with HTTP workers

### Launch modes

The user runs one command: `php qbixserver.php`. When relay config exists (`Q/relay` keys are set, or Platform fallback keys are present), the server automatically spawns `qbixrelay.php` as a managed child process. The server monitors the relay, restarts it on crash, and reports its status in the dashboard. No relay config = no relay process = no confusion.

For production deployments that want separate resource limits, logging, or restart policies, the relay can also run standalone via systemd. Both modes are equivalent at runtime:

1. **Auto-spawned (default)** — `qbixserver.php` forks `qbixrelay.php` on startup when relay config exists. Monitors health, restarts on crash, shows status in dashboard. The user never runs two commands.
2. **Standalone** — run `qbixrelay.php` directly (or via systemd). Production deployments where the relay has its own cgroup, log stream, and restart policy.

Both share the same `server.json` config and the same `local/relay.db` database. No IPC is needed between them — coordination happens through the database and `handleUsingRemote` event delegation.

### Worker dispatch

The relay process manages its own `stream_select` event loop for persistent SMTP and webhook connections. When a message arrives (inbound email or SMS), the relay forks a child process to run the handler — the same COW fork model the web server uses for HTTP requests. Each message gets its own isolated process at ~120KB. The child runs the handler, writes to `relay.db`, and exits.

---

## 2. Inbound email — SMTP handler dispatch

### Concept

The relay listens on port 25 (and optionally 587) and implements the SMTP protocol directly in its event loop. When a message arrives, it parses the envelope and body, computes the thread ID, and dispatches to a PHP handler via `Q::event()`.

MX records point directly at the server. No Google, no external MTA for inbound — the relay is the MX destination.

### Routing

Routing is config-driven, mapping `To:` address patterns to event names:

```json
{
    "Q": {
        "relay": {
            "email": {
                "inbound": {
                    "port": 25,
                    "routes": {
                        "support@*": "email/support",
                        "noreply+*@example.com": "email/bounce",
                        "*@tickets.example.com": "email/ticket"
                    }
                }
            }
        }
    }
}
```

Patterns support `*` as a wildcard. The first matching route wins. Unmatched mail is rejected at the SMTP level (550).

When no route matches but the local part matches a configured username (see section 6), the message is routed to the default inbox handler.

### Handler interface

Handlers are dispatched via `Q::event()` with the standard three-phase model (before hooks → main handler → after hooks):

```php
<?php
// handlers/email/support/received.php
function email_support_received(&$params, &$result) {
    // $params['from']        — envelope sender
    // $params['to']          — envelope recipients (array)
    // $params['subject']     — parsed subject line
    // $params['body']        — plain text body
    // $params['html']        — HTML body (if present)
    // $params['headers']     — all headers as associative array
    // $params['attachments'] — array of attachment metadata
    // $params['messageId']   — Message-ID header
    // $params['inReplyTo']   — In-Reply-To header (for threading)
    // $params['references']  — References header (for threading)
    // $params['threadId']    — computed thread ID (see Threading)
    // $params['raw']         — raw RFC 2822 message
    // $params['username']    — resolved username (if matched)

    $ticket = MyApp\Support::createFromEmail($params);
    $result = ['ticketId' => $ticket->id];
}
```

Because handlers are `Q::event()` calls, they support:

- **Before/after hooks** via config (`Q/handlersBeforeEvent`, `Q/handlersAfterEvent`)
- **Remote delegation** via `Q/handlersUsingRemote` — forward the event to another server over Unix socket or HTTP with HMAC signing
- **Platform integration** — when Qbix Platform is loaded, its hooks fire automatically

### Attachments

- **Metadata first** — `$params['attachments']` contains filename, MIME type, size, and content-ID for each attachment. The handler decides whether to load the content.
- **Lazy loading** — call `$params['loadAttachment']($index)` to get the raw bytes. This avoids loading large attachments into memory when the handler only needs metadata.
- **Size limits** — configurable per-message and per-attachment. Messages exceeding the limit are rejected at SMTP level (552).
- **Storage policy** — the relay doesn't store attachments. The handler decides where they go. A helper saves to a configurable temp directory with automatic cleanup after a TTL.

```json
{
    "Q": {
        "relay": {
            "email": {
                "maxMessageSize": "25MB",
                "maxAttachmentSize": "10MB",
                "tempDir": "local/relay-tmp/",
                "tempTtl": 3600
            }
        }
    }
}
```

### SMTP protocol details

The relay implements minimum viable SMTP:

- `EHLO` / `HELO`
- `MAIL FROM`
- `RCPT TO`
- `DATA`
- `QUIT`
- `STARTTLS` (using existing TLS infrastructure)
- `AUTH PLAIN` / `AUTH LOGIN` (for authenticated submission on port 587)

Extensions: `8BITMIME`, `SIZE`, `STARTTLS`, `AUTH` (on submission port). No `BDAT`, `CHUNKING`, or `DSN` in the initial version.

---

## 3. Inbound mobile — SMS webhook dispatch

### Concept

Inbound SMS arrives as an HTTP webhook POST from Twilio (or another provider). Since this is just HTTP, the web server can receive it directly — but routing it through the relay's event system keeps all messaging unified.

The web server receives the POST at a configured path (e.g. `/_mobile`) and fires `Q::event("mobile/default/received", ...)`. If `handleUsingRemote` is configured, this delegates to the relay process for credential verification and logging.

### Handler interface

```php
<?php
// handlers/mobile/default/received.php
function mobile_default_received(&$params, &$result) {
    // $params['from']         — sender phone number (e.g. +15551234567)
    // $params['to']           — your Twilio number
    // $params['body']         — message text
    // $params['numMedia']     — attachment count
    // $params['mediaUrls']    — array of media URLs
    // $params['mediaTypes']   — array of MIME types
    // $params['username']     — resolved username (if phone matched)
    // $params['threadId']     — computed thread ID
    // $params['provider']     — 'twilio' (for future multi-provider)

    // Auto-reply, create ticket, notify panel, etc.
}
```

### Twilio webhook parameters

Twilio POSTs standard parameters: `From`, `To`, `Body`, `MessageSid`, `NumMedia`, `MediaUrl0`, `MediaContentType0`, plus geographic data (`FromCity`, `FromState`, `FromCountry`). The relay normalizes these into the `$params` format above.

### Webhook verification

The relay verifies Twilio's request signature (`X-Twilio-Signature`) using the auth token to prevent spoofed webhooks. This is why credential isolation matters — the auth token lives in the relay, not in the web server.

---

## 4. Outbound email relay

### Concept

Frameworks (Laravel, WordPress, Qbix Platform, etc.) send outbound mail by connecting to an SMTP server. Instead of connecting directly to SES or Mailgun, they connect to `localhost:2525` — the relay process. The relay receives the message, logs it, optionally enriches it, and forwards it to the actual provider.

Alternatively, the framework fires `Q::event("relay/email/send", ...)` which `handleUsingRemote` delegates to the relay process over a Unix socket. The framework never sees the upstream credentials.

### Why this pattern

| Benefit | How |
|---|---|
| **Credential isolation** | Framework code never sees SMTP passwords — credentials live in relay config, not `.env` |
| **Unified logging** | Every outbound email from every app is logged in `relay.db` |
| **Conversation tracking** | The relay tracks outbound threads alongside inbound, building a unified conversation view |
| **Visibility** | Dashboard shows email volume, delivery status, and errors per app |
| **Rate limiting** | Token-bucket rate limiter + hourly circuit breaker at the relay level |
| **Provider portability** | Switch from SES to Google relay by changing one config — no app changes |

### Synchronous by default

The relay is synchronous: the framework sends to `localhost:2525`, the relay connects to the upstream provider, delivers, and returns the result. The caller blocks until delivery completes (or fails).

This works because the COW fork model makes blocking cheap. Each request runs in its own forked process at ~120KB. A 200ms SMTP relay doesn't tie up a 50MB worker. Callers who want async can use their framework's queue system.

### Digest mode

Ported from the existing Node.js relay (`platform/scripts/smtp.js`), the relay supports digest batching for high-frequency senders:

- When multiple messages arrive from the same sender to the same recipient within a configurable window, the relay batches them into a single digest email
- Exponential backoff on the batch window (30s → 60s → 120s → ...) prevents runaway digest accumulation
- Digest state lives in `relay.db` (`digest_pending` and `digest_state` tables), so it survives process restarts

### Enrichment (optional)

The relay can optionally enrich outbound messages before forwarding:

- **DKIM signing** — sign with the server's key if the `From:` domain matches a configured domain
- **Tracking headers** — add `X-Qbix-App` and `X-Qbix-Branch` headers for debugging
- **Link tracking** — rewrite URLs for click tracking (opt-in, off by default)
- **Unsubscribe headers** — add `List-Unsubscribe` and `List-Unsubscribe-Post` per RFC 8058

---

## 5. Outbound mobile relay

### Concept

The same relay pattern for SMS. The framework fires `Q::event("relay/mobile/send", ...)` or calls a relay API endpoint. The relay rate-limits, logs, and sends through Twilio (or another provider). Credentials stay in the relay.

### Configuration

```json
{
    "Q": {
        "relay": {
            "mobile": {
                "provider": "twilio",
                "accountSid": null,
                "authToken": null,
                "fromNumber": null,
                "webhookPath": "/_mobile",
                "rateLimit": {
                    "perMinute": 30,
                    "perHour": 500
                }
            }
        }
    }
}
```

When `accountSid`, `authToken`, or `fromNumber` are null, the relay falls back to Platform config (`Users/mobile/twilio/sid`, etc.) — see section 6a.

### Provider adapters

The relay is provider-agnostic for mobile. Initial support is Twilio. Future adapters:

- **Vonage (Nexmo)** — REST API, per-message pricing
- **MessageBird** — REST API, EU-focused
- **SNS** — AWS Simple Notification Service for SMS

Each adapter is a small class (~50 lines) that translates the relay's internal send call to the provider's API.

---

## 6a. Config resolution — Platform fallback

The relay checks its own config keys first (`Q/relay/...`), then falls back to Qbix Platform Users plugin config if present (`Users/...`). If neither exists, the relay logs an informative error telling the operator exactly what to set.

### Fallback map

| Relay key (`Q/relay/...`) | Platform fallback (`Users/...`) |
|---|---|
| `email/outbound/host` | `email/smtp/host` |
| `email/outbound/port` | `email/smtp/port` |
| `email/outbound/encryption` | `email/smtp/auth` |
| `email/outbound/username` | `email/smtp/username` |
| `email/outbound/password` | `email/smtp/password` |
| `mobile/accountSid` | `mobile/twilio/sid` |
| `mobile/authToken` | `mobile/twilio/token` |
| `mobile/fromNumber` | `mobile/twilio/fromNumber` |

Keys with **no fallback** (relay-only, new capabilities):

`email/inbound/*`, `mobile/webhookPath`, `mobile/provider`, `users/*`, `rateLimit/*`, `digest/*`, `ai/*`, `db`, `socket`, `dkim/*`, enrichment flags.

### Resolution logic

```php
function relayConfig($key, $default = null) {
    static $fallbacks = [
        'email/outbound/host'       => ['Users', 'email', 'smtp', 'host'],
        'email/outbound/port'       => ['Users', 'email', 'smtp', 'port'],
        'email/outbound/encryption' => ['Users', 'email', 'smtp', 'auth'],
        'email/outbound/username'   => ['Users', 'email', 'smtp', 'username'],
        'email/outbound/password'   => ['Users', 'email', 'smtp', 'password'],
        'mobile/accountSid'         => ['Users', 'mobile', 'twilio', 'sid'],
        'mobile/authToken'          => ['Users', 'mobile', 'twilio', 'token'],
        'mobile/fromNumber'         => ['Users', 'mobile', 'twilio', 'fromNumber'],
    ];

    $parts = explode('/', $key);
    $val = Q_Config::get('Q', 'relay', ...$parts, null);
    if ($val !== null) return $val;

    $path = $fallbacks[$key] ?? null;
    if ($path) {
        $val = Q_Config::get(...$path, null);
        if ($val !== null) return $val;
    }

    if ($default === null) {
        Q::log("relay: '$key' not configured — set Q/relay/$key"
            . ($path ? " or " . implode('/', $path) : ""),
            'warning');
    }
    return $default;
}
```

### Migration path

**New standalone deployment:** set `Q/relay/*` explicitly. No Platform, no fallback consulted.

**Existing Platform deployment, adding relay:** start the relay with no `Q/relay/email/outbound` or `Q/relay/mobile` config. The relay reads existing `Users/email/smtp` and `Users/mobile/twilio` settings and starts working immediately. Then optionally copy credentials into `Q/relay/` for credential isolation and point Platform's SMTP at `localhost:2525`.

---

## 6b. Username-to-address mapping

The server already has usernames (configured in `server.json`, independent of the Platform's Users plugin). The relay maps these same usernames to email addresses and phone numbers:

```json
{
    "Q": {
        "relay": {
            "users": {
                "alice": {
                    "email": "alice@example.com",
                    "mobile": "+15551234567"
                },
                "bob": {
                    "email": "bob@example.com"
                }
            }
        }
    }
}
```

This enables:

- **Inbound routing** — email to `alice@example.com` resolves to username `alice`; SMS from `+15551234567` resolves to `alice`
- **Per-user inbox** — messages are stored in `relay.db` tagged by username
- **Unified identity** — the same username works across HTTP auth, email, and SMS

When the Qbix Platform is loaded, this config map is bypassed in favor of `Users_User` records with verified emails and phone numbers. The relay detects the Platform and delegates identity resolution to it.

---

## 7. Threading and conversations

### Unified thread model

Threading works across both email and mobile, tracked in `relay.db`:

| Message concept | Relay concept |
|---|---|
| Email thread (by References/In-Reply-To) | Thread in `relay.db` |
| SMS conversation (by phone number pair) | Thread in `relay.db` |
| Recipients (To + CC for email, phone for SMS) | Thread membership |
| Individual message | Row in `thread_messages` |

**Thread ID computation (email):**

1. If `In-Reply-To` or `References` headers exist, extract the root message ID — this is the thread ID
2. Otherwise, generate a new thread ID from the Message-ID

**Thread ID computation (mobile):**

1. Hash of the sorted pair of phone numbers (sender + recipient) — all SMS between two numbers belong to the same thread

**Membership rules (email):**

- All addresses in `To:` and `CC:` of any message in the thread are members
- A sender can post to a thread only if they appear in the recipients list of a prior message in the thread
- **BCC policy** (configurable): by default, BCC'd senders cannot contribute to the thread. Set `relay.email.bccCanReply: true` to allow it

### Cross-channel conversations

A thread can span channels. If a customer's email address and phone number are both mapped to the same username, the dashboard shows one unified conversation regardless of channel:

```
Customer sends email     → thread created, stored in relay.db
Server replies via email → same thread
Customer sends SMS       → matched to same user, same thread
Server replies via SMS   → same thread
```

---

## 8. Persistence — relay.db

All relay state lives in a single SQLite database at `local/relay.db`. Shared between the relay process and the web server (for dashboard queries). The schema:

```sql
-- Thread tracking
CREATE TABLE threads (
    thread_id       TEXT PRIMARY KEY,
    root_message_id TEXT,
    channel         TEXT NOT NULL,  -- 'email' or 'mobile'
    subject         TEXT,
    created_at      INTEGER NOT NULL,
    updated_at      INTEGER NOT NULL
);

CREATE TABLE thread_members (
    thread_id   TEXT NOT NULL,
    address     TEXT NOT NULL,  -- email address or phone number
    username    TEXT,           -- resolved username (if matched)
    added_at    INTEGER NOT NULL,
    role        TEXT DEFAULT 'participant',
    PRIMARY KEY (thread_id, address)
);

CREATE TABLE thread_messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    thread_id   TEXT NOT NULL,
    message_id  TEXT,           -- Message-ID for email, MessageSid for SMS
    channel     TEXT NOT NULL,  -- 'email' or 'mobile'
    direction   TEXT NOT NULL,  -- 'inbound' or 'outbound'
    from_addr   TEXT NOT NULL,
    to_addr     TEXT,
    subject     TEXT,
    body_text   TEXT,
    body_html   TEXT,
    raw         BLOB,          -- raw RFC 2822 for email
    received_at INTEGER NOT NULL
);

-- Per-user inbox view
CREATE TABLE inbox (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    username    TEXT NOT NULL,
    thread_id   TEXT NOT NULL,
    message_id  TEXT,
    channel     TEXT NOT NULL,
    from_addr   TEXT NOT NULL,
    subject     TEXT,
    body_text   TEXT,
    received_at INTEGER NOT NULL,
    read_at     INTEGER,
    flags       TEXT DEFAULT '{}',  -- JSON: starred, archived, labels
    ai_labels   TEXT               -- JSON: AI-assigned classification
);

-- Digest batching (ported from smtp.js)
CREATE TABLE digest_pending (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient   TEXT NOT NULL,
    mail_from   TEXT NOT NULL,
    raw_message BLOB NOT NULL,
    received_at INTEGER NOT NULL
);

CREATE TABLE digest_state (
    recipient       TEXT NOT NULL,
    mail_from       TEXT NOT NULL,
    next_delay_ms   INTEGER DEFAULT 30000,
    last_received_at INTEGER,
    msg_count       INTEGER DEFAULT 0,
    PRIMARY KEY (recipient, mail_from)
);

-- Delivery log (all channels)
CREATE TABLE delivery_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    channel     TEXT NOT NULL,  -- 'email' or 'mobile'
    direction   TEXT NOT NULL,
    from_addr   TEXT NOT NULL,
    to_addr     TEXT NOT NULL,
    subject     TEXT,
    message_id  TEXT,
    bytes       INTEGER,
    status      TEXT NOT NULL,  -- 'sent', 'failed', 'queued', 'bounced'
    error       TEXT,
    provider    TEXT,           -- 'google', 'ses', 'twilio', etc.
    app_host    TEXT,           -- which app sent it
    timestamp   INTEGER NOT NULL
);

-- Rate limiting
CREATE TABLE rate_state (
    key             TEXT PRIMARY KEY,
    tokens          REAL NOT NULL,
    last_refill     INTEGER NOT NULL,
    hour_window     TEXT  -- JSON: hourly counters for circuit breaker
);
```

---

## 9. Rate limiting and circuit breaker

Ported from the battle-tested Node.js relay (`platform/scripts/smtp.js`), which was hardened after a 34,000-message incident:

### Token bucket

Each sender gets a token bucket with configurable fill rate:

```json
{
    "Q": {
        "relay": {
            "rateLimit": {
                "perMinute": 60,
                "perHour": 1000,
                "burstSize": 10
            }
        }
    }
}
```

Tokens refill at `perMinute / 60` per second. Each message consumes one token. When the bucket is empty, messages are rejected (or queued for digest).

### Hourly circuit breaker

If any single sender exceeds the hourly limit, the circuit breaker trips and all messages from that sender are rejected for the remainder of the hour. This prevents runaway loops from exhausting provider quotas.

Rate state is persisted in `relay.db` so it survives process restarts.

---

## 10. Q::event() integration

The relay uses the standard `Q::event()` dispatch for all message handling. This means every extension point the Qbix event system provides is available:

### Event names

| Event | When |
|---|---|
| `relay/email/received` | Inbound email arrived |
| `relay/mobile/received` | Inbound SMS arrived |
| `relay/email/send` | Outbound email requested |
| `relay/mobile/send` | Outbound SMS requested |
| `relay/email/sent` | Outbound email delivered |
| `relay/mobile/sent` | Outbound SMS delivered |
| `relay/email/failed` | Outbound email delivery failed |
| `relay/mobile/failed` | Outbound SMS delivery failed |

### Before/after hooks via config

```json
{
    "Q": {
        "handlersBeforeEvent": {
            "relay/email/send": ["relay/before/rateLimit"],
            "relay/mobile/send": ["relay/before/rateLimit"]
        },
        "handlersAfterEvent": {
            "relay/email/received": ["relay/after/notifyPanel"],
            "relay/mobile/received": ["relay/after/notifyPanel"]
        }
    }
}
```

### Remote delegation for credential isolation

```json
{
    "Q": {
        "handlersUsingRemote": {
            "relay/email/send": {
                "baseUrl": "http://127.0.0.1:2525",
                "socket": "/run/qbix/relay.sock",
                "timeout": 30,
                "returnType": "array"
            },
            "relay/mobile/send": {
                "socket": "/run/qbix/relay.sock",
                "timeout": 10,
                "returnType": "array"
            }
        }
    }
}
```

The web server (or any app) fires `Q::event("relay/email/send", ...)`. The event system sees the `handleUsingRemote` config, POSTs the event to the relay process over a Unix socket with HMAC signing. The relay has the credentials, does the delivery, and returns the result. The app never sees the secrets.

---

## 11. AI hooks (extension points)

The relay defines extension points for AI-powered message processing. Without the Qbix Platform, these are no-ops. With the Platform's AI plugin, they enable classification, policy checks, and suggested replies.

### Hook points

| Hook | Fires when | What AI can do |
|---|---|---|
| `relay/ai/classify` | Inbound message received | Priority, category, sentiment, suggested labels |
| `relay/ai/policy` | Outbound message about to send | PII scan, tone check, quarantine decision |
| `relay/ai/suggest` | User views a thread in panel | Draft reply, suggest template |
| `relay/ai/summarize` | Thread exceeds N messages | Thread summary for inbox view |

### Three-tier cost control for inbound

1. **Gate before LLM** — is this a known sender? An active thread reply? Unknown first contact? Only unknown/unclassified messages go to the LLM.
2. **Per-handler token budget** — configurable limits on LLM tokens per message and per hour, per handler.
3. **Lazy-loaded attachments** — the handler gets attachment metadata only. Vision model analysis is opt-in per handler, gated by the token budget.

### Configuration

```json
{
    "Q": {
        "relay": {
            "ai": {
                "provider": "anthropic",
                "apiKey": "sk-...",
                "model": "claude-haiku-4-5-20251001",
                "classify": true,
                "suggestReplies": true,
                "policyCheck": true,
                "maxTokensPerMessage": 1000,
                "maxTokensPerHour": 50000
            }
        }
    }
}
```

When the Platform is loaded, the relay can use `AI_LLM::process()` with the observations pipeline instead of direct API calls, giving structured analysis via `observations.json` configs.

---

## 12. Default mail client

The relay ships with a default handler and panel UI that provide a working inbox out of the box.

### Default handler

`handlers/relay/email/default/received.php` — stores the message in `relay.db`, routes to the username's inbox, fires AI hooks if configured, notifies the panel via WebSocket.

### Panel inbox tab

The control panel gains a "Messages" tab (not just "Email") showing:

- **Inbox** — per-user message list with unread count, sender, subject, preview, channel indicator (email/SMS)
- **Thread view** — full conversation timeline, interleaved inbound and outbound, across channels
- **Compose / Reply** — send email or SMS from the panel
- **Status labels** — open, pending, resolved, closed (lightweight ticket tracking)
- **Search** — full-text search across all messages

### Reply templates

Templates live in `handlers/relay/templates/`:

```
handlers/relay/templates/
    acknowledgment.txt      — "Thanks for reaching out..."
    billing-issue.txt       — "I've looked into your billing..."
    resolved.txt            — "This has been resolved..."
    custom/                 — user adds their own here
```

When AI is configured, it can suggest which template to use based on inbound message classification.

---

## 13. Deliverability and provider strategy

### Inbound: MX points at the server

MX records point directly at `qbixrelay.php` on port 25. No Google, no external MTA for inbound. The relay is the MX destination.

### Outbound: Google Workspace SMTP relay

The relay forwards outbound email through an established provider for deliverability. The recommended option is the Google Workspace SMTP relay at `smtp-relay.gmail.com:587`.

Google Workspace exposes three "Allowed senders" settings ([documentation](https://support.google.com/a/answer/2956491)):

| Option | What it allows |
|---|---|
| **Only registered users in my domains** | Sender must be an actual Workspace user account |
| **Only addresses in my domains** | Any `*@yourdomain.com` address — no Workspace account needed |
| **Any addresses** (not recommended) | Any address; rewrites envelope sender for off-domain |

**Option 2 is the key.** One Workspace subscription ($7/month for one admin account), and every address on the domain can send through Google's servers. Auth is by IP allowlist — no credentials stored in config at all.

| | Per-user providers (Workspace, M365) | Per-email providers (SES, Mailgun) | Google Workspace relay |
|---|---|---|---|
| **Cost model** | $7/user/month | $0.10–0.80 per 1000 emails | $7/month flat (one admin user) |
| **Unlimited sender addresses** | No — each needs an account | Yes | Yes |
| **Daily limit** | 2,000/user | Varies (SES: 50k+) | 10,000/user, 4.6M org-wide |
| **Auth method** | App password or OAuth2 | API key or SMTP credentials | IP allowlist (no credentials) |
| **Deliverability** | Google-grade | Excellent (if DNS configured) | Google-grade |
| **Setup** | Per-user provisioning | DNS records + API key | One-time IP allowlist |

### Recommended relay configuration

```json
{
    "Q": {
        "relay": {
            "email": {
                "outbound": {
                    "host": "smtp-relay.gmail.com",
                    "port": 587,
                    "encryption": "tls",
                    "auth": "ip"
                }
            }
        }
    }
}
```

With `"auth": "ip"`, no username or password is needed. For providers that use credentials (SES, Mailgun, generic SMTP), use `"auth": "credentials"` with `username` and `password` fields.

All outbound keys default to null. When null, the relay falls back to Platform config (`Users/email/smtp/*`) — see section 6a. If neither is set, the relay logs which key to configure.

### Provider adapters (email)

The relay is provider-agnostic. The outbound config accepts any SMTP server. Provider-specific adapters could add:

- **SES** — `email-smtp.us-east-1.amazonaws.com`, credentials from IAM
- **Mailgun** — `smtp.mailgun.org`, per-domain API keys
- **SendGrid** — `smtp.sendgrid.net`, API key as password
- **Postmark** — `smtp.postmarkapp.com`, server token

Each adapter is ~50 lines of config validation and auth setup.

### Multi-domain with autohost

When autohost provisions a new domain, the relay can send as any address on that domain too — as long as the domain is added to the Workspace account (Admin Console → Domains → Add a domain). Adding a domain is free and doesn't require creating user accounts.

Combined with autohost's DNS verification, this creates a fully automated flow:

1. Customer points DNS (A record + MX record) at the server
2. Autohost provisions TLS for HTTPS
3. MX starts receiving inbound email on the new domain
4. Domain is added to Workspace for outbound relay
5. Full bidirectional email on the new domain with zero manual config

---

## 14. MCP tools

The relay exposes tools for AI agents (via the server's existing MCP interface):

| Tool | What it does |
|---|---|
| `send_email` | Send an email through the relay (subject to policy hooks) |
| `send_mobile` | Send an SMS through the relay |
| `read_inbox` | Read a user's inbox (filtered by channel, status, date) |
| `read_thread` | Read a full conversation thread |
| `list_threads` | List threads for a user, with filters |
| `update_status` | Set thread status (open, pending, resolved) |
| `list_quarantined` | List messages held by AI policy check |
| `approve_message` | Release a quarantined message for delivery |

---

## 15. Platform integration

When the Qbix Platform is loaded, the relay upgrades automatically:

| Capability | Server-only | With Platform |
|---|---|---|
| **Identity** | Config-based username map | `Users_User` with verified emails/phones |
| **Config** | `Q/relay/*` set explicitly | `Q/relay/*` keys fall back to `Users/*` config (section 6a) — zero-config start |
| **Sending** | Direct relay calls | `Users_Email::sendMessage()` and `Users_Mobile::sendMessage()` point SMTP at relay |
| **Events** | `relay/*` events | Also fires `Users/email/sendMessage` and `Users/mobile/sendMessage` with all Platform hooks |
| **AI** | Direct API calls (Haiku) | `AI_LLM::process()` with observations pipeline |
| **Contacts** | Username map only | `Users_Contact` records created on inbound |
| **Threads** | `relay.db` threads | Also mapped to Streams rooms |

### Quick start with existing Platform

If the Platform is already configured with SMTP and Twilio credentials, the relay works immediately — it reads `Users/email/smtp/*` and `Users/mobile/twilio/*` as fallbacks (section 6a). No `Q/relay/` config needed to start.

### Full credential isolation (recommended for production)

Copy credentials into `Q/relay/` config, then point Platform at the relay:

```json
{
    "Users": {
        "email": {
            "smtp": {
                "host": "localhost",
                "port": 2525
            }
        }
    }
}
```

For mobile, use `handleUsingRemote` to delegate `Users/mobile/sendMessage` to the relay:

```json
{
    "Q": {
        "handlersUsingRemote": {
            "Users/mobile/sendMessage": {
                "socket": "/run/qbix/relay.sock",
                "timeout": 10,
                "returnType": "array"
            }
        }
    }
}
```

Now Platform code never sees provider secrets — it sends to localhost / Unix socket, the relay forwards with the real credentials.

---

## 16. Dashboard integration

The dashboard uses a standardized panel structure. Each panel covers one protocol/channel, with a consistent layout: summary gauges across the top, a Sankey flow diagram showing traffic paths and outcomes, time-series charts, and a detail table. A persistent System strip across the top shows cross-cutting health.

### System strip (always visible)

Persistent header above all panels, showing at a glance:

- CPU / memory usage (server + relay processes)
- Disk usage (relay.db size, temp dir, logs)
- Relay process health: running / stopped / restarting, uptime, last crash time
- Queue depths: email outbound queue, digest pending, SMS pending
- Rate limiter state: tokens remaining, any tripped circuit breakers

### Panel: HTTP

What the dashboard already tracks, now organized as a panel:

- **Gauges**: req/s, error rate (4xx + 5xx), p95 latency
- **Sankey**: requests → route → response code. Shows which routes generate errors, which dominate traffic. High-cardinality routes make this the best Sankey fit of the four panels.
- **Time series**: request rate, error rate, response time percentiles over time
- **Table**: recent requests with method, path, status, duration, branch
- **Branch breakdown**: traffic by branch (for A/B testing)

### Panel: WebSockets

- **Gauges**: active connections, messages/sec (in + out), reconnection rate
- **Time series**: connections over time, message throughput, reconnection spikes
- **Table**: active connections with duration, subscription count, messages sent/received
- **Detail**: subscriptions by stream type, connection duration distribution
- **No Sankey** — WebSocket connections aren't a "flow through" with branching outcomes. A live gauge + time series is more natural here.

### Panel: Email

- **Gauges**: outbound sent/min, inbound received/min, delivery rate, bounce rate, queue depth
- **Sankey (outbound)**: messages queued → template/type (transactional, notification, digest) → outcome (delivered, soft bounce, hard bounce, spam complaint). Shows which templates have deliverability problems at a glance.
- **Sankey (inbound)**: received → validation (SPF/DKIM pass, fail) → routing (matched thread, new thread, matched route) → outcome (stored, rejected, rate-limited). Shows how much inbound mail is legitimate vs rejected and why.
- **Time series**: outbound volume, delivery rate, bounce rate trend, complaint rate trend (sender reputation signals)
- **Table**: recent messages with direction, from, to, subject, status, channel
- **Thread view**: click a thread to see the full conversation (inbound + outbound, interleaved with SMS if same user)
- **Quarantine queue**: messages held by AI policy check, with approve/reject actions
- **Digest stats**: pending digest count, last batch time, recipients per batch

### Panel: Mobile

- **Gauges**: SMS sent/min, delivery success rate, cost (running total today/this month)
- **Sankey**: messages queued → type (transactional, notification) → outcome (delivered, failed/invalid number, failed/carrier block, opted out/STOP). Thinner than email but still useful for showing failure reasons.
- **Time series**: send rate, delivery rate, cost over time
- **Table**: recent messages with direction, from, to, body preview, status, cost (per-segment)
- **Per-country breakdown**: international SMS pricing varies significantly; shows volume and cost by destination country
- **Opt-out tracking**: STOP response rate over time

### Layout

The dashboard uses a **tab** layout — one panel visible at a time, with the System strip always visible above. Tabs: **HTTP** | **WebSockets** | **Email** | **Mobile**. Each tab shows its panel's gauges, Sankey (where applicable), charts, and table. Click any tab to switch. The System strip provides cross-panel awareness without needing to see everything at once.

### Sankey: template journey tracking

Beyond delivery outcomes, the Email and Mobile Sankeys can track the full template journey — what happens after delivery. Templates are tagged (e.g. `welcome`, `password-reset`, `billing-reminder`), and the Sankey traces:

```
Template A sent → clicked link X → Template B sent → clicked link Y → ...
               → clicked link Z → (no further template)
               → no click       → Template C sent (reminder) → ...
```

This shows the user journey across templates: which links in a welcome email lead to which follow-up, which reminders actually drive clicks, and where the funnel drops off. Tags on templates make this possible without hard-coding template names into the dashboard — any tagged template participates in the flow automatically.

For this to work, the relay needs:
- **Template tags** on outbound messages (set by the sender in the event params)
- **Link click tracking** (the optional URL rewriting from section 4, enrichment)
- **Correlation** between click events and subsequent sends to the same recipient

This is a second-order Sankey — layered on top of the basic delivery-outcome Sankey, available when link tracking is enabled.

### Standardized panel structure

Each panel follows the same visual template where applicable:

1. **Gauge row** — 3–5 key numbers across the top
2. **Sankey diagram** — traffic flow with branching outcomes (omitted for WebSockets)
3. **Time-series charts** — trends over selectable time windows
4. **Detail table** — recent items with sortable columns and click-to-expand

---

## 17. Porting from smtp.js

The existing Node.js relay (`platform/scripts/smtp.js`, ~1,100 lines) provides battle-tested implementations of:

- Full SMTP state machine (`EHLO`, `MAIL FROM`, `RCPT TO`, `DATA`, `STARTTLS`, `AUTH`)
- MIME parser (multipart, base64, quoted-printable, nested)
- Token-bucket rate limiter + hourly circuit breaker
- Digest mode with exponential backoff
- Outbound SMTP client (plain/implicit-TLS/STARTTLS)
- Per-message audit logging

The porting strategy:

| Component | Node.js | PHP relay |
|---|---|---|
| SMTP protocol handler | In event loop (persistent connections) | In relay's `stream_select` loop |
| MIME parser | String/buffer manipulation | Direct translation to PHP string functions |
| Rate limiter | In-process `Map` | `relay.db` `rate_state` table |
| Digest accumulator | In-process `Map` with timers | `relay.db` `digest_pending`/`digest_state` + timer in relay process |
| Outbound SMTP client | Async with callbacks | Synchronous PHP in forked child |
| Handler dispatch | Calls app code directly | `Q::event()` in forked child |

---

## 18. Non-goals (for now)

- **IMAP/POP3 server** — users read messages through the control panel, app UI, or MCP tools, not through mail clients.
- **Full MTA** — the relay is not Postfix. No queue, no bounce handling, no greylisting, no SPF/DKIM verification on inbound. The relay trusts that inbound mail has already passed through a real MTA (or accepts the risk for direct MX).
- **Mailing list management** — use a dedicated tool (Mailchimp, Listmonk, etc.).
- **Voice calls** — Twilio voice is WebSocket-based media streaming, significantly more complex. Future consideration.
- **Push notifications** — FCM/APNs are outbound-only, stateless API calls. Could be added as a third channel but don't need the relay's threading and persistence model.

---

## 19. Implementation order

1. **SMTP protocol + inbound email handler dispatch** — core value, enables new use cases
2. **Persistence (relay.db) + threading** — needed for conversation integrity and crash recovery
3. **Outbound email relay** — depends on SMTP protocol code from step 1
4. **Rate limiting + digest** — port from smtp.js, uses relay.db
5. **Username-to-address mapping + inbox** — builds on persistence
6. **SMS/mobile support** — Twilio webhook + API, uses same event/threading model
7. **Default panel inbox UI** — visualization layer on relay.db
8. **AI hooks** — extension points, no-op without config
9. **MCP tools** — exposes relay to AI agents
10. **Enrichment** (DKIM, tracking) — polish, per-customer need

---

## 20. Estimated scope

~2,500–3,000 lines of PHP for the full relay:

- SMTP protocol parser and state machine (~300 lines)
- MIME parser (ported from smtp.js) (~250 lines)
- Outbound SMTP client (~200 lines)
- Relay process lifecycle and event loop (~200 lines)
- Routing and Q::event() dispatch (~100 lines)
- Threading and membership tracking (~150 lines)
- relay.db schema and queries (~200 lines)
- Rate limiting and circuit breaker (~150 lines)
- Digest accumulator (~150 lines)
- Username mapping and inbox (~100 lines)
- Twilio SMS integration (webhook + API) (~200 lines)
- AI hook stubs (~100 lines)
- MCP tool definitions (~200 lines)
- Config, logging, panel integration (~200 lines)

---

---
[← Back to README](../README.md)
