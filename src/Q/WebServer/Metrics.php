<?php
/**
 * Server-side operational metrics and buffered logging.
 *
 * Metrics: per-minute time-series in SQLite (requests, latency percentiles,
 * status codes, worker stats, memory). Queryable from dashboard and
 * exportable as Prometheus gauges.
 *
 * Logs: buffered in memory, flushed to disk periodically. Rotated daily,
 * old logs compressed to zip, kept up to N days.
 *
 * Config (Q.webserver.metrics):
 *   enabled: true (default)
 *   db: "local/metrics.db"
 *   retainDays: 30
 *   flushInterval: 10 (seconds)
 *   logBufferSize: 500 (lines before forced flush)
 *   logRetainDays: 7
 *   logCompress: true
 *   accessLog: "local/logs/access.log"
 *   errorLog: "local/logs/error.log"
 */
class Q_WebServer_Metrics
{
	// ── In-memory buffers ──
	private static $accessBuffer = [];
	private static $errorBuffer = [];
	private static $requestTimes = [];  // [ms] for current minute
	private static $statusCodes = [];   // [bucket => count] for current minute
	private static $currentMinute = '';
	private static $db = null;
	private static $initialized = false;

	// ── Session & clickstream (in-memory, flushed to SQLite) ──
	private static $sessions = [];       // cookieHash => {lastPath, lastTime}
	private static $flowBuffer = [];     // [{from, to}] accumulated transitions
	private static $pageHits = [];       // path => {hits, sessions:{}, totalMs}
	private static $prevRps = [];        // last 5 minutes RPS for anomaly detection
	private static $requestBuffer = [];  // [{ts, sessionId, path, prevPath, status, durationMs, ip, host, platform, browser, lang}]
	private static $sessionUpdates = []; // sessionId => {firstSeen, lastSeen, ip, host, platform, browser, lang, pageCount, entryPath}

	static function enabled()
	{
		return Q_Config::get('Q', 'webserver', 'metrics', 'enabled', true);
	}

	/**
	 * Initialize metrics: open DB, set up flush timer.
	 * Called once from the server event loop.
	 */
	static function init()
	{
		if (self::$initialized || !self::enabled()) return;
		self::$initialized = true;
		self::$currentMinute = date('Y-m-d H:i');

		// Ensure log directories exist
		$accessLog = self::accessLogPath();
		$errorLog = self::errorLogPath();
		@mkdir(dirname($accessLog), 0755, true);
		@mkdir(dirname($errorLog), 0755, true);

		// Open SQLite
		$dbPath = Q_Config::get('Q', 'webserver', 'metrics', 'db', qbix_data_path('local/metrics.db'));
		@mkdir(dirname($dbPath), 0755, true);
		try {
			self::$db = new \SQLite3($dbPath);
			self::$db->busyTimeout(1000);
			self::$db->exec('PRAGMA journal_mode=WAL');
			self::$db->exec('CREATE TABLE IF NOT EXISTS minute_stats (
				ts TEXT PRIMARY KEY,
				requests INTEGER DEFAULT 0,
				p50_ms REAL DEFAULT 0,
				p95_ms REAL DEFAULT 0,
				p99_ms REAL DEFAULT 0,
				avg_ms REAL DEFAULT 0,
				status_2xx INTEGER DEFAULT 0,
				status_3xx INTEGER DEFAULT 0,
				status_4xx INTEGER DEFAULT 0,
				status_5xx INTEGER DEFAULT 0,
				workers INTEGER DEFAULT 0,
				memory_mb REAL DEFAULT 0
			)');
			// Clickstream: page transitions (from → to with count)
			self::$db->exec('CREATE TABLE IF NOT EXISTS flow (
				from_path TEXT NOT NULL,
				to_path TEXT NOT NULL,
				count INTEGER DEFAULT 1,
				PRIMARY KEY (from_path, to_path)
			)');
			// Per-path stats
			self::$db->exec('CREATE TABLE IF NOT EXISTS page_stats (
				path TEXT PRIMARY KEY,
				hits INTEGER DEFAULT 0,
				unique_sessions INTEGER DEFAULT 0,
				avg_ms REAL DEFAULT 0,
				last_hit TEXT
			)');
			// Individual request log for analytics
			self::$db->exec('CREATE TABLE IF NOT EXISTS requests (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				ts INTEGER NOT NULL,
				session_id TEXT NOT NULL,
				path TEXT NOT NULL,
				prev_path TEXT,
				status INTEGER,
				duration_ms REAL,
				ip TEXT,
				host TEXT,
				platform TEXT,
				browser TEXT,
				language TEXT
			)');
			// Session summary table
			self::$db->exec('CREATE TABLE IF NOT EXISTS sessions (
				session_id TEXT PRIMARY KEY,
				first_seen INTEGER NOT NULL,
				last_seen INTEGER NOT NULL,
				ip TEXT,
				host TEXT,
				platform TEXT,
				browser TEXT,
				language TEXT,
				page_count INTEGER DEFAULT 1,
				entry_path TEXT
			)');
			// Indexes for requests
			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_req_ts ON requests(ts)');
			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_req_session ON requests(session_id, ts)');
			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_req_path ON requests(path)');
			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_req_prev ON requests(prev_path, path)');
			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_req_host ON requests(host)');
			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_req_platform ON requests(platform)');
			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_req_browser ON requests(browser)');
			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_req_ip ON requests(ip)');
			// Indexes for sessions
			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_sess_last ON sessions(last_seen)');
			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_sess_host ON sessions(host)');
			self::$db->exec('CREATE INDEX IF NOT EXISTS idx_sess_ip ON sessions(ip)');
		} catch (\Exception $e) {
			self::$db = null;
		}
	}

	// ── Recording ──

	/**
	 * Record a completed request. Called from the server after sending the response.
	 */
	static function recordRequest($statusCode, $durationMs, $method, $path, $clientIp, $userAgent, $bytesSent, $cookies = [], $host = null, $headers = [])
	{
		if (!self::enabled()) return;

		$minute = date('Y-m-d H:i');
		if ($minute !== self::$currentMinute) {
			self::flushMinute();
			self::$currentMinute = $minute;
		}

		$statusCode = (int) $statusCode;
		self::$requestTimes[] = $durationMs;
		$bucket = (int) floor($statusCode / 100) . 'xx';
		self::$statusCodes[$bucket] = (self::$statusCodes[$bucket] ?? 0) + 1;

		// ── Session detection via stable cookie ──
		// Try framework-specific cookies first, then fall back to common names.
		// Since we detect the framework at startup, we prioritize the right cookie.
		$sessionId = null;
		$cookieNames = Q_Config::get('Q', 'webserver', 'metrics', 'sessionCookies', null);
		if (!$cookieNames) {
			$cookieNames = self::frameworkCookies();
		}
		// Prefix matching for frameworks that use dynamic cookie names
		// (hash suffix varies per installation or per domain)
		$prefixes = [
			'Q_session',             // Qbix: Q_session_<appName>
			'wordpress_logged_in_',  // WordPress
			'SESS', 'SSESS',         // Drupal (SESS<hash>, SSESS<hash> for secure)
			'PrestaShop-',           // PrestaShop
			'phpbb3_',               // phpBB
			'oc',                    // Nextcloud: oc<hash>
			'fe_typo_user',          // TYPO3
		];
		foreach ($cookies as $name => $val) {
			foreach ($prefixes as $pfx) {
				if (strpos($name, $pfx) === 0) {
					$sessionId = md5($name . '=' . $val);
					break 2;
				}
			}
		}
		if (!$sessionId) {
			foreach ($cookieNames as $name) {
				if (!empty($cookies[$name])) {
					$sessionId = md5($name . '=' . $cookies[$name]);
					break;
				}
			}
		}
		// Fallback: hash IP + UA (less accurate, but catches cookieless visitors)
		if (!$sessionId) {
			$sessionId = md5($clientIp . '|' . ($userAgent ?? ''));
		}

		// ── Clickstream: track page transitions ──
		if ($method === 'GET' && $statusCode < 400 && !self::isStaticAsset($path)) {
			$prevPath = null;
			if (isset(self::$sessions[$sessionId])) {
				$prev = self::$sessions[$sessionId];
				// Only count as a transition if within 30 minutes
				if (time() - $prev['time'] < 1800) {
					$prevPath = $prev['path'];
				}
			}
			self::$sessions[$sessionId] = ['path' => $path, 'time' => time()];

			// Buffer individual request for analytics
			$ua = self::parseUserAgent($userAgent);
			$lang = self::parsePrimaryLanguage($headers['accept-language'] ?? null);
			$now = time();
			self::$requestBuffer[] = [
				'ts' => $now,
				'sessionId' => $sessionId,
				'path' => $path,
				'prevPath' => $prevPath,
				'status' => $statusCode,
				'durationMs' => $durationMs,
				'ip' => $clientIp,
				'host' => $host,
				'platform' => $ua['platform'],
				'browser' => $ua['browser'],
				'lang' => $lang,
			];

			// Update session metadata
			if (!isset(self::$sessionUpdates[$sessionId])) {
				self::$sessionUpdates[$sessionId] = [
					'firstSeen' => $now,
					'lastSeen' => $now,
					'ip' => $clientIp,
					'host' => $host,
					'platform' => $ua['platform'],
					'browser' => $ua['browser'],
					'lang' => $lang,
					'pageCount' => 1,
					'entryPath' => $path,
				];
			} else {
				self::$sessionUpdates[$sessionId]['lastSeen'] = $now;
				self::$sessionUpdates[$sessionId]['pageCount']++;
			}

			if ($prevPath && $prevPath !== $path) {
				self::$flowBuffer[] = ['from' => $prevPath, 'to' => $path];
			}

			// Per-page stats
			if (!isset(self::$pageHits[$path])) {
				self::$pageHits[$path] = ['hits' => 0, 'sessions' => [], 'totalMs' => 0];
			}
			self::$pageHits[$path]['hits']++;
			self::$pageHits[$path]['sessions'][$sessionId] = true;
			self::$pageHits[$path]['totalMs'] += $durationMs;

			// Evict old sessions (keep last 10K)
			if (count(self::$sessions) > 10000) {
				uasort(self::$sessions, function($a, $b) { return $b['time'] - $a['time']; });
				self::$sessions = array_slice(self::$sessions, 0, 5000, true);
			}
		}

		// Buffer access log line
		$ts = date('d/M/Y:H:i:s O');
		$line = sprintf('%s - - [%s] "%s %s" %d %d %.1fms "%s"',
			$clientIp, $ts, $method, $path, $statusCode, $bytesSent,
			$durationMs, substr($userAgent ?? '-', 0, 200)
		);
		self::$accessBuffer[] = $line;

		$bufferSize = Q_Config::get('Q', 'webserver', 'metrics', 'logBufferSize', 500);
		if (count(self::$accessBuffer) >= $bufferSize) {
			self::flushLogs();
		}
	}

	/**
	 * Check if a path is a static asset (skip clickstream tracking for these).
	 */
	private static function isStaticAsset($path)
	{
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return in_array($ext, ['css','js','png','jpg','jpeg','gif','svg','ico','woff','woff2','ttf','map','webp'], true);
	}

	/**
	 * Parse User-Agent string for platform and browser.
	 */
	static function parseUserAgent($ua)
	{
		if (!$ua) return ['platform' => 'Other', 'browser' => 'Other'];

		// Platform
		$platform = 'Other';
		if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
			$platform = 'iOS';
		} elseif (stripos($ua, 'Android') !== false) {
			$platform = 'Android';
		} elseif (stripos($ua, 'Windows') !== false) {
			$platform = 'Windows';
		} elseif (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS') !== false) {
			$platform = 'macOS';
		} elseif (stripos($ua, 'CrOS') !== false) {
			$platform = 'ChromeOS';
		} elseif (stripos($ua, 'Linux') !== false) {
			$platform = 'Linux';
		}

		// Browser (order matters: specific before generic)
		$browser = 'Other';
		if (stripos($ua, 'Edg/') !== false || stripos($ua, 'EdgA/') !== false) {
			$browser = 'Edge';
		} elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) {
			$browser = 'Opera';
		} elseif (stripos($ua, 'Firefox/') !== false || stripos($ua, 'FxiOS/') !== false) {
			$browser = 'Firefox';
		} elseif (stripos($ua, 'SamsungBrowser/') !== false) {
			$browser = 'Samsung';
		} elseif (stripos($ua, 'CriOS/') !== false) {
			$browser = 'Chrome';
		} elseif (stripos($ua, 'Chrome/') !== false) {
			$browser = 'Chrome';
		} elseif (stripos($ua, 'Safari/') !== false) {
			$browser = 'Safari';
		} elseif (stripos($ua, 'MSIE') !== false || stripos($ua, 'Trident/') !== false) {
			$browser = 'IE';
		}

		// Check for bots
		if (preg_match('/bot|crawl|spider|slurp|mediapartners/i', $ua)) {
			$browser = 'Bot';
		}

		return ['platform' => $platform, 'browser' => $browser];
	}

	/**
	 * Parse the primary language from Accept-Language header.
	 */
	static function parsePrimaryLanguage($header)
	{
		if (!$header) return null;
		$parts = explode(',', $header);
		$lang = trim(explode(';', $parts[0])[0]);
		// Normalize: "en-US" → "en-US", "en" → "en"
		return $lang ?: null;
	}

	/**
	 * Get session cookie names prioritized by detected framework.
	 * Each framework uses a different cookie name for sessions.
	 */
	private static function frameworkCookies()
	{
		static $cached = null;
		if ($cached !== null) return $cached;

		// Framework-specific session cookies (checked in order)
		$perFramework = [
			'qbix'     => ['Q_nonce'],                  // Q_session_<app> handled by prefix
			'laravel'  => ['laravel_session', 'XSRF-TOKEN'],
			'symfony'  => ['PHPSESSID', 'REMEMBERME'],
			'drupal'   => [],                           // SESS<hash> handled by prefix
			'wordpress'=> [],                           // wordpress_logged_in_<hash> by prefix
			'joomla'   => ['joomla_user_state'],
			'magento'  => ['PHPSESSID', 'frontend', 'adminhtml'],
			'typo3'    => [],                           // fe_typo_user handled by prefix
			'craftcms' => ['CraftSessionId', 'CRAFT_CSRF_TOKEN'],
			'moodle'   => ['MoodleSession'],
			'mediawiki'=> ['wiki_session'],             // <wiki>_session by convention
			'nextcloud'=> ['nc_session_id'],            // oc<hash> handled by prefix
			'prestashop'=> [],                          // PrestaShop-<hash> by prefix
			'cakephp'  => ['CAKEPHP', 'csrfToken'],
			'codeigniter' => ['ci_session'],
			'yii'      => ['_csrf', 'PHPSESSID'],
			'laminas'  => ['PHPSESSID'],
			'fuelphp'  => ['fueld', 'fuelcid'],
		];

		// Common fallbacks that work with most PHP apps
		$common = [
			'PHPSESSID',      // PHP default
			'session',        // Generic
			'sid',            // Generic
			'_session',       // Ruby/Sinatra
			'connect.sid',    // Express.js
			'sessionid',      // Django
			'_session_id',    // Rails
			'ASP.NET_SessionId', // ASP.NET
		];

		// Check if we know the framework
		$framework = null;
		if (class_exists('Q_WebServer_Autohost', false)) {
			// Use the app dir detection
			$appDir = defined('APP_DIR') ? APP_DIR : getcwd();
			$framework = Q_WebServer_Autohost::detectFramework($appDir);
		}

		$result = [];
		if ($framework && isset($perFramework[$framework])) {
			$result = $perFramework[$framework];
		}
		// Append common fallbacks (deduped)
		foreach ($common as $c) {
			if (!in_array($c, $result)) $result[] = $c;
		}

		$cached = $result;
		return $cached;
	}

	/**
	 * Buffer an error log line.
	 */
	static function recordError($message)
	{
		if (!self::enabled()) return;
		self::$errorBuffer[] = date('Y-m-d H:i:s') . ' ' . $message;
		if (count(self::$errorBuffer) >= 100) {
			self::flushLogs();
		}
	}

	// ── Flushing ──

	/**
	 * Flush the current minute's stats to SQLite.
	 */
	static function flushMinute()
	{
		if (empty(self::$requestTimes) || !self::$db) return;

		$times = self::$requestTimes;
		sort($times);
		$n = count($times);

		$stats = [
			'ts' => self::$currentMinute,
			'requests' => $n,
			'p50' => $times[(int) ($n * 0.5)] ?? 0,
			'p95' => $times[(int) ($n * 0.95)] ?? 0,
			'p99' => $times[min($n - 1, (int) ($n * 0.99))] ?? 0,
			'avg' => array_sum($times) / $n,
			's2' => self::$statusCodes['2xx'] ?? 0,
			's3' => self::$statusCodes['3xx'] ?? 0,
			's4' => self::$statusCodes['4xx'] ?? 0,
			's5' => self::$statusCodes['5xx'] ?? 0,
			'workers' => 0,
			'mem' => round(memory_get_usage(true) / 1048576, 1),
		];

		$pool = \Q_WebServer::$pool ?? null;
		if ($pool && method_exists($pool, 'workerStats')) {
			$ws = $pool->workerStats();
			$stats['workers'] = $ws['total'] ?? 0;
		}

		try {
			$stmt = self::$db->prepare('INSERT OR REPLACE INTO minute_stats VALUES (
				:ts, :requests, :p50, :p95, :p99, :avg,
				:s2, :s3, :s4, :s5, :workers, :mem
			)');
			$stmt->bindValue(':ts', $stats['ts']);
			$stmt->bindValue(':requests', $stats['requests']);
			$stmt->bindValue(':p50', $stats['p50']);
			$stmt->bindValue(':p95', $stats['p95']);
			$stmt->bindValue(':p99', $stats['p99']);
			$stmt->bindValue(':avg', $stats['avg']);
			$stmt->bindValue(':s2', $stats['s2']);
			$stmt->bindValue(':s3', $stats['s3']);
			$stmt->bindValue(':s4', $stats['s4']);
			$stmt->bindValue(':s5', $stats['s5']);
			$stmt->bindValue(':workers', $stats['workers']);
			$stmt->bindValue(':mem', $stats['mem']);
			$stmt->execute();
		} catch (\Exception $e) {
			// SQLite error — skip this minute
		}

		self::$requestTimes = [];
		self::$statusCodes = [];

		// Flush clickstream and page data
		self::flushClickstream();

		// ── Anomaly detection: spike in RPS or error rate ──
		self::$prevRps[] = $stats['requests'];
		if (count(self::$prevRps) > 5) array_shift(self::$prevRps);

		$webhookUrl = Q_Config::get('Q', 'webserver', 'metrics', 'anomalyWebhook', null);
		if ($webhookUrl && count(self::$prevRps) >= 3) {
			$avgRps = array_sum(array_slice(self::$prevRps, 0, -1)) / (count(self::$prevRps) - 1);
			$currentRps = end(self::$prevRps);
			$errorRate = $stats['requests'] > 0
				? ($stats['s5'] / $stats['requests']) * 100 : 0;

			$anomaly = null;
			if ($avgRps > 0 && $currentRps > $avgRps * 3) {
				$anomaly = 'traffic_spike';
			}
			if ($errorRate > 10 && $stats['s5'] > 5) {
				$anomaly = 'error_spike';
			}
			if ($stats['avg'] > 5000) {
				$anomaly = 'latency_spike';
			}

			if ($anomaly) {
				$payload = json_encode([
					'type' => $anomaly,
					'time' => $stats['ts'],
					'requests' => $currentRps,
					'avgRps' => round($avgRps, 1),
					'errorRate' => round($errorRate, 1),
					'avgLatency' => round($stats['avg'], 1),
					'p95' => round($stats['p95'], 1),
				]);
				// Fire-and-forget POST
				@file_get_contents($webhookUrl, false, stream_context_create([
					'http' => [
						'method' => 'POST',
						'header' => "Content-Type: application/json\r\n",
						'content' => $payload,
						'timeout' => 2,
						'ignore_errors' => true,
					]
				]));
			}
		}
	}

	/**
	 * Flush log buffers to disk.
	 */
	static function flushLogs()
	{
		if (!empty(self::$accessBuffer)) {
			$path = self::accessLogPath();
			self::rotateIfNeeded($path);
			file_put_contents($path, implode("\n", self::$accessBuffer) . "\n", FILE_APPEND);
			self::$accessBuffer = [];
		}
		if (!empty(self::$errorBuffer)) {
			$path = self::errorLogPath();
			self::rotateIfNeeded($path);
			file_put_contents($path, implode("\n", self::$errorBuffer) . "\n", FILE_APPEND);
			self::$errorBuffer = [];
		}
	}

	/**
	 * Periodic tick — called from the event loop.
	 */
	static function tick()
	{
		$minute = date('Y-m-d H:i');
		if ($minute !== self::$currentMinute) {
			self::flushMinute();
			self::$currentMinute = $minute;
		} else {
			// Flush clickstream and page data even within the same minute
			// so the dashboard shows live data
			self::flushClickstream();
		}
		self::flushLogs();
	}

	/**
	 * Flush just the clickstream and page buffers to SQLite
	 * (without closing the minute stats).
	 *
	 * Uses SQLite syntax compatible with old SQLite versions (including 3.7.x).
	 */
	private static function flushClickstream()
	{
		if (!self::$db) return;

		// ── Flow transitions ──
		if (!empty(self::$flowBuffer)) {
			try {
				self::$db->exec('BEGIN');

				$update = self::$db->prepare(
					'UPDATE flow
					SET count = count + 1
					WHERE from_path = :f AND to_path = :t'
				);

				$insert = self::$db->prepare(
					'INSERT OR IGNORE INTO flow
					(from_path, to_path, count)
					VALUES (:f, :t, 1)'
				);

				if ($update && $insert) {
					foreach (self::$flowBuffer as $f) {
						$update->bindValue(':f', $f['from']);
						$update->bindValue(':t', $f['to']);
						$update->execute();
						$update->reset();

						if (self::$db->changes() === 0) {
							$insert->bindValue(':f', $f['from']);
							$insert->bindValue(':t', $f['to']);
							$insert->execute();
							$insert->reset();
						}
					}
				}

				self::$db->exec('COMMIT');
			} catch (\Throwable $e) {
				@self::$db->exec('ROLLBACK');
			}

			self::$flowBuffer = [];
		}

		// ── Per-page statistics ──
		if (!empty(self::$pageHits)) {
			try {
				self::$db->exec('BEGIN');

				$now = date('c');

				/*
				* Important: calculate the new weighted average using the OLD
				* value of hits. SQLite evaluates the RHS expressions before
				* assigning the updated values.
				*/
				$update = self::$db->prepare(
					'UPDATE page_stats SET
						avg_ms = (avg_ms * hits + :a * :h) / (hits + :h),
						hits = hits + :h,
						unique_sessions = unique_sessions + :u,
						last_hit = :t
					WHERE path = :p'
				);

				$insert = self::$db->prepare(
					'INSERT OR IGNORE INTO page_stats
					(path, hits, unique_sessions, avg_ms, last_hit)
					VALUES (:p, :h, :u, :a, :t)'
				);

				if ($update && $insert) {
					foreach (self::$pageHits as $path => $data) {
						$hits = $data['hits'];
						$unique = count($data['sessions']);
						$avg = $hits > 0
							? $data['totalMs'] / $hits
							: 0;

						$update->bindValue(':p', $path);
						$update->bindValue(':h', $hits, SQLITE3_INTEGER);
						$update->bindValue(':u', $unique, SQLITE3_INTEGER);
						$update->bindValue(':a', $avg);
						$update->bindValue(':t', $now);
						$update->execute();
						$update->reset();

						if (self::$db->changes() === 0) {
							$insert->bindValue(':p', $path);
							$insert->bindValue(':h', $hits, SQLITE3_INTEGER);
							$insert->bindValue(':u', $unique, SQLITE3_INTEGER);
							$insert->bindValue(':a', $avg);
							$insert->bindValue(':t', $now);
							$insert->execute();
							$insert->reset();
						}
					}
				}

				self::$db->exec('COMMIT');
			} catch (\Throwable $e) {
				@self::$db->exec('ROLLBACK');
			}

			self::$pageHits = [];
		}

		// ── Individual request records ──
		if (!empty(self::$requestBuffer)) {
			try {
				self::$db->exec('BEGIN');

				$stmt = self::$db->prepare(
					'INSERT INTO requests
					(ts, session_id, path, prev_path, status, duration_ms,
					ip, host, platform, browser, language)
					VALUES
					(:ts, :sid, :path, :prev, :status, :dur,
					:ip, :host, :plat, :br, :lang)'
				);

				if ($stmt) {
					foreach (self::$requestBuffer as $r) {
						$stmt->bindValue(':ts', $r['ts'], SQLITE3_INTEGER);
						$stmt->bindValue(':sid', $r['sessionId']);
						$stmt->bindValue(':path', $r['path']);
						$stmt->bindValue(':prev', $r['prevPath']);
						$stmt->bindValue(':status', $r['status'], SQLITE3_INTEGER);
						$stmt->bindValue(':dur', $r['durationMs']);
						$stmt->bindValue(':ip', $r['ip']);
						$stmt->bindValue(':host', $r['host']);
						$stmt->bindValue(':plat', $r['platform']);
						$stmt->bindValue(':br', $r['browser']);
						$stmt->bindValue(':lang', $r['lang']);
						$stmt->execute();
						$stmt->reset();
					}
				}

				self::$db->exec('COMMIT');
			} catch (\Throwable $e) {
				@self::$db->exec('ROLLBACK');
			}

			self::$requestBuffer = [];
		}

		// ── Session summaries ──
		if (!empty(self::$sessionUpdates)) {
			try {
				self::$db->exec('BEGIN');

				$update = self::$db->prepare(
					'UPDATE sessions SET
						last_seen = CASE
							WHEN last_seen > :last THEN last_seen
							ELSE :last
						END,
						page_count = page_count + :cnt
					WHERE session_id = :sid'
				);

				$insert = self::$db->prepare(
					'INSERT OR IGNORE INTO sessions
					(session_id, first_seen, last_seen, ip, host,
					platform, browser, language, page_count, entry_path)
					VALUES
					(:sid, :first, :last, :ip, :host,
					:plat, :br, :lang, :cnt, :entry)'
				);

				if ($update && $insert) {
					foreach (self::$sessionUpdates as $sid => $s) {
						$update->bindValue(':sid', $sid);
						$update->bindValue(':last', $s['lastSeen'], SQLITE3_INTEGER);
						$update->bindValue(':cnt', $s['pageCount'], SQLITE3_INTEGER);
						$update->execute();
						$update->reset();

						if (self::$db->changes() === 0) {
							$insert->bindValue(':sid', $sid);
							$insert->bindValue(':first', $s['firstSeen'], SQLITE3_INTEGER);
							$insert->bindValue(':last', $s['lastSeen'], SQLITE3_INTEGER);
							$insert->bindValue(':ip', $s['ip']);
							$insert->bindValue(':host', $s['host']);
							$insert->bindValue(':plat', $s['platform']);
							$insert->bindValue(':br', $s['browser']);
							$insert->bindValue(':lang', $s['lang']);
							$insert->bindValue(':cnt', $s['pageCount'], SQLITE3_INTEGER);
							$insert->bindValue(':entry', $s['entryPath']);
							$insert->execute();
							$insert->reset();
						}
					}
				}

				self::$db->exec('COMMIT');
			} catch (\Throwable $e) {
				@self::$db->exec('ROLLBACK');
			}

			self::$sessionUpdates = [];
		}
	}

	// ── Log rotation ──

	private static $lastRotateCheck = [];

	static function rotateIfNeeded($logPath)
	{
		$today = date('Y-m-d');
		if ((self::$lastRotateCheck[$logPath] ?? '') === $today) return;
		self::$lastRotateCheck[$logPath] = $today;

		if (!is_file($logPath)) return;

		// Check if the log's last modification was a different day
		$logDate = date('Y-m-d', filemtime($logPath));
		if ($logDate === $today) return;

		// Rotate: rename current log to dated name
		$rotated = $logPath . '.' . $logDate;
		if (!is_file($rotated)) {
			rename($logPath, $rotated);

			// Compress old rotated logs
			$compress = Q_Config::get('Q', 'webserver', 'metrics', 'logCompress', true);
			if ($compress && class_exists('ZipArchive')) {
				$zipPath = $rotated . '.zip';
				$za = new \ZipArchive();
				if ($za->open($zipPath, \ZipArchive::CREATE) === true) {
					$za->addFile($rotated, basename($rotated));
					$za->close();
					unlink($rotated);
				}
			}
		}

		// Purge old logs beyond retention
		$retainDays = Q_Config::get('Q', 'webserver', 'metrics', 'logRetainDays', 7);
		$dir = dirname($logPath);
		$base = basename($logPath);
		$cutoff = strtotime("-$retainDays days");
		foreach (scandir($dir) as $f) {
			if (strpos($f, $base . '.') !== 0) continue;
			$fPath = $dir . '/' . $f;
			if (filemtime($fPath) < $cutoff) {
				@unlink($fPath);
			}
		}
	}

	// ── Data cleanup ──

	/**
	 * Purge old metrics data beyond retention.
	 */
	static function purgeOld()
	{
		if (!self::$db) return;
		$retainDays = Q_Config::get('Q', 'webserver', 'metrics', 'retainDays', 30);
		$cutoff = date('Y-m-d H:i', strtotime("-$retainDays days"));
		$cutoffTs = strtotime("-$retainDays days");
		self::$db->exec("DELETE FROM minute_stats WHERE ts < '$cutoff'");
		self::$db->exec("DELETE FROM requests WHERE ts < $cutoffTs");
		self::$db->exec("DELETE FROM sessions WHERE last_seen < $cutoffTs");
	}

	// ── Query ──

	/**
	 * Get recent stats for the dashboard.
	 */
	static function recentStats($minutes = 60)
	{
		if (!self::$db) return [];
		$cutoff = date('Y-m-d H:i', strtotime("-$minutes minutes"));
		$result = self::$db->query(
			"SELECT * FROM minute_stats WHERE ts >= '$cutoff' ORDER BY ts"
		);
		$rows = [];
		while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Get summary stats.
	 */
	static function summary($hours = 24)
	{
		if (!self::$db) return [];
		$cutoff = date('Y-m-d H:i', strtotime("-$hours hours"));
		$row = self::$db->querySingle(
			"SELECT SUM(requests) as total_requests,
				AVG(avg_ms) as avg_latency,
				AVG(p95_ms) as avg_p95,
				SUM(status_2xx) as total_2xx,
				SUM(status_4xx) as total_4xx,
				SUM(status_5xx) as total_5xx,
				AVG(memory_mb) as avg_memory
			FROM minute_stats WHERE ts >= '$cutoff'",
			true
		);
		return $row ?: [];
	}

	/**
	 * Prometheus-compatible text output.
	 */
	static function prometheus()
	{
		$lines = [];
		$summary = self::summary(1); // last hour
		$pool = \Q_WebServer::$pool ?? null;
		$workers = $pool && method_exists($pool, 'workerStats') ? $pool->workerStats() : null;

		$lines[] = '# HELP qbix_requests_total Total requests in last hour';
		$lines[] = '# TYPE qbix_requests_total counter';
		$lines[] = 'qbix_requests_total ' . ($summary['total_requests'] ?? 0);

		$lines[] = '# HELP qbix_latency_avg_ms Average response time';
		$lines[] = '# TYPE qbix_latency_avg_ms gauge';
		$lines[] = 'qbix_latency_avg_ms ' . round($summary['avg_latency'] ?? 0, 2);

		$lines[] = '# HELP qbix_latency_p95_ms P95 response time';
		$lines[] = '# TYPE qbix_latency_p95_ms gauge';
		$lines[] = 'qbix_latency_p95_ms ' . round($summary['avg_p95'] ?? 0, 2);

		$lines[] = '# HELP qbix_workers_total Total workers';
		$lines[] = '# TYPE qbix_workers_total gauge';
		$lines[] = 'qbix_workers_total ' . ($workers['total'] ?? 0);

		$lines[] = '# HELP qbix_workers_busy Busy workers';
		$lines[] = '# TYPE qbix_workers_busy gauge';
		$lines[] = 'qbix_workers_busy ' . ($workers['busy'] ?? 0);

		$lines[] = '# HELP qbix_memory_bytes Server memory usage';
		$lines[] = '# TYPE qbix_memory_bytes gauge';
		$lines[] = 'qbix_memory_bytes ' . memory_get_usage(true);

		$lines[] = '# HELP qbix_errors_total 5xx responses in last hour';
		$lines[] = '# TYPE qbix_errors_total counter';
		$lines[] = 'qbix_errors_total ' . ($summary['total_5xx'] ?? 0);

		return implode("\n", $lines) . "\n";
	}

	// ── Paths ──

	static function accessLogPath()
	{
		return Q_Config::get('Q', 'webserver', 'metrics', 'accessLog', qbix_data_path('local/logs/access.log'));
	}

	static function errorLogPath()
	{
		return Q_Config::get('Q', 'webserver', 'metrics', 'errorLog', qbix_data_path('local/logs/error.log'));
	}

	/**
	 * Get status for the control panel.
	 */
	static function status()
	{
		$dbPath = Q_Config::get('Q', 'webserver', 'metrics', 'db', qbix_data_path('local/metrics.db'));
		$accessLog = self::accessLogPath();
		$errorLog = self::errorLogPath();

		$logFiles = [];
		$logDir = dirname($accessLog);
		if (is_dir($logDir)) {
			foreach (scandir($logDir) as $f) {
				if ($f === '.' || $f === '..') continue;
				$fp = $logDir . '/' . $f;
				$logFiles[] = [
					'name' => $f,
					'size' => filesize($fp),
					'modified' => date('Y-m-d H:i', filemtime($fp)),
				];
			}
		}

		return [
			'enabled' => self::enabled(),
			'db' => $dbPath,
			'dbSize' => is_file($dbPath) ? filesize($dbPath) : 0,
			'accessLog' => $accessLog,
			'errorLog' => $errorLog,
			'bufferSize' => count(self::$accessBuffer) + count(self::$errorBuffer),
			'activeSessions' => count(self::$sessions),
			'logFiles' => $logFiles,
			'requestsBuffered' => count(self::$requestBuffer),
			'retainDays' => Q_Config::get('Q', 'webserver', 'metrics', 'retainDays', 30),
			'logRetainDays' => Q_Config::get('Q', 'webserver', 'metrics', 'logRetainDays', 7),
		];
	}

	/**
	 * Get the clickstream flow graph (from → to with counts).
	 * Returns edges sorted by count, limited to top N.
	 */
	static function flow($limit = 50)
	{
		if (!self::$db) return [];
		$result = self::$db->query(
			"SELECT from_path, to_path, count FROM flow ORDER BY count DESC LIMIT $limit"
		);
		$edges = [];
		while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
			$edges[] = $row;
		}
		return $edges;
	}

	/**
	 * Get top pages by hits.
	 */
	static function topPages($limit = 20)
	{
		if (!self::$db) return [];
		$result = self::$db->query(
			"SELECT path, hits, unique_sessions, avg_ms, last_hit
			 FROM page_stats ORDER BY hits DESC LIMIT $limit"
		);
		$pages = [];
		while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
			$pages[] = $row;
		}
		return $pages;
	}

	/**
	 * Get the flow graph starting from a specific page.
	 * Returns {outgoing: [{to, count}], incoming: [{from, count}]}.
	 */
	static function pageFlow($path)
	{
		if (!self::$db) return ['outgoing' => [], 'incoming' => []];
		$out = [];
		$in = [];
		$escapedPath = self::$db->escapeString($path);
		$r = self::$db->query("SELECT to_path, count FROM flow WHERE from_path='$escapedPath' ORDER BY count DESC LIMIT 20");
		while ($row = $r->fetchArray(SQLITE3_ASSOC)) $out[] = $row;
		$r = self::$db->query("SELECT from_path, count FROM flow WHERE to_path='$escapedPath' ORDER BY count DESC LIMIT 20");
		while ($row = $r->fetchArray(SQLITE3_ASSOC)) $in[] = $row;
		return ['outgoing' => $out, 'incoming' => $in];
	}

	// ── Analytics queries ──

	/**
	 * Get navigation flow edges with optional filters.
	 * Returns [{from_path, to_path, count}] for Sankey diagrams.
	 */
	static function analyticsFlow($filters = [], $limit = 50)
	{
		if (!self::$db) return [];
		$where = ['prev_path IS NOT NULL'];
		$params = [];
		self::buildAnalyticsWhere($filters, $where, $params);
		$sql = 'SELECT prev_path AS from_path, path AS to_path, COUNT(*) AS count
				FROM requests WHERE ' . implode(' AND ', $where) . '
				GROUP BY prev_path, path ORDER BY count DESC LIMIT ' . (int) $limit;
		return self::analyticsQuery($sql, $params);
	}

	/**
	 * Get drilldown: where users go FROM a specific page, or come TO it.
	 */
	static function analyticsDrilldown($path, $direction = 'outgoing', $filters = [], $limit = 20)
	{
		if (!self::$db) return [];
		$where = [];
		$params = [];
		self::buildAnalyticsWhere($filters, $where, $params);
		if ($direction === 'outgoing') {
			$where[] = 'prev_path = :drill_path';
			$params[':drill_path'] = $path;
			$sql = 'SELECT path AS to_path, COUNT(*) AS count
					FROM requests WHERE ' . implode(' AND ', $where) . '
					GROUP BY path ORDER BY count DESC LIMIT ' . (int) $limit;
		} else {
			$where[] = 'path = :drill_path';
			$where[] = 'prev_path IS NOT NULL';
			$params[':drill_path'] = $path;
			$sql = 'SELECT prev_path AS from_path, COUNT(*) AS count
					FROM requests WHERE ' . implode(' AND ', $where) . '
					GROUP BY prev_path ORDER BY count DESC LIMIT ' . (int) $limit;
		}
		return self::analyticsQuery($sql, $params);
	}

	/**
	 * List sessions matching filters with summary info.
	 */
	static function analyticsSessions($filters = [], $limit = 50, $offset = 0)
	{
		if (!self::$db) return [];
		$where = [];
		$params = [];
		self::buildSessionWhere($filters, $where, $params);
		$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
		$sql = "SELECT session_id, first_seen, last_seen, ip, host, platform, browser, language, page_count, entry_path
				FROM sessions $whereClause
				ORDER BY last_seen DESC
				LIMIT " . (int) $limit . " OFFSET " . (int) $offset;
		return self::analyticsQuery($sql, $params);
	}

	/**
	 * Get the full request path for a single session.
	 */
	static function sessionPath($sessionId)
	{
		if (!self::$db) return [];
		$stmt = self::$db->prepare(
			'SELECT ts, path, prev_path, status, duration_ms, ip, host
			 FROM requests WHERE session_id = :sid ORDER BY ts ASC'
		);
		$stmt->bindValue(':sid', $sessionId);
		$result = $stmt->execute();
		$rows = [];
		while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Analytics overview: aggregate stats for the given filters.
	 */
	static function analyticsOverview($filters = [])
	{
		if (!self::$db) return [];
		$where = [];
		$params = [];
		self::buildAnalyticsWhere($filters, $where, $params);
		$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

		$row = self::$db->querySingle(
			"SELECT COUNT(*) AS page_views,
					COUNT(DISTINCT session_id) AS sessions,
					COUNT(DISTINCT ip) AS unique_ips,
					AVG(duration_ms) AS avg_ms
			 FROM requests $whereClause", true
		);

		// Top pages
		$sql = "SELECT path, COUNT(*) AS hits FROM requests $whereClause GROUP BY path ORDER BY hits DESC LIMIT 10";
		$topPages = self::analyticsQuery($sql, $params);

		// Top platforms
		$sql = "SELECT platform, COUNT(*) AS count FROM requests $whereClause GROUP BY platform ORDER BY count DESC";
		$topPlatforms = self::analyticsQuery($sql, $params);

		// Top browsers
		$sql = "SELECT browser, COUNT(*) AS count FROM requests $whereClause GROUP BY browser ORDER BY count DESC";
		$topBrowsers = self::analyticsQuery($sql, $params);

		// Top languages
		$sql = "SELECT language, COUNT(*) AS count FROM requests $whereClause AND language IS NOT NULL GROUP BY language ORDER BY count DESC LIMIT 10";
		$topLanguages = self::analyticsQuery($sql, $params);

		// Available hosts (for app filter)
		$hostSql = "SELECT DISTINCT host FROM requests WHERE host IS NOT NULL ORDER BY host";
		$hosts = [];
		$r = self::$db->query($hostSql);
		while ($h = $r->fetchArray(SQLITE3_ASSOC)) $hosts[] = $h['host'];

		return [
			'pageViews' => (int) ($row['page_views'] ?? 0),
			'sessions' => (int) ($row['sessions'] ?? 0),
			'uniqueIps' => (int) ($row['unique_ips'] ?? 0),
			'avgMs' => round($row['avg_ms'] ?? 0, 1),
			'topPages' => $topPages,
			'topPlatforms' => $topPlatforms,
			'topBrowsers' => $topBrowsers,
			'topLanguages' => $topLanguages,
			'hosts' => $hosts,
		];
	}

	/**
	 * Build WHERE clauses from analytics filters.
	 * Supported filters: from (unix ts), to (unix ts), host, platform, browser, language, ip, path.
	 */
	private static function buildAnalyticsWhere($filters, &$where, &$params)
	{
		if (!empty($filters['from'])) {
			$where[] = 'ts >= :from_ts';
			$params[':from_ts'] = (int) $filters['from'];
		}
		if (!empty($filters['to'])) {
			$where[] = 'ts <= :to_ts';
			$params[':to_ts'] = (int) $filters['to'];
		}
		if (!empty($filters['host'])) {
			$where[] = 'host = :host';
			$params[':host'] = $filters['host'];
		}
		if (!empty($filters['platform'])) {
			$where[] = 'platform = :platform';
			$params[':platform'] = $filters['platform'];
		}
		if (!empty($filters['browser'])) {
			$where[] = 'browser = :browser';
			$params[':browser'] = $filters['browser'];
		}
		if (!empty($filters['language'])) {
			$where[] = 'language = :language';
			$params[':language'] = $filters['language'];
		}
		if (!empty($filters['ip'])) {
			// Support prefix matching for IP ranges
			$where[] = 'ip LIKE :ip_prefix';
			$params[':ip_prefix'] = $filters['ip'] . '%';
		}
		if (!empty($filters['path'])) {
			$where[] = 'path LIKE :path_prefix';
			$params[':path_prefix'] = $filters['path'] . '%';
		}
	}

	/**
	 * Build WHERE clauses for sessions table.
	 */
	private static function buildSessionWhere($filters, &$where, &$params)
	{
		if (!empty($filters['from'])) {
			$where[] = 'last_seen >= :from_ts';
			$params[':from_ts'] = (int) $filters['from'];
		}
		if (!empty($filters['to'])) {
			$where[] = 'first_seen <= :to_ts';
			$params[':to_ts'] = (int) $filters['to'];
		}
		if (!empty($filters['host'])) {
			$where[] = 'host = :host';
			$params[':host'] = $filters['host'];
		}
		if (!empty($filters['platform'])) {
			$where[] = 'platform = :platform';
			$params[':platform'] = $filters['platform'];
		}
		if (!empty($filters['browser'])) {
			$where[] = 'browser = :browser';
			$params[':browser'] = $filters['browser'];
		}
		if (!empty($filters['language'])) {
			$where[] = 'language = :language';
			$params[':language'] = $filters['language'];
		}
		if (!empty($filters['ip'])) {
			$where[] = 'ip LIKE :ip_prefix';
			$params[':ip_prefix'] = $filters['ip'] . '%';
		}
	}

	/**
	 * Execute a parameterized analytics query and return rows.
	 */
	private static function analyticsQuery($sql, $params)
	{
		try {
			$stmt = self::$db->prepare($sql);
			foreach ($params as $key => $val) {
				$stmt->bindValue($key, $val);
			}
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
	 * Get date range of available analytics data.
	 */
	static function analyticsDateRange()
	{
		if (!self::$db) return null;
		try {
			$row = self::$db->querySingle(
				'SELECT MIN(ts) AS min_ts, MAX(ts) AS max_ts, COUNT(*) AS total FROM requests', true
			);
			if (!$row || !$row['min_ts']) return null;
			return [
				'from' => (int) $row['min_ts'],
				'to' => (int) $row['max_ts'],
				'total' => (int) $row['total'],
			];
		} catch (\Exception $e) {
			return null;
		}
	}
}
