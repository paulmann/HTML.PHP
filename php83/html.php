<?php
/**
 * HTML.PHP — production template-processing library.
 *
 * VERSION: 3.0.0 (PHP 8.3 edition)
 *
 * A single-file, zero-dependency template engine that combines:
 *
 *  - Apache SSI directive support (#config, #set, #include, #echo, #if/#elif/
 *    #else/#endif, #exec, #flastmod, #fsize) evaluated in document order with
 *    a hardened, traversal-safe, escaping-by-default security model;
 *  - html4.pl-compatible legacy API (LoadTemplate, LoadVar, processHtml, HTML,
 *    update_template, sanitizeXSS, processSSI, Read_HTML, Show_HTML, Divide,
 *    lc_Tags, normalize, clean, clean_html, WriteFile, TrimSpaces);
 *  - a modern scoped rendering API (html_render/html_render_file) with scoped
 *    variables, variable filters (%{NAME|upper|escape}), loops
 *    (<!--@Loop ROWS-->), conditionals (<!--@If NAME-->), render caching and
 *    collected diagnostics (html_errors());
 *  - full internationalisation (i18n): gettext-compatible PO/MO catalogues,
 *    safe plural-form expression evaluation, message contexts, text domains,
 *    fallback chains, Accept-Language negotiation and sprintf/{named}
 *    interpolation — exposed as html_t()/html_n()/html_x()/html_nx() and wired
 *    into the legacy @{KEY} translation syntax;
 *  - a small WordPress-style hook system (html_add_filter/html_apply_filters/
 *    html_add_action/html_do_action) used internally at every extension point;
 *  - a WordPress bridge and maintenance toolkit (see wordpress/html-wp.php).
 *
 * Distribution: ONE file on purpose — drop it onto shared hosting, copy it
 * over FTP or vendor it into any project. No Composer, no build step, no
 * extensions beyond the PHP standard library (mbstring is used when present).
 *
 * Author:  Mikhail Deynekin
 * Site:    https://Deynekin.com
 * Email:   Mikhail@Deynekin.com
 * License: MIT (see LICENSE)
 *
 * Compatibility target: PHP 8.3+ (see php85/html.php for the PHP 8.5 edition).
 *
 * ---------------------------------------------------------------------------
 * PUBLIC API OVERVIEW
 * ---------------------------------------------------------------------------
 * Legacy (100 % backward compatible with 2.0.1):
 *   HTML(), processSSI(), processHtml(), LoadTemplate(), LoadVar(),
 *   update_template(), sanitizeXSS(), HTML_Sanitized_Input(), Read_HTML(),
 *   Show_HTML(), Divide(), lc_Tags(), normalize(), clean(), clean_html(),
 *   WriteFile(), TrimSpaces()
 *
 * Modern rendering:
 *   html_render(string $template, array $vars = [], array $options = []): string
 *   html_render_file(string $file, array $vars = [], array $options = []): string
 *   html_cache_clear(): int
 *
 * Internationalisation:
 *   html_set_locale(), html_get_locale(), html_detect_locale(),
 *   html_load_translations(), html_t(), html_n(), html_x(), html_nx()
 *
 * Hooks:
 *   html_add_filter(), html_apply_filters(), html_add_action(),
 *   html_do_action(), html_remove_filter(), html_var_filter()
 *
 * Escaping & helpers:
 *   html_escape(), html_escape_attr(), html_escape_url(), html_slugify(),
 *   html_truncate(), html_version(), html_config(), html_errors(),
 *   html_clear_errors(), html_ssi(), html_ssi_variables()
 *
 * Object API (namespace HtmlPhp\):
 *   Version, TemplateException, Config, Errors, Hooks, Escaper,
 *   Support\Str, Support\Num, Support\Pcre, Io\Files, Io\AtomicWriter,
 *   Html\Cleaner, Ssi\Processor, Ssi\Context, Ssi\Expression, Ssi\Exec,
 *   Ssi\Builtins, Ssi\TimeFormat, Ssi\SizeFormat, Ssi\Attributes,
 *   I18n\Translator, I18n\Catalog, I18n\PluralForms, I18n\Locale,
 *   Template\Renderer, Template\Resolver, Template\Filters, Template\Loops,
 *   Template\Cache, Legacy\Blocks
 *
 * ---------------------------------------------------------------------------
 * CHANGE LOG (abridged — full history in CHANGELOG.md)
 * ---------------------------------------------------------------------------
 * 3.0.0 - Major refactor of 2.0.1:
 *   - Internal architecture moved to namespaced final classes
 *     (HtmlPhp\…) while every legacy global function keeps its exact
 *     signature and semantics; seven internal helpers used by third-party
 *     tests (_htmlStringify, _htmlNumericValue, _htmlPrecision, _htmlRound,
 *     _htmlCleanRound, _htmlPositiveLimit, _htmlValidVariableName) remain as
 *     deprecated compatibility wrappers.
 *   - SSI is now evaluated by a sequential, document-order directive scanner
 *     (Apache mod_include semantics) instead of per-directive regex passes.
 *   - New SSI directives: #if/#elif/#else/#endif with a safe expression
 *     evaluator (comparisons, regex matching, &&, ||, !, parentheses,
 *     file-test operators), #exec cmd=/cgi= (deny-by-default, allow-listed,
 *     timeout- and size-bounded), #flastmod, #fsize with #config sizefmt,
 *     #config errmsg, #include file= (relative to the containing document),
 *     #echo encoding=none|entity|url and unquoted attribute values.
 *   - New i18n subsystem: PO/MO catalogue parser, gettext plural-form
 *     expression evaluator (no eval), contexts, domains, fallback chains,
 *     Accept-Language negotiation; @{KEY} and Translate.KEY now consult the
 *     catalogue before falling back to $GLOBALS and finally the key itself.
 *   - New template features: scoped rendering (html_render), variable
 *     filters, <!--@Loop--> / <!--@If--> blocks, render cache, missing-
 *     variable policies and html_errors() diagnostics.
 *   - New hook system modelled after WordPress (html_add_filter et al.).
 *   - WordPress bridge + maintenance toolkit (separate file).
 * 2.0.1 - Hardened single-file release (see CHANGELOG.md).
 */

declare(strict_types=1);

namespace HtmlPhp {

    /**
     * Library identity and runtime environment facts.
     */
    final class Version
    {
        /** Semantic version of the library. */
        public const VERSION = '3.0.0';

        /** Edition marker: '8.3' or '8.5'. */
        public const EDITION = '8.3';

        /** Minimum PHP_VERSION_ID this edition runs on. */
        public const MIN_PHP = 80300;

        /** Date of this build (YYYY-MM-DD). */
        public const BUILD_DATE = '2026-10-11';

        private function __construct()
        {
        }

        /**
         * Fails with a clear error when the runtime is older than required.
         */
        public static function guard(): void
        {
            if (\PHP_VERSION_ID < self::MIN_PHP) {
                throw new \RuntimeException(sprintf(
                    'HTML.PHP %s (%s edition) requires PHP %s or newer; running %s.',
                    self::VERSION,
                    self::EDITION,
                    self::MIN_PHP === 80300 ? '8.3' : '8.5',
                    \PHP_VERSION
                ));
            }
        }

        /**
         * Returns "3.0.0 (8.3 edition)".
         */
        public static function banner(): string
        {
            return self::VERSION . ' (' . self::EDITION . ' edition)';
        }
    }

    Version::guard();

    /**
     * Single exception type raised by every deliberate library failure.
     *
     * Callers can catch \RuntimeException for backward compatibility with
     * 2.0.1 (TemplateException extends it) or catch this class specifically.
     */
    class TemplateException extends \RuntimeException
    {
        /** @var array<string, mixed> */
        private array $contextData = [];

        /**
         * @param array<string, mixed> $context
         */
        public static function withContext(string $message, array $context = [], ?\Throwable $previous = null): static
        {
            $exception = new static($message, 0, $previous);
            $exception->contextData = $context;

            return $exception;
        }

        /**
         * @return array<string, mixed>
         */
        public function context(): array
        {
            return $this->contextData;
        }
    }
}

namespace HtmlPhp\Support {

    use HtmlPhp\TemplateException;

    /**
     * PCRE helpers that convert regex failures into exceptions and provide
     * Unicode patterns with byte-safe fallbacks for malformed input.
     */
    final class Pcre
    {
        private function __construct()
        {
        }

        /**
         * preg_replace_callback() that throws on PCRE failure.
         */
        public static function replaceCallback(string $pattern, callable $callback, string $subject): string
        {
            $result = preg_replace_callback($pattern, $callback, $subject);

            if ($result === null) {
                throw TemplateException::withContext(
                    'Template regular expression failed: ' . preg_last_error_msg(),
                    ['pattern' => $pattern]
                );
            }

            return $result;
        }

        /**
         * preg_replace_callback() with a hard cap on replacements (DoS guard).
         */
        public static function replaceCallbackLimited(
            string $pattern,
            callable $callback,
            string $subject,
            int $limit
        ): string {
            $result = preg_replace_callback($pattern, $callback, $subject, $limit);

            if ($result === null) {
                throw TemplateException::withContext(
                    'Template regular expression failed: ' . preg_last_error_msg(),
                    ['pattern' => $pattern]
                );
            }

            return $result;
        }

        /**
         * preg_replace() that throws on PCRE failure.
         */
        public static function replace(string $pattern, string $replacement, string $subject): string
        {
            $result = preg_replace($pattern, $replacement, $subject);

            if ($result === null) {
                throw TemplateException::withContext(
                    'Template regular expression failed: ' . preg_last_error_msg(),
                    ['pattern' => $pattern]
                );
            }

            return $result;
        }

        /**
         * Applies a Unicode pattern and retries with a byte-safe pattern when
         * the subject is not valid UTF-8.
         */
        public static function replaceUnicode(
            string $unicodePattern,
            string $bytePattern,
            string $replacement,
            string $subject
        ): string {
            $result = preg_replace($unicodePattern, $replacement, $subject);

            if ($result !== null) {
                return $result;
            }

            $result = preg_replace($bytePattern, $replacement, $subject);

            return $result ?? $subject;
        }

        /**
         * preg_match() that treats PCRE failure as "no match" plus an error entry.
         */
        public static function match(string $pattern, string $subject, array &$matches = []): bool
        {
            $result = @preg_match($pattern, $subject, $matches);

            if ($result === false) {
                $matches = [];
                \HtmlPhp\Errors::add(
                    \HtmlPhp\Errors::REGEX_FAILED,
                    'Regular expression failed: ' . preg_last_error_msg()
                );

                return false;
            }

            return $result === 1;
        }
    }

    /**
     * String helpers: safe stringification, numeric parsing, rounding,
     * slugs, truncation and entity normalisation support.
     */
    final class Str
    {
        /** Sentinel used by normalize() to protect <br> markers. */
        public const BR_MARKER = "\x01HTML_BR\x01";

        /**
         * Fallback transliteration map (Cyrillic + common European letters),
         * used by slugify() when the intl extension is unavailable.
         */
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

        /** Sentinel used by the strftime converter for %j (day of year). */
        public const DOY_MARKER = "\x02\x03";

        private function __construct()
        {
        }

        /**
         * Converts any template value to a string without emitting
         * conversion warnings or notices.
         */
        public static function stringify(mixed $value): string
        {
            if ($value === null) {
                return '';
            }

            if (is_string($value)) {
                return $value;
            }

            if (is_int($value)) {
                return (string) $value;
            }

            if (is_float($value)) {
                if (!is_finite($value)) {
                    return '';
                }

                return rtrim(rtrim(number_format($value, 14, '.', ''), '0'), '.');
            }

            if (is_bool($value)) {
                return $value ? '1' : '';
            }

            if ($value instanceof \Stringable) {
                return (string) $value;
            }

            if (is_array($value)) {
                return '';
            }

            return '';
        }

        /**
         * Parses numbers with dot or comma decimal separators and common
         * group separators ("1 234 567,89", "1,234,567.89", "1'234'567.89").
         */
        public static function numericValue(mixed $value): float
        {
            if (is_int($value) || is_float($value)) {
                return is_finite((float) $value) ? (float) $value : 0.0;
            }

            if (!is_string($value)) {
                return 0.0;
            }

            $number = str_replace(
                [' ', "\t", "\r", "\n", "\v", "\f", "\u{00A0}", "\u{202F}", "\u{2009}", "'"],
                '',
                trim($value)
            );

            if ($number === '') {
                return 0.0;
            }

            $lastComma = strrpos($number, ',');
            $lastDot = strrpos($number, '.');

            if ($lastComma !== false && $lastDot !== false) {
                if ($lastComma > $lastDot) {
                    $number = str_replace('.', '', $number);
                    $number = str_replace(',', '.', $number);
                } else {
                    $number = str_replace(',', '', $number);
                }
            } elseif ($lastComma !== false) {
                $parts = explode(',', $number);

                if (count($parts) > 2 && strlen((string) end($parts)) === 3) {
                    $number = str_replace(',', '', $number);
                } else {
                    $number = str_replace(',', '.', $number);
                }
            }

            if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/', $number, $matches) !== 1) {
                return 0.0;
            }

            $result = (float) $matches[0];

            return is_finite($result) ? $result : 0.0;
        }

        /**
         * Returns true when the string looks like a finite decimal number.
         */
        public static function isNumericString(string $value): bool
        {
            return preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/', trim($value)) === 1;
        }

        /**
         * Formats a number with a stable dot decimal separator, honouring the
         * configured precision cap.
         */
        public static function round(float $value, mixed $precision): string
        {
            $digits = self::precision($precision);

            if (!is_finite($value)) {
                $value = 0.0;
            }

            $formatted = number_format($value, $digits, '.', '');

            if (preg_match('/^-0(?:\.0+)?$/', $formatted) === 1) {
                return $digits > 0 ? '0.' . str_repeat('0', $digits) : '0';
            }

            return $formatted;
        }

        /**
         * Like round() but removes the decimal suffix for exact integers.
         */
        public static function cleanRound(float $value, mixed $precision): string
        {
            if (!is_finite($value)) {
                return '0';
            }

            if (fmod($value, 1.0) === 0.0) {
                $integer = number_format($value, 0, '.', '');

                return $integer === '-0' ? '0' : $integer;
            }

            return self::round($value, $precision);
        }

        /**
         * Returns a bounded decimal precision (0 … HTML_MAX_PRECISION).
         */
        public static function precision(mixed $precision): int
        {
            $maximum = \HtmlPhp\Config::limit('HTML_MAX_PRECISION', 100, 1000, 0);
            $value = is_int($precision) || (is_string($precision) && preg_match('/^\d+$/', $precision) === 1)
                ? (int) $precision
                : 0;

            return max(0, min($maximum, $value));
        }

        /**
         * Divides two values, returning 0 for a zero divisor or non-finite
         * results (html4.pl semantics).
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
         * Checks whether a template/global variable name is syntactically safe.
         *
         * Allows NAME, NAME_1, Translate.NAME, user.name; rejects empty names,
         * null bytes, path-like names and superglobal tricks.
         */
        public static function isValidVariableName(string $name): bool
        {
            if ($name === '' || str_contains($name, "\0")) {
                return false;
            }

            return preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,127}$/', $name) === 1;
        }

        /**
         * URL-safe, lowercase, ASCII-friendly slug (mbstring-aware).
         */
        public static function slugify(string $text, string $separator = '-'): string
        {
            $text = trim($text);

            if ($text === '') {
                return '';
            }

            if (function_exists('transliterator_transliterate')) {
                $converted = @transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);

                if (is_string($converted)) {
                    $text = $converted;
                }
            } else {
                // No intl: lowercase and apply the built-in Cyrillic map so
                // slugs stay meaningful without the extension.
                $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
                $text = strtr($text, self::TRANSLITERATION);
            }

            $text = (string) preg_replace('~[^a-z0-9]+~', $separator, $text);
            $text = trim($text, $separator);

            return $text !== '' ? $text : 'n-a';
        }

        /**
         * Truncates to at most $length characters on a word boundary when
         * possible; multibyte-safe when mbstring is available.
         */
        public static function truncate(string $text, int $length, string $suffix = '…'): string
        {
            $length = max(1, $length);

            if (function_exists('mb_strlen')) {
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

            if (strlen($text) <= $length) {
                return $text;
            }

            $cut = substr($text, 0, $length);
            $space = strrpos($cut, ' ');

            if ($space !== false && $space > intdiv($length, 2)) {
                $cut = substr($cut, 0, $space);
            }

            return rtrim($cut, " \t\n\r\0\x0B.,;:!?") . $suffix;
        }

        /**
         * Normalises the legacy entity subset used by html4.pl.
         */
        public static function normalizeEntities(string $text): string
        {
            $text = Pcre::replaceCallback(
                '~&#x([0-9A-Fa-f]{1,6});~',
                static fn(array $matches): string => '&#' . hexdec($matches[1]) . ';',
                $text
            );

            $text = Pcre::replaceCallback(
                '~&#(\d{1,7});~',
                static function (array $matches): string {
                    $number = $matches[1];
                    $map = $GLOBALS['entities'] ?? null;

                    if (is_array($map) && isset($map[$number])) {
                        return '&' . Str::stringify($map[$number]) . ';';
                    }

                    return '&#' . $number . ';';
                },
                $text
            );

            return str_ireplace(['&thorn;', '&eth;', '&szlig;', '&nbsp;'], ['th', 'd', 'ss', ' '], $text);
        }

        /**
         * Formats a byte size Apache-style ("abbrev": 1.2K, 3M) or exact bytes.
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
    }
}
namespace HtmlPhp {

    use HtmlPhp\Support\Str;

    /**
     * Typed facade over the documented $GLOBALS['HTML_*'] configuration keys.
     *
     * $GLOBALS remains the single source of truth so that (a) every 2.0.1
     * configuration snippet keeps working verbatim and (b) a single process
     * can still tweak behaviour per request. Config adds bounds checking,
     * type coercion and a machine-readable catalogue of every key.
     */
    final class Config
    {
        /**
         * Catalogue: key => [default, kind, min, max] where kind is one of
         * int, bool, string, array, callable, mixed.
         *
         * @var array<string, array{0: mixed, 1: string, 2: int, 3: int}>
         */
        private const KEYS = [
            // --- engine limits -------------------------------------------------
            'HTML_MAX_SSI_DEPTH' => [16, 'int', 1, 128],
            'HTML_MAX_SSI_TAGS' => [256, 'int', 1, 10000],
            'HTML_MAX_TEMPLATE_PASSES' => [2, 'int', 1, 16],
            'HTML_MAX_BLOCK_DEPTH' => [64, 'int', 1, 1024],
            'HTML_MAX_PRECISION' => [100, 'int', 0, 1000],
            'HTML_MAX_TEMPLATE_BYTES' => [16 * 1024 * 1024, 'int', 1024, 512 * 1024 * 1024],
            'HTML_MAX_INCLUDE_BYTES' => [8 * 1024 * 1024, 'int', 1024, 256 * 1024 * 1024],
            'HTML_LOOP_MAX' => [10000, 'int', 1, 1000000],
            'HTML_MAX_LOOP_DEPTH' => [32, 'int', 1, 128],
            // --- SSI behaviour -------------------------------------------------
            'HTML_SSI_ALLOW_PARENT' => [false, 'bool', 0, 0],
            'HTML_SSI_ECHO_ESCAPE' => [true, 'bool', 0, 0],
            'HTML_SSI_ECHO_ALLOW' => [[], 'array', 0, 0],
            'HTML_SSI_TIMEFMT' => ['%A, %d-%b-%Y %H:%M:%S %Z', 'string', 0, 0],
            'HTML_SSI_SIZEFMT' => ['abbrev', 'string', 0, 0],
            'HTML_SSI_ERRMSG' => [null, 'mixed', 0, 0],
            'HTML_SSI_EXEC' => [false, 'bool', 0, 0],
            'HTML_SSI_EXEC_CMD_ALLOW' => [[], 'array', 0, 0],
            'HTML_SSI_EXEC_CGI_EXT' => [['php'], 'array', 0, 0],
            'HTML_SSI_EXEC_CGI_MODE' => ['spawn', 'string', 0, 0],
            'HTML_SSI_EXEC_TIMEOUT' => [5, 'int', 1, 60],
            'HTML_SSI_EXEC_MAX_BYTES' => [65536, 'int', 1, 16 * 1024 * 1024],
            // --- input / output ------------------------------------------------
            'HTML_AUTO_SANITIZE_INPUT' => [false, 'bool', 0, 0],
            'HTML_ALLOWED_TAGS' => [null, 'array', 0, 0],
            'HTML_ESCAPE_VARS' => [false, 'bool', 0, 0],
            'HTML_ESCAPER' => [null, 'callable', 0, 0],
            'HTML_MISSING_VAR' => ['', 'string', 0, 0],
            'HTML_DEBUG' => [false, 'bool', 0, 0],
            // HTML_WWW_PATH intentionally defaults to null (unset): the SSI
            // document-root resolution falls through to $_SERVER['DOCUMENT_ROOT']
            // only while the key is absent from $GLOBALS.
            'HTML_WWW_PATH' => [null, 'mixed', 0, 0],
            // --- i18n ----------------------------------------------------------
            'HTML_I18N_LOCALE' => ['en', 'string', 0, 0],
            'HTML_I18N_FALLBACK' => [['en'], 'array', 0, 0],
            'HTML_I18N_DOMAIN' => ['messages', 'string', 0, 0],
            'HTML_I18N_DETECT' => [false, 'bool', 0, 0],
            // --- cache ---------------------------------------------------------
            'HTML_CACHE_DIR' => ['', 'string', 0, 0],
            'HTML_CACHE_TTL' => [0, 'int', 0, 86400 * 30],
        ];

        /** Keys that exist but are managed internally. */
        private const INTERNAL_KEYS = ['HTML_Prepare_Count', 'TAGS', 'html', 'parity', 'entities'];

        private function __construct()
        {
        }

        /**
         * Installs the documented defaults for keys the host did not set.
         */
        public static function bootstrap(): void
        {
            foreach (self::KEYS as $key => $spec) {
                if ($spec[0] === null) {
                    // null defaults stay unset so ?? fallthroughs keep working.
                    continue;
                }

                $GLOBALS[$key] ??= $spec[0];
            }

            $GLOBALS['HTML_ALLOWED_TAGS'] ??= [
                'br' => true, 'p' => true, 'h1' => true, 'h2' => true, 'h3' => true,
                'h4' => true, 'strong' => true, 'b' => true, 'ul' => true, 'ol' => true,
                'li' => true, 'hr' => true,
            ];
        }

        /**
         * Every configuration key known to this catalogue.
         *
         * @return list<string>
         */
        public static function keys(): array
        {
            return array_keys(self::KEYS);
        }

        /**
         * Raw value with the documented default as fallback.
         */
        public static function get(string $key): mixed
        {
            $value = $GLOBALS[$key] ?? null;

            if ($value === null && isset(self::KEYS[$key])) {
                return self::KEYS[$key][0];
            }

            return $value;
        }

        /**
         * Writes a configuration value (no clamping — use limit()/flag()).
         */
        public static function set(string $key, mixed $value): void
        {
            $GLOBALS[$key] = $value;
        }

        /**
         * Applies overrides and returns the effective configuration.
         *
         * @param array<string, mixed>|null $overrides
         * @return array<string, mixed>
         */
        public static function apply(?array $overrides = null): array
        {
            if ($overrides !== null) {
                foreach ($overrides as $key => $value) {
                    if (is_string($key) && str_starts_with($key, 'HTML_')) {
                        $GLOBALS[$key] = $value;
                    } else {
                        Errors::add(Errors::CONFIG_UNKNOWN, 'Unknown configuration key.', ['key' => (string) $key]);
                    }
                }
            }

            $effective = [];

            foreach (self::KEYS as $key => $spec) {
                $effective[$key] = self::get($key);
            }

            return $effective;
        }

        /**
         * Bounded integer configuration value (accepts numeric strings).
         */
        public static function limit(string $key, int $default, int $maximum, int $minimum = 1): int
        {
            $value = $GLOBALS[$key] ?? $default;
            $value = is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)
                ? (int) $value
                : $default;

            return max($minimum, min($maximum, $value));
        }

        /**
         * Boolean configuration value with the documented default.
         */
        public static function flag(string $key, bool $default = false): bool
        {
            $value = $GLOBALS[$key] ?? $default;

            if (is_bool($value)) {
                return $value;
            }

            if (is_string($value)) {
                $lower = strtolower(trim($value));

                return !in_array($lower, ['', '0', 'false', 'off', 'no'], true);
            }

            return (bool) $value;
        }

        /**
         * String configuration value.
         */
        public static function text(string $key, string $default = ''): string
        {
            $value = $GLOBALS[$key] ?? $default;

            return is_string($value) ? $value : (is_scalar($value) ? Str::stringify($value) : $default);
        }

        /**
         * Array configuration value (lists and maps both allowed).
         *
         * @return array<array-key, mixed>
         */
        public static function list_(string $key, array $default = []): array
        {
            $value = $GLOBALS[$key] ?? $default;

            return is_array($value) ? $value : $default;
        }

        /**
         * True when $name is a configuration/internal key that templates must
         * never read through #set/#echo or write through any directive.
         */
        public static function isReserved(string $name): bool
        {
            return isset(self::KEYS[$name]) || in_array($name, self::INTERNAL_KEYS, true)
                || isset(\HTML_RESERVED_GLOBALS[$name]);
        }

        /**
         * True when the name passes the syntax gate and is not reserved and
         * (when configured) is on the HTML_SSI_ECHO_ALLOW allow-list.
         */
        public static function isGlobalAllowed(string $name): bool
        {
            if (!Str::isValidVariableName($name)) {
                return false;
            }

            if (isset(\HTML_RESERVED_GLOBALS[$name])) {
                return false;
            }

            $allow = $GLOBALS['HTML_SSI_ECHO_ALLOW'] ?? [];

            if (is_array($allow) && $allow !== []) {
                return isset($allow[$name]) || in_array($name, $allow, true);
            }

            return true;
        }
    }

    Config::bootstrap();

    /**
     * Non-fatal diagnostics collected during rendering.
     *
     * html_errors() exposes the entries; HTML_DEBUG=true additionally renders
     * visible placeholders for missing variables. The list is capped so a
     * broken template can never exhaust memory.
     */
    final class Errors
    {
        public const MISSING_VAR = 'missing-var';
        public const SSI_INCLUDE_FAILED = 'ssi-include-failed';
        public const SSI_INCLUDE_CYCLE = 'ssi-include-cycle';
        public const SSI_LIMIT_REACHED = 'ssi-limit-reached';
        public const SSI_EXEC_DENIED = 'ssi-exec-denied';
        public const SSI_EXEC_FAILED = 'ssi-exec-failed';
        public const SSI_EXPR_ERROR = 'ssi-expr-error';
        public const FILTER_UNKNOWN = 'filter-unknown';
        public const REGEX_FAILED = 'regex-failed';
        public const CATALOG_PARSE = 'catalog-parse';
        public const CACHE_IO = 'cache-io';
        public const CONFIG_UNKNOWN = 'config-unknown';
        public const LOOP_GUARD = 'loop-guard';
        public const WP_BRIDGE = 'wp-bridge';

        private const MAX_ENTRIES = 100;

        /** @var list<array{code: string, message: string, context: array<string, mixed>, where: string}> */
        private static array $entries = [];

        private function __construct()
        {
        }

        /**
         * Records one diagnostic entry (silently drops beyond the cap).
         *
         * @param array<string, mixed> $context
         */
        public static function add(string $code, string $message, array $context = []): void
        {
            if (count(self::$entries) >= self::MAX_ENTRIES) {
                return;
            }

            $frame = null;

            foreach (debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $candidate) {
                $file = (string) ($candidate['file'] ?? '');

                if ($file !== '' && $file !== __FILE__) {
                    $frame = $file . ':' . (int) ($candidate['line'] ?? 0);
                    break;
                }
            }

            self::$entries[] = [
                'code' => $code,
                'message' => $message,
                'context' => $context,
                'where' => $frame ?? '(library)',
            ];

            Hooks::doAction('htmlphp_error', $code, $message, $context);
        }

        /**
         * All collected entries, oldest first.
         *
         * @return list<array{code: string, message: string, context: array<string, mixed>, where: string}>
         */
        public static function all(): array
        {
            return self::$entries;
        }

        /**
         * Entries filtered by code prefix (empty = all).
         *
         * @return list<array{code: string, message: string, context: array<string, mixed>, where: string}>
         */
        public static function byCode(string $code): array
        {
            if ($code === '') {
                return self::$entries;
            }

            return array_values(array_filter(
                self::$entries,
                static fn(array $entry): bool => $entry['code'] === $code
            ));
        }

        public static function count(): int
        {
            return count(self::$entries);
        }

        /**
         * The most recently recorded entry (null when empty).
         *
         * @return array{code: string, message: string, context: array<string, mixed>, where: string}|null
         */
        public static function last(): ?array
        {
            return self::$entries === [] ? null : self::$entries[count(self::$entries) - 1];
        }

        public static function clear(): void
        {
            self::$entries = [];
        }
    }

    /**
     * Minimal WordPress-style hook registry (filters + actions) with
     * priorities, used at every documented extension point of the engine.
     */
    final class Hooks
    {
        /** @var array<string, array<int, array<int, callable>>> */
        private static array $filters = [];

        /** @var array<string, array<int, array<int, callable>>> */
        private static array $actions = [];

        private static int $sequence = 0;

        private function __construct()
        {
        }

        public static function addFilter(string $tag, callable $callback, int $priority = 10): void
        {
            self::$filters[$tag][$priority][++self::$sequence] = $callback;
        }

        public static function addAction(string $tag, callable $callback, int $priority = 10): void
        {
            self::$actions[$tag][$priority][++self::$sequence] = $callback;
        }

        public static function removeFilter(string $tag, int $priority = 10): void
        {
            unset(self::$filters[$tag][$priority]);
        }

        public static function removeAction(string $tag, int $priority = 10): void
        {
            unset(self::$actions[$tag][$priority]);
        }

        public static function hasFilter(string $tag): bool
        {
            return isset(self::$filters[$tag]);
        }

        public static function hasAction(string $tag): bool
        {
            return isset(self::$actions[$tag]);
        }

        public static function applyFilters(string $tag, mixed $value, mixed ...$args): mixed
        {
            if (!isset(self::$filters[$tag])) {
                return $value;
            }

            $buckets = self::$filters[$tag];
            ksort($buckets);

            foreach ($buckets as $callbacks) {
                foreach ($callbacks as $callback) {
                    $value = $callback($value, ...$args);
                }
            }

            return $value;
        }

        public static function doAction(string $tag, mixed ...$args): void
        {
            if (!isset(self::$actions[$tag])) {
                return;
            }

            $buckets = self::$actions[$tag];
            ksort($buckets);

            foreach ($buckets as $callbacks) {
                foreach ($callbacks as $callback) {
                    $callback(...$args);
                }
            }
        }

        /**
         * Drops every hook (used by tests and long-running workers).
         */
        public static function removeAll(): void
        {
            self::$filters = [];
            self::$actions = [];
            self::$sequence = 0;
        }

        /**
         * Hook points fired by the engine:
         *   htmlphp_error             (action)  code, message, context
         *   htmlphp_ssi_builtins      (filter)  array of SSI environment variables
         *   htmlphp_ssi_include_path  (filter)  resolved include path, virtual name
         *   htmlphp_ssi_echo          (filter)  escaped value, variable name
         *   htmlphp_missing_var       (action)  name, policy
         *   htmlphp_render            (filter)  final output, template name
         *   htmlphp_translate         (filter)  translation, msgid, domain, locale
         *   htmlphp_filter_apply      (action)  filter name, arguments
         */
        public static function documented(): array
        {
            return [
                'htmlphp_error', 'htmlphp_ssi_builtins', 'htmlphp_ssi_include_path',
                'htmlphp_ssi_echo', 'htmlphp_missing_var', 'htmlphp_render',
                'htmlphp_translate', 'htmlphp_filter_apply',
            ];
        }
    }

    /**
     * Output escaping with a pluggable escaper (HTML_ESCAPER) so the
     * WordPress bridge can install esc_html()/esc_attr()/esc_url().
     */
    final class Escaper
    {
        private function __construct()
        {
        }

        /**
         * HTML text escaping (the default policy for SSI echo output and for
         * %{VAR} substitution when HTML_ESCAPE_VARS is on).
         */
        public static function html(string $value): string
        {
            $custom = $GLOBALS['HTML_ESCAPER'] ?? null;

            if (is_callable($custom)) {
                return Str::stringify($custom($value));
            }

            return htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5 | \ENT_SUBSTITUTE, 'UTF-8', false);
        }

        /**
         * Attribute escaping — identical policy to html() but always the
         * built-in encoder (never double-escaped through a custom escaper).
         */
        public static function attr(string $value): string
        {
            return htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5 | \ENT_SUBSTITUTE, 'UTF-8', false);
        }

        /**
         * URL-value escaping: rawurlencode() every character that is not
         * unreserved or a structural URL delimiter. "&" and "'" ARE encoded
         * because this escaper sanitises values placed into URLs, not whole
         * query strings.
         */
        public static function url(string $value): string
        {
            $result = preg_replace_callback(
                '/[^A-Za-z0-9\-._~:\\/?#\\[\\]@!$()*+,;=%]/',
                static fn(array $m): string => rawurlencode($m[0]),
                $value
            );

            return $result ?? $value;
        }

        /**
         * JSON escaping safe for inline <script> contexts.
         */
        public static function json(mixed $value): string
        {
            $encoded = json_encode(
                $value,
                \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP | \JSON_UNESCAPED_UNICODE
            );

            return $encoded === false ? 'null' : $encoded;
        }

        /**
         * Recursively sanitises a nested input array for safe HTML output
         * (legacy sanitizeXSS() semantics, double_encode=true preserved).
         *
         * @param array<array-key, mixed> $input
         * @return array<array-key, mixed>
         */
        public static function sanitizeArray(array $input): array
        {
            $sanitized = [];

            foreach ($input as $key => $value) {
                if (is_array($value)) {
                    $sanitized[$key] = self::sanitizeArray($value);
                    continue;
                }

                $sanitized[$key] = htmlspecialchars(
                    Str::stringify($value),
                    \ENT_QUOTES | \ENT_HTML5 | \ENT_SUBSTITUTE,
                    'UTF-8',
                    true
                );
            }

            return $sanitized;
        }
    }
}
namespace HtmlPhp\Io {

    use HtmlPhp\Config;
    use HtmlPhp\Errors;
    use HtmlPhp\Support\Str;
    use HtmlPhp\TemplateException;

    /**
     * Guarded file reads shared by templates, includes and catalogues.
     */
    final class Files
    {
        private function __construct()
        {
        }

        /**
         * Reads a whole file with a configurable byte cap.
         *
         * Returns null when the file is missing, unreadable or too large.
         */
        public static function slurp(string $path, ?int $maxBytes = null): ?string
        {
            if ($path === '' || str_contains($path, "\0") || !is_file($path) || !is_readable($path)) {
                return null;
            }

            $maxBytes ??= Config::limit('HTML_MAX_INCLUDE_BYTES', 8 * 1024 * 1024, 256 * 1024 * 1024);
            $size = @filesize($path);

            if ($size !== false && $size > $maxBytes) {
                return null;
            }

            $contents = @file_get_contents($path);

            return $contents === false ? null : $contents;
        }

        /**
         * Normalises a path for prefix comparisons: native separators become
         * "/" and, on case-insensitive filesystems, the path is lowercased.
         */
        public static function normalizeForCompare(string $path): string
        {
            $normalized = rtrim(str_replace('\\', '/', $path), '/');

            if (\PHP_OS_FAMILY === 'Windows' || \PHP_OS_FAMILY === 'Darwin') {
                $normalized = strtolower($normalized);
            }

            return $normalized;
        }

        /**
         * True when $path is $root itself or lives inside $root.
         */
        public static function isInside(string $path, string $root): bool
        {
            $path = self::normalizeForCompare($path);
            $root = self::normalizeForCompare($root);

            return $path === $root || str_starts_with($path, $root . '/');
        }
    }

    /**
     * Atomic file writer (legacy WriteFile() semantics, hardened).
     */
    final class AtomicWriter
    {
        private function __construct()
        {
        }

        /**
         * Writes $data to $file atomically: temp file in the destination
         * directory, verified payload length, mode preservation, rename with
         * a locked in-place fallback.
         */
        public static function write(string $file, mixed $data): bool
        {
            if ($file === '' || str_contains($file, "\0")) {
                throw new \InvalidArgumentException('The output file path is invalid.');
            }

            $directory = dirname($file);

            if (!is_dir($directory) || !is_writable($directory)) {
                throw new TemplateException("Output directory '{$directory}' is not writable.");
            }

            if (is_file($file) && !is_writable($file)) {
                throw new TemplateException("Output file '{$file}' is not writable.");
            }

            $payload = Str::stringify($data);
            $expected = strlen($payload);

            $prefix = (string) preg_replace('~[^A-Za-z0-9._-]~', '_', basename($file));
            $prefix = substr($prefix, 0, 32);

            if ($prefix === '') {
                $prefix = 'html';
            }

            $temporary = tempnam($directory, $prefix . '.tmp.');

            if ($temporary === false) {
                throw new TemplateException("Cannot create a temporary file in '{$directory}'.");
            }

            if (is_file($file)) {
                $perms = fileperms($file);
                $mode = ($perms === false ? 0644 : $perms) & 0777;
            } else {
                $oldUmask = umask();
                umask($oldUmask);
                $mode = 0666 & ~$oldUmask;
            }

            try {
                $written = file_put_contents($temporary, $payload, \LOCK_EX);

                if ($written === false || $written !== $expected) {
                    throw new TemplateException("Cannot completely write temporary file '{$temporary}'.");
                }

                @chmod($temporary, $mode);

                if (!@rename($temporary, $file)) {
                    $fallback = @file_put_contents($file, $payload, \LOCK_EX);

                    if ($fallback === false || $fallback !== $expected) {
                        throw new TemplateException("Cannot completely write output file '{$file}'.");
                    }

                    @chmod($file, $mode);
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }

            return true;
        }
    }
}

namespace HtmlPhp\Html {

    use HtmlPhp\Config;
    use HtmlPhp\Support\Pcre;
    use HtmlPhp\Support\Str;

    /**
     * HTML cleaning and normalisation helpers (legacy normalize(),
     * clean_html(), lc_Tags() semantics).
     */
    final class Cleaner
    {
        private function __construct()
        {
        }

        /**
         * Converts HTML tag names to lowercase without touching attributes.
         */
        public static function lcTags(string $input): string
        {
            return Pcre::replaceCallback(
                '~<(\s*/?\s*)([A-Za-z][A-Za-z0-9:_-]*)((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>~',
                static fn(array $matches): string => '<' . $matches[1] . strtolower($matches[2]) . $matches[3] . '>',
                $input
            );
        }

        /**
         * Normalises HTML to text while optionally preserving <br> tags.
         */
        public static function normalize(string $buffer, mixed $br = null, mixed $cleanEdges = false): string
        {
            $preserveBreaks = $br === null || (int) $br === 0;
            $marker = Str::BR_MARKER;

            $buffer = str_replace("\x01", '', $buffer);
            $buffer = Pcre::replace('~<!--.*?-->~s', '', $buffer);

            if ($preserveBreaks) {
                $buffer = Pcre::replace('~<br\s*/?>~i', $marker, $buffer);
            }

            $buffer = Pcre::replace('~<(?:"[^"]*"|\'[^\']*\'|[^\'">])*>~s', ' ', $buffer);
            $buffer = Str::normalizeEntities($buffer);
            $buffer = Pcre::replace('~[\x00\x02-\x08\x0B\x0C\x0E-\x1F\x7F]~', '', $buffer);
            $buffer = Pcre::replace('~[\t\n\r ]+~', ' ', $buffer);
            $buffer = $preserveBreaks ? str_replace($marker, '<br>', $buffer) : $buffer;
            $buffer = trim($buffer);
            $buffer = Pcre::replace('~,\\z~', '', $buffer);

            if (!empty($cleanEdges)) {
                $buffer = Pcre::replaceUnicode(
                    '~^[.,:\\\\/><!]+\s*|\s*[.,:\\\\/><!]+$~u',
                    '~^[.,:\\\\/><!]+\s*|\s*[.,:\\\\/><!]+$~',
                    '',
                    $buffer
                );
                $buffer = trim($buffer);
            }

            return $buffer;
        }

        /**
         * Returns an allow-listed HTML tag without attributes.
         *
         * @param array<string, bool> $allowed
         */
        public static function cleanTag(string $closing, string $tag, array $allowed): string
        {
            $tag = strtolower($tag);

            return !empty($allowed[$tag]) ? '<' . $closing . $tag . '>' : '';
        }

        /**
         * Legacy clean() helper: allow-list comes from $GLOBALS['TAGS'].
         */
        public static function cleanWithGlobalTags(string $closing, string $tag): string
        {
            $allowed = isset($GLOBALS['TAGS']) && is_array($GLOBALS['TAGS']) ? $GLOBALS['TAGS'] : [];

            return self::cleanTag($closing, $tag, $allowed);
        }

        /**
         * Keeps only allow-listed HTML tags and removes their attributes.
         *
         * <script>/<style> elements are dropped with their contents (including
         * an unterminated opener); every other removed tag keeps its text.
         */
        public static function cleanHtml(string $html): string
        {
            $allowed = Config::list_('HTML_ALLOWED_TAGS', []);

            $html = Pcre::replace('~<!--.*?-->~s', '', $html);
            $html = Pcre::replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $html);
            $html = Pcre::replace('~<(script|style)\b[^>]*>.*$~is', '', $html);

            $html = Pcre::replaceCallback(
                '~<(/?)([A-Za-z][A-Za-z0-9:_-]*)(?:"[^"]*"|\'[^\']*\'|[^\'">])*>~s',
                static fn(array $matches): string => self::cleanTag($matches[1], $matches[2], $allowed),
                $html
            );

            $html = Str::normalizeEntities($html);
            $html = Pcre::replaceUnicode('~\s+~u', '~[\s\x00-\x1F]+~', ' ', $html);

            return trim($html);
        }

        /**
         * Removes whitespace and non-word characters (legacy TrimSpaces()).
         */
        public static function trimSpaces(mixed $string): string
        {
            $value = Str::stringify($string);

            return Pcre::replaceUnicode('~[^\p{L}\p{N}_]+~u', '~\W+~', '', $value);
        }
    }
}
namespace HtmlPhp\Ssi {

    use HtmlPhp\Config;
    use HtmlPhp\Errors;
    use HtmlPhp\Hooks;
    use HtmlPhp\Io\Files;
    use HtmlPhp\Support\Str;

    /**
     * Parser for SSI directive attribute lists.
     *
     * Accepts double-quoted, single-quoted and (unlike mod_include, as a
     * documented convenience) unquoted values; attribute order is irrelevant.
     * Example: var="NAME" value='VALUE' stub=plain
     */
    final class Attributes
    {
        private function __construct()
        {
        }

        /**
         * @return array<string, string> lower-cased attribute name => raw value
         */
        public static function parse(string $source): array
        {
            $attributes = [];

            preg_match_all(
                '~([A-Za-z_][A-Za-z0-9_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\'"]+))~s',
                $source,
                $matches,
                \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL
            );

            foreach ($matches as $match) {
                $name = strtolower((string) $match[1]);
                $attributes[$name] = (string) ($match[2] ?? $match[3] ?? $match[4] ?? '');
            }

            return $attributes;
        }
    }

    /**
     * strftime()-style time format support (Apache #config timefmt).
     */
    final class TimeFormat
    {
        public const DEFAULT_FORMAT = '%A, %d-%b-%Y %H:%M:%S %Z';

        /**
         * strftime() token => date() token map.
         */
        private const MAP = [
            'Y' => 'Y', 'y' => 'y', 'C' => 'Y', 'm' => 'm', 'd' => 'd',
            'e' => 'j', 'H' => 'H', 'k' => 'G', 'I' => 'h', 'l' => 'g',
            'M' => 'i', 'S' => 's', 'p' => 'A', 'P' => 'a', 'B' => 'F',
            'h' => 'M', 'b' => 'M', 'A' => 'l', 'a' => 'D', 'Z' => 'T',
            'z' => 'O', 'u' => 'N', 'w' => 'w', 'G' => 'o', 'V' => 'W',
            'U' => 'W', 'W' => 'W', 'D' => 'm/d/y', 'F' => 'Y-m-d',
            'T' => 'H:i:s', 'R' => 'H:i', 'r' => 'h:i:s A',
            'c' => 'Y-m-d\TH:i:sP', 'x' => 'm/d/y', 'X' => 'H:i:s',
            's' => 'U', 'n' => "\n", 't' => "\t", '%' => '%',
        ];

        /** Every date() formatting character; literals must be escaped. */
        private const DATE_TOKENS = 'dDjlNSwzWFmMntLoYyaABgGXxhHisuevIOPTZcrUp';

        private function __construct()
        {
        }

        /**
         * Converts a strftime()-style format into a date()-compatible one.
         *
         * Literal characters are escaped because date() would reinterpret
         * them. %j (one-based day of year) becomes the DOY marker because
         * date() only has zero-based "z".
         */
        public static function strftimeToDate(string $format): string
        {
            $converted = '';
            $length = strlen($format);

            $escapeLiteral = static function (string $char): string {
                if ($char === '\\') {
                    return '\\\\';
                }

                return str_contains(self::DATE_TOKENS, $char) ? '\\' . $char : $char;
            };

            for ($index = 0; $index < $length; ++$index) {
                $character = $format[$index];

                if ($character !== '%' || $index + 1 >= $length) {
                    $converted .= $escapeLiteral($character);
                    continue;
                }

                $token = $format[++$index];

                if ($token === 'j') {
                    $converted .= Str::DOY_MARKER;
                    continue;
                }

                if (isset(self::MAP[$token])) {
                    $converted .= self::MAP[$token];
                    continue;
                }

                $converted .= $escapeLiteral($token);
            }

            return $converted;
        }

        /**
         * Formats a timestamp using a strftime()-style format.
         */
        public static function format(int $timestamp, string $format, bool $gmt = false): string
        {
            if ($format === '') {
                $format = self::DEFAULT_FORMAT;
            }

            $dateFormat = self::strftimeToDate($format);
            $result = $gmt ? gmdate($dateFormat, $timestamp) : date($dateFormat, $timestamp);

            if (str_contains($dateFormat, Str::DOY_MARKER)) {
                $dayOfYear = (int) ($gmt ? gmdate('z', $timestamp) : date('z', $timestamp)) + 1;
                $result = str_replace(Str::DOY_MARKER, sprintf('%03d', $dayOfYear), $result);
            }

            return $result;
        }

        /**
         * The effective time format (context override or global config).
         */
        public static function current(?string $override = null): string
        {
            if ($override !== null && $override !== '') {
                return $override;
            }

            $timefmt = Str::stringify($GLOBALS['HTML_SSI_TIMEFMT'] ?? '');

            return $timefmt !== '' ? $timefmt : self::DEFAULT_FORMAT;
        }
    }

    /**
     * Apache #config sizefmt support: "abbrev" (default) or "bytes".
     */
    final class SizeFormat
    {
        private function __construct()
        {
        }

        public static function format(int $bytes, ?string $style = null): string
        {
            $style ??= strtolower(Config::text('HTML_SSI_SIZEFMT', 'abbrev'));

            return Str::formatSize($bytes, in_array($style, ['bytes', 'byte'], true) ? 'bytes' : 'abbrev');
        }
    }

    /**
     * Built-in Apache SSI environment variables.
     */
    final class Builtins
    {
        /** Names exposed to #echo and $VAR expansion inside #set/#if. */
        public const NAMES = [
            'DATE_LOCAL' => true,
            'DATE_GMT' => true,
            'DOCUMENT_URI' => true,
            'DOCUMENT_NAME' => true,
            'DOCUMENT_ROOT' => true,
            'QUERY_STRING' => true,
            'SERVER_NAME' => true,
            'SERVER_SOFTWARE' => true,
            'SERVER_PROTOCOL' => true,
            'REQUEST_METHOD' => true,
            'REQUEST_URI' => true,
            'SCRIPT_NAME' => true,
            'CONTENT_TYPE' => true,
            'REMOTE_ADDR' => true,
            'HTTP_USER_AGENT' => true,
            'LAST_MODIFIED' => true,
        ];

        private function __construct()
        {
        }

        public static function isBuiltin(string $name): bool
        {
            return isset(self::NAMES[$name]);
        }

        /**
         * Resolves the request URI used by DOCUMENT_URI (query string removed).
         */
        public static function documentUri(): string
        {
            $uri = Str::stringify($_SERVER['REQUEST_URI'] ?? '');

            if ($uri === '') {
                $uri = Str::stringify($_SERVER['SCRIPT_NAME'] ?? '');
            }

            if ($uri === '') {
                return '';
            }

            $uri = (string) preg_replace('~\?.*$~s', '', $uri);
            $uri = str_replace('\\', '/', $uri);

            return str_starts_with($uri, '/') ? $uri : '/' . $uri;
        }

        /**
         * Computes the whole built-in map for one render context.
         *
         * @return array<string, string>
         */
        public static function map(?Context $context = null): array
        {
            $timefmt = $context !== null ? $context->timefmt : TimeFormat::current();
            $now = $context !== null ? $context->now : time();

            $documentUri = self::documentUri();
            $documentRoot = $context !== null ? $context->documentRoot() : '';

            if ($documentRoot === '') {
                $documentRoot = Str::stringify($_SERVER['DOCUMENT_ROOT'] ?? '');
            }

            $lastModified = '';
            $currentFile = $context?->currentFile;

            if (is_string($currentFile) && $currentFile !== '' && is_file($currentFile)) {
                $modified = @filemtime($currentFile);

                if ($modified !== false) {
                    $lastModified = TimeFormat::format($modified, $timefmt);
                }
            }

            $map = [
                'DATE_LOCAL' => TimeFormat::format($now, $timefmt),
                'DATE_GMT' => TimeFormat::format($now, $timefmt, true),
                'DOCUMENT_URI' => $documentUri,
                'DOCUMENT_NAME' => $documentUri === '' ? '' : basename($documentUri),
                'DOCUMENT_ROOT' => $documentRoot,
                'QUERY_STRING' => Str::stringify($_SERVER['QUERY_STRING'] ?? ''),
                'SERVER_NAME' => Str::stringify($_SERVER['SERVER_NAME'] ?? ''),
                'SERVER_SOFTWARE' => Str::stringify($_SERVER['SERVER_SOFTWARE'] ?? ('HTML.PHP/' . \HtmlPhp\Version::VERSION)),
                'SERVER_PROTOCOL' => Str::stringify($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1'),
                'REQUEST_METHOD' => Str::stringify($_SERVER['REQUEST_METHOD'] ?? 'GET'),
                'REQUEST_URI' => Str::stringify($_SERVER['REQUEST_URI'] ?? $documentUri),
                'SCRIPT_NAME' => Str::stringify($_SERVER['SCRIPT_NAME'] ?? ''),
                'CONTENT_TYPE' => Str::stringify($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''),
                'REMOTE_ADDR' => Str::stringify($_SERVER['REMOTE_ADDR'] ?? ''),
                'HTTP_USER_AGENT' => Str::stringify($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'LAST_MODIFIED' => $lastModified,
            ];

            $filtered = Hooks::applyFilters('htmlphp_ssi_builtins', $map);

            return is_array($filtered) ? $filtered : $map;
        }
    }

    /**
     * Per-render SSI state: resolved document root, current file, #config
     * overrides, include cycle stack, directive budget and a stable "now"
     * so DATE_LOCAL cannot tick over mid-render.
     */
    final class Context
    {
        public int $now;
        public int $depth = 0;
        public int $processed = 0;

        /** @var array<string, true> realpath stack guarding include cycles */
        public array $stack = [];

        public string $timefmt;
        public string $sizefmt;
        public ?string $errmsg = null;

        /** @var array<string, string>|null */
        private ?array $builtins = null;

        public function __construct(
            public ?string $currentFile = null,
            public ?string $rootOverride = null
        ) {
            $this->now = time();
            $this->timefmt = TimeFormat::current();
            $this->sizefmt = strtolower(Config::text('HTML_SSI_SIZEFMT', 'abbrev'));

            $globalErrmsg = $GLOBALS['HTML_SSI_ERRMSG'] ?? null;
            $this->errmsg = is_string($globalErrmsg) ? $globalErrmsg : null;
        }

        /**
         * Child context for an included file (shares budgets and #config state).
         */
        public function child(string $file): self
        {
            $child = new self($file, $this->rootOverride);
            $child->now = $this->now;
            $child->depth = $this->depth + 1;
            $child->processed = &$this->processed;
            $child->stack = &$this->stack;
            $child->timefmt = &$this->timefmt;
            $child->sizefmt = &$this->sizefmt;
            $child->errmsg = &$this->errmsg;

            return $child;
        }

        /**
         * Built-in SSI variables, computed once per root context.
         *
         * @return array<string, string>
         */
        public function builtins(): array
        {
            return $this->builtins ??= Builtins::map($this);
        }

        /**
         * The document root includes resolve against.
         */
        public function documentRoot(): string
        {
            $root = $this->rootOverride;

            if ($root === null || $root === '') {
                $root = Str::stringify($GLOBALS['HTML_WWW_PATH'] ?? ($_SERVER['DOCUMENT_ROOT'] ?? ''));
            }

            if ($root === '') {
                $root = getcwd() ?: '.';
            }

            $resolved = realpath($root);

            if ($resolved !== false && is_dir($resolved)) {
                return $resolved;
            }

            $trimmed = rtrim($root, "/\\");

            return $trimmed !== '' ? $trimmed : \DIRECTORY_SEPARATOR;
        }

        /**
         * Directory that relative includes of the current document resolve against.
         */
        public function relativeDir(): ?string
        {
            $current = $this->currentFile;

            if ($current === null || $current === '') {
                return null;
            }

            if (is_dir($current)) {
                return $current;
            }

            $directory = dirname($current);

            return $directory === '' || $directory === '.' ? null : $directory;
        }

        /**
         * Error replacement text for failed directives (#config errmsg).
         */
        public function errorText(): string
        {
            return $this->errmsg ?? '';
        }

        public function resetBuiltins(): void
        {
            $this->builtins = null;
        }

        /**
         * True while another include budget remains.
         */
        public function budgetAllows(): bool
        {
            $maxDepth = Config::limit('HTML_MAX_SSI_DEPTH', 16, 128);
            $maxTags = Config::limit('HTML_MAX_SSI_TAGS', 256, 10000);

            if ($this->depth >= $maxDepth) {
                Errors::add(Errors::SSI_LIMIT_REACHED, 'SSI include depth limit reached.', ['depth' => $this->depth]);

                return false;
            }

            if ($this->processed >= $maxTags) {
                Errors::add(Errors::SSI_LIMIT_REACHED, 'SSI directive budget exhausted.', ['processed' => $this->processed]);

                return false;
            }

            return true;
        }

        /**
         * One candidate include path, containment- and size-checked.
         */
        public function resolveCandidate(string $candidate): ?string
        {
            $resolved = realpath($candidate);

            if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
                return null;
            }

            if (!Config::flag('HTML_SSI_ALLOW_PARENT')) {
                $root = realpath($this->documentRoot());

                if ($root === false || !is_dir($root) || !Files::isInside($resolved, $root)) {
                    return null;
                }
            }

            $maximum = Config::limit('HTML_MAX_INCLUDE_BYTES', 8 * 1024 * 1024, 256 * 1024 * 1024);
            $size = @filesize($resolved);

            if ($size === false || $size > $maximum) {
                return null;
            }

            return $resolved;
        }

        /**
         * Resolves an SSI path (from #include/#fsize/#flastmod/file tests).
         *
         * Absolute paths ("/ssi/x.htm") resolve from the document root,
         * relative ones ("ssi/x.htm", "./ssi/x.htm") from the directory of
         * the file containing the directive first, then the document root.
         */
        public function resolvePath(string $virtual): ?string
        {
            if ($virtual === '' || str_contains($virtual, "\0")) {
                return null;
            }

            $absolute = str_starts_with($virtual, '/') || str_starts_with($virtual, '\\');

            $virtual = str_replace('\\', '/', $virtual);
            $virtual = ltrim($virtual, '/');
            $virtual = (string) preg_replace('~/+~', '/', $virtual);

            if ($virtual === '' || preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*://~', $virtual) === 1) {
                return null;
            }

            if (!Config::flag('HTML_SSI_ALLOW_PARENT') && preg_match('~(?:^|/)\.\.(?:/|$)~', $virtual) === 1) {
                return null;
            }

            $relative = str_replace('/', \DIRECTORY_SEPARATOR, $virtual);
            $root = $this->documentRoot();
            $candidates = [];

            if (!$absolute) {
                $relativeDir = $this->relativeDir();

                if ($relativeDir !== null && $relativeDir !== '') {
                    $candidates[] = rtrim($relativeDir, "/\\") . \DIRECTORY_SEPARATOR . $relative;
                }
            }

            $candidates[] = $root . \DIRECTORY_SEPARATOR . $relative;

            foreach ($candidates as $candidate) {
                $resolved = $this->resolveCandidate($candidate);

                if ($resolved !== null) {
                    return $resolved;
                }
            }

            return null;
        }
    }
}
namespace HtmlPhp\Ssi {

    use HtmlPhp\Errors;
    use HtmlPhp\Io\Files;
    use HtmlPhp\Support\Str;

    /**
     * Safe expression evaluator for <!--#if expr="…" --> conditions.
     *
     * Supported grammar (Apache mod_include compatible subset plus
     * documented extensions):
     *
     *   expr       := or
     *   or         := and ( '||' and )*
     *   and        := not ( '&&' not )*
     *   not        := '!' not | comparison
     *   comparison := operand [ ('='|'=='|'!='|'<'|'<='|'>'|'>=') operand ]
     *   operand    := filetest? primary
     *   filetest   := '-f' | '-d' | '-e' | '-s' | '-x' | '-r' | '-w'
     *   primary    := '(' expr ')' | STRING | REGEX | VARIABLE | WORD
     *
     *   STRING   := "…"  (backslash escapes: \" \\ \$ \/ \n \t)
     *   REGEX    := /…/  (right-hand side of = and != only)
     *   VARIABLE := $NAME | ${NAME}   (built-ins win, then $GLOBALS)
     *   WORD     := unquoted run of [A-Za-z0-9_.,:;@+/-] (a literal string)
     *
     * Semantics:
     *   - every value is a string; a value is true when it is not '' and not '0';
     *   - '=' with a /regex/ right side performs a case-sensitive match;
     *   - '<', '<=', '>', '>=' compare numerically when both sides are
     *     numeric strings, byte-wise otherwise;
     *   - file tests resolve paths exactly like #include (document-root
     *     containment) and yield '1' or '';
     *   - parse errors evaluate to false and are recorded in html_errors().
     *
     * There is no eval(), no shell, no PHP evaluation — expressions are
     * tokenised and parsed by this class only.
     */
    final class Expression
    {
        private const MAX_LENGTH = 4096;

        /** @var list<array{0: string, 1: string}> token stream [type, value] */
        private array $tokens = [];
        private int $position = 0;
        private ?Context $context;

        private function __construct(?Context $context)
        {
            $this->context = $context;
        }

        /**
         * Evaluates an expression string; errors yield false.
         */
        public static function evaluate(string $expression, ?Context $context = null): bool
        {
            $expression = trim($expression);

            if ($expression === '') {
                return false;
            }

            if (strlen($expression) > self::MAX_LENGTH) {
                Errors::add(Errors::SSI_EXPR_ERROR, 'SSI expression is too long.', ['length' => strlen($expression)]);

                return false;
            }

            $parser = new self($context);

            try {
                $parser->tokens = $parser->tokenize($expression);
                $result = $parser->parseOr();

                if ($parser->position < count($parser->tokens)) {
                    Errors::add(
                        Errors::SSI_EXPR_ERROR,
                        'Trailing input in SSI expression.',
                        ['expression' => $expression]
                    );

                    return false;
                }

                return self::truthy($result);
            } catch (ExpressionSyntaxError $error) {
                Errors::add(
                    Errors::SSI_EXPR_ERROR,
                    'Invalid SSI expression: ' . $error->getMessage(),
                    ['expression' => $expression]
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
            $length = strlen($source);
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

                if (preg_match('~[A-Za-z0-9_.,:;@+/\\\\*-]+~', $source, $matches, 0, $index) === 1
                    && $matches[0] !== ''
                ) {
                    $tokens[] = ['word', $matches[0]];
                    $index += strlen($matches[0]);
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

            throw new ExpressionSyntaxError('unterminated string literal');
        }

        /**
         * @return array{0: string, 1: int}
         */
        private function readVariable(string $source, int $index): array
        {
            if (($source[$index + 1] ?? '') === '{') {
                if (preg_match('~\$\{([A-Za-z_][A-Za-z0-9_.-]*)\}~A', $source, $matches, 0, $index) === 1) {
                    return [$matches[1], $index + strlen($matches[0])];
                }

                throw new ExpressionSyntaxError('malformed ${NAME} reference');
            }

            if (preg_match('~\$([A-Za-z_][A-Za-z0-9_.-]*)~A', $source, $matches, 0, $index) === 1) {
                return [$matches[1], $index + strlen($matches[0])];
            }

            throw new ExpressionSyntaxError('malformed $NAME reference');
        }

        /**
         * @return array{0: string, 1: int}
         */
        private function readRegex(string $source, int $index): array
        {
            $length = strlen($source);
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

            $previous = $tokens[count($tokens) - 1][0];

            // After a file-test operator ("/" starts a PATH word, not a regex).
            return in_array($previous, ['=', '==', '!=', '(', '!', '&&', '||'], true);
        }

        private function fileTestAt(string $source, int $index): bool
        {
            $pair = substr($source, $index, 2);

            return in_array($pair, ['-f', '-d', '-e', '-s', '-x', '-r', '-w'], true)
                && !preg_match('~[A-Za-z0-9_]~', $source[$index + 2] ?? ' ');
        }

        // ---------------------------------------------------------------- parser

        private function peek(): ?array
        {
            return $this->tokens[$this->position] ?? null;
        }

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

            if ($token === null || !in_array($token[0], ['=', '==', '!=', '<', '<=', '>', '>='], true)) {
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
                Errors::add(
                    Errors::SSI_EXPR_ERROR,
                    'Invalid regular expression in SSI condition.',
                    ['pattern' => $pattern]
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

            // Path containment mirrors #include semantics.
            $resolved = $this->context !== null
                ? $this->context->resolvePath($path)
                : (realpath($path) !== false && is_file(realpath($path)) ? realpath($path) : null);

            if ($operator === '-d') {
                $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\')
                    ? rtrim($this->context?->documentRoot() ?? '', "/\\") . '/' . ltrim(str_replace('\\', '/', $path), '/')
                    : null;

                if ($absolute !== null) {
                    $real = realpath($absolute);

                    if ($real !== false && is_dir($real)) {
                        if ($this->context === null
                            || \HtmlPhp\Config::flag('HTML_SSI_ALLOW_PARENT')
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
                '-f' => is_file($resolved),
                '-e' => true,
                '-s' => (@filesize($resolved) ?: 0) > 0,
                '-x' => is_executable($resolved),
                '-r' => is_readable($resolved),
                '-w' => is_writable($resolved),
                default => false,
            };
        }

        /**
         * Variable lookup shared with #echo: built-ins win, then $GLOBALS
         * under the allow-list gate.
         */
        private function resolveVariable(string $name): string
        {
            $name = trim($name);

            if ($name === '' || str_contains($name, "\0")) {
                return '';
            }

            if (Builtins::isBuiltin($name)) {
                if ($this->context !== null) {
                    return $this->context->builtins()[$name] ?? '';
                }

                return Builtins::map(null)[$name] ?? '';
            }

            if (!\HtmlPhp\Config::isGlobalAllowed($name)) {
                return '';
            }

            return Str::stringify($GLOBALS[$name] ?? '');
        }
    }

    /**
     * Internal parse failure signal (never escapes Expression::evaluate()).
     */
    final class ExpressionSyntaxError extends \Exception
    {
    }
}
namespace HtmlPhp\Ssi {

    use HtmlPhp\Config;
    use HtmlPhp\Errors;
    use HtmlPhp\Io\Files;
    use HtmlPhp\Support\Str;

    /**
     * Sandboxed runner for <!--#exec cmd="…" --> and <!--#exec cgi="…" -->.
     *
     * Security model (deny by default):
     *   - nothing runs unless HTML_SSI_EXEC is true;
     *   - cmd=: the first argv token must appear on the HTML_SSI_EXEC_CMD_ALLOW
     *     allow-list (binary name or absolute path); the command never goes
     *     through a shell (proc_open receives an argv array);
     *   - cgi=: the script must resolve inside the document root (same
     *     containment as #include) and have an extension listed in
     *     HTML_SSI_EXEC_CGI_EXT; mode "spawn" (default) runs it through
     *     PHP_BINARY in a separate process, mode "include" runs it in-process
     *     inside an output buffer (faster, shares process state — opt-in);
     *   - every run is bounded by HTML_SSI_EXEC_TIMEOUT seconds and
     *     HTML_SSI_EXEC_MAX_BYTES of output; exceeding either terminates the
     *     child and records an entry in html_errors().
     */
    final class Exec
    {
        private function __construct()
        {
        }

        /**
         * Runs <!--#exec cmd="…" -->.
         */
        public static function runCmd(string $command, Context $context): string
        {
            if (!self::guard($command, 'cmd')) {
                return $context->errorText();
            }

            $argv = self::tokenizeCommand($command);

            if ($argv === []) {
                Errors::add(Errors::SSI_EXEC_FAILED, 'SSI #exec cmd is empty.');

                return $context->errorText();
            }

            $binary = self::resolveAllowedBinary($argv[0]);

            if ($binary === null) {
                Errors::add(
                    Errors::SSI_EXEC_DENIED,
                    'SSI #exec cmd binary is not on HTML_SSI_EXEC_CMD_ALLOW.',
                    ['command' => $argv[0]]
                );

                return $context->errorText();
            }

            $argv[0] = $binary;

            $environment = [
                'PATH' => self::environment('PATH'),
                'DOCUMENT_ROOT' => $context->documentRoot(),
                'SERVER_NAME' => self::environment('SERVER_NAME'),
                'GATEWAY_INTERFACE' => 'CGI/1.1',
            ];

            return self::runProcess($argv, $environment, $context->documentRoot());
        }

        /**
         * Runs <!--#exec cgi="…" -->.
         */
        public static function runCgi(string $virtual, Context $context): string
        {
            if (!self::guard($virtual, 'cgi')) {
                return $context->errorText();
            }

            $script = $context->resolvePath($virtual);

            if ($script === null) {
                Errors::add(
                    Errors::SSI_EXEC_DENIED,
                    'SSI #exec cgi script did not resolve inside the document root.',
                    ['virtual' => $virtual]
                );

                return $context->errorText();
            }

            $extension = strtolower(pathinfo($script, \PATHINFO_EXTENSION));
            $allowedExtensions = array_map(
                static fn(mixed $value): string => strtolower(Str::stringify($value)),
                Config::list_('HTML_SSI_EXEC_CGI_EXT', ['php'])
            );

            if (!in_array($extension, $allowedExtensions, true)) {
                Errors::add(
                    Errors::SSI_EXEC_DENIED,
                    'SSI #exec cgi extension is not allowed.',
                    ['script' => $script, 'extension' => $extension]
                );

                return $context->errorText();
            }

            $mode = strtolower(Config::text('HTML_SSI_EXEC_CGI_MODE', 'spawn'));

            if ($mode === 'include') {
                return self::runInclude($script, $context);
            }

            $environment = [
                'PATH' => self::environment('PATH'),
                'DOCUMENT_ROOT' => $context->documentRoot(),
                'SCRIPT_NAME' => '/' . ltrim(str_replace('\\', '/', $virtual), '/'),
                'SCRIPT_FILENAME' => $script,
                'REQUEST_METHOD' => self::environment('REQUEST_METHOD', 'GET'),
                'QUERY_STRING' => self::environment('QUERY_STRING'),
                'SERVER_NAME' => self::environment('SERVER_NAME', 'localhost'),
                'SERVER_PROTOCOL' => self::environment('SERVER_PROTOCOL', 'HTTP/1.1'),
                'GATEWAY_INTERFACE' => 'CGI/1.1',
                'REDIRECT_STATUS' => '200',
                'HTTP_USER_AGENT' => self::environment('HTTP_USER_AGENT'),
            ];

            $argv = [
                \PHP_BINARY,
                '-d', 'display_errors=0',
                '-d', 'log_errors=0',
                '-f', $script,
            ];

            return self::runProcess($argv, $environment, dirname($script));
        }

        // ---------------------------------------------------------------- guards

        private static function guard(string $target, string $kind): bool
        {
            if ($target === '' || str_contains($target, "\0")) {
                return false;
            }

            if (!Config::flag('HTML_SSI_EXEC')) {
                Errors::add(
                    Errors::SSI_EXEC_DENIED,
                    'SSI #exec is disabled (set HTML_SSI_EXEC=true to enable).',
                    ['kind' => $kind]
                );

                return false;
            }

            return true;
        }

        /**
         * Splits a command line into argv tokens honouring single and double
         * quotes. No shell interpretation takes place.
         *
         * @return list<string>
         */
        public static function tokenizeCommand(string $command): array
        {
            $tokens = [];
            $length = strlen($command);
            $current = '';
            $quoted = false;
            $hasToken = false;

            for ($index = 0; $index < $length; ++$index) {
                $char = $command[$index];

                if ($quoted) {
                    if ($char === '\\' && $index + 1 < $length && $command[$index + 1] === '"') {
                        $current .= '"';
                        ++$index;
                        continue;
                    }

                    if ($char === '"') {
                        $quoted = false;
                        continue;
                    }

                    $current .= $char;
                    continue;
                }

                if ($char === '"') {
                    $quoted = true;
                    $hasToken = true;
                    continue;
                }

                if ($char === "'") {
                    $end = strpos($command, "'", $index + 1);
                    $end = $end === false ? $length : $end;
                    $current .= substr($command, $index + 1, $end - $index - 1);
                    $index = $end;
                    $hasToken = true;
                    continue;
                }

                if ($char === ' ' || $char === "\t") {
                    if ($hasToken || $current !== '') {
                        $tokens[] = $current;
                        $current = '';
                        $hasToken = false;
                    }

                    continue;
                }

                $current .= $char;
                $hasToken = true;
            }

            if ($hasToken || $current !== '') {
                $tokens[] = $current;
            }

            return $tokens;
        }

        /**
         * Resolves the command's binary against the allow-list.
         */
        private static function resolveAllowedBinary(string $binary): ?string
        {
            $allow = Config::list_('HTML_SSI_EXEC_CMD_ALLOW', []);

            if ($allow === []) {
                return null;
            }

            $allowStrings = array_map(static fn(mixed $value): string => Str::stringify($value), $allow);

            $candidates = [];

            if (str_contains($binary, '/') || str_contains($binary, '\\')) {
                $candidates[] = $binary;
            } else {
                foreach (explode(\PATH_SEPARATOR, self::environment('PATH')) as $directory) {
                    if ($directory === '') {
                        continue;
                    }

                    $candidates[] = rtrim($directory, "/\\") . \DIRECTORY_SEPARATOR . $binary;
                }
            }

            foreach ($candidates as $candidate) {
                $resolved = realpath($candidate);

                if ($resolved === false || !is_file($resolved) || !is_executable($resolved)) {
                    continue;
                }

                foreach ($allowStrings as $allowed) {
                    if ($allowed === '') {
                        continue;
                    }

                    $allowedResolved = realpath($allowed);

                    if ($allowedResolved !== false) {
                        if (Files::normalizeForCompare($resolved) === Files::normalizeForCompare($allowedResolved)) {
                            return $resolved;
                        }

                        continue;
                    }

                    if ($allowed === basename($resolved)) {
                        return $resolved;
                    }
                }
            }

            return null;
        }

        private static function environment(string $key, string $default = ''): string
        {
            $value = $_SERVER[$key] ?? getenv($key);

            return is_string($value) && $value !== '' ? $value : $default;
        }

        // ------------------------------------------------------------- execution

        /**
         * Spawns a process, capturing at most HTML_SSI_EXEC_MAX_BYTES of
         * stdout within HTML_SSI_EXEC_TIMEOUT seconds.
         *
         * @param list<string> $argv
         * @param array<string, string> $environment
         */
        private static function runProcess(array $argv, array $environment, string $cwd): string
        {
            $timeout = Config::limit('HTML_SSI_EXEC_TIMEOUT', 5, 60);
            $maxBytes = Config::limit('HTML_SSI_EXEC_MAX_BYTES', 65536, 16 * 1024 * 1024);

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $filteredEnvironment = array_filter(
                $environment,
                static fn(string $value): bool => $value !== ''
            );

            $process = @proc_open($argv, $descriptors, $pipes, $cwd, $filteredEnvironment);

            if (!is_resource($process)) {
                Errors::add(Errors::SSI_EXEC_FAILED, 'SSI #exec could not spawn the process.', ['argv' => $argv]);

                return '';
            }

            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);

            $output = '';
            $started = microtime(true);
            $timedOut = false;
            $truncated = false;

            while (true) {
                $read = [$pipes[1]];
                $write = null;
                $except = null;
                $select = @stream_select($read, $write, $except, 0, 200000);

                if ($select === false) {
                    break;
                }

                if ($select > 0) {
                    $chunk = fread($pipes[1], 8192);

                    if ($chunk === false || ($chunk === '' && feof($pipes[1]))) {
                        break;
                    }

                    if ($chunk !== '') {
                        $remaining = $maxBytes - strlen($output);

                        if ($remaining <= 0) {
                            $truncated = true;
                            break;
                        }

                        if (strlen($chunk) > $remaining) {
                            $chunk = substr($chunk, 0, $remaining);
                            $truncated = true;
                        }

                        $output .= $chunk;

                        if ($truncated) {
                            break;
                        }
                    }
                } elseif (feof($pipes[1])) {
                    break;
                }

                if (microtime(true) - $started > $timeout) {
                    $timedOut = true;
                    break;
                }
            }

            if ($timedOut || $truncated) {
                @proc_terminate($process, defined('SIGKILL') ? \SIGKILL : 9);
            }

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }

            $status = proc_close($process);

            if ($timedOut) {
                Errors::add(
                    Errors::SSI_EXEC_FAILED,
                    sprintf('SSI #exec timed out after %d second(s).', $timeout),
                    ['argv' => $argv]
                );
            } elseif ($truncated) {
                Errors::add(
                    Errors::SSI_EXEC_FAILED,
                    sprintf('SSI #exec output truncated at %d byte(s).', $maxBytes),
                    ['argv' => $argv]
                );
            } elseif ($status !== 0) {
                Errors::add(
                    Errors::SSI_EXEC_FAILED,
                    sprintf('SSI #exec exited with status %d.', $status),
                    ['argv' => $argv]
                );
            }

            return $output;
        }

        /**
         * In-process CGI mode: include the script inside an output buffer.
         */
        private static function runInclude(string $script, Context $context): string
        {
            $maxBytes = Config::limit('HTML_SSI_EXEC_MAX_BYTES', 65536, 16 * 1024 * 1024);

            $runner = static function () use ($script): void {
                include $script;
            };

            ob_start();

            try {
                $runner();
            } catch (\Throwable $error) {
                ob_end_clean();
                Errors::add(
                    Errors::SSI_EXEC_FAILED,
                    'SSI #exec cgi include threw: ' . $error->getMessage(),
                    ['script' => $script]
                );

                return $context->errorText();
            }

            $output = (string) ob_get_clean();

            if (strlen($output) > $maxBytes) {
                $output = substr($output, 0, $maxBytes);
                Errors::add(
                    Errors::SSI_EXEC_FAILED,
                    sprintf('SSI #exec output truncated at %d byte(s).', $maxBytes),
                    ['script' => $script]
                );
            }

            return $output;
        }
    }
}
namespace HtmlPhp\Ssi {

    use HtmlPhp\Config;
    use HtmlPhp\Errors;
    use HtmlPhp\Escaper;
    use HtmlPhp\Hooks;
    use HtmlPhp\Io\Files;
    use HtmlPhp\Support\Pcre;
    use HtmlPhp\Support\Str;

    /**
     * The SSI directive engine.
     *
     * Unlike 2.0.1 (which applied one regex pass per directive type in a
     * fixed order), 3.0 scans the document sequentially and evaluates every
     * directive in document order — the semantics of Apache mod_include.
     * Conditional directives (#if/#elif/#else/#endif) are tracked on a frame
     * stack, so directives inside a false branch are parsed for nesting but
     * never executed.
     *
     * Supported directives:
     *   #config timefmt=… sizefmt=… errmsg=…
     *   #set var=… value=…
     *   #include virtual=… | file=…
     *   #echo var=… [encoding=none|entity|url]
     *   #exec cmd=… | cgi=…            (deny by default — see Exec)
     *   #flastmod file=… | virtual=…
     *   #fsize file=… | virtual=…
     *   #if expr=… / #elif expr=… / #else / #endif
     *   ##echo var=…                   (legacy double-hash form)
     *   <!--@var="NAME"value-->        (legacy html4.pl form)
     */
    final class Processor
    {
        /**
         * Scanner pattern: every SSI comment in one match.
         *
         * body = "##name" | "#name" | "@var" ; rest = attribute payload.
         */
        private const DIRECTIVE_PATTERN =
            '~<!--(?<body>\#\#[a-zA-Z]+|\#[a-zA-Z]+|@var\b)(?<rest>.*?)-->~is';

        /** Maximum #if nesting depth (independent of include depth). */
        private const MAX_CONDITIONAL_DEPTH = 64;

        private Context $context;

        /** @var list<array{active: bool, taken: bool}> */
        private array $frames = [];

        private function __construct(Context $context)
        {
            $this->context = $context;
        }

        /**
         * Processes all SSI directives in $html.
         *
         * @param Context|null $context per-render state (created on demand)
         */
        public static function process(string $html, ?Context $context = null): string
        {
            $processor = new self($context ?? new Context());

            return $processor->scan($html);
        }

        // ---------------------------------------------------------------- scanner

        private function scan(string $html): string
        {
            if ($html === '' || !str_contains($html, '<!--')) {
                return $html;
            }

            $parts = preg_split(self::DIRECTIVE_PATTERN, $html, -1, \PREG_SPLIT_DELIM_CAPTURE);

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

        /**
         * Routes one directive; maintains the conditional frame stack.
         */
        private function dispatch(string $body, string $rest): string
        {
            $original = '<!--' . $body . $rest . '-->';

            if (str_starts_with($body, '@var')) {
                return $this->active() ? $this->legacyVarDirective($body . $rest, $original) : '';
            }

            $legacy = str_starts_with($body, '##');
            $name = strtolower($legacy ? substr($body, 2) : substr($body, 1));

            // Conditional directives are always tracked (nesting), but their
            // expressions are only evaluated while the enclosing branch runs.
            if (in_array($name, ['if', 'elif', 'elseif', 'else', 'endif'], true)) {
                $this->conditional($name, $rest);

                return '';
            }

            if (!$this->active()) {
                return '';
            }

            $attributes = Attributes::parse($rest);

            return match ($legacy ? '##' . $name : $name) {
                'config' => $this->configDirective($attributes),
                'set' => $this->setDirective($attributes),
                'include' => $this->includeDirective($attributes),
                'echo' => $this->echoDirective($attributes),
                '##echo' => $this->legacyEchoDirective($attributes),
                'exec' => $this->execDirective($attributes),
                'flastmod' => $this->lastmodDirective($attributes),
                'fsize' => $this->sizeDirective($attributes),
                // Unknown directives stay in the output verbatim (mod_include
                // compatibility: they are ordinary HTML comments to us).
                default => $original,
            };
        }

        /**
         * Current branch activity (top of the conditional frame stack).
         */
        private function active(): bool
        {
            return $this->frames === [] || $this->frames[count($this->frames) - 1]['active'];
        }

        private function parentActive(): bool
        {
            $depth = count($this->frames);

            return $depth === 0 || $this->frames[$depth - 1]['active'];
        }

        /**
         * Handles #if / #elif / #else / #endif frame transitions.
         */
        private function conditional(string $name, string $rest): void
        {
            if ($name === 'endif') {
                if ($this->frames === []) {
                    Errors::add(Errors::SSI_EXPR_ERROR, 'SSI #endif without a matching #if.');

                    return;
                }

                array_pop($this->frames);

                return;
            }

            if ($name === 'if') {
                if (count($this->frames) >= self::MAX_CONDITIONAL_DEPTH) {
                    Errors::add(Errors::SSI_EXPR_ERROR, 'SSI conditional nesting is too deep.');
                    $this->frames[] = ['active' => false, 'taken' => true];

                    return;
                }

                $result = $this->parentActive() && $this->evaluate($rest);
                $this->frames[] = ['active' => $result, 'taken' => $result];

                return;
            }

            // #elif / #else without an open #if are ignored (mod_include parity).
            if ($this->frames === []) {
                Errors::add(Errors::SSI_EXPR_ERROR, 'SSI #' . $name . ' without a matching #if.');

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

        /**
         * Activity of the frames below the top one (used by #elif/#else).
         */
        private function parentActiveExcludingTop(): bool
        {
            $depth = count($this->frames);

            return $depth <= 1 || $this->frames[$depth - 2]['active'];
        }

        /**
         * Extracts and evaluates the expr="…" payload of #if/#elif.
         */
        private function evaluate(string $rest): bool
        {
            $attributes = Attributes::parse($rest);
            $expression = $attributes['expr'] ?? '';

            // mod_include also accepts expr=v (unquoted); Attributes keeps it.
            return Expression::evaluate($expression, $this->context);
        }

        // ------------------------------------------------------------- directives

        private function configDirective(array $attributes): string
        {
            if (isset($attributes['timefmt']) && $attributes['timefmt'] !== '') {
                $GLOBALS['HTML_SSI_TIMEFMT'] = $attributes['timefmt'];
                $this->context->timefmt = $attributes['timefmt'];
                $this->context->resetBuiltins();
            }

            if (isset($attributes['sizefmt']) && $attributes['sizefmt'] !== '') {
                $sizefmt = strtolower($attributes['sizefmt']);
                $GLOBALS['HTML_SSI_SIZEFMT'] = $sizefmt;
                $this->context->sizefmt = $sizefmt;
            }

            if (array_key_exists('errmsg', $attributes)) {
                $this->context->errmsg = $attributes['errmsg'];
            }

            return '';
        }

        private function setDirective(array $attributes): string
        {
            $name = trim($attributes['var'] ?? '');
            $value = $attributes['value'] ?? '';

            if (self::isWritableName($name)) {
                $GLOBALS[$name] = $this->expandVariables($value);
            }

            return '';
        }

        private function includeDirective(array $attributes): string
        {
            $virtual = $attributes['virtual'] ?? '';
            $file = $attributes['file'] ?? '';

            if ($virtual === '' && $file === '') {
                return '';
            }

            if (!$this->context->budgetAllows()) {
                return '';
            }

            $path = null;

            if ($file !== '') {
                // file= is strictly relative to the directory of the current document.
                $path = $this->resolveFileAttribute($file);
            } else {
                $path = $this->context->resolvePath($virtual);
            }

            $label = $virtual !== '' ? $virtual : $file;

            if ($path === null) {
                Errors::add(
                    Errors::SSI_INCLUDE_FAILED,
                    'SSI include could not be resolved or is outside the document root.',
                    ['target' => $label]
                );

                return $this->context->errorText();
            }

            $path = Str::stringify(Hooks::applyFilters('htmlphp_ssi_include_path', $path, $label));

            if ($path === '' || isset($this->context->stack[$path])) {
                Errors::add(
                    Errors::SSI_INCLUDE_CYCLE,
                    'SSI include cycle detected.',
                    ['target' => $label, 'path' => $path]
                );

                return $this->context->errorText();
            }

            $contents = Files::slurp($path);

            if ($contents === null) {
                Errors::add(Errors::SSI_INCLUDE_FAILED, 'SSI include could not be read.', ['path' => $path]);

                return $this->context->errorText();
            }

            ++$this->context->processed;

            $child = $this->context->child($path);
            $this->context->stack[$path] = true;

            try {
                $processor = new self($child);

                return $processor->scan($contents);
            } finally {
                unset($this->context->stack[$path]);
                $this->context->processed = $child->processed;
            }
        }

        /**
         * Apache file= semantics: relative to the containing document only,
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

            if (!Config::flag('HTML_SSI_ALLOW_PARENT') && preg_match('~(?:^|/)\.\.(?:/|$)~', $file) === 1) {
                return null;
            }

            $relativeDir = $this->context->relativeDir();

            if ($relativeDir === null || $relativeDir === '') {
                return null;
            }

            $candidate = rtrim($relativeDir, "/\\") . \DIRECTORY_SEPARATOR
                . str_replace('/', \DIRECTORY_SEPARATOR, ltrim($file, './'));

            return $this->context->resolveCandidate($candidate);
        }

        private function echoDirective(array $attributes): string
        {
            $name = $attributes['var'] ?? '';
            $value = $this->echoValue($name);
            $encoding = strtolower($attributes['encoding'] ?? '');

            $escaped = match ($encoding) {
                'none' => $value,
                'url' => rawurlencode($value),
                'entity' => Escaper::html($value),
                default => Config::flag('HTML_SSI_ECHO_ESCAPE', true) ? Escaper::html($value) : $value,
            };

            return Str::stringify(Hooks::applyFilters('htmlphp_ssi_echo', $escaped, trim($name)));
        }

        private function legacyEchoDirective(array $attributes): string
        {
            $name = trim($attributes['var'] ?? '');

            if (!Config::isGlobalAllowed($name)) {
                return '';
            }

            $value = Str::stringify($GLOBALS[$name] ?? '');

            return Config::flag('HTML_SSI_ECHO_ESCAPE', true) ? Escaper::html($value) : $value;
        }

        private function execDirective(array $attributes): string
        {
            $cmd = $attributes['cmd'] ?? '';
            $cgi = $attributes['cgi'] ?? '';

            if ($cmd !== '') {
                return Exec::runCmd($cmd, $this->context);
            }

            if ($cgi !== '') {
                return Exec::runCgi($cgi, $this->context);
            }

            return '';
        }

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
            $current = $this->context->currentFile;

            return is_string($current) && $current !== '' && is_file($current) ? $current : null;
        }

        /**
         * Legacy <!--@var="NAME" value--> directive: stores value (gated) and
         * emits it untrimmed, exactly like 2.0.1.
         */
        private function legacyVarDirective(string $payload, string $original): string
        {
            $matches = [];

            if (Pcre::match('~^@var\s*=\s*"([^"]*)"(.*?)$~is', $payload, $matches)) {
                $name = trim($matches[1]);
                $value = trim($matches[2]);

                if (self::isWritableName($name)) {
                    $GLOBALS[$name] = $value;
                }

                return $matches[2];
            }

            return $original;
        }

        // ------------------------------------------------------------- variables

        /**
         * True when a template directive may store a value under $name.
         * Reserved names and built-in SSI variables are never writable.
         */
        public static function isWritableName(string $name): bool
        {
            if (!Str::isValidVariableName($name)) {
                return false;
            }

            return !Config::isReserved($name) && !Builtins::isBuiltin($name);
        }

        /**
         * Value of an #echo variable: built-ins win over template globals.
         */
        public function echoValue(string $name): string
        {
            $name = trim($name);

            if ($name === '' || str_contains($name, "\0") || Config::isReserved($name)) {
                return '';
            }

            if (Builtins::isBuiltin($name)) {
                $allow = $GLOBALS['HTML_SSI_ECHO_ALLOW'] ?? [];

                if (is_array($allow) && $allow !== []
                    && !isset($allow[$name]) && !in_array($name, $allow, true)
                ) {
                    return '';
                }

                return $this->context->builtins()[$name] ?? '';
            }

            if (!Config::isGlobalAllowed($name)) {
                return '';
            }

            return Str::stringify($GLOBALS[$name] ?? '');
        }

        /**
         * Expands $VAR / ${VAR} inside a #set value payload.
         *
         * Unknown names are kept verbatim; a backslash escapes a literal
         * dollar sign; reserved names expand to ''.
         */
        public function expandVariables(string $value): string
        {
            if ($value === '' || !str_contains($value, '$')) {
                return $value;
            }

            $protected = Pcre::replace('~\\\\\$~', "\x01", $value);
            $builtins = null;
            $context = $this->context;

            $expanded = Pcre::replaceCallback(
                '~\$(?:\{([A-Za-z_][A-Za-z0-9_]*)\}|([A-Za-z_][A-Za-z0-9_]*))~',
                static function (array $matches) use (&$builtins, $context): string {
                    $name = $matches[1] !== '' ? $matches[1] : ($matches[2] ?? '');

                    if ($name === '' || Config::isReserved($name)) {
                        return '';
                    }

                    if (Builtins::isBuiltin($name)) {
                        $builtins ??= $context->builtins();

                        return $builtins[$name] ?? '';
                    }

                    if (array_key_exists($name, $GLOBALS) && $GLOBALS[$name] !== null) {
                        return Str::stringify($GLOBALS[$name]);
                    }

                    return $matches[0];
                },
                $protected
            );

            return str_replace("\x01", '$', $expanded);
        }
    }
}
namespace HtmlPhp\I18n {

    /**
     * Locale tag handling: normalisation and Accept-Language negotiation.
     */
    final class Locale
    {
        public const DEFAULT_LOCALE = 'en';

        private function __construct()
        {
        }

        /**
         * Normalises any locale spelling to "ll_CC":
         *   en-us, EN_US.utf8, en_US.UTF-8@euro, en-usoed → en_US / en.
         */
        public static function normalize(string $locale): string
        {
            $locale = trim($locale);

            if ($locale === '') {
                return self::DEFAULT_LOCALE;
            }

            // Drop codeset and modifier: ru_RU.UTF-8@euro → ru_RU
            $locale = preg_replace('~[.@].*$~', '', $locale) ?? $locale;
            $locale = str_replace('-', '_', $locale);

            if (preg_match('~^([A-Za-z]{2,3})(?:_([A-Za-z]{4}))?(?:_([A-Za-z]{2}|[0-9]{3}))?$~', $locale, $m) !== 1) {
                return self::DEFAULT_LOCALE;
            }

            $language = strtolower($m[1]);

            if (($m[3] ?? '') !== '') {
                return $language . '_' . strtoupper($m[3]);
            }

            if (($m[2] ?? '') !== '') {
                // Script subtag only (zh_Hant): keep as zh_Hant.
                return $language . '_' . ucfirst(strtolower($m[2]));
            }

            return $language;
        }

        /**
         * Language part of a locale ("ru_RU" → "ru").
         */
        public static function language(string $locale): string
        {
            $normalized = self::normalize($locale);
            $position = strpos($normalized, '_');

            return $position === false ? $normalized : substr($normalized, 0, $position);
        }

        /**
         * Parses an Accept-Language header into q-sorted candidates.
         *
         * @return list<array{locale: string, q: float}>
         */
        public static function parseAcceptLanguage(?string $header): array
        {
            $header ??= isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
                ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE']
                : '';

            if (trim($header) === '') {
                return [];
            }

            $candidates = [];

            foreach (explode(',', $header) as $part) {
                $segments = explode(';', trim($part));
                $tag = self::normalize(trim((string) $segments[0]));
                $quality = 1.0;

                foreach (array_slice($segments, 1) as $parameter) {
                    if (preg_match('~^\s*q\s*=\s*([0-9.]+)\s*$~i', $parameter, $m) === 1) {
                        $quality = max(0.0, min(1.0, (float) $m[1]));
                    }
                }

                if ($tag !== '' && $tag !== '*') {
                    $candidates[] = ['locale' => $tag, 'q' => $quality];
                } elseif ($tag === '*') {
                    $candidates[] = ['locale' => '*', 'q' => $quality];
                }
            }

            usort($candidates, static fn(array $a, array $b): int => $b['q'] <=> $a['q']);

            return $candidates;
        }

        /**
         * Picks the best available locale for an Accept-Language header.
         *
         * Match order: exact tag → same language with any territory → any
         * available locale sharing the language prefix → $default.
         *
         * @param list<string>|array<string, mixed> $available
         */
        public static function negotiate(array $available, ?string $header = null, ?string $default = null): string
        {
            $availableList = array_values(array_map(
                static fn(mixed $locale): string => self::normalize((string) $locale),
                is_array($available) ? $available : []
            ));

            if ($availableList === []) {
                return self::normalize($default ?? self::DEFAULT_LOCALE);
            }

            $fallback = self::normalize($default ?? $availableList[0]);

            foreach (self::parseAcceptLanguage($header) as $candidate) {
                $wanted = $candidate['locale'];

                if ($wanted === '*') {
                    continue;
                }

                if (in_array($wanted, $availableList, true)) {
                    return $wanted;
                }

                $language = self::language($wanted);

                foreach ($availableList as $option) {
                    if (self::language($option) === $language) {
                        return $option;
                    }
                }
            }

            return $fallback;
        }
    }

    /**
     * Gettext plural-form expression evaluator.
     *
     * Parses expressions such as
     *   nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4
     *          && (n%100<10 || n%100>=20) ? 1 : 2);
     * with a small recursive-descent parser. There is no eval(): only integer
     * arithmetic over `n`, comparisons, logical operators, ?: and parentheses
     * are understood, and the result is clamped to the declared form count.
     */
    final class PluralForms
    {
        /** Hard cap — gettext plural counts never legitimately exceed this. */
        public const MAX_FORMS = 6;

        private const MAX_EXPRESSION_LENGTH = 512;

        /**
         * Built-in plural rules per language, used when a catalogue has no
         * Plural-Forms header. Key = language code.
         */
        private const LANGUAGE_RULES = [
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
            // Icelandic / Macedonian edge cases
            'is' => 'nplurals=2; plural=(n%10!=1 || n%100==11);',
            'mk' => 'nplurals=2; plural=(n%10==1 && n%100!=11);',
            // Maltese
            'mt' => 'nplurals=4; plural=(n==1 ? 0 : n==0 || ( n%100>1 && n%100<11) ? 1 : (n%100>10 && n%100<20 ) ? 2 : 3);',
        ];

        private int $count;
        private array $tokens;
        private int $position = 0;

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

            $header = is_string($header) ? trim($header) : '';

            if ($header !== '') {
                if (preg_match('~nplurals\s*=\s*(\d+)~i', $header, $m) === 1) {
                    $count = max(1, min(self::MAX_FORMS, (int) $m[1]));
                }

                if (preg_match('~plural\s*=\s*(.+?)\s*;?\s*$~is', $header, $m) === 1) {
                    $candidate = trim($m[1]);

                    if ($candidate !== '' && strlen($candidate) <= self::MAX_EXPRESSION_LENGTH) {
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

                if ($this->position < count($this->tokens)) {
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
            $length = strlen($expression);
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
                    $index += strlen($m[0]);
                    continue;
                }

                if ($char === 'n') {
                    $tokens[] = ['n', 'n'];
                    ++$index;
                    continue;
                }

                $two = substr($expression, $index, 2);

                if (in_array($two, ['==', '!=', '<=', '>=', '&&', '||'], true)) {
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
                && in_array($token[0], ['<', '<=', '>', '>='], true)) {
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
                && in_array($token[0], ['*', '/', '%'], true)) {
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
}
namespace HtmlPhp\I18n {

    use HtmlPhp\Errors;
    use HtmlPhp\Io\Files;
    use HtmlPhp\Support\Str;

    /**
     * One message catalogue: a single (locale, domain) pair.
     *
     * Parses both gettext formats:
     *   - PO (text): msgctxt/msgid/msgid_plural/msgstr[n], multi-line
     *     strings, comments and the fuzzy flag;
     *   - MO (binary): little- and big-endian magic, context separator
     *     "\x04" and NUL-separated plural forms.
     *
     * Non-UTF-8 charsets declared in the PO header are converted with
     * iconv/mbstring when available.
     */
    final class Catalog
    {
        private const CONTEXT_SEPARATOR = "\x04";

        public readonly string $locale;
        public readonly string $domain;

        /** @var array<string, array{single: ?string, plurals: list<string>, fuzzy: bool}> */
        private array $messages = [];

        /** @var array<string, string> lower-cased header name => value */
        private array $headers = [];

        private ?PluralForms $pluralForms = null;
        private string $charset = 'utf-8';

        private function __construct(string $locale, string $domain)
        {
            $this->locale = $locale;
            $this->domain = $domain;
        }

        /**
         * Loads a catalogue from a .po or .mo file (sniffed by magic bytes).
         */
        public static function load(string $path, string $locale, string $domain = 'messages'): ?self
        {
            $contents = Files::slurp($path, 32 * 1024 * 1024);

            if ($contents === null) {
                Errors::add(Errors::CATALOG_PARSE, 'Translation catalogue is not readable.', ['path' => $path]);

                return null;
            }

            $magic = substr($contents, 0, 4);

            if ($magic === "\xde\x12\x04\x95" || $magic === "\x95\x04\x12\xde") {
                return self::fromMo($contents, $locale, $domain);
            }

            $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

            if ($extension === 'mo') {
                Errors::add(Errors::CATALOG_PARSE, 'File has a .mo extension but no MO magic.', ['path' => $path]);

                return null;
            }

            return self::fromPo($contents, $locale, $domain);
        }

        // ------------------------------------------------------------------- PO

        public static function fromPo(string $source, string $locale, string $domain = 'messages'): self
        {
            $catalog = new self(Locale::normalize($locale), $domain);

            $entry = ['context' => '', 'id' => '', 'idPlural' => '', 'forms' => [], 'fuzzy' => false];
            $target = null; // ['id'|'context'|'idPlural'|'form', int]
            $lines = preg_split('~\r\n|\r|\n~', $source) ?: [];

            $flush = static function () use (&$entry, $catalog): void {
                if ($entry['id'] !== '' || $entry['forms'] !== []) {
                    $catalog->store(
                        $entry['context'],
                        $entry['id'],
                        $entry['idPlural'],
                        $entry['forms'],
                        $entry['fuzzy']
                    );
                }

                $entry = ['context' => '', 'id' => '', 'idPlural' => '', 'forms' => [], 'fuzzy' => false];
            };

            foreach ($lines as $rawLine) {
                $line = trim($rawLine);

                if ($line === '') {
                    $flush();
                    $target = null;
                    continue;
                }

                if ($line[0] === '#') {
                    if (str_starts_with($line, '#,')) {
                        $flags = array_map('trim', explode(',', substr($line, 2)));
                        $entry['fuzzy'] = $entry['fuzzy'] || in_array('fuzzy', $flags, true);
                    }

                    // #. #: #| and plain # comments carry no payload for us.
                    continue;
                }

                if (preg_match('~^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(.*)$~', $line, $m) === 1) {
                    $keyword = $m[1];
                    $payload = $m[3];

                    if (str_starts_with($keyword, 'msgstr[')) {
                        $target = ['form', (int) ($m[2] ?? 0)];
                    } elseif ($keyword === 'msgstr') {
                        $target = ['form', 0];
                    } elseif ($keyword === 'msgid') {
                        $target = ['id', 0];
                    } elseif ($keyword === 'msgid_plural') {
                        $target = ['idPlural', 0];
                    } else {
                        $target = ['context', 0];
                    }

                    $string = self::poString($payload);

                    if ($string !== null) {
                        $catalog->appendEntry($entry, $target, $string);
                    }

                    continue;
                }

                // Continuation line: a bare quoted string.
                if ($line[0] === '"' && $target !== null) {
                    $string = self::poString($line);

                    if ($string !== null) {
                        $catalog->appendEntry($entry, $target, $string);
                    }
                }
            }

            $flush();
            $catalog->finalizeHeaders();

            return $catalog;
        }

        /**
         * Appends a parsed string fragment to the current entry slot.
         *
         * @param array{context: string, id: string, idPlural: string, forms: array<int, string>, fuzzy: bool} $entry
         * @param array{0: string, 1: int} $target
         */
        private function appendEntry(array &$entry, array $target, string $fragment): void
        {
            match ($target[0]) {
                'context' => $entry['context'] .= $fragment,
                'id' => $entry['id'] .= $fragment,
                'idPlural' => $entry['idPlural'] .= $fragment,
                default => $entry['forms'][$target[1]] = ($entry['forms'][$target[1]] ?? '') . $fragment,
            };
        }

        /**
         * Parses one PO quoted string ("…" with escapes); null when invalid.
         */
        private static function poString(string $payload): ?string
        {
            $payload = trim($payload);

            if ($payload === '' || $payload[0] !== '"') {
                return null;
            }

            $length = strlen($payload);
            $value = '';

            for ($index = 1; $index < $length; ++$index) {
                $char = $payload[$index];

                if ($char === '\\' && $index + 1 < $length) {
                    $next = $payload[++$index];
                    $value .= match ($next) {
                        'n' => "\n",
                        't' => "\t",
                        'r' => "\r",
                        '0' => "\0",
                        default => $next,
                    };
                    continue;
                }

                if ($char === '"') {
                    return $value;
                }

                $value .= $char;
            }

            return null; // Unterminated string.
        }

        // ------------------------------------------------------------------- MO

        public static function fromMo(string $binary, string $locale, string $domain = 'messages'): ?self
        {
            $length = strlen($binary);

            if ($length < 28) {
                Errors::add(Errors::CATALOG_PARSE, 'MO catalogue is truncated.');

                return null;
            }

            $magic = substr($binary, 0, 4);
            $endian = $magic === "\xde\x12\x04\x95" ? 'V' : 'N';

            $header = unpack($endian . 'revision/' . $endian . 'count/' . $endian . 'origOffset/'
                . $endian . 'transOffset/' . $endian . 'hashSize/' . $endian . 'hashOffset', substr($binary, 4, 24));

            if ($header === false) {
                return null;
            }

            $majorRevision = ($header['revision'] >> 16) & 0xFFFF;

            if ($majorRevision > 1) {
                Errors::add(
                    Errors::CATALOG_PARSE,
                    sprintf('Unsupported MO revision %d.', $majorRevision)
                );

                return null;
            }

            $count = (int) $header['count'];
            $origOffset = (int) $header['origOffset'];
            $transOffset = (int) $header['transOffset'];

            if ($count < 0 || $count > 1_000_000
                || $origOffset < 0 || $origOffset + $count * 8 > $length
                || $transOffset < 0 || $transOffset + $count * 8 > $length
            ) {
                Errors::add(Errors::CATALOG_PARSE, 'MO catalogue tables are out of bounds.');

                return null;
            }

            $catalog = new self(Locale::normalize($locale), $domain);

            for ($index = 0; $index < $count; ++$index) {
                $origMeta = unpack($endian . 'len/' . $endian . 'off', substr($binary, $origOffset + $index * 8, 8));
                $transMeta = unpack($endian . 'len/' . $endian . 'off', substr($binary, $transOffset + $index * 8, 8));

                if ($origMeta === false || $transMeta === false) {
                    continue;
                }

                $origLen = (int) $origMeta['len'];
                $origOff = (int) $origMeta['off'];
                $transLen = (int) $transMeta['len'];
                $transOff = (int) $transMeta['off'];

                if ($origOff < 0 || $origOff + $origLen > $length
                    || $transOff < 0 || $transOff + $transLen > $length
                ) {
                    continue;
                }

                $original = substr($binary, $origOff, $origLen);
                $translation = substr($binary, $transOff, $transLen);

                if ($original === '') {
                    // Metadata entry: headers (and possibly a NUL-separated
                    // comment block we do not need).
                    $catalog->storeHeaders($translation);
                    continue;
                }

                $context = '';

                if (str_contains($original, self::CONTEXT_SEPARATOR)) {
                    [$context, $original] = explode(self::CONTEXT_SEPARATOR, $original, 2);
                }

                $idParts = explode("\0", $original);
                $id = (string) $idParts[0];
                $idPlural = (string) ($idParts[1] ?? '');
                $forms = [];

                foreach (explode("\0", $translation) as $position => $form) {
                    $forms[$position] = $form;
                }

                $catalog->store($context, $id, $idPlural, $forms, false);
            }

            $catalog->finalizeHeaders();

            return $catalog;
        }

        // -------------------------------------------------------------- storage

        /**
         * @param array<int, string> $forms
         */
        private function store(string $context, string $id, string $idPlural, array $forms, bool $fuzzy): void
        {
            if ($id === '') {
                $joined = '';

                foreach ($forms as $form) {
                    $joined .= $form;
                }

                if ($joined !== '') {
                    $this->storeHeaders($joined);
                }

                return;
            }

            ksort($forms);

            $this->messages[self::key($context, $id)] = [
                'single' => $forms[0] ?? null,
                'plurals' => array_values(array_map('strval', $forms)),
                'fuzzy' => $fuzzy,
            ];

            if ($idPlural !== '') {
                $this->messages[self::key($context, $idPlural)] ??= $this->messages[self::key($context, $id)];
            }
        }

        private function storeHeaders(string $raw): void
        {
            foreach (preg_split('~\r\n|\r|\n~', $raw) ?: [] as $line) {
                if (preg_match('~^([A-Za-z][A-Za-z0-9_-]*)\s*:\s*(.*)$~', trim($line), $m) === 1) {
                    $this->headers[strtolower($m[1])] = trim($m[2]);
                }
            }

            $charsetHeader = $this->headers['content-type'] ?? '';

            if (preg_match('~charset\s*=\s*([A-Za-z0-9_-]+)~i', $charsetHeader, $m) === 1) {
                $this->charset = strtolower($m[1]);
            }
        }

        /**
         * Converts non-UTF-8 payloads once, right after parsing.
         */
        private function finalizeHeaders(): void
        {
            $this->pluralForms = PluralForms::parse($this->headers['plural-forms'] ?? null, $this->locale);

            if (in_array($this->charset, ['utf-8', 'utf8', 'ascii', 'us-ascii'], true)) {
                return;
            }

            $charset = $this->charset;

            $convert = static function (string $text) use ($charset): string {
                if (function_exists('iconv')) {
                    $converted = @iconv($charset, 'UTF-8//TRANSLIT', $text);

                    if ($converted !== false) {
                        return $converted;
                    }
                }

                if (function_exists('mb_convert_encoding')) {
                    $converted = @mb_convert_encoding($text, 'UTF-8', $charset);

                    if (is_string($converted)) {
                        return $converted;
                    }
                }

                return $text;
            };

            foreach ($this->messages as $key => $entry) {
                $this->messages[$key] = [
                    'single' => $entry['single'] === null ? null : $convert($entry['single']),
                    'plurals' => array_map($convert, $entry['plurals']),
                    'fuzzy' => $entry['fuzzy'],
                ];
            }
        }

        private static function key(string $context, string $msgid): string
        {
            return $context === '' ? $msgid : $context . self::CONTEXT_SEPARATOR . $msgid;
        }

        // --------------------------------------------------------------- lookup

        /**
         * Singular translation or null (fuzzy entries are ignored).
         */
        public function get(string $msgid, string $context = ''): ?string
        {
            $entry = $this->messages[self::key($context, $msgid)] ?? null;

            if ($entry === null || $entry['fuzzy'] || $entry['single'] === null || $entry['single'] === '') {
                return null;
            }

            return $entry['single'];
        }

        /**
         * Plural translation form or null.
         */
        public function getPlural(string $msgid, int $formIndex, string $context = ''): ?string
        {
            $entry = $this->messages[self::key($context, $msgid)] ?? null;

            if ($entry === null || $entry['fuzzy'] || $entry['plurals'] === []) {
                return null;
            }

            $form = $entry['plurals'][$formIndex] ?? null;

            return is_string($form) && $form !== '' ? $form : null;
        }

        public function has(string $msgid, string $context = ''): bool
        {
            return $this->get($msgid, $context) !== null;
        }

        public function pluralForms(): PluralForms
        {
            return $this->pluralForms ??= PluralForms::parse(null, $this->locale);
        }

        /**
         * @return array<string, string>
         */
        public function headers(): array
        {
            return $this->headers;
        }

        public function count(): int
        {
            return count($this->messages);
        }
    }
}
namespace HtmlPhp\I18n {

    use HtmlPhp\Config;
    use HtmlPhp\Errors;
    use HtmlPhp\Hooks;
    use HtmlPhp\Support\Str;

    /**
     * The translation engine behind html_t()/html_n()/html_x()/html_nx() and
     * the legacy @{KEY} / Translate.KEY syntax.
     *
     * Lookup pipeline for a message id:
     *   1. WordPress delegation (when HTML_I18N_WP is on or auto-detected
     *      and __()/_n()/_x()/_nx() exist) — a hit is any result that is not
     *      the untranslated msgid;
     *   2. own catalogues: current locale, then every locale of the fallback
     *      chain (HTML_I18N_LOCALE → HTML_I18N_FALLBACK);
     *   3. the msgid itself (never an empty string, never an exception).
     *
     * The result passes through sprintf-style (%1$s, %d) or named ({key})
     * interpolation and the 'htmlphp_translate' filter hook.
     */
    final class Translator
    {
        /** @var array<string, array<string, Catalog>> domain => locale => catalogue */
        private static array $catalogs = [];

        /** @var array<string, true> "locale|path|domain" load guard */
        private static array $loaded = [];

        private function __construct()
        {
        }

        /**
         * Drops every loaded catalogue (tests, long-running workers).
         */
        public static function reset(): void
        {
            self::$catalogs = [];
            self::$loaded = [];
        }

        // ------------------------------------------------------------- catalogues

        /**
         * Loads a .po/.mo file (or a directory searched for
         * "{domain}-{locale}.mo|po" and "{locale}/LC_MESSAGES/{domain}.mo|po")
         * into the given domain.
         */
        public static function load(string $locale, string $path, string $domain = 'messages'): bool
        {
            $locale = Locale::normalize($locale);
            $candidates = self::catalogueCandidates($locale, $path, $domain);

            foreach ($candidates as $candidate) {
                $key = $locale . '|' . $candidate . '|' . $domain;

                if (isset(self::$loaded[$key])) {
                    return true;
                }

                if (!is_file($candidate)) {
                    continue;
                }

                $catalog = Catalog::load($candidate, $locale, $domain);

                if ($catalog !== null) {
                    self::$catalogs[$domain][$locale] = $catalog;
                    self::$loaded[$key] = true;

                    return true;
                }
            }

            return false;
        }

        /**
         * @return list<string>
         */
        private static function catalogueCandidates(string $locale, string $path, string $domain): array
        {
            if ($path !== '' && is_file($path)) {
                return [$path];
            }

            if ($path === '' || !is_dir($path)) {
                return [];
            }

            $root = rtrim($path, "/\\");

            return [
                $root . \DIRECTORY_SEPARATOR . $domain . '-' . $locale . '.mo',
                $root . \DIRECTORY_SEPARATOR . $domain . '-' . $locale . '.po',
                $root . \DIRECTORY_SEPARATOR . $locale . '.mo',
                $root . \DIRECTORY_SEPARATOR . $locale . '.po',
                $root . \DIRECTORY_SEPARATOR . $locale . \DIRECTORY_SEPARATOR . 'LC_MESSAGES'
                    . \DIRECTORY_SEPARATOR . $domain . '.mo',
                $root . \DIRECTORY_SEPARATOR . $locale . \DIRECTORY_SEPARATOR . 'LC_MESSAGES'
                    . \DIRECTORY_SEPARATOR . $domain . '.po',
            ];
        }

        public static function hasCatalogue(string $locale, string $domain = 'messages'): bool
        {
            return isset(self::$catalogs[$domain][Locale::normalize($locale)]);
        }

        /**
         * Diagnostics: loaded catalogues and their message counts.
         *
         * @return array<string, array<string, int>>
         */
        public static function stats(): array
        {
            $stats = [];

            foreach (self::$catalogs as $domain => $locales) {
                foreach ($locales as $locale => $catalog) {
                    $stats[$domain][$locale] = $catalog->count();
                }
            }

            return $stats;
        }

        // ---------------------------------------------------------------- locale

        public static function locale(): string
        {
            $locale = Config::text('HTML_I18N_LOCALE', Locale::DEFAULT_LOCALE);

            if ($locale === '' && Config::flag('HTML_I18N_DETECT')) {
                $locale = Locale::negotiate(self::availableLocales(), null, Locale::DEFAULT_LOCALE);
                Config::set('HTML_I18N_LOCALE', $locale);
            }

            return Locale::normalize($locale !== '' ? $locale : Locale::DEFAULT_LOCALE);
        }

        public static function setLocale(string $locale): void
        {
            Config::set('HTML_I18N_LOCALE', Locale::normalize($locale));
        }

        /**
         * Every locale that has at least one loaded catalogue.
         *
         * @return list<string>
         */
        public static function availableLocales(): array
        {
            $locales = [];

            foreach (self::$catalogs as $byLocale) {
                foreach (array_keys($byLocale) as $locale) {
                    $locales[$locale] = true;
                }
            }

            return array_keys($locales);
        }

        /**
         * Current locale followed by the configured fallback chain.
         *
         * @return list<string>
         */
        public static function fallbackChain(): array
        {
            $chain = [self::locale()];

            foreach (Config::list_('HTML_I18N_FALLBACK', []) as $locale) {
                $normalized = Locale::normalize(Str::stringify($locale));

                if (!in_array($normalized, $chain, true)) {
                    $chain[] = $normalized;
                }
            }

            return $chain;
        }

        // ------------------------------------------------------------ translation

        /**
         * Translates a message id.
         *
         * @param array<array-key, mixed> $args
         */
        public static function t(string $msgid, array $args = [], ?string $domain = null): string
        {
            return self::translate($msgid, null, 0, '', $args, $domain);
        }

        /**
         * Translates with plural selection.
         *
         * @param array<array-key, mixed> $args
         */
        public static function n(string $singular, string $plural, int $count, array $args = [], ?string $domain = null): string
        {
            return self::translate($singular, $plural, $count, '', $args, $domain);
        }

        /**
         * Translates with a message context (msgctxt).
         *
         * @param array<array-key, mixed> $args
         */
        public static function x(string $msgid, string $context, array $args = [], ?string $domain = null): string
        {
            return self::translate($msgid, null, 0, $context, $args, $domain);
        }

        /**
         * Translates plural + context (msgctxt + msgid_plural).
         *
         * @param array<array-key, mixed> $args
         */
        public static function nx(
            string $singular,
            string $plural,
            int $count,
            string $context,
            array $args = [],
            ?string $domain = null
        ): string {
            return self::translate($singular, $plural, $count, $context, $args, $domain);
        }

        /**
         * @param string|null $plural      plural source form (null = singular message)
         * @param array<array-key, mixed> $args
         */
        private static function translate(
            string $msgid,
            ?string $plural,
            int $count,
            string $context,
            array $args,
            ?string $domain
        ): string {
            $domain ??= Config::text('HTML_I18N_DOMAIN', 'messages');

            if ($domain === '') {
                $domain = 'messages';
            }

            $result = null;

            // 1. WordPress delegation.
            if (self::wordpressMode()) {
                $result = self::viaWordpress($msgid, $plural, $count, $context, $domain);
            }

            // 2. Own catalogues along the fallback chain.
            if ($result === null) {
                foreach (self::fallbackChain() as $locale) {
                    $catalog = self::$catalogs[$domain][$locale] ?? null;

                    if ($catalog === null) {
                        continue;
                    }

                    if ($plural === null) {
                        $result = $catalog->get($msgid, $context);
                    } else {
                        $index = $catalog->pluralForms()->select($count);
                        $result = $catalog->getPlural($msgid, $index, $context);
                        $result ??= $catalog->getPlural($plural, $index, $context);
                        $result ??= $catalog->get($msgid, $context);
                    }

                    if ($result !== null) {
                        break;
                    }
                }
            }

            // 3. Source fallback.
            if ($result === null) {
                $result = $plural !== null ? ($count === 1 ? $msgid : $plural) : $msgid;

                if (Config::flag('HTML_DEBUG')) {
                    Errors::add(
                        Errors::CATALOG_PARSE,
                        'No translation found.',
                        ['msgid' => $msgid, 'domain' => $domain, 'locale' => self::locale()]
                    );
                }
            }

            $result = self::interpolate($result, $args);

            $filtered = Hooks::applyFilters('htmlphp_translate', $result, $msgid, $domain, self::locale());

            return is_string($filtered) ? $filtered : $result;
        }

        /**
         * True when WordPress i18n functions should be consulted first.
         *
         * HTML_I18N_WP: null (default) = auto-detect WordPress, true = force,
         * false = never.
         */
        public static function wordpressMode(): bool
        {
            $mode = $GLOBALS['HTML_I18N_WP'] ?? null;

            if ($mode === true || $mode === false) {
                return $mode;
            }

            return \defined('ABSPATH') && function_exists('__') && function_exists('determine_locale');
        }

        private static function viaWordpress(
            string $msgid,
            ?string $plural,
            int $count,
            string $context,
            string $domain
        ): ?string {
            $result = null;

            try {
                if ($plural !== null && function_exists('_nx') && function_exists('_n')) {
                    $result = $context !== ''
                        ? \_nx($msgid, $plural, $count, $context, $domain)
                        : \_n($msgid, $plural, $count, $domain);
                } elseif ($context !== '' && function_exists('_x')) {
                    $result = \_x($msgid, $context, $domain);
                } elseif (function_exists('__')) {
                    $result = \__($msgid, $domain);
                }
            } catch (\Throwable) {
                return null;
            }

            if (!is_string($result) || $result === '') {
                return null;
            }

            // A miss is signalled by gettext returning the source string.
            $source = $plural !== null ? ($count === 1 ? $msgid : $plural) : $msgid;

            return $result === $source ? null : $result;
        }

        /**
         * Applies named ({key}) and sprintf-style (%1$s) interpolation.
         *
         * @param array<array-key, mixed> $args
         */
        public static function interpolate(string $text, array $args): string
        {
            if ($args === []) {
                return $text;
            }

            $named = [];

            foreach ($args as $key => $value) {
                if (is_string($key) || is_int($key)) {
                    $named['{' . $key . '}'] = Str::stringify($value);
                }
            }

            if ($named !== []) {
                $text = strtr($text, $named);
            }

            $isList = array_is_list($args);

            if ($isList && $args !== [] && str_contains($text, '%')) {
                try {
                    return vsprintf($text, $args);
                } catch (\ValueError) {
                    return $text;
                }
            }

            return $text;
        }
    }
}
namespace HtmlPhp\Template {

    use HtmlPhp\Config;
    use HtmlPhp\Errors;
    use HtmlPhp\Escaper;
    use HtmlPhp\Hooks;
    use HtmlPhp\I18n\Translator;
    use HtmlPhp\Support\Str;

    /**
     * Registry of variable filters usable in templates as %{NAME|filter:arg}.
     *
     * Built-ins: escape, attr, url, json, upper, lower, ucfirst, words,
     * number, round, cleanround, int, float, date, truncate, nl2br,
     * striptags, slug, default, raw, pad, repeat, translate.
     *
     * Custom filters are registered with html_var_filter() (or
     * Filters::add()); a filter receives the string value plus its argument
     * list and returns a string.
     */
    final class Filters
    {
        /** @var array<string, callable(string, list<string>): string> */
        private static array $custom = [];

        private function __construct()
        {
        }

        public static function add(string $name, callable $callback): void
        {
            $name = strtolower(trim($name));

            if ($name !== '' && preg_match('~^[a-z0-9_]+$~', $name) === 1) {
                self::$custom[$name] = $callback;
            }
        }

        public static function has(string $name): bool
        {
            $name = strtolower($name);

            return isset(self::$custom[$name]) || in_array($name, [
                'escape', 'attr', 'url', 'json', 'upper', 'lower', 'ucfirst', 'words',
                'number', 'round', 'cleanround', 'int', 'float', 'date', 'truncate',
                'nl2br', 'striptags', 'slug', 'default', 'raw', 'pad', 'repeat',
                'translate',
            ], true);
        }

        public static function reset(): void
        {
            self::$custom = [];
        }

        /**
         * Applies one filter with its arguments.
         *
         * @param list<string> $args
         */
        public static function apply(string $name, string $value, array $args = []): string
        {
            $name = strtolower(trim($name));

            Hooks::doAction('htmlphp_filter_apply', $name, $args);

            if (isset(self::$custom[$name])) {
                return Str::stringify((self::$custom[$name])($value, $args));
            }

            return match ($name) {
                'escape', 'esc', 'e' => Escaper::html($value),
                'attr' => Escaper::attr($value),
                'url' => Escaper::url($value),
                'json' => Escaper::json($value),
                'upper' => function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value),
                'lower' => function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value),
                'ucfirst' => function_exists('mb_ucfirst')
                    ? mb_ucfirst($value, 'UTF-8')
                    : (function_exists('mb_strtoupper') && $value !== ''
                        ? mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8')
                        : ucfirst($value)),
                'words' => Str::stringify(str_word_count($value)),
                'number' => self::number($value, $args),
                'round' => Str::round(Str::numericValue($value), $args[0] ?? 0),
                'cleanround' => Str::cleanRound(Str::numericValue($value), $args[0] ?? 0),
                'int' => (string) (int) round(Str::numericValue($value)),
                'float' => Str::stringify(Str::numericValue($value)),
                'date' => self::date($value, $args),
                'truncate' => Str::truncate($value, (int) ($args[0] ?? 80), $args[1] ?? '…'),
                'nl2br' => nl2br(Escaper::html($value)),
                'striptags' => strip_tags($value),
                'slug' => Str::slugify($value, $args[0] ?? '-'),
                'default' => $value !== '' ? $value : (string) ($args[0] ?? ''),
                'raw' => $value,
                'pad' => str_pad($value, (int) ($args[0] ?? 0), $args[1] ?? ' ', (int) ($args[2] ?? 0) === 1 ? \STR_PAD_LEFT : \STR_PAD_RIGHT),
                'repeat' => str_repeat($value, max(0, min(1000, (int) ($args[0] ?? 2)))),
                'translate' => Translator::t($value),
                default => self::unknown($name, $value),
            };
        }

        private static function unknown(string $name, string $value): string
        {
            Errors::add(Errors::FILTER_UNKNOWN, 'Unknown template filter.', ['filter' => $name]);

            return $value;
        }

        /**
         * number_format with locale-independent dot/comma control:
         *   %{V|number}      → 1,234.57 (2 decimals, en-US style)
         *   %{V|number:3}    → 3 decimals
         *   %{V|number:2:,:.}→ decimal ",", thousands "."
         *
         * @param list<string> $args
         */
        private static function number(string $value, array $args): string
        {
            $decimals = (int) ($args[0] ?? 2);
            $decimalPoint = $args[1] ?? '.';
            $thousands = $args[2] ?? ',';

            $formatted = number_format(Str::numericValue($value), max(0, min(20, $decimals)), $decimalPoint, $thousands);

            return preg_match('/^-0(?:\.0+)?$/', $formatted) === 1
                ? '0' . ($decimals > 0 ? $decimalPoint . str_repeat('0', $decimals) : '')
                : $formatted;
        }

        /**
         * Formats a UNIX timestamp or parseable date/time string.
         *
         * @param list<string> $args
         */
        private static function date(string $value, array $args): string
        {
            $format = $args[0] ?? 'Y-m-d';
            $timestamp = Str::isNumericString($value) ? (int) Str::numericValue($value) : strtotime($value);

            if ($timestamp === false) {
                return $value;
            }

            return date($format, $timestamp);
        }
    }

    /**
     * Variable resolution chain shared by the legacy update_template()
     * callback and the modern scoped renderer.
     *
     * Resolution order for a name:
     *   1. scoped variables of the current render (html_render $vars / loop row);
     *   2. "Translate.KEY"  → translation catalogue → $GLOBALS['Translate.KEY'] → KEY;
     *   3. $GLOBALS[name] when set and not null (legacy behaviour);
     *   4. the configured missing-variable policy.
     */
    final class Resolver
    {
        /** @var array<string, mixed> */
        private array $scope;
        private bool $useGlobals;

        /**
         * @param array<string, mixed> $scope
         */
        public function __construct(array $scope = [], bool $useGlobals = true)
        {
            $this->scope = $scope;
            $this->useGlobals = $useGlobals;
        }

        /**
         * Resolves a bare name (no filter chain, no numeric directive).
         */
        public function value(string $name): ?string
        {
            $name = trim($name);

            if ($name === '' || str_contains($name, "\0")) {
                return null;
            }

            // 1. Scoped variables (dot notation walks nested arrays/objects).
            if ($this->resolveScope($name, $found)) {
                return $found === null ? '' : Str::stringify($found);
            }

            // 2. Translation lookups.
            if (str_starts_with($name, 'Translate.')) {
                $key = substr($name, strlen('Translate.'));
                $translated = Translator::t($key);

                if ($translated !== $key) {
                    return $translated;
                }

                if ($this->useGlobals && array_key_exists($name, $GLOBALS) && $GLOBALS[$name] !== null) {
                    return Str::stringify($GLOBALS[$name]);
                }

                return $key;
            }

            // 3. Legacy $GLOBALS lookup.
            if ($this->useGlobals && array_key_exists($name, $GLOBALS) && $GLOBALS[$name] !== null) {
                return Str::stringify($GLOBALS[$name]);
            }

            // 4. Missing.
            return null;
        }

        /**
         * Resolves a name with the missing-variable policy applied — the
         * string every substitution form finally emits.
         */
        public function resolve(string $name): string
        {
            $value = $this->value($name);

            if ($value !== null) {
                return $value;
            }

            $policy = Config::flag('HTML_DEBUG') ? 'placeholder' : Config::text('HTML_MISSING_VAR', '');

            Hooks::doAction('htmlphp_missing_var', $name, $policy);
            Errors::add(Errors::MISSING_VAR, 'Template variable is not defined.', ['name' => $name]);

            return match ($policy) {
                'keep' => '%{' . $name . '}',
                'placeholder' => '[missing:' . $name . ']',
                'exception' => throw TemplateExceptionMissing::create($name),
                default => '',
            };
        }

        /**
         * Dot-notation scope lookup: "user.name" walks $scope['user']['name']
         * (arrays, ArrayAccess and public object properties).
         */
        private function resolveScope(string $name, mixed &$found): bool
        {
            if (array_key_exists($name, $this->scope)) {
                $found = $this->scope[$name];

                return true;
            }

            if (!str_contains($name, '.')) {
                return false;
            }

            $segments = explode('.', $name);
            $current = $this->scope;

            foreach ($segments as $segment) {
                if (is_array($current) && array_key_exists($segment, $current)) {
                    $current = $current[$segment];
                    continue;
                }

                if ($current instanceof \ArrayAccess && $current->offsetExists($segment)) {
                    $current = $current[$segment];
                    continue;
                }

                if (is_object($current) && isset($current->{$segment})) {
                    $current = $current->{$segment};
                    continue;
                }

                return false;
            }

            $found = $current;

            return true;
        }
    }

    /**
     * Raised by the resolver only under the "exception" missing-var policy.
     */
    final class TemplateExceptionMissing extends \HtmlPhp\TemplateException
    {
        public static function create(string $name): self
        {
            return self::withContext('Missing template variable: ' . $name, ['name' => $name]);
        }
    }
}
namespace HtmlPhp\Template {

    use HtmlPhp\Config;
    use HtmlPhp\Errors;
    use HtmlPhp\Escaper;
    use HtmlPhp\Hooks;
    use HtmlPhp\Io\AtomicWriter;
    use HtmlPhp\I18n\Translator;
    use HtmlPhp\Ssi\Context;
    use HtmlPhp\Ssi\Processor;
    use HtmlPhp\Support\Pcre;
    use HtmlPhp\Support\Str;

    /**
     * Variable substitution engine (%{NAME}, #{NAME}, @[email], %{KEY.Round:N}
     * and friends) with 3.0 filter chains and optional auto-escaping.
     *
     * The resolver callable receives a name and returns the final string
     * (missing-variable policy already applied by the caller's resolver).
     */
    final class Substitution
    {
        /** Per exec-symbol compiled numeric-directive patterns. */
        private static array $numericPatterns = [];

        private function __construct()
        {
        }

        /**
         * Applies every substitution form to $html (one pass).
         *
         * @param callable(string): string $resolver
         */
        public static function process(
            string $html,
            callable $resolver,
            string $execSymbol = '%',
            bool $autoEscape = false
        ): string {
            if ($execSymbol === '') {
                $execSymbol = '%';
            }

            $quoted = preg_quote($execSymbol, '~');

            // 1. %{%{NAME}} / @{%{NAME}} — resolve the inner name and keep one
            //    literal brace level (2.0.1 behaviour).
            $html = Pcre::replaceCallback(
                '~([%@])\{\%\{([^{}]*?)\}\}~',
                static fn(array $matches): string => $matches[1] . '{' . $resolver($matches[2]) . '}',
                $html
            );

            // 2. @{KEY} — translation lookup with the key itself as fallback.
            $html = Pcre::replaceCallback(
                '~@\{([^{}]*?)\}~',
                static function (array $matches) use ($resolver, $autoEscape): string {
                    [$name, $filters] = self::splitFilters($matches[1]);
                    $translated = $resolver('Translate.' . $name);
                    $value = $translated !== '' ? $translated : $name;

                    return self::finish($value, $filters, $autoEscape);
                },
                $html
            );

            // 3. Numeric directives (html4.pl): Round / Clean Round / Div /
            //    Round Div / Clean Round Div — with optional filter chain.
            $filterGroups = [
                'clean_round_div' => 4,
                'clean_round' => 3,
                'round_div' => 4,
                'round' => 3,
                'div' => 3,
            ];

            foreach (self::numericPatterns($quoted) as $mode => $pattern) {
                $filterGroup = $filterGroups[$mode];
                $html = Pcre::replaceCallback(
                    $pattern,
                    static function (array $matches) use ($resolver, $mode, $autoEscape, $filterGroup): string {
                        $value = self::numericDirective($resolver, $mode, $matches);

                        return self::finish($value, self::parseFilterChain($matches[$filterGroup] ?? ''), $autoEscape);
                    },
                    $html
                );
            }

            // 4. #{NAME} — alternate brace form.
            $html = Pcre::replaceCallback(
                '~#\{([^{}]*?)\}~',
                static function (array $matches) use ($resolver, $autoEscape): string {
                    [$name, $filters] = self::splitFilters($matches[1]);

                    return self::finish($resolver($name), $filters, $autoEscape);
                },
                $html
            );

            // 5. %{NAME} — the primary form (exec symbol configurable).
            $html = Pcre::replaceCallback(
                '~' . $quoted . '\{([^{}]*?)\}~',
                static function (array $matches) use ($resolver, $quoted, $autoEscape): string {
                    [$name, $filters] = self::splitFilters($matches[1]);

                    // A numeric directive written with a filter chain
                    // ("%{Amount.Round:2|upper}") lands here after step 3.
                    $numeric = self::numericFromPayload($name, $quoted, $resolver);

                    if ($numeric !== null) {
                        return self::finish($numeric, $filters, $autoEscape);
                    }

                    return self::finish($resolver($name), $filters, $autoEscape);
                },
                $html
            );

            // 6. %[NAME] → %{NAME}, resolved by a later pass (2.0.1 behaviour).
            $html = Pcre::replaceCallback(
                '~' . $quoted . '\[([^\[\]]*?)\]~',
                static fn(array $matches): string => $execSymbol . '{' . $matches[1] . '}',
                $html
            );

            return $html;
        }

        /**
         * Splits "NAME|filter:arg|filter2" into [name, [[filter, [args]], …]].
         *
         * @return array{0: string, 1: list<array{0: string, 1: list<string>}>}
         */
        private static function splitFilters(string $payload): array
        {
            if (!str_contains($payload, '|')) {
                return [trim($payload), []];
            }

            $segments = explode('|', $payload);
            $name = trim((string) array_shift($segments));

            return [$name, self::parseSegments($segments)];
        }

        /**
         * @return list<array{0: string, 1: list<string>}>
         */
        private static function parseSegments(array $segments): array
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
         * Splits "name:arg1:'an:arg':arg3" into [name, arg1, an:arg, arg3].
         *
         * Single- or double-quoted arguments may contain the ":" separator
         * (needed for date formats such as 'H:i'); backslashes escape inside
         * quotes.
         *
         * @return list<string>
         */
        private static function splitArguments(string $segment): array
        {
            $parts = [];
            $current = '';
            $quote = null;
            $length = strlen($segment);

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

        /**
         * Parses the tail "|f1:a|f2" that numeric-directive patterns capture.
         *
         * @return list<array{0: string, 1: list<string>}>
         */
        private static function parseFilterChain(string $tail): array
        {
            if ($tail === '') {
                return [];
            }

            return self::parseSegments(explode('|', $tail));
        }

        /**
         * @param list<array{0: string, 1: list<string>}> $filters
         */
        private static function withFilters(string $value, array $filters): string
        {
            foreach ($filters as [$name, $args]) {
                $value = Filters::apply($name, $value, $args);
            }

            return $value;
        }

        /**
         * @param list<array{0: string, 1: list<string>}> $filters
         */
        private static function finish(string $value, array $filters, bool $autoEscape): string
        {
            $value = self::withFilters($value, $filters);

            if (!$autoEscape) {
                return $value;
            }

            foreach ($filters as [$name]) {
                if (in_array(strtolower($name), ['escape', 'esc', 'e', 'attr', 'url', 'json', 'nl2br', 'raw'], true)) {
                    return $value;
                }
            }

            return Escaper::html($value);
        }

        /**
         * Compiled numeric-directive patterns; group 4 is the filter tail.
         *
         * @return array<string, string>
         */
        private static function numericPatterns(string $quoted): array
        {
            return self::$numericPatterns[$quoted] ??= [
                'clean_round_div' => '~' . $quoted . '\{([^{}|]*?)\.\s*Clean\s+Round\s+Div\s+([+-]?[\d.,]+)\s*:\s*(\d+)\s*((?:\|[^{}]*)?)\}~i',
                'clean_round' => '~' . $quoted . '\{([^{}|]*?)\.\s*Clean\s+Round\s*:\s*(\d+)\s*((?:\|[^{}]*)?)\}~i',
                'round_div' => '~' . $quoted . '\{([^{}|]*?)\.\s*Round\s+Div\s+([+-]?[\d.,]+)\s*:\s*(\d+)\s*((?:\|[^{}]*)?)\}~i',
                'round' => '~' . $quoted . '\{([^{}|]*?)\.\s*Round\s*:\s*(\d+)\s*((?:\|[^{}]*)?)\}~i',
                'div' => '~' . $quoted . '\{([^{}|]*?)\.\s*Div\s+([+-]?[\d.,]+)\s*((?:\|[^{}]*)?)\}~i',
            ];
        }

        /**
         * Evaluates one numeric directive from its regex matches.
         *
         * @param callable(string): string $resolver
         * @param array<int, string> $matches
         */
        private static function numericDirective(callable $resolver, string $mode, array $matches): string
        {
            $value = Str::numericValue($resolver(trim($matches[1])));

            if ($mode === 'clean_round') {
                return Str::cleanRound($value, $matches[2]);
            }

            if ($mode === 'round') {
                return Str::round($value, $matches[2]);
            }

            $divisor = Str::numericValue($matches[2]);
            $result = (float) Str::divide($value, $divisor);

            return match ($mode) {
                'clean_round_div' => Str::cleanRound($result, $matches[3]),
                'round_div' => Str::round($result, $matches[3]),
                default => Str::stringify($result),
            };
        }

        /**
         * Handles "%{KEY.Round:2|…}" payloads that survived step 3 because a
         * filter chain made the strict pattern skip them.
         *
         * @param callable(string): string $resolver
         */
        private static function numericFromPayload(string $payload, string $quoted, callable $resolver): ?string
        {
            if (preg_match('~^(.*?)\.\s*(Clean\s+Round\s+Div|Clean\s+Round|Round\s+Div|Round|Div)\s*(.*)$~i', $payload, $m) !== 1) {
                return null;
            }

            $name = trim($m[1]);
            $directive = strtolower(preg_replace('~\s+~', ' ', $m[2]) ?? '');
            $rest = trim($m[3]);

            $value = Str::numericValue($resolver($name));

            return match ($directive) {
                'clean round div' => self::divFormat($value, $rest, true),
                'round div' => self::divFormat($value, $rest, false),
                'clean round' => Str::cleanRound($value, self::precisionArg($rest)),
                'round' => Str::round($value, self::precisionArg($rest)),
                'div' => Str::stringify((float) Str::divide($value, Str::numericValue($rest))),
                default => null,
            };
        }

        private static function precisionArg(string $rest): string
        {
            return preg_match('~^:?\s*(\d+)~', ltrim($rest, ': '), $m) === 1 ? $m[1] : '0';
        }

        private static function divFormat(float $value, string $rest, bool $clean): string
        {
            if (preg_match('~^([+-]?[\d.,]+)\s*:?\s*(\d+)?$~', $rest, $m) !== 1) {
                return Str::stringify((float) Str::divide($value, Str::numericValue($rest)));
            }

            $result = (float) Str::divide($value, Str::numericValue($m[1]));
            $precision = $m[2] ?? 0;

            return $clean ? Str::cleanRound($result, $precision) : Str::round($result, $precision);
        }
    }

    /**
     * <!--@Loop NAME--> / <!--@If NAME--> block processing with row scoping.
     *
     *   <!--@Loop ROWS-->
     *     <tr class="%{_first}"><td>%{_position}</td><td>%{name}</td></tr>
     *   <!--@EndLoop ROWS-->
     *
     *   <!--@If PREMIUM--> … <!--@Else--> … <!--@EndIf-->
     *   <!--@If !EMPTY_FLAG--> negated condition <!--@EndIf-->
     *
     * Rows may be arrays, ArrayAccess objects, plain objects or scalars.
     * Inside a loop the row fields shadow the outer scope; the magic names
     * _index, _position, _key, _first, _last, _count and _odd are always
     * available. Loop variables support dot notation ("user.items").
     */
    final class Loops
    {
        private const TOKEN_PATTERN =
            '~<!--@(Loop|EndLoop|If|Else|EndIf)(?:\s+(!?[A-Za-z_][A-Za-z0-9_.]*))?\s*-->~is';

        private function __construct()
        {
        }

        /**
         * Processes every loop/conditional block in $text; plain segments are
         * handed to $renderNode (which applies variable substitution).
         *
         * @param array<string, mixed> $scope
         * @param callable(string, array<string, mixed>): string $renderNode
         */
        public static function process(string $text, array $scope, callable $renderNode, int $depth = 0): string
        {
            if (!str_contains($text, '<!--@')) {
                return $renderNode($text, $scope);
            }

            $maxDepth = Config::limit('HTML_MAX_LOOP_DEPTH', 32, 128);

            if ($depth > $maxDepth) {
                Errors::add(Errors::LOOP_GUARD, 'Loop/conditional nesting is too deep.');

                return '';
            }

            $output = '';
            $offset = 0;

            while (preg_match(self::TOKEN_PATTERN, $text, $match, \PREG_OFFSET_CAPTURE, $offset) === 1) {
                $tokenStart = (int) $match[0][1];
                $tokenEnd = $tokenStart + strlen($match[0][0]);
                $keyword = strtolower((string) $match[1][0]);
                $name = trim((string) ($match[2][0] ?? ''));

                $output .= $renderNode(substr($text, $offset, $tokenStart - $offset), $scope);

                if (in_array($keyword, ['else', 'endif', 'endloop'], true)) {
                    // Stray closing token without an opener: skip it.
                    Errors::add(Errors::LOOP_GUARD, 'Unexpected block closing token.', ['token' => $keyword]);
                    $offset = $tokenEnd;
                    continue;
                }

                $close = self::findClosingToken($text, $tokenEnd, $keyword);

                if ($close === null) {
                    Errors::add(Errors::LOOP_GUARD, 'Block is never closed.', ['token' => $keyword, 'name' => $name]);
                    $offset = $tokenEnd;
                    continue;
                }

                [$ifPart, $elsePart, $after] = $close;

                $output .= $keyword === 'loop'
                    ? self::expandLoop($name, $ifPart, $scope, $renderNode, $depth)
                    : self::expandCondition($name, $ifPart, $elsePart ?? '', $scope, $renderNode, $depth);

                $offset = $after;
            }

            $output .= $renderNode(substr($text, $offset), $scope);

            return $output;
        }

        /**
         * Finds the matching closing token (nesting-aware).
         *
         * @return array{0: string, 1: ?string, 2: int}|null [if-part, else-part, offset-after]
         */
        private static function findClosingToken(string $text, int $from, string $keyword): ?array
        {
            $depth = 1;
            $innerStart = $from;
            $elseStart = null;
            $elseContentStart = null;
            $offset = $from;

            while (preg_match(self::TOKEN_PATTERN, $text, $match, \PREG_OFFSET_CAPTURE, $offset) === 1) {
                $tokenStart = (int) $match[0][1];
                $tokenEnd = $tokenStart + strlen($match[0][0]);
                $token = strtolower((string) $match[1][0]);

                if ($token === $keyword) {
                    ++$depth;
                } elseif ($token === 'end' . $keyword) {
                    --$depth;

                    if ($depth === 0) {
                        if ($elseStart !== null && $elseContentStart !== null) {
                            return [
                                substr($text, $innerStart, $elseStart - $innerStart),
                                substr($text, $elseContentStart, $tokenStart - $elseContentStart),
                                $tokenEnd,
                            ];
                        }

                        return [substr($text, $innerStart, $tokenStart - $innerStart), null, $tokenEnd];
                    }
                } elseif ($token === 'else' && $depth === 1 && $keyword === 'if' && $elseStart === null) {
                    $elseStart = $tokenStart;
                    $elseContentStart = $tokenEnd;
                }

                $offset = $tokenEnd;
            }

            return null;
        }

        /**
         * @param array<string, mixed> $scope
         * @param callable(string, array<string, mixed>): string $renderNode
         */
        private static function expandLoop(
            string $name,
            string $inner,
            array $scope,
            callable $renderNode,
            int $depth
        ): string {
            if ($name === '' || str_starts_with($name, '!')) {
                Errors::add(Errors::LOOP_GUARD, '<!--@Loop--> requires a variable name.');

                return '';
            }

            $rows = self::rawScopeGet($scope, $name);

            if ($rows instanceof \Traversable) {
                $rows = iterator_to_array($rows, false);
            }

            if (!is_array($rows)) {
                if ($rows !== null) {
                    Errors::add(Errors::LOOP_GUARD, 'Loop variable is not iterable.', ['name' => $name]);
                }

                return '';
            }

            $maxRows = Config::limit('HTML_LOOP_MAX', 10000, 1000000);

            if (count($rows) > $maxRows) {
                Errors::add(Errors::LOOP_GUARD, 'Loop row limit reached.', ['name' => $name, 'rows' => count($rows)]);
                $rows = array_slice($rows, 0, $maxRows);
            }

            $count = count($rows);
            $output = '';
            $index = 0;

            foreach ($rows as $key => $row) {
                $rowScope = $scope;

                if (is_array($row)) {
                    $rowScope = array_merge($rowScope, $row);
                } elseif ($row instanceof \ArrayAccess) {
                    foreach (array_keys((array) $row) as $field) {
                        if ($row->offsetExists($field)) {
                            $rowScope[(string) $field] = $row->offsetGet($field);
                        }
                    }

                    $rowScope['value'] = $row;
                } elseif (is_object($row)) {
                    $rowScope = array_merge($rowScope, get_object_vars($row));
                    $rowScope['value'] = $row;
                } else {
                    $rowScope['value'] = $row;
                }

                $rowScope['_index'] = $index;
                $rowScope['_position'] = $index + 1;
                $rowScope['_key'] = (string) $key;
                $rowScope['_first'] = $index === 0 ? 'first' : '';
                $rowScope['_last'] = $index === $count - 1 ? 'last' : '';
                $rowScope['_count'] = $count;
                $rowScope['_odd'] = $index % 2 === 1 ? 'odd' : 'even';

                $output .= self::process($inner, $rowScope, $renderNode, $depth + 1);
                ++$index;
            }

            return $output;
        }

        /**
         * @param array<string, mixed> $scope
         * @param callable(string, array<string, mixed>): string $renderNode
         */
        private static function expandCondition(
            string $name,
            string $inner,
            string $elseInner,
            array $scope,
            callable $renderNode,
            int $depth
        ): string {
            if ($name === '') {
                Errors::add(Errors::LOOP_GUARD, '<!--@If--> requires a variable name.');

                return self::process($inner, $scope, $renderNode, $depth + 1);
            }

            $negated = str_starts_with($name, '!');
            $variable = $negated ? substr($name, 1) : $name;

            $value = self::rawScopeGet($scope, $variable);

            if ($value === null) {
                // Fall back to a scope-only string lookup (no $GLOBALS in the
                // modern API — conditions see exactly what substitutions see).
                $value = (new Resolver($scope, false))->value($variable);
            }

            $truthy = is_array($value)
                ? $value !== []
                : ($value !== null && $value !== '' && $value !== false && $value !== 0 && $value !== '0');

            if ($negated) {
                $truthy = !$truthy;
            }

            return self::process($truthy ? $inner : $elseInner, $scope, $renderNode, $depth + 1);
        }

        /**
         * Raw scope lookup supporting dot notation without stringification.
         *
         * @param array<string, mixed> $scope
         */
        private static function rawScopeGet(array $scope, string $name): mixed
        {
            if (array_key_exists($name, $scope)) {
                return $scope[$name];
            }

            if (!str_contains($name, '.')) {
                return null;
            }

            $current = $scope;

            foreach (explode('.', $name) as $segment) {
                if (is_array($current) && array_key_exists($segment, $current)) {
                    $current = $current[$segment];
                    continue;
                }

                if ($current instanceof \ArrayAccess && $current->offsetExists($segment)) {
                    $current = $current[$segment];
                    continue;
                }

                if (is_object($current) && isset($current->{$segment})) {
                    $current = $current->{$segment};
                    continue;
                }

                return null;
            }

            return $current;
        }
    }
}
namespace HtmlPhp\Template {

    use HtmlPhp\Config;
    use HtmlPhp\Errors;
    use HtmlPhp\Io\AtomicWriter;
    use HtmlPhp\Support\Str;

    /**
     * File-backed render cache.
     *
     * Enabled when HTML_CACHE_DIR points to a writable directory and either
     * HTML_CACHE_TTL > 0 or an explicit ['cache' => true, 'ttl' => N] option
     * is passed to html_render(). Entries are JSON envelopes written
     * atomically; the key covers the library version, template body, sorted
     * variables, locale, escaping mode and document root so a configuration
     * change can never serve stale output.
     *
     * Note: pages that #include external partials should use short TTLs —
     * the cache does not watch included files.
     */
    final class Cache
    {
        public const EXTENSION = '.htmlphp';

        private function __construct()
        {
        }

        public static function directory(): string
        {
            $directory = Config::text('HTML_CACHE_DIR', '');

            if ($directory === '' || !is_dir($directory) || !is_writable($directory)) {
                return '';
            }

            return rtrim($directory, "/\\");
        }

        /**
         * @param array<string, mixed> $vars
         * @param array<string, mixed> $options
         */
        public static function key(string $template, array $vars, array $options): string
        {
            ksort($vars);

            $material = implode('|', [
                \HtmlPhp\Version::VERSION,
                \HtmlPhp\I18n\Translator::locale(),
                Config::flag('HTML_ESCAPE_VARS') ? '1' : '0',
                (string) ($options['escape'] ?? ''),
                (string) ($options['file'] ?? ''),
                (string) ($options['root'] ?? ''),
                hash('sha256', $template),
                hash('sha256', serialize(self::scalarView($vars))),
            ]);

            return hash('sha256', $material);
        }

        /**
         * Reduces variables to a serialisable, order-stable scalar view.
         *
         * @param array<array-key, mixed> $vars
         * @return array<array-key, mixed>
         */
        private static function scalarView(array $vars): array
        {
            $view = [];

            foreach ($vars as $key => $value) {
                if (is_array($value)) {
                    $view[$key] = self::scalarView($value);
                } elseif (is_object($value)) {
                    $view[$key] = $value instanceof \Stringable ? (string) $value : get_class($value);
                } elseif (is_scalar($value) || $value === null) {
                    $view[$key] = $value;
                } else {
                    $view[$key] = gettype($value);
                }
            }

            ksort($view);

            return $view;
        }

        public static function get(string $key, int $ttl): ?string
        {
            $directory = self::directory();

            if ($directory === '' || $ttl <= 0) {
                return null;
            }

            $path = $directory . \DIRECTORY_SEPARATOR . $key . self::EXTENSION;

            if (!is_file($path)) {
                return null;
            }

            $raw = @file_get_contents($path);

            if ($raw === false) {
                return null;
            }

            $envelope = json_decode($raw, true);

            if (!is_array($envelope) || !isset($envelope['expires'], $envelope['output'])) {
                @unlink($path);

                return null;
            }

            if ((int) $envelope['expires'] <= time()) {
                @unlink($path);

                return null;
            }

            return is_string($envelope['output']) ? $envelope['output'] : null;
        }

        public static function put(string $key, string $output, int $ttl): void
        {
            $directory = self::directory();

            if ($directory === '' || $ttl <= 0) {
                return;
            }

            try {
                AtomicWriter::write(
                    $directory . \DIRECTORY_SEPARATOR . $key . self::EXTENSION,
                    (string) json_encode(
                        ['expires' => time() + $ttl, 'output' => $output],
                        \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
                    )
                );
            } catch (\Throwable $error) {
                Errors::add(Errors::CACHE_IO, 'Render cache write failed: ' . $error->getMessage());
            }
        }

        /**
         * Removes every cache entry; returns the number of deleted files.
         */
        public static function clear(): int
        {
            $directory = self::directory();

            if ($directory === '') {
                return 0;
            }

            $deleted = 0;

            foreach ((array) glob($directory . \DIRECTORY_SEPARATOR . '*' . self::EXTENSION) as $path) {
                if (is_string($path) && is_file($path) && @unlink($path)) {
                    ++$deleted;
                }
            }

            return $deleted;
        }
    }
}
namespace HtmlPhp\Template {

    use HtmlPhp\Config;
    use HtmlPhp\Hooks;
    use HtmlPhp\Io\Files;
    use HtmlPhp\Ssi\Context;
    use HtmlPhp\Ssi\Processor;
    use HtmlPhp\Support\Str;
    use HtmlPhp\TemplateException;

    /**
     * The modern rendering pipeline used by html_render()/html_render_file():
     *
     *   [cache lookup] → SSI pass → loops & conditionals → variable
     *   substitution (+ filters, missing-var policy, optional auto-escape)
     *   → repeated up to HTML_MAX_TEMPLATE_PASSES times → 'htmlphp_render'
     *   hook → [cache store].
     *
     * Variables are scoped: $vars (and loop rows) shadow each other but never
     * touch $GLOBALS unless ['globals' => true] is requested.
     */
    final class Renderer
    {
        private function __construct()
        {
        }

        /**
         * Renders a template string.
         *
         * Recognised options:
         *   file    ?string  document path for relative SSI includes
         *   root    ?string  document-root override for SSI containment
         *   ssi     bool     run the SSI pass (default true)
         *   loops   bool     process <!--@Loop-->/<!--@If--> blocks (default true)
         *   globals bool     fall back to $GLOBALS lookups (default false)
         *   escape  ?bool    auto-escape substitutions (default HTML_ESCAPE_VARS)
         *   passes  ?int     pipeline repetitions (default HTML_MAX_TEMPLATE_PASSES)
         *   cache   bool     use the render cache (default false)
         *   ttl     ?int     cache TTL seconds (default HTML_CACHE_TTL)
         *
         * @param array<string, mixed> $vars
         * @param array<string, mixed> $options
         */
        public static function render(string $template, array $vars = [], array $options = []): string
        {
            $maximumBytes = Config::limit('HTML_MAX_TEMPLATE_BYTES', 16 * 1024 * 1024, 512 * 1024 * 1024);

            if (strlen($template) > $maximumBytes) {
                throw TemplateException::withContext(
                    'Template exceeds the configured size limit.',
                    ['bytes' => strlen($template), 'limit' => $maximumBytes]
                );
            }

            $cacheEnabled = (bool) ($options['cache'] ?? false);
            $ttl = (int) ($options['ttl'] ?? Config::limit('HTML_CACHE_TTL', 0, 86400 * 30, 0));
            $cacheKey = $cacheEnabled ? Cache::key($template, $vars, $options) : '';

            if ($cacheEnabled) {
                $cached = Cache::get($cacheKey, $ttl);

                if ($cached !== null) {
                    return $cached;
                }
            }

            $passes = isset($options['passes'])
                ? max(1, min(16, (int) $options['passes']))
                : Config::limit('HTML_MAX_TEMPLATE_PASSES', 2, 16);

            $text = $template;

            for ($pass = 0; $pass < $passes; ++$pass) {
                if (($options['ssi'] ?? true)) {
                    $context = new Context(
                        isset($options['file']) && is_string($options['file']) ? $options['file'] : null,
                        isset($options['root']) && is_string($options['root']) ? $options['root'] : null
                    );
                    $text = Processor::process($text, $context);
                }

                $text = self::renderBlocks($text, $vars, $options);
            }

            $filtered = Hooks::applyFilters('htmlphp_render', $text, (string) ($options['file'] ?? ''));
            $text = is_string($filtered) ? $filtered : $text;

            if ($cacheEnabled && $cacheKey !== '') {
                Cache::put($cacheKey, $text, $ttl);
            }

            return $text;
        }

        /**
         * Reads a template file (size-guarded) and renders it. The file path
         * becomes the default 'file' option so relative SSI includes resolve
         * against its directory.
         *
         * @param array<string, mixed> $vars
         * @param array<string, mixed> $options
         */
        public static function renderFile(string $file, array $vars = [], array $options = []): string
        {
            if ($file === '' || str_contains($file, "\0")) {
                throw new \InvalidArgumentException('The template file path is invalid.');
            }

            $maximumBytes = Config::limit('HTML_MAX_TEMPLATE_BYTES', 16 * 1024 * 1024, 512 * 1024 * 1024);
            $contents = Files::slurp($file, $maximumBytes);

            if ($contents === null) {
                throw TemplateException::withContext(
                    "Template file '{$file}' is not readable or exceeds the size limit."
                );
            }

            $options['file'] ??= $file;

            return self::render($contents, $vars, $options);
        }

        /**
         * Loops/conditionals stage; plain segments go through substitution.
         *
         * @param array<string, mixed> $vars
         * @param array<string, mixed> $options
         */
        private static function renderBlocks(string $text, array $vars, array $options): string
        {
            $autoEscape = isset($options['escape']) && is_bool($options['escape'])
                ? $options['escape']
                : Config::flag('HTML_ESCAPE_VARS');

            $useGlobals = (bool) ($options['globals'] ?? false);

            $renderNode = static function (string $segment, array $scope) use ($autoEscape, $useGlobals): string {
                if ($segment === '') {
                    return '';
                }

                $resolver = new Resolver($scope, $useGlobals);

                return Substitution::process($segment, $resolver->resolve(...), '%', $autoEscape);
            };

            if (($options['loops'] ?? true)) {
                return Loops::process($text, $vars, $renderNode);
            }

            return $renderNode($text, $vars);
        }
    }
}

namespace HtmlPhp\Legacy {

    use HtmlPhp\Config;
    use HtmlPhp\I18n\Translator;
    use HtmlPhp\Ssi\Context;
    use HtmlPhp\Ssi\Processor;
    use HtmlPhp\Support\Pcre;
    use HtmlPhp\Support\Str;
    use HtmlPhp\Template\Substitution;
    use HtmlPhp\TemplateException;

    /**
     * html4.pl-compatible block API: LoadTemplate()/LoadVar() named blocks,
     * Read_HTML() numbered blocks and Show_HTML() block rendering. Ported
     * from 2.0.1 with identical semantics.
     */
    final class Blocks
    {
        private function __construct()
        {
        }

        /**
         * Returns a block name captured by either a legacy or custom marker.
         *
         * @param array<int, string> $matches
         */
        public static function blockName(array $matches): string
        {
            if (isset($matches[2]) && $matches[2] !== '') {
                return trim($matches[2]);
            }

            return isset($matches[1]) ? trim($matches[1]) : '';
        }

        /**
         * Internal bounded implementation for legacy block loading.
         *
         * @param resource $handle
         */
        public static function loadVar($handle, string $startPattern, int $depth): string
        {
            if (!is_resource($handle)) {
                return '';
            }

            if ($depth >= Config::limit('HTML_MAX_BLOCK_DEPTH', 64, 1024)) {
                return '';
            }

            $endPattern = '~<!--@End\s+Block\s*(.*?)-->~i';
            $result = '';

            while (($line = fgets($handle)) !== false) {
                if (preg_match($endPattern, $line) === 1) {
                    break;
                }

                if (preg_match($startPattern, $line, $matches) === 1) {
                    $name = self::blockName($matches);

                    if ($name !== '' && Config::isGlobalAllowed($name)) {
                        $GLOBALS[$name] = self::loadVar($handle, $startPattern, $depth + 1);
                    } else {
                        self::loadVar($handle, $startPattern, $depth + 1);
                    }

                    continue;
                }

                $result .= $line;
            }

            return $result;
        }

        /**
         * Recursively loads a named legacy template block.
         *
         * @param resource $handle
         */
        public static function loadVarPublic($handle, string $startPattern): string
        {
            return self::loadVar($handle, $startPattern, 0);
        }

        /**
         * Loads a legacy template and publishes named blocks into $GLOBALS.
         */
        public static function loadTemplate(string $file): string
        {
            if ($file === '' || str_contains($file, "\0") || !is_file($file) || !is_readable($file)) {
                return 'Template Not Found';
            }

            $maximum = Config::limit('HTML_MAX_TEMPLATE_BYTES', 16 * 1024 * 1024, 512 * 1024 * 1024);
            $size = @filesize($file);

            if ($size !== false && $size > $maximum) {
                return 'Bad Template File';
            }

            $handle = fopen($file, 'rb');

            if ($handle === false) {
                return 'Bad Template File';
            }

            $html = '';
            $startPattern = '~<!--@Block\s*(.*?)-->~i';

            try {
                while (($line = fgets($handle)) !== false) {
                    if (preg_match($startPattern, $line, $matches) === 1) {
                        $name = self::blockName($matches);

                        if ($name !== '' && Config::isGlobalAllowed($name)) {
                            $GLOBALS[$name] = self::loadVarPublic($handle, $startPattern);
                        } elseif ($name !== '') {
                            self::loadVarPublic($handle, $startPattern);
                        }

                        continue;
                    }

                    $html .= $line;
                }
            } finally {
                fclose($handle);
            }

            return $html;
        }

        /**
         * Loads numeric html4.pl-style blocks into $GLOBALS['html'].
         */
        public static function readHtml(string $file, int $block = 1): string
        {
            if ($file === '' || str_contains($file, "\0") || !is_file($file) || !is_readable($file)) {
                throw new TemplateException("Template file '{$file}' is not readable.");
            }

            $maximum = Config::limit('HTML_MAX_TEMPLATE_BYTES', 16 * 1024 * 1024, 512 * 1024 * 1024);
            $size = @filesize($file);

            if ($size !== false && $size > $maximum) {
                throw new TemplateException("Template file '{$file}' exceeds the configured size limit.");
            }

            $contents = file_get_contents($file);

            if ($contents === false) {
                throw new TemplateException("Template file '{$file}' could not be read.");
            }

            if ($block < 0) {
                $block = 1;
            }

            if ($block === 1 || !isset($GLOBALS['html']) || !is_array($GLOBALS['html'])) {
                $GLOBALS['html'] = [];
            }

            $base = Pcre::replaceCallback(
                '~<!--@block\s+(\d+).*?-->(.*?)<!--@end.*?-->~is',
                static function (array $matches): string {
                    $GLOBALS['html'][(int) $matches[1]] = trim($matches[2]);

                    return '';
                },
                $contents
            );

            $residual = trim($base);

            if (!array_key_exists($block, $GLOBALS['html'])) {
                $GLOBALS['html'][$block] = $residual;
            }

            return Str::stringify($GLOBALS['html'][$block] ?? '');
        }

        /**
         * Renders an html4.pl-style numbered block.
         *
         * Accepts either one associative array or legacy key/value argument
         * pairs. Returns the rendered line for output=var*, otherwise echoes
         * (or writes to the supplied handle) and returns null.
         */
        public static function showHtml(mixed ...$arguments): ?string
        {
            if (count($arguments) === 1 && is_array($arguments[0])) {
                $public = $arguments[0];
            } else {
                $public = [];

                for ($index = 0, $count = count($arguments); $index + 1 < $count; $index += 2) {
                    $public[Str::stringify($arguments[$index])] = $arguments[$index + 1];
                }
            }

            $block = array_key_exists('html', $public) && $public['html'] !== ''
                ? (int) $public['html']
                : 1;
            $blocks = isset($GLOBALS['html']) && is_array($GLOBALS['html']) ? $GLOBALS['html'] : [];
            $source = Str::stringify($blocks[$block] ?? '');

            $parts = preg_split('~<!--@Split.*?-->~', $source, 2);

            if ($parts === false) {
                $parts = [$source];
            }

            if (empty($public['PARITY'])) {
                $GLOBALS['parity'] = empty($GLOBALS['parity']) ? 1 : 0;
            }

            $line = $parts[0] ?? '';

            if (isset($parts[1]) && $parts[1] !== '' && !empty($GLOBALS['parity'])) {
                $line = $parts[1];
            }

            if (strtolower(Str::stringify($public['SSI'] ?? '')) !== 'off') {
                $root = Str::stringify($public['www_path'] ?? '');
                $context = new Context(null, $root !== '' ? $root : null);
                $line = Processor::process($line, $context);
            }

            $resolver = static fn(string $name): string => Str::stringify($public[$name] ?? '');
            $symbol = Str::stringify($public['exec_symbol'] ?? '');
            $line = Substitution::process(
                $line,
                $resolver,
                $symbol !== '' ? $symbol : '%',
                Config::flag('HTML_ESCAPE_VARS')
            );
            $line = Pcre::replace('~\[!--@(.*?)--\]~s', '<!--@$1-->', $line);
            $line = Pcre::replace('~<\?html\s*if\s*\([^)]*\).*?\?>~is', '', $line);

            $mode = strtolower(Str::stringify($public['output'] ?? ''));

            if (str_starts_with($mode, 'var')) {
                return $line;
            }

            $handle = $public['handle'] ?? null;
            $usable = is_resource($handle) && get_resource_type($handle) === 'stream';
            $written = false;

            if (($mode === 'file' || $mode === 'comb') && $usable) {
                $written = fwrite($handle, $line) !== false;
            }

            if ($mode !== 'file' || !$written) {
                if (empty($GLOBALS['SILENT']) || ($mode === 'file' && !$written)) {
                    echo $line;
                }
            }

            return null;
        }
    }
}
namespace {

    use HtmlPhp\Config;
    use HtmlPhp\Errors;
    use HtmlPhp\Escaper;
    use HtmlPhp\Hooks;
    use HtmlPhp\Html\Cleaner;
    use HtmlPhp\I18n\Locale;
    use HtmlPhp\I18n\Translator;
    use HtmlPhp\Io\AtomicWriter;
    use HtmlPhp\Legacy\Blocks;
    use HtmlPhp\Ssi\Builtins;
    use HtmlPhp\Ssi\Context;
    use HtmlPhp\Ssi\Processor;
    use HtmlPhp\Support\Str;
    use HtmlPhp\Template\Cache;
    use HtmlPhp\Template\Filters;
    use HtmlPhp\Template\Renderer;
    use HtmlPhp\Template\Substitution;
    use HtmlPhp\Version;

    /**
     * Global names that templates are never allowed to read through SSI
     * directives or overwrite from a template. Kept as a public constant for
     * backward compatibility with 2.0.1; Config::isReserved() is the
     * authoritative runtime check.
     */
    const HTML_RESERVED_GLOBALS = [
        'GLOBALS' => true,
        '_SERVER' => true,
        '_GET' => true,
        '_POST' => true,
        '_FILES' => true,
        '_COOKIE' => true,
        '_SESSION' => true,
        '_REQUEST' => true,
        '_ENV' => true,
        // engine limits
        'HTML_MAX_SSI_DEPTH' => true,
        'HTML_MAX_SSI_TAGS' => true,
        'HTML_MAX_TEMPLATE_PASSES' => true,
        'HTML_MAX_BLOCK_DEPTH' => true,
        'HTML_MAX_PRECISION' => true,
        'HTML_MAX_TEMPLATE_BYTES' => true,
        'HTML_MAX_INCLUDE_BYTES' => true,
        'HTML_LOOP_MAX' => true,
        'HTML_MAX_LOOP_DEPTH' => true,
        // SSI behaviour
        'HTML_SSI_ALLOW_PARENT' => true,
        'HTML_SSI_ECHO_ESCAPE' => true,
        'HTML_SSI_ECHO_ALLOW' => true,
        'HTML_SSI_TIMEFMT' => true,
        'HTML_SSI_SIZEFMT' => true,
        'HTML_SSI_ERRMSG' => true,
        'HTML_SSI_EXEC' => true,
        'HTML_SSI_EXEC_CMD_ALLOW' => true,
        'HTML_SSI_EXEC_CGI_EXT' => true,
        'HTML_SSI_EXEC_CGI_MODE' => true,
        'HTML_SSI_EXEC_TIMEOUT' => true,
        'HTML_SSI_EXEC_MAX_BYTES' => true,
        // input / output
        'HTML_AUTO_SANITIZE_INPUT' => true,
        'HTML_ALLOWED_TAGS' => true,
        'HTML_ESCAPE_VARS' => true,
        'HTML_ESCAPER' => true,
        'HTML_MISSING_VAR' => true,
        'HTML_DEBUG' => true,
        'HTML_WWW_PATH' => true,
        // i18n
        'HTML_I18N_LOCALE' => true,
        'HTML_I18N_FALLBACK' => true,
        'HTML_I18N_DOMAIN' => true,
        'HTML_I18N_DETECT' => true,
        'HTML_I18N_WP' => true,
        // cache
        'HTML_CACHE_DIR' => true,
        'HTML_CACHE_TTL' => true,
        // internals
        'HTML_Prepare_Count' => true,
        'TAGS' => true,
        'html' => true,
        'parity' => true,
        'entities' => true,
    ];

    /**
     * Built-in Apache SSI variables exposed to <!--#echo var="…" --> and to
     * the $VAR expansion inside <!--#set … value="…" --> and #if expressions.
     * Kept as a public constant for backward compatibility; Builtins::NAMES
     * is the authoritative runtime list.
     */
    const HTML_SSI_BUILTIN_VARS = Builtins::NAMES;

    // =====================================================================
    // LEGACY PUBLIC API (byte-for-byte compatible with 2.0.1)
    // =====================================================================

    /**
     * Recursively loads a named legacy template block.
     *
     * @param resource $handle
     */
    function LoadVar($handle, string $startPattern): string
    {
        return Blocks::loadVarPublic($handle, $startPattern);
    }

    /**
     * Loads a legacy template and publishes named blocks into $GLOBALS.
     */
    function LoadTemplate(string $file): string
    {
        return Blocks::loadTemplate($file);
    }

    /**
     * Processes template variables using the supplied legacy callback.
     */
    function processHtml(string $html, callable $updateTemplate): string
    {
        $resolver = static fn(string $name): string => Str::stringify($updateTemplate($name));

        return Substitution::process($html, $resolver, '%', Config::flag('HTML_ESCAPE_VARS'));
    }

    /**
     * Main legacy HTML preprocessor: SSI pass + variable pass, evaluated up
     * to HTML_MAX_TEMPLATE_PASSES times so that escaped references resolve.
     */
    function HTML(string $html): string
    {
        $ownsCounter = empty($GLOBALS['HTML_Prepare_Count']);
        $pass = $ownsCounter ? 1 : (int) $GLOBALS['HTML_Prepare_Count'];
        $maximum = Config::limit('HTML_MAX_TEMPLATE_PASSES', 2, 16);

        $html = processSSI($html);
        $html = processHtml($html, 'update_template');

        if (!$ownsCounter || $pass >= $maximum) {
            return $html;
        }

        $GLOBALS['HTML_Prepare_Count'] = $pass + 1;

        try {
            return HTML($html);
        } finally {
            unset($GLOBALS['HTML_Prepare_Count']);
        }
    }

    /**
     * Resolves a legacy template variable or its translation fallback.
     *
     * Since 3.0 a "Translate.KEY" name consults the i18n catalogues (and
     * WordPress when delegation is active) before falling back to the
     * $GLOBALS entry and finally the key itself.
     */
    function update_template(string $varName): string
    {
        if ($varName !== '' && array_key_exists($varName, $GLOBALS) && $GLOBALS[$varName] !== null) {
            return Str::stringify($GLOBALS[$varName]);
        }

        if (str_starts_with($varName, 'Translate.')) {
            $key = substr($varName, strlen('Translate.'));
            $translated = Translator::t($key);

            return $translated !== '' ? $translated : $key;
        }

        return '';
    }

    /**
     * Sanitizes a nested input array for safe HTML text or attribute output.
     *
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    function sanitizeXSS(array $input): array
    {
        return Escaper::sanitizeArray($input);
    }

    /**
     * Returns sanitized request arrays without mutating PHP superglobals.
     *
     * @return array{
     *     get: array<array-key, mixed>,
     *     post: array<array-key, mixed>,
     *     cookie: array<array-key, mixed>,
     *     request: array<array-key, mixed>
     * }
     */
    function HTML_Sanitized_Input(): array
    {
        return [
            'get' => sanitizeXSS($_GET ?? []),
            'post' => sanitizeXSS($_POST ?? []),
            'cookie' => sanitizeXSS($_COOKIE ?? []),
            'request' => sanitizeXSS($_REQUEST ?? []),
        ];
    }

    /**
     * Processes SSI include, set, echo, config, conditional, exec, fsize,
     * flastmod and legacy variable directives.
     *
     * The optional $currentFile keeps the legacy call signature intact and
     * tells the processor which file the markup came from: relative includes
     * ("ssi/x.htm") are then resolved against its directory instead of the
     * document root.
     */
    function processSSI(string $html, ?string $currentFile = null): string
    {
        return Processor::process($html, new Context($currentFile));
    }

    /**
     * Loads numeric html4.pl-style blocks into $GLOBALS['html'].
     */
    function Read_HTML(string $file, int $block = 1): string
    {
        return Blocks::readHtml($file, $block);
    }

    /**
     * Renders an html4.pl-style block (array or legacy key/value pairs).
     */
    function Show_HTML(mixed ...$arguments): ?string
    {
        return Blocks::showHtml(...$arguments);
    }

    /**
     * Divides two values and returns zero for a zero divisor.
     */
    function Divide(mixed $a, mixed $b): float|int
    {
        return Str::divide($a, $b);
    }

    /**
     * Converts HTML tag names to lowercase without modifying attributes.
     */
    function lc_Tags(string $input): string
    {
        return Cleaner::lcTags($input);
    }

    /**
     * Normalizes HTML to text while optionally preserving BR tags.
     */
    function normalize(string $buffer, mixed $br = null, mixed $cleanEdges = false): string
    {
        return Cleaner::normalize($buffer, $br, $cleanEdges);
    }

    /**
     * Legacy public helper: allow-listed tag check against $GLOBALS['TAGS'].
     */
    function clean(string $closing, string $tag): string
    {
        return Cleaner::cleanWithGlobalTags($closing, $tag);
    }

    /**
     * Keeps only allow-listed HTML tags and removes their attributes.
     */
    function clean_html(string $html): string
    {
        return Cleaner::cleanHtml($html);
    }

    /**
     * Writes a file atomically in the destination directory.
     */
    function WriteFile(string $file, mixed $data): bool
    {
        return AtomicWriter::write($file, $data);
    }

    /**
     * Removes whitespace and non-word characters while preserving Unicode letters.
     */
    function TrimSpaces(mixed $string): string
    {
        return Cleaner::trimSpaces($string);
    }

    // =====================================================================
    // DEPRECATED INTERNAL COMPATIBILITY WRAPPERS (used by 2.0.1-era tests)
    // =====================================================================

    /** @deprecated use HtmlPhp\Support\Str::stringify() */
    function _htmlStringify(mixed $value): string
    {
        return Str::stringify($value);
    }

    /** @deprecated use HtmlPhp\Support\Str::numericValue() */
    function _htmlNumericValue(mixed $value): float
    {
        return Str::numericValue($value);
    }

    /** @deprecated use HtmlPhp\Support\Str::precision() */
    function _htmlPrecision(mixed $precision): int
    {
        return Str::precision($precision);
    }

    /** @deprecated use HtmlPhp\Support\Str::round() */
    function _htmlRound(float $value, mixed $precision): string
    {
        return Str::round($value, $precision);
    }

    /** @deprecated use HtmlPhp\Support\Str::cleanRound() */
    function _htmlCleanRound(float $value, mixed $precision): string
    {
        return Str::cleanRound($value, $precision);
    }

    /** @deprecated use HtmlPhp\Config::limit() */
    function _htmlPositiveLimit(string $name, int $default, int $maximum, int $minimum = 1): int
    {
        return Config::limit($name, $default, $maximum, $minimum);
    }

    /** @deprecated use HtmlPhp\Support\Str::isValidVariableName() */
    function _htmlValidVariableName(string $name): bool
    {
        return Str::isValidVariableName($name);
    }

    // =====================================================================
    // MODERN PUBLIC API (3.0)
    // =====================================================================

    /**
     * Library version banner, e.g. "3.0.0 (8.3 edition)".
     */
    function html_version(): string
    {
        return Version::banner();
    }

    /**
     * Reads (and optionally overrides) the effective configuration.
     *
     * @param array<string, mixed>|null $overrides
     * @return array<string, mixed>
     */
    function html_config(?array $overrides = null): array
    {
        return Config::apply($overrides);
    }

    /**
     * Renders a template string with scoped variables.
     *
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $options see Renderer::render()
     */
    function html_render(string $template, array $vars = [], array $options = []): string
    {
        return Renderer::render($template, $vars, $options);
    }

    /**
     * Reads and renders a template file with scoped variables.
     *
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $options
     */
    function html_render_file(string $file, array $vars = [], array $options = []): string
    {
        return Renderer::renderFile($file, $vars, $options);
    }

    /**
     * Modern alias of processSSI() with an explicit base-file argument.
     */
    function html_ssi(string $html, ?string $file = null): string
    {
        return Processor::process($html, new Context($file));
    }

    /**
     * The built-in SSI environment variables of the current request.
     *
     * @return array<string, string>
     */
    function html_ssi_variables(?string $file = null): array
    {
        return Builtins::map(new Context($file));
    }

    /**
     * Translates a message (html_t('Hello {name}', ['name' => 'World'])).
     *
     * @param array<array-key, mixed> $args
     */
    function html_t(string $msgid, array $args = [], ?string $domain = null): string
    {
        return Translator::t($msgid, $args, $domain);
    }

    /**
     * Translates a plural message.
     *
     * @param array<array-key, mixed> $args
     */
    function html_n(string $singular, string $plural, int $count, array $args = [], ?string $domain = null): string
    {
        return Translator::n($singular, $plural, $count, $args, $domain);
    }

    /**
     * Translates a message within a context (gettext msgctxt).
     *
     * @param array<array-key, mixed> $args
     */
    function html_x(string $msgid, string $context, array $args = [], ?string $domain = null): string
    {
        return Translator::x($msgid, $context, $args, $domain);
    }

    /**
     * Translates a plural message within a context.
     *
     * @param array<array-key, mixed> $args
     */
    function html_nx(
        string $singular,
        string $plural,
        int $count,
        string $context,
        array $args = [],
        ?string $domain = null
    ): string {
        return Translator::nx($singular, $plural, $count, $context, $args, $domain);
    }

    /**
     * Sets the active locale (normalised to ll_CC).
     */
    function html_set_locale(string $locale): void
    {
        Translator::setLocale($locale);
    }

    /**
     * Returns the active locale.
     */
    function html_get_locale(): string
    {
        return Translator::locale();
    }

    /**
     * Picks the best available locale for an Accept-Language header.
     *
     * @param list<string> $available
     */
    function html_detect_locale(array $available, ?string $acceptLanguage = null, ?string $default = null): string
    {
        return Locale::negotiate($available, $acceptLanguage, $default);
    }

    /**
     * Loads a .po/.mo catalogue (or a directory of them) into a domain.
     */
    function html_load_translations(string $locale, string $path, string $domain = 'messages'): bool
    {
        return Translator::load($locale, $path, $domain);
    }

    /**
     * Registers a filter hook (WordPress-style).
     */
    function html_add_filter(string $tag, callable $callback, int $priority = 10): void
    {
        Hooks::addFilter($tag, $callback, $priority);
    }

    /**
     * Applies a filter hook.
     */
    function html_apply_filters(string $tag, mixed $value, mixed ...$args): mixed
    {
        return Hooks::applyFilters($tag, $value, ...$args);
    }

    /**
     * Removes every callback of a filter hook priority.
     */
    function html_remove_filter(string $tag, int $priority = 10): void
    {
        Hooks::removeFilter($tag, $priority);
    }

    /**
     * Registers an action hook.
     */
    function html_add_action(string $tag, callable $callback, int $priority = 10): void
    {
        Hooks::addAction($tag, $callback, $priority);
    }

    /**
     * Fires an action hook.
     */
    function html_do_action(string $tag, mixed ...$args): void
    {
        Hooks::doAction($tag, ...$args);
    }

    /**
     * Registers a custom variable filter usable as %{NAME|name:args}.
     */
    function html_var_filter(string $name, callable $callback): void
    {
        Filters::add($name, $callback);
    }

    /**
     * HTML text escaping (honours the HTML_ESCAPER override).
     */
    function html_escape(string $value): string
    {
        return Escaper::html($value);
    }

    /**
     * HTML attribute escaping.
     */
    function html_escape_attr(string $value): string
    {
        return Escaper::attr($value);
    }

    /**
     * URL escaping.
     */
    function html_escape_url(string $value): string
    {
        return Escaper::url($value);
    }

    /**
     * URL-safe slug.
     */
    function html_slugify(string $text, string $separator = '-'): string
    {
        return Str::slugify($text, $separator);
    }

    /**
     * Word-boundary, multibyte-safe truncation.
     */
    function html_truncate(string $text, int $length, string $suffix = '…'): string
    {
        return Str::truncate($text, $length, $suffix);
    }

    /**
     * Non-fatal diagnostics collected during rendering.
     *
     * @return list<array{code: string, message: string, context: array<string, mixed>, where: string}>
     */
    function html_errors(string $code = ''): array
    {
        return Errors::byCode($code);
    }

    /**
     * Clears collected diagnostics.
     */
    function html_clear_errors(): void
    {
        Errors::clear();
    }

    /**
     * Purges the render cache; returns the number of deleted entries.
     */
    function html_cache_clear(): int
    {
        return Cache::clear();
    }

    /**
     * Resets all in-memory state (catalogues, hooks, filters, diagnostics).
     * Intended for test suites and long-running workers.
     */
    function html_reset_state(): void
    {
        Translator::reset();
        Hooks::removeAll();
        Filters::reset();
        Errors::clear();
    }

    // Optional request-time input sanitisation (opt-in via configuration;
    // superglobals are never mutated by default).
    if (!empty($GLOBALS['HTML_AUTO_SANITIZE_INPUT'])) {
        $_GET = sanitizeXSS($_GET ?? []);
        $_POST = sanitizeXSS($_POST ?? []);
        $_COOKIE = sanitizeXSS($_COOKIE ?? []);
        $_REQUEST = sanitizeXSS($_REQUEST ?? []);
    }
}
