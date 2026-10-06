# Migrating from Apache

If you have an existing Apache + mod_php or Apache + php-fpm setup, Qbix Server replaces both. Your `.htaccess` files work without changes.

## Quick start

```bash
# Stop Apache
sudo systemctl stop apache2  # or httpd

# Start Qbix Server
./qbixserver --root=/var/www/html --port=80
```

## .htaccess compatibility

The server reads `.htaccess` files natively and supports:

- `RewriteEngine On/Off`
- `RewriteRule` with regex patterns, flags `[L]`, `[R=301]`, `[QSA]`, `[NC]`
- `RewriteCond` with `%{REQUEST_FILENAME}`, `%{REQUEST_URI}`, `%{HTTP_HOST}`
- `DirectoryIndex`
- `ErrorDocument`
- `Header set/append/unset`
- `Options -Indexes`

Most WordPress, Laravel, Drupal, and Joomla `.htaccess` files work unchanged.

## Configuration mapping

### VirtualHost → domains config

```apache
<VirtualHost *:80>
    ServerName app.example.com
    DocumentRoot /var/www/app/public
</VirtualHost>
```

Becomes:

```json
{
  "Q": {
    "webserver": {
      "domains": {
        "app.example.com": { "root": "/var/www/app/public" }
      }
    }
  }
}
```

### mod_rewrite → already supported

Your `.htaccess` RewriteRules work as-is. The server also has built-in front-controller routing that handles the common case (try static file → fall through to index.php) without any rewrite rules.

### mod_ssl → tls config

```json
{ "Q": { "webserver": { "tls": "auto" } } }
```

### ProxyPass → proxy config

```json
{ "Q": { "webserver": { "proxy": { "/api/": "http://backend:3000" } } } }
```

### What you keep

- `.htaccess` files work unchanged
- `$_SERVER` superglobals populated identically
- `php.ini` settings respected via `--php-ini` flag
- File upload handling works the same way

### What you gain

- 10-100x more concurrent workers via COW memory
- Single binary deployment — no Apache modules to install
- Built-in cert provisioning and auto-renewal
- Control panel with framework-aware package management
