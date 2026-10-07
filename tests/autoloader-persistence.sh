#!/usr/bin/env bash
#
# Regression test: an autoloader a script registers stays registered.
#
# The compat layer used to unregister them at the end of each request, to
# hand the next one the boot-time stack. But a class declared during a
# request stays declared, and applications register their autoloader
# behind a guard on exactly that:
#
#     if (!class_exists('ezpAutoloader', false)) {
#         class ezpAutoloader { ... }
#         spl_autoload_register(array('ezpAutoloader', 'autoload'));
#     }
#
# Second request: the class is still there, the block is skipped, the
# autoloader is never registered again, and nothing can be loaded.
# Exponential served the first request and reported "Class eZDB not found"
# for every one after — on whichever worker had already seen a request, so
# the site failed for some visitors and not others.
#
# A distinct class per request matters here: reusing one would be found
# already loaded from the first request and would pass either way.
#
#   ./tests/autoloader-persistence.sh
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

ROOT="$TMP/public"; mkdir -p "$ROOT/classes"
for n in 1 2 3 4; do
    printf '<?php class Widget%s { public static function name() { return "W%s"; } }\n' \
        "$n" "$n" > "$ROOT/classes/Widget$n.php"
done

cat > "$ROOT/app.php" <<'PHP'
<?php
header('Content-Type: text/plain');
if (!class_exists('MyLoader', false)) {
    class MyLoader {
        public static function load($c) {
            $f = __DIR__ . '/classes/' . $c . '.php';
            if (is_file($f)) require_once $f;
        }
    }
    spl_autoload_register(array('MyLoader', 'load'));
}
$n = (int) ($_GET['n'] ?? 1);
$c = 'Widget' . $n;
echo "loaders=", count(spl_autoload_functions() ?: []), " ";
echo class_exists($c) ? $c::name() : "NO-AUTOLOAD";
PHP

echo "=============================================="
echo " Qbix Server — autoloaders across requests"
echo "=============================================="
echo

# One worker, so every request lands on the one that already ran.
( setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" --port=$PORT --workers=1 \
    >"$TMP/server.log" 2>&1 </dev/null & )

up=0
for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/app.php?n=1" 2>/dev/null && { up=1; break; }
done
[ "$up" = "1" ] || { echo "  server never came up"; sed 's/^/    /' "$TMP/server.log" | head -10; exit 1; }

get() { curl -s --max-time 15 "http://127.0.0.1:$PORT/app.php?n=$1" 2>/dev/null; }

r1=$(get 1)
case "$r1" in *W1*) ok "first request autoloads (${r1})" ;;
              *) bad "first request failed: $r1" ;; esac

# The point: a class not seen before, on a worker that has already served.
r2=$(get 2)
case "$r2" in
    *W2*) ok "second request still autoloads (${r2})" ;;
    *NO-AUTOLOAD*) bad "second request lost the autoloader (${r2})" ;;
    *) bad "second request: $r2" ;;
esac

r3=$(get 3)
case "$r3" in *W3*) ok "third request still autoloads (${r3})" ;;
              *) bad "third request: $r3" ;; esac

r4=$(get 4)
case "$r4" in *W4*) ok "fourth request still autoloads (${r4})" ;;
              *) bad "fourth request: $r4" ;; esac

# The count must not drift either way: not shrinking to the boot stack,
# and not growing one loader per request.
n1=${r1#loaders=}; n1=${n1%% *}
n4=${r4#loaders=}; n4=${n4%% *}
[ "$n1" = "$n4" ] \
    && ok "the autoloader stack neither shrinks nor grows ($n1)" \
    || bad "stack drifted from $n1 to $n4"

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
