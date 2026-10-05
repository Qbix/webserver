<?php
/**
 * Q_Relay_EmailTracker
 *
 * Instruments outgoing HTML emails with:
 *   1. A tracking pixel (1x1 transparent GIF) for open detection
 *   2. Link wrapping for click-through measurement
 *
 * All tracking routes are handled by WebServer at:
 *   GET /Q/relay/open?id=TRACKING_ID   → 1x1 GIF + log open event
 *   GET /Q/relay/click?id=TRACKING_ID&url=DESTINATION → 302 redirect + log click
 *
 * Config (Q.relay.tracking):
 *   enabled:      true/false (default true)
 *   baseUrl:      override base URL for tracking links (auto-detected from Host header)
 *   excludePatterns: array of URL patterns to skip wrapping (e.g. unsubscribe links)
 */
class Q_Relay_EmailTracker
{
	/**
	 * Check if email tracking is enabled.
	 * @return bool
	 */
	static function enabled()
	{
		return Q_Config::get('Q', 'relay', 'tracking', 'enabled', true);
	}

	/**
	 * Instrument an outgoing HTML email with tracking pixel and link wrapping.
	 *
	 * @param string $rawMessage  The raw RFC 5322 message
	 * @param string $from        Envelope sender
	 * @param string $to          Envelope recipient
	 * @param string|null $template  Template name for funnel analysis
	 * @param string|null $appHost   App host for multi-tenant tracking
	 * @return string The modified raw message with tracking injected
	 */
	static function instrument($rawMessage, $from, $to, $template = null, $appHost = null)
	{
		if (!self::enabled()) return $rawMessage;

		// Parse headers to get message-id and subject
		$split = Q_Relay_Mime::splitHeaderBody($rawMessage);
		$headers = Q_Relay_Mime::parseHeaders($split['headers']);
		$messageId = Q_Relay_Mime::headerValue($headers, 'message-id');
		$subject = Q_Relay_Mime::headerValue($headers, 'subject');
		$contentType = Q_Relay_Mime::headerValue($headers, 'content-type');

		// Create tracking record
		$trackingId = Q_Relay_Db::createTracking(
			$messageId, $from, $to, $subject, $template, $appHost
		);
		if (!$trackingId) return $rawMessage;

		$baseUrl = self::baseUrl();

		// Process based on content type
		if (stripos($contentType, 'multipart/') !== false) {
			// Multipart message — find and modify the HTML part
			$rawMessage = self::instrumentMultipart($rawMessage, $trackingId, $baseUrl);
		} elseif (stripos($contentType, 'text/html') !== false) {
			// Simple HTML message
			$body = $split['body'];
			$body = self::wrapLinks($body, $trackingId, $baseUrl);
			$body = self::addPixel($body, $trackingId, $baseUrl);
			$rawMessage = $split['headers'] . "\r\n\r\n" . $body;
		}
		// text/plain messages — no tracking possible

		return $rawMessage;
	}

	/**
	 * Handle tracking pixel request: log open event and return 1x1 GIF.
	 *
	 * @param string $trackingId
	 * @param array $parsed  Request data for IP/UA extraction
	 * @return array {status, body, headers}
	 */
	static function handleOpen($trackingId, $parsed)
	{
		$reqHeaders = $parsed['headers'] ?? array();
		$ip = self::clientIp($parsed);
		$ua = $reqHeaders['user-agent'] ?? '';

		Q_Relay_Db::logEmailEvent($trackingId, 'open', null, $ip, $ua);

		// 1x1 transparent GIF
		$gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
		return array(
			'status' => 200,
			'body' => $gif,
			'headers' => array(
				'Content-Type' => 'image/gif',
				'Cache-Control' => 'no-store, no-cache, must-revalidate',
				'Pragma' => 'no-cache',
				'Expires' => '0'
			)
		);
	}

	/**
	 * Handle click tracking: log click event and redirect.
	 *
	 * @param string $trackingId
	 * @param string $url  Destination URL
	 * @param array $parsed  Request data for IP/UA extraction
	 * @return array {status, body, headers}
	 */
	static function handleClick($trackingId, $url, $parsed)
	{
		$reqHeaders = $parsed['headers'] ?? array();
		$ip = self::clientIp($parsed);
		$ua = $reqHeaders['user-agent'] ?? '';

		// Validate URL to prevent open redirect
		if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
			return array('status' => 400, 'body' => 'Invalid URL');
		}

		Q_Relay_Db::logEmailEvent($trackingId, 'click', $url, $ip, $ua);

		return array(
			'status' => 302,
			'body' => '',
			'headers' => array(
				'Location' => $url,
				'Cache-Control' => 'no-store'
			)
		);
	}

	// ── Internal ──

	/**
	 * Get the base URL for tracking links.
	 */
	private static function baseUrl()
	{
		$configured = Q_Config::get('Q', 'relay', 'tracking', 'baseUrl', null);
		if ($configured) return rtrim($configured, '/');

		// Auto-detect from server config
		$host = Q_Config::get('Q', 'webserver', 'host', 'localhost');
		$port = Q_Config::get('Q', 'webserver', 'port', 443);
		$scheme = ($port == 443) ? 'https' : 'http';
		$portSuffix = ($port == 443 || $port == 80) ? '' : ':' . $port;
		return $scheme . '://' . $host . $portSuffix;
	}

	/**
	 * Wrap <a href="..."> links in HTML body with click tracking redirects.
	 */
	private static function wrapLinks($html, $trackingId, $baseUrl)
	{
		$excludePatterns = Q_Config::get('Q', 'relay', 'tracking', 'excludePatterns', array());

		return preg_replace_callback(
			'/<a\s([^>]*?)href=["\']([^"\']+)["\']([^>]*?)>/i',
			function ($m) use ($trackingId, $baseUrl, $excludePatterns) {
				$url = $m[2];

				// Skip non-http links (mailto:, tel:, #, etc.)
				if (!preg_match('#^https?://#i', $url)) return $m[0];

				// Skip excluded patterns
				foreach ($excludePatterns as $pattern) {
					if (strpos($url, $pattern) !== false) return $m[0];
				}

				$wrappedUrl = $baseUrl . '/Q/relay/click?id='
					. urlencode($trackingId) . '&url=' . urlencode($url);

				return '<a ' . $m[1] . 'href="' . htmlspecialchars($wrappedUrl) . '"' . $m[3] . '>';
			},
			$html
		);
	}

	/**
	 * Insert a tracking pixel before </body> (or at end of HTML).
	 */
	private static function addPixel($html, $trackingId, $baseUrl)
	{
		$pixelUrl = $baseUrl . '/Q/relay/open?id=' . urlencode($trackingId);
		$pixel = '<img src="' . htmlspecialchars($pixelUrl)
			. '" width="1" height="1" alt="" style="display:none;border:0;" />';

		if (stripos($html, '</body>') !== false) {
			return str_ireplace('</body>', $pixel . '</body>', $html);
		}
		// No </body> tag — append
		return $html . $pixel;
	}

	/**
	 * Process multipart message to find and instrument the HTML part.
	 */
	private static function instrumentMultipart($rawMessage, $trackingId, $baseUrl)
	{
		// Find the boundary from Content-Type header
		if (!preg_match('/boundary=["\']?([^"\'\s;]+)/i', $rawMessage, $bm)) {
			return $rawMessage;
		}
		$boundary = $bm[1];

		// Split into parts
		$parts = explode('--' . $boundary, $rawMessage);
		$modified = false;

		for ($i = 0; $i < count($parts); $i++) {
			// Look for text/html content type in each part
			if (stripos($parts[$i], 'text/html') !== false && !$modified) {
				// Split this part's headers from body
				$partSplit = preg_split('/\r?\n\r?\n/', $parts[$i], 2);
				if (count($partSplit) === 2) {
					$partBody = $partSplit[1];

					// Check for content-transfer-encoding
					$isBase64 = (stripos($partSplit[0], 'base64') !== false);
					$isQP = (stripos($partSplit[0], 'quoted-printable') !== false);

					if ($isBase64) {
						$decoded = base64_decode($partBody);
						$decoded = self::wrapLinks($decoded, $trackingId, $baseUrl);
						$decoded = self::addPixel($decoded, $trackingId, $baseUrl);
						$partBody = chunk_split(base64_encode($decoded));
					} elseif ($isQP) {
						$decoded = quoted_printable_decode($partBody);
						$decoded = self::wrapLinks($decoded, $trackingId, $baseUrl);
						$decoded = self::addPixel($decoded, $trackingId, $baseUrl);
						$partBody = quoted_printable_encode($decoded);
					} else {
						$partBody = self::wrapLinks($partBody, $trackingId, $baseUrl);
						$partBody = self::addPixel($partBody, $trackingId, $baseUrl);
					}

					$parts[$i] = $partSplit[0] . "\r\n\r\n" . $partBody;
					$modified = true;
				}
			}
		}

		return implode('--' . $boundary, $parts);
	}

	private static function clientIp($parsed)
	{
		$headers = $parsed['headers'] ?? array();
		if (!empty($headers['cf-connecting-ip'])) return $headers['cf-connecting-ip'];
		if (!empty($headers['x-real-ip'])) return $headers['x-real-ip'];
		if (!empty($headers['x-forwarded-for'])) {
			$parts = explode(',', $headers['x-forwarded-for']);
			return trim($parts[0]);
		}
		return $parsed['_remoteAddr'] ?? '0.0.0.0';
	}
}
