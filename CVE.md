# CVE-2026-109618 & CVE-2026-109619 (PHP2Shell)

**Severity:** Critical — CVSS v4.0 9.2 (unauthenticated remote code execution)
**Affected:** PHP 8.0–8.5 (all maintained branches prior to Oct 21 2026 patch)
**Fixed in PHP:** 8.2.35, 8.3.36, 8.4.27, 8.5.12 (released Oct 21 2026)
**Qbix Server fix:** 3.4.1 (this release) — structural mitigation, no PHP upgrade required

## What the vulnerabilities are

Both CVEs are heap use-after-free bugs in PHP's C runtime triggered by crafted `multipart/form-data` POST requests.

CVE-2026-109618 is in `php_session_rfc1867_callback` in `ext/session/session.c`. This callback is registered by the session extension to track `session.upload_progress` as a multipart body is parsed. A specially constructed multipart body can trigger a use-after-free in that callback, leading to arbitrary code execution in the PHP worker process.

CVE-2026-109619 is in the multipart parser itself. It fires on any `multipart/form-data` POST regardless of whether sessions or upload-progress are in use.

Both run inside the PHP runtime before any application PHP code executes. No login check, no middleware, no WordPress plugin, no Laravel guard can intercept them — they fire during SAPI request setup.

## Why standard php-fpm setups are fully exposed

In a conventional nginx + php-fpm deployment:

- nginx forwards the raw request body to php-fpm over FastCGI
- php-fpm's SAPI startup hooks register `php_session_rfc1867_callback`
- PHP's own multipart parser processes the raw attacker-controlled bytes
- The use-after-free fires, the process is exploited
- Application code has not executed yet

The only reliable fix in this setup is upgrading PHP. WAF signature rules help for known payloads but offer no structural guarantee.

## Why Qbix Server is structurally protected

Qbix Server runs as **CLI SAPI**, not FPM. This changes the threat model in two independent ways.

**1. CLI SAPI does not register the upload-progress callback.**

`php_session_rfc1867_callback` is registered as an `rfc1867_post_handler` during web SAPI startup. CLI SAPI does not perform this registration. The callback does not exist in the worker process, so CVE-2026-109618's code path cannot execute.

**2. The raw multipart body never reaches PHP's parser.**

Qbix Server parses `multipart/form-data` in the parent process using its own parser, then populates `$_POST` and `$_FILES` from the parsed, validated representation before the PHP worker script is included. The PHP worker never sees the attacker's raw bytes. PHP's built-in multipart parser is redundant and unused.

**3. Explicit ini hardening added in 3.4.1 (defense in depth).**

Even if a future vulnerability or configuration edge case were to engage PHP's body-reading machinery, `Pool.php` now explicitly disables both attack surfaces immediately before each script include:

```php
@ini_set('enable_post_data_reading',        '0');
@ini_set('session.upload_progress.enabled', '0');
```

`enable_post_data_reading = 0` instructs PHP to skip populating `$_POST` and `$_FILES` from the request body entirely — the multipart parser never runs. `session.upload_progress.enabled = 0` removes the rfc1867 callback registration for that request. Together they eliminate both CVE paths at the ini layer, redundantly with the structural protections above.

This is possible in Qbix Server's architecture because the server controls the `include()` call site. In standard php-fpm, PHP's body-reading runs before any userland code and cannot be intercepted this way.

## What this means for existing Qbix Server deployments

Deployments running any version of Qbix Server prior to 3.4.1 are protected by points 1 and 2 above — the structural CLI SAPI and multipart re-serialization protections — regardless of PHP version. The 3.4.1 release adds the explicit ini hardening as an additional layer.

You do not need to upgrade PHP to be protected when running Qbix Server. Upgrading PHP is still recommended for any PHP code running outside Qbix Server's request pipeline (cron jobs, CLI scripts, etc.).

## Recommended actions

| Scenario | Action |
|---|---|
| Running Qbix Server 3.4.1+ | No further action required for web traffic |
| Running Qbix Server < 3.4.1 | Update to 3.4.1; structural protection already present |
| PHP used outside Qbix Server (CLI scripts, cron) | Upgrade PHP to a patched release |
| Cannot upgrade PHP immediately | Set `session.upload_progress.enabled = Off` in php.ini as interim measure; or deploy [Qbix Proxy](https://github.com/Qbix/proxy) in front of your stack |
| Running nginx + php-fpm, cannot migrate yet | Deploy [Qbix Proxy](https://github.com/Qbix/proxy) as a drop-in layer to mitigate this and other PHP-level vulnerabilities without changing your application |

## Using Qbix Server as a drop-in replacement for nginx + php-fpm

Qbix Server can replace nginx + php-fpm as the HTTP frontend and PHP execution environment. In this configuration, all HTTP traffic — including multipart POST requests — is handled by Qbix Server's own parser before PHP executes. PHP2Shell and future parser-class RCEs in PHP's C runtime do not reach the execution path.

The architecture shift is: instead of a WAF pattern-matching against known payloads (which requires a new rule per CVE), Qbix Server re-serializes the request from its own parsed representation. An attack payload that relies on malformed or adversarial raw bytes is gone before PHP sees anything.

## If you cannot switch to Qbix Server right now

For deployments that cannot migrate immediately, the open-source [Qbix Proxy](https://github.com/Qbix/proxy) can be placed in front of an existing nginx + php-fpm setup. It applies the same request re-serialization strategy — parsing and re-emitting multipart bodies before they reach PHP — providing structural mitigation for PHP2Shell and a general defensive layer against future parser-class vulnerabilities, without requiring changes to your application code.

## References

- [PHP2Shell advisory (cve.tools)](https://cve.tools/blog/php2shell-cve-2026-109618-cve-2026-109619)
- [Security Affairs writeup](https://securityaffairs.com/201403/security/php-prepares-emergency-fixes-for-two-critical-remote-code-execution-flaws.html)
- [Phoenix Security technical analysis](https://phoenix.security/php-core-rce-cve-2026-109618-109619-critical-remote-code-execution/)
- [GitHub one-line mitigation (session.upload_progress)](https://github.com/ersinkoc/php-fix)
