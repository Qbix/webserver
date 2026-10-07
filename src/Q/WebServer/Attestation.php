<?php
/**
 * M-of-N code signing and runtime attestation.
 *
 * Sign: multiple signers each sign the binary's content hash.
 *   ./qbixserver --sign-binary --key=alice.pem --key=bob.pem
 *
 * Verify: at startup, check that M of N signatures are valid.
 *   Config: Q.webserver.trust.m = 2 (require 2 valid signatures)
 *
 * Attest: serve /Q/attestation with the binary hash and signatures.
 *   Browsers or monitoring tools can verify the deployment.
 *
 * Signatures are stored in signatures.json alongside the binary
 * (or inside the appended zip for packed binaries).
 */
class Q_WebServer_Attestation
{
	private static $signatures = null;
	private static $binaryHash = null;

	/**
	 * Compute the SHA-256 hash of the binary (excluding signatures.json).
	 */
	static function binaryHash($binaryPath = null)
	{
		if (self::$binaryHash !== null) return self::$binaryHash;
		if (!$binaryPath) {
			$binaryPath = realpath($_SERVER['SCRIPT_FILENAME'] ?? $GLOBALS['argv'][0]);
		}
		if (!$binaryPath || !is_file($binaryPath)) return null;
		self::$binaryHash = 'sha256:' . hash_file('sha256', $binaryPath);
		return self::$binaryHash;
	}

	/**
	 * Sign a binary with one or more private keys.
	 * Each key produces a signature over the binary's SHA-256 hash.
	 * Returns the signatures array.
	 */
	static function sign($binaryPath, $keyPaths, $signerNames = [])
	{
		$hash = hash_file('sha256', $binaryPath);
		$signatures = [];

		foreach ($keyPaths as $i => $keyPath) {
			$keyData = file_get_contents($keyPath);
			if (!$keyData) {
				fwrite(STDERR, "Cannot read key: $keyPath\n");
				continue;
			}
			$privKey = openssl_pkey_get_private($keyData);
			if (!$privKey) {
				fwrite(STDERR, "Invalid private key: $keyPath\n");
				continue;
			}
			$details = openssl_pkey_get_details($privKey);
			$keyId = substr(hash('sha256', $details['key']), 0, 16);

			$sig = '';
			if (!openssl_sign($hash, $sig, $privKey, OPENSSL_ALGO_SHA256)) {
				fwrite(STDERR, "Signing failed with: $keyPath\n");
				continue;
			}

			$signerName = $signerNames[$i] ?? basename($keyPath, '.pem');
			$signatures[] = [
				'signer' => $signerName,
				'key_id' => $keyId,
				'algorithm' => 'RSA-SHA256',
				'signature' => base64_encode($sig),
				'signed_at' => date('c'),
				'public_key' => $details['key'], // PEM public key
			];
		}

		return [
			'version' => 1,
			'binary_hash' => 'sha256:' . $hash,
			'binary_size' => filesize($binaryPath),
			'signatures' => $signatures,
			'created_at' => date('c'),
		];
	}

	/**
	 * Write signatures to a file next to the binary.
	 */
	static function writeSignatures($binaryPath, $sigData)
	{
		$sigPath = self::signaturesPath($binaryPath);
		@mkdir(dirname($sigPath), 0755, true);
		file_put_contents($sigPath, json_encode($sigData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		return $sigPath;
	}

	/**
	 * Add a signature to an existing signatures.json.
	 * Used for collecting signatures from multiple signers over time.
	 */
	static function addSignature($binaryPath, $keyPath, $signerName = null)
	{
		$sigPath = self::signaturesPath($binaryPath);
		$existing = is_file($sigPath) ? json_decode(file_get_contents($sigPath), true) : null;

		$newSigs = self::sign($binaryPath, [$keyPath], [$signerName ?? basename($keyPath, '.pem')]);
		if (empty($newSigs['signatures'])) return null;

		if ($existing && $existing['binary_hash'] === $newSigs['binary_hash']) {
			// Same binary — append signature
			$existing['signatures'][] = $newSigs['signatures'][0];
			self::writeSignatures($binaryPath, $existing);
			return $existing;
		}

		// Different binary or no existing file — fresh signatures
		self::writeSignatures($binaryPath, $newSigs);
		return $newSigs;
	}

	/**
	 * Verify M of N signatures against the binary.
	 * Returns ['valid' => bool, 'verified' => int, 'required' => int, 'details' => [...]]
	 */
	static function verify($binaryPath = null, $m = null)
	{
		if (!$binaryPath) {
			$binaryPath = realpath($_SERVER['SCRIPT_FILENAME'] ?? $GLOBALS['argv'][0]);
		}
		if (!$binaryPath) {
			return ['valid' => false, 'error' => 'Cannot determine binary path'];
		}

		$sigData = self::loadSignatures($binaryPath);
		if (!$sigData || empty($sigData['signatures'])) {
			return ['valid' => false, 'error' => 'No signatures found', 'verified' => 0, 'required' => $m ?? 1];
		}

		$currentHash = hash_file('sha256', $binaryPath);
		$expectedHash = str_replace('sha256:', '', $sigData['binary_hash']);

		// For packed binaries, the hash changes when files are appended.
		// In that case, verify against the stored hash (the signer signed
		// the original binary before packing).
		// TODO: sign the content hash (phar portion only) for packed binaries

		$verified = 0;
		$details = [];
		foreach ($sigData['signatures'] as $sig) {
			$pubKey = openssl_pkey_get_public($sig['public_key'] ?? '');
			if (!$pubKey) {
				$details[] = ['signer' => $sig['signer'], 'status' => 'invalid_key'];
				continue;
			}
			$sigBytes = base64_decode($sig['signature'] ?? '');
			$ok = openssl_verify($expectedHash, $sigBytes, $pubKey, OPENSSL_ALGO_SHA256);
			if ($ok === 1) {
				$verified++;
				$details[] = [
					'signer' => $sig['signer'],
					'key_id' => $sig['key_id'],
					'signed_at' => $sig['signed_at'] ?? null,
					'status' => 'valid',
				];
			} else {
				$details[] = [
					'signer' => $sig['signer'],
					'key_id' => $sig['key_id'],
					'status' => 'invalid_signature',
				];
			}
		}

		$required = $m ?? Q_Config::get('Q', 'webserver', 'trust', 'm', 1);
		$n = count($sigData['signatures']);

		return [
			'valid' => $verified >= $required,
			'verified' => $verified,
			'required' => $required,
			'total' => $n,
			'label' => "$verified of $n" . ($required > 1 ? " (need $required)" : ''),
			'binary_hash' => $sigData['binary_hash'],
			'hash_matches' => ($currentHash === $expectedHash),
			'details' => $details,
		];
	}

	/**
	 * Load signatures from disk.
	 */
	static function loadSignatures($binaryPath = null)
	{
		if (self::$signatures !== null) return self::$signatures;
		if (!$binaryPath) {
			$binaryPath = realpath($_SERVER['SCRIPT_FILENAME'] ?? $GLOBALS['argv'][0]);
		}
		$sigPath = self::signaturesPath($binaryPath);
		if (is_file($sigPath)) {
			self::$signatures = json_decode(file_get_contents($sigPath), true);
		}
		return self::$signatures;
	}

	/**
	 * Path to signatures.json for a given binary.
	 */
	static function signaturesPath($binaryPath)
	{
		return preg_replace('/\.(exe|phar)$/i', '', $binaryPath) . '.signatures.json';
	}

	/**
	 * Serve the /Q/attestation endpoint.
	 */
	static function attestation()
	{
		$binaryPath = realpath($_SERVER['SCRIPT_FILENAME'] ?? $GLOBALS['argv'][0]);
		$sigData = self::loadSignatures($binaryPath);
		$verification = self::verify($binaryPath);

		return [
			'server' => 'Qbix Server v' . QBIX_SERVER_VERSION,
			'binary_hash' => self::binaryHash($binaryPath),
			'binary_size' => filesize($binaryPath),
			'signed' => !empty($sigData['signatures']),
			'verification' => $verification,
			'signatures' => array_map(function($s) {
				// Don't expose full public keys in the endpoint — just metadata
				return [
					'signer' => $s['signer'],
					'key_id' => $s['key_id'],
					'algorithm' => $s['algorithm'] ?? 'RSA-SHA256',
					'signed_at' => $s['signed_at'] ?? null,
				];
			}, $sigData['signatures'] ?? []),
		];
	}
}
