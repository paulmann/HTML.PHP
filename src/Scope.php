<?php

declare(strict_types=1);

namespace HtmlPhp;

use HtmlPhp\Support\Str;

/**
 * A named stack of variable scopes used while rendering.
 *
 * Lookups walk the stack top-down (innermost wins), which gives loops and
 * partials natural shadowing semantics. There is deliberately **no**
 * fallback to `$GLOBALS`: a name either exists in some frame or it is
 * missing, and the configured {@see Config\MissingVarPolicy} decides what
 * happens then.
 */
final class Scope
{
    /** Ceiling for dynamic (`[…]`) keys — a lookup key is data, not a document. */
    private const int MAX_KEY_LENGTH = 256;

    /** @var list<array<string, mixed>> */
    private array $frames;

    /**
     * @param array<string, mixed> $root outermost frame
     */
    public function __construct(array $root = [])
    {
        $this->frames = [$root];
    }

    /**
     * Pushes a new innermost frame.
     *
     * @param array<string, mixed> $vars
     */
    public function push(array $vars = []): void
    {
        $this->frames[] = $vars;
    }

    /** Removes the innermost frame (the root frame is never removed). */
    public function pop(): void
    {
        if (\count($this->frames) > 1) {
            array_pop($this->frames);
        }
    }

    /**
     * Runs `$callback` with an extra frame on the stack.
     *
     * @param array<string, mixed> $vars
     */
    public function with(array $vars, callable $callback): mixed
    {
        $this->push($vars);

        try {
            return $callback($this);
        } finally {
            $this->pop();
        }
    }

    public function depth(): int
    {
        return \count($this->frames);
    }

    /**
     * Finds a name in the innermost frame that defines it.
     * Dot notation (`user.address.city`) walks arrays, objects and
     * ArrayAccess. Returns `[found, value]`.
     *
     * @return array{0: bool, 1: mixed}
     */
    public function find(string $name): array
    {
        if ($name === '') {
            return [false, null];
        }

        // Fast path: plain key in any frame, innermost first.
        if (!str_contains($name, '.')) {
            for ($i = \count($this->frames) - 1; $i >= 0; $i--) {
                if (\array_key_exists($name, $this->frames[$i])) {
                    return [true, $this->frames[$i][$name]];
                }
            }

            return [false, null];
        }

        [$head, $rest] = explode('.', $name, 2);

        for ($i = \count($this->frames) - 1; $i >= 0; $i--) {
            if (\array_key_exists($head, $this->frames[$i])) {
                $value = $this->frames[$i][$head];
                $found = self::step($value, $rest);

                if ($found[0]) {
                    return $found;
                }
            }
        }

        return [false, null];
    }

    public function has(string $name): bool
    {
        return $this->find($name)[0];
    }

    public function get(string $name, mixed $default = null): mixed
    {
        [$found, $value] = $this->find($name);

        return $found ? $value : $default;
    }

    /**
     * Writes into the innermost frame (used by `<!--@Set-->` and loop vars).
     */
    public function set(string $name, mixed $value): void
    {
        $last = \count($this->frames) - 1;
        $this->frames[$last][$name] = $value;
    }

    /**
     * Merged view of all frames (innermost wins) — used by partials that
     * inherit the caller scope and by `#printenv`-style debugging.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $merged = [];

        foreach ($this->frames as $frame) {
            $merged = array_merge($merged, $frame);
        }

        return $merged;
    }

    /**
     * Walks a dotted path (`a.b.c`) through arrays, `ArrayAccess`, objects
     * and public properties — the shared accessor for `%{user.name}` style
     * lookups and for expression index/property chains.
     *
     * Deliberately conservative: only *public* properties and zero-argument
     * methods (including `getX()`/`isX()`/`hasX()` accessors) are reachable,
     * magic `__get` aside. There is no way to write, no method arguments and
     * no static/class-constant access, so a template can read a DTO but can
     * never invoke behaviour on it.
     *
     * @return array{0: bool, 1: mixed} `[found, value]`
     */
    public static function step(mixed $value, string $path): array
    {
        foreach (explode('.', $path) as $segment) {
            if ($segment === '') {
                return [false, null];
            }

            if (\is_array($value)) {
                if (!\array_key_exists($segment, $value)) {
                    return [false, null];
                }
                $value = $value[$segment];
                continue;
            }

            if ($value instanceof \ArrayAccess) {
                if (!$value->offsetExists($segment)) {
                    return [false, null];
                }
                $value = $value->offsetGet($segment);
                continue;
            }

            if (\is_object($value)) {
                if (isset($value->{$segment})) {
                    $value = $value->{$segment};
                    continue;
                }

                if (method_exists($value, $segment)) {
                    $value = $value->{$segment}();
                    continue;
                }

                // Support getX()/isX() accessors used by many DTOs.
                foreach (['get', 'is', 'has'] as $prefix) {
                    $method = $prefix . ucfirst($segment);
                    if (method_exists($value, $method)) {
                        $value = $value->{$method}();
                        continue 2;
                    }
                }
            }

            return [false, null];
        }

        return [true, $value];
    }

    /**
     * Reads one element out of a value by an *arbitrary* key — the
     * `name[expr]` primitive behind nested/dynamic variable access.
     *
     * Unlike {@see self::step()}, the key is computed at render time from
     * template data, so it is validated hard: only `int` and `string` keys
     * are honoured (anything else — including arrays, objects and `null` —
     * yields "not found"), keys are length-capped, and only arrays,
     * `ArrayAccess` and strings are indexable. Plain objects are **not**
     * reachable by dynamic key: property access requires a literal dotted
     * path, so data flowing in from a request can never be turned into a
     * property/method name.
     *
     * @return array{0: bool, 1: mixed} `[found, value]`
     */
    public static function index(mixed $value, mixed $key): array
    {
        if (\is_int($key)) {
            // Fast path below.
        } elseif (\is_string($key)) {
            if ($key === '' || \strlen($key) > self::MAX_KEY_LENGTH || str_contains($key, "\0")) {
                return [false, null];
            }
        } else {
            return [false, null];
        }

        if (\is_string($value)) {
            if (!\is_int($key) && !self::isIntegerString($key)) {
                return [false, null];
            }

            $offset = (int) $key;
            $length = Str::length($value);

            if ($offset < 0) {
                $offset += $length;
            }

            return $offset >= 0 && $offset < $length
                ? [true, Str::substr($value, $offset, 1)]
                : [false, null];
        }

        if (\is_array($value)) {
            return \array_key_exists($key, $value) ? [true, $value[$key]] : [false, null];
        }

        if ($value instanceof \ArrayAccess) {
            return $value->offsetExists($key) ? [true, $value->offsetGet($key)] : [false, null];
        }

        return [false, null];
    }

    private static function isIntegerString(string $value): bool
    {
        return preg_match('/^-?\d+$/', $value) === 1;
    }
}
