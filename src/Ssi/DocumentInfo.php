<?php

declare(strict_types=1);

namespace HtmlPhp\Ssi;

use HtmlPhp\RequestContext;

/**
 * Identity of the document being processed.
 *
 * Anchors relative `file=` includes, `LAST_MODIFIED`, `DOCUMENT_URI` and the
 * containment root. Built by the caller (Engine, WordPress bridge, CLI) —
 * the SSI layer never reaches for `$_SERVER` itself.
 *
 * @param array<string, mixed> $vars extra variables visible to `#if`/`#echo`
 *        (the Engine injects the current render scope here)
 */
final readonly class DocumentInfo
{
    /**
     * @param array<string, mixed> $vars
     */
    public function __construct(
        public ?string $file = null,
        public ?string $root = null,
        public ?RequestContext $request = null,
        public array $vars = []
    ) {
    }

    /** Document for a plain string render (no file on disk). */
    public static function forString(?string $root = null, ?RequestContext $request = null): self
    {
        return new self(null, $root, $request);
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function withVars(array $vars): self
    {
        return new self($this->file, $this->root, $this->request, $vars);
    }
}
