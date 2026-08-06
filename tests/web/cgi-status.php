<?php
// Fixture for the php-cgi carveout. Scripts routed to php-cgi run in a FRESH
// PHP process that has none of the server's classes loaded, so they must use
// only native PHP -- header() here, not Q_WebServer_State::header().
$c = isset($_GET['c']) ? (int)$_GET['c'] : 200;
header("HTTP/1.1 $c");
header('X-Cgi: yes');
echo "cgi-status-$c";
