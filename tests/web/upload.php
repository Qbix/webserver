<?php
Q::header('Content-Type: application/json');
$result = ['post' => $_POST, 'files' => []];
foreach ($_FILES as $k => $f) {
    $result['files'][$k] = [
        'name' => $f['name'], 'size' => $f['size'],
        'type' => $f['type'], 'error' => $f['error'],
        'content' => file_get_contents($f['tmp_name']),
    ];
}
echo json_encode($result);
