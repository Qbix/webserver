<?php
function board_card_move($params) {
    $data = $params['data'] ?? array();
    $room = $params['room'];
    $socketId = $room->senderId();
    $id = $data['id'] ?? 0;
    if (isset($room->state['cards'][$id])) {
        $room->state['cards'][$id]['col'] = $data['col'];
    }
    Q_Socket::broadcastExcept(array('card_move', $data), $socketId);
}
