<?php

declare(strict_types=1);

namespace HtmlPhp;

use HtmlPhp\Event\Dispatcher;
use HtmlPhp\Log\LoggerInterface;

/**
 * Bounded, structured diagnostics collector.
 *
 * Replaces the v3 static `Errors` registry: one instance per engine, wired
 * through the container. Non-fatal problems (missing variables, denied
 * `#exec`, failed includes, unknown filters) are recorded here instead of
 * throwing, optionally forwarded to a {@see LoggerInterface} and always
 * broadcast on the `htmlphp.error` event so hosts can react.
 */
final class Diagnostics
{
    // Diagnostic codes — stable identifiers, keep in sync with the docs.
    public const string MISSING_VAR = 'missing-var';
    public const string SSI_INCLUDE_FAILED = 'ssi-include-failed';
    public const string SSI_INCLUDE_CYCLE = 'ssi-include-cycle';
    public const string SSI_LIMIT_REACHED = 'ssi-limit-reached';
    public const string SSI_EXEC_DENIED = 'ssi-exec-denied';
    public const string SSI_EXEC_FAILED = 'ssi-exec-failed';
    public const string SSI_EXPR_ERROR = 'ssi-expr-error';
    public const string SSI_UNKNOWN_DIRECTIVE = 'ssi-unknown-directive';
    public const string FILTER_UNKNOWN = 'filter-unknown';
    public const string REGEX_FAILED = 'regex-failed';
    public const string CATALOG_PARSE = 'catalog-parse';
    public const string CACHE_IO = 'cache-io';
    public const string LOOP_GUARD = 'loop-guard';
    public const string TEMPLATE_LINT = 'template-lint';
    public const string WP_BRIDGE = 'wp-bridge';

    /** @var list<array{code: string, message: string, context: array<string, mixed>, where: string, time: int}> */
    private array $entries = [];

    public function __construct(
        private readonly int $cap = 100,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?Dispatcher $events = null
    ) {
    }

    /**
     * Records one entry. Entries beyond the cap are silently dropped — the
     * collector must never become a memory leak inside a loop-heavy render.
     *
     * @param array<string, mixed> $context
     */
    public function record(string $code, string $message, array $context = []): void
    {
        if (count($this->entries) < $this->cap) {
            $this->entries[] = [
                'code' => $code,
                'message' => $message,
                'context' => $context,
                'where' => $this->caller(),
                'time' => time(),
            ];
        }

        $this->logger?->warning($message, ['code' => $code, ...$context]);
        $this->events?->dispatch('htmlphp.error', $code, $message, $context);
    }

    /** @return list<array{code: string, message: string, context: array<string, mixed>, where: string, time: int}> */
    public function all(): array
    {
        return $this->entries;
    }

    /**
     * Entries whose code equals `$code` (empty string = all).
     *
     * @return list<array{code: string, message: string, context: array<string, mixed>, where: string, time: int}>
     */
    public function byCode(string $code): array
    {
        if ($code === '') {
            return $this->entries;
        }

        return array_values(array_filter(
            $this->entries,
            static fn(array $entry): bool => $entry['code'] === $code
        ));
    }

    public function count(string $code = ''): int
    {
        return $code === '' ? count($this->entries) : count($this->byCode($code));
    }

    /** @return array{code: string, message: string, context: array<string, mixed>, where: string, time: int}|null */
    public function last(): ?array
    {
        return $this->entries === [] ? null : $this->entries[array_key_last($this->entries)];
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    /** True when nothing was recorded — handy in tests and health checks. */
    public function clean(): bool
    {
        return $this->entries === [];
    }

    /** First frame outside this library, for actionable "where" info. */
    private function caller(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10) as $frame) {
            $file = (string) ($frame['file'] ?? '');

            if ($file !== '' && !str_contains($file, '/src/') && !str_contains($file, 'html.php')) {
                return $file . ':' . (int) ($frame['line'] ?? 0);
            }
        }

        return '(library)';
    }
}
