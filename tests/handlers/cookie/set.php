<?php
function cookie_set(&$params, &$result) {
    Q_Response::setCookie('test_cookie', 'hello_world', 0, '/');
    $result = ['set' => true];
}
