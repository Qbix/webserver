<?php
/**
 * Utility methods compatible with the Qbix Platform's Q_Utils class.
 * When users upgrade to the full Platform, this class is replaced seamlessly.
 * @class Q_Utils
 */
class Q_Utils
{
	/**
	 * Generate a random string
	 * @method randomString
	 * @static
	 * @param {integer} $len
	 * @param {string} $characters
	 * @return {string}
	 */
	static function randomString($len = 8, $characters = 'abcdefghijklmnopqrstuvwxyz')
	{
		$cLen = strlen($characters);
		$result = '';
		for ($i = 0; $i < $len; ++$i) {
			$result .= $characters[random_int(0, $cLen - 1)];
		}
		return $result;
	}

	/**
	 * Returns a random hex string
	 * @method randomHexString
	 * @static
	 */
	static function randomHexString($length)
	{
		return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
	}

	/**
	 * Recursive key-sort an array (matches Platform's Q_Utils::ksort)
	 * @method ksort
	 * @static
	 */
	static function ksort(&$array, $flags = SORT_REGULAR)
	{
		foreach ($array as &$value) {
			if (is_array($value)) self::ksort($value, $flags);
		}
		return ksort($array, $flags);
	}

	/**
	 * Serialize array to a canonical string for signing.
	 * Matches Platform's Q_Utils::serialize — recursive ksort + http_build_query.
	 * @method serialize
	 * @static
	 */
	static function serialize(array $data, $separator = '&')
	{
		self::ksort($data);
		return str_replace('+', '%20',
			http_build_query($data, '', $separator, PHP_QUERY_RFC3986));
	}

	/**
	 * Generate HMAC signature. Uses sha1 to match Platform.
	 * @method signature
	 * @static
	 * @param {array|string} $data
	 * @param {string} $secret
	 * @return {string}
	 */
	static function signature($data, $secret = null)
	{
		if (!isset($secret)) {
			$secret = Q_Config::get('Q', 'internal', 'secret', null);
		}
		if (!isset($secret)) {
			$secret = self::generateLocalSecret();
		}
		if (is_array($data)) {
			$data = self::serialize($data);
		}
		return hash_hmac('sha1', $data, $secret);
	}

	/**
	 * Sign data by adding a signature field. Matches Platform's Q_Utils::sign.
	 * @method sign
	 * @static
	 * @param {array} $data
	 * @param {array|string} $fieldKeys Path for the signature field
	 * @param {string} $secret
	 * @return {array}
	 */
	static function sign($data, $fieldKeys = null, $secret = null)
	{
		if (!isset($secret)) {
			$secret = Q_Config::get('Q', 'internal', 'secret', null);
		}
		if (!isset($secret)) {
			$secret = self::generateLocalSecret();
		}
		if (!$fieldKeys) {
			$sf = Q_Config::get('Q', 'internal', 'sigField', 'sig');
			$fieldKeys = array("Q.$sf");
		}
		if (is_string($fieldKeys)) $fieldKeys = array($fieldKeys);

		$ref = &$data;
		for ($i = 0, $c = count($fieldKeys); $i < $c - 1; ++$i) {
			if (!array_key_exists($fieldKeys[$i], $ref)) {
				$ref[$fieldKeys[$i]] = array();
			}
			$ref = &$ref[$fieldKeys[$i]];
		}
		$ef = end($fieldKeys);
		unset($ref[$ef]);
		$ref[$ef] = self::signature($data, $secret);
		return $data;
	}

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

		$expected = self::signature($data, $secret);
		return hash_equals($expected, $receivedSig);
	}

	/**
	 * Generate a stable machine-specific secret. Matches Platform.
	 * @method generateLocalSecret
	 * @static
	 */
	static function generateLocalSecret()
	{
		$parts = array(
			gethostname(),
			PHP_OS,
			defined('APP_DIR') ? APP_DIR : __DIR__,
		);
		if (PHP_OS_FAMILY === 'Windows') {
			$guid = @trim(shell_exec(
				'reg query "HKLM\SOFTWARE\Microsoft\Cryptography" /v MachineGuid 2>NUL'
			) ?? '');
			if (preg_match('/MachineGuid\s+REG_SZ\s+([^\r\n]+)/i', $guid, $m)) {
				$parts[] = trim($m[1]);
			}
		} else {
			if (is_readable('/etc/machine-id')) {
				$parts[] = trim(file_get_contents('/etc/machine-id'));
			}
		}
		return hash('sha256', implode("\t", $parts));
	}

	/**
	 * Generate a self-signed certificate for server-to-server TLS.
	 * Returns array with 'cert', 'key', 'fingerprint'.
	 * @method generateSelfSignedCert
	 * @static
	 */
	static function generateSelfSignedCert($commonName = null)
	{
		if (!function_exists('openssl_pkey_new')) return null;

		$cn = $commonName ?? gethostname() ?? 'qbix-server';
		$key = openssl_pkey_new(array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		));
		$csr = openssl_csr_new(array(
			'commonName' => $cn,
			'organizationName' => 'Qbix Server',
		), $key);
		$cert = openssl_csr_sign($csr, null, $key, 3650); // 10 years

		openssl_x509_export($cert, $certPem);
		openssl_pkey_export($key, $keyPem);

		// Fingerprint
		$der = openssl_x509_fingerprint($cert, 'sha256');

		return array(
			'cert' => $certPem,
			'key' => $keyPem,
			'fingerprint' => $der,
		);
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
		$result = self::generateSelfSignedCert();
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
	 * Get or generate a P-256 (ES256) key pair for OpenClaiming.
	 * Separate from the TLS cert — this is for signing claims.
	 * @method claimingKeyPair
	 * @static
	 * @return {array} With 'publicKeyPem', 'publicKeyBase64', 'privateKeyPem'
	 */
	static function claimingKeyPair()
	{
		$base = defined('APP_DIR') ? APP_DIR : dirname(Q_WebServer::$rootDir);
		$localDir = $base . DS . 'local';
		$pubFile = $localDir . DS . 'claim.pub';
		$privFile = $localDir . DS . 'claim.key';

		if (file_exists($pubFile) && file_exists($privFile)) {
			$pubPem = file_get_contents($pubFile);
			$privPem = file_get_contents($privFile);
			// Extract raw public key bytes for data URI
			$pubKey = openssl_pkey_get_public($pubPem);
			$details = openssl_pkey_get_details($pubKey);
			$derPub = $details['key'];
			// Strip PEM headers to get base64
			$b64 = str_replace(array("-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\n", "\r"), '', $derPub);
			return array(
				'publicKeyPem' => $pubPem,
				'publicKeyBase64' => $b64,
				'privateKeyPem' => $privPem,
			);
		}

		if (!function_exists('openssl_pkey_new')) return null;

		$key = openssl_pkey_new(array(
			'curve_name' => 'prime256v1',
			'private_key_type' => OPENSSL_KEYTYPE_EC,
		));
		if (!$key) return null;

		$details = openssl_pkey_get_details($key);
		openssl_pkey_export($key, $privPem);
		$pubPem = $details['key'];

		if (!is_dir($localDir)) @mkdir($localDir, 0700, true);
		file_put_contents($pubFile, $pubPem);
		file_put_contents($privFile, $privPem);
		chmod($privFile, 0600);

		$b64 = str_replace(array("-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\n", "\r"), '', $pubPem);

		return array(
			'publicKeyPem' => $pubPem,
			'publicKeyBase64' => $b64,
			'privateKeyPem' => $privPem,
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
		$keyPair = self::claimingKeyPair();
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
		$canonical = self::jcsCanonicalize($forSigning);

		$privKey = openssl_pkey_get_private($keyPair['privateKeyPem']);
		$derSig = '';
		openssl_sign($canonical, $derSig, $privKey, OPENSSL_ALGO_SHA256);

		$idx = array_search($signerKey, $keys, true);
		$sigs[$idx] = base64_encode(self::derToRawP256($derSig));
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
		$keyPair = self::claimingKeyPair();
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
		$canonical = self::jcsCanonicalize($claim);

		// Sign with ES256 (P-256 + SHA-256)
		$privKey = openssl_pkey_get_private($keyPair['privateKeyPem']);
		$derSig = '';
		openssl_sign($canonical, $derSig, $privKey, OPENSSL_ALGO_SHA256);

		// Convert DER signature to raw r||s (64 bytes) for OCP wire format
		$rawSig = self::derToRawP256($derSig);

		$claim['sig'] = array(base64_encode($rawSig));

		return $claim;
	}

	/**
	 * Convert DER-encoded ECDSA signature to raw r||s (64 bytes).
	 * OCP uses raw r||s, OpenSSL produces DER.
	 * @method derToRawP256
	 * @static
	 */
	static function derToRawP256($der)
	{
		// DER: 30 <len> 02 <rlen> <r> 02 <slen> <s>
		$offset = 2; // skip 30 <len>
		if (ord($der[1]) > 127) $offset++; // long form length

		// R
		$rLen = ord($der[$offset + 1]);
		$r = substr($der, $offset + 2, $rLen);
		$offset += 2 + $rLen;

		// S
		$sLen = ord($der[$offset + 1]);
		$s = substr($der, $offset + 2, $sLen);

		// Strip leading zero padding, then pad to 32 bytes
		$r = ltrim($r, "\x00");
		$s = ltrim($s, "\x00");
		$r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
		$s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

		return $r . $s; // 64 bytes
	}

	/**
	 * Convert raw r||s (64 bytes) back to DER for OpenSSL verification.
	 * @method rawToDerP256
	 * @static
	 */
	static function rawToDerP256($raw)
	{
		$r = substr($raw, 0, 32);
		$s = substr($raw, 32, 32);

		// Add leading zero if high bit set (positive integer in ASN.1)
		if (ord($r[0]) >= 0x80) $r = "\x00" . $r;
		if (ord($s[0]) >= 0x80) $s = "\x00" . $s;

		$rEnc = "\x02" . chr(strlen($r)) . $r;
		$sEnc = "\x02" . chr(strlen($s)) . $s;
		$body = $rEnc . $sEnc;

		return "\x30" . chr(strlen($body)) . $body;
	}

	/**
	 * RFC 8785 / JCS canonicalization — sorted keys, deterministic JSON.
	 * Compatible with Q_Data::canonicalize() in the Platform.
	 * @method jcsCanonicalize
	 * @static
	 */
	static function jcsCanonicalize($data)
	{
		if (is_array($data)) {
			if (self::isAssoc($data)) {
				ksort($data, SORT_STRING);
				$parts = array();
				foreach ($data as $k => $v) {
					$parts[] = json_encode($k) . ':' . self::jcsCanonicalize($v);
				}
				return '{' . implode(',', $parts) . '}';
			} else {
				$parts = array();
				foreach ($data as $v) {
					$parts[] = self::jcsCanonicalize($v);
				}
				return '[' . implode(',', $parts) . ']';
			}
		}
		return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	private static function isAssoc($arr)
	{
		if (!is_array($arr) || $arr === array()) return false;
		return array_keys($arr) !== range(0, count($arr) - 1);
	}
}
