<?php

declare(strict_types=1);

namespace HtmlPhp;

use HtmlPhp\Exception\ContainerException;

/**
 * Minimal lazy service container (PSR-11 shaped: `get()`/`has()`).
 *
 * The engine wires its own services through {@see self::withDefaults()};
 * hosts override any of them **before first use** by calling `instance()`
 * or `factory()`:
 *
 * ```php
 * $engine = Engine::create($config, function (Container $c): void {
 *     $c->instance(CacheInterface::class, new RedisCache($redis));
 * });
 * ```
 *
 * Deliberately tiny: no autowiring, no tags, no compilation — a full DI
 * component belongs to the host framework, and this container delegates
 * cleanly to one when present (register the host container as a factory).
 */
final class Container
{
    /** @var array<string, callable(Container): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * Registers a lazy factory. The factory runs at most once; its result is
     * shared (singleton semantics).
     *
     * @param callable(Container): mixed $factory
     */
    public function factory(string $id, callable $factory): void
    {
        unset($this->instances[$id]);
        $this->factories[$id] = $factory;
    }

    /** Registers an already-built instance. */
    public function instance(string $id, mixed $instance): void
    {
        unset($this->factories[$id]);
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new ContainerException("Service '{$id}' is not registered.", ['id' => $id]);
        }

        return $this->instances[$id] = ($this->factories[$id])($this);
    }

    /**
     * Typed convenience wrapper around {@see self::get()}.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function service(string $id): object
    {
        $service = $this->get($id);

        if (!$service instanceof $id) {
            throw new ContainerException(
                "Service '{$id}' resolved to " . get_debug_type($service) . '.',
                ['id' => $id]
            );
        }

        return $service;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_values(array_unique([...array_keys($this->instances), ...array_keys($this->factories)]));
    }

    /** Drops every factory and instance (tests, long-running workers). */
    public function reset(): void
    {
        $this->factories = [];
        $this->instances = [];
    }

    /**
     * Container pre-wired with the full default service graph for `$config`.
     * Every service is lazy: unused services are never instantiated.
     */
    public static function withDefaults(Config $config): self
    {
        $container = new self();
        $container->instance(Config::class, $config);

        $container->factory(Support\ClockInterface::class, static fn(): Support\ClockInterface => new Support\SystemClock());

        $container->factory(Log\LoggerInterface::class, static fn(Container $c): Log\LoggerInterface
            => Log\LoggerFactory::create($c->service(Config::class)->log));

        $container->factory(Diagnostics::class, static fn(Container $c): Diagnostics => new Diagnostics(
            $c->service(Config::class)->limits->maxDiagnostics,
            $c->service(Config::class)->log->forwardDiagnostics ? $c->service(Log\LoggerInterface::class) : null,
            $c->service(Event\Dispatcher::class)
        ));

        $container->factory(Event\Dispatcher::class, static fn(): Event\Dispatcher => new Event\Dispatcher());

        $container->factory(Contracts\CacheInterface::class, static fn(Container $c): Contracts\CacheInterface
            => Cache\CacheFactory::create(
                $c->service(Config::class)->cache,
                $c->service(Diagnostics::class)
            ));

        $container->factory(Html\Escaper::class, static fn(): Html\Escaper => new Html\Escaper());

        $container->factory(I18n\Formats::class, static fn(Container $c): I18n\Formats
            => new I18n\Formats(
                $c->service(Config::class)->i18n,
                $c->service(Support\ClockInterface::class)
            ));

        $container->factory(Template\Filters::class, static fn(Container $c): Template\Filters => new Template\Filters(
            $c->service(Diagnostics::class),
            $c->service(Event\Dispatcher::class),
            $c->service(Html\Escaper::class),
            $c->service(I18n\TranslatorInterface::class),
            $c->service(I18n\Formats::class),
            $c->service(Config::class)->syntax->strict
        ));

        $container->factory(Template\TemplateLocator::class, static fn(Container $c): Template\TemplateLocator
            => new Template\TemplateLocator(
                $c->service(Config::class)->templates,
                $c->service(Config::class)->security
            ));

        $container->factory(I18n\TranslatorInterface::class, static fn(Container $c): I18n\TranslatorInterface
            => I18n\TranslatorFactory::create($c));

        $container->factory(Ssi\Processor::class, static fn(Container $c): Ssi\Processor => new Ssi\Processor(
            $c->service(Config::class),
            $c->service(Diagnostics::class),
            $c->service(Event\Dispatcher::class),
            $c->service(Html\Escaper::class),
            $c->service(Support\ClockInterface::class)
        ));

        $container->factory(Template\Renderer::class, static fn(Container $c): Template\Renderer => new Template\Renderer(
            $c->service(Config::class),
            $c->service(Diagnostics::class),
            $c->service(Event\Dispatcher::class),
            $c->service(Contracts\CacheInterface::class),
            $c->service(Ssi\Processor::class),
            $c->service(Template\Filters::class),
            $c->service(I18n\TranslatorInterface::class),
            $c->service(Html\Escaper::class),
            $c->service(Template\TemplateLocator::class)
        ));

        $container->factory(Template\Linter::class, static fn(Container $c): Template\Linter => new Template\Linter(
            $c->service(Config::class),
            $c->service(Diagnostics::class),
            $c->service(Template\Filters::class),
            $c->service(Template\TemplateLocator::class),
            ($t = $c->service(I18n\TranslatorInterface::class)) instanceof I18n\Translator ? $t : null
        ));

        $container->factory(Engine::class, static fn(Container $c): Engine => new Engine(
            $c->service(Config::class),
            $c
        ));

        return $container;
    }
}
