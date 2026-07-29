<?php
/**
 * @module Q
 */

/**
 * On-the-fly image resizing and format conversion with disk cache.
 *
 * Intercepts image requests with ?w= or ?h= query params, or requests
 * for a format that doesn't exist on disk but can be generated from
 * a source with the same basename (e.g. photo.webp from photo.png).
 *
 * Cache lives at APP_DIR/files/Q/cached/images/ with structure:
 *   {basename}/{width}x{height}.{format}
 *
 * @class Q_WebServer_Image
 */
class Q_WebServer_Image
{
	/** @var string Cache directory path */
	static $cacheDir = null;

	/** @var integer Max cache size in bytes (default 256MB) */
	static $maxCacheSize = 268435456;

	/** @var array Supported source formats */
	static $sourceFormats = array('png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp');

	/** @var array Formats we can convert to */
	static $outputFormats = array('webp', 'jpg', 'jpeg', 'png', 'gif', 'avif');

	/**
	 * Initialize the cache directory.
	 * @method init
	 * @static
	 */
	static function init()
	{
		if (self::$cacheDir) return;
		$base = defined('APP_DIR') ? APP_DIR : Q_WebServer::$rootDir . '..';
		self::$cacheDir = rtrim($base, DS) . DS . 'files' . DS . 'Q'
			. DS . 'cached' . DS . 'images' . DS;
		self::$maxCacheSize = Q_Config::get('Q', 'images', 'cache', 'maxSize',
			self::$maxCacheSize);
	}

	/**
	 * Try to handle an image request. Returns a response array or null.
	 *
	 * Handles two cases:
	 * 1. Resize: ?w=300 or ?w=300&h=200 on any image URL
	 * 2. Format conversion: /photo.webp when only /photo.png exists
	 *
	 * @method handle
	 * @static
	 * @param {string} $fsPath Resolved filesystem path (may not exist for conversion)
	 * @param {string} $urlPath The URL path
	 * @param {array} $parsed Full parsed request
	 * @return {array|null} Response array or null if not an image request
	 */
	static function handle($fsPath, $urlPath, $parsed)
	{
		self::init();

		$ext = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
		$query = array();
		if (!empty($parsed['query'])) parse_str($parsed['query'], $query);

		$wantResize = isset($query['w']) || isset($query['h']);
		$wantConvert = !$fsPath && in_array($ext, self::$outputFormats);

		if (!$wantResize && !$wantConvert) return null;

		// Find the source file
		$sourcePath = $fsPath;
		$sourceExt = $ext;
		if (!$sourcePath || !file_exists($sourcePath)) {
			// Look for same basename with different extension
			$sourcePath = self::findSource($urlPath);
			if (!$sourcePath) return null;
			$sourceExt = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
		}

		// Requested dimensions
		$w = isset($query['w']) ? max(1, min(4096, (int) $query['w'])) : 0;
		$h = isset($query['h']) ? max(1, min(4096, (int) $query['h'])) : 0;
		$outFormat = in_array($ext, self::$outputFormats) ? $ext : $sourceExt;

		// Check Accept header for format preference
		$accept = $parsed['headers']['accept'] ?? '';
		$saveData = ($parsed['headers']['save-data'] ?? '') === 'on';

		// Auto-select best format when resizing
		if ($wantResize || $wantConvert) {
			if (strpos($accept, 'image/avif') !== false
				&& function_exists('imageavif')
				&& $outFormat !== 'avif'
			) {
				$outFormat = 'avif'; // best compression
			} elseif (strpos($accept, 'image/webp') !== false
				&& $outFormat !== 'webp' && $outFormat !== 'avif'
			) {
				$outFormat = 'webp';
			}
		}

		// Save-Data: reduce quality and prefer smaller format
		$qualityOverride = null;
		if ($saveData) {
			$qualityOverride = 50; // lower quality for metered connections
			if (!$w && !$h) {
				// No resize requested but Save-Data is on — cap at 800px
				$w = 800;
			}
		}

		// Normalize jpeg
		if ($outFormat === 'jpeg') $outFormat = 'jpg';

		// Cache path: files/Q/cached/images/{basename}/{w}x{h}.{format}
		// Include save-data in cache key to avoid serving low-quality to normal users
		$basename = pathinfo($urlPath, PATHINFO_FILENAME);
		$dirPart = dirname($urlPath);
		$cacheKey = ltrim($dirPart . '/' . $basename, '/');
		$sizeKey = ($w || $h)
			? ($w ? $w : '') . 'x' . ($h ? $h : '')
			: 'original';
		if ($saveData) $sizeKey .= '_sd';
		$cachePath = self::$cacheDir . str_replace('/', DS, $cacheKey)
			. DS . $sizeKey . '.' . $outFormat;

		// Cache hit — serve directly
		if (file_exists($cachePath)) {
			$mtime = filemtime($sourcePath);
			$cmtime = filemtime($cachePath);
			if ($cmtime >= $mtime) {
				return self::imageResponse($cachePath, $outFormat, $parsed);
			}
			// Source changed — regenerate
			@unlink($cachePath);
		}

		// Generate in current process (forked child or event loop)
		$result = self::generate($sourcePath, $cachePath, $w, $h, $outFormat, $qualityOverride);
		if (!$result) return null;

		return self::imageResponse($cachePath, $outFormat, $parsed);
	}

	/**
	 * Find a source image when the requested format doesn't exist.
	 * e.g. /img/photo.webp → looks for /img/photo.png, /img/photo.jpg, etc.
	 * @method findSource
	 * @static
	 */
	static function findSource($urlPath)
	{
		$dir = dirname($urlPath);
		$basename = pathinfo($urlPath, PATHINFO_FILENAME);
		foreach (self::$sourceFormats as $srcExt) {
			$candidate = $dir . '/' . $basename . '.' . $srcExt;
			$rel = str_replace('/', DS, ltrim($candidate, '/'));
			$full = Q_WebServer::$rootDir . $rel;
			if (file_exists($full)) return $full;
		}
		return null;
	}

	/**
	 * Generate a resized/converted image.
	 * @method generate
	 * @static
	 */
	static function generate($sourcePath, $cachePath, $w, $h, $outFormat, $qualityOverride = null)
	{
		if (!function_exists('imagecreatetruecolor')) return false;

		$sourceExt = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

		// Animated GIF detection — GD can't resize these, just copy/convert
		if ($sourceExt === 'gif' && self::isAnimatedGif($sourcePath)) {
			if ($outFormat === 'gif' && !$w && !$h) {
				// Just copy — can't resize animated GIFs with GD
				$dir = dirname($cachePath);
				if (!is_dir($dir)) @mkdir($dir, 0755, true);
				copy($sourcePath, $cachePath);
				return true;
			}
			// Converting animated GIF to other format — use first frame only
		}

		// Load source image
		switch ($sourceExt) {
			case 'png':  $src = @imagecreatefrompng($sourcePath); break;
			case 'jpg':
			case 'jpeg': $src = @imagecreatefromjpeg($sourcePath); break;
			case 'gif':  $src = @imagecreatefromgif($sourcePath); break;
			case 'webp':
				if (!function_exists('imagecreatefromwebp')) return false;
				$src = @imagecreatefromwebp($sourcePath);
				break;
			case 'bmp':
				if (!function_exists('imagecreatefrombmp')) return false;
				$src = @imagecreatefrombmp($sourcePath);
				break;
			default: return false;
		}
		if (!$src) return false;

		$srcW = imagesx($src);
		$srcH = imagesy($src);

		// Don't upscale — clamp to source dimensions
		if ($w > $srcW) $w = $srcW;
		if ($h > $srcH) $h = $srcH;

		// Calculate target dimensions maintaining aspect ratio
		if ($w && $h) {
			$dstW = $w; $dstH = $h;
		} elseif ($w) {
			$dstW = $w;
			$dstH = max(1, (int) round($srcH * ($w / $srcW)));
		} elseif ($h) {
			$dstH = $h;
			$dstW = max(1, (int) round($srcW * ($h / $srcH)));
		} else {
			$dstW = $srcW; $dstH = $srcH;
		}

		// Determine if output supports transparency
		$outSupportsAlpha = in_array($outFormat, array('png', 'webp', 'gif', 'avif'));
		// Determine if source might have alpha
		$srcHasAlpha = in_array($sourceExt, array('png', 'webp', 'gif'));

		// Resize or prepare for format conversion
		if ($dstW !== $srcW || $dstH !== $srcH) {
			$dst = imagecreatetruecolor($dstW, $dstH);
			if ($outSupportsAlpha) {
				// Preserve transparency
				imagealphablending($dst, false);
				imagesavealpha($dst, true);
				$transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
				imagefill($dst, 0, 0, $transparent);
			} elseif ($srcHasAlpha) {
				// Flatten alpha onto white background (e.g. PNG→JPEG)
				$white = imagecolorallocate($dst, 255, 255, 255);
				imagefill($dst, 0, 0, $white);
				imagealphablending($dst, true);
			}
			imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
			imagedestroy($src);
			$src = $dst;
		} else {
			// No resize — but may need alpha handling for format conversion
			if (!$outSupportsAlpha && $srcHasAlpha) {
				// Flatten: composite source onto white background
				$dst = imagecreatetruecolor($srcW, $srcH);
				$white = imagecolorallocate($dst, 255, 255, 255);
				imagefill($dst, 0, 0, $white);
				imagealphablending($dst, true);
				imagecopy($dst, $src, 0, 0, 0, 0, $srcW, $srcH);
				imagedestroy($src);
				$src = $dst;
			} elseif ($outSupportsAlpha) {
				// Ensure alpha is preserved for format conversion
				imagealphablending($src, false);
				imagesavealpha($src, true);
			}
		}

		// Create cache directory
		$dir = dirname($cachePath);
		if (!is_dir($dir)) @mkdir($dir, 0755, true);

		// Save in target format
		$quality = $qualityOverride ?? Q_Config::get('Q', 'images', 'quality', 82);
		switch ($outFormat) {
			case 'webp':
				if (!function_exists('imagewebp')) { imagedestroy($src); return false; }
				// GD imagewebp quality: 0-100 lossy, -1 = lossless
				// Quality > 100 or == 0 can produce bloated output in some GD versions
				$wq = max(1, min(100, $quality));
				imagewebp($src, $cachePath, $wq);
				// GD bug: imagewebp can produce 0-byte files on some inputs
				if (!file_exists($cachePath) || filesize($cachePath) === 0) {
					@unlink($cachePath);
					// Fallback: try lossless
					imagewebp($src, $cachePath, -1);
				}
				break;
			case 'avif':
				if (!function_exists('imageavif')) { imagedestroy($src); return false; }
				imageavif($src, $cachePath, max(0, min(100, $quality)));
				break;
			case 'jpg':
			case 'jpeg':
				imagejpeg($src, $cachePath, max(1, min(100, $quality)));
				break;
			case 'png':
				// PNG compression 0-9 (0=none, 9=max). Map from quality:
				// quality 100 → compression 0, quality 0 → compression 9
				$compression = (int) round((100 - $quality) / 100 * 9);
				imagepng($src, $cachePath, max(0, min(9, $compression)));
				break;
			case 'gif':
				imagegif($src, $cachePath);
				break;
			default:
				imagedestroy($src);
				return false;
		}
		imagedestroy($src);

		// Verify output was created
		if (!file_exists($cachePath) || filesize($cachePath) === 0) {
			@unlink($cachePath);
			return false;
		}

		// Evict old cache files if total size exceeds limit
		self::evictIfNeeded();

		return true;
	}

	/**
	 * Detect animated GIF (has multiple frames).
	 * @method isAnimatedGif
	 * @static
	 * @param {string} $path File path
	 * @return {boolean}
	 */
	static function isAnimatedGif($path)
	{
		$fh = @fopen($path, 'rb');
		if (!$fh) return false;
		$count = 0;
		while (!feof($fh) && $count < 2) {
			$chunk = fread($fh, 1024 * 100);
			$count += substr_count($chunk, "\x00\x21\xF9\x04");
			if ($count >= 2) { fclose($fh); return true; }
		}
		fclose($fh);
		return false;
	}

	/**
	 * Build a response for a cached image file.
	 * @method imageResponse
	 * @static
	 */
	static function imageResponse($path, $format, $parsed)
	{
		$mime = array(
			'webp' => 'image/webp', 'avif' => 'image/avif',
			'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
			'png' => 'image/png', 'gif' => 'image/gif',
		);
		$body = file_get_contents($path);
		$etag = '"' . dechex(filemtime($path)) . '-' . dechex(strlen($body)) . '"';

		// 304 Not Modified
		$ifNone = $parsed['headers']['if-none-match'] ?? '';
		if ($ifNone === $etag) {
			return array('status' => 304, 'body' => '',
				'headers' => array('ETag' => $etag));
		}

		return array(
			'status' => 200,
			'body' => $body,
			'headers' => array(
				'Content-Type' => $mime[$format] ?? 'application/octet-stream',
				'Cache-Control' => 'public, max-age=31536000, immutable',
				'ETag' => $etag,
				'Vary' => 'Accept',
			),
		);
	}

	/**
	 * LRU eviction — delete oldest cached files when total exceeds maxCacheSize.
	 * @method evictIfNeeded
	 * @static
	 */
	static function evictIfNeeded()
	{
		if (!is_dir(self::$cacheDir)) return;

		// Only check periodically (every ~50 cache writes)
		static $counter = 0;
		if (++$counter % 50 !== 0) return;

		$files = array();
		$totalSize = 0;
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(self::$cacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
		);
		foreach ($it as $file) {
			if (!$file->isFile()) continue;
			$size = $file->getSize();
			$totalSize += $size;
			$files[] = array(
				'path' => $file->getPathname(),
				'atime' => $file->getATime(),
				'size' => $size,
			);
		}

		if ($totalSize <= self::$maxCacheSize) return;

		// Sort by access time — oldest first
		usort($files, function ($a, $b) { return $a['atime'] - $b['atime']; });

		// Delete until under 75% of limit
		$target = (int) (self::$maxCacheSize * 0.75);
		foreach ($files as $f) {
			if ($totalSize <= $target) break;
			@unlink($f['path']);
			$totalSize -= $f['size'];
			// Clean empty parent dirs
			$dir = dirname($f['path']);
			if (is_dir($dir) && count(scandir($dir)) === 2) @rmdir($dir);
		}
	}
}
