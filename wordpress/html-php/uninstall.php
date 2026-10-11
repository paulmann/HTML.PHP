<?php
/**
 * Removes every trace of the plugin. Runs only through WP uninstall.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('htmlphp_content_ssi');
delete_option('htmlphp_cache_keys');

global $wpdb;

if (isset($wpdb) && is_object($wpdb)) {
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'htmlphp\_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_htmlphp:%' OR option_name LIKE '\_transient\_timeout\_htmlphp:%'");
}

$uploads = wp_upload_dir();
$cacheDir = (string) ($uploads['basedir'] ?? '') . '/htmlphp-cache';

if (is_dir($cacheDir)) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }

    @rmdir($cacheDir);
}
