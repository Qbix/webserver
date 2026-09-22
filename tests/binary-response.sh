#!/usr/bin/env bash
#
# Regression test: a PHP script may return bytes that are not UTF-8.
#
# Worker responses travel to the parent as JSON. json_encode() returns
# false on invalid UTF-8 and strlen(false) is 0, so a binary body went out
# as a length prefix of zero and nothing after it: the parent read an empty
# frame and closed the connection while still logging 200. Every image,
# PDF or archive a script generated disappeared without a trace — curl
# reported an empty reply, the access log a success.
#
# The bodies here are compared byte for byte, because a response that is
# merely non-empty proves nothing: the failure mode was silent truncation.
#
#   ./tests/binary-response.sh
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
trap 'rm -rf "$TMP"; pkill -f "qbixserver.php.*--port=$PORT" 2>/dev/null' EXIT

ok()  { PASS=$((PASS+1)); printf "  ok   %s\n" "$1"; }
bad() { FAIL=$((FAIL+1)); printf "  FAIL %s\n" "$1"; }

command -v "$PHP" >/dev/null || { echo "no php"; exit 1; }

ROOT="$TMP/public"; mkdir -p "$ROOT"

# Text, to show the ordinary path is untouched.
printf '<?php echo "plain text";\n' > "$ROOT/text.php"

# Every byte 0..255, the shortest thing that is definitely not UTF-8.
cat > "$ROOT/bytes.php" <<'PHP'
<?php
header('Content-Type: application/octet-stream');
$out = '';
for ($i = 0; $i < 256; $i++) $out .= chr($i);
echo $out;
PHP

# A lone continuation byte: valid-looking length, invalid encoding.
cat > "$ROOT/invalid.php" <<'PHP'
<?php
header('Content-Type: application/octet-stream');
echo str_repeat('A', 64) . "\x80\x81\xfe\xff";
PHP

# Something large enough to cross the socket in several reads.
cat > "$ROOT/big.php" <<'PHP'
<?php
header('Content-Type: application/octet-stream');
$out = '';
for ($i = 0; $i < 4096; $i++) $out .= chr($i % 256);
echo $out;
PHP

# A real PNG, when gd is around.
cat > "$ROOT/png.php" <<'PHP'
<?php
if (!function_exists('imagecreatetruecolor')) { http_response_code(501); echo 'no gd'; return; }
$im = imagecreatetruecolor(40, 20);
imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 200));
header('Content-Type: image/png');
imagepng($im);
imagedestroy($im);
PHP

echo "=============================================="
echo " Qbix Server — binary response bodies"
echo "=============================================="
echo

( setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" --port=$PORT --workers=2 \
    >"$TMP/server.log" 2>&1 </dev/null & )

for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/text.php" 2>/dev/null && break
done

get() { curl -s -o "$2" -w '%{http_code}' --max-time 15 "http://127.0.0.1:$PORT/$1" 2>/dev/null; }

# Build the expected bytes locally with the same rules.
"$PHP" -r '$o=""; for($i=0;$i<256;$i++)$o.=chr($i); file_put_contents("'"$TMP"'/bytes.exp",$o);'
"$PHP" -r 'file_put_contents("'"$TMP"'/invalid.exp", str_repeat("A",64)."\x80\x81\xfe\xff");'
"$PHP" -r '$o=""; for($i=0;$i<4096;$i++)$o.=chr($i%256); file_put_contents("'"$TMP"'/big.exp",$o);'

same() { # same <label> <got> <expected>
    if cmp -s "$2" "$3"; then ok "$1"
    else bad "$1 — $(stat -c%s "$2" 2>/dev/null || echo 0) bytes, expected $(stat -c%s "$3")"; fi
}

c=$(get text.php "$TMP/text.got")
[ "$c" = "200" ] && ok "text response still works ($c)" || bad "text response — got $c"

c=$(get bytes.php "$TMP/bytes.got")
[ "$c" = "200" ] && ok "all 256 byte values: 200" || bad "all 256 byte values — got ${c:-no reply}"
same "all 256 byte values arrive intact" "$TMP/bytes.got" "$TMP/bytes.exp"

c=$(get invalid.php "$TMP/invalid.got")
[ "$c" = "200" ] && ok "invalid utf-8 tail: 200" || bad "invalid utf-8 tail — got ${c:-no reply}"
same "invalid utf-8 tail arrives intact" "$TMP/invalid.got" "$TMP/invalid.exp"

c=$(get big.php "$TMP/big.got")
[ "$c" = "200" ] && ok "4KB of binary: 200" || bad "4KB of binary — got ${c:-no reply}"
same "4KB of binary arrives intact" "$TMP/big.got" "$TMP/big.exp"

c=$(get png.php "$TMP/png.got")
if [ "$c" = "501" ]; then
    echo "  note: no gd in this php, skipping the png case"
else
    [ "$c" = "200" ] && ok "generated png: 200" || bad "generated png — got ${c:-no reply}"
    head -c 8 "$TMP/png.got" 2>/dev/null | od -An -tx1 | tr -d ' \n' \
        | grep -q '^89504e470d0a1a0a' \
        && ok "png signature intact" || bad "png signature wrong or truncated"
fi

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
