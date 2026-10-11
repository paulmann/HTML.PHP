<?php

declare(strict_types=1);

namespace HtmlPhp\Log;

/**
 * PSR-3 shaped logging contract (self-contained — the library ships with
 * zero dependencies, so it declares its own interface; `Psr3Adapter`
 * forwards to a real `Psr\Log\LoggerInterface` when one is installed).
 */
interface LoggerInterface
{
    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function notice(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function critical(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function alert(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function emergency(string $message, array $context = []): void;
}
