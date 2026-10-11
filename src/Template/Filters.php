<?php

declare(strict_types=1);

namespace HtmlPhp\Template;

use HtmlPhp\Diagnostics;
use HtmlPhp\Event\Dispatcher;
use HtmlPhp\Exception\TemplateException;
use HtmlPhp\Html\Escaper;
use HtmlPhp\I18n\Formats;
use HtmlPhp\I18n\TranslatorInterface;
use HtmlPhp\Support\Str;

/**
 * The filter registry behind `%{NAME|filter:arg|filter2}`.
 *
 * Instance-based in v4 (v3 kept custom filters in a static map). Filters
 * receive and return `mixed` — array/object-aware filters (`join`, `first`,
 * `length`, …) are first-class citizens; the final stringification happens
 * once, in {@see Substitution}.
 *
 * Register your own: `$engine->filters()->add('shout', fn($v) => strtoupper((string) $v) . '!');`
 */
final class Filters
{
    /** @var array<string, callable(mixed, list<string>): mixed> */
    private array $custom = [];

    public function __construct(
        private readonly Diagnostics $diagnostics,
        private readonly Dispatcher $events,
        private readonly Escaper $escaper,
        private readonly TranslatorInterface $translator,
        private readonly Formats $formats,
        private readonly bool $strict = false,
    ) {}

    /** Registers (or replaces) a filter. Names are lower-cased `[a-z0-9_]+`. */
    public function add(string $name, callable $callback): void
    {
        $name = strtolower(trim($name));

        if ($name === '' || preg_match('~^[a-z0-9_]+$~', $name) !== 1) {
            throw new \InvalidArgumentException('Filter names must match [a-z0-9_]+.');
        }

        $this->custom[$name] = $callback;
    }

    public function has(string $name): bool
    {
        $name = strtolower($name);

        return isset($this->custom[$name]) || isset(self::BUILTIN[$name]);
    }

    /**
     * Every known filter name (built-ins + custom) — used by the linter.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_unique([...array_keys(self::BUILTIN), ...array_keys($this->custom)]));
    }

    public function remove(string $name): void
    {
        unset($this->custom[strtolower($name)]);
    }

    public function reset(): void
    {
        $this->custom = [];
    }

    /** Built-in name list (kept as a constant for cheap `has()` checks). */
    private const array BUILTIN = [
        'escape' => true, 'esc' => true, 'e' => true, 'attr' => true, 'url' => true,
        'json' => true, 'js' => true, 'css' => true, 'upper' => true, 'lower' => true,
        'ucfirst' => true, 'title' => true, 'words' => true, 'number' => true,
        'round' => true, 'cleanround' => true, 'int' => true, 'float' => true,
        'div' => true, 'abs' => true, 'date' => true, 'ago' => true, 'truncate' => true,
        'excerpt' => true, 'nl2br' => true, 'striptags' => true, 'slug' => true,
        'default' => true, 'raw' => true, 'pad' => true, 'repeat' => true,
        'translate' => true, 'join' => true, 'split' => true, 'length' => true,
        'first' => true, 'last' => true, 'reverse' => true, 'sort' => true,
        'unique' => true, 'slice' => true, 'replace' => true, 'trim' => true,
        'base64' => true, 'md5' => true, 'sha1' => true, 'keys' => true,
    ];

    /**
     * Applies one filter with its arguments.
     *
     * @param list<string> $args
     */
    public function apply(string $name, mixed $value, array $args = []): mixed
    {
        $name = strtolower(trim($name));

        $this->events->dispatch('htmlphp.filter.apply', $name, $args);

        if (isset($this->custom[$name])) {
            return ($this->custom[$name])($value, $args);
        }

        if (!isset(self::BUILTIN[$name])) {
            return $this->unknown($name, $value);
        }

        return $this->builtin($name, $value, $args);
    }

    /** @param list<string> $args */
    private function builtin(string $name, mixed $value, array $args): mixed
    {
        $string = Str::stringify($value);

        return match ($name) {
            'escape', 'esc', 'e' => $this->escaper->byStrategy($args[0] ?? 'html', $value),
            'attr' => $this->escaper->attr($string),
            'url' => $this->escaper->url($string),
            'json' => $this->escaper->json($value),
            'js' => $this->escaper->js($value),
            'css' => $this->escaper->css($string),
            'upper' => Str::upper($string),
            'lower' => Str::lower($string),
            'ucfirst' => Str::ucfirst($string),
            'title' => Str::words($string),
            'words' => str_word_count($string),
            'number' => isset($args[1]) || isset($args[2])
                ? Str::number(
                    Str::numericValue($value),
                    (int) ($args[0] ?? 2),
                    (string) ($args[1] ?? '.'),
                    (string) ($args[2] ?? ','),
                )
                : $this->formats->number(
                    Str::numericValue($value),
                    null,
                    isset($args[0]) ? (int) $args[0] : null,
                ),
            'round' => Str::round(Str::numericValue($value), $args[0] ?? 0),
            'cleanround' => Str::cleanRound(Str::numericValue($value), $args[0] ?? 0),
            'int' => (string) (int) round(Str::numericValue($value)),
            'float' => Str::stringify(Str::numericValue($value)),
            'div' => Str::stringify(Str::divide($value, $args[0] ?? 0)),
            'abs' => Str::stringify(abs(Str::numericValue($value))),
            'date' => $this->formats->date($value, $args[0] ?? null),
            'ago' => $this->formats->ago($value),
            'truncate' => Str::truncate($string, (int) ($args[0] ?? 80), $args[1] ?? '…'),
            'excerpt' => Str::truncate(strip_tags($string), (int) ($args[0] ?? 160), $args[1] ?? '…'),
            'nl2br' => nl2br($this->escaper->html($string)),
            'striptags' => strip_tags($string),
            'slug' => Str::slugify($string, $args[0] ?? '-'),
            'default' => $string !== '' ? $string : ($args[0] ?? ''),
            'raw' => $value,
            'pad' => str_pad(
                $string,
                (int) ($args[0] ?? 0),
                $args[1] ?? ' ',
                (int) ($args[2] ?? 0) === 1 ? STR_PAD_LEFT : STR_PAD_RIGHT,
            ),
            'repeat' => str_repeat($string, max(0, min(1000, (int) ($args[0] ?? 2)))),
            'translate' => $this->translator->t($string),
            'join' => self::isIterableValue($value)
                ? implode($args[0] ?? ', ', array_map(Str::stringify(...), self::toArray($value)))
                : $string,
            'split' => explode($args[0] ?? ',', $string),
            'length' => (string) self::lengthOf($value),
            'first' => Str::stringify(self::edge($value, true)),
            'last' => Str::stringify(self::edge($value, false)),
            'reverse' => self::isIterableValue($value)
                ? array_reverse(self::toArray($value))
                : implode('', array_reverse(preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY) ?: [])),
            'sort' => self::sorted($value, ($args[0] ?? '') !== ''),
            'unique' => self::isIterableValue($value)
                ? array_values(array_unique(self::toArray($value), SORT_REGULAR))
                : $string,
            'slice' => self::sliced($value, (int) ($args[0] ?? 0), isset($args[1]) ? (int) $args[1] : null),
            'replace' => str_replace($args[0] ?? '', $args[1] ?? '', $string),
            'trim' => $args === [] ? trim($string) : trim($string, (string) $args[0]),
            'base64' => base64_encode($string),
            'md5' => md5($string),
            'sha1' => sha1($string),
            'keys' => self::isIterableValue($value) ? implode(', ', array_map(Str::stringify(...), array_keys(self::toArray($value)))) : '',
            default => $value, // unreachable: BUILTIN gates the match
        };
    }

    private function unknown(string $name, mixed $value): mixed
    {
        if ($this->strict) {
            throw new TemplateException('Unknown template filter.', ['filter' => $name]);
        }

        $this->diagnostics->record(Diagnostics::FILTER_UNKNOWN, 'Unknown template filter.', ['filter' => $name]);

        return $value;
    }

    private static function isIterableValue(mixed $value): bool
    {
        return \is_array($value) || $value instanceof \Traversable;
    }

    /** @return array<array-key, mixed> */
    private static function toArray(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }

        return $value instanceof \Traversable ? iterator_to_array($value) : [];
    }

    private static function lengthOf(mixed $value): int
    {
        return match (true) {
            \is_array($value) => \count($value),
            $value instanceof \Countable => \count($value),
            $value instanceof \Traversable => iterator_count($value),
            \is_string($value) => Str::length($value),
            default => $value === null ? 0 : 1,
        };
    }

    private static function edge(mixed $value, bool $first): mixed
    {
        if (\is_string($value)) {
            $length = Str::length($value);

            return $length === 0 ? '' : ($first ? Str::substr($value, 0, 1) : Str::substr($value, $length - 1, 1));
        }

        $array = self::toArray($value);

        if ($array === []) {
            return '';
        }

        return $first ? array_values($array)[0] : array_values($array)[\count($array) - 1];
    }

    private static function sorted(mixed $value, bool $descending): mixed
    {
        if (!self::isIterableValue($value)) {
            return $value;
        }

        $array = self::toArray($value);
        $descending ? arsort($array) : asort($array);

        return $array;
    }

    private static function sliced(mixed $value, int $offset, ?int $length): mixed
    {
        if (\is_string($value)) {
            return Str::substr($value, $offset, $length);
        }

        if (!self::isIterableValue($value)) {
            return $value;
        }

        return \array_slice(self::toArray($value), $offset, $length, true);
    }
}
