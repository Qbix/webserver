<?php
function board_cursor($params) {
    $data = $params['data'] ?? array();
    $socketId = $params['room']->senderId();
    Q_Socket::broadcastExcept(array('cursor', $data), $socketId);
}
