<?php
function board_join($params) {
    $room = $params['room'];
    $data = $params['data'] ?? array();
    $socketId = $room->senderId();
    $name = $data['name'] ?? 'anon';

    $room->state['members'][$socketId] = $name;

    // Broadcast join
    Q_Socket::broadcast(array('joined', array('name' => $name)));

    // Send current state to the joiner
    Q_Socket::emit(array('sync', array(
        'cards' => $room->state['cards'],
        'users' => array_flip(array_unique($room->state['members'])),
    )), $socketId);
}
