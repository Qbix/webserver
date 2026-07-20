<?php
/**
 * @module Q
 */

/**
 * Reverse proxy header handling for Q_WebServer.
 *
 * When behind Cloudflare, AWS ALB, Caddy, nginx, etc.,
 * the client's real IP and protocol are in X-Forwarded-*
 * headers. This class extracts them from trusted proxies.
 *
 * Config:
 *   "Q": { "webserver": { "proxy": {
 *     "trusted": ["127.0.0.1", "10.0.0.0/8", "172.16.0.0/12",
 *       "192.168.0.0/16", "173.245.48.0/20", "103.21.244.0/22"],
 *     "headers": {
 *       "ip": "X-Forwarded-For",
 *       "proto": "X-Forwarded-Proto",
 *       "host": "X-Forwarded-Host"
 *     }
 *   }}}
 *
 * Cloudflare IPs are in the default trusted list. Add your
 * own load balancer IPs as needed.
 *
 * @class Q_WebServer_Proxy
 */
class Q_WebServer_Proxy
{
	static $trusted = null;

	/**
	 * Extract the real client IP from proxy headers.
	 * Only trusts headers from configured proxy IPs.
	 *
	 * @method clientIp
	 * @static
	 * @param {string} $directIp The socket-level remote IP
	 * @param {array} $headers Request headers (lowercase keys)
	 * @return {string} Real client IP
	 */
	static function clientIp($directIp, $headers)
	{
		if (!self::isTrusted($directIp)) return $directIp;

		$headerName = strtolower(Q_Config::get(
			'Q', 'webserver', 'proxy', 'headers', 'ip',
			'x-forwarded-for'
		));

		$forwarded = $headers[$headerName] ?? '';
		if (!$forwarded) return $directIp;

		// X-Forwarded-For: client, proxy1, proxy2
		// Rightmost untrusted IP is the real client
		$ips = array_map('trim', explode(',', $forwarded));
		for ($i = count($ips) - 1; $i >= 0; $i--) {
			if (!self::isTrusted($ips[$i])) {
				return $ips[$i];
			}
		}
		return $ips[0]; // all trusted, use leftmost
	}

	/**
	 * Extract the real protocol (http/https).
	 *
	 * @method clientProto
	 * @static
	 * @param {string} $directIp
	 * @param {array} $headers
	 * @param {boolean} $isTls Whether connection is TLS
	 * @return {string} 'http' or 'https'
	 */
	static function clientProto($directIp, $headers, $isTls = false)
	{
		if ($isTls) return 'https';
		if (!self::isTrusted($directIp)) return 'http';

		$headerName = strtolower(Q_Config::get(
			'Q', 'webserver', 'proxy', 'headers', 'proto',
			'x-forwarded-proto'
		));
		$proto = $headers[$headerName] ?? '';
		return strtolower($proto) === 'https' ? 'https' : 'http';
	}

	/**
	 * Extract the real host.
	 *
	 * @method clientHost
	 * @static
	 * @param {string} $directIp
	 * @param {array} $headers
	 * @return {string}
	 */
	static function clientHost($directIp, $headers)
	{
		if (self::isTrusted($directIp)) {
			$headerName = strtolower(Q_Config::get(
				'Q', 'webserver', 'proxy', 'headers', 'host',
				'x-forwarded-host'
			));
			$host = $headers[$headerName] ?? '';
			if ($host) return $host;
		}
		return $headers['host'] ?? 'localhost';
	}

	/**
	 * Check if an IP is a trusted proxy.
	 *
	 * @method isTrusted
	 * @static
	 * @param {string} $ip
	 * @return {boolean}
	 */
	static function isTrusted($ip)
	{
		if (self::$trusted === null) {
			self::$trusted = Q_Config::get(
				'Q', 'webserver', 'proxy', 'trusted',
				array('127.0.0.1', '::1')
			);
		}
		foreach (self::$trusted as $range) {
			if (strpos($range, '/') !== false) {
				if (self::ipInCidr($ip, $range)) return true;
			} else {
				if ($ip === $range) return true;
			}
		}
		return false;
	}

	/**
	 * Check if IP is within a CIDR range.
	 */
	static function ipInCidr($ip, $cidr)
	{
		list($subnet, $bits) = explode('/', $cidr);
		$ip = ip2long($ip);
		$subnet = ip2long($subnet);
		if ($ip === false || $subnet === false) return false;
		$mask = -1 << (32 - (int) $bits);
		return ($ip & $mask) === ($subnet & $mask);
	}
}
