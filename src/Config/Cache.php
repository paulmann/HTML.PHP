<?php

declare(strict_types=1);

namespace HtmlPhp\Config;

use HtmlPhp\Config;

/**
 * Render-cache options. The concrete backend is chosen by `driver`; hosts
 * needing something exotic register their own
 * {@see \HtmlPhp\Contracts\CacheInterface} in the container instead.
 */
final readonly class Cache
{
    public function __construct(
        public bool $enabled = false,
        public CacheDriver $driver = CacheDriver::File,
        public string $directory = '',
        public int $ttl = 0,
        public string $prefix = 'htmlphp',
        public bool $lockWrites = true,
        public bool $hardenDirectory = true
    ) {
        if ($ttl < 0 || $ttl > 86400 * 30) {
            throw new \HtmlPhp\Exception\ConfigException(
                'cache.ttl must be between 0 and 30 days (seconds).',
                ['value' => $ttl]
            );
        }
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $driver = (string) Config::value($config, 'driver', CacheDriver::File->value);

        return new self(
            enabled: Config::bool($config, 'enabled', false),
            driver: CacheDriver::tryFrom($driver)
                ?? throw new \HtmlPhp\Exception\ConfigException(
                    'cache.driver must be one of: null, array, file, apcu, wordpress.',
                    ['value' => $driver]
                ),
            directory: (string) Config::value($config, 'directory', ''),
            ttl: Config::int($config, 'ttl', 0),
            prefix: (string) Config::value($config, 'prefix', 'htmlphp'),
            lockWrites: Config::bool($config, 'lock_writes', true),
            hardenDirectory: Config::bool($config, 'harden_directory', true)
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'driver' => $this->driver->value,
            'directory' => $this->directory,
            'ttl' => $this->ttl,
            'prefix' => $this->prefix,
            'lock_writes' => $this->lockWrites,
            'harden_directory' => $this->hardenDirectory,
        ];
    }

    public const array KEYS = [
        'enabled', 'driver', 'directory', 'ttl', 'prefix', 'lock_writes', 'harden_directory',
    ];
}
