<?php

declare(strict_types=1);

namespace HtmlPhp;

/**
 * Version metadata and the runtime guard used by the single-file bundles.
 */
final class Version
{
    public const string VERSION = '5.0.0';

    /** Rewritten to `'8.5'` in the PHP 8.5 bundle by `tools/build.php`. */
    public const string EDITION = '8.3';

    public const int MIN_PHP = 80300;

    public const string BUILD_DATE = '2026-10-12';

    private function __construct() {}

    /** `"5.0.0 (8.3 edition)"` */
    public static function banner(): string
    {
        return self::VERSION . ' (' . self::EDITION . ' edition)';
    }

    /**
     * Fails fast with a clear message when the running PHP is too old —
     * called from the bundle guard; Composer users are protected by the
     * platform requirement instead.
     */
    public static function assertRuntime(): void
    {
        if (PHP_VERSION_ID < self::MIN_PHP) {
            throw new Exception\HtmlPhpException(\sprintf(
                'HTML.PHP %s (%s edition) requires PHP %d.%d or newer; running %s.',
                self::VERSION,
                self::EDITION,
                intdiv(self::MIN_PHP, 10000),
                intdiv(self::MIN_PHP % 10000, 100),
                PHP_VERSION,
            ));
        }
    }
}
