<?php

declare(strict_types=1);

namespace HtmlPhp;

use HtmlPhp\Contracts\CacheInterface;
use HtmlPhp\Event\Dispatcher;
use HtmlPhp\I18n\Translator;
use HtmlPhp\I18n\TranslatorInterface;
use HtmlPhp\Log\LoggerInterface;
use HtmlPhp\Ssi\DocumentInfo;
use HtmlPhp\Ssi\Processor as SsiProcessor;
use HtmlPhp\Support\ClockInterface;
use HtmlPhp\Template\Filters;
use HtmlPhp\Template\Linter;
use HtmlPhp\Template\LintIssue;
use HtmlPhp\Template\Renderer;
use HtmlPhp\Template\RenderOptions;
use HtmlPhp\Template\RenderStats;
use HtmlPhp\Template\TemplateLocator;

/**
 * The public facade — everything a host application needs.
 *
 * ```php
 * $engine = Engine::create([
 *     'templates' => ['paths' => [__DIR__ . '/tpl']],
 *     'i18n'      => ['locale' => 'de', 'paths' => [__DIR__ . '/lang']],
 * ]);
 *
 * echo $engine->render('page.html', ['title' => 'Hello']);
 * ```
 *
 * Construction is explicit: a {@see Config} value object in, a wired
 * {@see Container} out. No globals, no statics, no hidden state — multiple
 * engines with different configurations coexist in one process.
 */
final class Engine
{
    private readonly Container $container;
    private ?RenderStats $lastStats = null;

    public function __construct(
        private readonly Config $config,
        ?Container $container = null,
    ) {
        $this->container = $container ?? Container::withDefaults($config);
        $this->container->instance(self::class, $this);

        $this->events()->on('htmlphp.rendered', function (RenderStats $stats): void {
            $this->lastStats = $stats;
        });
    }

    /**
     * Builds an engine from an array or Config, running `$setup` against the
     * container **before** any service is instantiated (override anything).
     *
     * @param Config|array<string, mixed> $config
     * @param (callable(Container): void)|null $setup
     */
    public static function create(Config|array $config = [], ?callable $setup = null): self
    {
        $config = $config instanceof Config ? $config : Config::fromArray($config);
        $container = Container::withDefaults($config);

        if ($setup !== null) {
            $setup($container);
        }

        return new self($config, $container);
    }

    // --------------------------------------------------------------- render

    /**
     * Renders a template located through the configured paths/namespaces.
     *
     * @param array<string, mixed> $vars
     */
    public function render(string $name, array $vars = [], ?RenderOptions $options = null): string
    {
        $path = $this->locator()->locate($name);

        if ($path === null) {
            throw new Exception\TemplateNotFoundException(
                "Template '{$name}' could not be located.",
                ['template' => $name, 'roots' => $this->locator()->roots()],
            );
        }

        return $this->renderer()->renderFile($path, $vars, $options);
    }

    /**
     * Renders a file by path (host-trusted; bypasses the locator but still
     * passes every size/security guard).
     *
     * @param array<string, mixed> $vars
     */
    public function renderFile(string $path, array $vars = [], ?RenderOptions $options = null): string
    {
        return $this->renderer()->renderFile($path, $vars, $options);
    }

    /**
     * Renders a template given as a string (database content, WP options,
     * ad-hoc fragments).
     *
     * @param array<string, mixed> $vars
     */
    public function renderString(string $source, array $vars = [], ?RenderOptions $options = null): string
    {
        return $this->renderer()->render($source, $vars, $options);
    }

    /**
     * Renders and echoes.
     *
     * @param array<string, mixed> $vars
     */
    public function display(string $name, array $vars = [], ?RenderOptions $options = null): void
    {
        echo $this->render($name, $vars, $options);
    }

    /** @param array<string, mixed> $vars */
    public function displayString(string $source, array $vars = [], ?RenderOptions $options = null): void
    {
        echo $this->renderString($source, $vars, $options);
    }

    // ------------------------------------------------------------------ SSI

    /**
     * Runs the SSI pass over arbitrary content (post bodies, option values,
     * legacy pages) — the public successor of v3's `html_ssi()`.
     */
    public function processSsi(string $content, ?DocumentInfo $document = null): string
    {
        return $this->ssi()->process($content, $document);
    }

    /**
     * The built-in SSI variable map for a document (successor of
     * `html_ssi_variables()`).
     *
     * @return array<string, string>
     */
    public function ssiVariables(?DocumentInfo $document = null): array
    {
        return $this->ssi()->variables($document);
    }

    // ----------------------------------------------------------------- i18n

    /** @param array<array-key, mixed> $args */
    public function t(string $msgid, array $args = [], ?string $domain = null): string
    {
        return $this->translator()->t($msgid, $args, $domain);
    }

    /** @param array<array-key, mixed> $args */
    public function n(string $singular, string $plural, int $count, array $args = [], ?string $domain = null): string
    {
        return $this->translator()->n($singular, $plural, $count, $args, $domain);
    }

    /** @param array<array-key, mixed> $args */
    public function x(string $msgid, string $context, array $args = [], ?string $domain = null): string
    {
        return $this->translator()->x($msgid, $context, $args, $domain);
    }

    /** @param array<array-key, mixed> $args */
    public function nx(string $singular, string $plural, int $count, string $context, array $args = [], ?string $domain = null): string
    {
        return $this->translator()->nx($singular, $plural, $count, $context, $args, $domain);
    }

    public function setLocale(string $locale): void
    {
        $this->translator()->setLocale($locale);
    }

    public function locale(): string
    {
        return $this->translator()->locale();
    }

    /**
     * Loads catalogues for a locale from the configured `i18n.paths`.
     *
     * @return list<string> loaded files
     */
    public function loadTranslations(string $locale, ?string $domain = null): array
    {
        $translator = $this->translator();

        if (!$translator instanceof Translator) {
            return [];
        }

        return $translator->loadConfigured($locale, $domain ?? $this->config->i18n->domain);
    }

    // ----------------------------------------------------------------- lint

    /** @return list<LintIssue> */
    public function lintString(string $source, string $origin = '-'): array
    {
        return $this->linter()->lint($source, $origin);
    }

    /** @return list<LintIssue> */
    public function lint(string $nameOrPath): array
    {
        $path = is_file($nameOrPath) ? $nameOrPath : $this->locator()->locate($nameOrPath);

        if ($path === null) {
            return [new LintIssue('error', 'template-unreadable', "Template '{$nameOrPath}' could not be located.", $nameOrPath)];
        }

        return $this->linter()->lintFile($path);
    }

    /** @return array<string, list<LintIssue>> */
    public function lintDirectory(string $directory): array
    {
        return $this->linter()->lintDirectory($directory);
    }

    // --------------------------------------------------------------- services

    public function config(): Config
    {
        return $this->config;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function renderer(): Renderer
    {
        return $this->container->service(Renderer::class);
    }

    public function locator(): TemplateLocator
    {
        return $this->container->service(TemplateLocator::class);
    }

    public function ssi(): SsiProcessor
    {
        return $this->container->service(SsiProcessor::class);
    }

    public function translator(): TranslatorInterface
    {
        return $this->container->service(TranslatorInterface::class);
    }

    public function filters(): Filters
    {
        return $this->container->service(Filters::class);
    }

    public function events(): Dispatcher
    {
        return $this->container->service(Dispatcher::class);
    }

    public function cache(): CacheInterface
    {
        return $this->container->service(CacheInterface::class);
    }

    public function logger(): LoggerInterface
    {
        return $this->container->service(LoggerInterface::class);
    }

    public function diagnostics(): Diagnostics
    {
        return $this->container->service(Diagnostics::class);
    }

    public function clock(): ClockInterface
    {
        return $this->container->service(ClockInterface::class);
    }

    public function linter(): Linter
    {
        return $this->container->service(Linter::class);
    }

    public function escaper(): Html\Escaper
    {
        return $this->container->service(Html\Escaper::class);
    }

    /** Stats of the most recent render (null before the first one). */
    public function stats(): ?RenderStats
    {
        return $this->lastStats;
    }

    /** Purges the render cache and drops memoized parse trees. */
    public function clearCache(): int
    {
        $this->renderer()->clearParsed();
        Template\Expression::reset();

        return $this->cache()->clear();
    }

    public function version(): string
    {
        return Version::banner();
    }
}
