<?php

/**
 * 06 — SSI conditionals (#if/#elif/#else/#endif), expressions and #exec.
 */

declare(strict_types=1);

use HtmlPhp\Diagnostics;

group('SSI: conditionals');

test('simple truthiness on set variables', function (): void {
    $engine = test_engine();

    assertSame('yes', ssi($engine, '<!--#set var="F" value="1" --><!--#if expr="$F" -->yes<!--#else-->no<!--#endif-->'));
    assertSame('no', ssi($engine, '<!--#if expr="$MISSING" -->yes<!--#else-->no<!--#endif-->'));
    assertSame('no', ssi($engine, '<!--#set var="Z" value="0" --><!--#if expr="$Z" -->yes<!--#else-->no<!--#endif-->'));
});

test('string and numeric comparisons', function (): void {
    $engine = test_engine();

    assertSame('1', ssi($engine, '<!--#set var="A" value="abc" --><!--#if expr="$A = \'abc\'" -->1<!--#endif-->'));
    assertSame('1', ssi($engine, '<!--#set var="A" value="abc" --><!--#if expr="$A != \'xyz\'" -->1<!--#endif-->'));
    assertSame('1', ssi($engine, '<!--#set var="N" value="10" --><!--#if expr="$N > 9" -->1<!--#endif-->'));
    assertSame('1', ssi($engine, '<!--#set var="N" value="10" --><!--#if expr="$N <= 10" -->1<!--#endif-->'));
    assertSame('', ssi($engine, '<!--#set var="N" value="10" --><!--#if expr="$N < 10" -->1<!--#endif-->'));
});

test('regex matching with /pattern/', function (): void {
    $engine = test_engine();

    assertSame('m', ssi($engine, '<!--#set var="U" value="Mozilla/5.0" --><!--#if expr="$U = /Mozilla/" -->m<!--#endif-->'));
    assertSame('n', ssi($engine, '<!--#set var="U" value="curl" --><!--#if expr="$U = /Mozilla/" -->m<!--#else-->n<!--#endif-->'));
});

test('logical operators, negation and grouping', function (): void {
    $engine = test_engine();

    assertSame('1', ssi($engine, '<!--#set var="A" value="1" --><!--#set var="B" value="" --><!--#if expr="$A && !$B" -->1<!--#endif-->'));
    assertSame('1', ssi($engine, '<!--#if expr="(\'a\' = \'a\') || (\'b\' = \'c\')" -->1<!--#endif-->'));
    assertSame('', ssi($engine, '<!--#if expr="!(\'a\' = \'a\')" -->1<!--#endif-->'));
});

test('elif chains take the first matching branch only', function (): void {
    $engine = test_engine();

    $tpl = '<!--#set var="V" value="2" -->'
        . '<!--#if expr="$V = \'1\'" -->one'
        . '<!--#elif expr="$V = \'2\'" -->two'
        . '<!--#elif expr="$V = \'2\'" -->also-two'
        . '<!--#else-->other<!--#endif-->';

    assertSame('two', ssi($engine, $tpl));
});

test('nested conditionals suppress whole subtrees', function (): void {
    $engine = test_engine();

    $tpl = '<!--#if expr="\'\' = \'\'" -->A<!--#if expr="\'x\' = \'y\'" -->B<!--#else-->C<!--#endif-->D<!--#else-->E<!--#endif-->';
    assertSame('ACD', ssi($engine, $tpl));
});

test('inactive branches drop includes and sets', function (): void {
    $engine = test_engine();
    fixture_write('tpl/ssi/never.html', 'NEVER');

    $out = ssi($engine, '<!--#if expr="\'\' " --><!--#include virtual="/ssi/never.html" --><!--#set var="S" value="1" --><!--#endif-->[<!--#echo var="S" -->]');
    assertSame('[]', $out);
});

test('orphan #endif / #elif are diagnosed and tolerated', function (): void {
    $engine = test_engine();

    assertSame('x', ssi($engine, 'x<!--#endif-->'));
    assertSame(true, $engine->diagnostics()->count(Diagnostics::SSI_EXPR_ERROR) > 0);
});

test('broken expressions are false + diagnostic, never fatal', function (): void {
    $engine = test_engine();

    assertSame('no', ssi($engine, '<!--#if expr="$A = " -->yes<!--#else-->no<!--#endif-->'));
    assertSame('no', ssi($engine, '<!--#if expr="(((" -->yes<!--#else-->no<!--#endif-->'));
    assertSame(true, $engine->diagnostics()->count(Diagnostics::SSI_EXPR_ERROR) > 0);
});

test('file tests -f -d -s -e -x -r work inside the root', function (): void {
    $engine = test_engine();
    $path = fixture_write('tpl/ssi/file.txt', 'content');
    @mkdir(fixture_path('tpl/ssi/dir'), 0777, true);
    fixture_write('tpl/ssi/empty.txt', '');

    assertSame('f', ssi($engine, '<!--#if expr="-f /ssi/file.txt" -->f<!--#endif-->'));
    assertSame('d', ssi($engine, '<!--#if expr="-d /ssi/dir" -->d<!--#endif-->'));
    assertSame('s', ssi($engine, '<!--#if expr="-s /ssi/file.txt" -->s<!--#endif-->'));
    assertSame('', ssi($engine, '<!--#if expr="-s /ssi/empty.txt" -->s<!--#endif-->'));
    assertSame('e', ssi($engine, '<!--#if expr="-e /ssi/file.txt" -->e<!--#endif-->'));
    assertSame('', ssi($engine, '<!--#if expr="-f /ssi/missing.txt" -->f<!--#endif-->'));
    assertSame('', ssi($engine, '<!--#if expr="-f ../../outside.txt" -->f<!--#endif-->'));
    assertSame('F', ssi($engine, '<!--#if expr="-F /ssi/file.txt" -->F<!--#endif-->'));
});

test('-A/-U subrequest tests are refused with a diagnostic', function (): void {
    $engine = test_engine();

    assertSame('', ssi($engine, '<!--#if expr="-A /ssi/file.txt" -->x<!--#endif-->'));
    assertSame(true, $engine->diagnostics()->count(Diagnostics::SSI_EXPR_ERROR) > 0);
});

test('${NAME} braces and direct string operands', function (): void {
    $engine = test_engine();

    assertSame('1', ssi($engine, '<!--#set var="A" value="v" --><!--#if expr="${A} = \'v\'" -->1<!--#endif-->'));
    assertSame('1', ssi($engine, '<!--#if expr="\'plain\' = \'plain\'" -->1<!--#endif-->'));
});

test('expression length is capped', function (): void {
    $engine = test_engine(['limits' => ['max_expression_length' => 32]]);

    assertSame('no', ssi($engine, '<!--#if expr="\'' . str_repeat('x', 64) . '\' = \'' . str_repeat('x', 64) . '\'" -->yes<!--#else-->no<!--#endif-->'));
});

group('SSI: #exec');

test('exec is denied by default and records the denial', function (): void {
    $engine = test_engine();

    assertSame('', ssi($engine, '<!--#exec cmd="echo pwned" -->'));
    assertSame(true, $engine->diagnostics()->count(Diagnostics::SSI_EXEC_DENIED) > 0);
});

test('denied exec emits errmsg when configured', function (): void {
    $engine = test_engine(['ssi' => ['error_message' => '[denied]']]);

    assertSame('[denied]', ssi($engine, '<!--#exec cmd="echo x" -->'));
});

test('allow-list mode runs whitelisted binaries only', function (): void {
    if (!is_executable('/bin/echo')) {
        skip('/bin/echo not available');
    }

    $engine = test_engine(['ssi' => [
        'exec_mode' => 'allow-list',
        'exec_cmd_allow' => ['/bin/echo'],
    ]]);

    assertSame('hello', trim(ssi($engine, '<!--#exec cmd="/bin/echo hello" -->')));
    assertSame('', ssi($engine, '<!--#exec cmd="/bin/cat /etc/passwd" -->'));
    assertSame(true, $engine->diagnostics()->count(Diagnostics::SSI_EXEC_DENIED) > 0);
});

test('command tokenization honours quotes without a shell', function (): void {
    $tokens = HtmlPhp\Ssi\Exec::tokenizeCommand('/bin/echo "two words" \'single\' plain');

    assertSame(['/bin/echo', 'two words', 'single', 'plain'], $tokens);
});

test('delegate policy decides per directive', function (): void {
    $engine = test_engine(['ssi' => [
        'exec_mode' => 'delegate',
        'exec_delegate' => static fn(string $kind, array $info): bool|string => $info['target'] === 'ok' ? 'DELEGATED' : false,
    ]]);

    assertSame('DELEGATED', ssi($engine, '<!--#exec cmd="ok" -->'));
    assertSame('', ssi($engine, '<!--#exec cmd="nope" -->'));
});

test('cgi include mode runs allowed extensions in-process', function (): void {
    $engine = test_engine(['ssi' => ['exec_mode' => 'allow-list', 'exec_cgi_mode' => 'include']]);
    fixture_write('tpl/ssi/script.php', '<?php echo "CGI:" . (1 + 1);');

    assertSame('CGI:2', ssi($engine, '<!--#exec cgi="/ssi/script.php" -->'));
});

test('cgi refuses extensions outside the allow-list', function (): void {
    $engine = test_engine(['ssi' => ['exec_mode' => 'allow-list', 'exec_cgi_mode' => 'include']]);
    fixture_write('tpl/ssi/script.pl', 'print "x";');

    assertSame('', ssi($engine, '<!--#exec cgi="/ssi/script.pl" -->'));
});

test('cgi include mode captures throws as errmsg, not fatal errors', function (): void {
    $engine = test_engine(['ssi' => ['exec_mode' => 'allow-list', 'exec_cgi_mode' => 'include', 'error_message' => '[E]']]);
    fixture_write('tpl/ssi/bad.php', '<?php throw new RuntimeException("boom");');

    assertSame('[E]', ssi($engine, '<!--#exec cgi="/ssi/bad.php" -->'));
    assertSame(true, $engine->diagnostics()->count(Diagnostics::SSI_EXEC_FAILED) > 0);
});

test('exec output is capped by max_exec_output_bytes', function (): void {
    if (!is_executable('/bin/echo')) {
        skip('/bin/echo not available');
    }

    $engine = test_engine([
        'ssi' => ['exec_mode' => 'allow-list', 'exec_cmd_allow' => ['/bin/echo']],
        'limits' => ['max_exec_output_bytes' => 8],
    ]);

    $out = ssi($engine, '<!--#exec cmd="/bin/echo 0123456789abcdef" -->');
    assertSame(true, strlen($out) <= 8);
});
