#!/usr/bin/env bash
#
# Regression test: idle workers must survive.
#
# An octane worker blocks on readExact() between requests. PHP applies
# default_socket_timeout to that read, so without an explicit
# stream_set_timeout() the read returns '' after 60s idle, readExact()
# reports it as EOF, and the worker exits. The pool only notices when it
# dispatches, so the first N requests after an idle period (N = worker
# count) come back 502 "Worker died".
#
# Waiting 60s per assertion would be useless in CI, so the server is
# started with a 2s default_socket_timeout: the bug reproduces in seconds
# and the fix has to hold regardless of the ini value.
#
#   ./tests/idle-worker.sh
#
# Exits non-zero on any failure.

set -uo pipefail

WS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-php}"
WORKERS=2
IDLE=6            # > default_socket_timeout below
SOCK_TIMEOUT=2
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
printf '<?php echo "alive";\n' > "$ROOT/t.php"

echo "=============================================="
echo " Qbix Server — idle worker survival"
echo "=============================================="
echo "  port=$PORT workers=$WORKERS default_socket_timeout=${SOCK_TIMEOUT}s idle=${IDLE}s"
echo

( setsid "$PHP" -d default_socket_timeout=$SOCK_TIMEOUT "$WS/qbixserver.php" \
    --root="$ROOT" --port=$PORT --workers=$WORKERS \
    >"$TMP/server.log" 2>&1 </dev/null & )

for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/t.php" 2>/dev/null && break
done

code() { curl -s -o /dev/null -w '%{http_code}' --max-time 12 "http://127.0.0.1:$PORT/t.php" 2>/dev/null; }
pids() {
    curl -s --max-time 8 "http://127.0.0.1:$PORT/Q/health" 2>/dev/null \
      | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN),true);
          $w=$d["workerStats"]["workers"]??[];
          echo implode(",", array_map(fn($x)=>$x["pid"], $w));' 2>/dev/null
}

c=$(code)
[ "$c" = "200" ] && ok "serves before idle (200)" || bad "serves before idle — got $c"

before="$(pids)"
[ -n "$before" ] && ok "worker pids readable ($before)" || bad "could not read worker pids"

sleep $IDLE

# Every worker is idle past the socket timeout. Ask for exactly as many
# requests as there are workers: with the bug, each one lands on a worker
# that quietly exited and every single one is a 502.
fails=0
for i in $(seq 1 $WORKERS); do
    c=$(code)
    [ "$c" = "200" ] || { fails=$((fails+1)); printf "       request %d after idle -> %s\n" "$i" "$c"; }
done
[ "$fails" -eq 0 ] \
    && ok "all $WORKERS requests after ${IDLE}s idle are 200" \
    || bad "$fails of $WORKERS requests after idle failed"

after="$(pids)"
[ "$before" = "$after" ] \
    && ok "worker pids unchanged — nobody was respawned" \
    || bad "workers were respawned: $before -> $after"

sleep $IDLE
c=$(code)
[ "$c" = "200" ] && ok "still serving after a second idle period" || bad "second idle period — got $c"

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
