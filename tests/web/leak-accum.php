<?php
/**
 * Reports memory usage and static accumulation state.
 * If the snapshot reset works, memory should stay flat and
 * the counter should always be 1 (never 2, 3, 4...).
 */

if (!class_exists('AccumTest', false)) {
    class AccumTest {
        public static $counter = 0;
        public static $items = array();
    }
}

AccumTest::$counter++;
AccumTest::$items[] = 'request_' . uniqid();

Q_WebServer_State::header('Content-Type: application/json');
echo json_encode(array(
    'counter' => AccumTest::$counter,
    'items_count' => count(AccumTest::$items),
    'memory' => memory_get_usage(true),
    'memory_peak' => memory_get_peak_usage(true),
    'pid' => getmypid(),
));
