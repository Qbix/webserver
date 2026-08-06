#!/usr/bin/env bash
#
# Dual-mode end-to-end acceptance test.
#
# Starts the server for real -- twice, standalone and --app -- and drives it
# over a socket with tests/probe.php. Nothing is mocked. These are the
# behaviours that unit tests miss: exit() in a script once sent the client
# zero bytes while the access log said 200, and headers set under --app were
# silently dropped. Both were invisible until something read the wire.
#
#   ./tests/run-probe.sh [platform-dir] [app-dir]
#
# Exits non-zero if either mode fails.
set -uo pipefail

WS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLATFORM="${1:-}"
APP="${2:-/tmp/qbixserver-probe-app}"
SA_PORT=${SA_PORT:-20701}
APP_PORT=${APP_PORT:-20702}
RC=0
PID=""

cleanup() {
	[ -n "$PID" ] && kill "$PID" 2>/dev/null
	wait "$PID" 2>/dev/null
	PID=""
}
trap cleanup EXIT

# Wait for the port to accept, rather than sleeping a fixed amount: startup
# time varies a lot between standalone and --app (the Platform bootstraps a
# whole framework), and a fixed sleep is either slow or flaky.
wait_up() {
	local port=$1 tries=${2:-60}
	for _ in $(seq 1 "$tries"); do
		if php -r 'exit(@fsockopen("127.0.0.1",(int)$argv[1],$e,$s,1) ? 0 : 1);' "$port" 2>/dev/null; then
			return 0
		fi
		sleep 0.25
	done
	return 1
}

run_mode() {
	local label=$1 port=$2 probe_app=$3; shift 3
	echo ""
	echo "──────── $label ────────"
	php "$WS/qbixserver.php" "$@" --port="$port" > "/tmp/probe-$port.log" 2>&1 &
	PID=$!
	if ! wait_up "$port"; then
		echo "  FAIL server never accepted on port $port"
		sed -n '1,40p' "/tmp/probe-$port.log"
		RC=1; cleanup; return
	fi

	local out
	out=$(PROBE_APP="$probe_app" php "$WS/tests/probe.php" "$port" 2>&1)
	echo "$out"
	if echo "$out" | grep -q '^  XX'; then
		echo "  FAIL $label had probe failures"
		RC=1
	fi

	# The server must still be alive: a request that kills it is a bug even
	# when the response looked fine.
	if ! kill -0 "$PID" 2>/dev/null; then
		echo "  FAIL server died during $label"
		RC=1
	fi
	cleanup
}

run_mode "STANDALONE" "$SA_PORT" "" --root="$WS/tests/web"

if [ -n "$PLATFORM" ] && [ -f "$PLATFORM/Q.php" ]; then
	bash "$WS/tests/fixtures/make-app.sh" "$PLATFORM" "$APP" "$APP_PORT" >/dev/null
	run_mode "--app MODE" "$APP_PORT" "1" --app="$APP"
else
	# Do NOT pass silently: a skipped --app run in CI is indistinguishable
	# from a passing one, which is how this class of bug survives.
	echo ""
	echo "──────── --app MODE ────────"
	echo "  SKIP no Platform given (pass its path as \$1)"
fi

echo ""
if [ "$RC" -eq 0 ]; then echo "ALL PROBES PASSED"; else echo "PROBE FAILURES"; fi
exit "$RC"
