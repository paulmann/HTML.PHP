<?php

declare(strict_types=1);

namespace HtmlPhp\Template;

/**
 * One node of the parsed template tree.
 *
 * The parser turns the flat comment-tag syntax into a tree so loops,
 * conditionals, blocks and partials nest correctly by construction rather
 * than by balanced-regex luck. Node kinds are plain strings (no enum) to
 * keep the compiled/cached representation cheap.
 *
 * Kind catalog:
 *  - `text`      raw output (`value` = string)
 *  - `var`       `%{NAME|filter}` (`value` = name, `filters` = list, `raw` = bool)
 *  - `translate` `@{KEY}` (`value` = message id)
 *  - `loop`      (`name`, `body`, `else`, `maxRows`)
 *  - `if`        (`branches` = list of [expr, body], `else` = nodes)
 *  - `block`     inheritance slot (`name`, `body`)
 *  - `extends`   (`value` = parent template name)
 *  - `partial`   (`value` = template, `with`, `only`, `ignoreMissing`)
 *  - `set`       (`name`, `value` expression)
 *  - `trans`     (`msgid`, `plural`, `count`, `args`, `body` nodes)
 *  - `raw`       verbatim, unprocessed (`value`)
 *  - `comment`   dropped
 */
final class Node
{
    /**
     * @param array<string, mixed> $attributes kind-specific payload
     * @param list<Node> $children
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $attributes = [],
        public readonly array $children = [],
    ) {}

    /** @param list<Node> $children */
    public function withChildren(array $children): self
    {
        return new self($this->kind, $this->attributes, $children);
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
