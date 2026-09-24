# Qbix Server — Mobile Transport Layer

Native transport bridge for running Qbix Server on iOS and Android. Handles peer discovery, transport negotiation, BLE chunking, and mesh identity — the PHP server sees only HTTP on localhost.

## How It Works

The PHP server always listens on `127.0.0.1:port`. The native TransportManager discovers nearby Qbix Servers over every available channel, picks the best transport, and proxies HTTP requests between them. App code calling `handleUsingRemote('qbix-peer://...')` gets BLE mesh, P2P Wi-Fi, or TCP transparently.

### Transport Priority

When a peer is reachable over multiple transports, the manager picks the best one automatically:

| Priority | Transport | Range | Bandwidth | Platforms | Notes |
|---|---|---|---|---|---|
| 1 | TCP (LAN) | Same Wi-Fi | 100+ Mbps | iOS + Android | mDNS/Bonjour discovery |
| 2 | MultipeerConnectivity | ~200ft | 2–25 Mbps | iOS only | Auto-negotiates BT/P2P Wi-Fi |
| 3 | BLE GATT | ~100ft | ~2 Mbps | iOS + Android | Cross-platform, mesh-capable |

If a peer is on the same Wi-Fi and also within BLE range, TCP is used. If Wi-Fi drops, the manager falls back to BLE seamlessly.

### Discovery → Handshake → Routing

When a new peer is discovered (over any transport):

1. **Read identity**: fetch `peer_id` from the peer's Identity characteristic (BLE) or `/Q/sync/identity` (TCP)
2. **Register**: call our PHP server's `/Q/api/transport/event` with `type: "online"`
3. **Handshake**: for TCP peers, call `/Q/api/transport/connect` which performs the mesh ECDH handshake. BLE peers exchange identity via the GATT Identity characteristic
4. **Route**: the PHP MeshRouter adds the peer to its routing table and propagates HELLOs to other connected peers

The PHP server now knows about this peer and can send encrypted requests to it via `Transport::requestFromPeer()`.

## Architecture

```
┌───────────────────────────────────────────┐
│  Mobile App                               │
│                                           │
│  ┌──────────┐   ┌──────────────────────┐  │
│  │ WebView  │──▶│  PHP Server          │  │
│  │          │◀──│  (localhost:port)     │  │
│  └──────────┘   └──────────┬───────────┘  │
│                            │              │
│  ┌─────────────────────────┴───────────┐  │
│  │       TransportManager              │  │
│  │                                     │  │
│  │  ┌───────┐ ┌───────────┐ ┌───────┐ │  │
│  │  │ LAN   │ │Multipeer  │ │ BLE   │ │  │
│  │  │ mDNS  │ │(iOS only) │ │ GATT  │ │  │
│  │  │ TCP   │ │BT+P2P WiFi│ │       │ │  │
│  │  └───────┘ └───────────┘ └───────┘ │  │
│  │                                     │  │
│  │  Transport priority: TCP > MC > BLE │  │
│  │  Auto-fallback on transport loss    │  │
│  └─────────────────────────────────────┘  │
│                                           │
│  ┌─────────────────────────────────────┐  │
│  │  Background Persistence             │  │
│  │  iOS: silent AVAudioEngine          │  │
│  │  Android: Foreground Service        │  │
│  └─────────────────────────────────────┘  │
└───────────────────────────────────────────┘
```

## BLE GATT Service

All platforms use the same UUIDs and chunking protocol for cross-platform BLE:

| Characteristic | UUID | Properties | Purpose |
|---|---|---|---|
| Service | `0000FB10-…` | — | Qbix Server BLE service |
| Request | `0000FB11-…` | Write | Client sends chunked HTTP request |
| Response | `0000FB12-…` | Notify | Server sends chunked HTTP response |
| Identity | `0000FB13-…` | Read | Peer's mesh_id, name, port (JSON) |

### Chunking Protocol

BLE has a maximum transfer unit (MTU) of 23–517 bytes per write. HTTP requests and responses are larger, so we chunk them. The protocol matches `MeshBLE.php` exactly — the same code runs in PHP tests and native bridges:

```
Single chunk (fits in MTU):  [0x03][4-byte big-endian length][payload]
First chunk:                 [0x01][4-byte big-endian length][payload…]
Middle chunk(s):             [0x02][payload…]
Last chunk:                  [0x03][payload…]
```

Maximum message size: 64KB. Flag bytes: `0x01` = first, `0x02` = middle, `0x03` = last/only.

The receiver accumulates chunks until it sees `0x03`, then reassembles and processes the complete HTTP request.

## Files

### iOS (`ios/`)

**TransportManager.swift** (844 lines) — unified transport manager:
- Discovers peers over LAN (NWBrowser/Bonjour), MultipeerConnectivity, and BLE
- Tracks peers by `peer_id` with multi-transport deduplication (same peer found over both LAN and BLE gets one entry with both transports listed)
- BLE peripheral (GATT server) + BLE central (scanner) with the standard chunking protocol
- Registers peers with PHP via `/Q/api/transport/event`
- Triggers mesh handshake for TCP peers via `/Q/api/transport/connect`
- Falls back automatically when a transport is lost

**BackgroundKeepAlive.swift** (103 lines) — silent AVAudioEngine loop:
- `MixWithOthers` so user's music continues
- Auto-restarts after interruptions (phone calls, Siri)
- Requires `UIBackgroundModes: [audio]` in Info.plist

### Android (`android/`)

**TransportManager.kt** (658 lines) — unified transport manager:
- Discovers peers over LAN (NSD/mDNS) and BLE GATT
- Same chunking protocol, same GATT UUIDs as iOS
- Registers and handshakes with PHP the same way

**QbixServerService.kt** (76 lines) — Foreground Service:
- Persistent notification, `START_STICKY` for auto-restart
- Starts TransportManager on creation

### Mesh Protocol (PHP, in `src/Q/WebServer/`)

The native bridge calls these PHP endpoints:

| Endpoint | Called by | Purpose |
|---|---|---|
| `GET /Q/sync/identity` | TransportManager | Fetch our mesh peer_id + cert |
| `POST /Q/api/transport/event` | TransportManager | Register/unregister peers |
| `POST /Q/api/transport/connect` | TransportManager | Handshake with a TCP peer |
| `POST /Q/sync/handshake` | Remote peer | ECDH key exchange |
| `POST /Q/sync/request` | Remote peer | Encrypted HTTP request relay |

## Platform Requirements

### iOS (Info.plist)

```xml
<key>UIBackgroundModes</key>
<array><string>audio</string></array>
<key>NSLocalNetworkUsageDescription</key>
<string>Qbix Server connects with nearby devices on your network.</string>
<key>NSBonjourServices</key>
<array><string>_qbix-server._tcp</string></array>
<key>NSBluetoothAlwaysUsageDescription</key>
<string>Qbix Server connects with nearby devices over Bluetooth.</string>
```

### Android (AndroidManifest.xml)

```xml
<uses-permission android:name="android.permission.BLUETOOTH_CONNECT" />
<uses-permission android:name="android.permission.BLUETOOTH_ADVERTISE" />
<uses-permission android:name="android.permission.BLUETOOTH_SCAN" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE_DATA_SYNC" />

<service android:name=".QbixServerService"
    android:foregroundServiceType="dataSync" android:exported="false" />
```

## GitHub Actions CI

The CI builds mobile transport bundles as experimental jobs:

- **build-android**: cross-compiles PHP to `aarch64-linux-android` via static-php-cli + Android NDK. Produces `qbixserver-android-arm64.phar` — a phar archive the native shell embeds.
- **build-ios**: builds PHP as a static library via static-php-cli on macOS. Produces `qbixserver-ios-arm64.phar` — embedded by the Swift app via `PhpBridge`.

Both jobs use `continue-on-error: true` so mobile build failures don't block the desktop release. The native TransportManager files (`.swift`, `.kt`) are bundled into the release zip under `mobile/`.

## App Store Precedent

All techniques used here have App Store / Play Store precedent:

- **PocketServer** — HTTP server on iOS with background audio, App Store approved
- **BitChat** (Jack Dorsey) — BLE mesh messaging, both stores, July 2025
- **KSWeb** — Apache+PHP+MySQL on Android, Play Store, Foreground Service
- **NativePHP Mobile** — PHP 8.4 embedded on iOS/Android, App Store approved
