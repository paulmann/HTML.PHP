<?php
/**
 * Test suite bootstrap.
 *
 * - loads the library under test exactly once,
 * - pins a deterministic environment (UTC, no locale surprises),
 * - offers temp fixture helpers rooted at tests/tmp/.
 *
 * Nothing here may depend on the current working directory: every path is
 * derived from __DIR__.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'html.php';

date_default_timezone_set('UTC');

/**
 * Absolute path of the library under test.
 */
function library_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'html.php';
}

/**
 * Absolute path of the runtime scratch directory (tests/tmp).
 */
function fixture_dir(): string
{
    static $directory = null;

    if ($directory === null) {
        $directory = __DIR__ . DIRECTORY_SEPARATOR . 'tmp';

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create the fixture directory '{$directory}'.");
        }
    }

    return $directory;
}

/**
 * Absolute path of a fixture, whether or not it exists yet.
 */
function fixture_path(string $relative): string
{
    $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relative, "/\\"));

    if ($relative === '' || str_contains($relative, '..')) {
        throw new InvalidArgumentException("Invalid fixture path '{$relative}'.");
    }

    return fixture_dir() . DIRECTORY_SEPARATOR . $relative;
}

/**
 * Writes a fixture file, creating parent directories, and returns its path.
 */
function fixture_write(string $relative, string $body): string
{
    $path = fixture_path($relative);
    $directory = dirname($path);

    if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
        throw new RuntimeException("Cannot create the fixture directory '{$directory}'.");
    }

    if (file_put_contents($path, $body) !== strlen($body)) {
        throw new RuntimeException("Cannot write the fixture '{$path}'.");
    }

    return $path;
}

/**
 * Removes a fixture directory tree. Missing paths are not an error.
 */
function remove_tree(string $path): void
{
    if ($path === '' || !file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_link($path) || is_file($path)) {
        @chmod($path, 0o666);
        @unlink($path);

        return;
    }

    $entries = scandir($path);

    if ($entries !== false) {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            remove_tree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }

    @rmdir($path);
}

/**
 * Deletes every fixture created during the run.
 */
function cleanup_fixtures(): void
{
    remove_tree(fixture_dir());
}

/**
 * Runs a PHP snippet in a fresh interpreter and returns [exitCode, output].
 *
 * proc_open() with an argument vector is used so no shell quoting is involved.
 *
 * @return array{0: int, 1: string}
 */
function run_isolated_php(string $code): array
{
    $command = [
        PHP_BINARY,
        '-d', 'error_reporting=E_ALL',
        '-d', 'display_errors=1',
        '-d', 'log_errors=0',
        '-r', $code,
    ];

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start an isolated PHP process.');
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout . $stderr];
}

/**
 * Builds a `php -r` snippet that requires the library under test.
 */
function isolated_library_include(): string
{
    return 'require ' . var_export(library_path(), true) . ';';
}
