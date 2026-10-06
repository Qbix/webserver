# Building and Distributing Binaries

Qbix Server can be packaged as a single executable file containing the PHP runtime, the web server, and your entire application. The binary runs on any machine — nothing to install.

## Why is the binary 5 MB, not 200 MB?

Electron ships an entire copy of Chromium and Node.js inside every app. That's a full browser engine duplicated for each application on the user's machine.

Qbix Server opens the browser that's already there. `--open` calls the system browser — the one the user already has open, already has their passwords saved in, already has their extensions. We ship zero rendering code.

| Component | Electron app | Qbix Server binary |
|---|---|---|
| Browser engine | ~120 MB (Chromium) | 0 — uses system browser |
| Language runtime | ~30 MB (Node.js + V8) | ~4 MB (static PHP) |
| Server | bundled in Node | included in the 4 MB |
| Dependencies | ~10 MB (node_modules) | 0 — no Composer, no npm |
| App code | varies | varies |
| **Typical total** | **~165 MB** | **~5 MB** |

The static PHP binary from [static-php-cli](https://github.com/crazywhalecc/static-php-cli) includes pcntl, sockets, sqlite3, openssl, phar, and mbstring in about 4 MB. The phar with all server code adds ~1 MB. Your app code goes on top.

The tradeoff: Electron gives a controlled rendering environment — same browser engine on every machine. Qbix Server gives whatever browser the user has. For a web app that already works in a browser — which is what PHP apps are — there's no reason to ship another one.

## Creating a Binary

### From the phar (requires static-php-cli)

```bash
# Download spc and build a static PHP with the extensions you need
./spc build "pcntl,sockets,pdo_sqlite,sqlite3,openssl,mbstring,phar,tokenizer,filter,ctype,posix,session" --build-cli --build-micro

# Build the phar
php -d phar.readonly=0 build-phar.php

# Combine into a single binary
./spc micro:combine bin/qbixserver.phar -O qbixserver
chmod +x qbixserver
```

### Pack your app into the binary

```bash
# Bundle app files — the binary serves them directly
./qbixserver --pack=./my-app --output=myapp
./myapp
```

The `--pack` flag appends your app files as a zip to the binary. At startup the server detects the appended zip, extracts to a temp directory, and serves from there.

For a GUI app that opens a browser automatically:

```bash
./qbixserver --pack=./my-app --gui --output=myapp
# On run: browser opens, no terminal interaction needed
```

## Managing Binaries Like Zip Files

The packed binary is a valid zip file (with the executable prepended). Standard zip tools can list, extract, and add files.

### List contents

```bash
unzip -l myapp
# or
7z l myapp
```

### Extract a file

```bash
unzip -p myapp web/config.json
```

### Add or update files

```bash
# Adjust zip offsets first (one-time)
zip -A myapp

# Add files
cd my-updates && zip -r ../myapp web/new-page.html web/style.css
```

### Cross-platform support

| OS | Tool | Read | Write |
|---|---|---|---|
| Linux | unzip, 7z | ✅ | ✅ |
| macOS | unzip, Keka, 7-Zip | ✅ | ✅ |
| Windows | 7-Zip, WinRAR | ✅ | ✅ |

Windows Explorer needs the file renamed to `.zip` to browse it natively. 7-Zip opens it regardless of extension.

### Customizing a binary

Because the packed binary is a zip, you can create your own distribution by modifying the bundled files. This is how you'd white-label the server, ship a patched version of your app, or swap out configuration for a specific customer.

```bash
# Start from the release binary
cp qbixserver-linux-x86_64 myapp
chmod +x myapp

# Pack your app into it
./myapp --pack=./my-app --output=myapp

# Later, update a single file without rebuilding
zip -A myapp                                  # fix offsets (one-time)
zip myapp web/config.json                    # replace config
zip myapp web/templates/header.html          # update a template
zip -d myapp web/old-page.html               # remove a file

# On Windows with 7-Zip (right-click → Open Archive):
# drag files in and out, save, done.
```

The workflow is: start with the official binary, pack your app in, then treat the result as a zip you can edit freely. Add pages, swap assets, update config — all without rebuilding from source.

After modifying a binary, its hash changes and any existing signatures become invalid. If you're distributing the binary to others, re-sign it:

```bash
./myapp --sign-binary --key=mykey.pem --signer="My Company"
./myapp --publish-rekor   # optional: publish to transparency log
```

If you're running it yourself on a trusted machine, signing is optional — the binary works regardless.

## Where Data Is Stored

When running as a packed binary, the server stores runtime data next to the binary:

```
/opt/myapp              ← the binary
/opt/myapp.data/        ← created automatically
  panel.json            ← control panel config and password
  metrics.db            ← time-series stats (SQLite)
  logs/access.log       ← buffered access log
  logs/error.log        ← error log
  certs/                ← TLS certificates
  keys/                 ← signing keys
```

Override with `--data-dir=/path` or set `QBIX_DATA_DIR`.

## Signing Binaries

### Generate a key

```bash
./qbixserver --generate-key=alice
# → local/keys/alice.pem (private, ECDSA P-256)
# → local/keys/alice.pub.pem (public, share this)
```

### Sign

```bash
./qbixserver --sign-binary --key=local/keys/alice.pem --signer=Alice
```

Multiple people can sign independently. Each signature is appended to `<binary>.signatures.json`:

```bash
# Alice signs on her machine
./qbixserver --sign-binary --key=alice.pem --signer=Alice

# Bob signs on his machine
./qbixserver --sign-binary --key=bob.pem --signer=Bob
```

### Verify

```bash
# Check that at least 1 signature is valid
./qbixserver --verify-binary

# Require 2 of N signatures (M-of-N threshold)
./qbixserver --verify-binary --m=2
```

### Publish to Sigstore Rekor

For independent verification, publish the attestation to Sigstore's public transparency log:

```bash
./qbixserver --publish-rekor
# → Published! Rekor UUID: ...
# → Verify: https://search.sigstore.dev/?uuid=...
```

Rekor provides a tamper-evident record. Anyone can fetch the entry and confirm the hash matches what the server is running. The server can't fake this — Rekor's Merkle tree is append-only.

### Runtime attestation

The server exposes `GET /Q/attestation` returning the binary hash, all signatures, verification status, and the Rekor log entry (if published). Monitoring tools or browser extensions can verify the deployment without trusting the server alone.

### From the control panel

The Security tab lets you sign, verify, and publish to Rekor from the browser. Paste a PEM private key, name the signer, click Sign. Adjust the M threshold and click Verify. One-click Rekor publishing with confirmation.

## Windows GUI Mode

On Windows, the release includes two variants:

- `qbixserver-windows-x64.exe` — console app (shows a terminal window)
- `qbixserver-windows-x64-gui.exe` — GUI subsystem (no terminal window)

The GUI variant is identical except the PE header's subsystem flag is set to WINDOWS via `editbin`. Double-click it and the browser opens with your app — no console visible.

When using `--pack --gui`, a `.vbs` launcher is also created for machines without the GUI binary:

```
myapp.exe    ← the packed binary
myapp.vbs    ← double-click this, runs myapp.exe hidden
```

## Platform Code Signing

The ECDSA signatures described above verify that the code was approved by your team. They don't satisfy the operating system's own code signing requirements. For distribution to end users, you'll also need platform-specific signing so the OS doesn't block the binary on download.

### macOS (Apple Developer ID)

macOS Gatekeeper blocks unsigned binaries downloaded from the internet. To distribute a packed binary, sign it with an Apple Developer ID certificate ($99/year) and submit it for notarization:

```bash
# Sign with your Developer ID
codesign --force --options runtime \
  --sign "Developer ID Application: Your Name (TEAM_ID)" \
  myapp

# Submit for notarization (requires Xcode command line tools)
xcrun notarytool submit myapp \
  --apple-id you@example.com \
  --team-id TEAM_ID \
  --password @keychain:notarytool \
  --wait

# Staple the notarization ticket to the binary
xcrun stapler staple myapp
```

After stapling, any Mac can run the binary without Gatekeeper warnings. Without notarization, users have to right-click → Open → "Open Anyway", which is not acceptable for production distribution.

The GitHub Actions workflow can automate this: store the Developer ID certificate as a repository secret, import it into the macOS runner's keychain, and sign + notarize as part of the release build.

### Windows (Authenticode)

Windows SmartScreen flags unsigned executables downloaded from the internet. To avoid the "Windows protected your PC" warning, sign with an Authenticode certificate from a CA like DigiCert, Sectigo, or SSL.com (typically $200-400/year, or free through SignPath for open source):

```powershell
# Sign with signtool (from Windows SDK)
signtool sign /tr http://timestamp.digicert.com /td sha256 /fd sha256 /a myapp.exe
```

EV (Extended Validation) certificates bypass SmartScreen immediately. Standard certificates build reputation over time — after enough users run the signed binary without issues, SmartScreen stops warning.

### Linux

Linux distributions don't enforce code signing at the OS level. The ECDSA signatures and optional Rekor attestation are sufficient for verifying binary integrity. For distribution via package managers, sign the `.deb` or `.rpm` package with GPG as those ecosystems expect.

### Relationship to ECDSA signatures

Platform signing (codesign, Authenticode) and ECDSA signing serve different purposes:

- **Platform signing** tells the OS "this binary comes from a known developer." It's about the distributor's identity.
- **ECDSA M-of-N signing** tells your users "this specific code was reviewed and approved by 2 of 3 authorized signers." It's about the code's integrity.
- **Rekor** tells anyone "this code existed in this exact form at this timestamp." It's about the deployment's provenance.

You'd typically use all three for a production release: platform-sign so the OS allows it, ECDSA-sign so your team can verify the code, Rekor-publish so auditors can confirm nothing changed after release.
