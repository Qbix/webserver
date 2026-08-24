<?php
/**
 * SSE test endpoint. Streams 5 events, one per second.
 */
Q_Response::header('Content-Type: text/event-stream');
Q_Response::header('Cache-Control: no-cache');
Q_Response::header('X-Accel-Buffering: no');

for ($i = 1; $i <= 5; $i++) {
    echo "id: $i\n";
    echo "data: " . json_encode(array('count' => $i, 'time' => date('H:i:s'))) . "\n\n";
    @ob_flush();
    flush();
    if ($i < 5) sleep(1);
}
