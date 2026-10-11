<?php

declare(strict_types=1);

namespace HtmlPhp\Template;

use HtmlPhp\Config\Security as SecurityConfig;
use HtmlPhp\Config\Templates as TemplatesConfig;
use HtmlPhp\Io\PathGuard;

/**
 * Resolves template names to files.
 *
 * Search order: `@namespace/name` roots first, then each configured
 * `templates.paths` root, then (for partials) the directory of the
 * including document. The default extension is appended when the name
 * has none. Everything passes through {@see PathGuard}: absolute paths,
 * traversal outside the roots and denied extensions (`*.php`, `*.env`, …)
 * are rejected — template *data* can never make the engine read an
 * arbitrary file.
 */
final class TemplateLocator
{
    /** @var array<string, PathGuard> per-root guards */
    private array $guards = [];

    /** @var array<string, string|false> memoized name => realpath (false = miss) */
    private array $resolved = [];

    public function __construct(
        private readonly TemplatesConfig $config,
        private readonly SecurityConfig $security
    ) {
    }

    private function guardFor(string $root): PathGuard
    {
        return $this->guards[$root] ??= new PathGuard([$root], $this->security->deniedExtensions, false);
    }

    /**
     * @param string|null $baseDir directory of the including document
     *        (partials may resolve relative to their parent template)
     */
    public function locate(string $name, ?string $baseDir = null): ?string
    {
        $name = trim($name);

        if ($name === '' || str_contains($name, "\0")) {
            return null;
        }

        $key = $name . '|' . ($baseDir ?? '');

        if (array_key_exists($key, $this->resolved)) {
            $cached = $this->resolved[$key];

            return $cached === false ? null : $cached;
        }

        $path = $this->search($name, $baseDir);
        $this->resolved[$key] = $path ?? false;

        return $path;
    }

    private function search(string $name, ?string $baseDir): ?string
    {
        // Absolute references are template-data attacks; refuse them.
        if (str_starts_with($name, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $name) === 1) {
            return null;
        }

        [$namespace, $relative] = $this->splitNamespace($name);

        $roots = $namespace !== null
            ? (isset($this->config->namespaces[$namespace]) ? [$this->config->namespaces[$namespace]] : [])
            : $this->config->paths;

        if ($namespace === null && $baseDir !== null && $baseDir !== '') {
            // Partials resolve relative to the including document first.
            $roots = [$baseDir, ...$roots];
        }

        foreach ($this->candidates($relative) as $candidate) {
            foreach ($roots as $root) {
                $root = (string) $root;
                $resolved = $this->guardFor($root)->resolve($candidate, $root);

                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    /**
     * Name variants to try: verbatim, then with the default extension.
     *
     * @return list<string>
     */
    private function candidates(string $name): array
    {
        $candidates = [$name];

        $extension = $this->config->extension;

        if ($extension !== '' && !str_ends_with($name, $extension)) {
            $candidates[] = $name . $extension;
        }

        return $candidates;
    }

    /** @return array{0: ?string, 1: string} [namespace, relative] */
    private function splitNamespace(string $name): array
    {
        if (str_starts_with($name, '@') && preg_match('~^@([A-Za-z0-9_-]+)/(.+)$~', $name, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return [null, ltrim($name, '@')];
    }

    public function clearCache(): void
    {
        $this->resolved = [];
    }

    /** @return list<string> */
    public function roots(): array
    {
        return $this->config->paths;
    }
}
