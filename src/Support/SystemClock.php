<?php

declare(strict_types=1);

namespace HtmlPhp\Support;

/** Wall-clock time source for production use. */
final class SystemClock implements ClockInterface
{
    public function now(): int
    {
        return time();
    }

    public function dateTime(?string $timezone = null): \DateTimeImmutable
    {
        return $timezone === null || $timezone === ''
            ? new \DateTimeImmutable()
            : new \DateTimeImmutable('now', new \DateTimeZone($timezone));
    }
}
