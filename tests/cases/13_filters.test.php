<?php
/**
 * 13 - Variable filters: built-ins, chains, custom filters and the
 *      interaction with numeric directives and auto-escaping.
 */

declare(strict_types=1);

group('Filters: built-ins');

test('escape/attr/url/json filters', function (): void {
    $vars = ['x' => '<b>"x"</b>'];

    assertSame('&lt;b&gt;&quot;x&quot;&lt;/b&gt;', html_render('%{x|escape}', $vars));
    assertSame('&lt;b&gt;&quot;x&quot;&lt;/b&gt;', html_render('%{x|attr}', $vars));
    assertSame('a%20b%26c', html_render('%{x|url}', ['x' => 'a b&c']));
    assertContains('\u003C', html_render('%{x|json}', $vars));
});

test('case filters are multibyte aware', function (): void {
    assertSame('ПРИВЕТ', html_render('%{x|upper}', ['x' => 'привет']));
    assertSame('привет', html_render('%{x|lower}', ['x' => 'ПРИВЕТ']));
    assertSame('Привет', html_render('%{x|ucfirst}', ['x' => 'привет']));
});

test('number filter formats with separators', function (): void {
    assertSame('1,234.57', html_render('%{x|number}', ['x' => 1234.567]));
    assertSame('1,234.567', html_render('%{x|number:3}', ['x' => 1234.567]));
    assertSame('1.234,57', html_render('%{x|number:2:,:.}', ['x' => 1234.567]));
    assertSame('0.00', html_render('%{x|number:2}', ['x' => 0]));
});

test('int/float/round/cleanround filters', function (): void {
    assertSame('3', html_render('%{x|int}', ['x' => 2.6]));
    assertSame('2.5', html_render('%{x|float}', ['x' => '2.5abc']));
    assertSame('2.50', html_render('%{x|round:2}', ['x' => 2.5]));
    assertSame('3', html_render('%{x|cleanround:2}', ['x' => 3.0]));
});

test('date filter formats timestamps and strings', function (): void {
    assertSame('2023-11-14', html_render('%{x|date:Y-m-d}', ['x' => 1700000000]));
    assertSame('2023', html_render('%{x|date:Y}', ['x' => '2023-06-01']));
    assertSame('not-a-date', html_render('%{x|date:Y}', ['x' => 'not-a-date']));
});

test('truncate/slug/striptags/nl2br/words filters', function (): void {
    assertSame('Hello…', html_render('%{x|truncate:6}', ['x' => 'Hello world']));
    assertSame('hello-world', html_render('%{x|slug}', ['x' => 'Hello World!']));
    assertSame('text', html_render('%{x|striptags}', ['x' => '<p>text</p>']));
    assertSame('a<br />' . "\n" . 'b', html_render('%{x|nl2br}', ['x' => "a\nb"]));
    assertSame('3', html_render('%{x|words}', ['x' => 'one two three']));
});

test('default/pad/repeat/raw filters', function (): void {
    assertSame('fallback', html_render('%{x|default:fallback}', ['x' => '']));
    assertSame('set', html_render('%{x|default:fallback}', ['x' => 'set']));
    assertSame('007', html_render('%{x|pad:3:0:1}', ['x' => '7']));
    assertSame('abab', html_render('%{x|repeat:2}', ['x' => 'ab']));
    assertSame('<b>x</b>', html_render('%{x|raw}', ['x' => '<b>x</b>']));
});

test('Filter chains apply left to right', function (): void {
    assertSame('&lt;B&gt;', html_render('%{x|upper|escape}', ['x' => '<b>']));
    assertSame('&LT;B&GT;', html_render('%{x|escape|upper}', ['x' => '<b>']), 'upper after escape hits the entities');
    assertSame('bob-1', html_render('%{x|upper|slug}', ['x' => 'Bob 1']), 'slugify lowercases by design');
});

test('Quoted filter arguments may contain the colon separator', function (): void {
    assertSame('14.11.2023 22:13', html_render("%{x|date:'d.m.Y H:i'}", ['x' => 1700000000]));
    assertSame('a:ba' . 'c:d', html_render("%{x|pad:7:'a:b':1}", ['x' => 'c:d']), 'str_pad repeats the quoted pad string');
});

test('An unknown filter keeps the value and records an error', function (): void {
    html_clear_errors();

    assertSame('v', html_render('%{x|nope}', ['x' => 'v']));
    assertTrue(count(html_errors('filter-unknown')) > 0);
});

group('Filters: numeric directives');

test('Numeric directives accept a filter chain', function (): void {
    assertSame('1,234.57', html_render('%{x.Round:2|number:2}', ['x' => '1234.5678']));
    assertSame('617.28', html_render('%{x.Div 2|round:2}', ['x' => '1234.5678']));
});

test('Legacy numeric directives are untouched', function (): void {
    $GLOBALS['AM'] = '1234.5678';

    assertSame('1234.57', HTML('%{AM.Round:2}'));
    assertSame('1234.57', HTML('%{AM.Clean Round:2}'));
    assertSame('308.64', HTML('%{AM.Clean Round Div 4:2}'));
});

group('Filters: custom and translation');

test('html_var_filter registers a custom filter', function (): void {
    html_var_filter('exclaim', static fn(string $value, array $args): string => $value . str_repeat('!', (int) ($args[0] ?? 1)));

    assertSame('hey!!', html_render('%{x|exclaim:2}', ['x' => 'hey']));
});

test('Custom filters work inside loops', function (): void {
    html_var_filter('bracket', static fn(string $v): string => '[' . $v . ']');

    assertSame(
        '[a][b]',
        html_render('<!--@Loop R-->%{v|bracket}<!--@EndLoop-->', ['R' => [['v' => 'a'], ['v' => 'b']]])
    );
});

test('Filters apply to @{} translations', function (): void {
    html_load_translations('de', fixture_write('filters/de.po', "msgid \"Hi\"\nmsgstr \"Hallo Welt\"\n"));
    html_set_locale('de');

    assertSame('HALLO WELT', html_render('@{Hi|upper}', []));
});

group('Filters: auto-escaping');

test('HTML_ESCAPE_VARS escapes plain substitutions', function (): void {
    $GLOBALS['HTML_ESCAPE_VARS'] = true;

    assertSame('&lt;script&gt;', html_render('%{x}', ['x' => '<script>']));
    assertSame('<script>', html_render('%{x|raw}', ['x' => '<script>']), '|raw opts out');
});

test('The escape option overrides the global flag per render', function (): void {
    assertSame('&lt;b&gt;', html_render('%{x}', ['x' => '<b>'], ['escape' => true]));
    assertSame('<b>', html_render('%{x}', ['x' => '<b>'], ['escape' => false]));
});

test('Auto-escaping does not double-escape filtered output', function (): void {
    $GLOBALS['HTML_ESCAPE_VARS'] = true;

    assertSame('&lt;b&gt;', html_render('%{x|escape}', ['x' => '<b>']));
});

test('The legacy HTML() pipeline stays unescaped by default', function (): void {
    $GLOBALS['V'] = '<b>x</b>';

    assertSame('<b>x</b>', HTML('%{V}'));
});
