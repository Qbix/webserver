# Qbix Server Mesh Protocol — Design Document

## 1. Overview

A peer-to-peer mesh layer that lets Qbix Server instances discover each other, establish encrypted connections, and exchange HTTP requests over Bluetooth (BLE GATT), MultipeerConnectivity (iOS), Wi-Fi Direct (Android), or TCP (LAN/internet). The PHP server always listens on localhost. The native bridge handles transport. The mesh layer handles routing, encryption, and identity.

## 2. Identity

A peer's identity is the SHA-256 hash of its ECDSA P-256 public key, encoded as lowercase hex. 64 characters. No external registration, no CA, no blockchain. Identity IS the key.

```
peer_id = hex(sha256(public_key_der))
```

The keypair is generated once on first run and stored in the data directory alongside the existing Trust.php keys. The self-signed X.509 cert embeds the public key and the peer_id as the Common Name.

**Persistence:** The keypair lives at `QBIX_DATA_DIR/keys/mesh.key` and `mesh.crt`. If the data directory is wiped, a new identity is generated. The old identity is gone — same as losing an Ethereum wallet.

**Human-readable names:** The peer_id is the cryptographic identity. The device also advertises a human-readable name (device name or user-chosen alias). The name is NOT the identity — two devices can share a name. The name is for display in the panel; the peer_id is for routing and verification.

## 3. Discovery

### 3.1 BLE Advertising

Each device advertises a BLE GATT service with UUID `QB1X0001-...01`. The advertisement includes:
- Service UUID (for scanning filters)
- Local name: "QS:{first8 of peer_id}" (e.g. "QS:a3f7c291")

The full peer_id is NOT in the advertisement (BLE advertisement payload is 31 bytes max). The full ID is exchanged during the handshake after connection.

### 3.2 MultipeerConnectivity (iOS only)

Service type: `qbix-server`. Discovery info: `{"id": "{first16 of peer_id}"}`. MultipeerConnectivity handles transport negotiation (Bluetooth vs P2P Wi-Fi) automatically.

### 3.3 LAN Discovery

Multicast DNS (mDNS/Bonjour): `_qbix-server._tcp` with TXT record containing `id={peer_id}`. Works on all platforms. Falls back to UDP broadcast on port 5353 if mDNS is unavailable.

### 3.4 Manual Connection

For internet-connected servers: specify `host:port` directly. The handshake proceeds over TCP. This is how `handleUsingRemote` works today — the mesh just adds BLE/Multipeer as additional transports.

## 4. Handshake

After discovery, two peers perform a handshake to verify identity and establish an encrypted session.

```
Step 1: Cert Exchange
  A → B: {cert_a, nonce_a}
  B → A: {cert_b, nonce_b}
  
  Both verify: sha256(cert.public_key) == advertised peer_id?
  If not, disconnect immediately.

Step 2: Mutual Authentication  
  A → B: sign(nonce_b, private_key_a)
  B → A: sign(nonce_a, private_key_b)
  
  Both verify the signature against the cert's public key.
  This proves possession of the private key — not just presenting a cert.

Step 3: ECDH Key Agreement
  A generates ephemeral keypair (ea_pub, ea_priv)
  B generates ephemeral keypair (eb_pub, eb_priv)
  A → B: ea_pub
  B → A: eb_pub
  
  shared_secret = ECDH(ea_priv, eb_pub) = ECDH(eb_priv, ea_pub)
  session_key = HKDF-SHA256(shared_secret, salt=nonce_a||nonce_b, info="qbix-mesh")
  
Step 4: Session Established
  All subsequent messages encrypted with AES-256-GCM using session_key.
  Each message has an incrementing 96-bit nonce (never reuse).
```

The handshake takes 2 round trips. Over BLE at ~10ms latency, that's ~40ms. Over LAN at ~1ms, it's ~4ms.

## 5. Star Topology (Direct Connections)

The default and most common topology. One device connects directly to another. No relays, no routing table.

```
    ┌──── Client A
    │
Server ──── Client B
    │
    └──── Client C
```

Each client has a session with the server. The server has sessions with all clients. Requests flow directly between connected peers. This covers: classroom (teacher + students), field work (coordinator + workers), local sharing (my phone + my laptop), LAN deployment (server + browser clients).

No mesh routing needed. The PHP server sees HTTP requests arriving on localhost from the native bridge. Each request has an `X-Peer-Id` header added by the bridge so the PHP app knows which peer it came from.

## 6. Mesh Topology (Multi-Hop)

When peers are out of direct BLE range, intermediate nodes relay traffic. This is optional — star topology works without it. Mesh activates only when a peer is reachable through another peer but not directly.

### 6.1 Route Announcement

When a device comes online, it broadcasts a HELLO message to all directly connected peers:

```json
{
  "type": "hello",
  "peer_id": "a3f7c291...",
  "name": "Greg's iPhone",
  "hops": 0,
  "timestamp": 1695400000,
  "ttl": 7
}
```

Each recipient:
1. Adds/updates a routing entry: `(destination=a3f7c291, next_hop=<direct_connection>, hops=0)`
2. Re-broadcasts with hops+1 and ttl-1 (if ttl > 0)
3. Does NOT re-broadcast if it already has a route with fewer or equal hops (loop prevention)

### 6.2 Routing Table

Each device maintains:

```
routing_table = {
  peer_id => {
    next_hop: peer_id of directly connected neighbor to forward through,
    hops: number of hops to reach this peer,
    last_seen: timestamp of most recent hello/heartbeat,
    name: human-readable name (display only)
  }
}
```

Route selection: shortest path (fewest hops). Ties broken by most recent last_seen. Routes expire after 60 seconds without a heartbeat.

### 6.3 Heartbeat

Every 15 seconds, each device sends a heartbeat to all directly connected peers:

```json
{
  "type": "heartbeat",
  "peer_id": "a3f7c291...",
  "known_peers": ["def456...", "789abc..."],
  "timestamp": 1695400015
}
```

The `known_peers` list lets neighbors detect route changes without full re-announcement. If a neighbor's `known_peers` includes a peer you don't have a route to, request a full HELLO for that peer.

### 6.4 Route Expiry

If a peer's route entry has `last_seen` older than 60 seconds, remove it and broadcast a BYE:

```json
{
  "type": "bye",
  "peer_id": "a3f7c291...",
  "reason": "timeout"
}
```

Neighbors remove the route and re-broadcast the BYE (with ttl).

### 6.5 Forwarding

When a device receives a message for a peer_id that isn't directly connected:

1. Look up `routing_table[peer_id]`
2. If found, forward the encrypted message to `next_hop`
3. If not found, return an error to the sender

The forwarding node cannot read the message — it's encrypted end-to-end between the original sender and the destination. The forwarder only sees: `{from: peer_id, to: peer_id, encrypted_payload: bytes}`.

## 7. Encryption Layers

### 7.1 Link Encryption (BLE)

The OS provides BLE link-layer encryption (LE Secure Connections). This protects against passive eavesdropping on the radio. But relay nodes can still read traffic without additional encryption.

### 7.2 Session Encryption (End-to-End)

The ECDH-derived session key encrypts all HTTP traffic between two specific peers. Even if the message routes through 5 intermediate nodes, only the endpoints can decrypt it. Each message:

```
encrypted_message = {
  from: peer_id_sender,      // plaintext (routing needs it)
  to: peer_id_destination,    // plaintext (routing needs it)
  nonce: 12_bytes,            // incrementing, never reused
  ciphertext: AES-256-GCM(session_key, nonce, http_request_or_response),
  tag: 16_bytes               // GCM authentication tag
}
```

### 7.3 Application TLS (Optional)

For `handleUsingRemote` calls between two Qbix Servers that want standard TLS semantics, they can establish a TLS connection over the encrypted session. This is double encryption but provides standard HTTP client/server semantics that existing PHP code expects. In practice, the session encryption (7.2) is sufficient and TLS is unnecessary overhead for BLE.

## 8. PHP Integration

### 8.1 Transport.php Peer Events

```php
// App registers handlers
Q_WebServer_Transport::onPeerOnline($handler);    // new peer connected
Q_WebServer_Transport::onPeerOffline($handler);   // peer disconnected
Q_WebServer_Transport::onPeerMessage($handler);   // arbitrary message from peer
```

The native bridge calls `/Q/api/transport/event` when peers connect/disconnect. Transport.php dispatches to registered handlers.

### 8.2 Peer Registry

Transport.php maintains:

```php
$peers = [
  'a3f7c291...' => [
    'name' => "Greg's iPhone",
    'transport' => 'ble',       // ble, multipeer, tcp, wifi_direct
    'direct' => true,           // directly connected or routed?
    'hops' => 0,                // 0 = direct
    'lastSeen' => 1695400015,
    'sessionEstablished' => true,
    'capabilities' => ['sync', 'relay'],  // what this peer supports
  ]
];
```

### 8.3 handleUsingRemote Integration

```php
// Existing Qbix mechanism — no changes needed
Q::handleUsingRemote($peerUrl, $method, $path, $data);

// peerUrl can now be:
//   https://example.com/api/...       — internet server (existing)
//   http://192.168.1.50:8080/...      — LAN server (existing)  
//   qbix-peer://a3f7c291.../...       — mesh peer (new)

// The qbix-peer:// scheme triggers Transport::forwardToPeer()
// which routes through the native bridge over whatever transport
// reaches that peer (BLE, Multipeer, relay).
```

### 8.4 Sync Protocol Endpoints

Built-in endpoints that any Qbix app can use:

```
GET  /Q/sync/identity       — returns this peer's ID and cert
POST /Q/sync/handshake      — cert exchange + ECDH (called by native bridge)
GET  /Q/sync/bloom?table=X&since=T  — Bloom filter of changes since T
POST /Q/sync/diff            — given two Bloom filters, return the diff
POST /Q/sync/records         — fetch specific records by key
GET  /Q/sync/tree?table=X&root=R    — prolly tree node
POST /Q/sync/merge           — receive records from a peer and merge
```

### 8.5 Sync Flow (onPeerOnline)

```
1. Peer connects, handshake completes
2. Native bridge calls /Q/api/transport/event {type: "online", peer_id: "..."}
3. Transport.php dispatches onPeerOnline handlers
4. App handler:
   a. GET /Q/sync/bloom?table=messages&since=<lastSync>  (from peer)
   b. Compare with local Bloom filter
   c. If diff < 100 records: POST /Q/sync/records to fetch missing
   d. If diff > 100 records: compare prolly tree roots, walk divergent branches
   e. POST /Q/sync/merge to send our records the peer is missing
5. Both sides now synchronized
```

## 9. BLE Payload Limits

BLE MTU is typically 23 bytes (legacy) to 517 bytes (BLE 5.0 with DLE). An HTTP request is usually 200-2000 bytes. An HTTP response can be much larger.

**Chunking protocol:**

```
First chunk:   [0x01][2-byte total_length][payload...]
Middle chunks: [0x02][payload...]
Last chunk:    [0x03][payload...]

Receiver accumulates chunks until 0x03, then processes the complete message.
```

Maximum message size: 64KB (2-byte length field). For larger payloads, use L2CAP CoC (Connection-oriented Channels) which provides stream semantics — available on iOS 11+ and Android 10+.

For the typical `handleUsingRemote` JSON payload (200-2000 bytes), 1-4 BLE writes suffice.

## 10. Edge Cases and Failure Modes

### 10.1 Peer Goes Offline Mid-Request

Request was sent, peer disappears before responding.

**Handling:** The native bridge has a 15-second timeout per request. If no response arrives, it returns HTTP 504 Gateway Timeout to the PHP server. The PHP app handles 504 the same way it handles any failed remote call — retry or degrade.

If the peer was a relay (forwarding through it), the routing table entry expires after 60 seconds. The sender will discover an alternative route or get a "no route to peer" error.

### 10.2 Split Brain (Two Groups Merge)

Devices A-B-C are one mesh. Devices D-E-F are another. Device C walks into range of device D. The meshes merge.

**Handling:** C and D perform the standard handshake. C's HELLO propagates through D-E-F. D's HELLO propagates through A-B-C. Within 2 heartbeat cycles (30 seconds), all 6 devices have full routing tables. The Bloom filter sync triggers on each new peer, bringing everyone up to date.

**Conflict resolution:** Two devices may have modified the same record while in separate meshes. This is a standard CRDT/vector-clock problem. The sync protocol detects conflicts (same key, different values, neither is ancestor of the other) and delegates to the app's merge function. Qbix Streams already has conflict resolution logic.

### 10.3 Routing Loops

A → B → C → A could form a loop if routing tables are inconsistent.

**Prevention:** Each forwarded message has a TTL (max 7). Decremented on each hop. TTL=0 means drop. Additionally, each message has a unique message_id. Each node maintains a small seen-message cache (LRU, 1000 entries). If a message_id is already in the cache, drop it (loop detected).

### 10.4 Man-in-the-Middle

Attacker M tries to intercept communication between A and B by advertising as both.

**Prevention:** The peer_id is the hash of the public key. During handshake, A verifies that B's public key hashes to B's advertised peer_id. M cannot produce a key that hashes to B's ID without breaking SHA-256. M can only present its own key with its own ID — which A will see is not B.

If A has never communicated with B before, A doesn't know B's peer_id. The first connection is trust-on-first-use (TOFU). After the first connection, A pins B's peer_id and will reject a different key in the future. Same security model as SSH known_hosts.

For higher security: exchange peer_ids out-of-band (QR code, the Qbix app's existing invite system). Then even the first connection is verified.

### 10.5 Large Payloads (Images, Files)

A 5MB image over BLE at 2 Mbps = 20 seconds. Feasible but slow.

**Handling:** For payloads over 64KB, use L2CAP CoC (stream-oriented BLE channel) instead of GATT characteristics. If L2CAP is unavailable, fall back to GATT chunking but warn the PHP app about expected latency via a `X-Transport-Bandwidth: low` header so it can adapt (e.g., send thumbnail instead of full image).

For Wi-Fi Direct or MultipeerConnectivity: no payload limit concern — bandwidth is 25+ Mbps.

### 10.6 iOS Backgrounding Kills Connections

App is backgrounded, iOS suspends it after ~5 seconds.

**With BackgroundKeepAlive (silent audio):** Process stays alive. BLE connections persist. Server keeps listening. Connections from other devices continue to work.

**Without BackgroundKeepAlive:** BLE connections drop. MultipeerConnectivity sessions survive briefly (30s grace period). On resume, all connections re-establish automatically. The routing table has stale entries that get pruned on the next heartbeat cycle.

**Mitigation:** When the native bridge detects the app entering the background without KeepAlive, it sends a "going-away" BYE to all connected peers so they prune routes immediately instead of waiting for timeout.

### 10.7 Clock Skew Between Devices

Timestamps are used for last_seen, heartbeats, and sync ordering.

**Handling:** All timestamps in the mesh protocol are relative, not absolute. "Last seen 15 seconds ago" not "last seen at 16:43:02 UTC." The sync protocol uses logical clocks (Lamport timestamps or vector clocks) for ordering records, not wall-clock time. This makes the protocol immune to clock skew.

### 10.8 Many Peers Joining Simultaneously (Event Scenario)

500 people at a conference all open the app at once.

**Handling:** BLE can't handle 500 direct connections. The star topology breaks down. Instead:
- Each device connects to at most 7-8 nearby devices (BLE central limit)
- The mesh routing handles multi-hop to reach further devices
- HELLO flooding is bounded by TTL=7 and the seen-message cache
- Heartbeat interval increases adaptively: if you have >20 known peers, heartbeat every 30s instead of 15s
- The sync protocol uses Bloom filters specifically because they're O(1) to compare regardless of dataset size

For the conference scenario, the real bottleneck is BLE radio bandwidth shared among many devices, not the protocol. MultipeerConnectivity (which uses P2P Wi-Fi at 25 Mbps) handles this much better on iOS. Android Wi-Fi Direct similarly.

### 10.9 Malicious Peer (Denial of Service)

A peer sends garbage data, huge payloads, or floods requests.

**Handling:**
- Per-peer rate limiting in the native bridge: max 100 requests/minute per peer
- Maximum message size enforced at the BLE level: 64KB per message
- The PHP server's existing rate limiting applies to transport-proxied requests
- Peers that exceed limits get disconnected and temporarily banned (exponential backoff: 30s, 60s, 120s, ...)
- The native bridge tracks per-peer bandwidth and disconnects peers that consume more than their share

### 10.10 NAT Traversal (Internet Peers)

Two Qbix Servers on different home networks want to connect over the internet.

**Not in scope for v1.** Mesh is for nearby devices (BLE range + same LAN). Internet connectivity uses standard `handleUsingRemote` over HTTPS to a server with a public IP or domain. NAT traversal (STUN/TURN/ICE) is a future extension that would enable internet mesh — but it's a fundamentally different problem from local mesh.

### 10.11 Replay Attacks

Attacker records an encrypted request and replays it later.

**Prevention:** Each encrypted message uses an incrementing nonce. The receiver tracks the highest nonce seen per sender. Any message with a nonce ≤ the current high-water mark is rejected. On session re-establishment (reconnect), the nonce resets but the session key changes (new ECDH ephemeral keys), so old messages won't decrypt.

### 10.12 Identity Collision

Two devices independently generate keypairs that produce the same peer_id (SHA-256 collision).

**Probability:** 2^-256 for a targeted collision, 2^-128 for a birthday collision among all devices. At 10 billion devices, the probability is ~10^-19. Not a practical concern. If it somehow happens, both devices would fail to authenticate to each other (wrong private key for the shared ID). The affected user generates a new keypair.

### 10.13 Stale Session Keys After Long Disconnection

Two peers established a session, then didn't communicate for days. The session key is old.

**Handling:** Session keys have a maximum lifetime of 24 hours. After that, the next request triggers an automatic re-handshake. The native bridge tracks session age and initiates re-keying transparently. The PHP layer doesn't see this — it just sees a brief delay on the first request after re-keying.

### 10.14 Partial Sync Failure

Peer goes offline in the middle of the Bloom-filter-to-records sync sequence.

**Handling:** The sync protocol is idempotent. Each record transfer is independent. If the sync gets interrupted after transferring 50 of 100 records, the next `onPeerOnline` event runs the sync again. The Bloom filter comparison shows only the remaining 50 records as missing. No rollback needed, no transaction log, no corruption risk. The same applies to prolly tree sync — each tree node request is independent.

### 10.15 Conflicting Route Announcements (Oscillation)

Device A is reachable through both B (2 hops) and C (3 hops). B goes down, routes reconverge through C. B comes back, routes flip. Repeated flapping causes oscillation.

**Handling:** Route changes have a dampening timer. If a route to the same destination changes more than 3 times in 60 seconds, the route is marked "unstable" and held for 30 seconds before further changes are accepted. This prevents oscillation at the cost of slightly slower reconvergence for flapping peers. Standard BGP-style route dampening, simplified.

### 10.16 BLE Interference in Crowded RF Environment

Conference with 500 BLE devices, many non-Qbix (AirPods, watches, beacons). The 2.4GHz band is saturated.

**Handling:** BLE 5.0 adaptive frequency hopping already mitigates this at the radio level. At the application level: increase connection interval when bandwidth isn't needed (saves radio time), prefer MultipeerConnectivity's P2P Wi-Fi (5GHz band, less crowded) on iOS, and fall back to LAN TCP if Wi-Fi infrastructure is available. The native bridge reports transport quality via `X-Transport-Bandwidth` headers so the PHP app can adapt (e.g., don't try to sync large files over congested BLE).

### 10.17 Peer Impersonation After Device Theft

Someone steals a device and now has its mesh private key.

**Handling:** Same as Ethereum wallet theft — the key is compromised. The device owner generates a new keypair on a new device. Other peers who had pinned the old peer_id need to be told to update their trust list. This can be handled at the Qbix app layer: the user's Qbix account links to their peer_id, and updating the link (signed by the account key) revokes the old device. The mesh protocol itself has no revocation — the Qbix identity layer handles it.

## 11. Protocol Messages Summary

| Type | Direction | Purpose | Payload |
|---|---|---|---|
| HELLO | broadcast | Announce presence | peer_id, name, hops, ttl, capabilities |
| BYE | broadcast | Announce departure | peer_id, reason |
| HEARTBEAT | to direct peers | Keep routes alive | peer_id, known_peers, timestamp |
| HANDSHAKE_1 | A→B | Cert + nonce | cert_der, nonce |
| HANDSHAKE_2 | B→A | Cert + nonce + signature | cert_der, nonce, sig(nonce_a) |
| HANDSHAKE_3 | A→B | Signature + ECDH pubkey | sig(nonce_b), ecdh_pub |
| HANDSHAKE_4 | B→A | ECDH pubkey | ecdh_pub |
| REQUEST | A→B | Encrypted HTTP request | from, to, nonce, ciphertext, tag |
| RESPONSE | B→A | Encrypted HTTP response | from, to, nonce, ciphertext, tag |
| FORWARD | relay | Relay encrypted message | from, to, ttl, msg_id, encrypted_payload |

## 12. Implementation Plan

### Turn 1: Identity + Handshake in PHP (~1 session)

**Build:** `Mesh.php` with identity generation (reusing Trust.php's ECDSA P-256), peer_id derivation, cert generation with peer_id as CN, handshake endpoints (`/Q/sync/identity`, `/Q/sync/handshake`), session key derivation via ECDH, AES-256-GCM encrypt/decrypt.

**Test in container:** Two PHP processes on different ports simulate two peers. Process A calls Process B's `/Q/sync/handshake`, they exchange certs, verify peer_ids, derive session keys, and send an encrypted ping/pong. Verify: correct peer_id derivation, mutual authentication, session key agreement, encryption round-trip.

**Deliverable:** A standalone PHP class that can establish an encrypted session between two Qbix Servers over HTTP. No BLE yet — just the cryptographic layer.

### Turn 2: Peer Registry + Events + Panel Tab (~1 session)

**Build:** Transport.php peer registry with `onPeerOnline`/`onPeerOffline`/`onPeerMessage` event hooks. Panel tab (tab 14: "Nearby") showing connected peers, their transport type, hop count, last seen. API endpoints: `/Q/api/transport/peers`, `/Q/api/transport/event`, `/Q/api/transport/config`.

**Test in container:** Start two server instances. Instance A registers as a peer of Instance B via HTTP call to `/Q/api/transport/event`. Verify: peer appears in B's panel, onPeerOnline handler fires, peer list API returns the correct data. Simulate disconnect, verify onPeerOffline fires and peer is removed.

**Deliverable:** Working peer management with UI, events, and API. Still TCP-only — BLE comes later.

### Turn 3: Encrypted Request/Response over TCP (~1 session)

**Build:** Wire the handshake (Turn 1) into the peer connection flow (Turn 2). When a peer registers, automatically perform the handshake. Implement `Transport::requestFromPeer()` that sends an encrypted HTTP request to a peer and returns the decrypted response. Implement the `qbix-peer://` URL scheme for `handleUsingRemote`.

**Test in container:** Three server instances: A, B, C. A connects to B, handshake completes. A sends an encrypted HTTP GET to B for `/Q/health`. Verify: B decrypts, processes, encrypts response, A decrypts. Then test `handleUsingRemote('qbix-peer://B_peer_id/Q/health')` from A — verify it routes through Transport.php and returns B's health response.

**Deliverable:** End-to-end encrypted peer-to-peer HTTP over TCP. The PHP layer is complete — BLE is just a transport swap.

### Turn 4: Routing + Multi-Hop (~1 session)

**Build:** Routing table in Mesh.php. HELLO/BYE/HEARTBEAT message handlers. Route announcement, propagation, expiry. FORWARD message type for relaying encrypted payloads through intermediate nodes. TTL and seen-message deduplication.

**Test in container:** Three servers: A, B, C. A connects to B. B connects to C. A cannot connect directly to C (simulated by not giving A C's address). A sends a request to C's peer_id — verify it routes through B. Kill B — verify A gets a "no route" error after route expiry. Restart B — verify routes reconverge.

**Deliverable:** Multi-hop mesh routing over TCP. The routing logic is transport-agnostic so it works identically over BLE.

### Turn 5: Sync Protocol (Bloom Filters + Record Exchange) (~1 session)

**Build:** Bloom filter implementation in PHP (or use a library). `/Q/sync/bloom`, `/Q/sync/records`, `/Q/sync/merge` endpoints. `onPeerOnline` default handler that runs the Bloom filter sync. Conflict detection (same key, divergent values). Pluggable merge strategy (last-writer-wins default, app can override).

**Test in container:** Two servers with SQLite databases. Insert different records into each. Connect them. Verify: Bloom filter exchange identifies the diff, missing records are fetched, both databases converge. Insert a conflicting record (same key, different value) on both sides before connecting — verify the conflict is detected and the merge strategy resolves it.

**Deliverable:** Automatic data sync between peers on connection. The foundation for offline-first Qbix apps.

### Turn 6: BLE + Native Bridge Integration (~1 session)

**Build:** Refine the Swift and Kotlin code from `mobile/`. Add the chunking protocol. Wire the native bridge to call the PHP handshake and event endpoints. Add BLE-specific headers (`X-Peer-Id`, `X-Transport`, `X-Transport-Bandwidth`). Add rate limiting per peer in the native bridge.

**Test in container:** Can't test real BLE in a container. Instead: write a BLE transport simulator in PHP that speaks the same chunking protocol over TCP. Two server instances communicate through the simulator, which adds artificial latency (10ms per chunk), MTU limits (512 bytes), and random connection drops. Verify: chunking works, large payloads survive, reconnection works, rate limiting triggers correctly.

**Deliverable:** The native code is ready for real-device testing. The PHP side is fully tested via the BLE simulator.

### Turn 7: Prolly Tree Sync (Optional, for large datasets) (~1 session)

**Build:** Prolly tree implementation in PHP. Deterministic chunking based on content hash. `/Q/sync/tree` endpoint that returns tree nodes. Tree comparison algorithm that walks divergent branches. Integration with the sync flow: if Bloom diff > threshold, fall back to prolly tree comparison.

**Test in container:** Two servers with 10,000 records each, 100 records different. Verify: prolly tree sync transfers only ~100 records (plus O(log n) tree nodes), not the full 10,000. Compare with the Bloom filter approach to verify the prolly tree is more efficient for large datasets.

**Deliverable:** Efficient sync for large datasets. Completes the sync protocol.

## 13. What Each Turn Depends On

```
Turn 1: Identity + Handshake          (standalone)
Turn 2: Peer Registry + Events        (standalone)
Turn 3: Encrypted Requests            (depends on 1 + 2)
Turn 4: Routing + Multi-Hop           (depends on 3)
Turn 5: Sync Protocol                 (depends on 2 + 3)
Turn 6: BLE Native Bridge             (depends on 3 + 4)
Turn 7: Prolly Tree Sync              (depends on 5)
```

Turns 1 and 2 can be done in parallel. Turns 5 and 4 can be done in parallel after Turn 3. Turn 7 is optional for v1.

**Minimum viable mesh:** Turns 1-3 (3 sessions). Gives you encrypted peer-to-peer HTTP between Qbix Servers. No multi-hop, no sync, no BLE — but the cryptographic and transport foundations are solid and the protocol is designed so the rest layers on top cleanly.

**Full mesh without BLE:** Turns 1-5 (5 sessions). Gives you encrypted multi-hop mesh with automatic data sync. Works over TCP. BLE is just a transport swap.

**Full mesh with BLE:** Turns 1-6 (6 sessions). Ready for real mobile deployment.

**Full mesh with efficient large-dataset sync:** Turns 1-7 (7 sessions). The complete system.
