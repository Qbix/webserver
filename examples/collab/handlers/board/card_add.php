<?php
function board_card_add($params) {
    $room = $params['room'];
    $data = $params['data'] ?? array();
    $socketId = $room->senderId();

    $id = $data['id'] ?? $room->state['nextCardId']++;
    $room->state['cards'][$id] = $data;

    Q_Socket::broadcastExcept(array('card_add', $data), $socketId);
}
