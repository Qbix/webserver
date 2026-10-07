#!/usr/bin/env bash
#
# Regression test: the compat transform works inside a namespace.
#
# The transform rewrites calls to functions it shims, header() becoming
# Q_WebServer_Compat::_header() and so on. It wrote that name unqualified,
# which is only correct in global code: a file declaring a namespace
# resolves it inside that namespace, so the call became
#
#     Class "Composer\Autoload\Q_WebServer_Compat" not found
#
# in Composer's autoloader, and whatever the namespace happened to be
# elsewhere. Any namespaced file calling a shimmed function was fatal —
# which is most of a modern application, since the transform reaches
# vendor code too.
#
#   ./tests/namespaced-scripts.sh
#
# Exits non-zero on any failure.

set -uo pipefail

WS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-php}"
PASS=0; FAIL=0
TMP="$(mktemp -d)"
# Pick a port nothing else holds. Fixed ranges collide with whatever the
# machine happens to run -- a container on the same number makes every
# assertion here fail with an empty reply and no hint why.
PORT=0
for _ in $(seq 1 60); do
    _p=$(( 19000 + RANDOM % 900 ))
    ss -ltn 2>/dev/null | grep -q ":$_p " || { PORT=$_p; break; }
done
[ "$PORT" = "0" ] && { echo "  no free port found"; exit 1; }
trap 'rm -rf "$TMP"; pkill -f "qbixserver.*--port=$PORT" 2>/dev/null' EXIT

ok()  { PASS=$((PASS+1)); printf "  ok   %s\n" "$1"; }
bad() { FAIL=$((FAIL+1)); printf "  FAIL %s\n" "$1"; }

command -v "$PHP" >/dev/null || { echo "no php"; exit 1; }

ROOT="$TMP/public"; mkdir -p "$ROOT"

cat > "$ROOT/global.php" <<'PHP'
<?php
header('Content-Type: text/plain');
setcookie('g', '1', 0, '/');
echo "GLOBAL";
PHP

# \header() — fully qualified inside a namespace.
cat > "$ROOT/qualified.php" <<'PHP'
<?php
namespace Acme\Deep;
\header('Content-Type: text/plain');
\setcookie('q', '1', 0, '/');
echo "QUALIFIED";
PHP

# header() — unqualified inside a namespace, which PHP falls back to the
# global function for. This is what vendor code looks like.
cat > "$ROOT/unqualified.php" <<'PHP'
<?php
namespace Composer\Autoload;
header('Content-Type: text/plain');
session_start();
echo "UNQUALIFIED";
PHP

# A namespaced file pulled in by a global one, the shape an autoloader has.
# require_once, not require: a worker persists, so a second request would
# redeclare the function and take the worker down with a fatal — which
# reaches the client as a bare "Worker died".
cat > "$ROOT/including.php" <<'PHP'
<?php
require_once __DIR__ . '/lib_ns.php';
header('Content-Type: text/plain');
echo \Vendor\Lib\go();
PHP
cat > "$ROOT/lib_ns.php" <<'PHP'
<?php
namespace Vendor\Lib;
function go() {
    ini_set('display_errors', '0');
    headers_sent();
    return "INCLUDED";
}
PHP

echo "=============================================="
echo " Qbix Server — shimmed calls inside namespaces"
echo "=============================================="
echo

( setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" --port=$PORT --workers=2 \
    >"$TMP/server.log" 2>&1 </dev/null & )

up=0
for _ in $(seq 1 25); do
    sleep 0.4
    if curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/global.php" 2>/dev/null; then
        up=1; break
    fi
done
if [ "$up" != "1" ]; then
    echo "  server on port $PORT never came up — log:"
    sed -e 's/^/    /' "$TMP/server.log" | head -12
    exit 1
fi

check() { # check <label> <file> <expected body>
    local code body
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "http://127.0.0.1:$PORT/$2" 2>/dev/null)
    body=$(curl -s --max-time 15 "http://127.0.0.1:$PORT/$2" 2>/dev/null)
    if [ "$code" = "200" ] && [ "$body" = "$3" ]; then
        ok "$1"
    else
        bad "$1 — $code, body: $(printf '%s' "$body" | head -c 90)"
    fi
}

check "global code still works"                  global.php      GLOBAL
check "\\header() inside a namespace"             qualified.php   QUALIFIED
check "header() inside a namespace"              unqualified.php UNQUALIFIED
check "namespaced file included by a global one" including.php   INCLUDED

# The failure was always the same shape: the shim class looked up relative
# to the namespace. Catch it whatever the namespace.
for f in qualified.php unqualified.php including.php; do
    b=$(curl -s --max-time 15 "http://127.0.0.1:$PORT/$f" 2>/dev/null)
    case "$b" in
        *'Q_WebServer_Compat" not found'*)
            bad "$f resolved the shim inside its namespace" ;;
        *) ok "$f resolves the shim globally" ;;
    esac
done

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
