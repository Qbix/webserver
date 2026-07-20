<?php
/**
 * @module Q
 */

/**
 * WebSocket server for Q_Evented loops.
 *
 * Handles RFC 6455 WebSocket protocol: upgrade handshake,
 * frame encoding/decoding, ping/pong, channels, broadcast.
 * Works on the same port as Q_WebServer — HTTP requests are
 * served normally, WebSocket upgrades are handed off here.
 *
 * Client-side uses the browser's native WebSocket API:
 *   var ws = new WebSocket('ws://localhost:8080/my/path');
 *
 * Server-side:
 *   // In a Q_Evented loop, after detecting Upgrade header:
 *   Q_WebSocket::upgrade($socket, $headers, function ($socket, $msg) {
 *       // handle incoming message
 *   });
 *
 *   // Broadcast to all connected clients (or a channel):
 *   Q_WebSocket::broadcast(array('type' => 'update', 'data' => $data));
 *   Q_WebSocket::broadcastTo('dashboard', array('type' => 'stats'));
 *
 * @class Q_WebSocket
 */
class Q_WebSocket
{
	const GUID = '258EAFA5-E914-47DA-95CA-5AB5DC587B41';

	/**
	 * Connected clients. socketKey => [socket, watcher, channels, buffer, onMessage]
	 * @property $clients
	 * @static
	 */
	static $clients = array();

	/**
	 * Channel → subscriber map. channel => [socketKey => true]
	 * @property $channels
	 * @static
	 */
	static $channels = array();

	/**
	 * Upgrade an HTTP connection to WebSocket.
	 * Performs the RFC 6455 handshake and registers the socket
	 * with Q_Evented for non-blocking frame reads.
	 *
	 * @method upgrade
	 * @static
	 * @param {resource} $socket The TCP socket (from Q_WebServer)
	 * @param {array} $headers Lowercase HTTP headers from the request
	 * @param {callable|null} [$onMessage=null] function($socketKey, $message)
	 *  called when client sends a text frame
	 * @param {string|null} [$channel=null] Auto-subscribe to this channel
	 * @return {boolean} true if upgrade succeeded
	 */
	static function upgrade($socket, $headers, $onMessage = null, $channel = null)
	{
		$key = $headers['sec-websocket-key'] ?? '';
		if (!$key) return false;

		$accept = base64_encode(sha1($key . self::GUID, true));

		$resp = "HTTP/1.1 101 Switching Protocols\r\n"
			. "Upgrade: websocket\r\n"
			. "Connection: Upgrade\r\n"
			. "Sec-WebSocket-Accept: $accept\r\n\r\n";
		fwrite($socket, $resp);

		$sk = (int) $socket;
		self::$clients[$sk] = array(
			'socket'    => $socket,
			'watcher'   => null,
			'channels'  => array(),
			'buffer'    => '',
			'onMessage' => $onMessage
		);

		self::$clients[$sk]['watcher'] = Q_Evented::onReadable(
			$socket,
			function ($s) { Q_WebSocket::onData($s); }
		);

		if ($channel) {
			self::subscribe($sk, $channel);
		}

		return true;
	}

	/**
	 * Handle incoming data on a WebSocket connection.
	 * Parses frames, dispatches text messages, handles
	 * ping/pong and close.
	 *
	 * @method onData
	 * @static
	 * @param {resource} $socket
	 */
	static function onData($socket)
	{
		$sk = (int) $socket;
		if (!isset(self::$clients[$sk])) return;

		$data = @fread($socket, 65536);
		if ($data === false || $data === '') {
			self::disconnect($sk);
			return;
		}

		self::$clients[$sk]['buffer'] .= $data;

		while (strlen(self::$clients[$sk]['buffer']) >= 2) {
			$frame = self::decodeFrame(self::$clients[$sk]['buffer']);
			if ($frame === null) break; // incomplete

			self::$clients[$sk]['buffer'] = $frame['remaining'];

			switch ($frame['opcode']) {
				case 0x1: // Text frame
					$cb = self::$clients[$sk]['onMessage'];
					if ($cb) {
						$cb($sk, $frame['payload']);
					}
					break;
				case 0x8: // Close
					self::encodeAndSend($socket, 0x8, '');
					self::disconnect($sk);
					return;
				case 0x9: // Ping → Pong
					self::encodeAndSend($socket, 0xA, $frame['payload']);
					break;
				case 0xA: // Pong — ignore
					break;
			}
		}
	}

	// ── Sending ──────────────────────────────────────────

	/**
	 * Send a text message to a specific client.
	 * @method send
	 * @static
	 * @param {integer} $socketKey
	 * @param {array|string} $data If array, JSON-encoded
	 */
	static function send($socketKey, $data)
	{
		if (!isset(self::$clients[$socketKey])) return;
		$text = is_string($data) ? $data : json_encode($data);
		self::encodeAndSend(self::$clients[$socketKey]['socket'], 0x1, $text);
	}

	/**
	 * Broadcast to ALL connected clients.
	 * @method broadcast
	 * @static
	 * @param {array|string} $data
	 */
	static function broadcast($data)
	{
		$text = is_string($data) ? $data : json_encode($data);
		foreach (self::$clients as $sk => $c) {
			if (is_resource($c['socket'])) {
				self::encodeAndSend($c['socket'], 0x1, $text);
			} else {
				self::disconnect($sk);
			}
		}
	}

	/**
	 * Broadcast to clients subscribed to a channel.
	 * @method broadcastTo
	 * @static
	 * @param {string} $channel
	 * @param {array|string} $data
	 */
	static function broadcastTo($channel, $data)
	{
		if (empty(self::$channels[$channel])) return;
		$text = is_string($data) ? $data : json_encode($data);
		foreach (self::$channels[$channel] as $sk => $_) {
			if (!isset(self::$clients[$sk]) || !is_resource(self::$clients[$sk]['socket'])) {
				unset(self::$channels[$channel][$sk]);
				continue;
			}
			self::encodeAndSend(self::$clients[$sk]['socket'], 0x1, $text);
		}
	}

	// ── Channels ─────────────────────────────────────────

	static function subscribe($socketKey, $channel)
	{
		self::$channels[$channel][$socketKey] = true;
		self::$clients[$socketKey]['channels'][$channel] = true;
	}

	static function unsubscribe($socketKey, $channel)
	{
		unset(self::$channels[$channel][$socketKey]);
		unset(self::$clients[$socketKey]['channels'][$channel]);
	}

	// ── Connection management ────────────────────────────

	static function disconnect($sk)
	{
		if (!isset(self::$clients[$sk])) return;
		$w = self::$clients[$sk]['watcher'];
		if ($w) Q_Evented::cancel($w);
		foreach (self::$clients[$sk]['channels'] as $ch => $_) {
			unset(self::$channels[$ch][$sk]);
		}
		@fclose(self::$clients[$sk]['socket']);
		unset(self::$clients[$sk]);
	}

	static function disconnectAll()
	{
		foreach (array_keys(self::$clients) as $sk) {
			self::disconnect($sk);
		}
	}

	static function clientCount()
	{
		return count(self::$clients);
	}

	// ── RFC 6455 frame encoding/decoding ─────────────────

	/**
	 * Decode one frame from a buffer.
	 * @return {array|null} [opcode, payload, remaining] or null if incomplete
	 */
	static function decodeFrame(&$buf)
	{
		$len = strlen($buf);
		if ($len < 2) return null;

		$b0 = ord($buf[0]);
		$b1 = ord($buf[1]);
		$opcode = $b0 & 0x0F;
		$masked = ($b1 & 0x80) !== 0;
		$payloadLen = $b1 & 0x7F;
		$offset = 2;

		if ($payloadLen === 126) {
			if ($len < 4) return null;
			$payloadLen = unpack('n', substr($buf, 2, 2))[1];
			$offset = 4;
		} elseif ($payloadLen === 127) {
			if ($len < 10) return null;
			$payloadLen = unpack('J', substr($buf, 2, 8))[1];
			$offset = 10;
		}

		$totalNeeded = $offset + ($masked ? 4 : 0) + $payloadLen;
		if ($len < $totalNeeded) return null;

		if ($masked) {
			$mask = substr($buf, $offset, 4);
			$offset += 4;
			$payload = substr($buf, $offset, $payloadLen);
			for ($i = 0; $i < $payloadLen; $i++) {
				$payload[$i] = chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
			}
		} else {
			$payload = substr($buf, $offset, $payloadLen);
		}

		return array(
			'opcode'    => $opcode,
			'payload'   => $payload,
			'remaining' => substr($buf, $offset + $payloadLen)
		);
	}

	/**
	 * Encode and send a frame (server→client, unmasked).
	 */
	static function encodeAndSend($socket, $opcode, $payload)
	{
		$len = strlen($payload);
		$frame = chr(0x80 | $opcode);
		if ($len < 126) {
			$frame .= chr($len);
		} elseif ($len < 65536) {
			$frame .= chr(126) . pack('n', $len);
		} else {
			$frame .= chr(127) . pack('J', $len);
		}
		$frame .= $payload;
		@fwrite($socket, $frame);
	}
}
