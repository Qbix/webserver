<?php
// Read config.json (will go through stream wrapper which injects credentials)
$configPath = __DIR__ . '/config.json';
$raw = file_get_contents($configPath);
$config = json_decode($raw, true);

header('Content-Type: application/json');
echo json_encode(array(
    'db_name' => $config['database']['name'] ?? 'MISSING',
    'db_user' => $config['database']['username'] ?? 'MISSING',
    'db_host' => $config['database']['host'] ?? 'MISSING',
    'db_port' => $config['database']['port'] ?? 'MISSING',
    'db_pass_is_set' => !empty($config['database']['password']),
    'has_placeholders' => strpos($raw, '{{') !== false,
    'app_name' => $config['app']['name'] ?? 'MISSING',
), JSON_PRETTY_PRINT) . "\n";
