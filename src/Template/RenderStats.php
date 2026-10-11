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
    public string $template = '';
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
    public array $templates = [];

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
        foreach (get_object_vars($this) as $property => $value) {
            $this->{$property} = match (true) {
                is_int($value) && $property !== 'peakMemoryKb' => 0,
                is_float($value) => 0.0,
                is_bool($value) => false,
                is_array($value) => [],
                is_string($value) => '',
                default => $value,
            };
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
