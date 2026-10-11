<?php

declare(strict_types=1);

namespace HtmlPhp\Cache;

use HtmlPhp\Contracts\CacheInterface;

/** Always-miss cache; disables caching without `null` checks at call sites. */
final class NullCache implements CacheInterface
{
    public function get(string $key): ?string
    {
        return null;
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function delete(string $key): bool
    {
        return false;
    }

    public function clear(): int
    {
        return 0;
    }
}
