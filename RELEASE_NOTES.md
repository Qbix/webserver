# Qbix Server 2.3.2

Enabling an app in the control panel now ties it to the domain the panel was opened on. Before, Enable only changed the default root in memory, so the domain kept showing the welcome page and a restart forgot the choice. The app's web folder is now saved as that domain's root in `local/panel.json` and applied at once; Disable removes it again, and for a domain the panel created itself it removes the entry, so the domain returns to the welcome page.

Certificate requests no longer stop the server. Startup provisioning and the 12-hour renewal run each request in its own worker process, at most two at a time with the rest queued, so a slow or refusing Let's Encrypt cannot freeze the event loop or ignore Ctrl-C. Names Let's Encrypt can never certify (`.test`, `.local`, IP addresses, single labels, wildcards on the HTTP-01 path) are rejected at once with the reason, and a failed order reports the real error instead of PHP warnings from `file_get_contents`. Renewal keeps every name a certificate already covers. Certificates issued through the control panel are renewed by the same schedule, because renewal scans the certificate folder.

PHP 8.2 and later no longer report deprecations at startup: `Q_WebServer_Pool` declares `octane` and `maxRequests` instead of creating them dynamically, and `ReflectionProperty::setAccessible()` is called only on PHP before 8.1, where it has an effect (it is deprecated in 8.5).

On macOS the worker count is checked against the process's own open-file limit, which defaults to 256, instead of the much higher `kern.maxfilesperproc`. The server raises the limit toward what the system allows and, if that is not enough, offers a smaller number of workers instead of failing with "Too many open files" after the banner.

Command line: options that take a value now accept a space as well as an equals sign, so `--port 8080` works like `--port=8080` (before, the space form was ignored and the server tried port 80). The startup box now grows to fit its widest line and counts characters rather than bytes, so the right-hand border lines up. When the port is already taken, the error now names the process that holds it, with its PID, user, command line and the folder it was started from, and shows how to stop it or pick another port. This uses `lsof` or `ss` on macOS and Linux, and `netstat` and PowerShell on Windows; a process owned by another user may need `sudo`.

A certificate request that failed with "Server is shutting down" is fixed. The worker process that answers a request inherited the parent's running event loop and then refused to start its own, so every certificate order failed at once and left the half-started loop behind. In the same area, a timer that cancelled itself from inside its own callback (the worker reaper did) corrupted the timer table in the `stream_select` and `poll` drivers and ended in a fatal "Function name must be a string"; both drivers now tolerate it.

A domain with no app now shows `src/Q/welcome.php`, the default page, instead of a one-line placeholder. A domain that has an app or root but no `index.php` or `index.html` in it now gets a "No index file" page that names the folder, rather than being mistaken for the welcome page.

Control panel logs: the log viewer reported "Log file not found" for files that were listed with their sizes, because it looked for them under the wrong folder. It now looks in the app's own folder and in the log folder, and still refuses anything outside them.

A failed certificate request is now kept where the panel can show it. The Domains tab (and, in 3.2, a branch's TLS badge) shows "Failed [ See Error ]" instead of going back to "Provision" as if nothing had happened, and the link opens a dialog with the full error, a Copy button and Try again. The message is stored in the certificate folder, so it is still there when you come back later, and it is replaced by the next request. A domain served through nginx is now certified through certbot and nginx from the panel (it used to refuse with a note); if certbot or nginx is missing, cannot use sudo, or fails, that reason is what the dialog shows. A certificate problem never takes the server down: startup provisioning, renewal and the panel's own requests catch and record any failure, including an exception, and carry on.

Domains and apps: each domain row has a dropdown of the apps in the apps folder in place of "Configure app" and "Enable". The domain's current app is first and marked; apps that serve other domains come next with those domains named, since one app can serve several. Choosing an app saves it as that domain's root at once, and "No app (welcome page)" removes it. An app's "Domains" button lists the domains it serves, each with a dropdown set to this app that can be changed to another, a field to add a domain, and a button to the Domains tab. "Configure" now says what it does: it runs the app's own configure script, which creates its `local/` folder, and has nothing to do with serving a domain. Apps with `public/`, `public_html/` or `htdocs/` instead of `web/` are listed too.

Text areas in the panel grow to show their text, up to half the window height.

---

# Qbix Server 2.3.1

Fixes the macOS release job: `tests/stream-wrappers.sh` required a system `php` even when it was given a binary to test, and the macOS runner has none. It now asks for `php` only when running from sources.

The per-host auto-cache and the component cache are complete again. The control panel and `WebServer.php` already called `Q_WebServer_Cache::configureHost`, `Q_WebServer_Cache_Components::getPage` and the rest, but `Cache.php` and `Cache/Components.php` did not define them.

Two responses were sent with `Content-Type: Array`: the ACME challenge answer and the "setting up your domain" splash page. The headers array had been passed where `sendResponse()` expects the content-type string. A browser given that shows the page source instead of rendering it.

Requesting a certificate in the control panel with no ACME email saved now asks for the address, keeps it, and carries on, instead of telling you to edit `Q.webserver.tls.acmeEmail`. Behind nginx or Apache it says the proxy holds the certificate.

A new setting, `Q.webserver.unknownHosts`, decides what a domain name with no entry in `hosts` or `domains` gets. The default, `"root"`, keeps today's behavior: the default root answers. Set it to `"welcome"` and such a domain gets the welcome page pointing at the control panel instead, so one app's site is never shown under a domain that was not set up for it. localhost and IP addresses always get the default root, and the server's own `/Q/` routes are never hidden.

The event loop no longer sleeps past a repeating timer that a callback has just rescheduled, or past a timer that a callback added. Scheduled tasks marked `disabled` are skipped. Certificate renewal skips the `.pending` directory and loads the renewed certificate for new connections straight away.

---

# Qbix Server 2.3 — Strange New Worlds

The autohost log, the watchdog log and any file you list under `Q/webserver/logs/files` are now rotated, compressed and deleted on a schedule instead of growing for ever, with the same policy as `access.log` and `error.log`. New control panel routes `logs/managed` and `logs/rotate` show and run it. See [docs/logs.md](docs/logs.md).

---

# Qbix Server v2.0.0 — Mesh

v1.x was a PHP web server. v2.0 is a mesh-networked runtime. Phones discover each other over Bluetooth, establish encrypted sessions without any central server, synchronize data peer-to-peer, and run PHP applications on every node.

The web server is still there — every v1.x feature works unchanged. v2.0 adds a new capability surface on top.

## Mesh Protocol

Every Qbix Server instance has a cryptographic identity: an ECDSA P-256 keypair generated on first run. The peer ID is `sha256(public_key)` — 64 hex characters, same security model as Ethereum. No registration, no CA, no blockchain.

When two servers discover each other (over BLE, Wi-Fi, or TCP), they perform a 4-step handshake: certificate exchange, mutual authentication via ECDSA signatures, ECDH ephemeral key agreement, and AES-256-GCM session encryption. All subsequent traffic is encrypted end-to-end.

```php
// Any Qbix app can now talk to nearby servers
Q::handleUsingRemote('qbix-peer://' . $peerId . '/api/endpoint', $data);

// React to peers coming and going
Q_WebServer_Transport::onPeerOnline(function ($peer) {
    // $peer has: peer_id, name, transport, address
    // Sync data, exchange messages, coordinate work
});
```

### Routing

When peers are out of direct range, intermediate nodes relay traffic. The router uses distance-vector routing (the same algorithm that ran the early internet) with HELLO/BYE/HEARTBEAT messages, TTL limits, deduplication, and route dampening. A message from A to C routes through B transparently — encrypted end-to-end so B can't read it.

### Data Sync

When a peer connects, the sync protocol runs automatically:

1. Exchange Bloom filters (1KB each) to identify what's different
2. Transfer only the missing records
3. Resolve conflicts (last-writer-wins by default, pluggable per table)

For large datasets (10,000+ records), the protocol switches to prolly tree comparison: a deterministic content-addressed tree where identical subtrees are skipped entirely. With 10,000 records and 100 differences, the diff takes 1.2ms and transfers ~11KB of hashes instead of the full dataset.

## Mobile Platforms

Qbix Server runs on iOS and Android. PHP runtimes now exist for both platforms (NativePHP Mobile, php-ios, Phphone). The native TransportManager handles peer discovery and transport negotiation.

### Transport Priority

When a peer is reachable over multiple channels, the best one is used automatically:

| Priority | Transport | Bandwidth | Range |
|---|---|---|---|
| 1 | TCP (LAN/Wi-Fi) | 100+ Mbps | Same network |
| 2 | MultipeerConnectivity | 2–25 Mbps | ~200ft (iOS only) |
| 3 | BLE GATT | ~2 Mbps | ~100ft per hop |

If Wi-Fi drops, traffic falls back to BLE seamlessly. The PHP server never knows — it sees HTTP on localhost regardless of transport.

### BLE Chunking

HTTP payloads are chunked for BLE's MTU constraints (23–517 bytes per write). The chunking protocol is implemented identically in PHP, Swift, and Kotlin — flag byte + 4-byte length header, tested against MTU 23 (BLE 4.0), 247 (typical), and 517 (BLE 5.0 max). Maximum message size: 64KB over GATT.

### Background Persistence

- **iOS**: silent AVAudioEngine with `MixWithOthers` keeps the process alive. App Store precedent: PocketServer, Mob framework.
- **Android**: Foreground Service with persistent notification. `START_STICKY` for auto-restart.

## New in the Control Panel

Tab 14 ("Nearby") shows:

- This server's mesh identity (peer_id)
- Active transports (TCP, BLE, MultipeerConnectivity)
- Connected peers with transport type, hop count, encryption status
- Routing table (destination, next hop, hops)
- Encrypted sessions list
- Connect button for manually adding a TCP peer by address

## Test Summary

| Suite | Tests |
|---|---|
| Mesh identity + handshake | 37 |
| Transport registry + events | 52 |
| Encrypted P2P HTTP | 39 |
| Routing + multi-hop | 65 |
| Bloom filter sync | 59 |
| BLE transport simulation | 63 |
| Prolly tree sync | 41 |
| Integration (all wired) | 37 |
| Server (HTTP, panel, workers) | 71 |
| **Total** | **464** |

## Source Files (Mesh Layer)

| File | Lines | Purpose |
|---|---|---|
| Mesh.php | 718 | Identity, handshake, ECDH, AES-256-GCM |
| Transport.php | 692 | Peer registry, events, API, router wiring |
| MeshRouter.php | 537 | Distance-vector routing, FORWARD relay |
| MeshSync.php | 450 | Bloom filters, conflict resolution, sync flow |
| MeshBLE.php | 332 | Chunking protocol, rate limiter, simulator |
| ProllyTree.php | 448 | Content-addressed tree, O(d·log n) diff |
| TransportManager.swift | 844 | iOS: LAN + MC + BLE, auto-fallback |
| TransportManager.kt | 658 | Android: LAN + BLE, same protocol |

## Upgrading from v1.x

No breaking changes. All v1.x configuration, APIs, and behavior are preserved. The mesh layer is additive — it activates when peers are discovered and does nothing when running as a standalone server.

The mesh identity keypair is generated automatically on first run and stored in the data directory. Deleting it generates a new identity (same as losing an Ethereum wallet).

## What Was in v1.5

Everything from v1.5 is included: ECDSA M-of-N signing, Sigstore Rekor transparency log, SQLite auto-provisioning, PHP 8.6 Io\Poll epoll driver, Metrics/Analytics with clickstream, autohost for 15 frameworks, 4-platform CI, `--pack` single-binary mode, Windows COW fork.

## Fixes in this build

- **Framework code in namespaces.** The source rewriter emitted `Q_WebServer_Compat::_header(...)` without a leading backslash, so inside any `namespace` block PHP looked for `Vendor\Namespace\Q_WebServer_Compat` and the request failed with "class not found". Every Symfony, Laravel and Drupal response goes through a namespaced `Response::sendHeaders()`, so all three returned 500. Shims are now emitted fully qualified. Regression test: `tests/test_compat_namespace.php`.
- **`/Q/sync/*` and `/Q/api/transport/*` returned 500 for every request.** The raw query string was passed to `array_merge()`, which throws on a string. These are the endpoints the native TransportManager and remote peers call, so no peer could connect over HTTP.
- **Peer requests hung.** A server receiving an encrypted request from a peer made an HTTP call back to its own port from inside its event loop, which could never be answered, and used an undeclared `$listenPort` that always resolved to 8080. Requests are now dispatched in-process through the router.
- **A proxied peer response could crash the server.** API results whose `status` field was a string (`"ok"` from a peer's `/Q/health`) were used as the HTTP status code, and the metrics recorder then failed on arithmetic with a string. API status codes are now validated and metrics casts to int.
- **Bloom filters saturated.** Sync filters were a fixed 1KB, which gave a 93% false-positive rate at 5,000 keys, meaning most missing records were never sent. Filters are now sized to the table (about 1.8 bytes per key, ~0.1% false positives).
- `tests/test_mesh_e2e.sh` (two real servers: identity, handshake, encrypted request) now passes 14/14, and runs in CI with the other mesh suites.

New docs: [compatibility.md](docs/compatibility.md) (rewritten), [images.md](docs/images.md), [sync.md](docs/sync.md), [mobile.md](docs/mobile.md), [app-mode.md](docs/app-mode.md). The phar now bundles `docs/` so `/Q/docs` works from it.

Domains set up in the control panel now survive a restart. The server read `local/panel.json` relative to the folder it was started from, while the panel saves to the app folder or data directory, so after a restart the domain fell back to the welcome page until it was removed and added again. The server now loads the panel's own file as well, and moves its `domains` into `Q.webserver.domains` the same way on startup, on the 3-second watcher and on reload (2.3 never merged panel domains at startup at all).

The SPA fallback (`Q.webserver.fallback`) called a method that does not exist and failed the request; it now serves the file.

The server no longer asks for certificates at startup for a domain marked as proxied, or for a domain the panel added while the server only speaks HTTP, because TLS is handled in front of it. "Provision" in the panel still works. When the panel request came through a CDN (Cloudflare, CloudFront, Fastly, Akamai, Sucuri, Imperva, Bunny, Azure Front Door, Vercel, Netlify, or any proxy that sends forwarding headers) the server says so and asks for confirmation first, explaining that the CDN ends HTTPS itself and that a Let's Encrypt check would land on the CDN.

Domains: "Remove" is shown only for domains the panel itself manages, with a tooltip and confirmation that say it removes the panel's entry and nothing else (no certificate, DNS or files). The "Select another domain" dropdown for an app starts on a disabled placeholder, with the button disabled until a domain is chosen, and the row is left out when no other domain remains. An app's detail page now opens on a Domains tab in place of Actions and the separate Domains column; Configure and the editor buttons moved to the row above the tabs.

Every panel button gets a tooltip that says what it does, and all panel text can be translated. The language is picked from the browser (or `?lang=es`, remembered) and a selector in the sidebar lists the catalogs found in `src/Q/WebServer/lang/<code>.json` and `local/lang/<code>.json` (the second overrides the first). A catalog maps English text to its translation, with `{name}` placeholders allowed. "Export strings for translation" in the selector downloads every string the page has shown so far as a starting catalog. A Spanish starter catalog is included; the rest of the strings stay in English until a catalog covers them.

Binary Attestation no longer shows "FAILED" for a build that has no signatures; the status appears only once there is at least one signature, and without any the panel explains that the build is unsigned and what signing does. "Cron" is now called "Scheduled"

The startup certificate skip now covers every domain in the panel's own config (Add, Enable and the app dropdown all write there), not only ones tagged as added by the panel, whenever the server is not serving HTTPS itself. A request that arrives with a CDN's own headers (Cloudflare's `cf-ray` and the like) is also remembered on the domain as `cdn`, in memory and in the panel config; startup then never requests a certificate for it, and the Domains tab shows "Behind Cloudflare" in place of "no cert" (3.2).

Workers: Uptime showed "NaNm" because the panel passed the dashboard's formatted string ("2h 3m") to a function that expects seconds. It now passes seconds..
