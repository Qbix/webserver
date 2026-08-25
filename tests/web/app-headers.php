<?php
/**
 * Simulates --app mode header flow:
 * Q_Response::header() → Q_WebServer_State::header()
 *
 * In standalone mode, our Q_Response already delegates.
 * This test verifies the delegation chain works end-to-end:
 * the headers we set via Q_Response actually appear in the HTTP response.
 */

// Set headers via Q_Response (the API apps should use)
Q_Response::header('Content-Type: application/json');
Q_Response::header('X-App-Mode: platform-compat');
Q_Response::header('X-Custom-Token: test123');
Q_Response::code(201);

// Also set a cookie via Q_Response
Q_Response::setCookie('test_cookie', 'hello', 0, '/');

echo json_encode(array(
    'ok' => true,
    'method' => Q_Request::method(),
    'path' => $_SERVER['REQUEST_URI'] ?? '/',
    'code' => Q_Response::code(),
    'headers_set' => array(
        'Content-Type' => Q_WebServer_State::getHeader('Content-Type'),
        'X-App-Mode' => Q_WebServer_State::getHeader('X-App-Mode'),
        'X-Custom-Token' => Q_WebServer_State::getHeader('X-Custom-Token'),
    ),
));
