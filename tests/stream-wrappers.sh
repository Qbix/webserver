#!/usr/bin/env bash
#
# Regression test: a script cannot unregister the wrappers the server
# reads itself through.
#
# Hardened applications drop the phar wrapper — Exponential, Drupal and
# others have done it since the 2018 phar deserialisation work:
#
#     if (PHP_SAPI !== 'cli' && in_array('phar', stream_get_wrappers())) {
#         stream_wrapper_unregister('phar');
#     }
#
# The guard spares CLI. This SAPI is called micro, so it does not apply,
# and in a single-file build the server lives in that phar and autoloads
# its own classes from phar:// paths. One page view took the runtime out
# from under itself — invisibly, since preloaded classes kept working and
# only a request needing a new one failed, reporting whatever class that
# request wanted. A worker outlives the request, so the damage stayed for
# every later request it handled.
#
# Needs the binary: from a source checkout there is no phar to lose, and
# every assertion here passes without meaning anything.
#
#   ./tests/stream-wrappers.sh
#   QB=/path/to/binary ./tests/stream-wrappers.sh
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

QB="${QB:-$WS/bin/qbixserver}"
if [ -x "$QB" ]; then
    RUN=("$QB"); VIA="binary: $QB"
else
    RUN=("$PHP" "$WS/qbixserver.php")
    VIA="sources — no phar to lose, this run proves little"
fi

ROOT="$TMP/public"; mkdir -p "$ROOT"

printf '<?php header("Content-Type: text/plain"); echo implode(",", stream_get_wrappers());\n' \
    > "$ROOT/list.php"
# What a hardened application does on every request.
cat > "$ROOT/harden.php" <<'PHP'
<?php
header('Content-Type: text/plain');
if (PHP_SAPI !== 'cli' && in_array('phar', stream_get_wrappers())) {
    $r = stream_wrapper_unregister('phar');
    echo "called, returned ", var_export($r, true);
} else {
    echo "not attempted";
}
PHP
printf '<?php header("Content-Type: text/plain"); var_dump(stream_wrapper_unregister("file"));\n' \
    > "$ROOT/killfile.php"
# A wrapper that is not ours must still be removable.
cat > "$ROOT/other.php" <<'PHP'
<?php
header('Content-Type: text/plain');
$had = in_array('ftp', stream_get_wrappers());
$r = $had ? stream_wrapper_unregister('ftp') : null;
echo "had=", var_export($had, true), " removed=", var_export($r, true),
     " still=", var_export(in_array('ftp', stream_get_wrappers()), true);
PHP
# Something that forces the server to autoload a class it has not used yet.
printf '<?php header("Content-Type: text/plain"); setcookie("x","1",0,"/"); session_start(); echo "worked";\n' \
    > "$ROOT/after.php"

echo "=============================================="
echo " Qbix Server — the server's own stream wrappers"
echo "=============================================="
echo "  via $VIA"
echo

( setsid "${RUN[@]}" --root="$ROOT" --port=$PORT --workers=1 \
    >"$TMP/server.log" 2>&1 </dev/null & )

for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/list.php" 2>/dev/null && break
done

body() { curl -s --max-time 15 "http://127.0.0.1:$PORT/$1" 2>/dev/null; }
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 15 "http://127.0.0.1:$PORT/$1" 2>/dev/null; }

b=$(body list.php)
case "$b" in *phar*) ok "phar is registered to begin with" ;;
             *) bad "no phar wrapper at all — nothing to test" ;; esac

b=$(body harden.php)
case "$b" in
    *"returned true"*) ok "the hardening call reports success ($b)" ;;
    *"not attempted"*) bad "phar was already gone before the call" ;;
    *) bad "unexpected: $b" ;;
esac

# The point of the whole thing.
b=$(body list.php)
case "$b" in *phar*) ok "phar survives the call" ;;
             *) bad "phar was removed — the server can no longer autoload" ;; esac

b=$(body killfile.php)
b2=$(body list.php)
case "$b2" in *file*) ok "file survives too" ;;
             *) bad "file wrapper was removed" ;; esac

# Only ours are protected.
b=$(body other.php)
case "$b" in
    *"had=false"*) echo "  note: no ftp wrapper here, skipping the pass-through case" ;;
    *"removed=true still=false"*) ok "an unrelated wrapper can still be unregistered" ;;
    *) bad "unrelated wrapper not removable: $b" ;;
esac

# Same worker, a request that makes the server reach for a class it has
# not loaded yet: this is where the damage used to surface.
[ "$(code after.php)" = "200" ] && ok "worker still serves after the attempt" \
                               || bad "worker broken after the attempt"
[ "$(body after.php)" = "worked" ] && ok "and returns the right body" \
                                   || bad "body wrong: $(body after.php)"

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
