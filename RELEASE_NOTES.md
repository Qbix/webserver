# Qbix Server v1.5.0 – Boldly Go

## What's New Since v1.3

### ECDSA P-256 Signing with M-of-N Verification

The Trust system now uses ECDSA P-256 by default (same curve as Sigstore and SSH). Keys are 256 bits instead of RSA's 2048, signatures are 64 bytes instead of 256. Old RSA keys still work — the signer auto-detects the key type.

Multiple signers can each sign the binary independently. At verification time, configure a threshold: "require 2 of 3 signatures to be valid."

```bash
# Generate ECDSA keys
./qbixserver --generate-key=alice
./qbixserver --generate-key=bob

# Each signer signs independently
./qbixserver --sign-binary --key=local/keys/alice.pem --signer=Alice
./qbixserver --sign-binary --key=local/keys/bob.pem --signer=Bob

# Verify: need 2 of 2
./qbixserver --verify-binary --m=2
```

### Sigstore Rekor Transparency Log

After signing, optionally publish the attestation to Sigstore's public transparency log. Rekor provides an independent, tamper-evident record that the binary was signed at a specific time. A compromised server can't fake this — the Rekor entry is append-only and publicly auditable.

```bash
./qbixserver --publish-rekor
# → Published! Rekor UUID: ...
# → Verify: https://search.sigstore.dev/?uuid=...
```

The `/Q/attestation` endpoint returns both the server's own signatures and the Rekor log entry. Browsers get two independent attestations: the server says "my hash is X, signed by Alice and Bob," and Rekor says "hash X was registered at time T." If they agree, the deployment is what the signers approved.

### `/Q/attestation` Endpoint

Serves the binary hash, signer metadata, verification result, and Rekor reference as JSON. Monitoring tools, browser extensions, or client-side JS can verify the deployment without trusting the server alone.

### Security Tab in Control Panel

The panel now has 13 tabs. The new Security tab shows:

- Binary hash (SHA-256) and all signatures with signer names, key IDs, algorithms, dates
- Sign from the browser: paste a PEM private key, name the signer, click Sign
- Verify with adjustable M-of-N threshold
- Publish to Sigstore Rekor with one click (with confirmation — it's permanent and public)
- Rekor log entry link after publishing
- Code Trust status showing per-directory manifest verification results

### Metrics and Analytics

Server-side operational metrics with buffered I/O:

**Buffered logging** — access log lines accumulate in memory (up to 500 lines). Flushed to disk every 10 seconds. Error log has a separate buffer. No disk write per request.

**Time-series in SQLite** — one row per minute: request count, p50/p95/p99 latency, status code distribution, worker count, memory. Queryable from the dashboard. Retained 30 days by default.

**Clickstream analytics** — tracks page transitions per session. Each `(from_page, to_page)` edge accumulates a count. The flow graph powers userflow diagrams on the dashboard. Sessions detected via framework-aware cookie matching (15 frameworks supported) with IP+UA fallback.

**Per-page stats** — hits, unique sessions, average response time, last hit. Queryable via `metrics/pages`.

**Prometheus endpoint** — `GET /Q/metrics` returns standard gauges and counters in Prometheus text format. Grafana, Datadog, or any scraper can consume it directly.

**Log rotation** — daily rotation, old logs compressed to `.zip`, purged after N days (default 7). Zip compression saves 85-90% on repetitive log data.

**Anomaly webhook** — set `Q.webserver.metrics.anomalyWebhook` to a URL and the server POSTs a JSON alert on traffic spikes (3× average), error spikes (>10% 5xx), or latency spikes (avg >5s).

**Framework-aware session cookies** — detects 15 frameworks and knows their session cookie names:

| Framework | Cookie |
|---|---|
| Qbix | `Q_session_*` (prefix) |
| Laravel | `laravel_session` |
| WordPress | `wordpress_logged_in_*` (prefix) |
| Drupal | `SESS*` (prefix) |
| Symfony | `PHPSESSID` |
| Magento | `frontend`, `adminhtml` |
| Craft CMS | `CraftSessionId` |
| Moodle | `MoodleSession` |
| + 7 more | auto-detected |

### Autohost — Auto-Provision Domains

Enable `Q.webserver.autohost.enabled: true`. When a request arrives with an unknown Host header, the server validates the hostname, checks DNS (multi-resolver: system + 1.1.1.1 + 8.8.8.8), provisions a Let's Encrypt cert, writes the domain config, and serves the app. Authorization modes: open, allowlist with glob patterns, or a custom PHP hook.

### Watchdog — Auto-Restart on Crash

Fork an independent process that monitors the server and restarts it on crash with exponential backoff. Gives up after 10 crashes in one hour. On clean exit, the watchdog exits too — the shutdown handler explicitly kills it.

### Graceful Worker Recycling

Workers track their request count. After `maxRequests` (default 1000), a worker finishes its current request and is replaced with a fresh fork. Panel controls: recycle a single worker, or "Recycle All" for a rolling restart. Per-worker table shows PID, status, and request count.

### Config File Watcher

The server polls config files every 3 seconds. When a file changes, the config is re-read and merged. New requests see new values immediately. No restart needed.

### Data Directory for Packed Binaries

When running as a packed binary, data goes to `<binary>.data/` instead of `./local/`. Subdirectories for logs, certs, and keys created automatically. All components use `qbix_data_path()` so paths resolve correctly in both packed and normal mode.

### SQLite Auto-Provisioning

If your app bundles a `.sqlite` file at a conventional location, the server copies it to the data directory on first run and writes the framework config to point at it. The seed stays in the zip for factory reset — delete `myapp.data/` and re-run to start fresh.

Seed locations checked: `database/database.sqlite` (Laravel), `var/data.db` (Symfony), `local/db.sqlite` (Qbix), `data/db.sqlite`, or any single `.sqlite` file in the app root.

Config writing for six frameworks:

- **Qbix** — modifies `local/app.json`, replaces `Db.connections.*` with SQLite DSN, preserves existing plugin connections, auto-detects installed plugins from `plugins/*/config/plugin.json` and adds connections with correct table prefixes. Preserves tab indentation and empty objects.
- **Laravel** — sets `DB_CONNECTION=sqlite` and `DB_DATABASE=/path` in `.env`.
- **Symfony** — sets `DATABASE_URL=sqlite:///path` in `.env`.
- **WordPress** — writes `DB_DIR` and `DB_FILE` constants in `wp-config.php` (requires wp-sqlite-db drop-in).
- **Craft CMS** — sets `CRAFT_DB_DRIVER=sqlite` in `.env`.
- **Drupal** — appends SQLite driver config to `sites/default/settings.php`.

Sets `QBIX_DB_PATH` environment variable so apps can find the provisioned database.

### Hosts File Management

The Domains tab reads `/etc/hosts` (Windows: `drivers\etc\hosts`), cross-references configured domains, and offers to add missing entries with platform-specific elevation commands (macOS auth dialog, Windows UAC, Linux pkexec).

### Migration Guides

- [Migrating from nginx](docs/migrate-nginx.md)
- [Migrating from Apache](docs/migrate-apache.md)
- [Migrating from Caddy](docs/migrate-caddy.md)

### Built-in Cert Renewal

A `_certRenewal` task runs every 12 hours via the internal scheduler. Scans all certs and renews any expiring within 30 days.

## Download

| Platform | Binary | Fork Model |
|---|---|---|
| Linux x86_64 | `qbixserver-linux-x86_64` | pcntl_fork (COW) |
| Linux ARM64 | `qbixserver-linux-aarch64` | pcntl_fork (COW) |
| macOS ARM64 | `qbixserver-macos-arm64` | pcntl_fork (COW) |
| Windows x64 | `qbixserver-windows-x64.exe` | RtlCloneUserProcess (COW) |
| Windows x64 | `qbixserver-windows-x64-gui.exe` | Same, no console window |

## Stats

- 70 unit tests + 30 end-to-end API tests + 25 SQLite provisioning tests = 125 checks, 0 failures
- 13-tab control panel (4,411 lines)
- Trust system with ECDSA + M-of-N + Rekor (780 lines)
- Metrics with clickstream + Prometheus (724 lines)
- SQLite auto-provisioning for 6 frameworks (387 lines)
- 22 documentation files
- ~16,000 lines of PHP + 165 lines of C
