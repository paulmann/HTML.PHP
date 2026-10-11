<?php

declare(strict_types=1);

namespace HtmlPhp\Support;

use HtmlPhp\Exception\RegexException;

/**
 * PCRE wrappers that never return `null`.
 *
 * A failed match is a template bug or a ReDoS attempt; both surface as
 * {@see RegexException} instead of a silent `null` that callers forget to
 * check. `match()` is the exception: it reports "no match" and lets the
 * caller decide, because user-supplied data legitimately fails to match.
 */
final class Pcre
{
    private function __construct()
    {
    }

    /**
     * `preg_replace_callback()` that throws on PCRE failure.
     *
     * @param callable(array<array-key, string|null>): string $callback
     */
    public static function replaceCallback(string $pattern, callable $callback, string $subject, int $limit = -1): string
    {
        $result = preg_replace_callback($pattern, $callback, $subject, $limit);

        return $result ?? throw self::failure($pattern);
    }

    /** `preg_replace()` that throws on PCRE failure. */
    public static function replace(string $pattern, string $replacement, string $subject, int $limit = -1): string
    {
        $result = preg_replace($pattern, $replacement, $subject, $limit);

        return $result ?? throw self::failure($pattern);
    }

    /**
     * `preg_match()` where a PCRE error means "no match" (never `false`).
     *
     * @param array<array-key, string|null> $matches
     */
    public static function match(string $pattern, string $subject, array &$matches = []): bool
    {
        $result = @preg_match($pattern, $subject, $matches);

        if ($result === false) {
            $matches = [];

            return false;
        }

        return $result === 1;
    }

    /**
     * `preg_match_all()` where a PCRE error means "zero matches".
     *
     * @param array<array-key, array<array-key, string|null>> $matches
     */
    public static function matchAll(string $pattern, string $subject, array &$matches = []): int
    {
        $result = @preg_match_all($pattern, $subject, $matches);

        if ($result === false) {
            $matches = [];

            return 0;
        }

        return $result;
    }

    /**
     * Applies a Unicode pattern and retries with a byte-safe pattern when
     * the subject is not valid UTF-8 (legacy normalize()/cleanHtml() input).
     */
    public static function replaceUnicode(
        string $unicodePattern,
        string $bytePattern,
        string $replacement,
        string $subject
    ): string {
        $result = preg_replace($unicodePattern, $replacement, $subject);

        if ($result !== null) {
            return $result;
        }

        $result = preg_replace($bytePattern, $replacement, $subject);

        return $result ?? $subject;
    }

    /**
     * `preg_split()` that throws on PCRE failure.
     *
     * @return list<string>
     */
    public static function split(string $pattern, string $subject, int $limit = -1, int $flags = 0): array
    {
        $result = preg_split($pattern, $subject, $limit, $flags);

        return $result === false ? throw self::failure($pattern) : $result;
    }

    /** True when the pattern compiles (used by `lint`). */
    public static function isValid(string $pattern): bool
    {
        return @preg_match($pattern, '') !== false;
    }

    private static function failure(string $pattern): RegexException
    {
        return new RegexException(
            'Regular expression failed: ' . preg_last_error_msg(),
            ['pattern' => Pcre::safePattern($pattern)]
        );
    }

    private static function safePattern(string $pattern): string
    {
        return Str::length($pattern) > 200 ? Str::substr($pattern, 0, 200) . '…' : $pattern;
    }
}
