<?php
function swarm_task_update($params) {
    $db = swarm_db();
    $stmt = $db->prepare('UPDATE tasks SET done = :done, updated_at = datetime("now") WHERE uuid = :uuid');
    $stmt->bindValue(':done', (int) $params['done'], SQLITE3_INTEGER);
    $stmt->bindValue(':uuid', $params['uuid'], SQLITE3_TEXT);
    $stmt->execute();
    return array('ok' => true);
}
