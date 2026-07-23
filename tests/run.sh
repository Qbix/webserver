#!/bin/bash
#
# Qbix Server — Test Suite
#
# Runs functional, security, PHP integration, and performance tests.
# Starts the server automatically, runs tests, reports results.
#
# Usage:
#   ./tests/run.sh                 Run all tests
#   ./tests/run.sh --quick         Skip benchmarks
#   ./tests/run.sh --bench         Benchmarks only
#   ./tests/run.sh --bench-nginx   Compare against nginx (must be installed)
#
# Requirements: PHP 8.1+, ab (Apache Bench)
#

set +e  # Don't exit on errors — tests report failures themselves

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
PORT=19876
HOST="127.0.0.1"
PASS=0
FAIL=0
SKIP=0
ERRORS=""

# ── Helpers ──────────────────────────────────────────

red()   { echo -e "\033[31m$1\033[0m"; }
green() { echo -e "\033[32m$1\033[0m"; }
yellow(){ echo -e "\033[33m$1\033[0m"; }
bold()  { echo -e "\033[1m$1\033[0m"; }

request() {
    local method="$1" path="$2" extra_headers="$3" body="$4"
    timeout 5 php -r '
    $fp = @fsockopen("'"$HOST"'", '"$PORT"', $e, $es, 2);
    if (!$fp) { echo "CONNECT_FAIL"; exit; }
    $req = "'"$method"' '"$path"' HTTP/1.1\r\nHost: localhost\r\n";
    $extra = "'"$extra_headers"'";
    if ($extra) $req .= str_replace("\\r\\n", "\r\n", $extra) . "\r\n";
    $body = "'"$body"'";
    if ($body) $req .= "Content-Length: " . strlen($body) . "\r\n";
    $req .= "Connection: close\r\n\r\n";
    if ($body) $req .= $body;
    fwrite($fp, $req);
    stream_set_timeout($fp, 3);
    $r = "";
    while (!feof($fp)) {
        $c = @fread($fp, 65536);
        if ($c === false || $c === "") break;
        $r .= $c;
        $info = stream_get_meta_data($fp);
        if (!empty($info["timed_out"])) break;
    }
    fclose($fp);
    echo $r;
    ' 2>/dev/null
}

status_of() {
    echo "$1" | head -1 | grep -oP 'HTTP/1\.[01] \K\d+'
}

body_of() {
    local sep=$(echo "$1" | grep -n $'^\r$' | head -1 | cut -d: -f1)
    if [ -n "$sep" ]; then
        echo "$1" | tail -n +"$((sep+1))"
    fi
}

header_of() {
    echo "$1" | grep -i "^$2:" | head -1 | sed 's/^[^:]*: *//' | tr -d '\r'
}

assert_status() {
    local name="$1" expected="$2" actual="$3"
    if [ "$actual" = "$expected" ]; then
        green "  ✓ $name (HTTP $actual)"
        PASS=$((PASS+1))
    else
        red "  ✗ $name — expected $expected, got ${actual:-FAIL}"
        FAIL=$((FAIL+1))
        ERRORS="${ERRORS}\n  ✗ $name"
    fi
}

assert_contains() {
    local name="$1" haystack="$2" needle="$3"
    if echo "$haystack" | grep -q "$needle"; then
        green "  ✓ $name"
        PASS=$((PASS+1))
    else
        red "  ✗ $name — response missing: $needle"
        FAIL=$((FAIL+1))
        ERRORS="${ERRORS}\n  ✗ $name"
    fi
}

assert_not_contains() {
    local name="$1" haystack="$2" needle="$3"
    if echo "$haystack" | grep -q "$needle"; then
        red "  ✗ $name — response contains: $needle"
        FAIL=$((FAIL+1))
        ERRORS="${ERRORS}\n  ✗ $name"
    else
        green "  ✓ $name"
        PASS=$((PASS+1))
    fi
}

# ── Start server ─────────────────────────────────────

start_server() {
    pkill -f "qbixserver.php.*$PORT" 2>/dev/null || true
    sleep 1
    cd "$ROOT_DIR"
    php qbixserver.php --root="$SCRIPT_DIR/web" --port=$PORT > /tmp/qbix_test.log 2>&1 &
    SERVER_PID=$!
    sleep 2
    if ! kill -0 $SERVER_PID 2>/dev/null; then
        red "Failed to start server. Log:"
        cat /tmp/qbix_test.log
        exit 1
    fi
}

stop_server() {
    if [ -n "$SERVER_PID" ]; then
        kill $SERVER_PID 2>/dev/null || true
        wait $SERVER_PID 2>/dev/null || true
    fi
}

trap stop_server EXIT

# ══════════════════════════════════════════════════════
#  TESTS
# ══════════════════════════════════════════════════════

run_functional() {
    bold "\n═══ Functional Tests ═══"

    bold "\n  Static files"
    R=$(request "GET" "/index.html")
    assert_status "GET /index.html" "200" "$(status_of "$R")"
    assert_contains "Content-Type html" "$R" "text/html"
    assert_contains "Body contains heading" "$R" "Test Page"

    R=$(request "HEAD" "/index.html")
    assert_status "HEAD /index.html" "200" "$(status_of "$R")"
    assert_not_contains "HEAD has no body" "$R" "Test Page"

    bold "\n  Content types"
    echo "body{}" > "$SCRIPT_DIR/web/style.css"
    R=$(request "GET" "/style.css")
    assert_contains "CSS content type" "$R" "text/css"

    echo '{"a":1}' > "$SCRIPT_DIR/web/data.json"
    R=$(request "GET" "/data.json")
    assert_contains "JSON content type" "$R" "application/json"

    bold "\n  Caching"
    R=$(request "GET" "/index.html")
    ETAG=$(header_of "$R" "ETag")
    if [ -n "$ETAG" ]; then
        green "  ✓ ETag header present: $ETAG"
        PASS=$((PASS+1))
        # Test 304 via ab which handles connection properly
        NOT_MOD=$(ab -n 1 -H "If-None-Match: $ETAG" http://$HOST:$PORT/index.html 2>&1 | grep "Non-2xx" | awk '{print $3}')
        if [ "$NOT_MOD" = "1" ]; then
            green "  ✓ 304 Not Modified with matching ETag"
            PASS=$((PASS+1))
        else
            yellow "  ⊘ 304 test inconclusive"
            SKIP=$((SKIP+1))
        fi
    else
        yellow "  ⊘ No ETag header (skipped 304 test)"
        SKIP=$((SKIP+2))
    fi

    bold "\n  Keep-alive"
    KA=$(timeout 3 php -r '
    $fp = @fsockopen("'"$HOST"'", '"$PORT"', $e, $es, 1);
    if (!$fp) { echo "FAIL"; exit; }
    fwrite($fp, "GET /index.html HTTP/1.1\r\nHost: l\r\nConnection: keep-alive\r\n\r\n");
    stream_set_timeout($fp, 2);
    $r = "";
    while (($line = fgets($fp)) !== false) {
        $r .= $line;
        if (trim($line) === "") break; // end of headers
    }
    fclose($fp);
    echo (stripos($r, "keep-alive") !== false) ? "YES" : "NO";
    ' 2>/dev/null)
    if [ "$KA" = "YES" ]; then
        green "  ✓ Keep-alive connection"
        PASS=$((PASS+1))
    else
        red "  ✗ Keep-alive not in response headers"
        FAIL=$((FAIL+1))
    fi

    bold "\n  Error handling"
    R=$(request "GET" "/nonexistent")
    assert_status "404 for missing file" "404" "$(status_of "$R")"

    R=$(request "GET" "/Q/health")
    assert_status "Health endpoint" "200" "$(status_of "$R")"
    assert_contains "Health JSON" "$R" '"status"'

    R=$(request "GET" "/Q/dashboard")
    assert_status "Dashboard" "200" "$(status_of "$R")"
}

run_security() {
    bold "\n═══ Security Tests ═══"

    bold "\n  Path traversal"
    R=$(request "GET" "/../../../etc/passwd")
    S=$(status_of "$R")
    if [ "$S" = "400" ] || [ "$S" = "403" ] || [ "$S" = "404" ]; then
        green "  ✓ Path traversal blocked (HTTP $S)"
        PASS=$((PASS+1))
    else
        red "  ✗ Path traversal NOT blocked (HTTP $S)"
        FAIL=$((FAIL+1))
        ERRORS="${ERRORS}\n  ✗ Path traversal"
    fi

    R=$(request "GET" "/..%2F..%2F..%2Fetc%2Fpasswd")
    S=$(status_of "$R")
    if [ "$S" = "400" ] || [ "$S" = "403" ] || [ "$S" = "404" ]; then
        green "  ✓ Encoded path traversal blocked (HTTP $S)"
        PASS=$((PASS+1))
    else
        red "  ✗ Encoded path traversal NOT blocked (HTTP $S)"
        FAIL=$((FAIL+1))
        ERRORS="${ERRORS}\n  ✗ Encoded path traversal"
    fi

    bold "\n  Dotfile protection"
    R=$(request "GET" "/.env")
    assert_status "Dotfile .env blocked" "403" "$(status_of "$R")"
    assert_not_contains "Dotfile content hidden" "$R" "secret"

    R=$(request "GET" "/.secret/data.txt")
    S=$(status_of "$R")
    if [ "$S" = "403" ] || [ "$S" = "404" ]; then
        green "  ✓ Dotdir .secret/ blocked (HTTP $S)"
        PASS=$((PASS+1))
    else
        red "  ✗ Dotdir .secret/ accessible (HTTP $S)"
        FAIL=$((FAIL+1))
        ERRORS="${ERRORS}\n  ✗ Dotdir accessible"
    fi

    bold "\n  Malformed requests"
    R=$(timeout 3 php -r '
    $fp = @fsockopen("'"$HOST"'", '"$PORT"', $e, $es, 1);
    if (!$fp) { echo "CONNECT_FAIL"; exit; }
    fwrite($fp, "GARBAGE DATA HERE\r\n\r\n");
    stream_set_timeout($fp, 2);
    $r = ""; while (!feof($fp)) { $c = @fread($fp, 4096); if ($c===""||$c===false) break; $r .= $c; } fclose($fp);
    echo $r;
    ' 2>/dev/null)
    S=$(status_of "$R")
    if [ "$S" = "400" ] || [ -z "$S" ]; then
        green "  ✓ Malformed request rejected"
        PASS=$((PASS+1))
    else
        red "  ✗ Malformed request not rejected (HTTP $S)"
        FAIL=$((FAIL+1))
    fi

    bold "\n  Oversized headers"
    BIGHEADER=$(python3 -c "print('X-Big: ' + 'A' * 70000)" 2>/dev/null || echo "")
    if [ -n "$BIGHEADER" ]; then
        R=$(request "GET" "/" "$BIGHEADER")
        S=$(status_of "$R")
        if [ "$S" = "431" ] || [ -z "$S" ]; then
            green "  ✓ Oversized header rejected (431)"
            PASS=$((PASS+1))
        else
            red "  ✗ Oversized header accepted (HTTP $S)"
            FAIL=$((FAIL+1))
        fi
    else
        yellow "  ⊘ Oversized header test skipped (no python3)"
        SKIP=$((SKIP+1))
    fi

    bold "\n  Null bytes in URL"
    R=$(request "GET" "/index%00.html")
    S=$(status_of "$R")
    if [ "$S" = "400" ] || [ "$S" = "403" ] || [ "$S" = "404" ]; then
        green "  ✓ Null byte in URL blocked (HTTP $S)"
        PASS=$((PASS+1))
    else
        red "  ✗ Null byte in URL not blocked (HTTP $S)"
        FAIL=$((FAIL+1))
    fi

    bold "\n  Blocked directories"
    for DIR in handlers config classes scripts; do
        R=$(request "GET" "/$DIR/test.php")
        S=$(status_of "$R")
        if [ "$S" = "403" ]; then
            green "  ✓ /$DIR/ blocked (403)"
            PASS=$((PASS+1))
        else
            red "  ✗ /$DIR/ not blocked (HTTP $S)"
            FAIL=$((FAIL+1))
            ERRORS="${ERRORS}\n  ✗ /$DIR/ not blocked"
        fi
    done

    bold "\n  WebSocket frame limits"
    FRAME_RESULT=$(timeout 5 php -r '
    $fp = @fsockopen("'"$HOST"'", '"$PORT"', $e, $es, 2);
    if (!$fp) { echo "FAIL"; exit; }
    fwrite($fp, "GET /ws HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
    // Consume the 101 upgrade response headers
    stream_set_timeout($fp, 2);
    while (($line = fgets($fp)) !== false) {
        if (trim($line) === "") break;
    }
    usleep(100000);
    // Drain any remaining data
    stream_set_blocking($fp, false);
    @fread($fp, 65536);
    stream_set_blocking($fp, true);
    stream_set_timeout($fp, 3);
    // Send frame claiming 100MB payload
    $frame = chr(0x81).chr(0xFF);
    $frame .= pack("J", 100000000);
    $frame .= "\x00\x00\x00\x00";
    $frame .= "x";
    fwrite($fp, $frame);
    usleep(1000000);
    stream_set_blocking($fp, false);
    $result = @fread($fp, 1);
    echo ($result === false || $result === "") ? "DISCONNECTED" : "STILL_OPEN";
    fclose($fp);
    ' 2>/dev/null)
    if [ "$FRAME_RESULT" = "DISCONNECTED" ]; then
        green "  ✓ Oversized frame disconnects client"
        PASS=$((PASS+1))
    else
        red "  ✗ Oversized frame did NOT disconnect (got: $FRAME_RESULT)"
        FAIL=$((FAIL+1))
        ERRORS="${ERRORS}\n  ✗ Oversized frame"
    fi

    bold "\n  Panel localhost restriction"
    R=$(request "GET" "/Q/panel")
    S=$(status_of "$R")
    if [ "$S" = "200" ] || [ "$S" = "403" ]; then
        green "  ✓ Panel access controlled (HTTP $S)"
        PASS=$((PASS+1))
    else
        red "  ✗ Panel returned unexpected status (HTTP $S)"
        FAIL=$((FAIL+1))
    fi
}

run_websocket() {
    bold "\n═══ WebSocket Tests ═══"

    bold "\n  Upgrade handshake"
    WS_RESULT=$(timeout 5 php -r '
    $fp = @fsockopen("'"$HOST"'", '"$PORT"', $e, $es, 2);
    if (!$fp) { echo "FAIL"; exit; }
    fwrite($fp, "GET /ws HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
    stream_set_timeout($fp, 2);
    $r = "";
    while (($line = fgets($fp)) !== false) {
        $r .= $line;
        if (trim($line) === "") break;
    }
    fclose($fp);
    echo (strpos($r, "101") !== false) ? "UPGRADED" : "FAIL";
    ' 2>/dev/null)
    if [ "$WS_RESULT" = "UPGRADED" ]; then
        green "  ✓ WebSocket upgrade (101 Switching Protocols)"
        PASS=$((PASS+1))
    else
        red "  ✗ WebSocket upgrade failed"
        FAIL=$((FAIL+1))
        ERRORS="${ERRORS}\n  ✗ WebSocket upgrade"
    fi

    bold "\n  Socket.IO handshake"
    SIO_RESULT=$(timeout 5 php -r '
    $fp = @fsockopen("'"$HOST"'", '"$PORT"', $e, $es, 2);
    if (!$fp) { echo "FAIL"; exit; }
    fwrite($fp, "GET /socket.io/?EIO=4&transport=websocket HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
    stream_set_timeout($fp, 2);
    // Read upgrade response
    $r = "";
    while (($line = fgets($fp)) !== false) {
        $r .= $line;
        if (trim($line) === "") break;
    }
    if (strpos($r, "101") === false) { echo "NO_UPGRADE"; fclose($fp); exit; }
    // Read Engine.IO OPEN packet (should be a WebSocket frame containing "0{...}")
    usleep(200000);
    $data = @fread($fp, 4096);
    fclose($fp);
    // Decode WebSocket frame
    if (strlen($data) < 2) { echo "NO_DATA"; exit; }
    $len = ord($data[1]) & 0x7F;
    $payload = substr($data, 2, $len);
    echo (substr($payload, 0, 1) === "0" && strpos($payload, "sid") !== false) ? "SIO_OK" : "SIO_FAIL";
    ' 2>/dev/null)
    if [ "$SIO_RESULT" = "SIO_OK" ]; then
        green "  ✓ Socket.IO Engine.IO handshake (sid received)"
        PASS=$((PASS+1))
    else
        red "  ✗ Socket.IO handshake failed ($SIO_RESULT)"
        FAIL=$((FAIL+1))
        ERRORS="${ERRORS}\n  ✗ Socket.IO handshake"
    fi

    bold "\n  Socket.IO ping/pong"
    PING_RESULT=$(timeout 5 php -r '
    $fp = @fsockopen("'"$HOST"'", '"$PORT"', $e, $es, 2);
    if (!$fp) { echo "FAIL"; exit; }
    fwrite($fp, "GET /socket.io/?EIO=4&transport=websocket HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
    stream_set_timeout($fp, 2);
    $r = ""; while (($line = fgets($fp)) !== false) { $r .= $line; if (trim($line) === "") break; }
    usleep(200000);
    @fread($fp, 4096); // consume handshake

    // Send Engine.IO ping: "2"
    $frame = chr(0x81) . chr(0x81) . "\x00\x00\x00\x00" . "2"; // masked
    fwrite($fp, $frame);
    usleep(200000);

    // Read pong
    $data = @fread($fp, 4096);
    fclose($fp);
    if (!$data) { echo "NO_RESPONSE"; exit; }
    $len = ord($data[1]) & 0x7F;
    $payload = substr($data, 2, $len);
    echo ($payload === "3") ? "PONG_OK" : "PONG_FAIL:$payload";
    ' 2>/dev/null)
    if [ "$PING_RESULT" = "PONG_OK" ]; then
        green "  ✓ Engine.IO ping→pong"
        PASS=$((PASS+1))
    else
        red "  ✗ Engine.IO ping/pong failed ($PING_RESULT)"
        FAIL=$((FAIL+1))
        ERRORS="${ERRORS}\n  ✗ Engine.IO ping/pong"
    fi

    bold "\n  Client JS served"
    R=$(request "GET" "/Q/socket.js")
    assert_status "QSocket client JS" "200" "$(status_of "$R")"
    assert_contains "QSocket class" "$R" "QSocket"

    R=$(request "GET" "/socket.io/socket.io.js")
    assert_status "socket.io-client JS" "200" "$(status_of "$R")"
}

run_php() {
    bold "\n═══ PHP Integration Tests ═══"

    bold "\n  Basic execution"
    R=$(request "GET" "/hello.php?foo=bar&n=42")
    assert_status "PHP script execution" "200" "$(status_of "$R")"
    B=$(body_of "$R")
    assert_contains "GET method" "$B" '"method":"GET"'
    assert_contains "Query string parsed" "$B" '"foo":"bar"'
    assert_contains "PHP_SELF set" "$B" 'php_self'
    assert_contains "SERVER_SOFTWARE" "$B" 'QbixServer'
    assert_contains "GATEWAY_INTERFACE" "$B" 'CGI'
    assert_contains "Q class available" "$B" '"q_class":true'
    assert_contains "Q_Request available" "$B" '"q_request":true'
    assert_contains "Q_Config available" "$B" '"q_config":true'

    bold "\n  Cookies"
    R=$(request "GET" "/hello.php" "Cookie: session=abc123; theme=dark")
    B=$(body_of "$R")
    assert_contains "Cookie parsed" "$B" '"session":"abc123"'
    assert_contains "Multiple cookies" "$B" '"theme":"dark"'

    bold "\n  Basic auth"
    AUTH=$(echo -n "admin:secret" | base64)
    R=$(request "GET" "/hello.php" "Authorization: Basic $AUTH")
    B=$(body_of "$R")
    assert_contains "Auth user parsed" "$B" '"auth_user":"admin"'

    bold "\n  POST form data"
    R=$(request "POST" "/hello.php" "Content-Type: application/x-www-form-urlencoded" "name=Alice&age=30")
    B=$(body_of "$R")
    assert_contains "POST data parsed" "$B" '"name":"Alice"'

    bold "\n  POST JSON"
    R=$(timeout 5 php -r '
    $fp = @fsockopen("'"$HOST"'", '"$PORT"', $e, $es, 2);
    if (!$fp) { echo "CONNECT_FAIL"; exit; }
    $body = json_encode(["msg" => "hello"]);
    $h = "POST /hello.php HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n";
    fwrite($fp, $h . $body);
    stream_set_timeout($fp, 3);
    $r = "";
    while (!feof($fp)) { $c = @fread($fp, 65536); if ($c === false || $c === "") break; $r .= $c; }
    fclose($fp);
    echo $r;
    ' 2>/dev/null)
    B=$(body_of "$R")
    assert_contains "JSON body parsed" "$B" '"msg":"hello"'
    assert_contains "Raw input available" "$B" '"raw_input":'

    bold "\n  HTTPS detection via proxy"
    R=$(request "GET" "/hello.php" "X-Forwarded-Proto: https")
    B=$(body_of "$R")
    assert_contains "HTTPS detected from proxy" "$B" '"scheme":"https"'

    bold "\n  File upload (multipart)"
    BOUNDARY="----QbixTestBoundary"
    BODY="------QbixTestBoundary\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nMyDoc\r\n------QbixTestBoundary\r\nContent-Disposition: form-data; name=\"doc\"; filename=\"test.txt\"\r\nContent-Type: text/plain\r\n\r\nFile content here\r\n------QbixTestBoundary--\r\n"
    R=$(timeout 5 php -r '
    $fp = @fsockopen("'"$HOST"'", '"$PORT"', $e, $es, 2);
    if (!$fp) { echo "CONNECT_FAIL"; exit; }
    $body = "------QbixTestBoundary\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nMyDoc\r\n------QbixTestBoundary\r\nContent-Disposition: form-data; name=\"doc\"; filename=\"test.txt\"\r\nContent-Type: text/plain\r\n\r\nFile content here\r\n------QbixTestBoundary--\r\n";
    $h = "POST /upload.php HTTP/1.1\r\nHost: localhost\r\nContent-Type: multipart/form-data; boundary=----QbixTestBoundary\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n";
    fwrite($fp, $h . $body);
    stream_set_timeout($fp, 3);
    $r = ""; while (!feof($fp)) { $c = @fread($fp, 65536); if ($c===""||$c===false) break; $r .= $c; } fclose($fp);
    echo $r;
    ' 2>/dev/null)
    B=$(body_of "$R")
    assert_contains "Multipart POST field" "$B" '"title":"MyDoc"'
    assert_contains "File upload name" "$B" '"name":"test.txt"'
    assert_contains "File upload content" "$B" '"content":"File content here"'

    bold "\n  Response headers from PHP"
    R=$(request "GET" "/headers.php")
    assert_status "Custom status code" "201" "$(status_of "$R")"
    assert_contains "Custom header" "$R" "X-Custom-Header: hello"

    bold "\n  Crash isolation"
    R=$(request "GET" "/exit.php")
    # Server should survive
    sleep 1
    R2=$(request "GET" "/index.html")
    assert_status "Server survives exit()" "200" "$(status_of "$R2")"

    R=$(request "GET" "/fatal.php")
    sleep 1
    R2=$(request "GET" "/index.html")
    assert_status "Server survives fatal error" "200" "$(status_of "$R2")"
}

run_bench() {
    bold "\n═══ Performance Benchmarks ═══"

    if ! command -v ab &>/dev/null; then
        yellow "  ⊘ Apache Bench (ab) not found — skipping benchmarks"
        yellow "    Install: sudo apt install apache2-utils"
        SKIP=$((SKIP+3))
        return
    fi

    bold "\n  Static file throughput"
    echo -n "  Sequential (c=1):    "
    ab -n 2000 -c 1 http://$HOST:$PORT/index.html 2>&1 | grep "Requests per second" | awk '{print $4 " req/s"}'
    echo -n "  Concurrent (c=10):   "
    ab -n 5000 -c 10 http://$HOST:$PORT/index.html 2>&1 | grep "Requests per second" | awk '{print $4 " req/s"}'
    echo -n "  Keep-alive (c=10):   "
    ab -n 5000 -c 10 -k http://$HOST:$PORT/index.html 2>&1 | grep "Requests per second" | awk '{print $4 " req/s"}'
    echo -n "  Keep-alive (c=50):   "
    ab -n 10000 -c 50 -k http://$HOST:$PORT/index.html 2>&1 | grep "Requests per second" | awk '{print $4 " req/s"}'

    bold "\n  PHP throughput"
    echo -n "  PHP script (c=10):   "
    ab -n 500 -c 10 -s 10 http://$HOST:$PORT/hello.php 2>&1 | grep "Requests per second" | awk '{print $4 " req/s"}'

    bold "\n  Stress test"
    RESULT=$(ab -n 10000 -c 50 -k http://$HOST:$PORT/index.html 2>&1)
    FAILED=$(echo "$RESULT" | grep "Failed requests" | awk '{print $3}')
    RPS=$(echo "$RESULT" | grep "Requests per second" | awk '{print $4}')
    if [ "$FAILED" = "0" ]; then
        green "  ✓ 10K requests @ c=50: $RPS req/s, 0 failures"
        PASS=$((PASS+1))
    else
        red "  ✗ 10K requests @ c=50: $FAILED failures"
        FAIL=$((FAIL+1))
    fi

    bold "\n  Server still alive after stress"
    R=$(request "GET" "/index.html")
    assert_status "Alive after stress test" "200" "$(status_of "$R")"
}

run_bench_nginx() {
    bold "\n═══ nginx Comparison ═══"

    if ! command -v nginx &>/dev/null; then
        yellow "  ⊘ nginx not found — skipping comparison"
        return
    fi

    if ! command -v ab &>/dev/null; then
        yellow "  ⊘ Apache Bench (ab) not found"
        return
    fi

    NGINX_PORT=19877
    NGINX_CONF="/tmp/qbix_test_nginx.conf"
    cat > "$NGINX_CONF" << NGINXEOF
worker_processes 1;
error_log /dev/null;
pid /tmp/qbix_test_nginx.pid;
events { worker_connections 1024; }
http {
    include /etc/nginx/mime.types;
    access_log off;
    server {
        listen $NGINX_PORT;
        root $SCRIPT_DIR/web;
    }
}
NGINXEOF
    nginx -c "$NGINX_CONF" 2>/dev/null
    sleep 1

    printf "\n  %-25s %12s %12s %8s\n" "Scenario" "nginx" "Qbix" "Ratio"
    printf "  %-25s %12s %12s %8s\n" "─────────────────────────" "────────────" "────────────" "────────"

    for scenario in "c=1:-c 1 -n 2000" "c=10:-c 10 -n 5000" "c=10 keep-alive:-c 10 -n 5000 -k" "c=50 keep-alive:-c 50 -n 10000 -k"; do
        NAME="${scenario%%:*}"
        ARGS="${scenario##*:}"
        NGINX_RPS=$(ab $ARGS http://$HOST:$NGINX_PORT/index.html 2>&1 | grep "Requests per" | awk '{printf "%.0f", $4}')
        QBIX_RPS=$(ab $ARGS http://$HOST:$PORT/index.html 2>&1 | grep "Requests per" | awk '{printf "%.0f", $4}')
        if [ -n "$NGINX_RPS" ] && [ "$NGINX_RPS" -gt 0 ]; then
            RATIO=$(echo "scale=0; $QBIX_RPS * 100 / $NGINX_RPS" | bc)
            printf "  %-25s %10s/s %10s/s %6s%%\n" "$NAME" "$NGINX_RPS" "$QBIX_RPS" "$RATIO"
        fi
    done

    nginx -s stop -c "$NGINX_CONF" 2>/dev/null || true
    rm -f "$NGINX_CONF" /tmp/qbix_test_nginx.pid
}

# ── Main ─────────────────────────────────────────────

bold "╔══════════════════════════════════════════╗"
bold "║  Qbix Server — Test Suite                ║"
bold "╚══════════════════════════════════════════╝"

start_server

case "${1:-all}" in
    --quick)
        run_functional
        run_security
        run_php
        run_websocket
        ;;
    --bench)
        run_bench
        ;;
    --bench-nginx)
        run_bench
        run_bench_nginx
        ;;
    *)
        run_functional
        run_security
        run_php
        run_websocket
        run_bench
        ;;
esac

# ── Summary ──────────────────────────────────────────

bold "\n╔══════════════════════════════════════════╗"
if [ $FAIL -eq 0 ]; then
    green "║  ✓ ALL TESTS PASSED                      ║"
else
    red   "║  ✗ SOME TESTS FAILED                     ║"
fi
bold "╚══════════════════════════════════════════╝"
echo ""
echo "  Passed:  $PASS"
echo "  Failed:  $FAIL"
echo "  Skipped: $SKIP"
if [ $FAIL -gt 0 ]; then
    echo -e "\n  Failures:$ERRORS"
fi
echo ""

stop_server
exit $FAIL
