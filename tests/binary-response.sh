#!/usr/bin/env bash
#
# Regression test: binary (non-UTF-8) response bodies reach the client intact.
# The worker sends responses to the parent as JSON, which cannot carry
# invalid UTF-8; such bodies must travel base64-encoded.
#
#   ./tests/binary-response.sh

set -uo pipefail

WS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-php}"
PASS=0; FAIL=0
TMP="$(mktemp -d)"
PORT=0
for _ in $(seq 1 60); do
    _p=$(( 19000 + RANDOM % 900 ))
    ss -ltn 2>/dev/null | grep -q ":$_p " || { PORT=$_p; break; }
done
[ "$PORT" = "0" ] && { echo "  no free port found"; exit 1; }
trap 'rm -rf "$TMP"; pkill -f "qbixserver.*--port=$PORT" 2>/dev/null' EXIT

ok()  { PASS=$((PASS+1)); printf "  ok   %s\n" "$1"; }
bad() { FAIL=$((FAIL+1)); printf "  FAIL %s\n" "$1"; }

ROOT="$TMP/public"; mkdir -p "$ROOT"

# 256 bytes 0x00..0xff
cat > "$ROOT/b256.php" <<'PHP'
<?php header('Content-Type: application/octet-stream');
for ($i = 0; $i < 256; $i++) echo chr($i);
PHP
cat > "$ROOT/tail.php" <<'PHP'
<?php header('Content-Type: application/octet-stream'); echo "abc\xff\xfe\xc3";
PHP
cat > "$ROOT/b4k.php" <<'PHP'
<?php header('Content-Type: application/octet-stream');
for ($i = 0; $i < 4096; $i++) echo chr(($i * 7 + 128) % 256);
PHP
cat > "$ROOT/png.php" <<'PHP'
<?php header('Content-Type: image/png');
echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
PHP

( setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" --port=$PORT --workers=2 \
    >"$TMP/server.log" 2>&1 </dev/null & )
for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/tail.php" 2>/dev/null && break
done

check() { # name script expected-file
    local code
    code=$(curl -s -o "$TMP/out" -w '%{http_code}' --max-time 15 "http://127.0.0.1:$PORT/$2")
    [ "$code" = "200" ] && ok "$1 — 200" || bad "$1 — got $code"
    if cmp -s "$TMP/out" "$3"; then ok "$1 arrives intact"
    else bad "$1 arrives intact — $(stat -c %s "$TMP/out") bytes, expected $(stat -c %s "$3")"; fi
}

"$PHP" -r 'for($i=0;$i<256;$i++)echo chr($i);' > "$TMP/e256"
printf 'abc\xff\xfe\xc3' > "$TMP/etail"
"$PHP" -r 'for($i=0;$i<4096;$i++)echo chr(($i*7+128)%256);' > "$TMP/e4k"
"$PHP" -r 'echo base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==");' > "$TMP/epng"

check "256 bytes of binary" b256.php "$TMP/e256"
check "invalid UTF-8 tail" tail.php "$TMP/etail"
check "4KB of binary" b4k.php "$TMP/e4k"
check "PNG" png.php "$TMP/epng"

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
