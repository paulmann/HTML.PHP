<?php

declare(strict_types=1);

namespace HtmlPhp\Event;

/**
 * Priority-ordered event dispatcher (WordPress-hook shaped by design).
 *
 * Two flavours share one registry:
 *  - `dispatch($tag, ...$args)` — fire-and-forget notifications
 *    (`htmlphp.render.before`, `htmlphp.error`, …);
 *  - `filter($tag, $value, ...$args)` — the value travels through every
 *    listener and the last result wins, exactly like `apply_filters()`.
 *
 * The WordPress bridge mirrors every tag onto `do_action`/`apply_filters`,
 * so themes can hook the engine without knowing it exists.
 */
final class Dispatcher
{
    /** @var array<string, array<int, array<int, callable>>> tag => priority => listeners */
    private array $listeners = [];

    /** @var array<string, int> recursion guard per tag */
    private array $running = [];

    private int $sequence = 0;

    /**
     * Registers a listener; returns a closure that removes it again.
     */
    public function on(string $tag, callable $listener, int $priority = 10): \Closure
    {
        $order = $this->sequence++;
        $this->listeners[$tag][$priority][$order] = $listener;

        return function () use ($tag, $priority, $order): void {
            unset($this->listeners[$tag][$priority][$order]);

            if (($this->listeners[$tag][$priority] ?? null) === []) {
                unset($this->listeners[$tag][$priority]);
            }

            if (($this->listeners[$tag] ?? null) === []) {
                unset($this->listeners[$tag]);
            }
        };
    }

    public function off(string $tag, ?int $priority = null): void
    {
        if ($priority === null) {
            unset($this->listeners[$tag]);

            return;
        }

        unset($this->listeners[$tag][$priority]);
    }

    public function has(string $tag): bool
    {
        foreach ($this->listeners[$tag] ?? [] as $bucket) {
            if ($bucket !== []) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return array_keys($this->listeners);
    }

    public function dispatch(string $tag, mixed ...$args): void
    {
        foreach ($this->sorted($tag) as $listener) {
            $listener(...$args);
        }
    }

    /**
     * Runs `$value` through every listener of `$tag` (chainable filters).
     * A listener returning `null` keeps the previous value, which makes
     * "observe-only" filters safe.
     */
    public function filter(string $tag, mixed $value, mixed ...$args): mixed
    {
        if (($this->running[$tag] ?? 0) > 32) {
            return $value; // runaway recursion guard
        }

        $this->running[$tag] = ($this->running[$tag] ?? 0) + 1;

        try {
            foreach ($this->sorted($tag) as $listener) {
                $result = $listener($value, ...$args);

                if ($result !== null) {
                    $value = $result;
                }
            }
        } finally {
            $this->running[$tag]--;
        }

        return $value;
    }

    public function reset(): void
    {
        $this->listeners = [];
        $this->running = [];
    }

    /** @return list<callable> */
    private function sorted(string $tag): array
    {
        $buckets = $this->listeners[$tag] ?? [];

        if ($buckets === []) {
            return [];
        }

        ksort($buckets);

        $sorted = [];

        foreach ($buckets as $listeners) {
            ksort($listeners);

            foreach ($listeners as $listener) {
                $sorted[] = $listener;
            }
        }

        return $sorted;
    }
}
