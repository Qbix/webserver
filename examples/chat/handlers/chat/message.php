<?php
/**
 * A chat message — broadcast to all room members.
 */
function chat_message($params) {
    $room = $params['room'];
    $data = $params['data'] ?? array();
    $socketId = $room->senderId();

    $room->state['messageCount']++;

    // Broadcast to everyone EXCEPT the sender (sender already has the message)
    Q_Socket::broadcastExcept(array(
        'message', array(
            'id'   => $data['id'] ?? $room->state['messageCount'],
            'name' => $data['name'] ?? ($room->state['members'][$socketId] ?? 'anon'),
            'text' => $data['text'] ?? '',
            'room' => $data['room'] ?? 'general',
            'time' => $data['time'] ?? date('H:i'),
        )
    ), $socketId);
}
