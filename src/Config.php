<?php

declare(strict_types=1);

namespace HtmlPhp;

use HtmlPhp\Config\Cache as CacheConfig;
use HtmlPhp\Config\I18n as I18nConfig;
use HtmlPhp\Config\Limits;
use HtmlPhp\Config\Log as LogConfig;
use HtmlPhp\Config\Security as SecurityConfig;
use HtmlPhp\Config\Ssi as SsiConfig;
use HtmlPhp\Config\Syntax as SyntaxConfig;
use HtmlPhp\Config\Templates as TemplatesConfig;
use HtmlPhp\Exception\ConfigException;

/**
 * Immutable configuration aggregate.
 *
 * One `Config` value object replaces the ~35 `$GLOBALS['HTML_*']` keys of
 * v3. It is built once (`Config::fromArray()`, `Config::default()` or the
 * constructor), validated eagerly — unknown keys are rejected so typos fail
 * fast — and then handed to {@see Engine::create()}. `with*()` methods
 * return modified clones; the original never changes.
 *
 * ```php
 * $config = Config::fromArray([
 *     'debug'     => true,
 *     'templates' => ['paths' => [__DIR__ . '/tpl']],
 *     'ssi'       => ['exec_mode' => 'deny'],
 * ]);
 * ```
 */
final readonly class Config
{
    public function __construct(
        public bool $debug = false,
        public string $charset = 'UTF-8',
        public TemplatesConfig $templates = new TemplatesConfig(),
        public SyntaxConfig $syntax = new SyntaxConfig(),
        public SsiConfig $ssi = new SsiConfig(),
        public I18nConfig $i18n = new I18nConfig(),
        public CacheConfig $cache = new CacheConfig(),
        public LogConfig $log = new LogConfig(),
        public SecurityConfig $security = new SecurityConfig(),
        public Limits $limits = new Limits(),
    ) {}

    public static function default(): self
    {
        return new self();
    }

    /**
     * Builds a config from a nested array, rejecting unknown keys at every
     * level so a misspelled option is a hard error, not a silent no-op.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        self::rejectUnknown($config, [
            'debug', 'charset', 'templates', 'syntax', 'ssi', 'i18n',
            'cache', 'log', 'security', 'limits',
        ], 'config');

        $group = static function (string $key, array $known) use ($config): array {
            $value = $config[$key] ?? [];

            if (!\is_array($value)) {
                throw new ConfigException("Config group '{$key}' must be an array.", ['value' => $value]);
            }

            self::rejectUnknown($value, $known, $key);

            return $value;
        };

        return new self(
            debug: self::bool($config, 'debug', false),
            charset: (string) self::value($config, 'charset', 'UTF-8'),
            templates: TemplatesConfig::fromArray($group('templates', TemplatesConfig::KEYS)),
            syntax: SyntaxConfig::fromArray($group('syntax', SyntaxConfig::KEYS)),
            ssi: SsiConfig::fromArray($group('ssi', SsiConfig::KEYS)),
            i18n: I18nConfig::fromArray($group('i18n', I18nConfig::KEYS)),
            cache: CacheConfig::fromArray($group('cache', CacheConfig::KEYS)),
            log: LogConfig::fromArray($group('log', LogConfig::KEYS)),
            security: SecurityConfig::fromArray($group('security', SecurityConfig::KEYS)),
            limits: Limits::fromArray($group('limits', [
                'max_template_bytes', 'max_include_bytes', 'max_ssi_depth', 'max_ssi_tags',
                'max_loop_rows', 'max_loop_depth', 'max_block_depth', 'max_passes',
                'max_expression_length', 'max_exec_output_bytes', 'exec_timeout',
                'max_diagnostics', 'max_precision',
            ])),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'debug' => $this->debug,
            'charset' => $this->charset,
            'templates' => $this->templates->toArray(),
            'syntax' => $this->syntax->toArray(),
            'ssi' => $this->ssi->toArray(),
            'i18n' => $this->i18n->toArray(),
            'cache' => $this->cache->toArray(),
            'log' => $this->log->toArray(),
            'security' => $this->security->toArray(),
            'limits' => $this->limits->toArray(),
        ];
    }

    public function withDebug(bool $debug): self
    {
        return new self(
            $debug,
            $this->charset,
            $this->templates,
            $this->syntax,
            $this->ssi,
            $this->i18n,
            $this->cache,
            $this->log,
            $this->security,
            $this->limits,
        );
    }

    public function withSsi(SsiConfig $ssi): self
    {
        return new self(
            $this->debug,
            $this->charset,
            $this->templates,
            $this->syntax,
            $ssi,
            $this->i18n,
            $this->cache,
            $this->log,
            $this->security,
            $this->limits,
        );
    }

    public function withI18n(I18nConfig $i18n): self
    {
        return new self(
            $this->debug,
            $this->charset,
            $this->templates,
            $this->syntax,
            $this->ssi,
            $i18n,
            $this->cache,
            $this->log,
            $this->security,
            $this->limits,
        );
    }

    public function withCache(CacheConfig $cache): self
    {
        return new self(
            $this->debug,
            $this->charset,
            $this->templates,
            $this->syntax,
            $this->ssi,
            $this->i18n,
            $cache,
            $this->log,
            $this->security,
            $this->limits,
        );
    }

    public function withTemplates(TemplatesConfig $templates): self
    {
        return new self(
            $this->debug,
            $this->charset,
            $templates,
            $this->syntax,
            $this->ssi,
            $this->i18n,
            $this->cache,
            $this->log,
            $this->security,
            $this->limits,
        );
    }

    public function withSyntax(SyntaxConfig $syntax): self
    {
        return new self(
            $this->debug,
            $this->charset,
            $this->templates,
            $syntax,
            $this->ssi,
            $this->i18n,
            $this->cache,
            $this->log,
            $this->security,
            $this->limits,
        );
    }

    /**
     * Merges a partial array over this config (same shape as fromArray input).
     *
     * @param array<string, mixed> $overrides
     */
    public function merge(array $overrides): self
    {
        return self::fromArray(self::mergeArrays($this->toArray(), $overrides));
    }

    // ---------------------------------------------------------------- readers

    /** First present key wins; values are returned untyped. */
    /** @param array<string, mixed> $config */
    public static function value(array $config, string $key, mixed $default): mixed
    {
        return \array_key_exists($key, $config) && $config[$key] !== null ? $config[$key] : $default;
    }

    /** @param array<string, mixed> $config */
    public static function bool(array $config, string $key, bool $default): bool
    {
        if (!\array_key_exists($key, $config) || $config[$key] === null) {
            return $default;
        }

        $value = $config[$key];

        return match (true) {
            \is_bool($value) => $value,
            \is_int($value) => $value !== 0,
            \is_string($value) => !\in_array(strtolower($value), ['', '0', 'false', 'off', 'no'], true),
            default => (bool) $value,
        };
    }

    /** @param array<string, mixed> $config */
    public static function nullableBool(array $config, string $key): ?bool
    {
        if (!\array_key_exists($key, $config) || $config[$key] === null) {
            return null;
        }

        $value = $config[$key];

        if (\is_string($value) && strtolower($value) === 'auto') {
            return null;
        }

        return self::bool($config, $key, false);
    }

    /** @param array<string, mixed> $config */
    public static function int(array $config, string $key, int $default): int
    {
        if (!\array_key_exists($key, $config) || $config[$key] === null) {
            return $default;
        }

        $value = $config[$key];

        if (\is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        throw new ConfigException("Config key '{$key}' must be an integer.", ['value' => $value]);
    }

    /** @param array<string, mixed> $config */
    public static function nullableString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!\is_scalar($value)) {
            throw new ConfigException("Config key '{$key}' must be a string or null.", ['key' => $key]);
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public static function stringList(array $config, string $key): array
    {
        $value = $config[$key] ?? [];

        if (!\is_array($value)) {
            throw new ConfigException("Config key '{$key}' must be an array of strings.", ['key' => $key]);
        }

        return array_values(array_map(
            static fn($item): string => \is_scalar($item) ? (string) $item : throw new ConfigException(
                "Config key '{$key}' must contain only strings.",
                ['key' => $key],
            ),
            $value,
        ));
    }

    /**
     * @param array<string, mixed> $config
     * @param array<array-key, mixed> $default
     * @return array<array-key, mixed>
     */
    public static function arrayValue(array $config, string $key, array $default): array
    {
        $value = $config[$key] ?? $default;

        if (!\is_array($value)) {
            throw new ConfigException("Config key '{$key}' must be an array.", ['key' => $key]);
        }

        return $value;
    }

    /** @param array<string, mixed> $config */
    public static function callableOrNull(array $config, string $key): ?callable
    {
        $value = $config[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!\is_callable($value)) {
            throw new ConfigException("Config key '{$key}' must be callable.", ['key' => $key]);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $config
     * @param list<string> $known
     */
    private static function rejectUnknown(array $config, array $known, string $group): void
    {
        foreach (array_keys($config) as $key) {
            if (!\is_string($key) || !\in_array($key, $known, true)) {
                throw new ConfigException(
                    \sprintf(
                        'Unknown configuration key "%s" in group "%s". Known keys: %s.',
                        (string) $key,
                        $group,
                        implode(', ', $known),
                    ),
                    ['group' => $group, 'key' => $key],
                );
            }
        }
    }

    /**
     * Recursive merge where lists are replaced, not appended.
     *
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overrides
     * @return array<array-key, mixed>
     */
    private static function mergeArrays(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key]) && self::isAssoc($value) && self::isAssoc($base[$key])) {
                $base[$key] = self::mergeArrays($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /** @param array<array-key, mixed> $array */
    private static function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, \count($array) - 1);
    }
}
