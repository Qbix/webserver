#!/usr/bin/env php
<?php
/**
 * Build a self-contained Qbix Server phar that includes your app.
 *
 * Usage:
 *   php build-app.php [--output=myapp.phar] [--root=./web]
 *
 * The resulting phar contains:
 *   - The Qbix Server engine (src/, handlers/, qbixserver.php)
 *   - Your app's web/ directory (static files + PHP scripts)
 *   - Your app's config/ directory (if present)
 *   - Your app's classes/ and handlers/ directories (if present)
 *
 * Combine with a static PHP binary for a fully self-contained executable:
 *   spc micro:combine myapp.phar -O myapp
 *   chmod +x myapp
 *   ./myapp --port=8080
 *
 * Or run directly with PHP:
 *   php myapp.phar --port=8080
 */

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "Error: phar.readonly is enabled.\n");
    fwrite(STDERR, "Run with: php -d phar.readonly=0 " . $argv[0] . "\n");
    exit(1);
}

// Parse arguments
$opts = getopt('', ['output:', 'root:', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php build-app.php [--output=myapp.phar] [--root=./web]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --output=FILE   Output phar filename (default: app.phar)\n";
    echo "  --root=DIR      App root directory (default: current directory)\n";
    echo "                  Should contain web/, and optionally config/, classes/, handlers/\n";
    exit(0);
}

$output = $opts['output'] ?? 'app.phar';
$appRoot = $opts['root'] ?? getcwd();
$appRoot = rtrim(realpath($appRoot) ?: $appRoot, '/');

// Find the server directory
$serverDir = __DIR__;
if (!file_exists($serverDir . '/qbixserver.php')) {
    fwrite(STDERR, "Error: run this script from the webserver directory\n");
    exit(1);
}

// Validate app has a web/ directory
if (!is_dir($appRoot . '/web')) {
    fwrite(STDERR, "Error: no web/ directory found in $appRoot\n");
    fwrite(STDERR, "The app root should contain a web/ directory with your PHP scripts and static files.\n");
    exit(1);
}

// Clean up old phar
if (file_exists($output)) {
    unlink($output);
}

echo "Building self-contained phar...\n";
echo "  Server: $serverDir\n";
echo "  App:    $appRoot\n";
echo "  Output: $output\n\n";

$phar = new Phar($output);
$phar->startBuffering();

// Add server files
echo "  Adding server engine...\n";
$serverFiles = [
    'qbixserver.php',
    'src/',
    'handlers/',
    'web/',  // server's default web/ (welcome page, logo, etc.)
];

foreach ($serverFiles as $item) {
    $fullPath = $serverDir . '/' . $item;
    if (is_dir($fullPath)) {
        $phar->buildFromIterator(
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullPath, FilesystemIterator::SKIP_DOTS)
            ),
            $serverDir
        );
    } elseif (is_file($fullPath)) {
        $phar->addFile($fullPath, $item);
    }
}

// Add app files (these override server's web/ if present)
echo "  Adding app files...\n";
$appDirs = ['web', 'config', 'classes', 'handlers'];
$appFileCount = 0;

foreach ($appDirs as $dir) {
    $fullPath = $appRoot . '/' . $dir;
    if (is_dir($fullPath)) {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fullPath, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            $relPath = $dir . '/' . $iter->getSubPathname();
            $phar->addFile($file->getPathname(), $relPath);
            $appFileCount++;
        }
        echo "    $dir/ — " . iterator_count(new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fullPath, FilesystemIterator::SKIP_DOTS)
        )) . " files\n";
    }
}

// Add app's config/server.json if it exists
if (file_exists($appRoot . '/config/server.json')) {
    echo "    config/server.json included\n";
}

// Set the stub — this is what runs when the phar is executed
$stub = <<<'STUB'
#!/usr/bin/env php
<?php
// Self-contained Qbix Server — app bundled inside this phar
Phar::mapPhar('qbixserver.phar');
require 'phar://qbixserver.phar/qbixserver.php';
__HALT_COMPILER();
STUB;

$phar->setStub($stub);
$phar->stopBuffering();

$size = filesize($output);
$sizeKB = round($size / 1024);
echo "\nBuilt: $output ({$sizeKB} KB, $appFileCount app files)\n";
echo "\nRun with:\n";
echo "  php $output\n";
echo "  php $output --port=8080\n";
echo "\nOr combine with a static PHP binary:\n";
echo "  spc micro:combine $output -O myapp\n";
echo "  chmod +x myapp && ./myapp\n";
