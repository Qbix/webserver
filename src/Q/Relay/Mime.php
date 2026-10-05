<?php
/**
 * Q_Relay_Mime
 *
 * MIME parsing utilities for the Qbix Server relay system.
 * PHP port of the MIME parsing functions from the Node.js smtp.js relay.
 *
 * All methods are static; no constructor needed.
 */
class Q_Relay_Mime
{
	/**
	 * Decode RFC 2047 encoded-word sequences.
	 * Handles both Base64 (?B?) and Quoted-Printable (?Q?) encodings.
	 *
	 * @param string $s The string potentially containing encoded words
	 * @return string The decoded string
	 */
	static function decodeRFC2047($s)
	{
		return preg_replace_callback(
			'/=\?([^?]+)\?(B|Q)\?([^?]*)\?=/i',
			function ($m) {
				$charset = $m[1];
				$encoding = strtoupper($m[2]);
				$encoded = $m[3];

				if ($encoding === 'B') {
					$decoded = base64_decode($encoded);
				} else {
					// Q encoding: underscores represent spaces,
					// =XX are hex-encoded bytes
					$decoded = str_replace('_', ' ', $encoded);
					$decoded = preg_replace_callback(
						'/=([0-9A-Fa-f]{2})/',
						function ($hex) {
							return chr(hexdec($hex[1]));
						},
						$decoded
					);
				}

				if ($decoded === false) {
					return $m[0];
				}

				$upper = strtoupper($charset);
				if ($upper !== 'UTF-8' && $upper !== 'US-ASCII') {
					$converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
					if ($converted !== false) {
						$decoded = $converted;
					}
				}

				return $decoded;
			},
			$s
		);
	}

	/**
	 * Split a raw email message into headers and body at the first blank line.
	 *
	 * @param string $raw The raw email message
	 * @return array{headers: string, body: string}
	 */
	static function splitHeaderBody($raw)
	{
		// Look for \r\n\r\n first, then \n\n
		$pos = strpos($raw, "\r\n\r\n");
		if ($pos !== false) {
			return array(
				'headers' => substr($raw, 0, $pos),
				'body' => substr($raw, $pos + 4)
			);
		}

		$pos = strpos($raw, "\n\n");
		if ($pos !== false) {
			return array(
				'headers' => substr($raw, 0, $pos),
				'body' => substr($raw, $pos + 2)
			);
		}

		// No blank line found — entire message is headers
		return array(
			'headers' => $raw,
			'body' => ''
		);
	}

	/**
	 * Parse raw header text into an associative array.
	 * Handles continuation lines (lines starting with whitespace).
	 * Keys are lowercased; values are arrays of strings.
	 *
	 * @param string $raw The raw header block
	 * @return array<string, string[]>
	 */
	static function parseHeaders($raw)
	{
		$headers = array();
		$lines = preg_split('/\r?\n/', $raw);
		$currentName = null;
		$currentValue = null;

		foreach ($lines as $line) {
			if ($line === '') {
				continue;
			}

			// Continuation line: starts with space or tab
			if (($line[0] === ' ' || $line[0] === "\t") && $currentName !== null) {
				$currentValue .= ' ' . trim($line);
				continue;
			}

			// Save previous header
			if ($currentName !== null) {
				$key = strtolower($currentName);
				if (!isset($headers[$key])) {
					$headers[$key] = array();
				}
				$headers[$key][] = $currentValue;
			}

			// Parse new header line
			$colonPos = strpos($line, ':');
			if ($colonPos !== false) {
				$currentName = substr($line, 0, $colonPos);
				$currentValue = ltrim(substr($line, $colonPos + 1));
			} else {
				$currentName = null;
				$currentValue = null;
			}
		}

		// Don't forget the last header
		if ($currentName !== null) {
			$key = strtolower($currentName);
			if (!isset($headers[$key])) {
				$headers[$key] = array();
			}
			$headers[$key][] = $currentValue;
		}

		return $headers;
	}

	/**
	 * Get the first value of a header, decoded via decodeRFC2047.
	 *
	 * @param array<string, string[]> $headers Parsed headers array
	 * @param string $name Header name (case-insensitive)
	 * @return string|null The decoded header value, or null if not found
	 */
	static function headerValue($headers, $name)
	{
		$key = strtolower($name);
		if (!isset($headers[$key]) || empty($headers[$key])) {
			return null;
		}
		return self::decodeRFC2047($headers[$key][0]);
	}

	/**
	 * Extract the boundary parameter from a Content-Type header value.
	 *
	 * @param string $contentType The Content-Type header value
	 * @return string|null The boundary string, or null if not found
	 */
	static function extractBoundary($contentType)
	{
		if (preg_match('/boundary\s*=\s*"([^"]+)"/i', $contentType, $m)) {
			return $m[1];
		}
		if (preg_match('/boundary\s*=\s*([^\s;]+)/i', $contentType, $m)) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Split a multipart body by boundary markers.
	 *
	 * @param string $body The multipart body
	 * @param string $boundary The boundary string
	 * @return string[] Array of part strings (without boundary lines)
	 */
	static function splitMultipart($body, $boundary)
	{
		$delimiter = '--' . $boundary;
		$closing = $delimiter . '--';
		$parts = array();

		// Split on the boundary
		$segments = explode($delimiter, $body);

		// First segment is the preamble (before the first boundary), skip it.
		// Last segment after closing delimiter is epilogue, skip it.
		$count = count($segments);
		for ($i = 1; $i < $count; $i++) {
			$segment = $segments[$i];

			// If this segment starts with '--', it's the closing boundary
			if (strncmp($segment, '--', 2) === 0) {
				break;
			}

			// Strip the leading \r\n or \n after the boundary line
			if (strncmp($segment, "\r\n", 2) === 0) {
				$segment = substr($segment, 2);
			} elseif (strncmp($segment, "\n", 1) === 0) {
				$segment = substr($segment, 1);
			}

			// Strip trailing \r\n or \n before next boundary
			if (substr($segment, -2) === "\r\n") {
				$segment = substr($segment, 0, -2);
			} elseif (substr($segment, -1) === "\n") {
				$segment = substr($segment, 0, -1);
			}

			$parts[] = $segment;
		}

		return $parts;
	}

	/**
	 * Extract filename from Content-Disposition or Content-Type headers.
	 * Handles RFC 5987 encoding (filename*=utf-8''...) and RFC 2047.
	 *
	 * @param array<string, string[]> $headers Parsed headers array
	 * @return string The filename, defaulting to "attachment"
	 */
	static function extractFilename($headers)
	{
		$disposition = self::headerValue($headers, 'content-disposition');
		$contentType = self::headerValue($headers, 'content-type');

		$sources = array();
		if ($disposition !== null) {
			$sources[] = $disposition;
		}
		if ($contentType !== null) {
			$sources[] = $contentType;
		}

		foreach ($sources as $header) {
			// Try RFC 5987 extended parameter first: filename*=utf-8''encoded
			if (preg_match("/filename\\*\\s*=\\s*(?:UTF-8|utf-8)''([^;\\s]+)/i", $header, $m)) {
				return rawurldecode($m[1]);
			}

			// Try quoted filename
			if (preg_match('/filename\s*=\s*"([^"]+)"/i', $header, $m)) {
				return self::decodeRFC2047($m[1]);
			}

			// Try unquoted filename
			if (preg_match('/filename\s*=\s*([^\s;]+)/i', $header, $m)) {
				return self::decodeRFC2047($m[1]);
			}

			// Try "name" parameter (common in Content-Type)
			if (preg_match('/\bname\s*=\s*"([^"]+)"/i', $header, $m)) {
				return self::decodeRFC2047($m[1]);
			}
			if (preg_match('/\bname\s*=\s*([^\s;]+)/i', $header, $m)) {
				return self::decodeRFC2047($m[1]);
			}
		}

		return 'attachment';
	}

	/**
	 * Decode quoted-printable content.
	 * Removes soft line breaks and decodes =XX hex sequences.
	 *
	 * @param string $qp The quoted-printable encoded string
	 * @return string The decoded string
	 */
	static function decodeQuotedPrintable($qp)
	{
		// Remove soft line breaks (= at end of line)
		$qp = preg_replace('/=\r?\n/', '', $qp);

		// Decode =XX hex sequences
		$decoded = preg_replace_callback(
			'/=([0-9A-Fa-f]{2})/',
			function ($m) {
				return chr(hexdec($m[1]));
			},
			$qp
		);

		return $decoded;
	}

	/**
	 * Recursively parse MIME structure, collecting text, HTML, and attachments.
	 *
	 * @param string $body The body to parse
	 * @param array<string, string[]> $headers Parsed headers for this part
	 * @param array $results Reference to results array with keys: text, html, attachments
	 * @param int $depth Current recursion depth (max 20)
	 * @return void
	 */
	static function parseMIMERecursive($body, $headers, &$results, $depth = 0)
	{
		if ($depth > 20) {
			return;
		}

		$contentType = self::headerValue($headers, 'content-type');
		if ($contentType === null) {
			$contentType = 'text/plain';
		}
		$ct = strtolower(trim(preg_replace('/;.*$/', '', $contentType)));

		$encoding = self::headerValue($headers, 'content-transfer-encoding');
		$encoding = ($encoding !== null) ? strtolower(trim($encoding)) : '7bit';

		$disposition = self::headerValue($headers, 'content-disposition');
		$dispositionType = null;
		if ($disposition !== null) {
			$dispositionType = strtolower(trim(preg_replace('/;.*$/', '', $disposition)));
		}

		// Check if this is a multipart type
		if (strncmp($ct, 'multipart/', 10) === 0) {
			$boundary = self::extractBoundary($contentType);
			if ($boundary !== null) {
				$parts = self::splitMultipart($body, $boundary);
				foreach ($parts as $part) {
					$split = self::splitHeaderBody($part);
					$partHeaders = self::parseHeaders($split['headers']);
					self::parseMIMERecursive(
						$split['body'], $partHeaders,
						$results, $depth + 1
					);
				}
			}
			return;
		}

		// Decode the body content
		$content = $body;
		if ($encoding === 'base64') {
			$content = base64_decode(preg_replace('/\s+/', '', $content));
			if ($content === false) {
				$content = '';
			}
		} elseif ($encoding === 'quoted-printable') {
			$content = self::decodeQuotedPrintable($content);
		}

		// Check for charset and convert if needed
		if (strncmp($ct, 'text/', 5) === 0) {
			$charset = null;
			if (preg_match('/charset\s*=\s*"?([^";\\s]+)"?/i', $contentType, $m)) {
				$charset = strtoupper(trim($m[1]));
			}
			if ($charset !== null && $charset !== 'UTF-8' && $charset !== 'US-ASCII') {
				$converted = @mb_convert_encoding($content, 'UTF-8', $charset);
				if ($converted !== false) {
					$content = $converted;
				}
			}
		}

		// Determine if this is an attachment
		$isAttachment = ($dispositionType === 'attachment');
		$isInlineImage = false;

		if (!$isAttachment && $dispositionType === 'inline'
			&& strncmp($ct, 'text/', 5) !== 0
		) {
			// Inline non-text content (e.g., inline images)
			$isInlineImage = (strncmp($ct, 'image/', 6) === 0);
			$isAttachment = true;
		}

		if (!$isAttachment && strncmp($ct, 'text/', 5) !== 0) {
			// Non-text content without explicit disposition is an attachment
			$isAttachment = true;
			$isInlineImage = (strncmp($ct, 'image/', 6) === 0);
		}

		if ($isAttachment) {
			$filename = self::extractFilename($headers);
			$results['attachments'][] = array(
				'filename' => $filename,
				'size' => strlen($content),
				'contentType' => $ct,
				'content' => $content,
				'isInlineImage' => $isInlineImage
			);
			return;
		}

		// Text content
		if ($ct === 'text/plain') {
			$results['text'][] = $content;
		} elseif ($ct === 'text/html') {
			$results['html'][] = $content;
		}
	}

	/**
	 * Parse a full raw email message into its MIME components.
	 *
	 * @param string $raw The complete raw email message
	 * @return array{text: string[], html: string[], attachments: array[]}
	 */
	static function parseFullMIME($raw)
	{
		$split = self::splitHeaderBody($raw);
		$headers = self::parseHeaders($split['headers']);
		$results = array(
			'text' => array(),
			'html' => array(),
			'attachments' => array()
		);
		self::parseMIMERecursive($split['body'], $headers, $results);
		return $results;
	}

	/**
	 * Sanitize HTML content for safe display.
	 * Strips <script> tags and on* event attributes.
	 * Replaces <img> tags with cid: sources when inline images were dropped.
	 *
	 * @param string $html The HTML content to sanitize
	 * @param bool $droppedInline Whether inline images were dropped
	 * @return string The sanitized HTML
	 */
	static function sanitizeHTML($html, $droppedInline)
	{
		// Remove <script> tags and their contents
		$html = preg_replace(
			'/<script\b[^>]*>.*?<\/script\s*>/is',
			'',
			$html
		);

		// Remove on* event attributes
		$html = preg_replace(
			'/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i',
			'',
			$html
		);

		// If inline images were dropped, replace cid: img sources
		// with a placeholder indicating the image was removed
		if ($droppedInline) {
			$html = preg_replace(
				'/<img\b([^>]*)\bsrc\s*=\s*["\']cid:[^"\']*["\']([^>]*)>/i',
				'<img$1src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" alt="[inline image removed]"$2>',
				$html
			);
		}

		return $html;
	}

	/**
	 * Escape HTML entities for safe insertion into HTML.
	 *
	 * @param string $s The string to escape
	 * @return string The escaped string
	 */
	static function escapeHtml($s)
	{
		return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}
}
