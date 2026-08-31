<?php
function swarm_message($params) {
    $db = swarm_db();
    $stmt = $db->prepare('INSERT OR IGNORE INTO messages (uuid, name, text, server, created_at) VALUES (:uuid, :name, :text, :server, datetime("now"))');
    $stmt->bindValue(':uuid', $params['uuid'], SQLITE3_TEXT);
    $stmt->bindValue(':name', $params['name'], SQLITE3_TEXT);
    $stmt->bindValue(':text', $params['text'], SQLITE3_TEXT);
    $stmt->bindValue(':server', $params['server'] ?? '', SQLITE3_TEXT);
    $stmt->execute();
    return array('ok' => true, 'uuid' => $params['uuid']);
}
