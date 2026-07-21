<?php
/**
 * @module Q
 */

/**
 * WebSocket server for Q_Evented loops.
 *
 * Handles RFC 6455 WebSocket protocol: upgrade handshake,
 * frame encoding/decoding, ping/pong, channels, broadcast.
 *
 * Two types of worker processes:
 *   - Connection workers: one per WebSocket connection (user isolation)
 *   - Room workers: one per active room (shared ephemeral state)
 *
 * @class Q_WebSocket
 */
class Q_WebSocket
{
	const GUID = '258EAFA5-E914-47DA-95CA-5AB5DC587B41';

	/** Connected clients. socketKey => [socket, watcher, channels, buffer, onMessage] */
	static $clients = array();
	/** Channel/room subscriptions. channelName => [socketKey => true] */
	static $channels = array();
	/** Connection workers. socketKey => [pid, pipe, watcher] */
	static $workers = array();
	/** Room workers. roomName => [pid, pipe, watcher, members => [socketKey => true], tick => ms] */
	static $roomWorkers = array();
	/** Cached room patterns from config */
	static $roomPatterns = null;

	// ── Upgrade + framing (unchanged) ───────────────

	static function upgrade($socket, $headers, $onMessage = null, $channel = null, $path = '/')
	{
		$key = $headers['sec-websocket-key'] ?? null;
		if (!$key) return false;
		$accept = base64_encode(sha1($key . self::GUID, true));
		$resp = "HTTP/1.1 101 Switching Protocols\r\n"
			. "Upgrade: websocket\r\nConnection: Upgrade\r\n"
			. "Sec-WebSocket-Accept: $accept\r\n"
			. "Server: QbixServer\r\n\r\n";
		@fwrite($socket, $resp);
		$sk = (int) $socket;
		$watcher = Q_Evented::onReadable($socket, function ($sock) use ($sk) {
			Q_WebSocket::onData($sk, $sock);
		});

		// Socket.IO clients connect to configured path (default /socket.io)
		// Set Q.socket.io to false to disable Socket.IO protocol
		$ioPath = Q_Config::get('Q', 'socket', 'io', '/socket.io');
		$proto = ($ioPath !== false && strpos($path, $ioPath) === 0) ? 'socketio' : 'json';

		self::$clients[$sk] = array(
			'socket' => $socket, 'watcher' => $watcher,
			'channels' => array(), 'buffer' => '', 'onMessage' => $onMessage,
			'protocol' => $proto,
		);

		// Socket.IO: send Engine.IO OPEN handshake
		if ($proto === 'socketio') {
			$sid = base_convert(mt_rand(1000000, 9999999) . $sk, 10, 36);
			$handshake = '0' . json_encode(array(
				'sid' => $sid,
				'upgrades' => array(),
				'pingInterval' => 25000,
				'pingTimeout' => 20000,
				'maxPayload' => 1000000,
			));
			self::sendRaw($sk, $handshake);
		}

		if ($channel) self::subscribe($sk, $channel);
		return true;
	}

	static function onData($sk, $socket)
	{
		if (!isset(self::$clients[$sk])) return;
		$data = @fread($socket, 65536);
		if ($data === false || $data === '') {
			self::disconnect($sk);
			return;
		}
		self::$clients[$sk]['buffer'] .= $data;
		while (($frame = self::decodeFrame(self::$clients[$sk]['buffer'])) !== null) {
			switch ($frame['opcode']) {
				case 0x1: // text
					$cb = self::$clients[$sk]['onMessage'];
					if ($cb) $cb($sk, $frame['payload']);
					break;
				case 0x2: // binary — ignore
					break;
				case 0x8: // close
					self::disconnect($sk);
					return;
				case 0x9: // ping → pong
					self::encodeAndSend(self::$clients[$sk]['socket'], 0xA, $frame['payload']);
					break;
				case 0xA: // pong — ignore
					break;
			}
		}
	}

	static function decodeFrame(&$buffer)
	{
		$len = strlen($buffer);
		if ($len < 2) return null;
		$b0 = ord($buffer[0]); $b1 = ord($buffer[1]);
		$opcode = $b0 & 0x0F;
		$masked = ($b1 >> 7) & 1;
		$payloadLen = $b1 & 0x7F;
		$offset = 2;
		if ($payloadLen === 126) {
			if ($len < 4) return null;
			$payloadLen = unpack('n', substr($buffer, 2, 2))[1];
			$offset = 4;
		} elseif ($payloadLen === 127) {
			if ($len < 10) return null;
			$payloadLen = unpack('J', substr($buffer, 2, 8))[1];
			$offset = 10;
		}
		if ($masked) {
			if ($len < $offset + 4 + $payloadLen) return null;
			$mask = substr($buffer, $offset, 4);
			$offset += 4;
			$payload = '';
			$raw = substr($buffer, $offset, $payloadLen);
			for ($i = 0; $i < $payloadLen; $i++) {
				$payload .= chr(ord($raw[$i]) ^ ord($mask[$i % 4]));
			}
		} else {
			if ($len < $offset + $payloadLen) return null;
			$payload = substr($buffer, $offset, $payloadLen);
		}
		$buffer = substr($buffer, $offset + $payloadLen);
		return array('opcode' => $opcode, 'payload' => $payload);
	}

	// ── Sending ─────────────────────────────────────

	static function send($socketKey, $data)
	{
		if (!isset(self::$clients[$socketKey])) return;
		$encoded = self::encodeSend($socketKey, $data);
		self::encodeAndSend(self::$clients[$socketKey]['socket'], 0x1, $encoded);
	}

	static function broadcast($data)
	{
		foreach (self::$clients as $sk => $c) {
			$encoded = self::encodeSend($sk, $data);
			self::encodeAndSend($c['socket'], 0x1, $encoded);
		}
	}

	static function broadcastTo($channel, $data)
	{
		if (!isset(self::$channels[$channel])) return;
		foreach (self::$channels[$channel] as $sk => $_) {
			if (isset(self::$clients[$sk])) {
				$encoded = self::encodeSend($sk, $data);
				self::encodeAndSend(self::$clients[$sk]['socket'], 0x1, $encoded);
			}
		}
	}

	static function subscribe($sk, $channel, $data = array())
	{
		if (!isset(self::$channels[$channel])) self::$channels[$channel] = array();
		self::$channels[$channel][$sk] = true;
		if (isset(self::$clients[$sk])) self::$clients[$sk]['channels'][$channel] = true;
		// If a room worker exists for this channel, notify it
		self::notifyRoomJoin($channel, $sk, $data);
	}

	static function unsubscribe($sk, $channel, $data = array())
	{
		unset(self::$channels[$channel][$sk]);
		if (empty(self::$channels[$channel])) unset(self::$channels[$channel]);
		if (isset(self::$clients[$sk])) unset(self::$clients[$sk]['channels'][$channel]);
		self::notifyRoomLeave($channel, $sk, $data);
	}

	static function disconnect($sk)
	{
		if (!isset(self::$clients[$sk])) return;
		self::notifyDisconnect($sk);
		$w = self::$clients[$sk]['watcher'];
		if ($w) Q_Evented::cancel($w);
		foreach (self::$clients[$sk]['channels'] as $ch => $_) {
			unset(self::$channels[$ch][$sk]);
			self::notifyRoomLeave($ch, $sk);
		}
		@fclose(self::$clients[$sk]['socket']);
		unset(self::$clients[$sk]);
	}

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

	// ── Connection worker (process-per-socket) ──────

	static function dispatchEvent($socketKey, $raw, $path = '/')
	{
		$proto = self::$clients[$socketKey]['protocol'] ?? 'json';

		if ($proto === 'socketio') {
			$msg = self::parseSocketIO($socketKey, $raw);
			if ($msg === null) return; // handled internally (ping/pong/connect)
		} else {
			// Bare WebSocket — plain JSON
			$msg = json_decode($raw, true);
			if (!$msg) return;
			// Ack-only response (client responding to server RPC)
			if (isset($msg['ack']) && !isset($msg['event'])) {
				self::handleRpcResponse($msg['ack'], $msg['data'] ?? null);
				return;
			}
			if (empty($msg['event'])) return;
		}

		$event = $msg['event'];

		// Check if this event should go to a room worker instead
		if (isset(self::$clients[$socketKey])) {
			foreach (self::$clients[$socketKey]['channels'] as $ch => $_) {
				if (isset(self::$roomWorkers[$ch])) {
					// Forward to room worker with sender info
					$msg['_socketId'] = $socketKey;
					self::sendToRoomWorker($ch, $msg);
					return;
				}
			}
		}

		// Default: per-connection worker
		if (!isset(self::$workers[$socketKey])) {
			self::spawnWorker($socketKey, $path);
		}
		if (!isset(self::$workers[$socketKey])) return;

		$json = json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$packet = pack('N', strlen($json)) . $json;
		$written = @fwrite(self::$workers[$socketKey]['pipe'], $packet);
		if ($written === false || $written === 0) {
			self::cleanupWorker($socketKey);
			self::spawnWorker($socketKey, $path);
			if (isset(self::$workers[$socketKey])) {
				@fwrite(self::$workers[$socketKey]['pipe'], $packet);
			}
		}
	}

	// ── Socket.IO protocol support ──────────────────

	/**
	 * Parse a Socket.IO/Engine.IO message. Returns normalized internal
	 * format or null if the message was handled internally (ping, connect).
	 */
	static function parseSocketIO($socketKey, $raw)
	{
		if ($raw === '') return null;
		$eioType = $raw[0];

		switch ($eioType) {
			case '2': // Engine.IO ping
				self::sendRaw($socketKey, '3'); // pong
				return null;
			case '3': // Engine.IO pong
				return null;
			case '5': // Engine.IO upgrade
				return null;
			case '4': // Engine.IO message → Socket.IO packet
				break;
			default:
				return null;
		}

		// Strip Engine.IO prefix "4"
		$sio = substr($raw, 1);
		if ($sio === '' || $sio === false) return null;
		$sioType = $sio[0];
		$rest = substr($sio, 1);

		// Extract namespace from packet (before comma or ack digits)
		$ns = '';
		if (isset($rest[0]) && $rest[0] === '/') {
			$commaPos = strpos($rest, ',');
			if ($commaPos !== false) {
				$ns = substr($rest, 1, $commaPos - 1); // strip leading /
				$rest = substr($rest, $commaPos + 1);
			}
		}

		switch ($sioType) {
			case '0': // CONNECT to namespace
				$sid = base_convert(mt_rand(1000000, 9999999) . microtime(true) * 1000, 10, 36);
				$nsPrefix = $ns ? '/' . $ns . ',' : '';

				// Try connect handler (optional — auto-accepts if no handler)
				$connectEvent = $ns ? $ns . '/connect' : 'connect';
				if (Q::canHandle($connectEvent)) {
					// Return as event so it dispatches to the handler
					return array('event' => $connectEvent, 'data' => array(),
						'_ns' => $ns, '_nsConnect' => true, '_nsSid' => $sid);
				}

				// Auto-accept: send CONNECT ack
				self::sendRaw($socketKey, '40' . $nsPrefix . '{"sid":"' . $sid . '"}');
				// Store namespace membership
				if (!isset(self::$clients[$socketKey]['namespaces'])) {
					self::$clients[$socketKey]['namespaces'] = array();
				}
				self::$clients[$socketKey]['namespaces'][$ns] = true;
				return null;

			case '1': // DISCONNECT from namespace
				$disconnectEvent = $ns ? $ns . '/disconnect' : 'disconnect';
				if (isset(self::$clients[$socketKey]['namespaces'])) {
					unset(self::$clients[$socketKey]['namespaces'][$ns]);
				}
				return array('event' => '_disconnect', 'data' => array(), '_ns' => $ns);

			case '2': // EVENT (possibly with ack)
				// Extract optional ack ID (digits before JSON array)
				$ackId = null;
				$i = 0;
				while ($i < strlen($rest) && ctype_digit($rest[$i])) $i++;
				if ($i > 0) {
					$ackId = (int) substr($rest, 0, $i);
					$rest = substr($rest, $i);
				}
				$arr = json_decode($rest, true);
				if (!is_array($arr) || empty($arr)) return null;
				$eventName = array_shift($arr);
				$data = isset($arr[0]) ? $arr[0] : array();
				// Prepend namespace to event name
				if ($ns) $eventName = $ns . '/' . $eventName;
				$msg = array('event' => $eventName, 'data' => $data);
				if ($ackId !== null) $msg['ack'] = $ackId;
				return $msg;

			case '3': // ACK (client responding to server RPC)
				$i = 0;
				while ($i < strlen($rest) && ctype_digit($rest[$i])) $i++;
				$ackId = ($i > 0) ? (int) substr($rest, 0, $i) : null;
				$rest = substr($rest, $i);
				$arr = json_decode($rest, true);
				$result = (is_array($arr) && !empty($arr)) ? $arr[0] : null;
				if ($ackId !== null) {
					self::handleRpcResponse($ackId, $result);
				}
				return null;

			default:
				return null;
		}
	}

	/**
	 * Send raw text frame to a WebSocket client (no JSON wrapping).
	 * Used for Socket.IO protocol frames.
	 */
	static function sendRaw($socketKey, $text)
	{
		if (!isset(self::$clients[$socketKey]['socket'])) return;
		self::encodeAndSend(self::$clients[$socketKey]['socket'], 0x1, $text);
	}

	/**
	 * Send a Socket.IO ACK response: 43<ackId>[data]
	 */
	/**
	 * Send a Socket.IO ACK response: 43<ackId>[data]
	 * or bare JSON: {"ack": ackId, "data": ...}
	 */
	static function sendAck($socketKey, $ackId, $data)
	{
		$proto = self::$clients[$socketKey]['protocol'] ?? 'json';
		if ($proto === 'socketio') {
			self::sendRaw($socketKey, '43' . $ackId . json_encode(array($data), JSON_UNESCAPED_SLASHES));
		} else {
			self::send($socketKey, array('ack' => $ackId, 'data' => $data));
		}
	}

	/**
	 * Encode outgoing data for the client's protocol.
	 */
	static function encodeSend($socketKey, $data)
	{
		$proto = self::$clients[$socketKey]['protocol'] ?? 'json';

		if ($proto === 'socketio') {
			$event = $data['event'] ?? 'message';
			$payload = $data['data'] ?? $data;
			$arr = array($event, $payload);
			return '42' . json_encode($arr, JSON_UNESCAPED_SLASHES);
		}

		// Bare WebSocket — plain JSON
		return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	static function spawnWorker($socketKey, $path)
	{
		if (!function_exists('pcntl_fork')) return;

		$pf = defined('STREAM_PF_UNIX') ? STREAM_PF_UNIX : STREAM_PF_INET;
		$pair = stream_socket_pair($pf, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		if (!$pair) return;

		$pid = pcntl_fork();
		if ($pid === -1) { fclose($pair[0]); fclose($pair[1]); return; }

		if ($pid === 0) {
			// ── CHILD: connection message loop ──
			fclose($pair[0]);
			$pipe = $pair[1];
			Q_Socket::$_pipe = $pipe;

			$socket = new Q_Socket($socketKey);

			$connectHandler = Q_Config::get('Q', 'webserver', 'sockets', 'events', '_connect', null);
			if ($connectHandler) {
				Q::event($connectHandler, array(
					'socket' => $socket, 'path' => $path,
					'event' => '_connect', 'data' => array(),
				));
				Q_Socket::flush();
			}

			while (true) {
				// Check message queue first (filled by __call when it
				// reads non-RPC messages while waiting for a response)
				if (!empty(Q_Socket::$_messageQueue)) {
					$msg = array_shift(Q_Socket::$_messageQueue);
				} else {
					$header = @fread($pipe, 4);
					if ($header === false || $header === '' || strlen($header) < 4) break;
					$len = unpack('N', $header)[1];
					if ($len <= 0 || $len > 10485760) break;
					$json = '';
					while (strlen($json) < $len) {
						$chunk = @fread($pipe, $len - strlen($json));
						if ($chunk === false || $chunk === '') break 2;
						$json .= $chunk;
					}
					$msg = json_decode($json, true);
					if (!$msg) continue;
				}

				$event = $msg['event'] ?? '';
				if ($event === '_disconnect') break;

				$mapped = Q_Config::get('Q', 'webserver', 'sockets', 'events', $event, $event);
				Q_Socket::$_ack = isset($msg['ack']) ? $msg['ack'] : null;

				$result = null;
				$params = array(
					'socket' => $socket,
					'path'   => $path,
					'event'  => $event,
					'data'   => $msg['data'] ?? array(),
				);
				Q::event($mapped, $params, false, false, $result);

				if (Q_Socket::$_ack !== null && $result !== null) {
					Q_Socket::_cmd(array(
						'cmd' => 'ack', 'socketId' => $socket->id,
						'ackId' => Q_Socket::$_ack, 'data' => $result,
					));
				}
				Q_Socket::flush();
			}

			$disconnectHandler = Q_Config::get('Q', 'webserver', 'sockets', 'events', '_disconnect', null);
			if ($disconnectHandler) {
				Q::event($disconnectHandler, array(
					'socket' => $socket, 'event' => '_disconnect', 'data' => array(),
				));
				Q_Socket::flush();
			}
			fclose($pipe);
			exit(0);
		}

		// ── PARENT ──
		fclose($pair[1]);
		stream_set_blocking($pair[0], false);
		$ipcWatcher = Q_Evented::onReadable($pair[0], function ($pipe) use ($socketKey) {
			$data = @fread($pipe, 65536);
			if ($data === false || $data === '') {
				Q_WebSocket::cleanupWorker($socketKey);
				return;
			}
			$lines = explode("\n", trim($data));
			foreach ($lines as $line) {
				if ($line === '') continue;
				$cmd = json_decode($line, true);
				if ($cmd) Q_WebSocket::executeCommand($cmd);
			}
		});
		self::$workers[$socketKey] = array(
			'pid' => $pid, 'pipe' => $pair[0], 'watcher' => $ipcWatcher,
		);
	}

	static function cleanupWorker($socketKey)
	{
		if (!isset(self::$workers[$socketKey])) return;
		$w = self::$workers[$socketKey];
		if ($w['watcher']) Q_Evented::cancel($w['watcher']);
		@fclose($w['pipe']);
		if ($w['pid'] > 0 && function_exists('posix_kill')) {
			posix_kill($w['pid'], SIGTERM);
			pcntl_waitpid($w['pid'], $st, WNOHANG);
		}
		unset(self::$workers[$socketKey]);
	}

	static function notifyDisconnect($socketKey)
	{
		if (!isset(self::$workers[$socketKey])) return;
		$json = json_encode(array('event' => '_disconnect', 'data' => array()));
		$packet = pack('N', strlen($json)) . $json;
		@fwrite(self::$workers[$socketKey]['pipe'], $packet);
		self::cleanupWorker($socketKey);
	}

	// ── Room workers (process-per-room) ─────────────

	/**
	 * Get room patterns from config. Cached.
	 * Config format:
	 *   Q.webserver.sockets.rooms.$pattern = {handler, tick?}
	 *   e.g. "game/$id" => {"handler": "game/room", "tick": 100}
	 * @method getRoomPatterns
	 * @static
	 */
	static function getRoomPatterns()
	{
		if (self::$roomPatterns !== null) return self::$roomPatterns;
		self::$roomPatterns = Q_Config::get('Q', 'webserver', 'sockets', 'rooms', array());
		return self::$roomPatterns;
	}

	/**
	 * Check if a room name matches a configured room pattern.
	 * Returns the config (handler, tick) or null.
	 * @method matchRoomPattern
	 * @static
	 */
	static function matchRoomPattern($roomName)
	{
		$patterns = self::getRoomPatterns();
		if (empty($patterns)) return null;
		$segments = explode('/', $roomName);
		foreach ($patterns as $pattern => $config) {
			$pSegments = explode('/', $pattern);
			if (count($pSegments) !== count($segments)) continue;
			$match = true;
			$params = array();
			for ($i = 0; $i < count($pSegments); $i++) {
				$ps = $pSegments[$i];
				if (isset($ps[0]) && ($ps[0] === '$' || $ps[0] === ':')) {
					$params[substr($ps, 1)] = $segments[$i];
				} elseif ($ps !== $segments[$i]) {
					$match = false;
					break;
				}
			}
			if ($match) {
				return array_merge((array) $config, array('_params' => $params, '_pattern' => $pattern));
			}
		}
		return null;
	}

	/**
	 * Spawn a room worker process.
	 * @method spawnRoomWorker
	 * @static
	 */
	static function spawnRoomWorker($roomName, $config)
	{
		if (!function_exists('pcntl_fork')) return;
		if (isset(self::$roomWorkers[$roomName])) return;

		$pf = defined('STREAM_PF_UNIX') ? STREAM_PF_UNIX : STREAM_PF_INET;
		$pair = stream_socket_pair($pf, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		if (!$pair) return;

		$handler = $config['handler'] ?? '';
		$tick = isset($config['tick']) ? (int) $config['tick'] : 0;
		$params = $config['_params'] ?? array();

		$pid = pcntl_fork();
		if ($pid === -1) { fclose($pair[0]); fclose($pair[1]); return; }

		if ($pid === 0) {
			// ── CHILD: room message loop ──
			fclose($pair[0]);
			$pipe = $pair[1];
			Q_Socket::$_pipe = $pipe;

			// Set up tick timer if configured
			$tickCallback = null;
			if ($tick > 0) {
				$tickCallback = function () use ($handler, $roomName, $params, $pipe) {
					Q_Socket::$_ack = null;
					$result = null;
					$room = new Q_Room($roomName, 0, $params);
					$p = array_merge($params, array(
						'room' => $room, 'event' => '_tick', 'data' => array(),
					));
					Q::event($handler . '/tick', $p, false, false, $result);
					Q_Socket::flush();
				};
			}

			// Fire _init event
			$result = null;
			$room = new Q_Room($roomName, 0, $params);
			Q::event($handler . '/init', array_merge($params, array(
				'room' => $room, 'event' => '_init', 'data' => array(),
			)), false, false, $result);
			Q_Socket::flush();

			// Message loop with optional tick
			stream_set_blocking($pipe, false);
			$lastTick = microtime(true);

			while (true) {
				$read = array($pipe);
				$write = $except = null;
				$timeout = $tick > 0 ? max(0.001, ($tick / 1000.0) - (microtime(true) - $lastTick)) : 1.0;
				$ready = @stream_select($read, $write, $except, (int) $timeout,
					(int) (($timeout - (int) $timeout) * 1000000));

				// Tick
				if ($tick > 0 && (microtime(true) - $lastTick) * 1000 >= $tick) {
					$lastTick = microtime(true);
					if ($tickCallback) $tickCallback();
				}

				if ($ready === false) break;
				if ($ready === 0) continue;

				// Read length-prefixed messages
				$raw = @fread($pipe, 65536);
				if ($raw === false || $raw === '') break;

				// May contain multiple messages
				$buf = $raw;
				while (strlen($buf) >= 4) {
					$len = unpack('N', substr($buf, 0, 4))[1];
					if ($len <= 0 || $len > 10485760) { $buf = ''; break; }
					if (strlen($buf) < 4 + $len) break;
					$json = substr($buf, 4, $len);
					$buf = substr($buf, 4 + $len);

					$msg = json_decode($json, true);
					if (!$msg) continue;

					$event = $msg['event'] ?? '';
					if ($event === '_shutdown') break 2;

					Q_Socket::$_ack = isset($msg['ack']) ? $msg['ack'] : null;
					$senderSocketId = $msg['_socketId'] ?? 0;

					$result = null;
					$room = new Q_Room($roomName, $senderSocketId, $params);
					$p = array_merge($params, array(
						'room'  => $room,
						'event' => $event,
						'data'  => $msg['data'] ?? array(),
					));
					// Lifecycle events: _join → handler/join
					// User events: message → handler/message
					$eventPath = $handler . '/' . ltrim($event, '_');
					Q::event($eventPath, $p, false, false, $result);

					if (Q_Socket::$_ack !== null && $result !== null) {
						Q_Socket::_cmd(array(
							'cmd' => 'ack', 'socketId' => $room->socketId,
							'ackId' => Q_Socket::$_ack, 'data' => $result,
						));
					}
					Q_Socket::flush();
				}
			}

			// Fire _destroy event
			$room = new Q_Room($roomName, 0, $params);
			Q::event($handler . '/destroy', array_merge($params, array(
				'room' => $room, 'event' => '_destroy', 'data' => array(),
			)), false, false, $result);
			Q_Socket::flush();

			fclose($pipe);
			exit(0);
		}

		// ── PARENT ──
		fclose($pair[1]);
		stream_set_blocking($pair[0], false);
		$ipcWatcher = Q_Evented::onReadable($pair[0], function ($pipe) use ($roomName) {
			$data = @fread($pipe, 65536);
			if ($data === false || $data === '') {
				Q_WebSocket::cleanupRoomWorker($roomName);
				return;
			}
			$lines = explode("\n", trim($data));
			foreach ($lines as $line) {
				if ($line === '') continue;
				$cmd = json_decode($line, true);
				if ($cmd) Q_WebSocket::executeCommand($cmd);
			}
		});
		self::$roomWorkers[$roomName] = array(
			'pid' => $pid, 'pipe' => $pair[0], 'watcher' => $ipcWatcher,
			'members' => array(),
		);
	}

	/**
	 * Send a message to a room worker.
	 * @method sendToRoomWorker
	 * @static
	 */
	static function sendToRoomWorker($roomName, $msg)
	{
		if (!isset(self::$roomWorkers[$roomName])) return;
		$json = json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$packet = pack('N', strlen($json)) . $json;
		@fwrite(self::$roomWorkers[$roomName]['pipe'], $packet);
	}

	/**
	 * Notify room worker when a socket joins.
	 * @method notifyRoomJoin
	 * @static
	 */
	static function notifyRoomJoin($channel, $socketKey, $data = array())
	{
		$config = self::matchRoomPattern($channel);
		if (!$config) return;

		// Spawn room worker if not running
		if (!isset(self::$roomWorkers[$channel])) {
			self::spawnRoomWorker($channel, $config);
		}
		if (!isset(self::$roomWorkers[$channel])) return;

		self::$roomWorkers[$channel]['members'][$socketKey] = true;
		self::sendToRoomWorker($channel, array(
			'event' => '_join', 'data' => $data,
			'_socketId' => $socketKey,
		));
	}

	/**
	 * Notify room worker when a socket leaves.
	 * @method notifyRoomLeave
	 * @static
	 */
	static function notifyRoomLeave($channel, $socketKey, $data = array())
	{
		if (!isset(self::$roomWorkers[$channel])) return;
		unset(self::$roomWorkers[$channel]['members'][$socketKey]);

		self::sendToRoomWorker($channel, array(
			'event' => '_leave', 'data' => $data,
			'_socketId' => $socketKey,
		));

		// Shut down room if empty
		if (empty(self::$roomWorkers[$channel]['members'])) {
			self::sendToRoomWorker($channel, array(
				'event' => '_shutdown', 'data' => array(),
			));
			self::cleanupRoomWorker($channel);
		}
	}

	/**
	 * Clean up a room worker.
	 * @method cleanupRoomWorker
	 * @static
	 */
	static function cleanupRoomWorker($roomName)
	{
		if (!isset(self::$roomWorkers[$roomName])) return;
		$w = self::$roomWorkers[$roomName];
		if ($w['watcher']) Q_Evented::cancel($w['watcher']);
		@fclose($w['pipe']);
		if ($w['pid'] > 0 && function_exists('posix_kill')) {
			posix_kill($w['pid'], SIGTERM);
			pcntl_waitpid($w['pid'], $st, WNOHANG);
		}
		unset(self::$roomWorkers[$roomName]);
	}

	// ── In-process fallback (Windows) ───────────────

	static function dispatchEventInProcess($eventName, $params, $socketKey, $ack)
	{
		Q_Socket::$_directMode = true;
		Q_Socket::$_ack = $ack;
		$socket = new Q_Socket($socketKey);
		$params['socket'] = $socket;
		$result = null;
		Q::event($eventName, $params, false, false, $result);
		if ($ack !== null && $result !== null) {
			self::send($socketKey, array('ack' => $ack, 'data' => $result));
		}
		Q_Socket::$_directMode = false;
	}

	// ── IPC command execution ───────────────────────

	static function clientCount()
	{
		return count(self::$clients);
	}

	static function executeCommand($cmd)
	{
		switch ($cmd['cmd'] ?? '') {
			case 'send':
				self::send($cmd['socketId'], $cmd['data']);
				break;
			case 'broadcast':
				self::broadcastTo($cmd['room'], $cmd['data']);
				break;
			case 'broadcastAll':
				self::broadcast($cmd['data']);
				break;
			case 'join':
				self::subscribe($cmd['socketId'], $cmd['room'], $cmd['data'] ?? array());
				break;
			case 'leave':
				self::unsubscribe($cmd['socketId'], $cmd['room'], $cmd['data'] ?? array());
				break;
			case 'ack':
				self::sendAck($cmd['socketId'], $cmd['ackId'], $cmd['data']);
				break;
			case 'rpc':
				self::handleRpc($cmd);
				break;
		}
	}

	// ── Server→Client RPC ───────────────────────────

	/** @internal Maps rpcAckId → ['pipe' => resource, 'rpcId' => int] */
	static $pendingRpc = array();
	/** @internal Counter for server→client ack IDs */
	static $rpcAckCounter = 0;

	/**
	 * Handle an RPC request from a child process.
	 * Sends the method call to the client with an ack ID, then routes
	 * the client's ack response back to the child's IPC pipe.
	 */
	static function handleRpc($cmd)
	{
		$socketKey = $cmd['socketId'];
		$method = $cmd['method'];
		$data = $cmd['data'] ?? array();
		$rpcId = $cmd['rpcId'];

		// Generate a unique ack ID for server→client
		$ackId = ++self::$rpcAckCounter;

		// Find which child pipe to route the response back to
		$childPipe = null;
		if (isset(self::$workers[$socketKey])) {
			$childPipe = self::$workers[$socketKey]['pipe'];
		}
		if (!$childPipe) return;

		// Store mapping so we can route the ack response back
		self::$pendingRpc[$ackId] = array(
			'pipe' => $childPipe,
			'rpcId' => $rpcId,
		);

		// Send RPC call to client with ack ID
		$proto = self::$clients[$socketKey]['protocol'] ?? 'json';
		if ($proto === 'socketio') {
			// Socket.IO: 42<ackId>["method", data]
			$payload = '42' . $ackId . json_encode(array($method, $data), JSON_UNESCAPED_SLASHES);
			self::sendRaw($socketKey, $payload);
		} else {
			// Bare: {"event":"method", "data":..., "ack": ackId}
			self::send($socketKey, array('event' => $method, 'data' => $data, 'ack' => $ackId));
		}
	}

	/**
	 * Route an ack response from a client back to the child that
	 * initiated the RPC call.
	 * @return boolean True if this was an RPC ack and was handled
	 */
	static function handleRpcResponse($ackId, $result)
	{
		if (!isset(self::$pendingRpc[$ackId])) return false;

		$pending = self::$pendingRpc[$ackId];
		unset(self::$pendingRpc[$ackId]);

		// Send response back to child via IPC pipe
		$response = json_encode(array(
			'_rpc' => $pending['rpcId'],
			'result' => $result,
		), JSON_UNESCAPED_SLASHES);
		$packet = pack('N', strlen($response)) . $response;
		@fwrite($pending['pipe'], $packet);
		return true;
	}
}
