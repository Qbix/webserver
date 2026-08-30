<?php
/**
 * Swarm API — self-healing distributed task list + chat.
 *
 * Each server has its own SQLite. Writes are forwarded to all known peers.
 * New servers sync state from any existing peer on startup.
 *
 * TASKS:
 *   GET    /api.php                    — list tasks
 *   POST   /api.php                    — create task {text}
 *   PUT    /api.php                    — update task {uuid, done}
 *   DELETE /api.php                    — delete task {uuid}
 *
 * CHAT:
 *   GET    /api.php?action=messages    — recent messages (last 100)
 *   POST   /api.php?action=message     — send message {name, text}
 *
 * CLUSTER:
 *   GET    /api.php?action=sync        — dump all data (tasks + messages)
 *   POST   /api.php?action=join        — register as peer {url}
 *   GET    /api.php?action=peers       — list known peers
 *   GET    /api.php?action=info        — server identity
 */
Q_Response::header('Content-Type: application/json');
Q_Response::header('Access-Control-Allow-Origin: *');
Q_Response::header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
Q_Response::header('Access-Control-Allow-Headers: Content-Type, X-Forwarded-From');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ── Database setup ──
$dataDir = dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/data';
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
$db = new SQLite3($dataDir . '/swarm.db');
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('CREATE TABLE IF NOT EXISTS tasks (
    uuid TEXT PRIMARY KEY,
    text TEXT NOT NULL,
    done INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)');
$db->exec('CREATE TABLE IF NOT EXISTS messages (
    uuid TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    text TEXT NOT NULL,
    server TEXT DEFAULT "",
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)');
$db->exec('CREATE TABLE IF NOT EXISTS peers (
    url TEXT PRIMARY KEY,
    last_seen TEXT DEFAULT CURRENT_TIMESTAMP
)');

// ── Server identity ──
$myPort = $_SERVER['SERVER_PORT'] ?? getenv('PORT') ?: '4000';
$myUrl = 'http://localhost:' . $myPort;

// ── Routing ──
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$isForwarded = !empty($_SERVER['HTTP_X_FORWARDED_FROM']);

// ── Cluster actions ──
switch ($action) {
    case 'sync':
        $tasks = [];
        $s = $db->query('SELECT * FROM tasks ORDER BY created_at ASC');
        while ($r = $s->fetchArray(SQLITE3_ASSOC)) { $r['done'] = (bool)$r['done']; $tasks[] = $r; }
        $msgs = [];
        $s = $db->query('SELECT * FROM messages ORDER BY created_at ASC');
        while ($r = $s->fetchArray(SQLITE3_ASSOC)) { $msgs[] = $r; }
        echo json_encode(['tasks' => $tasks, 'messages' => $msgs, 'source' => $myUrl]);
        return;

    case 'join':
        $peerUrl = trim($input['url'] ?? '');
        if (!$peerUrl || $peerUrl === $myUrl) { echo '{"error":"invalid"}'; return; }
        $stmt = $db->prepare('INSERT OR REPLACE INTO peers (url, last_seen) VALUES (:url, datetime("now"))');
        $stmt->bindValue(':url', $peerUrl, SQLITE3_TEXT);
        $stmt->execute();
        if (!$isForwarded) {
            foreach (getPeers($db, $myUrl) as $peer) {
                if ($peer === $peerUrl) continue;
                forwardTo($peer, 'POST', '?action=join', ['url' => $peerUrl], $myUrl);
            }
        }
        echo json_encode(['ok' => true, 'registered' => $peerUrl]);
        return;

    case 'peers':
        echo json_encode(['peers' => getPeers($db, $myUrl), 'self' => $myUrl]);
        return;

    case 'info':
        echo json_encode([
            'url' => $myUrl,
            'port' => $myPort,
            'tasks' => (int) $db->querySingle('SELECT COUNT(*) FROM tasks'),
            'messages' => (int) $db->querySingle('SELECT COUNT(*) FROM messages'),
            'peers' => count(getPeers($db, $myUrl)),
        ]);
        return;

    case 'messages':
        $rows = [];
        $s = $db->query('SELECT * FROM messages ORDER BY created_at DESC LIMIT 100');
        while ($r = $s->fetchArray(SQLITE3_ASSOC)) { $rows[] = $r; }
        echo json_encode(array_reverse($rows));
        return;

    case 'message':
        $name = trim($input['name'] ?? '');
        $text = trim($input['text'] ?? '');
        if (!$name || !$text) { echo '{"error":"name and text required"}'; return; }
        $uuid = $input['uuid'] ?? bin2hex(random_bytes(8));
        $server = $input['server'] ?? $myUrl;
        $stmt = $db->prepare('INSERT OR IGNORE INTO messages (uuid, name, text, server, created_at) VALUES (:uuid, :name, :text, :server, datetime("now"))');
        $stmt->bindValue(':uuid', $uuid, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':text', $text, SQLITE3_TEXT);
        $stmt->bindValue(':server', $server, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['ok' => true, 'uuid' => $uuid]);
        if (!$isForwarded) {
            foreach (getPeers($db, $myUrl) as $peer) {
                forwardTo($peer, 'POST', '?action=message', ['uuid'=>$uuid,'name'=>$name,'text'=>$text,'server'=>$server], $myUrl);
            }
        }
        return;
}

// ── Task CRUD ──
switch ($method) {
    case 'GET':
        $rows = [];
        $s = $db->query('SELECT * FROM tasks ORDER BY done ASC, created_at DESC');
        while ($r = $s->fetchArray(SQLITE3_ASSOC)) { $r['done'] = (bool)$r['done']; $rows[] = $r; }
        echo json_encode($rows);
        break;

    case 'POST':
        $text = trim($input['text'] ?? '');
        if (!$text) { echo '{"error":"text required"}'; return; }
        $uuid = $input['uuid'] ?? bin2hex(random_bytes(8));
        $stmt = $db->prepare('INSERT OR IGNORE INTO tasks (uuid, text) VALUES (:uuid, :text)');
        $stmt->bindValue(':uuid', $uuid, SQLITE3_TEXT);
        $stmt->bindValue(':text', $text, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['ok' => true, 'uuid' => $uuid]);
        if (!$isForwarded) {
            foreach (getPeers($db, $myUrl) as $peer) {
                forwardTo($peer, 'POST', '', ['uuid'=>$uuid,'text'=>$text], $myUrl);
            }
        }
        break;

    case 'PUT':
        $uuid = $input['uuid'] ?? '';
        $done = (int)($input['done'] ?? 0);
        if (!$uuid) { echo '{"error":"uuid required"}'; return; }
        $stmt = $db->prepare('UPDATE tasks SET done = :done, updated_at = datetime("now") WHERE uuid = :uuid');
        $stmt->bindValue(':done', $done, SQLITE3_INTEGER);
        $stmt->bindValue(':uuid', $uuid, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['ok' => true]);
        if (!$isForwarded) {
            foreach (getPeers($db, $myUrl) as $peer) {
                forwardTo($peer, 'PUT', '', ['uuid'=>$uuid,'done'=>$done], $myUrl);
            }
        }
        break;

    case 'DELETE':
        $uuid = $input['uuid'] ?? '';
        if (!$uuid) { echo '{"error":"uuid required"}'; return; }
        $stmt = $db->prepare('DELETE FROM tasks WHERE uuid = :uuid');
        $stmt->bindValue(':uuid', $uuid, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['ok' => true]);
        if (!$isForwarded) {
            foreach (getPeers($db, $myUrl) as $peer) {
                forwardTo($peer, 'DELETE', '', ['uuid'=>$uuid], $myUrl);
            }
        }
        break;
}

function getPeers($db, $selfUrl) {
    $peers = [];
    $s = $db->query('SELECT url FROM peers ORDER BY url');
    while ($r = $s->fetchArray(SQLITE3_ASSOC)) {
        if ($r['url'] !== $selfUrl) $peers[] = $r['url'];
    }
    return $peers;
}

function forwardTo($peerUrl, $method, $qs, $body, $fromUrl) {
    $url = rtrim($peerUrl, '/') . '/api.php' . $qs;
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => "Content-Type: application/json\r\nX-Forwarded-From: $fromUrl\r\n",
        'content' => json_encode($body),
        'timeout' => 2,
        'ignore_errors' => true,
    ]]);
    @file_get_contents($url, false, $ctx);
}
