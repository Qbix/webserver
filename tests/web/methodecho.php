<?php
Q_WebServer_State::header('Content-Type: application/json');
echo json_encode(array(
    'method' => $_SERVER['REQUEST_METHOD'],
    'len'    => (int)($_SERVER['CONTENT_LENGTH'] ?? 0),
    'body'   => file_get_contents('php://input'),
));
