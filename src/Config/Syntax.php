<?php

declare(strict_types=1);

namespace HtmlPhp\Config;

use HtmlPhp\Config;

/**
 * Template language options: delimiters, escaping and the missing-variable
 * policy.
 */
final readonly class Syntax
{
    public function __construct(
        public string $varOpen = '%{',
        public string $varClose = '}',
        public string $altOpen = '#{',
        public string $transOpen = '@{',
        public string $tagOpen = '<!--@',
        public string $tagClose = '-->',
        public bool $autoEscape = true,
        public MissingVarPolicy $missingVar = MissingVarPolicy::Empty,
        public string $missingPlaceholderFormat = '[missing:%s]',
        public bool $singleBraces = false,
        public bool $allowRawFilter = true,
        public bool $strict = false,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $missing = (string) Config::value($config, 'missing_var', MissingVarPolicy::Empty->value);

        return new self(
            varOpen: (string) Config::value($config, 'var_open', '%{'),
            varClose: (string) Config::value($config, 'var_close', '}'),
            altOpen: (string) Config::value($config, 'alt_open', '#{'),
            transOpen: (string) Config::value($config, 'trans_open', '@{'),
            tagOpen: (string) Config::value($config, 'tag_open', '<!--@'),
            tagClose: (string) Config::value($config, 'tag_close', '-->'),
            autoEscape: Config::bool($config, 'auto_escape', true),
            missingVar: MissingVarPolicy::tryFrom($missing)
                ?? throw new \HtmlPhp\Exception\ConfigException(
                    'syntax.missing_var must be one of: empty, keep, placeholder, throw.',
                ),
            missingPlaceholderFormat: (string) Config::value($config, 'missing_placeholder_format', '[missing:%s]'),
            singleBraces: Config::bool($config, 'single_braces', false),
            allowRawFilter: Config::bool($config, 'allow_raw_filter', true),
            strict: Config::bool($config, 'strict', false),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'var_open' => $this->varOpen,
            'var_close' => $this->varClose,
            'alt_open' => $this->altOpen,
            'trans_open' => $this->transOpen,
            'tag_open' => $this->tagOpen,
            'tag_close' => $this->tagClose,
            'auto_escape' => $this->autoEscape,
            'missing_var' => $this->missingVar->value,
            'missing_placeholder_format' => $this->missingPlaceholderFormat,
            'single_braces' => $this->singleBraces,
            'allow_raw_filter' => $this->allowRawFilter,
            'strict' => $this->strict,
        ];
    }

    public const array KEYS = [
        'var_open', 'var_close', 'alt_open', 'trans_open', 'tag_open', 'tag_close',
        'auto_escape', 'missing_var', 'missing_placeholder_format', 'single_braces',
        'allow_raw_filter', 'strict',
    ];
}
