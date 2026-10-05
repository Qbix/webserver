<?php
/**
 * SQLite database layer for the Qbix relay system.
 *
 * Manages local/relay.db with tables for threads, messages, inbox,
 * digest batching, delivery logging, and rate limiting.
 *
 * Config (Q.relay.db):
 *   path: "local/relay.db"
 */
class Q_Relay_Db
{
	private static $db = null;
	private static $initialized = false;

	/**
	 * Open or create the relay database and run the schema.
	 * @param string|null $dbPath Override path (default from config or local/relay.db)
	 */
	static function init($dbPath = null)
	{
		if (self::$initialized) return;
		self::$initialized = true;

		if (!$dbPath) {
			$dbPath = Q_Config::get('Q', 'relay', 'db', 'path', qbix_data_path('local/relay.db'));
		}
		@mkdir(dirname($dbPath), 0755, true);

		try {
			self::$db = new \SQLite3($dbPath);
			self::$db->busyTimeout(2000);
			self::$db->exec('PRAGMA journal_mode=WAL');

			// ── Schema ──

			self::$db->exec('CREATE TABLE IF NOT EXISTS threads (
				thread_id       TEXT PRIMARY KEY,
				root_message_id TEXT,
				channel         TEXT NOT NULL,
				subject         TEXT,
				created_at      INTEGER NOT NULL,
				updated_at      INTEGER NOT NULL
			)');

			self::$db->exec('CREATE TABLE IF NOT EXISTS thread_members (
				thread_id   TEXT NOT NULL,
				address     TEXT NOT NULL,
				username    TEXT,
				added_at    INTEGER NOT NULL,
				role        TEXT DEFAULT \'participant\',
				PRIMARY KEY (thread_id, address)
			)');

			self::$db->exec('CREATE TABLE IF NOT EXISTS thread_messages (
				id          INTEGER PRIMARY KEY AUTOINCREMENT,
				thread_id   TEXT NOT NULL,
				message_id  TEXT,
				channel     TEXT NOT NULL,
				direction   TEXT NOT NULL,
				from_addr   TEXT NOT NULL,
				to_addr     TEXT,
				subject     TEXT,
				body_text   TEXT,
				body_html   TEXT,
				raw         BLOB,
				received_at INTEGER NOT NULL
			)');

			self::$db->exec('CREATE TABLE IF NOT EXISTS inbox (
				id          INTEGER PRIMARY KEY AUTOINCREMENT,
				username    TEXT NOT NULL,
				thread_id   TEXT NOT NULL,
				message_id  TEXT,
				channel     TEXT NOT NULL,
				from_addr   TEXT NOT NULL,
				subject     TEXT,
				body_text   TEXT,
				received_at INTEGER NOT NULL,
				read_at     INTEGER,
				flags       TEXT DEFAULT \'{}\',
				ai_labels   TEXT
			)');

			self::$db->exec('CREATE TABLE IF NOT EXISTS digest_pending (
				id          INTEGER PRIMARY KEY AUTOINCREMENT,
				recipient   TEXT NOT NULL,
				mail_from   TEXT NOT NULL,
				raw_message BLOB NOT NULL,
				received_at INTEGER NOT NULL
			)');

			self::$db->exec('CREATE TABLE IF NOT EXISTS digest_state (
				recipient       TEXT NOT NULL,
				mail_from       TEXT NOT NULL,
				next_delay_ms   INTEGER DEFAULT 30000,
				last_received_at INTEGER,
				msg_count       INTEGER DEFAULT 0,
				PRIMARY KEY (recipient, mail_from)
			)');

			self::$db->exec('CREATE TABLE IF NOT EXISTS delivery_log (
				id          INTEGER PRIMARY KEY AUTOINCREMENT,
				channel     TEXT NOT NULL,
				direction   TEXT NOT NULL,
				from_addr   TEXT NOT NULL,
				to_addr     TEXT NOT NULL,
				subject     TEXT,
				message_id  TEXT,
				bytes       INTEGER,
				status      TEXT NOT NULL,
				error       TEXT,
				provider    TEXT,
				app_host    TEXT,
				timestamp   INTEGER NOT NULL
			)');

			self::$db->exec('CREATE TABLE IF NOT EXISTS rate_state (
				key             TEXT PRIMARY KEY,
				tokens          REAL NOT NULL,
				last_refill     INTEGER NOT NULL,
				hour_window     TEXT
			)');

			// Email tracking: opens and clicks
			self::$db->exec('CREATE TABLE IF NOT EXISTS email_tracking (
				id              INTEGER PRIMARY KEY AUTOINCREMENT,
				tracking_id     TEXT NOT NULL UNIQUE,
				message_id      TEXT,
				from_addr       TEXT,
				to_addr         TEXT,
				subject         TEXT,
				template        TEXT,
				app_host        TEXT,
				created_at      INTEGER NOT NULL
			)');

			self::$db->exec('CREATE TABLE IF NOT EXISTS email_events (
				id              INTEGER PRIMARY KEY AUTOINCREMENT,
				tracking_id     TEXT NOT NULL,
				event_type      TEXT NOT NULL,
				url             TEXT,
				ip              TEXT,
				user_agent      TEXT,
				timestamp       INTEGER NOT NULL
			)');

			// ── Indexes ──

			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_thread_messages_thread ON thread_messages(thread_id)');
			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_inbox_user_time ON inbox(username, received_at)');
			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_delivery_log_ts ON delivery_log(timestamp)');
			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_delivery_log_chan_status ON delivery_log(channel, status)');
			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_email_tracking_mid ON email_tracking(message_id)');
			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_email_events_tid ON email_events(tracking_id)');
			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_email_events_ts ON email_events(timestamp)');
		} catch (\Exception $e) {
			self::$db = null;
		}
	}

	/**
	 * Return the SQLite3 instance. Calls init() if needed.
	 * @return \SQLite3|null
	 */
	static function db()
	{
		if (!self::$initialized) {
			self::init();
		}
		return self::$db;
	}

	// ── Delivery log ──

	/**
	 * Insert a row into delivery_log.
	 */
	static function logDelivery($channel, $direction, $from, $to, $subject,
		$messageId, $bytes, $status, $error = null, $provider = null, $appHost = null)
	{
		$db = self::db();
		if (!$db) return;
		try {
			$stmt = $db->prepare(
				'INSERT INTO delivery_log
					(channel, direction, from_addr, to_addr, subject, message_id, bytes, status, error, provider, app_host, timestamp)
				VALUES (:channel, :direction, :from, :to, :subject, :mid, :bytes, :status, :error, :provider, :host, :ts)'
			);
			$stmt->bindValue(':channel', $channel);
			$stmt->bindValue(':direction', $direction);
			$stmt->bindValue(':from', $from);
			$stmt->bindValue(':to', $to);
			$stmt->bindValue(':subject', $subject);
			$stmt->bindValue(':mid', $messageId);
			$stmt->bindValue(':bytes', $bytes, SQLITE3_INTEGER);
			$stmt->bindValue(':status', $status);
			$stmt->bindValue(':error', $error);
			$stmt->bindValue(':provider', $provider);
			$stmt->bindValue(':host', $appHost);
			$stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
			$stmt->execute();
		} catch (\Exception $e) {
			// best-effort
		}
	}

	// ── Thread messages ──

	/**
	 * Insert a message into thread_messages and bump the thread's updated_at.
	 */
	static function storeMessage($threadId, $messageId, $channel, $direction,
		$from, $to, $subject, $bodyText, $bodyHtml, $raw)
	{
		$db = self::db();
		if (!$db) return;
		$now = time();
		try {
			$stmt = $db->prepare(
				'INSERT INTO thread_messages
					(thread_id, message_id, channel, direction, from_addr, to_addr, subject, body_text, body_html, raw, received_at)
				VALUES (:tid, :mid, :chan, :dir, :from, :to, :subj, :text, :html, :raw, :ts)'
			);
			$stmt->bindValue(':tid', $threadId);
			$stmt->bindValue(':mid', $messageId);
			$stmt->bindValue(':chan', $channel);
			$stmt->bindValue(':dir', $direction);
			$stmt->bindValue(':from', $from);
			$stmt->bindValue(':to', $to);
			$stmt->bindValue(':subj', $subject);
			$stmt->bindValue(':text', $bodyText);
			$stmt->bindValue(':html', $bodyHtml);
			$stmt->bindValue(':raw', $raw, SQLITE3_BLOB);
			$stmt->bindValue(':ts', $now, SQLITE3_INTEGER);
			$stmt->execute();

			$upd = $db->prepare('UPDATE threads SET updated_at = :ts WHERE thread_id = :tid');
			$upd->bindValue(':ts', $now, SQLITE3_INTEGER);
			$upd->bindValue(':tid', $threadId);
			$upd->execute();
		} catch (\Exception $e) {
			// best-effort
		}
	}

	// ── Threads ──

	/**
	 * Find or create a thread. If the thread already exists, update its
	 * updated_at timestamp. Returns the thread_id.
	 */
	static function findOrCreateThread($threadId, $channel, $subject, $rootMessageId = null)
	{
		$db = self::db();
		if (!$db) return $threadId;
		$now = time();
		try {
			$ins = $db->prepare(
				'INSERT OR IGNORE INTO threads (thread_id, root_message_id, channel, subject, created_at, updated_at)
				VALUES (:tid, :root, :chan, :subj, :now, :now)'
			);
			$ins->bindValue(':tid', $threadId);
			$ins->bindValue(':root', $rootMessageId);
			$ins->bindValue(':chan', $channel);
			$ins->bindValue(':subj', $subject);
			$ins->bindValue(':now', $now, SQLITE3_INTEGER);
			$ins->execute();
			$upd = $db->prepare(
				'UPDATE threads SET updated_at = :now WHERE thread_id = :tid'
			);
			$upd->bindValue(':now', $now, SQLITE3_INTEGER);
			$upd->bindValue(':tid', $threadId);
			$upd->execute();
		} catch (\Exception $e) {
			// best-effort
		}
		return $threadId;
	}

	// ── Thread members ──

	/**
	 * Add a member to a thread. Silently ignores duplicates.
	 */
	static function addThreadMember($threadId, $address, $username = null)
	{
		$db = self::db();
		if (!$db) return;
		try {
			$stmt = $db->prepare(
				'INSERT OR IGNORE INTO thread_members (thread_id, address, username, added_at)
				VALUES (:tid, :addr, :user, :ts)'
			);
			$stmt->bindValue(':tid', $threadId);
			$stmt->bindValue(':addr', $address);
			$stmt->bindValue(':user', $username);
			$stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
			$stmt->execute();
		} catch (\Exception $e) {
			// best-effort
		}
	}

	// ── Inbox ──

	/**
	 * Insert a message into a user's inbox.
	 */
	static function storeInbox($username, $threadId, $messageId, $channel,
		$from, $subject, $bodyText)
	{
		$db = self::db();
		if (!$db) return;
		try {
			$stmt = $db->prepare(
				'INSERT INTO inbox
					(username, thread_id, message_id, channel, from_addr, subject, body_text, received_at)
				VALUES (:user, :tid, :mid, :chan, :from, :subj, :text, :ts)'
			);
			$stmt->bindValue(':user', $username);
			$stmt->bindValue(':tid', $threadId);
			$stmt->bindValue(':mid', $messageId);
			$stmt->bindValue(':chan', $channel);
			$stmt->bindValue(':from', $from);
			$stmt->bindValue(':subj', $subject);
			$stmt->bindValue(':text', $bodyText);
			$stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
			$stmt->execute();
		} catch (\Exception $e) {
			// best-effort
		}
	}

	// ── Digest state ──

	/**
	 * Read digest state for a recipient+sender pair.
	 * @return array|null {next_delay_ms, last_received_at, msg_count}
	 */
	static function getDigestState($recipient, $mailFrom)
	{
		$db = self::db();
		if (!$db) return null;
		try {
			$stmt = $db->prepare(
				'SELECT next_delay_ms, last_received_at, msg_count
				FROM digest_state WHERE recipient = :r AND mail_from = :f'
			);
			$stmt->bindValue(':r', $recipient);
			$stmt->bindValue(':f', $mailFrom);
			$result = $stmt->execute();
			$row = $result->fetchArray(SQLITE3_ASSOC);
			return $row ?: null;
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Upsert digest state for a recipient+sender pair.
	 */
	static function setDigestState($recipient, $mailFrom, $nextDelayMs, $msgCount)
	{
		$db = self::db();
		if (!$db) return;
		try {
			$ts = time();
			$ins = $db->prepare(
				'INSERT OR IGNORE INTO digest_state (recipient, mail_from, next_delay_ms, last_received_at, msg_count)
				VALUES (:r, :f, :delay, :ts, :cnt)'
			);
			$ins->bindValue(':r', $recipient);
			$ins->bindValue(':f', $mailFrom);
			$ins->bindValue(':delay', $nextDelayMs, SQLITE3_INTEGER);
			$ins->bindValue(':ts', $ts, SQLITE3_INTEGER);
			$ins->bindValue(':cnt', $msgCount, SQLITE3_INTEGER);
			$ins->execute();
			$upd = $db->prepare(
				'UPDATE digest_state SET next_delay_ms = :delay, last_received_at = :ts, msg_count = :cnt
				WHERE recipient = :r AND mail_from = :f'
			);
			$upd->bindValue(':r', $recipient);
			$upd->bindValue(':f', $mailFrom);
			$upd->bindValue(':delay', $nextDelayMs, SQLITE3_INTEGER);
			$upd->bindValue(':ts', $ts, SQLITE3_INTEGER);
			$upd->bindValue(':cnt', $msgCount, SQLITE3_INTEGER);
			$upd->execute();
		} catch (\Exception $e) {
			// best-effort
		}
	}

	// ── Digest pending ──

	/**
	 * Queue a raw message for digest batching.
	 */
	static function addDigestPending($recipient, $mailFrom, $rawMessage)
	{
		$db = self::db();
		if (!$db) return;
		try {
			$stmt = $db->prepare(
				'INSERT INTO digest_pending (recipient, mail_from, raw_message, received_at)
				VALUES (:r, :f, :raw, :ts)'
			);
			$stmt->bindValue(':r', $recipient);
			$stmt->bindValue(':f', $mailFrom);
			$stmt->bindValue(':raw', $rawMessage, SQLITE3_BLOB);
			$stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
			$stmt->execute();
		} catch (\Exception $e) {
			// best-effort
		}
	}

	/**
	 * Get all pending digest messages for a recipient+sender pair.
	 * @return array Rows with {id, raw_message, received_at}
	 */
	static function getDigestPending($recipient, $mailFrom)
	{
		$db = self::db();
		if (!$db) return [];
		try {
			$stmt = $db->prepare(
				'SELECT id, raw_message, received_at FROM digest_pending
				WHERE recipient = :r AND mail_from = :f
				ORDER BY received_at ASC'
			);
			$stmt->bindValue(':r', $recipient);
			$stmt->bindValue(':f', $mailFrom);
			$result = $stmt->execute();
			$rows = [];
			while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
				$rows[] = $row;
			}
			return $rows;
		} catch (\Exception $e) {
			return [];
		}
	}

	/**
	 * Delete all pending digest messages for a recipient+sender pair.
	 */
	static function clearDigestPending($recipient, $mailFrom)
	{
		$db = self::db();
		if (!$db) return;
		try {
			$stmt = $db->prepare(
				'DELETE FROM digest_pending WHERE recipient = :r AND mail_from = :f'
			);
			$stmt->bindValue(':r', $recipient);
			$stmt->bindValue(':f', $mailFrom);
			$stmt->execute();
		} catch (\Exception $e) {
			// best-effort
		}
	}

	// ── Rate state ──

	/**
	 * Read rate-limiter state for a key.
	 * @return array|null {tokens, last_refill, hour_window}
	 */
	static function getRateState($key)
	{
		$db = self::db();
		if (!$db) return null;
		try {
			$stmt = $db->prepare(
				'SELECT tokens, last_refill, hour_window FROM rate_state WHERE key = :k'
			);
			$stmt->bindValue(':k', $key);
			$result = $stmt->execute();
			$row = $result->fetchArray(SQLITE3_ASSOC);
			return $row ?: null;
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Upsert rate-limiter state for a key.
	 */
	static function setRateState($key, $tokens, $lastRefill, $hourWindow)
	{
		$db = self::db();
		if (!$db) return;
		try {
			$ins = $db->prepare(
				'INSERT OR IGNORE INTO rate_state (key, tokens, last_refill, hour_window)
				VALUES (:k, :t, :r, :h)'
			);
			$ins->bindValue(':k', $key);
			$ins->bindValue(':t', $tokens);
			$ins->bindValue(':r', $lastRefill, SQLITE3_INTEGER);
			$ins->bindValue(':h', $hourWindow);
			$ins->execute();
			$upd = $db->prepare(
				'UPDATE rate_state SET tokens = :t, last_refill = :r, hour_window = :h
				WHERE key = :k'
			);
			$upd->bindValue(':k', $key);
			$upd->bindValue(':t', $tokens);
			$upd->bindValue(':r', $lastRefill, SQLITE3_INTEGER);
			$upd->bindValue(':h', $hourWindow);
			$upd->execute();
		} catch (\Exception $e) {
			// best-effort
		}
	}

	// ── Email tracking ──

	/**
	 * Create an email tracking record. Returns the tracking ID.
	 */
	static function createTracking($messageId, $from, $to, $subject,
		$template = null, $appHost = null)
	{
		$db = self::db();
		if (!$db) return null;
		$trackingId = bin2hex(random_bytes(16));
		try {
			$stmt = $db->prepare(
				'INSERT INTO email_tracking
					(tracking_id, message_id, from_addr, to_addr, subject, template, app_host, created_at)
				VALUES (:tid, :mid, :from, :to, :subj, :tpl, :host, :ts)'
			);
			$stmt->bindValue(':tid', $trackingId);
			$stmt->bindValue(':mid', $messageId);
			$stmt->bindValue(':from', $from);
			$stmt->bindValue(':to', $to);
			$stmt->bindValue(':subj', $subject);
			$stmt->bindValue(':tpl', $template);
			$stmt->bindValue(':host', $appHost);
			$stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
			$stmt->execute();
			return $trackingId;
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Log an email tracking event (open or click).
	 */
	static function logEmailEvent($trackingId, $eventType, $url = null,
		$ip = null, $userAgent = null)
	{
		$db = self::db();
		if (!$db) return;
		try {
			$stmt = $db->prepare(
				'INSERT INTO email_events
					(tracking_id, event_type, url, ip, user_agent, timestamp)
				VALUES (:tid, :type, :url, :ip, :ua, :ts)'
			);
			$stmt->bindValue(':tid', $trackingId);
			$stmt->bindValue(':type', $eventType);
			$stmt->bindValue(':url', $url);
			$stmt->bindValue(':ip', $ip);
			$stmt->bindValue(':ua', $userAgent);
			$stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
			$stmt->execute();
		} catch (\Exception $e) {
			// best-effort
		}
	}

	/**
	 * Get email tracking record by tracking ID.
	 * @return array|null
	 */
	static function getTracking($trackingId)
	{
		$db = self::db();
		if (!$db) return null;
		try {
			$stmt = $db->prepare(
				'SELECT * FROM email_tracking WHERE tracking_id = :tid'
			);
			$stmt->bindValue(':tid', $trackingId);
			$result = $stmt->execute();
			return $result->fetchArray(SQLITE3_ASSOC) ?: null;
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Get email tracking events for a tracking ID.
	 * @return array
	 */
	static function getTrackingEvents($trackingId)
	{
		$db = self::db();
		if (!$db) return [];
		try {
			$stmt = $db->prepare(
				'SELECT * FROM email_events WHERE tracking_id = :tid ORDER BY timestamp ASC'
			);
			$stmt->bindValue(':tid', $trackingId);
			$result = $stmt->execute();
			$rows = [];
			while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
				$rows[] = $row;
			}
			return $rows;
		} catch (\Exception $e) {
			return [];
		}
	}

	/**
	 * Get email flow data for Sankey diagrams.
	 * Returns: {nodes, links} showing template → opened → clicked → next_template
	 * @param string|null $since  ISO date string for time filter
	 * @return array {nodes, links}
	 */
	static function emailFlowData($since = null)
	{
		$db = self::db();
		if (!$db) return array('nodes' => array(), 'links' => array());
		try {
			$sinceTs = $since ? strtotime($since) : strtotime('-30 days');

			// Get all tracking records with their events
			$stmt = $db->prepare(
				'SELECT t.tracking_id, t.template, t.to_addr, t.subject,
					e.event_type, e.url, e.timestamp as event_ts
				FROM email_tracking t
				LEFT JOIN email_events e ON t.tracking_id = e.tracking_id
				WHERE t.created_at >= :since
				ORDER BY t.tracking_id, e.timestamp'
			);
			$stmt->bindValue(':since', $sinceTs, SQLITE3_INTEGER);
			$result = $stmt->execute();

			$trackingData = array(); // tid => {template, events: [{type, url}]}
			while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
				$tid = $row['tracking_id'];
				if (!isset($trackingData[$tid])) {
					$trackingData[$tid] = array(
						'template' => $row['template'] ?: $row['subject'] ?: 'unknown',
						'events' => array()
					);
				}
				if ($row['event_type']) {
					$trackingData[$tid]['events'][] = array(
						'type' => $row['event_type'],
						'url' => $row['url']
					);
				}
			}

			// Build Sankey: template_sent → opened → clicked_link → ...
			$nodeCounts = array();
			$linkCounts = array();

			foreach ($trackingData as $data) {
				$tpl = 'sent:' . $data['template'];
				$nodeCounts[$tpl] = ($nodeCounts[$tpl] ?? 0) + 1;

				$hasOpen = false;
				$hasClick = false;
				foreach ($data['events'] as $ev) {
					if ($ev['type'] === 'open') $hasOpen = true;
					if ($ev['type'] === 'click') $hasClick = true;
				}

				if ($hasOpen) {
					$openNode = 'opened:' . $data['template'];
					$nodeCounts[$openNode] = ($nodeCounts[$openNode] ?? 0) + 1;
					$key = $tpl . "\t" . $openNode;
					$linkCounts[$key] = ($linkCounts[$key] ?? 0) + 1;

					if ($hasClick) {
						// Find clicked URLs
						foreach ($data['events'] as $ev) {
							if ($ev['type'] === 'click' && $ev['url']) {
								$clickNode = 'clicked:' . parse_url($ev['url'], PHP_URL_PATH);
								$nodeCounts[$clickNode] = ($nodeCounts[$clickNode] ?? 0) + 1;
								$key2 = $openNode . "\t" . $clickNode;
								$linkCounts[$key2] = ($linkCounts[$key2] ?? 0) + 1;
							}
						}
					} else {
						$noClick = 'no-click:' . $data['template'];
						$nodeCounts[$noClick] = ($nodeCounts[$noClick] ?? 0) + 1;
						$key = $openNode . "\t" . $noClick;
						$linkCounts[$key] = ($linkCounts[$key] ?? 0) + 1;
					}
				} else {
					$noOpen = 'not-opened:' . $data['template'];
					$nodeCounts[$noOpen] = ($nodeCounts[$noOpen] ?? 0) + 1;
					$key = $tpl . "\t" . $noOpen;
					$linkCounts[$key] = ($linkCounts[$key] ?? 0) + 1;
				}
			}

			$nodes = array();
			foreach ($nodeCounts as $id => $count) {
				$nodes[] = array('id' => $id, 'name' => $id, 'value' => $count);
			}

			$links = array();
			foreach ($linkCounts as $key => $count) {
				list($src, $tgt) = explode("\t", $key);
				$links[] = array('source' => $src, 'target' => $tgt, 'value' => $count);
			}

			return array('nodes' => $nodes, 'links' => $links);
		} catch (\Exception $e) {
			return array('nodes' => array(), 'links' => array());
		}
	}

	/**
	 * Email tracking summary stats.
	 * @return array {total, opened, clicked, openRate, clickRate}
	 */
	static function emailTrackingSummary($since = null)
	{
		$db = self::db();
		if (!$db) return array('total' => 0, 'opened' => 0, 'clicked' => 0);
		try {
			$sinceTs = $since ? strtotime($since) : strtotime('-30 days');

			$stmt = $db->prepare('SELECT COUNT(*) as c FROM email_tracking WHERE created_at >= :s');
			$stmt->bindValue(':s', $sinceTs, SQLITE3_INTEGER);
			$total = $stmt->execute()->fetchArray()['c'];

			$stmt2 = $db->prepare(
				'SELECT COUNT(DISTINCT tracking_id) as c FROM email_events
				WHERE event_type = \'open\' AND timestamp >= :s'
			);
			$stmt2->bindValue(':s', $sinceTs, SQLITE3_INTEGER);
			$opened = $stmt2->execute()->fetchArray()['c'];

			$stmt3 = $db->prepare(
				'SELECT COUNT(DISTINCT tracking_id) as c FROM email_events
				WHERE event_type = \'click\' AND timestamp >= :s'
			);
			$stmt3->bindValue(':s', $sinceTs, SQLITE3_INTEGER);
			$clicked = $stmt3->execute()->fetchArray()['c'];

			return array(
				'total' => $total,
				'opened' => $opened,
				'clicked' => $clicked,
				'openRate' => $total > 0 ? round($opened / $total * 100, 1) : 0,
				'clickRate' => $total > 0 ? round($clicked / $total * 100, 1) : 0
			);
		} catch (\Exception $e) {
			return array('total' => 0, 'opened' => 0, 'clicked' => 0);
		}
	}
}
