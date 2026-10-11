<?php

declare(strict_types=1);

namespace HtmlPhp\Log;

/**
 * Forwards to PHP's `error_log()` — under WordPress this lands in
 * `debug.log` when `WP_DEBUG_LOG` is on, and in the SAPI log elsewhere.
 */
final class ErrorLogLogger extends AbstractLogger
{
    public function __construct(
        private readonly string $channel = 'htmlphp',
        private readonly string $minLevel = 'debug'
    ) {
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!\HtmlPhp\Config\Log::levelAccepted($this->minLevel, $level)) {
            return;
        }

        $line = sprintf(
            '[%s] %s.%s: %s%s',
            date('Y-m-d H:i:s'),
            $this->channel,
            $level,
            $this->interpolate($message, $context),
            $context === [] ? '' : ' ' . (json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}')
        );

        error_log($line);
    }
}
