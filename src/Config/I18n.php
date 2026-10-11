<?php

declare(strict_types=1);

namespace HtmlPhp\Config;

use HtmlPhp\Config;

/**
 * Internationalisation options.
 */
final readonly class I18n
{
    /**
     * @param list<string> $fallback locale fallback chain, most specific first
     * @param list<string> $paths directories scanned for catalogues using the
     *        `{domain}-{locale}.{mo|po|php|json}`, `{locale}.{mo|po|php|json}`
     *        or `{locale}/LC_MESSAGES/{domain}.{mo|po}` conventions
     * @param bool|null $useWordPress `null` = auto-detect (delegate when the
     *        WP translation functions exist), `true`/`false` force the choice
     */
    public function __construct(
        public string $locale = 'en',
        public array $fallback = ['en'],
        public string $domain = 'messages',
        public array $paths = [],
        public bool $detect = false,
        public ?bool $useWordPress = null,
        public int $numberDecimals = 2,
        public string $decimalPoint = '.',
        public string $thousandsSeparator = ',',
        public string $dateFormat = 'Y-m-d',
        public string $timeFormat = 'H:i',
        public string $dateTimeFormat = 'Y-m-d H:i',
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $useWp = Config::nullableBool($config, 'use_wordpress');

        return new self(
            locale: \HtmlPhp\I18n\Locale::normalize((string) Config::value($config, 'locale', 'en')),
            fallback: array_map(
                static fn(string $locale): string => \HtmlPhp\I18n\Locale::normalize($locale),
                Config::stringList($config, 'fallback') ?: ['en'],
            ),
            domain: (string) Config::value($config, 'domain', 'messages'),
            paths: array_values(array_map(strval(...), Config::arrayValue($config, 'paths', []))),
            detect: Config::bool($config, 'detect', false),
            useWordPress: $useWp,
            numberDecimals: Config::int($config, 'number_decimals', 2),
            decimalPoint: (string) Config::value($config, 'decimal_point', '.'),
            thousandsSeparator: (string) Config::value($config, 'thousands_separator', ','),
            dateFormat: (string) Config::value($config, 'date_format', 'Y-m-d'),
            timeFormat: (string) Config::value($config, 'time_format', 'H:i'),
            dateTimeFormat: (string) Config::value($config, 'datetime_format', 'Y-m-d H:i'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'fallback' => $this->fallback,
            'domain' => $this->domain,
            'paths' => $this->paths,
            'detect' => $this->detect,
            'use_wordpress' => $this->useWordPress,
            'number_decimals' => $this->numberDecimals,
            'decimal_point' => $this->decimalPoint,
            'thousands_separator' => $this->thousandsSeparator,
            'date_format' => $this->dateFormat,
            'time_format' => $this->timeFormat,
            'datetime_format' => $this->dateTimeFormat,
        ];
    }

    public const array KEYS = [
        'locale', 'fallback', 'domain', 'paths', 'detect', 'use_wordpress',
        'number_decimals', 'decimal_point', 'thousands_separator',
        'date_format', 'time_format', 'datetime_format',
    ];
}
