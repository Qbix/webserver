<?php
header('Content-Type: application/json');
echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'body' => $_POST]);
