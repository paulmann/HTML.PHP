<?php
/**
 * Test suite bootstrap (v4).
 *
 * - loads the library under test exactly once:
 *     default      → src/ through autoload.php,
 *     --lib=PATH   → a built single-file bundle (php83/html.php, php85/html.php),
 *     HTML_PHP_LIB → same via environment variable;
 * - pins a deterministic environment (UTC);
 * - offers temp fixture helpers rooted at tests/tmp/;
 * - offers engine factory helpers shared by every case.
 *
 * Nothing here may depend on the current working directory: every path is
 * derived from __DIR__.
 */

declare(strict_types=1);

/**
 * Resolves what is under test: 'src' (PSR-4 tree) or a bundle path.
 */
function library_path(): string
{
    static $resolved = null;

    if ($resolved !== null) {
        return $resolved;
    }

    $candidate = defined('HTML_PHP_LIB') && is_string(HTML_PHP_LIB) && HTML_PHP_LIB !== ''
        ? HTML_PHP_LIB
        : (string) getenv('HTML_PHP_LIB');

    if ($candidate !== '' && is_file($candidate)) {
        $resolved = (realpath($candidate) ?: $candidate);

        return $resolved;
    }

    $resolved = 'src';

    return $resolved;
}

/** True when the suite runs against a single-file bundle. */
function testing_bundle(): bool
{
    return library_path() !== 'src';
}

if (testing_bundle()) {
    require_once library_path();
} else {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'autoload.php';
}

date_default_timezone_set('UTC');

/** Absolute path of the runtime scratch directory (tests/tmp). */
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

/** Absolute path of a fixture, whether or not it exists yet. */
function fixture_path(string $relative): string
{
    $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relative, "/\\"));

    if ($relative === '' || str_contains($relative, '..')) {
        throw new InvalidArgumentException("Invalid fixture path '{$relative}'.");
    }

    return fixture_dir() . DIRECTORY_SEPARATOR . $relative;
}

/** Writes a fixture file, creating parent directories; returns its path. */
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

/** Removes a directory tree. Missing paths are not an error. */
function remove_tree(string $path): void
{
    if ($path === '' || (!file_exists($path) && !is_link($path))) {
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

/** Deletes every fixture created during the run. */
function cleanup_fixtures(): void
{
    remove_tree(fixture_dir());
}

/**
 * Runs a PHP snippet in a fresh interpreter and returns [exitCode, output].
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

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
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

/** `php -r` prelude that loads the library under test. */
function isolated_library_include(): string
{
    return testing_bundle()
        ? 'require ' . var_export(library_path(), true) . ';'
        : 'require ' . var_export(dirname(__DIR__) . '/autoload.php', true) . ';';
}

// ---------------------------------------------------------------- test engine

use HtmlPhp\Config\MissingVarPolicy;
use HtmlPhp\Engine;
use HtmlPhp\RequestContext;
use HtmlPhp\Support\FrozenClock;
use HtmlPhp\Template\RenderOptions;

/**
 * Builds an engine rooted at the fixture directory with sane test defaults
 * (auto-escape on, array logger, deterministic clock).
 *
 * @param array<string, mixed> $config merged over the defaults
 */
function test_engine(array $config = [], ?FrozenClock $clock = null): Engine
{
    $base = [
        'debug' => true,
        'templates' => ['paths' => [fixture_path('tpl')]],
        'log' => ['driver' => 'array'],
        'ssi' => ['document_root' => fixture_path('tpl')],
    ];

    $engine = Engine::create(array_replace_recursive($base, $config), static function ($container) use ($clock): void {
        if ($clock !== null) {
            $container->instance(HtmlPhp\Support\ClockInterface::class, $clock);
        }
    });

    if (!is_dir(fixture_path('tpl'))) {
        @mkdir(fixture_path('tpl'), 0o777, true);
    }

    return $engine;
}

/** Request context pinned to deterministic test values. */
function test_request(array $server = []): RequestContext
{
    return RequestContext::fromServer(array_merge([
        'DOCUMENT_ROOT' => fixture_path('tpl'),
        'REQUEST_URI' => '/index.shtml?a=1',
        'SCRIPT_NAME' => '/index.shtml',
        'QUERY_STRING' => 'a=1',
        'REQUEST_METHOD' => 'GET',
        'SERVER_NAME' => 'example.test',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_USER_AGENT' => 'HtmlPhpTests/4.0',
        'HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9,en;q=0.5',
    ], $server));
}

/** Render options with the test request + fixture document root. */
function test_options(?string $file = null, array $overrides = []): RenderOptions
{
    return new RenderOptions(
        file: $file,
        root: $overrides['root'] ?? fixture_path('tpl'),
        request: $overrides['request'] ?? test_request(),
        ssi: $overrides['ssi'] ?? null,
        structure: $overrides['structure'] ?? null,
        autoEscape: $overrides['escape'] ?? null,
        missingVar: $overrides['missing'] ?? null,
        cache: $overrides['cache'] ?? null,
        ttl: $overrides['ttl'] ?? null,
        locale: $overrides['locale'] ?? null,
        ssiVars: $overrides['ssi_vars'] ?? null
    );
}
