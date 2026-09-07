<?php
/**
 * Swarm API — distributed task list + chat using Q::event() and cluster replication.
 *
 * Events are handled by local PHP handler files (handlers/swarm/*.php)
 * and automatically replicated to peers by Q_WebServer_Cluster when
 * Q.cluster.replicate includes the event name.
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
 *   GET    /api.php?action=sync        — dump all data for new peer bootstrap
 *   POST   /api.php?action=join        — register as peer {url}
 *   GET    /api.php?action=peers       — list known peers
 *   GET    /api.php?action=info        — server identity
 */
Q_Response::header('Content-Type: application/json');
Q_Response::header('Access-Control-Allow-Origin: *');
Q_Response::header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
Q_Response::header('Access-Control-Allow-Headers: Content-Type, X-Forwarded-From, X-Q-Event');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ── Server identity ──
$myPort = $_SERVER['SERVER_PORT'] ?? getenv('PORT') ?: '4000';
$myUrl = 'http://localhost:' . $myPort;

// ── Register peers from PEERS env into config ──
$envPeers = getenv('PEERS') ?: '';
if ($envPeers) {
    foreach (array_filter(array_map('trim', explode(',', $envPeers))) as $p) {
        if ($p !== $myUrl) {
            $db = swarm_db();
            $stmt = $db->prepare('INSERT OR IGNORE INTO peers (url) VALUES (:url)');
            $stmt->bindValue(':url', $p, SQLITE3_TEXT);
            $stmt->execute();
        }
    }
}

// ── Configure cluster replication for swarm events ──
Q_Config::set('Q', 'cluster', 'replicate', array(
    'swarm/task_add', 'swarm/task_update', 'swarm/task_delete', 'swarm/message'
));

// ── Routing ──
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$isForwarded = !empty($_SERVER['HTTP_X_FORWARDED_FROM'])
    || !empty($_SERVER['HTTP_X_Q_EVENT']);

// ── Cluster actions ──
switch ($action) {
    case 'sync':
        $db = swarm_db();
        $tasks = []; $msgs = [];
        $s = $db->query('SELECT * FROM tasks ORDER BY created_at ASC');
        while ($r = $s->fetchArray(SQLITE3_ASSOC)) { $r['done'] = (bool)$r['done']; $tasks[] = $r; }
        $s = $db->query('SELECT * FROM messages ORDER BY created_at ASC');
        while ($r = $s->fetchArray(SQLITE3_ASSOC)) { $msgs[] = $r; }
        echo json_encode(['tasks' => $tasks, 'messages' => $msgs, 'source' => $myUrl]);
        return;

    case 'join':
        $peerUrl = trim($input['url'] ?? '');
        if (!$peerUrl || $peerUrl === $myUrl) { echo '{"error":"invalid"}'; return; }
        $db = swarm_db();
        $stmt = $db->prepare('INSERT OR REPLACE INTO peers (url, last_seen) VALUES (:url, datetime("now"))');
        $stmt->bindValue(':url', $peerUrl, SQLITE3_TEXT);
        $stmt->execute();
        // Add to cluster config dynamically
        if (class_exists('Q_WebServer_Cluster', false) && Q_WebServer_Cluster::isActive()) {
            Q_WebServer_Cluster::handleJoin(['url' => $peerUrl]);
        }
        echo json_encode(['ok' => true, 'registered' => $peerUrl]);
        return;

    case 'peers':
        echo json_encode(['peers' => swarm_peers($myUrl), 'self' => $myUrl]);
        return;

    case 'info':
        $db = swarm_db();
        echo json_encode([
            'url' => $myUrl, 'port' => $myPort,
            'tasks' => (int) $db->querySingle('SELECT COUNT(*) FROM tasks'),
            'messages' => (int) $db->querySingle('SELECT COUNT(*) FROM messages'),
            'peers' => count(swarm_peers($myUrl)),
        ]);
        return;

    case 'messages':
        $db = swarm_db();
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
        // Q::event() handles local save (handler) + cluster replication (automatic)
        $result = null;
        Q::event('swarm/message', [
            'uuid' => $uuid, 'name' => $name, 'text' => $text,
            'server' => $server, '_forwarded' => $isForwarded,
        ], false, false, $result);
        echo json_encode(['ok' => true, 'uuid' => $uuid]);
        return;

    case 'payment':
        // Chokepoint pattern: the sandbox forwards this to the authority
        // via handlersUsingRemote. The sandbox never sees the API key.
        // Note: this endpoint does NOT need the database — it's a
        // stateless operation that only the authority can process.
        $amount = (float) ($input['amount'] ?? 0);
        $currency = $input['currency'] ?? 'USD';
        $description = $input['description'] ?? '';
        if ($amount <= 0) { echo '{"error":"amount required"}'; return; }
        $result = null;
        Q::event('swarm/payment', [
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description,
        ], false, false, $result);
        echo json_encode($result ?: ['error' => 'no handler response']);
        return;

    case 'email':
        // SMTP credentials only on the authority — sandbox forwards
        $to = trim($input['to'] ?? '');
        $subject = trim($input['subject'] ?? '');
        $body = $input['body'] ?? '';
        if (!$to || !$subject) { echo '{"error":"to and subject required"}'; return; }
        $result = null;
        Q::event('swarm/email', [
            'to' => $to, 'subject' => $subject, 'body' => $body,
        ], false, false, $result);
        echo json_encode($result ?: ['error' => 'no handler response']);
        return;

    case 'oauth':
        // OAuth client_secret only on the authority — sandbox forwards
        $provider = $input['provider'] ?? 'google';
        $code = $input['code'] ?? '';
        $redirectUri = $input['redirectUri'] ?? '';
        if (!$code) { echo '{"error":"authorization code required"}'; return; }
        $result = null;
        Q::event('swarm/oauth', [
            'provider' => $provider, 'code' => $code,
            'redirectUri' => $redirectUri,
        ], false, false, $result);
        echo json_encode($result ?: ['error' => 'no handler response']);
        return;
}

// ── Task CRUD via Q::event() ──
switch ($method) {
    case 'GET':
        $db = swarm_db();
        $rows = [];
        $s = $db->query('SELECT * FROM tasks ORDER BY done ASC, created_at DESC');
        while ($r = $s->fetchArray(SQLITE3_ASSOC)) { $r['done'] = (bool)$r['done']; $rows[] = $r; }
        echo json_encode($rows);
        break;

    case 'POST':
        $text = trim($input['text'] ?? '');
        if (!$text) { echo '{"error":"text required"}'; return; }
        $uuid = $input['uuid'] ?? bin2hex(random_bytes(8));
        $result = null;
        Q::event('swarm/task_add', [
            'uuid' => $uuid, 'text' => $text, '_forwarded' => $isForwarded,
        ], false, false, $result);
        echo json_encode(['ok' => true, 'uuid' => $uuid]);
        break;

    case 'PUT':
        $uuid = $input['uuid'] ?? '';
        $done = (int) ($input['done'] ?? 0);
        if (!$uuid) { echo '{"error":"uuid required"}'; return; }
        $result = null;
        Q::event('swarm/task_update', [
            'uuid' => $uuid, 'done' => $done, '_forwarded' => $isForwarded,
        ], false, false, $result);
        echo json_encode(['ok' => true]);
        break;

    case 'DELETE':
        $uuid = $input['uuid'] ?? '';
        if (!$uuid) { echo '{"error":"uuid required"}'; return; }
        $result = null;
        Q::event('swarm/task_delete', [
            'uuid' => $uuid, '_forwarded' => $isForwarded,
        ], false, false, $result);
        echo json_encode(['ok' => true]);
        break;
}

// ── Helpers ──

function swarm_db() {
    static $db = null;
    if ($db) return $db;
    $dataDir = dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/data';
    if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
    $db = new SQLite3($dataDir . '/swarm.db');
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS tasks (uuid TEXT PRIMARY KEY, text TEXT NOT NULL, done INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $db->exec('CREATE TABLE IF NOT EXISTS messages (uuid TEXT PRIMARY KEY, name TEXT NOT NULL, text TEXT NOT NULL, server TEXT DEFAULT "", created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $db->exec('CREATE TABLE IF NOT EXISTS peers (url TEXT PRIMARY KEY, last_seen TEXT DEFAULT CURRENT_TIMESTAMP)');
    return $db;
}

function swarm_peers($selfUrl) {
    $db = swarm_db();
    $peers = [];
    $s = $db->query('SELECT url FROM peers ORDER BY url');
    while ($r = $s->fetchArray(SQLITE3_ASSOC)) {
        if ($r['url'] !== $selfUrl) $peers[] = $r['url'];
    }
    return $peers;
}
