<?php

/**
 * Server identity, claims and payload verification for the Qbix Server.
 *
 * These used to live on Q_Utils — a class name the PLATFORM also defines. In
 * --app mode the Platform's Q_Utils wins, and it has none of these methods, so
 * every call died with "Call to undefined method". The webserver must only use
 * members of a shared class that the Platform also declares; anything else
 * belongs on a webserver-only class, which is what this is.
 *
 * @class Q_WebServer_Identity
 */
class Q_WebServer_Identity
{

	/**
	 * Verify a signed data array
	 * @method verify
	 * @static
	 * @param {array} $data Data with signature
	 * @param {string} $secret
	 * @return {boolean}
	 */
	static function verify($data, $secret = null)
	{
		$sf = Q_Config::get('Q', 'internal', 'sigField', 'sig');
		$fieldKey = "Q.$sf";
		$receivedSig = $data[$fieldKey] ?? ($data['Q'][$sf] ?? null);
		if (!$receivedSig) return false;

		// Remove sig, recompute
		unset($data[$fieldKey]);
		if (isset($data['Q'][$sf])) unset($data['Q'][$sf]);

		$expected = Q_Utils::signature($data, $secret);
		return hash_equals($expected, $receivedSig);
	}

	/**
	 * Get or generate this server's identity (cert + fingerprint).
	 * Stored in local/server.crt and local/server.key.
	 * @method serverIdentity
	 * @static
	 * @return {array} With 'fingerprint', 'cert', 'key' paths
	 */
	static function serverIdentity()
	{
		$base = defined('APP_DIR') ? APP_DIR : dirname(Q_WebServer::$rootDir);
		$localDir = $base . DS . 'local';
		$certFile = $localDir . DS . 'server.crt';
		$keyFile = $localDir . DS . 'server.key';
		$fpFile = $localDir . DS . 'server.fingerprint';

		if (file_exists($certFile) && file_exists($keyFile) && file_exists($fpFile)) {
			return array(
				'fingerprint' => trim(file_get_contents($fpFile)),
				'cert' => $certFile,
				'key' => $keyFile,
			);
		}

		// Generate
		$result = Q_Utils::generateSelfSignedCert();
		if (!$result) return null;

		if (!is_dir($localDir)) @mkdir($localDir, 0700, true);
		file_put_contents($certFile, $result['cert']);
		file_put_contents($keyFile, $result['key']);
		chmod($keyFile, 0600);
		file_put_contents($fpFile, $result['fingerprint']);

		return array(
			'fingerprint' => $result['fingerprint'],
			'cert' => $certFile,
			'key' => $keyFile,
		);
	}

	/**
	 * Sign any claim template with this server's P-256 key.
	 * Adds key[] and sig[] fields. If they already exist, appends (multisig).
	 * @method signClaim
	 * @static
	 */
	static function signClaim($claim)
	{
		$keyPair = Q_Utils::claimingKeyPair();
		if (!$keyPair) return $claim;

		if (empty($claim['ocp'])) $claim['ocp'] = 1;

		$signerKey = 'data:key/es256;base64,' . $keyPair['publicKeyBase64'];

		$keys = isset($claim['key']) ? (is_array($claim['key']) ? $claim['key'] : array($claim['key'])) : array();
		$sigs = isset($claim['sig']) ? (is_array($claim['sig']) ? $claim['sig'] : array($claim['sig'])) : array();

		if (!in_array($signerKey, $keys, true)) {
			$keys[] = $signerKey;
			$sigs[] = null;
		}

		// Sort keys lexicographically (OCP convention)
		$pairs = array();
		foreach ($keys as $i => $k) {
			$pairs[] = array('key' => $k, 'sig' => $sigs[$i] ?? null);
		}
		usort($pairs, function ($a, $b) { return strcmp($a['key'], $b['key']); });
		$keys = array_column($pairs, 'key');
		$sigs = array_column($pairs, 'sig');

		$claim['key'] = $keys;
		$claim['sig'] = $sigs;

		// Canonicalize — must strip sig[] before hashing (OCP convention)
		$forSigning = $claim;
		unset($forSigning['sig']);
		$canonical = Q_Utils::jcsCanonicalize($forSigning);

		$privKey = openssl_pkey_get_private($keyPair['privateKeyPem']);
		$derSig = '';
		openssl_sign($canonical, $derSig, $privKey, OPENSSL_ALGO_SHA256);

		$idx = array_search($signerKey, $keys, true);
		$sigs[$idx] = base64_encode(Q_Utils::derToRawP256($derSig));
		$claim['sig'] = $sigs;

		return $claim;
	}

	/**
	 * Generate a signed OpenClaim for this server's identity.
	 * Published at /.well-known/openclaiming/{hostname}/server.json
	 * @method serverClaim
	 * @static
	 * @param {string} $hostname
	 * @return {array|null} The signed claim as an array
	 */
	static function serverClaim($hostname)
	{
		$identity = self::serverIdentity();
		$keyPair = Q_Utils::claimingKeyPair();
		if (!$identity || !$keyPair) return null;

		$claim = array(
			'ocp' => 1,
			'iss' => $hostname . '/server',
			'stm' => array(
				'type' => 'server',
				'software' => 'Qbix Server',
				'version' => defined('QBIX_SERVER_VERSION') ? QBIX_SERVER_VERSION : '1.0.0',
				'fingerprint' => $identity['fingerprint'],
				'endpoints' => array(
					'event' => '/Q/event',
					'health' => '/Q/health',
					'openapi' => '/.well-known/openapi.json',
					'mcp' => '/.well-known/mcp.json',
				),
			),
			'iat' => time(),
			'key' => array('data:key/es256;base64,' . $keyPair['publicKeyBase64']),
		);

		// Canonicalize: RFC 8785 / JCS — sorted keys, no sig field
		$canonical = Q_Utils::jcsCanonicalize($claim);

		// Sign with ES256 (P-256 + SHA-256)
		$privKey = openssl_pkey_get_private($keyPair['privateKeyPem']);
		$derSig = '';
		openssl_sign($canonical, $derSig, $privKey, OPENSSL_ALGO_SHA256);

		// Convert DER signature to raw r||s (64 bytes) for OCP wire format
		$rawSig = Q_Utils::derToRawP256($derSig);

		$claim['sig'] = array(base64_encode($rawSig));

		return $claim;
	}
}
