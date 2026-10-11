<?php

/**
 * 02 — Support layer: Str, Pcre, clocks, Files, AtomicWriter, PathGuard.
 */

declare(strict_types=1);

use HtmlPhp\Io\AtomicWriter;
use HtmlPhp\Io\Files;
use HtmlPhp\Io\PathGuard;
use HtmlPhp\Support\FrozenClock;
use HtmlPhp\Support\Pcre;
use HtmlPhp\Support\Str;
use HtmlPhp\Support\SystemClock;

group('Str: stringify & numbers');

test('stringify covers every scalar plus Stringable and enums', function (): void {
    assertSame('', Str::stringify(null));
    assertSame('x', Str::stringify('x'));
    assertSame('42', Str::stringify(42));
    assertSame('1.5', Str::stringify(1.5));
    assertSame('1', Str::stringify(true));
    assertSame('', Str::stringify(false));
    assertSame('', Str::stringify(['a']));
    assertSame('obj', Str::stringify(new class implements Stringable {
        public function __toString(): string
        {
            return 'obj';
        }
    }));
    assertSame('abbrev', Str::stringify(HtmlPhp\Config\SizeFormat::Abbrev));
});

test('numericValue parses international formats', function (): void {
    assertSame(1234567.89, Str::numericValue('1 234 567,89'));
    assertSame(1234567.89, Str::numericValue('1,234,567.89'));
    assertSame(1234567.89, Str::numericValue("1'234'567.89"));
    assertSame(1.5, Str::numericValue('1,5'));
    assertSame(1.234, Str::numericValue('1,234')); // single comma = decimal separator
    assertSame(-2.5, Str::numericValue(' -2.5 '));
    assertSame(0.0, Str::numericValue('abc'));
    assertSame(0.0, Str::numericValue(null));
    assertSame(0.0, Str::numericValue(INF));
});

test('round / cleanRound / precision semantics', function (): void {
    assertSame('1.24', Str::round(1.235, 2));
    assertSame('0.00', Str::round(-0.001, 2));
    assertSame('0', Str::round(-0.2, 0));
    assertSame('3', Str::cleanRound(3.0, 2));
    assertSame('3.14', Str::cleanRound(3.14159, 2));
    assertSame('0', Str::cleanRound(NAN, 2));
    assertSame(5, Str::precision('5'));
    assertSame(0, Str::precision('x'));
    assertSame(100, Str::precision(5000));
});

test('number formats with explicit separators and no -0', function (): void {
    assertSame('1,234.50', Str::number(1234.5));
    assertSame('1.234,50', Str::number(1234.5, 2, ',', '.'));
    assertSame('0.00', Str::number(-0.0001, 2));
});

test('divide guards zero divisors and non-finite results', function (): void {
    assertSame(0, Str::divide(5, 0));
    assertSame(2.5, Str::divide(5, 2));
    assertSame(0, Str::divide(INF, 0));
});

group('Str: text tools');

test('truncate cuts on word boundaries, multibyte-safe', function (): void {
    assertSame('hello', Str::truncate('hello', 10));
    assertSame('hello…', Str::truncate('hello world', 8));
    assertSame('привет…', Str::truncate('привет мир', 8));
    assertSame('abc…', Str::truncate('abcdef', 3));
});

test('slugify is deterministic with and without ext-intl', function (): void {
    // Map-covered characters resolve identically regardless of ext-intl:
    // the built-in map is applied before the ICU fallback (see Str::slugify).
    assertSame('privet-mir', Str::slugify('Привет мир'));
    assertSame('ae-b-c', Str::slugify('Ä B C!')); // map wins: ä → ae (ICU alone would give 'a')
    assertSame('hello-privet-world', Str::slugify('Hello Привет World'));
    assertSame('n-a', Str::slugify('!!!'));
    assertSame('x_y', Str::slugify('x y', '_'));
});

test('slugify falls back to ICU for scripts outside the built-in map', function (): void {
    // Greek is not in TRANSLITERATION: with intl it romanises via ICU,
    // without intl the unmapped letters are stripped like any non-[a-z0-9].
    if (function_exists('transliterator_transliterate')) {
        assertTrue(Str::slugify('Αθήνα') !== 'n-a');
    } else {
        assertSame('n-a', Str::slugify('Αθήνα'));
    }
});

test('keepWordChars strips everything but letters, digits, underscore', function (): void {
    assertSame('abc_123', Str::keepWordChars('  a b-c_1.2!3 '));
    assertSame('Привет1', Str::keepWordChars('Привет, 1!'));
});

test('normalizeEntities converts hex to decimal and applies the map', function (): void {
    assertSame('&#65;', Str::normalizeEntities('&#x41;'));
    assertSame('&blank;', Str::normalizeEntities('&#160;', ['160' => 'blank']));
    assertSame('thdss ', Str::normalizeEntities('&thorn;&eth;&szlig;&nbsp;'));
});

test('formatSize matches Apache abbrev style', function (): void {
    assertSame('512', Str::formatSize(512));
    assertSame('1K', Str::formatSize(1024));
    assertSame('1.3K', Str::formatSize(1250)); // Apache rounds up
    assertSame('1.5M', Str::formatSize(1572864));
    assertSame('2048', Str::formatSize(2048, 'bytes'));
    assertSame('0', Str::formatSize(-5));
});

test('isValidVariableName accepts dots and rejects tricks', function (): void {
    assertSame(true, Str::isValidVariableName('user.name'));
    assertSame(true, Str::isValidVariableName('_x1'));
    assertSame(false, Str::isValidVariableName(''));
    assertSame(false, Str::isValidVariableName("a\0b"));
    assertSame(false, Str::isValidVariableName('/etc/passwd'));
});

test('sanitizeRecursive encodes nested strings only', function (): void {
    $result = Str::sanitizeRecursive(['a' => '<b>', 'n' => ['c' => "&'"], 'keep' => 5]);

    assertSame('&lt;b&gt;', $result['a']);
    assertSame('&amp;&apos;', $result['n']['c']);
    assertSame(5, $result['keep']);
});

group('Pcre');

test('match returns false (never null) on invalid patterns', function (): void {
    assertSame(false, Pcre::match('~(unclosed~', 'x'));
    assertSame(true, Pcre::match('~\d+~', '42'));
});

test('replaceCallback throws RegexException on PCRE failure', function (): void {
    // Catastrophic backtracking hits PREG_BACKTRACK_LIMIT_ERROR.
    assertThrows(
        HtmlPhp\Exception\RegexException::class,
        static fn() => Pcre::replaceCallback('~^(?:1+)+$~', static fn(array $m): string => 'x', str_repeat('1', 32) . '!'),
    );
});

test('split and matchAll behave', function (): void {
    assertSame(['a', 'b'], Pcre::split('~[,;]~', 'a,b'));
    $matches = [];
    assertSame(2, Pcre::matchAll('~\d~', 'a1b2', $matches));
});

group('Clocks');

test('frozen clock is deterministic and movable', function (): void {
    $clock = new FrozenClock(1_700_000_000);
    assertSame(1_700_000_000, $clock->now());
    $clock->advance(60);
    assertSame(1_700_000_060, $clock->now());
    assertSame('2023-11-14T22:14:20+00:00', $clock->dateTime('UTC')->format('c'));
    assertSame(true, (new SystemClock())->now() > 1_700_000_000);
});

group('Io');

test('Files::slurp honours caps and rejects junk paths', function (): void {
    $path = fixture_write('io/file.txt', 'hello');

    assertSame('hello', Files::slurp($path));
    assertSame(null, Files::slurp($path, 2));
    assertSame(null, Files::slurp($path . '.missing'));
    assertSame(null, Files::slurp("with\0nul"));
});

test('Files::extension and isInside', function (): void {
    assertSame('php', Files::extension('/a/b/c.PHP'));
    assertSame('', Files::extension('/a/b/Makefile'));
    assertSame(true, Files::isInside('/a/b/c', '/a/b'));
    assertSame(true, Files::isInside('/a/b', '/a/b'));
    assertSame(false, Files::isInside('/a/bc', '/a/b'));
    assertSame(false, Files::isInside('/a/b', ''));
});

test('AtomicWriter writes, preserves modes and creates nothing outside', function (): void {
    $path = fixture_write('io/atomic.txt', 'first');
    chmod($path, 0640);
    AtomicWriter::write($path, 'second');

    assertSame('second', (string) file_get_contents($path));

    // NTFS does not store POSIX modes — permission preservation is a
    // POSIX-platform guarantee and is only asserted there.
    if (PHP_OS_FAMILY !== 'Windows') {
        assertSame('640', substr(sprintf('%o', (int) fileperms($path)), -3));
    }

    assertThrows(
        HtmlPhp\Exception\IoException::class,
        static fn() => AtomicWriter::write(fixture_path('io/does/not/exist/x.txt'), 'nope'),
    );
});

group('PathGuard');

test('resolves inside roots and refuses traversal', function (): void {
    $root = fixture_path('guard');
    fixture_write('guard/ok.html', 'x');
    fixture_write('guard/sub/ok2.html', 'x');
    fixture_write('outside.html', 'secret');

    $guard = new PathGuard([$root]);

    assertSame(realpath(fixture_path('guard/ok.html')), $guard->resolve('ok.html'));
    assertSame(realpath(fixture_path('guard/sub/ok2.html')), $guard->resolve('sub/ok2.html'));
    assertSame(null, $guard->resolve('../outside.html'));
    assertSame(null, $guard->resolve('/etc/passwd'));
    assertSame(null, $guard->resolve("nul\0.html"));
});

test('denied extensions and dotfiles never resolve', function (): void {
    $root = fixture_path('guard');
    fixture_write('guard/secret.php', '<?php echo 1;');
    fixture_write('guard/.env', 'DB=1');
    fixture_write('guard/notes.txt', 'x');

    $guard = new PathGuard([$root]);

    assertSame(null, $guard->resolve('secret.php'));
    assertSame(null, $guard->resolve('.env'));
    assertSame(true, $guard->resolve('notes.txt') !== null);
});

test('baseDir resolution and allowParentPaths', function (): void {
    $root = fixture_path('guard');
    fixture_write('guard/a/b.html', 'x');
    $guard = new PathGuard([$root]);

    assertSame(realpath(fixture_path('guard/a/b.html')), $guard->resolve('b.html', fixture_path('guard/a')));

    $lenient = new PathGuard([$root], [], true);
    assertSame(true, $lenient->resolve('../outside.html') !== null);
});
