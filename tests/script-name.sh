#!/usr/bin/env bash
#
# Regression test: SCRIPT_NAME and PHP_SELF carry the script's path.
#
# They were built as '/' . basename($scriptPath), which is right only for a
# script in the document root. Anything in a subdirectory lost it: an
# application under /shop/ was told it lived at /, and every absolute URL
# it built from SCRIPT_NAME went one or more directories too high.
#
# The page still came back 200 with the right HTML, which is what made it
# hard to see. It simply arrived unstyled, because every stylesheet href
# was wrong, and posting a form went somewhere else. Against a server that
# reports SCRIPT_NAME properly the same application looks completely
# different — that difference is the whole symptom.
#
#   ./tests/script-name.sh
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

ROOT="$TMP/public"; mkdir -p "$ROOT/shop/admin"
PROBE='<?php header("Content-Type: text/plain");
echo $_SERVER["SCRIPT_NAME"], "|", $_SERVER["PHP_SELF"], "|", $_SERVER["DOCUMENT_ROOT"];'
printf '%s\n' "$PROBE" > "$ROOT/i.php"
printf '%s\n' "$PROBE" > "$ROOT/shop/i.php"
printf '%s\n' "$PROBE" > "$ROOT/shop/admin/i.php"
printf '%s\n' "$PROBE" > "$ROOT/shop/index.php"

# What an application actually does with it.
cat > "$ROOT/shop/link.php" <<'PHP'
<?php
header('Content-Type: text/plain');
$base = dirname($_SERVER['SCRIPT_NAME']);
echo rtrim($base, '/') . '/style.css';
PHP

echo "=============================================="
echo " Qbix Server — SCRIPT_NAME below the root"
echo "=============================================="
echo

( setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" --port=$PORT --workers=2 \
    >"$TMP/server.log" 2>&1 </dev/null & )

up=0
for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/i.php" 2>/dev/null && { up=1; break; }
done
[ "$up" = "1" ] || { echo "  server never came up"; sed 's/^/    /' "$TMP/server.log" | head -10; exit 1; }

body() { curl -s --max-time 15 "http://127.0.0.1:$PORT/$1" 2>/dev/null; }

check() { # check <label> <path> <expected SCRIPT_NAME>
    local got; got=$(body "$2"); local sn=${got%%|*}
    [ "$sn" = "$3" ] && ok "$1 ($sn)" || bad "$1 — got '$sn', expected '$3'"
}

check "document root"        i.php              /i.php
check "one level down"       shop/i.php         /shop/i.php
check "two levels down"      shop/admin/i.php   /shop/admin/i.php

# A directory request resolves to its index; the name must be the index's.
check "directory index"      shop/              /shop/index.php

# PHP_SELF tracks SCRIPT_NAME.
g=$(body shop/admin/i.php); sn=${g%%|*}; rest=${g#*|}; self=${rest%%|*}
[ "$self" = "$sn" ] && ok "PHP_SELF matches SCRIPT_NAME" \
                    || bad "PHP_SELF '$self' != SCRIPT_NAME '$sn'"

# DOCUMENT_ROOT without a trailing slash, as mod_php and fpm report it:
# an application joining it with '/path' otherwise builds '//path'.
docroot=${g##*|}
case "$docroot" in
    */) bad "DOCUMENT_ROOT ends in a slash ($docroot)" ;;
    "") bad "DOCUMENT_ROOT is empty" ;;
    *)  ok "DOCUMENT_ROOT has no trailing slash" ;;
esac

# The thing that actually broke: a URL built from SCRIPT_NAME.
u=$(body shop/link.php)
[ "$u" = "/shop/style.css" ] \
    && ok "a URL built from SCRIPT_NAME points at the right place ($u)" \
    || bad "built URL was '$u', expected '/shop/style.css'"

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
