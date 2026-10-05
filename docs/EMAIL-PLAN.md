# Email Support — Design Plan

**Status:** Planned  
**Version target:** 3.2+

Qbix Server already handles HTTP and WebSocket as first-class transports with the same handler pattern: drop a file in a folder, the server dispatches to it. Email (SMTP) is the natural third transport. This document describes two planned capabilities:

1. **Inbound SMTP** — the server accepts email and dispatches to PHP handlers
2. **Local SMTP relay** — frameworks send outbound mail through the server, which logs, enriches, and forwards

---

## 1. Inbound SMTP — Handler Dispatch

### Concept

The server listens on port 25 (and optionally 587) and implements the SMTP protocol directly in the event loop — the same `stream_select` / amphp architecture used for HTTP and WebSocket. When a message arrives, the server parses it and dispatches to PHP handlers using the same file-in-folder convention.

### Why server-level, not framework-level

Inbound SMTP is a transport concern:

- The server already manages sockets, TLS, and process lifecycle
- The COW fork model gives each message its own isolated process — no shared state between messages
- Config-based routing (which addresses go to which handlers) belongs in `server.json`, not in each framework
- External MX records forward mail to a port, not to a framework

### Architecture

```
External MX ──→ Port 25/587 ──→ SMTP protocol handler ──→ Parse envelope + body
                                                              │
                                                    ┌────────┤
                                                    ▼        ▼
                                              Route by     Fork child
                                             To: address    process
                                                    │
                                                    ▼
                                              handlers/email/support.php
```

### Routing

Routing is config-driven, mapping `To:` address patterns to handler files:

```json
{
    "Q": {
        "webserver": {
            "smtp": {
                "enabled": true,
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
```

Patterns support `*` as a wildcard. The first matching route wins. Unmatched mail is rejected at the SMTP level (550).

### Handler interface

Handlers follow the same convention as HTTP and WebSocket handlers:

```php
<?php
// handlers/email/support.php
function email_support(&$params, &$result) {
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

    // Create a support ticket
    $ticket = MyApp\Support::createFromEmail($params);
    
    $result = ['ticketId' => $ticket->id];
}
```

### Attachments

Attachment handling follows these principles:

- **Metadata first** — `$params['attachments']` contains filename, MIME type, size, and content-ID for each attachment. The handler decides whether to load the content.
- **Lazy loading** — call `$params['loadAttachment']($index)` to get the raw bytes. This avoids loading large attachments into memory when the handler only needs metadata.
- **Size limits** — configurable per-message and per-attachment size limits. Messages exceeding the limit are rejected at SMTP level (552).
- **Storage policy** — the server doesn't store attachments. The handler decides where they go (filesystem, S3, database). A helper method saves to a configurable temp directory with automatic cleanup after a TTL.

```json
{
    "smtp": {
        "maxMessageSize": "25MB",
        "maxAttachmentSize": "10MB",
        "tempDir": "local/email-tmp/",
        "tempTtl": 3600
    }
}
```

### Threading / conversations

Email threading maps naturally to the existing room model:

| Email concept | Server concept |
|---|---|
| Thread (by References/In-Reply-To) | Room |
| Recipients (To + CC) | Room membership |
| Message | Event dispatched to the room |

**Thread ID computation:**

1. If `In-Reply-To` or `References` headers exist, extract the root message ID — this is the thread ID
2. Otherwise, generate a new thread ID from the Message-ID

**Membership rules:**

- All addresses in `To:` and `CC:` of any message in the thread are members
- A sender can post to a thread only if they appear in the recipients list of a prior message in the thread
- **BCC policy** (configurable): by default, BCC'd senders cannot contribute to the thread. Set `smtp.bccCanReply: true` to allow it

This prevents strangers from injecting messages into conversations by guessing or spoofing a References header — you have to have been a recipient to participate.

### SMTP protocol details

The server implements the minimum viable SMTP:

- `EHLO` / `HELO`
- `MAIL FROM`
- `RCPT TO`
- `DATA`
- `QUIT`
- `STARTTLS` (using existing TLS infrastructure)
- `AUTH PLAIN` / `AUTH LOGIN` (for authenticated submission on port 587)

Extensions: `8BITMIME`, `SIZE`, `STARTTLS`, `AUTH` (on submission port). No `BDAT`, `CHUNKING`, or `DSN` in the initial version.

### Estimated scope

~800–1000 lines of PHP:

- SMTP protocol parser and state machine (~300 lines)
- Message parsing (envelope, headers, MIME multipart, attachments) (~250 lines)
- Routing and handler dispatch (~100 lines)
- Threading and membership tracking (~150 lines)
- Config, rate limiting, logging (~100 lines)

---

## 2. Local SMTP Relay — Outbound Mail

### Concept

Frameworks (Laravel, WordPress, etc.) send outbound mail by connecting to an SMTP server. Instead of connecting directly to SES, Mailgun, or SendGrid, they connect to `localhost:25` — the Qbix Server itself. The server receives the message, logs it, optionally enriches it, and forwards it to the actual SMTP provider using credentials configured at the server level.

### Why this pattern

| Benefit | How |
|---|---|
| **Credential isolation** | Framework code never sees SMTP passwords — credentials live in `server.json`, not `.env` |
| **Unified logging** | Every outbound email from every app is logged in one place |
| **Conversation tracking** | The server can track outbound threads alongside inbound, building a unified conversation view |
| **Visibility** | Dashboard shows email volume, delivery status, and errors per app |
| **Bottleneck control** | Rate limiting, queuing, and deduplication at the server level |
| **Provider portability** | Switch from SES to Mailgun by changing one config — no app changes |

### Configuration

```json
{
    "Q": {
        "webserver": {
            "smtp": {
                "relay": {
                    "enabled": true,
                    "upstream": {
                        "host": "smtp.mailgun.org",
                        "port": 587,
                        "username": "postmaster@mg.example.com",
                        "password": "key-xxx",
                        "encryption": "tls"
                    },
                    "fromDomain": "example.com",
                    "rateLimit": {
                        "perMinute": 60,
                        "perHour": 1000
                    }
                }
            }
        }
    }
}
```

### Synchronous by default

The relay is synchronous: when a framework calls `mail()` or sends via SMTP to localhost, the server connects to the upstream provider, delivers the message, and returns the result. The caller blocks until delivery completes (or fails).

This is the right default because:

1. **The COW fork model makes blocking cheap.** Each request runs in its own forked process at ~120KB. A 200ms SMTP relay doesn't tie up a 50MB worker — it ties up a 120KB one. With thousands of concurrent workers, blocking is free.
2. **Callers expect synchronous semantics.** `mail()` returns true/false. Laravel's `Mail::send()` expects to know if delivery succeeded. Async breaks these contracts.
3. **Callers who want async can do async themselves.** Queue the mail job, dispatch it to a worker — the framework's queue system handles the async. The relay doesn't need to duplicate that.
4. **Simplicity.** No queue, no retry logic, no persistent state for pending deliveries. The relay is a pipe.

### What the relay logs

For each relayed message:

- Timestamp
- From / To / Subject
- App host (which app sent it)
- Message-ID
- Size
- Upstream response (success / error code)
- Thread ID (if References or In-Reply-To present)

Stored in `local/email-relay.log` and in the analytics SQLite database (for dashboard queries).

### Enrichment (optional)

The server can optionally enrich outbound messages before forwarding:

- **DKIM signing** — sign with the server's key if the `From:` domain matches a configured domain
- **Tracking headers** — add `X-Qbix-App` and `X-Qbix-Branch` headers for debugging
- **Link tracking** — rewrite URLs for click tracking (opt-in, off by default)
- **Unsubscribe headers** — add `List-Unsubscribe` and `List-Unsubscribe-Post` per RFC 8058

### Dashboard integration

The control panel gains an "Email" tab showing:

- Outbound volume over time (chart)
- Delivery success rate
- Recent messages (from, to, subject, status, timestamp)
- Per-app breakdown
- Thread view — click a thread to see the full conversation (inbound + outbound interleaved)

---

## 3. Unified conversation model

When both inbound and outbound are active, the server can build a unified view of email conversations:

```
Customer ──email──→ Server (inbound handler dispatches to app)
         ←email── Server (relay forwards app's reply)
         ──email──→ Server (customer responds, threaded)
```

The thread is tracked by Message-ID / References headers. The dashboard shows the full conversation timeline. The handler receives the thread history as context.

This turns a web server into a communication hub — HTTP for browsers, WebSocket for real-time, SMTP for email, all unified under the same handler pattern and permission model.

---

## 4. Deliverability and provider strategy

The relay doesn't need to be its own MTA with its own IP reputation. It forwards through an established provider. The best option for most deployments is the **Google Workspace SMTP relay service**, which has a remarkable property: you can send as any address on your domain without provisioning individual user accounts.

### Google Workspace SMTP relay

Google Workspace exposes an SMTP relay at `smtp-relay.gmail.com:587` with three "Allowed senders" settings ([documentation](https://support.google.com/a/answer/2956491)):

| Option | What it allows |
|---|---|
| **Only registered users in my domains** | Sender must be an actual Workspace user account |
| **Only addresses in my domains** | Any `*@yourdomain.com` address — no Workspace account needed |
| **Any addresses** (not recommended) | Any address; rewrites envelope sender for off-domain |

**Option 2 is the key.** One Workspace subscription ($7/month for one admin account), and every address on the domain can send through Google's servers:

- `support@yourdomain.com` — not a real user, still sends through Google
- `noreply@yourdomain.com` — same
- `billing@yourdomain.com` — same
- `anything-at-all@yourdomain.com` — same

Google [explicitly documents](https://support.google.com/a/answer/2956491) this for printers, scanners, and applications that need to send as generic addresses. Auth is by IP allowlist (the server's IP is added in the Workspace admin console), so no credentials are stored in `server.json` at all.

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
        "webserver": {
            "smtp": {
                "relay": {
                    "enabled": true,
                    "upstream": {
                        "host": "smtp-relay.gmail.com",
                        "port": 587,
                        "encryption": "tls",
                        "auth": "ip"
                    }
                }
            }
        }
    }
}
```

With `"auth": "ip"`, no username or password is needed — Google authenticates by the server's source IP, which the admin has allowlisted in the Workspace admin console. For providers that use credentials (SES, Mailgun, generic SMTP), use `"auth": "credentials"` with `username` and `password` fields.

### Multi-domain with autohost

When autohost provisions a new domain, the relay can send as any address on that domain too — as long as the domain is added to the Workspace account (Admin Console → Domains → Add a domain). Adding a domain to Workspace is free and doesn't require creating user accounts. Combined with autohost's DNS verification, this creates a fully automated flow: customer points DNS → autohost provisions TLS → domain added to Workspace → relay can send as any address on it → inbound handlers process replies.

### Provider adapters (future)

The relay design is provider-agnostic. The upstream config accepts any SMTP server. Provider-specific adapters could add:

- **SES** — SES SMTP interface at `email-smtp.us-east-1.amazonaws.com`, credentials from IAM
- **Mailgun** — `smtp.mailgun.org`, per-domain API keys
- **SendGrid** — `smtp.sendgrid.net`, API key as password
- **Postmark** — `smtp.postmarkapp.com`, server token

Each adapter is ~50 lines of config validation and auth setup. The relay code itself is provider-independent.

---

## 5. Non-goals (for now)

- **IMAP/POP3 server** — reading mail is a client concern. Frameworks that need to read mail can use IMAP libraries.
- **Webmail UI** — out of scope. The dashboard shows email activity, but the panel is not a mail client.
- **Full MTA** — the server is not Postfix. No queue, no bounce handling, no greylisting, no SPF/DKIM verification on inbound (leave that to the external MX or a dedicated mail gateway). The server trusts that inbound mail has already passed through a real MTA.
- **Mailing list management** — use a dedicated tool (Mailchimp, Listmonk, etc.).

---

## 6. Implementation order

1. **Inbound SMTP + handler dispatch** — core value, enables new use cases
2. **Threading and membership** — builds on inbound, needed for conversation integrity
3. **Local SMTP relay** — depends on SMTP protocol code from step 1, adds outbound
4. **Dashboard integration** — visualization layer on top of logging from steps 1–3
5. **Enrichment** (DKIM, tracking) — polish, per-customer need

---

---
[← Back to README](../README.md)
