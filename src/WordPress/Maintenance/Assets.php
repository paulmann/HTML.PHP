<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress\Maintenance;

final class Assets
{
    private function __construct() {}

    /**
     * Enqueues a stylesheet; the version is the file mtime when the file
     * is readable, so deploys bust proxies and browser caches.
     *
     * @param list<string> $deps
     */
    public static function style(string $handle, string $src, array $deps = [], string $media = 'all'): void
    {
        self::enqueue('style', $handle, $src, $deps, $media);
    }

    /**
     * @param list<string> $deps
     */
    public static function script(string $handle, string $src, array $deps = [], bool $inFooter = true): void
    {
        self::enqueue('script', $handle, $src, $deps, null, $inFooter);
    }


    /** @param list<string> $deps */
    private static function enqueue(string $kind, string $handle, string $src, array $deps, ?string $media, bool $inFooter = false): void
    {
        $version = self::version($src);

        if ($kind === 'style') {
            if (\function_exists('wp_register_style')) {
                wp_register_style($handle, $src, $deps, $version, $media);
            }

            if (\function_exists('wp_enqueue_style')) {
                wp_enqueue_style($handle);
            }

            return;
        }

        if (\function_exists('wp_register_script')) {
            wp_register_script($handle, $src, $deps, $version, $inFooter);
        }

        if (\function_exists('wp_enqueue_script')) {
            wp_enqueue_script($handle);
        }
    }

    /**
     * Resolves the cache-busting version for a URL or path.
     */
    public static function version(string $src): string
    {
        $path = $src;

        if (preg_match('~^https?://~i', $path) === 1) {
            if (\function_exists('home_url')) {
                $home = (string) home_url();

                if ($home !== '' && str_starts_with($path, $home)) {
                    $relative = substr($path, \strlen($home));

                    if (\function_exists('wp_normalize_path') && \defined('ABSPATH')) {
                        $path = rtrim((string) \ABSPATH, '/\\') . '/' . ltrim($relative, '/');
                    }
                }
            }
        }

        if (is_file($path)) {
            $mtime = @filemtime($path);

            if ($mtime !== false) {
                return (string) $mtime;
            }
        }

        return \HtmlPhp\Version::VERSION;
    }
}
