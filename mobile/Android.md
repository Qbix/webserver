# Qbix Server on Android

This document explains how the Android build works — from PHP embedding to Play Store submission.

## The Short Version

The app is a native Kotlin shell that extracts a bundled PHP binary from APK assets, starts it as a subprocess on `127.0.0.1`, and points a WebView at it. A Foreground Service keeps the server alive in the background. The native TransportManager handles peer discovery over BLE and mDNS. No Cordova, no Capacitor — the PHP process runs your Qbix app exactly as it would on a server.

## Architecture

```
┌─────────────────────────────────────────┐
│  Android App (.apk)                     │
│                                         │
│  MainActivity                           │
│    ├─ PhpBridge.start(context) → port   │
│    ├─ startForegroundService(intent)    │
│    │    └─ QbixServerService            │
│    │         └─ TransportManager        │
│    └─ WebView → http://127.0.0.1:port  │
│                                         │
│  ┌──────────────────────────────────┐   │
│  │ PhpBridge                        │   │
│  │  Extracts micro binary from APK  │   │
│  │  assets, runs as subprocess on   │   │
│  │  127.0.0.1:<port>                │   │
│  └──────────────────────────────────┘   │
└─────────────────────────────────────────┘
```

## How PHP Runs on Android

### The micro binary

[static-php-cli](https://github.com/crazywhalecc/static-php-cli) cross-compiles PHP to a statically linked `aarch64-linux-android` binary using the Android NDK. The `spc micro:combine` command fuses that binary with the phar archive, producing a single self-executing file.

The CI workflow (`.github/workflows/release.yml`, job `build-android`) does this on an Ubuntu runner:

1. Sets up Android NDK 27.x
2. Downloads PHP sources via `spc download`
3. Cross-compiles with the NDK's clang: `--cc=aarch64-linux-android34-clang`
4. Builds the phar via `build-phar.php`
5. Combines them: `spc micro:combine bin/qbixserver.phar -O qbixserver-android-arm64`

If the cross-compile fails (static-php-cli's Android support is still maturing), the CI falls back to building a host-arch PHP for the phar only, and the native shell would need a separate PHP runtime.

### PhpBridge.kt

`PhpBridge` is a Kotlin `object` (singleton) that:

1. Extracts the micro binary (or phar) from APK assets to `context.filesDir/qbix-server/`
2. Extracts `src/` and `web/` directories recursively from assets
3. Finds a free port via `ServerSocket(0).use { it.localPort }`
4. Starts a `ProcessBuilder` subprocess:

```kotlin
val cmd = listOf(exe.absolutePath, "-S", "127.0.0.1:$port")
val pb = ProcessBuilder(cmd).directory(serverDir).redirectErrorStream(true)
process = pb.start()
```

The binary is extracted fresh on each launch to pick up updates bundled with app upgrades. Assets are read-only in the APK, so the extraction to `filesDir` is required.

### Why a subprocess and not JNI?

Linking `libphp.a` into the app via JNI (the way NativePHP does it) is possible but adds significant build complexity — you need a properly cross-compiled PHP static library, JNI bindings, and careful lifecycle management. The subprocess approach is simpler and more robust: PHP runs in its own process, the app talks to it over HTTP, and a crash in PHP doesn't bring down the Android runtime.

The trade-off is a ~300ms startup delay while the server boots, covered by the standard splash screen.

## Project Structure

When you click "Prepare" in the control panel, the scaffolding generates:

```
apps/YourApp/mobile/android/
├── settings.gradle.kts
├── build.gradle.kts              ← top-level (plugin versions)
├── gradle.properties
├── gradlew
├── gradle/wrapper/
│   └── gradle-wrapper.properties
├── app/
│   ├── build.gradle.kts          ← app module (SDK versions, deps)
│   └── src/main/
│       ├── AndroidManifest.xml
│       ├── assets/               ← put phar/binary here
│       └── java/com/example/app/
│           ├── MainActivity.kt
│           ├── PhpBridge.kt
│           ├── QbixServerService.kt
│           └── TransportManager.kt
├── .gitignore
```

### Gradle (Kotlin DSL)

The scaffolding uses Kotlin DSL (`build.gradle.kts`) instead of Groovy (`build.gradle`). Kotlin DSL is the default for new Android projects since 2023 and provides better IDE support and type safety.

Key settings in `app/build.gradle.kts`:
- `compileSdk = 34`, `minSdk = 26` — supports Android 8.0+
- `jvmTarget = "17"` — Kotlin targets JDK 17
- Dependencies: `core-ktx`, `appcompat`, `webkit`
- Assets source set configured so Gradle includes `src/main/assets/` in the APK

The Gradle wrapper (`gradlew`) is included so you don't need Gradle installed separately. It downloads Gradle 8.5 on first run.

## Background Persistence

Android is more permissive than iOS about background processes, but still kills apps that aren't visible. A Foreground Service keeps the server alive.

`QbixServerService.kt` creates a persistent notification ("Qbix Server — Running on port N") and calls `startForeground()`. This tells the OS the process is doing user-visible work. The service uses `START_STICKY`, so the OS restarts it if it's killed for memory.

The manifest declares the service with `foregroundServiceType="dataSync"` and requires the `FOREGROUND_SERVICE_DATA_SYNC` permission, which is the correct type for a local server that syncs data with peers.

Unlike iOS, there's no audio trick needed. Foreground Services are a first-class API that Android explicitly provides for exactly this use case.

## Transport Layer

`TransportManager.kt` handles peer discovery and communication over two transports:

| Transport | Range | Bandwidth | How |
|---|---|---|---|
| TCP (LAN) | Same Wi-Fi | 100+ Mbps | NSD (Android's mDNS implementation) |
| BLE GATT | ~100ft | ~2 Mbps | BluetoothGattServer + scanner |

Android doesn't have MultipeerConnectivity (that's Apple-only), so LAN and BLE are the available channels. The BLE implementation uses the same GATT service UUIDs and chunking protocol as iOS, so Android and iOS devices discover and communicate with each other.

TransportManager runs inside the Foreground Service so it stays alive in the background. It registers discovered peers with the PHP server via `POST /Q/api/transport/event` and triggers mesh handshakes via `POST /Q/api/transport/connect`.

## Permissions

The manifest declares:

- **INTERNET** — HTTP to localhost and LAN peers.
- **ACCESS_NETWORK_STATE** — detecting Wi-Fi for LAN transport.
- **BLUETOOTH_CONNECT, BLUETOOTH_ADVERTISE, BLUETOOTH_SCAN** — BLE transport. These are runtime permissions on Android 12+; the app must request them at launch.
- **FOREGROUND_SERVICE, FOREGROUND_SERVICE_DATA_SYNC** — background persistence.
- **android:usesCleartextTraffic="true"** — allows HTTP to `127.0.0.1`. Without this, Android's network security config blocks cleartext traffic by default.

## Building

### Debug

1. Put `qbixserver.phar` (or the micro binary) in `app/src/main/assets/`
2. Open the project in Android Studio, or run:

```bash
cd apps/YourApp/mobile/android
./gradlew assembleDebug
```

The APK lands in `app/build/outputs/apk/debug/`. Install on a connected device:

```bash
adb install app/build/outputs/apk/debug/app-debug.apk
```

Debug APKs are signed with a debug keystore automatically — no signing setup needed for development.

### Release

1. Get `qbixserver-android-arm64` from the CI release artifacts (or build it locally — see below)
2. Put it in `app/src/main/assets/`
3. Set up signing (see next section)
4. Build a release APK or AAB:

```bash
# APK (for direct distribution or sideloading)
./gradlew assembleRelease

# AAB (for Play Store — Google recommends this)
./gradlew bundleRelease
```

From the control panel, click "Build" with the Android platform selected. This runs `./gradlew assembleRelease` and tracks the output.

### What goes in the APK/AAB

- The micro binary (~15 MB) in `assets/` — extracted at runtime by PhpBridge
- `assets/src/` — your PHP source files
- `assets/web/` — static web assets (if any)
- Compiled Kotlin classes (MainActivity, PhpBridge, QbixServerService, TransportManager)
- Android resources (strings.xml, manifest)

## Signing and Distribution

### Creating a signing keystore

Android requires all release builds to be signed. You create a keystore once and use it for every release of your app. Keep the keystore file and its passwords safe — if you lose them, you cannot update your app on the Play Store.

```bash
keytool -genkeypair \
  -v \
  -keystore yourapp-release.jks \
  -keyalg RSA \
  -keysize 2048 \
  -validity 10000 \
  -alias yourapp \
  -storepass YOUR_STORE_PASSWORD \
  -keypass YOUR_KEY_PASSWORD \
  -dname "CN=Your Name, O=Your Company, L=Your City, ST=Your State, C=US"
```

This creates `yourapp-release.jks`. Store it outside version control (the `.gitignore` already excludes `*.jks`).

### Configuring Gradle to sign release builds

Add a `signingConfigs` block to `app/build.gradle.kts`:

```kotlin
android {
    signingConfigs {
        create("release") {
            storeFile = file("../yourapp-release.jks")
            storePassword = System.getenv("STORE_PASSWORD") ?: "YOUR_STORE_PASSWORD"
            keyAlias = "yourapp"
            keyPassword = System.getenv("KEY_PASSWORD") ?: "YOUR_KEY_PASSWORD"
        }
    }
    buildTypes {
        getByName("release") {
            signingConfig = signingConfigs.getByName("release")
            isMinifyEnabled = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }
}
```

For CI builds, pass the passwords as environment variables (`STORE_PASSWORD`, `KEY_PASSWORD`) instead of hardcoding them.

You can also configure signing via Android Studio: **Build → Generate Signed Bundle/APK**, which walks you through keystore creation and produces a signed APK or AAB.

### Google Play Store

1. Create a [Google Play Developer account](https://play.google.com/console/) ($25 one-time fee).
2. Create a new app in the Play Console.
3. Fill in the store listing: name, description, screenshots, category, privacy policy URL, content rating questionnaire.
4. Build a signed AAB:

```bash
./gradlew bundleRelease
# Output: app/build/outputs/bundle/release/app-release.aab
```

5. Upload the AAB under **Release → Production** (or a testing track first — see below).
6. Submit for review. Google's review typically takes a few hours to a few days.

Points to be aware of during review:

- **Foreground Service policy** — Google requires that foreground services have a user-visible purpose. The persistent notification satisfies this. Use `foregroundServiceType="dataSync"` to indicate the service syncs data with peers.
- **Target API level** — Google requires `targetSdk = 34` (Android 14) or higher for new apps. The scaffolding sets this.
- **64-bit requirement** — the micro binary must be `aarch64` (arm64). The CI cross-compiles for this architecture. If you also need `x86_64` (for Chromebooks), add a second build variant.

### Testing tracks

The Play Console offers testing tracks before a full production release:

- **Internal testing** — up to 100 testers, no review required. Good for team testing.
- **Closed testing** — invite-only, requires a brief review. Good for beta testers.
- **Open testing** — anyone can join via a link, requires review. Good for public betas.

Upload the same signed AAB to a testing track. Testers install via the Play Store (the app shows as "Early access").

### Direct distribution (outside Play Store)

For distribution outside the Play Store (enterprise, sideloading, alternative stores):

1. Build a signed release APK (not AAB — AABs are Play Store only):

```bash
./gradlew assembleRelease
# Output: app/build/outputs/apk/release/app-release.apk
```

2. Distribute the APK directly. Users enable "Install unknown apps" in their device settings to install it.
3. For F-Droid or other alternative stores, follow their submission process — the signed APK is the same artifact.

### Play App Signing

Google recommends (and for new apps requires) [Play App Signing](https://support.google.com/googleplay/android-developer/answer/9842756), where Google manages the final signing key. You upload with your upload key (the keystore above), and Google re-signs with a key they hold. This protects against key loss — if you lose your upload key, Google can reset it. The Play Console walks you through this on first upload.

## Building the Micro Binary Locally

To cross-compile the micro binary on your dev machine:

```bash
# You need the Android NDK installed
# Via Android Studio: SDK Manager → SDK Tools → NDK
# Or: sdkmanager "ndk;27.2.12479018"

# Install static-php-cli
curl -fSL https://dl.static-php.dev/static-php-cli/spc-bin/nightly/spc-linux-x86_64 -o spc
chmod +x spc

# Download PHP sources
./spc download --for-extensions="sockets,pdo_sqlite,sqlite3,openssl,mbstring,phar,tokenizer,filter,ctype,session" --with-php=8.3

# Cross-compile for Android arm64
NDK_HOME="$ANDROID_HOME/ndk/27.2.12479018"
./spc build "sockets,pdo_sqlite,sqlite3,openssl,mbstring,phar,tokenizer,filter,ctype,session" \
  --build-cli --build-micro \
  --cc="$NDK_HOME/toolchains/llvm/prebuilt/linux-x86_64/bin/aarch64-linux-android34-clang" \
  --cxx="$NDK_HOME/toolchains/llvm/prebuilt/linux-x86_64/bin/aarch64-linux-android34-clang++"

# Create phar and combine
./buildroot/bin/php -d phar.readonly=0 build-phar.php
./spc micro:combine bin/qbixserver.phar -O qbixserver-android-arm64
```

If the cross-compile fails (static-php-cli's Android NDK support is still experimental), you can bundle just the phar and provide a separate PHP binary compiled via Termux or the php-android project.
