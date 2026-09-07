<?php
/**
 * Startup sync — runs when PEERS env is set.
 * Pulls tasks + messages from a peer, registers with them.
 */
$peersEnv = getenv('PEERS') ?: '';
if (!$peersEnv) return;

$peers = array_filter(array_map('trim', explode(',', $peersEnv)));
$myPort = $_SERVER['SERVER_PORT'] ?? getenv('PORT') ?: '4000';
$myUrl = 'http://localhost:' . $myPort;

$dataDir = __DIR__ . '/../data';
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
$db = new SQLite3($dataDir . '/swarm.db');
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('CREATE TABLE IF NOT EXISTS tasks (uuid TEXT PRIMARY KEY, text TEXT NOT NULL, done INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE IF NOT EXISTS messages (uuid TEXT PRIMARY KEY, name TEXT NOT NULL, text TEXT NOT NULL, server TEXT DEFAULT "", created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE IF NOT EXISTS peers (url TEXT PRIMARY KEY, last_seen TEXT DEFAULT CURRENT_TIMESTAMP)');

foreach ($peers as $peerUrl) {
    echo "Syncing from $peerUrl...\n";
    $json = @file_get_contents(rtrim($peerUrl, '/') . '/api.php?action=sync', false,
        stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]));
    if ($json) {
        $data = json_decode($json, true);
        if (!empty($data['tasks'])) {
            $stmt = $db->prepare('INSERT OR REPLACE INTO tasks (uuid, text, done, created_at, updated_at) VALUES (:uuid, :text, :done, :ca, :ua)');
            foreach ($data['tasks'] as $t) {
                $stmt->bindValue(':uuid', $t['uuid'], SQLITE3_TEXT);
                $stmt->bindValue(':text', $t['text'], SQLITE3_TEXT);
                $stmt->bindValue(':done', (int)$t['done'], SQLITE3_INTEGER);
                $stmt->bindValue(':ca', $t['created_at'] ?? date('Y-m-d H:i:s'), SQLITE3_TEXT);
                $stmt->bindValue(':ua', $t['updated_at'] ?? date('Y-m-d H:i:s'), SQLITE3_TEXT);
                $stmt->execute(); $stmt->reset();
            }
            echo "  Synced " . count($data['tasks']) . " tasks\n";
        }
        if (!empty($data['messages'])) {
            $stmt = $db->prepare('INSERT OR IGNORE INTO messages (uuid, name, text, server, created_at) VALUES (:uuid, :name, :text, :server, :ca)');
            foreach ($data['messages'] as $m) {
                $stmt->bindValue(':uuid', $m['uuid'], SQLITE3_TEXT);
                $stmt->bindValue(':name', $m['name'], SQLITE3_TEXT);
                $stmt->bindValue(':text', $m['text'], SQLITE3_TEXT);
                $stmt->bindValue(':server', $m['server'] ?? '', SQLITE3_TEXT);
                $stmt->bindValue(':ca', $m['created_at'] ?? date('Y-m-d H:i:s'), SQLITE3_TEXT);
                $stmt->execute(); $stmt->reset();
            }
            echo "  Synced " . count($data['messages']) . " messages\n";
        }
    }
    // Register with peer
    @file_get_contents(rtrim($peerUrl, '/') . '/api.php?action=join', false,
        stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode(['url' => $myUrl]), 'timeout' => 2]]));
    $stmt2 = $db->prepare('INSERT OR REPLACE INTO peers (url, last_seen) VALUES (:url, datetime("now"))');
    $stmt2->bindValue(':url', $peerUrl, SQLITE3_TEXT);
    $stmt2->execute();
    echo "  Registered with $peerUrl\n";
}
echo "Ready. " . (int)$db->querySingle('SELECT COUNT(*) FROM tasks') . " tasks, " . (int)$db->querySingle('SELECT COUNT(*) FROM messages') . " messages.\n";
