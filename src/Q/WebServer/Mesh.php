<?php
/**
 * Peer-to-peer mesh identity and encrypted session management.
 *
 * Identity: peer_id = hex(sha256(public_key_der)). Same security model
 * as Ethereum — identity IS the key. No CA, no registration.
 *
 * Handshake: cert exchange → mutual authentication → ECDH key agreement.
 * Session encryption: AES-256-GCM with HKDF-derived session key.
 *
 * @class Q_WebServer_Mesh
 */
class Q_WebServer_Mesh
{
	private static $privateKey = null;   // OpenSSL key resource
	private static $publicKeyPem = null; // PEM string
	private static $certPem = null;      // Self-signed X.509 PEM
	private static $peerId = null;       // hex sha256 of DER public key
	private static $sessions = array();  // peer_id => [key, nonceCounter, established]
	private static $keyDir = null;

	// ── Identity ────────────────────────────────────────

	/**
	 * Initialize mesh identity. Loads existing keypair or generates a new one.
	 * @param string|null $dataDir Data directory (default: QBIX_DATA_DIR or 'local')
	 */
	static function init($dataDir = null)
	{
		if (self::$peerId !== null) return; // already initialized

		self::$keyDir = $dataDir
			? rtrim($dataDir, '/') . '/keys'
			: (getenv('QBIX_MESH_DIR')
				?: (function_exists('qbix_data_path')
					? qbix_data_path('keys')
					: 'local/keys'));

		if (!is_dir(self::$keyDir)) {
			@mkdir(self::$keyDir, 0700, true);
		}

		$keyPath = self::$keyDir . '/mesh.key';
		$certPath = self::$keyDir . '/mesh.crt';

		if (file_exists($keyPath) && file_exists($certPath)) {
			self::loadKeypair($keyPath, $certPath);
		} else {
			self::generateKeypair($keyPath, $certPath);
		}
	}

	/**
	 * Get this peer's identity.
	 * @return string 64-character hex string
	 */
	static function peerId()
	{
		if (self::$peerId === null) self::init();
		return self::$peerId;
	}

	/**
	 * Get this peer's certificate in PEM format.
	 * @return string
	 */
	static function certPem()
	{
		if (self::$certPem === null) self::init();
		return self::$certPem;
	}

	/**
	 * Get this peer's public key in PEM format.
	 * @return string
	 */
	static function publicKeyPem()
	{
		if (self::$publicKeyPem === null) self::init();
		return self::$publicKeyPem;
	}

	/**
	 * Derive a peer_id from a PEM-encoded public key or certificate.
	 * @param string $pem Public key or certificate PEM
	 * @return string|null 64-char hex peer_id, or null on failure
	 */
	static function deriveId($pem)
	{
		$pubDer = self::publicKeyDer($pem);
		if ($pubDer === null) return null;
		return hash('sha256', $pubDer);
	}

	/**
	 * Verify that a certificate's public key matches a claimed peer_id.
	 * @param string $certPem Certificate PEM
	 * @param string $claimedId Claimed peer_id (64-char hex)
	 * @return bool
	 */
	static function verifyIdentity($certPem, $claimedId)
	{
		$derived = self::deriveId($certPem);
		return $derived !== null && hash_equals($derived, strtolower($claimedId));
	}

	// ── Handshake ───────────────────────────────────────

	/**
	 * Create a handshake initiation message (Step 1).
	 * @return array {cert, nonce, peer_id}
	 */
	static function handshakeInit()
	{
		self::init();
		$nonce = bin2hex(random_bytes(16));
		return array(
			'cert' => self::$certPem,
			'nonce' => $nonce,
			'peer_id' => self::$peerId
		);
	}

	/**
	 * Process a handshake initiation and create response (Steps 1-2).
	 *
	 * @param array $init The received init message {cert, nonce, peer_id}
	 * @return array|null {cert, nonce, signature, peer_id, ecdh_pub} or null on failure
	 */
	static function handshakeRespond($init)
	{
		self::init();

		// Verify their identity
		if (!self::verifyIdentity($init['cert'], $init['peer_id'])) {
			return null; // identity mismatch — abort
		}

		// Sign their nonce to prove we hold our private key
		$signature = self::sign($init['nonce']);
		if ($signature === null) return null;

		// Generate ephemeral ECDH keypair
		$ecdh = self::generateEphemeralKey();
		if ($ecdh === null) return null;

		// Store their cert for later verification
		$myNonce = bin2hex(random_bytes(16));

		// Stash state for completing the handshake
		$pending = array(
			'their_cert' => $init['cert'],
			'their_peer_id' => $init['peer_id'],
			'their_nonce' => $init['nonce'],
			'my_nonce' => $myNonce,
			'ecdh_private' => $ecdh['private_pem'],
			'ecdh_public' => $ecdh['public_pem']
		);
		// Store keyed by their peer_id
		self::$sessions[$init['peer_id']] = array(
			'pending' => $pending,
			'established' => false
		);

		return array(
			'cert' => self::$certPem,
			'nonce' => $myNonce,
			'signature' => $signature,
			'peer_id' => self::$peerId,
			'ecdh_pub' => $ecdh['public_pem']
		);
	}

	/**
	 * Complete the handshake as the initiator (Steps 2-3).
	 *
	 * @param array $response The response from handshakeRespond
	 * @param string $myNonce The nonce we sent in handshakeInit
	 * @return array|null {peer_id, signature, ecdh_pub} to send as final step, or null
	 */
	static function handshakeComplete($response, $myNonce)
	{
		self::init();

		// Verify their identity
		if (!self::verifyIdentity($response['cert'], $response['peer_id'])) {
			return null;
		}

		// Verify their signature on our nonce
		if (!self::verifySignature($myNonce, $response['signature'], $response['cert'])) {
			return null;
		}

		// Sign their nonce
		$signature = self::sign($response['nonce']);
		if ($signature === null) return null;

		// Generate our ECDH ephemeral key
		$ecdh = self::generateEphemeralKey();
		if ($ecdh === null) return null;

		// Derive shared secret
		$sharedSecret = self::ecdhDerive($ecdh['private_pem'], $response['ecdh_pub']);
		if ($sharedSecret === null) return null;

		// Derive session key via HKDF
		$salt = hex2bin($myNonce) . hex2bin($response['nonce']);
		$sessionKey = self::hkdf($sharedSecret, $salt, 'qbix-mesh', 32);

		// Establish session
		self::$sessions[$response['peer_id']] = array(
			'key' => $sessionKey,
			'nonceCounter' => 0,
			'established' => true,
			'establishedAt' => time()
		);

		return array(
			'peer_id' => self::$peerId,
			'signature' => $signature,
			'ecdh_pub' => $ecdh['public_pem']
		);
	}

	/**
	 * Finalize handshake as the responder (Step 3).
	 *
	 * @param array $final The final message from handshakeComplete
	 * @return bool true if session established
	 */
	static function handshakeFinalize($final)
	{
		$theirId = $final['peer_id'];
		if (!isset(self::$sessions[$theirId]['pending'])) return false;

		$pending = self::$sessions[$theirId]['pending'];

		// Verify their signature on our nonce
		if (!self::verifySignature(
			$pending['my_nonce'],
			$final['signature'],
			$pending['their_cert']
		)) {
			unset(self::$sessions[$theirId]);
			return false;
		}

		// Derive shared secret
		$sharedSecret = self::ecdhDerive(
			$pending['ecdh_private'],
			$final['ecdh_pub']
		);
		if ($sharedSecret === null) {
			unset(self::$sessions[$theirId]);
			return false;
		}

		// Derive session key (same salt construction, but responder reverses nonce order)
		$salt = hex2bin($pending['their_nonce']) . hex2bin($pending['my_nonce']);
		$sessionKey = self::hkdf($sharedSecret, $salt, 'qbix-mesh', 32);

		// Establish session
		self::$sessions[$theirId] = array(
			'key' => $sessionKey,
			'nonceCounter' => 0,
			'established' => true,
			'establishedAt' => time()
		);

		return true;
	}

	// ── Encryption ──────────────────────────────────────

	/**
	 * Encrypt a message for a specific peer.
	 * @param string $peerId Destination peer_id
	 * @param string $plaintext Data to encrypt
	 * @return array|null {nonce, ciphertext, tag} or null if no session
	 */
	static function encrypt($peerId, $plaintext)
	{
		$session = self::$sessions[$peerId] ?? null;
		if (!$session || empty($session['established'])) return null;

		$counter = ++self::$sessions[$peerId]['nonceCounter'];
		// 12-byte nonce: 4 bytes direction flag + 8 bytes counter
		$nonce = pack('N', 1) . pack('J', $counter); // 1 = outgoing

		$tag = '';
		$ciphertext = openssl_encrypt(
			$plaintext, 'aes-256-gcm', $session['key'],
			OPENSSL_RAW_DATA, $nonce, $tag
		);
		if ($ciphertext === false) return null;

		return array(
			'nonce' => bin2hex($nonce),
			'ciphertext' => base64_encode($ciphertext),
			'tag' => bin2hex($tag)
		);
	}

	/**
	 * Decrypt a message from a specific peer.
	 * @param string $peerId Sender peer_id
	 * @param array $message {nonce, ciphertext, tag}
	 * @return string|null Plaintext or null if decryption fails
	 */
	static function decrypt($peerId, $message)
	{
		$session = self::$sessions[$peerId] ?? null;
		if (!$session || empty($session['established'])) return null;

		$nonce = hex2bin($message['nonce']);
		$ciphertext = base64_decode($message['ciphertext']);
		$tag = hex2bin($message['tag']);

		// Replay protection: check nonce counter
		$counter = unpack('J', substr($nonce, 4))[1];
		$highWater = $session['highWater'] ?? 0;
		if ($counter <= $highWater) return null; // replay
		self::$sessions[$peerId]['highWater'] = $counter;

		$plaintext = openssl_decrypt(
			$ciphertext, 'aes-256-gcm', $session['key'],
			OPENSSL_RAW_DATA, $nonce, $tag
		);

		return $plaintext === false ? null : $plaintext;
	}

	/**
	 * Check if we have an established session with a peer.
	 * @param string $peerId
	 * @return bool
	 */
	static function hasSession($peerId)
	{
		return !empty(self::$sessions[$peerId]['established']);
	}

	/**
	 * Get all active session peer IDs.
	 * @return array
	 */
	static function activeSessions()
	{
		$active = array();
		foreach (self::$sessions as $id => $s) {
			if (!empty($s['established'])) {
				$active[] = $id;
			}
		}
		return $active;
	}

	/**
	 * Drop a session (peer disconnected or key expired).
	 * @param string $peerId
	 */
	static function dropSession($peerId)
	{
		unset(self::$sessions[$peerId]);
	}

	// ── HTTP Endpoints ──────────────────────────────────

	/**
	 * Run a raw HTTP request from a peer against this server's router and
	 * return the raw HTTP response.
	 * @method dispatchLocal
	 * @static
	 * @param {string} $httpRequest Raw HTTP/1.1 request
	 * @param {string} $from Sender's peer_id
	 * @return {string} Raw HTTP/1.1 response
	 */
	static function dispatchLocal($httpRequest, $from)
	{
		if (!class_exists('Q_WebServer', false)
			|| !method_exists('Q_WebServer', 'parseRequest')
			|| !method_exists('Q_WebServer', 'route')
		) {
			return "HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\n\r\n";
		}
		$parsed = Q_WebServer::parseRequest($httpRequest);
		if (!empty($parsed['_malformed']) || !empty($parsed['_badMethod'])) {
			return "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n";
		}
		$parsed['headers']['x-peer-id'] = $from;
		$parsed['headers']['x-mesh-request'] = '1';
		$parsed['clientIp'] = '127.0.0.1';
		try {
			$response = Q_WebServer::route($parsed);
		} catch (\Throwable $e) {
			$response = array('status' => 500, 'body' => 'Internal Server Error');
		}
		if (!is_array($response)) {
			$response = array('status' => 502, 'body' => '');
		}
		$status = (int) ($response['status'] ?? 200);
		$body = (string) ($response['body'] ?? '');
		$out = "HTTP/1.1 $status " . self::reasonPhrase($status) . "\r\n";
		foreach (($response['headers'] ?? array()) as $k => $v) {
			if (strcasecmp($k, 'Content-Length') === 0) continue;
			foreach ((array) $v as $one) {
				$out .= "$k: $one\r\n";
			}
		}
		$out .= "Content-Length: " . strlen($body) . "\r\n\r\n" . $body;
		return $out;
	}

	private static function reasonPhrase($status)
	{
		$phrases = array(200 => 'OK', 201 => 'Created', 204 => 'No Content',
			301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified',
			400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
			404 => 'Not Found', 500 => 'Internal Server Error', 502 => 'Bad Gateway');
		return $phrases[$status] ?? 'Status';
	}

	/**
	 * Handle /Q/sync/* endpoints.
	 * @param string $action identity|handshake
	 * @param array $data POST body (decoded JSON)
	 * @return array JSON response
	 */
	static function handleSyncApi($action, $data = array())
	{
		self::init();

		switch ($action) {
			case 'identity':
				return array(
					'peer_id' => self::$peerId,
					'cert' => self::$certPem,
					'name' => gethostname()
				);

			case 'handshake':
				$step = $data['step'] ?? '';
				if ($step === 'init') {
					// We received an init — respond
					$response = self::handshakeRespond($data);
					if ($response === null) {
						return array('error' => 'Handshake failed: identity verification');
					}
					return array('step' => 'respond', 'data' => $response);
				}
				if ($step === 'finalize') {
					// We received the final message — complete the session
					$ok = self::handshakeFinalize($data);
					return $ok
						? array('step' => 'done', 'established' => true)
						: array('error' => 'Handshake finalize failed');
				}
				return array('error' => 'Unknown handshake step');

			case 'ping':
				// Encrypted ping — verify session works
				$from = $data['from'] ?? '';
				if (!self::hasSession($from)) {
					return array('error' => 'No session with ' . substr($from, 0, 8));
				}
				$decrypted = self::decrypt($from, $data['message']);
				if ($decrypted === null) {
					return array('error' => 'Decryption failed');
				}
				// Encrypt pong response
				$pong = self::encrypt($from, 'pong:' . $decrypted);
				return array(
					'pong' => $pong,
					'decrypted' => $decrypted
				);

			case 'request':
				// Receive an encrypted HTTP request from a peer, process it locally,
				// encrypt the response, and return it.
				$from = $data['from'] ?? '';
				if (empty($from)) {
					return array('error' => 'Missing from peer_id');
				}

				// Decrypt the request
				$httpRequest = null;
				if (!empty($data['encrypted']) && !empty($data['payload'])) {
					if (!self::hasSession($from)) {
						return array('error' => 'No session with peer');
					}
					$httpRequest = self::decrypt($from, $data['payload']);
					if ($httpRequest === null) {
						return array('error' => 'Decryption failed');
					}
				} elseif (!empty($data['raw'])) {
					$httpRequest = base64_decode($data['raw']);
				}
				if (empty($httpRequest)) {
					return array('error' => 'No request payload');
				}

				// Dispatch the request in-process through the server's router.
				// This handler runs inside the server's own event loop, so an
				// HTTP call back to our own port can never be accepted: it hung
				// until the timeout and returned 502.
				$raw = self::dispatchLocal($httpRequest, $from);

				// Encrypt the response and return
				$responsePayload = array('from' => self::$peerId);
				if (!empty($data['encrypted']) && self::hasSession($from)) {
					$enc = self::encrypt($from, $raw);
					if ($enc) {
						$responsePayload['encrypted'] = true;
						$responsePayload['payload'] = $enc;
						return $responsePayload;
					}
				}
				$responsePayload['encrypted'] = false;
				$responsePayload['raw'] = base64_encode($raw);
				return $responsePayload;

			default:
				// Forward handler — relay or deliver encrypted messages
				if ($action === 'forward') {
					$routerFile = __DIR__ . '/MeshRouter.php';
					if (is_file($routerFile)) {
						require_once $routerFile;
						$result = Q_WebServer_MeshRouter::handleForward(
							$data['from'] ?? '', $data, self::$peerId
						);
						if ($result['action'] === 'deliver') {
							// Decrypt and process locally
							$plaintext = self::decrypt($result['from'], $result['payload']);
							if ($plaintext === null) {
								return array('error' => 'Forward delivery: decryption failed');
							}
							// Treat as a local request — re-inject into handleSyncApi
							return array('delivered' => true, 'decrypted' => true);
						}
						return $result;
					}
					return array('error' => 'Router not available');
				}

				// Prolly tree endpoint
				if ($action === 'tree') {
					$treeFile = __DIR__ . '/ProllyTree.php';
					if (is_file($treeFile)) {
						require_once $treeFile;
						return Q_WebServer_ProllyTree::handleApi($data);
					}
					return array('error' => 'ProllyTree not available');
				}

				// Delegate sync-specific actions (bloom, records, merge, tables)
				$syncFile = __DIR__ . '/MeshSync.php';
				if (is_file($syncFile)) {
					require_once $syncFile;
					if (in_array($action, array('bloom', 'records', 'merge', 'tables'))) {
						return Q_WebServer_MeshSync::handleSyncApi($action, $data);
					}
				}
				return array('error' => 'Unknown sync action: ' . $action);
		}
	}

	// ── Internal helpers ────────────────────────────────

	private static function generateKeypair($keyPath, $certPath)
	{
		$config = array(
			'curve_name' => 'prime256v1',
			'private_key_type' => OPENSSL_KEYTYPE_EC
		);
		$key = openssl_pkey_new($config);
		if (!$key) return;

		openssl_pkey_export($key, $privPem);
		$details = openssl_pkey_get_details($key);
		$pubPem = $details['key'];

		// Derive peer_id from public key DER
		$pubDer = self::pemToDer($pubPem, 'PUBLIC KEY');
		$peerId = hash('sha256', $pubDer);

		// Generate self-signed cert with peer_id as CN
		$dn = array('commonName' => $peerId);
		$csr = openssl_csr_new($dn, $key, array('digest_alg' => 'sha256'));
		$cert = openssl_csr_sign($csr, null, $key, 3650, array('digest_alg' => 'sha256'));
		openssl_x509_export($cert, $certPemStr);

		// Save
		file_put_contents($keyPath, $privPem, 0);
		chmod($keyPath, 0600);
		file_put_contents($certPath, $certPemStr, 0);

		self::$privateKey = $key;
		self::$publicKeyPem = $pubPem;
		self::$certPem = $certPemStr;
		self::$peerId = $peerId;
	}

	private static function loadKeypair($keyPath, $certPath)
	{
		$privPem = file_get_contents($keyPath);
		$certPemStr = file_get_contents($certPath);

		$key = openssl_pkey_get_private($privPem);
		$details = openssl_pkey_get_details($key);

		self::$privateKey = $key;
		self::$publicKeyPem = $details['key'];
		self::$certPem = $certPemStr;

		$pubDer = self::pemToDer($details['key'], 'PUBLIC KEY');
		self::$peerId = hash('sha256', $pubDer);
	}

	private static function generateEphemeralKey()
	{
		$key = openssl_pkey_new(array(
			'curve_name' => 'prime256v1',
			'private_key_type' => OPENSSL_KEYTYPE_EC
		));
		if (!$key) return null;

		openssl_pkey_export($key, $privPem);
		$details = openssl_pkey_get_details($key);

		return array(
			'private_pem' => $privPem,
			'public_pem' => $details['key']
		);
	}

	/**
	 * ECDH key agreement.
	 * openssl_pkey_derive is broken for EC on PHP 8.3 — use CLI fallback.
	 */
	private static function ecdhDerive($myPrivatePem, $theirPublicPem)
	{
		// Try native first (works on PHP 8.4+)
		$myKey = openssl_pkey_get_private($myPrivatePem);
		if ($myKey) {
			$shared = @openssl_pkey_derive($myKey, $theirPublicPem, 32);
			if ($shared && strlen($shared) === 32) {
				return $shared;
			}
		}

		// CLI fallback for PHP 8.3 and earlier
		$tmpDir = sys_get_temp_dir();
		$privFile = tempnam($tmpDir, 'ecdh_priv_');
		$pubFile = tempnam($tmpDir, 'ecdh_pub_');
		$outFile = tempnam($tmpDir, 'ecdh_out_');

		file_put_contents($privFile, $myPrivatePem);
		file_put_contents($pubFile, $theirPublicPem);

		$cmd = sprintf(
			'openssl pkeyutl -derive -inkey %s -peerkey %s -out %s 2>/dev/null',
			escapeshellarg($privFile),
			escapeshellarg($pubFile),
			escapeshellarg($outFile)
		);
		exec($cmd, $output, $exitCode);

		$shared = null;
		if ($exitCode === 0 && file_exists($outFile)) {
			$shared = file_get_contents($outFile);
			if (strlen($shared) !== 32) $shared = null;
		}

		@unlink($privFile);
		@unlink($pubFile);
		@unlink($outFile);

		return $shared;
	}

	private static function sign($data)
	{
		$signature = '';
		$ok = openssl_sign($data, $signature, self::$privateKey, OPENSSL_ALGO_SHA256);
		return $ok ? base64_encode($signature) : null;
	}

	private static function verifySignature($data, $signatureB64, $certPem)
	{
		$sig = base64_decode($signatureB64);
		$pubKey = openssl_pkey_get_public($certPem);
		if (!$pubKey) {
			// Try extracting from cert
			$cert = openssl_x509_read($certPem);
			if ($cert) $pubKey = openssl_pkey_get_public($cert);
		}
		if (!$pubKey) return false;
		return openssl_verify($data, $sig, $pubKey, OPENSSL_ALGO_SHA256) === 1;
	}

	/**
	 * HKDF-SHA256 key derivation.
	 */
	private static function hkdf($ikm, $salt, $info, $length)
	{
		if (function_exists('hash_hkdf')) {
			return hash_hkdf('sha256', $ikm, $length, $info, $salt);
		}
		// Manual HKDF for older PHP
		$prk = hash_hmac('sha256', $ikm, $salt, true);
		$t = '';
		$okm = '';
		for ($i = 1; strlen($okm) < $length; $i++) {
			$t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
			$okm .= $t;
		}
		return substr($okm, 0, $length);
	}

	private static function publicKeyDer($pem)
	{
		// If it's a certificate, extract the public key first
		$cert = @openssl_x509_read($pem);
		if ($cert) {
			$pubKey = openssl_pkey_get_public($cert);
			$details = openssl_pkey_get_details($pubKey);
			$pem = $details['key'];
		}
		return self::pemToDer($pem, 'PUBLIC KEY');
	}

	private static function pemToDer($pem, $type)
	{
		$header = "-----BEGIN $type-----";
		$footer = "-----END $type-----";
		$body = str_replace(array($header, $footer, "\r", "\n"), '', $pem);
		$der = base64_decode($body);
		return $der ?: null;
	}
}
