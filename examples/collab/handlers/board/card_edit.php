<?php
function board_card_edit($params) {
    $data = $params['data'] ?? array();
    $room = $params['room'];
    $socketId = $room->senderId();
    $id = $data['id'] ?? 0;
    if (isset($room->state['cards'][$id])) {
        $room->state['cards'][$id]['text'] = $data['text'];
    }
    Q_Socket::broadcastExcept(array('card_edit', $data), $socketId);
}
