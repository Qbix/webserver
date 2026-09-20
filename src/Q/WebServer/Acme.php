<?php
/**
 * Lightweight ACME v2 client for automated TLS certificate provisioning.
 * Implements the HTTP-01 challenge flow against Let's Encrypt (or any ACME CA).
 * No external dependencies — uses openssl_* and file_get_contents().
 */
class Q_WebServer_Acme
{
	const LE_DIRECTORY = 'https://acme-v02.api.letsencrypt.org/directory';
	const LE_STAGING   = 'https://acme-staging-v02.api.letsencrypt.org/directory';

	protected static $directory = null;
	protected static $nonce = null;

	// Pending HTTP-01 challenges: token => thumbprint authorization
	protected static $pendingChallenges = [];

	/**
	 * Check if a token is pending for an HTTP-01 challenge.
	 * Called by the router on /.well-known/acme-challenge/* requests.
	 */
	static function getChallengeResponse($token)
	{
		return self::$pendingChallenges[$token] ?? null;
	}

	/**
	 * Provision a certificate for one or more domains.
	 * @param string[] $domains
	 * @param string $certDir Directory to store certs (e.g. local/certs)
	 * @param string $email Contact email for the ACME account
	 * @param bool $staging Use Let's Encrypt staging for testing
	 * @return array{success: bool, error?: string, cert?: string, key?: string}
	 */
	static function provision(array $domains, $certDir, $email, $staging = false)
	{
		$primaryDomain = $domains[0];
		$domainDir = rtrim($certDir, '/') . '/' . $primaryDomain;
		@mkdir($domainDir, 0700, true);

		$accountKeyPath = rtrim($certDir, '/') . '/account.pem';
		$domainKeyPath  = $domainDir . '/privkey.pem';

		try {
			// 1. Get or create account key
			$accountKey = self::getOrCreateKey($accountKeyPath);

			// 2. Fetch ACME directory
			$directoryUrl = $staging ? self::LE_STAGING : self::LE_DIRECTORY;
			self::$directory = self::httpGet($directoryUrl);

			// 3. Get initial nonce
			self::$nonce = self::httpHead(self::$directory['newNonce']);

			// 4. Create or find account
			$account = self::createAccount($accountKey, $email);
			$accountUrl = $account['url'];

			// 5. Create order
			$identifiers = array_map(function ($d) {
				return ['type' => 'dns', 'value' => $d];
			}, $domains);
			$order = self::signedRequest(
				self::$directory['newOrder'],
				['identifiers' => $identifiers],
				$accountKey, $accountUrl
			);

			// 6. Process authorizations (HTTP-01 challenges)
			foreach ($order['body']['authorizations'] as $authUrl) {
				$auth = self::httpGet($authUrl);
				$domain = $auth['identifier']['value'];

				// Find the http-01 challenge
				$challenge = null;
				foreach ($auth['challenges'] as $ch) {
					if ($ch['type'] === 'http-01') {
						$challenge = $ch;
						break;
					}
				}
				if (!$challenge) {
					return ['success' => false, 'error' => "No HTTP-01 challenge for {$domain}"];
				}

				// Compute key authorization
				$thumbprint = self::thumbprint($accountKey);
				$keyAuth = $challenge['token'] . '.' . $thumbprint;

				// Register the challenge so our router can serve it
				self::$pendingChallenges[$challenge['token']] = $keyAuth;

				// Tell ACME we're ready
				self::signedRequest(
					$challenge['url'],
					(object) [],
					$accountKey, $accountUrl
				);

				// Poll until valid or invalid
				$maxWait = 60;
				$start = time();
				while (time() - $start < $maxWait) {
					sleep(2);
					$status = self::httpGet($challenge['url']);
					if ($status['status'] === 'valid') break;
					if ($status['status'] === 'invalid') {
						unset(self::$pendingChallenges[$challenge['token']]);
						$err = $status['error']['detail'] ?? 'Challenge failed';
						return ['success' => false, 'error' => "Challenge failed for {$domain}: {$err}"];
					}
				}
				unset(self::$pendingChallenges[$challenge['token']]);
			}

			// 7. Generate domain key + CSR
			$domainKey = self::getOrCreateKey($domainKeyPath);
			$csr = self::generateCSR($domains, $domainKey);

			// 8. Finalize order
			$finalize = self::signedRequest(
				$order['body']['finalize'],
				['csr' => self::base64url($csr)],
				$accountKey, $accountUrl
			);

			// 9. Poll order until certificate is ready
			$orderUrl = $order['headers']['location'] ?? $order['body']['url'] ?? '';
			$maxWait = 60;
			$start = time();
			$certUrl = null;
			while (time() - $start < $maxWait) {
				sleep(2);
				$check = self::httpGet($orderUrl ?: $finalize['body']['url'] ?? '');
				if (isset($check['certificate'])) {
					$certUrl = $check['certificate'];
					break;
				}
				if (($check['status'] ?? '') === 'invalid') {
					return ['success' => false, 'error' => 'Order became invalid'];
				}
			}
			if (!$certUrl) {
				return ['success' => false, 'error' => 'Timed out waiting for certificate'];
			}

			// 10. Download certificate chain
			$certPem = self::httpGetRaw($certUrl);
			file_put_contents($domainDir . '/fullchain.pem', $certPem);
			chmod($domainDir . '/fullchain.pem', 0600);

			return [
				'success' => true,
				'cert' => $domainDir . '/fullchain.pem',
				'key' => $domainKeyPath,
				'domains' => $domains,
				'expires' => self::certExpiry($domainDir . '/fullchain.pem'),
			];

		} catch (Exception $e) {
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Check if a certificate needs renewal (< $days until expiry).
	 */
	static function needsRenewal($certPath, $days = 30)
	{
		if (!is_file($certPath)) return true;
		$expiry = self::certExpiry($certPath);
		if (!$expiry) return true;
		return ($expiry - time()) < ($days * 86400);
	}

	/**
	 * Get the expiry timestamp of a PEM certificate.
	 */
	static function certExpiry($certPath)
	{
		$pem = @file_get_contents($certPath);
		if (!$pem) return null;
		$cert = @openssl_x509_parse($pem);
		return $cert['validTo_time_t'] ?? null;
	}

	/**
	 * Get the domains (CN + SANs) from a PEM certificate.
	 */
	static function certDomains($certPath)
	{
		$pem = @file_get_contents($certPath);
		if (!$pem) return [];
		$cert = @openssl_x509_parse($pem);
		$domains = [];
		if (!empty($cert['subject']['CN'])) $domains[] = $cert['subject']['CN'];
		if (!empty($cert['extensions']['subjectAltName'])) {
			foreach (explode(',', $cert['extensions']['subjectAltName']) as $san) {
				$san = trim($san);
				if (strpos($san, 'DNS:') === 0) {
					$d = substr($san, 4);
					if (!in_array($d, $domains)) $domains[] = $d;
				}
			}
		}
		return $domains;
	}

	// ── Internal ──────────────────────────────────────────────────────

	private static function getOrCreateKey($path)
	{
		if (is_file($path)) {
			return openssl_pkey_get_private('file://' . $path);
		}
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($key, $pem);
		@mkdir(dirname($path), 0700, true);
		file_put_contents($path, $pem);
		chmod($path, 0600);
		return $key;
	}

	private static function thumbprint($key)
	{
		$details = openssl_pkey_get_details($key);
		$jwk = [
			'e' => self::base64url($details['rsa']['e']),
			'kty' => 'RSA',
			'n' => self::base64url($details['rsa']['n']),
		];
		return self::base64url(hash('sha256', json_encode($jwk), true));
	}

	private static function generateCSR($domains, $key)
	{
		$san = implode(',', array_map(function ($d) { return 'DNS:' . $d; }, $domains));
		$tmpConf = tempnam(sys_get_temp_dir(), 'acme_');
		file_put_contents($tmpConf,
			"[req]\n" .
			"distinguished_name=dn\n" .
			"req_extensions=san\n" .
			"[dn]\n" .
			"CN={$domains[0]}\n" .
			"[san]\n" .
			"subjectAltName={$san}\n"
		);
		$csr = openssl_csr_new(
			['CN' => $domains[0]],
			$key,
			['config' => $tmpConf, 'digest_alg' => 'sha256']
		);
		openssl_csr_export($csr, $csrPem);
		@unlink($tmpConf);
		// Convert PEM to DER
		$csrDer = base64_decode(
			str_replace(["\n", "\r", '-----BEGIN CERTIFICATE REQUEST-----', '-----END CERTIFICATE REQUEST-----'], '', $csrPem)
		);
		return $csrDer;
	}

	private static function createAccount($key, $email)
	{
		$payload = [
			'termsOfServiceAgreed' => true,
			'contact' => ['mailto:' . $email],
		];
		$resp = self::signedRequest(self::$directory['newAccount'], $payload, $key, null);
		$resp['url'] = $resp['headers']['location'] ?? '';
		return $resp;
	}

	private static function signedRequest($url, $payload, $key, $accountUrl)
	{
		$details = openssl_pkey_get_details($key);
		$protected = [
			'alg' => 'RS256',
			'nonce' => self::$nonce,
			'url' => $url,
		];
		if ($accountUrl) {
			$protected['kid'] = $accountUrl;
		} else {
			$protected['jwk'] = [
				'kty' => 'RSA',
				'n' => self::base64url($details['rsa']['n']),
				'e' => self::base64url($details['rsa']['e']),
			];
		}

		$protectedB64 = self::base64url(json_encode($protected));
		$payloadB64 = is_object($payload) && empty((array) $payload)
			? ''
			: self::base64url(json_encode($payload));

		openssl_sign("$protectedB64.$payloadB64", $sig, $key, OPENSSL_ALGO_SHA256);

		$body = json_encode([
			'protected' => $protectedB64,
			'payload' => $payloadB64,
			'signature' => self::base64url($sig),
		]);

		$ctx = stream_context_create(['http' => [
			'method' => 'POST',
			'header' => "Content-Type: application/jose+json\r\n",
			'content' => $body,
			'ignore_errors' => true,
		]]);

		$resp = file_get_contents($url, false, $ctx);
		$headers = self::parseResponseHeaders($http_response_header ?? []);

		if (isset($headers['replay-nonce'])) {
			self::$nonce = $headers['replay-nonce'];
		}

		return ['body' => json_decode($resp, true) ?: [], 'headers' => $headers, 'raw' => $resp];
	}

	private static function httpGet($url)
	{
		$resp = file_get_contents($url, false, stream_context_create([
			'http' => ['ignore_errors' => true]
		]));
		if (isset($http_response_header)) {
			$headers = self::parseResponseHeaders($http_response_header);
			if (isset($headers['replay-nonce'])) self::$nonce = $headers['replay-nonce'];
		}
		return json_decode($resp, true) ?: [];
	}

	private static function httpGetRaw($url)
	{
		return file_get_contents($url, false, stream_context_create([
			'http' => ['ignore_errors' => true]
		]));
	}

	private static function httpHead($url)
	{
		$ctx = stream_context_create(['http' => ['method' => 'HEAD', 'ignore_errors' => true]]);
		@file_get_contents($url, false, $ctx);
		$headers = self::parseResponseHeaders($http_response_header ?? []);
		return $headers['replay-nonce'] ?? '';
	}

	private static function parseResponseHeaders($raw)
	{
		$h = [];
		foreach ($raw as $line) {
			if (strpos($line, ':') !== false) {
				list($k, $v) = explode(':', $line, 2);
				$h[strtolower(trim($k))] = trim($v);
			}
			if (strpos($line, 'HTTP/') === 0) {
				$h['status'] = (int) substr($line, 9, 3);
			}
		}
		return $h;
	}

	private static function base64url($data)
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}
}
