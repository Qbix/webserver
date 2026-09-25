#!/usr/bin/env bash
#
# Regression test: the document root can be listed like any other
# directory, and still shows the welcome page when it is not listable.
#
# The root was special-cased twice and the two rules contradicted each
# other. The welcome page returned unconditionally for '/', so the
# listing check below it — written as "isIndexed($path) || $path === '/'"
# — could never run for the root: dead code that read as if the root were
# always listable while it was never listable.
#
# Now the welcome page stands aside when the root is explicitly indexed,
# and the dead disjunct is gone, so "-Indexes" on the root means what it
# says instead of being overridden.
#
#   ./tests/root-listing.sh
#
# Exits non-zero on any failure.

set -uo pipefail

WS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-php}"
PASS=0; FAIL=0
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

ok()  { PASS=$((PASS+1)); printf "  ok   %s\n" "$1"; }
bad() { FAIL=$((FAIL+1)); printf "  FAIL %s\n" "$1"; }

command -v "$PHP" >/dev/null || { echo "no php"; exit 1; }

ROOT="$TMP/public"; mkdir -p "$ROOT/sub"
echo "top"    > "$ROOT/a.txt"
echo "nested" > "$ROOT/sub/b.txt"

cat > "$TMP/on.json"  <<'JSON'
{"Q":{"web":{"indexed":{"paths":{"#^/#":true}}}}}
JSON
cat > "$TMP/off.json" <<'JSON'
{"Q":{"web":{"indexed":{"paths":{"#^/$#":false,"#^/#":true}}}}}
JSON

echo "=============================================="
echo " Qbix Server — document root listing"
echo "=============================================="
echo

PIDS=()
cleanup() { for p in "${PIDS[@]:-}"; do kill "$p" 2>/dev/null; done; }
trap 'cleanup; rm -rf "$TMP"' EXIT

start() { # start <port> [config]
    local port="$1"; shift
    if [ $# -gt 0 ]; then
        setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" --config="$1" \
            --port="$port" --workers=2 >"$TMP/s$port.log" 2>&1 </dev/null &
    else
        setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" \
            --port="$port" --workers=2 >"$TMP/s$port.log" 2>&1 </dev/null &
    fi
    PIDS+=($!)
    for _ in $(seq 1 25); do
        sleep 0.4
        curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$port/a.txt" 2>/dev/null && return 0
    done
    return 1
}

body() { curl -s --max-time 12 "http://127.0.0.1:$1$2" 2>/dev/null; }
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 12 "http://127.0.0.1:$1$2" 2>/dev/null; }

# ── default: nothing indexed ─────────────────────────────────────────
P1=$(( 9400 + RANDOM % 100 ))
start $P1 || { echo "server on $P1 did not come up"; exit 1; }

b=$(body $P1 /)
case "$b" in
    *"Index of /"*) bad "default root should not list" ;;
    *"Qbix Server"*) ok "default root shows the welcome page" ;;
    *) bad "default root served something unexpected" ;;
esac
[ "$(code $P1 /sub/)" = "403" ] \
    && ok "default subdirectory stays 403" \
    || bad "default subdirectory — expected 403, got $(code $P1 /sub/)"

# ── root indexed ─────────────────────────────────────────────────────
P2=$(( 9500 + RANDOM % 100 ))
start $P2 "$TMP/on.json" || { echo "server on $P2 did not come up"; exit 1; }

b=$(body $P2 /)
case "$b" in
    *"Index of /"*) ok "indexed root lists" ;;
    *) bad "indexed root did not list" ;;
esac
case "$b" in
    *'href="/a.txt"'*) ok "listing links the file" ;;
    *) bad "listing missing a.txt" ;;
esac
case "$b" in
    *'href="/sub/"'*) ok "listing links the subdirectory" ;;
    *) bad "listing missing sub/" ;;
esac
[ "$(code $P2 /a.txt)" = "200" ] \
    && ok "files still served with root indexed" \
    || bad "file under indexed root — got $(code $P2 /a.txt)"

# ── root excluded, everything else indexed ───────────────────────────
# The dead "|| \$path === '/'" made this impossible: the root listed no
# matter what the config said.
P3=$(( 9600 + RANDOM % 100 ))
start $P3 "$TMP/off.json" || { echo "server on $P3 did not come up"; exit 1; }

b=$(body $P3 /)
case "$b" in
    *"Index of /"*) bad "root excluded but still listed" ;;
    *) ok "root can be excluded while subdirectories list" ;;
esac
[ "$(code $P3 /sub/)" = "200" ] \
    && ok "subdirectory still lists" \
    || bad "subdirectory — expected 200, got $(code $P3 /sub/)"

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
