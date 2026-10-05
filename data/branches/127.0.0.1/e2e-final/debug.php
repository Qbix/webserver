<?php
$result = array();
$result['APP_DIR'] = defined('APP_DIR') ? APP_DIR : '(not defined)';
$result['QBIX_DATA_DIR'] = defined('QBIX_DATA_DIR') ? QBIX_DATA_DIR : '(not defined)';
$path = defined('APP_DIR') ? APP_DIR . '/local/panel.json' : 'unknown';
$result['panelPath'] = $path;
$result['fileExists'] = file_exists($path);
if (file_exists($path)) {
    $result['content'] = json_decode(file_get_contents($path), true) ? 'valid JSON' : 'invalid';
}
echo json_encode($result);
