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

	// ── The log of a request, for the people who run the server ─────────────

	/** @var string|null the log file this process is writing to */
	protected static $logFile = null;

	private static function logPath($certDir, $name)
	{
		return rtrim($certDir, '/') . '/.logs/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '.log';
	}

	/**
	 * Start a new attempt in the log of a name. Earlier attempts stay (the last few hundred lines),
	 * so a retry does not hide why the one before it failed.
	 * @method beginLog
	 * @static
	 */
	static function beginLog($certDir, $name, $how)
	{
		$f = self::logPath($certDir, $name);
		@mkdir(dirname($f), 0700, true);
		if (is_file($f) && filesize($f) > 65536) {
			$lines = file($f);
			@file_put_contents($f, implode('', array_slice($lines, -400)));
		}
		self::$logFile = $f;
		self::note('--- ' . $how . ' ---');
	}

	/** Add a line to the log this process is writing; nothing happens if there is none. */
	static function note($msg)
	{
		if (!self::$logFile) return;
		@file_put_contents(self::$logFile, gmdate('H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
	}

	/** Add a line to the log of a name, from a process that did not start the request. */
	static function logTo($certDir, $name, $msg)
	{
		$f = self::logPath($certDir, $name);
		@mkdir(dirname($f), 0700, true);
		@file_put_contents($f, gmdate('H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
	}

	/** @return string the log of a name ("" if there is none) */
	static function readLog($certDir, $name)
	{
		$f = self::logPath($certDir, $name);
		if (!is_file($f)) return '';
		$lines = file($f);
		return implode('', array_slice($lines, -300));
	}

	/**
	 * Ask for the challenge answer the way Let's Encrypt will: over plain HTTP, by the public name.
	 * Tells what stands in the way when something does. Advisory only: a server that cannot reach
	 * itself by its own public name (NAT) fails this and can still pass the real check.
	 * @return array {ok: boolean, why: string, server: string, code: int}
	 */
	private static function selfCheck($domain, $token, $keyAuth)
	{
		$url = 'http://' . $domain . '/.well-known/acme-challenge/' . $token;
		$ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 8, 'follow_location' => 0,
			'header' => "User-Agent: Qbix-Server-selfcheck\r\n"]]);
		$body = @file_get_contents($url, false, $ctx);
		$h = self::parseResponseHeaders($http_response_header ?? []);
		if ($body === false && empty($h['status'])) {
			return ['ok' => false, 'code' => 0, 'server' => '',
				'why' => "this machine could not connect to $url (name does not resolve to it, port 80 closed, or the machine cannot reach itself by its public name)"];
		}
		$code = $h['status'] ?? 0;
		$server = $h['server'] ?? '';
		if (trim((string) $body) === $keyAuth) return ['ok' => true, 'code' => $code, 'server' => $server, 'why' => ''];
		$why = "$url answered HTTP $code" . ($server ? " from \"$server\"" : '') . ' without the challenge answer';
		if ($code >= 300 && $code < 400 && !empty($h['location'])) $why .= ' (it redirects to ' . $h['location'] . ')';
		return ['ok' => false, 'code' => $code, 'server' => $server, 'why' => $why];
	}

	/**
	 * What to tell whoever runs the server when port 80 for a name is answered by something other
	 * than this server (nginx in front, usually).
	 * @method proxyAdvice
	 * @static
	 * @param {string} $domain
	 * @param {string} $server The Server header the check saw
	 * @return {string}
	 */
	static function proxyAdvice($domain, $server = '')
	{
		$port = class_exists('Q_WebServer', false) && Q_WebServer::$port ? Q_WebServer::$port : 8080;
		$who = $server !== '' ? $server : 'a web server in front of this one';
		return "Port 80 for $domain is answered by $who, which did not pass the challenge on to this server. "
			. "Either (1) in the nginx server block that answers $domain, or in a catch-all one for its parent domain, add\n"
			. "    location ^~ /.well-known/acme-challenge/ { proxy_pass http://127.0.0.1:$port; proxy_set_header Host \$host; }\n"
			. "and reload nginx; or (2) set Q.webserver.tls.method to \"certbot\", so that certbot and nginx do it, which needs this server to run as root "
			. "or to have passwordless sudo for certbot, nginx, cp, rm, cat and test.";
	}

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
		self::beginLog($certDir, $primary, 'requesting a certificate for ' . $primary . ' with ' . ($method === 'certbot' ? 'certbot and nginx' : 'the built-in ACME client (Let\'s Encrypt, HTTP-01 on port 80)'));
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

		// Track any nginx conf.d files we wrote so we can clean up
		$wroteNginxConfs = [];

		try {
			// 0. HTTPS pre-probe: if the domain already has a working cert (e.g. CDN), skip provisioning
			self::note('probing https://' . $primaryDomain . ' to see if a certificate is already in place');
			$tls = self::detectTls($primaryDomain);
			if ($tls['hasCert'] && !$tls['noCert']) {
				if ($tls['cdnCert']) {
					$cdnNote = $tls['cdnHeaders'] ? ' (CDN headers: ' . implode(', ', $tls['cdnHeaders']) . ')' : '';
					self::note('https://' . $primaryDomain . ' already has a working certificate' . $cdnNote . '; no provisioning needed');
					return ['success' => false, 'permanent' => true,
						'error' => "https://$primaryDomain already has a working TLS certificate (served by a CDN or upstream proxy$cdnNote). No new certificate is needed.",
						'hint' => 'If you need a certificate on this server too, disable or bypass the CDN for this domain first.'];
				}
				// Has a cert but no CDN — could be already provisioned or another server; proceed anyway
				self::note('https://' . $primaryDomain . ' already responds with a cert (not CDN), but will try to renew anyway');
			} else {
				if (!empty($tls['noRoute'])) {
					self::note('domain not reachable over HTTPS (' . ($tls['error'] ?: 'no route') . '); provisioning anyway — Let\'s Encrypt will validate via HTTP-01');
				} else {
					self::note($tls['error'] ?: 'no HTTPS cert found; will provision one');
				}
			}

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

			self::note('account ready; asking for a certificate for ' . implode(', ', $domains));
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
				$auth = self::postAsGet($authUrl, $accountKey, $accountUrl);
				$domain = $auth['identifier']['value'] ?? '';
				if ($domain === '' || empty($auth['challenges'])) {
					$d = $auth['detail'] ?? 'no details';
					self::note("could not read the authorization: $d");
					return ['success' => false, 'error' => "Could not read the authorization from the ACME server: $d"];
				}
				if (($auth['status'] ?? '') === 'valid') {
					self::note("$domain is already authorized");
					continue;
				}

				// Find the http-01 challenge
				$challenge = null;
				foreach ($auth['challenges'] as $ch) {
					if ($ch['type'] === 'http-01') {
						$challenge = $ch;
						break;
					}
				}
				if (!$challenge) {
					foreach ($wroteNginxConfs as $d => $path) self::removeNginxChallengeConf($d);
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
				self::note("challenge for $domain is ready to be answered at /.well-known/acme-challenge/" . $challenge['token']);

				// Can the challenge be fetched the way the CA will? (Say so in the log now, whatever follows.)
				$check = self::selfCheck($domain, $challenge['token'], $keyAuth);
				self::note($check['ok'] ? 'own check: the challenge is reachable at http://' . $domain . '/.well-known/acme-challenge/...'
					: 'own check: ' . $check['why']);

				// If the self-check failed because something else answered port 80, try adding a
				// nginx conf.d file that proxy_passes the challenge to this server, then re-check.
				if (!$check['ok'] && $check['code'] !== 0 && !isset($wroteNginxConfs[$domain])) {
					self::note("port 80 for $domain is answered by something else; trying to add an nginx conf.d proxy rule");
					$nginx = self::tryWriteNginxChallengeConf($domain);
					if ($nginx['ok']) {
						$wroteNginxConfs[$domain] = $nginx['wrote'];
						self::note('wrote ' . $nginx['wrote'] . ' and reloaded nginx; re-checking challenge');
						self::pump(2); // give nginx a moment to reload
						$check2 = self::selfCheck($domain, $challenge['token'], $keyAuth);
						self::note($check2['ok'] ? 'own check (after nginx conf.d): challenge is now reachable'
							: 'own check (after nginx conf.d): still not reachable — ' . $check2['why']);
						if ($check2['ok']) $check = $check2;
					} else {
						self::note('could not write nginx conf.d: ' . $nginx['why']);
					}
				}

				// Tell ACME we're ready: an empty JSON object, which starts the check
				$ready = self::signedRequest($challenge['url'], new \stdClass, $accountKey, $accountUrl);
				self::failIfError($ready['body'] ?? null, 'Telling the ACME server the challenge is ready');
				self::note('asked Let\'s Encrypt to check ' . $domain);

				// Poll until valid or invalid
				$maxWait = 90;
				$start = time();
				$status = [];
				while (time() - $start < $maxWait) {
					self::pump(2);
					$status = self::postAsGet($challenge['url'], $accountKey, $accountUrl);
					if (($status['status'] ?? '') === 'valid') break;
					if (($status['status'] ?? '') === 'invalid') break;
				}
				unset(self::$pendingChallenges[$challenge['token']]);
				if (self::$inChild && self::$challengeDir) @unlink(self::$challengeDir . '/' . $challenge['token']);
				$st = $status['status'] ?? 'unknown';
				self::note("check of $domain: $st");
				if ($st !== 'valid') {
					$err = $status['error']['detail'] ?? ($st === 'invalid' ? 'Challenge failed' : "Let's Encrypt had not finished checking after {$maxWait}s (status: $st)");
					self::note('Let\'s Encrypt says: ' . $err);
					$hint = '';
					if (!$check['ok'] && ($check['code'] !== 0)) {
						$hint = self::proxyAdvice($domain, $check['server']);
					} elseif (!$check['ok']) {
						$hint = ucfirst($check['why']) . '.';
					}
					if ($hint !== '') self::note('what to do: ' . $hint);
					foreach ($wroteNginxConfs as $d => $path) self::removeNginxChallengeConf($d);
					return ['success' => false, 'error' => "Challenge failed for {$domain}: {$err}", 'hint' => $hint];
				}
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
			self::note('order finalized; waiting for the certificate');

			// 9. Poll order until certificate is ready
			$orderUrl = $order['headers']['location'] ?? $order['body']['url'] ?? $finalize['body']['url'] ?? '';
			if ($orderUrl === '') throw new Exception('The ACME server did not say where to find the order');
			$maxWait = 60;
			$start = time();
			$certUrl = null;
			while (time() - $start < $maxWait) {
				self::pump(2);
				$check = self::postAsGet($orderUrl, $accountKey, $accountUrl);
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
			self::note('certificate saved to ' . $domainDir . '/fullchain.pem');

			// Clean up any temporary nginx conf.d files we wrote
			foreach ($wroteNginxConfs as $d => $path) {
				self::note("removing temporary nginx conf.d file $path");
				self::removeNginxChallengeConf($d);
			}

			return [
				'success' => true,
				'cert' => $domainDir . '/fullchain.pem',
				'key' => $domainKeyPath,
				'domains' => $domains,
				'expires' => self::certExpiry($domainDir . '/fullchain.pem'),
			];

		} catch (Exception $e) {
			// Clean up any temporary nginx conf.d files we wrote
			foreach ($wroteNginxConfs as $d => $path) {
				self::removeNginxChallengeConf($d);
			}
			self::note('failed: ' . $e->getMessage());
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

	// ── Nginx / CDN helpers ──────────────────────────────────────────

	/**
	 * Check if a domain already has a working TLS certificate, and whether a CDN is in front.
	 * Uses curl (exec) when available, falls back to a stream context that lets us inspect the error.
	 *
	 * @param  string $fqdn
	 * @return array {hasCert: bool, cdnCert: bool, cdnHeaders: string[], noCert: bool, noRoute: bool, error: string}
	 *   noRoute: true  — DNS not set yet or domain not reachable; defer and retry later.
	 *   noCert:  true  — Domain is reachable over HTTP but has no valid HTTPS cert yet; provision now.
	 *   hasCert: true  — HTTPS works; check cdnCert to see if a CDN holds the certificate.
	 *
	 * Strategy: probe HTTP first (port 80) to confirm DNS is live without needing a cert.
	 * Only if HTTP is reachable do we probe HTTPS to detect an existing cert or a CDN.
	 * This avoids the chicken-and-egg where HTTPS is down precisely because no cert exists yet.
	 */
	static function detectTls($fqdn)
	{
		$result = ['hasCert' => false, 'cdnCert' => false, 'cdnHeaders' => [], 'noCert' => false, 'noRoute' => false, 'error' => ''];
		$cdnMarkers = ['CF-Ray', 'X-Served-By', 'X-Cache', 'X-CDN', 'X-Fastly', 'Via:.*varnish', 'Server:.*cloudflare', 'Server:.*akamai'];

		if (function_exists('exec')) {
			// ── Step 1: HTTP probe — confirms DNS and routing without needing a cert ──────────────
			$httpOut = []; $httpCode = 0;
			@exec('curl -sI --max-time 8 --connect-timeout 6 --max-redirs 3 '
				. escapeshellarg('http://' . $fqdn . '/') . ' 2>&1', $httpOut, $httpCode);
			$httpRaw = implode("\n", $httpOut);

			if (!preg_match('/HTTP\/[12]/', $httpRaw)) {
				// No HTTP response — DNS not set, no route, or firewall.  Defer.
				$result['noRoute'] = true;
				$result['noCert']  = true;
				$result['error']   = 'Domain not reachable over HTTP: ' . trim(substr($httpRaw, 0, 200));
				return $result;
			}

			// ── Step 2: HTTPS probe — detect existing cert or CDN ────────────────────────────────
			$httpsOut = []; $httpsCode = 0;
			@exec('curl -sI --max-time 8 --connect-timeout 6 '
				. escapeshellarg('https://' . $fqdn . '/') . ' 2>&1', $httpsOut, $httpsCode);
			$httpsRaw = implode("\n", $httpsOut);

			if (preg_match('/HTTP\/[12]/', $httpsRaw)) {
				// HTTPS works — cert is already in place.
				$result['hasCert'] = true;
				foreach ($cdnMarkers as $marker) {
					if (preg_match('/' . $marker . '/i', $httpsRaw)) {
						$result['cdnCert']      = true;
						$result['cdnHeaders'][] = $marker;
					}
				}
				return $result;
			}

			// HTTP works but HTTPS does not — domain is live, no cert yet.  Provision.
			$result['noCert'] = true;
			if (preg_match('/SSL|certificate|CERT|unable to get|no alternative/i', $httpsRaw)) {
				$result['error'] = 'TLS error (no valid cert): ' . trim(preg_replace('/\s+/', ' ',
					substr(implode(' ', array_slice($httpsOut, 0, 5)), 0, 200)));
			} else {
				$result['error'] = 'HTTPS not reachable (port 443 closed or no cert): ' . trim(substr($httpsRaw, 0, 200));
			}
			return $result;
		}

		// ── Fallback: PHP stream context ─────────────────────────────────────────────────────────
		// Step 1: HTTP reachability check
		$httpCtx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 8, 'ignore_errors' => true,
			'follow_location' => true, 'max_redirects' => 3]]);
		$httpResp = @file_get_contents('http://' . $fqdn . '/', false, $httpCtx);
		$httpHdrs = self::parseResponseHeaders($http_response_header ?? []);
		if ($httpResp === false && empty($httpHdrs['status'])) {
			$result['noRoute'] = true;
			$result['noCert']  = true;
			$result['error']   = 'Domain not reachable over HTTP';
			return $result;
		}

		// Step 2: HTTPS probe
		$httpsCtx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => true],
			'http' => ['method' => 'HEAD', 'timeout' => 8, 'ignore_errors' => true]]);
		$httpsResp = @file_get_contents('https://' . $fqdn . '/', false, $httpsCtx);
		$lastHdrs  = $http_response_header ?? [];
		$httpsHdrs = self::parseResponseHeaders($lastHdrs);
		if ($httpsResp !== false || !empty($httpsHdrs['status'])) {
			$result['hasCert'] = true;
			$raw = implode("\n", $lastHdrs);
			foreach ($cdnMarkers as $marker) {
				if (preg_match('/' . $marker . '/i', $raw)) {
					$result['cdnCert']      = true;
					$result['cdnHeaders'][] = $marker;
				}
			}
		} else {
			// HTTP worked but HTTPS didn't — domain is live, no cert yet.
			$result['noCert'] = true;
			$result['error']  = 'HTTPS not reachable (no cert or port 443 closed)';
		}
		return $result;
	}

	/**
	 * Write an additive nginx conf.d file that proxy_passes /.well-known/acme-challenge/ for $fqdn
	 * to this server so the HTTP-01 challenge can be answered. Backs up any existing file, validates
	 * with `nginx -t`, reloads, and rolls back on failure.
	 *
	 * @param  string $fqdn
	 * @return array {ok: bool, why: string, wrote: string|null}
	 */
	static function tryWriteNginxChallengeConf($fqdn)
	{
		if (!function_exists('exec')) {
			return ['ok' => false, 'why' => 'exec() is not available; cannot write nginx config', 'wrote' => null];
		}
		$port = class_exists('Q_WebServer', false) && Q_WebServer::$port ? (int) Q_WebServer::$port : 8080;
		$confDir = '/etc/nginx/conf.d';
		if (!is_dir($confDir)) {
			return ['ok' => false, 'why' => 'nginx conf.d directory not found at ' . $confDir, 'wrote' => null];
		}
		$safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $fqdn);
		$confPath = $confDir . '/qbix-acme-' . $safeName . '.conf';
		$backup = null;

		if (is_file($confPath)) {
			$backup = $confPath . '.bak-' . time();
			if (!@copy($confPath, $backup)) {
				return ['ok' => false, 'why' => "Could not back up $confPath", 'wrote' => null];
			}
		}

		$conf = "# Written by Qbix Server for ACME HTTP-01 challenge — safe to remove after cert is issued\n"
			. "server {\n"
			. "    listen 80;\n"
			. "    server_name $fqdn;\n"
			. "    location ^~ /.well-known/acme-challenge/ {\n"
			. "        proxy_pass http://127.0.0.1:$port;\n"
			. "        proxy_set_header Host \$host;\n"
			. "    }\n"
			. "    location / {\n"
			. "        proxy_pass http://127.0.0.1:$port;\n"
			. "        proxy_set_header Host \$host;\n"
			. "        proxy_set_header X-Real-IP \$remote_addr;\n"
			. "    }\n"
			. "}\n";

		if (!@file_put_contents($confPath, $conf)) {
			return ['ok' => false, 'why' => "Could not write $confPath (permission denied?)", 'wrote' => null];
		}

		// Test nginx config
		$testOut = []; $testCode = 0;
		@exec('nginx -t 2>&1', $testOut, $testCode);
		if ($testCode !== 0) {
			// Roll back
			if ($backup) @rename($backup, $confPath);
			else @unlink($confPath);
			return ['ok' => false, 'why' => 'nginx -t failed after writing ' . $confPath . ': ' . implode(' ', $testOut), 'wrote' => null];
		}
		if ($backup) @unlink($backup);

		// Reload nginx
		$reloadOut = []; $reloadCode = 0;
		@exec('nginx -s reload 2>&1', $reloadOut, $reloadCode);
		if ($reloadCode !== 0) {
			@unlink($confPath);
			return ['ok' => false, 'why' => 'nginx -s reload failed: ' . implode(' ', $reloadOut), 'wrote' => null];
		}

		return ['ok' => true, 'why' => '', 'wrote' => $confPath];
	}

	/**
	 * Remove the temporary nginx conf.d challenge-forwarding file for $fqdn and reload nginx.
	 * @param  string $fqdn
	 */
	static function removeNginxChallengeConf($fqdn)
	{
		if (!function_exists('exec')) return;
		$safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $fqdn);
		$confPath = '/etc/nginx/conf.d/qbix-acme-' . $safeName . '.conf';
		if (is_file($confPath)) {
			@unlink($confPath);
			@exec('nginx -s reload 2>&1');
		}
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
		// null is a POST-as-GET (empty payload). An empty object is the "{}" that tells the CA a
		// challenge is ready; sending "" there only fetched the challenge and never started the check.
		$payloadB64 = $payload === null
			? ''
			: self::base64url(is_object($payload) ? '{}' : json_encode($payload));

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

	/** Fetch an ACME resource the way the protocol asks since unauthenticated GETs were retired. */
	private static function postAsGet($url, $key, $accountUrl)
	{
		$r = self::signedRequest($url, null, $key, $accountUrl);
		return is_array($r['body']) ? $r['body'] : [];
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
