<?php
Q_Response::header('Content-Type: application/json');
$dbPath = dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/data/todos.db';
$dbDir = dirname($dbPath);
if (!is_dir($dbDir)) @mkdir($dbDir, 0755, true);
try {
    $db = new SQLite3($dbPath);
} catch (Exception $e) {
    echo json_encode(['error' => 'SQLite not available: ' . $e->getMessage()]);
    return;
}
$db->exec('CREATE TABLE IF NOT EXISTS todos (id INTEGER PRIMARY KEY AUTOINCREMENT, text TEXT NOT NULL, done INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];
switch ($method) {
    case 'GET':
        $stmt = $db->query('SELECT * FROM todos ORDER BY done ASC, id DESC');
        $rows = [];
        while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) { $row['done'] = (bool) $row['done']; $rows[] = $row; }
        echo json_encode($rows);
        break;
    case 'POST':
        $text = trim($input['text'] ?? '');
        if (!$text) { echo '{"error":"text required"}'; break; }
        $stmt = $db->prepare('INSERT INTO todos (text) VALUES (:text)');
        $stmt->bindValue(':text', $text, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['id' => $db->lastInsertRowID(), 'ok' => true]);
        break;
    case 'PUT':
        $id = (int) ($input['id'] ?? 0);
        $done = (int) ($input['done'] ?? 0);
        if (!$id) { echo '{"error":"id required"}'; break; }
        $stmt = $db->prepare('UPDATE todos SET done = :done WHERE id = :id');
        $stmt->bindValue(':done', $done, SQLITE3_INTEGER);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        echo json_encode(['ok' => true]);
        break;
    case 'DELETE':
        $id = (int) ($input['id'] ?? 0);
        if (!$id) { echo '{"error":"id required"}'; break; }
        $stmt = $db->prepare('DELETE FROM todos WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        echo json_encode(['ok' => true]);
        break;
    default:
        Q_Response::code(405);
        echo '{"error":"method not allowed"}';
}
