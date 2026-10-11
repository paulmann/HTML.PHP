<?php

declare(strict_types=1);

namespace HtmlPhp\Html;

use HtmlPhp\Support\Str;

/**
 * Context-aware output escaping.
 *
 * Not final: hosts that need a different HTML policy can subclass and
 * register their instance in the container. The defaults match v3
 * semantics exactly (including `double_encode: false` for text/attribute
 * contexts, so already-escaped snippets survive a render pass).
 */
class Escaper
{
    /**
     * @param (callable(string): string)|null $customHtmlEscaper replaces the
     *        built-in text escaper only — attribute/URL/JSON escaping always
     *        uses the built-ins so security never depends on user code
     */
    public function __construct(private readonly mixed $customHtmlEscaper = null)
    {
        if ($this->customHtmlEscaper !== null && !\is_callable($this->customHtmlEscaper)) {
            throw new \InvalidArgumentException('The custom HTML escaper must be callable.');
        }
    }

    /** HTML text context — the default for `%{VAR}` auto-escaping and `#echo`. */
    public function html(string $value): string
    {
        if ($this->customHtmlEscaper !== null) {
            return Str::stringify(($this->customHtmlEscaper)($value));
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    /** Attribute context — always the built-in encoder. */
    public function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    /**
     * URL-value escaping: `rawurlencode()` every character that is not
     * unreserved or a structural URL delimiter. `&` and `'` **are** encoded
     * because this sanitises values placed *into* URLs, not whole query
     * strings.
     */
    public function url(string $value): string
    {
        $result = preg_replace_callback(
            '/[^A-Za-z0-9\-._~:\/?#\[\]@!$()*+,;=%]/',
            static fn(array $m): string => rawurlencode($m[0]),
            $value,
        );

        return $result ?? $value;
    }

    /** JSON escaping safe for inline `<script>` contexts. */
    public function json(mixed $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE,
        );

        return $encoded === false ? 'null' : $encoded;
    }

    /**
     * Inline JavaScript string-literal escaping (alias semantics of `json`
     * for scalar values: quotes included, `<`/`&` hex-encoded).
     */
    public function js(mixed $value): string
    {
        return $this->json(\is_scalar($value) || $value === null ? $value : Str::stringify($value));
    }

    /** CSS-value escaping: every non-alphanumeric becomes `\HHHHHH `. */
    public function css(string $value): string
    {
        return (string) preg_replace_callback(
            '/[^a-zA-Z0-9]/',
            static fn(array $m): string => \sprintf('\\%06X ', \ord($m[0])),
            $value,
        );
    }

    /**
     * Recursively sanitises a nested input array for safe HTML output
     * (the v3 `sanitizeXSS()` semantics, `double_encode: true`).
     *
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    public function sanitizeArray(array $input): array
    {
        $sanitized = [];

        foreach ($input as $key => $value) {
            $sanitized[$key] = \is_array($value)
                ? $this->sanitizeArray($value)
                : htmlspecialchars(Str::stringify($value), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8', true);
        }

        return $sanitized;
    }

    /** Escapes by strategy name — the engine behind `|escape:'js'`. */
    public function byStrategy(string $strategy, mixed $value): string
    {
        $string = Str::stringify($value);

        return match (strtolower($strategy)) {
            'html', 'text', '' => $this->html($string),
            'attr', 'attribute', 'html_attr' => $this->attr($string),
            'url' => $this->url($string),
            'json' => $this->json($value),
            'js', 'javascript' => $this->js($value),
            'css' => $this->css($string),
            'none', 'raw' => $string,
            default => $this->html($string),
        };
    }
}
