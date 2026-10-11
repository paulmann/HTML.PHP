<?php

declare(strict_types=1);

namespace HtmlPhp\Template;

use HtmlPhp\Config;
use HtmlPhp\Diagnostics;
use HtmlPhp\Exception\TemplateException;
use HtmlPhp\Exception\TemplateNotFoundException;
use HtmlPhp\I18n\TranslatorInterface;
use HtmlPhp\Scope;
use HtmlPhp\Support\Str;

/**
 * Walks the parsed {@see Node} tree and produces output.
 *
 * Handles loops (four header forms, magic variables, `@Else`, `@Break`,
 * `@Continue`, row/depth guards), expression conditionals, layout sections
 * (with `@SectionParent`), scoped partials, `@Set` and `@Trans` blocks.
 * Text nodes are delegated to {@see Substitution}.
 */
final class TreeRenderer
{
    public function __construct(
        private readonly Config $config,
        private readonly Diagnostics $diagnostics,
        private readonly Substitution $substitution,
        private readonly TemplateLocator $locator,
        private readonly Renderer $renderer,
        private readonly TranslatorInterface $translator
    ) {
    }

    /**
     * @param list<Node> $nodes
     */
    public function render(array $nodes, RenderContext $rc): string
    {
        $output = '';

        foreach ($nodes as $node) {
            $output .= $this->renderNode($node, $rc);
        }

        return $output;
    }

    private function renderNode(Node $node, RenderContext $rc): string
    {
        return match ($node->kind) {
            'text' => $this->renderText((string) $node->attribute('value', ''), $rc),
            'raw' => (string) $node->attribute('value', ''),
            'loop' => $this->renderLoop($node, $rc),
            'if' => $this->renderIf($node, $rc),
            'section' => $this->renderSection($node, $rc),
            'section-parent' => $this->renderSectionParent($rc),
            'extends' => $this->registerExtends($node, $rc),
            'include' => $this->renderInclude($node, $rc),
            'set' => $this->renderSet($node, $rc),
            'trans' => $this->renderTrans($node, $rc),
            'break' => throw new LoopSignal('break'),
            'continue' => throw new LoopSignal('continue'),
            default => throw new TemplateException('Unknown node kind.', ['kind' => $node->kind]),
        };
    }

    private function renderText(string $text, RenderContext $rc): string
    {
        if ($text === '') {
            return '';
        }

        if ($rc->collectMode) {
            return ''; // child-of-layout: only sections contribute output
        }

        $rc->stats->substitutions++;

        return $this->substitution->process($text, $rc->scope, $rc->autoEscape, $rc->missingVar);
    }

    // ------------------------------------------------------------------ loops

    private function renderLoop(Node $node, RenderContext $rc): string
    {
        /** @var array{mode: string, source: string, value: ?string, key: ?string, to: ?string, line: int} $spec */
        $spec = $node->attribute('spec');

        if ($rc->loopDepth >= $this->config->limits->maxLoopDepth) {
            $this->diagnostics->record(
                Diagnostics::LOOP_GUARD,
                'Loop nesting is too deep; block skipped.',
                ['line' => $spec['line'] ?? 0]
            );

            return '';
        }

        $rc->stats->loops++;

        $rows = $this->loopIterable($spec, $rc);
        $total = count($rows);

        if ($total === 0) {
            $else = $node->attribute('else', []);

            return $rc->collectMode ? '' : $this->render($else, $rc);
        }

        $output = '';
        $maxRows = $this->config->limits->maxLoopRows;
        $truncated = false;
        $index = 0;
        $rc->loopDepth++;

        try {
            foreach ($rows as $key => $row) {
                if ($index >= $maxRows) {
                    $truncated = true;
                    break;
                }

                $frame = $this->loopFrame($spec, $key, $row, $index, $total);
                $rc->scope->push($frame);
                $rc->stats->loopRows++;

                try {
                    $output .= $this->render($node->children, $rc);
                } catch (LoopSignal $signal) {
                    if ($signal->kind === 'break') {
                        $rc->scope->pop();
                        break;
                    }
                    // continue: fall through to pop + next iteration
                }

                $rc->scope->pop();
                $index++;
            }
        } finally {
            $rc->loopDepth--;
        }

        if ($truncated) {
            $this->diagnostics->record(
                Diagnostics::LOOP_GUARD,
                sprintf('Loop truncated at the configured row limit (%d).', $maxRows),
                ['source' => $spec['source'], 'limit' => $maxRows]
            );
        }

        return $output;
    }

    /**
     * Materialises the iterable described by the loop spec.
     *
     * @param array{mode: string, source: string, value: ?string, key: ?string, to: ?string} $spec
     * @return array<array-key, mixed>
     */
    private function loopIterable(array $spec, RenderContext $rc): array
    {
        if ($spec['mode'] === 'range') {
            $from = (int) Str::numericValue($spec['source']);
            $to = (int) Str::numericValue((string) $spec['to']);

            // Generate at most maxLoopRows+1 values so the iteration guard
            // performs the (diagnosed) truncation uniformly for all modes.
            $limit = $this->config->limits->maxLoopRows;
            $step = $from <= $to ? 1 : -1;
            $cappedTo = abs($to - $from) > $limit ? $from + $step * $limit : $to;

            return range($from, $cappedTo, $step);
        }

        $source = $spec['source'];

        // The source may be a dotted name or a small expression.
        $value = strpbrk($source, ' +-*~/()') !== false
            ? Expression::evaluate($source, $rc->scope, $this->config->limits->maxExpressionLength)
            : ($rc->scope->find($source)[0] ? $rc->scope->get($source) : null);

        if ($value === null) {
            if (!$rc->scope->has($source)) {
                $this->diagnostics->record(
                    Diagnostics::MISSING_VAR,
                    'Loop source is not defined; treated as empty.',
                    ['name' => $source]
                );
            }

            return [];
        }

        return self::normalizeIterable($value);
    }

    /**
     * Arrays, Countable/Traversable objects, public-property objects and
     * scalars (single-row loops, exposed as `value`) all iterate.
     *
     * @return array<array-key, mixed>
     */
    public static function normalizeIterable(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        if ($value instanceof \ArrayAccess && $value instanceof \Countable) {
            $items = [];
            $total = count($value);

            for ($index = 0; $index < $total; ++$index) {
                $items[$index] = $value[$index];
            }

            return $items;
        }

        if (is_object($value)) {
            return get_object_vars($value);
        }

        if ($value === null || $value === '') {
            return [];
        }

        return [0 => $value];
    }

    /**
     * Builds the scope frame for one row: aliases per spec mode, row fields
     * (rows mode, v3-compatible shadowing) and the magic variables.
     *
     * @param array{mode: string, source: string, value: ?string, key: ?string, to: ?string} $spec
     * @return array<string, mixed>
     */
    private function loopFrame(array $spec, string|int $key, mixed $row, int $index, int $total): array
    {
        $frame = [];

        if ($spec['mode'] === 'rows') {
            // v3 semantics: the row's own fields enter the scope directly.
            if (is_array($row) || is_object($row)) {
                foreach (self::normalizeIterable($row) as $name => $field) {
                    $frame[(string) $name] = $field;
                }
            }

            $frame['value'] = $row;
        } else {
            if ($spec['value'] !== null) {
                $frame[$spec['value']] = $row;
            }

            if ($spec['key'] !== null) {
                $frame[$spec['key']] = $key;
            }
        }

        $first = $index === 0;
        $last = $index === $total - 1;

        $frame['_index'] = $index;
        $frame['_position'] = $index + 1;
        $frame['_key'] = $key;
        $frame['_first'] = $first;
        $frame['_last'] = $last;
        $frame['_count'] = $total;
        $frame['_odd'] = $index % 2 === 1;
        $frame['_even'] = $index % 2 === 0;
        $frame['loop'] = [
            'index' => $index + 1,
            'index0' => $index,
            'first' => $first,
            'last' => $last,
            'length' => $total,
            'key' => $key,
        ];

        return $frame;
    }

    // ------------------------------------------------------------ conditionals

    private function renderIf(Node $node, RenderContext $rc): string
    {
        if ($rc->collectMode) {
            return '';
        }

        $rc->stats->conditionals++;

        /** @var list<array{expr: ?string, nodes: list<Node>}> $branches */
        $branches = $node->attribute('branches', []);

        foreach ($branches as $branch) {
            $expression = trim((string) ($branch['expr'] ?? ''));

            if ($expression === '' || $this->branchTruthy($expression, $rc)) {
                return $this->render($branch['nodes'], $rc);
            }
        }

        /** @var list<Node> $else */
        $else = $node->attribute('else', []);

        return $this->render($else, $rc);
    }

    private function branchTruthy(string $expression, RenderContext $rc): bool
    {
        try {
            return Expression::truthy(
                Expression::evaluate($expression, $rc->scope, $this->config->limits->maxExpressionLength)
            );
        } catch (TemplateException $error) {
            if ($this->config->syntax->strict) {
                throw $error;
            }

            $this->diagnostics->record(
                Diagnostics::TEMPLATE_LINT,
                'Invalid condition expression; branch skipped: ' . $error->getMessage(),
                ['expression' => $expression]
            );

            return false;
        }
    }

    // ------------------------------------------------------- layout sections

    private function renderSection(Node $node, RenderContext $rc): string
    {
        $name = (string) $node->attribute('name', '');

        if ($rc->collectMode) {
            $rc->pushOverride($name, $node->children);
            $rc->stats->sections++;

            return '';
        }

        return $this->renderSectionLevel($name, 0, $node->children, $rc);
    }

    /**
     * @param list<Node> $defaultNodes
     */
    private function renderSectionLevel(string $name, int $level, array $defaultNodes, RenderContext $rc): string
    {
        $nodes = $rc->sectionNodes($name, $level, $defaultNodes);

        $rc->sectionFrames[] = [$name, $level, $defaultNodes];
        $rc->stats->sections++;

        try {
            return $this->render($nodes, $rc);
        } finally {
            array_pop($rc->sectionFrames);
        }
    }

    private function renderSectionParent(RenderContext $rc): string
    {
        $frame = $rc->sectionFrames[array_key_last($rc->sectionFrames)] ?? null;

        if ($frame === null) {
            $this->diagnostics->record(
                Diagnostics::TEMPLATE_LINT,
                '<!--@SectionParent--> used outside of a section.'
            );

            return '';
        }

        [$name, $level, $defaultNodes] = $frame;

        return $this->renderSectionLevel($name, $level + 1, $defaultNodes, $rc);
    }

    private function registerExtends(Node $node, RenderContext $rc): string
    {
        if ($rc->extendsTemplate === null) {
            $rc->extendsTemplate = (string) $node->attribute('template', '');
        }

        return '';
    }

    // --------------------------------------------------------------- partials

    private function renderInclude(Node $node, RenderContext $rc): string
    {
        if ($rc->collectMode) {
            return '';
        }

        $template = (string) $node->attribute('template', '');
        $ignoreMissing = (bool) $node->attribute('ignoreMissing', false);
        $only = (bool) $node->attribute('only', false);
        /** @var list<string>|null $with */
        $with = $node->attribute('with');

        $baseDir = $rc->currentFile !== null ? dirname($rc->currentFile) : null;
        $path = $this->locator->locate($template, $baseDir);

        if ($path === null) {
            if ($ignoreMissing) {
                return '';
            }

            throw new TemplateNotFoundException(
                "Partial template '{$template}' could not be located.",
                ['template' => $template, 'line' => $node->attribute('line')]
            );
        }

        $isolated = $only || $with !== null;
        $frame = [];

        if (!$isolated) {
            $frame = $rc->scope->all();
        } elseif ($with !== null) {
            foreach ($with as $name) {
                [$found, $value] = $rc->scope->find($name);

                if ($found) {
                    $frame[$name] = $value;
                }
            }
        }

        $rc->stats->includes++;

        return $this->renderer->renderPartial($path, $frame, $rc, $isolated);
    }

    // ------------------------------------------------------------------- set

    private function renderSet(Node $node, RenderContext $rc): string
    {
        $name = (string) $node->attribute('name', '');
        $expression = (string) $node->attribute('expr', '');

        if ($name === '' || !Str::isValidVariableName($name)) {
            $this->diagnostics->record(
                Diagnostics::TEMPLATE_LINT,
                '<!--@Set--> target name is invalid.',
                ['name' => $name]
            );

            return '';
        }

        $value = Expression::evaluate($expression, $rc->scope, $this->config->limits->maxExpressionLength);
        $rc->scope->set($name, $value);

        return '';
    }

    // ----------------------------------------------------------------- trans

    private function renderTrans(Node $node, RenderContext $rc): string
    {
        if ($rc->collectMode) {
            return '';
        }

        /** @var list<Node> $singular */
        $singular = $node->attribute('singular', []);
        /** @var list<Node> $plural */
        $plural = $node->attribute('plural', []);
        /** @var array<string, string> $attrs */
        $attrs = $node->attribute('attrs', []);

        [$msgid, $args] = $this->msgidOf($singular, $rc);

        if ($msgid === null) {
            // Non-text content inside @Trans: render as a normal fragment.
            return $this->render($singular, $rc);
        }

        $domain = $attrs['domain'] ?? null;
        $context = $attrs['context'] ?? '';
        $rc->stats->translations++;

        if (isset($attrs['count']) && $attrs['count'] !== '' && $plural !== []) {
            [$pluralId] = $this->msgidOf($plural, $rc);
            $count = $this->countOf($attrs['count'], $rc);
            $args['count'] = Str::stringify($count);

            // The translator interpolates {0}, {1}, … and {count} itself.
            return $context !== ''
                ? $this->translator->nx($msgid, (string) $pluralId, $count, $context, $args, $domain)
                : $this->translator->n($msgid, (string) $pluralId, $count, $args, $domain);
        }

        return $context !== ''
            ? $this->translator->x($msgid, $context, $args, $domain)
            : $this->translator->t($msgid, $args, $domain);
    }

    /**
     * Derives the msgid and positional arguments from a trans body:
     * `%{expr}`/`#{expr}` become `{n}` placeholders, evaluated against the
     * current scope. Returns `null` when the body contains structure tags
     * (which cannot be part of a gettext message id).
     *
     * @param list<Node> $nodes
     * @return array{0: string|null, 1: array<array-key, string>}
     */
    private function msgidOf(array $nodes, RenderContext $rc): array
    {
        $msgid = '';
        $args = [];
        $index = 0;

        foreach ($nodes as $node) {
            if ($node->kind !== 'text') {
                return [null, []];
            }

            $text = (string) $node->attribute('value', '');

            $text = \HtmlPhp\Support\Pcre::replaceCallback(
                '~[%#]\{([^{}]*)\}~',
                function (array $m) use ($rc, &$args, &$index): string {
                    $payload = trim((string) $m[1]);
                    $placeholder = '{' . $index . '}';

                    $rendered = $this->substitution->process(
                        '%{' . $payload . '}',
                        $rc->scope,
                        false,
                        $rc->missingVar
                    );

                    // Keys are the bare indices; Translator::interpolate()
                    // wraps them into `{0}`, `{1}`, … placeholders.
                    $args[(string) $index] = $rc->autoEscape
                        ? $this->renderer->escaper()->html($rendered)
                        : $rendered;
                    $index++;

                    return $placeholder;
                },
                $text
            );

            $msgid .= $text;
        }

        return [trim((string) preg_replace('~\s+~', ' ', $msgid)), $args];
    }

    private function countOf(string $attribute, RenderContext $rc): int
    {
        if (str_contains($attribute, '{')) {
            $rendered = $this->substitution->process($attribute, $rc->scope, false, $rc->missingVar);

            return (int) Str::numericValue($rendered);
        }

        $value = Expression::evaluate($attribute, $rc->scope, $this->config->limits->maxExpressionLength);

        return (int) Str::numericValue($value);
    }

}
