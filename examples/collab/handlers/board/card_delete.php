<?php
function board_card_delete($params) {
    $data = $params['data'] ?? array();
    $room = $params['room'];
    $socketId = $room->senderId();
    $id = $data['id'] ?? 0;
    unset($room->state['cards'][$id]);
    Q_Socket::broadcastExcept(array('card_delete', $data), $socketId);
}
