<?php
function chat_reaction($params) {
    $room = $params['room'];
    $data = $params['data'] ?? array();
    $socketId = $room->senderId();

    Q_Socket::broadcastExcept(array(
        'reaction', $data
    ), $socketId);
}
