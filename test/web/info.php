<?php
header('Content-Type: application/json');
echo json_encode([
    'time' => date('c'),
    'php' => PHP_VERSION,
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'uri' => $_SERVER['REQUEST_URI'] ?? '/',
    'server' => 'Qbix Server',
]);
