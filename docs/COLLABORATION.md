# AI-Assisted Collaboration

Qbix Server v2.3 turns any running PHP application into a workspace that AI coding assistants and human collaborators can safely edit over the network. This page explains how that works in practice.

## The basic idea

A public-facing Qbix Server exposes a [Model Context Protocol](https://modelcontextprotocol.io) endpoint at `/Q/mcp/{appHost}/{branchName}`. AI tools — Claude, ChatGPT plugins, Cursor, Windsurf, or anything that speaks MCP — connect to that endpoint, authenticate with a bearer token, and get a set of tools for reading and writing files on a branch of the running app. Every write goes through the same permission model that human collaborators use: file-tier restrictions, deny-path rules, and admin review before anything reaches production.

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
| `health` | Verify the branch is reachable |
| `branch_export` | Download the branch (or trunk) as an archive with credentials scrubbed |
| `branch_push` | Push individual files, each validated against the caller's file tier |
| `branch_patch` | Apply a unified diff, with optional VCS commit |
| `branch_request_merge` | Ask an admin to merge the branch back to trunk |

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

## Permission model

The two-axis model applies to patches the same way it applies to individual file pushes:

**Branch permission** controls access to the branch itself:
- `view` — can export and read, but not write
- `edit` — can push files and apply patches
- `admin` — can also configure lockdown settings

**File tier** controls which file types the caller can modify:
- `styles` — CSS, SCSS, LESS, SASS only
- `markup` — above + HTML, SVG, Markdown, images, fonts, JSON, XML, YAML
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

## Example: Claude edits a Laravel app

```
1. Claude connects to the MCP endpoint
   POST /Q/mcp/myapp.example.com/feature-redesign

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

| Approach | Trunk safety | Permission control | VCS history | Works with any AI tool |
|---|---|---|---|---|
| AI edits files directly via SSH | ❌ | ❌ | Manual | ❌ |
| AI commits to a git branch | ✅ | ❌ (full repo access) | ✅ | ❌ (needs git access) |
| AI uses a custom API | ✅ | Custom | Custom | ❌ (proprietary) |
| **Qbix Server MCP** | ✅ | ✅ (file tier + deny paths) | ✅ (automatic) | ✅ (standard MCP) |

The key difference is that Qbix Server combines branch isolation, file-level permissions, and VCS history in a single system that any MCP-compatible AI tool can use without custom integration.
