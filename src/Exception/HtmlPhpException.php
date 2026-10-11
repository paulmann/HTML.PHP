<?php

declare(strict_types=1);

namespace HtmlPhp\Exception;

/**
 * Base class of every exception thrown by the library.
 *
 * Carries a structured `context` map (template name, line, directive, …)
 * so logs and the WordPress admin screen can show actionable detail. In
 * debug mode the context is appended to the message; in production it is
 * available programmatically only.
 */
class HtmlPhpException extends \RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        public readonly array $context = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Re-throws with extra context merged in (fluent helper for catch sites).
     * Every concrete subclass is final, so `new static` is safe.
     *
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): static
    {
        // @phpstan-ignore new.static
        return new static(
            $this->getMessage(),
            array_merge($this->context, $context),
            (int) $this->getCode(),
            $this->getPrevious(),
        );
    }

    /** Human-readable one-liner including context, safe for logs. */
    public function describe(): string
    {
        if ($this->context === []) {
            return $this->getMessage();
        }

        $pairs = [];
        foreach ($this->context as $key => $value) {
            $pairs[] = $key . '=' . (\is_scalar($value) || $value === null
                ? var_export($value, true)
                : (json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '?'));
        }

        return $this->getMessage() . ' [' . implode(' ', $pairs) . ']';
    }
}
