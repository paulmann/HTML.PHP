<?php

declare(strict_types=1);

namespace HtmlPhp\Template;

use HtmlPhp\Config;
use HtmlPhp\Config\ExecMode;
use HtmlPhp\Diagnostics;
use HtmlPhp\I18n\Extractor;
use HtmlPhp\I18n\Translator;
use HtmlPhp\Support\Pcre;

/**
 * Static template analysis: unbalanced tags, unknown tags/filters,
 * malformed expressions, unresolvable partials/layouts and translatable
 * strings missing from the catalogue.
 *
 * Powers `Engine::lint()`, `bin/htmlphp lint`, `wp htmlphp lint` and the
 * WordPress admin screen — the "will this template explode in production?"
 * check that runs in CI instead of at 3 a.m.
 */
final class Linter
{
    /** @var array<string, true> */
    private const array KNOWN_STANDALONE = [
        'extends' => true, 'include' => true, 'set' => true,
        'sectionparent' => true, 'break' => true, 'continue' => true,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Diagnostics $diagnostics,
        private readonly Filters $filters,
        private readonly TemplateLocator $locator,
        private readonly ?Translator $translator = null
    ) {
    }

    /**
     * Lints one template source.
     *
     * @return list<LintIssue>
     */
    public function lint(string $source, string $origin = '-'): array
    {
        $issues = [];

        foreach ($this->structureIssues($source, $origin) as $issue) {
            $issues[] = $issue;
        }

        // The structure pass already produced a node tree when it did not
        // throw; re-parse defensively behind a try/catch for the deep checks.
        try {
            $nodes = (new TagParser())->parse($source, $origin);
        } catch (\Throwable) {
            // Structural failure already reported — still check substitutions
            // so one broken tag cannot hide unknown filters.
            $this->textIssues($source, $origin, $issues);

            return $issues;
        }

        $this->walk($nodes, $source, $origin, $issues);
        $this->ssiIssues($source, $origin, $issues);

        return $issues;
    }

    /**
     * Lints a file.
     *
     * @return list<LintIssue>
     */
    public function lintFile(string $path): array
    {
        $contents = \HtmlPhp\Io\Files::slurp($path, $this->config->limits->maxTemplateBytes);

        if ($contents === null) {
            return [new LintIssue('error', 'template-unreadable', 'Template is not readable or too large.', $path, 0)];
        }

        return $this->lint($contents, $path);
    }

    /**
     * Lints every template below a directory.
     *
     * @return array<string, list<LintIssue>> issues keyed by file
     */
    public function lintDirectory(string $directory): array
    {
        $report = [];

        if (!is_dir($directory)) {
            return $report;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $info */
        foreach ($iterator as $info) {
            if (!$info->isFile()) {
                continue;
            }

            $extension = strtolower($info->getExtension());

            if (!in_array($extension, ['html', 'htm', 'txt', 'shtml', 'xhtml'], true)) {
                continue;
            }

            $issues = $this->lintFile($info->getPathname());

            if ($issues !== []) {
                $report[$info->getPathname()] = $issues;
            }
        }

        return $report;
    }

    // ------------------------------------------------------------- structure

    /**
     * Cheap pre-pass over the raw tag stream: unknown tags and unbalanced
     * blocks are reported with line numbers even when the parser throws on
     * the first one.
     *
     * @return list<LintIssue>
     */
    private function structureIssues(string $source, string $origin): array
    {
        $issues = [];

        if (!str_contains($source, '<!--@')) {
            return $issues;
        }

        $count = preg_match_all(
            '~<!--@([A-Za-z][A-Za-z0-9]*)(.*?)-->~s',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );

        if ($count === 0 || $count === false) {
            return $issues;
        }

        $open = [];
        $known = [
            'loop' => 'endloop', 'if' => 'endif', 'unless' => 'endunless',
            'section' => 'endsection', 'trans' => 'endtrans',
            'raw' => 'endraw', 'comment' => 'endcomment',
        ];
        $mids = ['else' => true, 'elif' => true, 'plural' => true];

        foreach ($matches as $match) {
            $name = strtolower((string) $match[1][0]);
            $offset = (int) $match[0][1];
            $line = substr_count($source, "\n", 0, $offset) + 1;

            if (!isset($known[$name])
                && !isset($mids[$name])
                && !isset(self::KNOWN_STANDALONE[$name])
                && !in_array($name, ['endloop', 'endif', 'endunless', 'endsection', 'endtrans', 'endraw', 'endcomment'], true)
            ) {
                $issues[] = new LintIssue('error', 'unknown-tag', 'Unknown template tag "@' . $name . '".', $origin, $line);
                continue;
            }

            if (isset($known[$name])) {
                $open[] = [$name, $line];
                continue;
            }

            $closes = array_search($name, $known, true);

            if ($closes !== false) {
                $top = $open === [] ? null : $open[count($open) - 1];

                if ($top === null || $top[0] !== $closes) {
                    $issues[] = new LintIssue(
                        'error',
                        'unbalanced-tag',
                        sprintf(
                            '"@%s" closes %s.',
                            $name,
                            $top === null ? 'nothing' : 'an open "@' . $top[0] . '" from line ' . $top[1]
                        ),
                        $origin,
                        $line
                    );

                    continue; // the opener stays open — it is still unclosed
                }

                array_pop($open);
                continue;
            }

            if (isset($mids[$name]) && $open === []) {
                $issues[] = new LintIssue('error', 'orphan-mid', '"@' . $name . '" without an opening block.', $origin, $line);
            }
        }

        foreach ($open as [$name, $line]) {
            $issues[] = new LintIssue('error', 'unclosed-tag', 'Block "@' . $name . '" is never closed.', $origin, $line);
        }

        return $issues;
    }

    // ----------------------------------------------------------- deep checks

    /**
     * @param list<Node> $nodes
     * @param list<LintIssue> $issues
     */
    private function walk(array $nodes, string $source, string $origin, array &$issues, int $depth = 0): void
    {
        foreach ($nodes as $node) {
            $line = (int) ($node->attribute('line') ?? 0);

            switch ($node->kind) {
                case 'text':
                    $this->textIssues((string) $node->attribute('value', ''), $origin, $issues);
                    break;

                case 'loop':
                    $this->loopIssues($node, $origin, $issues);
                    $this->walk($node->children, $source, $origin, $issues, $depth + 1);
                    $this->walk($node->attribute('else', []), $source, $origin, $issues, $depth + 1);
                    break;

                case 'if':
                    foreach ($node->attribute('branches', []) as $branch) {
                        $this->expressionIssues((string) ($branch['expr'] ?? ''), $origin, $line, $issues);
                        $this->walk($branch['nodes'], $source, $origin, $issues, $depth + 1);
                    }
                    $this->walk($node->attribute('else', []), $source, $origin, $issues, $depth + 1);
                    break;

                case 'section':
                    $this->walk($node->children, $source, $origin, $issues, $depth + 1);
                    break;

                case 'trans':
                    $this->transIssues($node, $origin, $issues);
                    $this->walk($node->attribute('singular', []), $source, $origin, $issues, $depth + 1);
                    $this->walk($node->attribute('plural', []), $source, $origin, $issues, $depth + 1);
                    break;

                case 'include':
                case 'extends':
                    $this->referenceIssues($node, $origin, $line, $issues);
                    break;

                case 'set':
                    $this->expressionIssues((string) $node->attribute('expr', ''), $origin, $line, $issues);
                    break;

                case 'break':
                case 'continue':
                    if ($depth === 0) {
                        $issues[] = new LintIssue('warning', 'loop-signal-outside-loop', '"@' . $node->kind . '" outside of any loop.', $origin, $line);
                    }
                    break;

                default:
                    break;
            }
        }
    }

    /** @param list<LintIssue> $issues */
    private function textIssues(string $text, string $origin, array &$issues): void
    {
        if ($text === '') {
            return;
        }

        Pcre::replaceCallback(
            '~([%#@])\{([^{}]*)\}~',
            function (array $m) use ($origin, &$issues): string {
                $payload = (string) $m[2];
                $line = 0;
                [$name, $filters] = Substitution::splitFilters($payload);

                if ($m[1] === '@') {
                    $this->messageIssue(trim(explode('|', $name)[0]), $origin, $line, $issues);
                }

                foreach ($filters as [$filterName, $args]) {
                    $lower = strtolower($filterName);

                    if ($lower === 'raw' && !$this->config->syntax->allowRawFilter) {
                        $issues[] = new LintIssue('error', 'filter-disabled', 'Filter "raw" is disabled by configuration.', $origin, $line);
                        continue;
                    }

                    if (!$this->filters->has($lower)) {
                        $issues[] = new LintIssue('error', 'unknown-filter', 'Unknown filter "' . $filterName . '".', $origin, $line);
                    }
                }

                return $m[1] . '{' . $payload . '}';
            },
            $text
        );
    }

    /** @param list<LintIssue> $issues */
    private function loopIssues(Node $node, string $origin, array &$issues): void
    {
        $spec = $node->attribute('spec');

        if (!is_array($spec)) {
            return;
        }

        $line = (int) ($spec['line'] ?? 0);

        if (($spec['mode'] ?? '') === 'range') {
            $span = abs((int) $spec['to'] - (int) $spec['source']);

            if ($span > $this->config->limits->maxLoopRows) {
                $issues[] = new LintIssue('warning', 'loop-range-too-large', sprintf('Loop range spans %d rows (limit %d).', $span, $this->config->limits->maxLoopRows), $origin, $line);
            }
        }
    }

    /** @param list<LintIssue> $issues */
    private function expressionIssues(string $expression, string $origin, int $line, array &$issues): void
    {
        $expression = trim($expression);

        if ($expression === '') {
            return;
        }

        try {
            Expression::validate($expression, $this->config->limits->maxExpressionLength);
        } catch (\Throwable $error) {
            $issues[] = new LintIssue('error', 'bad-expression', $error->getMessage(), $origin, $line, ['expression' => $expression]);
        }
    }

    /** @param list<LintIssue> $issues */
    private function referenceIssues(Node $node, string $origin, int $line, array &$issues): void
    {
        $template = (string) $node->attribute('template', '');

        if ($template === '') {
            $issues[] = new LintIssue('error', 'missing-reference', '"@' . $node->kind . '" has no template name.', $origin, $line);

            return;
        }

        if ($this->locator->roots() === [] && $this->config->templates->namespaces === []) {
            return; // nothing to resolve against; skip silently
        }

        if ($this->locator->locate($template) === null && !$node->attribute('ignoreMissing', false)) {
            $issues[] = new LintIssue(
                'error',
                'unresolved-' . $node->kind,
                sprintf('"%s" cannot be located in the configured template paths.', $template),
                $origin,
                $line,
                ['template' => $template]
            );
        }
    }

    /** @param list<LintIssue> $issues */
    private function transIssues(Node $node, string $origin, array &$issues): void
    {
        $attrs = $node->attribute('attrs', []);
        $line = (int) ($node->attribute('line') ?? 0);
        $plural = $node->attribute('plural', []);

        if (isset($attrs['count']) && $plural === []) {
            $issues[] = new LintIssue('warning', 'trans-count-without-plural', '"@Trans" has count= but no <!--@Plural--> body.', $origin, $line);
        }

        if (!isset($attrs['count']) && $plural !== []) {
            $issues[] = new LintIssue('warning', 'trans-plural-without-count', '"@Trans" has a plural body but no count= attribute.', $origin, $line);
        }

        foreach ($node->attribute('singular', []) as $child) {
            if ($child->kind !== 'text') {
                $issues[] = new LintIssue('error', 'trans-nested-tag', '"@Trans" bodies may only contain text and substitutions.', $origin, $line);
                break;
            }
        }
    }

    /** @param list<LintIssue> $issues */
    private function messageIssue(string $msgid, string $origin, int $line, array &$issues): void
    {
        if ($msgid === '' || $this->translator === null) {
            return;
        }

        $locale = $this->translator->locale();
        $catalogue = $this->translator->catalogue($locale, $this->config->i18n->domain);

        if ($catalogue !== null && !$catalogue->has($msgid)) {
            $issues[] = new LintIssue('warning', 'untranslated-message', sprintf('"%s" has no %s translation.', $msgid, $locale), $origin, $line, ['msgid' => $msgid]);
        }
    }

    // -------------------------------------------------------------------- SSI

    /**
     * Validates SSI directives: unknown names, `#if` expressions and
     * `#include`/`#echo` attribute presence.
     *
     * @param list<LintIssue> $issues
     */
    private function ssiIssues(string $source, string $origin, array &$issues): void
    {
        $count = preg_match_all('~<!--\#([a-zA-Z]+)((?:(?!<!--).)*?)-->~s', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        if ($count === 0 || $count === false) {
            return;
        }

        $known = [
            'config', 'echo', 'exec', 'flastmod', 'fsize', 'include',
            'printenv', 'set', 'if', 'elif', 'else', 'endif', 'block', 'endblock',
        ];

        foreach ($matches as $match) {
            $name = strtolower((string) $match[1][0]);
            $payload = (string) ($match[2][0] ?? '');
            $line = substr_count($source, "\n", 0, (int) $match[0][1]) + 1;

            if (!in_array($name, $known, true)) {
                $issues[] = new LintIssue('warning', 'unknown-ssi-directive', 'Unknown SSI directive "#' . $name . '" (left in output).', $origin, $line);
                continue;
            }

            $attributes = \HtmlPhp\Ssi\Attributes::parse($payload);

            if ($name === 'if' || $name === 'elif') {
                $expression = $attributes['expr'] ?? '';

                if ($expression === '') {
                    $issues[] = new LintIssue('error', 'ssi-missing-expr', '"#' . $name . '" has no expr= attribute.', $origin, $line);
                    continue;
                }

                // SSI expressions never throw: syntax errors surface as
                // diagnostics, which the linter converts into issues.
                $before = $this->diagnostics->count(Diagnostics::SSI_EXPR_ERROR);
                \HtmlPhp\Ssi\Expression::evaluate($expression, $this->lintContext());

                foreach (array_slice($this->diagnostics->byCode(Diagnostics::SSI_EXPR_ERROR), $before) as $entry) {
                    $issues[] = new LintIssue('error', 'ssi-bad-expr', $entry['message'], $origin, $line, ['expression' => $expression]);
                }
                continue;
            }

            if ($name === 'include' && ($attributes['file'] ?? '') === '' && ($attributes['virtual'] ?? '') === '') {
                $issues[] = new LintIssue('error', 'ssi-include-target', '"#include" needs file= or virtual=.', $origin, $line);
                continue;
            }

            if ($name === 'echo' && ($attributes['var'] ?? '') === '') {
                $issues[] = new LintIssue('error', 'ssi-echo-var', '"#echo" needs var=.', $origin, $line);
                continue;
            }

            if ($name === 'set' && ($attributes['var'] ?? '') === '') {
                $issues[] = new LintIssue('error', 'ssi-set-var', '"#set" needs var=.', $origin, $line);
            }

            if ($name === 'exec' && $this->config->ssi->execMode === ExecMode::Deny) {
                $issues[] = new LintIssue('info', 'ssi-exec-denied', '"#exec" is present but the policy denies execution.', $origin, $line);
            }
        }
    }

    private function lintContext(): \HtmlPhp\Ssi\SsiContext
    {
        return new \HtmlPhp\Ssi\SsiContext(
            new \HtmlPhp\Ssi\DocumentInfo(null, $this->config->ssi->documentRoot),
            $this->config->ssi,
            $this->config->security,
            $this->config->limits,
            $this->diagnostics,
            new \HtmlPhp\Event\Dispatcher(),
            new \HtmlPhp\Support\SystemClock()
        );
    }

    /**
     * Extracts translatable messages from a source (CLI/admin helper).
     */
    public function extract(string $source, string $origin = '-'): Extractor
    {
        return (new Extractor())->scan($source, $origin);
    }
}
