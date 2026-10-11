<?php

declare(strict_types=1);

namespace HtmlPhp\Io;

/**
 * Guarded filesystem reads shared by templates, SSI includes and catalogues.
 */
final class Files
{
    public const int DEFAULT_MAX_BYTES = 8 * 1024 * 1024;

    private function __construct() {}

    /**
     * Reads a whole file with a byte cap.
     *
     * Returns `null` when the file is missing, unreadable or too large —
     * callers decide whether that is an error or a cache miss.
     */
    public static function slurp(string $path, ?int $maxBytes = null): ?string
    {
        if ($path === '' || str_contains($path, "\0") || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $maxBytes ??= self::DEFAULT_MAX_BYTES;
        $size = @filesize($path);

        if ($size !== false && $size > $maxBytes) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /** Lowercase extension without the dot (`''` when there is none). */
    public static function extension(string $path): string
    {
        $base = basename(str_contains($path, "\0") ? '' : $path);
        $dot = strrpos($base, '.');

        return $dot === false || $dot === 0 ? '' : strtolower(substr($base, $dot + 1));
    }

    /** Modification time, or `0` when unavailable. */
    public static function mtime(string $path): int
    {
        $mtime = @filemtime($path);

        return $mtime === false ? 0 : $mtime;
    }

    /**
     * Normalises a path for prefix comparisons: native separators become
     * `/` and, on case-insensitive filesystems, the path is lowercased.
     */
    public static function normalizeForCompare(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        if (PHP_OS_FAMILY === 'Windows' || PHP_OS_FAMILY === 'Darwin') {
            $normalized = strtolower($normalized);
        }

        return $normalized;
    }

    /** True when `$path` is `$root` itself or lives inside `$root`. */
    public static function isInside(string $path, string $root): bool
    {
        $path = self::normalizeForCompare($path);
        $root = self::normalizeForCompare($root);

        if ($root === '') {
            return false;
        }

        return $path === $root || str_starts_with($path, $root . '/');
    }
}
