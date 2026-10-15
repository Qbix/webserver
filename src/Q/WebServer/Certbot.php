<?php
/**
 * Q_WebServer_Certbot — certificates for a domain that nginx (or Apache) answers on ports 80 and 443.
 *
 * When a web server in front owns those ports, Let's Encrypt's check reaches it, not this server, so
 * the built-in ACME client cannot pass. This class lets certbot do the asking and teaches nginx about
 * the result, in this order, in a child process (see Q_WebServer_Acme::provisionAsync):
 *
 *   1. certbot certonly --webroot (the root the nginx server block serves the challenge from), or,
 *      if that fails, certbot certonly --nginx (certbot puts the challenge into nginx and takes it out);
 *   2. a small server block for that one name in nginx's conf.d, which points nginx at the new
 *      certificate and at this server, written under a managed file name;
 *   3. nginx -t. If it does not pass, the file is removed and nothing has changed;
 *   4. a reload, and a look at what nginx now serves for that name. If it is not the new
 *      certificate, the file is removed and nginx reloaded again.
 *
 * It needs to run as root, or with sudo that does not ask for a password, because it reads nginx's
 * configuration, writes into it and reloads it. available() says whether that is so, and why not.
 *
 * Config under Q.webserver.tls.certbot (all optional):
 *   bin           certbot executable (default "certbot")
 *   nginx         nginx executable (default "nginx")
 *   confDir       where to write the server block (default: found from nginx -T, else /etc/nginx/conf.d)
 *   liveDir       where certbot keeps certificates (default /etc/letsencrypt/live)
 *   reload        command that reloads nginx (default "<nginx> -s reload")
 *   skipVerify    true to not check what nginx serves after the reload
 *
 * @class Q_WebServer_Certbot
 * @static
 */
class Q_WebServer_Certbot
{
	/** @var array|null result of the last available() */
	private static $probe = null;

	/**
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	private static function opt($key, $default)
	{
		$v = Q_Config::get('Q', 'webserver', 'tls', 'certbot', $key, null);
		return ($v === null || $v === '') ? $default : $v;
	}

	/** Run a command; returns [exit code, combined output]. */
	private static function sh($cmd, $timeout = 120)
	{
		$p = @proc_open($cmd . ' 2>&1', array(1 => array('pipe', 'w')), $pipes);
		if (!is_resource($p)) return array(127, 'could not run: ' . $cmd);
		stream_set_blocking($pipes[1], false);
		$out = '';
		$end = time() + $timeout;
		while (true) {
			$chunk = stream_get_contents($pipes[1]);
			if ($chunk !== false && $chunk !== '') $out .= $chunk;
			$st = proc_get_status($p);
			if (!$st['running']) break;
			if (time() > $end) {
				proc_terminate($p, 9);
				$out .= "\n(timed out after {$timeout}s)";
				break;
			}
			usleep(100000);
		}
		$out .= (string) stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$code = proc_close($p);
		if (isset($st) && !$st['running'] && $st['exitcode'] >= 0) $code = $st['exitcode'];
		return array($code, trim($out));
	}

	private static function which($bin)
	{
		if (strpos($bin, '/') !== false) return is_executable($bin) ? $bin : null;
		list($c, $o) = self::sh('command -v ' . escapeshellarg($bin), 5);
		return ($c === 0 && $o !== '') ? $o : null;
	}

	/** "" when running as root, "sudo -n " when sudo works without a password, null otherwise. */
	private static function privilege()
	{
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) return '';
		if (self::which('sudo')) {
			list($c) = self::sh('sudo -n true', 5);
			if ($c === 0) return 'sudo -n ';
		}
		return null;
	}

	/**
	 * Can this server have certbot and nginx do the work? Looks at, without changing anything:
	 * certbot and nginx exist; we are root (or have passwordless sudo); nginx's configuration
	 * can be read and passes its own test; there is a conf.d directory we can write to.
	 *
	 * @method available
	 * @static
	 * @return {array} ok (boolean), why (string, when not ok), and the facts found
	 */
	static function available()
	{
		if (self::$probe !== null && self::$probe['at'] > time() - 60) return self::$probe;
		$r = array('ok' => false, 'why' => '', 'at' => time());
		$certbot = self::which((string) self::opt('bin', 'certbot'));
		$nginx = self::which((string) self::opt('nginx', 'nginx'));
		if (!$certbot) {
			$r['why'] = 'certbot is not installed (apt install certbot, or snap install --classic certbot)';
		} elseif (!$nginx) {
			$r['why'] = 'nginx was not found on this machine';
		} else {
			$sudo = self::privilege();
			if ($sudo === null) {
				$r['why'] = 'this server is not running as root and sudo asks for a password, so it cannot change nginx';
			} else {
				list($c, $conf) = self::sh($sudo . escapeshellarg($nginx) . ' -T');
				if ($c !== 0) {
					$r['why'] = 'nginx -T does not pass: ' . self::tail($conf, 3);
				} else {
					$dir = self::opt('confDir', null);
					if (!$dir) {
						$dir = '/etc/nginx/conf.d';
						if (preg_match_all('~^\s*include\s+(/[^\s;*]+)/\*\.conf\s*;~m', $conf, $m)) $dir = $m[1][0];
					}
					if (!is_dir($dir)) {
						$r['why'] = "nginx's conf.d directory $dir does not exist; set Q.webserver.tls.certbot.confDir";
					} elseif ($sudo === '' && !is_writable($dir)) {
						$r['why'] = "$dir is not writable";
					} else {
						$r = array('ok' => true, 'why' => '', 'at' => time(), 'certbot' => $certbot,
							'nginx' => $nginx, 'sudo' => $sudo, 'confDir' => $dir);
					}
				}
			}
		}
		return self::$probe = $r;
	}

	/**
	 * What to tell whoever runs the server, for a failure we can recognise.
	 * @method adminHint
	 * @static
	 * @param {string} $why The error text
	 * @return {string}
	 */
	static function adminHint($why)
	{
		$w = strtolower((string) $why);
		$user = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'the server user') : 'the server user';
		if (strpos($w, 'not installed') !== false) {
			return 'Install certbot on the machine: apt install certbot (or snap install --classic certbot).';
		}
		if (strpos($w, 'not running as root') !== false || strpos($w, 'permission denied') !== false || strpos($w, 'sudo') !== false || strpos($w, 'not writable') !== false) {
			return "Certbot and nginx need root. Run the server as root, or let $user use sudo without a password for them, "
				. "e.g. in /etc/sudoers.d/qbix: $user ALL=(root) NOPASSWD: " . '/usr/bin/certbot, /usr/sbin/nginx, /usr/bin/cp, /usr/bin/rm, /usr/bin/cat, /usr/bin/test'
				. ". (Use the paths \"which certbot nginx cp rm cat test\" shows.) Then request the certificate again.";
		}
		if (strpos($w, 'nginx -t') !== false || strpos($w, 'nginx -t does not pass') !== false) {
			return 'nginx rejects its own configuration. Run nginx -t on the machine and fix what it reports; the certificate will be requested again after that.';
		}
		if (strpos($w, 'challenge') !== false || strpos($w, 'unauthorized') !== false || strpos($w, '404') !== false) {
			return 'Let\'s Encrypt could not fetch the challenge from port 80. Check that the name points at this machine and that nginx serves /.well-known/acme-challenge/ for it (see the log).';
		}
		return '';
	}

	private static function tail($text, $lines)
	{
		$a = array_values(array_filter(array_map('trim', explode("\n", (string) $text))));
		return implode(' / ', array_slice($a, -$lines));
	}

	/**
	 * Whether this domain should be certified through certbot: nginx or Apache answers for it.
	 * @method wanted
	 * @static
	 * @param {string} $appHost The parent domain (a branch's subdomain belongs to it)
	 * @return {boolean}
	 */
	static function wanted($appHost)
	{
		$method = Q_Config::get('Q', 'webserver', 'tls', 'method', 'auto');
		if ($method === 'acme') return false;
		if ($method === 'certbot') return true;
		$conf = Q_Config::get('Q', 'webserver', 'domains', $appHost, array());
		// Seen behind a reverse proxy (X-Forwarded-* headers from nginx): the proxy holds ports 80 and 443,
		// so the built-in client cannot pass the check and its certificate would not be the one served.
		return !empty($conf['proxied']) || ($conf['cdn'] ?? '') === 'a reverse proxy';
	}

	/**
	 * Get a certificate for one name through certbot and have nginx serve it. Meant for a child
	 * process; it waits for certbot and nginx.
	 *
	 * @method provision
	 * @static
	 * @param {string} $fqdn  The name (a branch subdomain, say)
	 * @param {string} $email
	 * @param {array} $options  root (web root to answer the challenge from), upstream (host:port of this server)
	 * @return {array} Like Q_WebServer_Acme::provision(): success, error, cert, key, domains
	 */
	static function provision($fqdn, $email, array $options = array())
	{
		$why = Q_WebServer_Acme::publicNameError($fqdn);
		if ($why) return array('success' => false, 'permanent' => true, 'error' => $why);
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return array('success' => false, 'error' => 'A valid contact email is needed for Let\'s Encrypt');
		}
		$a = self::available();
		if (!$a['ok']) {
			Q_WebServer_Acme::note('certbot cannot be used here: ' . $a['why']);
			return array('success' => false, 'error' => 'Cannot use certbot: ' . $a['why'], 'hint' => self::adminHint($a['why']));
		}
		Q_WebServer_Acme::note('certbot: ' . $a['certbot'] . ', nginx: ' . $a['nginx'] . ', privilege: ' . ($a['sudo'] === '' ? 'running as root' : 'sudo without a password')
			. ', nginx conf.d: ' . $a['confDir']);

		$live = rtrim((string) self::opt('liveDir', '/etc/letsencrypt/live'), '/') . '/' . $fqdn;
		$cert = $live . '/fullchain.pem';
		$key = $live . '/privkey.pem';
		$sudo = $a['sudo'];
		$q = 'escapeshellarg';

		// 1. The certificate
		if (!self::sudoIsFile($sudo, $cert) || Q_WebServer_Acme::needsRenewal($cert)) {
			$base = $sudo . $q($a['certbot']) . ' certonly --non-interactive --agree-tos --keep-until-expiring'
				. ' -m ' . $q($email) . ' --cert-name ' . $q($fqdn) . ' -d ' . $q($fqdn)
				. ' --deploy-hook ' . $q(self::reloadCommand($a));
			$errors = array();
			$ok = false;
			$root = $options['root'] ?? null;
			if ($root && is_dir($root)) {
				Q_WebServer_Acme::note('running certbot with --webroot -w ' . $root);
				list($c, $o) = self::sh($base . ' --webroot -w ' . $q($root), 180);
				Q_WebServer_Acme::note("certbot --webroot exited $c:\n" . self::tail($o, 12));
				if ($c === 0) $ok = true; else $errors[] = 'webroot: ' . self::tail($o, 4);
			}
			if (!$ok) {
				Q_WebServer_Acme::note('running certbot with --nginx');
				list($c, $o) = self::sh($base . ' --nginx', 180);
				Q_WebServer_Acme::note("certbot --nginx exited $c:\n" . self::tail($o, 12));
				if ($c === 0) $ok = true; else $errors[] = 'nginx: ' . self::tail($o, 4);
			}
			if (!$ok) {
				$all = implode(' ', $errors);
				return array('success' => false, 'error' => 'certbot failed. ' . implode(' | ', $errors), 'hint' => self::adminHint($all));
			}
		} else {
			Q_WebServer_Acme::note('a certificate for ' . $fqdn . ' already exists in ' . $live . ' and is not due for renewal');
		}

		// 2. nginx
		Q_WebServer_Acme::note('writing the nginx server block for ' . $fqdn . ' in ' . $a['confDir']);
		$err = self::installServerBlock($a, $fqdn, $live, $options);
		if ($err !== null) {
			Q_WebServer_Acme::note('nginx: ' . $err);
			return array('success' => false, 'error' => $err, 'hint' => self::adminHint($err));
		}
		Q_WebServer_Acme::note('nginx serves ' . $fqdn . ' with the new certificate');

		return array('success' => true, 'cert' => $cert, 'key' => $key, 'domains' => array($fqdn),
			'via' => 'certbot', 'expires' => Q_WebServer_Acme::certExpiry($cert));
	}

	private static function sudoIsFile($sudo, $path)
	{
		if ($sudo === '') return is_file($path);
		list($c) = self::sh($sudo . 'test -f ' . escapeshellarg($path), 5);
		return $c === 0;
	}

	private static function reloadCommand($a)
	{
		$custom = self::opt('reload', null);
		return $custom ? (string) $custom : escapeshellarg($a['nginx']) . ' -s reload';
	}

	/** The text of the server block for one name. */
	static function serverBlock($fqdn, $live, $upstream)
	{
		return "# Managed by Qbix Server for $fqdn. Delete this file and reload nginx to remove it.\n"
			. "server {\n"
			. "    listen 443 ssl;\n"
			. "    server_name $fqdn;\n\n"
			. "    ssl_certificate $live/fullchain.pem;\n"
			. "    ssl_certificate_key $live/privkey.pem;\n\n"
			. "    location / {\n"
			. "        proxy_pass http://$upstream;\n"
			. "        proxy_http_version 1.1;\n\n"
			. "        proxy_set_header Host \$host;\n"
			. "        proxy_set_header X-Real-IP \$remote_addr;\n"
			. "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n"
			. "        proxy_set_header X-Forwarded-Proto https;\n\n"
			. "        proxy_set_header Upgrade \$http_upgrade;\n"
			. "        proxy_set_header Connection \"upgrade\";\n\n"
			. "        proxy_read_timeout 3600;\n"
			. "        proxy_send_timeout 3600;\n"
			. "        proxy_redirect off;\n"
			. "    }\n"
			. "}\n";
	}

	/** @return string|null an error, or null when nginx now serves the name with the new certificate */
	private static function installServerBlock($a, $fqdn, $live, array $options)
	{
		$sudo = $a['sudo'];
		$file = rtrim($a['confDir'], '/') . '/qbix-' . $fqdn . '.conf';
		$upstream = $options['upstream'] ?? ('127.0.0.1:' . (Q_WebServer::$port ?: 8080));
		$text = self::serverBlock($fqdn, $live, $upstream);
		$nginx = escapeshellarg($a['nginx']);
		$had = self::sudoIsFile($sudo, $file);
		$before = $had ? self::sudoRead($sudo, $file) : null;

		if (!self::sudoWrite($sudo, $file, $text)) return "could not write $file";

		$revert = function () use ($sudo, $file, $had, $before, $nginx, $a) {
			if ($had && $before !== null) self::sudoWrite($sudo, $file, $before);
			else self::sh($sudo . 'rm -f ' . escapeshellarg($file), 10);
			self::sh($sudo . self::reloadCommand($a), 30);
		};

		// nginx must accept the file before anything is reloaded
		list($c, $o) = self::sh($sudo . $nginx . ' -t');
		if ($c !== 0) {
			if ($had && $before !== null) self::sudoWrite($sudo, $file, $before);
			else self::sh($sudo . 'rm -f ' . escapeshellarg($file), 10);
			return 'nginx -t rejected the new server block, so it was removed: ' . self::tail($o, 4);
		}
		list($c, $o) = self::sh($sudo . self::reloadCommand($a), 30);
		if ($c !== 0) {
			$revert();
			return 'nginx would not reload, so the server block was removed: ' . self::tail($o, 3);
		}
		if (!self::opt('skipVerify', false)) {
			$seen = self::servedCertificate($fqdn);
			if ($seen === null) {
				$revert();
				return "nginx did not answer on 443 for $fqdn after the reload, so the server block was removed";
			}
			if (!in_array($fqdn, $seen, true)) {
				$revert();
				return "nginx still serves a certificate for " . implode(', ', $seen) . " (not $fqdn) after the reload, so the server block was removed";
			}
		}
		return null;
	}

	private static function sudoRead($sudo, $path)
	{
		if ($sudo === '') return @file_get_contents($path);
		list($c, $o) = self::sh($sudo . 'cat ' . escapeshellarg($path), 10);
		return $c === 0 ? $o . "\n" : null;
	}

	private static function sudoWrite($sudo, $path, $text)
	{
		if ($sudo === '') return @file_put_contents($path, $text) !== false;
		$tmp = tempnam(sys_get_temp_dir(), 'qbixng_');
		file_put_contents($tmp, $text);
		list($c) = self::sh($sudo . 'cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($path), 10);
		@unlink($tmp);
		return $c === 0;
	}

	/**
	 * What nginx presents for this name on this machine's port 443.
	 * @return string[]|null the names on the certificate, or null if nothing answered
	 */
	private static function servedCertificate($fqdn)
	{
		$ctx = stream_context_create(array('ssl' => array(
			'peer_name' => $fqdn, 'SNI_enabled' => true, 'SNI_server_name' => $fqdn,
			'verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => true,
		)));
		$s = @stream_socket_client('ssl://127.0.0.1:443', $en, $es, 10, STREAM_CLIENT_CONNECT, $ctx);
		if (!$s) return null;
		$params = stream_context_get_params($s);
		fclose($s);
		$cert = $params['options']['ssl']['peer_certificate'] ?? null;
		if (!$cert) return null;
		$x = openssl_x509_parse($cert);
		$names = array();
		if (!empty($x['subject']['CN'])) $names[] = $x['subject']['CN'];
		if (!empty($x['extensions']['subjectAltName'])) {
			foreach (explode(',', $x['extensions']['subjectAltName']) as $san) {
				$san = trim($san);
				if (strpos($san, 'DNS:') === 0) $names[] = substr($san, 4);
			}
		}
		return array_values(array_unique($names));
	}
}
