<?php

declare(strict_types=1);

namespace HtmlPhp\Template;

/**
 * One linter finding.
 *
 * Severity: `error` (render would fail or misbehave), `warning` (probably
 * a bug), `info` (worth knowing). Codes are stable identifiers for CI
 * baselines (`--ignore=unresolved-include`).
 */
final readonly class LintIssue
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $severity,
        public string $code,
        public string $message,
        public string $file = '-',
        public int $line = 0,
        public array $context = []
    ) {
    }

    public function isError(): bool
    {
        return $this->severity === 'error';
    }

    public function format(): string
    {
        return sprintf(
            '%s:%d %s [%s] %s',
            $this->file,
            $this->line,
            strtoupper($this->severity),
            $this->code,
            $this->message
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
