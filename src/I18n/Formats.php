<?php

declare(strict_types=1);

namespace HtmlPhp\I18n;

use HtmlPhp\Config\I18n as I18nConfig;
use HtmlPhp\Support\ClockInterface;
use HtmlPhp\Support\Str;

/**
 * Locale-aware number/date formatting.
 *
 * Separator conventions for ~30 common locales ship built in; anything
 * else falls back to the configured defaults (`i18n.decimal_point`,
 * `i18n.thousands_separator`, `i18n.date_format`, …). Extensible through
 * the `htmlphp.i18n.locale_formats` container override or by subclassing.
 */
class Formats
{
    /** language => [decimal point, thousands separator] */
    protected const array SEPARATORS = [
        'en' => ['.', ','], 'ja' => ['.', ','], 'zh' => ['.', ','], 'ko' => ['.', ','],
        'de' => [',', '.'], 'es' => [',', '.'], 'it' => [',', '.'], 'nl' => [',', '.'],
        'pt' => [',', '.'], 'tr' => [',', '.'], 'el' => [',', '.'], 'ro' => [',', '.'],
        'hu' => [',', ' '], 'pl' => [',', ' '], 'ru' => [',', ' '], 'uk' => [',', ' '],
        'cs' => [',', ' '], 'sk' => [',', ' '], 'bg' => [',', ' '], 'kk' => [',', ' '],
        'fr' => [',', "\u{202F}"], 'ar' => [',', '.'], 'he' => ['.', ','],
        'fi' => [',', ' '], 'sv' => [',', ' '], 'no' => [',', ' '], 'nb' => [',', ' '],
        'nn' => [',', ' '], 'da' => [',', '.'], 'id' => [',', '.'], 'vi' => [',', '.'],
        'th' => ['.', ','], 'hi' => ['.', ','], 'fa' => ['.', ','],
        'et' => [',', ' '], 'lv' => [',', ' '], 'lt' => [',', ' '], 'hr' => [',', '.'],
        'sl' => [',', '.'], 'sr' => [',', '.'], 'ka' => [',', ' '], 'hy' => [',', ' '],
    ];

    public function __construct(
        protected readonly I18nConfig $config,
        protected readonly ?ClockInterface $clock = null,
    ) {}

    /**
     * Formats a number with the locale's separators.
     */
    public function number(float|int|string $value, ?string $locale = null, ?int $decimals = null): string
    {
        [$point, $thousands] = $this->separators($locale);

        return Str::number(
            Str::numericValue($value),
            $decimals ?? $this->config->numberDecimals,
            $point,
            $thousands,
        );
    }

    /**
     * Formats a UNIX timestamp, numeric string or DateTime with a `date()`
     * pattern (defaults to the configured `i18n.date_format`).
     */
    public function date(int|string|\DateTimeInterface $value, ?string $format = null, ?string $timezone = null): string
    {
        return $this->formatDate($value, $format ?? $this->config->dateFormat, $timezone);
    }

    public function time(int|string|\DateTimeInterface $value, ?string $format = null, ?string $timezone = null): string
    {
        return $this->formatDate($value, $format ?? $this->config->timeFormat, $timezone);
    }

    public function datetime(int|string|\DateTimeInterface $value, ?string $format = null, ?string $timezone = null): string
    {
        return $this->formatDate($value, $format ?? $this->config->dateTimeFormat, $timezone);
    }

    /**
     * Human-friendly elapsed time ("3 minutes ago") — the filter behind
     * `%{updated|ago}`; English wording, hosts localise through the
     * `htmlphp.i18n.ago` event or by subclassing.
     */
    public function ago(int|string|\DateTimeInterface $value, ?int $now = null): string
    {
        $timestamp = $this->timestampOf($value);

        if ($timestamp === null) {
            return Str::stringify($value);
        }

        $diff = ($now ?? $this->clock?->now() ?? time()) - $timestamp;
        $future = $diff < 0;
        $diff = abs($diff);

        $unit = match (true) {
            $diff < 60 => ['second', $diff],
            $diff < 3600 => ['minute', intdiv($diff, 60)],
            $diff < 86400 => ['hour', intdiv($diff, 3600)],
            $diff < 2592000 => ['day', intdiv($diff, 86400)],
            $diff < 31536000 => ['month', intdiv($diff, 2592000)],
            default => ['year', intdiv($diff, 31536000)],
        };

        return $future
            ? \sprintf('in %d %s%s', $unit[1], $unit[0], $unit[1] === 1 ? '' : 's')
            : \sprintf('%d %s%s ago', $unit[1], $unit[0], $unit[1] === 1 ? '' : 's');
    }

    /** @return array{0: string, 1: string} */
    protected function separators(?string $locale): array
    {
        $locale = $locale !== null ? Locale::normalize($locale) : $this->config->locale;
        $language = Locale::language($locale);

        return self::SEPARATORS[$language]
            ?? [$this->config->decimalPoint, $this->config->thousandsSeparator];
    }

    protected function formatDate(int|string|\DateTimeInterface $value, string $format, ?string $timezone): string
    {
        $timestamp = $this->timestampOf($value);

        if ($timestamp === null) {
            return Str::stringify($value);
        }

        $tz = $timezone !== null && $timezone !== '' ? new \DateTimeZone($timezone) : null;

        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone($tz ?? new \DateTimeZone(date_default_timezone_get()))
            ->format($format);
    }

    protected function timestampOf(int|string|\DateTimeInterface $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (\is_int($value)) {
            return $value;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (Str::isNumericString($value)) {
            return (int) Str::numericValue($value);
        }

        $parsed = strtotime($value);

        return $parsed === false ? null : $parsed;
    }
}
