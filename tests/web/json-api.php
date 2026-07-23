<?php
header('Content-Type: application/json');
echo json_encode(['status' => 'ok', 'method' => $_SERVER['REQUEST_METHOD']]);
