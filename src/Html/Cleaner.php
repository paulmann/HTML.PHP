<?php

declare(strict_types=1);

namespace HtmlPhp\Html;

use HtmlPhp\Support\Pcre;
use HtmlPhp\Support\Str;

/**
 * HTML cleaning and normalisation (v3 `clean_html()`, `normalize()`,
 * `lc_Tags()` semantics) with the allow-list injected instead of read
 * from `$GLOBALS`.
 */
final class Cleaner
{
    /** Sentinel protecting `<br>` markers inside {@see self::normalize()}. */
    public const string BR_MARKER = "\x01HTML_BR\x01";

    /** @var array<string, true> */
    public const array DEFAULT_ALLOWED_TAGS = [
        'br' => true, 'p' => true, 'h1' => true, 'h2' => true, 'h3' => true,
        'h4' => true, 'strong' => true, 'b' => true, 'ul' => true, 'ol' => true,
        'li' => true, 'hr' => true,
    ];

    /** @param array<string, true> $allowedTags */
    public function __construct(private readonly array $allowedTags = self::DEFAULT_ALLOWED_TAGS) {}

    /** Converts HTML tag names to lowercase without touching attributes. */
    public function lcTags(string $input): string
    {
        return Pcre::replaceCallback(
            '~<(\s*/?\s*)([A-Za-z][A-Za-z0-9:_-]*)((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>~',
            static fn(array $matches): string => '<' . $matches[1] . strtolower($matches[2]) . $matches[3] . '>',
            $input,
        );
    }

    /**
     * Normalises HTML to a single text line while optionally preserving
     * `<br>` tags (legacy `normalize()` semantics).
     *
     * @param mixed $br preserve `<br>` when null/0, strip when truthy
     */
    public function normalize(string $buffer, mixed $br = null, mixed $cleanEdges = false): string
    {
        $preserveBreaks = $br === null || (int) $br === 0;
        $marker = self::BR_MARKER;

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
                $buffer,
            );
            $buffer = trim($buffer);
        }

        return $buffer;
    }

    /** Returns an allow-listed HTML tag without attributes ('' otherwise). */
    public function cleanTag(string $closing, string $tag): string
    {
        $tag = strtolower($tag);

        return !empty($this->allowedTags[$tag]) ? '<' . $closing . $tag . '>' : '';
    }

    /**
     * Keeps only allow-listed HTML tags and removes their attributes.
     *
     * `<script>`/`<style>` elements are dropped **with their contents**
     * (including an unterminated opener); every other removed tag keeps
     * its text.
     */
    public function cleanHtml(string $html): string
    {
        $html = Pcre::replace('~<!--.*?-->~s', '', $html);
        $html = Pcre::replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $html);
        $html = Pcre::replace('~<(script|style)\b[^>]*>.*$~is', '', $html);

        $html = Pcre::replaceCallback(
            '~<(/?)([A-Za-z][A-Za-z0-9:_-]*)(?:"[^"]*"|\'[^\']*\'|[^\'">])*>~s',
            fn(array $matches): string => $this->cleanTag($matches[1], $matches[2]),
            $html,
        );

        $html = Str::normalizeEntities($html);
        $html = Pcre::replaceUnicode('~\s+~u', '~[\s\x00-\x1F]+~', ' ', $html);

        return trim($html);
    }

    /** Removes whitespace and non-word characters (legacy `TrimSpaces()`). */
    public function trimSpaces(mixed $string): string
    {
        return Str::keepWordChars(Str::stringify($string));
    }

    /** @return array<string, true> */
    public function allowedTags(): array
    {
        return $this->allowedTags;
    }
}
