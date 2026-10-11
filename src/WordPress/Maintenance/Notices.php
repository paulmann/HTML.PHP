<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress\Maintenance;

final class Notices
{
    private const string QUEUE_KEY = 'notices_queue';
    private static bool $installed = false;

    private function __construct()
    {
    }

    /**
     * Queues a notice for the next admin screen.
     *
     * @param string $type one of info|success|warning|error; anything else
     *                     is normalised to "info"
     */
    public static function add(string $text, string $type = 'info', bool $dismissible = true): void
    {
        $type = in_array($type, ['info', 'success', 'warning', 'error'], true) ? $type : 'info';
        $queue = Options::getArray(self::QUEUE_KEY);
        $queue[] = ['text' => $text, 'type' => $type, 'dismissible' => $dismissible];
        Options::set(self::QUEUE_KEY, array_slice($queue, -50));
    }

    public static function install(): void
    {
        if (self::$installed || !function_exists('add_action')) {
            return;
        }

        add_action('admin_notices', [self::class, 'render']);
        self::$installed = true;
    }

    /**
     * Renders and clears the queue (called from admin_notices).
     */
    public static function render(): void
    {
        $queue = Options::getArray(self::QUEUE_KEY);

        if ($queue === []) {
            return;
        }

        Options::delete(self::QUEUE_KEY);

        $escape = static fn(string $value): string => function_exists('esc_attr')
            ? esc_attr($value)
            : htmlspecialchars($value, \ENT_QUOTES);

        foreach ($queue as $notice) {
            $class = 'notice notice-' . (is_string($notice['type'] ?? null) ? $notice['type'] : 'info');
            $class .= !empty($notice['dismissible']) ? ' is-dismissible' : '';

            echo '<div class="' . $escape($class) . '"><p>'
                . $escape((string) ($notice['text'] ?? '')) . '</p></div>';
        }
    }

    /**
     * The queued notices without rendering them (tests, CLI).
     *
     * @return list<array{text: string, type: string, dismissible: bool}>
     */
    public static function queued(): array
    {
        return Options::getArray(self::QUEUE_KEY);
    }

    public static function reset(): void
    {
        self::$installed = false;
    }
}
