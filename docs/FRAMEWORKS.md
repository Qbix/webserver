# Supported Frameworks

Qbix Server runs PHP frameworks unmodified. No code changes, no plugins, no extensions — your existing app works as-is. The server rewrites 27 built-in PHP function calls (like `header()`, `session_start()`, `setcookie()`) via source transformation at load time, so frameworks that rely on these functions work correctly under the CLI SAPI. See [compatibility.md](compatibility.md) for the full technical details.

## How to use

```bash
cd my-app
php qbixserver.php --root=public --preset=laravel --port=8080
```

The `--preset` flag sets framework-appropriate defaults (front controller, upload limits, memory limits). Without it, the server reads your `.htaccess` and auto-detects the front controller.

## Two execution modes

**No-boot mode** (default) — the front controller is re-included on each request. The compat source transform rewrites function calls so `header()`, `session_start()`, etc. work correctly. Your code runs unmodified. This is the simplest mode and works with every framework.

**Boot mode** — the server loads the framework once in a parent process, then COW-forks workers for each request. Workers inherit all loaded classes and share the parent's memory. This eliminates bootstrap overhead entirely but requires a boot adapter that knows how to snapshot and restore the framework's state. Boot adapters ship for most major frameworks.

## Frameworks

### Laravel

The heaviest bootstrap of the major frameworks. Service container compilation, facade loading, config parsing, and route registration take 50ms+ per request under php-builtin. Qbix's persistent workers eliminate this overhead entirely.

| | |
|---|---|
| **Preset** | `--preset=laravel` |
| **Document root** | `--root=public` |
| **Boot adapter** | Yes — snapshots after service container + route compilation |
| **Benchmark** | [190 → 653 req/s (3.4× in no-boot mode)](BENCHMARKS.md#laravel) |

```bash
cd my-laravel-app
php qbixserver.php --root=public --preset=laravel --port=8080
```

### Symfony

Symfony's compiled dependency injection container makes its bootstrap already fast. The compat source transform has minimal impact.

| | |
|---|---|
| **Preset** | `--preset=symfony` |
| **Document root** | `--root=public` |
| **Boot adapter** | Yes — snapshots after container compilation |
| **Benchmark** | [957 req/s baseline](BENCHMARKS.md#symfony) |

```bash
cd my-symfony-app
php qbixserver.php --root=public --preset=symfony --port=8080
```

### WordPress

WordPress calls PHP builtins directly throughout core, themes, and plugins. All 27 shimmed functions are exercised. The WordPress preset sets `upload_max_filesize` to 64M for the media uploader.

| | |
|---|---|
| **Preset** | `--preset=wordpress` |
| **Document root** | `--root=.` |
| **Boot adapter** | Yes — snapshots after plugin/theme loading |

```bash
cd my-wordpress-site
php qbixserver.php --root=. --preset=wordpress --port=8080
```

### Drupal

Drupal 8+ is built on Symfony components. The largest speedup of all tested frameworks: 4.0× in no-boot mode, thanks to Drupal's heavy module-loading and hook-system bootstrap.

| | |
|---|---|
| **Preset** | `--preset=drupal` |
| **Document root** | `--root=web` |
| **Boot adapter** | Yes (requires database during bootstrap) |
| **Benchmark** | [404 → 1,636 req/s (4.0× in no-boot mode)](BENCHMARKS.md#drupal-10) |

```bash
cd my-drupal-site
php qbixserver.php --root=web --preset=drupal --port=8080
```

### CakePHP

CakePHP's middleware pipeline and route compilation benefit from persistent workers. Strong 2.0× improvement in no-boot mode.

| | |
|---|---|
| **Preset** | `--preset=cakephp` |
| **Document root** | `--root=webroot` |
| **Boot adapter** | Yes |
| **Benchmark** | [905 → 1,790 req/s (2.0× in no-boot mode)](BENCHMARKS.md#cakephp-5) |

```bash
cd my-cakephp-app
php qbixserver.php --root=webroot --preset=cakephp --port=8080
```

### CodeIgniter 4

CodeIgniter has the fastest baseline of the full-stack frameworks. Boot mode is limited by CI4's `is_cli()` detection under the CLI SAPI.

| | |
|---|---|
| **Preset** | `--preset=codeigniter` |
| **Document root** | `--root=public` |
| **Boot adapter** | Yes (limited — SAPI detection issues in boot mode) |
| **Benchmark** | [463 req/s baseline](BENCHMARKS.md#codeigniter-4) |

```bash
cd my-ci4-app
php qbixserver.php --root=public --preset=codeigniter --port=8080
```

### Yii 2

Extremely fast baseline. No-boot mode works well; boot mode is slower due to Yii's extensive static state causing COW page faults.

| | |
|---|---|
| **Preset** | `--preset=yii` |
| **Document root** | `--root=web` |
| **Boot adapter** | Yes (slower than no-boot due to static state) |
| **Benchmark** | [3,527 req/s baseline, 2,880 in no-boot](BENCHMARKS.md#yii-2) |

```bash
cd my-yii-app
php qbixserver.php --root=web --preset=yii --port=8080
```

### Mezzio (Laminas)

Lightweight PSR-15 middleware framework. Fast baseline, 1.5× improvement in no-boot mode.

| | |
|---|---|
| **Preset** | `--preset=mezzio` |
| **Document root** | `--root=public` |
| **Boot adapter** | Yes (Slim/Mezzio adapter) |
| **Benchmark** | [1,580 → 2,337 req/s (1.5× in no-boot mode)](BENCHMARKS.md#mezzio) |

```bash
cd my-mezzio-app
php qbixserver.php --root=public --preset=mezzio --port=8080
```

### Slim

PSR-15 micro-framework. Shares the Mezzio boot adapter.

| | |
|---|---|
| **Preset** | `--preset=slim` |
| **Document root** | `--root=public` |
| **Boot adapter** | Yes (Slim/Mezzio adapter) |

```bash
cd my-slim-app
php qbixserver.php --root=public --preset=slim --port=8080
```

### Joomla

Joomla ships `.htaccess` with front-controller rewrite rules. Sessions and headers are shimmed via the compat layer.

| | |
|---|---|
| **Preset** | `--preset=joomla` |
| **Document root** | `--root=.` |
| **Boot adapter** | Yes |

```bash
cd my-joomla-site
php qbixserver.php --root=. --preset=joomla --port=8080
```

### Magento

Magento 2 requires significant resources (1GB+ dependencies, database). The preset sets `memory_limit` to 756M and `max_execution_time` to 600s.

| | |
|---|---|
| **Preset** | `--preset=magento` |
| **Document root** | `--root=pub` |
| **Boot adapter** | Yes |

```bash
cd my-magento-site
php qbixserver.php --root=pub --preset=magento --port=8080
```

### Nextcloud / ownCloud

File-hosting platforms with heavy upload requirements. Presets set `upload_max_filesize` to 512M.

| | |
|---|---|
| **Preset** | `--preset=nextcloud` or `--preset=owncloud` |
| **Document root** | `--root=.` |
| **Boot adapter** | Yes (OwnCloud adapter) |

```bash
cd my-nextcloud
php qbixserver.php --root=. --preset=nextcloud --port=8080
```

### Qbix Platform

The server was built for the Qbix Platform. In `--app` mode, the Platform's own dispatcher handles routing and the compat rewriter has nothing to do for Platform code (it uses `Q_Response::header()` instead of the builtins). Third-party libraries included by the app are still rewritten.

```bash
php qbixserver.php --app=/path/to/myapp --port=8080
```

See [app-mode.md](app-mode.md) for details on `--app` mode, SAPI emulation, and class ownership.

### Any PHP app

Any PHP app with a front controller works without a preset. If the document root has an `index.php`, all clean URLs route to it automatically (same behavior as `try_files $uri $uri/ /index.php` in nginx). If there's a `.htaccess`, its `RewriteRule` and `RewriteCond` directives are applied.

```bash
php qbixserver.php --root=public --port=8080
```

## Preset reference

| Preset | Front controller | `upload_max_filesize` | `post_max_size` | `memory_limit` | `max_execution_time` |
|---|---|---|---|---|---|
| `laravel` | `index.php` | 10M | 12M | 256M | 60s |
| `symfony` | `index.php` | 10M | 12M | 256M | 60s |
| `wordpress` | `index.php` | 64M | 64M | 256M | 300s |
| `drupal` | `index.php` | 32M | 32M | 256M | 240s |
| `cakephp` | `index.php` | 10M | 12M | 256M | 60s |
| `codeigniter` | `index.php` | 10M | 12M | 256M | 60s |
| `yii` | `index.php` | 10M | 12M | 256M | 60s |
| `slim` | `index.php` | 10M | 12M | 128M | 30s |
| `mezzio` | `index.php` | 10M | 12M | 128M | 30s |
| `joomla` | `index.php` | 32M | 32M | 256M | 300s |
| `magento` | `index.php` | 32M | 32M | 756M | 600s |
| `nextcloud` | `index.php` | 512M | 512M | 512M | 300s |
| `owncloud` | `index.php` | 512M | 512M | 512M | 300s |

All presets can be overridden with a JSON config file. See [configuration.md](configuration.md) for details.

## Boot adapters

Boot adapters are the mechanism behind boot mode. Each adapter knows how to:

1. **Detect** the framework from the document root (checks for characteristic files like `artisan`, `bin/console`, `wp-load.php`)
2. **Boot** the framework once in the parent process (load autoloader, compile container, register routes)
3. **Handle** each request in a forked worker (create request/response objects, dispatch through the framework's router)

| Adapter | Framework | Detection | Fork strategy |
|---|---|---|---|
| Laravel | Laravel 8+ | `artisan` + `vendor/laravel/framework` | Persistent workers, snapshot restore |
| Symfony | Symfony 4+ | `bin/console` + `vendor/symfony/http-kernel` | Persistent workers, fresh kernel per request |
| WordPress | WordPress 5+ | `wp-load.php` | Fork per request (global state) |
| Drupal | Drupal 8+ | `core/includes/bootstrap.inc` | Fork per request |
| CakePHP | CakePHP 4+ | `bin/cake` + `vendor/cakephp/cakephp` | Persistent workers |
| CodeIgniter | CodeIgniter 4+ | `spark` + `vendor/codeigniter4/framework` | Fork per request (static state) |
| Yii | Yii 2 | `yii` + `vendor/yiisoft/yii2` | Fork per request (static state) |
| Mezzio | Slim 4 / Mezzio | PSR-15 `RequestHandlerInterface` | Persistent workers |
| Joomla | Joomla 4+ | `libraries/src/Application` | Fork per request |
| Magento | Magento 2 | `bin/magento` + `vendor/magento/framework` | Fork per request |
| OwnCloud | ownCloud / Nextcloud | `lib/base.php` | Fork per request |
| Custom | Any | User-specified callable | Configurable |

When no boot adapter matches, the server falls back to no-boot mode: the front controller is re-included on each request with the compat source transform active. This works for any PHP application.

---
[← Back to README](../README.md) | [Benchmarks →](BENCHMARKS.md#framework-benchmarks) | [Compatibility →](compatibility.md)
