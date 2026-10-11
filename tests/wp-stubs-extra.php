<?php
/**
 * v4 additions to the (v3-inherited) WordPress stub set:
 * object cache, wp_mail capture and theme-directory functions.
 *
 * All state lives in $GLOBALS['wp_stub_*'] so the TestRunner's per-test
 * snapshot/restore keeps cases isolated.
 */

declare(strict_types=1);

$GLOBALS['wp_stub_object_cache'] ??= [];
$GLOBALS['wp_stub_mail'] ??= [];
$GLOBALS['wp_stub_theme'] ??= 'stub-theme';

function wp_cache_get(string $key, string $group = '', bool $force = false, ?bool &$found = null): mixed
{
    $entry = $GLOBALS['wp_stub_object_cache'][$group][$key] ?? null;

    if ($entry !== null && ($entry['expires'] === 0 || $entry['expires'] > time())) {
        if ($found !== null) {
            $found = true;
        }

        return $entry['value'];
    }

    if ($found !== null) {
        $found = false;
    }

    return false;
}

function wp_cache_set(string $key, mixed $value, string $group = '', int $ttl = 0): bool
{
    $GLOBALS['wp_stub_object_cache'][$group][$key] = [
        'value' => $value,
        'expires' => $ttl > 0 ? time() + $ttl : 0,
    ];

    return true;
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    unset($GLOBALS['wp_stub_object_cache'][$group][$key]);

    return true;
}

function wp_cache_flush(): bool
{
    $GLOBALS['wp_stub_object_cache'] = [];

    return true;
}

/**
 * Captures mails instead of sending them.
 *
 * @param string|list<string> $to
 * @param string|list<string> $headers
 * @param string|list<string> $attachments
 */
function wp_mail(string|array $to, string $subject, string $message, string|array $headers = '', string|array $attachments = []): bool
{
    $GLOBALS['wp_stub_mail'][] = [
        'to' => (array) $to,
        'subject' => $subject,
        'message' => $message,
        'headers' => (array) $headers,
        'attachments' => (array) $attachments,
    ];

    return true;
}

function get_stylesheet(): string
{
    return (string) $GLOBALS['wp_stub_theme'];
}

function get_stylesheet_directory(): string
{
    return fixture_path('wp-theme');
}

function get_template_directory(): string
{
    return fixture_path('wp-theme');
}

function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
}
