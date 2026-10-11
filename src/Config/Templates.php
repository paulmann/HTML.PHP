<?php

declare(strict_types=1);

namespace HtmlPhp\Config;

use HtmlPhp\Config;

/**
 * Template discovery: search paths, `@namespace` roots and the default
 * file extension appended to extension-less names.
 */
final readonly class Templates
{
    /**
     * @param list<string> $paths ordered template roots (first match wins)
     * @param array<string, string> $namespaces `@name` => root directory
     */
    public function __construct(
        public array $paths = [],
        public array $namespaces = [],
        public string $extension = '.html',
        public bool $cacheFileMTimes = true
    ) {
        foreach ($this->paths as $path) {
            if (!is_string($path) || $path === '') {
                throw new \HtmlPhp\Exception\ConfigException('templates.paths must contain non-empty strings.');
            }
        }
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $paths = Config::arrayValue($config, 'paths', []);
        $namespaces = [];

        foreach (Config::arrayValue($config, 'namespaces', []) as $alias => $dir) {
            $alias = ltrim((string) $alias, '@');

            if ($alias !== '') {
                $namespaces[$alias] = (string) $dir;
            }
        }

        return new self(
            paths: array_values(array_map(strval(...), $paths)),
            namespaces: $namespaces,
            extension: (string) Config::value($config, 'extension', '.html'),
            cacheFileMTimes: Config::bool($config, 'cache_file_mtimes', true)
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'paths' => $this->paths,
            'namespaces' => $this->namespaces,
            'extension' => $this->extension,
            'cache_file_mtimes' => $this->cacheFileMTimes,
        ];
    }

    public const array KEYS = ['paths', 'namespaces', 'extension', 'cache_file_mtimes'];
}
