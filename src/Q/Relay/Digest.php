<?php
/**
 * Q_Relay_Digest
 *
 * Digest batching system for the Qbix Server relay.
 * PHP port of the digest logic from the Node.js smtp.js relay.
 *
 * Batches multiple messages from the same sender to the same recipient
 * into a single digest email, with exponential backoff delays. The system
 * is OFF by default — it delays and batches mail, which is right for
 * notification fan-out and wrong for password resets.
 *
 * First message to a sender/recipient pair goes straight out; subsequent
 * ones batch with exponential delay.
 *
 * All state is persisted to SQLite via Q_Relay_Db (digest_pending and
 * digest_state tables). No in-memory state that would be lost on restart.
 *
 * Config keys (Q_Config, with Users fallback):
 *   Q.relay.digest.enabled          — default false
 *   Q.relay.digest.firstDelay       — ms, default 60000 (1 min)
 *   Q.relay.digest.backoff          — multiplier, default 2.0
 *   Q.relay.digest.maxDelay         — ms, default 3600000 (1 hour)
 *   Q.relay.digest.maxMessages      — per digest, default 20
 *   Q.relay.digest.cooldownMinutes  — reset after quiet period, default 30
 *   Q.relay.digest.subject          — template, default "{{count}} Updates"
 *   Q.relay.digest.bypassHeader     — header name, default "x-no-digest"
 *   Q.relay.digest.ascending        — sort order, default true
 *   Q.relay.digest.separatorText    — text separator template
 *   Q.relay.digest.separatorHtml    — HTML separator template
 *   Q.relay.digest.separatorFirst   — show separator before first entry, default true
 *   Q.relay.digest.maxAttachSize    — per attachment, default 5242880 (5 MB)
 *   Q.relay.digest.maxTotalAttach   — total, default 10485760 (10 MB)
 *
 * @package Q
 */
class Q_Relay_Digest
{
	/**
	 * Normalize an email address for digest key construction.
	 * Lowercases, trims, strips angle brackets, and removes +suffix
	 * from the local part.
	 *
	 * @param string $addr Raw email address (may include angle brackets)
	 * @return string Normalized address
	 */
	static function normalizeAddress($addr)
	{
		$addr = strtolower(trim($addr));
		// Strip angle brackets: <user@example.com> → user@example.com
		$addr = trim($addr, '<>');
		$addr = trim($addr);

		// Strip +suffix from local part
		$atPos = strpos($addr, '@');
		if ($atPos !== false) {
			$local = substr($addr, 0, $atPos);
			$domain = substr($addr, $atPos);
			$plusPos = strpos($local, '+');
			if ($plusPos !== false) {
				$local = substr($local, 0, $plusPos);
			}
			$addr = $local . $domain;
		}

		return $addr;
	}

	/**
	 * Build the canonical digest key for a recipient+sender pair.
	 *
	 * This key MUST be constructed the same way everywhere it is used.
	 * Inconsistent key construction was the root cause of the 34,000-message
	 * incident on 2026-08-03.
	 *
	 * @param string $rcpt Recipient address
	 * @param string $mailFrom Sender address
	 * @return string Canonical key in the form "normalizedRcpt|normalizedFrom"
	 */
	static function digestKey($rcpt, $mailFrom)
	{
		return self::normalizeAddress($rcpt) . '|' . self::normalizeAddress($mailFrom);
	}

	/**
	 * Process an inbound message through the digest system.
	 *
	 * Called for each inbound message. Decides whether each recipient
	 * should receive the message immediately or have it batched into
	 * a pending digest.
	 *
	 * @param string $mailFrom Envelope sender
	 * @param string[] $rcptTo Array of envelope recipients
	 * @param string $rawMessage Complete raw MIME message
	 * @param array $options {
	 *     @type array $parsedHeaders Pre-parsed headers (optional, avoids re-parsing)
	 * }
	 * @return array {
	 *     @type string $action 'immediate' or 'digest'
	 *     @type string[] $recipients Recipients for immediate delivery (when action=immediate)
	 *     @type array[] $results Per-recipient results when mixed actions occur
	 * }
	 */
	static function processMessage($mailFrom, $rcptTo, $rawMessage, $options = [])
	{
		// Check if digest is enabled
		$enabled = self::configVal(
			'Q.relay.digest.enabled',
			'Users.relay.digest.enabled',
			false
		);
		if (!$enabled) {
			return [
				'action' => 'immediate',
				'recipients' => $rcptTo
			];
		}

		// Check for bypass header
		$bypassHeader = self::configVal(
			'Q.relay.digest.bypassHeader',
			'Users.relay.digest.bypassHeader',
			'x-no-digest'
		);

		if ($bypassHeader) {
			$headers = isset($options['parsedHeaders'])
				? $options['parsedHeaders']
				: null;
			if ($headers === null) {
				$split = Q_Relay_Mime::splitHeaderBody($rawMessage);
				$headers = Q_Relay_Mime::parseHeaders($split['headers']);
			}
			$bypassKey = strtolower($bypassHeader);
			if (isset($headers[$bypassKey]) && !empty($headers[$bypassKey])) {
				return [
					'action' => 'immediate',
					'recipients' => $rcptTo
				];
			}
		}

		$firstDelay = self::configInt(
			'Q.relay.digest.firstDelay',
			'Users.relay.digest.firstDelay',
			60000
		);
		$cooldownMinutes = self::configInt(
			'Q.relay.digest.cooldownMinutes',
			'Users.relay.digest.cooldownMinutes',
			30
		);

		$immediateRecipients = [];
		$digestRecipients = [];
		$results = [];
		$now = time();
		$normalizedFrom = self::normalizeAddress($mailFrom);

		foreach ($rcptTo as $rcpt) {
			$normalizedRcpt = self::normalizeAddress($rcpt);
			$state = Q_Relay_Db::getDigestState($normalizedRcpt, $normalizedFrom);

			if ($state === null) {
				// First message for this pair — send immediately, initialize state
				Q_Relay_Db::setDigestState(
					$normalizedRcpt,
					$normalizedFrom,
					$firstDelay,
					1
				);
				$immediateRecipients[] = $rcpt;
				$results[] = [
					'recipient' => $rcpt,
					'action' => 'immediate'
				];
				continue;
			}

			// Check if cooldown period has elapsed (quiet period resets state)
			$lastReceived = isset($state['last_received_at'])
				? (int)$state['last_received_at']
				: 0;
			$cooldownSeconds = $cooldownMinutes * 60;
			if ($lastReceived > 0 && ($now - $lastReceived) >= $cooldownSeconds) {
				// Cooldown elapsed — reset state, send immediately
				Q_Relay_Db::setDigestState(
					$normalizedRcpt,
					$normalizedFrom,
					$firstDelay,
					1
				);
				$immediateRecipients[] = $rcpt;
				$results[] = [
					'recipient' => $rcpt,
					'action' => 'immediate'
				];
				continue;
			}

			// Add to pending digest
			Q_Relay_Db::addDigestPending(
				$normalizedRcpt,
				$normalizedFrom,
				$rawMessage
			);

			$newCount = (int)$state['msg_count'] + 1;
			$currentDelay = (int)$state['next_delay_ms'];
			Q_Relay_Db::setDigestState(
				$normalizedRcpt,
				$normalizedFrom,
				$currentDelay,
				$newCount
			);

			$flushAt = $now + (int)($currentDelay / 1000);
			$digestRecipients[] = $rcpt;
			$results[] = [
				'recipient' => $rcpt,
				'action' => 'digest',
				'flushAt' => $flushAt
			];
		}

		// If all recipients are immediate
		if (empty($digestRecipients)) {
			return [
				'action' => 'immediate',
				'recipients' => $immediateRecipients
			];
		}

		// If all recipients are digest
		if (empty($immediateRecipients)) {
			return [
				'action' => 'digest',
				'results' => $results
			];
		}

		// Mixed: some immediate, some digest
		return [
			'action' => 'mixed',
			'recipients' => $immediateRecipients,
			'results' => $results
		];
	}

	/**
	 * Get the timestamp when a digest for this pair should next flush.
	 *
	 * @param string $recipient Recipient address (will be normalized)
	 * @param string $mailFrom Sender address (will be normalized)
	 * @return int|null Unix timestamp of next flush, or null if no pending digest
	 */
	static function getNextFlushTime($recipient, $mailFrom)
	{
		$normalizedRcpt = self::normalizeAddress($recipient);
		$normalizedFrom = self::normalizeAddress($mailFrom);

		$state = Q_Relay_Db::getDigestState($normalizedRcpt, $normalizedFrom);
		if ($state === null) {
			return null;
		}

		$lastReceived = isset($state['last_received_at'])
			? (int)$state['last_received_at']
			: 0;
		$nextDelay = isset($state['next_delay_ms'])
			? (int)$state['next_delay_ms']
			: 0;

		if ($lastReceived === 0 || $nextDelay === 0) {
			return null;
		}

		// Check that there are actually pending messages
		$pending = Q_Relay_Db::getDigestPending($normalizedRcpt, $normalizedFrom);
		if (empty($pending)) {
			return null;
		}

		return $lastReceived + (int)($nextDelay / 1000);
	}

	/**
	 * Get all pending digest flushes, sorted by flush time.
	 *
	 * Called by the main relay loop to know when to wake up.
	 *
	 * @return array[] Array of ['recipient' => string, 'mailFrom' => string, 'flushAt' => int]
	 *                 sorted by flushAt ascending
	 */
	static function getPendingFlushes()
	{
		$db = Q_Relay_Db::db();
		if (!$db) {
			return [];
		}

		try {
			$result = $db->query(
				'SELECT ds.recipient, ds.mail_from, ds.next_delay_ms, ds.last_received_at
				FROM digest_state ds
				WHERE EXISTS (
					SELECT 1 FROM digest_pending dp
					WHERE dp.recipient = ds.recipient
					AND dp.mail_from = ds.mail_from
				)'
			);

			$flushes = [];
			while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
				$lastReceived = (int)$row['last_received_at'];
				$nextDelay = (int)$row['next_delay_ms'];
				if ($lastReceived > 0) {
					$flushes[] = [
						'recipient' => $row['recipient'],
						'mailFrom' => $row['mail_from'],
						'flushAt' => $lastReceived + (int)($nextDelay / 1000)
					];
				}
			}

			// Sort by flushAt ascending
			usort($flushes, function ($a, $b) {
				return $a['flushAt'] - $b['flushAt'];
			});

			return $flushes;
		} catch (\Exception $e) {
			return [];
		}
	}

	/**
	 * Flush a pending digest for a recipient+sender pair.
	 *
	 * Collects all pending messages, builds a digest MIME message,
	 * updates the delay state (exponential backoff), and clears the
	 * pending queue.
	 *
	 * @param string $recipient Recipient address (will be normalized)
	 * @param string $mailFrom Sender address (will be normalized)
	 * @return array|null {
	 *     @type string $mime Complete MIME digest message
	 *     @type string $mailFrom Envelope sender
	 *     @type string $recipient Envelope recipient
	 *     @type int $count Number of messages included in the digest
	 * } or null if nothing to flush
	 */
	static function flush($recipient, $mailFrom)
	{
		$normalizedRcpt = self::normalizeAddress($recipient);
		$normalizedFrom = self::normalizeAddress($mailFrom);

		$pending = Q_Relay_Db::getDigestPending($normalizedRcpt, $normalizedFrom);
		if (empty($pending)) {
			return null;
		}

		$maxMessages = self::configInt(
			'Q.relay.digest.maxMessages',
			'Users.relay.digest.maxMessages',
			20
		);
		$backoff = self::configFloat(
			'Q.relay.digest.backoff',
			'Users.relay.digest.backoff',
			2.0
		);
		$maxDelay = self::configInt(
			'Q.relay.digest.maxDelay',
			'Users.relay.digest.maxDelay',
			3600000
		);
		$maxAttachSize = self::configInt(
			'Q.relay.digest.maxAttachSize',
			'Users.relay.digest.maxAttachSize',
			5242880
		);
		$maxTotalAttach = self::configInt(
			'Q.relay.digest.maxTotalAttach',
			'Users.relay.digest.maxTotalAttach',
			10485760
		);

		// Parse each pending message
		$entries = [];
		$allAttachments = [];
		$totalAttachSize = 0;
		$omitCount = 0;

		$messagesToProcess = array_slice($pending, 0, $maxMessages);
		$omitCount = max(0, count($pending) - $maxMessages);

		foreach ($messagesToProcess as $row) {
			$raw = $row['raw_message'];
			$split = Q_Relay_Mime::splitHeaderBody($raw);
			$headers = Q_Relay_Mime::parseHeaders($split['headers']);
			$parsed = Q_Relay_Mime::parseFullMIME($raw);

			$subject = Q_Relay_Mime::headerValue($headers, 'subject');
			$from = Q_Relay_Mime::headerValue($headers, 'from');
			$date = Q_Relay_Mime::headerValue($headers, 'date');

			$entries[] = [
				'subject' => $subject ?: '(no subject)',
				'from' => $from ?: $normalizedFrom,
				'date' => $date ?: '',
				'receivedAt' => (int)$row['received_at'],
				'text' => implode("\n", $parsed['text']),
				'html' => implode('', $parsed['html'])
			];

			// Collect attachments within size limits
			foreach ($parsed['attachments'] as $att) {
				if ($att['size'] > $maxAttachSize) {
					continue;
				}
				if ($totalAttachSize + $att['size'] > $maxTotalAttach) {
					continue;
				}
				$totalAttachSize += $att['size'];
				$allAttachments[] = $att;
			}
		}

		$count = count($entries);
		if ($count === 0) {
			Q_Relay_Db::clearDigestPending($normalizedRcpt, $normalizedFrom);
			return null;
		}

		// Build the digest MIME message
		$digestMime = self::buildDigestMIME(
			$normalizedRcpt,
			$entries,
			$allAttachments,
			$omitCount,
			$normalizedFrom
		);

		// Update state: multiply delay by backoff factor, cap at max delay
		$state = Q_Relay_Db::getDigestState($normalizedRcpt, $normalizedFrom);
		$currentDelay = ($state !== null && isset($state['next_delay_ms']))
			? (int)$state['next_delay_ms']
			: 60000;
		$newDelay = min((int)($currentDelay * $backoff), $maxDelay);
		Q_Relay_Db::setDigestState(
			$normalizedRcpt,
			$normalizedFrom,
			$newDelay,
			0
		);

		// Clear pending messages
		Q_Relay_Db::clearDigestPending($normalizedRcpt, $normalizedFrom);

		return [
			'mime' => $digestMime,
			'mailFrom' => $normalizedFrom,
			'recipient' => $normalizedRcpt,
			'count' => $count
		];
	}

	/**
	 * Build a multipart MIME digest message from parsed entries.
	 *
	 * @param string $recipient Normalized recipient address
	 * @param array[] $entries Parsed message entries, each with:
	 *     subject, from, date, receivedAt, text, html
	 * @param array[] $attachments Attachment arrays from Q_Relay_Mime
	 * @param int $omitCount Number of messages omitted due to maxMessages cap
	 * @param string $mailFrom Normalized sender address
	 * @return string Complete MIME message with headers
	 */
	static function buildDigestMIME($recipient, $entries, $attachments, $omitCount, $mailFrom)
	{
		$ascending = self::configVal(
			'Q.relay.digest.ascending',
			'Users.relay.digest.ascending',
			true
		);
		$separatorFirst = self::configVal(
			'Q.relay.digest.separatorFirst',
			'Users.relay.digest.separatorFirst',
			true
		);
		$separatorText = self::configVal(
			'Q.relay.digest.separatorText',
			'Users.relay.digest.separatorText',
			"\n--- Message {{n}}: {{subject}} (from {{from}}, {{datetime}}) ---\n\n"
		);
		$separatorHtml = self::configVal(
			'Q.relay.digest.separatorHtml',
			'Users.relay.digest.separatorHtml',
			'<hr><p style="color:#666;font-size:12px"><strong>Message {{n}}: {{subject}}</strong> &mdash; from {{from}}, {{datetime}}</p>'
		);
		$subjectTemplate = self::configVal(
			'Q.relay.digest.subject',
			'Users.relay.digest.subject',
			'{{count}} Updates'
		);

		// Sort entries
		if ($ascending) {
			usort($entries, function ($a, $b) {
				return $a['receivedAt'] - $b['receivedAt'];
			});
		} else {
			usort($entries, function ($a, $b) {
				return $b['receivedAt'] - $a['receivedAt'];
			});
		}

		// Build combined text and HTML parts
		$textParts = [];
		$htmlParts = [];
		$count = count($entries);

		foreach ($entries as $i => $entry) {
			$index = $i + 1;

			// Render separators
			if ($i === 0 && $separatorFirst) {
				if ($entry['text'] !== '') {
					$textParts[] = self::renderSeparator($separatorText, $entry, $index, false);
				}
				if ($entry['html'] !== '') {
					$htmlParts[] = self::renderSeparator($separatorHtml, $entry, $index, true);
				}
			} elseif ($i > 0) {
				if ($entry['text'] !== '' || !empty($textParts)) {
					$textParts[] = self::renderSeparator($separatorText, $entry, $index, false);
				}
				if ($entry['html'] !== '' || !empty($htmlParts)) {
					$htmlParts[] = self::renderSeparator($separatorHtml, $entry, $index, true);
				}
			}

			if ($entry['text'] !== '') {
				$textParts[] = $entry['text'];
			}
			if ($entry['html'] !== '') {
				$htmlParts[] = $entry['html'];
			}
		}

		// Add omitted-message notice
		if ($omitCount > 0) {
			$notice = sprintf(
				"\n\n[%d additional message(s) omitted from this digest]\n",
				$omitCount
			);
			$textParts[] = $notice;
			$htmlParts[] = sprintf(
				'<p style="color:#999;font-style:italic">[%d additional message(s) omitted from this digest]</p>',
				$omitCount
			);
		}

		$textBody = implode('', $textParts);
		$htmlBody = implode('', $htmlParts);

		$hasText = ($textBody !== '');
		$hasHtml = ($htmlBody !== '');
		$hasAttachments = !empty($attachments);

		// Build the subject line
		$subject = str_replace('{{count}}', (string)$count, $subjectTemplate);

		// Common headers
		$headerLines = [];
		$headerLines[] = 'From: ' . $mailFrom;
		$headerLines[] = 'To: ' . $recipient;
		$headerLines[] = 'Subject: ' . $subject;
		$headerLines[] = 'MIME-Version: 1.0';
		$headerLines[] = 'Date: ' . gmdate('D, d M Y H:i:s O');
		$headerLines[] = 'Message-ID: <digest-' . bin2hex(random_bytes(8)) . '@' . self::domainOf($mailFrom) . '>';

		// Build MIME body structure
		if (!$hasAttachments) {
			if ($hasText && $hasHtml) {
				// multipart/alternative
				$boundary = self::generateBoundary();
				$headerLines[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
				$body = self::buildAlternative($boundary, $textBody, $htmlBody);
			} elseif ($hasHtml) {
				$headerLines[] = 'Content-Type: text/html; charset=UTF-8';
				$headerLines[] = 'Content-Transfer-Encoding: quoted-printable';
				$body = self::encodeQuotedPrintable($htmlBody);
			} else {
				$headerLines[] = 'Content-Type: text/plain; charset=UTF-8';
				$headerLines[] = 'Content-Transfer-Encoding: quoted-printable';
				$body = self::encodeQuotedPrintable($textBody);
			}
		} else {
			// multipart/mixed wrapping the text content + attachments
			$mixedBoundary = self::generateBoundary();
			$headerLines[] = 'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"';

			$bodyParts = [];

			// Text/HTML content as the first part
			if ($hasText && $hasHtml) {
				$altBoundary = self::generateBoundary();
				$altHeaders = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';
				$altBody = self::buildAlternative($altBoundary, $textBody, $htmlBody);
				$bodyParts[] = $altHeaders . "\r\n\r\n" . $altBody;
			} elseif ($hasHtml) {
				$part = "Content-Type: text/html; charset=UTF-8\r\n";
				$part .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
				$part .= self::encodeQuotedPrintable($htmlBody);
				$bodyParts[] = $part;
			} else {
				$part = "Content-Type: text/plain; charset=UTF-8\r\n";
				$part .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
				$part .= self::encodeQuotedPrintable($textBody);
				$bodyParts[] = $part;
			}

			// Attachments
			foreach ($attachments as $att) {
				$part = 'Content-Type: ' . $att['contentType'] . '; name="' . $att['filename'] . "\"\r\n";
				$part .= 'Content-Disposition: attachment; filename="' . $att['filename'] . "\"\r\n";
				$part .= "Content-Transfer-Encoding: base64\r\n\r\n";
				$part .= chunk_split(base64_encode($att['content']), 76, "\r\n");
				$bodyParts[] = $part;
			}

			$body = '';
			foreach ($bodyParts as $bp) {
				$body .= '--' . $mixedBoundary . "\r\n" . $bp . "\r\n";
			}
			$body .= '--' . $mixedBoundary . "--\r\n";
		}

		return implode("\r\n", $headerLines) . "\r\n\r\n" . $body;
	}

	/**
	 * Render a separator template with placeholder substitution.
	 *
	 * Supported placeholders:
	 *   {{subject}}, {{from}}, {{datetime}}, {{date}}, {{time}}, {{n}}
	 *
	 * @param string $template The separator template string
	 * @param array $entry Parsed message entry with subject, from, date, receivedAt
	 * @param int $index 1-based message index
	 * @param bool $isHtml Whether to HTML-escape substituted values
	 * @return string Rendered separator
	 */
	static function renderSeparator($template, $entry, $index, $isHtml)
	{
		$dateStr = '';
		$timeStr = '';
		$datetimeStr = '';

		if (!empty($entry['date'])) {
			$ts = strtotime($entry['date']);
			if ($ts !== false) {
				$dateStr = date('Y-m-d', $ts);
				$timeStr = date('H:i', $ts);
				$datetimeStr = date('Y-m-d H:i', $ts);
			} else {
				$datetimeStr = $entry['date'];
				$dateStr = $entry['date'];
			}
		} elseif (!empty($entry['receivedAt'])) {
			$ts = (int)$entry['receivedAt'];
			$dateStr = date('Y-m-d', $ts);
			$timeStr = date('H:i', $ts);
			$datetimeStr = date('Y-m-d H:i', $ts);
		}

		$subject = $entry['subject'] ?: '(no subject)';
		$from = $entry['from'] ?: '';

		$replacements = [
			'{{subject}}' => $isHtml ? Q_Relay_Mime::escapeHtml($subject) : $subject,
			'{{from}}' => $isHtml ? Q_Relay_Mime::escapeHtml($from) : $from,
			'{{datetime}}' => $isHtml ? Q_Relay_Mime::escapeHtml($datetimeStr) : $datetimeStr,
			'{{date}}' => $isHtml ? Q_Relay_Mime::escapeHtml($dateStr) : $dateStr,
			'{{time}}' => $isHtml ? Q_Relay_Mime::escapeHtml($timeStr) : $timeStr,
			'{{n}}' => (string)$index,
		];

		return str_replace(
			array_keys($replacements),
			array_values($replacements),
			$template
		);
	}

	// ----------------------------------------------------------------
	// Internal helpers
	// ----------------------------------------------------------------

	/**
	 * Build a multipart/alternative body with text and HTML parts.
	 *
	 * @param string $boundary The MIME boundary
	 * @param string $textBody Plain text content
	 * @param string $htmlBody HTML content
	 * @return string The multipart body
	 */
	protected static function buildAlternative($boundary, $textBody, $htmlBody)
	{
		$body = '';
		$body .= '--' . $boundary . "\r\n";
		$body .= "Content-Type: text/plain; charset=UTF-8\r\n";
		$body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
		$body .= self::encodeQuotedPrintable($textBody) . "\r\n";
		$body .= '--' . $boundary . "\r\n";
		$body .= "Content-Type: text/html; charset=UTF-8\r\n";
		$body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
		$body .= self::encodeQuotedPrintable($htmlBody) . "\r\n";
		$body .= '--' . $boundary . "--\r\n";
		return $body;
	}

	/**
	 * Generate a random MIME boundary string.
	 *
	 * @return string
	 */
	protected static function generateBoundary()
	{
		return '----=_Digest_' . bin2hex(random_bytes(16));
	}

	/**
	 * Encode a string as quoted-printable for MIME transport.
	 *
	 * @param string $str The string to encode
	 * @return string Quoted-printable encoded string
	 */
	protected static function encodeQuotedPrintable($str)
	{
		return quoted_printable_encode($str);
	}

	/**
	 * Extract the domain from an email address.
	 *
	 * @param string $addr Email address
	 * @return string Domain part, or 'localhost' if no @ found
	 */
	protected static function domainOf($addr)
	{
		$atPos = strpos($addr, '@');
		if ($atPos !== false) {
			return substr($addr, $atPos + 1);
		}
		return 'localhost';
	}

	/**
	 * Read a config value, trying the primary key first and falling
	 * back to the platform key, then the default.
	 *
	 * @param string $primaryKey Dot-path for Q_Config::get()
	 * @param string $fallbackKey Dot-path for the platform fallback
	 * @param mixed $default Value if neither key is set
	 * @return mixed
	 */
	protected static function configVal($primaryKey, $fallbackKey, $default)
	{
		$parts = explode('.', $primaryKey);
		$value = call_user_func_array(
			['Q_Config', 'get'],
			array_merge($parts, [null])
		);
		if ($value !== null) {
			return $value;
		}

		$parts = explode('.', $fallbackKey);
		$value = call_user_func_array(
			['Q_Config', 'get'],
			array_merge($parts, [null])
		);
		if ($value !== null) {
			return $value;
		}

		return $default;
	}

	/**
	 * Read an integer config value with primary/fallback/default.
	 *
	 * @param string $primaryKey Dot-path for Q_Config::get()
	 * @param string $fallbackKey Dot-path for the platform fallback
	 * @param int $default Value if neither key is set
	 * @return int
	 */
	protected static function configInt($primaryKey, $fallbackKey, $default)
	{
		$val = self::configVal($primaryKey, $fallbackKey, $default);
		return (int)$val;
	}

	/**
	 * Read a float config value with primary/fallback/default.
	 *
	 * @param string $primaryKey Dot-path for Q_Config::get()
	 * @param string $fallbackKey Dot-path for the platform fallback
	 * @param float $default Value if neither key is set
	 * @return float
	 */
	protected static function configFloat($primaryKey, $fallbackKey, $default)
	{
		$val = self::configVal($primaryKey, $fallbackKey, $default);
		return (float)$val;
	}
}
