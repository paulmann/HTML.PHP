<?php

declare(strict_types=1);

namespace HtmlPhp\Config;

use HtmlPhp\Config;

/**
 * Logging/diagnostics sink options.
 */
final readonly class Log
{
    public const array LEVELS = [
        'debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3,
        'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7,
    ];

    public function __construct(
        public LogDriver $driver = LogDriver::Null,
        public string $path = '',
        public string $level = 'warning',
        public string $channel = 'htmlphp',
        public bool $forwardDiagnostics = true
    ) {
        if (!isset(self::LEVELS[$this->level])) {
            throw new \HtmlPhp\Exception\ConfigException(
                'log.level must be a PSR-3 level name.',
                ['value' => $level]
            );
        }
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $driver = (string) Config::value($config, 'driver', LogDriver::Null->value);

        return new self(
            driver: LogDriver::tryFrom($driver)
                ?? throw new \HtmlPhp\Exception\ConfigException(
                    'log.driver must be one of: null, array, errorlog, file, syslog, wordpress.',
                    ['value' => $driver]
                ),
            path: (string) Config::value($config, 'path', ''),
            level: (string) Config::value($config, 'level', 'warning'),
            channel: (string) Config::value($config, 'channel', 'htmlphp'),
            forwardDiagnostics: Config::bool($config, 'forward_diagnostics', true)
        );
    }

    public function accepts(string $level): bool
    {
        return self::levelAccepted($this->level, $level);
    }

    /** True when `$level` is at least as severe as `$minimum`. */
    public static function levelAccepted(string $minimum, string $level): bool
    {
        return (self::LEVELS[$level] ?? 0) >= (self::LEVELS[$minimum] ?? 0);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver->value,
            'path' => $this->path,
            'level' => $this->level,
            'channel' => $this->channel,
            'forward_diagnostics' => $this->forwardDiagnostics,
        ];
    }

    public const array KEYS = ['driver', 'path', 'level', 'channel', 'forward_diagnostics'];
}
