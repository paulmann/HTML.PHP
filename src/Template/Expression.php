<?php

declare(strict_types=1);

namespace HtmlPhp\Template;

use HtmlPhp\Exception\TemplateSyntaxException;
use HtmlPhp\Scope;
use HtmlPhp\Support\Pcre;
use HtmlPhp\Support\Str;

/**
 * Safe expression language for `<!--@If-->`, `<!--@Elif-->`, `<!--@Set-->`,
 * loop ranges, `<!--@Trans count-->` and (since v5) inline `%{= …}`
 * substitutions.
 *
 * A hand-written tokenizer + precedence-climbing parser evaluates against
 * the render {@see Scope}. There is **no** `eval()`, no dynamic function
 * calls and no property writes: only literals, dotted names, index access,
 * arithmetic, comparisons, logical operators, ternary/coalesce, `in`,
 * `matches`, string concatenation (`~`) and a whitelist of pure functions.
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
 *   unary     := ('-'|'+') unary | postfix
 *   postfix   := primary ('[' expr ']' | '.' name)*   (v5: index/property chains)
 *   primary   := literal | name ('.' name)* | call | '(' expr ')' | range
 *
 * Security budget (v5): parse trees are memoized behind a hard FIFO cap,
 * every walk of host-supplied iterables is capped at
 * {@see Str::MAX_AGGREGATE_ITEMS}, dynamic keys pass
 * {@see Scope::index()} and indirect names pass {@see Scope::find()} only
 * after {@see Str::isValidVariableName()} — an expression can compute with
 * data but can never touch the filesystem, the network, a class name or a
 * callable.
 */
final class Expression
{
    /** Whitelisted pure functions callable from expressions. */
    public const array FUNCTIONS = [
        'count', 'length', 'empty', 'defined', 'min', 'max', 'abs',
        'round', 'floor', 'ceil', 'int', 'float', 'str', 'lower',
        'upper', 'number', 'default',
        // v5 — math & indirect access:
        'pow', 'sqrt', 'sum', 'avg', 'clamp', 'get',
    ];

    private const int MAX_DEPTH = 32;

    /** FIFO cap on the memoized-AST registry (unbounded memos are a DoS vector). */
    private const int MAX_AST_CACHE = 4096;

    /** Ceiling for a dynamic (`get()`) path — a lookup key is data, not a document. */
    private const int MAX_PATH_LENGTH = 256;

    /** @var list<array{0: string, 1: mixed}> */
    private array $tokens = [];

    private int $position = 0;
    private int $depth = 0;

    /** @var array<string, array<array-key, mixed>> memoized ASTs keyed by source */
    private static array $asts = [];

    private function __construct() {}

    /**
     * Evaluates `$source` against `$scope`.
     *
     * Parse results are memoized per expression string, so the same
     * condition inside a 10 000-row loop is parsed once. The memo is a
     * bounded FIFO: a template full of unique expressions can evict old
     * parses but can never grow the registry without limit.
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

        if (isset(self::$asts[$key])) {
            return self::$asts[$key];
        }

        $expression = new self();
        $expression->tokens = $expression->tokenize(self::guardLength($source, $maxLength));
        $expression->position = 0;
        $tree = $expression->parseExpression();
        $expression->expectEnd($source);

        if (\count(self::$asts) >= self::MAX_AST_CACHE) {
            $oldest = array_key_first(self::$asts);

            if ($oldest !== null) {
                unset(self::$asts[$oldest]);
            }
        }

        return self::$asts[$key] = $tree;
    }

    /** Truthiness used by `<!--@If-->`: PHP rules, `'0'`/`''`/`[]`/null are false. */
    public static function truthy(mixed $value): bool
    {
        if (\is_string($value)) {
            return $value !== '' && $value !== '0';
        }

        if (\is_array($value)) {
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

        if (\strlen($source) > $maxLength) {
            throw new TemplateSyntaxException(
                'Expression is too long.',
                ['length' => \strlen($source), 'limit' => $maxLength],
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
        $length = \strlen($source);
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
                $index += \strlen($m[0]);
                continue;
            }

            if ($char === '.' && ($source[$index + 1] ?? '') === '.') {
                $tokens[] = ['op', '..'];
                $index += 2;
                continue;
            }

            // `.name` only tokenizes standalone directly after a postfix
            // (`rows[0].name`); everywhere else a dot is part of a dotted
            // name or a numeric literal, both handled above.
            if ($char === '.' && (ctype_alpha($source[$index + 1] ?? '') || ($source[$index + 1] ?? '') === '_')) {
                $tokens[] = ['op', '.'];
                ++$index;
                continue;
            }

            if (ctype_alpha($char) || $char === '_') {
                preg_match('~[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*~A', $source, $m, 0, $index);
                $word = $m[0];
                $index += \strlen($word);
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
                '+', '-', '*', '/', '%', '~', '(', ')', '[', ']', ',', '?', ':'] as $candidate) {
                if (str_starts_with(substr($source, $index), $candidate)) {
                    $tokens[] = ['op', $candidate];
                    $index += \strlen($candidate);
                    continue 2;
                }
            }

            throw new TemplateSyntaxException(
                'Unexpected character "' . $char . '" in expression.',
                ['position' => $index],
            );
        }

        return $tokens;
    }

    /** @return array{0: string, 1: int} */
    private function readString(string $source, int $index): array
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

        while (($token = $this->peek()) !== null && $token[0] === 'op' && \in_array($token[1], $operators, true)) {
            $operator = $token[1];
            $this->next();

            if ($operator === 'matches') {
                $pattern = $this->parseAdditive();

                if ($pattern[0] !== 'lit' || !\is_string($pattern[1])) {
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

        while (($token = $this->peek()) !== null && $token[0] === 'op' && \in_array($token[1], $operators, true)) {
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

        return $this->parsePostfix();
    }

    /**
     * postfix := primary ('[' expr ']' | '.' name)*
     *
     * Index and property chains (`rows[0].name`, `hash[keyVar].total`) hang
     * off any primary — names, calls and parenthesised expressions alike.
     * Every `[` opens a nested {@see self::parseExpression()}, so the
     * existing depth guard bounds arbitrarily chained access.
     *
     * @return array<array-key, mixed>
     */
    private function parsePostfix(): array
    {
        $node = $this->parsePrimary();

        while (true) {
            if ($this->isOp('[')) {
                $this->next();
                $index = $this->parseExpression();
                $this->expectOp(']');
                $node = ['index', $node, $index];
                continue;
            }

            if ($this->isOp('.')) {
                $this->next();
                $token = $this->peek();

                if ($token === null || $token[0] !== 'name') {
                    throw new TemplateSyntaxException('Expected a property name after ".".');
                }

                $this->next();
                $node = ['prop', $node, (string) $token[1]];
                continue;
            }

            break;
        }

        return $node;
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
            $node = ['lit', $token[1]];

            // Numeric range literal: `1..10` (integer ranges, loop semantics).
            if ($token[0] === 'num' && $this->isOp('..')) {
                $this->next();

                return ['range', $node, $this->parseUnary()];
            }

            return $node;
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
        if ($this->position < \count($this->tokens)) {
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
            'index' => self::indexInto(self::reduceNode($node[1], $scope), self::reduceNode($node[2], $scope)),
            'prop' => self::property(self::reduceNode($node[1], $scope), (string) $node[2]),
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

        if (\is_string($left) && \is_string($right)) {
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
        if (\is_array($haystack)) {
            return \in_array($needle, $haystack, false);
        }

        if (\is_string($haystack) && (\is_string($needle) || is_numeric($needle))) {
            return $haystack !== '' && str_contains($haystack, Str::stringify($needle));
        }

        if ($haystack instanceof \Traversable) {
            $seen = 0;

            foreach ($haystack as $item) {
                if (++$seen > Str::MAX_AGGREGATE_ITEMS) {
                    break; // iteration budget: an infinite generator must not hang the render
                }

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

        // The probe must distinguish "invalid pattern" (false) from "valid
        // pattern that does not match the empty string" (0) — hence the raw
        // call here; the real match then goes through the Pcre guard so a
        // backtrack-limit hit (ReDoS-shaped pattern) is a clean no-match.
        if ($pattern === '' || @preg_match($pattern, '') === false) {
            $delimited = '~' . str_replace('~', '\\~', $pattern) . '~';
        }

        return Pcre::match($delimited, Str::stringify($subject));
    }

    /**
     * `value[key]` — the nested-variable primitive.
     *
     * Keys and container kinds are validated in {@see Scope::index()}:
     * int/string keys only, arrays / `ArrayAccess` / string offsets only,
     * capped key length, no dynamic property or method access on objects.
     * A miss is `null`, never a warning or a fatal.
     */
    private static function indexInto(mixed $value, mixed $key): mixed
    {
        [$found, $result] = Scope::index($value, $key);

        return $found ? $result : null;
    }

    /**
     * `value.name` after a postfix (`rows[0].name`) — the same walk the
     * dotted-name resolver uses, applied to an intermediate value.
     */
    private static function property(mixed $value, string $path): mixed
    {
        [$found, $result] = Scope::step($value, $path);

        return $found ? $result : null;
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
        if (!\in_array($name, self::FUNCTIONS, true)) {
            throw new TemplateSyntaxException(
                'Function "' . $name . '" is not allowed in expressions.',
                ['allowed' => self::FUNCTIONS],
            );
        }

        $values = array_map(static fn(array $node): mixed => self::reduceNode($node, $scope), $args);

        return match ($name) {
            'count', 'length' => self::countOf($values[0] ?? null),
            'empty' => !self::truthy($values[0] ?? null),
            'defined' => \is_string($values[0] ?? null) ? $scope->has((string) $values[0]) : ($values[0] ?? null) !== null,
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
            // v5 additions — all pure, all budget-guarded:
            'pow' => Str::power(Str::numericValue($values[0] ?? 0), Str::numericValue($values[1] ?? 0)),
            'sqrt' => Str::sqrt(Str::numericValue($values[0] ?? 0)),
            'sum' => Str::accumulate($values[0] ?? null)[0],
            'avg' => self::average($values[0] ?? null),
            'clamp' => self::clamp(
                Str::numericValue($values[0] ?? 0),
                Str::numericValue($values[1] ?? 0),
                Str::numericValue($values[2] ?? 0),
            ),
            'get' => self::indirect($values, $scope),
        };
    }

    /**
     * `get(name)` / `get(name, default)` — indirect access: resolves a
     * variable whose *name* is itself template data ("a variable inside a
     * variable").
     *
     * Accepted path grammar (parsed by {@see self::parsePath()}, never
     * evaluated): a dotted head name plus literal `[…]` segments and
     * `.name` continuations — `user.email`, `rows[1].name`, `hash['x-1 y']`.
     * Malformed, overlong or hostile paths (null bytes, unquoted bracket
     * keys, leading dots/digits) resolve to the default. Lookups go
     * **only** through the render scope stack — there is no global,
     * constant, environment or filesystem fallback, so data-controlled
     * names cannot escape the sandbox.
     *
     * @param list<mixed> $values
     */
    private static function indirect(array $values, Scope $scope): mixed
    {
        $name = $values[0] ?? null;
        $default = $values[1] ?? null;

        if (!\is_string($name)) {
            return $default;
        }

        $path = self::parsePath($name);

        if ($path === null) {
            return $default;
        }

        [$found, $value] = $scope->find($path['head']);

        if (!$found) {
            return $default;
        }

        foreach ($path['steps'] as $step) {
            [$found, $value] = $step[0] === 'index'
                ? Scope::index($value, $step[1])
                : Scope::step($value, $step[1]);

            if (!$found) {
                return $default;
            }
        }

        return $value;
    }

    /**
     * Validates and segments a dynamic path for {@see self::indirect()}.
     *
     * Returns `['head' => dotted-name, 'steps' => [['index', int|string] |
     * ['path', dotted-name], …]]` or `null` when anything about the path is
     * not strictly well-formed. This is a *parser*, not an evaluator: the
     * only bracket keys are integer literals or single/double-quoted string
     * literals (no null bytes, ≤ 255 chars), name runs use the same
     * character class as {@see Str::isValidVariableName()} and a name run
     * after `]` must start with `.`.
     *
     * @return array{head: string, steps: list<array{0: string, 1: int|string}>}|null
     */
    private static function parsePath(string $path): ?array
    {
        if ($path === '' || \strlen($path) > self::MAX_PATH_LENGTH || str_contains($path, "\0")) {
            return null;
        }

        /** @var list<array{0: string, 1: int|string}> $steps */
        $steps = [];
        $length = \strlen($path);

        // Head: a plain dotted name (`user.email`) resolved through Scope::find().
        if (preg_match('~\A[A-Za-z_][A-Za-z0-9_.-]*~', $path, $m) !== 1) {
            return null;
        }

        $head = $m[0];

        if (!self::isSaneDottedRun($head)) {
            return null;
        }

        $index = \strlen($head);

        while ($index < $length) {
            if ($path[$index] === '[') {
                $close = strpos($path, ']', $index);

                if ($close === false) {
                    return null;
                }

                $key = self::parseBracketKey(substr($path, $index + 1, $close - $index - 1));

                if ($key === null) {
                    return null;
                }

                $steps[] = ['index', $key];
                $index = $close + 1;
                continue;
            }

            if ($path[$index] === '.') {
                if (preg_match('~\G\.[A-Za-z0-9_][A-Za-z0-9_.-]*~', $path, $m, 0, $index) !== 1) {
                    return null;
                }

                $run = substr($m[0], 1);

                if (!self::isSaneDottedRun($run)) {
                    return null;
                }

                $steps[] = ['path', $run];
                $index += \strlen($m[0]);
                continue;
            }

            // A name run directly after `]` (no dot) — rejected: `a[0]b` is
            // ambiguous nonsense; `a[0].b` is the documented form.
            return null;
        }

        return ['head' => $head, 'steps' => $steps];
    }

    /**
     * No empty segments (`a..b`), no trailing dots (`a.`). Every segment is
     * a word run; digits may start any segment but the first — the head's
     * first character is checked by the caller's anchor regex, mirroring
     * {@see Str::isValidVariableName()}.
     */
    private static function isSaneDottedRun(string $run): bool
    {
        foreach (explode('.', $run) as $segment) {
            if ($segment === '' || preg_match('~^[A-Za-z0-9_][A-Za-z0-9_-]*$~', $segment) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** Integer literal or quoted string literal; anything else is rejected. */
    private static function parseBracketKey(string $raw): int|string|null
    {
        if (preg_match('~^-?\d{1,18}$~', $raw) === 1) {
            return (int) $raw;
        }

        $quote = $raw[0] ?? '';

        if (($quote === "'" || $quote === '"')
            && \strlen($raw) >= 2
            && $raw[\strlen($raw) - 1] === $quote
            && !str_contains($raw, "\0")
        ) {
            $key = substr($raw, 1, -1);

            // No quote of the same kind inside — keeps the literal unambiguous.
            return str_contains($key, $quote) ? null : ($key === '' ? null : $key);
        }

        return null;
    }

    private static function average(mixed $value): float
    {
        [$sum, $count] = Str::accumulate($value);

        return $count === 0 ? 0.0 : $sum / $count;
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return max($min, min($max, $value));
    }

    private static function countOf(mixed $value): int
    {
        return match (true) {
            \is_array($value) => \count($value),
            $value instanceof \Countable => \count($value),
            // Capped walk: an infinite host-supplied generator degrades to
            // the iteration budget instead of hanging the render.
            $value instanceof \Traversable => Str::accumulate($value)[1],
            \is_string($value) => Str::length($value),
            default => $value === null ? 0 : 1,
        };
    }

    private static function numeric(mixed $value): float|int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value)) {
            return is_finite($value) ? $value : 0.0;
        }

        return Str::numericValue($value);
    }
}
