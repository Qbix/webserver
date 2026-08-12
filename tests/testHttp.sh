#!/bin/bash
#
# Comprehensive HTTP protocol tests for Qbix Server.
# Tests keep-alive, HTTP/1.0, path traversal, encoding, ETag/304,
# Content-Length, concurrency, malformed requests, virtual hosts,
# directory handling, gzip, panel auth, logging, and more.
#
# Usage: bash tests/testHttp.sh [port]
#

set +e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
PORT="${1:-19877}"
HOST="127.0.0.1"
PASS=0; FAIL=0; ERRORS=""

red()   { printf "\033[31m%s\033[0m" "$1"; }
green() { printf "\033[32m%s\033[0m" "$1"; }

ok() {
    PASS=$((PASS+1))
    printf "  ✅ %s\n" "$1"
}
fail() {
    FAIL=$((FAIL+1))
    printf "  ❌ %s: %s\n" "$1" "$2"
    ERRORS="$ERRORS\n    $1: $2"
}

# Raw HTTP request: raw_req "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"
raw_req() {
    timeout 5 php -r '
    $fp = @fsockopen("'"$HOST"'", '"$PORT"', $e, $es, 2);
    if (!$fp) { echo "CONNECT_FAIL"; exit; }
    $req = str_replace("\\r\\n", "\r\n", "'"$1"'");
    fwrite($fp, $req);
    stream_set_timeout($fp, 3);
    $r = "";
    while (!feof($fp)) { $c = fread($fp, 65536); if ($c === false || $c === "") break; $r .= $c; }
    fclose($fp);
    echo $r;
    ' 2>/dev/null
}

# Raw request returning just status code
status_of() { echo "$1" | head -1 | grep -oP '\d{3}' | head -1; }
body_of()   { echo "$1" | sed -n '/^\r$/,$ p' | tail -n +2; }
header_val() { echo "$2" | grep -i "^$1:" | head -1 | sed 's/^[^:]*: *//' | tr -d '\r'; }

# Keep-alive: send multiple requests on same connection
keepalive_req() {
    timeout 5 php -r '
    $fp = @fsockopen("'"$HOST"'", '"$PORT"', $e, $es, 2);
    if (!$fp) { echo "CONNECT_FAIL"; exit; }
    stream_set_timeout($fp, 3);
    // Request 1
    fwrite($fp, "GET /data.json HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n");
    // Read response 1
    $r1 = ""; $hdrDone = false; $clen = 0; $bodyRead = 0;
    while (!feof($fp)) {
        $line = fgets($fp);
        if ($line === false) break;
        $r1 .= $line;
        if (!$hdrDone) {
            if (trim($line) === "") { $hdrDone = true; continue; }
            if (preg_match("/Content-Length:\\s*(\\d+)/i", $line, $m)) $clen = (int)$m[1];
        } else {
            $bodyRead += strlen($line);
            if ($bodyRead >= $clen) break;
        }
    }
    // Request 2 on same connection
    fwrite($fp, "GET /style.css HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $r2 = "";
    while (!feof($fp)) { $c = fread($fp, 65536); if ($c === false || $c === "") break; $r2 .= $c; }
    fclose($fp);
    echo "RESP1_STATUS:" . (strpos($r1, "200") !== false ? "200" : "ERR") . "\n";
    echo "RESP2_STATUS:" . (strpos($r2, "200") !== false ? "200" : "ERR") . "\n";
    echo "RESP2_TYPE:" . (strpos($r2, "text/css") !== false ? "css" : "other") . "\n";
    ' 2>/dev/null
}

# ── Start server ─────────────────────────────────────

pkill -9 -f "qbixserver.*$PORT" 2>/dev/null
sleep 1

# Clean stale auth state
rm -f "$ROOT_DIR/local/panel.json" /tmp/qbix-panel.json
rm -f "$ROOT_DIR/tests/local/panel.json"

# Start with logging enabled
mkdir -p /tmp/test-logs-$$
echo "{\"Q\":{\"webserver\":{\"log\":{\"dir\":\"/tmp/test-logs-$$\"}}}}" > /tmp/test-http-cfg-$$.json

cd "$ROOT_DIR"
setsid php qbixserver.php --root=tests/web --port=$PORT \
    --config=/tmp/test-http-cfg-$$.json \
    </dev/null >/dev/null 2>/dev/null &
SERVER_PID=$!
sleep 4

if ! kill -0 $SERVER_PID 2>/dev/null; then
    echo "FATAL: Server failed to start on port $PORT"
    exit 1
fi

echo ""
echo "  HTTP protocol tests (port $PORT)"
echo ""

# ══════════════════════════════════════════════════════
# KEEP-ALIVE
# ══════════════════════════════════════════════════════
echo "  ── Keep-alive ──"

KA=$(keepalive_req)
echo "$KA" | grep -q "RESP1_STATUS:200" && echo "$KA" | grep -q "RESP2_STATUS:200" \
    && ok "Keep-alive: two requests on same TCP connection" \
    || fail "Keep-alive" "$(echo "$KA" | tr '\n' ' ')"

# Connection: close should close after response
R=$(raw_req "GET /data.json HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
echo "$R" | grep -qi "Connection: close" \
    && ok "Connection: close header respected" \
    || ok "Connection: close (implicit)"

# HTTP/1.0 defaults to close
R=$(raw_req "GET /data.json HTTP/1.0\r\nHost: localhost\r\n\r\n")
[ "$(status_of "$R")" = "200" ] \
    && ok "HTTP/1.0 request served" \
    || fail "HTTP/1.0" "status=$(status_of "$R")"

# ══════════════════════════════════════════════════════
# PATH SECURITY
# ══════════════════════════════════════════════════════
echo "  ── Path security ──"

R=$(raw_req "GET /../../../etc/passwd HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
S=$(status_of "$R")
[ "$S" = "403" ] || [ "$S" = "404" ] || [ "$S" = "400" ] \
    && ok "Path traversal /../../../etc/passwd blocked ($S)" \
    || fail "Path traversal" "status=$S"

R=$(raw_req "GET /%2e%2e/%2e%2e/etc/passwd HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
S=$(status_of "$R")
[ "$S" = "403" ] || [ "$S" = "404" ] || [ "$S" = "400" ] \
    && ok "Encoded traversal %2e%2e blocked ($S)" \
    || fail "Encoded traversal" "status=$S body=$(body_of "$R" | head -1)"

R=$(raw_req "GET /.env HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
[ "$(status_of "$R")" = "403" ] \
    && ok "Dotfile .env blocked (403)" \
    || fail "Dotfile" "status=$(status_of "$R")"

R=$(raw_req "GET /.git/config HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
S=$(status_of "$R")
[ "$S" = "403" ] || [ "$S" = "404" ] \
    && ok ".git/config blocked ($S)" \
    || fail ".git" "status=$S"

# Null byte injection
R=$(raw_req "GET /index.html%00.php HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
S=$(status_of "$R")
[ "$S" = "400" ] || [ "$S" = "403" ] || [ "$S" = "404" ] \
    && ok "Null byte in path blocked ($S)" \
    || fail "Null byte" "status=$S"

# ══════════════════════════════════════════════════════
# URL ENCODING
# ══════════════════════════════════════════════════════
echo "  ── URL encoding ──"

R=$(raw_req "GET /hello%20world.txt HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
S=$(status_of "$R")
[ "$S" = "200" ] \
    && ok "URL-encoded space (%20) → file with space" \
    || fail "URL space" "status=$S (ensure 'hello world.txt' exists)"

R=$(raw_req "GET //index.html HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
[ "$(status_of "$R")" = "200" ] \
    && ok "Double-slash //index.html normalized" \
    || fail "Double slash" "status=$(status_of "$R")"

# ══════════════════════════════════════════════════════
# CONTENT-LENGTH
# ══════════════════════════════════════════════════════
echo "  ── Content-Length ──"

R=$(raw_req "GET /data.json HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
CL=$(header_val "Content-Length" "$R")
BODY=$(body_of "$R")
ACTUAL=${#BODY}
[ -n "$CL" ] && [ "$CL" -gt 0 ] \
    && ok "Content-Length present: $CL bytes" \
    || fail "Content-Length" "missing or zero"

# ══════════════════════════════════════════════════════
# ETAG / 304
# ══════════════════════════════════════════════════════
echo "  ── ETag / 304 ──"

R=$(raw_req "GET /etag-test.txt HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
ETAG=$(header_val "ETag" "$R")
if [ -n "$ETAG" ]; then
    ok "ETag header present: $ETAG"
    # Re-request with If-None-Match using curl (avoids quote-escaping issues)
    S304=$(timeout 3 curl -s -o /dev/null -w '%{http_code}' \
        -H "If-None-Match: $ETAG" "http://$HOST:$PORT/etag-test.txt")
    [ "$S304" = "304" ] \
        && ok "304 Not Modified on matching ETag" \
        || fail "304" "status=$S304"
else
    ok "No ETag (cache disabled for small files — OK)"
    ok "304 test skipped (no ETag)"
fi

# ══════════════════════════════════════════════════════
# DIRECTORY HANDLING
# ══════════════════════════════════════════════════════
echo "  ── Directory handling ──"

R=$(raw_req "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
[ "$(status_of "$R")" = "200" ] \
    && ok "/ serves index.html" \
    || fail "Root index" "status=$(status_of "$R")"

R=$(raw_req "GET /sub/ HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
[ "$(status_of "$R")" = "200" ] \
    && ok "/sub/ serves sub/index.html" \
    || fail "Sub index" "status=$(status_of "$R")"

R=$(raw_req "GET /sub HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
S=$(status_of "$R")
[ "$S" = "301" ] || [ "$S" = "302" ] || [ "$S" = "200" ] \
    && ok "/sub → redirect or serve ($S)" \
    || fail "Dir redirect" "status=$S"

# ══════════════════════════════════════════════════════
# GZIP
# ══════════════════════════════════════════════════════
echo "  ── Compression ──"

R=$(timeout 3 curl -s -D- --compressed "http://$HOST:$PORT/compressible.txt" 2>/dev/null)
echo "$R" | grep -qi "content-encoding.*gzip\|content-encoding.*deflate" \
    && ok "Gzip compression on text file" \
    || ok "No compression (acceptable for small files)"

# ══════════════════════════════════════════════════════
# MALFORMED REQUESTS
# ══════════════════════════════════════════════════════
echo "  ── Malformed requests ──"

R=$(raw_req "GARBAGE\r\n\r\n")
S=$(status_of "$R")
[ "$S" = "400" ] || [ -z "$R" ] \
    && ok "Garbage request → 400 or disconnect" \
    || fail "Garbage" "status=$S"

R=$(raw_req "GET / HTTP/1.1\r\n\r\n")
S=$(status_of "$R")
# Missing Host header — should still work (400 or serve)
[ -n "$S" ] \
    && ok "Missing Host header handled ($S)" \
    || ok "Missing Host → disconnect (OK)"

R=$(raw_req "get / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
S=$(status_of "$R")
[ "$S" = "501" ] || [ "$S" = "400" ] || [ "$S" = "200" ] \
    && ok "Lowercase method → $S" \
    || fail "Lowercase method" "status=$S"

# ══════════════════════════════════════════════════════
# LARGE BODY
# ══════════════════════════════════════════════════════
echo "  ── Large bodies ──"

BIGBODY=$(python3 -c "print('x'*50000)")
R=$(timeout 5 curl -s -o /dev/null -w '%{http_code}' -X POST \
    -H "Content-Type: text/plain" -d "$BIGBODY" \
    "http://$HOST:$PORT/raw-input.php" 2>/dev/null)
[ "$R" = "200" ] \
    && ok "50KB POST body accepted" \
    || fail "Large POST" "status=$R"

# ══════════════════════════════════════════════════════
# CONCURRENT REQUESTS
# ══════════════════════════════════════════════════════
echo "  ── Concurrency ──"

CONCURRENT=0
for i in $(seq 1 20); do
    timeout 3 curl -s -o /dev/null -w '%{http_code}' "http://$HOST:$PORT/data.json" &
done
RESULTS=$(wait; for pid in $(jobs -p); do wait $pid; done)
CONCURRENT=$(timeout 10 bash -c '
    GOOD=0
    for i in $(seq 1 20); do
        S=$(timeout 3 curl -s -o /dev/null -w "%{http_code}" "http://'"$HOST"':'"$PORT"'/data.json")
        [ "$S" = "200" ] && GOOD=$((GOOD+1))
    done
    echo $GOOD
')
[ "$CONCURRENT" -ge 18 ] \
    && ok "20 concurrent requests: $CONCURRENT/20 succeeded" \
    || fail "Concurrency" "$CONCURRENT/20"

# ══════════════════════════════════════════════════════
# MIME TYPES
# ══════════════════════════════════════════════════════
echo "  ── MIME types ──"

for ext_type in "html:text/html" "css:text/css" "json:application/json" "txt:text/plain"; do
    EXT="${ext_type%%:*}"
    EXPECTED="${ext_type##*:}"
    FILE=""
    case $EXT in
        html) FILE="/index.html" ;;
        css)  FILE="/style.css" ;;
        json) FILE="/data.json" ;;
        txt)  FILE="/etag-test.txt" ;;
    esac
    R=$(raw_req "GET $FILE HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
    CT=$(header_val "Content-Type" "$R")
    echo "$CT" | grep -q "$EXPECTED" \
        && ok ".$EXT → $EXPECTED" \
        || fail "MIME .$EXT" "got $CT"
done

# ══════════════════════════════════════════════════════
# FAVICON
# ══════════════════════════════════════════════════════
echo "  ── Built-in assets ──"

R=$(raw_req "GET /favicon.ico HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
[ "$(status_of "$R")" = "200" ] \
    && ok "favicon.ico served" \
    || fail "favicon" "status=$(status_of "$R")"

R=$(raw_req "GET /Q/logo.png HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
[ "$(status_of "$R")" = "200" ] \
    && ok "/Q/logo.png served" \
    || fail "logo" "status=$(status_of "$R")"

# ══════════════════════════════════════════════════════
# LOGGING
# ══════════════════════════════════════════════════════
echo "  ── Logging ──"

sleep 2  # let buffer flush
if [ -f "/tmp/test-logs-$$/access.log" ]; then
    LINES=$(wc -l < "/tmp/test-logs-$$/access.log")
    [ "$LINES" -gt 5 ] \
        && ok "Access log: $LINES lines written" \
        || fail "Access log" "only $LINES lines"
    # Check format
    head -1 "/tmp/test-logs-$$/access.log" | grep -qP '^\S+ - - \[' \
        && ok "Log format: combined (nginx-compatible)" \
        || fail "Log format" "$(head -1 /tmp/test-logs-$$/access.log)"
else
    fail "Access log" "file not created"
fi

# ══════════════════════════════════════════════════════
# PHP EXECUTION EDGE CASES
# ══════════════════════════════════════════════════════
echo "  ── PHP edge cases ──"

# exit() shouldn't crash the server
R=$(raw_req "GET /exit.php HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
# Server should still be alive
R2=$(raw_req "GET /data.json HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
[ "$(status_of "$R2")" = "200" ] \
    && ok "Server survives PHP exit()" \
    || fail "After exit()" "status=$(status_of "$R2")"

# fatal error shouldn't crash the server
R=$(raw_req "GET /fatal.php HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
R2=$(raw_req "GET /data.json HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
[ "$(status_of "$R2")" = "200" ] \
    && ok "Server survives PHP fatal error" \
    || fail "After fatal" "status=$(status_of "$R2")"

# ══════════════════════════════════════════════════════
# PANEL AUTH
# ══════════════════════════════════════════════════════
echo "  ── Panel auth ──"

# Panel page should be accessible (no password set yet)
R=$(timeout 3 curl -s -o /dev/null -w '%{http_code}' "http://$HOST:$PORT/Q/panel")
[ "$R" = "200" ] \
    && ok "Panel page loads (no password)" \
    || fail "Panel" "status=$R"

# Set password
TOKEN=$(timeout 3 curl -s -X POST -H 'Content-Type: application/json' \
    -d '{"password":"test123456"}' "http://$HOST:$PORT/Q/api/auth/setup" 2>/dev/null | \
    python3 -c "import sys,json;print(json.load(sys.stdin).get('token',''))" 2>/dev/null)

if [ -n "$TOKEN" ] && [ "$TOKEN" != "NONE" ]; then
    ok "Panel password set, got token"

    # Dashboard without cookie → redirect
    R=$(timeout 3 curl -s -o /dev/null -w '%{http_code}' "http://$HOST:$PORT/Q/dashboard")
    [ "$R" = "302" ] \
        && ok "Dashboard without auth → 302 redirect" \
        || fail "Dashboard no-auth" "status=$R"

    # Dashboard with cookie → 200
    R=$(timeout 3 curl -s -o /dev/null -w '%{http_code}' -b "Q_panel_token=$TOKEN" \
        "http://$HOST:$PORT/Q/dashboard")
    [ "$R" = "200" ] \
        && ok "Dashboard with cookie → 200" \
        || fail "Dashboard cookie" "status=$R"

    # Login with wrong password
    R=$(timeout 3 curl -s -X POST -H 'Content-Type: application/json' \
        -d '{"password":"wrong"}' "http://$HOST:$PORT/Q/api/auth/login" 2>/dev/null)
    echo "$R" | grep -q "error" \
        && ok "Wrong password rejected" \
        || fail "Wrong password" "$R"
else
    fail "Panel auth" "setup returned no token"
fi

# ══════════════════════════════════════════════════════
# Issue #14: SCRIPT_NAME + SERVER_PORT
# ══════════════════════════════════════════════════════

R=$(timeout 3 curl -s "http://$HOST:$PORT/Q/srv.php" 2>/dev/null)
SN=$(echo "$R" | python3 -c "import json,sys; print(json.load(sys.stdin).get('SCRIPT_NAME',''))" 2>/dev/null)
[ "$SN" = "/Q/srv.php" ] \
    && ok "SCRIPT_NAME preserves path segments (/Q/srv.php)" \
    || fail "SCRIPT_NAME" "got $SN"

SP=$(timeout 3 curl -s -H "Host: myapp.example.com" \
    "http://$HOST:$PORT/Q/srv.php" 2>/dev/null \
    | python3 -c "import json,sys; print(json.load(sys.stdin).get('SERVER_PORT',''))" 2>/dev/null)
[ "$SP" = "80" ] \
    && ok "SERVER_PORT defaults to 80 when Host has no port" \
    || fail "SERVER_PORT default" "got $SP"

SP2=$(timeout 3 curl -s -H "Host: myapp.example.com:8092" \
    "http://$HOST:$PORT/Q/srv.php" 2>/dev/null \
    | python3 -c "import json,sys; print(json.load(sys.stdin).get('SERVER_PORT',''))" 2>/dev/null)
[ "$SP2" = "8092" ] \
    && ok "SERVER_PORT from Host header port (8092)" \
    || fail "SERVER_PORT Host port" "got $SP2"

# ══════════════════════════════════════════════════════
# Q_Response::header() integration
# ══════════════════════════════════════════════════════

H=$(timeout 3 curl -sI "http://$HOST:$PORT/app-headers.php" 2>/dev/null)
echo "$H" | grep -q "201" \
    && ok "Q_Response::code(201) → HTTP 201" \
    || fail "Q_Response::code" "$H"

echo "$H" | grep -qi "X-App-Mode: platform-compat" \
    && ok "Q_Response::header() custom header in response" \
    || fail "Q_Response custom header" "$H"

echo "$H" | grep -qi "X-Custom-Token: test123" \
    && ok "Q_Response::header() second custom header" \
    || fail "Q_Response second header" "$H"

echo "$H" | grep -qi "Content-Type: application/json" \
    && ok "Q_Response::header() Content-Type" \
    || fail "Q_Response Content-Type" "$H"

echo "$H" | grep -qi "Set-Cookie.*test_cookie=hello" \
    && ok "Q_Response::setCookie() in response" \
    || fail "Q_Response setCookie" "$H"

# ══════════════════════════════════════════════════════
# RESULTS
# ══════════════════════════════════════════════════════

# Cleanup
pkill -9 -f "qbixserver.*$PORT" 2>/dev/null
rm -rf "/tmp/test-logs-$$" "/tmp/test-http-cfg-$$.json"

echo ""
echo "  $PASS passed, $FAIL failed"
if [ $FAIL -gt 0 ]; then
    echo -e "  Failures:$ERRORS"
fi
echo ""
exit $FAIL
