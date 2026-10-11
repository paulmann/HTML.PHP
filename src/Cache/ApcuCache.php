<?php

declare(strict_types=1);

namespace HtmlPhp\Cache;

use HtmlPhp\Contracts\CacheInterface;

/**
 * APCu-backed cache for single-host high-throughput installs.
 *
 * Requires the `apcu` extension; {@see self::isSupported()} lets the
 * factory fall back gracefully.
 */
final class ApcuCache implements CacheInterface
{
    public function __construct(private readonly string $prefix = 'htmlphp', private readonly int $defaultTtl = 0) {}

    public static function isSupported(): bool
    {
        return \function_exists('apcu_fetch') && (PHP_SAPI !== 'cli' || (bool) \ini_get('apc.enable_cli'));
    }

    public function get(string $key): ?string
    {
        $success = false;
        $value = apcu_fetch($this->prefix($key), $success);

        return $success && \is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        return apcu_store($this->prefix($key), $value, $ttl > 0 ? $ttl : $this->defaultTtl);
    }

    public function has(string $key): bool
    {
        return apcu_exists($this->prefix($key));
    }

    public function delete(string $key): bool
    {
        return apcu_delete($this->prefix($key));
    }

    public function clear(): int
    {
        $removed = 0;

        if (class_exists(\APCUIterator::class)) {
            $iterator = new \APCUIterator('/^' . preg_quote($this->prefix . ':', '/') . '/');
            $removed = iterator_count($iterator);
            apcu_delete($iterator);
        }

        return $removed;
    }

    private function prefix(string $key): string
    {
        return $this->prefix . ':' . $key;
    }
}
