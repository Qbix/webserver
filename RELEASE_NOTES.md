# Qbix Server Release Notes

## 2.5 — 2026-10-21

### Security: PHP2Shell mitigation (CVE-2026-109618 / CVE-2026-109619)

PHP announced two critical heap use-after-free CVEs (CVSS v4.0 9.2) in its multipart parser and session upload-progress handler, affecting PHP 8.0–8.5 prior to the Oct 21 2026 patch releases. In standard nginx + php-fpm deployments the vulnerabilities fire before any application code runs and cannot be blocked in userland.

Qbix Server is structurally protected by two architectural properties that exist in all prior releases: it runs as CLI SAPI (not FPM, so the upload-progress callback is never registered), and it parses multipart bodies itself in the parent process before any PHP worker script is included (so PHP's built-in parser never sees raw attacker bytes).

2.5 adds explicit defense-in-depth hardening: `Pool.php` now calls `ini_set('enable_post_data_reading', '0')` and `ini_set('session.upload_progress.enabled', '0')` immediately before each script include. This is possible because Qbix Server owns the `include()` call site — unlike php-fpm, where PHP's body-reading runs before any userland code can intervene.

See [CVE.md](CVE.md) for full technical analysis, including why Qbix Server can serve as a drop-in replacement for nginx + php-fpm to mitigate PHP2Shell without a PHP upgrade. For deployments that cannot migrate immediately, the open-source [Qbix Proxy](https://github.com/Qbix/proxy) can be placed in front of an existing PHP stack.

**No PHP upgrade is required for web traffic handled by Qbix Server.** Upgrading PHP is still recommended for CLI scripts and cron jobs running outside the Qbix request pipeline.

### Changes

- `src/Q/WebServer/Pool.php`: added `@ini_set('enable_post_data_reading', '0')` and `@ini_set('session.upload_progress.enabled', '0')` before each `include($req['scriptFilename'])` call, guarded with a comment referencing the CVEs.
- `CVE.md`: new file documenting the vulnerabilities, why Qbix Server is structurally protected, recommended actions for all deployment scenarios, and a pointer to Qbix Proxy for setups that cannot migrate immediately.
- `README.md`: added security callout near the comparison table linking to CVE.md.
- Added `src/Q/WebServer/RelayRoute.php`, `WakeRoute.php`, `RelayRoom.php`, `MCP.php`, `Extension.php`, `SafePHP.php`, `SafePHP/*`, `ClientMetrics.php`, `ClientMetrics/*`, and `src/Q/Relay/Db.php` — classes that `WebServer.php` references but were missing from the 2.x source tree.
- `.github/workflows/release.yml`: stream-wrappers regression test gated to Linux only (macOS skipped until the protection mechanism is ported).

---

## 2.4

Previous 2.x release.
