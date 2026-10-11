<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress;

use HtmlPhp\Engine;
use HtmlPhp\I18n\Auditor;
use HtmlPhp\I18n\Extractor;
use HtmlPhp\WordPress\Maintenance\Health;
use HtmlPhp\WordPress\Maintenance\SystemInfo;

/**
 * WP-CLI commands: `wp htmlphp …`
 *
 * Registered by {@see Bridge} when WP-CLI is present. Every command goes
 * through the booted engine — no private state.
 *
 *   wp htmlphp info                     versions, paths, catalogue stats
 *   wp htmlphp render <template> [--var=k:v]…
 *   wp htmlphp lint [<path>]            template or directory; exit 1 on errors
 *   wp htmlphp cache clear|size
 *   wp htmlphp i18n extract <dir> [--out=messages.pot]
 *   wp htmlphp i18n audit <dir> [--locale=de] [--domain=messages]
 *   wp htmlphp health                   actionable findings
 */
final class Cli
{
    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function info(array $args = [], array $assoc = []): void
    {
        $engine = Bridge::engine();
        $info = SystemInfo::collect($engine);

        if (!empty($assoc['json'])) {
            $this->line((string) json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        foreach (['wordpress', 'php', 'server', 'engine'] as $section) {
            $this->line('== ' . strtoupper($section) . ' ==');

            foreach ((array) ($info[$section] ?? []) as $key => $value) {
                $this->line(\sprintf('  %-22s %s', (string) $key, $this->flat($value)));
            }
        }
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function render(array $args, array $assoc = []): void
    {
        $template = (string) ($args[0] ?? '');

        if ($template === '') {
            $this->error('Usage: wp htmlphp render <template> [--var=k:v]…');

            return;
        }

        $vars = [];

        foreach ((array) ($assoc['var'] ?? []) as $pair) {
            $parts = explode('=', (string) $pair, 2);

            if (\count($parts) === 2) {
                $vars[$parts[0]] = $parts[1];
            }
        }

        $engine = Bridge::engine();

        try {
            $path = TemplateLoader::locate($template);
            $out = $path !== null
                ? $engine->renderFile($path, $vars)
                : $engine->renderString($template, $vars);

            $this->line($out);
        } catch (\Throwable $error) {
            $this->error('Render failed: ' . $error->getMessage());
        }
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function lint(array $args, array $assoc = []): void
    {
        $target = (string) ($args[0] ?? '');
        $engine = Bridge::engine();
        $hasErrors = false;

        if ($target === '') {
            foreach ($engine->config()->templates->paths as $root) {
                $hasErrors = $this->lintDirectory($engine, $root) || $hasErrors;
            }
        } elseif (is_dir($target)) {
            $hasErrors = $this->lintDirectory($engine, $target);
        } else {
            $path = TemplateLoader::locate($target) ?? $target;
            $issues = is_file($path) ? $engine->linter()->lintFile($path) : $engine->lint($target);

            foreach ($issues as $issue) {
                $this->report($issue->severity, $issue->format());
                $hasErrors = $hasErrors || $issue->isError();
            }
        }

        $hasErrors
            ? $this->error('Lint found errors.')
            : $this->success('Lint clean.');
    }

    private function lintDirectory(Engine $engine, string $dir): bool
    {
        $hasErrors = false;

        foreach ($engine->lintDirectory($dir) as $file => $issues) {
            foreach ($issues as $issue) {
                $this->report($issue->severity, $issue->format());
                $hasErrors = $hasErrors || $issue->isError();
            }
        }

        return $hasErrors;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function cache(array $args, array $assoc = []): void
    {
        $action = (string) ($args[0] ?? 'clear');
        $engine = Bridge::engine();

        if ($action === 'clear') {
            $removed = $engine->clearCache();
            $this->success(\sprintf('Removed %d cache entr%s.', $removed, $removed === 1 ? 'y' : 'ies'));

            return;
        }

        $this->error('Unknown cache action: ' . $action);
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function i18n(array $args, array $assoc = []): void
    {
        $action = (string) ($args[0] ?? '');
        $dir = (string) ($args[1] ?? '');
        $engine = Bridge::engine();

        if ($dir === '' || !is_dir($dir)) {
            $this->error('Usage: wp htmlphp i18n extract|audit <template-dir> [--out=file] [--locale=de] [--domain=messages]');

            return;
        }

        $extractor = new Extractor();
        $files = $extractor->scanDirectory($dir, ltrim($engine->config()->templates->extension, '.'));

        if ($action === 'extract') {
            $pot = $extractor->toPo();
            $out = (string) ($assoc['out'] ?? '');

            if ($out !== '') {
                file_put_contents($out, $pot) === false
                    ? $this->error('Cannot write ' . $out)
                    : $this->success(\sprintf('Wrote %d message(s) from %d file(s) to %s.', \count($extractor->entries()), \count($files), $out));

                return;
            }

            $this->line($pot);

            return;
        }

        if ($action === 'audit') {
            $locale = (string) ($assoc['locale'] ?? $engine->locale());
            $domain = (string) ($assoc['domain'] ?? $engine->config()->i18n->domain);
            $translator = $engine->translator();

            if (!$translator instanceof \HtmlPhp\I18n\Translator) {
                $this->error('Audit needs the built-in translator (WP delegation has no local catalogue store).');

                return;
            }

            $report = (new Auditor($translator))->audit($extractor->entries(), $locale, $domain);

            foreach (Auditor::format($report) as $line) {
                $this->line($line);
            }

            foreach ($extractor->dynamicHits() as $hit) {
                $this->report('warning', 'dynamic translate at ' . $hit);
            }

            return;
        }

        $this->error('Unknown i18n action: ' . $action);
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function health(array $args = [], array $assoc = []): void
    {
        foreach (Health::check(Bridge::engine()) as $finding) {
            $severity = (string) ($finding['severity'] ?? 'info');
            $this->report($severity, \sprintf('%s — %s', (string) ($finding['code'] ?? ''), (string) ($finding['message'] ?? '')));
        }
    }

    // ---------------------------------------------------------------- output

    private function report(string $severity, string $line): void
    {
        if (!class_exists('\WP_CLI')) {
            echo $line, "\n";

            return;
        }

        match ($severity) {
            'error', 'critical' => \WP_CLI::warning($line),
            'warning' => \WP_CLI::warning($line),
            default => \WP_CLI::log($line),
        };
    }

    private function line(string $text): void
    {
        class_exists('\WP_CLI') ? \WP_CLI::line($text) : print($text . "\n");
    }

    private function success(string $text): void
    {
        class_exists('\WP_CLI') ? \WP_CLI::success($text) : print($text . "\n");
    }

    private function error(string $text): void
    {
        if (class_exists('\WP_CLI')) {
            \WP_CLI::error($text);

            return;
        }

        fwrite(STDERR, $text . "\n");
    }

    /** @param mixed $value */
    private function flat($value): string
    {
        if (\is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
