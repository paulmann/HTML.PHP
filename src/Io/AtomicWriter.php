<?php

declare(strict_types=1);

namespace HtmlPhp\Io;

use HtmlPhp\Exception\IoException;

/**
 * Crash-safe file writer.
 *
 * Sequence: temp file in the destination directory → full write under
 * `LOCK_EX` → verified payload length → mode preservation → `rename()`,
 * with a locked in-place write as fallback for filesystems that refuse
 * cross-directory renames (some Windows/SMB setups).
 */
final class AtomicWriter
{
    private function __construct() {}

    /**
     * Writes `$data` to `$file` atomically.
     *
     * @param int|null $mode explicit permissions; `null` preserves the
     *        existing file mode or uses the umask default for new files
     * @throws IoException when the directory or file is not writable
     */
    public static function write(string $file, string $data, ?int $mode = null): bool
    {
        if ($file === '' || str_contains($file, "\0")) {
            throw new IoException('The output file path is invalid.', ['file' => $file]);
        }

        $directory = \dirname($file);

        if (!is_dir($directory) || !is_writable($directory)) {
            throw new IoException("Output directory '{$directory}' is not writable.", ['dir' => $directory]);
        }

        if (is_file($file) && !is_writable($file)) {
            throw new IoException("Output file '{$file}' is not writable.", ['file' => $file]);
        }

        $expected = \strlen($data);

        $prefix = substr((string) preg_replace('~[^A-Za-z0-9._-]~', '_', basename($file)), 0, 32);

        if ($prefix === '') {
            $prefix = 'html';
        }

        $temporary = tempnam($directory, $prefix . '.tmp.');

        if ($temporary === false) {
            throw new IoException("Cannot create a temporary file in '{$directory}'.", ['dir' => $directory]);
        }

        $mode ??= self::targetMode($file);

        try {
            $written = file_put_contents($temporary, $data, LOCK_EX);

            if ($written === false || $written !== $expected) {
                throw new IoException("Cannot completely write temporary file '{$temporary}'.", ['file' => $temporary]);
            }

            @chmod($temporary, $mode);

            if (!@rename($temporary, $file)) {
                $fallback = @file_put_contents($file, $data, LOCK_EX);

                if ($fallback === false || $fallback !== $expected) {
                    throw new IoException("Cannot completely write output file '{$file}'.", ['file' => $file]);
                }

                @chmod($file, $mode);
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return true;
    }

    private static function targetMode(string $file): int
    {
        if (is_file($file)) {
            $perms = fileperms($file);

            return ($perms === false ? 0644 : $perms) & 0777;
        }

        $oldUmask = umask();
        umask($oldUmask);

        return 0666 & ~$oldUmask;
    }
}
