<?php

/**
 * Test suite entry point (v4).
 *
 * Usage:
 *   php8.3 tests/run.php
 *   php8.5 tests/run.php --lib=php85/html.php     (bundle under test)
 *   php8.3 tests/run.php --filter=ssi --verbose
 *   php8.3 tests/run.php --list
 *
 * Exit codes: 0 all passed · 1 failures · 2 harness error.
 */

declare(strict_types=1);

$root = __DIR__;

try {
    require_once $root . DIRECTORY_SEPARATOR . 'TestRunner.php';

    $options = TestRunner::parseArguments($argv ?? []);

    if (is_string($options['lib']) && $options['lib'] !== '') {
        define('HTML_PHP_LIB', $options['lib']);
    }

    require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

    // WordPress stubs are always available; cases decide whether to use them.
    require_once $root . DIRECTORY_SEPARATOR . 'wp-stubs.php';
    require_once $root . DIRECTORY_SEPARATOR . 'wp-stubs-extra.php';

    $runner = TestRunner::create($options['verbose'], $options['color'], $options['filter']);
    $loaded = $runner->loadCaseDirectory($root . DIRECTORY_SEPARATOR . 'cases');

    if ($loaded === 0) {
        throw new RuntimeException('No test case files were found in ' . $root . DIRECTORY_SEPARATOR . 'cases');
    }

    if ($options['list']) {
        // Inventory mode: print the registry and exit without running tests.
        $runner->listTests();
        cleanup_fixtures();
        exit(0);
    }

    $exitCode = $runner->run();
} catch (Throwable $error) {
    fwrite(STDERR, sprintf(
        "HARNESS ERROR: %s: %s\n  at %s:%d\n",
        get_class($error),
        $error->getMessage(),
        $error->getFile(),
        $error->getLine(),
    ));

    cleanup_fixtures();
    exit(2);
}

cleanup_fixtures();

exit($exitCode);
