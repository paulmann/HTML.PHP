<?php

declare(strict_types=1);

namespace HtmlPhp\Ssi;

use HtmlPhp\Support\Str;

/**
 * Formats byte counts the way Apache's `#fsize` does.
 *
 * The style is passed in by the caller (from `#config sizefmt`); this class
 * reads no configuration of its own.
 */
final class SizeFormat
{
    private function __construct() {}

    public static function format(int $bytes, string $style = 'abbrev'): string
    {
        return Str::formatSize($bytes, \in_array(strtolower($style), ['bytes', 'byte'], true) ? 'bytes' : 'abbrev');
    }
}
