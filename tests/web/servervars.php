<?php
Q_WebServer_State::header('Content-Type: application/json');
echo json_encode(array(
    'SCRIPT_NAME'  => $_SERVER['SCRIPT_NAME']  ?? null,
    'PATH_INFO'    => $_SERVER['PATH_INFO']    ?? null,
    'PHP_SELF'     => $_SERVER['PHP_SELF']     ?? null,
    'QUERY_STRING' => $_SERVER['QUERY_STRING'] ?? null,
    'DOCUMENT_ROOT'=> isset($_SERVER['DOCUMENT_ROOT']) ? 'set' : null,
    'REMOTE_ADDR'  => isset($_SERVER['REMOTE_ADDR']) ? 'set' : null,
    'HTTPS'        => $_SERVER['HTTPS'] ?? 'off',
));
