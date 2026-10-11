<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress\Maintenance;

use HtmlPhp\Exception\TemplateException;

final class Rest
{
    private function __construct() {}

    /**
     * Registers a route; refusing registrations without an explicit
     * permission callback is intentional (WP security guideline).
     */
    public static function route(
        string $namespace,
        string $route,
        callable $handler,
        string $methods = 'GET',
        ?callable $permission = null,
    ): void {
        if (!\function_exists('register_rest_route')) {
            throw new TemplateException('The REST API is not available.');
        }

        register_rest_route($namespace, $route, [
            'methods' => $methods,
            'callback' => $handler,
            'permission_callback' => $permission ?? static fn(): bool => current_user_can('manage_options'),
        ]);
    }

    /**
     * Standard JSON success envelope.
     *
     * @return array{success: true, data: mixed}
     */
    public static function ok(mixed $data): array
    {
        return ['success' => true, 'data' => $data];
    }

    /**
     * Standard JSON error envelope.
     *
     * @return array{success: false, code: string, message: string}
     */
    public static function fail(string $code, string $message): array
    {
        return ['success' => false, 'code' => $code, 'message' => $message];
    }
}
