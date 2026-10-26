<?php
/**
 * package-extensions.php
 *
 * Zips up extension/chrome/ and extension/firefox/ into store-ready .zip
 * packages, one per browser, with manifest.json sitting at the root of the
 * archive (as both Chrome Web Store and addons.mozilla.org require).
 *
 * The version number is read straight out of each browser's manifest.json,
 * so bump "version" there before re-packaging a new release.
 *
 * Usage: php package-extensions.php [chrome|firefox]
 *   (no argument packages both)
 */

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "The PHP zip extension (ext-zip) is required but not available.\n");
    exit(1);
}

$root = dirname(__DIR__) . '/extension';
$distDir = $root . '/dist';

$targets = ['chrome', 'firefox'];
$only = $argv[1] ?? null;
if ($only !== null) {
    if (!in_array($only, $targets, true)) {
        fwrite(STDERR, "Unknown target \"$only\". Expected one of: " . implode(', ', $targets) . "\n");
        exit(1);
    }
    $targets = [$only];
}

// Files we never want inside the published package.
$excludeNames = ['.DS_Store', 'Thumbs.db'];

/**
 * Recursively list every file under $dir, returning paths relative to $dir
 * using forward slashes (zip entry names must use "/").
 */
function listFiles(string $dir, array $excludeNames): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        if (in_array($fileInfo->getFilename(), $excludeNames, true)) {
            continue;
        }
        $relative = ltrim(str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($dir))), '/');
        $files[] = $relative;
    }

    sort($files);
    return $files;
}

if (!is_dir($distDir) && !mkdir($distDir, 0775, true) && !is_dir($distDir)) {
    fwrite(STDERR, "Failed to create output directory: $distDir\n");
    exit(1);
}

$exitCode = 0;

foreach ($targets as $browser) {
    $sourceDir = $root . '/' . $browser;
    $manifestPath = $sourceDir . '/manifest.json';

    if (!file_exists($manifestPath)) {
        fwrite(STDERR, "[$browser] manifest.json not found at $manifestPath, skipping.\n");
        $exitCode = 1;
        continue;
    }

    $manifest = json_decode(file_get_contents($manifestPath), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        fwrite(STDERR, "[$browser] Failed to parse manifest.json: " . json_last_error_msg() . "\n");
        $exitCode = 1;
        continue;
    }

    $version = $manifest['version'] ?? null;
    if (!is_string($version) || $version === '') {
        fwrite(STDERR, "[$browser] manifest.json has no \"version\" field.\n");
        $exitCode = 1;
        continue;
    }

    $slug = 'spicy-vtubers-notifier';
    $zipPath = "{$distDir}/{$slug}-{$browser}-{$version}.zip";

    if (file_exists($zipPath)) {
        unlink($zipPath);
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fwrite(STDERR, "[$browser] Failed to create $zipPath\n");
        $exitCode = 1;
        continue;
    }

    foreach (listFiles($sourceDir, $excludeNames) as $relativePath) {
        $zip->addFile($sourceDir . '/' . $relativePath, $relativePath);
    }

    $fileCount = $zip->numFiles;
    $zip->close();

    $size = number_format(filesize($zipPath) / 1024, 1);
    echo "[$browser] $zipPath ($fileCount files, {$size} KB)\n";
}

exit($exitCode);
