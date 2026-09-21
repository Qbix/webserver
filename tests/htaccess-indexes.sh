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
#   ./tests/htaccess-indexes.sh
#
# Exits non-zero on any failure.

set -uo pipefail

WS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-php}"
PASS=0; FAIL=0
TMP="$(mktemp -d)"
PORT=$(( 9300 + RANDOM % 300 ))
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

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
