<?php
/** Shared bootstrap for the examples: dev autoload or single-file bundle. */
declare(strict_types=1);

$root = dirname(__DIR__);

foreach ([
    $root . '/vendor/autoload.php',
    $root . '/autoload.php',
    PHP_VERSION_ID >= 80500 ? $root . '/php85/html.php' : $root . '/php83/html.php',
] as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
        break;
    }
}

if (!class_exists(HtmlPhp\Engine::class)) {
    fwrite(STDERR, "Library not found.\n");
    exit(1);
}

function example_dir(string $sub = ''): string
{
    $dir = sys_get_temp_dir() . '/htmlphp-examples';
    @mkdir($dir, 0777, true);

    return $sub === '' ? $dir : $dir . '/' . trim($sub, '/');
}

echo "== HTML.PHP " . HtmlPhp\Version::banner() . " on PHP " . PHP_VERSION . " ==\n";
