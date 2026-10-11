<?php

declare(strict_types=1);

namespace HtmlPhp\Support;

/**
 * Stateless string helpers.
 *
 * Every method is pure: no configuration, no globals, no I/O. Callers that
 * need policy (precision caps, transliteration maps) pass it in.
 */
final class Str
{
    /** Fallback transliteration used by {@see self::slugify()} without intl. */
    public const TRANSLITERATION = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        'і' => 'i', 'ї' => 'yi', 'є' => 'ye', 'ґ' => 'g',
        'ў' => 'u', 'ђ' => 'dj', 'ј' => 'j', 'љ' => 'lj', 'њ' => 'nj',
        'ћ' => 'c', 'џ' => 'dz',
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'å' => 'a', 'æ' => 'ae', 'ø' => 'o',
        'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'î' => 'i',
        'ï' => 'i', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ñ' => 'n', 'ł' => 'l',
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ń' => 'n',
        'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'ő' => 'o', 'ű' => 'u',
        'š' => 's', 'ž' => 'z', 'č' => 'c', 'ř' => 'r', 'ď' => 'd',
        'ť' => 't', 'ň' => 'n', 'ě' => 'e', 'ů' => 'u',
    ];

    private const MAX_PRECISION = 100;

    /**
     * Hard iteration budget for aggregate walks (`sum`/`avg`/`count` over
     * `Traversable`) — an infinite generator from host code must not hang
     * a render. Mirrors the expression language's loop-range cap.
     */
    public const int MAX_AGGREGATE_ITEMS = 100_000;

    private function __construct() {}

    /**
     * Converts any template value to a string without emitting notices.
     *
     * Arrays and resources become `''`; floats keep up to 14 significant
     * decimals with trailing zeroes trimmed; booleans become `'1'`/`''`.
     */
    public static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            \is_string($value) => $value,
            \is_int($value) => (string) $value,
            \is_float($value) => is_finite($value)
                ? rtrim(rtrim(number_format($value, 14, '.', ''), '0'), '.')
                : '',
            \is_bool($value) => $value ? '1' : '',
            $value instanceof \Stringable => (string) $value,
            $value instanceof \BackedEnum => self::stringify($value->value),
            default => '',
        };
    }

    /**
     * Parses numbers written with dot or comma decimals and the usual group
     * separators (`1 234 567,89`, `1,234,567.89`, `1'234'567.89`).
     */
    public static function numericValue(mixed $value): float
    {
        if (\is_int($value) || \is_float($value)) {
            return is_finite((float) $value) ? (float) $value : 0.0;
        }

        if (!\is_string($value)) {
            return 0.0;
        }

        $number = str_replace(
            [' ', "\t", "\r", "\n", "\v", "\f", "\u{00A0}", "\u{202F}", "\u{2009}", "'"],
            '',
            trim($value),
        );

        if ($number === '') {
            return 0.0;
        }

        $number = self::normalizeSeparators($number);

        if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/', $number, $m) !== 1) {
            return 0.0;
        }

        $result = (float) $m[0];

        return is_finite($result) ? $result : 0.0;
    }

    private static function normalizeSeparators(string $number): string
    {
        $lastComma = strrpos($number, ',');
        $lastDot = strrpos($number, '.');

        if ($lastComma !== false && $lastDot !== false) {
            return $lastComma > $lastDot
                ? str_replace(',', '.', str_replace('.', '', $number))
                : str_replace(',', '', $number);
        }

        if ($lastComma === false) {
            return $number;
        }

        $parts = explode(',', $number);

        // Three comma groups of exactly three digits are thousands separators.
        return \count($parts) > 2 && \strlen((string) end($parts)) === 3
            ? str_replace(',', '', $number)
            : str_replace(',', '.', $number);
    }

    /** True when the trimmed string is a finite decimal literal. */
    public static function isNumericString(string $value): bool
    {
        return preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/', trim($value)) === 1;
    }

    /**
     * Returns a bounded decimal precision (0 … 100). Only integers and
     * digit-strings count; everything else degrades to 0.
     */
    public static function precision(mixed $precision): int
    {
        $value = \is_int($precision) || (\is_string($precision) && preg_match('/^\d+$/', $precision) === 1)
            ? (int) $precision
            : 0;

        return max(0, min(self::MAX_PRECISION, $value));
    }

    /**
     * Divides two values, returning 0 for a zero divisor or non-finite
     * results (html4.pl `Divide()` semantics, kept as a pure utility).
     */
    public static function divide(mixed $a, mixed $b): float|int
    {
        $left = self::numericValue($a);
        $right = self::numericValue($b);

        if ($right === 0.0) {
            return 0;
        }

        $result = $left / $right;

        return is_finite($result) ? $result : 0;
    }

    /**
     * Exponentiation with the same fail-safe semantics as {@see self::divide()}:
     * overflow (`10 ** 9999`), `0 ** -1` and `sqrt`-style `NAN` results
     * (negative base, fractional exponent) all degrade to `0.0` instead of
     * poisoning downstream arithmetic with `INF`/`NAN`.
     */
    public static function power(float $base, float $exponent): float
    {
        if (!is_finite($base) || !is_finite($exponent)) {
            return 0.0;
        }

        if ($base === 0.0 && $exponent < 0) {
            return 0.0; // PHP yields INF; a template dividing by zero is a bug, not a number
        }

        $result = $base ** $exponent;

        return is_finite($result) ? $result : 0.0;
    }

    /** Square root; negative and non-finite inputs degrade to `0.0`. */
    public static function sqrt(float $value): float
    {
        return $value > 0.0 && is_finite($value) ? sqrt($value) : 0.0;
    }

    /**
     * Sums and counts any template value in one capped pass.
     *
     * Arrays and `Traversable`s are walked (each item coerced through
     * {@see self::numericValue()}); scalars count as a single item; `null`
     * counts as nothing. The iteration cap keeps an infinite generator
     * passed from host code from hanging the render — the same budget the
     * expression language applies to loop ranges.
     *
     * @return array{0: float, 1: int} `[sum, count]`, count capped at {@see self::MAX_AGGREGATE_ITEMS}
     */
    public static function accumulate(mixed $value): array
    {
        if (\is_array($value) || $value instanceof \Traversable) {
            $sum = 0.0;
            $count = 0;

            foreach ($value as $item) {
                if ($count >= self::MAX_AGGREGATE_ITEMS) {
                    break;
                }

                $sum += self::numericValue($item);
                $count++;
            }

            return [is_finite($sum) ? $sum : 0.0, $count];
        }

        if ($value === null) {
            return [0.0, 0];
        }

        return [self::numericValue($value), 1];
    }

    /** `number_format()` with a dot decimal and no `-0` artefacts. */
    public static function round(float $value, mixed $precision = 0): string
    {
        $digits = self::precision($precision);
        $value = is_finite($value) ? $value : 0.0;
        $formatted = number_format($value, $digits, '.', '');

        return self::fixNegativeZero($formatted, $digits, '.');
    }

    /** Like {@see self::round()} but drops the fraction for whole numbers. */
    public static function cleanRound(float $value, mixed $precision = 0): string
    {
        if (!is_finite($value)) {
            return '0';
        }

        if (fmod($value, 1.0) === 0.0) {
            $integer = number_format($value, 0, '.', '');

            return $integer === '-0' ? '0' : $integer;
        }

        return self::round($value, self::precision($precision));
    }

    /** `number_format()` with caller-chosen separators and no `-0` artefacts. */
    public static function number(
        float $value,
        int $decimals = 2,
        string $decimalPoint = '.',
        string $thousandsSeparator = ',',
    ): string {
        $decimals = max(0, min(20, $decimals));
        $value = is_finite($value) ? $value : 0.0;

        return self::fixNegativeZero(
            number_format($value, $decimals, $decimalPoint, $thousandsSeparator),
            $decimals,
            $decimalPoint,
        );
    }

    private static function fixNegativeZero(string $formatted, int $decimals, string $point): string
    {
        if (preg_match('/^-0(?:\.0+)?$/', $formatted) !== 1) {
            return $formatted;
        }

        return $decimals > 0 ? '0' . $point . str_repeat('0', $decimals) : '0';
    }

    /**
     * Truncates to at most `$length` characters on a word boundary when
     * possible; multibyte-safe when mbstring is available. `$suffix` is
     * appended only when something was removed.
     */
    public static function truncate(string $text, int $length = 80, string $suffix = '…'): string
    {
        $length = max(1, $length);

        if (self::hasMb()) {
            if (mb_strlen($text, 'UTF-8') <= $length) {
                return $text;
            }

            $cut = mb_substr($text, 0, $length, 'UTF-8');
            $space = mb_strrpos($cut, ' ', 0, 'UTF-8');

            if ($space !== false && $space > intdiv($length, 2)) {
                $cut = mb_substr($cut, 0, $space, 'UTF-8');
            }

            return rtrim($cut, " \t\n\r\0\x0B.,;:!?") . $suffix;
        }

        if (\strlen($text) <= $length) {
            return $text;
        }

        $cut = substr($text, 0, $length);
        $space = strrpos($cut, ' ');

        if ($space !== false && $space > intdiv($length, 2)) {
            $cut = substr($cut, 0, $space);
        }

        return rtrim($cut, " \t\n\r\0\x0B.,;:!?") . $suffix;
    }

    /** Character count, multibyte-aware. */
    public static function length(string $text): int
    {
        return self::hasMb() ? mb_strlen($text, 'UTF-8') : \strlen($text);
    }

    /** Substring, multibyte-aware (`null` length means "to the end"). */
    public static function substr(string $text, int $start, ?int $length = null): string
    {
        return self::hasMb()
            ? mb_substr($text, $start, $length, 'UTF-8')
            : substr($text, $start, $length);
    }

    public static function upper(string $text): string
    {
        return self::hasMb() ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
    }

    public static function lower(string $text): string
    {
        return self::hasMb() ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    public static function ucfirst(string $text): string
    {
        if ($text === '') {
            return '';
        }

        if (\function_exists('mb_ucfirst')) {
            return mb_ucfirst($text, 'UTF-8');
        }

        return self::hasMb()
            ? mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8')
            : ucfirst($text);
    }

    /** Title-cases every word, multibyte-aware. */
    public static function words(string $text): string
    {
        if (!self::hasMb()) {
            return ucwords($text);
        }

        return (string) preg_replace_callback(
            '/[\p{L}\p{N}]+/u',
            static fn(array $m): string => self::ucfirst($m[0]),
            $text,
        );
    }

    private static function hasMb(): bool
    {
        return \function_exists('mb_strlen');
    }

    /**
     * URL slug: lower-case, apply the built-in transliteration map, collapse
     * everything outside `[a-z0-9]` into `$separator`.
     *
     * Deterministic by contract: the map runs **before** any ICU call, so
     * every character the map covers (Cyrillic + common European letters)
     * produces the same slug whether or not `ext-intl` is loaded — slugs end
     * up in URLs and storage, and must not depend on the host. ICU
     * (`Any-Latin; Latin-ASCII; Lower()`) is consulted only when non-ASCII
     * characters remain, i.e. for scripts the map does not cover (Greek,
     * Georgian, …); without intl those are stripped like any other
     * non-`[a-z0-9]` character.
     */
    public static function slugify(string $text, string $separator = '-'): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $separator = $separator === '' ? '-' : $separator;

        // 1. Deterministic core: lower-case, then the built-in map. Map
        //    coverage wins over ICU so the result never depends on ext-intl.
        $text = strtr(self::lower($text), self::TRANSLITERATION);

        // 2. ICU fallback for scripts the map does not cover, only while
        //    non-ASCII characters remain (the `@` guards E_WARNING on
        //    malformed UTF-8 input — transliterate() returns false then).
        if ($text !== '' && preg_match('/[^\x00-\x7F]/', $text) === 1
            && \function_exists('transliterator_transliterate')
        ) {
            /** @var string|false $converted */
            $converted = @transliterator_transliterate(
                'Any-Latin; Latin-ASCII; Lower()',
                $text,
            );

            if (\is_string($converted) && $converted !== '') {
                $text = $converted;
            }
        }

        $text = (string) preg_replace('~[^a-z0-9]+~', $separator, $text);
        $text = trim($text, $separator);

        return $text !== '' ? $text : 'n-a';
    }

    /**
     * Keeps only Unicode letters, digits and `_` (legacy `TrimSpaces`).
     */
    public static function keepWordChars(string $text): string
    {
        $result = preg_replace('/[^\p{L}\p{N}_]+/u', '', $text);

        if ($result !== null) {
            return $result;
        }

        // Invalid UTF-8: fall back to a byte-safe ASCII filter.
        return (string) preg_replace('/[^A-Za-z0-9_]+/', '', $text);
    }

    /**
     * Checks whether a template variable name is syntactically safe.
     * Allows `NAME`, `NAME_1`, `Translate.NAME`, `user.name`; rejects empty
     * names, null bytes, path-like names and superglobal tricks.
     */
    public static function isValidVariableName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0")) {
            return false;
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,127}$/', $name) === 1;
    }

    /**
     * Normalises the legacy entity subset used by html4.pl: hex entities
     * become decimal, an optional map renames selected decimal entities and
     * a handful of Latin-1 entities are folded into plain text.
     *
     * @param array<string, string>|null $map decimal-entity-number => name
     */
    public static function normalizeEntities(string $text, ?array $map = null): string
    {
        $text = Pcre::replaceCallback(
            '~&#x([0-9A-Fa-f]{1,6});~',
            static fn(array $matches): string => '&#' . hexdec($matches[1]) . ';',
            $text,
        );

        $text = Pcre::replaceCallback(
            '~&#(\d{1,7});~',
            static function (array $matches) use ($map): string {
                $number = $matches[1];

                if (\is_array($map) && isset($map[$number])) {
                    return '&' . self::stringify($map[$number]) . ';';
                }

                return '&#' . $number . ';';
            },
            $text,
        );

        return str_ireplace(['&thorn;', '&eth;', '&szlig;', '&nbsp;'], ['th', 'd', 'ss', ' '], $text);
    }

    /**
     * Formats a byte size Apache-style (`abbrev`: `12K`, `1.2M`) or as exact
     * bytes — the engine behind SSI `#fsize` and `#config sizefmt`.
     */
    public static function formatSize(int $bytes, string $style = 'abbrev'): string
    {
        if ($bytes < 0) {
            $bytes = 0;
        }

        if (strtolower($style) === 'bytes') {
            return (string) $bytes;
        }

        if ($bytes < 1024) {
            return (string) $bytes;
        }

        $units = ['K', 'M', 'G', 'T'];
        $value = (float) $bytes;
        $unit = '';

        foreach ($units as $unit) {
            $value /= 1024.0;

            if ($value < 1024.0) {
                break;
            }
        }

        // Apache prints "12K" but "1.2M": one decimal from megabytes up.
        $formatted = $unit === 'K'
            ? number_format(ceil($value * 10.0) / 10.0, $value < 10.0 ? 1 : 0, '.', '')
            : number_format($value, 1, '.', '');

        return rtrim(rtrim($formatted, '0'), '.') . $unit;
    }

    /**
     * Recursively applies `htmlspecialchars()` to a string or nested array
     * (the v3 `sanitizeXSS()` behaviour, without touching superglobals).
     *
     * @param array<array-key, mixed>|string $input
     * @return array<array-key, mixed>|string
     */
    public static function sanitizeRecursive(array|string $input): array|string
    {
        if (\is_string($input)) {
            return htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }

        $result = [];

        foreach ($input as $key => $value) {
            $result[$key] = \is_array($value) || \is_string($value)
                ? self::sanitizeRecursive($value)
                : $value;
        }

        return $result;
    }
}
