<?php
/**
 * @module Q
 */

/**
 * Resolves Windows shortcuts (.lnk) and macOS aliases to their targets.
 * Results are cached so the binary parsing only happens once per file.
 *
 * Used by Q_WebServer::resolveStatic() to transparently follow shortcuts
 * and aliases, making it easy for non-technical users to create links
 * without using symlinks or terminal commands.
 *
 * @class Q_WebServer_Shortcut
 */
class Q_WebServer_Shortcut
{
	/** @var array path => resolved target (or false if not resolvable) */
	private static $cache = array();

	/**
	 * Resolve a .lnk file or Mac alias. Returns the target path or null.
	 * Results are cached — subsequent calls for the same path are free.
	 *
	 * @method resolve
	 * @static
	 * @param {string} $path Path to a .lnk file or alias
	 * @return {string|null} Resolved target path, or null
	 */
	static function resolve($path)
	{
		if (isset(self::$cache[$path])) {
			$cached = self::$cache[$path];
			return $cached === false ? null : $cached;
		}

		$result = null;
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

		if ($ext === 'lnk') {
			$result = self::resolveLnk($path);
		} elseif (PHP_OS_FAMILY === 'Darwin') {
			$result = self::resolveMacAlias($path);
		}

		self::$cache[$path] = $result ?? false;
		return $result;
	}

	/**
	 * Check if a path is a shortcut/alias that we can resolve.
	 * @method isShortcut
	 * @static
	 * @param {string} $path
	 * @return {boolean}
	 */
	static function isShortcut($path)
	{
		if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'lnk') {
			return true;
		}
		// Mac aliases don't have a special extension — check resource fork
		if (PHP_OS_FAMILY === 'Darwin' && is_file($path)) {
			// Quick check: Mac aliases have a specific Finder flag
			// The 'alis' type or kIsAlias flag in the Finder info
			$rfork = $path . '/..namedfork/rsrc';
			if (file_exists($rfork) && filesize($rfork) > 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse a Windows .lnk shortcut file.
	 * Format: [MS-SHLNK] Shell Link Binary File Format
	 *
	 * @method resolveLnk
	 * @static
	 * @param {string} $path Path to .lnk file
	 * @return {string|null}
	 */
	static function resolveLnk($path)
	{
		$data = @file_get_contents($path, false, null, 0, 8192);
		if (!$data || strlen($data) < 76) return null;

		// Header magic: 4C 00 00 00
		if (substr($data, 0, 4) !== "\x4C\x00\x00\x00") return null;

		// CLSID must be 00021401-0000-0000-C000-000000000046
		$clsid = substr($data, 4, 16);
		$expected = "\x01\x14\x02\x00\x00\x00\x00\x00\xC0\x00\x00\x00\x00\x00\x00\x46";
		if ($clsid !== $expected) return null;

		// Link flags at offset 20 (4 bytes, little-endian)
		$flags = unpack('V', substr($data, 20, 4))[1];
		$hasTargetIDList = ($flags & 0x01) !== 0;
		$hasLinkInfo = ($flags & 0x02) !== 0;
		$hasStringData = ($flags & 0x04) !== 0; // NAME_STRING
		$isUnicode = ($flags & 0x80) !== 0;

		$offset = 76; // end of header

		// Skip LinkTargetIDList if present
		if ($hasTargetIDList) {
			if ($offset + 2 > strlen($data)) return null;
			$idListSize = unpack('v', substr($data, $offset, 2))[1];
			$offset += 2 + $idListSize;
		}

		// Parse LinkInfo for LocalBasePath
		if ($hasLinkInfo && $offset + 12 <= strlen($data)) {
			$linkInfoStart = $offset;
			$linkInfoSize = unpack('V', substr($data, $offset, 4))[1];
			$linkInfoHeaderSize = unpack('V', substr($data, $offset + 4, 4))[1];
			$linkInfoFlags = unpack('V', substr($data, $offset + 8, 4))[1];

			$hasLocalPath = ($linkInfoFlags & 0x01) !== 0;

			if ($hasLocalPath) {
				// LocalBasePathOffset at offset+12
				$localBasePathOffset = unpack('V', substr($data, $offset + 16, 4))[1];
				$pathStart = $linkInfoStart + $localBasePathOffset;

				// Read null-terminated string
				$target = '';
				for ($i = $pathStart; $i < strlen($data); $i++) {
					if ($data[$i] === "\x00") break;
					$target .= $data[$i];
				}

				if ($target && (file_exists($target) || is_dir($target))) {
					return $target;
				}

				// Try Unicode version (LocalBasePathOffsetUnicode)
				if ($linkInfoHeaderSize >= 0x24) {
					$uniOffset = unpack('V', substr($data, $offset + 28, 4))[1];
					$uniStart = $linkInfoStart + $uniOffset;
					$target = '';
					for ($i = $uniStart; $i + 1 < strlen($data); $i += 2) {
						$char = substr($data, $i, 2);
						if ($char === "\x00\x00") break;
						$target .= mb_convert_encoding($char, 'UTF-8', 'UTF-16LE');
					}
					if ($target && (file_exists($target) || is_dir($target))) {
						return $target;
					}
				}
			}

			$offset += $linkInfoSize;
		}

		// Fallback: try StringData (relative path or working dir)
		if ($hasStringData) {
			// Skip Name, RelativePath, WorkingDir, CommandLineArgs, IconLocation
			// Each is: CountCharacters (2 bytes) + String
			$fields = array('name', 'relativePath', 'workingDir');
			$fieldFlags = array(0x04, 0x08, 0x10);
			foreach ($fields as $idx => $field) {
				if (!($flags & $fieldFlags[$idx])) continue;
				if ($offset + 2 > strlen($data)) break;
				$count = unpack('v', substr($data, $offset, 2))[1];
				$offset += 2;
				$bytes = $isUnicode ? $count * 2 : $count;
				$str = substr($data, $offset, $bytes);
				if ($isUnicode) {
					$str = mb_convert_encoding($str, 'UTF-8', 'UTF-16LE');
				}
				$offset += $bytes;

				if ($field === 'relativePath' && $str) {
					$resolved = realpath(dirname($path) . DS . $str);
					if ($resolved) return $resolved;
				}
			}
		}

		return null;
	}

	/**
	 * Resolve a macOS alias using osascript (AppleScript).
	 * This is the most reliable method — it uses the same Finder
	 * resolution that the OS uses.
	 *
	 * @method resolveMacAlias
	 * @static
	 * @param {string} $path Path to alias file
	 * @return {string|null}
	 */
	static function resolveMacAlias($path)
	{
		if (PHP_OS_FAMILY !== 'Darwin') return null;

		// Use osascript to resolve — handles both legacy aliases and bookmarks
		$escaped = str_replace(array('\\', '"'), array('\\\\', '\\"'), $path);
		$cmd = 'osascript -e \'tell application "System Events" to get POSIX path'
			. ' of (disk item "' . $escaped . '" as alias)\' 2>/dev/null';
		$result = @trim(shell_exec($cmd) ?? '');

		if ($result && (file_exists($result) || is_dir($result))) {
			return $result;
		}

		// Fallback: try mdls to get the bookmark target
		$cmd2 = 'mdls -name kMDItemWhereFroms -raw ' . escapeshellarg($path) . ' 2>/dev/null';
		$result2 = @trim(shell_exec($cmd2) ?? '');
		if ($result2 && $result2 !== '(null)' && file_exists($result2)) {
			return $result2;
		}

		return null;
	}

	/**
	 * Clear the resolution cache (e.g. on hot reload).
	 * @method clearCache
	 * @static
	 */
	static function clearCache()
	{
		self::$cache = array();
	}
}
