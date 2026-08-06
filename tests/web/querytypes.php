<?php
Q_WebServer_State::header('Content-Type: application/json');
echo json_encode(array(
    'arr'    => $_GET['a'] ?? null,      // a[]=1&a[]=2
    'nested' => $_GET['n'] ?? null,      // n[x]=1
    'enc'    => $_GET['e'] ?? null,      // percent-encoded
));
