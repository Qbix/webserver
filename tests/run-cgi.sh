#!/usr/bin/env bash
#
# php-cgi carveout tests.
#
# Native header() is silently discarded under the CLI SAPI -- PHP gives no hook
# to capture it -- so scripts that rely on it must be routed to php-cgi via
# Q.webserver.cgi.patterns. That escape hatch is the documented answer, so it
# needs a test proving it actually works.
#
# NOTE: a script routed to php-cgi runs in a FRESH process with none of the
# server's classes loaded. Such scripts must use only native PHP.
set -uo pipefail
WS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=${PORT:-20531}
RC=0; PID=""
trap '[ -n "$PID" ] && kill -9 "$PID" 2>/dev/null' EXIT

command -v php-cgi >/dev/null 2>&1 || { echo "SKIP: php-cgi not installed"; exit 0; }

CFG=$(mktemp /tmp/cgi-XXXX.json)
cat > "$CFG" <<JSON
{ "Q": { "webserver": { "cgi": { "patterns": ["native.php","cgi-status.php"] } } } }
JSON

php "$WS/qbixserver.php" --root="$WS/tests/web" --port="$PORT" --config="$CFG" >/tmp/cgi-$PORT.log 2>&1 &
PID=$!
for _ in $(seq 1 50); do
  php -r '$f=@fsockopen("127.0.0.1",(int)$argv[1],$e,$s,1); exit($f?0:1);' "$PORT" 2>/dev/null && break
  sleep 0.2
done

check() { # label got want
  if [ "$2" = "$3" ]; then printf "  ok   %-28s %s\n" "$1" "$2"
  else printf "  FAIL %-28s %s (want %s)\n" "$1" "$2" "$3"; RC=1; fi
}
get() { # path -> "status|hasheader"
  php -r '
    $fp=@fsockopen("127.0.0.1",(int)$argv[1],$e,$s,3);
    if(!$fp){echo "CONNFAIL|no";exit;}
    fwrite($fp,"GET $argv[2] HTTP/1.1\r\nHost: l\r\nConnection: close\r\n\r\n");
    stream_set_timeout($fp,5);$r="";
    while(!feof($fp)){$c=@fread($fp,200000);if(!$c)break;$r.=$c;}
    fclose($fp);
    $st = preg_match("#^HTTP/\S+ (\d{3})#",$r,$m) ? $m[1] : "NORESP";
    echo $st."|".(stripos($r,$argv[3])!==false ? "yes":"no");
  ' "$PORT" "$1" "$2" 2>/dev/null
}

echo "═══ php-cgi carveout ═══"
r=$(get /native.php "X-Native: yes");   check "native header() status" "${r%%|*}" "201"
                                        check "native custom header"   "${r##*|}" "yes"
for c in 201 404 418 503; do
  r=$(get "/cgi-status.php?c=$c" "X-Cgi: yes")
  check "cgi status $c" "${r%%|*}" "$c"
done
r=$(get /hello.php "Content-Type"); check "non-cgi still in-process" "${r%%|*}" "200"

rm -f "$CFG"
echo ""
[ $RC -eq 0 ] && echo "CGI TESTS PASSED" || echo "CGI TESTS FAILED"
exit $RC
