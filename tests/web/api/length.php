<?php
$body = json_encode(['size' => strlen(file_get_contents('php://input'))]);
header('Content-Type: application/json');
echo $body;
