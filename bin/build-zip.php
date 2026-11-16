<?php

declare(strict_types=1);

/**
 * Builds the installable plugin zip: build/mulla-payments-{version}.zip.
 *
 *     composer install && php bin/build-zip.php
 *
 * The zip contains the plugin code plus vendor-prefixed/ (the bundled, prefixed SDK). It never
 * contains vendor/: the plugin has its own autoloader and needs no dev dependencies.
 */

const SLUG = 'mulla-payments';
const INCLUDE_PATHS = ['mulla-payments.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'src', 'views', 'assets', 'blocks', 'languages', 'vendor-prefixed'];

$root = dirname(__DIR__);

if (!is_file($root . '/vendor-prefixed/autoload.php')) {
    fwrite(STDERR, "build-zip: vendor-prefixed/ is missing. Run composer install first.\n");
    exit(1);
}

if (!preg_match('/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents($root . '/mulla-payments.php'), $match)) {
    fwrite(STDERR, "build-zip: could not read the Version header from mulla-payments.php.\n");
    exit(1);
}
$version = $match[1];

if (!preg_match('/^Stable tag:\s*(\S+)/m', (string) file_get_contents($root . '/readme.txt'), $stable) || $stable[1] !== $version) {
    fwrite(STDERR, "build-zip: readme.txt 'Stable tag' must equal the plugin Version ({$version}).\n");
    exit(1);
}

if (!is_dir($root . '/build') && !mkdir($root . '/build') && !is_dir($root . '/build')) {
    fwrite(STDERR, "build-zip: cannot create build/.\n");
    exit(1);
}

$zipPath = $root . '/build/' . SLUG . '-' . $version . '.zip';
@unlink($zipPath);

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "build-zip: cannot write {$zipPath}.\n");
    exit(1);
}

$count = 0;
foreach (INCLUDE_PATHS as $path) {
    $full = $root . '/' . $path;
    if (is_file($full)) {
        $zip->addFile($full, SLUG . '/' . $path);
        $count++;
        continue;
    }
    if (!is_dir($full)) {
        continue;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS)) as $file) {
        /** @var SplFileInfo $file */
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $zip->addFile($file->getPathname(), SLUG . '/' . $relative);
        $count++;
    }
}

$zip->close();

echo "build-zip: {$count} files -> " . substr($zipPath, strlen($root) + 1) . "\n";
