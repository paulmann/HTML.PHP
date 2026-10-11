<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress;

use HtmlPhp\Contracts\CacheInterface;
use HtmlPhp\Diagnostics;
use HtmlPhp\WordPress\Maintenance\Options;

/**
 * WordPress cache backend.
 *
 * Two tiers: the object cache (`wp_cache_*`, group `htmlphp`) for fast
 * in-request/persistent hits when a drop-in is installed, and transients
 * for guaranteed cross-request persistence on plain installs. Keys are
 * tracked in an option so {@see self::clear()} can actually enumerate
 * them — something neither tier can do on its own.
 *
 * Multisite: options and transients are per-site by default; the group is
 * suffixed with the blog id to keep sites apart in shared object caches.
 */
final class WpCache implements CacheInterface
{
    private const string GROUP_BASE = 'htmlphp';
    private const string REGISTRY_KEY = 'cache_keys';
    private const int REGISTRY_CAP = 1000;

    private string $group;

    public function __construct(
        private readonly string $prefix = 'htmlphp',
        private readonly int $defaultTtl = 0,
        private readonly ?Diagnostics $diagnostics = null,
    ) {
        $blogId = \function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        $this->group = self::GROUP_BASE . ($blogId > 0 ? '_' . $blogId : '');
    }

    public function get(string $key): ?string
    {
        if (!\function_exists('wp_cache_get')) {
            return null;
        }

        $found = false;
        $value = wp_cache_get($this->objectKey($key), $this->group, false, $found);

        if ($found && \is_string($value)) {
            return $value;
        }

        if (\function_exists('get_transient')) {
            $stored = get_transient($this->prefix . ':' . $key);

            if (\is_string($stored)) {
                wp_cache_set($this->objectKey($key), $stored, $this->group);

                return $stored;
            }
        }

        return null;
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        if (!\function_exists('wp_cache_set')) {
            $this->diagnostics?->record(
                Diagnostics::CACHE_IO,
                'WordPress object cache unavailable; entry was not stored.',
                ['key' => $key],
            );

            return false;
        }

        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;

        wp_cache_set($this->objectKey($key), $value, $this->group, $ttl > 0 ? $ttl : 0);

        if (\function_exists('set_transient')) {
            set_transient($this->prefix . ':' . $key, $value, $ttl > 0 ? $ttl : 0);
        }

        $this->register($key);

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function delete(string $key): bool
    {
        if (\function_exists('wp_cache_delete')) {
            wp_cache_delete($this->objectKey($key), $this->group);
        }

        if (\function_exists('delete_transient')) {
            delete_transient($this->prefix . ':' . $key);
        }

        $this->unregister($key);

        return true;
    }

    public function clear(): int
    {
        $keys = Options::getArray(self::REGISTRY_KEY);
        $removed = 0;

        foreach ($keys as $key) {
            if (\is_string($key)) {
                $this->delete($key);
                $removed++;
            }
        }

        Options::set(self::REGISTRY_KEY, []);

        return $removed;
    }

    private function objectKey(string $key): string
    {
        return $this->prefix . ':' . $key;
    }

    private function register(string $key): void
    {
        $keys = Options::getArray(self::REGISTRY_KEY);

        if (\in_array($key, $keys, true)) {
            return;
        }

        $keys[] = $key;

        if (\count($keys) > self::REGISTRY_CAP) {
            $keys = \array_slice($keys, -self::REGISTRY_CAP);
        }

        Options::set(self::REGISTRY_KEY, $keys);
    }

    private function unregister(string $key): void
    {
        $keys = Options::getArray(self::REGISTRY_KEY);
        $keys = array_values(array_filter($keys, static fn($k): bool => $k !== $key));
        Options::set(self::REGISTRY_KEY, $keys);
    }
}
