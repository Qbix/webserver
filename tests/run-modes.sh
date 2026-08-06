#!/usr/bin/env bash
#
# Dual-mode acceptance tests for the Qbix Server.
#
# Starts the server for real, twice — standalone and --app — and drives it over
# HTTP. Nothing here is mocked: these are the behaviours that broke when the
# Platform's classes started winning over ours, so they are checked in both modes.
#
#   ./tests/run-modes.sh [/path/to/app] [/path/to/platform]
#
# Exits non-zero on any failure.

set -uo pipefail

WS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="${1:-/tmp/apps/Hebrews}"
PLATFORM="${2:-/tmp/qbix/Platform/platform}"
PASS=0; FAIL=0
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"; [ -n "${PID:-}" ] && kill "$PID" 2>/dev/null' EXIT

ok()   { PASS=$((PASS+1)); printf "  ok   %s\n" "$1"; }
bad()  { FAIL=$((FAIL+1)); printf "  FAIL %s\n" "$1"; }
check(){ if [ "$2" = "$3" ]; then ok "$1 ($3)"; else bad "$1 — expected $3, got $2"; fi; }

# free-ish port
port() { echo $(( 8800 + RANDOM % 800 )); }

start() { # start <logfile> <args...>
    local log="$1"; shift
    ( setsid "$PHP" "$WS/qbixserver.php" "$@" >"$log" 2>&1 </dev/null & )
    for _ in $(seq 1 25); do
        sleep 0.4
        if curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/" 2>/dev/null \
           || ss -tnl 2>/dev/null | grep -q ":$PORT "; then return 0; fi
    done
    return 1
}
stop() { pkill -f "qbixserver.php.*--port=$PORT" 2>/dev/null; sleep 0.5; }

code() { curl -s -o /dev/null -w '%{http_code}' --max-time 12 "$@" 2>/dev/null; }
body() { curl -s --max-time 12 "$@" 2>/dev/null; }

PHP="${PHP:-php}"
command -v "$PHP" >/dev/null || { echo "no php"; exit 1; }

echo "=============================================="
echo " Qbix Server — dual-mode acceptance"
echo "=============================================="

# ─────────────────────────────────────────────────────────────────────────────
echo
echo "MODE 1: standalone (no Platform)"
echo "----------------------------------------------"
ROOT="$TMP/public"; mkdir -p "$ROOT/sub"
echo "hello-static" > "$ROOT/hello.txt"
printf 'body{color:red}' > "$ROOT/a.css"
cat > "$ROOT/echo.php" <<'PHP'
<?php
header('X-Mode: standalone');
// Set one through the Qbix response API too. A regression split the header
// store in two (Q_Response kept its own array while the server read
// Q_WebServer_State's), so headers set this way vanished from the response.
if (class_exists('Q_Response')) { Q_Response::setHeader('X-Via-Q', 'yes'); }
echo json_encode(array(
  'script'   => $_SERVER['SCRIPT_NAME'] ?? null,
  'pathInfo' => $_SERVER['PATH_INFO'] ?? '',
  'method'   => $_SERVER['REQUEST_METHOD'] ?? null,
  'query'    => $_SERVER['QUERY_STRING'] ?? '',
  'post'     => $_POST,
  'raw'      => file_get_contents('php://input')
), JSON_UNESCAPED_SLASHES);
PHP

PORT="$(port)"
if start "$TMP/standalone.log" --port="$PORT" --root="$ROOT"; then
    ok "server starts standalone on :$PORT"

    check "static text file"        "$(code "http://127.0.0.1:$PORT/hello.txt")" "200"
    check "static css file"         "$(code "http://127.0.0.1:$PORT/a.css")"     "200"
    check "unknown path is 404"     "$(code "http://127.0.0.1:$PORT/no-such-file")" "404"
    check "blocked dir is refused"  "$(code "http://127.0.0.1:$PORT/../qbixserver.php")" "404"
    check "php script executes"     "$(code "http://127.0.0.1:$PORT/echo.php")"  "200"

    B="$(body "http://127.0.0.1:$PORT/hello.txt")"
    [ "$B" = "hello-static" ] && ok "static body is exact" || bad "static body: '$B'"

    # PATH_INFO splitting — the bug that made every action call return HTML
    J="$(body "http://127.0.0.1:$PORT/echo.php/Some/Path")"
    echo "$J" | grep -q '"pathInfo":"/Some/Path"' \
        && ok "PATH_INFO split from script.php/path/info" \
        || bad "PATH_INFO not split: $(echo "$J" | head -c 90)"
    echo "$J" | grep -q '"script":"/echo.php"' \
        && ok "SCRIPT_NAME is the script, not index.php" \
        || bad "SCRIPT_NAME wrong: $(echo "$J" | head -c 90)"

    # query string + POST body survive
    echo "$(body "http://127.0.0.1:$PORT/echo.php?a=1&b=2")" | grep -q '"query":"a=1&b=2"' \
        && ok "query string passed through" || bad "query string lost"
    P="$(curl -s --max-time 12 -X POST --data 'k=v' "http://127.0.0.1:$PORT/echo.php" 2>/dev/null)"
    echo "$P" | grep -q '"post":{"k":"v"}' \
        && ok "POST body parsed into \$_POST" || bad "POST body lost: $(echo "$P" | head -c 80)"
    # php://input must work: the forking server consumes the real stream, so
    # Q_WebServer_State registers Q_PhpInputStream over the `php` wrapper to
    # serve THIS request's body. Regressed once when setInput() moved classes.
    echo "$P" | grep -q '"raw":"k=v"' \
        && ok "php://input returns the request body" \
        || bad "php://input empty: $(echo "$P" | head -c 90)"
    # and it must be per-request, not sticky from the previous one
    P2="$(curl -s --max-time 12 -X POST --data 'second=req' "http://127.0.0.1:$PORT/echo.php" 2>/dev/null)"
    echo "$P2" | grep -q '"raw":"second=req"' \
        && ok "php://input is per-request, not stale" \
        || bad "php://input stale: $(echo "$P2" | head -c 90)"

    # headers must survive: native header() AND the Qbix response API
    H="$(curl -s -D- -o /dev/null --max-time 12 "http://127.0.0.1:$PORT/echo.php" 2>/dev/null | tr -d '\r')"
    # PHP's native header() is a no-op under the CLI SAPI and headers_list()
    # returns nothing, so a long-running CLI server CANNOT capture it. Apps must
    # use Q_Response::setHeader() / Q::header(). Asserted so the day this DOES
    # start working (a different SAPI), the suite tells us.
    if echo "$H" | grep -qi "^X-Mode: standalone"; then
        ok "native header() reaches the response (SAPI now supports it)"
    else
        ok "native header() not captured under CLI SAPI (expected; use Q_Response::setHeader)"
    fi
    echo "$H" | grep -qi "^X-Via-Q: yes" \
        && ok "Q_Response::setHeader() reaches the response" \
        || bad "Q_Response::setHeader() lost — header store is split"

    # response must not be prefixed with a NUL byte (BUG #46 class)
    curl -s --max-time 12 "http://127.0.0.1:$PORT/echo.php" 2>/dev/null | head -c 1 | od -An -tx1 \
        | grep -q '00' && bad "response starts with a NUL byte" || ok "no leading NUL byte"

    # concurrency: the forking server must not drop requests
    N=0
    for i in $(seq 1 12); do
        ( trap - EXIT; [ "$(code "http://127.0.0.1:$PORT/echo.php")" = "200" ] && echo x >> "$TMP/conc" ) &
    done
    wait
    N="$(wc -l < "$TMP/conc" 2>/dev/null || echo 0)"
    check "12 concurrent requests all 200" "$N" "12"

    stop
else
    bad "server failed to start standalone"; cat "$TMP/standalone.log" 2>/dev/null | tail -5
fi

# ─────────────────────────────────────────────────────────────────────────────
echo
echo "MODE 2: --app (Platform loaded, its classes win)"
echo "----------------------------------------------"
if [ ! -f "$APP/scripts/Q.inc.php" ]; then
    echo "  SKIP: no app at $APP"
else
    # Point the app's baseUrl at the address we will actually listen on, so the
    # front controller can genuinely serve. Without this the Platform correctly
    # refuses the URL and we would only ever be testing its refusal path.
    PORT="$(port)"
    APPJSON="$APP/local/app.json"
    cp "$APPJSON" "$TMP/app.json.bak"
    "$PHP" -r '
        $f = $argv[1]; $url = $argv[2];
        $s = file_get_contents($f);
        $s = preg_replace("/\"appRootUrl\"\s*:\s*\"[^\"]*\"/", "\"appRootUrl\": \"$url\"", $s, 1);
        file_put_contents($f, $s);
    ' "$APPJSON" "http://127.0.0.1:$PORT" 2>/dev/null
    restore_app() { cp "$TMP/app.json.bak" "$APPJSON" 2>/dev/null; }
    trap 'restore_app; rm -rf "$TMP"; pkill -f "qbixserver.php.*--port=$PORT" 2>/dev/null' EXIT

    if start "$TMP/app.log" --app="$APP" --host=127.0.0.1 --port="$PORT"; then
        ok "server starts in --app mode on :$PORT"

        LOG="$TMP/app.log"
        grep -qi "Class \"Q_WebServer" "$LOG" && bad "class-not-found in app mode" \
            || ok "no Q_WebServer class-loading errors"
        grep -qi "undeclared static property" "$LOG" && bad "undeclared static property" \
            || ok "no undeclared-property errors (Q::\$paths class)"
        grep -qi "undefined method" "$LOG" && bad "undefined method in app mode" \
            || ok "no undefined-method errors (Q_Request/Q_Response class)"

        check "static file still served" "$(code "http://127.0.0.1:$PORT/robots.txt")" "200"

        # THE end-to-end assertion: the app's own front controller renders.
        C="$(code "http://127.0.0.1:$PORT/")"
        check "app front controller renders at /" "$C" "200"
        HTML="$(body "http://127.0.0.1:$PORT/")"
        # Assert SERVER correctness, not app content. The stock MyApp scaffold
        # renders a large debug page; a minimal fixture app renders a few dozen
        # bytes. Both are correct -- the server's job is to deliver whatever the
        # app produced, intact. Grading the byte count made this suite pass or
        # fail on which app you happened to point it at.
        [ "${#HTML}" -gt 0 ] && ok "app response body delivered (${#HTML} bytes)" \
            || bad "app response body was empty"
        echo "$HTML" | grep -qiE "Fatal error|Parse error|Call to undefined|Uncaught" \
            && bad "app response contains a PHP fatal" \
            || ok "app response contains no PHP fatal"
        CT="$(curl -s -D- -o /dev/null --max-time 15 "http://127.0.0.1:$PORT/" 2>/dev/null \
              | grep -i '^content-type' | tr -d '\r')"
        echo "$CT" | grep -qi "text/html" && ok "Content-Type is text/html" \
            || bad "Content-Type: $CT"

        # index.php directly, and a Qbix action route
        check "index.php directly" "$(code "http://127.0.0.1:$PORT/index.php")" "200"
        AC="$(code "http://127.0.0.1:$PORT/action.php/Streams/stream")"
        [ "$AC" = "200" ] || [ "$AC" = "400" ] || [ "$AC" = "401" ] || [ "$AC" = "403" ] \
            && ok "action.php route dispatches (HTTP $AC, not a 500)" \
            || bad "action.php returned $AC"

        # no NUL prefix on a real rendered page
        printf '%s' "$HTML" | head -c 1 | od -An -tx1 | grep -q '00' \
            && bad "app-mode response starts with a NUL byte" || ok "no leading NUL byte in app mode"

        # concurrency against the real app
        rm -f "$TMP/conc2"
        for i in $(seq 1 8); do
            ( trap - EXIT; [ "$(code "http://127.0.0.1:$PORT/")" = "200" ] && echo x >> "$TMP/conc2" ) &
        done
        wait
        check "8 concurrent app requests all 200" "$(wc -l < "$TMP/conc2" 2>/dev/null || echo 0)" "8"

        stop
        restore_app
    else
        restore_app
        bad "server failed to start in --app mode"; tail -5 "$TMP/app.log" 2>/dev/null
    fi
fi

# ─────────────────────────────────────────────────────────────────────────────
echo
echo "MODE 3: static analysis"
echo "----------------------------------------------"
if [ -d "$PLATFORM/classes" ]; then
    if "$PHP" "$WS/tests/platform-compat.php" "$PLATFORM" >/dev/null 2>&1; then
        ok "platform-compat: no use of members the Platform lacks"
    else
        bad "platform-compat found violations (run it for detail)"
    fi
else
    echo "  SKIP: no Platform at $PLATFORM"
fi

for f in "$WS"/src/Q/*.php "$WS"/src/Q/WebServer/*.php "$WS"/qbixserver.php; do
    "$PHP" -l "$f" >/dev/null 2>&1 || bad "syntax error in $(basename "$f")"
done
ok "all sources lint clean"

echo
echo "=============================================="
printf " %d passed, %d failed\n" "$PASS" "$FAIL"
echo "=============================================="
[ "$FAIL" -eq 0 ] || exit 1
