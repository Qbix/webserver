# Qbix Server v3.1 — Branch Subdomains & Auto-TLS

v3.0 added observability. v3.1 gives every branch its own subdomain and automatically provisions a TLS certificate for it — no manual cert management, no DNS provider API.

## Branch Subdomain Routing

Each branch is now accessible at `subdomain.apphost.com`. The subdomain defaults to the branch name (DNS-sanitized, lowercase, 1–63 characters). It's unique within each app and can be changed at any time through the Panel API or control panel UI.

Routing works by matching the incoming `Host` header: when the host is `feature.myapp.com` and `feature` maps to a known branch of `myapp.com`, the request goes to that branch's copy-on-write directory and database. The existing header (`X-Q-Branch`) and cookie (`_q_branch`) routing still work alongside subdomain routing.

**Panel API:**
- `POST /Q/panel/api/branches/subdomain` — get or set a branch's subdomain

**DNS setup:** Point `*.yourapp.com` to the server's IP with a wildcard A record. That's the only DNS configuration needed.

## Per-Subdomain TLS Certificate Provisioning

When `Q.webserver.tls.acmeEmail` is configured, the server provisions a Let's Encrypt certificate for each branch subdomain automatically using HTTP-01 challenges. No DNS provider credentials are needed.

### How it works

1. **At startup**, the server iterates all existing branches and provisions certs for any subdomain that doesn't have a valid one.

2. **On branch creation or subdomain change**, the worker process writes a marker file to `local/certs/.pending/`. The parent process checks for markers every 30 seconds and provisions certs in-process — this is where `$pendingChallenges` lives and where `handleRequest()` serves the ACME validation responses.

3. **Renewal** happens on the existing 12-hour timer. Branch subdomain certs are renewed alongside primary domain certs when they have fewer than 30 days remaining.

4. **SNI routing** registers each branch cert in the server's SNI map. HTTPS connections to `feature.myapp.com` get the correct certificate automatically, with no restart needed.

5. **Retry logic** retries failed provisioning up to 5 times (once per 30-second tick), then gives up and logs the failure.

### Prerequisites

- A wildcard DNS A record: `*.yourapp.com → server IP`
- Port 80 reachable from the internet (for ACME HTTP-01 validation)
- Config: `Q.webserver.tls.acmeEmail` set to a valid email address

### Rate limits

Let's Encrypt allows 50 certificates per registered domain per week. For development, set `Q.webserver.tls.acmeStaging: true` to use the staging environment (higher limits, untrusted certs).

### Cert storage

```
local/certs/
  account.pem                     # ACME account key (shared)
  myapp.com/
    fullchain.pem                  # Primary domain cert
    privkey.pem
  feature.myapp.com/
    fullchain.pem                  # Branch subdomain cert
    privkey.pem
  .pending/                        # Marker files (transient)
```

## Per-Branch Access Control

Each branch now has an access map that controls who can do what:

```json
{
  "alice": {"branch": "admin", "files": "code"},
  "bob":   {"branch": "edit",  "files": "frontend"},
  "carol": {"branch": "view",  "files": "none"}
}
```

**Branch permission** (`view` / `edit` / `admin`) controls whether a user can see the branch, push changes to it, or configure it.

**Files permission** (`none` / `styles` / `markup` / `frontend` / `code`) controls which file types the user can push, using the same tier system as default lockdown.

**Panel API:**
- `POST /Q/panel/api/branches/access` — get or set the access map

The control panel's Branches tab includes an access dialog for managing permissions per user.

## Control Panel Updates

The Branches tab has been redesigned:

- **Branch cards** show the subdomain URL, creation date, creator, database name, access count, and TLS status
- **Subdomain editing** — click the pencil icon to change a branch's subdomain inline
- **Copy URL** — one-click copy of the branch's full URL
- **TLS badge** — green checkmark when provisioned, yellow "pending" during provisioning, gray "no TLS" otherwise
- **Access dialog** — add/remove users, set branch and file permissions per user
- **Mobile-responsive** — cards stack vertically on small screens with full-width action buttons

## Configuration

All under `Q.webserver`:

| Key | Default | Purpose |
|---|---|---|
| `tls.acmeEmail` | `""` | ACME contact email (required for auto-TLS) |
| `tls.certDir` | `"local/certs"` | Where certs are stored |
| `tls.acmeStaging` | `false` | Use Let's Encrypt staging environment |

## Upgrading from v3.0

No breaking changes. All v3.0 configuration, APIs, and features are preserved. Branch subdomain routing and auto-TLS activate automatically when branches exist and `acmeEmail` is configured. Existing branches get subdomains defaulting to their branch name.
