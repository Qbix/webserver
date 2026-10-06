<?php
/**
 * BLE transport layer: chunking protocol, rate limiting, and simulator.
 *
 * Real BLE has hard constraints:
 *   - MTU: 23–517 bytes per write (typically 247 after negotiation)
 *   - Bandwidth: ~2 Mbps (BLE 5.0)
 *   - Latency: ~7.5ms per connection interval
 *
 * This class implements the chunking protocol that both the native bridges
 * (Swift/Kotlin) and this PHP simulator use, so the protocol is tested
 * end-to-end even without real Bluetooth hardware.
 *
 * Chunk format:
 *   [1 byte flags][payload...]
 *   Flags: 0x01 = first chunk (includes 4-byte total length)
 *          0x02 = middle chunk
 *          0x03 = last chunk (or only chunk if message fits in MTU)
 *
 * First chunk: [0x01][4-byte big-endian total length][payload...]
 * Middle:      [0x02][payload...]
 * Last:        [0x03][payload...]
 * Single:      [0x03][payload...] (flag 0x03 = last, no 0x01 = also first)
 *
 * @class Q_WebServer_MeshBLE
 */
class Q_WebServer_MeshBLE
{
	const FLAG_FIRST  = 0x01;
	const FLAG_MIDDLE = 0x02;
	const FLAG_LAST   = 0x03;

	const DEFAULT_MTU = 247;      // BLE 5.0 typical after negotiation
	const MIN_MTU = 23;           // BLE 4.0 minimum
	const MAX_MESSAGE = 65536;    // 64KB max message size over GATT

	// ── Chunking Protocol ───────────────────────────────

	/**
	 * Split a message into BLE-sized chunks.
	 *
	 * @param string $message Raw bytes to send
	 * @param int $mtu Maximum chunk size (including flag byte)
	 * @return array of chunk strings (binary)
	 */
	static function chunk($message, $mtu = self::DEFAULT_MTU)
	{
		$len = strlen($message);
		if ($len > self::MAX_MESSAGE) {
			throw new \RuntimeException("Message too large for BLE GATT: $len > " . self::MAX_MESSAGE);
		}

		$chunks = array();
		$payloadMtu = $mtu - 1; // 1 byte for flag

		if ($len === 0) {
			$chunks[] = chr(self::FLAG_LAST);
			return $chunks;
		}

		// Single chunk (fits in MTU with flag + length header)
		if ($len <= $payloadMtu - 4) {
			// Small enough for single chunk: [0x03][4-byte len][payload]
			$chunks[] = chr(self::FLAG_LAST) . pack('N', $len) . $message;
			return $chunks;
		}

		// Multi-chunk
		$offset = 0;

		// First chunk: [0x01][4-byte total length][payload...]
		$firstPayload = $payloadMtu - 4; // 4 bytes for length header
		$chunks[] = chr(self::FLAG_FIRST) . pack('N', $len)
			. substr($message, 0, $firstPayload);
		$offset = $firstPayload;

		// Middle and last chunks
		while ($offset < $len) {
			$remaining = $len - $offset;
			if ($remaining <= $payloadMtu) {
				// Last chunk
				$chunks[] = chr(self::FLAG_LAST) . substr($message, $offset);
				break;
			} else {
				// Middle chunk
				$chunks[] = chr(self::FLAG_MIDDLE) . substr($message, $offset, $payloadMtu);
				$offset += $payloadMtu;
			}
		}

		return $chunks;
	}

	/**
	 * Reassemble chunks into the original message.
	 *
	 * @param array $chunks Array of chunk strings (in order)
	 * @return string|null Reassembled message, or null if incomplete/invalid
	 */
	static function reassemble($chunks)
	{
		if (empty($chunks)) return null;

		$totalLength = null;
		$buffer = '';

		foreach ($chunks as $i => $chunk) {
			if (strlen($chunk) < 1) return null;
			$flag = ord($chunk[0]);
			$payload = substr($chunk, 1);

			switch ($flag) {
				case self::FLAG_FIRST:
					if (strlen($payload) < 4) return null;
					$totalLength = unpack('N', substr($payload, 0, 4))[1];
					$buffer = substr($payload, 4);
					break;

				case self::FLAG_MIDDLE:
					$buffer .= $payload;
					break;

				case self::FLAG_LAST:
					if ($totalLength === null && $i === 0) {
						// Single-chunk message: [0x03][4-byte len][payload]
						if (strlen($payload) >= 4) {
							$totalLength = unpack('N', substr($payload, 0, 4))[1];
							$buffer = substr($payload, 4);
						} else {
							$buffer = $payload;
						}
					} else {
						$buffer .= $payload;
					}

					// Verify length if we have it
					if ($totalLength !== null && strlen($buffer) !== $totalLength) {
						return null; // length mismatch
					}
					return $buffer;

				default:
					return null; // unknown flag
			}
		}

		return null; // no FLAG_LAST seen
	}

	// ── Rate Limiter ────────────────────────────────────

	private static $peerRequests = array(); // peer_id => [timestamps...]
	private static $peerBanned = array();   // peer_id => unban_time

	const RATE_LIMIT = 100;          // max requests per minute per peer
	const BAN_BASE = 30;             // initial ban duration in seconds
	private static $banMultiplier = array(); // peer_id => multiplier (exponential backoff)

	/**
	 * Check if a request from a peer should be allowed.
	 *
	 * @param string $peerId
	 * @return array [allowed => bool, reason => string]
	 */
	static function rateCheck($peerId)
	{
		$now = time();

		// Check ban
		if (isset(self::$peerBanned[$peerId])) {
			if ($now < self::$peerBanned[$peerId]) {
				$remaining = self::$peerBanned[$peerId] - $now;
				return array('allowed' => false, 'reason' => "Banned for {$remaining}s");
			}
			unset(self::$peerBanned[$peerId]);
		}

		// Clean old timestamps (older than 60s)
		if (!isset(self::$peerRequests[$peerId])) {
			self::$peerRequests[$peerId] = array();
		}
		self::$peerRequests[$peerId] = array_filter(
			self::$peerRequests[$peerId],
			function ($t) use ($now) { return $t > $now - 60; }
		);

		// Check rate
		if (count(self::$peerRequests[$peerId]) >= self::RATE_LIMIT) {
			// Ban with exponential backoff
			$mult = self::$banMultiplier[$peerId] ?? 1;
			$banDuration = self::BAN_BASE * $mult;
			self::$peerBanned[$peerId] = $now + $banDuration;
			self::$banMultiplier[$peerId] = min($mult * 2, 16); // cap at 16x
			return array('allowed' => false, 'reason' => "Rate limited, banned for {$banDuration}s");
		}

		// Record this request
		self::$peerRequests[$peerId][] = $now;
		return array('allowed' => true, 'count' => count(self::$peerRequests[$peerId]));
	}

	/**
	 * Reset rate limiter for a peer (e.g., on reconnect).
	 */
	static function rateReset($peerId)
	{
		unset(self::$peerRequests[$peerId], self::$peerBanned[$peerId],
			self::$banMultiplier[$peerId]);
	}

	// ── BLE Transport Simulator ─────────────────────────

	/**
	 * Simulate sending a message over BLE between two peers.
	 *
	 * Enforces: MTU chunking, artificial latency, bandwidth limit,
	 * optional random drops, and rate limiting.
	 *
	 * @param string $message The message to send
	 * @param string $fromPeerId Sender
	 * @param string $toPeerId Receiver
	 * @param array $opts [
	 *   mtu => int (default 247),
	 *   latency_ms => float (per-chunk, default 7.5),
	 *   drop_rate => float (0.0–1.0, probability of dropping a chunk, default 0),
	 *   bandwidth_kbps => int (default 2000 = 2Mbps)
	 * ]
	 * @return array [
	 *   success => bool,
	 *   message => string|null (reassembled),
	 *   chunks_sent => int,
	 *   chunks_received => int,
	 *   chunks_dropped => int,
	 *   total_bytes => int,
	 *   simulated_ms => float,
	 *   headers => [X-Transport, X-Transport-Bandwidth, X-Peer-Id]
	 * ]
	 */
	static function simulate($message, $fromPeerId, $toPeerId, $opts = array())
	{
		$mtu = $opts['mtu'] ?? self::DEFAULT_MTU;
		$latencyMs = $opts['latency_ms'] ?? 7.5;
		$dropRate = $opts['drop_rate'] ?? 0.0;
		$bandwidthKbps = $opts['bandwidth_kbps'] ?? 2000;

		// Rate check
		$rateResult = self::rateCheck($fromPeerId);
		if (!$rateResult['allowed']) {
			return array(
				'success' => false,
				'message' => null,
				'error' => $rateResult['reason'],
				'chunks_sent' => 0,
				'chunks_received' => 0,
				'chunks_dropped' => 0,
				'total_bytes' => 0,
				'simulated_ms' => 0,
				'headers' => self::transportHeaders($fromPeerId, $bandwidthKbps)
			);
		}

		// Chunk the message
		$chunks = self::chunk($message, $mtu);
		$totalBytes = strlen($message);
		$chunksSent = count($chunks);
		$chunksDropped = 0;
		$receivedChunks = array();
		$simulatedMs = 0;

		foreach ($chunks as $chunk) {
			// Simulate latency per chunk
			$simulatedMs += $latencyMs;

			// Simulate bandwidth (time to transmit chunk)
			$chunkBytes = strlen($chunk);
			$transmitMs = ($chunkBytes * 8) / ($bandwidthKbps); // ms
			$simulatedMs += $transmitMs;

			// Simulate random drop
			if ($dropRate > 0 && (mt_rand() / mt_getrandmax()) < $dropRate) {
				$chunksDropped++;
				continue;
			}

			$receivedChunks[] = $chunk;
		}

		// Reassemble
		$reassembled = null;
		$success = false;

		if ($chunksDropped === 0) {
			$reassembled = self::reassemble($receivedChunks);
			$success = ($reassembled !== null && $reassembled === $message);
		}

		return array(
			'success' => $success,
			'message' => $reassembled,
			'chunks_sent' => $chunksSent,
			'chunks_received' => count($receivedChunks),
			'chunks_dropped' => $chunksDropped,
			'total_bytes' => $totalBytes,
			'simulated_ms' => round($simulatedMs, 2),
			'headers' => self::transportHeaders($fromPeerId, $bandwidthKbps)
		);
	}

	/**
	 * Generate transport headers that the native bridge would add.
	 */
	static function transportHeaders($peerId, $bandwidthKbps = 2000)
	{
		$bandwidth = $bandwidthKbps >= 10000 ? 'high' :
			($bandwidthKbps >= 1000 ? 'medium' : 'low');
		return array(
			'X-Peer-Id' => $peerId,
			'X-Transport' => 'ble',
			'X-Transport-Bandwidth' => $bandwidth
		);
	}

	/**
	 * Reset all state (for testing).
	 */
	static function reset()
	{
		self::$peerRequests = array();
		self::$peerBanned = array();
		self::$banMultiplier = array();
	}
}
