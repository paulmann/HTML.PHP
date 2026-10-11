<?php

declare(strict_types=1);

namespace HtmlPhp\Template;

use HtmlPhp\Exception\TemplateSyntaxException;
use HtmlPhp\Scope;
use HtmlPhp\Support\Str;

/**
 * Safe expression language for `<!--@If-->`, `<!--@Elif-->`, `<!--@Set-->`,
 * loop ranges and `<!--@Trans count-->`.
 *
 * A hand-written tokenizer + precedence-climbing parser evaluates against
 * the render {@see Scope}. There is **no** `eval()`, no dynamic function
 * calls and no property writes: only literals, dotted names, arithmetic,
 * comparisons, logical operators, ternary/coalesce, `in`, `matches`,
 * string concatenation (`~`) and a whitelist of pure functions.
 *
 * Grammar (loosest first):
 *   expr      := ternary
 *   ternary   := coalesce ('?' expr ':' expr)?
 *   coalesce  := or ('??' or)*
 *   or        := and ('||' and)*            (alias: `or`)
 *   and       := not ('&&' not)*            (alias: `and`)
 *   not       := ('!'|'not') not | compare
 *   compare   := additive (('=='|'!='|'==='|'!=='|'<'|'<='|'>'|'>='|'in'|'matches') additive)*
 *   additive  := concat (('+'|'-') concat)*
 *   concat    := multiplicative ('~' multiplicative)*
 *   multiplicative := unary (('*'|'/'|'%') unary)*
 *   unary     := ('-'|'+') unary | primary
 *   primary   := literal | name ('.' name)* | call | '(' expr ')' | range
 */
final class Expression
{
    /** Whitelisted pure functions callable from expressions. */
    public const array FUNCTIONS = [
        'count', 'length', 'empty', 'defined', 'min', 'max', 'abs',
        'round', 'floor', 'ceil', 'int', 'float', 'str', 'lower',
        'upper', 'number', 'default',
    ];

    private const int MAX_DEPTH = 32;

    /** @var list<array{0: string, 1: mixed}> */
    private array $tokens = [];

    private int $position = 0;
    private int $depth = 0;

    /** @var array<string, array<array-key, mixed>> memoized ASTs keyed by source */
    private static array $asts = [];

    private function __construct()
    {
    }

    /**
     * Evaluates `$source` against `$scope`.
     *
     * Parse results are memoized per expression string, so the same
     * condition inside a 10 000-row loop is parsed once.
     *
     * @throws TemplateSyntaxException on malformed expressions (strict
     *         language design: a broken condition is a template bug)
     */
    public static function evaluate(string $source, Scope $scope, int $maxLength = 4096): mixed
    {
        return self::reduceNode(self::ast($source, $maxLength), $scope);
    }

    /** @return array<array-key, mixed> */
    private static function ast(string $source, int $maxLength): array
    {
        $key = $maxLength . '|' . $source;

        return self::$asts[$key] ??= (function () use ($source, $maxLength): array {
            $expression = new self();
            $expression->tokens = $expression->tokenize(self::guardLength($source, $maxLength));
            $expression->position = 0;
            $tree = $expression->parseExpression();
            $expression->expectEnd($source);

            return $tree;
        })();
    }

    /** Truthiness used by `<!--@If-->`: PHP rules, `'0'`/`''`/`[]`/null are false. */
    public static function truthy(mixed $value): bool
    {
        if (is_string($value)) {
            return $value !== '' && $value !== '0';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return (bool) $value;
    }

    /** Validates syntax without evaluating (used by the linter). */
    public static function validate(string $source, int $maxLength = 4096): void
    {
        self::ast($source, $maxLength);
    }

    /** Drops memoized parse trees (long-running workers after deploys). */
    public static function reset(): void
    {
        self::$asts = [];
    }

    private static function guardLength(string $source, int $maxLength): string
    {
        $source = trim($source);

        if ($source === '') {
            throw new TemplateSyntaxException('Empty expression.');
        }

        if (strlen($source) > $maxLength) {
            throw new TemplateSyntaxException(
                'Expression is too long.',
                ['length' => strlen($source), 'limit' => $maxLength]
            );
        }

        return $source;
    }

    // ------------------------------------------------------------------ lexer

    /**
     * @return list<array{0: string, 1: mixed}>
     */
    private function tokenize(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $index = 0;

        while ($index < $length) {
            $char = $source[$index];

            if (ctype_space($char)) {
                ++$index;
                continue;
            }

            if ($char === '"' || $char === "'") {
                [$value, $index] = $this->readString($source, $index);
                $tokens[] = ['str', $value];
                continue;
            }

            if (ctype_digit($char) || ($char === '.' && ctype_digit($source[$index + 1] ?? ''))) {
                preg_match('~\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|\.\d+~A', $source, $m, 0, $index);
                $tokens[] = ['num', str_contains($m[0], '.') || str_contains($m[0], 'e') || str_contains($m[0], 'E')
                    ? (float) $m[0]
                    : (int) $m[0]];
                $index += strlen($m[0]);
                continue;
            }

            if ($char === '.' && ($source[$index + 1] ?? '') === '.') {
                $tokens[] = ['..', '..'];
                $index += 2;
                continue;
            }

            if (ctype_alpha($char) || $char === '_') {
                preg_match('~[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*~A', $source, $m, 0, $index);
                $word = $m[0];
                $index += strlen($word);
                $lower = strtolower($word);
                $tokens[] = match ($lower) {
                    'true' => ['bool', true],
                    'false' => ['bool', false],
                    'null' => ['null', null],
                    'and' => ['op', '&&'],
                    'or' => ['op', '||'],
                    'not' => ['op', '!'],
                    'in' => ['op', 'in'],
                    'matches' => ['op', 'matches'],
                    default => ['name', $word],
                };
                continue;
            }

            foreach (['===', '!==', '==', '!=', '<=', '>=', '&&', '||', '??', '<', '>', '!',
                      '+', '-', '*', '/', '%', '~', '(', ')', ',', '?', ':'] as $candidate) {
                if (str_starts_with(substr($source, $index), $candidate)) {
                    $tokens[] = ['op', $candidate];
                    $index += strlen($candidate);
                    continue 2;
                }
            }

            throw new TemplateSyntaxException(
                'Unexpected character "' . $char . '" in expression.',
                ['position' => $index]
            );
        }

        return $tokens;
    }

    /** @return array{0: string, 1: int} */
    private function readString(string $source, int $index): array
    {
        $quote = $source[$index];
        $length = strlen($source);
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

        throw new TemplateSyntaxException('Unterminated string literal in expression.');
    }

    // ----------------------------------------------------------------- parser
    // The parser emits nested arrays instead of evaluating directly, so one
    // tokenize+parse can be reused (memoized) across loop iterations.

    /** @return array<array-key, mixed> */
    private function parseExpression(): array
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw new TemplateSyntaxException('Expression nesting is too deep.');
        }

        try {
            return $this->parseTernary();
        } finally {
            $this->depth--;
        }
    }

    /** @return array<array-key, mixed> */
    private function parseTernary(): array
    {
        $node = $this->parseCoalesce();

        if ($this->isOp('?')) {
            $this->next();
            $then = $this->parseExpression();
            $this->expectOp(':');
            $else = $this->parseExpression();

            return ['ternary', $node, $then, $else];
        }

        return $node;
    }

    /** @return array<array-key, mixed> */
    private function parseCoalesce(): array
    {
        $node = $this->parseBinary(['||'], 'parseAnd');

        while ($this->isOp('??')) {
            $this->next();
            $node = ['coalesce', $node, $this->parseAnd()];
        }

        return $node;
    }

    /** @return array<array-key, mixed> */
    private function parseAnd(): array
    {
        return $this->parseBinary(['&&'], 'parseNot');
    }

    /** @return array<array-key, mixed> */
    private function parseNot(): array
    {
        if ($this->isOp('!')) {
            $this->next();

            return ['not', $this->parseNot()];
        }

        return $this->parseComparison();
    }

    /** @return array<array-key, mixed> */
    private function parseComparison(): array
    {
        $operators = ['==', '!=', '===', '!==', '<', '<=', '>', '>=', 'in', 'matches'];
        $left = $this->parseAdditive();

        while (($token = $this->peek()) !== null && $token[0] === 'op' && in_array($token[1], $operators, true)) {
            $operator = $token[1];
            $this->next();

            if ($operator === 'matches') {
                $pattern = $this->parseAdditive();

                if ($pattern[0] !== 'lit' || !is_string($pattern[1])) {
                    throw new TemplateSyntaxException('The right side of "matches" must be a string literal.');
                }

                $left = ['matches', $left, $pattern[1]];
                continue;
            }

            $left = [$operator, $left, $this->parseAdditive()];
        }

        return $left;
    }

    /** @return array<array-key, mixed> */
    private function parseAdditive(): array
    {
        return $this->parseBinary(['+', '-'], 'parseConcat');
    }

    /** @return array<array-key, mixed> */
    private function parseConcat(): array
    {
        return $this->parseBinary(['~'], 'parseMultiplicative');
    }

    /** @return array<array-key, mixed> */
    private function parseMultiplicative(): array
    {
        return $this->parseBinary(['*', '/', '%'], 'parseUnary');
    }

    /**
     * @param list<string> $operators
     * @return array<array-key, mixed>
     */
    private function parseBinary(array $operators, string $next): array
    {
        $left = $this->{$next}();

        while (($token = $this->peek()) !== null && $token[0] === 'op' && in_array($token[1], $operators, true)) {
            $operator = $token[1];
            $this->next();
            $left = [$operator, $left, $this->{$next}()];
        }

        return $left;
    }

    /** @return array<array-key, mixed> */
    private function parseUnary(): array
    {
        if ($this->isOp('-') || $this->isOp('+')) {
            $operator = $this->next()[1];

            return [$operator === '-' ? 'neg' : 'pos', $this->parseUnary()];
        }

        return $this->parsePrimary();
    }

    /** @return array<array-key, mixed> */
    private function parsePrimary(): array
    {
        $token = $this->peek();

        if ($token === null) {
            throw new TemplateSyntaxException('Unexpected end of expression.');
        }

        if ($token[0] === 'num' || $token[0] === 'str' || $token[0] === 'bool' || $token[0] === 'null') {
            $this->next();

            return ['lit', $token[1]];
        }

        if ($token[0] === 'op' && $token[1] === '(') {
            $this->next();
            $node = $this->parseExpression();

            // Range: (a..b) or a..b handled at name level too.
            if ($this->isOp('..')) {
                $this->next();
                $to = $this->parseExpression();
                $this->expectOp(')');

                return ['range', $node, $to];
            }

            $this->expectOp(')');

            return $node;
        }

        if ($token[0] === 'name') {
            $this->next();
            $name = (string) $token[1];

            // function call
            if ($this->isOp('(')) {
                $this->next();
                $args = [];

                if (!$this->isOp(')')) {
                    $args[] = $this->parseExpression();

                    while ($this->isOp(',')) {
                        $this->next();
                        $args[] = $this->parseExpression();
                    }
                }

                $this->expectOp(')');

                return ['call', strtolower($name), $args];
            }

            // range over names/numbers: 1..10, first..last
            if ($this->isOp('..')) {
                $this->next();
                $to = $this->parseUnary();

                return ['range', ['name', $name], $to];
            }

            return ['name', $name];
        }

        throw new TemplateSyntaxException('Unexpected token in expression.', ['token' => (string) $token[1]]);
    }

    /** @return array{0: string, 1: mixed}|null */
    private function peek(): ?array
    {
        return $this->tokens[$this->position] ?? null;
    }

    /** @return array{0: string, 1: mixed} */
    private function next(): array
    {
        $token = $this->peek();

        if ($token === null) {
            throw new TemplateSyntaxException('Unexpected end of expression.');
        }

        ++$this->position;

        return $token;
    }

    private function isOp(string $op): bool
    {
        $token = $this->peek();

        return $token !== null && $token[0] === 'op' && $token[1] === $op;
    }

    private function expectOp(string $op): void
    {
        if (!$this->isOp($op)) {
            throw new TemplateSyntaxException('Expected "' . $op . '" in expression.');
        }

        $this->next();
    }

    private function expectEnd(string $source): void
    {
        if ($this->position < count($this->tokens)) {
            throw new TemplateSyntaxException('Trailing input in expression.', ['expression' => $source]);
        }
    }

    // -------------------------------------------------------------- evaluator

    /** @param array<array-key, mixed> $node */
    private static function reduceNode(array $node, Scope $scope): mixed
    {
        $op = (string) ($node[0] ?? '');

        return match ($op) {
            'lit' => $node[1],
            'name' => $scope->get((string) $node[1]),
            'neg' => -self::numeric(self::reduceNode($node[1], $scope)),
            'pos' => self::numeric(self::reduceNode($node[1], $scope)),
            'not' => !self::truthy(self::reduceNode($node[1], $scope)),
            '&&' => self::truthy(self::reduceNode($node[1], $scope)) && self::truthy(self::reduceNode($node[2], $scope)),
            '||' => self::truthy(self::reduceNode($node[1], $scope)) || self::truthy(self::reduceNode($node[2], $scope)),
            'coalesce' => self::reduceNode($node[1], $scope) ?? self::reduceNode($node[2], $scope),
            'ternary' => self::truthy(self::reduceNode($node[1], $scope))
                ? self::reduceNode($node[2], $scope)
                : self::reduceNode($node[3], $scope),
            '==' => self::loose(self::reduceNode($node[1], $scope), self::reduceNode($node[2], $scope)),
            '!=' => !self::loose(self::reduceNode($node[1], $scope), self::reduceNode($node[2], $scope)),
            '===' => self::reduceNode($node[1], $scope) === self::reduceNode($node[2], $scope),
            '!==' => self::reduceNode($node[1], $scope) !== self::reduceNode($node[2], $scope),
            '<', '<=', '>', '>=' => self::ordering($op, self::reduceNode($node[1], $scope), self::reduceNode($node[2], $scope)),
            '+' => self::numeric(self::reduceNode($node[1], $scope)) + self::numeric(self::reduceNode($node[2], $scope)),
            '-' => self::numeric(self::reduceNode($node[1], $scope)) - self::numeric(self::reduceNode($node[2], $scope)),
            '*' => self::numeric(self::reduceNode($node[1], $scope)) * self::numeric(self::reduceNode($node[2], $scope)),
            '/' => self::divide(self::reduceNode($node[1], $scope), self::reduceNode($node[2], $scope)),
            '%' => self::modulo(self::reduceNode($node[1], $scope), self::reduceNode($node[2], $scope)),
            '~' => Str::stringify(self::reduceNode($node[1], $scope)) . Str::stringify(self::reduceNode($node[2], $scope)),
            'in' => self::in(self::reduceNode($node[1], $scope), self::reduceNode($node[2], $scope)),
            'matches' => self::matches(self::reduceNode($node[1], $scope), (string) $node[2]),
            'range' => self::range(self::reduceNode($node[1], $scope), self::reduceNode($node[2], $scope)),
            'call' => self::call((string) $node[1], $node[2], $scope),
            default => throw new TemplateSyntaxException('Unknown expression node.', ['op' => $op]),
        };
    }

    private static function loose(mixed $left, mixed $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            return Str::numericValue($left) == Str::numericValue($right);
        }

        if (is_string($left) && is_string($right)) {
            return $left === $right;
        }

        return $left == $right;
    }

    private static function ordering(string $op, mixed $left, mixed $right): bool
    {
        if (is_numeric($left) || is_numeric($right)) {
            $a = Str::numericValue($left);
            $b = Str::numericValue($right);

            return match ($op) {
                '<' => $a < $b,
                '<=' => $a <= $b,
                '>' => $a > $b,
                default => $a >= $b,
            };
        }

        $order = strcmp(Str::stringify($left), Str::stringify($right));

        return match ($op) {
            '<' => $order < 0,
            '<=' => $order <= 0,
            '>' => $order > 0,
            default => $order >= 0,
        };
    }

    private static function divide(mixed $left, mixed $right): float|int
    {
        $divisor = Str::numericValue($right);

        if ($divisor === 0.0) {
            return 0;
        }

        $result = Str::numericValue($left) / $divisor;

        return is_finite($result) ? $result : 0;
    }

    private static function modulo(mixed $left, mixed $right): int
    {
        $divisor = (int) Str::numericValue($right);

        return $divisor === 0 ? 0 : (int) Str::numericValue($left) % $divisor;
    }

    private static function in(mixed $needle, mixed $haystack): bool
    {
        if (is_array($haystack)) {
            return in_array($needle, $haystack, false);
        }

        if (is_string($haystack) && (is_string($needle) || is_numeric($needle))) {
            return $haystack !== '' && str_contains($haystack, Str::stringify($needle));
        }

        if ($haystack instanceof \Traversable) {
            foreach ($haystack as $item) {
                if ($item == $needle) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function matches(mixed $subject, string $pattern): bool
    {
        $delimited = $pattern;

        if ($pattern === '' || @preg_match($pattern, '') === false) {
            $delimited = '~' . str_replace('~', '\\~', $pattern) . '~';
        }

        return @preg_match($delimited, Str::stringify($subject)) === 1;
    }

    /** @return list<int> */
    private static function range(mixed $from, mixed $to): array
    {
        $start = (int) Str::numericValue($from);
        $end = (int) Str::numericValue($to);
        $span = abs($end - $start);

        if ($span > 100_000) {
            throw new TemplateSyntaxException('Loop range is too large.', ['span' => $span]);
        }

        return range($start, $end);
    }

    /** @param list<array<array-key, mixed>> $args */
    private static function call(string $name, array $args, Scope $scope): mixed
    {
        if (!in_array($name, self::FUNCTIONS, true)) {
            throw new TemplateSyntaxException(
                'Function "' . $name . '" is not allowed in expressions.',
                ['allowed' => self::FUNCTIONS]
            );
        }

        $values = array_map(static fn(array $node): mixed => self::reduceNode($node, $scope), $args);

        return match ($name) {
            'count', 'length' => self::countOf($values[0] ?? null),
            'empty' => !self::truthy($values[0] ?? null),
            'defined' => is_string($values[0] ?? null) ? $scope->has((string) $values[0]) : ($values[0] ?? null) !== null,
            'min' => $values === [] ? 0 : min(array_map(Str::numericValue(...), $values)),
            'max' => $values === [] ? 0 : max(array_map(Str::numericValue(...), $values)),
            'abs' => abs(Str::numericValue($values[0] ?? 0)),
            'round' => Str::numericValue(round(Str::numericValue($values[0] ?? 0), (int) Str::numericValue($values[1] ?? 0))),
            'floor' => (float) floor(Str::numericValue($values[0] ?? 0)),
            'ceil' => (float) ceil(Str::numericValue($values[0] ?? 0)),
            'int' => (int) Str::numericValue($values[0] ?? 0),
            'float' => Str::numericValue($values[0] ?? 0),
            'str' => Str::stringify($values[0] ?? ''),
            'lower' => Str::lower(Str::stringify($values[0] ?? '')),
            'upper' => Str::upper(Str::stringify($values[0] ?? '')),
            'number' => Str::number(Str::numericValue($values[0] ?? 0), (int) ($values[1] ?? 2)),
            'default' => ($values[0] ?? null) ?? ($values[1] ?? ''),
        };
    }

    private static function countOf(mixed $value): int
    {
        return match (true) {
            is_array($value) => count($value),
            $value instanceof \Countable => count($value),
            $value instanceof \Traversable => iterator_count($value),
            is_string($value) => Str::length($value),
            default => $value === null || $value === '' ? 0 : 1,
        };
    }

    private static function numeric(mixed $value): float|int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : 0.0;
        }

        return Str::numericValue($value);
    }
}
