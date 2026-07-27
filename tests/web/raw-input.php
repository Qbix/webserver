<?php
$raw = file_get_contents('php://input');
header('Content-Type: application/json');
echo json_encode(['raw' => $raw, 'length' => strlen($raw)]);
