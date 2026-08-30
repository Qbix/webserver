<?php
/**
 * SSE streaming endpoint — demonstrates Server-Sent Events.
 *   ?mode=tokens  — AI token-by-token streaming
 *   ?mode=logs    — simulated server log feed
 *   ?mode=json    — structured JSON data events
 *   ?mode=counter — simple 1-per-second counter
 */
Q_Response::header('Content-Type: text/event-stream');
Q_Response::header('Cache-Control: no-cache');
Q_Response::header('X-Accel-Buffering: no');

$mode = $_GET['mode'] ?? 'tokens';

switch ($mode) {
    case 'tokens':
        $paragraphs = array(
            "The Qbix Server is a pure PHP web server that replaces the traditional nginx + php-fpm stack.",
            "It serves static files, executes PHP scripts, handles WebSocket connections, and provides a live dashboard — all from a single process.",
            "With octane mode enabled, persistent workers match php-fpm's per-request speed while using copy-on-write memory sharing to achieve over 100 times the concurrent capacity on the same hardware.",
            "The server supports Server-Sent Events for streaming responses like this one, making it perfect for proxying AI API calls to the browser.",
            "Each token arrives incrementally, just like a real language model completion. The browser renders them as they arrive — no buffering, no waiting for the full response.",
        );
        foreach ($paragraphs as $pi => $para) {
            $words = explode(' ', $para);
            foreach ($words as $word) {
                echo "data: " . $word . " \n\n";
                @ob_flush(); flush();
                usleep(strlen($word) > 6 ? 100000 : 50000);
            }
            if ($pi < count($paragraphs) - 1) {
                echo "data: \n\ndata: \n\n";
                @ob_flush(); flush();
                usleep(300000);
            }
        }
        echo "event: done\ndata: \n\n";
        @ob_flush(); flush();
        break;

    case 'logs':
        $methods = array('GET','GET','GET','POST','GET','PUT','DELETE','GET','PATCH','GET');
        $paths = array('/index.html','/api/users','/api/posts/42','/api/auth/login',
                      '/static/app.js','/api/settings','/api/posts/17','/health',
                      '/api/search?q=php','/api/notifications');
        $codes = array(200,200,200,201,200,200,204,200,404,500,301,304);
        $ips = array('192.168.1.42','10.0.0.15','172.16.0.8','192.168.1.100','10.0.0.23');
        for ($i = 0; $i < 50; $i++) {
            $line = date('H:i:s') . ' '
                . $codes[array_rand($codes)] . ' '
                . $methods[array_rand($methods)] . ' '
                . $paths[array_rand($paths)]
                . ' (' . (rand(1,200)/10) . 'ms)'
                . ' ' . $ips[array_rand($ips)];
            echo "data: $line\n\n";
            @ob_flush(); flush();
            usleep(rand(100000, 600000));
        }
        echo "event: done\ndata: \n\n";
        @ob_flush(); flush();
        break;

    case 'json':
        $sensors = array('cpu','memory','disk','network','gpu');
        for ($i = 0; $i < 40; $i++) {
            $data = array(
                'timestamp' => date('c'),
                'sensor' => $sensors[array_rand($sensors)],
                'value' => round(rand(100, 9500) / 100, 1),
                'unit' => '%',
                'host' => 'server-' . rand(1,3),
            );
            echo "data: " . json_encode($data) . "\n\n";
            @ob_flush(); flush();
            usleep(rand(200000, 800000));
        }
        echo "event: done\ndata: \n\n";
        @ob_flush(); flush();
        break;

    case 'counter':
    default:
        for ($i = 1; $i <= 60; $i++) {
            echo "data: " . $i . "\n\n";
            @ob_flush(); flush();
            sleep(1);
        }
        echo "event: done\ndata: \n\n";
        @ob_flush(); flush();
        break;
}
