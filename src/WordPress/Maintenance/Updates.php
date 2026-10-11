<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress\Maintenance;

final class Updates
{
    private function __construct()
    {
    }

    /**
     * @return array{core: list<array<string, mixed>>, plugins: list<array<string, mixed>>, themes: list<array<string, mixed>>, last_checked: ?int}
     */
    public static function check(): array
    {
        $result = ['core' => [], 'plugins' => [], 'themes' => [], 'last_checked' => null];

        if (!function_exists('get_site_transient')) {
            return $result;
        }

        $core = get_site_transient('update_core');

        if (is_object($core)) {
            $result['last_checked'] = isset($core->last_checked) ? (int) $core->last_checked : null;

            foreach ((array) ($core->updates ?? []) as $update) {
                if (is_object($update) && ($update->response ?? '') === 'upgrade') {
                    $result['core'][] = [
                        'version' => (string) ($update->version ?? ''),
                        'php' => (string) ($update->php_version ?? ''),
                        'locale' => (string) ($update->locale ?? ''),
                    ];
                }
            }
        }

        $plugins = get_site_transient('update_plugins');

        if (is_object($plugins)) {
            foreach ((array) ($plugins->response ?? []) as $file => $update) {
                if (!is_object($update)) {
                    continue;
                }

                $result['plugins'][] = [
                    'file' => (string) $file,
                    'slug' => (string) ($update->slug ?? ''),
                    'new_version' => (string) ($update->new_version ?? ''),
                ];
            }
        }

        $themes = get_site_transient('update_themes');

        if (is_object($themes)) {
            foreach ((array) ($themes->response ?? []) as $slug => $update) {
                $result['themes'][] = [
                    'slug' => (string) $slug,
                    'new_version' => (string) (is_array($update) ? ($update['new_version'] ?? '') : ''),
                ];
            }
        }

        return $result;
    }

    public static function count(): int
    {
        $update = self::check();

        return count($update['core']) + count($update['plugins']) + count($update['themes']);
    }
}
