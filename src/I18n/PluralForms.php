<?php

declare(strict_types=1);

namespace HtmlPhp\I18n;

/**
 * Safe evaluator for gettext `Plural-Forms` header expressions.
 *
 * Implements the C-subset grammar gettext actually emits (ternary, ||, &&,
 * ==/!=, </<=/>/>=, %/arithmetics, n) as a tokenizing recursive-descent
 * evaluator — never `eval()`. Ships built-in rules for 60+ languages so a
 * catalogue without the header still pluralises correctly.
 */
final class PluralForms
{
    /** Hard cap — gettext plural counts never legitimately exceed this. */
    public const int MAX_FORMS = 6;

    private const int MAX_EXPRESSION_LENGTH = 512;

    /**
     * Built-in plural rules per language, used when a catalogue has no
     * Plural-Forms header. Key = language code.
     */
    private const array LANGUAGE_RULES = [
        // Single form
        'ay' => 'nplurals=1; plural=0;', 'bo' => 'nplurals=1; plural=0;',
        'cgg' => 'nplurals=1; plural=0;', 'dz' => 'nplurals=1; plural=0;',
        'fa' => 'nplurals=1; plural=0;', 'id' => 'nplurals=1; plural=0;',
        'ja' => 'nplurals=1; plural=0;', 'jbo' => 'nplurals=1; plural=0;',
        'ka' => 'nplurals=1; plural=0;', 'kk' => 'nplurals=1; plural=0;',
        'km' => 'nplurals=1; plural=0;', 'ko' => 'nplurals=1; plural=0;',
        'ky' => 'nplurals=1; plural=0;', 'lo' => 'nplurals=1; plural=0;',
        'ms' => 'nplurals=1; plural=0;', 'my' => 'nplurals=1; plural=0;',
        'sah' => 'nplurals=1; plural=0;', 'sa' => 'nplurals=1; plural=0;',
        'ta' => 'nplurals=1; plural=0;', 'th' => 'nplurals=1; plural=0;',
        'tt' => 'nplurals=1; plural=0;', 'ug' => 'nplurals=1; plural=0;',
        'vi' => 'nplurals=1; plural=0;', 'wo' => 'nplurals=1; plural=0;',
        'zh' => 'nplurals=1; plural=0;',
        // Slavic 3-form family
        'be' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
        'bs' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
        'hr' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
        'ru' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
        'sr' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
        'uk' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
        // Czech/Slovak
        'cs' => 'nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;',
        'sk' => 'nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;',
        // Polish
        'pl' => 'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
        // Baltic
        'lt' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && (n%100<10 || n%100>=20) ? 1 : 2);',
        'lv' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n!=0 ? 1 : 2);',
        // Romanian / Moldavian
        'ro' => 'nplurals=3; plural=(n==1 ? 0 : (n==0 || (n%100>0 && n%100<20)) ? 1 : 2);',
        'mo' => 'nplurals=3; plural=(n==1 ? 0 : (n==0 || (n%100>0 && n%100<20)) ? 1 : 2);',
        // Celtic
        'cy' => 'nplurals=4; plural=(n==1) ? 0 : (n==2) ? 1 : (n != 8 && n != 11) ? 2 : 3;',
        'ga' => 'nplurals=5; plural=(n==1 ? 0 : n==2 ? 1 : n<7 ? 2 : n<11 ? 3 : 4);',
        'gd' => 'nplurals=4; plural=(n==1 || n==11) ? 0 : (n==2 || n==12) ? 1 : (n>2 && n<20) ? 2 : 3;',
        // Slovenian
        'sl' => 'nplurals=4; plural=(n%100==1 ? 1 : n%100==2 ? 2 : n%100==3 || n%100==4 ? 3 : 0);',
        // Arabic
        'ar' => 'nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5);',
        // "n > 1" family (French treats 0 and 1 as singular)
        'fr' => 'nplurals=2; plural=(n > 1);',
        'pt' => 'nplurals=2; plural=(n > 1);',
        'kab' => 'nplurals=2; plural=(n > 1);',
        // Icelandic / Macedonian edge cases
        'is' => 'nplurals=2; plural=(n%10!=1 || n%100==11);',
        'mk' => 'nplurals=2; plural=(n%10==1 && n%100!=11);',
        // Maltese
        'mt' => 'nplurals=4; plural=(n==1 ? 0 : n==0 || ( n%100>1 && n%100<11) ? 1 : (n%100>10 && n%100<20 ) ? 2 : 3);',
    ];

    private int $count;
    /** @var list<array{0: string, 1: string}> */
    private array $tokens;
    private int $position = 0;

    /** @param list<array{0: string, 1: string}> $tokens */
    private function __construct(int $count, array $tokens)
    {
        $this->count = $count;
        $this->tokens = $tokens;
    }

    /**
     * Parses a "Plural-Forms" header (or a bare plural expression).
     *
     * Falls back to the built-in rule for $language and finally to the
     * English rule (nplurals=2; plural=(n != 1)).
     */
    public static function parse(?string $header, string $language = 'en'): self
    {
        $language = Locale::language($language);
        $count = 2;
        $expression = 'n != 1';

        $header = \is_string($header) ? trim($header) : '';

        if ($header !== '') {
            if (preg_match('~nplurals\s*=\s*(\d+)~i', $header, $m) === 1) {
                $count = max(1, min(self::MAX_FORMS, (int) $m[1]));
            }

            if (preg_match('~plural\s*=\s*(.+?)\s*;?\s*$~is', $header, $m) === 1) {
                $candidate = trim($m[1]);

                if ($candidate !== '' && \strlen($candidate) <= self::MAX_EXPRESSION_LENGTH) {
                    $expression = $candidate;
                }
            }
        } elseif (isset(self::LANGUAGE_RULES[$language])) {
            return self::parse(self::LANGUAGE_RULES[$language], $language);
        }

        $tokens = self::tokenize($expression);

        return new self($count, $tokens ?? [['int', '0']]);
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * Selects the plural form index for $n (clamped, never negative).
     */
    public function select(int $n): int
    {
        if ($this->count === 1) {
            return 0;
        }

        try {
            $this->position = 0;
            $value = $this->parseTernary($n);

            if ($this->position < \count($this->tokens)) {
                return $n === 1 ? 0 : min(1, $this->count - 1);
            }
        } catch (\RuntimeException) {
            return $n === 1 ? 0 : min(1, $this->count - 1);
        }

        $index = (int) $value;

        return max(0, min($this->count - 1, $index));
    }

    // --------------------------------------------------------------- lexer

    /**
     * @return list<array{0: string, 1: string}>|null
     */
    private static function tokenize(string $expression): ?array
    {
        $tokens = [];
        $length = \strlen($expression);
        $index = 0;

        while ($index < $length) {
            $char = $expression[$index];

            if (ctype_space($char)) {
                ++$index;
                continue;
            }

            if (ctype_digit($char)) {
                preg_match('~\d+~A', $expression, $m, 0, $index);
                $tokens[] = ['int', $m[0]];
                $index += \strlen($m[0]);
                continue;
            }

            if ($char === 'n') {
                $tokens[] = ['n', 'n'];
                ++$index;
                continue;
            }

            $two = substr($expression, $index, 2);

            if (\in_array($two, ['==', '!=', '<=', '>=', '&&', '||'], true)) {
                $tokens[] = [$two, $two];
                $index += 2;
                continue;
            }

            if (str_contains('()<>=!?:+-*/%', $char)) {
                $tokens[] = [$char, $char];
                ++$index;
                continue;
            }

            return null;
        }

        return $tokens;
    }

    // ---------------------------------------------------------------- parser

    /** @return array{0: string, 1: string}|null */
    private function peek(): ?array
    {
        return $this->tokens[$this->position] ?? null;
    }

    private function accept(string $type): bool
    {
        $token = $this->peek();

        if ($token !== null && $token[0] === $type) {
            ++$this->position;

            return true;
        }

        return false;
    }

    private function parseTernary(int $n): int
    {
        $condition = $this->parseOr($n);

        if ($this->accept('?')) {
            $whenTrue = $this->parseTernary($n);

            if (!$this->accept(':')) {
                throw new \RuntimeException('expected ":" in plural expression');
            }

            $whenFalse = $this->parseTernary($n);

            return $condition !== 0 ? $whenTrue : $whenFalse;
        }

        // Not a ternary: pass the integer value through unchanged so
        // literal form indices ("plural=2") survive. Logical operators
        // already normalise their results to 0/1.
        return $condition;
    }

    private function parseOr(int $n): int
    {
        $left = $this->parseAnd($n);

        while ($this->accept('||')) {
            $right = $this->parseAnd($n);
            $left = ($left !== 0 || $right !== 0) ? 1 : 0;
        }

        return $left;
    }

    private function parseAnd(int $n): int
    {
        $left = $this->parseEquality($n);

        while ($this->accept('&&')) {
            $right = $this->parseEquality($n);
            $left = ($left !== 0 && $right !== 0) ? 1 : 0;
        }

        return $left;
    }

    private function parseEquality(int $n): int
    {
        $left = $this->parseRelational($n);

        while (($token = $this->peek()) !== null && ($token[0] === '==' || $token[0] === '!=' || $token[0] === '=')) {
            ++$this->position;
            $right = $this->parseRelational($n);
            $equal = $left === $right;

            if ($token[0] === '!=') {
                $equal = !$equal;
            }

            $left = $equal ? 1 : 0;
        }

        return $left;
    }

    private function parseRelational(int $n): int
    {
        $left = $this->parseAdditive($n);

        while (($token = $this->peek()) !== null
            && \in_array($token[0], ['<', '<=', '>', '>='], true)) {
            ++$this->position;
            $right = $this->parseAdditive($n);
            $left = match ($token[0]) {
                '<' => $left < $right ? 1 : 0,
                '<=' => $left <= $right ? 1 : 0,
                '>' => $left > $right ? 1 : 0,
                '>=' => $left >= $right ? 1 : 0,
            };
        }

        return $left;
    }

    private function parseAdditive(int $n): int
    {
        $left = $this->parseMultiplicative($n);

        while (($token = $this->peek()) !== null && ($token[0] === '+' || $token[0] === '-')) {
            ++$this->position;
            $right = $this->parseMultiplicative($n);
            $left = $token[0] === '+' ? $left + $right : $left - $right;
        }

        return $left;
    }

    private function parseMultiplicative(int $n): int
    {
        $left = $this->parseUnary($n);

        while (($token = $this->peek()) !== null
            && \in_array($token[0], ['*', '/', '%'], true)) {
            ++$this->position;
            $right = $this->parseUnary($n);

            if (($token[0] === '/' || $token[0] === '%') && $right === 0) {
                return 0;
            }

            $left = match ($token[0]) {
                '*' => $left * $right,
                '/' => intdiv($left, $right),
                '%' => $left % $right,
            };
        }

        return $left;
    }

    private function parseUnary(int $n): int
    {
        if ($this->accept('!')) {
            return $this->parseUnary($n) === 0 ? 1 : 0;
        }

        if ($this->accept('-')) {
            return -$this->parseUnary($n);
        }

        return $this->parsePrimary($n);
    }

    private function parsePrimary(int $n): int
    {
        $token = $this->peek();

        if ($token === null) {
            throw new \RuntimeException('unexpected end of plural expression');
        }

        ++$this->position;

        return match ($token[0]) {
            'int' => (int) $token[1],
            'n' => $n,
            '(' => $this->parseGrouped($n),
            default => throw new \RuntimeException('unexpected token "' . $token[1] . '"'),
        };
    }

    private function parseGrouped(int $n): int
    {
        $value = $this->parseTernary($n);

        if (!$this->accept(')')) {
            throw new \RuntimeException('missing closing parenthesis');
        }

        return $value;
    }
}
