# Migrating from Caddy

Caddy's automatic HTTPS and zero-config design is the closest to Qbix Server's philosophy. The main difference: Qbix Server runs your PHP directly instead of proxying to php-fpm.

## Quick start

```bash
# Stop Caddy
sudo systemctl stop caddy

# Start Qbix Server (automatic HTTPS works the same way)
./qbixserver --root=/var/www/myapp/public --port=443 --tls=auto --acme-email=admin@example.com
```

## Configuration mapping

### Caddyfile → config

```caddyfile
example.com {
    root * /var/www/myapp/public
    php_fastcgi unix//run/php/php-fpm.sock
    file_server
}
```

Becomes:

```json
{
  "Q": {
    "webserver": {
      "port": 443,
      "domains": {
        "example.com": { "root": "/var/www/myapp/public", "tls": "auto" }
      },
      "autohost": { "enabled": true, "acmeEmail": "admin@example.com" }
    }
  }
}
```

### Automatic HTTPS

Both Caddy and Qbix Server provision Let's Encrypt certs automatically. The behavior is nearly identical — set a domain, get a cert. Qbix Server also supports Cloudflare Origin CA for CDN deployments.

### Autohost (on-demand TLS)

Caddy's `on_demand_tls` maps directly to Qbix Server's autohost feature:

```json
{
  "Q": {
    "webserver": {
      "autohost": {
        "enabled": true,
        "authorize": "allowlist",
        "allowlist": ["*.example.com"]
      }
    }
  }
}
```

### reverse_proxy → proxy config

```json
{ "Q": { "webserver": { "proxy": { "/api/": "http://backend:3000" } } } }
```

### What's different

- **No Caddyfile syntax** — all config is JSON (or CLI flags)
- **No HTTP/3** — Caddy supports QUIC natively; Qbix Server uses HTTP/1.1
- **PHP runs in-process** — no php-fpm socket, no CGI. PHP is part of the server
- **COW workers** — instead of Caddy proxying to a fixed pool of fpm workers, Qbix Server forks thousands of lightweight workers that share memory
