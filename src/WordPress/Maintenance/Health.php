<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress\Maintenance;

final class Health
{
    private function __construct() {}

    /**
     * @return list<array{code: string, severity: 'info'|'warning'|'critical', message: string}>
     */
    /**
     * Runs every check; pass the engine to include its diagnostics backlog.
     */
    /**
     * @return list<array{code: string, severity: string, message: string}>
     */
    public static function check(?\HtmlPhp\Engine $engine = null): array
    {
        $issues = [];

        // PHP version support window.
        if (\PHP_VERSION_ID < 80300) {
            $issues[] = self::issue('php-eol', 'critical', 'PHP ' . \PHP_VERSION . ' is below the 8.3 engine requirement.');
        }

        // Debug mode in what looks like production.
        if (\defined('WP_DEBUG') && \WP_DEBUG === true && \defined('WP_DEBUG_DISPLAY') && \WP_DEBUG_DISPLAY === true) {
            $issues[] = self::issue('debug-display', 'warning', 'WP_DEBUG_DISPLAY is on: errors leak into HTML output.');
        }

        // Memory limit.
        $memory = self::iniBytes((string) \ini_get('memory_limit'));

        if ($memory > 0 && $memory < 64 * 1024 * 1024) {
            $issues[] = self::issue('memory-low', 'warning', 'memory_limit is below 64M (' . \ini_get('memory_limit') . ').');
        }

        // Disk space.
        if (\function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();
            $base = (string) ($uploads['basedir'] ?? '');

            if ($base !== '' && is_dir($base)) {
                if (!is_writable($base)) {
                    $issues[] = self::issue('uploads-ro', 'critical', 'The uploads directory is not writable.');
                }

                $free = @disk_free_space($base);

                if ($free !== false && $free < 100 * 1024 * 1024) {
                    $issues[] = self::issue('disk-low', 'critical', 'Less than 100 MB of free disk space.');
                } elseif ($free !== false && $free < 512 * 1024 * 1024) {
                    $issues[] = self::issue('disk-low', 'warning', 'Less than 512 MB of free disk space.');
                }
            }
        }

        // Database connectivity.
        $wpdb = $GLOBALS['wpdb'] ?? null;

        if (\is_object($wpdb) && method_exists($wpdb, 'check_connection')) {
            if (!$wpdb->check_connection($silent = true)) {
                $issues[] = self::issue('db-down', 'critical', 'The database connection is not alive.');
            }
        }

        // Cron.
        if (\defined('DISABLE_WP_CRON') && \DISABLE_WP_CRON === true) {
            $issues[] = self::issue('cron-external', 'info', 'DISABLE_WP_CRON is on: make sure a real crontab entry calls wp-cron.php.');
        }

        // Pending updates.
        if (class_exists(Updates::class) && \function_exists('get_site_transient')) {
            $pending = Updates::count();

            if ($pending > 0) {
                $issues[] = self::issue('updates-pending', 'info', $pending . ' update(s) available for core, plugins or themes.');
            }
        }

        // Engine diagnostics backlog.
        if ($engine !== null) {
            $errors = $engine->diagnostics()->count();

            if ($errors > 0) {
                $issues[] = self::issue('engine-errors', 'warning', $errors . ' template diagnostic(s) pending — inspect via $engine->diagnostics().');
            }
        }

        if ($issues === []) {
            $issues[] = self::issue('ok', 'info', 'No problems detected.');
        }

        return $issues;
    }

    /**
     * @return array{code: string, severity: string, message: string}
     */
    private static function issue(string $code, string $severity, string $message): array
    {
        return ['code' => $code, 'severity' => $severity, 'message' => $message];
    }

    /**
     * Parses shorthand byte values ("256M", "1G").
     */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return $value === '-1' ? \PHP_INT_MAX : 0;
        }

        if (preg_match('~^(\d+(?:\.\d+)?)\s*([kmg]?)b?$~i', $value, $m) !== 1) {
            return (int) $value;
        }

        $factor = match (strtolower($m[2])) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return (int) ((float) $m[1] * $factor);
    }
}
