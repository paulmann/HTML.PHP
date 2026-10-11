<?php

declare(strict_types=1);

namespace HtmlPhp\I18n;

/**
 * Locale normalisation, `Accept-Language` parsing and negotiation.
 *
 * No superglobals: callers pass the header value explicitly (typically
 * `RequestContext::$acceptLanguage`).
 */
final class Locale
{
    public const DEFAULT_LOCALE = 'en';

    private function __construct()
    {
    }

    /**
     * Normalises any locale spelling to "ll_CC":
     *   en-us, EN_US.utf8, en_US.UTF-8@euro, en-usoed → en_US / en.
     */
    public static function normalize(string $locale): string
    {
        $locale = trim($locale);

        if ($locale === '') {
            return self::DEFAULT_LOCALE;
        }

        // Drop codeset and modifier: ru_RU.UTF-8@euro → ru_RU
        $locale = preg_replace('~[.@].*$~', '', $locale) ?? $locale;
        $locale = str_replace('-', '_', $locale);

        if (preg_match('~^([A-Za-z]{2,3})(?:_([A-Za-z]{4}))?(?:_([A-Za-z]{2}|[0-9]{3}))?$~', $locale, $m) !== 1) {
            return self::DEFAULT_LOCALE;
        }

        $language = strtolower($m[1]);

        if (($m[3] ?? '') !== '') {
            return $language . '_' . strtoupper($m[3]);
        }

        if (($m[2] ?? '') !== '') {
            // Script subtag only (zh_Hant): keep as zh_Hant.
            return $language . '_' . ucfirst(strtolower($m[2]));
        }

        return $language;
    }

    /**
     * Language part of a locale ("ru_RU" → "ru").
     */
    public static function language(string $locale): string
    {
        $normalized = self::normalize($locale);
        $position = strpos($normalized, '_');

        return $position === false ? $normalized : substr($normalized, 0, $position);
    }

    /**
     * Parses an Accept-Language header into q-sorted candidates.
     *
     * @return list<array{locale: string, q: float}>
     */
    public static function parseAcceptLanguage(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }

        $candidates = [];

        foreach (explode(',', $header) as $part) {
            $segments = explode(';', trim($part));
            $tag = self::normalize(trim((string) $segments[0]));
            $quality = 1.0;

            foreach (array_slice($segments, 1) as $parameter) {
                if (preg_match('~^\s*q\s*=\s*([0-9.]+)\s*$~i', $parameter, $m) === 1) {
                    $quality = max(0.0, min(1.0, (float) $m[1]));
                }
            }

            if ($tag !== '' && $tag !== '*') {
                $candidates[] = ['locale' => $tag, 'q' => $quality];
            } elseif ($tag === '*') {
                $candidates[] = ['locale' => '*', 'q' => $quality];
            }
        }

        usort($candidates, static fn(array $a, array $b): int => $b['q'] <=> $a['q']);

        return $candidates;
    }

    /**
     * Picks the best available locale for an Accept-Language header.
     *
     * Match order: exact tag → same language with any territory → any
     * available locale sharing the language prefix → $default.
     *
     * @param list<string>|array<string, mixed> $available
     */
    public static function negotiate(array $available, ?string $header = null, ?string $default = null): string
    {
        $availableList = array_values(array_map(
            static fn(mixed $locale): string => self::normalize((string) $locale),
            $available
        ));

        if ($availableList === []) {
            return self::normalize($default ?? self::DEFAULT_LOCALE);
        }

        $fallback = self::normalize($default ?? $availableList[0]);

        foreach (self::parseAcceptLanguage($header) as $candidate) {
            $wanted = $candidate['locale'];

            if ($wanted === '*') {
                continue;
            }

            if (in_array($wanted, $availableList, true)) {
                return $wanted;
            }

            $language = self::language($wanted);

            foreach ($availableList as $option) {
                if (self::language($option) === $language) {
                    return $option;
                }
            }
        }

        return $fallback;
    }
}
