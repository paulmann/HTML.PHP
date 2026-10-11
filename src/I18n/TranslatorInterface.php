<?php

declare(strict_types=1);

namespace HtmlPhp\I18n;

/**
 * Translation contract.
 *
 * The engine depends only on this interface: swap in
 * {@see Translator} (own catalogues), a WordPress-delegating
 * implementation, or your own (database-backed, API-backed, …) through
 * the container.
 */
interface TranslatorInterface
{
    /**
     * Translates a message id with optional `{name}`/sprintf interpolation.
     *
     * @param array<array-key, mixed> $args
     */
    public function t(string $msgid, array $args = [], ?string $domain = null): string;

    /**
     * Plural-aware translation.
     *
     * @param array<array-key, mixed> $args
     */
    public function n(string $singular, string $plural, int $count, array $args = [], ?string $domain = null): string;

    /**
     * Translation with a message context (`msgctxt`).
     *
     * @param array<array-key, mixed> $args
     */
    public function x(string $msgid, string $context, array $args = [], ?string $domain = null): string;

    /**
     * Plural + context translation.
     *
     * @param array<array-key, mixed> $args
     */
    public function nx(
        string $singular,
        string $plural,
        int $count,
        string $context,
        array $args = [],
        ?string $domain = null
    ): string;

    /** Active locale, normalised (`ll` or `ll_CC`). */
    public function locale(): string;

    public function setLocale(string $locale): void;
}
