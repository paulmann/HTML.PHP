<?php

declare(strict_types=1);

namespace HtmlPhp\Template;

/**
 * Internal control-flow signal for `<!--@Break-->` / `<!--@Continue-->`.
 *
 * @internal caught by {@see TreeRenderer}; never escapes the engine
 */
final class LoopSignal extends \Exception
{
    public function __construct(public readonly string $kind)
    {
        parent::__construct('loop-' . $kind);
    }
}
