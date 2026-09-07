<?php
/**
 * A user leaves the chat room.
 * Broadcasts their departure.
 */
function chat_leave($params) {
    $room = $params['room'];
    $socketId = $room->senderId();
    $name = $room->state['members'][$socketId] ?? 'anon';

    unset($room->state['members'][$socketId]);
    $count = count($room->state['members']);

    Q_Socket::broadcast(array(
        'left', array('name' => $name, 'count' => $count)
    ));
}
