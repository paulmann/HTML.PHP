<?php

declare(strict_types=1);

namespace HtmlPhp\Cache;

use HtmlPhp\Contracts\CacheInterface;

/**
 * Wraps any PSR-16 SimpleCache implementation (Symfony Cache, laminas,
 * WordPress object-cache proxies, …) without requiring `psr/simple-cache`.
 */
final class Psr16Adapter implements CacheInterface
{
    public function __construct(private readonly object $cache, private readonly int $defaultTtl = 0)
    {
        foreach (['get', 'set', 'delete', 'has', 'clear'] as $method) {
            if (!method_exists($this->cache, $method)) {
                throw new \InvalidArgumentException(
                    "Psr16Adapter expects a SimpleCache-shaped object; '{$method}' is missing."
                );
            }
        }
    }

    public function get(string $key): ?string
    {
        $value = $this->cache->get($key, null);

        return is_string($value) ? $value : ($value === null ? null : (string) $value);
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        return (bool) $this->cache->set($key, $value, $ttl > 0 ? $ttl : ($this->defaultTtl > 0 ? $this->defaultTtl : null));
    }

    public function has(string $key): bool
    {
        return (bool) $this->cache->has($key);
    }

    public function delete(string $key): bool
    {
        return (bool) $this->cache->delete($key);
    }

    public function clear(): int
    {
        return $this->cache->clear() ? -1 : 0;
    }
}
