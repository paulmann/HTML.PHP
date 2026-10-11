<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress\Maintenance;

final class Transients
{
    private function __construct() {}

    public static function get(string $key, mixed $default = null, string $prefix = 'htmlphp_'): mixed
    {
        if (!\function_exists('get_transient')) {
            return $default;
        }


        $value = get_transient($prefix . $key);

        return $value === false ? $default : $value;
    }

    public static function set(string $key, mixed $value, int $ttl = 3600, string $prefix = 'htmlphp_'): bool
    {
        if (!\function_exists('set_transient')) {
            return false;
        }


        return (bool) set_transient($prefix . $key, $value, max(1, $ttl));
    }

    public static function delete(string $key, string $prefix = 'htmlphp_'): bool
    {
        if (!\function_exists('delete_transient')) {
            return false;
        }


        return (bool) delete_transient($prefix . $key);
    }

    /**
     * get-or-compute with a TTL: the standard maintenance cache pattern.
     */
    public static function remember(string $key, int $ttl, callable $factory, string $prefix = 'htmlphp_'): mixed
    {
        $sentinel = "\0htmlphp-miss\0";
        $cached = self::get($key, $sentinel, $prefix);

        if ($cached !== $sentinel) {
            return $cached;
        }

        $value = $factory();
        self::set($key, $value, $ttl, $prefix);

        return $value;
    }
}
