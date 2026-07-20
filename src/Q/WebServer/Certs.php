<?php
/**
 * @module Q
 */

/**
 * TLS certificate management for Q_WebServer.
 *
 * Two modes:
 *
 * 1. Local certbot: runs `certbot certonly` to obtain/renew
 *    Let's Encrypt certs. Checks expiration via openssl_x509_parse
 *    and renews automatically — no cron needed, runs on a
 *    Q_Evented timer.
 *
 * 2. Remote download: fetches certs from a URL (.zip containing
 *    fullchain.pem + privkey.pem). For dev domains like
 *    local.qbix.com where certs are published centrally.
 *    Checks actual cert expiration, re-downloads when expired.
 *
 * Config:
 *   "Q": {
 *     "web": {
 *       "https": {
 *         "cert": "/path/to/fullchain.pem",   // or auto-managed path
 *         "key": "/path/to/privkey.pem",
 *         "mode": "certbot",                  // "certbot" | "remote" | "manual"
 *         "domain": "example.com",
 *         "certbot": {
 *           "email": "you@example.com",
 *           "webroot": "/path/to/app/web",    // for webroot validation
 *           "renewDays": 30                   // renew when < 30 days remain
 *         },
 *         "remote": {
 *           "url": "https://certs.qbix.com/local.qbix.com/certs.zip",
 *           "checkInterval": 86400            // check daily (seconds)
 *         }
 *       }
 *     }
 *   }
 *
 * @class Q_WebServer_Certs
 */
class Q_WebServer_Certs
{
	/**
	 * Path to current fullchain.pem
	 * @property $certPath
	 * @static
	 */
	static $certPath = null;

	/**
	 * Path to current privkey.pem
	 * @property $keyPath
	 * @static
	 */
	static $keyPath = null;

	/**
	 * Initialize cert management. Loads existing certs,
	 * checks expiration, starts renewal timer if needed.
	 *
	 * @method init
	 * @static
	 * @param {string} $domain The domain to serve
	 * @return {boolean} true if valid certs are available
	 */
	static function init($domain = null)
	{
		$config = Q_Config::get('Q', 'web', 'https', array());
		$mode = Q::ifset($config, 'mode', 'manual');
		$domain = $domain ?: Q::ifset($config, 'domain', '');

		// Determine cert paths
		$certsDir = self::certsDir();
		self::$certPath = Q::ifset($config, 'cert',
			$certsDir . DS . 'fullchain.pem');
		self::$keyPath = Q::ifset($config, 'key',
			$certsDir . DS . 'privkey.pem');

		// Check if we have valid certs already
		$valid = self::validateCerts();

		if (!$valid) {
			// Try to obtain certs
			if ($mode === 'certbot') {
				$valid = self::obtainCertbot($domain, $config);
			} elseif ($mode === 'remote') {
				$valid = self::downloadRemote($config);
			}
		}

		// Start renewal timer
		if ($mode === 'certbot') {
			$checkInterval = 86400; // daily
			Q_Evented::repeat((float) $checkInterval, function () use ($domain, $config) {
				Q_WebServer_Certs::checkRenewal($domain, $config);
			});
		} elseif ($mode === 'remote') {
			$checkInterval = (float) Q::ifset($config, 'remote', 'checkInterval', 86400);
			Q_Evented::repeat($checkInterval, function () use ($config) {
				Q_WebServer_Certs::checkRemoteRenewal($config);
			});
		}

		return $valid;
	}

	/**
	 * Build an SSL context for stream_socket_server.
	 *
	 * @method sslContext
	 * @static
	 * @return {resource|null} Stream context or null if no certs
	 */
	static function sslContext()
	{
		if (!self::$certPath || !file_exists(self::$certPath)
			|| !self::$keyPath || !file_exists(self::$keyPath)
		) {
			return null;
		}

		return stream_context_create(array(
			'ssl' => array(
				'local_cert'        => self::$certPath,
				'local_pk'          => self::$keyPath,
				'verify_peer'       => false,
				'verify_peer_name'  => false,
				'allow_self_signed' => true,
				'crypto_method'     => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER
					| STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
			)
		));
	}

	/**
	 * Check if current certs exist and are not expired.
	 *
	 * @method validateCerts
	 * @static
	 * @return {boolean}
	 */
	static function validateCerts()
	{
		if (!self::$certPath || !file_exists(self::$certPath)) return false;
		if (!self::$keyPath || !file_exists(self::$keyPath)) return false;

		$expiry = self::certExpiry(self::$certPath);
		if ($expiry === null) return false;

		return $expiry > time();
	}

	/**
	 * Get cert expiration as Unix timestamp.
	 * Uses openssl_x509_parse() — reads from the actual cert,
	 * not mtime.
	 *
	 * @method certExpiry
	 * @static
	 * @param {string} $certPath Path to PEM cert
	 * @return {integer|null} Expiry timestamp or null
	 */
	static function certExpiry($certPath)
	{
		if (!function_exists('openssl_x509_parse')) return null;

		$pem = file_get_contents($certPath);
		if (!$pem) return null;

		$cert = openssl_x509_parse($pem);
		if (!$cert || !isset($cert['validTo_time_t'])) return null;

		return (int) $cert['validTo_time_t'];
	}

	/**
	 * Days remaining until cert expires.
	 *
	 * @method daysRemaining
	 * @static
	 * @return {integer|null}
	 */
	static function daysRemaining()
	{
		$expiry = self::certExpiry(self::$certPath);
		if ($expiry === null) return null;
		return max(0, (int) floor(($expiry - time()) / 86400));
	}

	// ── Certbot mode ─────────────────────────────────────

	/**
	 * Obtain a cert via certbot certonly.
	 *
	 * @method obtainCertbot
	 * @static
	 * @param {string} $domain
	 * @param {array} $config
	 * @return {boolean}
	 */
	static function obtainCertbot($domain, $config)
	{
		if (!$domain) {
			echo "[HTTPS] No domain configured for certbot\n";
			return false;
		}

		$email = Q::ifset($config, 'certbot', 'email', '');
		$webroot = Q::ifset($config, 'certbot', 'webroot', APP_WEB_DIR);
		$certsDir = self::certsDir();

		// Use standalone if port 80 is available, webroot otherwise
		$emailFlag = $email ? "--email $email" : "--register-unsafely-without-email";
		$cmd = "certbot certonly --non-interactive --agree-tos $emailFlag "
			. "--webroot -w " . escapeshellarg($webroot) . " "
			. "-d " . escapeshellarg($domain) . " "
			. "--cert-path " . escapeshellarg($certsDir . DS . 'fullchain.pem') . " "
			. "--key-path " . escapeshellarg($certsDir . DS . 'privkey.pem') . " "
			. "2>&1";

		echo "[HTTPS] Running certbot for $domain...\n";
		$output = shell_exec($cmd);
		$success = (strpos($output, 'Successfully') !== false
			|| strpos($output, 'Certificate not yet due for renewal') !== false);

		if ($success) {
			// Certbot stores in /etc/letsencrypt/live/$domain/
			// Copy or symlink to our certsDir
			$leDir = "/etc/letsencrypt/live/$domain";
			if (is_dir($leDir)) {
				self::$certPath = "$leDir/fullchain.pem";
				self::$keyPath = "$leDir/privkey.pem";
			}
			echo "[HTTPS] Certificate obtained for $domain\n";
			return self::validateCerts();
		}

		echo "[HTTPS] Certbot failed: $output\n";
		return false;
	}

	/**
	 * Check if certbot renewal is needed.
	 * Called on Q_Evented timer.
	 *
	 * @method checkRenewal
	 * @static
	 */
	static function checkRenewal($domain, $config)
	{
		$renewDays = (int) Q::ifset($config, 'certbot', 'renewDays', 30);
		$remaining = self::daysRemaining();

		if ($remaining === null || $remaining <= $renewDays) {
			echo "[HTTPS] Cert expires in " . ($remaining ?? '?')
				. " days, renewing...\n";
			$success = self::obtainCertbot($domain, $config);
			if ($success) {
				echo "[HTTPS] Renewed. " . self::daysRemaining() . " days remaining.\n";
				// Reload SSL context in WebServer
				self::reloadServerCerts();
			}
		}
	}

	// ── Remote download mode ─────────────────────────────

	/**
	 * Download certs from a remote URL (.zip file containing
	 * fullchain.pem and privkey.pem).
	 *
	 * @method downloadRemote
	 * @static
	 * @param {array} $config
	 * @return {boolean}
	 */
	static function downloadRemote($config)
	{
		$url = Q::ifset($config, 'remote', 'url', '');
		if (!$url) {
			echo "[HTTPS] No remote cert URL configured\n";
			return false;
		}

		echo "[HTTPS] Downloading certs from $url...\n";

		$certsDir = self::certsDir();
		$zipPath = $certsDir . DS . 'certs-download.zip';

		// Download
		$ch = curl_init($url);
		$fp = fopen($zipPath, 'wb');
		curl_setopt_array($ch, array(
			CURLOPT_FILE => $fp,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 30,
		));
		$success = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		fclose($fp);

		if (!$success || $status >= 400) {
			echo "[HTTPS] Download failed (HTTP $status)\n";
			@unlink($zipPath);
			return false;
		}

		// Extract
		$zip = new ZipArchive();
		if ($zip->open($zipPath) !== true) {
			echo "[HTTPS] Invalid zip file\n";
			@unlink($zipPath);
			return false;
		}

		$extracted = false;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			$basename = basename($name);
			if ($basename === 'fullchain.pem' || $basename === 'privkey.pem') {
				$zip->extractTo($certsDir, $name);
				// Move to certsDir root if nested
				$extractedPath = $certsDir . DS . $name;
				$targetPath = $certsDir . DS . $basename;
				if ($extractedPath !== $targetPath && file_exists($extractedPath)) {
					rename($extractedPath, $targetPath);
				}
				$extracted = true;
			}
		}
		$zip->close();
		@unlink($zipPath);

		if (!$extracted) {
			echo "[HTTPS] Zip did not contain fullchain.pem / privkey.pem\n";
			return false;
		}

		self::$certPath = $certsDir . DS . 'fullchain.pem';
		self::$keyPath = $certsDir . DS . 'privkey.pem';

		$days = self::daysRemaining();
		echo "[HTTPS] Certs installed, " . ($days ?? '?') . " days remaining\n";

		return self::validateCerts();
	}

	/**
	 * Check if remote certs need re-downloading.
	 * Checks actual cert expiration, not mtime.
	 *
	 * @method checkRemoteRenewal
	 * @static
	 */
	static function checkRemoteRenewal($config)
	{
		$remaining = self::daysRemaining();
		$renewDays = 7; // re-download when < 7 days remain

		if ($remaining === null || $remaining <= $renewDays) {
			echo "[HTTPS] Remote cert expires in " . ($remaining ?? '?')
				. " days, re-downloading...\n";
			$success = self::downloadRemote($config);
			if ($success) {
				self::reloadServerCerts();
			}
		}
	}

	// ── Helpers ──────────────────────────────────────────

	/**
	 * Directory for storing cert files.
	 *
	 * @method certsDir
	 * @static
	 * @return {string}
	 */
	static function certsDir()
	{
		$dir = Q_Config::get('Q', 'web', 'https', 'certsDir', null);
		if (!$dir) {
			$dir = (defined('APP_DIR') ? APP_DIR : '.') . DS . 'config' . DS . 'certs';
		}
		if (!is_dir($dir)) {
			mkdir($dir, 0700, true);
		}
		return $dir;
	}

	/**
	 * Reload certs in the running server.
	 * For stream_socket_server, this requires restarting
	 * the listener with a new SSL context.
	 *
	 * @method reloadServerCerts
	 * @static
	 */
	static function reloadServerCerts()
	{
		// With per-connection SSL context, new connections
		// automatically pick up the new cert files.
		// Just notify Q_WebServer for logging.
		Q_WebServer::reloadTls();
	}

	/**
	 * Format cert info for display.
	 *
	 * @method info
	 * @static
	 * @return {array} [valid, daysRemaining, expiry, subject, issuer]
	 */
	static function info()
	{
		if (!self::$certPath || !file_exists(self::$certPath)) {
			return array('valid' => false);
		}

		$pem = file_get_contents(self::$certPath);
		$cert = openssl_x509_parse($pem);
		if (!$cert) return array('valid' => false);

		$expiry = $cert['validTo_time_t'];
		return array(
			'valid'         => $expiry > time(),
			'daysRemaining' => max(0, (int) floor(($expiry - time()) / 86400)),
			'expiry'        => date('Y-m-d H:i:s', $expiry),
			'subject'       => $cert['subject']['CN'] ?? '',
			'issuer'        => $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? '',
		);
	}
}
