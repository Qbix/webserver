<?php
function swarm_task_delete($params) {
    $db = swarm_db();
    $stmt = $db->prepare('DELETE FROM tasks WHERE uuid = :uuid');
    $stmt->bindValue(':uuid', $params['uuid'], SQLITE3_TEXT);
    $stmt->execute();
    return array('ok' => true);
}
