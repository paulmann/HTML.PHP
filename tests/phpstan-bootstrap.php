<?php

/**
 * Bootstrap for static analysis only.
 *
 * The WordPress stubs (tests/wp-stubs.php) need a couple of harness symbols
 * at load time. Under phpstan we cannot include tests/bootstrap.php (it
 * would execute suite bootstrapping), so the minimal surface is provided
 * here. Never used at runtime or during test runs.
 */

declare(strict_types=1);

if (!function_exists('fixture_path')) {
    function fixture_path(string $relative): string
    {
        return sys_get_temp_dir() . '/htmlphp-phpstan/' . ltrim(str_replace('\\', '/', $relative), '/');
    }
}

if (!function_exists('fixture_dir')) {
    function fixture_dir(): string
    {
        return sys_get_temp_dir() . '/htmlphp-phpstan';
    }
}
