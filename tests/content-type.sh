#!/usr/bin/env bash
#
# Regression test: PHP responses must carry a Content-Type.
#
# headers_list() is a no-op under the CLI SAPI and Q_WebServer_State only
# holds what the script set itself, so a script that never calls header()
# produced a response with no Content-Type at all. Browsers then offer the
# page as a download instead of rendering it — phpinfo() being the obvious
# case. mod_php and fpm fall back to PHP's default_mimetype; so do we now.
#
# Explicit Content-Types must survive untouched, and 204/304 keep none.
#
#   ./tests/content-type.sh
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
echo '<h1>static</h1>'                                          > "$ROOT/s.html"
printf '<?php echo "plain";\n'                                  > "$ROOT/plain.php"
printf '<?php header("Content-Type: application/json"); echo "{}";\n' > "$ROOT/json.php"
printf '<?php header("Content-Type: text/plain"); echo "t";\n'  > "$ROOT/txt.php"
printf '<?php http_response_code(204);\n'                       > "$ROOT/empty.php"

echo "=============================================="
echo " Qbix Server — Content-Type on PHP responses"
echo "=============================================="
echo

( setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" --port=$PORT --workers=2 \
    >"$TMP/server.log" 2>&1 </dev/null & )

for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/s.html" 2>/dev/null && break
done

# ctype <path> -> the Content-Type value, lowercased, or '' when absent
ctype() {
    curl -s -D - -o /dev/null --max-time 12 "http://127.0.0.1:$PORT/$1" 2>/dev/null \
      | grep -i '^content-type:' | head -1 | cut -d: -f2- | tr -d '\r' \
      | sed 's/^ *//' | tr 'A-Z' 'a-z'
}
status() {
    curl -s -o /dev/null -w '%{http_code}' --max-time 12 "http://127.0.0.1:$PORT/$1" 2>/dev/null
}

c=$(ctype s.html)
case "$c" in text/html*) ok "static file keeps text/html ($c)";;
             *) bad "static file — got '${c:-none}'";; esac

# The regression: this one used to come back with no Content-Type at all.
c=$(ctype plain.php)
case "$c" in text/html*) ok "php without header() defaults to text/html ($c)";;
             *) bad "php without header() — got '${c:-none}'";; esac

c=$(ctype plain.php)
case "$c" in *charset=*) ok "default carries a charset ($c)";;
             *) bad "default has no charset — got '${c:-none}'";; esac

c=$(ctype json.php)
[ "$c" = "application/json" ] \
    && ok "explicit application/json survives" \
    || bad "explicit json overwritten — got '${c:-none}'"

c=$(ctype txt.php)
[ "$c" = "text/plain" ] \
    && ok "explicit text/plain survives" \
    || bad "explicit text/plain overwritten — got '${c:-none}'"

s=$(status empty.php); c=$(ctype empty.php)
if [ "$s" = "204" ]; then
    [ -z "$c" ] && ok "204 carries no Content-Type" \
                || bad "204 got a Content-Type ('$c')"
else
    bad "expected 204 from empty.php, got $s"
fi

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
