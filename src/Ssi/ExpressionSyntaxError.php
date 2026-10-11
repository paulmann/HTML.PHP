<?php

declare(strict_types=1);

namespace HtmlPhp\Ssi;

/**
 * Internal signal for an unparseable `#if` expression.
 *
 * Never escapes the engine: {@see Expression::evaluate()} catches it,
 * records a diagnostic and yields `false` (mod_include parity).
 */
final class ExpressionSyntaxError extends \Exception {}
