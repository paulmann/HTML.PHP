<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress\Maintenance;

use HtmlPhp\Exception\TemplateException;

final class Nonces
{
    private function __construct() {}

    public static function create(string $action = 'htmlphp_action'): string
    {
        self::requireWordPress();

        return (string) wp_create_nonce($action);
    }

    public static function verify(?string $nonce, string $action = 'htmlphp_action'): bool
    {
        self::requireWordPress();

        if (!\is_string($nonce) || $nonce === '') {
            return false;
        }

        return (bool) wp_verify_nonce($nonce, $action);
    }

    /**
     * Verifies the request nonce from $_REQUEST['_wpnonce'] and dies-safe
     * returns false otherwise; use inside form handlers.
     */
    public static function verifyRequest(string $action = 'htmlphp_action'): bool
    {
        $nonce = isset($_REQUEST['_wpnonce']) ? (string) $_REQUEST['_wpnonce'] : null;

        return self::verify($nonce, $action);
    }

    public static function field(string $action = 'htmlphp_action', string $name = '_wpnonce'): string
    {
        self::requireWordPress();

        $escape = static fn(string $value): string => \function_exists('esc_attr')
            ? esc_attr($value)
            : htmlspecialchars($value, \ENT_QUOTES);

        return '<input type="hidden" id="' . $escape($name) . '" name="' . $escape($name)
            . '" value="' . $escape(self::create($action)) . '" />';
    }

    private static function requireWordPress(): void
    {
        if (!\function_exists('wp_create_nonce')) {
            throw new TemplateException('WordPress nonce API is not available.');
        }
    }
}
