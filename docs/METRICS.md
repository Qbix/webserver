# Client-Side Metrics

Qbix Server can inject client-side telemetry scripts into every HTML page it serves and collect the resulting events. The system is opt-in, requires no changes to your application code, and works with any framework — static sites, Laravel, WordPress, SPAs, or the Qbix Platform.

See also: the [configuration reference](#configuration) at the bottom of this document.

## How It Works

When `Q.webserver.clientMetrics.enabled` is set to `true`, the server inserts `<script>` and `<link>` tags before `</head>` (or `</body>`) in HTML responses. The injected Metrics.js core — plus whichever trackers you enable — reports events back to a configurable POST endpoint. Events arrive as JSON via `sendBeacon` (with a `fetch` fallback) and are stored as daily TSV files, one line per event.

Injection is skipped when:
- The response already references `Metrics.js` or `Metrics.min.js` (Qbix Platform sites load it through their own asset pipeline)
- The `Sec-Fetch-Dest` header is present and not `document` (subresource fetches, iframes, etc.)
- The response body has no `</head>` or `</body>` tag

## Quick Start

```json
{
  "Q": {
    "webserver": {
      "clientMetrics": {
        "enabled": true
      }
    }
  }
}
```

That's it. The server injects the bundled Metrics.js and the default trackers (scroll + media) into every HTML page. Events are collected at `/Q/clientMetrics` and stored as TSV files under `local/client-metrics/`.

## Architecture

```
Browser                          Server
┌──────────────────┐             ┌──────────────────┐
│  Metrics.js      │             │  ClientMetrics.php│
│  + ScrollTracker │──sendBeacon─▶  handlePost()    │
│  + MediaTracker  │   / fetch   │  → daily TSV     │
│  + NavigationTkr │             │                  │
└──────────────────┘             │  handlePanelApi() │
                                 │  → dashboard JSON │
                                 │  → TSV download   │
                                 └──────────────────┘
```

### Event Format

Each event is a single JSON object sent via POST:

```json
{
  "label": "section:introduction",
  "page": "My Page Title",
  "sid": "abc123",
  "vid": "def456",
  "ts": 1696000000000,
  "tag": "h2",
  "ordinal": 0,
  "snippet": "Introduction"
}
```

The `label` field identifies the event type. Common labels:

| Label | Source | Meaning |
|---|---|---|
| `pageload` | Core | Page was loaded |
| `unload:42s` | Core | Page was closed after 42 seconds |
| `navigate` | Core | SPA navigation detected (pushState/popstate) |
| `outbound` | Core | External link clicked |
| `background:15s` | Core | Tab was backgrounded for 15 seconds |
| `section:intro` | ScrollTracker | User scrolled to the "intro" section |
| `depth:50%` | ScrollTracker | User reached 50% scroll depth |
| `anchor:faq` | ScrollTracker | User clicked an anchor link to #faq |
| `media:play` | MediaTracker | Media playback started |
| `media:pause` | MediaTracker | Media playback paused |
| `media:ended` | MediaTracker | Media playback completed |
| `media:checkpoint` | MediaTracker | Periodic playback progress |
| `dwell:intro:12s` | NavigationTracker | User spent 12 seconds on section |
| `nav:tab:pricing` | NavigationTracker | User switched to "pricing" tab |

### TSV Storage

Events are stored as tab-separated values in daily files (`YYYY-MM-DD.tsv`). Each line contains:

```
timestamp	visitorId	sessionId	page	label	dataJSON
```

Files older than `retainDays` (default 90) are automatically purged.

## Trackers

### Metrics.js (Core)

The core handles transport, session/visitor IDs, visibility detection, unload tracking, outbound link interception, and SPA navigation detection. It works standalone on any website.

**Session and visitor IDs**: A per-tab session ID is stored in `sessionStorage`; a persistent visitor ID in `localStorage`. Both survive page reloads within their scope.

**SPA navigation**: The core monkey-patches `history.pushState` and `history.replaceState` and listens to `popstate` to detect client-side page changes. When the Q framework is loaded, it also hooks into `Q.Page.onPush` for reliable SPA tracking. Each navigation fires a `navigate` event and resets the page timer.

**Outbound links**: Clicks on `<a>` tags with external URLs and calls to `window.open()` are intercepted and reported.

**Visibility**: Background/foreground transitions are tracked. When the tab is backgrounded for more than a second, a `background:Ns` event is sent on return.

### ScrollTracker

Section-aware scroll telemetry. Discovers sections by CSS selector (default: `h2[id], h3[id], section[id], [data-section]`), tracks which sections the user reads, and reports scroll depth milestones at 25%, 50%, 75%, and 100%.

```js
Metrics.ScrollTracker.init({
  sections: 'h2[id], h3[id]',
  debounce: 1000,
  depthMilestones: [25, 50, 75, 100],
  tocSelector: '.toc a',        // optional: real-time TOC highlighting
  tocActiveClass: 'active'
});
```

Features:
- Scroll-settle detection (waits for scroll to stop before reporting)
- Anchor click tracking with cooldown (suppresses scroll events during smooth-scroll)
- Pre-marks sections visible on page load (no spurious events from scroll restoration)
- TOC highlighting: real-time `active` class on TOC links matching the current section
- SPA-aware: resets tracking on page navigation

### NavigationTracker

A superset of ScrollTracker that adds dynamic section tracking and dwell time. Designed for pages with interactive UI — tabs, accordions, multi-column layouts.

```js
Metrics.NavigationTracker.init({
  sections: 'h2[id], h3[id]',
  debounce: 1000,
  trackDwell: true,
  observeMutations: true
});
```

Additional features beyond ScrollTracker:
- **Dynamic sections**: Detects Q/tabs, Q/columns, Q/expandable, Q/contextual widgets and tracks which tab/column the user views
- **Dwell time**: Reports how long the user spent on each section before scrolling away
- **Nav container links**: Observes `<nav>` and `[role="navigation"]` elements for link clicks
- **DOM mutation observer**: Re-discovers sections when the DOM changes (AJAX content, lazy loading)
- **SPA-aware**: Resets tracking on page navigation

### MediaTracker

Auto-discovers and tracks media players — native HTML5 `<video>` and `<audio>` elements plus embedded players from YouTube, Vimeo, SoundCloud, Wistia, JW Player, Dailymotion, Spotify, Twitch, and Muse.ai.

```js
Metrics.MediaTracker.init({
  checkpointInterval: 10  // seconds between progress reports
});
```

Features:
- Play, pause, seek, ended, and error events
- Unique watched-seconds tracking via range-merge (WatchedTracker)
- Periodic checkpoint events with current position and total watched time
- Works with dynamically loaded media (mutation observer)
- Handles YouTube and Vimeo API loading automatically

## Dashboard

The control panel includes a "Client Metrics" tab showing:
- Daily event counts, unique visitors, and unique sessions
- Top event labels and pages
- Filterable event table (by label prefix, page prefix)
- Raw TSV download for offline analysis

Access the dashboard data programmatically via the panel API:

```
GET /Q/panel/api/client-metrics          — overview with available dates
GET /Q/panel/api/client-metrics/{date}   — summary for a specific date
GET /Q/panel/api/client-metrics/{date}/events?label=section&page=/blog  — filtered events
GET /Q/panel/api/client-metrics/{date}/tsv  — raw TSV download
```

## Analytics Portal

The control panel also includes an "Analytics" tab with a server-side analytics portal. Unlike the client-side metrics above (which require JS injection and opt-in), the analytics portal records every HTTP request automatically and stores per-request data in SQLite — page views, sessions, navigation flow, user agents, and geography.

### How It Works

The server records every request to `local/metrics.db` with:
- **Session detection**: matches framework session cookies (Qbix, WordPress, Drupal, PrestaShop, phpBB, Nextcloud, TYPO3) or falls back to `md5(ip|ua)`.
- **Clickstream**: each request stores its `path` and `prev_path` (the previous page in the same session). This makes Sankey flow queries a simple `GROUP BY prev_path, path` rather than expensive self-joins.
- **UA parsing**: extracts platform (iOS, Android, Windows, macOS, ChromeOS, Linux) and browser (Edge, Opera, Firefox, Samsung, Chrome, Safari, IE, Bot) from the User-Agent string.
- **Language**: the primary language from the `Accept-Language` header.
- **Client IP**: resolved upstream by the proxy subsystem (X-Forwarded-For, X-Real-IP, CF-Connecting-IP through trusted proxy config).

Data is buffered in memory and flushed to SQLite periodically (default every 10 seconds). Old data is purged on the same schedule as other metrics (default 30 days).

### Sankey Visualization

The analytics tab includes an interactive Sankey diagram built with d3-sankey. It shows how users flow between pages — which pages they enter on, where they go next, and where they leave.

- Click any node to drill down: see outgoing and incoming flows for that page
- Breadcrumb navigation lets you walk through the site structure
- A mini Sankey also appears in the Apps tab for a quick overview

### Filters

All analytics endpoints accept the same filter parameters:

| Parameter | Meaning |
|---|---|
| `host` | Filter by hostname (app) |
| `period` | Time window: `1h`, `24h`, `7d`, `30d` |
| `platform` | Filter by detected platform |
| `browser` | Filter by detected browser |
| `language` | Filter by primary language |
| `ip` | Filter by IP prefix (e.g. `192.168.`) |
| `path` | Filter by path prefix (e.g. `/blog`) |

### API Endpoints

```
GET /Q/panel/api/metrics/analytics           — overview stats, top pages/platforms/browsers/languages, available hosts
GET /Q/panel/api/metrics/analytics/flow      — Sankey edges (from_path → to_path with count)
GET /Q/panel/api/metrics/analytics/drilldown — outgoing/incoming flows for a specific page
GET /Q/panel/api/metrics/analytics/sessions  — session list with metadata
GET /Q/panel/api/metrics/analytics/session   — full request timeline for a single session
GET /Q/panel/api/metrics/analytics/dates     — date range of available data
```

#### Overview response

```json
{
  "pageViews": 12450,
  "sessions": 834,
  "uniqueIps": 612,
  "avgMs": 42.3,
  "topPages": [{"path": "/", "hits": 3200}, ...],
  "topPlatforms": [{"platform": "Windows", "count": 5100}, ...],
  "topBrowsers": [{"browser": "Chrome", "count": 8200}, ...],
  "topLanguages": [{"language": "en", "count": 10000}, ...],
  "hosts": ["myapp.test", "other.test"]
}
```

#### Flow response

```json
[
  {"from_path": "/", "to_path": "/about", "count": 450},
  {"from_path": "/", "to_path": "/products", "count": 320},
  ...
]
```

#### Session detail

```
GET /Q/panel/api/metrics/analytics/session?id=abc123
```

Returns the full chronological list of requests in that session:

```json
[
  {"ts": 1696000000, "path": "/", "prev_path": null, "status": 200, "duration_ms": 35.2, "ip": "1.2.3.4", "host": "myapp.test"},
  {"ts": 1696000012, "path": "/products", "prev_path": "/", "status": 200, "duration_ms": 28.1, ...},
  ...
]
```

### SQLite Schema

Two tables store the analytics data:

```sql
CREATE TABLE requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts INTEGER NOT NULL,
    session_id TEXT NOT NULL,
    path TEXT NOT NULL,
    prev_path TEXT,
    status INTEGER,
    duration_ms REAL,
    ip TEXT,
    host TEXT,
    platform TEXT,
    browser TEXT,
    language TEXT
);

CREATE TABLE sessions (
    session_id TEXT PRIMARY KEY,
    first_seen INTEGER NOT NULL,
    last_seen INTEGER NOT NULL,
    ip TEXT,
    host TEXT,
    platform TEXT,
    browser TEXT,
    language TEXT,
    page_count INTEGER DEFAULT 1,
    entry_path TEXT
);
```

Both tables are indexed for fast filtering and are subject to the same retention policy as other metrics data.

## Standalone Usage

Metrics.js works without Qbix Server. Include it directly in your HTML:

```html
<script src="Metrics.js"></script>
<script src="Metrics.ScrollTracker.js"></script>
<script>
  Metrics.init({ endpoint: '/your/metrics/endpoint' });
  Metrics.ScrollTracker.init();
</script>
```

You need a server-side endpoint that accepts POST requests with JSON bodies. Each request contains an array of events.

## Q Framework Integration

When loaded alongside the Q framework, Metrics.js automatically:
- Aliases itself as `Q.Metrics`
- Sets `Q.Metrics.setState()` for state-dependent extra fields
- Hooks into `Q.Page.onPush` for SPA navigation tracking
- Wires up Q tools (Q/tabs, Q/columns, Q/expandable, Q.Contextual) for NavigationTracker
- Forwards error telemetry from `Q.req`
- Chains visit IDs across Q page transitions

## Extra Script/Style Injection

The injection system supports adding arbitrary JS and CSS files alongside the Metrics scripts. This is useful for custom analytics, A/B testing scripts, or application-wide stylesheets.

```json
{
  "Q": {
    "webserver": {
      "clientMetrics": {
        "enabled": true,
        "extraScripts": ["/js/analytics.js", "/js/ab-test.js"],
        "extraStyles": ["/css/global-overrides.css"]
      }
    }
  }
}
```

CSS files are injected first (before `</head>`) so styles are available before scripts run. Extra scripts are injected after the Metrics tracker scripts.

All injected tags respect the `Sec-Fetch-Dest` header — they are only added to top-level document requests, not to subresource fetches or iframes.

## Minified Builds

Both regular and minified (`.min.js`) versions of all files are bundled. To use the minified versions:

```json
{
  "Q": {
    "webserver": {
      "clientMetrics": {
        "enabled": true,
        "minified": true
      }
    }
  }
}
```

When `minified` is set, the server injects `Metrics.min.js` and the corresponding `.min.js` tracker files. If you point `scriptUrl` to a `.min.js` file, tracker filenames are automatically switched to `.min.js` as well.

## Configuration

All keys live under `Q.webserver.clientMetrics`:

| Key | Default | Purpose |
|---|---|---|
| `enabled` | `false` | Master switch (opt-in) |
| `endpoint` | `/Q/clientMetrics` | POST endpoint for events |
| `scriptUrl` | `null` | External Metrics.js URL (null = serve bundled copy) |
| `trackers` | `["scroll","media"]` | Which trackers to auto-init: `scroll`, `navigation`, `media` |
| `retainDays` | `90` | Days to keep TSV files |
| `inject` | `true` | Whether to inject the script tag |
| `minified` | `false` | Use `.min.js` versions of bundled scripts |
| `extraScripts` | `[]` | Additional JS file URLs to inject |
| `extraStyles` | `[]` | Additional CSS file URLs to inject |
| `checkpointInterval` | `10` | MediaTracker checkpoint interval (seconds) |
| `debounce` | `1000` | ScrollTracker/NavigationTracker debounce (ms) |
