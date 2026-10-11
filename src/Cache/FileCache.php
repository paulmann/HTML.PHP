<?php

declare(strict_types=1);

namespace HtmlPhp\Cache;

use HtmlPhp\Contracts\CacheInterface;
use HtmlPhp\Diagnostics;
use HtmlPhp\Io\AtomicWriter;
use HtmlPhp\Support\ClockInterface;

/**
 * Filesystem render cache.
 *
 * Layout: `{dir}/{prefix}/{shard}/{sha256}.cache`, payload = one JSON meta
 * line (`{"e":expiry}`) followed by the raw value, so values never pay an
 * escaping round-trip. Writes go through {@see AtomicWriter}; reads verify
 * expiry against the injected clock.
 *
 * When `harden` is on the directory receives a deny-all `.htaccess` and an
 * empty `index.html`, matching WordPress upload-hardening conventions.
 */
final class FileCache implements CacheInterface
{
    private bool $prepared = false;

    public function __construct(
        private readonly string $directory,
        private readonly string $prefix = 'htmlphp',
        private readonly int $defaultTtl = 0,
        private readonly bool $harden = true,
        private readonly ?ClockInterface $clock = null,
        private readonly ?Diagnostics $diagnostics = null
    ) {
    }

    public function get(string $key): ?string
    {
        $file = $this->path($key);

        if (!is_file($file)) {
            return null;
        }

        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $meta = fgets($handle);
            $decoded = is_string($meta) ? json_decode(trim($meta), true) : null;

            if (!is_array($decoded)) {
                return null;
            }

            $expiry = (int) ($decoded['e'] ?? 0);

            if ($expiry !== 0 && $expiry <= $this->now()) {
                return null;
            }

            $value = stream_get_contents($handle);

            return is_string($value) ? $value : null;
        } finally {
            fclose($handle);
        }
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        if (!$this->prepare()) {
            return false;
        }

        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        $meta = json_encode(['e' => $ttl > 0 ? $this->now() + $ttl : 0]) . "\n";
        $path = $this->path($key);
        $shard = dirname($path);

        if (!is_dir($shard) && !@mkdir($shard, 0775, true) && !is_dir($shard)) {
            $this->diagnostics?->record(Diagnostics::CACHE_IO, 'Cannot create cache shard.', ['dir' => $shard]);

            return false;
        }

        $this->sweep();

        try {
            return AtomicWriter::write($path, $meta . $value, 0664);
        } catch (\HtmlPhp\Exception\IoException $error) {
            $this->diagnostics?->record(Diagnostics::CACHE_IO, $error->getMessage(), $error->context);

            return false;
        }
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function delete(string $key): bool
    {
        $file = $this->path($key);

        return is_file($file) && @unlink($file);
    }

    public function clear(): int
    {
        $removed = 0;

        foreach ($this->files() as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /** Directory size in bytes (admin health screen, WP-CLI info). */
    public function size(): int
    {
        $bytes = 0;

        foreach ($this->files() as $file) {
            $bytes += (int) @filesize($file);
        }

        return $bytes;
    }

    public function count(): int
    {
        return iterator_count($this->files());
    }

    /** @return \Generator<int, string> */
    private function files(): \Generator
    {
        $root = $this->root();

        if (!is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $info */
        foreach ($iterator as $info) {
            if ($info->isFile() && $info->getExtension() === 'cache') {
                yield $info->getPathname();
            }
        }
    }

    /**
     * Probabilistic garbage collection: ~1 % of writes sweep expired files
     * so a busy site never needs a cron job, and an idle one pays nothing.
     */
    private function sweep(): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }

        $now = $this->now();

        foreach ($this->files() as $file) {
            $handle = @fopen($file, 'rb');

            if ($handle === false) {
                continue;
            }

            $meta = fgets($handle);
            fclose($handle);
            $decoded = is_string($meta) ? json_decode(trim($meta), true) : null;
            $expiry = is_array($decoded) ? (int) ($decoded['e'] ?? 0) : 0;

            if ($expiry !== 0 && $expiry <= $now) {
                @unlink($file);
            }
        }
    }

    private function path(string $key): string
    {
        $hash = hash('sha256', $key);

        return $this->root() . '/' . substr($hash, 0, 2) . '/' . $hash . '.cache';
    }

    private function root(): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $this->prefix) ?: 'htmlphp';

        return rtrim($this->directory, '/\\') . '/' . $safe;
    }

    private function prepare(): bool
    {
        if ($this->prepared) {
            return true;
        }

        $root = $this->root();

        if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
            $this->diagnostics?->record(Diagnostics::CACHE_IO, 'Cannot create cache directory.', ['dir' => $root]);

            return false;
        }

        if ($this->harden) {
            try {
                AtomicWriter::write($root . '/.htaccess', "Require all denied\nDeny from all\n", 0644);
                AtomicWriter::write($root . '/index.html', '', 0644);
            } catch (\HtmlPhp\Exception\IoException) {
                $this->diagnostics?->record(
                    Diagnostics::CACHE_IO,
                    'Cannot harden cache directory; serve it outside the web root or deny access in your vhost.',
                    ['dir' => $root]
                );
            }
        }

        return $this->prepared = true;
    }

    private function now(): int
    {
        return $this->clock?->now() ?? time();
    }
}
