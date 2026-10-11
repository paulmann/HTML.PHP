<?php

declare(strict_types=1);

namespace HtmlPhp\Log;

/**
 * Line-oriented file log: `[ts] channel.LEVEL: message {json-context}`.
 *
 * Appends with a single `file_put_contents()` in append mode; concurrent
 * writers on the same line size are safe enough for diagnostics, and the
 * WordPress bridge offers rotating logs on top of it.
 */
final class FileLogger extends AbstractLogger
{
    public function __construct(
        private readonly string $path,
        private readonly string $channel = 'htmlphp',
        private readonly string $minLevel = 'warning',
    ) {}

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!\HtmlPhp\Config\Log::levelAccepted($this->minLevel, $level)) {
            return;
        }

        $line = \sprintf(
            "[%s] %s.%s: %s%s\n",
            date('Y-m-d H:i:s'),
            $this->channel,
            $level,
            $this->interpolate($message, $context),
            $context === [] ? '' : ' ' . (json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'),
        );

        $dir = \dirname($this->path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }
}
