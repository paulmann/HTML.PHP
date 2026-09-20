<?php
/**
 * VERSION: 2026-09-20.2
 *
 * Production template processing library aligned with html4.pl capabilities.
 *
 * Author:	Mikhail Deynekin
 * Site:	https://Deynekin.com
 * Email:	Mikhail@Deynekin.com
 *
 * Compatibility target: PHP 8.3+
 * Library version: 2.0.1
 *
 * Public legacy API preserved:
 * - LoadTemplate(), LoadVar(), processHtml(), HTML(), update_template(),
 *   sanitizeXSS(), processSSI()
 *
 * Additional html4.pl-compatible API:
 * - Read_HTML(), Show_HTML(), Divide(), lc_Tags(), normalize(), clean(),
 *   clean_html(), WriteFile(), TrimSpaces()
 *
 * CHANGE LOG:
 * 2026-09-20.2 - Four pre-existing defects carried over by the hardening pass:
 *   - normalize() no longer destroys its own <br> marker: the "\x01" sentinel
 *     byte is stripped from the input (instead of only the full marker literal)
 *     so the marker can only come from this function, and "\x01" is excluded
 *     from the control-character strip, so the restore step finds it again.
 *     normalize('<p>a<br>b</p>') is now 'a<br>b' instead of 'aHTML_BRb'.
 *   - Read_HTML() no longer overwrites a block that a "<!--@block N-->" marker
 *     already published with the residual text: the residual is only used as a
 *     fallback when the requested block is undefined. A marker-less template
 *     still returns its whole trimmed content (legacy behaviour).
 *   - WriteFile() suppresses the warning of the locked in-place fallback
 *     (@file_put_contents), matching the surrounding @chmod/@rename/@unlink and
 *     the RuntimeException that reports the failure.
 *   - clean_html() drops <script>…</script> and <style>…</style> elements with
 *     their contents (including an unterminated opener) as a pre-pass. Those
 *     tags are not allow-listed, so the leftover code was never usable and was
 *     surfacing as visible text. All other removed tags keep their inner text.
 * 2026-09-20.1 - Hardening pass on top of 2.0.1:
 *   - declare(strict_types=1) at the top of the file, and a stricter $GLOBALS
 *     name gate. _htmlValidVariableName() rejects empty names, null bytes and
 *     path-like or syntactically broken names before any template-driven read
 *     or write reaches $GLOBALS.
 *   - Safer defaults: HTML_SSI_ECHO_ESCAPE is now true and
 *     HTML_AUTO_SANITIZE_INPUT is now false, so request superglobals are only
 *     mutated on explicit opt-in. HTML_Sanitized_Input() returns sanitized
 *     copies of $_GET/$_POST/$_COOKIE/$_REQUEST without mutating them.
 *   - SSI directives parse their attributes generically via
 *     _htmlParseSsiAttributes(), so attribute order no longer matters
 *     ("<!--#set value="VAL" var="NAME" -->" and a reordered #config now work).
 *   - _htmlSsiStrftimeToDate() escapes literal characters, so plain text in a
 *     time format ("Updated at %H:%M") is no longer reinterpreted as date()
 *     tokens, and %h maps to the abbreviated month name like %b. The
 *     "\x02\x03" day-of-year marker contract is unchanged.
 *   - Include containment normalizes separators and case for the
 *     case-insensitive Windows/macOS filesystems and enforces
 *     HTML_MAX_INCLUDE_BYTES.
 *   - clean_html() no longer publishes a temporary $GLOBALS['TAGS']; clean()
 *     keeps reading it for legacy callers.
 *   - WriteFile() makes the umask handling explicit and keeps the atomic
 *     write-then-rename with a locked in-place fallback.
 *   - Show_HTML() accepts '0' as a block selector.
 * 2026-09-16.1 - Apache SSI parity for the directives used by the conbat.ru site:
 *   - <!--#set var="NAME" value="VAL" --> writes into $GLOBALS and is removed from
 *     the output. $VAR references inside VAL are expanded once against the built-in
 *     SSI variables and $GLOBALS; undefined names are left untouched.
 *     Reserved and built-in names can never be overwritten.
 *   - <!--#echo var="NAME" --> (single or double quotes) outputs built-in SSI
 *     variables (DATE_LOCAL, DATE_GMT, DOCUMENT_URI, DOCUMENT_NAME, DOCUMENT_ROOT,
 *     QUERY_STRING, SERVER_NAME, HTTP_USER_AGENT, LAST_MODIFIED) or template
 *     variables, honouring HTML_RESERVED_GLOBALS and the optional
 *     HTML_SSI_ECHO_ALLOW allow-list. HTML_SSI_ECHO_ESCAPE still applies.
 *     The legacy <!--##echo var="NAME" --> directive is unchanged and accepts both
 *     quote styles now.
 *   - <!--#config timefmt="FMT" --> stores the format in HTML_SSI_TIMEFMT.
 *     strftime() tokens are converted to date() tokens (%Y %m %d %H %M %S %j %B %b
 *     %A %a %p %I %Z %z %D %F %T %R %r %c %n %t %%% and friends).
 *   - Relative includes ("ssi/x.htm", "./ssi/x.htm") resolve against the directory
 *     of the file that contains the directive, with a fallback to the document
 *     root; absolute paths ("/ssi/x.htm") still resolve from the document root.
 *     The current file travels through the new optional processSSI() argument, so
 *     the legacy processSSI($html) call keeps working.
 *   - Directive evaluation order inside one file is #config, #set, includes,
 *     #echo/##echo, <!--@var-->: variables set at the top of a template are
 *     therefore visible to the partials included below them.
 * 2026-09-04.2 - Security and edge-case audit:
 *   - normalize() and clean_html() no longer lose content when the input is not
 *     valid UTF-8: Unicode patterns fall back to byte-safe patterns.
 *   - _htmlCleanRound() handles values outside the platform integer range and
 *     no longer produces wrapped or scientific output.
 *   - WriteFile() applies umask-based permissions to newly created files instead
 *     of inheriting the 0600 mode of the temporary file, sanitizes the temporary
 *     prefix and verifies that the whole payload was written.
 *   - SSI directives can no longer overwrite superglobals or library
 *     configuration: reserved names are rejected and an optional allow-list plus
 *     output escaping are available for <!--##echo-->.
 *   - Show_HTML() validates $GLOBALS['html'], accepts '0' as an exec symbol and
 *     falls back to direct output when the requested handle is unusable.
 *   - HTML_MAX_PRECISION can be configured to 0 again.
 * 2026-09-03.1 - Alignment with html4.pl:
 *   - Removed the nested LoadVar() declaration that fataled on a second call.
 *   - Added locale-aware Round, Clean Round, Div, Round Div, Clean Round Div.
 *   - Added bounded SSI recursion, include limits and traversal protection.
 *   - Added atomic file output, quote-aware normalization and tag allow-listing.
 *   - Added brace-safe variable parsing, indirect variables and translations.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80300) {
    throw new RuntimeException('This template library requires PHP 8.3 or newer.');
}

$GLOBALS['HTML_MAX_SSI_DEPTH'] ??= 16;
$GLOBALS['HTML_MAX_SSI_TAGS'] ??= 256;
$GLOBALS['HTML_MAX_TEMPLATE_PASSES'] ??= 2;
$GLOBALS['HTML_MAX_BLOCK_DEPTH'] ??= 64;
$GLOBALS['HTML_MAX_PRECISION'] ??= 100;
$GLOBALS['HTML_MAX_TEMPLATE_BYTES'] ??= 16 * 1024 * 1024;
$GLOBALS['HTML_MAX_INCLUDE_BYTES'] ??= 8 * 1024 * 1024;
$GLOBALS['HTML_SSI_ALLOW_PARENT'] ??= false;
$GLOBALS['HTML_SSI_ECHO_ESCAPE'] ??= true;
$GLOBALS['HTML_SSI_ECHO_ALLOW'] ??= [];
$GLOBALS['HTML_SSI_TIMEFMT'] ??= '%A, %d-%b-%Y %H:%M:%S %Z';
$GLOBALS['HTML_AUTO_SANITIZE_INPUT'] ??= false;
$GLOBALS['HTML_ALLOWED_TAGS'] ??= [
    'br' => true,
    'p' => true,
    'h1' => true,
    'h2' => true,
    'h3' => true,
    'h4' => true,
    'strong' => true,
    'b' => true,
    'ul' => true,
    'ol' => true,
    'li' => true,
    'hr' => true,
];

/**
 * Global names that templates are never allowed to read or overwrite.
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
    'HTML_MAX_SSI_DEPTH' => true,
    'HTML_MAX_SSI_TAGS' => true,
    'HTML_MAX_TEMPLATE_PASSES' => true,
    'HTML_MAX_BLOCK_DEPTH' => true,
    'HTML_MAX_PRECISION' => true,
    'HTML_MAX_TEMPLATE_BYTES' => true,
    'HTML_MAX_INCLUDE_BYTES' => true,
    'HTML_SSI_ALLOW_PARENT' => true,
    'HTML_SSI_ECHO_ESCAPE' => true,
    'HTML_SSI_ECHO_ALLOW' => true,
    'HTML_SSI_TIMEFMT' => true,
    'HTML_AUTO_SANITIZE_INPUT' => true,
    'HTML_ALLOWED_TAGS' => true,
    'HTML_WWW_PATH' => true,
    'HTML_Prepare_Count' => true,
];

/**
 * Built-in Apache SSI variables exposed to <!--#echo var="…" --> and to the
 * $VAR expansion inside <!--#set … value="…" -->.
 */
const HTML_SSI_BUILTIN_VARS = [
    'DATE_LOCAL' => true,
    'DATE_GMT' => true,
    'DOCUMENT_URI' => true,
    'DOCUMENT_NAME' => true,
    'DOCUMENT_ROOT' => true,
    'QUERY_STRING' => true,
    'SERVER_NAME' => true,
    'HTTP_USER_AGENT' => true,
    'LAST_MODIFIED' => true,
];

/**
 * Converts a template value to a string without emitting conversion warnings.
 */
function _htmlStringify(mixed $value): string
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

    if ($value instanceof Stringable) {
        return (string) $value;
    }

    return '';
}

/**
 * Executes a callback replacement and converts an unexpected PCRE failure to an exception.
 */
function _htmlPregReplaceCallback(string $pattern, callable $callback, string $subject): string
{
    $result = preg_replace_callback($pattern, $callback, $subject);

    if ($result === null) {
        throw new RuntimeException('Template regular expression failed: ' . preg_last_error_msg());
    }

    return $result;
}

/**
 * Executes a regular replacement and converts an unexpected PCRE failure to an exception.
 */
function _htmlPregReplace(string $pattern, string $replacement, string $subject): string
{
    $result = preg_replace($pattern, $replacement, $subject);

    if ($result === null) {
        throw new RuntimeException('Template regular expression failed: ' . preg_last_error_msg());
    }

    return $result;
}

/**
 * Applies a Unicode pattern and retries with a byte-safe pattern on malformed input.
 */
function _htmlPregReplaceUnicode(string $unicodePattern, string $bytePattern, string $replacement, string $subject): string
{
    $result = preg_replace($unicodePattern, $replacement, $subject);

    if ($result !== null) {
        return $result;
    }

    $result = preg_replace($bytePattern, $replacement, $subject);

    return $result ?? $subject;
}

/**
 * Returns a bounded integer configuration value.
 */
function _htmlPositiveLimit(string $name, int $default, int $maximum, int $minimum = 1): int
{
    $value = $GLOBALS[$name] ?? $default;
    $value = is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)
        ? (int) $value
        : $default;

    return max($minimum, min($maximum, $value));
}

/**
 * Parses numbers with dot or comma decimal separators and common group separators.
 */
function _htmlNumericValue(mixed $value): float
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
 * Returns a bounded decimal precision.
 */
function _htmlPrecision(mixed $precision): int
{
    $maximum = _htmlPositiveLimit('HTML_MAX_PRECISION', 100, 1000, 0);
    $value = is_int($precision) || (is_string($precision) && preg_match('/^\d+$/', $precision) === 1)
        ? (int) $precision
        : 0;

    return max(0, min($maximum, $value));
}

/**
 * Formats a number with a stable dot decimal separator.
 */
function _htmlRound(float $value, mixed $precision): string
{
    $digits = _htmlPrecision($precision);

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
 * Removes the decimal suffix when the value is an exact integer.
 */
function _htmlCleanRound(float $value, mixed $precision): string
{
    if (!is_finite($value)) {
        return '0';
    }

    if (fmod($value, 1.0) === 0.0) {
        $integer = number_format($value, 0, '.', '');
        return $integer === '-0' ? '0' : $integer;
    }

    return _htmlRound($value, $precision);
}

/**
 * Evaluates one html4.pl-compatible numeric directive.
 */
function _htmlNumericDirective(
    callable $resolver,
    string $mode,
    string $key,
    mixed $divisor,
    mixed $precision
): string {
    $value = _htmlNumericValue($resolver($key));

    if ($mode === 'clean_round') {
        return _htmlCleanRound($value, $precision);
    }

    if ($mode === 'round') {
        return _htmlRound($value, $precision);
    }

    $result = (float) Divide($value, _htmlNumericValue($divisor));

    return match ($mode) {
        'clean_round_div' => _htmlCleanRound($result, $precision),
        'round_div' => _htmlRound($result, $precision),
        default => _htmlStringify($result),
    };
}

/**
 * Applies variables and numeric directives to a template string.
 */
function _htmlProcessVariables(string $html, callable $resolver, string $execSymbol = '%'): string
{
    if ($execSymbol === '') {
        $execSymbol = '%';
    }

    $quoted = preg_quote($execSymbol, '~');

    $html = _htmlPregReplaceCallback(
        '~([%@])\{\%\{([^{}]*?)\}\}~',
        static fn(array $matches): string => $matches[1] . '{' . _htmlStringify($resolver($matches[2])) . '}',
        $html
    );

    $html = _htmlPregReplaceCallback(
        '~@\{([^{}]*?)\}~',
        static function (array $matches) use ($resolver): string {
            $translated = _htmlStringify($resolver('Translate.' . $matches[1]));
            return $translated !== '' ? $translated : $matches[1];
        },
        $html
    );

    $directives = [
        'clean_round_div' => '~' . $quoted . '\{([^{}]*?)\.\s*Clean\s+Round\s+Div\s+([+-]?[\d.,]+)\s*:\s*(\d+)\s*\}~i',
        'clean_round' => '~' . $quoted . '\{([^{}]*?)\.\s*Clean\s+Round\s*:\s*(\d+)\s*\}~i',
        'round_div' => '~' . $quoted . '\{([^{}]*?)\.\s*Round\s+Div\s+([+-]?[\d.,]+)\s*:\s*(\d+)\s*\}~i',
        'round' => '~' . $quoted . '\{([^{}]*?)\.\s*Round\s*:\s*(\d+)\s*\}~i',
        'div' => '~' . $quoted . '\{([^{}]*?)\.\s*Div\s+([+-]?[\d.,]+)\s*\}~i',
    ];

    foreach ($directives as $mode => $pattern) {
        $html = _htmlPregReplaceCallback(
            $pattern,
            static function (array $matches) use ($resolver, $mode): string {
                if ($mode === 'clean_round' || $mode === 'round') {
                    return _htmlNumericDirective($resolver, $mode, $matches[1], null, $matches[2]);
                }

                $precision = $mode === 'div' ? null : ($matches[3] ?? null);

                return _htmlNumericDirective($resolver, $mode, $matches[1], $matches[2], $precision);
            },
            $html
        );
    }

    $html = _htmlPregReplaceCallback(
        '~#\{([^{}]*?)\}~',
        static fn(array $matches): string => _htmlStringify($resolver($matches[1])),
        $html
    );

    $html = _htmlPregReplaceCallback(
        '~' . $quoted . '\{([^{}]*?)\}~',
        static fn(array $matches): string => _htmlStringify($resolver($matches[1])),
        $html
    );

    $html = _htmlPregReplaceCallback(
        '~' . $quoted . '\[([^\[\]]*?)\]~',
        static fn(array $matches): string => $execSymbol . '{' . $matches[1] . '}',
        $html
    );

    return $html;
}

/**
 * Checks whether a template/global variable name is syntactically safe.
 *
 * Allows:
 *   NAME
 *   NAME_1
 *   Translate.NAME
 *   user.name
 *
 * Rejects empty names, null bytes, superglobal tricks and path-like names.
 */
function _htmlValidVariableName(string $name): bool
{
    if ($name === '' || str_contains($name, "\0")) {
        return false;
    }

    return preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,127}$/', $name) === 1;
}

/**
 * Returns true when a global name may be read or written by a template directive.
 */
function _htmlGlobalAllowed(string $name): bool
{
    if (!_htmlValidVariableName($name)) {
        return false;
    }

    if (isset(HTML_RESERVED_GLOBALS[$name])) {
        return false;
    }

    $allow = $GLOBALS['HTML_SSI_ECHO_ALLOW'] ?? [];
    if (is_array($allow) && $allow !== []) {
        return isset($allow[$name]) || in_array($name, $allow, true);
    }

    return true;
}

/**
 * Returns true when a built-in SSI variable may be echoed under the current allow-list.
 */
function _htmlSsiEchoNameAllowed(string $name): bool
{
    if (!_htmlValidVariableName($name)) {
        return false;
    }

    $allow = $GLOBALS['HTML_SSI_ECHO_ALLOW'] ?? [];

    if (!is_array($allow) || $allow === []) {
        return true;
    }

    return isset($allow[$name]) || in_array($name, $allow, true);
}

/**
 * Returns true when a template directive is allowed to store a value in $GLOBALS.
 *
 * Reserved names and built-in SSI variables are never writable.
 */
function _htmlSsiNameWritable(string $name): bool
{
    if (!_htmlValidVariableName($name)) {
        return false;
    }

    return !isset(HTML_RESERVED_GLOBALS[$name]) && !isset(HTML_SSI_BUILTIN_VARS[$name]);
}

/**
 * Converts a strftime()-style format string into a date()-compatible one.
 *
 * Literal characters are escaped because PHP date() treats many letters as
 * formatting tokens.
 *
 * %j is represented by "\x02\x03" because date() has zero-based "z", not
 * one-based strftime "%j".
 */
function _htmlSsiStrftimeToDate(string $format): string
{
    $map = [
        'Y' => 'Y',
        'y' => 'y',
        'C' => 'Y',
        'm' => 'm',
        'd' => 'd',
        'e' => 'j',
        'H' => 'H',
        'k' => 'G',
        'I' => 'h',
        'l' => 'g',
        'M' => 'i',
        'S' => 's',
        'p' => 'A',
        'P' => 'a',
        'B' => 'F',
        'h' => 'M',
        'b' => 'M',
        'A' => 'l',
        'a' => 'D',
        'Z' => 'T',
        'z' => 'O',
        'u' => 'N',
        'w' => 'w',
        'G' => 'o',
        'V' => 'W',
        'U' => 'W',
        'W' => 'W',
        'D' => 'm/d/y',
        'F' => 'Y-m-d',
        'T' => 'H:i:s',
        'R' => 'H:i',
        'r' => 'h:i:s A',
        'c' => 'Y-m-d\TH:i:sP',
        'x' => 'm/d/y',
        'X' => 'H:i:s',
        's' => 'U',
        'n' => "\n",
        't' => "\t",
        '%' => '%',
    ];

    // Every date() formatting character must be listed here, otherwise a
    // literal letter is reinterpreted by date() instead of being escaped.
    $dateTokens = 'dDjlNSwzWFmMntLoYyaABgGXxhHisuevIOPTZcrUp';
    $converted = '';
    $length = strlen($format);

    $escapeLiteral = static function (string $char) use ($dateTokens): string {
        if ($char === '\\') {
            return '\\\\';
        }

        return str_contains($dateTokens, $char) ? '\\' . $char : $char;
    };

    for ($index = 0; $index < $length; ++$index) {
        $character = $format[$index];

        if ($character !== '%' || $index + 1 >= $length) {
            $converted .= $escapeLiteral($character);
            continue;
        }

        $token = $format[++$index];

        if ($token === 'j') {
            $converted .= "\x02\x03";
            continue;
        }

        if (isset($map[$token])) {
            $converted .= $map[$token];
            continue;
        }

        $converted .= $escapeLiteral($token);
    }

    return $converted;
}

/**
 * Formats a timestamp using the configured SSI time format.
 */
function _htmlSsiFormatTime(int $timestamp, string $format, bool $gmt = false): string
{
    $dateFormat = _htmlSsiStrftimeToDate($format);
    $result = $gmt ? gmdate($dateFormat, $timestamp) : date($dateFormat, $timestamp);

    if (str_contains($dateFormat, "\x02\x03")) {
        $dayOfYear = (int) ($gmt ? gmdate('z', $timestamp) : date('z', $timestamp)) + 1;
        $result = str_replace("\x02\x03", sprintf('%03d', $dayOfYear), $result);
    }

    return $result;
}

/**
 * Returns the value of a built-in Apache SSI variable.
 *
 * @param array<string, string> $builtins
 */
function _htmlSsiBuiltinValue(string $name, array $builtins, ?string $currentFile): string
{
    if ($name !== 'LAST_MODIFIED') {
        return $builtins[$name] ?? '';
    }

    if ($currentFile === null || $currentFile === '' || !is_file($currentFile)) {
        return '';
    }

    $modified = filemtime($currentFile);
    if ($modified === false) {
        return '';
    }

    $timefmt = _htmlStringify($GLOBALS['HTML_SSI_TIMEFMT'] ?? '');

    return _htmlSsiFormatTime($modified, $timefmt !== '' ? $timefmt : '%A, %d-%b-%Y %H:%M:%S %Z');
}

/**
 * Resolves the request URI used by the DOCUMENT_URI built-in variable.
 */
function _htmlSsiDocumentUri(): string
{
    $uri = _htmlStringify($_SERVER['REQUEST_URI'] ?? '');

    if ($uri === '') {
        $uri = _htmlStringify($_SERVER['SCRIPT_NAME'] ?? '');
    }

    if ($uri === '') {
        return '';
    }

    $uri = (string) preg_replace('~\?.*$~s', '', $uri);
    $uri = str_replace('\\', '/', $uri);

    return str_starts_with($uri, '/') ? $uri : '/' . $uri;
}

/**
 * Returns the built-in SSI variables for the current request.
 *
 * @return array<string, string>
 */
function _htmlSsiBuiltins(?string $currentFile = null): array
{
    $timefmt = _htmlStringify($GLOBALS['HTML_SSI_TIMEFMT'] ?? '');
    if ($timefmt === '') {
        $timefmt = '%A, %d-%b-%Y %H:%M:%S %Z';
    }

    $now = time();
    $documentUri = _htmlSsiDocumentUri();
    $documentRoot = _htmlStringify($_SERVER['DOCUMENT_ROOT'] ?? '');

    if ($documentRoot === '') {
        $documentRoot = _htmlStringify($GLOBALS['HTML_WWW_PATH'] ?? '');
    }

    return [
        'DATE_LOCAL' => _htmlSsiFormatTime($now, $timefmt),
        'DATE_GMT' => _htmlSsiFormatTime($now, $timefmt, true),
        'DOCUMENT_URI' => $documentUri,
        'DOCUMENT_NAME' => $documentUri === '' ? '' : basename($documentUri),
        'DOCUMENT_ROOT' => $documentRoot,
        'QUERY_STRING' => _htmlStringify($_SERVER['QUERY_STRING'] ?? ''),
        'SERVER_NAME' => _htmlStringify($_SERVER['SERVER_NAME'] ?? ''),
        'HTTP_USER_AGENT' => _htmlStringify($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'LAST_MODIFIED' => _htmlSsiBuiltinValue('LAST_MODIFIED', [], $currentFile),
    ];
}

/**
 * Applies the configured HTML escaping policy to an echoed SSI value.
 */
function _htmlSsiEscapeEcho(string $value): string
{
    if (empty($GLOBALS['HTML_SSI_ECHO_ESCAPE'])) {
        return $value;
    }

    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8', false);
}

/**
 * Returns the value of a variable addressed by <!--#echo var="…" -->.
 *
 * Built-in SSI variables win over template variables of the same name.
 */
function _htmlSsiEchoValue(string $name, ?string $currentFile = null): string
{
    $name = trim($name);

    if ($name === '' || str_contains($name, "\0") || isset(HTML_RESERVED_GLOBALS[$name])) {
        return '';
    }

    if (isset(HTML_SSI_BUILTIN_VARS[$name])) {
        if (!_htmlSsiEchoNameAllowed($name)) {
            return '';
        }

        $builtins = _htmlSsiBuiltins($currentFile);

        return _htmlSsiBuiltinValue($name, $builtins, $currentFile);
    }

    if (!_htmlGlobalAllowed($name)) {
        return '';
    }

    return _htmlStringify($GLOBALS[$name] ?? '');
}

/**
 * Expands $VAR / ${VAR} references inside an SSI <!--#set … value="…" --> payload.
 *
 * Unknown names are kept verbatim, a backslash escapes a literal dollar sign.
 */
function _htmlSsiExpandVars(string $value, ?string $currentFile = null): string
{
    if ($value === '' || !str_contains($value, '$')) {
        return $value;
    }

    $protected = _htmlPregReplace('~\\\\\$~', "\x01", $value);
    $builtins = null;

    $expanded = _htmlPregReplaceCallback(
        '~\$(?:\{([A-Za-z_][A-Za-z0-9_]*)\}|([A-Za-z_][A-Za-z0-9_]*))~',
        static function (array $matches) use (&$builtins, $currentFile): string {
            $name = $matches[1] !== '' ? $matches[1] : ($matches[2] ?? '');

            if ($name === '' || isset(HTML_RESERVED_GLOBALS[$name])) {
                return '';
            }

            if (isset(HTML_SSI_BUILTIN_VARS[$name])) {
                $builtins ??= _htmlSsiBuiltins($currentFile);

                return _htmlSsiBuiltinValue($name, $builtins, $currentFile);
            }

            if (array_key_exists($name, $GLOBALS) && $GLOBALS[$name] !== null) {
                return _htmlStringify($GLOBALS[$name]);
            }

            return $matches[0];
        },
        $protected
    );

    return str_replace("\x01", '$', $expanded);
}

/**
 * Returns the directory that relative SSI includes are resolved against.
 */
function _htmlSsiRelativeDir(?string $currentFile): ?string
{
    if ($currentFile === null || $currentFile === '') {
        return null;
    }

    if (is_dir($currentFile)) {
        return $currentFile;
    }

    $directory = dirname($currentFile);

    return $directory === '' || $directory === '.' ? null : $directory;
}

/**
 * Returns the configured SSI document root.
 */
function _htmlDocumentRoot(?string $override = null): string
{
    $root = $override;

    if ($root === null || $root === '') {
        $root = _htmlStringify($GLOBALS['HTML_WWW_PATH'] ?? ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    }

    if ($root === '') {
        $root = getcwd() ?: '.';
    }

    $resolved = realpath($root);
    if ($resolved !== false && is_dir($resolved)) {
        return $resolved;
    }

    $trimmed = rtrim($root, "/\\");

    return $trimmed !== '' ? $trimmed : DIRECTORY_SEPARATOR;
}

/**
 * Checks one candidate include path and returns it when it is safe to use.
 */
function _htmlResolveIncludeCandidate(string $candidate, string $root): ?string
{
    $resolved = realpath($candidate);

    if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
        return null;
    }

    if (empty($GLOBALS['HTML_SSI_ALLOW_PARENT'])) {
        $resolvedRoot = realpath($root);

        if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
            return null;
        }

        $resolvedNormalized = rtrim(str_replace('\\', '/', $resolved), '/');
        $rootNormalized = rtrim(str_replace('\\', '/', $resolvedRoot), '/');

        if (PHP_OS_FAMILY === 'Windows' || PHP_OS_FAMILY === 'Darwin') {
            $resolvedNormalized = strtolower($resolvedNormalized);
            $rootNormalized = strtolower($rootNormalized);
        }

        if (
            $resolvedNormalized !== $rootNormalized
            && !str_starts_with($resolvedNormalized, $rootNormalized . '/')
        ) {
            return null;
        }
    }

    $maximum = _htmlPositiveLimit(
        'HTML_MAX_INCLUDE_BYTES',
        8 * 1024 * 1024,
        256 * 1024 * 1024
    );

    $size = filesize($resolved);

    if ($size === false || $size > $maximum) {
        return null;
    }

    return $resolved;
}

/**
 * Resolves an SSI virtual path and keeps it inside the configured document root.
 *
 * Absolute paths ("/ssi/x.htm") are resolved from the document root, relative ones
 * ("ssi/x.htm", "./ssi/x.htm") from $relativeDir first (the directory of the file
 * that contains the directive) and from the document root as a fallback.
 */
function _htmlResolveInclude(string $virtual, ?string $rootOverride = null, ?string $relativeDir = null): ?string
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

    $allowParent = !empty($GLOBALS['HTML_SSI_ALLOW_PARENT']);
    if (!$allowParent && preg_match('~(?:^|/)\.\.(?:/|$)~', $virtual) === 1) {
        return null;
    }

    $relative = str_replace('/', DIRECTORY_SEPARATOR, $virtual);
    $root = _htmlDocumentRoot($rootOverride);
    $candidates = [];

    if (!$absolute && $relativeDir !== null && $relativeDir !== '') {
        $candidates[] = rtrim($relativeDir, "/\\") . DIRECTORY_SEPARATOR . $relative;
    }

    $candidates[] = $root . DIRECTORY_SEPARATOR . $relative;

    foreach ($candidates as $candidate) {
        $resolved = _htmlResolveIncludeCandidate($candidate, $root);
        if ($resolved !== null) {
            return $resolved;
        }
    }

    return null;
}

/**
 * Parses SSI directive attributes.
 *
 * Example:
 *   var="NAME" value='VALUE'
 *
 * @return array<string, string>
 */
function _htmlParseSsiAttributes(string $source): array
{
    $attributes = [];

    preg_match_all(
        '~([A-Za-z_][A-Za-z0-9_-]*)\s*=\s*("([^"]*)"|\'([^\']*)\')~s',
        $source,
        $matches,
        PREG_SET_ORDER
    );

    foreach ($matches as $match) {
        $name = strtolower($match[1]);
        $value = $match[3] !== '' ? $match[3] : ($match[4] ?? '');
        $attributes[$name] = $value;
    }

    return $attributes;
}

/**
 * Processes SSI recursively with depth, count and cycle protection.
 *
 * Directives are evaluated in this order:
 * #config, #set, includes, #echo / ##echo, then <!--@var-->.
 *
 * @param array<string, true> $stack
 */
function _htmlProcessSSI(
    string $html,
    int $depth,
    array &$stack,
    int &$processed,
    ?string $rootOverride = null,
    ?string $currentFile = null
): string {
    $maxDepth = _htmlPositiveLimit('HTML_MAX_SSI_DEPTH', 16, 128);
    $maxTags = _htmlPositiveLimit('HTML_MAX_SSI_TAGS', 256, 10000);
    $relativeDir = _htmlSsiRelativeDir($currentFile);

    $html = _htmlPregReplaceCallback(
        '~<!--#config\s+((?:"[^"]*"|\'[^\']*\'|[^>])*)-->~is',
        static function (array $matches): string {
            $attrs = _htmlParseSsiAttributes($matches[1]);

            if (isset($attrs['timefmt']) && $attrs['timefmt'] !== '') {
                $GLOBALS['HTML_SSI_TIMEFMT'] = $attrs['timefmt'];
            }

            return '';
        },
        $html
    );

    $html = _htmlPregReplaceCallback(
        '~<!--#set\s+((?:"[^"]*"|\'[^\']*\'|[^>])*)-->~is',
        static function (array $matches) use ($currentFile): string {
            $attrs = _htmlParseSsiAttributes($matches[1]);

            $name = trim($attrs['var'] ?? '');
            $value = $attrs['value'] ?? '';

            if (_htmlSsiNameWritable($name)) {
                $GLOBALS[$name] = _htmlSsiExpandVars($value, $currentFile);
            }

            return '';
        },
        $html
    );

    $html = _htmlPregReplaceCallback(
        '~<!--#include\s+((?:"[^"]*"|\'[^\']*\'|[^>])*)-->~is',
        static function (array $matches) use (
            $depth,
            &$stack,
            &$processed,
            $maxDepth,
            $maxTags,
            $rootOverride,
            $relativeDir
        ): string {
            if ($depth >= $maxDepth || $processed >= $maxTags) {
                return '';
            }

            $attrs = _htmlParseSsiAttributes($matches[1]);
            $virtual = $attrs['virtual'] ?? '';

            if ($virtual === '') {
                return '';
            }

            ++$processed;

            $path = _htmlResolveInclude($virtual, $rootOverride, $relativeDir);

            if ($path === null || isset($stack[$path])) {
                return '';
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                return '';
            }

            $stack[$path] = true;

            try {
                return _htmlProcessSSI(
                    $contents,
                    $depth + 1,
                    $stack,
                    $processed,
                    $rootOverride,
                    $path
                );
            } finally {
                unset($stack[$path]);
            }
        },
        $html
    );

    $html = _htmlPregReplaceCallback(
        '~<!--#echo\s+((?:"[^"]*"|\'[^\']*\'|[^>])*)-->~is',
        static function (array $matches) use ($currentFile): string {
            $attrs = _htmlParseSsiAttributes($matches[1]);
            $name = $attrs['var'] ?? '';

            return _htmlSsiEscapeEcho(_htmlSsiEchoValue($name, $currentFile));
        },
        $html
    );

    $html = _htmlPregReplaceCallback(
        '~<!--##echo\s+((?:"[^"]*"|\'[^\']*\'|[^>])*)-->~is',
        static function (array $matches): string {
            $attrs = _htmlParseSsiAttributes($matches[1]);
            $name = trim($attrs['var'] ?? '');

            if (!_htmlGlobalAllowed($name)) {
                return '';
            }

            return _htmlSsiEscapeEcho(_htmlStringify($GLOBALS[$name] ?? ''));
        },
        $html
    );

    $html = _htmlPregReplaceCallback(
        '~<!--@var\s*=\s*"([^"]*)"(.*?)-->~is',
        static function (array $matches): string {
            $name = trim($matches[1]);
            $value = trim($matches[2]);

            if (_htmlSsiNameWritable($name)) {
                $GLOBALS[$name] = $value;
            }

            return $matches[2];
        },
        $html
    );

    return $html;
}

/**
 * Returns a block name captured by either a legacy or custom block pattern.
 *
 * @param array<int, string> $matches
 */
function _htmlBlockName(array $matches): string
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
function _htmlLoadVar($handle, string $startPattern, int $depth): string
{
    if (!is_resource($handle)) {
        return '';
    }

    if ($depth >= _htmlPositiveLimit('HTML_MAX_BLOCK_DEPTH', 64, 1024)) {
        return '';
    }

    $endPattern = '~<!--@End\s+Block\s*(.*?)-->~i';
    $result = '';

    while (($line = fgets($handle)) !== false) {
        if (preg_match($endPattern, $line) === 1) {
            break;
        }

        if (preg_match($startPattern, $line, $matches) === 1) {
            $name = _htmlBlockName($matches);
            if ($name !== '' && _htmlGlobalAllowed($name)) {
                $GLOBALS[$name] = _htmlLoadVar($handle, $startPattern, $depth + 1);
            } else {
                _htmlLoadVar($handle, $startPattern, $depth + 1);
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
function LoadVar($handle, string $startPattern): string
{
    return _htmlLoadVar($handle, $startPattern, 0);
}

/**
 * Loads a legacy template and publishes named blocks into $GLOBALS.
 */
function LoadTemplate(string $file): string
{
    if ($file === '' || str_contains($file, "\0") || !is_file($file) || !is_readable($file)) {
        return 'Template Not Found';
    }

    $maximum = _htmlPositiveLimit('HTML_MAX_TEMPLATE_BYTES', 16 * 1024 * 1024, 512 * 1024 * 1024);
    $size = filesize($file);
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
                $name = _htmlBlockName($matches);
                if ($name !== '' && _htmlGlobalAllowed($name)) {
                    $GLOBALS[$name] = LoadVar($handle, $startPattern);
                } elseif ($name !== '') {
                    LoadVar($handle, $startPattern);
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
 * Processes template variables using the supplied legacy callback.
 */
function processHtml(string $html, callable $updateTemplate): string
{
    $resolver = static fn(string $name): string => _htmlStringify($updateTemplate($name));

    return _htmlProcessVariables($html, $resolver, '%');
}

/**
 * Main legacy HTML preprocessor.
 */
function HTML(string $html): string
{
    $ownsCounter = empty($GLOBALS['HTML_Prepare_Count']);
    $pass = $ownsCounter ? 1 : (int) $GLOBALS['HTML_Prepare_Count'];
    $maximum = _htmlPositiveLimit('HTML_MAX_TEMPLATE_PASSES', 2, 16);

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
 */
function update_template(string $varName): string
{
    if ($varName !== '' && array_key_exists($varName, $GLOBALS) && $GLOBALS[$varName] !== null) {
        return _htmlStringify($GLOBALS[$varName]);
    }

    if (str_starts_with($varName, 'Translate.')) {
        return substr($varName, strlen('Translate.'));
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
    $sanitized = [];

    foreach ($input as $key => $value) {
        if (is_array($value)) {
            $sanitized[$key] = sanitizeXSS($value);
            continue;
        }

        $sanitized[$key] = htmlspecialchars(
            _htmlStringify($value),
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            'UTF-8',
            true
        );
    }

    return $sanitized;
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
 * Processes SSI include, set, echo, config and variable directives.
 *
 * The optional $currentFile keeps the legacy call signature intact and tells the
 * processor which file the markup came from: relative includes ("ssi/x.htm") are
 * then resolved against its directory instead of the document root.
 */
function processSSI(string $html, ?string $currentFile = null): string
{
    $stack = [];
    $processed = 0;

    return _htmlProcessSSI($html, 0, $stack, $processed, null, $currentFile);
}

/**
 * Loads numeric html4.pl-style blocks into $GLOBALS['html'].
 */
function Read_HTML(string $file, int $block = 1): string
{
    if ($file === '' || str_contains($file, "\0") || !is_file($file) || !is_readable($file)) {
        throw new RuntimeException("Template file '{$file}' is not readable.");
    }

    $maximum = _htmlPositiveLimit('HTML_MAX_TEMPLATE_BYTES', 16 * 1024 * 1024, 512 * 1024 * 1024);
    $size = filesize($file);
    if ($size !== false && $size > $maximum) {
        throw new RuntimeException("Template file '{$file}' exceeds the configured size limit.");
    }

    $contents = file_get_contents($file);
    if ($contents === false) {
        throw new RuntimeException("Template file '{$file}' could not be read.");
    }

    if ($block < 0) {
        $block = 1;
    }

    if ($block === 1 || !isset($GLOBALS['html']) || !is_array($GLOBALS['html'])) {
        $GLOBALS['html'] = [];
    }

    $base = _htmlPregReplaceCallback(
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

    return _htmlStringify($GLOBALS['html'][$block] ?? '');
}

/**
 * Renders an html4.pl-style block.
 *
 * Accepts either one associative array or legacy key/value argument pairs.
 */
function Show_HTML(mixed ...$arguments): ?string
{
    if (count($arguments) === 1 && is_array($arguments[0])) {
        $public = $arguments[0];
    } else {
        $public = [];
        for ($index = 0, $count = count($arguments); $index + 1 < $count; $index += 2) {
            $public[_htmlStringify($arguments[$index])] = $arguments[$index + 1];
        }
    }

    $block = array_key_exists('html', $public) && $public['html'] !== ''
        ? (int) $public['html']
        : 1;
    $blocks = isset($GLOBALS['html']) && is_array($GLOBALS['html']) ? $GLOBALS['html'] : [];
    $source = _htmlStringify($blocks[$block] ?? '');

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

    if (strtolower(_htmlStringify($public['SSI'] ?? '')) !== 'off') {
        $root = _htmlStringify($public['www_path'] ?? '');
        $stack = [];
        $processed = 0;
        $line = _htmlProcessSSI($line, 0, $stack, $processed, $root !== '' ? $root : null);
    }

    $resolver = static fn(string $name): string => _htmlStringify($public[$name] ?? '');
    $symbol = _htmlStringify($public['exec_symbol'] ?? '');
    $line = _htmlProcessVariables($line, $resolver, $symbol !== '' ? $symbol : '%');
    $line = _htmlPregReplace('~\[!--@(.*?)--\]~s', '<!--@$1-->', $line);
    $line = _htmlPregReplace('~<\?html\s*if\s*\([^)]*\).*?\?>~is', '', $line);

    $mode = strtolower(_htmlStringify($public['output'] ?? ''));
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

/**
 * Divides two values and returns zero for a zero divisor.
 */
function Divide(mixed $a, mixed $b): float|int
{
    $left = _htmlNumericValue($a);
    $right = _htmlNumericValue($b);

    if ($right === 0.0) {
        return 0;
    }

    $result = $left / $right;

    return is_finite($result) ? $result : 0;
}

/**
 * Converts HTML tag names to lowercase without modifying attributes.
 */
function lc_Tags(string $input): string
{
    return _htmlPregReplaceCallback(
        '~<(\s*/?\s*)([A-Za-z][A-Za-z0-9:_-]*)((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>~',
        static fn(array $matches): string => '<' . $matches[1] . strtolower($matches[2]) . $matches[3] . '>',
        $input
    );
}

/**
 * Normalizes HTML to text while optionally preserving BR tags.
 */
function normalize(string $buffer, mixed $br = null, mixed $cleanEdges = false): string
{
    $preserveBreaks = $br === null || (int) $br === 0;
    $marker = "\x01HTML_BR\x01";

    $buffer = str_replace("\x01", '', $buffer);
    $buffer = _htmlPregReplace('~<!--.*?-->~s', '', $buffer);

    if ($preserveBreaks) {
        $buffer = _htmlPregReplace('~<br\s*/?>~i', $marker, $buffer);
    }

    $buffer = _htmlPregReplace('~<(?:"[^"]*"|\'[^\']*\'|[^\'">])*>~s', ' ', $buffer);
    $buffer = _htmlNormalizeEntities($buffer);
    $buffer = _htmlPregReplace('~[\x00\x02-\x08\x0B\x0C\x0E-\x1F\x7F]~', '', $buffer);
    $buffer = _htmlPregReplace('~[\t\n\r ]+~', ' ', $buffer);
    $buffer = $preserveBreaks ? str_replace($marker, '<br>', $buffer) : $buffer;
    $buffer = trim($buffer);
    $buffer = _htmlPregReplace('~,\z~', '', $buffer);

    if (!empty($cleanEdges)) {
        $buffer = _htmlPregReplaceUnicode(
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
function _htmlCleanTag(string $closing, string $tag, array $allowed): string
{
    $tag = strtolower($tag);

    return !empty($allowed[$tag]) ? '<' . $closing . $tag . '>' : '';
}

/**
 * Legacy public helper.
 */
function clean(string $closing, string $tag): string
{
    $allowed = isset($GLOBALS['TAGS']) && is_array($GLOBALS['TAGS'])
        ? $GLOBALS['TAGS']
        : [];

    return _htmlCleanTag($closing, $tag, $allowed);
}

/**
 * Keeps only allow-listed HTML tags and removes their attributes.
 *
 * <script> and <style> elements are dropped together with their contents: their
 * tags are never allow-listed, so the leftover code would only surface as
 * visible text. Every other removed tag keeps its inner text.
 */
function clean_html(string $html): string
{
    $allowed = $GLOBALS['HTML_ALLOWED_TAGS'] ?? [];

    if (!is_array($allowed)) {
        $allowed = [];
    }

    $html = _htmlPregReplace('~<!--.*?-->~s', '', $html);
    $html = _htmlPregReplace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $html);
    $html = _htmlPregReplace('~<(script|style)\b[^>]*>.*$~is', '', $html);

    $html = _htmlPregReplaceCallback(
        '~<(/?)([A-Za-z][A-Za-z0-9:_-]*)(?:"[^"]*"|\'[^\']*\'|[^\'">])*>~s',
        static fn(array $matches): string => _htmlCleanTag($matches[1], $matches[2], $allowed),
        $html
    );

    $html = _htmlNormalizeEntities($html);
    $html = _htmlPregReplaceUnicode('~\s+~u', '~[\s\x00-\x1F]+~', ' ', $html);

    return trim($html);
}

/**
 * Normalizes the legacy entity subset used by html4.pl.
 */
function _htmlNormalizeEntities(string $text): string
{
    $text = _htmlPregReplaceCallback(
        '~&#x([0-9A-Fa-f]{1,6});~',
        static fn(array $matches): string => '&#' . hexdec($matches[1]) . ';',
        $text
    );

    $text = _htmlPregReplaceCallback(
        '~&#(\d{1,7});~',
        static function (array $matches): string {
            $number = $matches[1];
            $map = $GLOBALS['entities'] ?? null;

            if (is_array($map) && isset($map[$number])) {
                return '&' . _htmlStringify($map[$number]) . ';';
            }

            return '&#' . $number . ';';
        },
        $text
    );

    return str_ireplace(['&thorn;', '&eth;', '&szlig;', '&nbsp;'], ['th', 'd', 'ss', ' '], $text);
}

/**
 * Writes a file atomically in the destination directory.
 */
function WriteFile(string $file, mixed $data): bool
{
    if ($file === '' || str_contains($file, "\0")) {
        throw new InvalidArgumentException('The output file path is invalid.');
    }

    $directory = dirname($file);

    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException("Output directory '{$directory}' is not writable.");
    }

    if (is_file($file) && !is_writable($file)) {
        throw new RuntimeException("Output file '{$file}' is not writable.");
    }

    $payload = _htmlStringify($data);
    $expected = strlen($payload);

    $prefix = (string) preg_replace('~[^A-Za-z0-9._-]~', '_', basename($file));
    $prefix = substr($prefix, 0, 32);

    if ($prefix === '') {
        $prefix = 'html';
    }

    $temporary = tempnam($directory, $prefix . '.tmp.');

    if ($temporary === false) {
        throw new RuntimeException("Cannot create a temporary file in '{$directory}'.");
    }

    if (is_file($file)) {
        $mode = fileperms($file) & 0777;
    } else {
        $oldUmask = umask();
        umask($oldUmask);
        $mode = 0666 & ~$oldUmask;
    }

    try {
        $written = file_put_contents($temporary, $payload, LOCK_EX);

        if ($written === false || $written !== $expected) {
            throw new RuntimeException("Cannot completely write temporary file '{$temporary}'.");
        }

        @chmod($temporary, $mode);

        if (!@rename($temporary, $file)) {
            $fallback = @file_put_contents($file, $payload, LOCK_EX);

            if ($fallback === false || $fallback !== $expected) {
                throw new RuntimeException("Cannot completely write output file '{$file}'.");
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

/**
 * Removes whitespace and non-word characters while preserving Unicode letters.
 */
function TrimSpaces(mixed $string): string
{
    $value = _htmlStringify($string);

    return _htmlPregReplaceUnicode('~[^\p{L}\p{N}_]+~u', '~\W+~', '', $value);
}

if (!empty($GLOBALS['HTML_AUTO_SANITIZE_INPUT'])) {
    $_GET = sanitizeXSS($_GET ?? []);
    $_POST = sanitizeXSS($_POST ?? []);
    $_COOKIE = sanitizeXSS($_COOKIE ?? []);
    $_REQUEST = sanitizeXSS($_REQUEST ?? []);
}
