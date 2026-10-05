# Qbix Server: Collaborative Branches Plan

This document describes how the Qbix webserver will support branch-based collaboration on PHP applications. A branch is a copy-on-write clone of a running app — its own filesystem tree, its own database, its own credentials — that multiple people can work on independently, with changes merged back to the original when ready.

The design works across frameworks (Laravel, WordPress, Drupal, Qbix, Symfony, CakePHP, Yii, etc.) by using the webserver's existing source-transform wrapper and framework presets, so applications do not need modification.

## 1. What a branch contains

A branch consists of three things:

- **Filesystem**: a Q_Branch copy-on-write directory tree. Symlinks point to the original ("trunk") files. When a branch writes a file, Q_Branch creates a real copy in the branch directory; subsequent reads go to the copy instead of the symlink. The trunk files remain untouched.

- **Database**: a cloned copy of the app's database. The clone is made at branch-creation time and is fully independent afterward. Writes in the branch hit the cloned database, not the original.

- **Credentials**: the branch's database name (and optionally user/password) are injected at include-time via the Compat source-transform wrapper, so the framework reads branched credentials without knowing anything changed.

## 2. Branch lifecycle

1. **Create**: an operator (via control panel or API) requests a branch of app X. The server clones the filesystem via Q_Branch, clones the database, assigns a uid, and registers the branch in its state file.

2. **Route**: requests are routed to the branch by subdomain (`branch-xyz.myapp.example.com`) or by cookie/header (`X-Q-Branch: xyz`). The server checks subdomain first, falls back to cookie/header.

3. **Work**: collaborators make changes — editing files in the CoW tree, writing data through the app's UI. All changes are isolated to the branch.

4. **Preview**: the branch is a fully functional copy of the app, so it can be previewed at its subdomain or via the branch cookie.

5. **Merge**: changes are merged back to the trunk. This is where M-of-N signoff applies (designed separately).

6. **Delete**: the CoW directory is removed, the cloned database is dropped, the uid is released.

## 3. Database cloning

The server needs admin-level database credentials to create clones. These are configured once at the server level, not per-app:

```json
{
  "Q": { "webserver": { "branches": {
    "db": {
      "adapter": "mysql",
      "host": "localhost",
      "user": "qbix_admin",
      "password": "..."
    }
  }}}
}
```

Cloning mechanics by adapter:

- **MySQL**: `CREATE DATABASE branch_db` followed by `mysqldump --single-transaction original_db | mysql branch_db`. The `--single-transaction` flag gives a consistent snapshot without locking InnoDB tables. The admin user needs CREATE, DROP, and SELECT privileges on all app databases. Takes seconds for small databases, minutes for large ones.

- **Postgres**: `CREATE DATABASE branch_db TEMPLATE original_db`. This is atomic and fast — PostgreSQL does a filesystem-level copy of the template database's directory. Requires CREATEDB privilege. Briefly blocks new connections to the template database during the copy.

- **SQLite**: `copy('original.db', 'branch.db')` in PHP. No credentials needed. The server detects the SQLite file path from the framework's config (via the preset) and copies it into the branch's CoW directory.

The branch database reuses the app's existing database user — the server just changes the database name in the injected credentials. This avoids needing GRANT privileges. If the admin wants per-branch database users for tighter isolation, they can configure that, but it is not the default.

## 4. Credential injection via source transform

The Compat layer's `CompatFileWrapper` already intercepts every `include`, `require`, and `file_get_contents` call via the `file://` stream wrapper. For a branch, the wrapper detects framework config files and patches credentials in the returned source before the framework parses it.

Each framework preset specifies which files contain database credentials and how they are structured:

| Framework | Config file | Mechanism |
|-----------|------------|-----------|
| WordPress | `wp-config.php` | `define('DB_NAME', '...')` — token-replace the string literal |
| Laravel | `.env` | `DB_DATABASE=...` — line replacement in the env file content |
| Symfony | `.env` | `DATABASE_URL=...` — rewrite the DSN |
| Drupal | `settings.php` | `$databases['default']['default']['database'] = '...'` — token-replace |
| CakePHP | `config/app_local.php` | Array value replacement in returned PHP |
| Yii | `config/db.php` | Array value replacement in returned PHP |
| Qbix | `local/app.json` | JSON patch before returning file content |

The wrapper applies credential injection only when all of these conditions are met:

1. The current request is routed to a branch (determined during vhost resolution).
2. The file being read matches the preset's config-file pattern.
3. The branch has credential overrides registered (set during branch creation).

The injection is read-only — the original file on disk is never modified. The framework sees patched content in memory and proceeds as normal.

For non-PHP config files (`.env`, `.json`), the same stream wrapper intercepts `file_get_contents` and `fopen` calls. The Compat layer already handles this for other purposes (the `file://` wrapper is registered globally).

### Cache directories

Frameworks cache compiled views and config as PHP files. Each branch needs its own cache directory so cached files don't collide. The preset specifies the cache directory path, and the wrapper rewrites the cache path config to point to the branch's own directory:

| Framework | Cache path config | Default directory |
|-----------|------------------|-------------------|
| Laravel | `config/cache.php`, `CACHE_PATH` env | `storage/framework/` |
| WordPress | `WP_CONTENT_DIR` define | `wp-content/cache/` |
| Symfony | `var/cache/` (kernel config) | `var/cache/` |
| Drupal | `sites/default/files/` | `sites/default/files/` |
| Qbix | `Q/cache` config | `local/cache/` |

## 5. Per-branch OS isolation

Every branch gets its own Unix uid. This provides kernel-enforced isolation that cannot be bypassed by PHP code, extensions, or FFI.

### UID assignment

The server maintains a mapping file (`data/sandbox-uids.json`) that assigns sequential uids from a configurable range:

```json
{
  "Q": { "webserver": { "sandbox": {
    "uidBase": 60000,
    "uidRange": 5000
  }}}
}
```

Assignment is first-come-first-served: the first app gets uid 60000, its first branch gets 60001, the second branch gets 60002, and so on. The mapping file persists assignments across server restarts. UIDs do not need to correspond to named Unix users — Linux enforces filesystem permissions by numeric uid regardless.

An explicit `sandbox.uid` in the per-host config overrides the automatic assignment for that app's trunk. Branches of that app still get auto-assigned uids.

### File ownership

- **Trunk files**: owned by the app's trunk uid, mode 0644 (world-readable, owner-writable). Branch workers can read these files (via symlinks) but cannot modify them.

- **Branch CoW root**: owned by the branch uid, writable. New files and CoW copies created by the branch worker are owned by the branch uid.

- **Other branches' directories**: inaccessible to each branch worker via `open_basedir` restriction. Even if a branch worker somehow knew the path, it could not read or write files owned by a different uid.

### Process flow

1. Server starts as root, calls `Q_WebServer_Sandbox::detectCapabilities()`.
2. Request arrives, vhost resolution identifies the host and branch.
3. Server looks up (or assigns) the branch uid from the mapping file.
4. Server forks. Child calls `posix_setgid(branch_uid)` then `posix_setuid(branch_uid)`.
5. Child sets `open_basedir` to `branch_root:tmp_dir:allowed_paths`.
6. Child runs the application code. All file operations are constrained by both `open_basedir` (PHP level) and the uid (OS level).

When the server does not run as root (or on Windows), the OS isolation layer is unavailable. The PHP-level restrictions (`open_basedir`, shell-function gate, write blocking) still apply. Admins can selectively disable any of these per app via the host config.

## 6. Write restrictions

### No PHP self-modification

A sandboxed branch cannot write files with executable PHP extensions (`.php`, `.phtml`, `.phar`, `.inc`, `.php3`–`.php7`, `.phps`). The Compat stream wrapper intercepts `stream_open` in write mode, `rename`, `unlink` on PHP files, and denies the operation.

**Exception**: the preset's declared cache directories. Frameworks like Laravel compile Blade templates to PHP files in `storage/framework/views/`. The stream wrapper allows PHP writes only to paths under `<branch_root>/<preset_cache_dir>`. These cache files are generated from templates, ephemeral, and isolated to the branch.

### No shell execution

Shell functions (`exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_open`) are blocked by the Compat source-transform shims (already implemented in v2.2). The shims check `Q_WebServer_Sandbox::$shellAllowed` and throw `RuntimeException` when sandboxed.

### Config files are read-only

Credential injection is read-only — the wrapper patches content in memory on read, but the files on disk are never writable by the branch worker. Trunk files are owned by a different uid (owner-writable only), and the branch worker's uid cannot modify them.

## 7. Sandbox config and overrides

The default sandbox for branches is restrictive. Admins can relax specific restrictions per app in the host config:

```json
{
  "Q": { "webserver": { "hosts": { "myapp.example.com": {
    "root": "/var/www/myapp/public",
    "sandbox": {
      "enabled": true,
      "allowShell": true,
      "allowPhpWrite": true,
      "allowPaths": ["/var/shared/libs", "/usr/share/fonts"],
      "uid": 1050
    }
  }}}}
}
```

| Option | Default | Effect |
|--------|---------|--------|
| `enabled` | falsy | Sandbox is off unless explicitly enabled. When branching, the server enables it automatically for branches even if the trunk has it off. |
| `allowShell` | falsy | When truthy, shell functions are permitted. |
| `allowPhpWrite` | falsy | When truthy, writing `.php` files is permitted anywhere, not just cache dirs. |
| `allowPaths` | `[]` | Additional paths to include in `open_basedir` beyond the app/branch root and temp. |
| `uid` | auto | Override the auto-assigned uid for this app's trunk. |
| `noSetuid` | falsy | When truthy, skip `posix_setuid` even when available (run under the server's uid). |

Branches inherit their trunk app's sandbox config as a base, with per-branch overrides possible via the branch metadata in the state file.

## 8. Routing

Two mechanisms, checked in order:

### Subdomain routing

The server recognizes branch subdomains by pattern: `<branch>.<app-host>`. For example, `feature-login.myapp.example.com` routes to branch `feature-login` of `myapp.example.com`.

This requires wildcard DNS (`*.myapp.example.com → server IP`) and a wildcard TLS certificate (the server's ACME integration already supports this). Subdomain routing is the cleanest option for production-like preview URLs that can be shared externally.

### Cookie/header routing

The request includes a `X-Q-Branch` header or a `_q_branch` cookie naming the branch. The control panel sets this cookie when a user switches to a branch view.

This works without any DNS changes and is suitable for development workflows where the collaborator accesses the app through the control panel.

### Resolution flow

During vhost resolution in `handleRequest()`:

1. Parse the `Host` header. Check if it matches a known app host directly — if so, no branch, serve the trunk.
2. Check if the host is a subdomain of a known app host and the subdomain matches a registered branch name — if so, set `$branchName` and resolve rootDir to the branch's CoW root.
3. If no subdomain match, check `X-Q-Branch` header and `_q_branch` cookie — if either names a registered branch of the resolved app, set `$branchName` and switch rootDir.
4. Store `$branchName` in the parsed request so downstream code (sandbox, credential injection) can reference it.

## 9. Access control

Branch access control lives in the webserver layer, before the request reaches the application. The app does not know it is on a branch and does not participate in branch auth.

### Two-axis permission model

Collaborator permissions have two independent axes:

1. **Branch permission** — what the user can do structurally:
   - **`view`**: can browse the branch (the app renders normally with branched data). Cannot modify files via the panel's file explorer.
   - **`edit`**: can browse and modify files in the branch's CoW tree through the panel. The app itself may also allow data writes through its own UI (forms, uploads, etc.) — this is fine because writes go to the branched database and branched filesystem. Which files the user can write is governed by the file permission (see below).
   - **`admin`**: can merge, delete, and manage the branch's access list. Implies full file access (`code` tier) — admins bypass the file permission check.
   - **`null` (or absent)**: no access. Requests from unauthenticated users to a branch URL get a 403 or a redirect to the panel login.

2. **File permission** — what file types the user can write (only meaningful when branch permission is `edit`):
   - **`styles`**: `.css`, `.scss`, `.less`, `.sass` files only.
   - **`markup`**: above, plus `.html`, `.htm`, `.svg`, `.md`, framework template files (per preset), images, fonts, and other static assets.
   - **`frontend`**: above, plus `.js`, `.ts`, `.jsx`, `.tsx`, `.vue`, `.svelte`, `.mjs`, `.cjs`.
   - **`code`**: above, plus `.php` in preset-allowed folders.

Each tier is a superset of the previous. A user with `edit` branch permission and no explicit file permission defaults to `styles` (most restrictive), so existing configs don't accidentally grant more than intended.

### Access list format

The access list supports a short string form for simple cases and an object form for file permission and path overrides:

```json
{
  "access": {
    "carol": "admin",
    "alice": { "branch": "edit", "files": "frontend" },
    "bob": { "branch": "edit", "files": "styles" },
    "dave": "view",
    "*": null
  }
}
```

Short form mappings: `"view"` → view-only, `"admin"` → admin with full file access, `"edit"` → edit with `styles` file permission, `"edit:markup"` / `"edit:frontend"` / `"edit:code"` → edit with the named file tier.

The control panel displays this as a collaborator list:

```
carol    Admin     (full access)
alice    Can edit  ▸ Frontend files
bob      Can edit  ▸ Styles only
dave     Can view
```

### Authentication

Branch access is checked using the same auth mechanisms the control panel already uses:

- Panel session cookie (the user is logged into the webserver's control panel)
- Bearer token in the `Authorization` header (for API/programmatic access)
- Basic auth (for simple shared-password scenarios — the branch can have a password set)

The server checks auth before forking the branch worker. If auth fails, the server returns 403 without ever executing application code. The authenticated user's file permission tier is stored in the parsed request so the CompatFileWrapper can enforce it during the request.

## 10. File permission enforcement

The CompatFileWrapper already intercepts every file write (stream_open in write mode, rename, unlink, copy). The file permission system extends this to check the authenticated user's tier against the file being written.

### Tier check

On every intercepted write, the wrapper:

1. Determines the file's required tier by extension (`.css` → `styles`, `.html` → `markup`, `.js` → `frontend`, `.php` → `code`).
2. For `.php` files, checks the path against the preset's template declarations — a `.php` file in a template directory (e.g. WordPress theme, Qbix views/) is treated as `markup`, not `code`.
3. Compares the file's required tier against the user's tier. If the user's tier is lower, the write is denied with a clear error message.

### Preset tier paths

Each framework preset declares default path restrictions per tier. These protect sensitive paths regardless of file extension — a `.env` file is plain text but should not be writable at the `styles` or `markup` tier:

```json
{
  "preset": "laravel",
  "tierPaths": {
    "styles": {
      "deny": [".env", "config/**", "storage/**", "bootstrap/**"]
    },
    "markup": {
      "deny": [".env", "config/database.php", "config/app.php", "bootstrap/**"]
    },
    "frontend": {
      "deny": [".env", "config/database.php"]
    },
    "code": {
      "deny": [".env"]
    }
  },
  "templatePaths": [
    "resources/views/**/*.blade.php"
  ]
}
```

```json
{
  "preset": "wordpress",
  "tierPaths": {
    "styles": {
      "deny": ["wp-config.php", "wp-includes/**", "wp-admin/**"]
    },
    "markup": {
      "deny": ["wp-config.php", "wp-includes/**", "wp-admin/**", "wp-content/plugins/**/*.php"]
    },
    "frontend": {
      "deny": ["wp-config.php", "wp-includes/**", "wp-admin/**"]
    },
    "code": {
      "deny": ["wp-config.php"]
    }
  },
  "templatePaths": [
    "wp-content/themes/**/*.php"
  ]
}
```

```json
{
  "preset": "qbix",
  "configFormat": "json",
  "configFiles": ["local/app.json", "config/**/*.json"],
  "tierPaths": {
    "styles": {
      "deny": ["local/**", "config/**"]
    },
    "markup": {
      "deny": ["local/app.json", "config/Q/database.json"],
      "allow": ["config/Q/theme.json", "config/Q/menus.json"]
    },
    "frontend": {
      "deny": ["local/app.json"]
    }
  },
  "tierConfig": {
    "styles": {
      "allow": ["Q.theme.colors", "Q.theme.fonts"]
    },
    "markup": {
      "allow": ["Q.theme", "Q.menus", "Q.text"],
      "deny": ["Q.database", "Q.webserver"]
    },
    "frontend": {
      "allow": ["Q.theme", "Q.menus", "Q.text", "Q.routes"],
      "deny": ["Q.database"]
    }
  },
  "templatePaths": [
    "views/**/*.php"
  ]
}
```

### Per-user path overrides

When a specific user needs access beyond (or below) what their tier provides, the access entry can include a `paths` object:

```json
{
  "alice": {
    "branch": "edit",
    "files": "markup",
    "paths": {
      "allow": ["config/theme/**", "config/menus/**"],
      "deny": ["config/db.*"]
    }
  }
}
```

Patterns use glob syntax (`**` for recursive, `*` for single-level) and paths are relative to the branch root.

### Evaluation order

For each intercepted write:

1. **Extension tier check** — deny if the file's extension requires a higher tier than the user has.
2. **Preset `tierPaths[userTier].deny`** — deny if the path matches a preset-level denial for this tier.
3. **User `paths.deny`** — deny if the path matches a user-level denial.
4. **User `paths.allow`** — allow if the path matches a user-level allow (overrides preset deny, but never overrides user deny).
5. **Preset `tierPaths[userTier].allow`** — allow if the path matches a preset-level allow for this tier.
6. **Fall through** to the tier's default behavior (allow if extension is within tier, deny otherwise).

Deny always wins over allow at the same level. User-level deny cannot be overridden by user-level allow. This prevents mistakes where a broad allow accidentally opens a sensitive path.

### Config-key permissions

File-level gating controls which files a user can write. Config-key permissions go one level deeper: they control which keys inside a config file a user can change. This matters because a single config file can contain both innocuous settings (theme colors) and sensitive ones (database credentials).

Config-key permissions are available for frameworks that use structured, parseable config formats (JSON, YAML). For frameworks whose config lives in PHP files (Laravel, WordPress, Drupal), the wrapper cannot reliably parse arbitrary PHP expressions to diff individual keys, so config gating falls back to file-level `tierPaths`.

**Supported config formats:**

| Format | Frameworks | Key-level gating |
|--------|-----------|-----------------|
| JSON | Qbix | Yes — parse, diff keys, check permissions |
| YAML | Symfony | Yes — parse, diff keys, check permissions |
| PHP arrays | Laravel, CakePHP, Yii | No — file-level gating only via `tierPaths` |
| PHP defines | WordPress | No — file-level gating only |
| PHP mixed | Drupal | No — file-level gating only |

**Preset `tierConfig`:**

Each preset with a structured config format declares which config-tree paths each tier can modify. Paths use dot-notation matching the config tree's key hierarchy:

```json
{
  "preset": "qbix",
  "configFormat": "json",
  "configFiles": ["local/app.json", "config/**/*.json"],
  "tierConfig": {
    "styles": {
      "allow": ["Q.theme.colors", "Q.theme.fonts"]
    },
    "markup": {
      "allow": ["Q.theme", "Q.menus", "Q.text"],
      "deny": ["Q.database", "Q.webserver"]
    },
    "frontend": {
      "allow": ["Q.theme", "Q.menus", "Q.text", "Q.routes"],
      "deny": ["Q.database"]
    }
  }
}
```

```json
{
  "preset": "symfony",
  "configFormat": "yaml",
  "configFiles": ["config/**/*.yaml", "config/**/*.yml"],
  "tierConfig": {
    "styles": {
      "allow": ["twig.globals.theme"]
    },
    "markup": {
      "allow": ["twig", "framework.router"],
      "deny": ["doctrine", "framework.secret", "security"]
    },
    "frontend": {
      "allow": ["twig", "framework.router", "framework.assets"],
      "deny": ["doctrine.dbal", "framework.secret"]
    }
  }
}
```

A config path like `Q.theme` matches that key and everything under it (`Q.theme.colors`, `Q.theme.fonts.heading`, etc.). More specific paths take precedence: if `Q.theme` is allowed but `Q.theme.secret` is denied, the denial wins for that subtree.

**Per-user config overrides:**

The access entry can include a `config` object alongside `paths`:

```json
{
  "alice": {
    "branch": "edit",
    "files": "markup",
    "paths": {
      "allow": ["config/theme/**"]
    },
    "config": {
      "allow": ["Q.payments.display"],
      "deny": ["Q.payments.credentials"]
    }
  }
}
```

**Enforcement:**

When the wrapper intercepts a write to a file matching the preset's `configFiles` pattern, and the preset declares a `configFormat`:

1. Parse the original file content and the new content using the appropriate parser (JSON or YAML).
2. Diff the two parsed trees to find which keys changed, were added, or were removed.
3. For each changed key, check whether the key path is allowed for the user's tier by evaluating `tierConfig`, then per-user `config` overrides. Same deny-wins-over-allow logic as file path evaluation.
4. If any changed key is denied, reject the entire write and report which keys are not permitted.

For the control panel's file explorer, config files with key-level gating can optionally render as a structured editor (key-value form) rather than raw text, with denied keys shown as read-only. This is a UI enhancement for later phases.

### Control panel integration

The panel's file explorer respects file permissions: files the user cannot write are shown as read-only (greyed out edit controls, no upload button for restricted directories). The panel API checks the same tier and path rules before accepting a file save or upload. For config files with key-level gating, the panel can optionally show a structured editor with denied keys greyed out.

## 11. AI collaboration API

The branch management system is exposed via MCP tools and OpenAPI endpoints so AI agents (Claude, ChatGPT GPT Actions, or any MCP/REST consumer) can work on branches programmatically. The server already bridges MCP tools to HTTP handlers, so each operation is implemented once and served both ways. Discovery is already in place: `/.well-known/mcp.json` and `/mcp` (MCP streamable HTTP transport) for MCP-aware agents, `/.well-known/openapi.json` for GPT Actions and REST consumers. No directory listing is required — both Claude and ChatGPT support pointing directly at a server URL.

### Three-step workflow

**Step 1 — `branch/export` (grab source with credential placeholders)**

The agent calls this with an app host and optionally a branch name (omit for trunk). The server:

1. Builds a file tree respecting the caller's file-permission tier — a `frontend`-tier collaborator does not get `.php` files outside template dirs. Files outside the caller's tier are omitted from the archive entirely.
2. Scrubs credentials from config files using a layered detection strategy (see credential scrubbing below).
3. Attaches a manifest containing: framework name and version (from the preset), PHP version, preset name, a list of all placeholders so the agent knows what to fill for local testing, and install instructions (framework-specific, from the preset).
4. Returns the archive as a time-limited, single-use download URL.

**Step 2 — `branch/push` (push changes to a branch)**

The agent uploads a diff or full file tree. The server:

1. Validates every changed file against the caller's file-permission tier (same enforcement as the control panel file explorer — extension check, preset tierPaths, per-user overrides).
2. For structured config files, validates changed config keys against tierConfig.
3. Applies the changes to the branch's CoW overlay.
4. Returns a summary: files accepted, files rejected with reasons, and the branch's preview URL.

**Step 3 — `branch/request-merge` (notify admins)**

The agent calls this with a branch name and an optional description. The server:

1. Validates the caller has at least `edit` branch permission.
2. Creates a merge request record in the branch metadata.
3. Notifies app admins via configured channels (email, Streams notification, webhook).
4. Returns the merge request ID and status.

### Credential scrubbing

When exporting source for an AI agent, credential values must be replaced with named placeholders (`{{Q.database.host}}`, `{{DB_PASSWORD}}`, etc.) so secrets are never transmitted. Detection uses four layers evaluated in order:

1. **Config-key matching (primary)** — the preset's sensitive-paths list (`Q.database.*`, `Q.secrets.*` for Qbix; `DB_PASSWORD`, `APP_KEY`, `*_SECRET` for Laravel `.env`; `DB_PASSWORD`, `AUTH_KEY`, `*_SALT` for WordPress `wp-config.php`). Any value at a matching key is replaced with a placeholder named after the key. This covers the vast majority of credentials because frameworks put them at well-known paths.

2. **Key-name heuristic** — if the config key name (last segment) contains `password`, `secret`, `key`, `token`, `auth`, `credential`, `apikey`, or `passphrase` (case-insensitive), the value is scrubbed. This catches custom plugin credentials like `Q.myPlugin.stripeApiKey` that the preset does not list.

3. **Value prefix matching** — known API key prefixes are matched regardless of key name: `sk_live_`, `sk_test_`, `pk_live_`, `pk_test_` (Stripe), `AKIA` (AWS), `ghp_`, `gho_`, `ghs_` (GitHub), `xoxb-`, `xoxp-` (Slack), `SG.` (SendGrid), `rk_live_` (Stripe restricted), `whsec_` (Stripe webhook). A value starting with any of these is definitively a secret.

4. **Entropy heuristic (flagging only)** — string values with Shannon entropy above 4.0 bits/char, length ≥ 20, and no whitespace are flagged as "possible credential" in the export manifest. These are NOT automatically scrubbed — the manifest lists them so the caller (human or agent) can confirm. This avoids false positives on UUIDs, hashes, and encoded data.

The `looksLikeCredential($keyName, $value)` utility performs layers 2–4 and is shared by both the export API and any future audit tooling.

### MCP tool registration

The three operations are registered as MCP tools alongside existing handler tools:

- `branch/export` — parameters: `appHost` (required), `branchName` (optional, default trunk), `format` (tar.gz or zip, default tar.gz)
- `branch/push` — parameters: `appHost`, `branchName` (required), files as multipart upload or base64-encoded archive
- `branch/request-merge` — parameters: `appHost`, `branchName` (required), `description` (optional), `title` (optional)

All three require authentication (same Bearer token / panel session as other branch operations). The caller's branch and file permissions govern what they can do.

### OpenAPI endpoints

The same operations as REST endpoints described in `/.well-known/openapi.json`:

- `POST /api/branch/export`
- `POST /api/branch/push`
- `POST /api/branch/request-merge`

Request/response schemas mirror the MCP tool parameters. A ChatGPT GPT Action imports the OpenAPI schema by URL and gets the full API surface immediately, without directory listing.

## 12. State file

The server maintains branch state in `data/branches.json` (or configurable path). This file is read by the parent process and updated only by the parent (via control panel API or CLI). Branch workers never write to it.

```json
{
  "uidNext": 60004,
  "uidMap": {
    "myapp.example.com": 60000,
    "myapp.example.com/feature-login": 60001,
    "myapp.example.com/redesign": 60002,
    "otherapp.example.com": 60003
  },
  "branches": {
    "myapp.example.com/feature-login": {
      "uid": 60001,
      "root": "/var/qbix/branches/myapp/feature-login",
      "db": { "name": "myapp_feature_login", "adapter": "mysql" },
      "created": "2026-10-01T14:30:00Z",
      "createdBy": "admin",
      "access": {
        "admin": "admin",
        "alice": { "branch": "edit", "files": "frontend" },
        "bob": { "branch": "edit", "files": "styles" },
        "dave": "view"
      },
      "sandbox": {}
    }
  }
}
```

## 13. Implementation order

### Phase 1: Branch creation and routing

1. `Q_WebServer_Branch.php` — branch manager class. Methods: `create($appHost, $branchName)`, `delete($appHost, $branchName)`, `list($appHost)`, `resolve($host, $headers)`. Manages the state file.
2. Q_Branch integration — create CoW directory from the app's document root.
3. Database cloning — `Db_Branch::fork($source, $target, $config, $dbms)` dispatches to `Db_Branch_Sqlite`, `Db_Branch_Mysql`, or `Db_Branch_Postgres` adapter subclasses. Each adapter has `fork()` and `drop()` methods. All use raw PDO (no Platform `Db` dependency).
4. Subdomain and cookie/header routing in `handleRequest()` vhost resolution.

### Phase 2: Credential injection

5. Extend `CompatFileWrapper` to detect config-file reads for the active branch and patch credentials in the returned content.
6. Preset additions — each framework preset declares its config-file paths and credential patterns.
7. Cache directory rewriting — redirect framework cache paths to the branch's own directory.

### Phase 3: Write restrictions

8. Extend `CompatFileWrapper` to block PHP-extension writes outside preset cache directories when sandbox is active.
9. Block `rename` and `copy` to PHP-extension targets.

### Phase 4: Per-branch uid isolation

10. UID auto-assignment and mapping file management in `Q_WebServer_Sandbox`.
11. Trunk file ownership — `chown` trunk files to the trunk uid during branch creation.
12. Per-branch `posix_setuid` in the fork child, using the branch's assigned uid.

### Phase 5: Access control and file permissions

13. Two-axis permission model — branch access list with file permission tiers in the state file.
14. Auth check in `handleRequest()` before forking, using panel session / Bearer / Basic auth. Store authenticated user's file tier in the parsed request.
15. File permission enforcement in `CompatFileWrapper` — extension tier check, preset `tierPaths` evaluation, per-user path overrides.
16. Preset additions — each framework preset declares `tierPaths` (default path restrictions per tier), `templatePaths` (which `.php` paths count as templates for the `markup` tier), and for structured-config frameworks: `configFormat`, `configFiles`, and `tierConfig`.
17. Config-key enforcement — for presets with `configFormat` (JSON, YAML), the wrapper parses and diffs config files on write, checking changed keys against `tierConfig` and per-user `config` overrides.

### Phase 6: Control panel integration

18. Branch list view — shows all branches of each app, their status, and collaborators.
19. Branch switcher — sets the `_q_branch` cookie and reloads.
20. File explorer scoped to the active branch's CoW tree, with read-only display for files outside the user's file permission tier.
21. Collaborator management — add/remove users, set branch permission and file permission tier, configure per-user path and config overrides.
22. Diff view — shows which files in the CoW tree differ from trunk.
23. Structured config editor (optional) — for config files with key-level gating, render a key-value form with denied keys shown read-only.

### Phase 7: AI collaboration API

24. `looksLikeCredential($keyName, $value)` utility — implements layers 2–4 of credential detection (key-name heuristic, value prefix matching, entropy flagging).
25. `branch/export` — file tree assembly with tier-based filtering, credential scrubbing via preset sensitive-paths list plus `looksLikeCredential`, manifest generation with framework metadata and install instructions, archive creation and single-use download URL.
26. `branch/push` — file upload endpoint with full tier/tierPaths/tierConfig validation (reuses the same enforcement as the CompatFileWrapper write interception), applies changes to the CoW overlay.
27. `branch/request-merge` — merge request record creation, admin notification dispatch.
28. MCP tool registration — register the three tools in the MCP handler alongside existing tools.
29. OpenAPI schema generation — add the three endpoints to `/.well-known/openapi.json`.

## 14. Open questions for later

- **Merge strategy**: file-level three-way merge? Overwrite trunk? Require manual review? This is the M-of-N signoff discussion.
- **Branch-of-branch**: should branches be allowed to branch further? Probably yes for the filesystem (Q_Branch supports it), but database cloning depth needs a limit.
- **Concurrent database migrations**: if two branches both run migrations, merging becomes a schema conflict problem. This may need framework-specific handling or a "no migrations in branches" policy.
- **WebSocket and long-polling**: do branch workers need their own event loop, or can they share the trunk's? Probably separate, since they run under different uids.
- **Disk quotas**: branches can accumulate large CoW copies and cloned databases. The server should track per-branch disk usage and enforce configurable limits.
