#!/usr/bin/env bash
#
# Regression test: a response body carries what the script wrote, and
# nothing the engine had to say about it.
#
# PHP assigns $context to every stream wrapper instance opened with a
# context. Q_WebServer_CompatFileWrapper never declared the property, so
# PHP 8.2+ reported a dynamic property on each include, and with the
# default display_errors those notices went into the response body ahead
# of the script's own output.
#
# On a text page that was a stray paragraph. On a generated image it was
# fatal: a captcha came back with 1.5KB of notices in front of the PNG
# signature, so the file would not open — served as image/png, with a 200,
# and nothing in the error log.
#
# It only reproduces when the server runs from the phar: including a file
# from inside it passes the wrapper a context, and running from source
# does not. So this test drives ./bin/qbixserver when one is built and
# says so when it has to fall back to the sources, where it proves
# nothing. That is the same gap that let the UPX-corrupted binaries and a
# gd-less build ship: the suite tests a configuration nobody receives.
#
#   ./tests/clean-body.sh          uses bin/qbixserver if present
#   QB=/path/to/binary ./tests/clean-body.sh
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

# Prefer the built binary: from source the wrapper is never handed a
# context and the notice cannot appear.
QB="${QB:-$WS/bin/qbixserver}"
if [ -x "$QB" ]; then
    RUN=("$QB")
    VIA="binary: $QB"
else
    RUN=("$PHP" "$WS/qbixserver.php")
    VIA="sources — the phar path is not exercised, this run proves little"
fi

ROOT="$TMP/public"; mkdir -p "$ROOT"
printf '<?php echo "EXACTLY-THIS";\n'                       > "$ROOT/plain.php"
printf '<?php session_start(); echo "SESSION-OK";\n'        > "$ROOT/sess.php"
printf '<?php header("Content-Type: application/json"); echo json_encode(["a"=>1]);\n' \
                                                            > "$ROOT/json.php"
cat > "$ROOT/inc.php" <<'PHP'
<?php
// Including another file is what opens the wrapper a second time.
require __DIR__ . '/lib.php';
echo greet();
PHP
printf '<?php function greet() { return "FROM-INCLUDE"; }\n' > "$ROOT/lib.php"

echo "=============================================="
echo " Qbix Server — no engine diagnostics in bodies"
echo "=============================================="
echo

echo "  via $VIA"
echo

( setsid "${RUN[@]}" --root="$ROOT" --port=$PORT --workers=2 \
    >"$TMP/server.log" 2>&1 </dev/null & )

for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/plain.php" 2>/dev/null && break
done

body() { curl -s --max-time 15 "http://127.0.0.1:$PORT/$1" 2>/dev/null; }

# exact <label> <path> <expected body>
exact() {
    local got; got=$(body "$2")
    if [ "$got" = "$3" ]; then ok "$1"
    else
        bad "$1 — body was ${#got} bytes, expected ${#3}"
        printf '       first 120: %s\n' "$(printf '%s' "$got" | head -c 120 | tr '\n' ' ')"
    fi
}

exact "plain script: body is exactly its output"   plain.php "EXACTLY-THIS"
exact "session_start(): body is exactly its output" sess.php  "SESSION-OK"
exact "json: body is exactly its output"           json.php  '{"a":1}'
exact "include: body is exactly its output"        inc.php   "FROM-INCLUDE"

# Nothing diagnostic anywhere, whatever the wording.
for f in plain.php sess.php json.php inc.php; do
    b=$(body "$f")
    case "$b" in
        *Deprecated:*|*"Warning:"*|*"Notice:"*|*"Fatal error:"*|*"Parse error:"*)
            bad "$f leaks a diagnostic into the body" ;;
        *) ok "$f carries no diagnostic" ;;
    esac
done

# session_start() must not trip "headers already sent", which it does once
# a notice has been printed before it.
b=$(body sess.php)
case "$b" in
    *"headers have already been sent"*) bad "session warns about sent headers" ;;
    *) ok "session starts without a headers warning" ;;
esac

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
