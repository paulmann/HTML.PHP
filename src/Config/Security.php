<?php

declare(strict_types=1);

namespace HtmlPhp\Config;

use HtmlPhp\Config;

/**
 * Defence-in-depth switches that apply regardless of template trust level.
 */
final readonly class Security
{
    /**
     * Extensions that may never be served through `#include`, `@Partial` or
     * `@Extends` — prevents source disclosure of scripts and dotfile configs.
     */
    public const array DENIED_EXTENSIONS_DEFAULT = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phar',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'dll', 'so',
        'inc', 'conf', 'config', 'env', 'htaccess', 'htpasswd', 'ini', 'log',
        'sql', 'bak', 'swp', 'dist',
    ];

    /**
     * @param array<string, true>|null $allowedTags allow-list for
     *        {@see \HtmlPhp\Html\Cleaner}; `null` selects the documented default
     * @param list<string> $deniedExtensions lowercase extension blacklist for includes
     */
    public function __construct(
        public ?array $allowedTags = null,
        public array $deniedExtensions = self::DENIED_EXTENSIONS_DEFAULT,
        public bool $denyPhpIncludes = true,
        public bool $sanitizeExecOutput = true
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $allowed = Config::arrayValue($config, 'allowed_tags', []);

        return new self(
            allowedTags: $allowed === [] && !array_key_exists('allowed_tags', $config)
                ? null
                : self::normalizeTags($allowed),
            deniedExtensions: array_values(array_map(
                static fn($ext): string => strtolower(ltrim((string) $ext, '.')),
                Config::arrayValue($config, 'denied_extensions', self::DENIED_EXTENSIONS_DEFAULT)
            )),
            denyPhpIncludes: Config::bool($config, 'deny_php_includes', true),
            sanitizeExecOutput: Config::bool($config, 'sanitize_exec_output', true)
        );
    }

    /**
     * @param array<array-key, mixed> $tags
     * @return array<string, true>
     */
    private static function normalizeTags(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $key => $value) {
            $name = strtolower(trim((string) (is_int($key) ? $value : $key)));

            if ($name !== '' && preg_match('/^[a-z][a-z0-9-]*$/', $name) === 1) {
                $normalized[$name] = true;
            }
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'allowed_tags' => $this->allowedTags ?? [],
            'denied_extensions' => $this->deniedExtensions,
            'deny_php_includes' => $this->denyPhpIncludes,
            'sanitize_exec_output' => $this->sanitizeExecOutput,
        ];
    }

    public const array KEYS = ['allowed_tags', 'denied_extensions', 'deny_php_includes', 'sanitize_exec_output'];
}
