<?php

declare(strict_types=1);

namespace HtmlPhp\Log;

/**
 * Implements the level shortcuts by delegating to {@see self::log()}.
 */
abstract class AbstractLogger implements LoggerInterface
{
    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /**
     * Interpolates `{placeholder}` tokens from `$context` (PSR-3 style).
     *
     * @param array<string, mixed> $context
     */
    protected function interpolate(string $message, array $context): string
    {
        if ($context === [] || !str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];

        foreach ($context as $key => $value) {
            if (\is_scalar($value) || $value === null || $value instanceof \Stringable) {
                $replacements['{' . $key . '}'] = (string) ($value ?? 'null');
            }
        }

        return strtr($message, $replacements);
    }
}
