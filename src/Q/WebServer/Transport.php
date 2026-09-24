<?php
/**
 * Peer-to-peer transport layer with event system and peer registry.
 *
 * Manages nearby peers connected via BLE, MultipeerConnectivity, Wi-Fi Direct,
 * or TCP. The native bridge (Swift/Kotlin) calls the API endpoints to register
 * and manage peers. PHP apps register event handlers to react to peer changes.
 *
 * @class Q_WebServer_Transport
 */
class Q_WebServer_Transport
{
	private static $peers = array();
	private static $handlers = array(
		'online' => array(),
		'offline' => array(),
		'message' => array()
	);
	private static $enabled = array(
		'tcp' => true,
		'multipeer' => false,
		'ble' => false,
		'wifi_direct' => false
	);
	private static $initialized = false;

	/**
	 * Initialize transport from config and mesh identity.
	 */
	static function init()
	{
		if (self::$initialized) return;
		self::$initialized = true;

		$configPath = function_exists('qbix_data_path')
			? qbix_data_path('local/transport.json')
			: 'local/transport.json';
		if (file_exists($configPath)) {
			$saved = json_decode(file_get_contents($configPath), true);
			if (is_array($saved)) {
				self::$enabled = array_merge(self::$enabled, $saved);
			}
		}

		// Initialize mesh identity
		require_once __DIR__ . '/Mesh.php';
		require_once __DIR__ . '/MeshRouter.php';
		require_once __DIR__ . '/MeshBLE.php';
		Q_WebServer_Mesh::init();
	}

	// ── Event System ────────────────────────────────────

	/**
	 * Register a handler for when a peer comes online.
	 * @param callable $handler function($peer) where $peer has peer_id, name, transport, etc.
	 */
	static function onPeerOnline($handler)
	{
		self::$handlers['online'][] = $handler;
	}

	/**
	 * Register a handler for when a peer goes offline.
	 * @param callable $handler function($peer)
	 */
	static function onPeerOffline($handler)
	{
		self::$handlers['offline'][] = $handler;
	}

	/**
	 * Register a handler for arbitrary messages from a peer.
	 * @param callable $handler function($peerId, $message)
	 */
	static function onPeerMessage($handler)
	{
		self::$handlers['message'][] = $handler;
	}

	/**
	 * Dispatch an event to registered handlers.
	 * @param string $event 'online', 'offline', 'message'
	 * @param array $data Event data
	 */
	private static function dispatch($event, $data)
	{
		foreach (self::$handlers[$event] ?? array() as $handler) {
			try {
				$handler($data);
			} catch (\Throwable $e) {
				error_log("[Transport] Handler error on $event: " . $e->getMessage());
			}
		}
	}

	// ── Peer Registry ───────────────────────────────────

	/**
	 * Register a peer (called by native bridge or by direct TCP connection).
	 * Triggers onPeerOnline if the peer is new.
	 * @param array $data peer_id, name, transport, address, capabilities
	 * @return array
	 */
	static function registerPeer($data)
	{
		self::init();

		$peerId = $data['peer_id'] ?? ($data['address'] ?? '');
		if (empty($peerId)) return array('error' => 'Missing peer_id');

		$isNew = !isset(self::$peers[$peerId]);
		$wasOffline = isset(self::$peers[$peerId])
			&& (self::$peers[$peerId]['state'] ?? '') !== 'connected';

		self::$peers[$peerId] = array(
			'peer_id' => $peerId,
			'name' => $data['name'] ?? $peerId,
			'transport' => $data['transport'] ?? 'tcp',
			'state' => 'connected',
			'direct' => !empty($data['direct']) || ($data['hops'] ?? 0) === 0,
			'hops' => (int) ($data['hops'] ?? 0),
			'lastSeen' => time(),
			'address' => $data['address'] ?? '',
			'capabilities' => $data['capabilities'] ?? array(),
			'sessionEstablished' => false
		);

		// Auto-handshake if mesh identity is available
		if (Q_WebServer_Mesh::peerId() && !empty($data['address'])) {
			self::$peers[$peerId]['sessionEstablished'] =
				Q_WebServer_Mesh::hasSession($peerId);
		}

		if ($isNew || $wasOffline) {
			self::dispatch('online', self::$peers[$peerId]);

			// Register in routing table for mesh multi-hop
			if (class_exists('Q_WebServer_MeshRouter', false)) {
				Q_WebServer_MeshRouter::addDirectPeer($peerId, $data['name'] ?? $peerId);
			}
		}

		return array('registered' => true, 'peer_id' => $peerId, 'new' => $isNew);
	}

	/**
	 * Unregister a peer. Triggers onPeerOffline.
	 * @param string $peerId
	 * @param string $reason
	 * @return array
	 */
	static function unregisterPeer($peerId, $reason = 'disconnected')
	{
		$peer = self::$peers[$peerId] ?? null;
		if ($peer) {
			$peer['reason'] = $reason;
			self::dispatch('offline', $peer);
			Q_WebServer_Mesh::dropSession($peerId);

			// Remove from routing table (cascades to routes through this peer)
			if (class_exists('Q_WebServer_MeshRouter', false)) {
				Q_WebServer_MeshRouter::removeDirectPeer($peerId);
			}
		}
		unset(self::$peers[$peerId]);
		return array('unregistered' => true, 'peer_id' => $peerId);
	}

	/**
	 * Update a peer's last-seen time (heartbeat).
	 * @param string $peerId
	 * @return array
	 */
	static function heartbeat($peerId)
	{
		if (isset(self::$peers[$peerId])) {
			self::$peers[$peerId]['lastSeen'] = time();
			return array('ok' => true);
		}
		return array('error' => 'Unknown peer');
	}

	/**
	 * Deliver a message from a peer. Triggers onPeerMessage.
	 * @param string $peerId Sender
	 * @param mixed $message
	 * @return array
	 */
	static function deliverMessage($peerId, $message)
	{
		if (!isset(self::$peers[$peerId])) {
			return array('error' => 'Unknown peer');
		}

		// Rate-limit BLE peers
		$peer = self::$peers[$peerId];
		if ($peer['transport'] === 'ble' && class_exists('Q_WebServer_MeshBLE', false)) {
			$rateCheck = Q_WebServer_MeshBLE::rateCheck($peerId);
			if (!$rateCheck['allowed']) {
				return array('error' => 'Rate limited', 'reason' => $rateCheck['reason']);
			}
		}

		self::$peers[$peerId]['lastSeen'] = time();
		self::dispatch('message', array(
			'peer_id' => $peerId,
			'message' => $message
		));
		return array('delivered' => true);
	}

	/**
	 * Get all peers, with optional transport filter.
	 * @param string|null $transport Filter by transport type
	 * @return array
	 */
	static function peers($transport = null)
	{
		self::pruneStale();
		if ($transport === null) {
			return array_values(self::$peers);
		}
		return array_values(array_filter(self::$peers, function ($p) use ($transport) {
			return $p['transport'] === $transport;
		}));
	}

	/**
	 * Get a specific peer by ID.
	 * @param string $peerId
	 * @return array|null
	 */
	static function peer($peerId)
	{
		return self::$peers[$peerId] ?? null;
	}

	/**
	 * Get the count of connected peers.
	 * @return int
	 */
	static function peerCount()
	{
		self::pruneStale();
		return count(self::$peers);
	}

	// ── API Handler ─────────────────────────────────────

	/**
	 * Handle /Q/api/transport/* endpoints.
	 * @param string $action
	 * @param array $data POST body (decoded)
	 * @return array JSON response
	 */
	static function handleApi($action, $data = array())
	{
		self::init();

		switch ($action) {
			case 'peers':
				self::pruneStale();
				return array(
					'peers' => array_values(self::$peers),
					'transports' => self::$enabled,
					'count' => count(self::$peers),
					'mesh_id' => Q_WebServer_Mesh::peerId()
				);

			case 'register':
				return self::registerPeer($data);

			case 'unregister':
				$peerId = $data['peer_id'] ?? ($data['address'] ?? '');
				return self::unregisterPeer($peerId, $data['reason'] ?? 'manual');

			case 'heartbeat':
				$peerId = $data['peer_id'] ?? ($data['address'] ?? '');
				return self::heartbeat($peerId);

			case 'message':
				$peerId = $data['peer_id'] ?? ($data['from'] ?? '');
				return self::deliverMessage($peerId, $data['message'] ?? null);

			case 'event':
				// Unified event endpoint for native bridge
				$type = $data['type'] ?? '';
				if ($type === 'online') return self::registerPeer($data);
				if ($type === 'offline') return self::unregisterPeer(
					$data['peer_id'] ?? '', $data['reason'] ?? 'bridge');
				if ($type === 'message') return self::deliverMessage(
					$data['peer_id'] ?? '', $data['message'] ?? null);
				if ($type === 'heartbeat') return self::heartbeat(
					$data['peer_id'] ?? '');
				return array('error' => 'Unknown event type: ' . $type);

			case 'config':
				if (!empty($data)) {
					foreach (array('ble', 'multipeer', 'wifi_direct') as $t) {
						if (isset($data[$t])) {
							self::$enabled[$t] = (bool) $data[$t];
						}
					}
					self::saveConfig();
				}
				return array('transports' => self::$enabled);

			case 'status':
				self::pruneStale();
				$status = array(
					'mesh_id' => Q_WebServer_Mesh::peerId(),
					'mesh_name' => gethostname(),
					'transports' => self::$enabled,
					'peers' => array_values(self::$peers),
					'count' => count(self::$peers),
					'sessions' => Q_WebServer_Mesh::activeSessions(),
					'capabilities' => self::detectCapabilities()
				);
				// Add routing table if available
				if (class_exists('Q_WebServer_MeshRouter', false)) {
					$routes = Q_WebServer_MeshRouter::routingTable();
					$status['routes'] = array();
					foreach ($routes as $dest => $route) {
						$status['routes'][] = array(
							'destination' => $dest,
							'next_hop' => $route['next_hop'],
							'hops' => $route['hops'],
							'name' => $route['name']
						);
					}
					$status['known_peers'] = Q_WebServer_MeshRouter::knownPeers();
				}
				return $status;

			case 'connect':
				// Connect to a remote peer by address — discover, handshake, register
				$address = $data['address'] ?? '';
				if (empty($address)) return array('error' => 'Missing address');
				return self::connectToPeer($address);

			case 'request':
				// Send an encrypted request to a connected peer
				$peerId = $data['peer_id'] ?? '';
				$method = $data['method'] ?? 'GET';
				$path = $data['path'] ?? '/Q/health';
				$headers = $data['headers'] ?? array();
				$body = $data['body'] ?? '';
				if (empty($peerId)) return array('error' => 'Missing peer_id');
				$result = self::requestFromPeer($peerId, $method, $path, $headers, $body);
				return $result !== null
					? $result
					: array('error' => 'Request failed — no route to peer');

			default:
				return array('error' => 'Unknown transport action: ' . $action);
		}
	}

	// ── Peer Connection (TCP) ───────────────────────────

	/**
	 * Connect to a remote Qbix Server by address.
	 * Discovers identity, performs handshake, registers as peer.
	 *
	 * @param string $address Base URL (e.g. http://127.0.0.1:9090)
	 * @return array Result with peer_id, name, and session status
	 */
	static function connectToPeer($address)
	{
		self::init();
		$address = rtrim($address, '/');

		// Step 1: Discover the remote peer's identity
		$identity = self::httpGet($address . '/Q/sync/identity');
		if (!$identity || empty($identity['peer_id'])) {
			return array('error' => 'Could not reach peer at ' . $address);
		}

		$remotePeerId = $identity['peer_id'];
		$remoteName = $identity['name'] ?? 'Unknown';

		// Step 2: Verify we're not connecting to ourselves
		if ($remotePeerId === Q_WebServer_Mesh::peerId()) {
			return array('error' => 'Cannot connect to self');
		}

		// Step 3: Perform handshake
		// 3a: Send init
		$init = Q_WebServer_Mesh::handshakeInit();
		$init['step'] = 'init';

		$response = self::httpPost($address, '/Q/sync/handshake',
			json_encode($init));
		if (!$response || !empty($response['error'])) {
			return array('error' => 'Handshake init failed: '
				. ($response['error'] ?? 'no response'));
		}

		$respondData = $response['data'] ?? $response;

		// 3b: Complete our side
		$final = Q_WebServer_Mesh::handshakeComplete(
			$respondData, $init['nonce']);
		if (!$final) {
			return array('error' => 'Handshake verification failed');
		}

		// 3c: Send finalize to remote
		$final['step'] = 'finalize';
		$finResult = self::httpPost($address, '/Q/sync/handshake',
			json_encode($final));
		if (!$finResult || !empty($finResult['error'])) {
			return array('error' => 'Handshake finalize failed: '
				. ($finResult['error'] ?? 'no response'));
		}

		// Step 4: Register the peer
		self::registerPeer(array(
			'peer_id' => $remotePeerId,
			'name' => $remoteName,
			'transport' => 'tcp',
			'address' => $address,
			'hops' => 0,
			'direct' => true
		));

		// Mark session as established
		if (isset(self::$peers[$remotePeerId])) {
			self::$peers[$remotePeerId]['sessionEstablished'] =
				Q_WebServer_Mesh::hasSession($remotePeerId);
		}

		return array(
			'connected' => true,
			'peer_id' => $remotePeerId,
			'name' => $remoteName,
			'encrypted' => Q_WebServer_Mesh::hasSession($remotePeerId)
		);
	}

	// ── handleUsingRemote integration ───────────────────

	/**
	 * Get nearby servers for handleUsingRemote.
	 * @return array of [peer_id, name, transport, url]
	 */
	static function nearbyServers()
	{
		self::pruneStale();
		$servers = array();
		foreach (self::$peers as $peer) {
			if ($peer['state'] === 'connected') {
				$servers[] = array(
					'peer_id' => $peer['peer_id'],
					'name' => $peer['name'],
					'transport' => $peer['transport'],
					'url' => 'qbix-peer://' . $peer['peer_id']
				);
			}
		}
		return $servers;
	}

	/**
	 * Send an encrypted HTTP request to a connected peer and return the response.
	 *
	 * @param string $peerId Destination peer_id
	 * @param string $method HTTP method
	 * @param string $path Request path
	 * @param array $headers
	 * @param string $body
	 * @return array|null Decoded JSON response, or null on failure
	 */
	static function requestFromPeer($peerId, $method, $path,
		$headers = array(), $body = '')
	{
		self::init();
		$peer = self::$peers[$peerId] ?? null;

		// If we don't have this peer directly, check the router
		if ((!$peer || $peer['state'] !== 'connected')
			&& class_exists('Q_WebServer_MeshRouter', false))
		{
			$nextHop = Q_WebServer_MeshRouter::nextHop($peerId);
			if ($nextHop && isset(self::$peers[$nextHop])) {
				// Route through the next hop — build a FORWARD envelope
				$httpReq = "$method $path HTTP/1.1\r\nHost: qbix-peer\r\n";
				$httpReq .= "X-Peer-Id: " . Q_WebServer_Mesh::peerId() . "\r\n";
				foreach ($headers as $k => $v) $httpReq .= "$k: $v\r\n";
				if (!empty($body)) $httpReq .= "Content-Length: " . strlen($body) . "\r\n";
				$httpReq .= "\r\n" . $body;

				// Encrypt for the final destination
				if (Q_WebServer_Mesh::hasSession($peerId)) {
					$encrypted = Q_WebServer_Mesh::encrypt($peerId, $httpReq);
					if ($encrypted) {
						$forward = Q_WebServer_MeshRouter::createForward(
							Q_WebServer_Mesh::peerId(), $peerId, $encrypted
						);
						if ($forward) {
							$hopPeer = self::$peers[$nextHop];
							if ($hopPeer['transport'] === 'tcp' && !empty($hopPeer['address'])) {
								return self::httpPost($hopPeer['address'],
									'/Q/sync/forward', json_encode($forward));
							}
						}
					}
				}
				return null;
			}
			return null; // no route
		}

		if (!$peer || $peer['state'] !== 'connected') return null;

		// Build HTTP request string
		$httpReq = "$method $path HTTP/1.1\r\nHost: qbix-peer\r\n";
		$httpReq .= "X-Peer-Id: " . Q_WebServer_Mesh::peerId() . "\r\n";
		foreach ($headers as $k => $v) $httpReq .= "$k: $v\r\n";
		if (!empty($body)) {
			$httpReq .= "Content-Length: " . strlen($body) . "\r\n";
		}
		$httpReq .= "\r\n" . $body;

		// Encrypt if session exists
		$payload = array('from' => Q_WebServer_Mesh::peerId());
		if (Q_WebServer_Mesh::hasSession($peerId)) {
			$encrypted = Q_WebServer_Mesh::encrypt($peerId, $httpReq);
			if ($encrypted) {
				$payload['encrypted'] = true;
				$payload['payload'] = $encrypted;
			} else {
				$payload['encrypted'] = false;
				$payload['raw'] = base64_encode($httpReq);
			}
		} else {
			$payload['encrypted'] = false;
			$payload['raw'] = base64_encode($httpReq);
		}

		// For TCP peers, POST to their /Q/sync/request endpoint
		if ($peer['transport'] === 'tcp' && !empty($peer['address'])) {
			$result = self::httpPost(
				$peer['address'],
				'/Q/sync/request',
				json_encode($payload)
			);
			if (!$result) return null;

			// Decrypt response if encrypted
			if (!empty($result['encrypted']) && !empty($result['payload'])) {
				$plaintext = Q_WebServer_Mesh::decrypt($peerId, $result['payload']);
				if ($plaintext === null) return array('error' => 'Decryption failed');
				// Parse the HTTP response
				return self::parseHttpResponse($plaintext);
			} elseif (!empty($result['raw'])) {
				return self::parseHttpResponse(base64_decode($result['raw']));
			}
			return $result;
		}

		// For BLE/Multipeer, route through native bridge
		return self::httpPost(
			'http://127.0.0.1:' . (Q_WebServer::$port ?: 8080),
			'/Q/transport/bridge-send',
			json_encode(array('peer' => $peerId, 'request' => base64_encode(json_encode($payload))))
		);
	}

	/**
	 * Parse a raw HTTP response into an associative array.
	 * @param string $raw Raw HTTP response
	 * @return array
	 */
	private static function parseHttpResponse($raw)
	{
		$parts = explode("\r\n\r\n", $raw, 2);
		$headerSection = $parts[0] ?? '';
		$body = $parts[1] ?? '';

		$lines = explode("\r\n", $headerSection);
		$statusLine = array_shift($lines);
		preg_match('/HTTP\/\d\.\d (\d+)/', $statusLine, $m);
		$status = (int) ($m[1] ?? 0);

		$headers = array();
		foreach ($lines as $line) {
			$pos = strpos($line, ':');
			if ($pos !== false) {
				$headers[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
			}
		}

		// Try JSON decode the body
		$decoded = json_decode($body, true);
		if ($decoded !== null) {
			$decoded['_status'] = $status;
			$decoded['_headers'] = $headers;
			return $decoded;
		}

		return array(
			'_status' => $status,
			'_headers' => $headers,
			'_body' => $body
		);
	}

	// ── Internal ────────────────────────────────────────

	private static function pruneStale()
	{
		$cutoff = time() - 60;
		foreach (self::$peers as $id => $peer) {
			if ($peer['lastSeen'] < $cutoff) {
				self::unregisterPeer($id, 'timeout');
			}
		}
	}

	private static function detectCapabilities()
	{
		$caps = array(
			'tcp' => true,
			'ble' => false,
			'multipeer' => false,
			'wifi_direct' => false,
			'mesh_identity' => Q_WebServer_Mesh::peerId() !== null,
			'platform' => PHP_OS_FAMILY
		);
		if (defined('QBIX_MOBILE_PLATFORM')) {
			$platform = QBIX_MOBILE_PLATFORM;
			$caps['ble'] = true;
			$caps['multipeer'] = ($platform === 'ios');
			$caps['wifi_direct'] = ($platform === 'android');
		}
		return $caps;
	}

	private static function saveConfig()
	{
		$path = function_exists('qbix_data_path')
			? qbix_data_path('local/transport.json')
			: 'local/transport.json';
		$dir = dirname($path);
		if (!is_dir($dir)) @mkdir($dir, 0755, true);
		@file_put_contents($path, json_encode(self::$enabled, JSON_PRETTY_PRINT));
	}

	private static function httpGet($url)
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_HTTPHEADER => array('Accept: application/json')
		));
		$result = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($result === false || $code >= 400) return null;
		return json_decode($result, true);
	}

	private static function httpPost($baseUrl, $path, $body)
	{
		$url = rtrim($baseUrl, '/') . $path;
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
			CURLOPT_TIMEOUT => 15
		));
		$result = curl_exec($ch);
		curl_close($ch);
		if ($result === false) return null;
		return json_decode($result, true);
	}

	/**
	 * Reset state (for testing).
	 */
	static function reset()
	{
		self::$peers = array();
		self::$handlers = array('online' => array(), 'offline' => array(), 'message' => array());
		self::$initialized = false;
	}
}
