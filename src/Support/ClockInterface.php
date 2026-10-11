<?php

declare(strict_types=1);

namespace HtmlPhp\Support;

/**
 * Time source abstraction.
 *
 * Injected wherever "now" is needed (SSI built-ins, date filters, cache
 * expiry) so behaviour is deterministic in tests and long-running workers.
 */
interface ClockInterface
{
    /** Current UNIX timestamp. */
    public function now(): int;

    /** Current time as an immutable DateTime in the given timezone. */
    public function dateTime(?string $timezone = null): \DateTimeImmutable;
}
