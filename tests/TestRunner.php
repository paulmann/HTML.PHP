<?php
/**
 * TestRunner - a small, dependency-free test runner for the html.php library.
 *
 * The runner exposes a global helper API that test case files use:
 *
 *   group(string $name)                       start a new output section
 *   test(string $name, callable $fn)          register one test
 *   assertSame($expected, $actual)            identity comparison
 *   assertTrue($actual) / assertFalse($actual) / assertNull($actual)
 *   assertContains(string $needle, string $haystack)
 *   assertNotContains(string $needle, string $haystack)
 *   assertMatches(string $pattern, string $subject)
 *   assertThrows(string $class, callable $fn, ?string $messageContains)
 *   assertInstanceOf(string $class, mixed $actual)
 *   fail(string $message)
 *
 * A failed assertion throws TestAssertionError, which the runner catches and
 * reports with the test name, the origin file/line of the assertion and the
 * expected/actual values. The run continues with the next test.
 *
 * Every test runs with its own output buffer and with $GLOBALS/$_SERVER/... 
 * snapshotted and restored, so test order cannot matter.
 */

declare(strict_types=1);

/**
 * Raised by a failing assertion. Carries the origin of the assertion so the
 * runner can point at the test case line and not at this file.
 */
final class TestAssertionError extends Exception
{
    public readonly string $originFile;
    public readonly int $originLine;

    public function __construct(string $message, string $originFile, int $originLine)
    {
        parent::__construct($message);
        $this->originFile = $originFile;
        $this->originLine = $originLine;
    }
}

/**
 * Returns [file, line] of the first stack frame outside this file.
 *
 * @return array{0: string, 1: int}
 */
function _trOrigin(): array
{
    $self = __FILE__;
    $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16);

    foreach ($frames as $frame) {
        if (!isset($frame['file'])) {
            continue;
        }

        if ($frame['file'] !== $self) {
            return [$frame['file'], (int) ($frame['line'] ?? 0)];
        }
    }

    return [$self, 0];
}

final class TestRunner
{
    private const UNGROUPED = '(no group)';

    /** @var array<int, array{name: string, tests: array<int, array{name: string, fn: callable, file: string, line: int}>}> */
    private array $sections = [];

    /** @var array<int, array{test: string, group: string, file: string, line: int, message: string}> */
    private array $failures = [];

    private int $passed = 0;
    private int $failed = 0;
    private int $assertions = 0;
    private int $skipped = 0;
    private int $currentSection = -1;
    private string $currentTest = '';
    private string $currentGroup = self::UNGROUPED;
    private string $currentCaseFile = '';
    private bool $finished = false;
    private static ?self $instance = null;

    private function __construct(
        private readonly bool $verbose,
        private readonly bool $color,
        private readonly ?string $filter
    ) {
    }

    public static function create(bool $verbose = false, bool $color = true, ?string $filter = null): self
    {
        $runner = new self($verbose, $color, $filter !== '' ? $filter : null);
        self::$instance = $runner;

        register_shutdown_function(static function () use ($runner): void {
            if ($runner->finished) {
                return;
            }

            fwrite(STDERR, "\nHARNESS ERROR: the run did not complete (parse error, fatal error or exit).\n");
            exit(2);
        });

        return $runner;
    }

    public static function runner(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('TestRunner::create() must be called before any test or assertion.');
        }

        return self::$instance;
    }

    /**
     * Parses the command line options understood by run-tests.php.
     *
     * @param array<int, string> $argv
     * @return array{verbose: bool, color: bool, filter: ?string, list: bool}
     */
    public static function parseArguments(array $argv): array
    {
        $options = ['verbose' => false, 'color' => true, 'filter' => null, 'list' => false];

        foreach ($argv as $argument) {
            if ($argument === '--verbose' || $argument === '-v') {
                $options['verbose'] = true;
            } elseif ($argument === '--no-color') {
                $options['color'] = false;
            } elseif ($argument === '--list') {
                $options['list'] = true;
            } elseif (str_starts_with($argument, '--filter=')) {
                $options['filter'] = substr($argument, strlen('--filter='));
            }
        }

        if (getenv('NO_COLOR') !== false) {
            $options['color'] = false;
        }

        if ($options['color'] && function_exists('stream_isatty')) {
            $options['color'] = @stream_isatty(STDOUT);
        }

        return $options;
    }

    // ---------------------------------------------------------------- registry

    public function startGroup(string $name): void
    {
        $this->sections[] = ['name' => $name, 'tests' => []];
        $this->currentSection = count($this->sections) - 1;
        $this->currentGroup = $name;
    }

    public function addTest(string $name, callable $fn): void
    {
        if ($this->currentSection < 0) {
            $this->startGroup(self::UNGROUPED);
        }

        [, $line] = _trOrigin();

        $this->sections[$this->currentSection]['tests'][] = [
            'name' => $name,
            'fn' => $fn,
            'file' => $this->currentCaseFile !== '' ? $this->currentCaseFile : '',
            'line' => $line,
        ];
    }

    public function countAssertion(): void
    {
        ++$this->assertions;
    }

    public function fail(string $message): never
    {
        [$file, $line] = _trOrigin();

        throw new TestAssertionError($message, $file, $line);
    }

    /**
     * Loads one test case file. The file is executed in method scope so that
     * its top level variables cannot leak into the global namespace.
     */
    public function loadCaseFile(string $path): void
    {
        $this->currentCaseFile = $path;

        try {
            require $path;
        } finally {
            $this->currentCaseFile = '';
        }
    }

    public function loadCaseDirectory(string $directory): int
    {
        $files = glob(rtrim($directory, "/\\") . DIRECTORY_SEPARATOR . '*.test.php');

        if ($files === false) {
            throw new RuntimeException("Cannot list test cases in '{$directory}'.");
        }

        sort($files, SORT_STRING);
        $loaded = 0;

        foreach ($files as $file) {
            $this->loadCaseFile($file);
            ++$loaded;
        }

        return $loaded;
    }

    // --------------------------------------------------------------------- run

    public function run(): int
    {
        $this->printBanner();

        foreach ($this->sections as $section) {
            $tests = array_values(array_filter(
                $section['tests'],
                fn(array $test): bool => $this->matches($section['name'], $test)
            ));

            if ($tests === []) {
                continue;
            }

            $this->write($this->paint($section['name'], 'header') . "\n");

            foreach ($tests as $test) {
                $this->runOne($section['name'], $test);
            }

            $this->write("\n");
        }

        $this->printSummary();
        $this->finished = true;

        return $this->failed === 0 ? 0 : 1;
    }

    /**
     * @param array{name: string, fn: callable, file: string, line: int} $test
     */
    private function runOne(string $group, array $test): void
    {
        $this->currentTest = $test['name'];
        $snapshot = $this->snapshotGlobals();
        $superglobals = $this->snapshotSuperglobals();
        $level = ob_get_level();
        ob_start();
        $started = microtime(true);

        try {
            ($test['fn'])();
            $duration = (microtime(true) - $started) * 1000;
            ++$this->passed;
            $this->write($this->paint('  PASS  ', 'pass') . $test['name']);

            if ($this->verbose) {
                $this->write(sprintf('   (%s:%d, %.1f ms)', basename($test['file']), $test['line'], $duration));
            }

            $this->write("\n");
        } catch (Throwable $error) {
            ++$this->failed;

            if ($error instanceof TestAssertionError) {
                $file = $error->originFile;
                $line = $error->originLine;
                $message = $error->getMessage();
            } else {
                $file = $error->getFile();
                $line = $error->getLine();
                $message = get_class($error) . ': ' . $error->getMessage();
            }

            $this->failures[] = [
                'test' => $test['name'],
                'group' => $group,
                'file' => $file,
                'line' => $line,
                'message' => $message,
            ];

            $this->write($this->paint('  FAIL  ', 'fail') . $test['name'] . "\n");
            $this->write('         ' . $this->paint(basename($file) . ':' . $line, 'dim') . "\n");

            foreach (explode("\n", rtrim($message, "\n")) as $lineOfMessage) {
                $this->write('         ' . $this->paint($lineOfMessage, 'fail') . "\n");
            }
        } finally {
            while (ob_get_level() > $level) {
                $captured = (string) ob_get_clean();

                if ($this->verbose && $captured !== '') {
                    $this->write('         ' . $this->paint('captured output: ' . strlen($captured) . ' bytes', 'dim') . "\n");
                }
            }

            $this->restoreSuperglobals($superglobals);
            $this->restoreGlobals($snapshot);
            $this->currentTest = '';
        }
    }

    private function matches(string $group, ?array $test): bool
    {
        if ($this->filter === null) {
            return true;
        }

        $needle = strtolower($this->filter);
        $haystack = strtolower($group);

        if ($test !== null) {
            $haystack .= ' ' . strtolower($test['name']) . ' ' . strtolower(basename($test['file']));
        }

        return str_contains($haystack, $needle);
    }

    // ---------------------------------------------------------------- isolation

    /**
     * @return array<string, mixed>
     */
    private function snapshotGlobals(): array
    {
        $snapshot = [];

        foreach ($GLOBALS as $key => $value) {
            if ($key === 'GLOBALS') {
                continue;
            }

            $snapshot[$key] = $value;
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function restoreGlobals(array $snapshot): void
    {
        foreach ($GLOBALS as $key => $value) {
            if ($key === 'GLOBALS' || array_key_exists($key, $snapshot)) {
                continue;
            }

            unset($GLOBALS[$key]);
        }

        foreach ($snapshot as $key => $value) {
            $GLOBALS[$key] = $value;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotSuperglobals(): array
    {
        $names = ['_SERVER', '_GET', '_POST', '_COOKIE', '_FILES', '_REQUEST', '_ENV', '_SESSION'];
        $snapshot = [];

        foreach ($names as $name) {
            $snapshot[$name] = $GLOBALS[$name] ?? null;
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function restoreSuperglobals(array $snapshot): void
    {
        foreach ($snapshot as $name => $value) {
            if ($value === null && !array_key_exists($name, $GLOBALS)) {
                continue;
            }

            if ($value === null) {
                unset($GLOBALS[$name]);
                continue;
            }

            $GLOBALS[$name] = $value;
        }
    }

    // ------------------------------------------------------------------ output

    private function printBanner(): void
    {
        $this->write("\n");
        $this->write($this->paint('html.php test suite', 'header') . "\n");
        $this->write(sprintf("PHP %s | %s\n", PHP_VERSION, PHP_OS_FAMILY));

        if ($this->filter !== null) {
            $this->write('filter: ' . $this->filter . "\n");
        }

        $this->write($this->paint(str_repeat('-', 72), 'dim') . "\n\n");
    }

    private function printSummary(): void
    {
        $this->write($this->paint(str_repeat('=', 72), 'dim') . "\n");

        if ($this->failures !== []) {
            $this->write($this->paint(sprintf('FAILURES (%d)', count($this->failures)), 'fail') . "\n");

            foreach ($this->failures as $index => $failure) {
                $this->write(sprintf(
                    "%d) %s\n   in group: %s\n   at:       %s:%d\n",
                    $index + 1,
                    $failure['test'],
                    $failure['group'],
                    $failure['file'],
                    $failure['line']
                ));

                foreach (explode("\n", rtrim($failure['message'], "\n")) as $line) {
                    $this->write('   ' . $line . "\n");
                }

                $this->write("\n");
            }
        }

        $summary = sprintf(
            'SUMMARY: %d passed, %d failed (%d assertion(s))',
            $this->passed,
            $this->failed,
            $this->assertions
        );

        $this->write($this->paint($summary, $this->failed === 0 ? 'pass' : 'fail') . "\n");
    }

    private function write(string $text): void
    {
        fwrite(STDOUT, $text);
    }

    private function paint(string $text, string $style): string
    {
        if (!$this->color) {
            return $text;
        }

        $codes = [
            'pass' => "\033[32m",
            'fail' => "\033[31m",
            'header' => "\033[36;1m",
            'dim' => "\033[90m",
        ];

        return ($codes[$style] ?? '') . $text . "\033[0m";
    }
}

// ---------------------------------------------------------------- helper API

function group(string $name): void
{
    TestRunner::runner()->startGroup($name);
}

function test(string $name, callable $fn): void
{
    TestRunner::runner()->addTest($name, $fn);
}

function fail(string $message = 'test failed'): never
{
    TestRunner::runner()->fail($message);
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    TestRunner::runner()->countAssertion();

    if ($expected === $actual) {
        return;
    }

    TestRunner::runner()->fail(sprintf(
        "%sexpected: %s\nactual:   %s\ncomparison: === (%s vs %s)",
        $message !== '' ? $message . "\n" : '',
        var_export($expected, true),
        var_export($actual, true),
        get_debug_type($expected),
        get_debug_type($actual)
    ));
}

function assertTrue(mixed $actual, string $message = ''): void
{
    TestRunner::runner()->countAssertion();

    if ($actual === true) {
        return;
    }

    TestRunner::runner()->fail(sprintf(
        "%sexpected: true\nactual:   %s",
        $message !== '' ? $message . "\n" : '',
        var_export($actual, true)
    ));
}

function assertFalse(mixed $actual, string $message = ''): void
{
    TestRunner::runner()->countAssertion();

    if ($actual === false) {
        return;
    }

    TestRunner::runner()->fail(sprintf(
        "%sexpected: false\nactual:   %s",
        $message !== '' ? $message . "\n" : '',
        var_export($actual, true)
    ));
}

function assertNull(mixed $actual, string $message = ''): void
{
    TestRunner::runner()->countAssertion();

    if ($actual === null) {
        return;
    }

    TestRunner::runner()->fail(sprintf(
        "%sexpected: null\nactual:   %s",
        $message !== '' ? $message . "\n" : '',
        var_export($actual, true)
    ));
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    TestRunner::runner()->countAssertion();

    if (str_contains($haystack, $needle)) {
        return;
    }

    TestRunner::runner()->fail(sprintf(
        "%shaystack does not contain the expected substring\nneedle:   %s\nhaystack: %s",
        $message !== '' ? $message . "\n" : '',
        var_export($needle, true),
        var_export($haystack, true)
    ));
}

function assertNotContains(string $needle, string $haystack, string $message = ''): void
{
    TestRunner::runner()->countAssertion();

    if (!str_contains($haystack, $needle)) {
        return;
    }

    TestRunner::runner()->fail(sprintf(
        "%shaystack unexpectedly contains the substring\nneedle:   %s\nhaystack: %s",
        $message !== '' ? $message . "\n" : '',
        var_export($needle, true),
        var_export($haystack, true)
    ));
}

function assertMatches(string $pattern, string $subject, string $message = ''): void
{
    TestRunner::runner()->countAssertion();

    $result = @preg_match($pattern, $subject);

    if ($result === 1) {
        return;
    }

    TestRunner::runner()->fail(sprintf(
        "%ssubject does not match the pattern\npattern:  %s\nsubject:  %s\npcre:     %s",
        $message !== '' ? $message . "\n" : '',
        var_export($pattern, true),
        var_export($subject, true),
        $result === false ? preg_last_error_msg() : 'no match'
    ));
}

function assertThrows(string $class, callable $fn, ?string $messageContains = null): Throwable
{
    TestRunner::runner()->countAssertion();

    try {
        $fn();
    } catch (Throwable $error) {
        if (!$error instanceof $class) {
            TestRunner::runner()->fail(sprintf(
                "expected exception: %s\nactual exception:   %s (%s)",
                $class,
                get_class($error),
                $error->getMessage()
            ));
        }

        if ($messageContains !== null && !str_contains($error->getMessage(), $messageContains)) {
            TestRunner::runner()->fail(sprintf(
                "exception message does not contain the expected text\nexpected substring: %s\nactual message:     %s",
                var_export($messageContains, true),
                var_export($error->getMessage(), true)
            ));
        }

        return $error;
    }

    TestRunner::runner()->fail(sprintf(
        "expected exception: %s\nactual:             nothing was thrown",
        $class
    ));
}

function assertInstanceOf(string $class, mixed $actual, string $message = ''): void
{
    TestRunner::runner()->countAssertion();

    if ($actual instanceof $class) {
        return;
    }

    TestRunner::runner()->fail(sprintf(
        "%sexpected instance of: %s\nactual:               %s",
        $message !== '' ? $message . "\n" : '',
        $class,
        get_debug_type($actual)
    ));
}
