#!/usr/bin/env bash
#
# Regression test: "Options +Indexes" in .htaccess turns directory
# listings on, the way it does under Apache.
#
# The .htaccess parser only ever understood the four RewriteX directives
# and silently skipped everything else, so an Options line had no effect
# and no diagnostic either. Listings could only be enabled centrally,
# through Q.web.indexed.paths.
#
# Checked here: +Indexes enables, -Indexes disables, the deepest file in
# the chain wins, a directory with no .htaccess still falls through to
# the config, and the .htaccess itself stays unreachable.
#
# Also the Q.web.indexed.allowOverride cap, which exists because a
# writable document root otherwise lets anyone who can drop a file in it
# expose a directory:
#
#   true        .htaccess may enable and disable (default)
#   "restrict"  .htaccess may only disable; +Indexes is ignored
#   false       .htaccess is ignored here entirely
#
#   ./tests/htaccess-indexes.sh
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

ROOT="$TMP/public"
mkdir -p "$ROOT/on/deep/deeper" "$ROOT/off" "$ROOT/plain" "$ROOT/img"
for d in on on/deep on/deep/deeper off plain img; do
    echo "content" > "$ROOT/$d/f.txt"
done

printf 'Options +Indexes\n'                    > "$ROOT/on/.htaccess"
printf 'Options -Indexes\n'                    > "$ROOT/off/.htaccess"
# A deeper file switches it back off, then on again further down.
printf 'Options -Indexes\n'                    > "$ROOT/on/deep/.htaccess"
printf 'Options +Indexes +FollowSymLinks\n'    > "$ROOT/on/deep/deeper/.htaccess"

echo "=============================================="
echo " Qbix Server — Options +Indexes in .htaccess"
echo "=============================================="
echo

( setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" --port=$PORT --workers=2 \
    >"$TMP/server.log" 2>&1 </dev/null & )

for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/plain/f.txt" 2>/dev/null && break
done

code()  { curl -s -o /dev/null -w '%{http_code}' --max-time 12 "http://127.0.0.1:$PORT$1" 2>/dev/null; }
body()  { curl -s --max-time 12 "http://127.0.0.1:$PORT$1" 2>/dev/null; }

check() { # check <label> <path> <expected code>
    local got; got=$(code "$2")
    [ "$got" = "$3" ] && ok "$1 ($3)" || bad "$1 — expected $3, got $got"
}

check "+Indexes lists"                  /on/              200
check "-Indexes forbids"                /off/             403
check "no .htaccess falls through"      /plain/           403
check "deeper -Indexes wins"            /on/deep/         403
check "deepest +Indexes wins again"     /on/deep/deeper/  200

# The listing has to be a real listing, not some other 200.
b=$(body /on/)
case "$b" in
    *"Index of /on/"*) ok "listing names the directory" ;;
    *) bad "listing body missing 'Index of /on/'" ;;
esac
case "$b" in
    *"f.txt"*) ok "listing names the file" ;;
    *) bad "listing does not mention f.txt" ;;
esac

# Serving files is unaffected by any of this.
check "file still served under +Indexes" /on/f.txt   200
check "file still served under -Indexes" /off/f.txt  200

# And the .htaccess must not become readable just because we parse it.
check ".htaccess stays hidden"          /on/.htaccess  403

# Without any .htaccess the config default (/img/) still applies.
check "config default still works"      /img/          200

# ── Q.web.indexed.allowOverride ──────────────────────────────────────
# /img/ is on by config default and carries "-Indexes", which is the case
# that tells "false" (ignore .htaccess) from "restrict" (deny still counts).
printf 'Options -Indexes\n' > "$ROOT/img/.htaccess"

override() { # override <json> <label> <on-code> <img-code>
    local cfg="$TMP/ovr.json" port=$(( 9700 + RANDOM % 200 ))
    printf '%s\n' "$1" > "$cfg"
    ( setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" --config="$cfg" \
        --port=$port --workers=2 >"$TMP/ovr$port.log" 2>&1 </dev/null & )
    for _ in $(seq 1 25); do
        sleep 0.4
        curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$port/plain/f.txt" 2>/dev/null && break
    done
    local a b
    a=$(curl -s -o /dev/null -w '%{http_code}' --max-time 12 "http://127.0.0.1:$port/on/" 2>/dev/null)
    b=$(curl -s -o /dev/null -w '%{http_code}' --max-time 12 "http://127.0.0.1:$port/img/" 2>/dev/null)
    [ "$a" = "$3" ] && ok "$2: +Indexes -> $3" || bad "$2: +Indexes expected $3, got $a"
    [ "$b" = "$4" ] && ok "$2: -Indexes on a config-enabled dir -> $4" \
                    || bad "$2: -Indexes expected $4, got $b"
    pkill -f "qbixserver.php.*--port=$port" 2>/dev/null
}

override '{"Q":{"web":{"indexed":{"paths":{"#^/img/#":true},"allowOverride":true}}}}' \
    "allowOverride true" 200 403
override '{"Q":{"web":{"indexed":{"paths":{"#^/img/#":true},"allowOverride":"restrict"}}}}' \
    "allowOverride restrict" 403 403
override '{"Q":{"web":{"indexed":{"paths":{"#^/img/#":true},"allowOverride":false}}}}' \
    "allowOverride false" 403 200

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
