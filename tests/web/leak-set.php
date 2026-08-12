<?php
/**
 * Deliberately pollutes every kind of state that the snapshot reset
 * must clean up between requests.  Called FIRST, then leak-check.php
 * is called on the SAME worker to verify nothing survived.
 */

// 1. Static property on a user-defined class
if (!class_exists('LeakTestCounter', false)) {
    class LeakTestCounter {
        public static $count = 0;
        public static $secret = '';
    }
}
LeakTestCounter::$count = 42;
LeakTestCounter::$secret = 'REQUEST_A_SECRET_TOKEN_xyz789';

// 2. Global variable
$GLOBALS['_leak_test_global'] = 'leaked_global_data';
$GLOBALS['_leak_user_secret'] = 'password123';

// 3. Superglobal pollution (beyond what the request naturally sets)
$_GET['injected_param'] = 'should_not_persist';
$_POST['injected_post'] = 'should_not_persist';
$_COOKIE['injected_cookie'] = 'should_not_persist';
$_SERVER['INJECTED_HEADER'] = 'should_not_persist';
$_REQUEST['injected_request'] = 'should_not_persist';

// 4. Output buffer state — push an extra buffer
ob_start();
echo "BUFFERED_CONTENT_SHOULD_NOT_LEAK";

// 5. File handle leak — open a temp file and DON'T close it
$tmpFile = tempnam(sys_get_temp_dir(), 'leak_');
$fh = fopen($tmpFile, 'w');
fwrite($fh, 'secret data in temp file');
// intentionally not closing $fh

// 6. Accumulate memory — allocate a big string in a static
if (!class_exists('LeakTestMemory', false)) {
    class LeakTestMemory {
        public static $blob = '';
    }
}
LeakTestMemory::$blob = str_repeat('X', 1024 * 1024); // 1MB

// Respond with what we set, so the test can confirm the write worked
Q_WebServer_State::header('Content-Type: application/json');
$content = ob_get_contents();
ob_end_clean();

echo json_encode(array(
    'set' => true,
    'static_count' => LeakTestCounter::$count,
    'static_secret' => substr(LeakTestCounter::$secret, 0, 10) . '...',
    'global_set' => isset($GLOBALS['_leak_test_global']),
    'get_injected' => isset($_GET['injected_param']),
    'blob_size' => strlen(LeakTestMemory::$blob),
    'buffer_content' => strlen($content) > 0,
    'tmp_file' => $tmpFile,
));
