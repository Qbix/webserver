<?php
/**
 * Page view counter with SQLite persistence.
 * POST /count.php — increment and return count
 * GET  /count.php — return current count
 */
Q_Response::header('Content-Type: application/json');

$dbPath = dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/data/counter.db';
$dbDir = dirname($dbPath);
if (!is_dir($dbDir)) @mkdir($dbDir, 0755, true);

try {
    $db = new SQLite3($dbPath);
    $db->exec('CREATE TABLE IF NOT EXISTS counter (id INTEGER PRIMARY KEY, count INTEGER DEFAULT 0)');
    $db->exec('INSERT OR IGNORE INTO counter (id, count) VALUES (1, 0)');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $db->exec('UPDATE counter SET count = count + 1 WHERE id = 1');
    }

    $count = (int) $db->querySingle('SELECT count FROM counter WHERE id = 1');
    echo json_encode(['count' => $count]);
} catch (Exception $e) {
    // SQLite not available — use in-memory counter
    static $fallbackCount = 0;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') $fallbackCount++;
    echo json_encode(['count' => $fallbackCount, 'note' => 'in-memory (no SQLite)']);
}
