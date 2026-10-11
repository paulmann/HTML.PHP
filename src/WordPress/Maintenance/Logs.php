<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress\Maintenance;

final class Logs
{
    private const array LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];

    private function __construct() {}

    public static function directory(): string
    {
        $configured = \defined('HTML_WP_LOG_DIR') ? \constant('HTML_WP_LOG_DIR') : null;

        if (\is_string($configured) && $configured !== '') {
            return rtrim($configured, "/\\");
        }

        if (\function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();
            $base = (string) ($uploads['basedir'] ?? '');

            if ($base !== '' && is_dir($base)) {
                return rtrim($base, "/\\") . \DIRECTORY_SEPARATOR . 'html-php-logs';
            }
        }

        return sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'html-php-logs';
    }

    public static function file(): string
    {
        return self::directory() . \DIRECTORY_SEPARATOR . 'html-php-' . date('Y-m-d') . '.log';
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function write(string $level, string $message, array $context = []): bool
    {
        $level = \in_array($level, self::LEVELS, true) ? $level : 'info';
        $directory = self::directory();

        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            return false;
        }

        $line = \sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . (string) json_encode($context, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        );

        $written = @file_put_contents(self::file(), $line, \FILE_APPEND | \LOCK_EX);

        self::rotate();

        return $written !== false;
    }

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): bool
    {
        return self::write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = []): bool
    {
        return self::write('error', $message, $context);
    }

    /**
     * Keeps only the newest N log files.
     */
    public static function rotate(int $keep = 14): int
    {
        $keep = max(1, $keep);
        $files = (array) glob(self::directory() . \DIRECTORY_SEPARATOR . 'html-php-*.log');
        $files = array_values(array_filter($files, 'is_string'));
        sort($files);

        $removed = 0;

        while (\count($files) > $keep) {
            $oldest = (string) array_shift($files);

            if (@unlink($oldest)) {
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * Tail of today's log (newest last).
     *
     * @return list<string>
     */
    public static function tail(int $lines = 50): array
    {
        $file = self::file();

        if (!is_file($file)) {
            return [];
        }

        $content = (string) @file_get_contents($file);
        $all = array_values(array_filter(explode("\n", $content), static fn(string $l): bool => trim($l) !== ''));

        return \array_slice($all, -$lines);
    }
}
