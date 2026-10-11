<?php

declare(strict_types=1);

namespace HtmlPhp\Cache;

use HtmlPhp\Config\Cache as CacheConfig;
use HtmlPhp\Config\CacheDriver;
use HtmlPhp\Contracts\CacheInterface;
use HtmlPhp\Diagnostics;
use HtmlPhp\Exception\CacheException;

/**
 * Instantiates the cache backend described by the configuration, with
 * safe fallbacks (APCu missing → array cache + diagnostic).
 */
final class CacheFactory
{
    private function __construct()
    {
    }

    public static function create(CacheConfig $config, ?Diagnostics $diagnostics = null): CacheInterface
    {
        if (!$config->enabled) {
            return new NullCache();
        }

        return match ($config->driver) {
            CacheDriver::Null => new NullCache(),
            CacheDriver::Array => new ArrayCache(defaultTtl: $config->ttl),
            CacheDriver::File => $config->directory === ''
                ? throw new CacheException('cache.driver "file" requires cache.directory.')
                : new FileCache(
                    $config->directory,
                    $config->prefix,
                    $config->ttl,
                    $config->hardenDirectory,
                    diagnostics: $diagnostics
                ),
            CacheDriver::Apcu => ApcuCache::isSupported()
                ? new ApcuCache($config->prefix, $config->ttl)
                : self::fallback($diagnostics, $config->ttl),
            CacheDriver::WordPress => class_exists('\HtmlPhp\WordPress\WpCache') && function_exists('wp_cache_get')
                ? new \HtmlPhp\WordPress\WpCache($config->prefix, $config->ttl, $diagnostics)
                : self::fallback($diagnostics, $config->ttl),
        };
    }

    private static function fallback(?Diagnostics $diagnostics, int $ttl): CacheInterface
    {
        $diagnostics?->record(
            Diagnostics::CACHE_IO,
            'Configured cache driver unavailable; falling back to an in-process array cache.'
        );

        return new ArrayCache(defaultTtl: $ttl);
    }
}
