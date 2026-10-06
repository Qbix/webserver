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
