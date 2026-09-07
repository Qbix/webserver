#!/usr/bin/env php
<?php
/**
 * Build qbixserver.phar — single-file distributable.
 *
 * Usage: php -d phar.readonly=0 build-phar.php
 * Output: bin/qbixserver.phar
 */

if (ini_get('phar.readonly')) {
	echo "Error: phar.readonly is enabled.\n";
	echo "Run with: php -d phar.readonly=0 build-phar.php\n";
	exit(1);
}

$pharFile = __DIR__ . '/bin/qbixserver.phar';
if (file_exists($pharFile)) {
	unlink($pharFile);
}

@mkdir(__DIR__ . '/bin', 0755, true);

echo "Building qbixserver.phar...\n";

$phar = new Phar($pharFile, 0, 'qbixserver.phar');
$phar->startBuffering();

// Add src/ tree WITH the src/ prefix so __DIR__.'/src/Q.php' works
$baseDir = __DIR__;
$srcDir = __DIR__ . '/src';
$it = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($it as $file) {
	$rel = substr($file->getPathname(), strlen($baseDir) + 1); // e.g. src/Q.php
	$phar->addFile($file->getPathname(), $rel);
}

// Add the main server file at root
$phar->addFile(__DIR__ . '/qbixserver.php', 'qbixserver.php');

// Add web/ directory for self-contained mode
$webDir = __DIR__ . '/web';
if (is_dir($webDir)) {
	$wit = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($webDir, RecursiveDirectoryIterator::SKIP_DOTS)
	);
	foreach ($wit as $file) {
		$rel = substr($file->getPathname(), strlen($baseDir) + 1); // e.g. web/index.php
		$phar->addFile($file->getPathname(), $rel);
	}
}

$fileCount = $phar->count();

// Minimal stub
$stub = <<<'STUB'
#!/usr/bin/env php
<?php
Phar::mapPhar('qbixserver.phar');
require 'phar://qbixserver.phar/qbixserver.php';
__HALT_COMPILER();
STUB;

$phar->setStub($stub);
$phar->stopBuffering();

chmod($pharFile, 0755);

$size = filesize($pharFile);
echo "Built: bin/qbixserver.phar (" . round($size / 1024) . " KB, $fileCount files)\n";
echo "Run:   php bin/qbixserver.phar --root=./web --port=8080\n";
