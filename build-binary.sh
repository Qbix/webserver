#!/bin/bash
#
# Build a self-contained Qbix Server binary.
#
# The binary includes the PHP interpreter + all server code.
# No PHP installation needed on the target machine.
#
# Usage: ./build-binary.sh [--arch=x86_64|aarch64] [--os=linux|macos]
#
# Prerequisites:
#   - Docker (for cross-compilation) or local build tools
#   - ~2GB disk space for the build
#
# Output: bin/qbixserver (or bin/qbixserver-$OS-$ARCH)
#

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BIN_DIR="$SCRIPT_DIR/bin"
SRC_DIR="$SCRIPT_DIR/src"

ARCH="${ARCH:-$(uname -m)}"
OS="${OS:-linux}"
PHP_VERSION="8.3"

# Parse args
for arg in "$@"; do
    case $arg in
        --arch=*) ARCH="${arg#*=}" ;;
        --os=*)   OS="${arg#*=}" ;;
        --help)
            echo "Usage: $0 [--arch=x86_64|aarch64] [--os=linux|macos]"
            echo ""
            echo "Builds a self-contained Qbix Server binary."
            echo "Requires Docker for cross-compilation."
            exit 0
            ;;
    esac
done

mkdir -p "$BIN_DIR"

echo "═══════════════════════════════════════════"
echo "  Building Qbix Server binary"
echo "  PHP: $PHP_VERSION"
echo "  OS:  $OS"
echo "  Arch: $ARCH"
echo "═══════════════════════════════════════════"
echo ""

# ── Method 1: static-php-cli (preferred) ─────────

build_with_static_php_cli() {
    echo "Using static-php-cli..."

    # Check if static-php-cli is available
    if ! command -v spc &>/dev/null; then
        echo "Installing static-php-cli..."
        # Download the latest release
        SPC_URL="https://github.com/crazywhalecc/static-php-cli/releases/latest/download/spc-linux-x86_64.tar.gz"
        if [ "$ARCH" = "aarch64" ] || [ "$ARCH" = "arm64" ]; then
            SPC_URL="https://github.com/crazywhalecc/static-php-cli/releases/latest/download/spc-linux-aarch64.tar.gz"
        fi
        curl -sL "$SPC_URL" | tar xz -C /tmp/
        chmod +x /tmp/spc
        SPC="/tmp/spc"
    else
        SPC="spc"
    fi

    # Build PHP micro SAPI with required extensions
    $SPC doctor --auto-fix 2>/dev/null || true
    $SPC download --with-php=$PHP_VERSION \
        --for-extensions=pcntl,sockets,openssl,mbstring,filter,ctype,tokenizer
    $SPC build \
        pcntl,sockets,openssl,mbstring,filter,ctype,tokenizer \
        --build-micro \
        --debug

    MICRO_SFXN="buildroot/bin/micro.sfx"

    # First build the PHAR, then cat micro.sfx + phar = binary
    echo "Building PHAR for embedding..."
    php -d phar.readonly=0 "$SCRIPT_DIR/build-phar.php"

    echo "Combining micro.sfx + PHAR..."
    cat "$MICRO_SFXN" "$BIN_DIR/qbixserver.phar" > "$BIN_DIR/qbixserver"
    chmod +x "$BIN_DIR/qbixserver"

    echo ""
    echo "Binary built: $BIN_DIR/qbixserver"
    ls -lh "$BIN_DIR/qbixserver"
}

# ── Method 2: Docker-based build ─────────────────

build_with_docker() {
    echo "Using Docker for isolated build..."

    # Create a temporary build context
    TMPDIR=$(mktemp -d)
    cp -r "$SRC_DIR" "$TMPDIR/src"
    cp "$SCRIPT_DIR/build-phar.php" "$TMPDIR/"
    # build-phar.php requires these too
    cp "$SCRIPT_DIR/qbixserver.php" "$TMPDIR/"
    [ -d "$SCRIPT_DIR/web" ] && cp -r "$SCRIPT_DIR/web" "$TMPDIR/web"

    # Keep in sync with .github/workflows/release.yml
    EXTS="pcntl,sockets,pdo_sqlite,sqlite3,openssl,mbstring,phar,tokenizer,filter,ctype,posix,session"

    cat > "$TMPDIR/Dockerfile" << DOCKERFILE
FROM php:8.3-cli-alpine AS builder

RUN apk add --no-cache curl bash tar

# Install static-php-cli
RUN curl -sL https://github.com/crazywhalecc/static-php-cli/releases/latest/download/spc-linux-x86_64.tar.gz \
    | tar xz -C /usr/local/bin/ && chmod +x /usr/local/bin/spc

WORKDIR /build

# Build the PHP micro SAPI BEFORE copying any source. This is the expensive
# step (tens of minutes); keeping it above the COPY lines means editing the
# server's PHP code reuses the cached layer instead of rebuilding PHP.
RUN spc doctor --auto-fix 2>/dev/null || true
RUN spc download --with-php=8.3 --for-extensions=$EXTS
RUN spc build "$EXTS" --build-micro

COPY src/ src/
COPY web/ web/
COPY build-phar.php qbixserver.php ./

RUN mkdir -p bin && php -d phar.readonly=0 build-phar.php

# Combine. micro:combine appends the phar as an ELF overlay that phpmicro
# locates by reading its own file at runtime -- never UPX-pack the result.
RUN spc micro:combine bin/qbixserver.phar -O bin/qbixserver && \
    chmod +x bin/qbixserver
DOCKERFILE

    docker rm -f qbix-extract >/dev/null 2>&1 || true
    docker build -t qbixserver-builder "$TMPDIR"
    docker create --name qbix-extract qbixserver-builder
    docker cp qbix-extract:/build/bin/qbixserver "$BIN_DIR/qbixserver"
    docker rm qbix-extract
    # The builder image is deliberately kept. Deleting it drops its layers,
    # and with them the cached PHP build -- which is the whole point of
    # building the micro SAPI before the COPY lines above. Remove it by hand
    # (docker rmi qbixserver-builder) when you want the space back.

    rm -rf "$TMPDIR"

    echo ""
    echo "Binary built: $BIN_DIR/qbixserver"
    ls -lh "$BIN_DIR/qbixserver"
}

# ── Method 3: Manual build (fallback) ────────────

build_manual() {
    echo "Building PHAR (binary build requires static-php-cli or Docker)..."
    php -d phar.readonly=0 "$SCRIPT_DIR/build-phar.php"

    echo ""
    echo "PHAR built. For a static binary, install static-php-cli or use Docker:"
    echo "  Method A: curl -sL https://github.com/crazywhalecc/static-php-cli/... | tar xz"
    echo "  Method B: $0 --docker"
    echo ""
    echo "The PHAR works identically: php bin/qbixserver.phar --port=8080"
}

# ── Choose build method ──────────────────────────

if [ "$1" = "--docker" ] && command -v docker &>/dev/null; then
    build_with_docker
elif command -v spc &>/dev/null || [ -f /tmp/spc ]; then
    build_with_static_php_cli
elif command -v docker &>/dev/null; then
    echo "static-php-cli not found, falling back to Docker build..."
    build_with_docker
else
    build_manual
fi

echo ""
echo "Done."
