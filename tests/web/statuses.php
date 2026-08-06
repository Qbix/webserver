<?php
// Arbitrary status codes must round-trip, not just 200/201/302.
$c = isset($_GET['c']) ? (int)$_GET['c'] : 200;
Q_WebServer_State::responseCode($c);
echo "status-$c";
