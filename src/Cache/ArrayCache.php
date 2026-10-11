<?php

declare(strict_types=1);

namespace HtmlPhp\Cache;

use HtmlPhp\Contracts\CacheInterface;
use HtmlPhp\Support\ClockInterface;

/**
 * Per-process cache. Useful for CLI runs, tests and as a first tier in
 * front of slower backends.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{0: string, 1: int}> value + expiry (0 = never) */
    private array $entries = [];

    public function __construct(private readonly ?ClockInterface $clock = null, private readonly int $defaultTtl = 0)
    {
    }

    public function get(string $key): ?string
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry[1] !== 0 && $entry[1] <= $this->now()) {
            unset($this->entries[$key]);

            return null;
        }

        return $entry[0];
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        $this->entries[$key] = [$value, $ttl > 0 ? $this->now() + $ttl : 0];

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function delete(string $key): bool
    {
        $existed = isset($this->entries[$key]);
        unset($this->entries[$key]);

        return $existed;
    }

    public function clear(): int
    {
        $count = count($this->entries);
        $this->entries = [];

        return $count;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    private function now(): int
    {
        return $this->clock?->now() ?? time();
    }
}
