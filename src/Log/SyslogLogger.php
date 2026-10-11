<?php

declare(strict_types=1);

namespace HtmlPhp\Log;

/** Forwards to the system syslog facility. */
final class SyslogLogger extends AbstractLogger
{
    private const int IDENT = LOG_USER;

    private bool $opened = false;

    public function __construct(private readonly string $channel = 'htmlphp') {}

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->opened) {
            openlog($this->channel, LOG_PID, self::IDENT);
            $this->opened = true;
        }

        syslog(self::priority($level), $this->interpolate($message, $context));
    }

    public function __destruct()
    {
        if ($this->opened) {
            closelog();
        }
    }

    private static function priority(string $level): int
    {
        return match ($level) {
            'emergency' => LOG_EMERG,
            'alert' => LOG_ALERT,
            'critical' => LOG_CRIT,
            'error' => LOG_ERR,
            'warning' => LOG_WARNING,
            'notice' => LOG_NOTICE,
            'info' => LOG_INFO,
            default => LOG_DEBUG,
        };
    }
}
