#!/usr/bin/env bash
#
# Build a minimal, plugin-free Qbix app for testing --app mode.
#
# The Platform will not bootstrap without a real app: it needs a config with
# Q/plugins and Q/web/appRootUrl, and its Q_Uri is not loadable on its own.
# Without this fixture, tests/platform-compat.php and tests/routing-parity.php
# both SKIP with exit 0 -- which in CI looks exactly like passing.
#
#   make-app.sh <platform-dir> <target-dir> [port]
#
set -euo pipefail
PLATFORM="${1:?usage: make-app.sh <platform-dir> <target-dir> [port]}"
TARGET="${2:?usage: make-app.sh <platform-dir> <target-dir> [port]}"
PORT="${3:-20099}"

[ -f "$PLATFORM/Q.php" ] || { echo "no Platform at $PLATFORM" >&2; exit 1; }

rm -rf "$TARGET"
mkdir -p "$TARGET"/{config,local,web,handlers/TestApp,classes,views,files,scripts}

cat > "$TARGET/config/app.json" <<JSON
{
  "Q": {
    "app": "TestApp",
    "appInfo": { "version": "0.1", "compatible": "0.1", "connections": [] },
    "plugins": [],
    "routes": {
      "AI/webhook/:type/:task": {"module": "AI", "action": "webhook"},
      "AI/stream/command": {"module": "AI", "action": "stream/command"},
      "Safebox/workload/:workloadId": {"module": "Safebox", "action": "workload"},
      "Safebox/action": {"module": "Safebox", "action": "action"},
      "Streams/stream/:publisherId/:name": {"module": "Streams", "action": "stream"},
      ":module/:action": {},
      "": {"module": "TestApp", "action": "welcome"}
    },
    "response": { "slotNames": ["content"] },
    "exception": { "showTrace": true, "showFileAndLine": true },
    "web": { "languages": {"en": 1} }
  }
}
JSON

# appRootUrl must match the port the server is started on, or the Platform's
# dispatcher rejects every request with "bad url".
cat > "$TARGET/local/app.json" <<JSON
{ "Q": { "web": { "appRootUrl": "http://localhost:$PORT" } } }
JSON

cat > "$TARGET/local/paths.json" <<JSON
{ "platform": "$PLATFORM" }
JSON

cat > "$TARGET/scripts/Q.inc.php" <<'PHP'
<?php
if (!defined('APP_DIR')) {
	define('APP_DIR', dirname(dirname(__FILE__)));
}
$Q_paths = json_decode(file_get_contents(APP_DIR.'/local/paths.json'), true);
if (!defined('Q_DIR')) {
	define('Q_DIR', $Q_paths['platform']);
}
include_once(Q_DIR.'/Q.php');
PHP

cat > "$TARGET/handlers/TestApp/welcome.php" <<'PHP'
<?php
function TestApp_welcome(&$params) {
	Q_Response::setSlot('content', 'welcome-from-testapp');
}
PHP

cat > "$TARGET/web/index.php" <<'PHP'
<?php
include(dirname(dirname(__FILE__)).'/scripts/Q.inc.php');
Q_WebController::execute();
PHP
cp "$TARGET/web/index.php" "$TARGET/web/action.php"
echo '<?php echo "plain-php-ok";' > "$TARGET/web/plain.php"
echo 'static-file-ok' > "$TARGET/web/static.txt"

# The probe drives the SAME fixtures in both modes, so copy them all rather
# than a hand-maintained list -- every fixture added for standalone was
# otherwise missing under --app, and the probe reported dozens of failures
# that were really just absent files.
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ -d "$HERE/web" ]; then
	cp -R "$HERE/web/." "$TARGET/web/"
fi
# index.php/action.php must stay the Platform front controller, not a fixture.
cat > "$TARGET/web/index.php" <<'PHP'
<?php
include(dirname(dirname(__FILE__)).'/scripts/Q.inc.php');
Q_WebController::execute();
PHP
cp "$TARGET/web/index.php" "$TARGET/web/action.php"

echo "built $TARGET (platform=$PLATFORM, port=$PORT)"
