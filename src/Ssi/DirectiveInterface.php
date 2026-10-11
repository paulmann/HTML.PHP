<?php

declare(strict_types=1);

namespace HtmlPhp\Ssi;

/**
 * Contract for custom SSI directives.
 *
 * Register through {@see DirectiveRegistry::register()}; a registered name
 * overrides a built-in of the same name, letting hosts replace e.g.
 * `#include` resolution with their own (WordPress template parts, S3, …).
 */
interface DirectiveInterface
{
    /** Directive name without the `#` (lowercase, e.g. `mywidget`). */
    public function name(): string;

    /**
     * Renders the directive.
     *
     * @param array<string, string> $attributes lower-cased attribute map
     * @param SsiContext $context current document state (vars, budgets, paths)
     * @return string output to splice into the document ('' for side-effect-only directives)
     */
    public function render(array $attributes, SsiContext $context): string;
}
