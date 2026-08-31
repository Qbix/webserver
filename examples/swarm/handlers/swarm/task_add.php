<?php
function swarm_task_add($params) {
    $db = swarm_db();
    $stmt = $db->prepare('INSERT OR IGNORE INTO tasks (uuid, text) VALUES (:uuid, :text)');
    $stmt->bindValue(':uuid', $params['uuid'], SQLITE3_TEXT);
    $stmt->bindValue(':text', $params['text'], SQLITE3_TEXT);
    $stmt->execute();
    return array('ok' => true, 'uuid' => $params['uuid']);
}
