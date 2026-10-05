# 📧 Email & SMS Relay

Qbix Server v3.2 includes a built-in relay process that handles email and SMS as first-class transports — the same way it handles HTTP and WebSocket. Drop a handler file in a folder, configure your provider, and the relay dispatches inbound messages to your PHP code.

The relay is a sibling process (`qbixrelay.php`) managed by the main server. One command: `php qbixserver.php` — the server detects relay config and spawns the relay automatically.

---

## Quick Start

### 1. Add relay config to `server.json`

```json
{
  "Q": {
    "relay": {
      "smtp": {
        "listenHost": "127.0.0.1",
        "listenPort": 2525,
        "host": "email-smtp.us-east-1.amazonaws.com",
        "port": 465,
        "secure": true,
        "user": "AKIAIOSFODNN7EXAMPLE",
        "password": "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY"
      },
      "mobile": {
        "provider": "twilio",
        "accountSid": "ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
        "authToken": "your_auth_token",
        "fromNumber": "+15551234567"
      }
    }
  }
}
```

### 2. Start the server

```bash
php qbixserver.php
```

The relay starts automatically when config is present. You'll see:

```
  ╔══════════════════════════════════╗
  ║       Qbix Relay v3.2.0         ║
  ╚══════════════════════════════════╝

  PID:    12345
  SMTP:   127.0.0.1:2525
```

### 3. Point your MTA at the relay

Configure your mail server (Postfix, SES, etc.) to forward inbound mail to `127.0.0.1:2525`. The relay receives it, parses it, and dispatches to your handler.

---

## Architecture

```
                    ┌──────────────────────┐
  php qbixserver ──→│  qbixserver.php      │
                    │  (web server)         │
                    └────────┬─────────────┘
                             │ auto-spawns when config exists
                    ┌────────▼─────────────┐
                    │  qbixrelay.php       │
                    │  (relay process)     │
                    └────────┬─────────────┘
                             │
             ┌───────────────┼───────────────┐
             │               │               │
        ┌────▼─────┐   ┌────▼─────┐   ┌────▼─────┐
        │ Inbound  │   │ Outbound │   │  Mobile  │
        │ SMTP     │   │ SMTP     │   │  (SMS)   │
        │          │   │          │   │          │
        │ Listen   │   │ SES,     │   │ Twilio   │
        │ :2525    │   │ Mailgun  │   │ webhooks │
        └──────────┘   └──────────┘   └──────────┘
```

### Process model

- **Fault isolation**: a bug in SMTP parsing doesn't crash HTTP serving
- **Independent lifecycle**: restart the relay without dropping web connections
- **Credential isolation**: provider secrets live only in the relay process
- **COW workers**: each inbound message forks a ~120KB child process, same as HTTP requests

### Data flow

1. Inbound email arrives at the SMTP listener
2. Relay parses MIME, extracts text/html/attachments
3. Message is stored in `local/relay.db` (SQLite)
4. If digest batching applies, message is queued for later delivery
5. Handler fires: `Q::event('Q/relay/email/incoming', ...)`
6. Outbound delivery goes through the SMTP client to your provider (SES, etc.)

---

## Incoming Email vs. WebSockets

Qbix Server treats email and WebSockets as peer transports. Both deliver messages to your PHP handlers through `Q::event()`, but they serve different communication patterns:

| | Inbound Email | WebSockets |
|---|---|---|
| **Transport** | SMTP (port 2525) | HTTP upgrade (port 80/443) |
| **Connection** | Stateless — each message is independent | Stateful — persistent bidirectional connection |
| **Identity** | Email address (`user@example.com`) | Session/connection ID, authenticated user |
| **Delivery** | Store-and-forward, may be batched via digest | Real-time, sub-second latency |
| **Offline** | Messages queue in inbox, delivered on next check | Lost unless your app implements a replay queue |
| **Threading** | Built-in via Message-ID / In-Reply-To headers | App-defined rooms and channels |
| **Reach** | Anyone with an email address, any provider | Only users connected to your server |
| **Handler** | `Q::event('Q/relay/email/incoming', ...)` | `Q::event('Q/websocket/message', ...)` |
| **Storage** | Automatically stored in `relay.db` threads | Not stored by default — ephemeral |

### When to use which

**Email** is best when you need to reach people who aren't currently on your site, when messages should be durable (stored and searchable), when threading matters (support tickets, discussions), or when you want digest batching (notification storms → single summary).

**WebSockets** are best for real-time interactions where latency matters — live chat, collaborative editing, presence indicators, gaming, notifications that should appear instantly.

**Both together**: A common pattern is to use WebSockets for real-time delivery when the user is connected, and fall back to email when they're offline. The relay's threading model makes this natural — the same conversation can span both transports:

```php
<?php
// In your notification handler
function notify($userId, $message) {
    // Try WebSocket first
    $delivered = Q_WebSocket::send($userId, $message);

    if (!$delivered) {
        // User is offline — queue an email
        Q_Relay_SmtpClient::deliver(
            'app@yoursite.com',
            $userEmail,
            Q_Relay_Mime::compose($message)
        );
    }
}
```

---

## Email Provider Setup

The relay's outbound SMTP client works with any provider that speaks SMTP. Below are detailed setup guides for the most common ones.

### Gmail / Google Workspace

Google Workspace offers three SMTP options. The **SMTP relay service** is recommended for server-to-server sending because it supports the highest volume and doesn't require per-user app passwords.

#### Option 1: SMTP Relay Service (recommended)

The SMTP relay allows up to **10,000 recipients per user per day** — effectively unlimited for most apps. It authenticates by IP address rather than username/password, so your server needs a static IP.

**Step 1: Enable the SMTP relay in Google Admin**

1. Sign in to [admin.google.com](https://admin.google.com)
2. Go to **Apps → Google Workspace → Gmail → Routing**
3. Scroll to **SMTP relay service** and click **Configure** (or **Add another rule**)
4. Set:
   - **Allowed senders**: "Only addresses in my domains" (or "Any addresses" if sending on behalf of external)
   - **Authentication**: "Only accept mail from the specified IP addresses" — add your server's public IP
   - **Encryption**: "Require TLS encryption"
5. Save

**Step 2: Configure the relay**

```json
{
  "Q": {
    "relay": {
      "smtp": {
        "host": "smtp-relay.gmail.com",
        "port": 587,
        "secure": false
      }
    }
  }
}
```

No `user` or `password` needed — authentication is IP-based. Port 587 uses STARTTLS (automatic upgrade). You can also use port 465 (implicit TLS) with `"secure": true`, or port 25 (unencrypted, not recommended).

**Step 3: Set up SPF and DKIM**

Add your server's IP to your domain's SPF record:

```
v=spf1 include:_spf.google.com ip4:YOUR.SERVER.IP ~all
```

DKIM is configured automatically through Google Workspace — go to **Apps → Gmail → Authenticate email** in Admin to verify.

#### Option 2: Gmail SMTP Server

For lower-volume sending (up to **2,000 messages/day**), you can use `smtp.gmail.com` with per-user credentials. Each sender needs a Google Workspace account and an app password.

```json
{
  "Q": {
    "relay": {
      "smtp": {
        "host": "smtp.gmail.com",
        "port": 465,
        "secure": true,
        "user": "sender@yourdomain.com",
        "password": "xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

**Generating an app password:**

1. Sign in to [myaccount.google.com](https://myaccount.google.com) as the sending user
2. Go to **Security → 2-Step Verification** (must be enabled)
3. At the bottom, click **App passwords**
4. Select "Mail" and "Other (Custom name)", enter "Qbix Relay"
5. Copy the 16-character password (spaces optional)

#### Sender Avatars & Profile Photos

When your emails arrive in Gmail, recipients see the sender's Google profile photo in their inbox. To set this up:

1. **Google Workspace profile photo**: In Admin, go to **Directory → Users**, click a user, and upload their photo. Or have users set their own at [aboutme.google.com](https://aboutme.google.com) with visibility set to "Anyone."

2. **Gravatar fallback**: For broader coverage (Outlook, Thunderbird, etc.), create a [Gravatar](https://gravatar.com) account for each sender address and upload the same photo. Many email clients check Gravatar when no provider-specific photo exists.

3. **BIMI (advanced)**: Brand Indicators for Message Identification displays your brand logo next to emails in supporting clients. Requires a verified trademark, a VMC (Verified Mark Certificate), and a BIMI DNS record. See [bimigroup.org](https://bimigroup.org) for the standard.

For apps that send notifications on behalf of individual users (e.g. "Alice commented on your post"), create Google Workspace accounts for each user so their profile photo appears. For generic system emails (password resets, alerts), a single service account with your brand logo works well.

#### Sending Limits Summary (Google Workspace)

| Method | Daily Limit | Recipients/Message | Auth |
|--------|------------|-------------------|------|
| SMTP Relay (`smtp-relay.gmail.com`) | 10,000 recipients/user | 10,000 | IP allowlist |
| Gmail SMTP (`smtp.gmail.com`) | 2,000 messages | 2,000 (500 external) | App password |
| Restricted SMTP (`aspmx.l.google.com`) | Workspace limits | Gmail recipients only | None (IP allowlist) |

Trial accounts are capped at 500/day. Paid accounts may see automatic increases after cumulative spend exceeds $100 USD.

---

### Amazon SES

Amazon Simple Email Service is the go-to for high-volume transactional email. Pay-per-use pricing ($0.10/1,000 emails), no per-day caps in production (SES scales to millions), and deep AWS integration.

**Step 1: Create SMTP credentials**

1. In the [AWS Console](https://console.aws.amazon.com/ses), go to **SMTP settings**
2. Click **Create SMTP credentials**
3. This creates an IAM user — save the SMTP username and password

**Step 2: Verify your domain**

In SES, go to **Verified identities → Create identity → Domain**. Add the DKIM CNAME records and SPF record to your DNS.

**Step 3: Move out of sandbox**

New SES accounts start in sandbox mode (can only send to verified addresses). Request production access under **Account dashboard → Request production access**.

**Step 4: Configure the relay**

```json
{
  "Q": {
    "relay": {
      "smtp": {
        "host": "email-smtp.us-east-1.amazonaws.com",
        "port": 465,
        "secure": true,
        "user": "AKIAIOSFODNN7EXAMPLE",
        "password": "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY"
      }
    }
  }
}
```

Replace the region in the hostname with your SES region (`us-west-2`, `eu-west-1`, etc.).

**Inbound email with SES**: SES can receive email and forward it to your relay. Set up a **Receipt Rule** that forwards to an SNS topic or S3 bucket, then have a Lambda or cron job deliver the messages to your relay's SMTP port. Alternatively, configure SES to forward directly to `127.0.0.1:2525` if your server runs on the same EC2 instance.

---

### SendGrid

SendGrid (owned by Twilio) offers a generous free tier (100 emails/day) and straightforward SMTP setup. Good for getting started quickly.

**Step 1: Create an API key**

1. In the [SendGrid dashboard](https://app.sendgrid.com), go to **Settings → API Keys**
2. Click **Create API Key** with "Full Access" or "Restricted Access" (enable Mail Send)
3. Copy the key — it won't be shown again

**Step 2: Authenticate your domain**

Go to **Settings → Sender Authentication → Domain Authentication** and add the CNAME records to your DNS.

**Step 3: Configure the relay**

```json
{
  "Q": {
    "relay": {
      "smtp": {
        "host": "smtp.sendgrid.net",
        "port": 465,
        "secure": true,
        "user": "apikey",
        "password": "SG.xxxxxxxxxxxxxxxxxxxx.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
      }
    }
  }
}
```

Note: the username is literally the string `apikey` — the API key goes in the password field.

**Inbound email with SendGrid**: SendGrid's Inbound Parse webhook can POST incoming emails to your server. Configure a parse webhook URL at `https://yourserver.com/Q/relay/email/inbound-webhook` and the relay will handle it alongside direct SMTP delivery.

---

### Mailgun

Mailgun offers 1,000 free emails/month (on the Flex plan, for the first 3 months) with excellent deliverability. Well-suited for transactional email.

```json
{
  "Q": {
    "relay": {
      "smtp": {
        "host": "smtp.mailgun.org",
        "port": 465,
        "secure": true,
        "user": "postmaster@mg.yourdomain.com",
        "password": "your-mailgun-smtp-password"
      }
    }
  }
}
```

Find your SMTP credentials in the Mailgun dashboard under **Sending → Domain settings → SMTP credentials**.

---

### Provider Comparison

| Provider | Free Tier | Production Limit | Pricing | Best For |
|----------|----------|-----------------|---------|----------|
| **Google Workspace** | — | 10,000/user/day (relay) | $7.20/user/month | Teams already on Google |
| **Amazon SES** | 62,000/month (if on EC2) | No hard cap | $0.10/1,000 emails | High volume, AWS shops |
| **SendGrid** | 100/day | Varies by plan | Free → $19.95/month | Quick setup, free tier |
| **Mailgun** | 1,000/month (3 months) | Varies by plan | $0.80/1,000 emails | Transactional email |
| **Postmark** | 100/month | Varies by plan | $1.25/1,000 emails | Deliverability focus |

All providers require domain verification (SPF + DKIM) for production sending. The relay works with any SMTP-compatible service — the ones above are the most popular.

---

## Mobile (SMS) with Twilio

The relay integrates with Twilio for sending and receiving SMS. This gives your app a phone number that can send verification codes, alerts, and two-way conversations.

### Setup

**Step 1: Get a Twilio number**

1. Sign up at [twilio.com](https://www.twilio.com)
2. Buy a phone number with SMS capability ($1.15/month for US numbers)
3. Note your **Account SID** and **Auth Token** from the console dashboard

**Step 2: Configure the relay**

```json
{
  "Q": {
    "relay": {
      "mobile": {
        "provider": "twilio",
        "accountSid": "ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
        "authToken": "your_auth_token",
        "fromNumber": "+15551234567"
      }
    }
  }
}
```

**Step 3: Configure the Twilio webhook**

In the Twilio console, go to your phone number's settings and set the **Messaging webhook** to:

```
https://yourserver.com/Q/relay/sms
```

Method: **HTTP POST**. The relay validates every incoming webhook using Twilio's HMAC-SHA1 signature — invalid requests get a 403.

### Sending SMS

```php
<?php
// Simple message
Q_Relay_Mobile::send('+15559876543', 'Your verification code is 123456');

// With a template
Q_Relay_Mobile::sendTemplate('+15559876543', 'verification', [
    'code' => '123456',
]);
```

### Receiving SMS

When someone texts your Twilio number, the relay:
1. Receives the webhook POST from Twilio
2. Validates the HMAC-SHA1 signature against your auth token
3. Fires a `Q::event()` so your app can handle it

```php
<?php
Q::event('Q/relay/sms/incoming', [
    'from' => '+15551234567',
    'to'   => '+15559876543',
    'body' => 'Message text',
]);
```

### Twilio Pricing

SMS pricing varies by country. US messages cost ~$0.0079/outbound and ~$0.0075/inbound. For current pricing, see [twilio.com/sms/pricing](https://www.twilio.com/sms/pricing).

### SMS vs Email: When to Use

| Use SMS for | Use Email for |
|-------------|---------------|
| Verification codes (2FA) | Welcome sequences |
| Time-sensitive alerts | Digests and summaries |
| Short replies (< 160 chars) | Rich HTML content |
| Users who opted in to texts | Long-form communication |
| Delivery confirmation | Attachments |

---

## Configuration Reference

All relay config lives under `Q.relay` in `server.json`. When running with Qbix Platform (`--app`), the relay also checks `Users.relay` as a fallback — so existing Platform email config works without duplication.

### SMTP (Email)

| Key | Default | Description |
|-----|---------|-------------|
| `smtp.listenHost` | `127.0.0.1` | Inbound SMTP bind address |
| `smtp.listenPort` | `2525` | Inbound SMTP port |
| `smtp.host` | — | Outbound SMTP server (SES, Mailgun, etc.) |
| `smtp.port` | `587` | Outbound SMTP port |
| `smtp.secure` | `false` | Use TLS (port 465) vs STARTTLS (port 587) |
| `smtp.user` | — | Outbound SMTP username |
| `smtp.password` | — | Outbound SMTP password |
| `smtp.maxSessions` | `100` | Max concurrent inbound SMTP sessions |
| `smtp.maxMessageSize` | `26214400` | Max message size in bytes (25 MB) |

### Mobile (SMS)

| Key | Default | Description |
|-----|---------|-------------|
| `mobile.provider` | `twilio` | SMS provider |
| `mobile.accountSid` | — | Twilio Account SID |
| `mobile.authToken` | — | Twilio Auth Token |
| `mobile.fromNumber` | — | Default sender number (E.164) |
| `mobile.webhookPath` | `/Q/relay/sms` | Path for inbound SMS webhooks |

### Rate Limiting

| Key | Default | Description |
|-----|---------|-------------|
| `rateLimit.maxPerMinute` | `60` | Token bucket: messages per minute |
| `rateLimit.maxPerHour` | `1000` | Circuit breaker: max per hour |

When the hourly limit is hit, the circuit breaker trips and blocks all outbound delivery until manually reset (send `SIGHUP` to the relay process).

### Digest Batching

When the same sender sends multiple messages to the same recipient in quick succession, the relay can batch them into a single digest instead of flooding the inbox.

| Key | Default | Description |
|-----|---------|-------------|
| `digest.enabled` | `true` | Enable digest batching |
| `digest.firstDelay` | `30000` | Wait time after first message (ms) |
| `digest.backoff` | `2.0` | Multiply delay after each additional message |
| `digest.maxDelay` | `300000` | Maximum delay between flushes (5 minutes) |
| `digest.maxMessages` | `20` | Force flush after this many messages |
| `digest.cooldownMinutes` | `30` | Reset delay after this much silence |

### Logging

| Key | Default | Description |
|-----|---------|-------------|
| `log.level` | `info` | Log level: `debug`, `info`, `warn`, `error` |

---

## Sending Email

### From PHP (standalone mode)

```php
<?php
// Send an email through the relay's outbound SMTP client
Q_Relay_SmtpClient::deliver(
    'sender@example.com',
    'recipient@example.com',
    $rawMimeMessage
);
```

### From PHP (with Qbix Platform)

```php
<?php
// The relay handles Users/email/sendMessage events automatically
Q::event('Users/email/sendMessage', [
    'to'      => 'user@example.com',
    'subject' => 'Welcome!',
    'body'    => '<h1>Hello</h1><p>Welcome to our app.</p>',
]);
```

### Direct SMTP

Any application can send mail by connecting to the relay's SMTP port:

```bash
# From the same machine
swaks --to user@example.com --from app@example.com \
      --server 127.0.0.1 --port 2525 \
      --body "Hello from the app"
```

---

## Email Open & Click Tracking

Outgoing HTML emails are automatically instrumented with:

1. **Tracking pixel** (1x1 transparent GIF) for open detection
2. **Link wrapping** for click-through measurement

### How it works

When an email is sent through the relay, `Q_Relay_EmailTracker` injects:
- A 1x1 transparent GIF before `</body>` pointing to `/Q/relay/open?id=TRACKING_ID`
- Each `<a href="...">` link is rewritten to `/Q/relay/click?id=TRACKING_ID&url=ORIGINAL_URL`

When recipients open the email or click a link, these unauthenticated endpoints log the event and either return the GIF or redirect (302) to the original URL.

### Configuration

```json
{
  "Q": {
    "relay": {
      "tracking": {
        "enabled": true,
        "baseUrl": "https://yourserver.example.com",
        "excludePatterns": ["/unsubscribe", "/privacy"]
      }
    }
  }
}
```

- `enabled` (default: `true`) — toggle tracking on/off
- `baseUrl` — override the auto-detected server URL for tracking links
- `excludePatterns` — URL patterns to skip wrapping (e.g. unsubscribe links)

### Dashboard

The **Email** tab on the dashboard (`/Q/dashboard`) shows:
- Total emails sent, opened, clicked (with rates)
- Interactive Sankey funnel: Sent &rarr; Opened &rarr; Clicked Links / No Click
- Data from the `email_tracking` and `email_events` tables in `relay.db`

### API

| Endpoint | Description |
|---|---|
| `GET /Q/panel/api/email-tracking/summary` | `{total, opened, clicked, openRate, clickRate}` |
| `GET /Q/panel/api/email-tracking/flow` | Sankey flow data `{nodes, links}` |

Both accept an optional `?since=YYYY-MM-DD` parameter.

### Template tracking

Pass a `template` key in the email metadata to group tracking by template name:

```php
$meta = ['template' => 'welcome', 'appHost' => 'myapp.example.com'];
```

This enables per-template funnel analysis in the Sankey diagram.

---

## Receiving Messages

### Inbound Email

The relay fires `Q::event()` hooks when email arrives. With Qbix Platform, these integrate with the existing event system. Without it, register handlers directly:

```php
<?php
// In your app's event handler
Q::event('Q/relay/email/incoming', [
    'from'    => 'sender@example.com',
    'to'      => 'recipient@example.com',
    'subject' => 'Re: Hello',
    'text'    => 'Plain text body',
    'html'    => '<p>HTML body</p>',
    'raw'     => '...full MIME...',
    'parsed'  => [/* Q_Relay_Mime::parseFullMIME result */],
]);
```

### Inbound SMS

Configure a Twilio webhook pointing to your server's relay path:

```
https://yourserver.com/Q/relay/sms
```

The relay validates the Twilio signature (HMAC-SHA1) and fires:

```php
Q::event('Q/relay/sms/incoming', [
    'from' => '+15551234567',
    'to'   => '+15559876543',
    'body' => 'Message text',
]);
```

---

## Expanding to Qbix Platform

When you run the relay with `--app` pointing to a Qbix Platform application, it integrates with the Platform's user system, email infrastructure, and database layer. This is optional — the relay works standalone — but the Platform integration adds user-aware threading, email-to-user mapping, and a richer database model.

### Config Fallback

The relay checks config keys in two places, in order:

1. `Q.relay.smtp.host` — relay's own config (in `server.json`)
2. `Users.relay.smtp.host` — Platform's existing config (fallback)

This means existing Platform deployments that already have `Users.relay` configured don't need to duplicate config. The relay logs which config source it's using at startup.

### Platform Integration Points

| Feature | Standalone | With Platform |
|---------|-----------|---------------|
| Outbound SMTP | Via relay config | Also via `Users.relay` config fallback |
| User identity | Email addresses only | Maps to Platform user IDs |
| Event hooks | `Q::event('Q/relay/...')` | Also fires `Users/email/*` events |
| Templates | Raw MIME only | Platform's template system (`Users_Email`) |
| Contact lookup | N/A | `Users_User::fromEmail()` |
| Threading | By Message-ID headers | Also by user/stream relationships |

### Database Tables

The relay stores all its data in a local SQLite database at `local/relay.db`. Here are the tables and their schemas:

#### `threads` — Conversation threading

```sql
CREATE TABLE threads (
    thread_id       TEXT PRIMARY KEY,
    root_message_id TEXT,             -- Message-ID of the first email in thread
    channel         TEXT NOT NULL,     -- 'email' or 'sms'
    subject         TEXT,
    created_at      INTEGER NOT NULL,
    updated_at      INTEGER NOT NULL
);
```

Threads are created automatically from `Message-ID` and `In-Reply-To` headers. The relay groups related messages into the same thread.

#### `thread_members` — Participants

```sql
CREATE TABLE thread_members (
    thread_id   TEXT NOT NULL,
    address     TEXT NOT NULL,         -- email address or phone number
    username    TEXT,                   -- Platform username (if known)
    added_at    INTEGER NOT NULL,
    role        TEXT DEFAULT 'participant',  -- 'sender', 'recipient', 'cc', 'participant'
    PRIMARY KEY (thread_id, address)
);
```

#### `thread_messages` — Message archive

```sql
CREATE TABLE thread_messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    thread_id   TEXT NOT NULL,
    message_id  TEXT,                  -- RFC 2822 Message-ID
    channel     TEXT NOT NULL,         -- 'email' or 'sms'
    direction   TEXT NOT NULL,         -- 'inbound' or 'outbound'
    from_addr   TEXT NOT NULL,
    to_addr     TEXT,
    subject     TEXT,
    body_text   TEXT,                  -- Plain text body
    body_html   TEXT,                  -- HTML body
    raw         BLOB,                  -- Full MIME message
    received_at INTEGER NOT NULL
);
```

#### `inbox` — Per-user inbox

```sql
CREATE TABLE inbox (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    username    TEXT NOT NULL,          -- Panel user or Platform user
    thread_id   TEXT NOT NULL,
    message_id  TEXT,
    channel     TEXT NOT NULL,
    from_addr   TEXT NOT NULL,
    subject     TEXT,
    body_text   TEXT,
    received_at INTEGER NOT NULL,
    read_at     INTEGER,               -- NULL = unread
    flags       TEXT DEFAULT '{}',     -- JSON flags (starred, archived, etc.)
    ai_labels   TEXT                   -- AI-generated labels/categories
);
```

The inbox maps messages to users. When a panel user has an email address configured, incoming messages addressed to that email appear in their inbox.

#### `digest_pending` — Queued for batching

```sql
CREATE TABLE digest_pending (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient   TEXT NOT NULL,
    mail_from   TEXT NOT NULL,
    raw_message BLOB NOT NULL,
    received_at INTEGER NOT NULL
);
```

#### `digest_state` — Per-sender delay tracking

```sql
CREATE TABLE digest_state (
    recipient       TEXT NOT NULL,
    mail_from       TEXT NOT NULL,
    next_delay_ms   INTEGER DEFAULT 30000,
    last_received_at INTEGER,
    msg_count       INTEGER DEFAULT 0,
    PRIMARY KEY (recipient, mail_from)
);
```

#### `delivery_log` — Outbound audit trail

```sql
CREATE TABLE delivery_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    channel     TEXT NOT NULL,         -- 'email' or 'sms'
    direction   TEXT NOT NULL,
    from_addr   TEXT NOT NULL,
    to_addr     TEXT NOT NULL,
    subject     TEXT,
    message_id  TEXT,
    bytes       INTEGER,
    status      TEXT NOT NULL,         -- 'delivered', 'failed', 'queued', 'bounced'
    error       TEXT,                  -- Error message if failed
    provider    TEXT,                  -- 'ses', 'sendgrid', 'twilio', etc.
    app_host    TEXT,                  -- Qbix app host (Platform mode)
    timestamp   INTEGER NOT NULL
);
```

#### `rate_state` — Rate limiter persistence

```sql
CREATE TABLE rate_state (
    key             TEXT PRIMARY KEY,  -- 'global'
    tokens          REAL NOT NULL,     -- Current token count
    last_refill     INTEGER NOT NULL,  -- Last refill timestamp
    hour_window     TEXT               -- JSON: hourly message counts
);
```

### Indexes

```sql
CREATE INDEX idx_thread_messages_thread ON thread_messages(thread_id);
CREATE INDEX idx_inbox_user_time ON inbox(username, received_at);
CREATE INDEX idx_delivery_log_ts ON delivery_log(timestamp);
CREATE INDEX idx_delivery_log_chan_status ON delivery_log(channel, status);
```

### Querying the Database

The relay provides static methods on `Q_Relay_Db` for common queries:

```php
<?php
// Get a user's inbox (most recent first)
$messages = Q_Relay_Db::getInbox('alice', 20, 0);

// Get a thread with all its messages
$thread = Q_Relay_Db::getThread($threadId);
$messages = Q_Relay_Db::getThreadMessages($threadId);

// Check delivery status
$log = Q_Relay_Db::getDeliveryLog('email', 50);

// Get rate limiter state
$state = Q_Relay_Db::getRateState('global');
```

You can also query the SQLite database directly:

```bash
sqlite3 local/relay.db "SELECT * FROM delivery_log ORDER BY timestamp DESC LIMIT 10;"
```

---

## Running Standalone

The relay can run independently of `qbixserver.php` for production deployments that want separate process management:

```bash
# With CLI options
php bin/qbixrelay.php --host=127.0.0.1 --port=2525

# With a Qbix app (loads Q framework)
php bin/qbixrelay.php --app=/path/to/myapp

# With a config file
php bin/qbixrelay.php --config=relay.json

# Debug mode
php bin/qbixrelay.php --debug
```

### CLI Options

| Option | Description |
|--------|-------------|
| `--app=DIR` | Qbix app directory (loads full Q framework) |
| `--config=FILE` | JSON config file to merge |
| `--host=IP` | SMTP listen address (default: `127.0.0.1`) |
| `--port=PORT` | SMTP listen port (default: `2525`) |
| `--pid=PATH` | Write PID file |
| `--debug` | Enable verbose logging |
| `--version` | Print version and exit |
| `--help` | Print usage and exit |

### systemd Service

```ini
[Unit]
Description=Qbix Relay
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/php /opt/qbix/bin/qbixrelay.php --app=/opt/myapp --pid=/run/qbixrelay.pid
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

---

## MIME Parser

The relay includes a full MIME parser (`Q_Relay_Mime`) for handling email messages:

```php
$parsed = Q_Relay_Mime::parseFullMIME($rawMessage);

echo $parsed['text'];           // Plain text body
echo $parsed['html'];           // HTML body
print_r($parsed['attachments']); // [{filename, contentType, data, size}]
print_r($parsed['headers']);     // Parsed headers
```

Features:
- RFC 2047 encoded-word decoding (base64 and quoted-printable)
- Header unfolding and continuation lines
- Multipart boundary parsing (recursive, depth limit 20)
- Base64 and quoted-printable content transfer encoding
- Charset conversion via `mb_convert_encoding`
- RFC 5987 filename encoding
- HTML sanitization (strips `<script>`, event handlers)

---

## Rate Limiting & Circuit Breaker

### Token Bucket

The relay uses a token bucket algorithm for outbound rate limiting. Tokens refill at `maxPerMinute` per minute. Each outbound message consumes one token. When tokens are exhausted, messages wait until a token becomes available.

### Circuit Breaker

The hourly circuit breaker tracks total outbound messages per hour. When `maxPerHour` is exceeded, the breaker trips and blocks all outbound delivery. This prevents runaway sending (e.g., from a digest bug or misconfigured handler).

**Reset the circuit breaker:**

```bash
# Send SIGHUP to the relay process
kill -HUP $(cat /path/to/relay.pid)
```

### Crash Recovery

Rate-limiter state is persisted to SQLite every 60 seconds. On restart, the relay restores its token count and hour window, so limits aren't reset by crashes or restarts.

---

## Digest Batching

When a sender sends multiple messages to the same recipient in quick succession (e.g., notification storms), the relay batches them into a single digest email.

### How it works

1. First message from sender → recipient: delivered immediately
2. Second message within `firstDelay`: queued, timer starts
3. Additional messages: timer extends with exponential backoff
4. Timer fires or `maxMessages` reached: all queued messages sent as one digest
5. After `cooldownMinutes` of silence: delays reset to initial values

### Bypass

Messages can skip digest batching by including the header:

```
X-Qbix-No-Digest: true
```

This is useful for transactional emails (password resets, verification codes) that should never be delayed.

---

## Security

### Open Relay Protection

The relay refuses to bind to `0.0.0.0` or any non-loopback address without authentication configured. This prevents accidentally running an open relay.

### Twilio Webhook Validation

Inbound SMS webhooks are validated using Twilio's HMAC-SHA1 signature scheme. Invalid signatures are rejected with HTTP 403.

### Credential Isolation

Provider secrets (SMTP passwords, Twilio tokens) live only in the relay process. The web server delegates outbound sending via `handleUsingRemote` events and never sees the credentials.

---

## Monitoring

### Status File

The relay writes a JSON status file at `local/relay-status.json` (atomic write via temp + rename) with current metrics:

```json
{
  "pid": 12345,
  "uptime": 3600,
  "smtp": {
    "sessions": 3,
    "messagesIn": 142,
    "messagesOut": 98
  },
  "rateLimit": {
    "tokens": 45.2,
    "tripped": false
  },
  "workers": 2,
  "timestamp": 1696500000
}
```

### Dashboard Integration

When running with `qbixserver.php`, the relay's status appears in the server dashboard alongside HTTP and WebSocket stats. The dashboard shows:

- Messages in / out / failed
- Active SMTP sessions
- Rate limiter token count
- Circuit breaker status (with a red indicator if tripped)
- Stale status warning (if the relay hasn't updated in 2+ minutes)

---

## Files

```
src/Q/Relay.php              — Process manager, event loop, signal handling
src/Q/Relay/Db.php           — SQLite database layer (8 tables)
src/Q/Relay/Smtp.php         — Inbound SMTP protocol handler
src/Q/Relay/SmtpClient.php   — Outbound SMTP client (SES, Mailgun, etc.)
src/Q/Relay/Mime.php         — MIME parser (RFC 2047, multipart, attachments)
src/Q/Relay/RateLimiter.php  — Token bucket + circuit breaker
src/Q/Relay/Digest.php       — Digest batching system
src/Q/Relay/Mobile.php       — Twilio SMS integration
bin/qbixrelay.php            — Entry point / CLI
tests/test_relay.php         — Test suite (79 tests)
local/relay.db               — SQLite database (created at runtime)
docs/RELAY-PLAN.md           — Architecture design document
```

---

## Troubleshooting

### Relay doesn't start

Check that relay config exists in `server.json`. The server only spawns the relay when SMTP or mobile config is present. Look for the banner output in stderr.

### Circuit breaker tripped

Send `SIGHUP` to the relay process to reset. Check `local/relay.db` → `delivery_log` for what triggered the limit.

### Messages not delivering

1. Check `delivery_log` in relay.db for error details
2. Verify outbound SMTP credentials
3. Check rate limiter metrics: `tokens` should be > 0
4. Try `--debug` mode for verbose SMTP transaction logging

### Digest messages delayed

This is by design — check digest config. Set `digest.enabled` to `false` to disable, or add `X-Qbix-No-Digest: true` header to bypass for specific messages.

### Gmail "Less secure app" errors

Google no longer supports plain passwords. You must use an app password (see the Gmail setup section above) or the SMTP relay service with IP-based auth.

### SES sandbox restrictions

New SES accounts can only send to verified email addresses. Request production access in the AWS Console under **SES → Account dashboard**.

---

[← Back to README](../README.md)
