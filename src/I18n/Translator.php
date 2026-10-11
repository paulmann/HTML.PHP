<?php

declare(strict_types=1);

namespace HtmlPhp\I18n;

use HtmlPhp\Config\I18n as I18nConfig;
use HtmlPhp\Diagnostics;
use HtmlPhp\Event\Dispatcher;
use HtmlPhp\Support\Str;

/**
 * The library's own translator: gettext-compatible catalogues, plural
 * forms, contexts, domains, fallback chains and optional WordPress
 * delegation — as an *instance* wired through the container (v3 kept all
 * of this in statics + `$GLOBALS`).
 *
 * Resolution order for every lookup:
 *  1. WordPress delegation (when enabled and the functions exist);
 *  2. own catalogues along the locale fallback chain;
 *  3. source-language fallback (`count === 1 ? singular : plural`);
 * then `{name}`/sprintf interpolation, then the `htmlphp.translate` filter.
 */
final class Translator implements TranslatorInterface
{
    /** @var array<string, array<string, Catalog>> domain => locale => catalogue */
    private array $catalogs = [];

    /** @var array<string, true> "locale|path|domain" load guard */
    private array $loaded = [];

    private string $locale;

    /** @var array<string, true> locales whose configured paths were scanned */
    private array $booted = [];

    public function __construct(
        private readonly I18nConfig $config,
        private readonly Diagnostics $diagnostics,
        private readonly Dispatcher $events,
        private readonly ?string $acceptLanguage = null
    ) {
        $this->locale = $this->detectLocale();
    }

    private function detectLocale(): string
    {
        $locale = Locale::normalize($this->config->locale);

        if ($this->config->detect && ($locale === '' || $locale === Locale::DEFAULT_LOCALE)) {
            $negotiated = Locale::negotiate($this->availableLocales(), $this->acceptLanguage, Locale::DEFAULT_LOCALE);

            if ($negotiated !== '') {
                $locale = $negotiated;
            }
        }

        return $locale !== '' ? $locale : Locale::DEFAULT_LOCALE;
    }

    // ------------------------------------------------------------- catalogues

    /**
     * Loads a catalogue file (or a directory searched for
     * `{domain}-{locale}.mo|po|json|php`, `{locale}.mo|po|json|php`,
     * `{locale}/LC_MESSAGES/{domain}.mo|po`) into the given domain.
     */
    public function load(string $locale, string $path, string $domain = 'messages'): bool
    {
        $locale = Locale::normalize($locale);

        foreach (self::catalogueCandidates($locale, $path, $domain) as $candidate) {
            $key = $locale . '|' . $candidate . '|' . $domain;

            if (isset($this->loaded[$key])) {
                return true;
            }

            if (!is_file($candidate)) {
                continue;
            }

            $catalog = Catalog::load($candidate, $locale, $domain, $this->diagnostics);

            if ($catalog !== null) {
                $existing = $this->catalogs[$domain][$locale] ?? null;

                if ($existing !== null) {
                    $existing->mergeFrom($catalog);
                } else {
                    $this->catalogs[$domain][$locale] = $catalog;
                }

                $this->loaded[$key] = true;

                return true;
            }
        }

        return false;
    }

    /**
     * Registers an in-memory catalogue (database-backed translations,
     * tests, WordPress bridges that prefer their own storage).
     */
    public function register(string $locale, string $domain, Catalog $catalog): void
    {
        $locale = Locale::normalize($locale);
        $existing = $this->catalogs[$domain][$locale] ?? null;

        if ($existing !== null) {
            $existing->mergeFrom($catalog, true); // explicit registration wins

            return;
        }

        $this->catalogs[$domain][$locale] = $catalog;
    }

    /**
     * Loads every configured `i18n.paths` directory for a locale.
     *
     * @return list<string> loaded file paths
     */
    public function loadConfigured(string $locale, string $domain = 'messages'): array
    {
        $loaded = [];

        foreach ($this->config->paths as $path) {
            foreach (self::catalogueCandidates(Locale::normalize($locale), (string) $path, $domain) as $candidate) {
                if (is_file($candidate) && $this->load($locale, $candidate, $domain)) {
                    $loaded[] = $candidate;
                    break;
                }
            }
        }

        return $loaded;
    }

    /**
     * @return list<string>
     */
    public static function catalogueCandidates(string $locale, string $path, string $domain): array
    {
        if ($path !== '' && is_file($path)) {
            return [$path];
        }

        if ($path === '' || !is_dir($path)) {
            return [];
        }

        $root = rtrim($path, "/\\");
        $candidates = [];

        foreach (['mo', 'po', 'json', 'php'] as $extension) {
            $candidates[] = $root . DIRECTORY_SEPARATOR . $domain . '-' . $locale . '.' . $extension;
            $candidates[] = $root . DIRECTORY_SEPARATOR . $locale . '.' . $extension;
        }

        foreach (['mo', 'po'] as $extension) {
            $candidates[] = $root . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR
                . 'LC_MESSAGES' . DIRECTORY_SEPARATOR . $domain . '.' . $extension;
        }

        return $candidates;
    }

    public function hasCatalogue(string $locale, string $domain = 'messages'): bool
    {
        $this->ensureLoaded($locale);

        return isset($this->catalogs[$domain][Locale::normalize($locale)]);
    }

    public function catalogue(string $locale, string $domain = 'messages'): ?Catalog
    {
        $this->ensureLoaded($locale);

        return $this->catalogs[$domain][Locale::normalize($locale)] ?? null;
    }

    /**
     * Scans the configured `i18n.paths` once per locale (lazy — a process
     * that never translates pays no I/O).
     */
    private function ensureLoaded(?string $locale = null): void
    {
        if ($this->config->paths === []) {
            return;
        }

        $locales = $locale !== null
            ? [Locale::normalize($locale)]
            : [$this->locale, ...array_map(Locale::normalize(...), $this->config->fallback)];

        foreach ($locales as $candidate) {
            if ($candidate === '' || isset($this->booted[$candidate])) {
                continue;
            }

            $this->booted[$candidate] = true;
            $this->loadConfigured($candidate, $this->config->domain);
        }
    }

    /**
     * Diagnostics: loaded catalogues and their message counts.
     *
     * @return array<string, array<string, int>>
     */
    public function stats(): array
    {
        $stats = [];

        foreach ($this->catalogs as $domain => $locales) {
            foreach ($locales as $locale => $catalog) {
                $stats[$domain][$locale] = $catalog->count();
            }
        }

        return $stats;
    }

    /** Drops every loaded catalogue (tests, long-running workers). */
    public function reset(): void
    {
        $this->catalogs = [];
        $this->loaded = [];
    }

    // ---------------------------------------------------------------- locale

    public function locale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $normalized = Locale::normalize($locale);
        $this->locale = $normalized !== '' ? $normalized : Locale::DEFAULT_LOCALE;
    }

    /**
     * Every locale that has at least one loaded catalogue.
     *
     * @return list<string>
     */
    public function availableLocales(): array
    {
        $this->ensureLoaded();
        $locales = [];

        foreach ($this->catalogs as $byLocale) {
            foreach (array_keys($byLocale) as $locale) {
                $locales[$locale] = true;
            }
        }

        return array_keys($locales);
    }

    /**
     * Negotiates the best locale from an Accept-Language header against the
     * loaded catalogues (public helper — the engine never reads `$_SERVER`).
     */
    public function negotiate(?string $acceptLanguage = null): string
    {
        return Locale::negotiate(
            $this->availableLocales(),
            $acceptLanguage ?? $this->acceptLanguage,
            $this->locale
        );
    }

    /**
     * Current locale followed by the configured fallback chain.
     *
     * @return list<string>
     */
    public function fallbackChain(): array
    {
        $chain = [$this->locale()];

        foreach ($this->config->fallback as $locale) {
            $normalized = Locale::normalize(Str::stringify($locale));

            if ($normalized !== '' && !in_array($normalized, $chain, true)) {
                $chain[] = $normalized;
            }
        }

        return $chain;
    }

    // ------------------------------------------------------------ translation

    /** @param array<array-key, mixed> $args */
    public function t(string $msgid, array $args = [], ?string $domain = null): string
    {
        return $this->translate($msgid, null, 0, '', $args, $domain);
    }

    /** @param array<array-key, mixed> $args */
    public function n(string $singular, string $plural, int $count, array $args = [], ?string $domain = null): string
    {
        return $this->translate($singular, $plural, $count, '', $args, $domain);
    }

    /** @param array<array-key, mixed> $args */
    public function x(string $msgid, string $context, array $args = [], ?string $domain = null): string
    {
        return $this->translate($msgid, null, 0, $context, $args, $domain);
    }

    /** @param array<array-key, mixed> $args */
    public function nx(
        string $singular,
        string $plural,
        int $count,
        string $context,
        array $args = [],
        ?string $domain = null
    ): string {
        return $this->translate($singular, $plural, $count, $context, $args, $domain);
    }

    /**
     * @param string|null $plural plural source form (null = singular message)
     * @param array<array-key, mixed> $args
     */
    private function translate(
        string $msgid,
        ?string $plural,
        int $count,
        string $context,
        array $args,
        ?string $domain
    ): string {
        $domain = $domain ?? $this->config->domain;

        if ($domain === '') {
            $domain = 'messages';
        }

        $this->ensureLoaded();

        $result = null;

        // 1. WordPress delegation.
        if ($this->wordpressMode()) {
            $result = $this->viaWordpress($msgid, $plural, $count, $context, $domain);
        }

        // 2. Own catalogues along the fallback chain.
        if ($result === null) {
            foreach ($this->fallbackChain() as $locale) {
                $catalog = $this->catalogs[$domain][$locale] ?? null;

                if ($catalog === null) {
                    continue;
                }

                if ($plural === null) {
                    $result = $catalog->get($msgid, $context);
                } else {
                    $index = $catalog->pluralForms()->select($count);
                    $result = $catalog->getPlural($msgid, $index, $context);
                    $result ??= $catalog->getPlural($plural, $index, $context);
                    $result ??= $catalog->get($msgid, $context);
                }

                if ($result !== null) {
                    break;
                }
            }
        }

        // 3. Source fallback.
        if ($result === null) {
            $result = $plural !== null ? ($count === 1 ? $msgid : $plural) : $msgid;

            $this->events->dispatch('htmlphp.translate.miss', $msgid, $domain, $this->locale());
        }

        $result = self::interpolate($result, $args);

        return Str::stringify($this->events->filter(
            'htmlphp.translate',
            $result,
            $msgid,
            $domain,
            $this->locale()
        ));
    }

    /**
     * True when WordPress i18n functions should be consulted first.
     *
     * `i18n.use_wordpress`: `null` (default) = auto-detect WordPress,
     * `true` = force, `false` = never.
     */
    public function wordpressMode(): bool
    {
        $mode = $this->config->useWordPress;

        if (is_bool($mode)) {
            return $mode;
        }

        return defined('ABSPATH') && function_exists('__') && function_exists('determine_locale');
    }

    private function viaWordpress(
        string $msgid,
        ?string $plural,
        int $count,
        string $context,
        string $domain
    ): ?string {
        $result = null;

        try {
            if ($plural !== null && function_exists('_nx') && function_exists('_n')) {
                $result = $context !== ''
                    ? _nx($msgid, $plural, $count, $context, $domain)
                    : _n($msgid, $plural, $count, $domain);
            } elseif ($context !== '' && function_exists('_x')) {
                $result = _x($msgid, $context, $domain);
            } elseif (function_exists('__')) {
                $result = __($msgid, $domain);
            }
        } catch (\Throwable) {
            return null;
        }

        if (!is_string($result) || $result === '') {
            return null;
        }

        // A miss is signalled by gettext returning the source string.
        $source = $plural !== null ? ($count === 1 ? $msgid : $plural) : $msgid;

        return $result === $source ? null : $result;
    }

    /**
     * Applies named (`{key}`) and sprintf-style (`%1$s`) interpolation.
     *
     * @param array<array-key, mixed> $args
     */
    public static function interpolate(string $text, array $args): string
    {
        if ($args === []) {
            return $text;
        }

        $named = [];

        foreach ($args as $key => $value) {
            $named['{' . $key . '}'] = Str::stringify($value);
        }

        $text = strtr($text, $named);

        if (array_is_list($args) && str_contains($text, '%')) {
            try {
                return vsprintf($text, $args);
            } catch (\ValueError) {
                return $text;
            }
        }

        return $text;
    }
}
