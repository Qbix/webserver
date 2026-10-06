#!/bin/bash
# Test: Two live Qbix Server instances perform handshake and exchange
# encrypted HTTP requests over TCP.
#
# Usage: bash tests/test_mesh_e2e.sh

set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PORT_A=17901
PORT_B=17902
P=0; F=0

pass() { P=$((P+1)); echo "  ✓ $1"; }
fail() { F=$((F+1)); echo "  ✗ $1"; }

cleanup() {
    pkill -f "port=$PORT_A" 2>/dev/null || true
    pkill -f "port=$PORT_B" 2>/dev/null || true
    rm -rf "$ROOT/local" /tmp/mesh_e2e_A /tmp/mesh_e2e_B
    wait 2>/dev/null
}
trap cleanup EXIT

# ── Start two servers ──

echo ""
echo "  ── Starting two servers ──"
echo ""

rm -rf "$ROOT/local" /tmp/mesh_e2e_A /tmp/mesh_e2e_B
mkdir -p /tmp/mesh_e2e_A/keys /tmp/mesh_e2e_B/keys

# Server A
cd "$ROOT"
rm -rf local/
QBIX_MESH_DIR=/tmp/mesh_e2e_A/keys setsid php qbixserver.php \
    --root=web --port=$PORT_A --workers=1 </dev/null >/dev/null 2>/dev/null &
sleep 5

# Server B — different mesh key dir = different identity  
QBIX_MESH_DIR=/tmp/mesh_e2e_B/keys setsid php qbixserver.php \
    --root=web --port=$PORT_B --workers=1 </dev/null >/dev/null 2>/dev/null &
sleep 5

# Verify both alive
HA=$(curl -s --max-time 3 http://127.0.0.1:$PORT_A/Q/health 2>/dev/null | head -c 9)
HB=$(curl -s --max-time 3 http://127.0.0.1:$PORT_B/Q/health 2>/dev/null | head -c 9)
[ "$HA" = '{"status"' ] && pass "Server A alive on $PORT_A" || fail "Server A not responding"
[ "$HB" = '{"status"' ] && pass "Server B alive on $PORT_B" || fail "Server B not responding"

# ── Test sync/identity endpoint ──

echo ""
echo "  ── Identity Endpoint ──"
echo ""

ID_A=$(curl -s --max-time 3 http://127.0.0.1:$PORT_A/Q/sync/identity 2>/dev/null)
ID_B=$(curl -s --max-time 3 http://127.0.0.1:$PORT_B/Q/sync/identity 2>/dev/null)

PEER_A=$(echo "$ID_A" | python3 -c "import sys,json;print(json.load(sys.stdin).get('peer_id',''))" 2>/dev/null)
PEER_B=$(echo "$ID_B" | python3 -c "import sys,json;print(json.load(sys.stdin).get('peer_id',''))" 2>/dev/null)

[ ${#PEER_A} -eq 64 ] && pass "Server A has 64-char peer_id: ${PEER_A:0:12}..." || fail "Server A peer_id invalid"
[ ${#PEER_B} -eq 64 ] && pass "Server B has 64-char peer_id: ${PEER_B:0:12}..." || fail "Server B peer_id invalid"
[ "$PEER_A" != "$PEER_B" ] && pass "Server A and B have different identities" || fail "Same identity!"

CERT_A=$(echo "$ID_A" | python3 -c "import sys,json;d=json.load(sys.stdin);print('yes' if 'BEGIN CERTIFICATE' in d.get('cert','') else 'no')" 2>/dev/null)
[ "$CERT_A" = "yes" ] && pass "Server A returns X.509 cert" || fail "Server A cert missing"

# ── Test handshake via transport/connect ──

echo ""
echo "  ── Handshake via transport/connect ──"
echo ""

# Auth with server A (need panel token for transport/connect)
TOKEN_A=$(curl -s --max-time 3 -X POST "http://127.0.0.1:$PORT_A/Q/api/auth/setup" \
    -H "Content-Type: application/json" -d '{"password":"testing123"}' \
    | python3 -c "import sys,json;print(json.load(sys.stdin)['token'])" 2>/dev/null)

[ -n "$TOKEN_A" ] && pass "Auth with Server A" || fail "Auth with Server A failed"

# Connect A to B
CONNECT=$(curl -s --max-time 10 -X POST "http://127.0.0.1:$PORT_A/Q/api/transport/connect" \
    -H "X-Panel-Token: $TOKEN_A" \
    -H "Content-Type: application/json" \
    -d "{\"address\":\"http://127.0.0.1:$PORT_B\"}" 2>/dev/null)

echo "  Connect response: $(echo "$CONNECT" | head -c 100)"

CONNECTED=$(echo "$CONNECT" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('connected',False))" 2>/dev/null)
ENCRYPTED=$(echo "$CONNECT" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('encrypted',False))" 2>/dev/null)
CONN_PEER=$(echo "$CONNECT" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('peer_id',''))" 2>/dev/null)

[ "$CONNECTED" = "True" ] && pass "Connection established" || fail "Connection failed"
[ "$ENCRYPTED" = "True" ] && pass "Encrypted session established" || fail "Session not encrypted"
[ "$CONN_PEER" = "$PEER_B" ] && pass "Connected to correct peer" || fail "Wrong peer_id"

# ── Verify peer appears in peer list ──

echo ""
echo "  ── Peer Registry ──"
echo ""

PEERS=$(curl -s --max-time 3 "http://127.0.0.1:$PORT_A/Q/api/transport/peers" \
    -H "X-Panel-Token: $TOKEN_A" 2>/dev/null)

PEER_COUNT=$(echo "$PEERS" | python3 -c "import sys,json;print(json.load(sys.stdin).get('count',0))" 2>/dev/null)
[ "$PEER_COUNT" = "1" ] && pass "One peer in registry" || fail "Expected 1 peer, got $PEER_COUNT"

PEER_NAME=$(echo "$PEERS" | python3 -c "import sys,json;ps=json.load(sys.stdin).get('peers',[]);print(ps[0].get('name','') if ps else '')" 2>/dev/null)
[ -n "$PEER_NAME" ] && pass "Peer has name: $PEER_NAME" || fail "Peer name missing"

PEER_ENC=$(echo "$PEERS" | python3 -c "import sys,json;ps=json.load(sys.stdin).get('peers',[]);print(ps[0].get('sessionEstablished',False) if ps else '')" 2>/dev/null)
[ "$PEER_ENC" = "True" ] && pass "Peer shows session established" || fail "Session not reflected in peer list"

# ── Send encrypted request from A to B ──

echo ""
echo "  ── Encrypted Request A→B ──"
echo ""

REQ_RESULT=$(curl -s --max-time 10 -X POST "http://127.0.0.1:$PORT_A/Q/api/transport/request" \
    -H "X-Panel-Token: $TOKEN_A" \
    -H "Content-Type: application/json" \
    -d "{\"peer_id\":\"$PEER_B\",\"method\":\"GET\",\"path\":\"/Q/health\"}" 2>/dev/null)

echo "  Request result: $(echo "$REQ_RESULT" | head -c 120)"

REQ_STATUS=$(echo "$REQ_RESULT" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('status','') or d.get('_status',''))" 2>/dev/null)
REQ_OK=$(echo "$REQ_RESULT" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('status',''))" 2>/dev/null)

[ "$REQ_OK" = "ok" ] || [ "$REQ_STATUS" = "200" ] && pass "Encrypted request returned health data" || fail "Request failed: $REQ_RESULT"

# ── Summary ──

echo ""
echo "  ═══════════════════════════════"
echo "  Mesh E2E: $P passed, $F failed"
echo "  ═══════════════════════════════"
echo ""

exit $((F > 0 ? 1 : 0))
