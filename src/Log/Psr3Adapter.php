<?php

declare(strict_types=1);

namespace HtmlPhp\Log;

/**
 * Bridges any PSR-3 logger (Monolog, Symfony, Laravel, …) to the library's
 * own {@see LoggerInterface}.
 *
 * The dependency stays optional: the adapter only calls `log()` on the
 * wrapped object, so no `psr/log` installation is required.
 */
final class Psr3Adapter extends AbstractLogger
{
    public function __construct(private readonly object $logger)
    {
        if (!method_exists($this->logger, 'log')) {
            throw new \InvalidArgumentException(
                'Psr3Adapter expects an object with a log($level, $message, array $context) method.',
            );
        }
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }
}
