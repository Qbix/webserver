<?php
Q_WebServer_State::header('Content-Type: application/json');
Q_Response::setCookie('probe', 'v1', 0, '/');
echo json_encode(array('set' => true));
