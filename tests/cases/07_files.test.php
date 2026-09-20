<?php
/**
 * 07 - WriteFile(): atomic writes, byte fidelity and error reporting.
 */

declare(strict_types=1);

/**
 * Lists the entries of a directory without the dot entries.
 *
 * @return array<int, string>
 */
$listing = static function (string $directory): array {
    $entries = scandir($directory);

    return $entries === false ? [] : array_values(array_diff($entries, ['.', '..']));
};

/**
 * Creates a fixture directory idempotently and returns its absolute path.
 */
$makeDir = static function (string $relative): string {
    $path = fixture_path($relative);

    if (!is_dir($path)) {
        mkdir($path, 0o777, true);
    }

    return $path;
};

/**
 * Installs a strict error handler that respects error_reporting(), so that
 * diagnostics suppressed with "@" are ignored while real warnings throw.
 *
 * @param array<int, string> $caught
 */
$installStrictHandler = static function (array &$caught): void {
    set_error_handler(static function (int $number, string $message, string $file, int $line) use (&$caught): bool {
        if ((error_reporting() & $number) === 0) {
            return false;
        }

        $caught[] = $message;

        throw new ErrorException($message, 0, $number, $file, $line);
    });
};

group('Files: WriteFile() content fidelity');

test('WriteFile() writes the exact bytes and returns true', function () use ($listing, $makeDir): void {
    $directory = $makeDir('write/basic');
    $target = $directory . DIRECTORY_SEPARATOR . 'payload.bin';
    $payload = "line 1\r\nline 2\nbinary \x00\x01\xFF end";

    $result = WriteFile($target, $payload);

    assertTrue($result);
    assertSame($payload, (string) file_get_contents($target));
    assertSame(strlen($payload), filesize($target));
    assertSame(['payload.bin'], $listing($directory), 'no temporary file may survive a successful write');
});

test('WriteFile() writes an empty payload', function (): void {
    $target = fixture_path('write/empty.txt');

    assertTrue(WriteFile($target, ''));
    assertSame('', (string) file_get_contents($target));
    assertSame(0, filesize($target));
});

test('WriteFile() overwrites an existing file completely', function (): void {
    $target = fixture_path('write/overwrite.txt');

    WriteFile($target, str_repeat('long content ', 100));
    WriteFile($target, 'short');

    assertSame('short', (string) file_get_contents($target));
    assertSame(5, filesize($target));
});

test('WriteFile() writes a large payload without truncation', function (): void {
    $target = fixture_path('write/large.txt');
    $payload = str_repeat('0123456789abcdef', 20000);

    assertTrue(WriteFile($target, $payload));
    assertSame(strlen($payload), filesize($target));
    assertSame(md5($payload), md5_file($target));
});

test('WriteFile() converts scalar data to its string form', function (): void {
    $target = fixture_path('write/scalars.txt');

    WriteFile($target, 42);
    assertSame('42', (string) file_get_contents($target));

    WriteFile($target, 1.5);
    assertSame('1.5', (string) file_get_contents($target));

    WriteFile($target, null);
    assertSame('', (string) file_get_contents($target));

    WriteFile($target, true);
    assertSame('1', (string) file_get_contents($target));

    WriteFile($target, ['a' => 1]);
    assertSame('', (string) file_get_contents($target), 'an array has no string form');
});

test('WriteFile() keeps the existing file permissions', function (): void {
    $target = fixture_path('write/perms.txt');
    WriteFile($target, 'first');

    $before = fileperms($target) & 0o777;
    WriteFile($target, 'second');

    assertSame($before, fileperms($target) & 0o777);
    assertSame('second', (string) file_get_contents($target));
});

group('Files: WriteFile() error reporting');

test('WriteFile() throws for an empty path', function (): void {
    $error = assertThrows(InvalidArgumentException::class, static fn(): bool => WriteFile('', 'x'));

    assertContains('path is invalid', $error->getMessage());
});

test('WriteFile() throws for a path containing a null byte', function (): void {
    $error = assertThrows(
        InvalidArgumentException::class,
        static fn(): bool => WriteFile(fixture_path('write/null.txt') . "\0suffix", 'x')
    );

    assertContains('path is invalid', $error->getMessage());
});

test('WriteFile() throws for a non existent output directory', function (): void {
    $error = assertThrows(
        RuntimeException::class,
        static fn(): bool => WriteFile(fixture_path('write/missing-dir/file.txt'), 'x')
    );

    assertContains('is not writable', $error->getMessage());
});

test('WriteFile() throws for a read only destination file', function (): void {
    $target = fixture_path('write/readonly.txt');
    WriteFile($target, 'first');
    chmod($target, 0o444);
    clearstatcache();

    $error = assertThrows(RuntimeException::class, static fn(): bool => WriteFile($target, 'second'));

    assertContains('is not writable', $error->getMessage());
    assertContains('readonly.txt', $error->getMessage());

    chmod($target, 0o666);
    assertSame('first', (string) file_get_contents($target), 'the original content must survive');
});

test('WriteFile() reports a directory destination without emitting a warning', function () use ($listing, $installStrictHandler, $makeDir): void {
    $directory = $makeDir('write/dir-target');
    $target = $directory . DIRECTORY_SEPARATOR . 'a-directory';
    mkdir($target);

    $caught = [];
    $installStrictHandler($caught);

    try {
        $error = assertThrows(RuntimeException::class, static fn(): bool => WriteFile($target, 'x'));
    } finally {
        restore_error_handler();
    }

    assertSame([], $caught, 'no un-suppressed PHP warning may escape');
    assertContains('Cannot completely write output file', $error->getMessage());
    assertSame(['a-directory'], $listing($directory), 'no temporary file may survive a failed write');
});

test('WriteFile() cleans up its temporary file after a failure', function () use ($listing, $makeDir): void {
    $directory = $makeDir('write/cleanup');
    $target = $directory . DIRECTORY_SEPARATOR . 'a-directory';
    mkdir($target);

    try {
        WriteFile($target, 'x');
    } catch (RuntimeException) {
        // expected
    }

    clearstatcache();
    assertSame(['a-directory'], $listing($directory));
});

test('WriteFile() leaves no temporary file in the directory after a success', function () use ($listing, $makeDir): void {
    $directory = $makeDir('write/leftovers');

    WriteFile($directory . DIRECTORY_SEPARATOR . 'one.txt', 'one');
    WriteFile($directory . DIRECTORY_SEPARATOR . 'two.txt', 'two');

    $entries = $listing($directory);
    sort($entries);

    assertSame(['one.txt', 'two.txt'], $entries);
});

group('Files: Read_HTML() and LoadTemplate() file errors');

test('LoadTemplate() reports an unreadable template path', function () use ($makeDir): void {
    $directory = $makeDir('write/template-dir');

    assertSame('Template Not Found', LoadTemplate($directory));
});

test('Read_HTML() throws for a directory path', function () use ($makeDir): void {
    $directory = $makeDir('write/html-dir');

    assertThrows(RuntimeException::class, static fn(): string => Read_HTML($directory));
});
