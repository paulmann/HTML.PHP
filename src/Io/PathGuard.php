<?php

declare(strict_types=1);

namespace HtmlPhp\Io;

use HtmlPhp\Config\Security as SecurityConfig;

/**
 * Path resolution with containment — the single choke point every include
 * in the library goes through (SSI `#include`, `@Partial`, `@Extends`,
 * catalogue files).
 *
 * A reference is accepted only when, after `realpath()` (which collapses
 * `..` segments *and* resolves symlinks), it lives inside one of the
 * configured roots and its extension is not on the deny list. The deny
 * list stops both script execution leaks (`*.php`, `*.phar`) and source
 * disclosure (`*.env`, `*.sql`, `wp-config.php`, `.htaccess`, …).
 */
final class PathGuard
{
    /** @var list<string> realpath'd roots */
    private readonly array $roots;

    /** @var array<string, true> */
    private readonly array $deniedExtensions;

    /**
     * @param list<string> $roots directories includes may resolve into
     * @param list<string> $deniedExtensions
     */
    public function __construct(
        array $roots,
        array $deniedExtensions = SecurityConfig::DENIED_EXTENSIONS_DEFAULT,
        private readonly bool $allowParentPaths = false,
    ) {
        $resolved = [];

        foreach ($roots as $root) {
            if (!\is_string($root) || $root === '') {
                continue;
            }

            $real = realpath($root);
            $resolved[] = $real === false ? Files::normalizeForCompare(rtrim($root, '/\\')) : $real;
        }

        $this->roots = array_values(array_unique($resolved));

        $denied = [];
        foreach ($deniedExtensions as $extension) {
            $extension = strtolower(ltrim((string) $extension, '.'));

            if ($extension !== '') {
                $denied[$extension] = true;
            }
        }
        $this->deniedExtensions = $denied;
    }

    /**
     * Resolves `$reference` against `$baseDir` (falling back to each root)
     * and validates it. Returns the realpath'd file or `null` when the
     * reference is rejected or does not exist.
     */
    public function resolve(string $reference, ?string $baseDir = null): ?string
    {
        if ($reference === '' || str_contains($reference, "\0")) {
            return null;
        }

        if (!$this->extensionAllowed($reference)) {
            return null;
        }

        $candidates = [];

        if ($this->isAbsolute($reference)) {
            $candidates[] = $reference;
        } else {
            if ($baseDir !== null && $baseDir !== '') {
                $candidates[] = rtrim($baseDir, '/\\') . '/' . $reference;
            }

            foreach ($this->roots as $root) {
                $candidates[] = $root . '/' . $reference;
            }
        }

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);

            if ($real === false || !is_file($real)) {
                continue;
            }

            if (!$this->extensionAllowed($real)) {
                return null; // never fall through to another root once denied
            }

            if ($this->contained($real)) {
                return $real;
            }

            if ($this->allowParentPaths) {
                return $real;
            }
        }

        return null;
    }

    /** True when the resolved path sits inside one of the roots. */
    public function contained(string $realPath): bool
    {
        foreach ($this->roots as $root) {
            if (Files::isInside($realPath, $root)) {
                return true;
            }
        }

        return false;
    }

    /** Extension deny-list check (`htaccess`-style dotfiles are denied too). */
    public function extensionAllowed(string $path): bool
    {
        $base = basename(str_replace('\\', '/', $path));

        if (str_starts_with($base, '.') && $base !== '.' && $base !== '..') {
            return false; // .htaccess, .env, .git …
        }

        $extension = Files::extension($base);

        return $extension === '' || !isset($this->deniedExtensions[$extension]);
    }

    /** @return list<string> */
    public function roots(): array
    {
        return $this->roots;
    }

    /** @return list<string> */
    public function deniedExtensionsList(): array
    {
        return array_keys($this->deniedExtensions);
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1
            || str_starts_with($path, '\\\\');
    }
}
