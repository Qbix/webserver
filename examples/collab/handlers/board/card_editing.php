<?php
function board_card_editing($params) {
    $data = $params['data'] ?? array();
    $socketId = $params['room']->senderId();
    Q_Socket::broadcastExcept(array('card_editing', $data), $socketId);
}
