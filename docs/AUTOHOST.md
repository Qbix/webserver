# Autohost — Automatic Domain Provisioning

Autohost lets your Qbix Server accept new domains on the fly. When a request arrives with a `Host:` header the server doesn't recognize, autohost validates the domain, verifies DNS, provisions a TLS certificate, and starts serving — all automatically.

This enables multi-tenant SaaS, white-label hosting, customer-owned domains, and self-serve site provisioning without manual setup per domain.

## How it works

When a request arrives for an unknown hostname:

1. **Validate** — hostname must be RFC 1123 compliant, at least two labels, no label longer than 63 characters
2. **Rate limit** — per-IP and global hourly limits prevent abuse
3. **Authorize** — check the hostname against the configured authorization mode (open, allowlist, or custom hook)
4. **Verify DNS** — multi-resolver check that the hostname actually points at this server's IP
5. **Provision TLS** — ACME HTTP-01 challenge for a Let's Encrypt certificate (if `acmeEmail` is configured)
6. **Write config** — domain is saved to `local/panel.json` and becomes a known host
7. **Serve** — subsequent requests are served normally

While TLS provisioning is in progress (typically 5–15 seconds), visitors see a splash page with a spinner and auto-refresh. Once the certificate is issued, the page reloads to the actual site.

## Configuration

Enable autohost in `config/server.json` or via the control panel's Autohost tab:

```json
{
    "Q": {
        "webserver": {
            "autohost": {
                "enabled": true,
                "authorize": "allowlist",
                "allowlist": ["*.example.com", "app.acme.com"],
                "dnsCheck": true,
                "acmeEmail": "admin@example.com"
            }
        }
    }
}
```

### Config reference

| Key | Default | What it does |
|---|---|---|
| `autohost.enabled` | `false` | Enable automatic domain provisioning |
| `autohost.authorize` | `"open"` | Authorization mode: `"open"`, `"allowlist"`, or a PHP hook file path |
| `autohost.allowlist` | `[]` | Hostname patterns when `authorize` is `"allowlist"` |
| `autohost.dnsCheck` | `true` | Verify the hostname's DNS resolves to this server before provisioning |
| `autohost.dnsResolvers` | `["1.1.1.1", "8.8.8.8"]` | Additional DNS resolvers for verification (beyond the system resolver) |
| `autohost.ourIps` | auto-detected | Explicit list of this server's public IPs (overrides auto-detection) |
| `autohost.rateLimit.perIpPerHour` | `10` | Max provisioning attempts per client IP per hour |
| `autohost.rateLimit.globalPerHour` | `100` | Max total provisioning attempts per hour |
| `autohost.splash` | `true` | Show a splash page during TLS provisioning |
| `autohost.defaultRoot` | server's current root | Document root for newly provisioned domains |
| `autohost.defaultApp` | server's current app | App directory for newly provisioned domains |
| `autohost.acmeEmail` | from `tls.acmeEmail` | Email for Let's Encrypt certificate registration |
| `autohost.dnsMismatchCacheSec` | `300` | How long to cache a DNS mismatch (avoids re-checking a domain that doesn't point here) |
| `autohost.certFailCacheSec` | `3600` | How long to cache a cert provisioning failure |
| `autohost.log` | `local/autohost.log` | Log file path |

## Authorization modes

### Open mode

```json
{ "authorize": "open" }
```

Any hostname that passes DNS verification is provisioned. Use this only when you control the DNS (e.g., a wildcard `*.yourplatform.com` record) or when the server is behind a firewall.

### Allowlist mode

```json
{
    "authorize": "allowlist",
    "allowlist": ["*.example.com", "app.acme.com", "*.shop.myplatform.io"]
}
```

Only hostnames matching a pattern are provisioned. Patterns support `*` as a wildcard for one DNS label — `*.example.com` matches `shop.example.com` but not `a.b.example.com`. Exact hostnames (`app.acme.com`) also work.

The allowlist is global — it applies to all domains on the server. For per-domain or more complex authorization logic, use a custom hook.

### Custom hook

```json
{ "authorize": "/path/to/hooks/autohost-auth.php" }
```

The hook file must return a callable that receives the hostname and context, and returns an array with an `allowed` key:

```php
<?php
// hooks/autohost-auth.php
return function ($hostname, $context) {
    // $context['ip'] — the client's IP address
    
    // Example: allow only domains from paying customers
    $allowed = MyApp\Billing::domainIsActive($hostname);
    
    return ['allowed' => $allowed];
};
```

The hook runs in the parent process, so keep it fast — no blocking I/O. For async lookups (database, API), consider caching results.

## DNS verification

When `dnsCheck` is `true` (the default), the server verifies that the hostname's `A` record points at one of this server's IP addresses before provisioning. This prevents someone from pointing arbitrary domains at your server to obtain certificates for domains they don't control.

The server checks three ways:

1. **System resolver** — `gethostbyname()` using the OS DNS configuration
2. **Cloudflare** — `dig @1.1.1.1` (or `nslookup` as fallback)
3. **Google** — `dig @8.8.8.8` (or `nslookup` as fallback)

If any resolver returns an IP matching this server, the check passes. Additional resolvers can be configured via `dnsResolvers`.

The server's own IP is auto-detected from network interfaces and external discovery services (AWS IMDS, ipify, icanhazip). You can override this with `ourIps` if auto-detection doesn't work in your environment.

## TLS provisioning

When `acmeEmail` is configured, autohost provisions a Let's Encrypt certificate for each new domain using HTTP-01 challenges. The server handles the ACME protocol internally — no certbot or external tools needed.

Certificates are stored in the `tls.certDir` directory (default `local/certs/`) with one subdirectory per hostname. The server registers each new certificate for SNI-based selection so HTTPS works immediately.

A daily timer (`renewAll`) checks all certificates and renews any expiring within 30 days. Renewed certificates are hot-swapped into the SNI map without restarting the server.

If `acmeEmail` is not set, autohost still provisions the domain (writes config, starts serving) but without TLS. You'd handle HTTPS via a reverse proxy in front.

## Framework detection

When autohost provisions a new domain and `defaultApp` points at an application directory, the server auto-detects which PHP framework is installed and selects the appropriate preset. Detected frameworks:

Qbix, Laravel (including October CMS and Statamic), Symfony, WordPress, Drupal, Joomla, Magento, TYPO3, Craft CMS, Moodle, MediaWiki, Nextcloud, PrestaShop, Laminas, FuelPHP.

The detected framework determines which directory is served as the document root (e.g., `public/` for Laravel, `web/` for Drupal).

## Rate limiting

Autohost rate limits provisioning attempts to prevent abuse:

- **Per-IP**: 10 attempts per hour per client IP (configurable via `rateLimit.perIpPerHour`)
- **Global**: 100 total attempts per hour across all IPs (configurable via `rateLimit.globalPerHour`)

Requests that exceed the limit are silently declined (the server returns no response for the unknown host). Rate limit counters are kept in memory and reset on server restart.

## Domain claim verification (planned)

When autohost provisions a new domain, the question arises: who gets to manage the site? The server will support configurable verification modes for domain claiming:

| `claimVerify` | Who can claim | Best for |
|---|---|---|
| (not set) | No self-serve claiming; admin assigns via panel | Managed hosting, white-label (default) |
| `"dns"` | Only someone with DNS control for the domain | Custom domains, high-security |
| `"token"` | Anyone with a pre-shared claim token | Onboarding flows where admin provisions first |

### DNS TXT verification

The strongest self-serve option. When a customer visits their newly provisioned domain, the landing page shows:

> To verify you own this domain, add this TXT record:
> `_qbix-verify.example.com TXT "qbix-site-verify=a8f3c2..."`

The customer adds the TXT record (they already have DNS access — they just pointed the A record at this server), clicks "Verify," and the server checks for the record. On match, the customer is created as a `manager` scoped to that domain.

The verification token is derived from the domain name and the server's fingerprint, so it's stable across page reloads and doesn't need to be stored separately.

### Pre-shared token

Before telling a customer to point their domain, the admin generates a claim token in the panel. The customer visits their domain, enters the token, and is verified. Useful for onboarding flows where the admin knows who the customer is.

### Admin-assigned (default)

No self-serve claiming. The domain is provisioned and serves the configured default page. An admin creates the user and assigns them to the domain from the panel. The customer receives an invite link.

This is the default — consistent with the principle that boolean options should default to falsy. Self-serve claiming requires explicit opt-in.

## Self-serve provisioning flow

Autohost enables two provisioning patterns for self-serve SaaS:

### Build first, point later

1. An admin (or automated workflow) creates a branch, customizes a template app, iterates, and promotes the branch to trunk
2. The site is ready
3. The customer points their domain's DNS at the server
4. Autohost provisions TLS and serves the already-ready site

### Point first, build after

1. The customer points their domain at the server
2. Autohost provisions TLS and serves a configurable landing page
3. The landing page triggers a workflow — domain verification, template cloning, branch creation
4. The customer (or an AI assistant) builds the site on a branch
5. An admin promotes the branch, replacing the landing page with the real site

The landing page for newly provisioned domains is configurable via `defaultRoot`. Point it at a directory containing your onboarding flow, holding page, or "coming soon" template.

## Panel UI

The Autohost tab in the control panel provides:

- **Enable/disable** toggle
- **Authorization mode** selector (open / allowlist / custom hook)
- **Allowlist** textarea (one pattern per line)
- **DNS check** toggle
- **ACME email** input
- **Recent log** — last 20 provisioning events

All changes take effect immediately — no server restart needed.

## Autohost vs. branch subdomains

These are separate systems that serve different purposes:

| | Autohost | Branch subdomains |
|---|---|---|
| **Purpose** | Provision new domains/apps | Preview branches of an existing app |
| **Trigger** | Unknown `Host:` header on any request | Admin creates a branch |
| **Domains** | Any domain pointing at the server | `{branch}.{apphost}` subdomains |
| **TLS** | ACME inline during first request | Parent process polls `.pending/` markers |
| **Use case** | Multi-tenant SaaS, customer-owned domains | Development, staging, A/B testing |

They don't conflict — a branch subdomain is a known host, so it never triggers autohost.

## Migrating from Caddy's on-demand TLS

If you're migrating from Caddy, autohost is the equivalent of Caddy's `on_demand` TLS. See [Migrate from Caddy](migrate-caddy.md) for the mapping.

---

---
[← Back to README](../README.md)
