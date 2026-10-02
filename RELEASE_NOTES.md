# Qbix Server v2.3 — Safe Collaboration

v2.1 made Qbix Server production-ready for frameworks. v2.3 makes it safe for multiple people — and AI assistants — to work on the same running app at the same time.

Each collaborator gets a copy-on-write branch: its own filesystem, its own cloned database, its own credentials. Branches are locked down by default so that even an untrusted contributor can only push CSS and HTML, while admins can relax restrictions per-branch or per-app. An MCP endpoint lets AI coding tools push changes through the same permission model. When work is ready, merge requests go through admin review before anything touches production.

## Collaborative Branching

The new `Branch` subsystem (3,100 lines) manages the full branch lifecycle: create, route, push, diff, export, merge, delete.

- **Copy-on-write filesystem**: branch directories use symlinks to trunk files. Writes create real copies in the branch directory; trunk files are never touched. Branch roots are created under a configurable base directory alongside the trunk.
- **Database cloning**: three adapters clone the app's database at branch-creation time.
  - **SQLite**: file copy. No credentials needed.
  - **MySQL/MariaDB**: `mysqldump --single-transaction` piped to `mysql`. Consistent snapshot without locking InnoDB tables.
  - **PostgreSQL**: `CREATE DATABASE ... TEMPLATE`. Atomic filesystem-level copy.
- **Branch routing**: requests reach branches by subdomain (`feature.myapp.test`), header (`X-Q-Branch`), or cookie (`_q_branch`).
- **Merge requests**: collaborators create merge requests with a title and description. Admins review and approve through the control panel API. On approval, branch files are copied to trunk.
- **Per-branch persistent workers**: the Pool routes requests to branch-specific workers in octane mode, with configurable `maxBranchWorkers` limits and worker stats.

## Default Lockdown

Branches and apps are locked down by default. The system uses a two-axis permission model:

**Branch permission** (view / edit / admin) controls who can see, push to, or configure a branch.

**File tier** (styles / markup / frontend / code) controls which file types a user can push:

| Tier | Allowed extensions |
|---|---|
| styles | .css, .scss, .less, .sass |
| markup | above + .html, .htm, .svg, .md, images, fonts, .json, .xml, .yaml |
| frontend | above + .js, .ts, .jsx, .tsx, .vue, .svelte |
| code | everything (null = no restriction) |

**Default branch settings:**
- File tier: `markup`
- Deny paths: `.env*`, `.git/`, `vendor/`, `node_modules/`
- Sandbox: shell execution disabled

The `code` tier bypasses deny-path checks — someone who can push PHP already has equivalent power.

### Lockdown Management API

Admins can adjust lockdown settings per-branch or per-app through the panel API:

- `GET /Q/panel/api/lockdown` — read branch lockdown config
- `POST /Q/panel/api/lockdown` — update file tier, deny paths, sandbox allowPaths
- `GET /Q/panel/api/branch-defaults` — read app-level defaults
- `POST /Q/panel/api/branch-defaults` — update app-level defaults

### Belt-and-Suspenders Isolation

On Linux, branches can optionally use OS-level UID/GID isolation: each branch gets its own system user, and the branch root is `chown`'d to that user. Combined with PHP-level `open_basedir`, this provides defense in depth — a PHP escape in one branch cannot read another branch's files. Off by default because it slows down fork-per-request mode.

## Patch-based Push with VCS Integration

The new `branch_patch` tool accepts a unified diff and applies it to a branch, with automatic VCS detection and commit support.

- **VCS fallback chain**: git → mercurial → `patch` command. On Windows, git or mercurial is required (no native `patch`).
- **Git mode**: initializes a git repo in the branch directory on first use. Applies patches with `git apply`. When a `commitMessage` is provided, stages and commits the changes, returning the commit hash.
- **Mercurial mode**: initializes an hg repo on first use. Applies with `hg import --no-commit`, then commits if a message is provided.
- **Patch mode**: applies with `patch -p1`. No commit history. If a commit message is specified, a note explains it was ignored.
- **Permission enforcement**: every file path in the diff is parsed and validated against the caller's file-permission tier and deny-path rules before anything is written. A patch that touches any denied path is rejected entirely.
- **CoW integration**: symlinks from trunk are resolved to real files before the patch is applied, so the trunk is never modified.

## MCP Integration

Each branch exposes a Model Context Protocol endpoint at `/Q/mcp/{appHost}/{branchName}`. AI coding assistants authenticate with a bearer token and use five tools:

| Tool | Purpose |
|---|---|
| `health` | Verify branch is accessible |
| `branch_export` | Download branch contents as structured JSON |
| `branch_push` | Push file changes (subject to file tier and deny paths) |
| `branch_patch` | Apply a unified diff with optional VCS commit |
| `branch_request_merge` | Create a merge request for admin review |

The MCP endpoint supports JSON-RPC 2.0 batching, CORS preflight, and proper error codes. Push and patch operations enforce the same permission checks as the panel API.

### LLM Discovery

The MCP `initialize` response now includes contextual instructions for AI assistants: which VCS is available, detected framework, file-permission model, and a hint to check for an `LLM.txt` file at the app root for app-specific guidance.

### AI-Assisted Editing on Public Servers

With v2.3, any Qbix Server exposed to the network is a workspace that AI coding assistants can safely edit. Claude, ChatGPT plugins, Cursor, Windsurf, or any MCP-compatible tool connects, authenticates with a scoped token, and works on a branch — never trunk. The server enforces permissions on every write; the AI cannot bypass file-tier or deny-path restrictions regardless of what it sends.

When git or mercurial is installed (standard on any production Linux server), each patch becomes a real commit. Admins reviewing a merge request see a proper commit log: what changed, why, and when. Because branches have real git repos, they can push to and pull from repos on other servers. This enables staging-to-production promotion across machines, distributed editing by multiple AI assistants across Qbix instances, and integration with CI services for automated testing before merge approval.

See [docs/COLLABORATION.md](docs/COLLABORATION.md) for the full workflow, permission model, security considerations, and multi-server patterns.

## Multi-User Auth

The control panel now supports multiple users with role-based access:

- **Owner**: created during initial setup, cannot be removed
- **Admin**: can manage branches, approve merges, configure lockdown
- **User**: can view and push to branches they have access to

Users authenticate via password and receive session tokens. Token-based auth works via `Authorization: Bearer`, `X-Panel-Token` header, or cookie.

## Test Suite

197 end-to-end assertions across 14 test sections:

1. Panel auth setup (12 assertions)
2. Multi-user management (13)
3. Branch management (12)
4. Claude MCP session simulation (19)
5. Admin merge review (12)
6. Database config (5)
7. Per-branch worker routing (18)
8. Branch lifecycle (8)
9. Edge cases and security (10)
10. Default lockdown (22)
11. Database cloning — SQLite + MariaDB + PostgreSQL (18)
12. Lockdown management API (17)
13. Deny path enforcement in push (10)
14. Patch-based push with VCS integration (19)

All tests pass on SQLite and MariaDB. PostgreSQL tests skip gracefully when the server is unavailable but are structurally validated.

## Upgrading from v2.1

No breaking changes. All v2.1 configuration, framework presets, and APIs are preserved. The branching subsystem is opt-in — it activates when you create the first branch through the panel API. The lockdown defaults apply only to new branches.

---

# Qbix Server v2.1.0 — Strange New Worlds

v2.0 was a mesh-networked runtime. v2.1 makes it production-ready: every major PHP framework runs unmodified, the control panel manages apps end-to-end, mobile apps can be built and distributed for iOS and Android from the panel, and nine security audit passes harden the entire stack.

The mesh layer is still there — every v2.0 feature works unchanged. v2.1 adds the framework compat layer, a rebuilt control panel, mobile build scaffolding, and a significant number of security fixes.

## Framework Compatibility

The compat source transform now handles output buffer protection, SAPI detection, and 27 shimmed PHP functions. Frameworks like Laravel, Symfony, WordPress, Drupal, CakePHP, Yii, and Mezzio run out of the box — no code changes, no plugins, no extensions.

```bash
# Drop a Laravel app in and go
php qbixserver.php --root=my-laravel-app/public --port=8080
```

### Source Transform

The rewriter intercepts calls that would break under Qbix's execution model:

- `ob_end_flush()`, `ob_end_clean()`, `ob_get_clean()`, `ob_get_level()` are shimmed so frameworks that drain output buffers in a loop don't infinite-loop against Qbix's protected buffer.
- `php_sapi_name()` returns `'cli-server'` instead of `'cli'`. The `PHP_SAPI` constant is replaced via a context-aware constant engine that skips qualified references like `SomeClass::PHP_SAPI`.
- All shimmed calls are emitted fully qualified (`\Q_WebServer_Compat::_header(...)`) so they resolve correctly inside namespaced framework code. The v2.0 rewriter omitted the leading backslash, which broke every Symfony, Laravel, and Drupal response.

### 13 Framework Presets

Built-in presets for Laravel, Symfony, WordPress, Drupal, CakePHP, CodeIgniter, Yii, Mezzio, Slim, Joomla, Magento, Nextcloud, and ownCloud. Each preset sets the front controller, upload limits, memory limits, and session settings appropriate for the framework.

### 12 Boot Adapters

Boot adapters automatically detect and bootstrap each framework. A Custom adapter allows user-specified boot callables.

### Performance

Benchmarked against PHP's built-in development server:

| Framework | php -S | Qbix Server | Speedup |
|---|---|---|---|
| Laravel | 190 req/s | 653 req/s | 3.4× |
| Drupal | 404 req/s | 1,636 req/s | 4.0× |
| CakePHP | 905 req/s | 1,790 req/s | 2.0× |
| Mezzio | 1,580 req/s | 2,337 req/s | 1.5× |

See [BENCHMARKS.md](docs/BENCHMARKS.md#framework-benchmarks) for full methodology.

## Control Panel

### App Management

Create, delete, start, stop, and configure apps from the panel. Domain assignment, SSL cert provisioning, and framework detection are integrated into the app creation flow. The panel also manages WordPress and Drupal plugin/module installation through their respective CLIs.

### Cache Clearing

Append `?Q.clearCache` to any URL to clear all caches — opcode, static file, precompression, and image — for that app. During development this replaces the cycle of clearing APC, restarting workers, and purging the image cache separately.

### Script Modules

The panel's JavaScript is organized into modules with a loader, replacing the inline scripts that grew to several thousand lines in v2.0. The module system loads only the code needed for the active tab.

## Mobile Build and Distribution

The control panel can scaffold, build, and package native iOS and Android apps.

### Scaffolding

Click **Prepare** with a platform selected and the panel generates a complete native project:

- **iOS**: Swift source files + `project.yml` for xcodegen. `PhpBridge.swift` starts PHP via `posix_spawn`, `TransportManager.swift` handles BLE/MC/LAN, `BackgroundKeepAlive.swift` maintains the silent audio session.
- **Android**: Kotlin source files + Gradle (Kotlin DSL). `PhpBridge.kt` extracts the binary from APK assets and runs it via `ProcessBuilder`, `TransportManager.kt` handles BLE/LAN, `QbixServerService.kt` runs the Foreground Service.

Both include the GATT service UUIDs, chunking protocol, and mesh handshake integration.

### Building

Click **Build** and the panel runs `xcodebuild` or `./gradlew assembleRelease`. CI produces `qbixserver-ios-arm64` and `qbixserver-android-arm64` micro binaries via static-php-cli, with Android NDK cross-compilation.

### Distribution

iOS apps are archived and uploaded to App Store Connect via the Xcode Organizer or `xcodebuild -exportArchive`. TestFlight handles beta distribution (25 internal testers without review, 10,000 external testers with review). Ad Hoc provisioning supports direct distribution to up to 100 registered devices.

Android apps are signed with a release keystore and uploaded to the Google Play Console as an AAB. The Play Console offers internal (100 testers, no review), closed (invite-only), and open testing tracks before production. Signed APKs can also be distributed directly for sideloading or alternative stores.

See [mobile/iOS.md](mobile/iOS.md) and [mobile/Android.md](mobile/Android.md) for full walkthroughs.

## Security Hardening

Nine audit passes reviewed Panel.php (~7,000 lines), TransportManager.swift (~860 lines), and TransportManager.kt (~680 lines). Each pass found progressively fewer issues — 7, then 3, then 1 — confirming convergence.

### XSS Prevention

All `innerHTML` assignments in the Nearby tab now escape API-returned data through `escHtml()`. Previously, a peer could set its mesh name to `<img onerror=...>` and have it rendered unsanitized in the control panel. Fixed in: mesh identity display, routing table rows, peer connection status, script selector options, attestation labels, and attestation signer fields.

### Path Traversal

`basename()` is applied to directory names from user input in `apiQbixNpm`, `apiFrameworkPkgDownload`, and framework package endpoints. Without this, a `target` value of `../../etc` could traverse outside the expected directory.

### Shell Injection

`escapeshellarg()` is applied to all user-supplied paths passed to shell commands. This covers the WordPress CLI path (`wp`) and Drupal CLI path (`drush`) in six locations across `apiFrameworkPackages`, `apiFrameworkPkgAction`, and `apiFrameworkRun`. A crafted CLI path like `/usr/bin/wp; rm -rf /` would previously execute the injected command.

### Authentication

`apiChangePassword` and `apiLogout` now check the `Authorization: Bearer` header in addition to `X-Panel-Token` and cookies, matching the existing `checkAuth` logic. API clients authenticating via Bearer header could previously not change their password or log out — the token lookup returned empty and the operation silently failed or invalidated the wrong session.

### BLE SSRF

Both the iOS and Android TransportManagers now reject BLE-received HTTP request paths that don't start with `/`. Without this check, a crafted BLE request could use the path as a `userinfo@host` trick — the HTTP client would interpret `user:pass@evil.com/path` as a request to `evil.com` — turning the local server into an open proxy reachable over Bluetooth.

```swift
// iOS fix
guard parsed.path.hasPrefix("/") else {
    sendBLEResponse("HTTP/1.1 400 Bad Request\r\n..."
        .data(using: .utf8)!, to: central)
    return
}
```

```kotlin
// Android fix
if (!path.startsWith("/")) return@submit
```

### MultipeerConnectivity Peer Identity

The iOS TransportManager now uses the peer's actual `mesh_id` from the ECDH handshake when relaying MultipeerConnectivity messages to PHP, instead of the MC `displayName`. The MC display name is an arbitrary string set by the remote device; the `mesh_id` is the cryptographic identity established during the handshake. Using the display name meant the PHP server couldn't match MC messages to the correct peer, breaking message routing for any peer whose display name didn't happen to match its mesh ID.

## Documentation

| Doc | What's new |
|---|---|
| [FRAMEWORKS.md](docs/FRAMEWORKS.md) | All 13 frameworks with presets, adapters, benchmarks |
| [BENCHMARKS.md](docs/BENCHMARKS.md) | Framework benchmark section |
| [mobile/README.md](mobile/README.md) | Transport layer, GATT protocol, platform requirements |
| [mobile/iOS.md](mobile/iOS.md) | Signing, TestFlight, App Store, Ad Hoc distribution |
| [mobile/Android.md](mobile/Android.md) | Keystore, Play Store, testing tracks, direct APK |
| [README.md](README.md) | Expanded Mobile section with build/distribute overview |

## Upgrading from v2.0

No breaking changes. All v2.0 configuration, APIs, and mesh behavior are preserved. The framework compat layer activates only when serving a framework that needs it. The security fixes apply automatically.
