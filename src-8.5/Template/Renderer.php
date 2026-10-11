<?php

declare(strict_types=1);

namespace HtmlPhp\Template;

use HtmlPhp\Config;
use HtmlPhp\Config\MissingVarPolicy;
use HtmlPhp\Contracts\CacheInterface;
use HtmlPhp\Diagnostics;
use HtmlPhp\Event\Dispatcher;
use HtmlPhp\Exception\TemplateException;
use HtmlPhp\Exception\TemplateNotFoundException;
use HtmlPhp\Html\Escaper;
use HtmlPhp\I18n\TranslatorInterface;
use HtmlPhp\Io\Files;
use HtmlPhp\Scope;
use HtmlPhp\Ssi\DocumentInfo;
use HtmlPhp\Ssi\Processor;
use HtmlPhp\Version;

/**
 * The render pipeline (PHP 8.5 edition overlay: pipe operator in render(),
 * array_find() in findExtends(), #[\NoDiscard] annotations).
 *
 * ```
 * source → parse (memoized) → layout inheritance (@Extends/@Section)
 *        → tree render (loops/ifs/partials/set/trans + %{} substitution)
 *        → SSI pass (mod_include directives, incl. from partials)
 *        → optional re-substitution pass (SSI output containing %{} tags)
 *        → 'htmlphp.render' filter → output cache → stats/events
 * ```
 *
 * Order rationale: the template language runs first so SSI sees the final
 * document (Apache semantics); one guarded re-substitution pass keeps the
 * v3 ability of `#include`d fragments carrying template tags.
 */
final class Renderer
{
    private readonly Substitution $substitution;
    private readonly TreeRenderer $tree;
    private readonly TagParser $parser;

    /** @var array<string, list<Node>> parse memo: sha1(source) => nodes */
    private array $parsed = [];

    public function __construct(
        private readonly Config $config,
        private readonly Diagnostics $diagnostics,
        private readonly Dispatcher $events,
        private readonly CacheInterface $cache,
        private readonly Processor $ssi,
        private readonly Filters $filters,
        private readonly TranslatorInterface $translator,
        private readonly Escaper $escaper,
        private readonly TemplateLocator $locator
    ) {
        $this->substitution = new Substitution(
            $config->syntax,
            $filters,
            $escaper,
            $translator,
            $diagnostics
        );

        $this->parser = new TagParser();
        $this->tree = new TreeRenderer(
            $config,
            $diagnostics,
            $this->substitution,
            $locator,
            $this,
            $translator
        );
    }

    public function escaper(): Escaper
    {
        return $this->escaper;
    }

    public function filters(): Filters
    {
        return $this->filters;
    }

    public function locator(): TemplateLocator
    {
        return $this->locator;
    }

    // ------------------------------------------------------------------ API

    /**
     * Renders a template **string**.
     *
     * @param array<string, mixed> $vars
     */
    #[\NoDiscard('Rendering without using the result is always a bug.')]
    public function render(string $source, array $vars = [], ?RenderOptions $options = null, string $origin = 'string'): string
    {
        $options ??= new RenderOptions();
        $stats = new RenderStats();
        $stats->start($origin);

        $maxBytes = $this->config->limits->maxTemplateBytes;

        if (strlen($source) > $maxBytes) {
            throw new TemplateException(
                'Template exceeds the configured size limit.',
                ['bytes' => strlen($source), 'limit' => $maxBytes]
            );
        }

        $useCache = $options->cache ?? $this->config->cache->enabled;
        $ttl = $options->ttl ?? $this->config->cache->ttl;
        $cacheKey = $useCache ? $this->cacheKey($source, $vars, $options) : '';

        if ($useCache && $cacheKey !== '') {
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                $stats->cacheHit = true;
                $stats->stop(strlen($cached));
                $this->events->dispatch('htmlphp.rendered', $stats);

                return $cached;
            }
        }

        // PHP 8.5 pipe operator: the pipeline reads like the docs.
        $applyRenderFilter = fn(string $text): string => ($filtered = $this->events->filter('htmlphp.render', $text, $origin, $vars)) !== null && is_string($filtered)
            ? $filtered
            : $text;

        $finalize = function (string $text) use ($useCache, $cacheKey, $ttl, $stats): string {
            if ($useCache && $cacheKey !== '') {
                $this->cache->set($cacheKey, $text, $ttl);
            }

            $stats->stop(strlen($text));
            $this->events->dispatch('htmlphp.rendered', $stats);

            return $text;
        };

        return $this->renderUncached($source, $vars, $options, $origin, $stats)
            |> $applyRenderFilter
            |> $finalize;
    }

    /**
     * Reads (size-guarded) and renders a template **file**. The path anchors
     * relative SSI includes and becomes the partial-resolution base.
     *
     * @param array<string, mixed> $vars
     */
    #[\NoDiscard]
    public function renderFile(string $path, array $vars = [], ?RenderOptions $options = null): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new TemplateException('The template file path is invalid.', ['path' => $path]);
        }

        $contents = Files::slurp($path, $this->config->limits->maxTemplateBytes);

        if ($contents === null) {
            throw new TemplateNotFoundException(
                "Template file '{$path}' is not readable or exceeds the size limit.",
                ['path' => $path]
            );
        }

        $options ??= new RenderOptions(file: $path);

        if ($options->file === null) {
            $options = new RenderOptions(
                file: $path,
                root: $options->root,
                request: $options->request,
                ssi: $options->ssi,
                structure: $options->structure,
                autoEscape: $options->autoEscape,
                missingVar: $options->missingVar,
                cache: $options->cache,
                ttl: $options->ttl,
                locale: $options->locale,
                ssiVars: $options->ssiVars
            );
        }

        return $this->render($contents, $vars, $options, $path);
    }

    /**
     * Renders a located partial inside a parent render (structure +
     * substitution only; SSI runs once over the assembled document).
     *
     * @param array<string, mixed> $vars
     * @internal used by {@see TreeRenderer}
     */
    public function renderPartial(string $path, array $vars, RenderContext $rc, bool $isolated = false): string
    {
        if ($rc->extendsDepth >= $this->config->limits->maxBlockDepth) {
            $this->diagnostics->record(
                Diagnostics::LOOP_GUARD,
                'Partial/layout nesting is too deep; skipped.',
                ['path' => $path]
            );

            return '';
        }

        $contents = Files::slurp($path, $this->config->limits->maxIncludeBytes);

        if ($contents === null) {
            throw new TemplateNotFoundException(
                "Partial template '{$path}' could not be read.",
                ['path' => $path]
            );
        }

        $nodes = $this->parseCached($contents, $path);
        $rc->stats->trackTemplate($path);

        $previousFile = $rc->currentFile;
        $rc->currentFile = $path;
        $rc->extendsDepth++;

        // Partials with their own @Extends are legal: run the same
        // inheritance dance on a nested context.
        $hasExtends = $this->findExtends($nodes) !== null;

        try {
            if ($hasExtends || $isolated) {
                $subRc = new RenderContext(
                    new Scope($vars),
                    $rc->stats,
                    $this->diagnostics,
                    $rc->autoEscape,
                    $rc->missingVar,
                    $rc->structure
                );
                $subRc->currentFile = $path;

                return $hasExtends
                    ? $this->renderWithInheritance($nodes, $subRc, $path, $vars)
                    : $this->tree->render($nodes, $subRc);
            }

            $rc->scope->push($vars);

            try {
                return $this->tree->render($nodes, $rc);
            } finally {
                $rc->scope->pop();
            }
        } finally {
            $rc->currentFile = $previousFile;
            $rc->extendsDepth--;
        }
    }

    // -------------------------------------------------------------- pipeline

    /**
     * @param array<string, mixed> $vars
     */
    private function renderUncached(
        string $source,
        array $vars,
        RenderOptions $options,
        string $origin,
        RenderStats $stats
    ): string {
        $previousLocale = null;

        if ($options->locale !== null && $options->locale !== '') {
            $previousLocale = $this->translator->locale();
            $this->translator->setLocale($options->locale);
        }

        try {
            $scope = new Scope($vars);
            $rc = new RenderContext(
                $scope,
                $stats,
                $this->diagnostics,
                $options->autoEscape ?? $this->config->syntax->autoEscape,
                $options->missingVar ?? $this->config->syntax->missingVar,
                $options->structure ?? true
            );
            $rc->currentFile = $options->file ?? (is_file($origin) ? $origin : null);

            $this->events->dispatch('htmlphp.render.before', $origin, $vars, $rc);

            if (!$rc->structure) {
                // Structure processing disabled: substitution over raw source.
                $text = $this->substitution->process($source, $scope, $rc->autoEscape, $rc->missingVar);
            } else {
                $nodes = $this->parseCached($source, $origin);
                $text = $this->renderWithInheritance($nodes, $rc, $origin, $vars);
            }

            $text = $this->runSsi($text, $vars, $options, $scope, $rc);

            $this->events->dispatch('htmlphp.render.after', $origin, $text);

            return $text;
        } finally {
            if ($previousLocale !== null) {
                $this->translator->setLocale($previousLocale);
            }
        }
    }

    /**
     * @param list<Node> $nodes
     * @param array<string, mixed> $vars
     */
    private function renderWithInheritance(array $nodes, RenderContext $rc, string $origin, array $vars): string
    {
        $extends = $this->findExtends($nodes);

        if ($extends === null) {
            return $this->tree->render($nodes, $rc);
        }

        // 1. Collect this template's section overrides.
        $rc->collectMode = true;
        $rc->extendsTemplate = $extends;

        try {
            $this->tree->render($nodes, $rc);
        } finally {
            $rc->collectMode = false;
        }

        $overrides = $rc->overrides;

        // 2. Walk up the parent chain.
        $template = $extends;
        $depth = 0;
        $baseDir = $rc->currentFile !== null ? dirname($rc->currentFile) : null;

        while ($template !== null && $template !== '') {
            if (++$depth > 8) {
                throw new TemplateException(
                    'Layout inheritance is too deep (cycle between layouts?).',
                    ['template' => $template, 'depth' => $depth]
                );
            }

            $path = $this->locator->locate($template, $baseDir);

            if ($path === null) {
                throw new TemplateNotFoundException(
                    "Layout template '{$template}' could not be located.",
                    ['template' => $template, 'child' => $origin]
                );
            }

            $contents = Files::slurp($path, $this->config->limits->maxTemplateBytes);

            if ($contents === null) {
                throw new TemplateNotFoundException(
                    "Layout template '{$path}' is not readable.",
                    ['path' => $path]
                );
            }

            $parentNodes = $this->parseCached($contents, $path);
            $rc->stats->trackTemplate($path);
            $parentExtends = $this->findExtends($parentNodes);

            if ($parentExtends !== null) {
                // Intermediate layout: collect its overrides too, keep walking.
                $rc->collectMode = true;
                $previousExtends = $rc->extendsTemplate;
                $rc->extendsTemplate = null;

                try {
                    $this->tree->render($parentNodes, $rc);
                } finally {
                    $rc->collectMode = false;
                }

                $overrides = $rc->overrides;
                $template = $rc->extendsTemplate ?? $parentExtends;
                $baseDir = dirname($path);
                continue;
            }

            // 3. Render the root layout with the merged override stacks.
            $rc->overrides = $overrides;
            $rc->currentFile = $path;

            return $this->tree->render($parentNodes, $rc);
        }

        throw new TemplateException('Layout inheritance ended without a root layout.', ['child' => $origin]);
    }

    /**
     * @param list<Node> $nodes
     */
    private function findExtends(array $nodes): ?string
    {
        // PHP 8.4 array_find(): first matching node, or null.
        $node = array_find($nodes, static fn(Node $candidate): bool => $candidate->kind === 'extends');

        return $node === null ? null : (string) $node->attribute('template', '');
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function runSsi(string $text, array $vars, RenderOptions $options, Scope $scope, RenderContext $rc): string
    {
        $enabled = $options->ssi ?? $this->config->ssi->enabled;

        if (!$enabled || !str_contains($text, '<!--')) {
            return $text;
        }

        $document = new DocumentInfo(
            file: $options->file ?? $rc->currentFile,
            root: $options->root ?? $this->config->ssi->documentRoot,
            request: $options->request,
            vars: [...$vars, ...(is_array($options->ssiVars) ? $options->ssiVars : [])]
        );

        $before = $text;
        $text = $this->ssi->process($text, $document);
        $rc->stats->ssiDirectives += $this->ssi->lastProcessed();

        // One guarded re-substitution pass: SSI-included fragments may
        // contain template tags (the documented successor of v3's passes=2).
        if ($this->config->ssi->processRenderedOutput
            && $text !== $before
            && (str_contains($text, $this->config->syntax->varOpen)
                || str_contains($text, $this->config->syntax->transOpen)
                || str_contains($text, '<!--@'))
        ) {
            $nodes = $this->parseCached($text, 'ssi-output');
            $text = $this->tree->render($nodes, $rc);
        }

        return $text;
    }

    // ------------------------------------------------------------------ misc

    /**
     * @return list<Node>
     */
    private function parseCached(string $source, string $origin): array
    {
        $key = hash('sha1', $source);

        if (isset($this->parsed[$key])) {
            return $this->parsed[$key];
        }

        if (count($this->parsed) >= 512) {
            $oldest = array_key_first($this->parsed);

            if ($oldest !== null) {
                unset($this->parsed[$oldest]);
            }
        }

        return $this->parsed[$key] = $this->parser->parse($source, $origin);
    }

    /** Drops memoized parse trees (deploy hooks, long-running workers). */
    public function clearParsed(): void
    {
        $this->parsed = [];
        $this->locator->clearCache();
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function cacheKey(string $source, array $vars, RenderOptions $options): string
    {
        return hash('sha256', implode('|', [
            Version::VERSION,
            $this->translator->locale(),
            ($options->autoEscape ?? $this->config->syntax->autoEscape) ? '1' : '0',
            ($options->missingVar ?? $this->config->syntax->missingVar)->value,
            ($options->ssi ?? $this->config->ssi->enabled) ? '1' : '0',
            (string) ($options->file ?? ''),
            (string) ($options->root ?? ''),
            hash('sha256', $source),
            hash('sha256', serialize(self::scalarView($vars))),
        ]));
    }

    /**
     * Reduces variables to a serialisable, order-stable scalar view.
     *
     * @param array<array-key, mixed> $vars
     * @return array<array-key, mixed>
     */
    private static function scalarView(array $vars): array
    {
        $view = [];

        foreach ($vars as $key => $value) {
            $view[$key] = match (true) {
                is_array($value) => self::scalarView($value),
                $value instanceof \Stringable => (string) $value,
                is_object($value) => $value::class,
                is_scalar($value), $value === null => $value,
                default => gettype($value),
            };
        }

        ksort($view);

        return $view;
    }
}
