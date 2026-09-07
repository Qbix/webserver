<?php
/**
 * Q_WebServer_Trust — code integrity verification.
 *
 * Verifies PHP files against signed manifests before loading them.
 * Supports M-of-N signature policies: "this code was signed by at least
 * 2 of these 3 keys before the server will run it."
 *
 * Manifest format (manifest.json in app/plugin root):
 * {
 *   "version": "1.0.0",
 *   "created": "2026-09-07T12:00:00Z",
 *   "files": {
 *     "src/Controller.php": "sha256:a1b2c3...",
 *     "src/Model.php": "sha256:d4e5f6..."
 *   },
 *   "signatures": [
 *     {"keyId": "developer", "algorithm": "sha256", "value": "base64..."},
 *     {"keyId": "auditor-1", "algorithm": "sha256", "value": "base64..."}
 *   ],
 *   "policy": {
 *     "require": 2,
 *     "keys": ["developer", "auditor-1", "auditor-2"]
 *   }
 * }
 *
 * Usage:
 *   --sign=DIR --key=path/to/private.pem --key-id=developer
 *   --verify=DIR
 *
 * @class Q_WebServer_Trust
 */
class Q_WebServer_Trust
{
	/** @var array Loaded manifests: dir => parsed manifest */
	private static $manifests = array();

	/** @var array Trusted public keys: keyId => PEM string */
	private static $trustedKeys = array();

	/** @var array Verified file hashes: realpath => true */
	private static $verified = array();

	/** @var bool Whether trust verification is enabled */
	private static $enabled = false;

	/** @var array Verification failures for reporting */
	private static $failures = array();

	/**
	 * Initialize the trust system.
	 * Loads trusted keys from config and scans for manifests.
	 */
	static function init()
	{
		$enabled = Q_Config::get('Q', 'trust', 'enabled', false);
		if (!$enabled) return;

		self::$enabled = true;

		// Load trusted public keys from config
		$keys = Q_Config::get('Q', 'trust', 'keys', array());
		foreach ($keys as $keyId => $keyPath) {
			if (is_file($keyPath)) {
				$pem = file_get_contents($keyPath);
				if ($pem) {
					self::$trustedKeys[$keyId] = $pem;
				}
			} elseif (strpos($keyPath, '-----BEGIN') === 0) {
				// Inline PEM
				self::$trustedKeys[$keyId] = $keyPath;
			}
		}

		// Also load keys from keyDir (only .pub.pem files)
		$keyDir = Q_Config::get('Q', 'trust', 'keyDir', null);
		if ($keyDir && is_dir($keyDir)) {
			foreach (glob($keyDir . '/*.pub.pem') as $file) {
				$keyId = str_replace('.pub', '', pathinfo($file, PATHINFO_FILENAME));
				if (!isset(self::$trustedKeys[$keyId])) {
					self::$trustedKeys[$keyId] = file_get_contents($file);
				}
			}
		}
	}

	/**
	 * Check if trust verification is enabled.
	 * @return bool
	 */
	static function isEnabled()
	{
		return self::$enabled;
	}

	/**
	 * Verify all files in a directory against its manifest.
	 * Call this at startup (during prewarm) to verify before serving requests.
	 *
	 * @param string $dir Directory containing manifest.json
	 * @param string $label Human label for error messages (e.g., "app", "plugin/Users")
	 * @return array ['ok' => bool, 'manifest' => ..., 'errors' => [...]]
	 */
	static function verifyDirectory($dir, $label = '')
	{
		$dir = rtrim($dir, DIRECTORY_SEPARATOR);
		$manifestPath = $dir . DIRECTORY_SEPARATOR . 'manifest.json';

		if (!is_file($manifestPath)) {
			// No manifest — check if policy requires one
			$requireManifest = Q_Config::get('Q', 'trust', 'requireManifest', false);
			if ($requireManifest) {
				return array(
					'ok' => false,
					'errors' => array("No manifest.json found in $label ($dir)"),
				);
			}
			return array('ok' => true, 'errors' => array(), 'skipped' => true);
		}

		$raw = file_get_contents($manifestPath);
		$manifest = json_decode($raw, true);
		if (!$manifest || !isset($manifest['files'])) {
			return array(
				'ok' => false,
				'errors' => array("Invalid manifest.json in $label"),
			);
		}

		self::$manifests[$dir] = $manifest;
		$errors = array();

		// 1. Verify file hashes
		foreach ($manifest['files'] as $relPath => $expectedHash) {
			$fullPath = $dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
			if (!is_file($fullPath)) {
				$errors[] = "Missing file: $relPath (listed in manifest)";
				continue;
			}

			$actualHash = self::hashFile($fullPath);
			if ($actualHash !== $expectedHash) {
				$errors[] = "Hash mismatch: $relPath"
					. " (expected " . substr($expectedHash, 0, 20) . "..."
					. ", got " . substr($actualHash, 0, 20) . "...)";
			} else {
				self::$verified[realpath($fullPath)] = true;
			}
		}

		// 2. Check for unlisted files (files on disk not in manifest)
		$strictMode = Q_Config::get('Q', 'trust', 'strict', false);
		if ($strictMode) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
			);
			foreach ($iterator as $file) {
				if (!$file->isFile()) continue;
				if ($file->getExtension() !== 'php') continue;
				$rel = self::relativePath($dir, $file->getRealPath());
				if (!isset($manifest['files'][$rel])) {
					$errors[] = "Unlisted file: $rel (on disk but not in manifest)";
				}
			}
		}

		// 3. Verify signatures
		$policy = $manifest['policy'] ?? array();
		$required = (int) ($policy['require'] ?? 0);
		$allowedKeys = $policy['keys'] ?? array_keys(self::$trustedKeys);

		if ($required > 0) {
			$signatures = $manifest['signatures'] ?? array();
			$validSigs = 0;
			$sigDetails = array();

			// The signed payload is the canonical JSON of the files map
			$payload = self::canonicalJson($manifest['files']);

			foreach ($signatures as $sig) {
				$keyId = $sig['keyId'] ?? '';
				$sigValue = $sig['value'] ?? '';
				$algorithm = $sig['algorithm'] ?? 'sha256';

				if (!in_array($keyId, $allowedKeys)) {
					$sigDetails[] = "$keyId: not in allowed keys";
					continue;
				}

				if (!isset(self::$trustedKeys[$keyId])) {
					$sigDetails[] = "$keyId: public key not loaded";
					continue;
				}

				$pubKey = openssl_pkey_get_public(self::$trustedKeys[$keyId]);
				if (!$pubKey) {
					$sigDetails[] = "$keyId: invalid public key";
					continue;
				}

				$sigBinary = base64_decode($sigValue);
				$valid = openssl_verify(
					$payload,
					$sigBinary,
					$pubKey,
					self::opensslAlgorithm($algorithm)
				);

				if ($valid === 1) {
					$validSigs++;
					$sigDetails[] = "$keyId: ✓ valid";
				} else {
					$sigDetails[] = "$keyId: ✕ invalid signature";
				}
			}

			if ($validSigs < $required) {
				$errors[] = "Signature policy not met: $validSigs of $required required signatures valid"
					. " (" . implode('; ', $sigDetails) . ")";
			}
		}

		$ok = empty($errors);
		if (!$ok) {
			self::$failures[$label ?: $dir] = $errors;
		}

		return array(
			'ok' => $ok,
			'manifest' => $manifest,
			'errors' => $errors,
			'verified' => count(array_filter(
				$manifest['files'],
				function ($h, $p) use ($dir) {
					$fp = realpath($dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $p));
					return isset(self::$verified[$fp]);
				},
				ARRAY_FILTER_USE_BOTH
			)),
			'total' => count($manifest['files']),
		);
	}

	/**
	 * Check a single file at runtime (for the stream wrapper).
	 * Returns true if: trust is disabled, file has no manifest, or file hash matches.
	 * Returns false only if the file IS in a manifest and doesn't match.
	 *
	 * @param string $filePath Absolute path to the file
	 * @return bool
	 */
	static function checkFile($filePath)
	{
		if (!self::$enabled) return true;

		$realPath = realpath($filePath);
		if (!$realPath) return true; // file doesn't exist — let PHP handle the error

		// Already verified at startup?
		if (isset(self::$verified[$realPath])) return true;

		// Find which manifest covers this file
		foreach (self::$manifests as $dir => $manifest) {
			$rel = self::relativePath($dir, $realPath);
			if ($rel === null) continue; // not under this dir

			if (!isset($manifest['files'][$rel])) {
				// File exists under a manifested dir but isn't listed
				$strict = Q_Config::get('Q', 'trust', 'strict', false);
				return !$strict; // strict mode rejects unlisted files
			}

			// Check hash
			$expected = $manifest['files'][$rel];
			$actual = self::hashFile($realPath);
			if ($actual === $expected) {
				self::$verified[$realPath] = true;
				return true;
			}

			// Hash mismatch — file was modified since manifest was signed
			self::$failures['runtime'][] = "Hash mismatch: $rel (modified since signing)";
			return false;
		}

		// File isn't under any manifested directory — allow
		return true;
	}

	// ── Signing toolchain ───────────────────────────────

	/**
	 * Generate a manifest for a directory.
	 * Hashes every .php file recursively.
	 *
	 * @param string $dir Directory to manifest
	 * @param array $exclude Paths to exclude (relative)
	 * @return array The manifest data (unsigned)
	 */
	static function generateManifest($dir, $exclude = array())
	{
		$dir = rtrim($dir, DIRECTORY_SEPARATOR);
		$files = array();
		$defaultExclude = array('vendor/test', 'vendor/Test', 'tests/', 'Tests/');
		$exclude = array_merge($defaultExclude, $exclude);

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $file) {
			if (!$file->isFile()) continue;
			$ext = $file->getExtension();
			if (!in_array($ext, array('php', 'json', 'phtml', 'inc'))) continue;

			$rel = self::relativePath($dir, $file->getRealPath());
			if ($rel === null) continue;

			// Check exclusions
			$skip = false;
			foreach ($exclude as $ex) {
				if (strpos($rel, $ex) === 0) { $skip = true; break; }
			}
			if ($skip) continue;
			if ($rel === 'manifest.json') continue; // don't hash the manifest itself

			$files[$rel] = self::hashFile($file->getRealPath());
		}

		ksort($files);

		return array(
			'version' => Q_Config::get('Q', 'appVersion', '1.0.0'),
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'files' => $files,
			'signatures' => array(),
			'policy' => array(
				'require' => 0,
				'keys' => array(),
			),
		);
	}

	/**
	 * Sign a manifest with a private key.
	 * Adds the signature to the manifest's signatures array.
	 *
	 * @param array &$manifest The manifest data (modified in place)
	 * @param string $privateKeyPath Path to PEM private key
	 * @param string $keyId Identifier for this key
	 * @param string $algorithm Hash algorithm (default: sha256)
	 * @return bool
	 */
	static function signManifest(&$manifest, $privateKeyPath, $keyId, $algorithm = 'sha256')
	{
		$keyPem = file_get_contents($privateKeyPath);
		if (!$keyPem) return false;

		$privKey = openssl_pkey_get_private($keyPem);
		if (!$privKey) return false;

		$payload = self::canonicalJson($manifest['files']);
		$signature = '';
		$ok = openssl_sign($payload, $signature, $privKey, self::opensslAlgorithm($algorithm));
		if (!$ok) return false;

		// Remove any existing signature from this keyId
		$manifest['signatures'] = array_values(array_filter(
			$manifest['signatures'] ?? array(),
			function ($s) use ($keyId) { return ($s['keyId'] ?? '') !== $keyId; }
		));

		$manifest['signatures'][] = array(
			'keyId' => $keyId,
			'algorithm' => $algorithm,
			'value' => base64_encode($signature),
			'signed' => gmdate('Y-m-d\TH:i:s\Z'),
		);

		// Update policy keys list
		$keys = $manifest['policy']['keys'] ?? array();
		if (!in_array($keyId, $keys)) {
			$keys[] = $keyId;
			$manifest['policy']['keys'] = $keys;
		}

		return true;
	}

	/**
	 * Save a manifest to disk.
	 *
	 * @param array $manifest
	 * @param string $dir Directory to save manifest.json in
	 * @return bool
	 */
	static function saveManifest($manifest, $dir)
	{
		$path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'manifest.json';
		$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		return file_put_contents($path, $json, LOCK_EX) !== false;
	}

	// ── Status / Dashboard ──────────────────────────────

	/**
	 * Get trust status for the dashboard.
	 *
	 * @return array
	 */
	static function status()
	{
		$dirs = array();
		foreach (self::$manifests as $dir => $manifest) {
			$policy = $manifest['policy'] ?? array();
			$dirs[] = array(
				'dir' => basename($dir),
				'version' => $manifest['version'] ?? '?',
				'files' => count($manifest['files']),
				'signatures' => count($manifest['signatures'] ?? array()),
				'required' => (int) ($policy['require'] ?? 0),
				'verified' => count(array_filter(
					$manifest['files'],
					function ($h, $p) use ($dir) {
						$fp = realpath($dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $p));
						return isset(self::$verified[$fp]);
					},
					ARRAY_FILTER_USE_BOTH
				)),
			);
		}

		return array(
			'enabled' => self::$enabled,
			'trustedKeys' => count(self::$trustedKeys),
			'manifests' => $dirs,
			'failures' => self::$failures,
		);
	}

	/**
	 * Get all failures.
	 * @return array
	 */
	static function getFailures()
	{
		return self::$failures;
	}

	// ── Helpers ──────────────────────────────────────────

	/**
	 * Hash a file with SHA-256.
	 * @param string $path
	 * @return string "sha256:hex..."
	 */
	private static function hashFile($path)
	{
		return 'sha256:' . hash_file('sha256', $path);
	}

	/**
	 * Get a file's path relative to a base directory.
	 * Returns null if the file is not under the base.
	 *
	 * @param string $base
	 * @param string $fullPath
	 * @return string|null Forward-slash separated relative path
	 */
	private static function relativePath($base, $fullPath)
	{
		$base = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		if (strpos($fullPath, $base) !== 0) return null;
		return str_replace(DIRECTORY_SEPARATOR, '/', substr($fullPath, strlen($base)));
	}

	/**
	 * Produce canonical JSON of the files map for signing.
	 * Keys sorted, no whitespace — deterministic across platforms.
	 *
	 * @param array $files
	 * @return string
	 */
	private static function canonicalJson($files)
	{
		ksort($files);
		return json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Map algorithm name to OpenSSL constant.
	 *
	 * @param string $algo
	 * @return int
	 */
	private static function opensslAlgorithm($algo)
	{
		switch (strtolower($algo)) {
			case 'sha384': return OPENSSL_ALGO_SHA384;
			case 'sha512': return OPENSSL_ALGO_SHA512;
			case 'sha256':
			default: return OPENSSL_ALGO_SHA256;
		}
	}

	// ── Key generation helper ───────────────────────────

	/**
	 * Generate a keypair for signing.
	 *
	 * @param string $privateKeyPath Where to save the private key
	 * @param string $publicKeyPath Where to save the public key
	 * @return bool
	 */
	static function generateKeypair($privateKeyPath, $publicKeyPath)
	{
		$config = array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
			'digest_alg' => 'sha256',
		);

		$key = openssl_pkey_new($config);
		if (!$key) return false;

		openssl_pkey_export($key, $privatePem);
		$details = openssl_pkey_get_details($key);
		$publicPem = $details['key'];

		$ok1 = file_put_contents($privateKeyPath, $privatePem, LOCK_EX);
		$ok2 = file_put_contents($publicKeyPath, $publicPem, LOCK_EX);
		if ($ok1) chmod($privateKeyPath, 0600); // private key: owner-only

		return $ok1 && $ok2;
	}
}
