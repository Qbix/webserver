<?php
/**
 * Q_WebServer_Cluster — room leader election with automatic failover.
 *
 * Assigns rooms to servers using consistent hashing of the room name
 * against a sorted peer list. Heartbeats detect peer failure; surviving
 * servers promote themselves in deterministic succession order.
 *
 * Config:
 *   {
 *     "Q": {
 *       "cluster": {
 *         "self": "http://server-a:4001",
 *         "peers": ["http://server-b:4002", "http://server-c:4003"],
 *         "heartbeat": 5,
 *         "timeout": 15,
 *         "roomStrategy": "hash"
 *       }
 *     }
 *   }
 *
 * Or set PEERS env var: PEERS=http://server-b:4002,http://server-c:4003
 *
 * Usage:
 *   Q_WebServer_Cluster::init($config);
 *   $leader = Q_WebServer_Cluster::leaderFor('board/abc123');
 *   if (!Q_WebServer_Cluster::isLeaderFor('board/abc123')) {
 *       // redirect client to $leader
 *   }
 */
class Q_WebServer_Cluster
{
	/** @var string This server's URL */
	private static $self = '';

	/** @var array All servers in the cluster (including self), sorted */
	private static $servers = array();

	/** @var array Peer URLs (excluding self) */
	private static $peers = array();

	/** @var array peer URL => last successful heartbeat timestamp */
	private static $lastSeen = array();

	/** @var array peer URL => consecutive missed heartbeats */
	private static $missed = array();

	/** @var int Heartbeat interval in seconds */
	private static $heartbeatInterval = 5;

	/** @var int Seconds before a peer is considered dead */
	private static $timeout = 15;

	/** @var bool Whether the cluster is active */
	private static $active = false;

	/** @var int Timer ID for heartbeat */
	private static $timerId = null;

	/**
	 * Initialize the cluster from config.
	 * @param array $config The Q.cluster config section
	 */
	static function init($config = null)
	{
		if (!$config) {
			$config = Q_Config::get('Q', 'cluster', array());
		}

		// Determine self URL
		$port = Q_Config::get('Q', 'webserver', 'port', 4000);
		self::$self = $config['self']
			?? getenv('CLUSTER_SELF')
			?: 'http://localhost:' . $port;

		// Gather peers from config and/or environment
		$peers = $config['peers'] ?? array();
		$envPeers = getenv('PEERS') ?: '';
		if ($envPeers) {
			$peers = array_merge($peers,
				array_filter(array_map('trim', explode(',', $envPeers)))
			);
		}
		$peers = array_unique($peers);

		if (empty($peers)) return; // single server, no clustering

		self::$peers = array_values(array_diff($peers, array(self::$self)));
		self::$servers = array_values(array_unique(
			array_merge(array(self::$self), self::$peers)
		));
		sort(self::$servers); // deterministic order

		self::$heartbeatInterval = (int) ($config['heartbeat'] ?? 5);
		self::$timeout = (int) ($config['timeout'] ?? 15);
		self::$active = true;

		// Initialize peer tracking
		$now = time();
		foreach (self::$peers as $peer) {
			self::$lastSeen[$peer] = $now; // assume alive at start
			self::$missed[$peer] = 0;
		}

		// Start heartbeat timer
		if (class_exists('Q_Evented', false)) {
			self::$timerId = Q_Evented::repeat(
				self::$heartbeatInterval,
				array(__CLASS__, 'heartbeat')
			);
		}

		// Register with peers
		foreach (self::$peers as $peer) {
			self::registerWith($peer);
		}
	}

	/**
	 * Check if clustering is active.
	 * @return bool
	 */
	static function isActive()
	{
		return self::$active;
	}

	/**
	 * Get this server's URL.
	 * @return string
	 */
	static function self()
	{
		return self::$self;
	}

	/**
	 * Get the list of all live servers (including self), sorted.
	 * @return array
	 */
	static function liveServers()
	{
		$live = array(self::$self);
		foreach (self::$peers as $peer) {
			if (self::isAlive($peer)) {
				$live[] = $peer;
			}
		}
		sort($live);
		return $live;
	}

	/**
	 * Get all peers (excluding self).
	 * @return array
	 */
	static function peers()
	{
		return self::$peers;
	}

	/**
	 * Determine which server is the leader for a given room.
	 * Uses consistent hashing: crc32(roomName) mod len(liveServers).
	 * Deterministic — every server computes the same answer from
	 * the same live server list.
	 *
	 * @param string $roomName The room identifier (e.g. "board/abc123")
	 * @return string The leader server's URL
	 */
	static function leaderFor($roomName)
	{
		$live = self::liveServers();
		if (empty($live)) return self::$self;
		$index = abs(crc32($roomName)) % count($live);
		return $live[$index];
	}

	/**
	 * Check if this server is the leader for a room.
	 * @param string $roomName
	 * @return bool
	 */
	static function isLeaderFor($roomName)
	{
		if (!self::$active) return true; // no cluster = always leader
		return self::leaderFor($roomName) === self::$self;
	}

	/**
	 * Get the WebSocket URL to redirect a client to the room's leader.
	 * @param string $roomName
	 * @return string|null WebSocket URL, or null if this server is the leader
	 */
	static function leaderWebSocketUrl($roomName)
	{
		if (self::isLeaderFor($roomName)) return null;
		$leader = self::leaderFor($roomName);
		// Convert http(s) to ws(s)
		$wsUrl = preg_replace('/^http/', 'ws', $leader);
		return $wsUrl;
	}

	/**
	 * Check if a peer is alive.
	 * @param string $peerUrl
	 * @return bool
	 */
	static function isAlive($peerUrl)
	{
		if (!isset(self::$lastSeen[$peerUrl])) return false;
		return (time() - self::$lastSeen[$peerUrl]) < self::$timeout;
	}

	/**
	 * Heartbeat — called periodically by the event loop.
	 * Pings each peer's /Q/health endpoint. On failure, increments
	 * the miss counter. After timeout, marks peer as dead and
	 * recalculates room assignments.
	 */
	static function heartbeat()
	{
		foreach (self::$peers as $peer) {
			$alive = self::pingPeer($peer);
			if ($alive) {
				self::$lastSeen[$peer] = time();
				self::$missed[$peer] = 0;
			} else {
				self::$missed[$peer]++;
				$elapsed = time() - (self::$lastSeen[$peer] ?? 0);
				if ($elapsed >= self::$timeout) {
					self::onPeerDead($peer);
				}
			}
		}
	}

	/**
	 * Ping a peer's health endpoint.
	 * @param string $peerUrl
	 * @return bool
	 */
	private static function pingPeer($peerUrl)
	{
		$url = rtrim($peerUrl, '/') . '/Q/health';
		$ctx = stream_context_create(array('http' => array(
			'method' => 'GET',
			'timeout' => 2,
			'ignore_errors' => true,
		)));
		$result = @file_get_contents($url, false, $ctx);
		if ($result === false) return false;
		$data = @json_decode($result, true);
		return !empty($data['status']) && $data['status'] === 'ok';
	}

	/**
	 * Handle a peer going dead — log it and let room reassignment
	 * happen naturally via leaderFor() (which only considers live servers).
	 */
	private static function onPeerDead($peerUrl)
	{
		// Room reassignment is automatic: leaderFor() uses liveServers()
		// which excludes dead peers. The next call to isLeaderFor() will
		// return true for rooms that were on the dead peer and hash to us.
		fwrite(STDERR, date('H:i:s')
			. " cluster: peer $peerUrl is dead (missed "
			. self::$missed[$peerUrl] . " heartbeats)\n");
	}

	/**
	 * Register this server with a peer.
	 */
	private static function registerWith($peerUrl)
	{
		$url = rtrim($peerUrl, '/') . '/Q/cluster/join';
		$ctx = stream_context_create(array('http' => array(
			'method' => 'POST',
			'header' => "Content-Type: application/json\r\n",
			'content' => json_encode(array(
				'url' => self::$self,
				'fingerprint' => Q_Config::get('Q', 'internal', 'fingerprint', ''),
			)),
			'timeout' => 3,
			'ignore_errors' => true,
		)));
		@file_get_contents($url, false, $ctx);
	}

	/**
	 * Handle a join request from another server.
	 * @param array $data {url, fingerprint}
	 */
	static function handleJoin($data)
	{
		$url = $data['url'] ?? '';
		if (!$url || $url === self::$self) return;
		if (!in_array($url, self::$peers)) {
			self::$peers[] = $url;
			self::$servers[] = $url;
			sort(self::$servers);
		}
		self::$lastSeen[$url] = time();
		self::$missed[$url] = 0;
	}

	/**
	 * Check if an event should be replicated to peers.
	 * Events are replicated if they appear in the Q.cluster.replicate config list.
	 * A wildcard '*' means replicate everything.
	 *
	 * @param string $eventName
	 * @return bool
	 */
	static function shouldReplicate($eventName)
	{
		$replicate = Q_Config::get('Q', 'cluster', 'replicate', array());
		if (empty($replicate)) return false;
		if (in_array('*', $replicate)) return true;
		// Check exact match and prefix match (e.g. "swarm/" matches "swarm/task_add")
		foreach ($replicate as $pattern) {
			if ($pattern === $eventName) return true;
			if (substr($pattern, -1) === '/' && strpos($eventName, $pattern) === 0) return true;
		}
		return false;
	}

	/**
	 * Get cluster status for the dashboard / health endpoint.
	 * @return array
	 */
	static function status()
	{
		if (!self::$active) {
			return array('active' => false, 'mode' => 'standalone');
		}
		$peers = array();
		foreach (self::$peers as $peer) {
			$peers[] = array(
				'url' => $peer,
				'alive' => self::isAlive($peer),
				'lastSeen' => self::$lastSeen[$peer] ?? 0,
				'missed' => self::$missed[$peer] ?? 0,
			);
		}
		return array(
			'active' => true,
			'self' => self::$self,
			'servers' => self::$servers,
			'liveServers' => self::liveServers(),
			'peers' => $peers,
			'heartbeat' => self::$heartbeatInterval,
			'timeout' => self::$timeout,
		);
	}

	/**
	 * Forward an event to all peers (fire-and-forget).
	 * Used for persisting mutations across the cluster.
	 *
	 * @param string $eventPath  e.g. "board/card_add"
	 * @param array  $data       Event payload
	 * @param string $method     HTTP method
	 */
	static function broadcast($eventPath, $data, $method = 'POST')
	{
		foreach (self::$peers as $peer) {
			if (!self::isAlive($peer)) continue;
			$url = rtrim($peer, '/') . '/Q/event';
			$ctx = stream_context_create(array('http' => array(
				'method' => $method,
				'header' => "Content-Type: application/json\r\n"
					. "X-Forwarded-From: " . self::$self . "\r\n"
					. "X-Q-Event: $eventPath\r\n",
				'content' => json_encode($data),
				'timeout' => 2,
				'ignore_errors' => true,
			)));
			@file_get_contents($url, false, $ctx);
		}
	}

	/**
	 * Sync state from a peer (pull all data).
	 * Called on startup when PEERS env is set.
	 *
	 * @param string $peerUrl
	 * @param string $endpoint The sync endpoint path
	 * @return array|null The synced data, or null on failure
	 */
	static function syncFrom($peerUrl, $endpoint = '/api.php?action=sync')
	{
		$url = rtrim($peerUrl, '/') . $endpoint;
		$ctx = stream_context_create(array('http' => array(
			'timeout' => 10,
			'ignore_errors' => true,
		)));
		$result = @file_get_contents($url, false, $ctx);
		if (!$result) return null;
		return json_decode($result, true);
	}
}
