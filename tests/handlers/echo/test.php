<?php
function echo_test(&$params, &$result) {
    $result = ['event' => $params['event'], 'data' => $params['data']];
}
