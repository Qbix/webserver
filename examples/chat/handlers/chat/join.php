<?php
/**
 * A user joins the chat room.
 * Broadcasts their arrival to all other members.
 */
function chat_join($params) {
    $room = $params['room'];
    $data = $params['data'] ?? array();
    $name = $data['name'] ?? 'anon';
    $socketId = $room->senderId();

    $room->state['members'][$socketId] = $name;
    $count = count($room->state['members']);

    // Tell everyone (including the joiner) about the new member
    Q_Socket::broadcast(array(
        'joined', array('name' => $name, 'count' => $count)
    ));

    // Send the full member list to the joiner
    Q_Socket::emit(array(
        'presence', array('users' => array_flip(array_unique($room->state['members'])))
    ), $socketId);
}
