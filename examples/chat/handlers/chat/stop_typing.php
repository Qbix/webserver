<?php
function chat_stop_typing($params) {
    $room = $params['room'];
    $data = $params['data'] ?? array();
    $socketId = $room->senderId();

    Q_Socket::broadcastExcept(array(
        'stop_typing', $data
    ), $socketId);
}
