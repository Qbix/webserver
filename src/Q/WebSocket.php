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

	static function upgrade($socket, $headers, $onMessage = null, $channel = null)
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
		self::$clients[$sk] = array(
			'socket' => $socket, 'watcher' => $watcher,
			'channels' => array(), 'buffer' => '', 'onMessage' => $onMessage
		);
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
		$json = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		self::encodeAndSend(self::$clients[$socketKey]['socket'], 0x1, $json);
	}

	static function broadcast($data)
	{
		$json = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		foreach (self::$clients as $sk => $c) {
			self::encodeAndSend($c['socket'], 0x1, $json);
		}
	}

	static function broadcastTo($channel, $data)
	{
		if (!isset(self::$channels[$channel])) return;
		$json = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		foreach (self::$channels[$channel] as $sk => $_) {
			if (isset(self::$clients[$sk])) {
				self::encodeAndSend(self::$clients[$sk]['socket'], 0x1, $json);
			}
		}
	}

	static function subscribe($sk, $channel)
	{
		if (!isset(self::$channels[$channel])) self::$channels[$channel] = array();
		self::$channels[$channel][$sk] = true;
		if (isset(self::$clients[$sk])) self::$clients[$sk]['channels'][$channel] = true;
		// If a room worker exists for this channel, notify it
		self::notifyRoomJoin($channel, $sk);
	}

	static function unsubscribe($sk, $channel)
	{
		unset(self::$channels[$channel][$sk]);
		if (empty(self::$channels[$channel])) unset(self::$channels[$channel]);
		if (isset(self::$clients[$sk])) unset(self::$clients[$sk]['channels'][$channel]);
		self::notifyRoomLeave($channel, $sk);
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
		$msg = json_decode($raw, true);
		if (!$msg || empty($msg['event'])) return;

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
			Q_Socket::$_socketId = $socketKey;

			$connectHandler = Q_Config::get('Q', 'webserver', 'sockets', 'events', '_connect', null);
			if ($connectHandler) {
				Q::event($connectHandler, array(
					'_socketId' => $socketKey, '_path' => $path,
					'event' => '_connect', 'data' => array(),
				));
				Q_Socket::flush();
			}

			while (true) {
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

				$event = $msg['event'] ?? '';
				if ($event === '_disconnect') break;

				$mapped = Q_Config::get('Q', 'webserver', 'sockets', 'events', $event, $event);
				Q_Socket::$_ack = isset($msg['ack']) ? $msg['ack'] : null;

				$result = null;
				$params = array(
					'_socketId' => $socketKey,
					'_path'     => $path,
					'_ack'      => Q_Socket::$_ack,
					'event'     => $event,
					'data'      => $msg['data'] ?? array(),
				);
				Q::event($mapped, $params, false, false, $result);

				if (Q_Socket::$_ack !== null && $result !== null) {
					Q_Socket::reply(array('ack' => Q_Socket::$_ack, 'data' => $result));
				}
				Q_Socket::flush();
			}

			$disconnectHandler = Q_Config::get('Q', 'webserver', 'sockets', 'events', '_disconnect', null);
			if ($disconnectHandler) {
				Q::event($disconnectHandler, array(
					'_socketId' => $socketKey, 'event' => '_disconnect', 'data' => array(),
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
			Q_Socket::$_socketId = 0; // room process, no single socket

			// Set up tick timer if configured
			$tickCallback = null;
			if ($tick > 0) {
				$tickCallback = function () use ($handler, $roomName, $params, $pipe) {
					Q_Socket::$_ack = null;
					$result = null;
					$p = array_merge($params, array(
						'_room' => $roomName, 'event' => '_tick',
						'data' => array(), '_socketId' => 0,
					));
					Q::event($handler, $p, false, false, $result);
					Q_Socket::flush();
				};
			}

			// Fire _init event
			$result = null;
			Q::event($handler, array_merge($params, array(
				'_room' => $roomName, 'event' => '_init', 'data' => array(),
				'_socketId' => 0,
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
					Q_Socket::$_socketId = $msg['_socketId'] ?? 0;

					$result = null;
					$p = array_merge($params, array(
						'_room'     => $roomName,
						'_socketId' => Q_Socket::$_socketId,
						'_ack'      => Q_Socket::$_ack,
						'event'     => $event,
						'data'      => $msg['data'] ?? array(),
					));
					Q::event($handler, $p, false, false, $result);

					if (Q_Socket::$_ack !== null && $result !== null) {
						Q_Socket::send(Q_Socket::$_socketId,
							array('ack' => Q_Socket::$_ack, 'data' => $result));
					}
					Q_Socket::flush();
				}
			}

			// Fire _destroy event
			Q::event($handler, array_merge($params, array(
				'_room' => $roomName, 'event' => '_destroy', 'data' => array(),
				'_socketId' => 0,
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
	static function notifyRoomJoin($channel, $socketKey)
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
			'event' => '_join', 'data' => array(),
			'_socketId' => $socketKey,
		));
	}

	/**
	 * Notify room worker when a socket leaves.
	 * @method notifyRoomLeave
	 * @static
	 */
	static function notifyRoomLeave($channel, $socketKey)
	{
		if (!isset(self::$roomWorkers[$channel])) return;
		unset(self::$roomWorkers[$channel]['members'][$socketKey]);

		self::sendToRoomWorker($channel, array(
			'event' => '_leave', 'data' => array(),
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
		Q_Socket::$_socketId = $socketKey;
		Q_Socket::$_ack = $ack;
		$result = null;
		Q::event($eventName, $params, false, false, $result);
		if ($ack !== null && $result !== null) {
			self::send($socketKey, array('ack' => $ack, 'data' => $result));
		}
		Q_Socket::$_directMode = false;
	}

	// ── IPC command execution ───────────────────────

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
				self::subscribe($cmd['socketId'], $cmd['room']);
				break;
			case 'leave':
				self::unsubscribe($cmd['socketId'], $cmd['room']);
				break;
		}
	}
}
