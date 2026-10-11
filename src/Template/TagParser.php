<?php

declare(strict_types=1);

namespace HtmlPhp\Template;

use HtmlPhp\Exception\TemplateSyntaxException;
use HtmlPhp\Ssi\Attributes;

/**
 * Parses the comment-tag template syntax into a {@see Node} tree.
 *
 * Recognised tags (`<!--@Name … -->`):
 *
 * | Tag | Form | Notes |
 * |---|---|---|
 * | Loop     | `<!--@Loop rows-->`, `<!--@Loop item in rows-->`, `<!--@Loop k, v in map-->`, `<!--@Loop i in 1..10-->` | `<!--@Else-->` = empty-iterable branch, `<!--@Break-->`/`<!--@Continue-->` honoured, `<!--@EndLoop rows-->` |
 * | If       | `<!--@If EXPR-->` | `<!--@Elif EXPR-->`, `<!--@Else-->`, `<!--@EndIf-->`; EXPR is the safe expression language |
 * | Unless   | `<!--@Unless EXPR-->` | sugar for `If !EXPR` |
 * | Section  | `<!--@Section name-->` | layout slot; override via child template, `<!--@SectionParent-->` inserts the parent default |
 * | Extends  | `<!--@Extends "base.html"-->` | layout inheritance (multi-level) |
 * | Include  | `<!--@Include "card.html" with="a,b" only ignore-missing-->` | scoped partial |
 * | Set      | `<!--@Set total="price * qty"-->` | writes into the current scope frame |
 * | Trans    | `<!--@Trans count="%{n}" domain="d" context="c"-->one<!--@Plural-->%{n} many<!--@EndTrans-->` | gettext-plural block |
 * | Raw      | `<!--@Raw-->…<!--@EndRaw-->` | verbatim |
 * | Comment  | `<!--@Comment-->…<!--@EndComment-->` | dropped |
 * | Break / Continue | `<!--@Break-->`, `<!--@Continue-->` | inside loops |
 *
 * Everything between tags becomes `text` nodes whose raw source is later
 * run through {@see Substitution} (`%{NAME|filters}`, `#{NAME}`, `@{KEY}`).
 */
final class TagParser
{
    /**
     * Tag scanner: name + payload up to the first `-->`.
     *
     * The payload is deliberately permissive (`.*?`): `<!--@If count > 2-->`
     * carries a bare `>` that a quote-aware character class would choke on.
     * Consequence: a literal `-->` inside a quoted attribute value ends the
     * tag early — documented, and the linter flags the fallout.
     */
    private const string TAG_PATTERN = '~<!--@([A-Za-z][A-Za-z0-9]*)(.*?)-->~s';

    private const array OPENERS = ['loop', 'if', 'unless', 'section', 'trans'];
    private const array CLOSERS = [
        'endloop' => 'loop', 'endif' => 'if', 'endunless' => 'unless',
        'endsection' => 'section', 'endtrans' => 'trans',
    ];

    private string $source = '';
    private string $origin = '-';

    /**
     * @return list<Node>
     * @throws TemplateSyntaxException on unbalanced/unknown structure
     */
    public function parse(string $source, string $origin = '-'): array
    {
        $this->source = $source;
        $this->origin = $origin;

        if (!str_contains($source, '<!--@')) {
            return $source === '' ? [] : [self::text($source)];
        }

        $tokens = $this->tokenize($source);
        $root = $this->buildTree($tokens);

        return $root;
    }

    // --------------------------------------------------------------- tokenize

    /**
     * @return list<array{name: string, payload: string, start: int, end: int}>
     */
    private function tokenize(string $source): array
    {
        $tokens = [];
        $count = preg_match_all(self::TAG_PATTERN, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        if ($count === false) {
            throw new TemplateSyntaxException('Template tag scan failed.', ['file' => $this->origin]);
        }

        foreach ($matches as $match) {
            $start = (int) $match[0][1];
            $tokens[] = [
                'name' => strtolower((string) $match[1][0]),
                'payload' => trim((string) ($match[2][0] ?? '')),
                'start' => $start,
                'end' => $start + strlen((string) $match[0][0]),
            ];
        }

        return $tokens;
    }

    // ------------------------------------------------------------------ tree

    /**
     * @param list<array{name: string, payload: string, start: int, end: int}> $tokens
     * @return list<Node>
     */
    private function buildTree(array $tokens): array
    {
        /** @var list<array{type: string, payload: string, children: list<Node>, branches: list<array{expr: ?string, nodes: list<Node>}>, else: list<Node>, inElse: bool, start: int}> $stack */
        $stack = [];
        $children = [];
        $cursor = 0;
        $skipTo = 0;

        $flushText = function (int $from, int $to) use (&$stack, &$children): void {
            if ($to <= $from) {
                return;
            }

            $slice = substr($this->source, $from, $to - $from);

            if ($slice === '') {
                return;
            }

            if ($stack === []) {
                $children[] = self::text($slice);

                return;
            }

            $top = &$stack[count($stack) - 1];

            if ($top['inElse']) {
                $top['else'][] = self::text($slice);
            } elseif ($top['branches'] !== [] && ($top['type'] === 'if' || $top['type'] === 'unless')) {
                $last = count($top['branches']) - 1;
                $top['branches'][$last]['nodes'][] = self::text($slice);
            } else {
                $top['children'][] = self::text($slice);
            }

            unset($top);
        };

        $appendNode = function (Node $node) use (&$stack, &$children): void {
            if ($stack === []) {
                $children[] = $node;

                return;
            }

            $top = &$stack[count($stack) - 1];

            if ($top['inElse']) {
                $top['else'][] = $node;
            } elseif ($top['branches'] !== [] && ($top['type'] === 'if' || $top['type'] === 'unless')) {
                $last = count($top['branches']) - 1;
                $top['branches'][$last]['nodes'][] = $node;
            } else {
                $top['children'][] = $node;
            }

            unset($top);
        };

        $count = count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];

            if ($token['start'] < $skipTo) {
                continue; // inside a Raw/Comment slice
            }

            $flushText($cursor, $token['start']);
            $cursor = $token['end'];
            $name = $token['name'];
            $payload = $token['payload'];

            // ---- verbatim regions -------------------------------------
            if ($name === 'raw' || $name === 'comment') {
                $endTag = '<!--@End' . ($name === 'raw' ? 'Raw' : 'Comment');
                $close = $this->findCi($endTag, $token['end']);

                if ($close === null) {
                    throw $this->error('Unclosed <!--@' . ucfirst($name) . '--> block.', $token['start']);
                }

                $closeEnd = strpos($this->source, '-->', $close);
                $closeEnd = $closeEnd === false ? strlen($this->source) : $closeEnd + 3;

                if ($name === 'raw') {
                    $appendNode(new Node('raw', [
                        'value' => substr($this->source, $token['end'], $close - $token['end']),
                    ]));
                }

                $skipTo = $closeEnd;
                $cursor = $closeEnd;
                continue;
            }

            // ---- openers ----------------------------------------------
            if (in_array($name, self::OPENERS, true)) {
                if ($name === 'trans' && $stack !== [] && $stack[count($stack) - 1]['type'] === 'trans') {
                    throw $this->error('Nested <!--@Trans--> blocks are not allowed.', $token['start']);
                }

                $stack[] = [
                    'type' => $name,
                    'payload' => $payload,
                    'children' => [],
                    'branches' => $name === 'if' || $name === 'unless'
                        ? [['expr' => $name === 'unless' ? self::negate($payload) : $payload, 'nodes' => []]]
                        : [],
                    'else' => [],
                    'inElse' => false,
                    'start' => $token['start'],
                ];
                continue;
            }

            // ---- closers ----------------------------------------------
            if (isset(self::CLOSERS[$name])) {
                $expected = self::CLOSERS[$name];

                if ($stack === [] || $stack[count($stack) - 1]['type'] !== $expected) {
                    throw $this->error(
                        'Unexpected <!--@' . $name . '-->'
                        . ($stack === [] ? ' without an opener.' : ' inside <!--@' . $stack[count($stack) - 1]['type'] . '-->.'),
                        $token['start']
                    );
                }

                $frame = array_pop($stack);
                $appendNode($this->frameToNode($frame, $token['start']));
                continue;
            }

            // ---- mid markers -------------------------------------------
            if ($name === 'else' || $name === 'elif' || $name === 'plural') {
                if ($stack === []) {
                    throw $this->error('Unexpected <!--@' . ucfirst($name) . '--> without an opener.', $token['start']);
                }

                $top = &$stack[count($stack) - 1];

                if ($name === 'plural') {
                    if ($top['type'] !== 'trans') {
                        throw $this->error('<!--@Plural--> is only valid inside <!--@Trans-->.', $token['start']);
                    }

                    $top['inElse'] = true; // plural body reuses the else slot
                    unset($top);
                    continue;
                }

                if ($top['type'] === 'if' || $top['type'] === 'unless') {
                    if ($name === 'else') {
                        $top['inElse'] = true;
                    } else {
                        if ($top['inElse']) {
                            throw $this->error('<!--@Elif--> after <!--@Else-->.', $token['start']);
                        }

                        $top['branches'][] = ['expr' => $payload, 'nodes' => []];
                    }
                } elseif ($top['type'] === 'loop') {
                    if ($name !== 'else') {
                        throw $this->error('<!--@Elif--> is not valid inside <!--@Loop-->.', $token['start']);
                    }

                    $top['inElse'] = true;
                } else {
                    throw $this->error('<!--@' . ucfirst($name) . '--> is not valid inside <!--@' . $top['type'] . '-->.', $token['start']);
                }

                unset($top);
                continue;
            }

            // ---- standalone --------------------------------------------
            $node = $this->standaloneNode($name, $payload, $token['start']);

            if ($node !== null) {
                $appendNode($node);
                continue;
            }

            throw $this->error('Unknown template tag "' . $name . '".', $token['start']);
        }

        $flushText($cursor, strlen($this->source));

        if ($stack !== []) {
            $frame = $stack[count($stack) - 1];
            throw $this->error('Unclosed <!--@' . ucfirst((string) $frame['type']) . '--> block.', (int) $frame['start']);
        }

        return $children;
    }

    /**
     * @param array{type: string, payload: string, children: list<Node>, branches: list<array{expr: ?string, nodes: list<Node>}>, else: list<Node>, inElse: bool, start: int} $frame
     */
    private function frameToNode(array $frame, int $offset): Node
    {
        $line = $this->line($offset);

        return match ($frame['type']) {
            'loop' => new Node('loop', [
                'spec' => self::parseLoopSpec($frame['payload'], $line),
                'else' => $frame['else'],
                'line' => $line,
            ], $frame['children']),
            'if', 'unless' => new Node('if', [
                'branches' => $frame['branches'],
                'else' => $frame['else'],
                'line' => $line,
            ]),
            'section' => new Node('section', [
                'name' => self::bareName($frame['payload'], $line),
                'line' => $line,
            ], $frame['children']),
            'trans' => new Node('trans', [
                'attrs' => Attributes::parse($frame['payload']),
                'singular' => $frame['children'],
                'plural' => $frame['else'],
                'line' => $line,
            ]),
            default => throw $this->error('Internal parser error for frame "' . $frame['type'] . '".', $offset),
        };
    }

    private function standaloneNode(string $name, string $payload, int $offset): ?Node
    {
        $line = $this->line($offset);

        return match ($name) {
            'extends' => new Node('extends', ['template' => self::bareName($payload, $line), 'line' => $line]),
            'sectionparent' => new Node('section-parent', ['line' => $line]),
            'include' => self::includeNode($payload, $line),
            'set' => self::setNode($payload, $line),
            'break' => new Node('break', ['line' => $line]),
            'continue' => new Node('continue', ['line' => $line]),
            default => null,
        };
    }

    private static function includeNode(string $payload, int $line): Node
    {
        $flags = [];

        foreach (['ignore-missing', 'ignore_missing', 'only', 'missing'] as $flag) {
            if (preg_match('~(?:^|\s)' . preg_quote($flag, '~') . '(?:\s|$)~i', $payload) === 1) {
                $flags[str_replace('_', '-', $flag)] = true;
                $payload = trim((string) preg_replace('~(?:^|\s)' . preg_quote($flag, '~') . '(?:\s|$)~i', ' ', $payload));
            }
        }

        $attributes = Attributes::parse($payload);

        // The template reference is either quoted or the leftover bare word.
        $template = '';

        if (preg_match('~^(?:"([^"]*)"|\'([^\']*)\')~', $payload, $m) === 1) {
            $template = (string) ($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
        } else {
            $leftover = trim(preg_replace('~[A-Za-z_][A-Za-z0-9_-]*\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\']+)~', ' ', $payload) ?? $payload);
            $template = trim($leftover, " \t\n\r\"'");
        }

        $with = isset($attributes['with']) && $attributes['with'] !== ''
            ? array_values(array_filter(array_map(
                static fn(string $part): string => trim($part),
                explode(',', $attributes['with'])
            ), static fn(string $part): bool => $part !== ''))
            : null;

        return new Node('include', [
            'template' => $template,
            'with' => $with,
            'only' => isset($flags['only']),
            'ignoreMissing' => isset($flags['ignore-missing']) || isset($flags['missing']),
            'line' => $line,
        ]);
    }

    private static function setNode(string $payload, int $line): Node
    {
        $attributes = Attributes::parse($payload);

        if (isset($attributes['name']) || isset($attributes['var'])) {
            return new Node('set', [
                'name' => (string) ($attributes['name'] ?? $attributes['var']),
                'expr' => (string) ($attributes['value'] ?? ''),
                'line' => $line,
            ]);
        }

        // `<!--@Set total="price * qty" -->` — single name="expr" attribute.
        if (count($attributes) === 1) {
            $name = (string) array_key_first($attributes);

            if (preg_match('~^[A-Za-z_][A-Za-z0-9_.]*$~', $name) === 1) {
                return new Node('set', ['name' => $name, 'expr' => (string) $attributes[$name], 'line' => $line]);
            }
        }

        // `<!--@Set total = price * qty -->` — bare form.
        if (preg_match('~^([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.+)$~s', $payload, $m) === 1) {
            return new Node('set', ['name' => $m[1], 'expr' => trim($m[2]), 'line' => $line]);
        }

        throw new TemplateSyntaxException(
            '<!--@Set--> requires name="expression" syntax.',
            ['payload' => $payload, 'line' => $line]
        );
    }

    /**
     * Parses the four loop header forms.
     *
     * @return array{mode: string, source: string, value: ?string, key: ?string, to: ?string, line: int}
     */
    public static function parseLoopSpec(string $payload, int $line = 0): array
    {
        $payload = trim($payload);

        if ($payload === '') {
            throw new TemplateSyntaxException('<!--@Loop--> requires an iterable name.', ['line' => $line]);
        }

        // "k, v in source" | "v in source" | "i in 1..10"
        if (preg_match('~^(?:([A-Za-z_][A-Za-z0-9_.]*)\s*,\s*)?([A-Za-z_][A-Za-z0-9_.]*)\s+in\s+(.+)$~is', $payload, $m) === 1) {
            $source = trim((string) $m[3]);

            if (preg_match('~^(-?\d+)\s*\.\.\s*(-?\d+)$~', $source, $range) === 1) {
                return [
                    'mode' => 'range', 'source' => $range[1], 'value' => (string) $m[2],
                    'key' => $m[1] !== '' ? (string) $m[1] : null, 'to' => $range[2], 'line' => $line,
                ];
            }

            return [
                'mode' => $m[1] !== '' ? 'kv' : 'alias',
                'source' => $source,
                'value' => (string) $m[2],
                'key' => $m[1] !== '' ? (string) $m[1] : null,
                'to' => null,
                'line' => $line,
            ];
        }

        // range without alias is not valid; plain iterable name:
        if (preg_match('~^[A-Za-z_][A-Za-z0-9_.]*$~', $payload) === 1) {
            return ['mode' => 'rows', 'source' => $payload, 'value' => null, 'key' => null, 'to' => null, 'line' => $line];
        }

        throw new TemplateSyntaxException(
            'Cannot parse <!--@Loop--> header.',
            ['payload' => $payload, 'line' => $line]
        );
    }

    private static function bareName(string $payload, int $line): string
    {
        $payload = trim($payload);

        if (preg_match('~^(?:"([^"]*)"|\'([^\']*)\')$~', $payload, $m) === 1) {
            $payload = (string) ($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
        }

        $payload = trim($payload);

        if ($payload === '' || preg_match('~^[\w.@/-]+$~', $payload) !== 1) {
            throw new TemplateSyntaxException('Expected a quoted template name or identifier.', [
                'payload' => $payload, 'line' => $line,
            ]);
        }

        return $payload;
    }

    private static function negate(string $expression): string
    {
        $expression = trim($expression);

        return str_starts_with($expression, '!') ? substr($expression, 1) : '!' . $expression;
    }

    private static function text(string $value): Node
    {
        return new Node('text', ['value' => $value]);
    }

    private function findCi(string $needle, int $offset): ?int
    {
        $position = stripos($this->source, $needle, $offset);

        return $position === false ? null : $position;
    }

    private function line(int $offset): int
    {
        return substr_count($this->source, "\n", 0, max(0, min($offset, strlen($this->source)))) + 1;
    }

    private function error(string $message, int $offset): TemplateSyntaxException
    {
        return new TemplateSyntaxException($message, [
            'file' => $this->origin,
            'line' => $this->line($offset),
        ]);
    }
}
