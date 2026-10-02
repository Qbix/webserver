<?php
/**
 * Client-side metrics: script injection and event collection.
 *
 * Injects the Metrics.js `<script>` tag into HTML responses served by
 * the webserver. Receives client-side telemetry events via POST and
 * stores them as TSV files, one per day.
 *
 * The injection is skipped when the response already contains a
 * reference to Metrics.js or Metrics.min.js (Qbix Platform sites
 * load it through their own asset pipeline).
 *
 * Config (Q.webserver.clientMetrics):
 *   enabled: false (default — opt-in)
 *   endpoint: "/Q/clientMetrics"   — POST endpoint for events
 *   scriptUrl: null                — URL of external Metrics.js to inject
 *                                    (null = serve the bundled copy)
 *   retainDays: 90                 — days to keep TSV files
 *   tsvDir: "local/client-metrics" — where TSV files are written
 *   inject: true                   — inject the <script> tag
 *   trackers: ["scroll","media"]   — which trackers to auto-init
 *   checkpointInterval: 10         — MediaTracker checkpoint seconds
 *   debounce: 1000                 — ScrollTracker debounce ms
 *   extraScripts: []               — additional JS file URLs to inject
 *   extraStyles: []                — additional CSS file URLs to inject
 *   minified: false                — use .min.js versions of bundled scripts
 */
class Q_WebServer_ClientMetrics
{
	private static $initialized = false;
	private static $tsvDir = '';
	private static $endpoint = '/Q/clientMetrics';
	private static $reqHeaders = array();

	/**
	 * One-time init — create TSV directory if needed.
	 * Called from WebServer boot when enabled.
	 */
	static function init()
	{
		if (self::$initialized) return;
		self::$initialized = true;
		self::$endpoint = Q_Config::get('Q', 'webserver', 'clientMetrics', 'endpoint', '/Q/clientMetrics');
		self::$tsvDir = self::tsvDir();
		@mkdir(self::$tsvDir, 0755, true);
	}

	static function enabled()
	{
		return Q_Config::get('Q', 'webserver', 'clientMetrics', 'enabled', false);
	}

	/**
	 * Return the configured POST endpoint path.
	 */
	static function endpoint()
	{
		return self::$endpoint;
	}

	// ── Script Injection ──────────────────────────────────

	/**
	 * Store request headers for the current request so that
	 * sendResponse-path injection can access them.
	 * @param array $headers  Lowercase-keyed header map
	 */
	static function setRequestHeaders($headers)
	{
		self::$reqHeaders = $headers;
	}

	/**
	 * Inject Metrics.js <script> tag (and any configured extra
	 * scripts/styles) into an HTML response body.
	 * Returns the modified body, or the original if injection is
	 * not appropriate (non-HTML, already loaded, injection disabled,
	 * or request is a subresource fetch).
	 *
	 * @param string $body        The response body
	 * @param string $type        The Content-Type header value
	 * @param array|null $headers Request headers (lowercase keys).
	 *                            Falls back to headers set via setRequestHeaders().
	 * @return string
	 */
	static function injectScript($body, $type, $headers = null)
	{
		if (!self::enabled()) return $body;
		if (!Q_Config::get('Q', 'webserver', 'clientMetrics', 'inject', true)) return $body;

		// Only inject into HTML responses
		if (stripos($type, 'text/html') === false) return $body;

		// Only inject into top-level document requests.
		// If Sec-Fetch-Dest is present and not "document", skip injection
		// (iframe, script, style, image, etc. are subresource fetches).
		if ($headers === null) $headers = self::$reqHeaders;
		$fetchDest = $headers['sec-fetch-dest'] ?? null;
		if ($fetchDest !== null && $fetchDest !== 'document') return $body;

		// Skip if Metrics.js is already loaded (Qbix Platform sites)
		if (stripos($body, 'Metrics.js') !== false
			|| stripos($body, 'Metrics.min.js') !== false
		) {
			return $body;
		}

		// Skip if body has no </head> or </body> — probably not a full HTML page
		$insertPos = self::findInsertPosition($body);
		if ($insertPos === false) return $body;

		$snippet = self::buildSnippet();
		return substr($body, 0, $insertPos) . $snippet . substr($body, $insertPos);
	}

	/**
	 * Find the best position to insert the script — just before </head>
	 * (preferred) or just before </body>.
	 * @return int|false
	 */
	private static function findInsertPosition($body)
	{
		// Prefer just before </head> so trackers initialize early
		$pos = stripos($body, '</head>');
		if ($pos !== false) return $pos;

		// Fallback: just before </body>
		$pos = stripos($body, '</body>');
		if ($pos !== false) return $pos;

		return false;
	}

	/**
	 * Build the <script> snippet to inject.
	 * Includes core Metrics.js, requested trackers, and init calls.
	 */
	private static function buildSnippet()
	{
		$endpoint = self::$endpoint;
		$scriptUrl = Q_Config::get('Q', 'webserver', 'clientMetrics', 'scriptUrl', null);
		$trackers = Q_Config::get('Q', 'webserver', 'clientMetrics', 'trackers', array('scroll', 'media'));
		$checkpointInterval = Q_Config::get('Q', 'webserver', 'clientMetrics', 'checkpointInterval', 10);
		$debounce = Q_Config::get('Q', 'webserver', 'clientMetrics', 'debounce', 1000);
		$extraScripts = Q_Config::get('Q', 'webserver', 'clientMetrics', 'extraScripts', array());
		$extraStyles = Q_Config::get('Q', 'webserver', 'clientMetrics', 'extraStyles', array());

		$lines = array();

		// Extra CSS — injected first so styles are available before scripts run
		foreach ($extraStyles as $url) {
			$safe = htmlspecialchars($url, ENT_QUOTES);
			$lines[] = '<link rel="stylesheet" href="' . $safe . '">';
		}

		// Use minified filenames when scriptUrl ends with .min.js,
		// or when the 'minified' config key is set.
		$useMin = Q_Config::get('Q', 'webserver', 'clientMetrics', 'minified', false)
			|| ($scriptUrl && substr($scriptUrl, -7) === '.min.js');

		// Core Metrics.js
		if ($scriptUrl) {
			$safe = htmlspecialchars($scriptUrl, ENT_QUOTES);
			$lines[] = '<script src="' . $safe . '"></script>';
		} else {
			// Serve bundled copy from /Q/clientMetrics/Metrics.js
			$coreName = $useMin ? 'Metrics.min.js' : 'Metrics.js';
			$lines[] = '<script src="' . htmlspecialchars($endpoint, ENT_QUOTES) . '/' . $coreName . '"></script>';
		}

		// Tracker scripts
		foreach ($trackers as $t) {
			$t = strtolower(trim($t));
			$name = '';
			if ($t === 'scroll') $name = 'Metrics.ScrollTracker';
			elseif ($t === 'navigation') $name = 'Metrics.NavigationTracker';
			elseif ($t === 'media') $name = 'Metrics.MediaTracker';
			if (!$name) continue;
			$name .= $useMin ? '.min.js' : '.js';

			if ($scriptUrl) {
				// Assume trackers are alongside the core script
				$base = substr($scriptUrl, 0, strrpos($scriptUrl, '/') + 1);
				$lines[] = '<script src="' . htmlspecialchars($base . $name, ENT_QUOTES) . '"></script>';
			} else {
				$lines[] = '<script src="' . htmlspecialchars($endpoint, ENT_QUOTES) . '/' . $name . '"></script>';
			}
		}

		// Extra JS — after trackers so they can depend on Metrics if needed
		foreach ($extraScripts as $url) {
			$safe = htmlspecialchars($url, ENT_QUOTES);
			$lines[] = '<script src="' . $safe . '"></script>';
		}

		// Init snippet
		$safeEndpoint = addslashes($endpoint);
		$init = "Metrics.init({endpoint:'{$safeEndpoint}',page:document.title});";
		foreach ($trackers as $t) {
			$t = strtolower(trim($t));
			if ($t === 'scroll') {
				$init .= "Metrics.ScrollTracker.init({debounce:{$debounce}});";
			} elseif ($t === 'navigation') {
				$init .= "Metrics.NavigationTracker.init({debounce:{$debounce}});";
			} elseif ($t === 'media') {
				$init .= "Metrics.MediaTracker.init({checkpointInterval:{$checkpointInterval}});";
			}
		}
		$lines[] = '<script>' . $init . '</script>';

		return "\n<!-- Qbix Client Metrics -->\n" . implode("\n", $lines) . "\n";
	}

	// ── Bundled Script Serving ─────────────────────────────

	/**
	 * Serve one of the bundled Metrics JS files.
	 * Called when a request matches /Q/clientMetrics/Metrics*.js
	 *
	 * @param string $filename  e.g. "Metrics.js", "Metrics.ScrollTracker.js"
	 * @return array|null  {status, body, headers} or null if not found
	 */
	static function serveScript($filename)
	{
		// Whitelist allowed filenames
		$allowed = array(
			'Metrics.js',
			'Metrics.min.js',
			'Metrics.ScrollTracker.js',
			'Metrics.ScrollTracker.min.js',
			'Metrics.NavigationTracker.js',
			'Metrics.NavigationTracker.min.js',
			'Metrics.MediaTracker.js',
			'Metrics.MediaTracker.min.js'
		);
		if (!in_array($filename, $allowed)) return null;

		$dir = __DIR__ . '/ClientMetrics';
		$path = $dir . '/' . $filename;
		if (!is_file($path)) return null;

		$body = file_get_contents($path);
		$mtime = filemtime($path);
		$etag = '"cm-' . dechex($mtime) . '-' . dechex(strlen($body)) . '"';

		return array(
			'status' => 200,
			'body' => $body,
			'headers' => array(
				'Content-Type' => 'application/javascript; charset=utf-8',
				'ETag' => $etag,
				'Cache-Control' => 'public, max-age=86400',
				'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT'
			)
		);
	}

	// ── Event Collection ──────────────────────────────────

	/**
	 * Handle a POST request with client-side metric events.
	 * Events arrive as JSON (Content-Type: application/json) or as
	 * a form-encoded body. Each event has at minimum a `label` field.
	 *
	 * @param array $parsed  The parsed request from WebServer
	 * @return array {status, body, headers}
	 */
	static function handlePost($parsed)
	{
		$method = $parsed['method'] ?? 'GET';

		// CORS preflight
		if ($method === 'OPTIONS') {
			return array(
				'status' => 204,
				'body' => '',
				'headers' => self::corsHeaders()
			);
		}

		if ($method !== 'POST') {
			return array('status' => 405, 'body' => 'POST required',
				'headers' => self::corsHeaders());
		}

		$body = $parsed['body'] ?? '';
		$contentType = $parsed['headers']['content-type'] ?? '';

		$events = array();
		if (stripos($contentType, 'application/json') !== false) {
			$decoded = json_decode($body, true);
			if (is_array($decoded)) {
				// Could be a single event or an array of events
				if (isset($decoded['label'])) {
					$events[] = $decoded;
				} else {
					$events = $decoded;
				}
			}
		} else {
			// sendBeacon sends as text/plain
			$decoded = json_decode($body, true);
			if (is_array($decoded)) {
				if (isset($decoded['label'])) {
					$events[] = $decoded;
				} else {
					$events = $decoded;
				}
			}
		}

		if (empty($events)) {
			return array('status' => 400, 'body' => 'No events',
				'headers' => self::corsHeaders());
		}

		// Determine client IP
		$headers = $parsed['headers'] ?? array();
		$ip = self::clientIp($parsed);

		// User agent
		$ua = $headers['user-agent'] ?? '';

		// Write events to TSV
		$time = date('Y-m-d\TH:i:s');
		$tsvFile = self::$tsvDir . '/' . date('Y-m-d') . '.tsv';
		$lines = '';

		foreach ($events as $event) {
			if (!is_array($event)) continue;
			$label = $event['label'] ?? '';
			if ($label === '') continue;

			$visitor = self::sanitize($event['visitor'] ?? '');
			$session = self::sanitize($event['session'] ?? '');
			$origin = self::sanitize($event['origin'] ?? '');
			$url = self::sanitize($event['url'] ?? '');
			$page = self::sanitize($event['page'] ?? '');
			$data = '';
			if (isset($event['data'])) {
				$data = self::sanitize(
					is_string($event['data']) ? $event['data'] : json_encode($event['data'])
				);
			}

			$lines .= implode("\t", array(
				$time,
				self::sanitize($ip),
				$visitor,
				$session,
				$origin,
				$url,
				$page,
				self::sanitize($label),
				$data,
				self::sanitize(substr($ua, 0, 255))
			)) . "\n";
		}

		if ($lines !== '') {
			// Write header if file is new
			if (!file_exists($tsvFile)) {
				$header = implode("\t", array(
					'time', 'ip', 'visitor', 'session', 'origin',
					'url', 'page', 'label', 'data', 'ua'
				)) . "\n";
				file_put_contents($tsvFile, $header, LOCK_EX);
			}
			file_put_contents($tsvFile, $lines, FILE_APPEND | LOCK_EX);
		}

		return array(
			'status' => 204,
			'body' => '',
			'headers' => self::corsHeaders()
		);
	}

	// ── TSV Query / Export ─────────────────────────────────

	/**
	 * List available TSV files (dates).
	 * @return array ['2024-01-15', '2024-01-16', ...]
	 */
	static function listDates()
	{
		$files = glob(self::$tsvDir . '/*.tsv');
		$dates = array();
		foreach ($files as $f) {
			$dates[] = basename($f, '.tsv');
		}
		sort($dates);
		return $dates;
	}

	/**
	 * Read a TSV file for a given date, optionally filtered.
	 *
	 * @param string $date      YYYY-MM-DD
	 * @param array  $filters   Optional {label: "prefix", visitor: "id", page: "prefix"}
	 * @param int    $limit     Max rows (0 = all)
	 * @return array {columns: [...], rows: [[...], ...], total: N}
	 */
	static function query($date, $filters = array(), $limit = 0)
	{
		$file = self::$tsvDir . '/' . $date . '.tsv';
		if (!file_exists($file)) {
			return array('columns' => array(), 'rows' => array(), 'total' => 0);
		}

		$fp = fopen($file, 'r');
		if (!$fp) return array('columns' => array(), 'rows' => array(), 'total' => 0);

		$columns = fgetcsv($fp, 0, "\t");
		if (!$columns) { fclose($fp); return array('columns' => array(), 'rows' => array(), 'total' => 0); }

		$rows = array();
		$total = 0;
		$labelFilter = $filters['label'] ?? '';
		$visitorFilter = $filters['visitor'] ?? '';
		$pageFilter = $filters['page'] ?? '';

		while (($line = fgetcsv($fp, 0, "\t")) !== false) {
			if (count($line) < count($columns)) continue;
			$row = array_combine($columns, $line);

			// Apply filters
			if ($labelFilter && strpos($row['label'] ?? '', $labelFilter) !== 0) continue;
			if ($visitorFilter && ($row['visitor'] ?? '') !== $visitorFilter) continue;
			if ($pageFilter && strpos($row['page'] ?? '', $pageFilter) !== 0) continue;

			$total++;
			if ($limit <= 0 || count($rows) < $limit) {
				$rows[] = $row;
			}
		}
		fclose($fp);

		return array('columns' => $columns, 'rows' => $rows, 'total' => $total);
	}

	/**
	 * Get the raw TSV content for a date (for download).
	 * @param string $date  YYYY-MM-DD
	 * @return string|null
	 */
	static function rawTsv($date)
	{
		$file = self::$tsvDir . '/' . $date . '.tsv';
		if (!file_exists($file)) return null;
		return file_get_contents($file);
	}

	/**
	 * Summary stats for a given date.
	 * @param string $date  YYYY-MM-DD
	 * @return array {events, visitors, sessions, topLabels, topPages}
	 */
	static function summary($date)
	{
		$file = self::$tsvDir . '/' . $date . '.tsv';
		if (!file_exists($file)) {
			return array('events' => 0, 'visitors' => 0, 'sessions' => 0,
				'topLabels' => array(), 'topPages' => array());
		}

		$fp = fopen($file, 'r');
		if (!$fp) return array('events' => 0, 'visitors' => 0, 'sessions' => 0,
			'topLabels' => array(), 'topPages' => array());

		$columns = fgetcsv($fp, 0, "\t");
		if (!$columns) { fclose($fp); return array('events' => 0); }

		$events = 0;
		$visitors = array();
		$sessions = array();
		$labels = array();
		$pages = array();

		while (($line = fgetcsv($fp, 0, "\t")) !== false) {
			if (count($line) < count($columns)) continue;
			$row = array_combine($columns, $line);
			$events++;
			if (!empty($row['visitor'])) $visitors[$row['visitor']] = true;
			if (!empty($row['session'])) $sessions[$row['session']] = true;
			$lbl = $row['label'] ?? '';
			// Group labels by prefix (before the colon)
			$prefix = strpos($lbl, ':') !== false ? substr($lbl, 0, strpos($lbl, ':')) : $lbl;
			$labels[$prefix] = ($labels[$prefix] ?? 0) + 1;
			if (!empty($row['page'])) {
				$pages[$row['page']] = ($pages[$row['page']] ?? 0) + 1;
			}
		}
		fclose($fp);

		arsort($labels);
		arsort($pages);

		return array(
			'events' => $events,
			'visitors' => count($visitors),
			'sessions' => count($sessions),
			'topLabels' => array_slice($labels, 0, 20, true),
			'topPages' => array_slice($pages, 0, 20, true)
		);
	}

	/**
	 * Purge TSV files older than retainDays.
	 */
	static function purgeOld()
	{
		$days = Q_Config::get('Q', 'webserver', 'clientMetrics', 'retainDays', 90);
		$cutoff = date('Y-m-d', strtotime("-{$days} days"));
		$files = glob(self::$tsvDir . '/*.tsv');
		foreach ($files as $f) {
			$date = basename($f, '.tsv');
			if ($date < $cutoff) {
				@unlink($f);
			}
		}
	}

	// ── Panel API ─────────────────────────────────────────

	/**
	 * Handle panel API requests for client metrics data.
	 * Routes:
	 *   GET  /Q/panel/api/client-metrics          — list dates + today's summary
	 *   GET  /Q/panel/api/client-metrics/YYYY-MM-DD — summary for that date
	 *   GET  /Q/panel/api/client-metrics/YYYY-MM-DD/tsv — raw TSV download
	 *   GET  /Q/panel/api/client-metrics/YYYY-MM-DD/events — filtered events JSON
	 *
	 * @param string $subPath  The path after /Q/panel/api/client-metrics
	 * @param array  $parsed   The parsed request
	 * @return array {status, body, headers}
	 */
	static function handlePanelApi($subPath, $parsed)
	{
		if (!self::enabled()) {
			return array('status' => 404, 'body' => json_encode(array('error' => 'Client metrics not enabled')),
				'headers' => array('Content-Type' => 'application/json'));
		}

		$parts = array_values(array_filter(explode('/', $subPath)));
		$json = array('Content-Type' => 'application/json');

		// GET /Q/panel/api/client-metrics — overview
		if (empty($parts)) {
			$dates = self::listDates();
			$today = date('Y-m-d');
			$todaySummary = in_array($today, $dates) ? self::summary($today) : null;
			return array('status' => 200,
				'body' => json_encode(array('dates' => $dates, 'today' => $todaySummary)),
				'headers' => $json);
		}

		$date = $parts[0];
		// Validate date format
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			return array('status' => 400, 'body' => json_encode(array('error' => 'Invalid date')),
				'headers' => $json);
		}

		$action = $parts[1] ?? '';

		if ($action === 'tsv') {
			$tsv = self::rawTsv($date);
			if ($tsv === null) {
				return array('status' => 404, 'body' => 'Not found');
			}
			return array('status' => 200, 'body' => $tsv,
				'headers' => array(
					'Content-Type' => 'text/tab-separated-values; charset=utf-8',
					'Content-Disposition' => 'attachment; filename="client-metrics-' . $date . '.tsv"'
				));
		}

		if ($action === 'events') {
			$qs = array();
			if (!empty($parsed['query'])) parse_str($parsed['query'], $qs);
			$filters = array();
			if (!empty($qs['label'])) $filters['label'] = $qs['label'];
			if (!empty($qs['visitor'])) $filters['visitor'] = $qs['visitor'];
			if (!empty($qs['page'])) $filters['page'] = $qs['page'];
			$limit = isset($qs['limit']) ? intval($qs['limit']) : 500;
			$result = self::query($date, $filters, $limit);
			return array('status' => 200, 'body' => json_encode($result), 'headers' => $json);
		}

		// Default: summary for that date
		$summary = self::summary($date);
		return array('status' => 200, 'body' => json_encode($summary), 'headers' => $json);
	}

	// ── Helpers ────────────────────────────────────────────

	private static function tsvDir()
	{
		$dir = Q_Config::get('Q', 'webserver', 'clientMetrics', 'tsvDir', 'local/client-metrics');
		if ($dir[0] !== '/') {
			// Relative to server working directory
			$dir = getcwd() . '/' . $dir;
		}
		return $dir;
	}

	private static function clientIp($parsed)
	{
		$headers = $parsed['headers'] ?? array();
		// Cloudflare
		if (!empty($headers['cf-connecting-ip'])) return $headers['cf-connecting-ip'];
		// Standard proxies
		if (!empty($headers['x-real-ip'])) return $headers['x-real-ip'];
		if (!empty($headers['x-forwarded-for'])) {
			$parts = explode(',', $headers['x-forwarded-for']);
			return trim($parts[0]);
		}
		return $parsed['_remoteAddr'] ?? '0.0.0.0';
	}

	/**
	 * Sanitize a value for TSV storage — strip tabs, newlines,
	 * and control characters.
	 */
	private static function sanitize($val)
	{
		return preg_replace('/[\t\r\n\x00-\x1f]/', ' ', (string) $val);
	}

	private static function corsHeaders()
	{
		return array(
			'Access-Control-Allow-Origin' => '*',
			'Access-Control-Allow-Methods' => 'POST, OPTIONS',
			'Access-Control-Allow-Headers' => 'Content-Type'
		);
	}
}
