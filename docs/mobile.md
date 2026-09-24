# iOS & Android

Qbix Server runs on phones. The PHP server listens on `127.0.0.1` inside the app, a WebView (or any HTTP client in the app) talks to it, and a native TransportManager written in Swift or Kotlin finds nearby Qbix Servers and carries requests between them. The PHP side never sees Bluetooth or Wi-Fi: every request, local or from a peer, arrives as HTTP on localhost.

The native sources, GATT UUIDs and chunking format are documented alongside the code in [mobile/README.md](../mobile/README.md). This page covers what you need to know to ship an app.

## PHP runtimes

PHP now runs on both platforms: NativePHP Mobile, php-ios and Phphone embed a PHP 8 interpreter in an app. CI builds `qbixserver-ios-arm64.phar` and `qbixserver-android-arm64.phar` with static-php-cli as experimental jobs; the native shell embeds the phar and starts it on launch.

## Transports

When a peer is reachable more than one way, the fastest available transport is used, and traffic moves to the next one if it drops:

| Priority | Transport | Range | Bandwidth | Platforms |
|---|---|---|---|---|
| 1 | TCP over the local network, found by mDNS/Bonjour | Same Wi-Fi | 100+ Mbps | iOS, Android |
| 2 | MultipeerConnectivity (Bluetooth and peer-to-peer Wi-Fi) | ~200 ft | 2–25 Mbps | iOS only |
| 3 | BLE GATT | ~100 ft per hop | ~2 Mbps | iOS, Android |

BLE is the only transport shared by both platforms, so an iPhone and an Android phone with no common Wi-Fi talk over BLE. BLE writes are 23–517 bytes, so requests and responses are split into chunks with a one-byte flag and a four-byte length; the largest message is 64KB.

## What happens when a peer is found

1. The TransportManager reads the peer's ID from its BLE Identity characteristic or `GET /Q/sync/identity`.
2. It tells the local PHP server with `POST /Q/api/transport/event`.
3. For TCP peers, it calls `POST /Q/api/transport/connect`, which runs the mesh handshake: certificate exchange, ECDSA mutual authentication, ECDH key agreement, then AES-256-GCM for everything after.
4. Your `Q_WebServer_Transport::onPeerOnline()` handlers run. From there you can call `Q_WebServer_Transport::requestFromPeer()` or start a [data sync](sync.md).

## Staying alive in the background

Phones suspend background apps, which would take a mesh node offline.

- **iOS:** `BackgroundKeepAlive.swift` plays silent audio through `AVAudioEngine` with `MixWithOthers`, so the user's own audio keeps playing, and restarts after interruptions such as calls. Requires the `audio` background mode.
- **Android:** `QbixServerService.kt` runs as a foreground service with a persistent notification and `START_STICKY`, so the system restarts it if it is killed.

Both techniques have store precedent: PocketServer on the App Store, KSWeb on Google Play, and BitChat's BLE mesh on both.

## Permissions

**iOS** (`Info.plist`): `UIBackgroundModes` with `audio`, `NSLocalNetworkUsageDescription`, `NSBonjourServices` with `_qbix-server._tcp`, and `NSBluetoothAlwaysUsageDescription`.

**Android** (`AndroidManifest.xml`): `BLUETOOTH_CONNECT`, `BLUETOOTH_ADVERTISE`, `BLUETOOTH_SCAN`, `FOREGROUND_SERVICE`, `FOREGROUND_SERVICE_DATA_SYNC`, and the service declared with `foregroundServiceType="dataSync"`.

The exact XML is in [mobile/README.md](../mobile/README.md#platform-requirements).

## Status

The mobile builds are experimental in CI and the native transport code is exercised by the PHP BLE simulator (`tests/test_mesh_ble.php`) rather than on devices in CI. Direct peer requests over TCP are tested end to end between two servers; see [sync.md](sync.md#current-limitations) for what multi-hop relay does not yet do.

---
[← Data Sync](sync.md) · [← Mesh Protocol](Mesh.md) · [← Back to README](../README.md)
