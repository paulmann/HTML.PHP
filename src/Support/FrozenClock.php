<?php

declare(strict_types=1);

namespace HtmlPhp\Support;

/**
 * Manually controlled clock for tests, CLI tooling and cache warm-up.
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(private int $timestamp) {}

    public function set(int $timestamp): void
    {
        $this->timestamp = $timestamp;
    }

    public function advance(int $seconds): void
    {
        $this->timestamp += $seconds;
    }

    public function now(): int
    {
        return $this->timestamp;
    }

    public function dateTime(?string $timezone = null): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->timestamp))
            ->setTimezone(new \DateTimeZone($timezone === null || $timezone === '' ? date_default_timezone_get() : $timezone));
    }
}
