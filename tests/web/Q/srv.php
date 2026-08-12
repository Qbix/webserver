<?php
/**
 * Dumps $_SERVER fields that Issue #14 found broken:
 * - SCRIPT_NAME: should match the request path, not lose segments
 * - SERVER_PORT: should reflect the Host header, not the listen port
 */
Q_WebServer_State::header('Content-Type: application/json');
echo json_encode(array(
    'SCRIPT_NAME'  => $_SERVER['SCRIPT_NAME'] ?? '(unset)',
    'SERVER_PORT'  => $_SERVER['SERVER_PORT'] ?? '(unset)',
    'SERVER_NAME'  => $_SERVER['SERVER_NAME'] ?? '(unset)',
    'REQUEST_URI'  => $_SERVER['REQUEST_URI'] ?? '(unset)',
    'DOCUMENT_ROOT'=> $_SERVER['DOCUMENT_ROOT'] ?? '(unset)',
    'HTTP_HOST'    => $_SERVER['HTTP_HOST'] ?? '(unset)',
));
