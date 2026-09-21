#!/usr/bin/env bash
#
# Regression test: setcookie() reaches the client, and sessions survive.
#
# Cookies are collected in Q_Response, which lives in the worker. The
# parent built the response and read its own copy of that state, which is
# always empty, so no cookie a script set ever went out. header('Set-Cookie:
# ...') worked, because that is an ordinary header and travels with the
# response — which is why this looked like a session bug rather than a
# cookie one.
#
# Nothing said so. The script ran, the status was 200, the body was right,
# and the browser simply never got a cookie: every request started a fresh
# session, so nothing could stay logged in and a captcha could never
# validate, since the code it stored was in a session the next request
# could not find.
#
#   ./tests/cookies-sessions.sh
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

printf '<?php setcookie("one","v1",0,"/"); header("Content-Type: text/plain"); echo "ok";\n' \
    > "$ROOT/one.php"
printf '<?php setcookie("a","1",0,"/"); setcookie("b","2",0,"/"); header("Content-Type: text/plain"); echo "ok";\n' \
    > "$ROOT/two.php"
printf '<?php header("Set-Cookie: manual=m; Path=/"); header("Content-Type: text/plain"); echo "ok";\n' \
    > "$ROOT/manual.php"
cat > "$ROOT/count.php" <<'PHP'
<?php
session_start();
$_SESSION['n'] = ($_SESSION['n'] ?? 0) + 1;
header('Content-Type: text/plain');
echo "id=", session_id(), " n=", $_SESSION['n'];
PHP

echo "=============================================="
echo " Qbix Server — cookies and sessions"
echo "=============================================="
echo

( setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" --port=$PORT --workers=2 \
    >"$TMP/server.log" 2>&1 </dev/null & )

for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/one.php" 2>/dev/null && break
done

heads() { curl -s -D - -o /dev/null --max-time 15 "http://127.0.0.1:$PORT/$1" 2>/dev/null | tr -d '\r'; }

h=$(heads one.php)
case "$h" in
    *"Set-Cookie: one=v1"*) ok "setcookie() reaches the client" ;;
    *) bad "setcookie() produced no Set-Cookie" ;;
esac

h=$(heads manual.php)
case "$h" in
    *"Set-Cookie: manual=m"*) ok "header('Set-Cookie: ...') still works" ;;
    *) bad "manual Set-Cookie header lost" ;;
esac

# Two cookies need two headers: an associative header array holds one, so
# this is where a naive fix quietly drops the first.
h=$(heads two.php)
n=$(printf '%s' "$h" | grep -ci '^set-cookie:')
[ "$n" = "2" ] && ok "two cookies produce two Set-Cookie headers" \
               || bad "expected 2 Set-Cookie headers, got $n"
case "$h" in *"a=1"*) ok "first of the two survives" ;; *) bad "cookie a=1 missing" ;; esac
case "$h" in *"b=2"*) ok "second of the two survives" ;; *) bad "cookie b=2 missing" ;; esac

# The session id has to come back as a cookie, or nothing can persist.
jar="$TMP/jar"
r1=$(curl -s -c "$jar" --max-time 15 "http://127.0.0.1:$PORT/count.php" 2>/dev/null)
grep -qi 'PHPSESSID' "$jar" && ok "session cookie is set" || bad "no session cookie"

r2=$(curl -s -b "$jar" -c "$jar" --max-time 15 "http://127.0.0.1:$PORT/count.php" 2>/dev/null)
r3=$(curl -s -b "$jar" --max-time 15 "http://127.0.0.1:$PORT/count.php" 2>/dev/null)

id1=${r1#id=}; id1=${id1%% *}
id2=${r2#id=}; id2=${id2%% *}
id3=${r3#id=}; id3=${id3%% *}
[ -n "$id1" ] && [ "$id1" = "$id2" ] && [ "$id2" = "$id3" ] \
    && ok "session id stays the same across requests" \
    || bad "session id changed: $id1 / $id2 / $id3"

case "$r1 | $r2 | $r3" in
    *"n=1"*"n=2"*"n=3"*) ok "session data accumulates (n=1,2,3)" ;;
    *) bad "session data did not persist: $r1 | $r2 | $r3" ;;
esac

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
