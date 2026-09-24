<?php
/**
 * Mesh routing: distance-vector routing table with HELLO/BYE/HEARTBEAT
 * propagation and FORWARD relaying for multi-hop encrypted messages.
 *
 * Each peer maintains a routing table of (destination, next_hop, hops, last_seen).
 * Route announcements propagate through the mesh with a TTL limit.
 * Encrypted messages to non-directly-connected peers are forwarded hop-by-hop.
 *
 * @class Q_WebServer_MeshRouter
 */
class Q_WebServer_MeshRouter
{
	/**
	 * Routing table: peer_id => [next_hop, hops, last_seen, name]
	 * Direct peers have next_hop = peer_id and hops = 0.
	 */
	private static $routes = array();

	/**
	 * Directly connected peers (peer_ids we have a session with).
	 */
	private static $directPeers = array(); // peer_id => true

	/**
	 * Seen message IDs for deduplication (LRU).
	 * Prevents routing loops and duplicate propagation.
	 */
	private static $seenMessages = array(); // msg_id => timestamp
	const MAX_SEEN = 1000;

	/**
	 * Maximum TTL for route announcements and forwarded messages.
	 */
	const MAX_TTL = 7;

	/**
	 * Route expiry in seconds (no heartbeat = pruned).
	 */
	const ROUTE_EXPIRY = 60;

	/**
	 * Route dampening: max flaps before hold-down.
	 */
	const MAX_FLAPS = 3;
	const FLAP_WINDOW = 60;
	const HOLD_DOWN = 30;
	private static $flapCount = array();   // peer_id => [count, window_start]
	private static $holdDown = array();    // peer_id => hold_until_timestamp

	// ── Direct Peer Management ──────────────────────────

	/**
	 * Register a directly connected peer.
	 * Adds a route with hops=0 and generates HELLO messages for propagation.
	 *
	 * @param string $peerId
	 * @param string $name
	 * @return array HELLO messages to send to other direct peers
	 */
	static function addDirectPeer($peerId, $name = '')
	{
		self::$directPeers[$peerId] = true;
		self::$routes[$peerId] = array(
			'next_hop' => $peerId,
			'hops' => 0,
			'last_seen' => time(),
			'name' => $name ?: $peerId
		);

		// Generate HELLO messages to propagate to other direct peers
		return self::generateHellosForPeer($peerId, $name);
	}

	/**
	 * Remove a directly connected peer.
	 * Removes the route and all routes that went through this peer.
	 * Returns BYE messages to propagate.
	 *
	 * @param string $peerId
	 * @return array BYE messages to send to other direct peers
	 */
	static function removeDirectPeer($peerId)
	{
		unset(self::$directPeers[$peerId]);

		// Remove all routes that use this peer as next_hop
		$removed = array();
		foreach (self::$routes as $dest => $route) {
			if ($route['next_hop'] === $peerId) {
				$removed[] = $dest;
				unset(self::$routes[$dest]);
			}
		}

		// Generate BYE messages for removed routes
		$byes = array();
		foreach ($removed as $dest) {
			$byes[] = array(
				'type' => 'bye',
				'peer_id' => $dest,
				'reason' => 'next_hop_lost',
				'msg_id' => self::generateMsgId(),
				'ttl' => self::MAX_TTL
			);
		}
		return $byes;
	}

	/**
	 * Check if a peer is directly connected.
	 * @param string $peerId
	 * @return bool
	 */
	static function isDirect($peerId)
	{
		return !empty(self::$directPeers[$peerId]);
	}

	// ── Route Lookup ────────────────────────────────────

	/**
	 * Get the next hop to reach a destination peer.
	 * @param string $destPeerId
	 * @return string|null next_hop peer_id, or null if no route
	 */
	static function nextHop($destPeerId)
	{
		if (!isset(self::$routes[$destPeerId])) return null;
		$route = self::$routes[$destPeerId];
		// Check if route is expired
		if (time() - $route['last_seen'] > self::ROUTE_EXPIRY) {
			unset(self::$routes[$destPeerId]);
			return null;
		}
		return $route['next_hop'];
	}

	/**
	 * Get the full routing table.
	 * @return array
	 */
	static function routingTable()
	{
		self::pruneStale();
		return self::$routes;
	}

	/**
	 * Get the hop count to a destination.
	 * @param string $destPeerId
	 * @return int|null hops, or null if no route
	 */
	static function hopsTo($destPeerId)
	{
		if (!isset(self::$routes[$destPeerId])) return null;
		return self::$routes[$destPeerId]['hops'];
	}

	/**
	 * Get all known peer IDs (directly connected + routed).
	 * @return array
	 */
	static function knownPeers()
	{
		self::pruneStale();
		return array_keys(self::$routes);
	}

	// ── Message Handlers ────────────────────────────────

	/**
	 * Process a HELLO message from a neighbor.
	 * Updates routing table and returns messages to propagate.
	 *
	 * @param string $fromPeerId The directly-connected neighbor that sent this
	 * @param array $hello The HELLO message
	 * @return array Messages to propagate to other direct peers
	 */
	static function handleHello($fromPeerId, $hello)
	{
		$msgId = $hello['msg_id'] ?? '';
		if (self::isDuplicate($msgId)) return array();

		$announcedPeer = $hello['peer_id'] ?? '';
		$name = $hello['name'] ?? '';
		$hops = (int) ($hello['hops'] ?? 0);
		$ttl = (int) ($hello['ttl'] ?? self::MAX_TTL);

		if (empty($announcedPeer)) return array();

		// Don't add a route to ourselves
		if (class_exists('Q_WebServer_Mesh') && $announcedPeer === Q_WebServer_Mesh::peerId()) {
			return array();
		}

		// Check hold-down (dampening)
		if (self::isHeldDown($announcedPeer)) return array();

		// Update routing table: accept if new or shorter path
		$existing = self::$routes[$announcedPeer] ?? null;
		$newHops = $hops + 1;

		if (!$existing || $newHops < $existing['hops']
			|| ($newHops === $existing['hops'] && time() - ($existing['last_seen'] ?? 0) > 30))
		{
			// Track flapping
			if ($existing && $existing['next_hop'] !== $fromPeerId) {
				self::recordFlap($announcedPeer);
				if (self::isHeldDown($announcedPeer)) return array();
			}

			self::$routes[$announcedPeer] = array(
				'next_hop' => $fromPeerId,
				'hops' => $newHops,
				'last_seen' => time(),
				'name' => $name ?: ($existing['name'] ?? $announcedPeer)
			);
		} else {
			// Same or worse route — just refresh last_seen if same path
			if ($existing['next_hop'] === $fromPeerId) {
				self::$routes[$announcedPeer]['last_seen'] = time();
			}
			// Don't propagate — we already have a better route
			return array();
		}

		// Propagate with hops+1, ttl-1 (if ttl > 0)
		if ($ttl <= 1) return array(); // TTL expired

		$propagated = $hello;
		$propagated['hops'] = $newHops;
		$propagated['ttl'] = $ttl - 1;

		return array($propagated);
	}

	/**
	 * Process a BYE message.
	 * Removes the route and propagates.
	 *
	 * @param string $fromPeerId
	 * @param array $bye
	 * @return array Messages to propagate
	 */
	static function handleBye($fromPeerId, $bye)
	{
		$msgId = $bye['msg_id'] ?? '';
		if (self::isDuplicate($msgId)) return array();

		$gonePeer = $bye['peer_id'] ?? '';
		$ttl = (int) ($bye['ttl'] ?? self::MAX_TTL);

		if (empty($gonePeer)) return array();

		// Only remove if our route goes through the reporting neighbor
		$route = self::$routes[$gonePeer] ?? null;
		if ($route && $route['next_hop'] === $fromPeerId) {
			unset(self::$routes[$gonePeer]);
		}

		// Propagate
		if ($ttl <= 1) return array();
		$propagated = $bye;
		$propagated['ttl'] = $ttl - 1;
		return array($propagated);
	}

	/**
	 * Process a HEARTBEAT from a neighbor.
	 * Updates last_seen for all routes through this neighbor.
	 * Discovers new peers from the neighbor's known_peers list.
	 *
	 * @param string $fromPeerId
	 * @param array $heartbeat
	 * @return array HELLO requests to send (for peers we don't know about)
	 */
	static function handleHeartbeat($fromPeerId, $heartbeat)
	{
		$knownPeers = $heartbeat['known_peers'] ?? array();

		// Refresh last_seen for this direct peer
		if (isset(self::$routes[$fromPeerId])) {
			self::$routes[$fromPeerId]['last_seen'] = time();
		}

		// Refresh last_seen for routes through this neighbor
		foreach (self::$routes as $dest => &$route) {
			if ($route['next_hop'] === $fromPeerId) {
				$route['last_seen'] = time();
			}
		}
		unset($route);

		// Check if neighbor knows peers we don't
		$unknown = array();
		$myId = class_exists('Q_WebServer_Mesh') ? Q_WebServer_Mesh::peerId() : '';
		foreach ($knownPeers as $peerId) {
			if ($peerId !== $myId && !isset(self::$routes[$peerId])) {
				$unknown[] = $peerId;
			}
		}

		return $unknown; // caller should request HELLOs for these
	}

	/**
	 * Create a FORWARD envelope for relaying an encrypted message.
	 *
	 * @param string $fromPeerId Original sender
	 * @param string $toPeerId Final destination
	 * @param array $encryptedPayload The encrypted message (nonce, ciphertext, tag)
	 * @return array|null FORWARD message, or null if no route
	 */
	static function createForward($fromPeerId, $toPeerId, $encryptedPayload)
	{
		$nextHop = self::nextHop($toPeerId);
		if ($nextHop === null) return null;

		return array(
			'type' => 'forward',
			'from' => $fromPeerId,
			'to' => $toPeerId,
			'next_hop' => $nextHop,
			'msg_id' => self::generateMsgId(),
			'ttl' => self::MAX_TTL,
			'payload' => $encryptedPayload
		);
	}

	/**
	 * Process a received FORWARD message.
	 * If we're the destination, return the payload for decryption.
	 * If not, relay to the next hop.
	 *
	 * @param string $fromPeerId The neighbor that sent this
	 * @param array $forward The FORWARD message
	 * @param string $myPeerId Our own peer_id
	 * @return array Result: [action => 'deliver'|'relay'|'drop', ...]
	 */
	static function handleForward($fromPeerId, $forward, $myPeerId)
	{
		$msgId = $forward['msg_id'] ?? '';
		if (self::isDuplicate($msgId)) {
			return array('action' => 'drop', 'reason' => 'duplicate');
		}

		$to = $forward['to'] ?? '';
		$ttl = (int) ($forward['ttl'] ?? 0);

		// Are we the destination?
		if ($to === $myPeerId) {
			return array(
				'action' => 'deliver',
				'from' => $forward['from'],
				'payload' => $forward['payload']
			);
		}

		// TTL check
		if ($ttl <= 1) {
			return array('action' => 'drop', 'reason' => 'ttl_expired');
		}

		// Find next hop
		$nextHop = self::nextHop($to);
		if ($nextHop === null) {
			return array('action' => 'drop', 'reason' => 'no_route');
		}

		// Relay: decrement TTL and forward
		$relayed = $forward;
		$relayed['ttl'] = $ttl - 1;
		$relayed['next_hop'] = $nextHop;

		return array(
			'action' => 'relay',
			'next_hop' => $nextHop,
			'message' => $relayed
		);
	}

	// ── Route Generation ────────────────────────────────

	/**
	 * Generate HELLO messages for a newly connected peer.
	 * Tells all other direct peers about the new peer,
	 * and tells the new peer about all known routes.
	 *
	 * @param string $newPeerId
	 * @param string $name
	 * @return array [for_new_peer => [...hellos...], for_others => [...hellos...]]
	 */
	private static function generateHellosForPeer($newPeerId, $name)
	{
		$myId = class_exists('Q_WebServer_Mesh') ? Q_WebServer_Mesh::peerId() : '';
		$forOthers = array();
		$forNew = array();

		// Tell others about the new peer
		$hello = array(
			'type' => 'hello',
			'peer_id' => $newPeerId,
			'name' => $name,
			'hops' => 0,
			'ttl' => self::MAX_TTL,
			'msg_id' => self::generateMsgId()
		);
		$forOthers[] = $hello;

		// Tell the new peer about all our other known peers
		foreach (self::$routes as $dest => $route) {
			if ($dest === $newPeerId || $dest === $myId) continue;
			$forNew[] = array(
				'type' => 'hello',
				'peer_id' => $dest,
				'name' => $route['name'],
				'hops' => $route['hops'],
				'ttl' => self::MAX_TTL - $route['hops'],
				'msg_id' => self::generateMsgId()
			);
		}

		// Also announce ourselves to the new peer
		if (!empty($myId)) {
			$forNew[] = array(
				'type' => 'hello',
				'peer_id' => $myId,
				'name' => gethostname(),
				'hops' => 0,
				'ttl' => self::MAX_TTL,
				'msg_id' => self::generateMsgId()
			);
		}

		return array('for_new_peer' => $forNew, 'for_others' => $forOthers);
	}

	/**
	 * Generate a heartbeat message.
	 * @return array
	 */
	static function generateHeartbeat()
	{
		return array(
			'type' => 'heartbeat',
			'peer_id' => class_exists('Q_WebServer_Mesh') ? Q_WebServer_Mesh::peerId() : '',
			'known_peers' => array_keys(self::$routes),
			'timestamp' => time()
		);
	}

	// ── Internal ────────────────────────────────────────

	/**
	 * Check and record a message ID for deduplication.
	 * @param string $msgId
	 * @return bool true if already seen (duplicate)
	 */
	private static function isDuplicate($msgId)
	{
		if (empty($msgId)) return false;
		if (isset(self::$seenMessages[$msgId])) return true;

		self::$seenMessages[$msgId] = time();

		// Prune old entries if cache is too large
		if (count(self::$seenMessages) > self::MAX_SEEN) {
			// Keep newest half
			arsort(self::$seenMessages);
			self::$seenMessages = array_slice(
				self::$seenMessages, 0, self::MAX_SEEN / 2, true);
		}

		return false;
	}

	/**
	 * Prune stale routes.
	 */
	static function pruneStale()
	{
		$cutoff = time() - self::ROUTE_EXPIRY;
		foreach (self::$routes as $dest => $route) {
			if ($route['last_seen'] < $cutoff && !isset(self::$directPeers[$dest])) {
				unset(self::$routes[$dest]);
			}
		}
	}

	/**
	 * Record a route flap for dampening.
	 */
	private static function recordFlap($peerId)
	{
		$now = time();
		if (!isset(self::$flapCount[$peerId])
			|| $now - self::$flapCount[$peerId]['start'] > self::FLAP_WINDOW) {
			self::$flapCount[$peerId] = array('count' => 1, 'start' => $now);
		} else {
			self::$flapCount[$peerId]['count']++;
		}
		if (self::$flapCount[$peerId]['count'] >= self::MAX_FLAPS) {
			self::$holdDown[$peerId] = $now + self::HOLD_DOWN;
		}
	}

	/**
	 * Check if a peer is in hold-down (dampened).
	 */
	private static function isHeldDown($peerId)
	{
		if (!isset(self::$holdDown[$peerId])) return false;
		if (time() >= self::$holdDown[$peerId]) {
			unset(self::$holdDown[$peerId], self::$flapCount[$peerId]);
			return false;
		}
		return true;
	}

	private static function generateMsgId()
	{
		return bin2hex(random_bytes(8));
	}

	/**
	 * Reset all state (for testing).
	 */
	static function reset()
	{
		self::$routes = array();
		self::$directPeers = array();
		self::$seenMessages = array();
		self::$flapCount = array();
		self::$holdDown = array();
	}
}
