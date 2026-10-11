<?php

declare(strict_types=1);

namespace HtmlPhp\Template;

use HtmlPhp\Config\MissingVarPolicy;
use HtmlPhp\RequestContext;

/**
 * Per-call render overrides (all optional; `null` = take the engine config).
 *
 * Replaces the v3 `$options` array — typed, documented, IDE-friendly:
 *
 * ```php
 * $engine->renderFile('page.html', $vars, new RenderOptions(
 *     ssi: false,
 *     cache: true,
 *     ttl: 300,
 * ));
 * ```
 */
final readonly class RenderOptions
{
    public function __construct(
        /** Document path anchoring relative SSI includes and LAST_MODIFIED. */
        public ?string $file = null,
        /** Document-root override for SSI containment. */
        public ?string $root = null,
        /** Request environment for SSI built-ins (else CLI context). */
        public ?RequestContext $request = null,
        /** Run the SSI pass. */
        public ?bool $ssi = null,
        /** Process structure tags (loops/ifs/sections/partials). */
        public ?bool $structure = null,
        /** Auto-escape substitutions. */
        public ?bool $autoEscape = null,
        /** Missing-variable policy override. */
        public ?MissingVarPolicy $missingVar = null,
        /** Use the render cache for this call. */
        public ?bool $cache = null,
        /** Cache TTL override (seconds, 0 = backend default). */
        public ?int $ttl = null,
        /** Locale override for this render (translator switch, restored after). */
        public ?string $locale = null,
        /**
         * Extra variables visible to SSI `#if`/`#echo` ($NAME lookups).
         *
         * @var array<string, mixed>|null
         */
        public ?array $ssiVars = null
    ) {
    }

    /**
     * Convenience constructor from a v3-style options array (migration aid).
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        return new self(
            file: isset($options['file']) && is_string($options['file']) ? $options['file'] : null,
            root: isset($options['root']) && is_string($options['root']) ? $options['root'] : null,
            ssi: isset($options['ssi']) ? (bool) $options['ssi'] : null,
            structure: isset($options['loops']) ? (bool) $options['loops'] : (isset($options['structure']) ? (bool) $options['structure'] : null),
            autoEscape: isset($options['escape']) ? (bool) $options['escape'] : null,
            cache: isset($options['cache']) ? (bool) $options['cache'] : null,
            ttl: isset($options['ttl']) ? (int) $options['ttl'] : null,
            locale: isset($options['locale']) && is_string($options['locale']) ? $options['locale'] : null
        );
    }
}
