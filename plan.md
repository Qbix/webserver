# Qbix Server 2.0 — Release Plan

## What's Already Done

The mesh protocol is fully implemented and tested (464 tests, 0 failures):

- **Mesh.php** (718 lines) — ECDSA P-256 identity, 4-step handshake, ECDH session keys, AES-256-GCM encryption
- **Transport.php** (692 lines) — peer registry, event system (onPeerOnline/Offline/Message), API endpoints, router + rate limiter wiring
- **MeshRouter.php** (537 lines) — distance-vector routing, HELLO/BYE/HEARTBEAT/FORWARD, TTL, dedup, dampening, cascade removal
- **MeshSync.php** (423 lines) — Bloom filter sync, pluggable conflict resolution, full sync protocol
- **MeshBLE.php** (332 lines) — BLE chunking protocol, per-peer rate limiter, transport simulator
- **ProllyTree.php** (448 lines) — deterministic content-addressed tree, O(d·log n) diff
- **iOS TransportManager.swift** (844 lines) — unified LAN + MultipeerConnectivity + BLE with auto-fallback
- **Android TransportManager.kt** (658 lines) — unified LAN (NSD) + BLE GATT, same chunking protocol
- Server startup wires mesh identity, banner prints Mesh ID
- `/Q/sync/*` endpoints routed in both main and worker paths
- Panel "Nearby" tab with identity, transports, peers, sessions
- Metrics 502/504 paths wired with cookies/clientIp/userAgent
- GitHub Actions 6-platform CI (4 desktop + 2 mobile experimental)

## What Remains — 2 Turns

### Turn 1: Version Bump + Docs + Panel Polish

**Version bump** (mechanical, every file):
- `qbixserver.php` line 23: `1.5.0` → `2.0.0`
- `docs/Mesh.md`: update any `1.5.0` references
- Server header automatically picks up the new version constant

**README.md rewrite** — the current README covers v1.0 features (HTTP serving, static files, PHP dispatch). Needs a new section covering:
- Mesh protocol overview (identity, handshake, encrypted sessions)
- Peer-to-peer: `handleUsingRemote('qbix-peer://...')`
- Data sync (Bloom filters, prolly trees, conflict resolution)
- Mobile: iOS + Android, BLE + LAN, background persistence
- Transport priority: TCP > MultipeerConnectivity > BLE
- Quick-start example: two servers discovering each other

**RELEASE_NOTES.md** — write v2.0 notes. The current notes cover v1.5 (Trust, Rekor, Metrics, etc.). v2.0 adds the mesh. Structure:
- What changed: "server" → "mesh-networked runtime"
- Mesh protocol (identity, encryption, routing, sync)
- Mobile platforms (iOS, Android, BLE, LAN)
- Transport layer (auto-negotiation, fallback, chunking)
- Full test summary (464 tests)
- Upgrade path from v1.x (no breaking changes, mesh is additive)

**Panel "Nearby" tab enhancement** — currently shows peers and sessions. Add:
- Routing table display (destination, next_hop, hops) from `status.routes`
- Known peers count from router
- Sync status (last sync time per peer, records synced)
- Connect button: enter an address, call `transport/connect`

**Bloom → ProllyTree auto-fallback** — wire into MeshSync::syncWithPeer():
- If `estimated_we_missing > 500` or Bloom filter size would exceed 50KB, use prolly tree diff instead
- The pieces exist (MeshSync + ProllyTree), the trigger logic is missing
- One `if` block in syncWithPeer, ~20 lines

**Test**: run all 464 existing tests (nothing should break — these are additive changes). Verify the panel loads with routing data.

### Turn 2: Site + Final Package

**Site rebuild** — the `/Q/docs` viewer site needs:
- Updated landing section with mesh protocol
- Mobile platform section (already partly there from v1.3)
- Architecture diagram showing the mesh topology
- Benchmark table updated with BLE timing from the simulator
- Framework compatibility unchanged (15 frameworks)

**Outreach doc update** — `webserver-outreach.md` needs a new pitch angle:
- "First PHP server with encrypted peer-to-peer mesh networking"
- Mobile deployment angle for NativePHP community
- BitChat comparison (more sophisticated: HTTP over mesh, data sync, not just chat)

**Final build artifacts**:
- Phar rebuild with v2.0.0 version
- Zip with all source, tests, docs, mobile code
- Release zip filename: `qbix-webserver-v2.0.0.zip`

**Test**: full suite (should be 464+), site loads, panel works, zip includes everything.

## File Changes by Turn

### Turn 1
| File | Change |
|---|---|
| `qbixserver.php` | Version `2.0.0` |
| `README.md` | Add mesh/mobile/sync sections |
| `RELEASE_NOTES.md` | Write v2.0 notes |
| `src/Q/WebServer/Panel.php` | Nearby tab: routing table, connect button |
| `src/Q/WebServer/MeshSync.php` | Bloom→ProllyTree fallback in syncWithPeer |
| `docs/Mesh.md` | Version reference update |

### Turn 2
| File | Change |
|---|---|
| `src/Q/WebServer/Panel.php` | Site/docs viewer content update |
| `docs/BENCHMARKS.md` | BLE timing section |
| `webserver-outreach.md` | New pitch angles |
| `bin/qbixserver.phar` | Rebuild |
| `qbix-webserver-v2.0.0.zip` | Final release package |

## What v2.0 Means

v1.x was a PHP web server — it served HTTP requests to browsers.

v2.0 is a mesh-networked runtime — phones discover each other over Bluetooth and Wi-Fi, establish encrypted sessions without any server, synchronize data peer-to-peer, and run PHP applications on every node. The web server is still there, but it's now one transport among several.

The version boundary is where "server" stops being the right word for what this is.
