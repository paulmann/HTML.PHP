<?php

declare(strict_types=1);

namespace HtmlPhp\Template;

/**
 * Counters and timings for one render — the engine's profiler.
 *
 * Exposed via `Engine::stats()` (last render) and the
 * `htmlphp.rendered` event; the WordPress bridge surfaces it in the
 * admin bar when `WP_DEBUG` is on.
 */
final class RenderStats
{
    // PHP 8.4 asymmetric visibility: readable everywhere, writable only here.
    public private(set) string $template = '';
    public float $startedAt = 0.0;
    public float $durationMs = 0.0;
    public bool $cacheHit = false;
    public int $substitutions = 0;
    public int $loops = 0;
    public int $loopRows = 0;
    public int $conditionals = 0;
    public int $includes = 0;
    public int $sections = 0;
    public int $translations = 0;
    public int $ssiDirectives = 0;
    public int $outputBytes = 0;
    public int $peakMemoryKb = 0;

    /** @var list<string> every template file touched (partials, layouts) */
    public private(set) array $templates = [];

    /** PHP 8.4 property hook: a computed, always-fresh one-liner. */
    public string $summary {
        get => sprintf(
            '%.2fms · %d template(s) · %d loop(s)/%d row(s) · %d include(s) · %d SSI directive(s) · cache %s · %d B out',
            $this->durationMs,
            count($this->templates),
            $this->loops,
            $this->loopRows,
            $this->includes,
            $this->ssiDirectives,
            $this->cacheHit ? 'hit' : 'miss',
            $this->outputBytes
        );
    }

    /** Records a template file touched by the render (partials, layouts). */
    public function trackTemplate(string $template): void
    {
        $this->templates[] = $template;
    }

    public function start(string $template): void
    {
        $this->reset();
        $this->template = $template;
        $this->trackTemplate($template);
        $this->startedAt = microtime(true);
        $this->peakMemoryKb = (int) (memory_get_usage(true) / 1024);
    }

    public function stop(int $outputBytes): void
    {
        $this->durationMs = round((microtime(true) - $this->startedAt) * 1000, 3);
        $this->outputBytes = $outputBytes;
    }

    public function reset(): void
    {
        $this->durationMs = 0.0;
        $this->cacheHit = false;
        $this->substitutions = 0;
        $this->loops = 0;
        $this->loopRows = 0;
        $this->conditionals = 0;
        $this->includes = 0;
        $this->sections = 0;
        $this->translations = 0;
        $this->ssiDirectives = 0;
        $this->outputBytes = 0;
        $this->peakMemoryKb = 0;
        $this->templates = [];
    }

    /** @return array<string, mixed> */
    #[\NoDiscard]
    public function toArray(): array
    {
        return [
            'template' => $this->template,
            'startedAt' => $this->startedAt,
            'durationMs' => $this->durationMs,
            'cacheHit' => $this->cacheHit,
            'substitutions' => $this->substitutions,
            'loops' => $this->loops,
            'loopRows' => $this->loopRows,
            'conditionals' => $this->conditionals,
            'includes' => $this->includes,
            'sections' => $this->sections,
            'translations' => $this->translations,
            'ssiDirectives' => $this->ssiDirectives,
            'outputBytes' => $this->outputBytes,
            'peakMemoryKb' => $this->peakMemoryKb,
            'templates' => $this->templates,
            'summary' => $this->summary,
        ];
    }
}
