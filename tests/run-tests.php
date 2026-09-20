<?php
/**
 * Test suite entry point.
 *
 * Usage:
 *   php tests/run-tests.php
 *   php tests/run-tests.php --filter=security
 *   php tests/run-tests.php --verbose
 *   php tests/run-tests.php --filter=include --verbose
 *   php tests/run-tests.php --list
 *
 * Exit codes:
 *   0  every selected test passed
 *   1  at least one test failed
 *   2  harness or bootstrap error
 *
 * Paths are resolved from __DIR__, so the current working directory does not
 * matter.
 */

declare(strict_types=1);

$root = __DIR__;

try {
    require_once $root . DIRECTORY_SEPARATOR . 'TestRunner.php';
    require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

    $options = TestRunner::parseArguments($argv ?? []);
    $runner = TestRunner::create($options['verbose'], $options['color'], $options['filter']);
    $loaded = $runner->loadCaseDirectory($root . DIRECTORY_SEPARATOR . 'cases');

    if ($loaded === 0) {
        throw new RuntimeException('No test case files were found in ' . $root . DIRECTORY_SEPARATOR . 'cases');
    }

    $exitCode = $runner->run();
} catch (Throwable $error) {
    fwrite(STDERR, sprintf(
        "HARNESS ERROR: %s: %s\n  at %s:%d\n",
        get_class($error),
        $error->getMessage(),
        $error->getFile(),
        $error->getLine()
    ));

    cleanup_fixtures();
    exit(2);
}

cleanup_fixtures();

exit($exitCode);
