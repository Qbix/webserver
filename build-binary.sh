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
# Output: bin/qbix-server (or bin/qbix-server-$OS-$ARCH)
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
    cat "$MICRO_SFXN" "$BIN_DIR/qbix-server.phar" > "$BIN_DIR/qbix-server"
    chmod +x "$BIN_DIR/qbix-server"

    echo ""
    echo "Binary built: $BIN_DIR/qbix-server"
    ls -lh "$BIN_DIR/qbix-server"
}

# ── Method 2: Docker-based build ─────────────────

build_with_docker() {
    echo "Using Docker for isolated build..."

    # Create a temporary Dockerfile
    TMPDIR=$(mktemp -d)
    cp -r "$SRC_DIR" "$TMPDIR/src"
    cp "$SCRIPT_DIR/build-phar.php" "$TMPDIR/"

    cat > "$TMPDIR/Dockerfile" << 'DOCKERFILE'
FROM php:8.3-cli-alpine AS builder

RUN apk add --no-cache curl bash tar

# Install static-php-cli
RUN curl -sL https://github.com/crazywhalecc/static-php-cli/releases/latest/download/spc-linux-x86_64.tar.gz \
    | tar xz -C /usr/local/bin/ && chmod +x /usr/local/bin/spc

WORKDIR /build
COPY src/ src/
COPY build-phar.php .

# Build PHAR first
RUN mkdir -p bin && php -d phar.readonly=0 build-phar.php

# Download PHP sources and build micro SAPI
RUN spc doctor --auto-fix 2>/dev/null || true
RUN spc download --with-php=8.3 --for-extensions=pcntl,sockets,openssl,mbstring,filter
RUN spc build pcntl,sockets,openssl,mbstring,filter --build-micro

# Combine
RUN cat buildroot/bin/micro.sfx bin/qbix-server.phar > bin/qbix-server && \
    chmod +x bin/qbix-server

FROM scratch
COPY --from=builder /build/bin/qbix-server /qbix-server
DOCKERFILE

    docker build -t qbix-server-builder "$TMPDIR"
    docker create --name qbix-extract qbix-server-builder
    docker cp qbix-extract:/qbix-server "$BIN_DIR/qbix-server"
    docker rm qbix-extract
    docker rmi qbix-server-builder 2>/dev/null || true

    rm -rf "$TMPDIR"

    echo ""
    echo "Binary built: $BIN_DIR/qbix-server"
    ls -lh "$BIN_DIR/qbix-server"
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
    echo "The PHAR works identically: php bin/qbix-server.phar --port=8080"
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
