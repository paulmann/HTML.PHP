<?php

declare(strict_types=1);

namespace HtmlPhp\Contracts;

/**
 * Render-cache backend contract (PSR-16 shaped, strings only).
 *
 * Implementations: {@see \HtmlPhp\Cache\FileCache}, ArrayCache, ApcuCache,
 * NullCache, Psr16Adapter and the WordPress object-cache adapter.
 */
interface CacheInterface
{
    /** Returns the stored value or `null` on miss/expiry. */
    public function get(string $key): ?string;

    /** Stores `$value` for `$ttl` seconds (`0` = backend default). */
    public function set(string $key, string $value, int $ttl = 0): bool;

    public function has(string $key): bool;

    public function delete(string $key): bool;

    /** Removes every entry of this backend; returns how many were dropped. */
    public function clear(): int;
}
