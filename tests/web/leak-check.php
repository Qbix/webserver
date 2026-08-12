<?php
/**
 * Checks whether ANY state from a prior request survived.
 * Must return all-clean for the snapshot reset to pass.
 */
Q_WebServer_State::header('Content-Type: application/json');

$leaks = array();

// 1. Static properties — should be back to declaration defaults
if (class_exists('LeakTestCounter', false)) {
    if (LeakTestCounter::$count !== 0) {
        $leaks[] = 'static_count=' . LeakTestCounter::$count . ' (want 0)';
    }
    if (LeakTestCounter::$secret !== '') {
        $leaks[] = 'static_secret=present (want empty)';
    }
} // class not existing at all is also fine (fork mode)

if (class_exists('LeakTestMemory', false)) {
    if (strlen(LeakTestMemory::$blob) > 0) {
        $leaks[] = 'memory_blob=' . strlen(LeakTestMemory::$blob) . ' bytes (want 0)';
    }
}

// 2. Global variables
if (isset($GLOBALS['_leak_test_global'])) {
    $leaks[] = 'global_leak_test=' . $GLOBALS['_leak_test_global'];
}
if (isset($GLOBALS['_leak_user_secret'])) {
    $leaks[] = 'global_secret=present';
}

// 3. Superglobals — should only contain THIS request's data
if (isset($_GET['injected_param'])) {
    $leaks[] = 'GET_injected_param=' . $_GET['injected_param'];
}
if (isset($_POST['injected_post'])) {
    $leaks[] = 'POST_injected_post=' . $_POST['injected_post'];
}
if (isset($_COOKIE['injected_cookie'])) {
    $leaks[] = 'COOKIE_injected_cookie=' . $_COOKIE['injected_cookie'];
}
if (isset($_SERVER['INJECTED_HEADER'])) {
    $leaks[] = 'SERVER_INJECTED_HEADER=' . $_SERVER['INJECTED_HEADER'];
}
if (isset($_REQUEST['injected_request'])) {
    $leaks[] = 'REQUEST_injected_request=' . $_REQUEST['injected_request'];
}

// 4. Check that $_GET, $_POST match THIS request (should be clean)
//    This request comes in as GET /leak-check.php?check=1
if (!isset($_GET['check']) || $_GET['check'] !== '1') {
    $leaks[] = 'GET_check missing or wrong (got: ' . json_encode($_GET) . ')';
}
if (!empty($_POST)) {
    $leaks[] = 'POST should be empty, got: ' . json_encode($_POST);
}

// 5. Check $_SERVER matches THIS request
if (strpos($_SERVER['REQUEST_URI'] ?? '', 'leak-set') !== false) {
    $leaks[] = 'REQUEST_URI still shows previous request';
}

// 6. Check php://input is empty for this GET request
$rawInput = $GLOBALS['_Q_RAW_INPUT'] ?? file_get_contents('php://input');
if (!empty($rawInput)) {
    $leaks[] = 'raw_input not empty: ' . substr($rawInput, 0, 50);
}

echo json_encode(array(
    'clean' => empty($leaks),
    'leaks' => $leaks,
    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
));
