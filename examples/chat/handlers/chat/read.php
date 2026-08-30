<?php
function chat_read($params) {
    $room = $params['room'];
    $data = $params['data'] ?? array();
    $socketId = $room->senderId();

    Q_Socket::broadcastExcept(array(
        'read', $data
    ), $socketId);
}
