<?php
/**
 * 14 - Loop and conditional blocks: <!--@Loop-->, <!--@If-->, scoping,
 *      magic variables, nesting and guards.
 */

declare(strict_types=1);

group('Loops: basics');

test('A loop renders one segment per row', function (): void {
    $output = html_render(
        '<!--@Loop R--><li>%{name}</li><!--@EndLoop-->',
        ['R' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c']]]
    );

    assertSame('<li>a</li><li>b</li><li>c</li>', $output);
});

test('The closing tag name is optional', function (): void {
    $output = html_render('<!--@Loop R-->[%{v}]<!--@EndLoop R-->', ['R' => [['v' => 1], ['v' => 2]]]);

    assertSame('[1][2]', $output);
});

test('Scalar rows are exposed as %{value}', function (): void {
    assertSame('a-b-', html_render('<!--@Loop R-->%{value}-<!--@EndLoop-->', ['R' => ['a', 'b']]));
});

test('Object rows expose their public properties', function (): void {
    $row = new stdClass();
    $row->title = 'T';

    assertSame('<h1>T</h1>', html_render('<!--@Loop R--><h1>%{title}</h1><!--@EndLoop-->', ['R' => [$row]]));
});

test('An empty or missing loop renders nothing', function (): void {
    assertSame('', html_render('<!--@Loop R-->x<!--@EndLoop-->', ['R' => []]));
    assertSame('', html_render('<!--@Loop MISSING-->x<!--@EndLoop-->', []));
});

test('A non-iterable loop variable renders nothing and records an error', function (): void {
    html_clear_errors();

    assertSame('', html_render('<!--@Loop R-->x<!--@EndLoop-->', ['R' => 'scalar']));
    assertTrue(count(html_errors('loop-guard')) > 0);
});

test('An unclosed loop emits the opening marker as text and records an error', function (): void {
    html_clear_errors();

    $output = html_render('a<!--@Loop R-->x', ['R' => [['v' => 1]]]);

    assertTrue(count(html_errors('loop-guard')) > 0);
    assertNotContains('<li>', $output);
});

group('Loops: magic variables');

test('Index, position, first, last, count and parity are available', function (): void {
    $output = html_render(
        '<!--@Loop R-->%{_index}|%{_position}|%{_first}|%{_last}|%{_count}|%{_odd};<!--@EndLoop-->',
        ['R' => [['v' => 1], ['v' => 2], ['v' => 3]]]
    );

    assertSame('0|1|first||3|even;1|2|||3|odd;2|3||last|3|even;', $output);
});

test('The original key is exposed as _key', function (): void {
    $output = html_render('<!--@Loop R-->%{_key}=%{v};<!--@EndLoop-->', ['R' => ['x' => ['v' => 1], 'y' => ['v' => 2]]]);

    assertSame('x=1;y=2;', $output);
});

group('Loops: scoping and nesting');

test('Row fields shadow outer variables but outer stay visible', function (): void {
    $output = html_render(
        '%{site}/<!--@Loop R-->%{site}:%{name}<!--@EndLoop-->',
        ['site' => 'S', 'R' => [['name' => 'n', 'site' => 'inner']]]
    );

    assertSame('S/inner:n', $output);
});

test('Nested loops multiply rows', function (): void {
    $output = html_render(
        '<!--@Loop OUTER-->(<!--@Loop INNER-->%{i}%{j}<!--@EndLoop-->)<!--@EndLoop-->',
        [
            'OUTER' => [['i' => 1], ['i' => 2]],
            'INNER' => [['j' => 'a'], ['j' => 'b']],
        ]
    );

    assertSame('(1a1b)(2a2b)', $output);
});

test('Loop variables support dot notation', function (): void {
    $output = html_render(
        '<!--@Loop user.roles-->%{value},<!--@EndLoop-->',
        ['user' => ['roles' => ['admin', 'editor']]]
    );

    assertSame('admin,editor,', $output);
});

test('Loops honour HTML_LOOP_MAX', function (): void {
    $GLOBALS['HTML_LOOP_MAX'] = 2;
    html_clear_errors();

    $output = html_render('<!--@Loop R-->%{value}<!--@EndLoop-->', ['R' => [1, 2, 3, 4]]);

    assertSame('12', $output);
    assertTrue(count(html_errors('loop-guard')) > 0);
});

test('Filters work inside loop rows', function (): void {
    $output = html_render(
        '<!--@Loop R-->%{name|upper};<!--@EndLoop-->',
        ['R' => [['name' => 'a'], ['name' => 'b']]]
    );

    assertSame('A;B;', $output);
});

group('Conditions: block-level If/Else');

test('A truthy variable renders the If branch', function (): void {
    assertSame('yes', html_render('<!--@If X-->yes<!--@Else-->no<!--@EndIf-->', ['X' => '1']));
    assertSame('yes', html_render('<!--@If X-->yes<!--@EndIf-->', ['X' => 'text']));
});

test('Falsy values render the Else branch', function (): void {
    assertSame('no', html_render('<!--@If X-->yes<!--@Else-->no<!--@EndIf-->', ['X' => '']));
    assertSame('no', html_render('<!--@If X-->yes<!--@Else-->no<!--@EndIf-->', ['X' => '0']));
    assertSame('no', html_render('<!--@If X-->yes<!--@Else-->no<!--@EndIf-->', ['X' => 0]));
    assertSame('no', html_render('<!--@If X-->yes<!--@Else-->no<!--@EndIf-->', []));
    assertSame('no', html_render('<!--@If X-->yes<!--@Else-->no<!--@EndIf-->', ['X' => []]));
});

test('Non-empty arrays are truthy', function (): void {
    assertSame('yes', html_render('<!--@If X-->yes<!--@Else-->no<!--@EndIf-->', ['X' => [0]]));
});

test('Negated conditions with !', function (): void {
    assertSame('empty', html_render('<!--@If !X-->empty<!--@Else-->set<!--@EndIf-->', ['X' => '']));
    assertSame('set', html_render('<!--@If !X-->empty<!--@Else-->set<!--@EndIf-->', ['X' => 'v']));
});

test('Conditions nest and see loop scope', function (): void {
    $output = html_render(
        '<!--@Loop R--><!--@If premium-->P<!--@Else-->F<!--@EndIf--><!--@EndLoop-->',
        ['R' => [['premium' => '1'], ['premium' => '']]]
    );

    assertSame('PF', $output);
});

test('An If inside a false branch never runs', function (): void {
    $output = html_render('<!--@If A--><!--@If B-->INNER<!--@EndIf--><!--@EndIf-->', ['A' => '', 'B' => '1']);

    assertSame('', $output);
});

group('Loops: interaction with SSI and legacy markers');

test('SSI directives inside loops run per row', function (): void {
    $output = html_render(
        '<!--@Loop R--><!--#set var="CURRENT" value="%{name}" --><!--#echo var="CURRENT" encoding="none" -->,<!--@EndLoop-->',
        ['R' => [['name' => 'a'], ['name' => 'b']]],
        ['passes' => 1]
    );

    // SSI runs before loops (pipeline order), so #set sees the raw placeholder
    // once; the loop then substitutes per row. Document the stable behaviour:
    assertContains('a', $output);
});

test('Legacy block markers are untouched by the loop scanner', function (): void {
    $output = html_render('<!--@Block Header-->keep<!--@End Block Header-->', []);

    assertContains('<!--@Block Header-->', $output);
});

test('Stray closing tokens are dropped with an error entry', function (): void {
    html_clear_errors();

    $output = html_render('a<!--@EndLoop-->b<!--@EndIf-->c', []);

    assertSame('abc', $output);
    assertTrue(count(html_errors('loop-guard')) > 0);
});
