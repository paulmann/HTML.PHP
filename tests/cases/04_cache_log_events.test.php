<?php

/**
 * 04 — Cache backends, loggers, event dispatcher.
 */

declare(strict_types=1);

use HtmlPhp\Cache\ArrayCache;
use HtmlPhp\Cache\CacheFactory;
use HtmlPhp\Cache\FileCache;
use HtmlPhp\Cache\NullCache;
use HtmlPhp\Cache\Psr16Adapter;
use HtmlPhp\Config\Cache as CacheConfig;
use HtmlPhp\Diagnostics;
use HtmlPhp\Event\Dispatcher;
use HtmlPhp\Log\ArrayLogger;
use HtmlPhp\Log\ErrorLogLogger;
use HtmlPhp\Log\FileLogger;
use HtmlPhp\Log\LoggerFactory;
use HtmlPhp\Log\NullLogger;
use HtmlPhp\Log\Psr3Adapter;
use HtmlPhp\Support\FrozenClock;

group('Cache: array');

test('set/get/has/delete/clear with TTL expiry', function (): void {
    $clock = new FrozenClock(1000);
    $cache = new ArrayCache($clock);

    $cache->set('a', 'A');
    $cache->set('b', 'B', 10);

    assertSame('A', $cache->get('a'));
    assertSame(true, $cache->has('b'));

    $clock->advance(11);

    assertSame(null, $cache->get('b'));
    assertSame('A', $cache->get('a')); // no ttl = never expires

    assertSame(true, $cache->delete('a'));
    assertSame(false, $cache->delete('a'));
    $cache->set('c', 'C');
    assertSame(1, $cache->clear());
    assertSame(null, $cache->get('c'));
});

test('default TTL applies when set() gets 0', function (): void {
    $clock = new FrozenClock(1000);
    $cache = new ArrayCache($clock, 5);
    $cache->set('k', 'v');
    $clock->advance(6);

    assertSame(null, $cache->get('k'));
});

group('Cache: file');

test('round-trips values, honours TTL and cleans up', function (): void {
    $clock = new FrozenClock(2000);
    $dir = fixture_path('cache');
    $cache = new FileCache($dir, 'test', 0, true, $clock);

    assertSame(true, $cache->set('key1', "multi\nline value"));
    assertSame("multi\nline value", $cache->get('key1'));
    assertSame(true, $cache->has('key1'));

    $cache->set('key2', 'x', 5);
    $clock->advance(6);
    assertSame(null, $cache->get('key2'));

    // hardening files exist
    assertSame(true, is_file($dir . '/test/.htaccess'));
    assertSame(true, is_file($dir . '/test/index.html'));
    assertContains('Require all denied', (string) file_get_contents($dir . '/test/.htaccess'));

    assertSame(true, $cache->size() > 0);
    assertSame(true, $cache->delete('key1'));
    assertSame(null, $cache->get('key1'));

    $cache->set('k3', 'v3');
    assertSame(2, $cache->clear()); // k3 plus the expired-but-present key2 file
});

test('uncreatable directory is reported through diagnostics, not exceptions', function (): void {
    // A path *underneath an existing file*: mkdir() fails on every OS
    // (ENOTDIR) and nothing can ever be created — unlike '/proc/...', which
    // on Windows resolves to a creatable C:\proc\... (and used to leak a
    // directory outside the repository).
    $blocker = fixture_write('cache-blocker.txt', 'not a directory');

    $diagnostics = new Diagnostics();
    $cache = new FileCache($blocker . '/sub', 'x', 0, false, null, $diagnostics);

    assertSame(false, $cache->set('k', 'v'));
    assertSame(true, $diagnostics->count(Diagnostics::CACHE_IO) > 0);
});

group('Cache: null, factory, psr16');

test('null cache always misses', function (): void {
    $cache = new NullCache();
    $cache->set('k', 'v');

    assertSame(null, $cache->get('k'));
    assertSame(false, $cache->has('k'));
    assertSame(0, $cache->clear());
});

test('factory maps drivers and falls back safely', function (): void {
    $diagnostics = new Diagnostics();

    assertInstanceOf(NullCache::class, CacheFactory::create(new CacheConfig(), $diagnostics)); // disabled
    assertInstanceOf(ArrayCache::class, CacheFactory::create(new CacheConfig(enabled: true, driver: HtmlPhp\Config\CacheDriver::Array), $diagnostics));
    assertInstanceOf(FileCache::class, CacheFactory::create(new CacheConfig(enabled: true, driver: HtmlPhp\Config\CacheDriver::File, directory: fixture_path('cache2')), $diagnostics));

    // apcu absent in CLI without the extension → array fallback + diagnostic
    if (!HtmlPhp\Cache\ApcuCache::isSupported()) {
        assertInstanceOf(ArrayCache::class, CacheFactory::create(new CacheConfig(enabled: true, driver: HtmlPhp\Config\CacheDriver::Apcu), $diagnostics));
        assertSame(true, $diagnostics->count(Diagnostics::CACHE_IO) > 0);
    }

    assertThrows(
        HtmlPhp\Exception\CacheException::class,
        static fn() => CacheFactory::create(new CacheConfig(enabled: true, driver: HtmlPhp\Config\CacheDriver::File, directory: '')),
    );
});

test('psr16 adapter wraps foreign caches', function (): void {
    $foreign = new class {
        /** @var array<string, mixed> */
        public array $data = [];

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->data[$key] ?? $default;
        }

        public function set(string $key, mixed $value, mixed $ttl = null): bool
        {
            $this->data[$key] = $value;

            return true;
        }

        public function has(string $key): bool
        {
            return isset($this->data[$key]);
        }

        public function delete(string $key): bool
        {
            unset($this->data[$key]);

            return true;
        }

        public function clear(): bool
        {
            $this->data = [];

            return true;
        }
    };

    $cache = new Psr16Adapter($foreign);
    $cache->set('k', 'v');

    assertSame('v', $cache->get('k'));
    assertSame(true, $cache->has('k'));
    assertSame(true, $cache->delete('k'));

    assertThrows(InvalidArgumentException::class, static fn() => new Psr16Adapter(new stdClass()));
});

group('Log');

test('array logger collects, null logger discards', function (): void {
    $logger = new ArrayLogger();
    $logger->error('boom {where}', ['where' => 'here']);
    $logger->debug('x');

    assertSame(2, count($logger->records()));
    assertSame('error: boom {where}', $logger->messages()[0]);

    $null = new NullLogger();
    $null->critical('ignored');
    assertSame(true, true);
});

test('file logger writes gated lines', function (): void {
    $path = fixture_path('logs/app.log');
    $logger = new FileLogger($path, 'chan', 'warning');

    $logger->debug('hidden');
    $logger->error('shown {a}', ['a' => 1]);

    $contents = (string) file_get_contents($path);
    assertNotContains('hidden', $contents);
    assertContains('chan.error: shown 1', $contents); // PSR-3 {placeholder} interpolation
    assertContains('{"a":1}', $contents);
});

test('level gate helper', function (): void {
    assertSame(true, HtmlPhp\Config\Log::levelAccepted('warning', 'error'));
    assertSame(false, HtmlPhp\Config\Log::levelAccepted('error', 'info'));
    assertSame(true, HtmlPhp\Config\Log::levelAccepted('debug', 'debug'));
});

test('psr3 adapter forwards verbatim', function (): void {
    $sink = new ArrayLogger();
    $adapter = new Psr3Adapter($sink);
    $adapter->warning('hi', ['k' => 'v']);

    assertSame('hi', $sink->records()[0]['message']);
    assertThrows(InvalidArgumentException::class, static fn() => new Psr3Adapter(new stdClass()));
});

test('factory builds configured drivers', function (): void {
    assertInstanceOf(NullLogger::class, LoggerFactory::create(new HtmlPhp\Config\Log()));
    assertInstanceOf(ArrayLogger::class, LoggerFactory::create(new HtmlPhp\Config\Log(driver: HtmlPhp\Config\LogDriver::Array)));
    assertInstanceOf(ErrorLogLogger::class, LoggerFactory::create(new HtmlPhp\Config\Log(driver: HtmlPhp\Config\LogDriver::ErrorLog)));
    assertInstanceOf(FileLogger::class, LoggerFactory::create(new HtmlPhp\Config\Log(driver: HtmlPhp\Config\LogDriver::File, path: fixture_path('logs/f2.log'))));
    // file driver without a path degrades to null instead of failing
    assertInstanceOf(NullLogger::class, LoggerFactory::create(new HtmlPhp\Config\Log(driver: HtmlPhp\Config\LogDriver::File)));
});

group('Events');

test('dispatch order follows priorities then registration', function (): void {
    $dispatcher = new Dispatcher();
    $order = [];

    $dispatcher->on('x', static function () use (&$order): void {
        $order[] = 'b10';
    });
    $dispatcher->on('x', static function () use (&$order): void {
        $order[] = 'a5';
    }, 5);
    $dispatcher->on('x', static function () use (&$order): void {
        $order[] = 'c10';
    });

    $dispatcher->dispatch('x');

    assertSame(['a5', 'b10', 'c10'], $order);
});

test('filter chains values and null keeps the previous one', function (): void {
    $dispatcher = new Dispatcher();
    $dispatcher->on('f', static fn(string $v): string => $v . '1');
    $dispatcher->on('f', static fn(string $v): ?string => null);
    $dispatcher->on('f', static fn(string $v): string => $v . '2');

    assertSame('x12', $dispatcher->filter('f', 'x'));
    assertSame('y', $dispatcher->filter('untagged', 'y'));
});

test('unsubscribe closure removes exactly one listener', function (): void {
    $dispatcher = new Dispatcher();
    $calls = 0;
    $off = $dispatcher->on('e', static function () use (&$calls): void {
        ++$calls;
    });
    $dispatcher->dispatch('e');
    $off();
    $dispatcher->dispatch('e');

    assertSame(1, $calls);
    assertSame(false, $dispatcher->has('e'));
});

test('off() clears tag or priority bucket', function (): void {
    $dispatcher = new Dispatcher();
    $dispatcher->on('t', static fn() => null, 5);
    $dispatcher->on('t', static fn() => null, 10);
    $dispatcher->off('t', 5);
    assertSame(true, $dispatcher->has('t'));
    $dispatcher->off('t');
    assertSame(false, $dispatcher->has('t'));
});

test('runaway recursion is capped', function (): void {
    $dispatcher = new Dispatcher();
    $dispatcher->on('r', static function (string $v) use ($dispatcher): string {
        return $dispatcher->filter('r', $v . 'x');
    });

    $result = $dispatcher->filter('r', '');
    assertSame(true, strlen($result) <= 33);
});
