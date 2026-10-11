<?php

declare(strict_types=1);

namespace HtmlPhp\Config;

/**
 * What a `%{NAME}` resolves to when the name is absent from every scope frame.
 */
enum MissingVarPolicy: string
{
    /** Substitute an empty string (recorded in diagnostics). */
    case Empty = 'empty';

    /** Leave the placeholder text untouched. */
    case Keep = 'keep';

    /** Substitute a visible debug marker such as `[missing:NAME]`. */
    case Placeholder = 'placeholder';

    /** Abort the render with a {@see \HtmlPhp\Exception\TemplateException}. */
    case Throw = 'throw';
}
