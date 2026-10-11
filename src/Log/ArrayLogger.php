<?php

declare(strict_types=1);

namespace HtmlPhp\Log;

/**
 * Keeps records in memory — the sink used by tests, `lint` reports and the
 * WordPress admin health screen.
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

    public function __construct(private readonly int $cap = 1000) {}

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (\count($this->records) < $this->cap) {
            $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
        }
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    public function records(): array
    {
        return $this->records;
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_map(static fn(array $r): string => $r['level'] . ': ' . $r['message'], $this->records);
    }

    public function clear(): void
    {
        $this->records = [];
    }
}
