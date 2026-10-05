# AI-Assisted Collaboration

Qbix Server v3.2 turns any running PHP application into a workspace that AI coding assistants and human collaborators can safely edit over the network. This page explains how that works in practice.

## The basic idea

A public-facing Qbix Server exposes a [Model Context Protocol](https://modelcontextprotocol.io) endpoint at `/mcp`. AI tools — Claude, ChatGPT plugins, Cursor, Windsurf, or anything that speaks MCP — connect to that endpoint, authenticate with a bearer token, and get a set of tools for reading and writing files on a branch of the running app. Every write goes through the same permission model that human collaborators use: file-tier restrictions, deny-path rules, and admin review before anything reaches production.

The server is the arbiter. The AI never touches trunk directly, never bypasses file-type restrictions, and never merges its own work. It proposes changes on a branch; a human reviews and approves them.

## What the AI assistant sees

When an MCP client connects, the `initialize` response tells it:

- What app and framework the server is running (Laravel, WordPress, Drupal, etc.)
- Which VCS is available (git, mercurial, or just the `patch` command)
- How the permission model works (file tiers, deny paths)
- Where to find app-specific guidance (`LLM.txt` at the app root, if the developer has placed one there)

This context lets the assistant make informed decisions about how to structure its changes and which files it can touch.

## Available tools

| Tool | What it does |
|---|---|
| `branch_list` | List all branches for an app |
| `branch_create` | Create a new branch with CoW filesystem and cloned database |
| `file_list` | List files in a branch directory |
| `file_read` | Read a file's contents from a branch |
| `branch_export` | Download the branch (or trunk) as an archive with credentials scrubbed |
| `branch_push` | Push individual files, each validated against the caller's file tier |
| `branch_patch` | Apply a unified diff, with optional VCS commit |
| `branch_request_merge` | Ask an admin to merge the branch back to trunk |

The server also exposes panel management tools through MCP — listing apps, checking server health, viewing metrics, and managing email delivery — so an AI assistant can monitor and administer the server without using the web panel.

## Patch-based workflow

The `branch_patch` tool is the most natural way for an AI assistant to propose changes. The assistant generates a unified diff — the same format `git diff` produces — and sends it to the server. The server:

1. Parses every file path in the diff
2. Validates each path against the caller's file-tier and deny-path permissions
3. Rejects the entire patch if any path is outside the caller's permissions
4. Breaks copy-on-write symlinks for affected files (so trunk is never modified)
5. Applies the patch using the best available tool

### VCS detection

The server probes for tools in this order:

| Priority | Tool | What happens |
|---|---|---|
| 1 | **git** | Initializes a git repo in the branch directory on first use. `git apply` applies the patch. If a `commitMessage` is provided, the change is staged and committed, and the commit hash is returned. |
| 2 | **hg** | Initializes a mercurial repo on first use. `hg import --no-commit` applies the patch. Commits if a message is provided. |
| 3 | **patch** | Applies with `patch -p1`. No commit history. A provided commit message is noted as ignored. |
| — | *none* | On Windows with no VCS installed, the server returns an error explaining that git or mercurial is required. |

### Real commits, real history

When git or mercurial is available, the branch directory accumulates real VCS history. Each patch the AI applies becomes a commit with a message, author, and timestamp. This means:

- Admins reviewing a merge request can see the full commit log of what the AI changed and why
- Multiple AI sessions (or a mix of AI and human edits) produce a coherent history
- The branch can be cloned, rebased, or cherry-picked using standard VCS workflows
- `git log`, `git diff`, and `git blame` all work in the branch directory

## Multi-server workflows

Because branches have real git repos, the standard git remote workflow applies. A branch directory on one server can push to or pull from a branch directory on another server, or from a central repository (GitHub, GitLab, Bitbucket). This opens up several patterns:

### Staging → production promotion

An AI assistant edits a branch on a staging server. When the work is reviewed and approved, the branch's git repo pushes to the production server's branch, where a second admin review gates deployment. The MCP endpoint on each server enforces its own permission model independently.

### Distributed editing

Multiple AI assistants (or one assistant alternating between servers) work on branches across different Qbix Server instances. Each branch has its own git repo. Standard `git remote add` / `git push` / `git pull` synchronizes work between them. The MCP layer handles file-level permissions; git handles merge conflicts and history.

### CI integration

A branch's git repo can push to a CI service (GitHub Actions, GitLab CI, etc.) for automated testing before the merge request is approved. The CI results inform the admin's review decision.

### Mesh federation

When [mesh networking](../README.md#mesh-networking) is configured, multiple Qbix Server instances can federate over encrypted P2P connections (BLE + Wi-Fi with multi-hop routing). Branch pushes between federated servers use the same permission model as local branches. Servers pin each other's identity via [OpenClaiming](api-discovery.md#well-knownopenclaiminghostnameserverjson) certificates to prevent MITM attacks on the mesh.

## Permission model

The two-axis model applies to patches the same way it applies to individual file pushes:

**Branch permission** controls access to the branch itself:
- `view` — can export and read, but not write
- `edit` — can push files and apply patches
- `admin` — can also configure lockdown settings

**File tier** controls which file types the caller can modify:
- `styles` — CSS, SCSS, LESS, SASS only
- `markup` — above + HTML, SVG, Markdown, templates (Handlebars, Mustache, Twig, Blade, EJS, Pug, Nunjucks), images, fonts, JSON, XML, YAML
- `frontend` — above + JS, TS, JSX, TSX, Vue, Svelte
- `code` — everything (bypasses deny-path checks)

A patch that touches even one file outside the caller's tier is rejected entirely. This is intentional — partial application of a patch is more dangerous than rejection, because the remaining hunks may not make sense without the rejected ones.

### Deny paths

By default, branches deny writes to `.env*`, `.git/`, `vendor/`, and `node_modules/`. Admins can adjust these per-branch or change the app-level defaults. The `code` tier bypasses deny paths, since someone who can push PHP already has equivalent power.

## LLM.txt discovery

The MCP `initialize` response tells AI assistants to look for an `LLM.txt` file at the app root — for example, `https://myapp.example.com/LLM.txt` or `https://myapp.example.com/.well-known/llm.txt`. This file is not part of Qbix Server itself; it's something the app developer creates to describe their application's structure, conventions, API surface, and anything else an AI assistant should know before making changes.

If the file exists, a well-behaved AI assistant reads it before starting work. If it doesn't, the assistant works from the framework detection and MCP tool descriptions alone.

## Security considerations

- **No direct trunk access.** AI assistants work on branches. Trunk is modified only when an admin approves a merge request.
- **Credential scrubbing.** Exports strip sensitive values from config files and replace them with `{{PLACEHOLDER}}` tokens, so API keys and database passwords are never sent to the AI.
- **Permission enforcement is server-side.** The AI cannot bypass file-tier or deny-path restrictions regardless of what it sends. The server validates every path before writing.
- **Token-scoped access.** Each MCP token is tied to a user with a specific branch permission and file tier. Revoking the token immediately cuts off access.
- **Patch validation is all-or-nothing.** A patch that touches any denied path is fully rejected, not partially applied.
- **VCS history is local.** Git or mercurial repos in branch directories don't push anywhere unless explicitly configured. The history stays on the server by default.
- **Telemetry headers.** Every outbound email sent through the relay includes `X-Q-App`, `X-Q-Host`, and `X-Q-View` headers for tracing which app and code path generated it. These same headers appear on HTTP responses when the app sets them.

## Email and SMS relay

The server includes a built-in [email and SMS relay](RELAY.md) that runs alongside the HTTP server. When configured, the relay starts an SMTP listener on a local port (default 2525) and handles both inbound and outbound email without any external mail server.

### How apps use it

PHP apps point their SMTP config at the relay's local port. For Qbix Platform apps, this means setting `Users.email.smtp.host` to `127.0.0.1` and `Users.email.smtp.port` to `2525`. For Laravel, set `MAIL_HOST=127.0.0.1` and `MAIL_PORT=2525` in `.env`. The app's existing email-sending code works without changes — it just sends to localhost instead of an external SMTP service.

The relay then handles delivery through a configured upstream provider (Amazon SES, Mailgun, or raw SMTP), with:

- **MIME parsing** — extracts text and HTML parts, handles multipart messages and attachments
- **Conversation threading** — groups messages by `Message-ID`, `In-Reply-To`, and `References` headers into threads with members
- **Digest batching** — aggregates high-frequency notifications into periodic digests with configurable intervals
- **Rate limiting** — per-recipient rate limits with a circuit breaker that opens after repeated failures
- **Delivery logging** — every send is logged with channel, direction, sender, recipient, status, and error detail

### SMS

The relay also handles SMS through Twilio. Apps call `Q_Relay_Mobile::send($to, $body)` or post to the relay's webhook endpoint. Inbound SMS arrives at `/Q/relay/sms/webhook`, is validated against Twilio's signature, and stored in the same threading system as email.

### Integration with Qbix Platform

The relay integrates with the Qbix Platform's Users plugin. `Users_Email::sendMessage()` sends through `Zend_Mail_Transport_Smtp`, which connects to the relay's local SMTP listener. The relay receives the message — including the platform's `X-Q-App` and `X-Q-View` headers — parses it, threads it, and delivers it upstream. This has been verified end-to-end: the platform boots, Zend_Mail connects to the relay's port 2525, the relay receives and parses the MIME message, threads it with sender and recipient, stores it in its SQLite database, and logs the delivery.

### Branch isolation for email

On branches, the relay's local SMTP port and dev credentials keep branch code from sending real email. The branch credential system (described below) automatically substitutes sandbox SMTP settings, so a branch's email-sending code hits the relay in test mode or a sandbox service like Mailtrap — never the production upstream.

## Branch subdomain routing and TLS

Each branch is accessible at `subdomain.apphost.com`. The subdomain defaults to the DNS-sanitized branch name and can be changed via the Panel API (`POST /Q/panel/api/branches/subdomain`) or the control panel UI. Subdomains are unique within each app.

Branch routing checks the incoming `Host` header. When the host matches `<slug>.<apphost>`, the request is routed to that branch's copy-on-write directory and database.

### Automatic TLS provisioning

When `Q.webserver.tls.acmeEmail` is configured, the server provisions a Let's Encrypt certificate for each branch subdomain automatically using HTTP-01 challenges. Prerequisites:

1. A wildcard DNS A record pointing `*.yourapp.com` to the server's IP
2. Port 80 reachable from the internet (for ACME validation)
3. `Q.webserver.tls.acmeEmail` set in your server config

No DNS provider API is needed. The server handles challenge responses internally.

**Timing:** Certs are provisioned at startup for all existing branches. When a new branch is created (or its subdomain is changed), the cert is provisioned within 30 seconds via a polling timer in the parent process.

**Renewal:** The 12-hour cert renewal timer covers branch subdomain certs alongside primary domain certs. Certs are renewed when they have fewer than 30 days remaining.

**Rate limits:** Let's Encrypt allows 50 certificates per registered domain per week. Set `Q.webserver.tls.acmeStaging: true` to use the staging environment during development.

### Per-branch access control

Each branch has an access map: `{username: {branch: "view"|"edit"|"admin", files: "none"|"frontend"|"markup"|"all"}}`. The `branch` permission controls what the user can do with the branch (view, push changes, or configure it). The `files` permission controls which file types they can push, using the same tier system as default lockdown.

Admins manage access through the control panel's access dialog or via `POST /Q/panel/api/branches/access`.

## Branch credential isolation

Branches run against development credentials, never production ones. When the server creates a branch, it scrubs sensitive values from config files and replaces them with `{{PLACEHOLDER}}` tokens. At runtime, a stream wrapper transparently injects the real development values before the app reads its config — the branch code never sees production credentials on disk.

### How it works

1. **Scrubbing** happens during branch creation and export. The server detects credentials using four methods: known key names from the framework preset (e.g. `DB_PASSWORD` for Laravel, `AUTH_KEY` for WordPress), keyword matching on key names (password, secret, token, apikey, etc.), known API key prefixes (`sk_live_`, `AKIA`, `ghp_`, `sk-`), and Shannon entropy analysis for high-randomness strings. Detected values are replaced with `{{KEY_NAME}}` tokens.

2. **Injection** happens at runtime via a PHP stream wrapper that intercepts file reads. When a branch worker reads a `.php`, `.env`, `.json`, `.yaml`, `.yml`, `.ini`, `.xml`, or `.conf` file containing `{{placeholder}}` patterns, the wrapper substitutes real values from the branch's credential store before returning the content.

### Configuring dev credentials

Set `Q.webserver.branches.credentials` in your server config with the development values that all branches should use:

```json
{
    "Q": {
        "webserver": {
            "branches": {
                "credentials": {
                    "REDIS_HOST": "127.0.0.1",
                    "REDIS_DB": "10",
                    "MAIL_HOST": "127.0.0.1",
                    "MAIL_PORT": "2525",
                    "STRIPE_KEY": "sk_test_...",
                    "STRIPE_SECRET": "sk_test_...",
                    "AWS_BUCKET": "myapp-dev-uploads"
                }
            }
        }
    }
}
```

These shared dev credentials apply to every branch. The admin configures them once; there is no per-branch credential management.

When the built-in relay is running, pointing `MAIL_HOST` / `MAIL_PORT` at `127.0.0.1:2525` routes all branch email through the relay, which can be configured to use a sandbox upstream or simply log without delivering.

### Database credentials are auto-generated

Database credentials are the one exception. Each branch gets its own cloned database (the name is auto-generated as `<host>_<branch>`), so the server generates per-branch DB credential keys automatically and merges them into the shared credentials. The auto-generated keys cover both `.env`-style names and framework-specific config paths:

| Key | Example value | Used by |
|---|---|---|
| `DB_DATABASE`, `DB_NAME` | `example_com_feature_x` | Laravel, generic .env |
| `DB_HOST` | `localhost` | Laravel, generic .env |
| `DB_PORT` | `3306` | Laravel, generic .env |
| `DB_USERNAME`, `DB_USER` | `br_example_com_feat_a1b2c3` | Laravel, WordPress, generic .env |
| `DB_PASSWORD` | `(auto-generated per branch)` | Laravel, generic .env |
| `DATABASE_URL` | `mysql://root:pass@localhost:3306/example_com_feature_x` | Symfony |
| `Q.database.main.name` | `example_com_feature_x` | Qbix |
| `Q.database.main.host` | `localhost` | Qbix |
| `databases.default.default.database` | `example_com_feature_x` | Drupal |
| `Datasources.default.database` | `example_com_feature_x` | CakePHP |
| `components.db.dsn` | `mysql:host=localhost;port=3306;dbname=...` | Yii |
| `database.default.database` | `example_com_feature_x` | CodeIgniter |

The framework is auto-detected from the host's `preset` setting. If a key appears in both the shared credentials and the auto-generated DB keys, the auto-generated value wins (since the branch has its own database, it must use its own database name).

### What to put in dev credentials

The goal is to prevent branches from touching production services. Typical entries:

- **Email**: Point to the relay's local port (`127.0.0.1:2525`) or a sandbox SMTP service (Mailtrap, Mailhog) so branch code cannot send real email
- **Payment processing**: Use test-mode keys (Stripe `sk_test_`, PayPal sandbox) so branches cannot charge real cards
- **Object storage**: A separate dev bucket or path prefix so branch uploads don't mix with production
- **Cache / Redis**: A different Redis database number or a key prefix so branch cache doesn't pollute production
- **Search**: A dev index name for Elasticsearch, Meilisearch, or Algolia
- **Push notifications**: Dev/sandbox credentials so branches don't notify real users
- **API keys for external services**: Dev-tier keys with lower rate limits and no production data access

Any key name that matches a `{{PLACEHOLDER}}` token in the branch's config files will be substituted. The key names are framework-agnostic — they just need to match what the scrubber replaced. You can add custom keys for any service your app uses.

### Database cloning

The server supports three database adapters for branch cloning:

| Adapter | Method | Speed | Config key |
|---|---|---|---|
| **SQLite** | File copy | Instant for small DBs | `branches.db.adapter: "sqlite"` |
| **MySQL / MariaDB** | PDO table-by-table copy (or `mysqldump` CLI fallback) | Seconds to minutes | `branches.db.adapter: "mysql"` |
| **PostgreSQL** | `CREATE DATABASE ... TEMPLATE` | Fast (Postgres-native CoW) | `branches.db.adapter: "postgres"` |

Configure the database adapter and connection in `Q.webserver.branches.db`:

```json
{
    "Q": {
        "webserver": {
            "branches": {
                "db": {
                    "adapter": "mysql",
                    "host": "localhost",
                    "user": "root",
                    "password": "your-db-password"
                }
            }
        }
    }
}
```

The source database is auto-detected from the app's config files using the framework preset. Each branch gets a cloned copy named `<host>_<branch>`. The clone is dropped when the branch is deleted.

#### Per-branch database user isolation

Each cloned database gets its own dedicated database user (MySQL: `br_<dbname>_<suffix>`) or role (PostgreSQL) with privileges scoped only to that branch's database. A compromised branch cannot access the source database, other branches' databases, or system databases. The per-branch credentials are auto-generated and injected transparently — no admin configuration required. Admin credentials are stored separately and used only for cleanup when the branch is dropped.

## Observability

The server includes a built-in [metrics and analytics](METRICS.md) system. A live dashboard at `/Q/dashboard` shows request rates, memory usage, status code distribution, and active connections. The control panel at `/Q/panel` provides app management, branch controls, an emails tab for viewing relay delivery logs, and server-side analytics with Sankey flow visualization and session replay.

Client-side metrics are opt-in: set `Q.webserver.clientMetrics.enabled` to `true` and the server injects telemetry scripts that report scroll depth, media playback, SPA navigation, and dwell time. Server-side analytics are automatic and require no client-side code.

## Example: Claude edits a Laravel app

```
1. Claude connects to the MCP endpoint
   POST /mcp
   Authorization: Bearer <token>

2. The initialize response tells Claude:
   - Framework: Laravel
   - VCS: git
   - File tier: frontend (no PHP)
   - Check LLM.txt for app conventions

3. Claude reads LLM.txt, learns the app's component structure

4. Claude exports the branch to understand the current state
   tools/call: branch_export

5. Claude generates a patch that updates Blade templates and CSS
   tools/call: branch_patch
   {
     "patch": "<unified diff>",
     "commitMessage": "Redesign header navigation"
   }

6. The server validates all paths, applies the patch, commits with git

7. Claude requests a merge
   tools/call: branch_request_merge
   {
     "title": "Header redesign",
     "description": "Updated nav layout and responsive breakpoints"
   }

8. An admin reviews the merge request, checks the git log,
   and approves or rejects
```

## Comparison with other approaches

| Approach | Trunk safety | Permission control | VCS history | Email/SMS | Works with any AI tool |
|---|---|---|---|---|---|
| AI edits files directly via SSH | ❌ | ❌ | Manual | ❌ | ❌ |
| AI commits to a git branch | ✅ | ❌ (full repo access) | ✅ | ❌ | ❌ (needs git access) |
| AI uses a custom API | ✅ | Custom | Custom | ❌ | ❌ (proprietary) |
| **Qbix Server MCP** | ✅ | ✅ (file tier + deny paths) | ✅ (automatic) | ✅ (built-in relay) | ✅ (standard MCP) |

The key difference is that Qbix Server combines branch isolation, file-level permissions, VCS history, and integrated email/SMS relay in a single system that any MCP-compatible AI tool can use without custom integration.
