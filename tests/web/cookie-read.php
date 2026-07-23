<?php
header('Content-Type: application/json');
echo json_encode(['cookies' => $_COOKIE]);
