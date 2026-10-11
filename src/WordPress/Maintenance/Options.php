<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress\Maintenance;

final class Options
{
    private function __construct() {}

    public static function get(string $key, mixed $default = null, string $prefix = 'htmlphp_'): mixed
    {
        if (!\function_exists('get_option')) {
            return $GLOBALS['htmlphp_options'][$prefix . $key] ?? $default;
        }

        $value = get_option($prefix . $key, $default);

        return $value === false ? $default : $value;
    }

    public static function set(string $key, mixed $value, string $prefix = 'htmlphp_'): bool
    {
        if (!\function_exists('update_option')) {
            $GLOBALS['htmlphp_options'][$prefix . $key] = $value;

            return true;
        }

        return (bool) update_option($prefix . $key, $value);
    }

    public static function delete(string $key, string $prefix = 'htmlphp_'): bool
    {
        if (!\function_exists('delete_option')) {
            unset($GLOBALS['htmlphp_options'][$prefix . $key]);

            return true;
        }

        return (bool) delete_option($prefix . $key);
    }

    public static function getInt(string $key, int $default = 0, string $prefix = 'htmlphp_'): int
    {
        $value = self::get($key, $default, $prefix);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function getBool(string $key, bool $default = false, string $prefix = 'htmlphp_'): bool
    {
        $value = self::get($key, $default, $prefix);

        return \is_bool($value) ? $value : filter_var($value, \FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function getArray(string $key, string $prefix = 'htmlphp_'): array
    {
        $value = self::get($key, [], $prefix);

        return \is_array($value) ? $value : [];
    }
}
