<?php
function board_init($params) {
    $room = $params['room'];
    $room->state = array(
        'members' => array(),
        'cards' => array(),
        'nextCardId' => 1,
    );
}
