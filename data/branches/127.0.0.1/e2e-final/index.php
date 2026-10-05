<?php
header('Content-Type: application/json');
$configPath = __DIR__ . '/config.json';
$raw = file_get_contents($configPath);
$config = json_decode($raw, true);
echo json_encode(array(
    'db_name' => $config['database']['name'] ?? 'MISSING',
    'db_user' => $config['database']['username'] ?? 'MISSING',
    'db_host' => $config['database']['host'] ?? 'MISSING',
    'has_placeholders' => strpos($raw, '{{') !== false,
    'raw_snippet' => substr($raw, 0, 200),
), JSON_PRETTY_PRINT) . "\n";
