<?php

/**
 * Development autoloader (PSR-4: HtmlPhp\ => src/).
 *
 * Production users either rely on Composer's autoloader or include the
 * single-file bundles (php83/html.php, php85/html.php) which need none.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'HtmlPhp\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
