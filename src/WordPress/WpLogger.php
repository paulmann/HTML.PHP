<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress;

use HtmlPhp\Config\Log as LogConfig;
use HtmlPhp\Log\AbstractLogger;
use HtmlPhp\WordPress\Maintenance\Logs;

/**
 * WordPress logger: rotating daily files under `{uploads}/html-php-logs/`
 * (via {@see Logs}) plus an `error_log()` mirror when `WP_DEBUG_LOG` is on,
 * so entries also land in `wp-content/debug.log`.
 */
final class WpLogger extends AbstractLogger
{
    public function __construct(
        private readonly string $channel = 'htmlphp',
        private readonly string $minLevel = 'warning'
    ) {
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!LogConfig::levelAccepted($this->minLevel, $level)) {
            return;
        }

        $line = $this->interpolate($message, $context);

        if ($context !== []) {
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $line .= ' ' . ($encoded === false ? '{}' : $encoded);
        }

        if (class_exists(Logs::class)) {
            Logs::write($level, $line);
        }

        if (defined('WP_DEBUG_LOG') && constant('WP_DEBUG_LOG')) {
            error_log(sprintf('[%s] %s.%s', $this->channel, $level, $line));
        }
    }
}
