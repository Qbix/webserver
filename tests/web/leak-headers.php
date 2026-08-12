<?php
/**
 * Sets various response headers and cookies.
 * The NEXT request to a different script must NOT see these headers
 * in its response — verifying Q_WebServer_State header reset.
 */
Q_WebServer_State::header('Content-Type: application/json');
Q_WebServer_State::header('X-Secret-Token: bearer_abc123_should_not_leak');
Q_WebServer_State::header('X-User-Id: user_42');
Q_WebServer_State::header('Set-Cookie: session_secret=s3cr3t; Path=/; HttpOnly');

echo json_encode(array('set' => true, 'headers_set' => 3));
