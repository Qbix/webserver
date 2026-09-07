<?php
function board_leave($params) {
    $room = $params['room'];
    $socketId = $room->senderId();
    $name = $room->state['members'][$socketId] ?? 'anon';
    unset($room->state['members'][$socketId]);
    Q_Socket::broadcast(array('left', array('name' => $name)));
}
