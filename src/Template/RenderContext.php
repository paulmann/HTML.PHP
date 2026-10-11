<?php

declare(strict_types=1);

namespace HtmlPhp\Template;

use HtmlPhp\Config\MissingVarPolicy;
use HtmlPhp\Diagnostics;
use HtmlPhp\Scope;

/**
 * Mutable per-render state shared by the renderer and the tree walker:
 * effective options, counters, layout-inheritance overrides and the
 * section resolution stack.
 *
 * @internal created by {@see Renderer} for each render call
 */
final class RenderContext
{
    /**
     * Layout overrides: section name => stack of override bodies (child first).
     *
     * @var array<string, list<list<Node>>>
     */
    public array $overrides = [];

    /** Set when walking a child template that `@Extends` a layout. */
    public bool $collectMode = false;

    /** Template requested by the first `<!--@Extends-->` of the walk. */
    public ?string $extendsTemplate = null;

    /**
     * Section resolution stack for `<!--@SectionParent-->`.
     *
     * @var list<array{0: string, 1: int, 2: list<Node>}>
     */
    public array $sectionFrames = [];

    public int $loopDepth = 0;
    public int $extendsDepth = 0;

    /** File of the template currently being walked (partial anchors). */
    public ?string $currentFile = null;

    public bool $autoEscape;
    public MissingVarPolicy $missingVar;
    public bool $structure;

    public function __construct(
        public readonly Scope $scope,
        public readonly RenderStats $stats,
        public readonly Diagnostics $diagnostics,
        bool $autoEscape,
        MissingVarPolicy $missingVar,
        bool $structure
    ) {
        $this->autoEscape = $autoEscape;
        $this->missingVar = $missingVar;
        $this->structure = $structure;
    }

    /**
     * Registers a child override for a section (layout inheritance).
     *
     * @param list<Node> $nodes
     */
    public function pushOverride(string $name, array $nodes): void
    {
        $this->overrides[$name][] = $nodes;
    }

    /**
     * Nodes to render for a section at the given inheritance level:
     * the deepest override that exists, else the inline default body.
     *
     * @param list<Node> $defaultNodes
     * @return list<Node>
     */
    public function sectionNodes(string $name, int $level, array $defaultNodes): array
    {
        return $this->overrides[$name][$level] ?? $defaultNodes;
    }
}
