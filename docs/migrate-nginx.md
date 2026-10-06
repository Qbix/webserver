# Migrating from nginx

If you have an existing nginx + php-fpm setup, Qbix Server replaces both nginx and php-fpm with a single binary. Here's how to migrate your configuration.

## Quick start

```bash
# Stop nginx + php-fpm
sudo systemctl stop nginx php-fpm

# Start Qbix Server on the same port
./qbixserver --root=/var/www/myapp/public --port=80 --workers=auto
```

## Configuration mapping

### server block → command line or config

nginx `server { listen 80; root /var/www/myapp/public; }` becomes:

```bash
./qbixserver --root=/var/www/myapp/public --port=80
```

Or in config:

```json
{ "Q": { "webserver": { "port": 80, "root": "/var/www/myapp/public" } } }
```

### try_files → automatic

nginx `try_files $uri $uri/ /index.php?$query_string;` is the default behavior. The server checks for a static file first, then routes to your front controller. No configuration needed.

### location blocks → presets or .htaccess

The server reads `.htaccess` files natively. Most nginx `location` blocks translate to `.htaccess` RewriteRules that already exist in your app (Laravel, WordPress, Symfony all ship with them).

For framework-specific routing:

```bash
./qbixserver --root=public --preset=laravel
./qbixserver --root=. --preset=wordpress
./qbixserver --root=public --preset=symfony
```

### proxy_pass → reverse proxy mode

If nginx was proxying to a backend:

```nginx
location /api/ { proxy_pass http://backend:3000; }
```

Set this in config:

```json
{ "Q": { "webserver": { "proxy": { "/api/": "http://backend:3000" } } } }
```

### SSL/TLS

nginx cert paths become config:

```json
{
  "Q": {
    "webserver": {
      "tls": {
        "cert": "/etc/letsencrypt/live/example.com/fullchain.pem",
        "key": "/etc/letsencrypt/live/example.com/privkey.pem"
      }
    }
  }
}
```

Or use auto-provisioning: `"tls": "auto"` with `"acmeEmail": "admin@example.com"`.

### X-Accel-Redirect

Supported natively. Your PHP code can return `X-Accel-Redirect: /path/to/file` headers and the server will serve the file directly, just like nginx.

### gzip

The server compresses responses automatically for clients that accept it. No `gzip on;` configuration needed.

### Virtual hosts

```json
{
  "Q": {
    "webserver": {
      "domains": {
        "app1.example.com": { "root": "/var/www/app1/public" },
        "app2.example.com": { "root": "/var/www/app2/web" }
      }
    }
  }
}
```

Multiple domains can point to the same app — the app uses `$_SERVER['HTTP_HOST']` to differentiate.

### What you lose

- **HTTP/2 server push** — not implemented (browsers are removing support anyway)
- **Load balancing** — use the clustering feature or an external LB
- **Rate limiting by IP** — implement in your PHP app or use a firewall

### What you gain

- **COW memory** — thousands of workers instead of dozens, on the same hardware
- **Single binary** — no nginx config, no php-fpm pool config, no socket permissions
- **Built-in control panel** — manage domains, workers, certs from a browser
- **Auto-provisioning** — new domains get certs automatically with autohost
