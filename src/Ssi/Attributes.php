<?php

declare(strict_types=1);

namespace HtmlPhp\Ssi;

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
