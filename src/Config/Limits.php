<?php

declare(strict_types=1);

namespace HtmlPhp\Config;

use HtmlPhp\Config;

use HtmlPhp\Exception\ConfigException;

/**
 * Resource ceilings that keep a hostile or buggy template from exhausting
 * CPU, memory or the filesystem.
 *
 * Every value is clamped to a documented hard bound at construction time,
 * so a mis-typed config array degrades to a safe value instead of disabling
 * the guard.
 */
final readonly class Limits
{
    public const int MAX_TEMPLATE_BYTES_CEILING = 512 * 1024 * 1024;
    public const int MAX_INCLUDE_BYTES_CEILING = 256 * 1024 * 1024;

    public function __construct(
        public int $maxTemplateBytes = 16 * 1024 * 1024,
        public int $maxIncludeBytes = 8 * 1024 * 1024,
        public int $maxSsiDepth = 16,
        public int $maxSsiTags = 256,
        public int $maxLoopRows = 10_000,
        public int $maxLoopDepth = 32,
        public int $maxBlockDepth = 64,
        public int $maxPasses = 2,
        public int $maxExpressionLength = 4096,
        public int $maxExecOutputBytes = 65_536,
        public int $execTimeoutSeconds = 5,
        public int $maxDiagnostics = 100,
        public int $maxPrecision = 100
    ) {
        $this->guard('maxTemplateBytes', $this->maxTemplateBytes, 1024, self::MAX_TEMPLATE_BYTES_CEILING);
        $this->guard('maxIncludeBytes', $this->maxIncludeBytes, 1024, self::MAX_INCLUDE_BYTES_CEILING);
        $this->guard('maxSsiDepth', $this->maxSsiDepth, 1, 128);
        $this->guard('maxSsiTags', $this->maxSsiTags, 1, 10_000);
        $this->guard('maxLoopRows', $this->maxLoopRows, 1, 1_000_000);
        $this->guard('maxLoopDepth', $this->maxLoopDepth, 1, 128);
        $this->guard('maxBlockDepth', $this->maxBlockDepth, 1, 1024);
        $this->guard('maxPasses', $this->maxPasses, 1, 16);
        $this->guard('maxExpressionLength', $this->maxExpressionLength, 16, 65_536);
        $this->guard('maxExecOutputBytes', $this->maxExecOutputBytes, 1, 16 * 1024 * 1024);
        $this->guard('execTimeoutSeconds', $this->execTimeoutSeconds, 1, 60);
        $this->guard('maxDiagnostics', $this->maxDiagnostics, 1, 10_000);
        $this->guard('maxPrecision', $this->maxPrecision, 0, 1000);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            maxTemplateBytes: Config::int($config, 'max_template_bytes', 16 * 1024 * 1024),
            maxIncludeBytes: Config::int($config, 'max_include_bytes', 8 * 1024 * 1024),
            maxSsiDepth: Config::int($config, 'max_ssi_depth', 16),
            maxSsiTags: Config::int($config, 'max_ssi_tags', 256),
            maxLoopRows: Config::int($config, 'max_loop_rows', 10_000),
            maxLoopDepth: Config::int($config, 'max_loop_depth', 32),
            maxBlockDepth: Config::int($config, 'max_block_depth', 64),
            maxPasses: Config::int($config, 'max_passes', 2),
            maxExpressionLength: Config::int($config, 'max_expression_length', 4096),
            maxExecOutputBytes: Config::int($config, 'max_exec_output_bytes', 65_536),
            execTimeoutSeconds: Config::int($config, 'exec_timeout', 5),
            maxDiagnostics: Config::int($config, 'max_diagnostics', 100),
            maxPrecision: Config::int($config, 'max_precision', 100)
        );
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'max_template_bytes' => $this->maxTemplateBytes,
            'max_include_bytes' => $this->maxIncludeBytes,
            'max_ssi_depth' => $this->maxSsiDepth,
            'max_ssi_tags' => $this->maxSsiTags,
            'max_loop_rows' => $this->maxLoopRows,
            'max_loop_depth' => $this->maxLoopDepth,
            'max_block_depth' => $this->maxBlockDepth,
            'max_passes' => $this->maxPasses,
            'max_expression_length' => $this->maxExpressionLength,
            'max_exec_output_bytes' => $this->maxExecOutputBytes,
            'exec_timeout' => $this->execTimeoutSeconds,
            'max_diagnostics' => $this->maxDiagnostics,
            'max_precision' => $this->maxPrecision,
        ];
    }

    private function guard(string $name, int $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            throw new ConfigException(
                "Limit '{$name}' must be between {$min} and {$max}.",
                ['limit' => $name, 'value' => $value, 'min' => $min, 'max' => $max]
            );
        }
    }
}
