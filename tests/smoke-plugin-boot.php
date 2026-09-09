<?php
/**
 * Smoke test: boots WordPress (SHORTINIT) and the plugin main file.
 * Catches any PHP fatals, missing classes, or broken autoload.
 * Exits 0 on success, non-zero on failure.
 */

declare(strict_types=1);

define('SHORTINIT', true);
$wpRoot = dirname(__DIR__, 4);
require_once $wpRoot . '/wp-load.php';

echo 'WP_BOOT: OK' . PHP_EOL;

$pluginFile = __DIR__ . '/../atomic-wp-linkedin-feed.php';
require_once $pluginFile;

echo 'PLUGIN_BOOT: OK' . PHP_EOL;
echo 'VERSION_CONST: ' . ATOMIC_WP_SOCIAL_SYNC_VERSION . PHP_EOL;
echo 'VERSION_FILE_OK: ' . (ATOMIC_WP_SOCIAL_SYNC_VERSION === '0.11.0' ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'PLUGIN_FILE: ' . ATOMIC_WP_SOCIAL_SYNC_FILE . PHP_EOL;

// Sanity-check the updater class is loadable via autoload.
$updaterExists = class_exists(\AtomicWPSocialSync\Update\GitHubReleaseUpdater::class);
echo 'UPDATER_CLASS_AUTOLOAD: ' . ($updaterExists ? 'PASS' : 'FAIL') . PHP_EOL;

exit(0);
