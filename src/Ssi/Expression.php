<?php

declare(strict_types=1);

namespace HtmlPhp\Ssi;

use HtmlPhp\Diagnostics;
use HtmlPhp\Io\Files;
use HtmlPhp\Support\Str;

/**
 * mod_include `#if` expression evaluator.
 *
 * Grammar (Apache-compatible): OR/AND/NOT, comparisons (`=`, `==`, `!=`,
 * `<`, `<=`, `>`, `>=`), `/regex/` operands, file tests (`-f -d -e -s -x
 * -r -w -F`; `-A`/`-U` are accepted but always false — a template engine
 * performs no subrequests), `$VAR`/`${VAR}` expansion, quoted strings and
 * grouping. Everything is a string (mod_include semantics); numeric-looking
 * operands compare numerically for the ordering operators.
 *
 * Evaluation never throws: syntax errors become diagnostics + `false`.
 */
final class Expression
{
    /** @var list<array{0: string, 1: string}> token stream [type, value] */
    private array $tokens = [];
    private int $position = 0;
    private SsiContext $context;

    private function __construct(SsiContext $context)
    {
        $this->context = $context;
    }

    /**
     * Evaluates an expression string; errors yield false.
     */
    public static function evaluate(string $expression, SsiContext $context): bool
    {
        $expression = trim($expression);

        if ($expression === '') {
            return false;
        }

        if (\strlen($expression) > $context->limits()->maxExpressionLength) {
            $context->diagnostics()->record(
                Diagnostics::SSI_EXPR_ERROR,
                'SSI expression is too long.',
                ['length' => \strlen($expression)],
            );

            return false;
        }

        $parser = new self($context);

        try {
            $parser->tokens = $parser->tokenize($expression);
            $result = $parser->parseOr();

            if ($parser->position < \count($parser->tokens)) {
                $context->diagnostics()->record(
                    Diagnostics::SSI_EXPR_ERROR,
                    'Trailing input in SSI expression.',
                    ['expression' => $expression],
                );

                return false;
            }

            return self::truthy($result);
        } catch (ExpressionSyntaxError $error) {
            $context->diagnostics()->record(
                Diagnostics::SSI_EXPR_ERROR,
                'Invalid SSI expression: ' . $error->getMessage(),
                ['expression' => $expression],
            );

            return false;
        }
    }

    /**
     * mod_include truthiness: '' and '0' are false, '1' from comparisons.
     */
    public static function truthy(string $value): bool
    {
        return $value !== '' && $value !== '0';
    }

    // ---------------------------------------------------------------- lexer

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function tokenize(string $source): array
    {
        $tokens = [];
        $length = \strlen($source);
        $index = 0;

        while ($index < $length) {
            $char = $source[$index];

            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                ++$index;
                continue;
            }

            if ($char === '"' || $char === "'") {
                [$value, $index] = $this->readQuoted($source, $index);
                $tokens[] = ['string', $value];
                continue;
            }

            if ($char === '$') {
                [$value, $index] = $this->readVariable($source, $index);
                $tokens[] = ['var', $value];
                continue;
            }

            if ($char === '/' && $this->regexAllowed($tokens)) {
                [$value, $index] = $this->readRegex($source, $index);
                $tokens[] = ['regex', $value];
                continue;
            }

            if ($char === '!' && ($source[$index + 1] ?? '') === '=') {
                $tokens[] = ['!=', '!='];
                $index += 2;
                continue;
            }

            if ($char === '(' || $char === ')' || $char === '!') {
                $tokens[] = [$char, $char];
                ++$index;
                continue;
            }

            if ($char === '&' || $char === '|') {
                $pair = substr($source, $index, 2);

                if ($pair === '&&' || $pair === '||') {
                    $tokens[] = [$pair, $pair];
                    $index += 2;
                    continue;
                }

                if ($char === '|' && ($source[$index + 1] ?? '') !== '|') {
                    // Single | is not supported; treat as or with a warning-free fallback.
                    $tokens[] = ['||', '||'];
                    ++$index;
                    continue;
                }

                throw new ExpressionSyntaxError('unexpected "' . $char . '"');
            }

            if ($char === '<' || $char === '>' || $char === '=') {
                $next = $source[$index + 1] ?? '';
                $operator = $char;

                if ($next === '=' || ($char === '=' && $next === '=')) {
                    $operator = $char . '=';
                    $index += 2;
                } else {
                    ++$index;
                }

                $tokens[] = [$operator, $operator];
                continue;
            }

            if ($char === '-' && $this->fileTestAt($source, $index)) {
                $tokens[] = ['filetest', substr($source, $index, 2)];
                $index += 2;
                continue;
            }

            if (preg_match('~[A-Za-z0-9_.,:;@+/\\\\*-]+~', $source, $matches, 0, $index) === 1) {
                $tokens[] = ['word', $matches[0]];
                $index += \strlen($matches[0]);
                continue;
            }

            throw new ExpressionSyntaxError('unexpected character "' . $char . '"');
        }

        return $tokens;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function readQuoted(string $source, int $index): array
    {
        $quote = $source[$index];
        $length = \strlen($source);
        $value = '';
        ++$index;

        while ($index < $length) {
            $char = $source[$index];

            if ($char === '\\' && $index + 1 < $length) {
                $next = $source[$index + 1];
                $value .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    default => $next,
                };
                $index += 2;
                continue;
            }

            if ($char === $quote) {
                return [$value, $index + 1];
            }

            $value .= $char;
            ++$index;
        }

        throw new ExpressionSyntaxError('unterminated string literal');
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function readVariable(string $source, int $index): array
    {
        if (($source[$index + 1] ?? '') === '{') {
            if (preg_match('~\$\{([A-Za-z_][A-Za-z0-9_.-]*)\}~A', $source, $matches, 0, $index) === 1) {
                return [$matches[1], $index + \strlen($matches[0])];
            }

            throw new ExpressionSyntaxError('malformed ${NAME} reference');
        }

        if (preg_match('~\$([A-Za-z_][A-Za-z0-9_.-]*)~A', $source, $matches, 0, $index) === 1) {
            return [$matches[1], $index + \strlen($matches[0])];
        }

        throw new ExpressionSyntaxError('malformed $NAME reference');
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function readRegex(string $source, int $index): array
    {
        $length = \strlen($source);
        $value = '';
        ++$index;

        while ($index < $length) {
            $char = $source[$index];

            if ($char === '\\' && $index + 1 < $length) {
                $value .= $char . $source[$index + 1];
                $index += 2;
                continue;
            }

            if ($char === '/') {
                return [$value, $index + 1];
            }

            $value .= $char;
            ++$index;
        }

        throw new ExpressionSyntaxError('unterminated regular expression');
    }

    /**
     * A slash opens a regex only after an operator, '(' or ',' — never
     * after a value (there "a / b" would be a division we do not have).
     *
     * @param list<array{0: string, 1: string}> $tokens
     */
    private function regexAllowed(array $tokens): bool
    {
        if ($tokens === []) {
            return true;
        }

        $previous = $tokens[\count($tokens) - 1][0];

        // After a file-test operator ("/" starts a PATH word, not a regex).
        return \in_array($previous, ['=', '==', '!=', '(', '!', '&&', '||'], true);
    }

    private function fileTestAt(string $source, int $index): bool
    {
        $pair = substr($source, $index, 2);

        return \in_array($pair, ['-f', '-d', '-e', '-s', '-x', '-r', '-w', '-A', '-F', '-U'], true)
            && !preg_match('~[A-Za-z0-9_]~', $source[$index + 2] ?? ' ');
    }

    // ---------------------------------------------------------------- parser

    /** @return array{0: string, 1: string}|null */
    private function peek(): ?array
    {
        return $this->tokens[$this->position] ?? null;
    }

    /** @return array{0: string, 1: string} */
    private function consume(): array
    {
        $token = $this->peek();

        if ($token === null) {
            throw new ExpressionSyntaxError('unexpected end of expression');
        }

        ++$this->position;

        return $token;
    }

    private function parseOr(): string
    {
        $left = $this->parseAnd();

        while (($token = $this->peek()) !== null && $token[0] === '||') {
            ++$this->position;
            $right = $this->parseAnd();
            $left = (self::truthy($left) || self::truthy($right)) ? '1' : '';
        }

        return $left;
    }

    private function parseAnd(): string
    {
        $left = $this->parseNot();

        while (($token = $this->peek()) !== null && $token[0] === '&&') {
            ++$this->position;
            $right = $this->parseNot();
            $left = (self::truthy($left) && self::truthy($right)) ? '1' : '';
        }

        return $left;
    }

    private function parseNot(): string
    {
        if (($token = $this->peek()) !== null && $token[0] === '!') {
            ++$this->position;

            return self::truthy($this->parseNot()) ? '' : '1';
        }

        return $this->parseComparison();
    }

    private function parseComparison(): string
    {
        [$leftValue, $leftRegex] = $this->parseOperand();
        $token = $this->peek();

        if ($token === null || !\in_array($token[0], ['=', '==', '!=', '<', '<=', '>', '>='], true)) {
            if ($leftRegex) {
                throw new ExpressionSyntaxError('a /regex/ needs a comparison operator');
            }

            return $leftValue;
        }

        ++$this->position;
        [$rightValue, $rightRegex] = $this->parseOperand();
        $operator = $token[0] === '==' ? '=' : $token[0];

        return $this->compare($operator, $leftValue, $leftRegex, $rightValue, $rightRegex) ? '1' : '';
    }

    /**
     * @return array{0: string, 1: bool} value + "is regex" flag
     */
    private function parseOperand(): array
    {
        $token = $this->peek();

        if ($token !== null && $token[0] === 'filetest') {
            ++$this->position;
            [$value] = $this->parseOperand();

            return [$this->fileTest($token[1], $value) ? '1' : '', false];
        }

        $token = $this->consume();

        return match ($token[0]) {
            '(' => [$this->parseGrouped(), false],
            'string', 'word' => [$token[1], false],
            'regex' => [$token[1], true],
            'var' => [$this->resolveVariable($token[1]), false],
            default => throw new ExpressionSyntaxError('unexpected token "' . $token[1] . '"'),
        };
    }

    private function parseGrouped(): string
    {
        $value = $this->parseOr();
        $token = $this->peek();

        if ($token === null || $token[0] !== ')') {
            throw new ExpressionSyntaxError('missing closing parenthesis');
        }

        ++$this->position;

        return $value;
    }

    private function compare(string $operator, string $left, bool $leftRegex, string $right, bool $rightRegex): bool
    {
        if ($operator === '=' || $operator === '!=') {
            if ($rightRegex) {
                $matched = $this->regexMatch($right, $left);

                return $operator === '=' ? $matched : !$matched;
            }

            if ($leftRegex) {
                $matched = $this->regexMatch($left, $right);

                return $operator === '=' ? $matched : !$matched;
            }

            $equal = $left === $right;

            return $operator === '=' ? $equal : !$equal;
        }

        if ($leftRegex || $rightRegex) {
            throw new ExpressionSyntaxError('ordering comparisons do not accept /regex/ operands');
        }

        if (Str::isNumericString($left) && Str::isNumericString($right)) {
            $a = Str::numericValue($left);
            $b = Str::numericValue($right);

            return match ($operator) {
                '<' => $a < $b,
                '<=' => $a <= $b,
                '>' => $a > $b,
                '>=' => $a >= $b,
                default => false,
            };
        }

        $order = strcmp($left, $right);

        return match ($operator) {
            '<' => $order < 0,
            '<=' => $order <= 0,
            '>' => $order > 0,
            '>=' => $order >= 0,
            default => false,
        };
    }

    private function regexMatch(string $pattern, string $subject): bool
    {
        $delimited = '~' . str_replace('~', '\\~', $pattern) . '~';
        $result = @preg_match($delimited, $subject);

        if ($result === false) {
            $this->context->diagnostics()->record(
                Diagnostics::SSI_EXPR_ERROR,
                'Invalid regular expression in SSI condition.',
                ['pattern' => $pattern],
            );

            return false;
        }

        return $result === 1;
    }

    private function fileTest(string $operator, string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        if ($operator === '-A' || $operator === '-U') {
            // Apache answers these with internal subrequests; a template
            // engine must not perform network I/O, so they evaluate to
            // false here (recorded for visibility).
            $this->context->diagnostics()->record(
                Diagnostics::SSI_EXPR_ERROR,
                'SSI file test ' . $operator . ' (subrequest) is not supported and evaluates to false.',
                ['path' => $path],
            );

            return false;
        }

        // Path containment mirrors #include semantics.
        $resolved = $this->context->resolvePath($path);

        if ($operator === '-d') {
            $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\')
                ? rtrim($this->context->documentRoot(), "/\\") . '/' . ltrim(str_replace('\\', '/', $path), '/')
                : null;

            if ($absolute !== null) {
                $real = realpath($absolute);

                if ($real !== false && is_dir($real)) {
                    if ($this->context->config()->allowParentPaths
                        || Files::isInside($real, $this->context->documentRoot())
                    ) {
                        return true;
                    }
                }
            }

            return false;
        }

        if ($resolved === null) {
            return false;
        }

        return match ($operator) {
            '-f', '-F' => is_file($resolved),
            '-e' => true,
            '-s' => (@filesize($resolved) ?: 0) > 0,
            '-x' => is_executable($resolved),
            '-r' => is_readable($resolved),
            '-w' => is_writable($resolved),
            default => false,
        };
    }

    /**
     * Variable lookup shared with #echo and #set expansion: built-ins win,
     * then #set variables, then the injected scope. Reserved names and the
     * echo allow-list are enforced inside the context.
     */
    private function resolveVariable(string $name): string
    {
        return $this->context->variable($name);
    }
}
