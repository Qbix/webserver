<?php
Q::header('Content-Type: application/json');
echo json_encode([
    'method'     => $_SERVER['REQUEST_METHOD'],
    'uri'        => $_SERVER['REQUEST_URI'],
    'query'      => $_GET,
    'post'       => $_POST,
    'cookies'    => $_COOKIE,
    'files'      => array_map(function($f) { return ['name'=>$f['name'],'size'=>$f['size']]; }, $_FILES),
    'php_self'   => $_SERVER['PHP_SELF'],
    'doc_root'   => $_SERVER['DOCUMENT_ROOT'],
    'server_sw'  => $_SERVER['SERVER_SOFTWARE'],
    'gateway'    => $_SERVER['GATEWAY_INTERFACE'],
    'remote'     => $_SERVER['REMOTE_ADDR'],
    'scheme'     => $_SERVER['REQUEST_SCHEME'],
    'auth_user'  => $_SERVER['PHP_AUTH_USER'] ?? null,
    'q_class'    => class_exists('Q'),
    'q_request'  => class_exists('Q_Request'),
    'q_config'   => class_exists('Q_Config'),
    'raw_input'  => Q_Request::input(),
]);
