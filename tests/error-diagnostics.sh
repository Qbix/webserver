#!/usr/bin/env bash
#
# Regression test: a fatal says where it happened.
#
# The 500 body used to be $e->getMessage() and nothing else. "Value of
# type null is not callable" with no file, no line and no trace says
# almost nothing: the message names neither the script nor the library it
# came from, and the access log records a 500 without a location either.
# Finding any of these meant patching a trace into the server and
# rebuilding.
#
# The file and line are always reported. --debug adds the trace and walks
# the previous-exception chain, which is where a framework's real cause
# usually sits.
#
# Note that this puts server-side paths in a response body. That is the
# same trade-off as display_errors, and this is a development server; a
# deployment that must not disclose them should put a proxy in front or
# run with the error page a reverse proxy provides.
#
#   ./tests/error-diagnostics.sh
#
# Exits non-zero on any failure.

set -uo pipefail

WS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-php}"
PASS=0; FAIL=0
TMP="$(mktemp -d)"

free_port() {
    local p
    for _ in $(seq 1 60); do
        p=$(( 19000 + RANDOM % 900 ))
        ss -ltn 2>/dev/null | grep -q ":$p " || { echo "$p"; return 0; }
    done
    return 1
}
PORT=$(free_port)   || { echo "  no free port found"; exit 1; }
DPORT=$(free_port)  || { echo "  no free port found"; exit 1; }
trap 'rm -rf "$TMP"; pkill -f "qbixserver.*--port=$PORT" 2>/dev/null; pkill -f "qbixserver.*--port=$DPORT" 2>/dev/null' EXIT

ok()  { PASS=$((PASS+1)); printf "  ok   %s\n" "$1"; }
bad() { FAIL=$((FAIL+1)); printf "  FAIL %s\n" "$1"; }

command -v "$PHP" >/dev/null || { echo "no php"; exit 1; }

ROOT="$TMP/public"; mkdir -p "$ROOT"
printf '<?php header("Content-Type: text/plain"); echo "fine";\n'  > "$ROOT/ok.php"
printf '<?php $x = null; $x();\n'                                  > "$ROOT/boom.php"
cat > "$ROOT/chained.php" <<'PHP'
<?php
try {
    throw new LogicException('the cause');
} catch (Throwable $e) {
    throw new RuntimeException('what surfaced', 0, $e);
}
PHP

echo "=============================================="
echo " Qbix Server — what a 500 tells you"
echo "=============================================="
echo

( setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" --port=$PORT  --workers=1 \
    >"$TMP/plain.log" 2>&1 </dev/null & )
( setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" --port=$DPORT --workers=1 --debug \
    >"$TMP/debug.log" 2>&1 </dev/null & )

for port in $PORT $DPORT; do
    up=0
    for _ in $(seq 1 25); do
        sleep 0.4
        curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$port/ok.php" 2>/dev/null && { up=1; break; }
    done
    [ "$up" = "1" ] || { echo "  server on $port never came up"; exit 1; }
done

body()  { curl -s --max-time 15 "http://127.0.0.1:$1/$2" 2>/dev/null; }
status(){ curl -s -o /dev/null -w '%{http_code}' --max-time 15 "http://127.0.0.1:$1/$2" 2>/dev/null; }

[ "$(body $PORT ok.php)" = "fine" ] && ok "a working script is untouched" \
                                    || bad "working script: $(body $PORT ok.php)"

b=$(body $PORT boom.php)
[ "$(status $PORT boom.php)" = "500" ] && ok "a fatal is a 500" || bad "not a 500"
case "$b" in *"not callable"*) ok "the message is still there" ;;
             *) bad "message missing: $b" ;; esac
case "$b" in *"boom.php:"*) ok "the file and line are named ($b)" ;;
             *) bad "no file:line — $b" ;; esac

# Without --debug it stays one line: no trace.
case "$b" in *"#0 "*) bad "a trace appears without --debug" ;;
             *) ok "no trace without --debug" ;; esac

d=$(body $DPORT boom.php)
case "$d" in *"boom.php:"*) ok "--debug names the file too" ;;
             *) bad "--debug lost the file: $d" ;; esac
case "$d" in *"#0 "*) ok "--debug adds the trace" ;;
             *) bad "--debug has no trace" ;; esac

# A framework wraps its real cause; without the chain it is invisible.
c=$(body $DPORT chained.php)
case "$c" in *"what surfaced"*) ok "the outer exception is reported" ;;
             *) bad "outer exception missing: $c" ;; esac
case "$c" in *"Caused by"*"the cause"*) ok "--debug follows the cause" ;;
             *) bad "--debug does not follow the cause" ;; esac

cp=$(body $PORT chained.php)
case "$cp" in *"Caused by"*) bad "the cause chain appears without --debug" ;;
              *) ok "no cause chain without --debug" ;; esac

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
