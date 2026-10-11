<?php

declare(strict_types=1);

namespace HtmlPhp\Ssi;

use HtmlPhp\Config;
use HtmlPhp\Diagnostics;
use HtmlPhp\Event\Dispatcher;
use HtmlPhp\Html\Escaper;
use HtmlPhp\Support\ClockInterface;

/**
 * Apache mod_include compatible SSI processor.
 *
 * Instance-based in v4 (v3 used statics + `$GLOBALS`): one Processor per
 * engine, all state per render inside {@see SsiContext}.
 *
 * ```php
 * $html = $engine->ssi()->process($content, new DocumentInfo(file: __FILE__));
 * ```
 *
 * Supported directives: `#config`, `#set`, `#include` (file/virtual,
 * `stub=`, `wait=` accepted), `#echo` (encoding none/url/base64/entity),
 * `#if`/`#elif`/`#else`/`#endif`, `#exec` (policy-gated), `#fsize`,
 * `#flastmod`, `#printenv`, `#block`/`#endblock` and host-registered
 * custom directives. The legacy v2 `##name` and `@var` comment forms were
 * removed in v4.
 */
final class Processor
{
    private readonly Exec $exec;
    private readonly DirectiveRegistry $registry;
    private int $lastProcessed = 0;

    public function __construct(
        private readonly Config $config,
        private readonly Diagnostics $diagnostics,
        private readonly Dispatcher $events,
        private readonly Escaper $escaper,
        private readonly ClockInterface $clock
    ) {
        $this->exec = new Exec($config->ssi, $config->limits, $config->security, $diagnostics);
        $this->registry = new DirectiveRegistry();
    }

    /** Custom/overriding directives (checked before built-ins). */
    public function directives(): DirectiveRegistry
    {
        return $this->registry;
    }

    /**
     * Processes every SSI directive in `$html`.
     *
     * @param DocumentInfo|null $document anchors relative includes,
     *        LAST_MODIFIED and the containment root; `null` behaves like a
     *        string render rooted at the configured document root
     */
    public function process(string $html, ?DocumentInfo $document = null): string
    {
        if (!$this->config->ssi->enabled || $html === '' || !str_contains($html, '<!--')) {
            return $html;
        }

        $document ??= DocumentInfo::forString($this->config->ssi->documentRoot);

        $context = new SsiContext(
            $document,
            $this->config->ssi,
            $this->config->security,
            $this->config->limits,
            $this->diagnostics,
            $this->events,
            $this->clock
        );

        $output = (new Scanner($context, $this->escaper, $this->exec, $this->registry))->run($html);
        $this->lastProcessed = $context->processed;

        return $output;
    }

    /** Directives processed by the most recent {@see self::process()} call. */
    public function lastProcessed(): int
    {
        return $this->lastProcessed;
    }

    /**
     * Processes with an existing context (nested renders that must share
     * budgets — used by the Engine when SSI runs inside a render pass).
     */
    public function processWithContext(string $html, SsiContext $context): string
    {
        return (new Scanner($context, $this->escaper, $this->exec, $this->registry))->run($html);
    }

    /**
     * The built-in variable map for a document (diagnostics, WP-CLI info,
     * `html_ssi_variables()` successor).
     *
     * @return array<string, string>
     */
    public function variables(?DocumentInfo $document = null): array
    {
        $document ??= DocumentInfo::forString($this->config->ssi->documentRoot);

        $context = new SsiContext(
            $document,
            $this->config->ssi,
            $this->config->security,
            $this->config->limits,
            $this->diagnostics,
            $this->events,
            $this->clock
        );

        return $context->builtins();
    }
}
