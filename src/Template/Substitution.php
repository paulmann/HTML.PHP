<?php

declare(strict_types=1);

namespace HtmlPhp\Template;

use HtmlPhp\Config\MissingVarPolicy;
use HtmlPhp\Config\Syntax as SyntaxConfig;
use HtmlPhp\Diagnostics;
use HtmlPhp\Exception\TemplateException;
use HtmlPhp\Html\Escaper;
use HtmlPhp\I18n\TranslatorInterface;
use HtmlPhp\Scope;
use HtmlPhp\Support\Pcre;
use HtmlPhp\Support\Str;

/**
 * Variable substitution: `%{NAME|filter:arg}`, `#{NAME}` and `@{KEY}`.
 *
 * v4 differences from v3:
 *  - the legacy forms `%[NAME]`, `%{%{NAME}}` and the html4.pl numeric
 *    directives (`.Round`, `.Div`, …) are gone — use filters
 *    (`%{N|round:2}`, `%{A|div:B}` does not exist; compute in `@Set`);
 *  - `@{KEY}` goes through the injected {@see TranslatorInterface}
 *    (no `Translate.KEY` globals);
 *  - missing-variable handling follows {@see MissingVarPolicy};
 *  - auto-escaping is on by default (config `syntax.auto_escape`), the
 *    `raw` filter opts out per-variable.
 */
final class Substitution
{
    /** Filters that already produce safe output (skip auto-escaping). */
    private const array ESCAPING_FILTERS = [
        'escape', 'esc', 'e', 'attr', 'url', 'json', 'js', 'css', 'nl2br', 'raw',
    ];

    /** @var array<string, true> names already reported missing this render */
    private array $reported = [];

    public function __construct(
        private readonly SyntaxConfig $syntax,
        private readonly Filters $filters,
        private readonly Escaper $escaper,
        private readonly TranslatorInterface $translator,
        private readonly Diagnostics $diagnostics,
    ) {}

    /**
     * Applies every substitution form to `$html` (single pass per form).
     */
    public function process(
        string $html,
        Scope $scope,
        bool $autoEscape,
        MissingVarPolicy $missingPolicy,
    ): string {
        if ($html === '') {
            return '';
        }

        $open = preg_quote($this->syntax->varOpen, '~');
        $close = preg_quote($this->syntax->varClose, '~');
        $alt = preg_quote($this->syntax->altOpen, '~');
        $trans = preg_quote($this->syntax->transOpen, '~');

        // @{KEY} — translation with optional filter chain.
        $html = Pcre::replaceCallback(
            '~' . $trans . '([^{}]*?)\}~',
            function (array $matches) use ($autoEscape): string {
                [$key, $filters] = self::splitFilters((string) $matches[1]);

                if ($key === '') {
                    return $matches[0];
                }

                $value = $this->translator->t($key);

                return $this->finish($value, $filters, $autoEscape);
            },
            $html,
        );

        // #{NAME} — alternate brace form.
        $html = Pcre::replaceCallback(
            '~' . $alt . '([^{}]*?)\}~',
            function (array $matches) use ($scope, $autoEscape, $missingPolicy): string {
                return $this->substitute((string) $matches[1], $matches[0], $scope, $autoEscape, $missingPolicy);
            },
            $html,
        );

        // %{NAME} — the primary form.
        $html = Pcre::replaceCallback(
            '~' . $open . '([^{}]*?)' . $close . '~',
            function (array $matches) use ($scope, $autoEscape, $missingPolicy): string {
                return $this->substitute((string) $matches[1], $matches[0], $scope, $autoEscape, $missingPolicy);
            },
            $html,
        );

        // {NAME} — opt-in single-brace form (off by default: clashes with CSS/JS).
        if ($this->syntax->singleBraces) {
            $html = Pcre::replaceCallback(
                '~(?<![%#@\\\\\w])\{([A-Za-z_][A-Za-z0-9_.]*(?:\|[^{}]*)?)\}(?!\w)~',
                function (array $matches) use ($scope, $autoEscape, $missingPolicy): string {
                    return $this->substitute((string) $matches[1], $matches[0], $scope, $autoEscape, $missingPolicy);
                },
                $html,
            );
        }

        return $html;
    }

    /**
     * Resolves and filters one `%{payload}`-style substitution.
     */
    private function substitute(
        string $payload,
        string $original,
        Scope $scope,
        bool $autoEscape,
        MissingVarPolicy $missingPolicy,
    ): string {
        [$name, $filters] = self::splitFilters($payload);

        if ($name === '' || !Str::isValidVariableName($name)) {
            // Not a variable reference at all (CSS, JS, literal braces).
            return $original;
        }

        [$found, $value] = $scope->find($name);

        if (!$found) {
            return $this->missing($name, $original, $missingPolicy);
        }

        return $this->finish($value, $filters, $autoEscape, $scope);
    }

    private function missing(string $name, string $original, MissingVarPolicy $policy): string
    {
        if (!isset($this->reported[$name])) {
            $this->reported[$name] = true;
            $this->diagnostics->record(
                Diagnostics::MISSING_VAR,
                'Template variable is not defined.',
                ['name' => $name, 'policy' => $policy->value],
            );
        }

        return match ($policy) {
            MissingVarPolicy::Empty => '',
            MissingVarPolicy::Keep => $original,
            MissingVarPolicy::Placeholder => \sprintf($this->syntax->missingPlaceholderFormat, $name),
            MissingVarPolicy::Throw => throw new TemplateException(
                "Template variable '{$name}' is not defined.",
                ['name' => $name],
            ),
        };
    }

    /**
     * Applies the filter chain, then auto-escapes unless a chain filter
     * already produced safe output (or `raw` was requested).
     *
     * @param list<array{0: string, 1: list<string>}> $filters
     */
    private function finish(mixed $value, array $filters, bool $autoEscape, ?Scope $scope = null): string
    {
        $hasRaw = false;

        foreach ($filters as [$name, $args]) {
            $lower = strtolower($name);

            if ($lower === 'raw') {
                if (!$this->syntax->allowRawFilter) {
                    $this->diagnostics->record(
                        Diagnostics::FILTER_UNKNOWN,
                        'The "raw" filter is disabled by configuration.',
                        [],
                    );

                    continue;
                }

                $hasRaw = true;
                continue;
            }

            if ($lower === 'translate' && $scope !== null) {
                $value = $this->translator->t(Str::stringify($value), $this->translateArgs($args, $scope));
                continue;
            }

            $value = $this->filters->apply($lower, $value, $args);
        }

        $string = Str::stringify($value);

        if (!$autoEscape || $hasRaw) {
            return $string;
        }

        foreach ($filters as [$name]) {
            $lower = strtolower($name);

            if ($lower === 'raw' && !$this->syntax->allowRawFilter) {
                continue; // a disabled raw filter must not suppress escaping
            }

            if (\in_array($lower, self::ESCAPING_FILTERS, true)) {
                return $string;
            }
        }

        return $this->escaper->html($string);
    }

    /**
     * `|translate:'%name%':user.name` style arguments — pairs of
     * placeholder/value resolved against the scope.
     *
     * @param list<string> $args
     * @return array<string, string>
     */
    private function translateArgs(array $args, Scope $scope): array
    {
        $pairs = [];

        for ($index = 0, $count = \count($args); $index + 1 < $count; $index += 2) {
            $placeholder = $args[$index];
            $value = $args[$index + 1];

            [$found, $resolved] = $scope->find($value);

            $pairs[$placeholder] = $found ? Str::stringify($resolved) : $value;
        }

        return $pairs;
    }

    /**
     * Splits `NAME|filter:arg|filter2` into `[name, [[filter, [args]], …]]`.
     *
     * @return array{0: string, 1: list<array{0: string, 1: list<string>}>}
     */
    public static function splitFilters(string $payload): array
    {
        if (!str_contains($payload, '|')) {
            return [trim($payload), []];
        }

        $segments = explode('|', $payload);
        $name = trim((string) array_shift($segments));

        return [$name, self::parseSegments($segments)];
    }

    /**
     * @param list<string> $segments
     * @return list<array{0: string, 1: list<string>}>
     */
    public static function parseSegments(array $segments): array
    {
        $filters = [];

        foreach ($segments as $segment) {
            $segment = trim((string) $segment);

            if ($segment === '') {
                continue;
            }

            $parts = self::splitArguments($segment);
            $name = trim((string) array_shift($parts));

            $filters[] = [$name, $parts];
        }

        return $filters;
    }

    /**
     * Splits `name:arg1:'an:arg':arg3` into `[name, arg1, an:arg, arg3]`.
     * Quoted arguments may contain the `:` separator (date formats!);
     * backslashes escape inside quotes.
     *
     * @return list<string>
     */
    public static function splitArguments(string $segment): array
    {
        $parts = [];
        $current = '';
        $quote = null;
        $length = \strlen($segment);

        for ($index = 0; $index < $length; ++$index) {
            $char = $segment[$index];

            if ($quote !== null) {
                if ($char === '\\' && $index + 1 < $length) {
                    $current .= $segment[++$index];
                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                    continue;
                }

                $current .= $char;
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === ':') {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $parts[] = trim($current);

        return $parts;
    }
}
