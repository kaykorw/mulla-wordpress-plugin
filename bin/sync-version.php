<?php

declare(strict_types=1);

/**
 * Copies the plugin version (mulla-payments.php "Version:" header) into readme.txt "Stable tag".
 *
 *     php bin/sync-version.php           update readme.txt
 *     php bin/sync-version.php --check   exit 1 if they differ
 *
 * release-please bumps the PHP files through x-release-please markers; readme.txt cannot carry
 * markers (they would show on WordPress.org), so the release workflow runs this on the release PR.
 */

$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);

if (!preg_match('/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents($root . '/mulla-payments.php'), $match)) {
    fwrite(STDERR, "sync-version: no Version header in mulla-payments.php.\n");
    exit(1);
}
$version = $match[1];

$readmePath = $root . '/readme.txt';
$readme = (string) file_get_contents($readmePath);
$updated = preg_replace('/^Stable tag:\s*\S+/m', 'Stable tag: ' . $version, $readme, 1, $count);

if ($count !== 1 || $updated === null) {
    fwrite(STDERR, "sync-version: no 'Stable tag:' line in readme.txt.\n");
    exit(1);
}

if ($updated === $readme) {
    echo "sync-version: readme.txt already at {$version}\n";
    exit(0);
}

if ($check) {
    fwrite(STDERR, "sync-version: readme.txt Stable tag differs from plugin Version {$version}. Run php bin/sync-version.php.\n");
    exit(1);
}

file_put_contents($readmePath, $updated);
echo "sync-version: readme.txt Stable tag -> {$version}\n";
