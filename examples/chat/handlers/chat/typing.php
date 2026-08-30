<?php
/**
 * Typing indicator — ephemeral, broadcast to others.
 */
function chat_typing($params) {
    $room = $params['room'];
    $data = $params['data'] ?? array();
    $socketId = $room->senderId();

    Q_Socket::broadcastExcept(array(
        'typing', $data
    ), $socketId);
}
