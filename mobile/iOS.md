# Qbix Server on iOS

This document explains how the iOS build works — from PHP embedding to App Store submission.

## The Short Version

The app is a native Swift shell that starts an embedded PHP server on `127.0.0.1`, then points a WKWebView at it. No Cordova, no Capacitor, no JavaScript bridge. The PHP process runs your Qbix app exactly as it would on a desktop or VPS. The native layer handles three things the web can't: background persistence, peer-to-peer transport (BLE + MultipeerConnectivity + LAN), and App Store packaging.

## Architecture

```
┌─────────────────────────────────────────┐
│  iOS App (.ipa)                         │
│                                         │
│  AppDelegate                            │
│    ├─ PhpBridge.start() → port          │
│    ├─ TransportManager.start(port)      │
│    ├─ BackgroundKeepAlive.start()        │
│    └─ WebViewController(port)           │
│         └─ WKWebView → http://127.0.0.1:port │
│                                         │
│  ┌──────────────────────────────────┐   │
│  │ PhpBridge                        │   │
│  │  micro binary (self-executing    │   │
│  │  PHP+phar) runs as subprocess    │   │
│  │  on 127.0.0.1:<port>             │   │
│  └──────────────────────────────────┘   │
└─────────────────────────────────────────┘
```

## How PHP Runs on iOS

### The micro binary

[static-php-cli](https://github.com/crazywhalecc/static-php-cli) compiles PHP into a statically linked binary with all extensions baked in. The `spc micro:combine` command fuses that binary with the phar archive, producing a single self-executing file — no PHP interpreter install needed.

The CI workflow (`.github/workflows/release.yml`, job `build-ios`) does this on a macOS runner:

1. Downloads PHP sources and extension sources via `spc download`
2. Builds a statically linked PHP CLI + micro binary via `spc build`
3. Runs `build-phar.php` to create `qbixserver.phar`
4. Combines them: `spc micro:combine bin/qbixserver.phar -O qbixserver-ios-arm64`

The output is a ~15 MB self-contained binary.

### PhpBridge.swift

`PhpBridge` copies the micro binary from the app bundle to the Documents directory (the bundle itself is read-only at runtime), finds a free TCP port by binding a socket to port 0, and launches PHP as a subprocess via `posix_spawn`:

```swift
var spawnPid: pid_t = 0
let cArgs = args.map { strdup($0) } + [nil]
let result = posix_spawn(&spawnPid, phpExe, &fileActions, nil, cArgs, cEnv)
```

`Foundation.Process` is unavailable on iOS, so PhpBridge uses `posix_spawn` directly. After spawning, it polls the server with a TCP connect loop (up to 3 seconds, 100ms intervals) before returning the port.

If the micro binary isn't bundled (during development), PhpBridge falls back to looking for `/usr/bin/php` and passing the phar as an argument. This means you can test in the Simulator without cross-compiling PHP.

### Why posix_spawn and not php_embed?

NativePHP's approach links `libphp.a` into the Swift binary via the `php_embed` SAPI and calls PHP functions directly from Swift. That's viable but adds build complexity — you need a PHP static library cross-compiled for `arm64-apple-ios`, and the embed SAPI ties your app's lifecycle to PHP's.

The subprocess approach is simpler: PHP runs as an isolated process, the app talks to it over HTTP, and a crash in PHP doesn't take down the whole app. After spawning, PhpBridge polls the server port with a TCP connect loop (up to 3 seconds), which reliably handles slow devices.

## Project Structure

When you click "Prepare" in the control panel, the scaffolding generates:

```
apps/YourApp/mobile/ios/
├── project.yml            ← xcodegen spec
├── YourApp/
│   ├── Info.plist
│   ├── AppDelegate.swift
│   ├── WebViewController.swift
│   ├── PhpBridge.swift
│   ├── TransportManager.swift
│   ├── BackgroundKeepAlive.swift
│   └── Resources/
│       └── LaunchScreen.storyboard
│   └── Server/
│       └── (put qbixserver-ios-arm64 or qbixserver.phar here)
├── .gitignore
```

### xcodegen

The scaffolding produces a `project.yml` instead of a hand-written `.xcodeproj` because Xcode project files are notoriously fragile binary plists. [xcodegen](https://github.com/yonaskolb/XcodeGen) reads the YAML spec and generates a valid `.xcodeproj` in one command:

```bash
brew install xcodegen
cd apps/YourApp/mobile/ios
xcodegen generate
open YourApp.xcodeproj
```

If xcodegen is installed when you click Prepare, this runs automatically.

## Background Persistence

iOS suspends apps within seconds of backgrounding. The server needs to stay alive so other devices can reach it.

`BackgroundKeepAlive.swift` starts a silent `AVAudioEngine` loop with the `.mixWithOthers` option. The OS sees an active audio session and keeps the process running. The user hears nothing; any music already playing continues.

This requires `UIBackgroundModes: [audio]` in Info.plist (already set by the scaffolding).

**App Store precedent:** PocketServer, Mob framework, Dala, and other approved apps use this technique. Apple's review guidelines allow background audio for legitimate purposes — in this case, the audio session is a side effect of the server needing to remain responsive.

## Transport Layer

`TransportManager.swift` handles peer discovery and communication over three transports:

| Transport | Range | Bandwidth | How |
|---|---|---|---|
| TCP (LAN) | Same Wi-Fi | 100+ Mbps | NWBrowser / Bonjour mDNS |
| MultipeerConnectivity | ~200ft | 2–25 Mbps | Apple's BT + P2P Wi-Fi framework |
| BLE GATT | ~100ft | ~2 Mbps | CoreBluetooth peripheral + central |

The manager picks the best available transport automatically and falls back if one drops. All three can be active simultaneously — the same peer might be reachable over LAN and BLE, and TransportManager deduplicates by `peer_id`.

BLE uses a custom GATT service with four characteristics (request, response, identity, and the service UUID). HTTP requests larger than the BLE MTU are chunked with a simple framing protocol (see `mobile/README.md` for the wire format).

The PHP server sees only HTTP requests on localhost. It doesn't know or care whether the request came from the local WebView, a LAN peer, or a BLE connection.

## Permissions

The scaffolding sets these in Info.plist:

- **NSAppTransportSecurity / NSAllowsLocalNetworking** — lets WKWebView load `http://127.0.0.1`. Without this, iOS blocks cleartext HTTP even to localhost.
- **UIBackgroundModes: audio** — background persistence via silent audio.
- **NSLocalNetworkUsageDescription** — required for Bonjour/mDNS peer discovery.
- **NSBonjourServices: _qbix-server._tcp** — the service type TransportManager advertises and browses for.
- **NSBluetoothAlwaysUsageDescription** — required for BLE transport.

## Building

### Debug (Simulator)

1. Put `qbixserver.phar` in `YourApp/Server/`
2. Generate the Xcode project (if you haven't already):

```bash
brew install xcodegen   # one-time
cd apps/YourApp/mobile/ios
xcodegen generate
```

3. Open `YourApp.xcodeproj` in Xcode
4. Select a Simulator target and Run (⌘R)

PhpBridge will fall back to the system `php` on macOS, which works in the Simulator. You won't have the micro binary, but the phar runs the same code.

### Release (Device)

1. Get the `qbixserver-ios-arm64` binary from the CI release artifacts (or build it locally — see below)
2. Put it in `YourApp/Server/`
3. In Xcode, set your signing team under Signing & Capabilities
4. Select your device and Build (⌘R), or Archive for distribution (see below)

From the control panel, click "Build" with the iOS platform selected. This runs `xcodebuild` with the right scheme and signing settings.

### What goes in the .ipa

- The micro binary (~15 MB) — the entire PHP runtime plus your Qbix app, bundled into a single self-executing file
- `TransportManager.swift` and `BackgroundKeepAlive.swift` — compiled into the native binary
- Standard Xcode assets (LaunchScreen, Info.plist)

## Signing and Distribution

### Setting up code signing

1. **Apple Developer account** — you need an [Apple Developer Program](https://developer.apple.com/programs/) membership ($99/year) to distribute apps. Free accounts can only run on your own devices.
2. **Signing team** — in Xcode, select your project → Signing & Capabilities tab → set Team to your Apple Developer account. Enable "Automatically manage signing" unless you have a reason to manage certificates manually.
3. **Bundle identifier** — set a unique bundle ID (e.g. `com.yourcompany.yourapp`). This is permanent once you publish to the App Store.
4. **Capabilities** — the scaffolding already sets the required entitlements (background audio, local networking). If you add capabilities like push notifications or iCloud, Xcode updates the provisioning profile automatically.

### TestFlight (beta testing)

1. In Xcode, select **Product → Archive** (make sure a real device or "Any iOS Device" is selected, not a Simulator).
2. When the archive completes, the Organizer opens. Click **Distribute App → App Store Connect → Upload**.
3. In [App Store Connect](https://appstoreconnect.apple.com), your build appears under TestFlight within a few minutes. Add internal testers (up to 25, no review needed) or external testers (up to 10,000, requires a brief review by Apple).
4. Testers install via the TestFlight app on their device.

You can also archive and upload from the command line:

```bash
# Archive
xcodebuild archive \
  -project YourApp.xcodeproj \
  -scheme YourApp \
  -archivePath build/YourApp.xcarchive

# Export for App Store upload
xcodebuild -exportArchive \
  -archivePath build/YourApp.xcarchive \
  -exportPath build/export \
  -exportOptionsPlist ExportOptions.plist

# Upload (requires App Store Connect API key or credentials)
xcrun altool --upload-app \
  -f build/export/YourApp.ipa \
  -t ios \
  --apiKey YOUR_KEY_ID \
  --apiIssuer YOUR_ISSUER_ID
```

### App Store submission

There's nothing unusual about this app from Apple's perspective. It's a native Swift app that launches a background process and shows a WebView. Thousands of apps do this.

1. In [App Store Connect](https://appstoreconnect.apple.com), create a new app with your bundle ID.
2. Fill in the metadata: name, description, screenshots, category, privacy policy URL.
3. Select a build from TestFlight and submit for review.

Points to be aware of during review:

- **Review guideline 2.5.2** — apps that download or execute code. The PHP binary and phar are bundled at build time, not downloaded at runtime. This is the same as any app that embeds a scripting engine (Lua, JavaScript, Python via Pythonista).
- **Review guideline 4.2** — minimum functionality. Your app needs to do more than show a web page. The transport layer (BLE mesh, peer discovery) provides native functionality beyond what a web app can do.
- **Background audio** — reviewers may ask why your app uses background audio. The answer is that the server needs to remain responsive to peer connections. PocketServer and others have been approved with the same approach.

### Ad Hoc / Enterprise distribution

For distributing outside the App Store (within an organization or to specific devices):

1. In the Apple Developer portal, register the UDIDs of target devices (up to 100 per year for Ad Hoc).
2. Create an Ad Hoc provisioning profile listing those devices.
3. Archive in Xcode, then **Distribute App → Ad Hoc** (or **Enterprise** if you have an Enterprise account).
4. The exported `.ipa` can be installed via Apple Configurator, MDM, or `xcrun devicectl device install app --device <udid> YourApp.ipa`.

## Building the Micro Binary Locally

If you want to build the micro binary on your Mac without waiting for CI:

```bash
# Install static-php-cli
curl -fSL https://dl.static-php.dev/static-php-cli/spc-bin/nightly/spc-macos-aarch64 -o spc
chmod +x spc

# Download PHP sources
./spc download --for-extensions="sockets,pdo_sqlite,sqlite3,openssl,mbstring,phar,tokenizer,filter,ctype,session" --with-php=8.3

# Build
./spc build "sockets,pdo_sqlite,sqlite3,openssl,mbstring,phar,tokenizer,filter,ctype,session" --build-cli --build-micro

# Create phar
./buildroot/bin/php -d phar.readonly=0 build-phar.php

# Combine into micro binary
./spc micro:combine bin/qbixserver.phar -O qbixserver-ios-arm64
```

This produces a macOS arm64 binary, which works in the Simulator. For a real device, you'd need to cross-compile for `arm64-apple-ios` — the CI workflow attempts this, but static-php-cli's iOS cross-compilation is still experimental. The reliable path for device builds is to bundle the phar and use a PHP runtime compiled via NativePHP's php-ios fork.
