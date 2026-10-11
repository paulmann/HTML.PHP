<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress\Maintenance;

final class SystemInfo
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function collect(?\HtmlPhp\Engine $engine = null): array
    {
        global $wp_version, $wpdb;

        $info = [
            'wordpress' => [
                'version' => \is_string($wp_version ?? null)
                    ? $wp_version
                    : (\function_exists('get_bloginfo') ? (string) get_bloginfo('version') : 'unknown'),
                'multisite' => \function_exists('is_multisite') ? (bool) is_multisite() : false,
                'locale' => \function_exists('determine_locale') ? (string) determine_locale() : '',
                'debug' => \defined('WP_DEBUG') && \WP_DEBUG === true,
                'script_debug' => \defined('SCRIPT_DEBUG') && \SCRIPT_DEBUG === true,
                'cron_disabled' => \defined('DISABLE_WP_CRON') && \DISABLE_WP_CRON === true,
                'memory_limit' => \defined('WP_MEMORY_LIMIT') ? (string) \WP_MEMORY_LIMIT : '',
                'site_url' => \function_exists('home_url') ? (string) home_url('/') : '',
                'active_theme' => self::themeName(),
                'plugins_active' => self::activePlugins(),
                'db_version' => \is_object($wpdb ?? null) && property_exists($wpdb, 'db_version')
                    ? (string) $wpdb->db_version
                    : '',
                'db_charset' => \is_object($wpdb ?? null) && property_exists($wpdb, 'charset')
                    ? (string) $wpdb->charset
                    : '',
            ],
            'php' => [
                'version' => \PHP_VERSION,
                'sapi' => \PHP_SAPI,
                'memory_limit' => (string) \ini_get('memory_limit'),
                'max_execution_time' => (string) \ini_get('max_execution_time'),
                'upload_max_filesize' => (string) \ini_get('upload_max_filesize'),
                'post_max_size' => (string) \ini_get('post_max_size'),
                'opcache' => \function_exists('opcache_get_status')
                    && (bool) \ini_get('opcache.enable'),
                'extensions' => self::relevantExtensions(),
            ],
            'server' => [
                'software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? \PHP_OS_FAMILY),
                'os' => \PHP_OS . ' ' . php_uname('m'),
                'disk_free' => self::diskFree(),
                'time' => date('c'),
                'timezone' => date_default_timezone_get(),
            ],
            'engine' => [
                'version' => \HtmlPhp\Version::banner(),
                'php' => \PHP_VERSION,
                'loaded_catalogues' => $engine !== null && $engine->translator() instanceof \HtmlPhp\I18n\Translator
                    ? $engine->translator()->stats()
                    : [],
                'pending_errors' => $engine?->diagnostics()->count() ?? 0,
            ],
        ];

        return $info;
    }

    /**
     * @return list<string>
     */
    private static function relevantExtensions(): array
    {
        $wanted = ['mbstring', 'intl', 'iconv', 'curl', 'gd', 'imagick', 'zip', 'openssl', 'json', 'mysqli', 'pdo_mysql', 'opcache'];
        $loaded = [];

        foreach ($wanted as $extension) {
            if (\extension_loaded($extension)) {
                $loaded[] = $extension;
            }
        }

        return $loaded;
    }

    private static function themeName(): string
    {
        if (!\function_exists('wp_get_theme')) {
            return '';
        }

        $theme = wp_get_theme();

        return method_exists($theme, 'get') ? (string) $theme->get('Name') : '';
    }

    /**
     * @return list<string>
     */
    private static function activePlugins(): array
    {
        if (!\function_exists('get_option')) {
            return [];
        }

        $plugins = get_option('active_plugins', []);

        return \is_array($plugins) ? array_values(array_map('strval', $plugins)) : [];
    }

    private static function diskFree(): string
    {
        if (!\function_exists('wp_upload_dir')) {
            return '';
        }

        $uploads = wp_upload_dir();
        $base = (string) ($uploads['basedir'] ?? '');

        if ($base === '' || !is_dir($base)) {
            return '';
        }

        $free = @disk_free_space($base);

        return $free === false ? '' : (string) $free;
    }
}
