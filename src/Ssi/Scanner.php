<?php

declare(strict_types=1);

namespace HtmlPhp\Ssi;

use HtmlPhp\Diagnostics;
use HtmlPhp\Html\Escaper;
use HtmlPhp\Io\Files;
use HtmlPhp\Support\Pcre;
use HtmlPhp\Support\Str;

/**
 * Single-document SSI scanner.
 *
 * One Scanner runs one document (the root template or one `#include`d
 * file); recursion creates a new Scanner over a child context so budgets
 * and cycle guards stay shared while conditional frames stay per-document
 * — exactly like mod_include.
 *
 * @internal instantiated by {@see Processor} only
 */
final class Scanner
{
    /** Scanner pattern: every modern SSI comment in one match. */
    private const string DIRECTIVE_PATTERN = '~<!--(?<body>\#[a-zA-Z]+)(?<rest>.*?)-->~is';

    /** Block extractor: `<!--#block name="x"-->…<!--#endblock-->`. */
    private const string BLOCK_PATTERN =
        '~<!--\#block\s+name\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))\s*-->(.*?)<!--\#endblock\s*-->~is';

    /** Maximum #if nesting depth (independent of include depth). */
    private const int MAX_CONDITIONAL_DEPTH = 64;

    /** @var list<array{active: bool, taken: bool}> */
    private array $frames = [];

    /** @var array<string, string> pre-scanned `#block` sections by name */
    private array $blocks = [];

    public function __construct(
        private readonly SsiContext $context,
        private readonly Escaper $escaper,
        private readonly Exec $exec,
        private readonly DirectiveRegistry $registry
    ) {
    }

    public function run(string $html): string
    {
        $html = $this->extractBlocks($html);

        return $this->scan($html);
    }

    /**
     * Removes `#block … #endblock` sections from the flow and remembers
     * them for `#include … stub="name"` fallbacks (nginx/Apache parity:
     * block content never appears twice).
     */
    private function extractBlocks(string $html): string
    {
        if (!str_contains($html, '#block')) {
            return $html;
        }

        $result = Pcre::replaceCallback(
            self::BLOCK_PATTERN,
            function (array $m): string {
                $name = (string) ($m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : ($m[3] ?? '')));

                if ($name !== '') {
                    $this->blocks[$name] = (string) ($m[4] ?? '');
                }

                return '';
            },
            $html
        );

        return $result;
    }

    private function scan(string $html): string
    {
        if ($html === '' || !str_contains($html, '<!--')) {
            return $html;
        }

        $parts = preg_split(self::DIRECTIVE_PATTERN, $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false || count($parts) === 1) {
            return $html;
        }

        // preg_split with two capture groups yields: text, body, rest, text, …
        // Text outside the currently active conditional branch is dropped.
        $output = $this->active() ? $parts[0] : '';
        $count = count($parts);

        for ($index = 1; $index + 2 < $count; $index += 3) {
            $body = (string) $parts[$index];
            $rest = (string) $parts[$index + 1];
            $output .= $this->dispatch($body, $rest);
            $output .= $this->active() ? (string) $parts[$index + 2] : '';
        }

        return $output;
    }

    /** Routes one directive; maintains the conditional frame stack. */
    private function dispatch(string $body, string $rest): string
    {
        $original = '<!--' . $body . $rest . '-->';
        $name = strtolower(substr($body, 1));

        // Conditional directives are always tracked (nesting), but their
        // expressions are only evaluated while the enclosing branch runs.
        if ($name === 'if' || $name === 'elif' || $name === 'elseif' || $name === 'else' || $name === 'endif') {
            $this->conditional($name, $rest);

            return '';
        }

        if (!$this->active()) {
            return '';
        }

        $attributes = Attributes::parse($rest);

        // Host-registered directives win over built-ins.
        $custom = $this->registry->get($name);

        if ($custom !== null) {
            ++$this->context->processed;

            return Str::stringify($custom->render($attributes, $this->context));
        }

        return match ($name) {
            'config' => $this->configDirective($attributes),
            'set' => $this->setDirective($attributes),
            'include' => $this->includeDirective($attributes),
            'echo' => $this->echoDirective($attributes),
            'exec' => $this->execDirective($attributes),
            'flastmod' => $this->lastmodDirective($attributes),
            'fsize' => $this->sizeDirective($attributes),
            'printenv' => $this->printenvDirective(),
            'block', 'endblock' => '', // consumed by the pre-pass
            // Unknown directives stay in the output verbatim (mod_include
            // compatibility: they are ordinary HTML comments to us).
            default => $this->unknownDirective($name, $original),
        };
    }

    private function unknownDirective(string $name, string $original): string
    {
        $this->context->diagnostics()->record(
            Diagnostics::SSI_UNKNOWN_DIRECTIVE,
            'Unknown SSI directive left in output.',
            ['directive' => $name]
        );

        return $original;
    }

    /** Current branch activity (top of the conditional frame stack). */
    private function active(): bool
    {
        return $this->frames === [] || $this->frames[array_key_last($this->frames)]['active'];
    }

    private function parentActive(): bool
    {
        $depth = count($this->frames);

        return $depth === 0 || $this->frames[$depth - 1]['active'];
    }

    /** Activity of the frames below the top one (used by #elif/#else). */
    private function parentActiveExcludingTop(): bool
    {
        $depth = count($this->frames);

        return $depth <= 1 || $this->frames[$depth - 2]['active'];
    }

    /** Handles #if / #elif / #else / #endif frame transitions. */
    private function conditional(string $name, string $rest): void
    {
        $diagnostics = $this->context->diagnostics();

        if ($name === 'endif') {
            if ($this->frames === []) {
                $diagnostics->record(Diagnostics::SSI_EXPR_ERROR, 'SSI #endif without a matching #if.');

                return;
            }

            array_pop($this->frames);

            return;
        }

        if ($name === 'if') {
            if (count($this->frames) >= self::MAX_CONDITIONAL_DEPTH) {
                $diagnostics->record(Diagnostics::SSI_EXPR_ERROR, 'SSI conditional nesting is too deep.');
                $this->frames[] = ['active' => false, 'taken' => true];

                return;
            }

            $result = $this->parentActive() && $this->evaluate($rest);
            $this->frames[] = ['active' => $result, 'taken' => $result];

            return;
        }

        // #elif / #else without an open #if are ignored (mod_include parity).
        if ($this->frames === []) {
            $diagnostics->record(Diagnostics::SSI_EXPR_ERROR, 'SSI #' . $name . ' without a matching #if.');

            return;
        }

        $top = &$this->frames[count($this->frames) - 1];

        if ($name === 'else') {
            $top['active'] = $this->parentActiveExcludingTop() && !$top['taken'];
            $top['taken'] = true;

            return;
        }

        // #elif / #elseif
        if ($top['taken'] || !$this->parentActiveExcludingTop()) {
            $top['active'] = false;

            return;
        }

        $result = $this->evaluate($rest);
        $top['active'] = $result;
        $top['taken'] = $result;

        unset($top);
    }

    /** Extracts and evaluates the `expr="…"` payload of #if/#elif. */
    private function evaluate(string $rest): bool
    {
        $attributes = Attributes::parse($rest);

        // mod_include also accepts expr=v (unquoted); Attributes keeps it.
        return Expression::evaluate($attributes['expr'] ?? '', $this->context);
    }

    // ------------------------------------------------------------- directives

    /** @param array<string, string> $attributes */
    private function configDirective(array $attributes): string
    {
        if (isset($attributes['timefmt']) && $attributes['timefmt'] !== '') {
            $this->context->timefmt = $attributes['timefmt'];
            $this->context->resetBuiltins();
        }

        if (isset($attributes['sizefmt']) && $attributes['sizefmt'] !== '') {
            $this->context->sizefmt = strtolower($attributes['sizefmt']);
        }

        if (array_key_exists('errmsg', $attributes)) {
            $this->context->errmsg = $attributes['errmsg'];
        }

        return '';
    }

    /** @param array<string, string> $attributes */
    private function setDirective(array $attributes): string
    {
        $name = trim($attributes['var'] ?? '');
        $value = $attributes['value'] ?? '';

        if ($this->context->isWritableName($name)) {
            $this->context->vars[$name] = $this->expandVariables($value);
        } else {
            $this->context->diagnostics()->record(
                Diagnostics::SSI_EXEC_DENIED,
                'SSI #set target name is reserved or invalid.',
                ['name' => $name]
            );
        }

        return '';
    }

    /** @param array<string, string> $attributes */
    private function includeDirective(array $attributes): string
    {
        $virtual = $attributes['virtual'] ?? '';
        $file = $attributes['file'] ?? '';
        $stub = $attributes['stub'] ?? '';
        // `wait="yes"` is accepted and ignored: the engine is sequential.

        if ($virtual === '' && $file === '') {
            return '';
        }

        $label = $virtual !== '' ? $virtual : $file;
        $fallback = fn(): string => $this->includeFallback($stub, $label);

        if (!$this->context->budgetAllows()) {
            return $fallback();
        }

        // file= is strictly relative to the directory of the current document.
        $path = $file !== ''
            ? $this->resolveFileAttribute($file)
            : $this->context->resolvePath($virtual);

        if ($path === null) {
            $this->context->diagnostics()->record(
                Diagnostics::SSI_INCLUDE_FAILED,
                'SSI include could not be resolved or is outside the document root.',
                ['target' => $label]
            );

            return $fallback();
        }

        $filtered = $this->context->events()->filter('htmlphp.ssi.include_path', $path, $label);
        $path = Str::stringify($filtered);

        if ($path === '' || isset($this->context->stack[$path])) {
            $this->context->diagnostics()->record(
                Diagnostics::SSI_INCLUDE_CYCLE,
                'SSI include cycle detected.',
                ['target' => $label, 'path' => $path]
            );

            return $fallback();
        }

        $contents = Files::slurp($path, $this->context->limits()->maxIncludeBytes);

        if ($contents === null) {
            $this->context->diagnostics()->record(
                Diagnostics::SSI_INCLUDE_FAILED,
                'SSI include could not be read.',
                ['path' => $path]
            );

            return $fallback();
        }

        ++$this->context->processed;

        $child = $this->context->child($path);
        $this->context->stack[$path] = true;

        try {
            $scanner = new self($child, $this->escaper, $this->exec, $this->registry);

            return $scanner->run($contents);
        } finally {
            unset($this->context->stack[$path]);
        }
    }

    /** Stub block when set, otherwise the configured `errmsg`. */
    private function includeFallback(string $stub, string $label): string
    {
        $stub = trim($stub);

        if ($stub !== '' && isset($this->blocks[$stub])) {
            return $this->blocks[$stub];
        }

        if ($stub !== '') {
            $this->context->diagnostics()->record(
                Diagnostics::SSI_INCLUDE_FAILED,
                'SSI include stub block was not found in this document.',
                ['target' => $label, 'stub' => $stub]
            );
        }

        return $this->context->errorText();
    }

    /**
     * Apache `file=` semantics: relative to the containing document only,
     * still under document-root containment.
     */
    private function resolveFileAttribute(string $file): ?string
    {
        if ($file === '' || str_contains($file, "\0")) {
            return null;
        }

        if (preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*://~', $file) === 1
            || str_starts_with($file, '/') || str_starts_with($file, '\\')
        ) {
            return null;
        }

        if (!$this->context->config()->allowParentPaths
            && preg_match('~(?:^|/)\.\.(?:/|$)~', $file) === 1
        ) {
            return null;
        }

        $relativeDir = $this->context->relativeDir();

        if ($relativeDir === null || $relativeDir === '') {
            return null;
        }

        $candidate = rtrim($relativeDir, "/\\") . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, ltrim($file, './'));

        return $this->context->resolveCandidate($candidate);
    }

    /** @param array<string, string> $attributes */
    private function echoDirective(array $attributes): string
    {
        $name = trim($attributes['var'] ?? '');
        $value = $this->context->variable($name);
        $encoding = strtolower($attributes['encoding'] ?? '');

        $escaped = match ($encoding) {
            'none' => $value,
            'url' => rawurlencode($value),
            'base64' => base64_encode($value),
            'entity' => $this->escaper->html($value),
            default => $this->context->config()->escapeEcho ? $this->escaper->html($value) : $value,
        };

        return Str::stringify($this->context->events()->filter('htmlphp.ssi.echo', $escaped, $name));
    }

    /** @param array<string, string> $attributes */
    private function execDirective(array $attributes): string
    {
        $cmd = $attributes['cmd'] ?? '';
        $cgi = $attributes['cgi'] ?? '';

        if ($cmd !== '') {
            return $this->exec->runCmd($cmd, $this->context);
        }

        if ($cgi !== '') {
            return $this->exec->runCgi($cgi, $this->context);
        }

        return '';
    }

    /** @param array<string, string> $attributes */
    private function lastmodDirective(array $attributes): string
    {
        $path = $this->targetPath($attributes);

        if ($path === null) {
            return $this->context->errorText();
        }

        $modified = @filemtime($path);

        if ($modified === false) {
            return $this->context->errorText();
        }

        return TimeFormat::format($modified, $this->context->timefmt);
    }

    /** @param array<string, string> $attributes */
    private function sizeDirective(array $attributes): string
    {
        $path = $this->targetPath($attributes);

        if ($path === null) {
            return $this->context->errorText();
        }

        $size = @filesize($path);

        if ($size === false) {
            return $this->context->errorText();
        }

        return SizeFormat::format($size, $this->context->sizefmt);
    }

    /**
     * `#printenv` — dumps every visible variable, one `NAME=value` per line
     * (mod_include parity). Values honour the echo escaping and allow-list.
     */
    private function printenvDirective(): string
    {
        if (!$this->context->config()->printenvEnabled) {
            return '';
        }

        $variables = $this->context->builtins();

        foreach ($this->context->vars as $name => $value) {
            $variables[$name] = $value;
        }

        foreach ($this->context->document->vars as $name => $value) {
            if (is_string($name) && !isset($variables[$name]) && !isset(SsiContext::RESERVED[$name])) {
                $variables[$name] = Str::stringify($value);
            }
        }

        ksort($variables);

        $lines = [];

        foreach ($variables as $name => $value) {
            if (!$this->context->echoAllowed((string) $name)) {
                continue;
            }

            $value = (string) $value;
            $lines[] = $name . '=' . ($this->context->config()->escapeEcho
                ? $this->escaper->html($value)
                : $value);
        }

        return implode("\n", $lines) . ($lines === [] ? '' : "\n");
    }

    /** @param array<string, string> $attributes */
    private function targetPath(array $attributes): ?string
    {
        $virtual = $attributes['virtual'] ?? '';
        $file = $attributes['file'] ?? '';

        if ($file !== '') {
            return $this->resolveFileAttribute($file);
        }

        if ($virtual !== '') {
            return $this->context->resolvePath($virtual);
        }

        // Default (Apache): the current document.
        $current = $this->context->document->file;

        return is_string($current) && $current !== '' && is_file($current) ? $current : null;
    }

    // ------------------------------------------------------------- variables

    /**
     * Expands `$VAR` / `${VAR}` inside a `#set` value payload.
     *
     * Unknown names are kept verbatim; a backslash escapes a literal dollar
     * sign; reserved names expand to `''`.
     */
    public function expandVariables(string $value): string
    {
        if ($value === '' || !str_contains($value, '$')) {
            return $value;
        }

        $protected = Pcre::replace('~\\\\\$~', "\x01", $value);
        $context = $this->context;

        $expanded = Pcre::replaceCallback(
            '~\$(?:\{([A-Za-z_][A-Za-z0-9_]*)\}|([A-Za-z_][A-Za-z0-9_]*))~',
            static function (array $matches) use ($context): string {
                $name = ($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? '');

                if ($name === '') {
                    return '';
                }

                if (isset(SsiContext::RESERVED[$name])) {
                    return '';
                }

                return $context->resolve($name) ?? $matches[0];
            },
            $protected
        );

        return str_replace("\x01", '$', $expanded);
    }
}
