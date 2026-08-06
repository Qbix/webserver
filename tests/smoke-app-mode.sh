#!/usr/bin/env bash
#
# Smoke test: does `--app` mode actually boot against a stock Qbix Platform app?
#
# This is deliberately the smallest possible assertion. It does not check that
# the app renders, or that routing works, or that anything is fast. It starts
# the server the way the README documents for Qbix apps, waits, and asks for
# one URL the server serves itself:
#
#     php qbixserver.php --app=<app> --port=<port>   →   GET /Q/health == 200
#
# If the server dies during bootstrap, or never binds, the test fails and
# prints the server's output.
#
# Usage:
#   APP_DIR=/path/to/Platform/MyApp tests/smoke-app-mode.sh
#
# Environment:
#   APP_DIR   (required) a Qbix Platform app directory — one with
#             scripts/Q.inc.php and local/paths.json
#   PORT      (default 8079)
#   TIMEOUT   (default 20) seconds to wait for the server to answer
#   PHP       (default php)

set -uo pipefail

APP_DIR=${APP_DIR:-}
PORT=${PORT:-8079}
TIMEOUT=${TIMEOUT:-20}
PHP=${PHP:-php}

if [ -z "$APP_DIR" ]; then
	echo "smoke-app-mode: APP_DIR is required" >&2
	exit 2
fi
if [ ! -f "$APP_DIR/scripts/Q.inc.php" ]; then
	echo "smoke-app-mode: $APP_DIR does not look like a Qbix app (no scripts/Q.inc.php)" >&2
	exit 2
fi
if [ ! -f "$APP_DIR/local/paths.json" ]; then
	echo "smoke-app-mode: $APP_DIR/local/paths.json missing — run scripts/Q/configure.php" >&2
	exit 2
fi

ROOT=$(cd "$(dirname "$0")/.." && pwd)
LOG=$(mktemp)
trap 'rm -f "$LOG"' EXIT

echo "smoke-app-mode: starting qbixserver.php --app=$APP_DIR --port=$PORT"
"$PHP" "$ROOT/qbixserver.php" --app="$APP_DIR" --port="$PORT" > "$LOG" 2>&1 &
SERVER_PID=$!

cleanup_server() {
	if kill -0 "$SERVER_PID" 2>/dev/null; then
		kill "$SERVER_PID" 2>/dev/null
		wait "$SERVER_PID" 2>/dev/null
	fi
}

fail() {
	echo
	echo "smoke-app-mode: FAIL — $1"
	echo "──────── server output ────────"
	cat "$LOG"
	echo "───────────────────────────────"
	cleanup_server
	exit 1
}

deadline=$(( $(date +%s) + TIMEOUT ))
while [ "$(date +%s)" -lt "$deadline" ]; do
	if ! kill -0 "$SERVER_PID" 2>/dev/null; then
		wait "$SERVER_PID" 2>/dev/null
		code=$?
		# Note: a bootstrap fatal currently exits 0, so the exit code alone is
		# not a reliable signal — the liveness check above is what catches it.
		fail "the server process exited during startup (exit code $code)"
	fi
	if curl -fsS -o /dev/null "http://127.0.0.1:$PORT/Q/health" 2>/dev/null; then
		echo "smoke-app-mode: PASS — /Q/health answered 200"
		cleanup_server
		exit 0
	fi
	sleep 0.5
done

fail "the server never answered /Q/health within ${TIMEOUT}s"
