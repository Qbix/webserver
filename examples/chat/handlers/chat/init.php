<?php
/**
 * Chat room initialization — called when the room process starts.
 * Sets up the shared state (member list, message count).
 */
function chat_init($params) {
    $room = $params['room'];
    $room->state = array(
        'members' => array(),
        'messageCount' => 0,
    );
}
