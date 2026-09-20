<?php
/**
 * 01 - Foundation: the file contract and the public API surface.
 */

declare(strict_types=1);

group('Foundation: library file contract');

test('The library source declares strict types', function (): void {
    $source = (string) file_get_contents(library_path());

    assertContains('declare(strict_types=1);', $source);
});

test('The library file contains no byte order mark', function (): void {
    $head = (string) file_get_contents(library_path(), false, null, 0, 3);

    assertSame('<?p', $head, 'a UTF-8 BOM would precede the opening tag and break header() in host applications');
});

test('The library reports its version strings', function (): void {
    $source = (string) file_get_contents(library_path());

    assertContains('VERSION: 2026-09-20.2', $source);
    assertContains('Library version: 2.0.1', $source);
});

test('The library guards the minimum PHP version', function (): void {
    $source = (string) file_get_contents(library_path());

    assertContains('PHP_VERSION_ID < 80300', $source);
    assertTrue(PHP_VERSION_ID >= 80300, 'the test interpreter must satisfy the library requirement');
});

test('The library documents its change log in the file header', function (): void {
    $source = (string) file_get_contents(library_path());

    assertContains('CHANGE LOG:', $source);
    assertContains('2026-09-20.2', $source);
    assertContains('2026-09-20.1', $source);
    assertContains('2026-09-16.1', $source);
    assertContains('2026-09-04.2', $source);
    assertContains('2026-09-03.1', $source);
});

test('The library is a single dependency-free file', function (): void {
    $source = (string) file_get_contents(library_path());

    assertSame(0, preg_match('~^\s*(require|include)(_once)?\s*[(\'"]~m', $source), 'the library must not require or include other files');
    assertNotContains('namespace ', $source, 'the library exposes plain global functions');
});

group('Foundation: public API surface');

test('Every documented public function is declared and callable', function (): void {
    $functions = [
        'LoadTemplate',
        'LoadVar',
        'processHtml',
        'HTML',
        'update_template',
        'sanitizeXSS',
        'HTML_Sanitized_Input',
        'processSSI',
        'Read_HTML',
        'Show_HTML',
        'Divide',
        'lc_Tags',
        'normalize',
        'clean',
        'clean_html',
        'WriteFile',
        'TrimSpaces',
    ];

    foreach ($functions as $function) {
        assertTrue(function_exists($function), "{$function}() must be declared");
        assertTrue(is_callable($function), "{$function}() must be callable");
        assertInstanceOf(ReflectionFunction::class, new ReflectionFunction($function));
    }
});

test('The public API keeps its documented parameter shapes', function (): void {
    $expected = [
        'LoadTemplate' => [1, false],
        'LoadVar' => [2, false],
        'processHtml' => [2, false],
        'HTML' => [1, false],
        'update_template' => [1, false],
        'sanitizeXSS' => [1, false],
        'HTML_Sanitized_Input' => [0, false],
        'processSSI' => [1, false],
        'Read_HTML' => [1, false],
        'Show_HTML' => [0, true],
        'Divide' => [2, false],
        'lc_Tags' => [1, false],
        'normalize' => [1, false],
        'clean' => [2, false],
        'clean_html' => [1, false],
        'WriteFile' => [2, false],
        'TrimSpaces' => [1, false],
    ];

    foreach ($expected as $function => [$required, $variadic]) {
        $reflection = new ReflectionFunction($function);

        assertSame($required, $reflection->getNumberOfRequiredParameters(), "{$function}() required parameters");
        assertSame($variadic, $reflection->isVariadic(), "{$function}() variadic flag");
    }
});

test('processSSI() keeps the legacy single argument signature', function (): void {
    $reflection = new ReflectionFunction('processSSI');

    assertSame(2, $reflection->getNumberOfParameters());
    assertSame(1, $reflection->getNumberOfRequiredParameters());
    assertSame(null, $reflection->getParameters()[1]->getDefaultValue());
});

test('normalize() keeps its three optional parameters', function (): void {
    $reflection = new ReflectionFunction('normalize');

    assertSame(3, $reflection->getNumberOfParameters());
    assertSame(1, $reflection->getNumberOfRequiredParameters());
    assertSame(null, $reflection->getParameters()[1]->getDefaultValue());
    assertSame(false, $reflection->getParameters()[2]->getDefaultValue());
});

test('The reserved global and built-in SSI name tables are published', function (): void {
    assertTrue(defined('HTML_RESERVED_GLOBALS'));
    assertTrue(defined('HTML_SSI_BUILTIN_VARS'));

    foreach (['GLOBALS', '_SERVER', '_GET', '_POST', '_COOKIE', '_SESSION', '_REQUEST', '_ENV'] as $name) {
        assertTrue(HTML_RESERVED_GLOBALS[$name] ?? false, "{$name} must be reserved");
    }

    foreach (['DATE_LOCAL', 'DATE_GMT', 'DOCUMENT_URI', 'DOCUMENT_NAME', 'QUERY_STRING'] as $name) {
        assertTrue(HTML_SSI_BUILTIN_VARS[$name] ?? false, "{$name} must be a built-in SSI variable");
    }
});

test('The configuration defaults are the hardened ones', function (): void {
    assertSame(16, $GLOBALS['HTML_MAX_SSI_DEPTH']);
    assertSame(256, $GLOBALS['HTML_MAX_SSI_TAGS']);
    assertSame(2, $GLOBALS['HTML_MAX_TEMPLATE_PASSES']);
    assertSame(64, $GLOBALS['HTML_MAX_BLOCK_DEPTH']);
    assertSame(100, $GLOBALS['HTML_MAX_PRECISION']);
    assertSame(false, $GLOBALS['HTML_SSI_ALLOW_PARENT']);
    assertSame(true, $GLOBALS['HTML_SSI_ECHO_ESCAPE']);
    assertSame(false, $GLOBALS['HTML_AUTO_SANITIZE_INPUT']);
    assertSame('%A, %d-%b-%Y %H:%M:%S %Z', $GLOBALS['HTML_SSI_TIMEFMT']);
    assertSame([], $GLOBALS['HTML_SSI_ECHO_ALLOW']);
    assertSame(true, $GLOBALS['HTML_ALLOWED_TAGS']['p']);
    assertSame(false, isset($GLOBALS['HTML_ALLOWED_TAGS']['script']));
});

test('The allow-list contains only presentation tags', function (): void {
    $tags = array_keys($GLOBALS['HTML_ALLOWED_TAGS']);

    assertSame(12, count($tags));
    assertContains('br', implode(',', $tags));
    assertNotContains('script', implode(',', $tags));
    assertNotContains('style', implode(',', $tags));
    assertNotContains('a', implode(',', $tags));
});

group('Foundation: isolation');

test('The harness provides a scratch directory under tests/tmp', function (): void {
    $directory = fixture_dir();

    assertTrue(is_dir($directory), 'fixture_dir() must create the directory');
    assertContains('tmp', $directory);
    assertContains('tests', $directory);
    assertSame($directory . DIRECTORY_SEPARATOR . 'probe.txt', fixture_path('probe.txt'));
    assertSame(fixture_path('probe/nested.txt'), fixture_write('probe/nested.txt', 'body'));
    assertSame('body', (string) file_get_contents(fixture_path('probe/nested.txt')));
});

test('A clean interpreter can bootstrap the library and render a template', function (): void {
    $code = isolated_library_include() . ';'
        . 'date_default_timezone_set("UTC");'
        . '$GLOBALS["NAME"] = "clean";'
        . '$rendered = HTML("Hi %{NAME}!");'
        . 'echo $rendered === "Hi clean!" ? "META_OK" : "META_FAIL:" . $rendered;';

    [$exitCode, $output] = run_isolated_php($code);

    assertSame(0, $exitCode, 'isolated interpreter exit code');
    assertContains('META_OK', $output);
});

test('A clean interpreter starts with the hardened defaults', function (): void {
    $code = isolated_library_include() . ';'
        . 'echo (($GLOBALS["HTML_SSI_ECHO_ESCAPE"] === true) ? "escape=on" : "escape=off"), "|",'
        . '(($GLOBALS["HTML_AUTO_SANITIZE_INPUT"] === false) ? "autosanitize=off" : "autosanitize=on"), "|",'
        . '(($GLOBALS["HTML_SSI_ALLOW_PARENT"] === false) ? "parent=off" : "parent=on");';

    [$exitCode, $output] = run_isolated_php($code);

    assertSame(0, $exitCode, 'isolated interpreter exit code');
    assertContains('escape=on', $output);
    assertContains('autosanitize=off', $output);
    assertContains('parent=off', $output);
});

test('A test may write to $GLOBALS and to the library configuration', function (): void {
    $GLOBALS['HTML_TEST_LEAK'] = 'written';
    $GLOBALS['HTML_SSI_TIMEFMT'] = '%Y';

    assertSame('written', $GLOBALS['HTML_TEST_LEAK']);
    assertSame('%Y', $GLOBALS['HTML_SSI_TIMEFMT']);
    assertSame(gmdate('Y'), processSSI('<!--#echo var="DATE_GMT" -->'));
});

test('Global state written by an earlier test is rolled back', function (): void {
    assertFalse(array_key_exists('HTML_TEST_LEAK', $GLOBALS), 'a previous test leaked a global');
    assertSame('%A, %d-%b-%Y %H:%M:%S %Z', $GLOBALS['HTML_SSI_TIMEFMT'], 'a previous test leaked a configuration change');
    assertMatches('~^\w+, \d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2} \w+$~', processSSI('<!--#echo var="DATE_GMT" -->'));
});

test('A test may write to a request superglobal', function (): void {
    $_SERVER['REQUEST_URI'] = '/leak/check.htm';

    assertSame('/leak/check.htm', $_SERVER['REQUEST_URI']);
    assertSame('/leak/check.htm', processSSI('<!--#echo var="DOCUMENT_URI" -->'));
});

test('Superglobals written by an earlier test are rolled back', function (): void {
    assertFalse(array_key_exists('REQUEST_URI', $_SERVER), 'a previous test leaked a superglobal');
});
