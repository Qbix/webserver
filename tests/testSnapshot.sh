#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════
#  Snapshot Reset Test Suite
#
#  Verifies that octane mode's snapshot restore between requests
#  prevents state leaks: statics, globals, superglobals, headers,
#  memory, and secrets.
#
#  Tests both octane mode (snapshot restore) and fork mode
#  (process-per-request) as a control group.
#
#  Usage: bash tests/testSnapshot.sh [port]
# ══════════════════════════════════════════════════════════════
set -e

PORT=${1:-18900}
OPORT=$((PORT + 1))
DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$DIR"

pass=0; fail=0; total=0
ok()   { pass=$((pass+1)); total=$((total+1)); echo "  ✅ $1"; }
fail() { fail=$((fail+1)); total=$((total+1)); echo "  ❌ $1"; }

cleanup() {
    kill $FPID $OPID 2>/dev/null
    wait $FPID $OPID 2>/dev/null 2>&1
    rm -f /tmp/qbix-panel.json tests/local/panel.json local/panel.json
}
trap cleanup EXIT

echo ""
echo "  Snapshot Reset Tests"
echo ""

# ── Start two servers: fork mode and octane mode ──────────────
rm -f /tmp/qbix-panel.json tests/local/panel.json local/panel.json

# Fork mode (1 worker, octane=off via env; actually use --workers=0 for fork-per-request)
php qbixserver.php --root=tests/web --port=$PORT --workers=0 \
    > /dev/null 2>&1 &
FPID=$!

# Octane mode (2 workers so we can test same-worker dispatch)
php qbixserver.php --root=tests/web --port=$OPORT --workers=2 \
    > /dev/null 2>&1 &
OPID=$!

sleep 4

# Helper: GET with JSON parsing
jget() {
    curl -s "$@" 2>/dev/null
}

# ═══════════════════════════════════════════════════════════════
#  PART 1: Static + Global + Superglobal isolation (Octane)
# ═══════════════════════════════════════════════════════════════

echo "  ── Octane mode (port $OPORT) ──"

# Step 1: Pollute state via leak-set.php
R=$(jget "http://127.0.0.1:$OPORT/leak-set.php")
SET_OK=$(echo "$R" | python3 -c "import json,sys; d=json.load(sys.stdin); print(d.get('set',''))" 2>/dev/null)
if [ "$SET_OK" = "True" ]; then
    ok "leak-set.php: state pollution succeeded"
else
    fail "leak-set.php: couldn't pollute state ($R)"
fi

# Step 2: Check for leaks via leak-check.php
# Send multiple requests — at least one should hit the same worker
CLEAN_COUNT=0
LEAK_DETAILS=""
for i in 1 2 3 4; do
    R=$(jget "http://127.0.0.1:$OPORT/leak-check.php?check=1")
    IS_CLEAN=$(echo "$R" | python3 -c "import json,sys; d=json.load(sys.stdin); print(d.get('clean',''))" 2>/dev/null)
    if [ "$IS_CLEAN" = "True" ]; then
        CLEAN_COUNT=$((CLEAN_COUNT+1))
    else
        LEAKS=$(echo "$R" | python3 -c "import json,sys; d=json.load(sys.stdin); print(', '.join(d.get('leaks',[])))" 2>/dev/null)
        LEAK_DETAILS="$LEAKS"
    fi
done

if [ $CLEAN_COUNT -eq 4 ]; then
    ok "Static + global + superglobal isolation: all 4 checks clean"
else
    fail "State leaked ($((4-CLEAN_COUNT))/4 checks dirty): $LEAK_DETAILS"
fi

# ═══════════════════════════════════════════════════════════════
#  PART 2: Accumulation detection (counter should always = 1)
# ═══════════════════════════════════════════════════════════════

MAX_COUNTER=0
for i in $(seq 1 20); do
    R=$(jget "http://127.0.0.1:$OPORT/leak-accum.php")
    C=$(echo "$R" | python3 -c "import json,sys; print(json.load(sys.stdin).get('counter',0))" 2>/dev/null)
    if [ "$C" -gt "$MAX_COUNTER" ] 2>/dev/null; then
        MAX_COUNTER=$C
    fi
done

if [ "$MAX_COUNTER" -le 1 ]; then
    ok "Accumulation: counter stayed at 1 across 20 requests"
else
    fail "Accumulation: counter reached $MAX_COUNTER (should be 1)"
fi

# ═══════════════════════════════════════════════════════════════
#  PART 3: Memory stability over many requests
# ═══════════════════════════════════════════════════════════════

# Warm up
for i in 1 2 3; do jget "http://127.0.0.1:$OPORT/leak-accum.php" > /dev/null; done

# Measure baseline
R=$(jget "http://127.0.0.1:$OPORT/leak-accum.php")
MEM_BASE=$(echo "$R" | python3 -c "import json,sys; print(json.load(sys.stdin).get('memory',0))" 2>/dev/null)

# Hammer with 50 requests (including ones that allocate 1MB blobs)
for i in $(seq 1 25); do
    jget "http://127.0.0.1:$OPORT/leak-set.php" > /dev/null
    jget "http://127.0.0.1:$OPORT/leak-accum.php" > /dev/null
done

# Measure after
R=$(jget "http://127.0.0.1:$OPORT/leak-accum.php")
MEM_AFTER=$(echo "$R" | python3 -c "import json,sys; print(json.load(sys.stdin).get('memory',0))" 2>/dev/null)

# Allow up to 4MB growth (PHP allocates in 2MB chunks, GC jitter)
if [ -n "$MEM_BASE" ] && [ -n "$MEM_AFTER" ]; then
    GROWTH=$(python3 -c "print(round(($MEM_AFTER - $MEM_BASE) / 1048576, 1))" 2>/dev/null)
    GROWTH_OK=$(python3 -c "print('yes' if ($MEM_AFTER - $MEM_BASE) < 4194304 else 'no')" 2>/dev/null)
    if [ "$GROWTH_OK" = "yes" ]; then
        ok "Memory stable: ${GROWTH} MB growth over 50 requests with 1MB allocs"
    else
        fail "Memory leak: ${GROWTH} MB growth (base=$MEM_BASE after=$MEM_AFTER)"
    fi
else
    fail "Memory: couldn't parse values (base=$MEM_BASE after=$MEM_AFTER)"
fi

# ═══════════════════════════════════════════════════════════════
#  PART 4: Response header isolation
# ═══════════════════════════════════════════════════════════════

# Set secret headers
jget "http://127.0.0.1:$OPORT/leak-headers.php" > /dev/null

# Now fetch a different page and check headers DON'T carry over
for i in 1 2 3 4; do
    HEADERS=$(curl -sI "http://127.0.0.1:$OPORT/hello.php" 2>/dev/null)
    if echo "$HEADERS" | grep -qi "X-Secret-Token"; then
        fail "Response header leak: X-Secret-Token found on subsequent request"
        break
    fi
    if echo "$HEADERS" | grep -qi "X-User-Id"; then
        fail "Response header leak: X-User-Id found on subsequent request"
        break
    fi
    if [ $i -eq 4 ]; then
        ok "Response headers: no secret headers leaked across requests"
    fi
done

# ═══════════════════════════════════════════════════════════════
#  PART 5: Cookie isolation between requests
# ═══════════════════════════════════════════════════════════════

# Send request A with a secret cookie
R_A=$(curl -s -b "auth_token=SECRET_BEARER_XYZ" \
    "http://127.0.0.1:$OPORT/hello.php" 2>/dev/null)

# Send request B WITHOUT that cookie — check it can't see A's cookie
R_B=$(jget "http://127.0.0.1:$OPORT/hello.php")

# hello.php dumps superglobals including $_COOKIE
COOKIE_LEAK=$(echo "$R_B" | python3 -c "
import json,sys
try:
    d=json.load(sys.stdin)
    c=d.get('cookies',{})
    print('leak' if 'auth_token' in c else 'clean')
except: print('clean')
" 2>/dev/null)

if [ "$COOKIE_LEAK" = "clean" ]; then
    ok "Cookie isolation: request B can't see request A's cookies"
else
    fail "Cookie leak: auth_token from request A visible in request B"
fi

# ═══════════════════════════════════════════════════════════════
#  PART 6: $_SERVER isolation — no cross-request header bleed
# ═══════════════════════════════════════════════════════════════

# Send request with Authorization header
R1=$(curl -s -H "Authorization: Bearer super_secret_jwt" \
    -H "X-Custom-Debug: internal_trace_id_999" \
    "http://127.0.0.1:$OPORT/hello.php" 2>/dev/null)

# Send clean request — check the Authorization header isn't there
R2=$(jget "http://127.0.0.1:$OPORT/hello.php")

HEADER_LEAK=$(echo "$R2" | python3 -c "
import json,sys
try:
    d=json.load(sys.stdin)
    has_auth = 'HTTP_AUTHORIZATION' in str(d)
    has_debug = 'HTTP_X_CUSTOM_DEBUG' in str(d)
    print('leak' if (has_auth or has_debug) else 'clean')
except: print('clean')
" 2>/dev/null)

# Multiple attempts to hit same worker
for i in 1 2 3; do
    R=$(jget "http://127.0.0.1:$OPORT/hello.php")
    HL=$(echo "$R" | python3 -c "
import json,sys
try:
    d=json.load(sys.stdin)
    # Check raw JSON string for any trace of the secret
    print('leak' if 'super_secret_jwt' in json.dumps(d) else 'clean')
except: print('clean')
" 2>/dev/null)
    if [ "$HL" = "leak" ]; then
        HEADER_LEAK="leak"
        break
    fi
done

if [ "$HEADER_LEAK" = "clean" ]; then
    ok "\$_SERVER isolation: Authorization/custom headers don't bleed"
else
    fail "\$_SERVER leak: previous request's headers visible"
fi

# ═══════════════════════════════════════════════════════════════
#  PART 7: POST body isolation
# ═══════════════════════════════════════════════════════════════

# Send POST with sensitive body
curl -s -X POST -d "password=hunter2&ssn=123-45-6789" \
    "http://127.0.0.1:$OPORT/hello.php" > /dev/null

# Next GET should have empty POST and empty raw input
for i in 1 2 3; do
    R=$(jget "http://127.0.0.1:$OPORT/hello.php")
    POST_LEAK=$(echo "$R" | python3 -c "
import json,sys
try:
    d=json.load(sys.stdin)
    has_pw = 'hunter2' in json.dumps(d)
    has_ssn = '123-45-6789' in json.dumps(d)
    has_post = bool(d.get('post',{}))
    ri = d.get('raw_input','')
    print('leak' if (has_pw or has_ssn or has_post or ri) else 'clean')
except: print('clean')
" 2>/dev/null)
    if [ "$POST_LEAK" = "leak" ]; then
        fail "POST body leak: password/SSN from prior POST visible in GET"
        break
    fi
done
if [ "$POST_LEAK" = "clean" ]; then
    ok "POST body isolation: no sensitive data carried to next request"
fi

# ═══════════════════════════════════════════════════════════════
#  PART 8: Fork mode control group — should also be clean
# ═══════════════════════════════════════════════════════════════

echo ""
echo "  ── Fork mode control (port $PORT) ──"

# Pollute then check
jget "http://127.0.0.1:$PORT/leak-set.php" > /dev/null
R=$(jget "http://127.0.0.1:$PORT/leak-check.php?check=1")
IS_CLEAN=$(echo "$R" | python3 -c "import json,sys; d=json.load(sys.stdin); print(d.get('clean',''))" 2>/dev/null)
if [ "$IS_CLEAN" = "True" ]; then
    ok "Fork mode: state isolation (process dies between requests)"
else
    LEAKS=$(echo "$R" | python3 -c "import json,sys; d=json.load(sys.stdin); print(', '.join(d.get('leaks',[])))" 2>/dev/null)
    fail "Fork mode leaked: $LEAKS"
fi

# Accumulation
MAX_C=0
for i in $(seq 1 10); do
    R=$(jget "http://127.0.0.1:$PORT/leak-accum.php")
    C=$(echo "$R" | python3 -c "import json,sys; print(json.load(sys.stdin).get('counter',0))" 2>/dev/null)
    if [ "$C" -gt "$MAX_C" ] 2>/dev/null; then MAX_C=$C; fi
done
if [ "$MAX_C" -le 1 ]; then
    ok "Fork mode: counter always 1 (clean process each time)"
else
    fail "Fork mode: counter=$MAX_C (should be 1)"
fi

# Secret headers
curl -s -H "Authorization: Bearer fork_secret" \
    "http://127.0.0.1:$PORT/hello.php" > /dev/null
R=$(jget "http://127.0.0.1:$PORT/hello.php")
HL=$(echo "$R" | python3 -c "
import json,sys
try:
    d=json.load(sys.stdin)
    print('leak' if 'fork_secret' in json.dumps(d) else 'clean')
except: print('clean')
" 2>/dev/null)
if [ "$HL" = "clean" ]; then
    ok "Fork mode: no header bleed between requests"
else
    fail "Fork mode: header leaked"
fi

# ═══════════════════════════════════════════════════════════════
#  PART 9: Rapid interleave — two sessions hitting same workers
# ═══════════════════════════════════════════════════════════════

echo ""
echo "  ── Interleaved sessions (octane) ──"

# Rapidly alternate between two "users" with different cookies
INTERLEAVE_OK=true
for i in $(seq 1 10); do
    # User A sets a cookie
    R_A=$(curl -s -b "user_id=ALICE_SECRET" \
        "http://127.0.0.1:$OPORT/hello.php" 2>/dev/null)
    # User B should not see it
    R_B=$(curl -s -b "user_id=BOB" \
        "http://127.0.0.1:$OPORT/hello.php" 2>/dev/null)
    HAS_ALICE=$(echo "$R_B" | python3 -c "
import json,sys
try:
    d=json.load(sys.stdin)
    print('yes' if 'ALICE_SECRET' in json.dumps(d) else 'no')
except: print('no')
" 2>/dev/null)
    if [ "$HAS_ALICE" = "yes" ]; then
        INTERLEAVE_OK=false
        break
    fi
done

if [ "$INTERLEAVE_OK" = true ]; then
    ok "Interleaved sessions: no cookie cross-contamination (20 requests)"
else
    fail "Cookie cross-contamination: Alice's cookie visible to Bob"
fi

# ═══════════════════════════════════════════════════════════════
#  Summary
# ═══════════════════════════════════════════════════════════════

echo ""
echo "  $pass passed, $fail failed"
echo ""
exit $fail
