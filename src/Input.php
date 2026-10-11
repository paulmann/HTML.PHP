<?php

declare(strict_types=1);

namespace HtmlPhp;

/**
 * Sanitised copies of the PHP input superglobals.
 *
 * This class is the single place in the whole library allowed to *read*
 * `$_GET`/`$_POST`/`$_COOKIE`/`$_REQUEST`, and it never writes to them.
 * Hosts that want the old `HTML_Sanitized_Input()` behaviour call
 * {@see self::all()} once at the edge and pass the result around explicitly.
 */
final class Input
{
    private function __construct() {}

    /**
     * `['get' => …, 'post' => …, 'cookie' => …, 'request' => …]` with every
     * string recursively `htmlspecialchars()`-encoded.
     *
     * @return array{get: array<array-key, mixed>, post: array<array-key, mixed>, cookie: array<array-key, mixed>, request: array<array-key, mixed>}
     */
    public static function all(): array
    {
        return [
            'get' => Support\Str::sanitizeRecursive($_GET),
            'post' => Support\Str::sanitizeRecursive($_POST),
            'cookie' => Support\Str::sanitizeRecursive($_COOKIE),
            'request' => Support\Str::sanitizeRecursive($_REQUEST),
        ];
    }

    /**
     * Sanitised `$_GET`/`$_POST`/`$_COOKIE`/`$_REQUEST` by name.
     *
     * @return array<array-key, mixed>
     */
    public static function source(string $name): array
    {
        $source = match (strtolower($name)) {
            'get' => $_GET,
            'post' => $_POST,
            'cookie' => $_COOKIE,
            'request' => $_REQUEST,
            default => throw new \InvalidArgumentException("Unknown input source '{$name}'."),
        };

        return Support\Str::sanitizeRecursive($source);
    }
}
