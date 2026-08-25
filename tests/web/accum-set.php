<?php
/**
 * Sets cookies, errors, and headers in Q_Response.
 * The NEXT request to a clean endpoint should NOT see these.
 * Tests fix for Issue #16 (accumulated response state).
 */
Q_Response::header('Content-Type: application/json');
Q_Response::header('X-Accumulation-Test: set');
Q_Response::setCookie('accum_test', 'should_not_persist', 0, '/');
Q_Response::addError(new Exception('test error'));

echo json_encode(array(
    'set' => true,
    'errors' => count(Q_Response::getErrors()),
    'cookies' => count(Q_WebServer_State::cookieHeaders()),
));
