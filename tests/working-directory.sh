#!/usr/bin/env bash
#
# Regression test: a script runs with its own directory as the working
# directory, the way mod_php and fpm run it.
#
# A persistent worker has no reason to change directory, so it kept the one
# the server was started in. Every relative path in an application then
# resolved against the server's directory: includes missed, and writes
# landed in the server's tree. Exponential put its template cache there,
# read a half-written file back on the next request and died on a parse
# error naming a file it had never heard of — three directories away from
# anything it owned.
#
# The directory is restored afterwards, so a script that chdir()s cannot
# leave the worker pointed somewhere else for the next request.
#
#   ./tests/working-directory.sh
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

ROOT="$TMP/public"; mkdir -p "$ROOT/deep/deeper"

printf '<?php header("Content-Type: text/plain"); echo getcwd();\n'   > "$ROOT/cwd.php"
printf '<?php header("Content-Type: text/plain"); echo getcwd();\n'   > "$ROOT/deep/cwd.php"
printf '<?php header("Content-Type: text/plain"); echo getcwd();\n'   > "$ROOT/deep/deeper/cwd.php"

# A relative include is the other half of it: an application splitting
# itself across files this way is the common case.
printf '<?php header("Content-Type: text/plain"); require "lib.php"; echo greet();\n' \
    > "$ROOT/deep/rel.php"
printf '<?php function greet() { return "RELATIVE-INCLUDE"; }\n' > "$ROOT/deep/lib.php"

# Writing relative, which is how a cache directory gets created.
cat > "$ROOT/deep/write.php" <<'PHP'
<?php
header('Content-Type: text/plain');
@mkdir('cachedir', 0777, true);
@file_put_contents('cachedir/f.txt', 'x');
echo is_file(__DIR__ . '/cachedir/f.txt') ? 'BESIDE-SCRIPT' : 'ELSEWHERE';
PHP

# A script that wanders off must not leave the worker there.
printf '<?php header("Content-Type: text/plain"); chdir("/tmp"); echo "MOVED";\n' \
    > "$ROOT/wander.php"

echo "=============================================="
echo " Qbix Server — working directory per request"
echo "=============================================="
echo

( setsid "$PHP" "$WS/qbixserver.php" --root="$ROOT" --port=$PORT --workers=1 \
    >"$TMP/server.log" 2>&1 </dev/null & )

up=0
for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/cwd.php" 2>/dev/null && { up=1; break; }
done
[ "$up" = "1" ] || { echo "  server never came up"; sed 's/^/    /' "$TMP/server.log" | head -10; exit 1; }

body() { curl -s --max-time 15 "http://127.0.0.1:$PORT/$1" 2>/dev/null; }

# realpath, because the temp dir may be reached through a symlink.
R=$(cd "$ROOT" && pwd -P)

[ "$(body cwd.php)" = "$R" ] \
    && ok "document root: cwd is the script's directory" \
    || bad "document root — got $(body cwd.php), expected $R"

[ "$(body deep/cwd.php)" = "$R/deep" ] \
    && ok "subdirectory: cwd follows the script" \
    || bad "subdirectory — got $(body deep/cwd.php)"

[ "$(body deep/deeper/cwd.php)" = "$R/deep/deeper" ] \
    && ok "nested subdirectory: cwd follows the script" \
    || bad "nested — got $(body deep/deeper/cwd.php)"

[ "$(body deep/rel.php)" = "RELATIVE-INCLUDE" ] \
    && ok "a relative require resolves next to the script" \
    || bad "relative require — got $(body deep/rel.php)"

[ "$(body deep/write.php)" = "BESIDE-SCRIPT" ] \
    && ok "a relative write lands next to the script" \
    || bad "relative write — got $(body deep/write.php)"

# Nothing may have been created in the server's own tree.
[ -e "$WS/cachedir" ] && bad "the script wrote into the server's directory" \
                      || ok "nothing was written into the server's directory"

# One worker, so the next request lands on the one that wandered.
[ "$(body wander.php)" = "MOVED" ] || bad "wander.php did not run"
[ "$(body cwd.php)" = "$R" ] \
    && ok "a script's chdir() does not leak into the next request" \
    || bad "cwd leaked: $(body cwd.php)"

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
