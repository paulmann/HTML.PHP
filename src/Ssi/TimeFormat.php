<?php

declare(strict_types=1);

namespace HtmlPhp\Ssi;

use HtmlPhp\Support\Str;

/**
 * strftime()-style formatting without the deprecated strftime().
 *
 * Apache's `#config timefmt` speaks strftime tokens; this converts them to
 * date() tokens (escaping literals) so no deprecation is emitted on 8.3+.
 */
final class TimeFormat
{
    public const string DEFAULT_FORMAT = '%A, %d-%b-%Y %H:%M:%S %Z';

    /** Sentinel for %j (one-based day of year); date() only has zero-based 'z'. */
    private const string DOY_MARKER = "\x02\x03";

    /**
     * strftime() token => date() token map.
     */
    private const array MAP = [
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
    private const string DATE_TOKENS = 'dDjlNSwzWFmMntLoYyaABgGXxhHisuevIOPTZcrUp';

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
                $converted .= self::DOY_MARKER;
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

        if (str_contains($dateFormat, self::DOY_MARKER)) {
            $dayOfYear = (int) ($gmt ? gmdate('z', $timestamp) : date('z', $timestamp)) + 1;
            $result = str_replace(self::DOY_MARKER, sprintf('%03d', $dayOfYear), $result);
        }

        return $result;
    }

}
