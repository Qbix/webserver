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
	private static $busy = false;
	private static $challengeDir = null;
	private static $inChild = false;
	private static $children = [];
	private static $queue = [];
	private static $reaper = null;
	private static $maxChildren = 2;
	private static $loopWasRunning = false;

	// Pending HTTP-01 challenges: token => thumbprint authorization
	protected static $pendingChallenges = [];

	/**
	 * Check if a token is pending for an HTTP-01 challenge.
	 * Called by the router on /.well-known/acme-challenge/* requests.
	 */
	static function getChallengeResponse($token)
	{
		if (isset(self::$pendingChallenges[$token])) return self::$pendingChallenges[$token];
		// A request running in a child process leaves its answers in files, because the
		// process that serves the challenge is not the one that asked for it.
		if (self::$challengeDir && preg_match('/^[A-Za-z0-9_-]{16,128}$/', (string) $token)) {
			$f = self::$challengeDir . '/' . $token;
			if (is_file($f)) {
				$v = @file_get_contents($f);
				if ($v !== false && $v !== '') return $v;
			}
		}
		return null;
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
		// One request at a time. Waiting for Let's Encrypt keeps the server's event loop turning
		// (see pump()), and a timer that fires meanwhile must not start a second request.
		if (self::$busy) {
			return ['success' => false, 'error' => 'Another certificate request is in progress'];
		}
		foreach ($domains as $d) {
			$why = self::publicNameError($d);
			if ($why) return ['success' => false, 'permanent' => true, 'error' => $why];
		}
		self::$busy = true;
		self::noteLoop();
		try {
			return self::doProvision($domains, $certDir, $email, $staging);
		} finally {
			self::$busy = false;
		}
	}

	/**
	 * Request a certificate in a child process, so the server keeps serving at full speed while
	 * Let's Encrypt is asked and answered, and Ctrl-C stays instant. $onDone($result) is called in
	 * the server process when the child has finished, with the same array provision() returns.
	 * Where fork is not available it asks in this process and calls $onDone straight away.
	 * @param string[] $domains
	 * @param string $certDir
	 * @param string $email
	 * @param boolean $staging
	 * @param callable|null $onDone
	 * @return array {started: boolean, error?: string}
	 */
	static function provisionAsync(array $domains, $certDir, $email, $staging = false, $onDone = null, $method = 'acme', array $options = array())
	{
		$primary = $domains[0];
		// 'certbot': nginx or Apache answers on 80 and 443, so certbot asks and the web server is told
		// (see Q_WebServer_Certbot). Say at once if that cannot work here.
		if ($method === 'certbot') {
			$cb = Q_WebServer_Certbot::available();
			if (!$cb['ok']) {
				$r = self::recordResult($certDir, $primary, ['success' => false, 'error' => 'Cannot use certbot: ' . $cb['why'], 'via' => 'certbot']);
				if ($onDone) $onDone($r);
				return ['started' => false, 'error' => $r['error']];
			}
		}
		foreach ($domains as $d) {
			$why = self::publicNameError($d);
			if ($why) {
				$r = self::recordResult($certDir, $primary, ['success' => false, 'permanent' => true, 'error' => $why]);
				if ($onDone) $onDone($r);
				return ['started' => false, 'error' => $why];
			}
		}
		if (self::isPending($primary)) return ['started' => false, 'error' => "A certificate for $primary is already being requested"];
		if (count(self::$children) >= self::$maxChildren) {
			// Startup and renewal pass 'queue' => true: the request waits its turn instead of failing
			if (!empty($options['queue']) && class_exists('Q_Evented')) {
				foreach (self::$queue as $q) {
					if ($q[0][0] === $primary) return ['started' => false, 'queued' => true];
				}
				self::$queue[] = [$domains, $certDir, $email, $staging, $onDone, $method, $options];
				if (self::$reaper === null) {
					self::$reaper = Q_Evented::repeat(1.0, function () { Q_WebServer_Acme::reap(); });
				}
				return ['started' => false, 'queued' => true];
			}
			return ['started' => false, 'error' => 'Too many certificate requests at once; try again shortly'];
		}

		$certDir = rtrim($certDir, '/');
		if (!class_exists('Q_WebServer_Fork', false)) {
			$f = __DIR__ . '/Fork.php';
			if (is_file($f)) require_once $f;
		}
		if (!class_exists('Q_WebServer_Fork', false) || !Q_WebServer_Fork::available()) {
			try {
				$r = ($method === 'certbot') ? Q_WebServer_Certbot::provision($primary, $email, $options)
					: self::provision($domains, $certDir, $email, $staging);
			} catch (Throwable $e) {
				$r = ['success' => false, 'error' => $e->getMessage()];
			}
			$r = self::recordResult($certDir, $primary, $r);
			if ($onDone) { try { $onDone($r); } catch (Throwable $e) { error_log('[ACME] ' . $e->getMessage()); } }
			return ['started' => true];
		}

		self::$challengeDir = $certDir . '/.challenges';
		@mkdir(self::$challengeDir, 0700, true);
		@mkdir($certDir . '/.results', 0700, true);
		$resultFile = $certDir . '/.results/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $primary) . '.json';
		@unlink($resultFile);
		// Answers left behind by a child that was killed
		foreach ((array) @scandir(self::$challengeDir) as $old) {
			$p = self::$challengeDir . '/' . $old;
			if ($old[0] !== '.' && is_file($p) && filemtime($p) < time() - 3600) @unlink($p);
		}

		$pid = Q_WebServer_Fork::fork();
		if ($pid === 0) {
			// ── child: a plain process with the server's code and nothing of its loop ──
			self::$inChild = true;
			self::$busy = false;
			self::$loopWasRunning = false;
			if (function_exists('pcntl_signal')) {
				foreach ([SIGINT, SIGTERM, SIGHUP] as $sig) @pcntl_signal($sig, SIG_DFL);
			}
			try {
				$r = ($method === 'certbot')
					? Q_WebServer_Certbot::provision($primary, $email, $options)
					: self::provision($domains, $certDir, $email, $staging);
			} catch (Throwable $e) {
				$r = ['success' => false, 'error' => $e->getMessage()];
			}
			$r['at'] = gmdate('Y-m-d\TH:i:s\Z');
			@file_put_contents($resultFile, json_encode($r));
			// Leave without running the server's shutdown handlers: they belong to the parent.
			if (function_exists('posix_kill') && function_exists('posix_getpid')) {
				@posix_kill(posix_getpid(), SIGKILL);
			}
			exit(0);
		}
		if (!$pid || $pid < 0) {
			try {
				$r = ($method === 'certbot') ? Q_WebServer_Certbot::provision($primary, $email, $options)
					: self::provision($domains, $certDir, $email, $staging);
			} catch (Throwable $e) {
				$r = ['success' => false, 'error' => $e->getMessage()];
			}
			$r = self::recordResult($certDir, $primary, $r);
			if ($onDone) { try { $onDone($r); } catch (Throwable $e) { error_log('[ACME] ' . $e->getMessage()); } }
			return ['started' => true];
		}
		self::$children[$pid] = [
			'domains' => $domains, 'primary' => $primary, 'started' => time(),
			'resultFile' => $resultFile, 'onDone' => $onDone,
		];
		if (self::$reaper === null && class_exists('Q_Evented')) {
			self::$reaper = Q_Evented::repeat(1.0, function () { Q_WebServer_Acme::reap(); });
		}
		return ['started' => true];
	}

	/** @return boolean whether a child is asking for a certificate for this name */
	static function isPending($primary)
	{
		foreach (self::$children as $c) {
			if ($c['primary'] === $primary) return true;
		}
		return false;
	}

	/** @return integer how many child requests are running */
	static function activeCount()
	{
		return count(self::$children);
	}

	/**
	 * Collect finished children and hand their results to the callbacks. Called every second
	 * while any child is running.
	 */
	static function reap()
	{
		foreach (self::$children as $pid => $c) {
			$done = function_exists('pcntl_waitpid') ? pcntl_waitpid($pid, $status, WNOHANG) : $pid;
			if ($done === 0) {
				if (time() - $c['started'] > 300) {
					@posix_kill($pid, SIGKILL);
				}
				continue;
			}
			unset(self::$children[$pid]);
			$r = is_file($c['resultFile']) ? json_decode((string) file_get_contents($c['resultFile']), true) : null;
			if (!is_array($r)) {
				$r = ['success' => false, 'error' => 'The certificate request stopped before it finished (the server may have been restarted, or the worker was killed after 5 minutes)', 'at' => gmdate('Y-m-d\TH:i:s\Z')];
				@file_put_contents($c['resultFile'], json_encode($r));
			}
			if (!empty($c['onDone'])) {
				try { ($c['onDone'])($r); } catch (Throwable $e) { error_log('[ACME] ' . $e->getMessage()); }
			}
		}
		while (self::$queue && count(self::$children) < self::$maxChildren) {
			$q = array_shift(self::$queue);
			self::provisionAsync($q[0], $q[1], $q[2], $q[3], $q[4], $q[5], $q[6]);
		}
		if (!self::$children && !self::$queue && self::$reaper !== null) {
			Q_Evented::cancel(self::$reaper);
			self::$reaper = null;
		}
	}

	/**
	 * Where a request for this name stands: pending, or the result the last one left.
	 * @return array {pending: boolean} or the provision() result
	 */
	static function status($certDir, $primary)
	{
		if (self::isPending($primary)) return ['pending' => true];
		$f = rtrim($certDir, '/') . '/.results/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $primary) . '.json';
		$r = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
		return is_array($r) ? $r : ['pending' => false, 'unknown' => true];
	}

	/**
	 * Keep how a request ended where the panel can find it later, so a failure is still there when the
	 * person comes back. The file is replaced by the next request for the same name.
	 */
	static function recordResult($certDir, $primary, array $r)
	{
		$dir = rtrim($certDir, '/') . '/.results';
		@mkdir($dir, 0700, true);
		if (!isset($r['at'])) $r['at'] = gmdate('Y-m-d\TH:i:s\Z');
		@file_put_contents($dir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $primary) . '.json', json_encode($r));
		return $r;
	}

	/** Forget the last result (a new request is about to be made). */
	static function clearResult($certDir, $primary)
	{
		@unlink(rtrim($certDir, '/') . '/.results/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $primary) . '.json');
	}

	/**
	 * @return array|null {error, at, ...} if the last request for this name failed and none is running, else null
	 */
	static function lastFailure($certDir, $primary)
	{
		if (self::isPending($primary)) return null;
		$f = rtrim($certDir, '/') . '/.results/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $primary) . '.json';
		$r = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
		if (!is_array($r) || !empty($r['success'])) return null;
		if (empty($r['error'])) $r['error'] = 'The request failed without saying why';
		return $r;
	}

	/** @return boolean whether a certificate request is running right now */
	static function busy()
	{
		return self::$busy;
	}

	/**
	 * Let's Encrypt can only issue for names that resolve on the public internet. Say so before
	 * contacting it, instead of failing half way through with a confusing error.
	 * @return string|null the reason, or null if the name could be public
	 */
	static function publicNameError($name)
	{
		$n = strtolower(trim((string) $name));
		if ($n === '' || strpos($n, '*') !== false) {
			return "'$name' is not a single host name; a wildcard needs a DNS-01 challenge, which this server does not do";
		}
		if (filter_var($n, FILTER_VALIDATE_IP)) {
			return "$name is an IP address; Let's Encrypt issues for domain names";
		}
		$labels = explode('.', $n);
		$tld = end($labels);
		if (count($labels) < 2
			|| in_array($tld, ['test', 'local', 'localhost', 'invalid', 'example', 'internal', 'lan', 'home', 'corp', 'intranet', 'localdomain'], true)
			|| !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $n)
		) {
			return "$name is not a public domain name (." . $tld . " names do not exist on the internet), so Let's Encrypt cannot issue a certificate for it";
		}
		return null;
	}

	/**
	 * Remember whether this process is the server with its loop running, for pump(). In a request's own
	 * worker process the loop it inherited is not ours to run or to wait on, so there it is not.
	 */
	private static function noteLoop()
	{
		self::$loopWasRunning = !self::$inChild && class_exists('Q_Evented', false) && Q_Evented::running();
	}

	/**
	 * Wait, but keep serving. The challenge answer is served by this same process, so a plain
	 * sleep() would make every HTTP-01 check fail, and would make Ctrl-C and SIGTERM wait until the
	 * request is over. Runs the event loop in short steps instead, and gives up if the server is told
	 * to stop.
	 * @throws Exception when the server is shutting down
	 */
	private static function pump($seconds)
	{
		$end = microtime(true) + $seconds;
		do {
			if (self::$inChild) {
				usleep(250000);
			} elseif (class_exists('Q_Evented', false) && Q_Evented::running()) {
				Q_Evented::tick(0.25);
			} else {
				if (self::$loopWasRunning) throw new Exception('Server is shutting down');
				usleep(250000);
				if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
			}
		} while (microtime(true) < $end);
	}

	/** Turn an ACME error document into an exception with the CA's own words. */
	private static function failIfError($body, $what)
	{
		if (is_array($body) && isset($body['type']) && strpos((string) $body['type'], 'urn:ietf:params:acme:error') === 0) {
			throw new Exception($what . ': ' . ($body['detail'] ?? $body['type']));
		}
	}

	private static function doProvision(array $domains, $certDir, $email, $staging = false)
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
			if (empty(self::$directory['newNonce']) || empty(self::$directory['newOrder'])) {
				throw new Exception("Could not read the ACME directory at $directoryUrl; is this machine online?");
			}

			// 3. Get initial nonce
			self::$nonce = self::httpHead(self::$directory['newNonce']);

			// 4. Create or find account
			$account = self::createAccount($accountKey, $email);
			self::failIfError($account['body'] ?? null, 'ACME account');
			$accountUrl = $account['url'];
			if (!$accountUrl) throw new Exception('The ACME server did not create an account');

			// 5. Create order
			$identifiers = array_map(function ($d) {
				return ['type' => 'dns', 'value' => $d];
			}, $domains);
			$order = self::signedRequest(
				self::$directory['newOrder'],
				['identifiers' => $identifiers],
				$accountKey, $accountUrl
			);

			self::failIfError($order['body'] ?? null, 'Certificate order for ' . implode(', ', $domains));
			if (empty($order['body']['authorizations']) || empty($order['body']['finalize'])) {
				throw new Exception('The ACME server gave no authorizations for ' . implode(', ', $domains));
			}

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
				if (self::$inChild && self::$challengeDir) {
					file_put_contents(self::$challengeDir . '/' . $challenge['token'], $keyAuth);
				}

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
					self::pump(2);
					$status = self::httpGet($challenge['url']);
					if ($status['status'] === 'valid') break;
					if ($status['status'] === 'invalid') {
						unset(self::$pendingChallenges[$challenge['token']]);
						if (self::$inChild && self::$challengeDir) @unlink(self::$challengeDir . '/' . $challenge['token']);
						$err = $status['error']['detail'] ?? 'Challenge failed';
						return ['success' => false, 'error' => "Challenge failed for {$domain}: {$err}"];
					}
				}
				unset(self::$pendingChallenges[$challenge['token']]);
				if (self::$inChild && self::$challengeDir) @unlink(self::$challengeDir . '/' . $challenge['token']);
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
			self::failIfError($finalize['body'] ?? null, 'Finalizing the order');

			// 9. Poll order until certificate is ready
			$orderUrl = $order['headers']['location'] ?? $order['body']['url'] ?? $finalize['body']['url'] ?? '';
			if ($orderUrl === '') throw new Exception('The ACME server did not say where to find the order');
			$maxWait = 60;
			$start = time();
			$certUrl = null;
			while (time() - $start < $maxWait) {
				self::pump(2);
				$check = self::httpGet($orderUrl);
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

		$resp = @file_get_contents($url, false, $ctx);
		$headers = self::parseResponseHeaders($http_response_header ?? []);

		if (isset($headers['replay-nonce'])) {
			self::$nonce = $headers['replay-nonce'];
		}

		return ['body' => json_decode($resp, true) ?: [], 'headers' => $headers, 'raw' => $resp];
	}

	private static function httpGet($url)
	{
		$resp = @file_get_contents($url, false, stream_context_create([
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
		return @file_get_contents($url, false, stream_context_create([
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
