# Security Architecture

This document covers the structural security properties of Qbix Server — what the server guarantees by design, what it does not guarantee, and why certain choices were made.

## RCE Containment

A Remote Code Execution (RCE) vulnerability in a PHP application typically means game over: the attacker can write a backdoor, overwrite configuration, or escalate to a persistent foothold. Qbix Server limits this damage structurally, without relying on application code being clean.

### How php-fpm fails

Under nginx + php-fpm, the PHP worker runs as a single user (e.g., `www-data`) that owns or can write to the application's files. An RCE that calls `file_put_contents('shell.php', '<?php system($_GET["cmd"]);')` persists across every future request. Even if you notice the breach and patch the original vulnerability, the attacker's code is already there.

Separately: php-fpm owns the fork. That means it cannot apply per-request UID sandboxing, per-listener trust boundaries, or OS-enforced isolation between requests. All of that has to live in userland — which is exactly where the RCE lives too.

### What Qbix Server does instead

Qbix Server owns the `pcntl_fork()` call. Before executing PHP for a request, the parent can:

1. Drop to a **worker UID** that has read+execute on `.php` files but no write permission anywhere in the source tree.
2. Limit writable paths to an explicit whitelist (`writablePaths` in the sandbox policy). By default the list is empty — the worker cannot write anywhere. Apps declare exactly which directories they need.
3. Enforce `.php`-only `require`. The Compat layer rewrites every `require`/`require_once`/`include`/`include_once` call to assert `realpath()` ends in `.php` before loading. This means an attacker cannot write malicious code to `uploads/payload.txt` and then `require` it — the rewriter refuses any path that doesn't end in `.php`. `.phar` archives are also excluded (a `.phar` is an executable archive and would defeat the purpose).

Together these mean: an RCE can do damage during one request — read data, make outbound connections, manipulate the response — but it cannot write new `.php` files (worker has no write permission on the source tree) and cannot load non-`.php` content as code (the rewriter blocks it). The breach is bounded to one request lifetime.

### The DAG root guarantee

CLI scripts — the ones that generate cached PHP files or compiled assets — run as a higher-privileged scheduler UID and are explicitly whitelisted. The source PHP files for those scripts are owned by the deploy UID, which the worker cannot write to. So the attack surface for "write a .php file to get persistence" has a fixed root set: the deploy user's files, changed only through a legitimate deploy or a scheduler job that passed authorization. RCE in a worker process cannot reach that root.

## .php-Only require

The `require` rewriter is not configurable. It is hardcoded because making it configurable would mean a misconfigured policy could re-open the hole. The rule is:

- `realpath($path)` must end in `.php`
- If it does not, the request throws immediately
- `.phar` is explicitly excluded (phar streams can execute arbitrary code on load)

This property holds even if an attacker compromises the sandbox policy config, because the rewriter runs before policy is consulted.

## Writable-Path Whitelist

By default, the worker UID cannot write to any directory. Write access is granted only through an explicit whitelist declared in the sandbox policy under the `writablePaths` key.

### Why whitelist instead of blacklist

A blacklist has to anticipate every path an attacker might reach — it fails open when a new upload directory is added, when a framework creates a temp directory in an unexpected location, or when a symlink redirects through a writable tree. A whitelist fails closed: if you forget to declare a path, the worker can't write there, and the app breaks loudly rather than silently becoming exploitable.

### Per-framework presets

`Sandbox::frameworkWritablePaths($framework)` returns the canonical writable directory set for each supported framework. Call it to populate `writablePaths` without guessing:

| Framework | Writable directories |
|-----------|----------------------|
| `qbix` (default) | `files/`, `tmp/`, `sessions/` |
| `wordpress` | `wp-content/uploads/`, `wp-content/cache/`, `wp-content/wflogs/`, `wp-content/upgrade/` |
| `drupal` | `sites/default/files/`, `sites/default/tmp/` |
| `laravel` | `storage/app/`, `storage/logs/`, `storage/framework/cache/`, `storage/framework/sessions/`, `storage/framework/views/`, `bootstrap/cache/` |
| `symfony` | `var/cache/`, `var/log/`, `var/sessions/`, `public/uploads/` |
| `joomla` | `images/`, `cache/`, `administrator/cache/`, `tmp/` |
| `static` | *(none — worker writes nothing)* |

The `qbix` preset is the default and most restrictive of the framework presets. `static` is the strictest of all — for fully static sites where the worker should never write anything.

### Startup enforcement

At startup, `Sandbox::applyFsPolicy()` runs before any application code executes. It:

1. Resolves relative `writablePaths` entries against the app root.
2. Validates that each declared path is actually under the app root (containment check — no `../../` escapes).
3. Verifies each declared path exists as a directory.
4. Scans the top-level directories under the app root and logs an error for any that are writable by the worker UID but not in the declared whitelist.

Warnings from step 4 mean the deployment is misconfigured — a directory is writable that the policy does not account for. This is surfaced as a startup error rather than silently allowing the write access.

### Monotonic merge

When policies are nested (listener floor + per-request overlay), `writablePaths` is merged by intersection: the result is only the paths that appear in both. A more-permissive overlay cannot expand the writable set beyond what the floor allows. This is the same monotonic-restriction principle that governs all other sandbox policy fields.

### App-declared sandbox defaults

An app can declare its own sandbox defaults in its `config/app.json` (or `local/app.json`, which takes precedence) under the top-level `sandbox` key:

```json
{
  "sandbox": {
    "writablePaths": ["files/", "tmp/", "sessions/"],
    "noNetwork": false
  }
}
```

These values are the **default floor** for that app — what the server would apply if no Panel override exists. The webserver reads this key when the Panel retrieves sandbox policy for that app (`GET /Q/api/sandbox/policy?app=myapp`) and surfaces it alongside the Panel-configured override.

### Policy precedence

Three layers combine to produce the effective sandbox policy for an app, from lowest to highest:

1. **App-declared default** — the `sandbox` key in the app's own `config/app.json` or `local/app.json`. Set by the app developer; describes what the app normally needs.
2. **Panel override** — stored in `local/panel.json` under `sandboxPolicy.<dirName>`. Set by authenticated Panel users (admins/owners). Can freely expand or narrow the app-declared default.
3. **Branch policy** (v3.x only) — applied at dispatch time. Can only narrow the policy (monotonic restriction): `writablePaths` is merged by intersection, so a branch can drop paths but never add them.

### Per-app policy storage

The Panel stores sandbox policy in `local/panel.json` keyed by app `dirName`:

```json
{
  "sandboxPolicy": {
    "myapp":    { "writablePaths": ["files/", "tmp/"], "noNetwork": false },
    "otherapp": { "writablePaths": [], "noNetwork": true }
  }
}
```

Each app's policy is independent. Editing one app's writable paths in Security → Sandbox Policies has no effect on any other app. The Panel UI requires you to select an app before reading or writing policy — there is no global default that silently applies to all apps. An app with no entry in `sandboxPolicy` gets an empty policy (no writable paths, network allowed by default).

## No System Cron

Qbix Server does not call `crontab`, write to `/etc/cron.d`, or depend on any system scheduler. The built-in **Scheduler** handles all time-triggered and on-demand job execution within the server process.

This is deliberate. System cron:

- Runs as a separate privilege context that doesn't participate in the server's UID model
- Has no awareness of the server's session authorization, sandbox policies, or writable-path restrictions
- Introduces a dependency on a system service outside the server's supervision tree

Instead, the server starts its Scheduler at startup and keeps it running. If the server isn't running, scheduled jobs don't run — which is the correct behavior, since those jobs depend on the server's authentication and authorization infrastructure to be meaningful.

**Server startup** is handled by the system init (systemd, launchd, etc.), not by cron. The server should be registered as a system service so it starts at boot and restarts on failure, the same as nginx, PostgreSQL, or any other long-running daemon. A watchdog process can be added for additional resilience, but that watchdog's job is to restart the server — not to substitute for it.

## What This Does Not Protect Against

- **Data exfiltration during a request**: a compromised worker can still read any file the worker UID can read and send it outbound. Limit `readablePaths` and restrict network access (via `noNetwork` policy or kernel-level rules) to reduce this.
- **Vulnerabilities in the server itself**: the parent process and pool manager run with higher privileges. A bug in request parsing or the pool dispatch path is more serious than a bug in application PHP.
- **Intentionally malicious apps deployed by an authorized user**: the UID model protects the server's own tree and isolates apps from each other, but it does not protect against a legitimate deployer who ships malicious code.
- **Symlink attacks**: if an attacker can create symlinks in a writable directory, they may be able to influence what `realpath()` resolves to. Writable directories should not allow symlink creation when possible.
