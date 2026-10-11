<?php
/**
 * 17 - Public helper functions: escaping, slugs, truncation and the
 *      error/config plumbing.
 */

declare(strict_types=1);

group('Helpers: escaping');

test('html_escape encodes the HTML text context', function (): void {
    assertSame('&lt;a href=&quot;x&quot;&gt;&amp;&apos;', html_escape('<a href="x">&\''));
});

test('html_escape_attr encodes quotes', function (): void {
    assertSame('a&quot;b&apos;c', html_escape_attr('a"b\'c'));
});

test('html_escape_url keeps structural characters but encodes values', function (): void {
    assertSame('/path/page?a=1%26b=%D0%B6', html_escape_url('/path/page?a=1&b=ж'));
});

test('A custom HTML_ESCAPER is honoured', function (): void {
    $GLOBALS['HTML_ESCAPER'] = static fn(string $v): string => strtoupper($v);

    assertSame('ABC', html_escape('abc'));
    assertSame('ABC', processSSI('<!--#set var="E_VAR" value="abc" --><!--#echo var="E_VAR" -->'));
});

test('sanitizeXSS walks nested arrays (legacy semantics)', function (): void {
    $result = sanitizeXSS(['a' => '<b>', 'nested' => ['c' => '&"'], 'n' => 5, 'null' => null]);

    assertSame('&lt;b&gt;', $result['a']);
    assertSame('&amp;&quot;', $result['nested']['c']);
    assertSame('5', $result['n']);
    assertSame('', $result['null']);
});

group('Helpers: strings');

test('html_slugify produces URL-safe slugs', function (): void {
    assertSame('hello-world', html_slugify('Hello World'));
    assertSame('a-b-c', html_slugify('  a -- b ,, c  '));
    assertSame('privet-mir', html_slugify('Привет мир'), 'transliteration when intl is present, ASCII otherwise');
    assertSame('n-a', html_slugify('!!!'));
    assertSame('custom_sep', html_slugify('Custom Sep', '_'));
});

test('html_truncate respects word boundaries', function (): void {
    assertSame('Hello world', html_truncate('Hello world', 20));
    assertSame('Hello…', html_truncate('Hello wonderful world', 9));
    assertSame('Hello wonder…', html_truncate('Hello wonderful world', 12));
    assertSame('abc…', html_truncate('abcdef', 3));
    assertSame('Ok!', html_truncate('Ok!', 10, '...'));
});

test('html_truncate strips dangling punctuation before the suffix', function (): void {
    assertSame('Hello…', html_truncate('Hello, world', 6));
});

test('Multibyte truncation counts characters, not bytes', function (): void {
    assertSame('Я теб…', html_truncate('Я тебя люблю', 5));
});

group('Helpers: diagnostics');

test('html_errors filters by code and html_clear_errors resets', function (): void {
    html_clear_errors();
    html_render('%{DIAG_A}', []);
    processSSI('<!--#include virtual="/diagnostic-missing.htm" -->');

    assertTrue(count(html_errors('missing-var')) >= 1);
    assertTrue(count(html_errors()) >= 2);
    assertTrue(count(html_errors('ssi-include-failed')) >= 1);

    html_clear_errors();

    assertSame([], html_errors());
});

test('Error entries carry code, message, context and origin', function (): void {
    html_clear_errors();
    html_render('%{DIAG_B}', []);

    $entries = html_errors('missing-var');
    $entry = $entries[0];

    assertSame('DIAG_B', $entry['context']['name']);
    assertTrue($entry['message'] !== '');
    assertTrue($entry['where'] !== '');
});

test('The error list is capped against runaway templates', function (): void {
    html_clear_errors();
    html_render(str_repeat('%{CAP_MISS}', 500), []);

    assertTrue(count(html_errors()) <= 100);
});

group('Helpers: reset');

test('html_reset_state clears hooks, filters, catalogues and errors', function (): void {
    html_add_filter('reset-check', static fn($v) => 'hooked');
    html_var_filter('resetfilter', static fn(string $v): string => 'x');
    html_load_translations('sv', fixture_write('helpers/sv.po', "msgid \"Hej\"\nmsgstr \"Hej!\"\n"));
    html_set_locale('sv');
    html_clear_errors();
    html_render('%{RESET_MISSING}', []);

    html_reset_state();

    assertSame('pass', html_apply_filters('reset-check', 'pass'));
    assertSame('Hej', html_t('Hej'), 'catalogue dropped');
    assertSame([], html_errors());
});
