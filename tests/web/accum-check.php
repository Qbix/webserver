<?php
/**
 * Checks whether Q_Response has any accumulated state from prior requests.
 * Should always return clean.
 */
Q_WebServer_State::header('Content-Type: application/json');

$leaks = array();

// Check Q_Response errors
$errors = Q_Response::getErrors();
if (!empty($errors)) {
    $leaks[] = 'errors=' . count($errors);
}

// Check Q_Response cookies (pending cookies, not request cookies)
$cookieHeaders = Q_Response::cookieHeaders();
if (!empty($cookieHeaders)) {
    $leaks[] = 'pending_cookies=' . count($cookieHeaders);
}

// Check Q_Response redirect
if (Q_Response::$redirected !== null) {
    $leaks[] = 'redirect=' . Q_Response::$redirected;
}

// Check response code (should be 200 default, not something left over)
$code = Q_Response::code();
if ($code !== 200) {
    $leaks[] = 'code=' . $code;
}

echo json_encode(array(
    'clean' => empty($leaks),
    'leaks' => $leaks,
));
