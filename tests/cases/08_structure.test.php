<?php
/**
 * 08 — Structure tags: loops, conditionals, layout inheritance, partials,
 *      set/raw/comment, break/continue, guards.
 */

declare(strict_types=1);

use HtmlPhp\Diagnostics;
use HtmlPhp\Exception\TemplateNotFoundException;
use HtmlPhp\Exception\TemplateSyntaxException;
use HtmlPhp\Template\RenderOptions;

group('Loops');

test('rows mode iterates arrays with v3 shadowing semantics', function (): void {
    $engine = test_engine();

    $out = $engine->renderString(
        '<!--@Loop rows-->%{_position}.%{name} <!--@EndLoop rows-->',
        ['rows' => [['name' => 'a'], ['name' => 'b']], 'name' => 'outer']
    );
    assertSame('1.a 2.b ', $out);
});

test('alias mode binds only the alias', function (): void {
    $engine = test_engine();

    $out = $engine->renderString('<!--@Loop item in rows-->%{item}<!--@EndLoop-->', ['rows' => ['x', 'y']]);
    assertSame('xy', $out);
});

test('kv mode binds key and value', function (): void {
    $engine = test_engine();

    $out = $engine->renderString('<!--@Loop k, v in map-->%{k}=%{v};<!--@EndLoop-->', ['map' => ['a' => 1, 'b' => 2]]);
    assertSame('a=1;b=2;', $out);
});

test('range mode iterates inclusive ranges, ascending and descending', function (): void {
    $engine = test_engine();

    assertSame('123', $engine->renderString('<!--@Loop i in 1..3-->%{i}<!--@EndLoop-->', []));
    assertSame('321', $engine->renderString('<!--@Loop i in 3..1-->%{i}<!--@EndLoop-->', []));
});

test('magic loop variables are complete', function (): void {
    $engine = test_engine();

    $out = $engine->renderString(
        '<!--@Loop rows-->%{_index}:%{_position}:%{_key}:%{_first}:%{_last}:%{_count}:%{_odd}:%{_even}:%{loop.index}|<!--@EndLoop-->',
        ['rows' => ['p' => 1, 'q' => 2]]
    );
    assertSame('0:1:p:1::2::1:1|1:2:q::1:2:1::2|', $out);
});

test('scalar iterables become a single row exposed as value', function (): void {
    $engine = test_engine();

    assertSame('hit', $engine->renderString('<!--@Loop rows-->%{value}<!--@EndLoop-->', ['rows' => 'hit']));
    assertSame('hit', $engine->renderString('<!--@Loop rows-->%{value}<!--@EndLoop-->', ['rows' => ['hit']]));
});

test('objects and Traversables iterate', function (): void {
    $engine = test_engine();

    $object = new stdClass();
    $object->name = 'obj';

    $generator = new ArrayIterator([['n' => 1], ['n' => 2]]);

    assertSame('obj', $engine->renderString('<!--@Loop rows-->%{name}<!--@EndLoop-->', ['rows' => [$object]]));
    assertSame('12', $engine->renderString('<!--@Loop rows-->%{n}<!--@EndLoop-->', ['rows' => $generator]));
});

test('else branch runs for empty iterables only', function (): void {
    $engine = test_engine();

    assertSame('EMPTY', $engine->renderString('<!--@Loop rows-->x<!--@Else-->EMPTY<!--@EndLoop-->', ['rows' => []]));
    assertSame('EMPTY', $engine->renderString('<!--@Loop rows-->x<!--@Else-->EMPTY<!--@EndLoop-->', []));
    assertSame('x', $engine->renderString('<!--@Loop rows-->x<!--@Else-->EMPTY<!--@EndLoop-->', ['rows' => [1]]));
});

test('nested loops keep separate scopes', function (): void {
    $engine = test_engine();

    $out = $engine->renderString(
        '<!--@Loop outer-->(%{name}:<!--@Loop inner-->%{v}<!--@EndLoop-->)<!--@EndLoop-->',
        ['outer' => [
            ['name' => 'a', 'inner' => [['v' => 1], ['v' => 2]]],
            ['name' => 'b', 'inner' => [['v' => 3]]],
        ]]
    );
    assertSame('(a:12)(b:3)', $out);
});

test('break and continue control iteration', function (): void {
    $engine = test_engine();

    $out = $engine->renderString('<!--@Loop i in 1..5--><!--@If i == 4--><!--@Break--><!--@EndIf-->%{i}<!--@EndLoop-->', []);
    assertSame('123', $out);

    $out = $engine->renderString('<!--@Loop i in 1..4--><!--@If i % 2 == 0--><!--@Continue--><!--@EndIf-->%{i}<!--@EndLoop-->', []);
    assertSame('13', $out);
});

test('row limit truncates and diagnoses', function (): void {
    $engine = test_engine(['limits' => ['max_loop_rows' => 3]]);

    assertSame('123', $engine->renderString('<!--@Loop i in 1..100-->%{i}<!--@EndLoop-->', []));
    assertSame(true, $engine->diagnostics()->count(Diagnostics::LOOP_GUARD) > 0);
});

test('loop nesting depth is guarded', function (): void {
    $engine = test_engine(['limits' => ['max_loop_depth' => 1]]);

    $out = $engine->renderString('<!--@Loop rows--><!--@Loop inner-->x<!--@EndLoop--><!--@EndLoop-->', [
        'rows' => [['inner' => [1]]],
    ]);
    assertSame('', $out);
    assertSame(true, $engine->diagnostics()->count(Diagnostics::LOOP_GUARD) > 0);
});

test('loop sources may be dotted paths', function (): void {
    $engine = test_engine();

    assertSame('bc', $engine->renderString('<!--@Loop k, v in nested.map-->%{v}<!--@EndLoop-->', ['nested' => ['map' => ['x' => 'b', 'y' => 'c']]]));
});

group('Conditionals');

test('if/elif/else/endif with expressions', function (): void {
    $engine = test_engine();

    assertSame('two', $engine->renderString('<!--@If n == 1-->one<!--@Elif n == 2-->two<!--@Else-->many<!--@EndIf-->', ['n' => 2]));
    assertSame('many', $engine->renderString('<!--@If n == 1-->one<!--@Elif n == 2-->two<!--@Else-->many<!--@EndIf-->', ['n' => 9]));
    assertSame('yes', $engine->renderString('<!--@If name == "bob" && age >= 18-->yes<!--@EndIf-->', ['name' => 'bob', 'age' => 20]));
    assertSame('no', $engine->renderString('<!--@If !(flag || other)-->no<!--@EndIf-->', ['flag' => false, 'other' => false]));
    assertSame('in', $engine->renderString('<!--@If "b" in list-->in<!--@EndIf-->', ['list' => ['a', 'b']]));
    assertSame('m', $engine->renderString('<!--@If s matches "/^a.c$/"-->m<!--@EndIf-->', ['s' => 'abc']));
});

test('unless negates', function (): void {
    $engine = test_engine();

    assertSame('shown', $engine->renderString('<!--@Unless hidden-->shown<!--@EndUnless-->', ['hidden' => false]));
    assertSame('', $engine->renderString('<!--@Unless hidden-->shown<!--@EndUnless-->', ['hidden' => true]));
});

test('truthiness: empty string, "0", empty array are false', function (): void {
    $engine = test_engine();

    assertSame('f', $engine->renderString('<!--@If v-->t<!--@Else-->f<!--@EndIf-->', ['v' => '']));
    assertSame('f', $engine->renderString('<!--@If v-->t<!--@Else-->f<!--@EndIf-->', ['v' => '0']));
    assertSame('f', $engine->renderString('<!--@If v-->t<!--@Else-->f<!--@EndIf-->', ['v' => []]));
    assertSame('t', $engine->renderString('<!--@If v-->t<!--@Else-->f<!--@EndIf-->', ['v' => [0]]));
});

test('ternary, coalesce, concatenation and arithmetic in expressions', function (): void {
    $engine = test_engine();

    assertSame('yes', $engine->renderString('<!--@If a ? true : false-->yes<!--@EndIf-->', ['a' => 1]));
    assertSame('fb', $engine->renderString('<!--@Set v="missing ?? \'fb\'"-->%{v}', []));
    assertSame('ab', $engine->renderString('<!--@Set v="\'a\' ~ \'b\'"-->%{v}', []));
    assertSame('7', $engine->renderString('<!--@Set v="3 + 2 * 2"-->%{v}', []));
    assertSame('2', $engine->renderString('<!--@Set v="7 % 5"-->%{v}', []));
    assertSame('0', $engine->renderString('<!--@Set v="5 / 0"-->%{v}', [])); // guarded division
});

test('expression functions are whitelisted', function (): void {
    $engine = test_engine();

    assertSame('3', $engine->renderString('<!--@Set n="count(list)"-->%{n}', ['list' => [1, 2, 3]]));
    assertSame('t', $engine->renderString('<!--@If empty(list)-->t<!--@EndIf-->', ['list' => []]));
    assertSame('9', $engine->renderString('<!--@Set n="max(3, 9)"-->%{n}', []));
    assertThrows(TemplateSyntaxException::class, static fn() => $engine->renderString('<!--@Set n="system(\'ls\'}"-->', []));
});

test('broken expressions diagnose in lenient mode, throw in strict', function (): void {
    $lenient = test_engine();
    assertSame('', $lenient->renderString('<!--@If (( -->x<!--@EndIf-->', []));
    assertSame(true, $lenient->diagnostics()->count(Diagnostics::TEMPLATE_LINT) > 0);

    $strict = test_engine(['syntax' => ['strict' => true]]);
    assertThrows(TemplateSyntaxException::class, static fn() => $strict->renderString('<!--@If (( -->x<!--@EndIf-->', []));
});

test('set writes into the current scope frame only', function (): void {
    $engine = test_engine();

    $out = $engine->renderString('<!--@Loop rows--><!--@Set x="v * 2"-->%{x}<!--@EndLoop-->%{x}', ['rows' => [['v' => 2]], 'x' => 'root']);
    assertSame('4root', $out);
});

group('Raw and comments');

test('raw blocks are verbatim, comments disappear', function (): void {
    $engine = test_engine();

    assertSame('%{keep} <!--@If x-->', $engine->renderString('<!--@Raw-->%{keep} <!--@If x--><!--@EndRaw-->', ['keep' => 'GONE']));
    assertSame('ab', $engine->renderString('a<!--@Comment--> secret %{x} <!--@EndComment-->b', []));
});

group('Layout inheritance');

test('child overrides parent sections; defaults survive', function (): void {
    fixture_write('tpl/layout.html', '<h><!--@Section title-->default<!--@EndSection--></h><d><!--@Section body-->DBODY<!--@EndSection--></d>');
    fixture_write('tpl/child.html', '<!--@Extends "layout.html"--><!--@Section title-->Child<!--@EndSection-->');

    $engine = test_engine();
    assertSame('<h>Child</h><d>DBODY</d>', $engine->render('child.html'));
});

test('multi-level inheritance resolves deepest override first', function (): void {
    fixture_write('tpl/grand.html', '[<!--@Section c-->G<!--@EndSection-->]');
    fixture_write('tpl/parent.html', '<!--@Extends "grand.html"--><!--@Section c-->P<!--@EndSection-->');
    fixture_write('tpl/kid.html', '<!--@Extends "parent.html"--><!--@Section c-->K<!--@EndSection-->');

    $engine = test_engine();
    assertSame('[K]', $engine->render('kid.html'));
});

test('SectionParent renders the next level down', function (): void {
    fixture_write('tpl/base2.html', '<!--@Section box-->BASE<!--@EndSection-->');
    fixture_write('tpl/wrap.html', '<!--@Extends "base2.html"--><!--@Section box-->(<!--@SectionParent-->)<!--@EndSection-->');

    $engine = test_engine();
    assertSame('(BASE)', $engine->render('wrap.html'));
});

test('child content outside sections is dropped', function (): void {
    fixture_write('tpl/l3.html', '<!--@Section s-->D<!--@EndSection-->');
    fixture_write('tpl/c3.html', 'IGNORED<!--@Extends "l3.html"--><!--@Section s-->V<!--@EndSection-->ALSO IGNORED');

    $engine = test_engine();
    assertSame('V', $engine->render('c3.html'));
});

test('missing layout throws TemplateNotFound', function (): void {
    fixture_write('tpl/c4.html', '<!--@Extends "nowhere.html"-->');
    $engine = test_engine();

    assertThrows(TemplateNotFoundException::class, static fn() => $engine->render('c4.html'));
});

group('Partials');

test('include inherits the current scope by default', function (): void {
    fixture_write('tpl/parts/hello.html', 'Hi %{name}');
    fixture_write('tpl/use.html', '<!--@Include "parts/hello.html"-->!');

    $engine = test_engine();
    assertSame('Hi Bob!', $engine->render('use.html', ['name' => 'Bob']));
});

test('only isolates and with maps selected variables', function (): void {
    fixture_write('tpl/parts/iso.html', '[%{a}|%{b}]');
    fixture_write('tpl/use2.html', '<!--@Include "parts/iso.html" only--><!--@Include "parts/iso.html" with="a"-->');

    $engine = test_engine();
    assertSame('[|][A|]', $engine->render('use2.html', ['a' => 'A', 'b' => 'B']));
});

test('ignore-missing skips silently, missing without it throws', function (): void {
    $engine = test_engine();

    assertSame('ok', $engine->renderString('ok<!--@Include "ghost.html" ignore-missing-->', []));
    assertThrows(TemplateNotFoundException::class, static fn() => $engine->renderString('<!--@Include "ghost.html"-->', []));
});

test('partials resolve relative to the including document too', function (): void {
    fixture_write('tpl/deep/dir/sibling.html', 'SIB');
    fixture_write('tpl/deep/dir/page.html', '<!--@Include "sibling.html"-->');

    $engine = test_engine();
    assertSame('SIB', $engine->render('deep/dir/page.html'));
});

test('partials may contain loops and substitutions', function (): void {
    fixture_write('tpl/parts/rows.html', '<!--@Loop rows-->%{n}<!--@EndLoop-->');

    $engine = test_engine();
    assertSame('12', $engine->renderString('<!--@Include "parts/rows.html"-->', ['rows' => [['n' => 1], ['n' => 2]]]));
});

test('namespaced templates resolve through @ns roots', function (): void {
    fixture_write('admin-views/dash.html', 'ADMIN');
    $engine = test_engine(['templates' => [
        'paths' => [fixture_path('tpl')],
        'namespaces' => ['@admin' => fixture_path('admin-views')],
    ]]);

    assertSame('ADMIN', $engine->render('@admin/dash.html'));
});

test('default extension is appended', function (): void {
    fixture_write('tpl/noext.html', 'N');
    $engine = test_engine();

    assertSame('N', $engine->render('noext'));
});

group('Parser strictness');

test('unbalanced and unknown tags are hard errors', function (): void {
    $engine = test_engine();

    assertThrows(TemplateSyntaxException::class, static fn() => $engine->renderString('<!--@Loop a-->x', []));
    assertThrows(TemplateSyntaxException::class, static fn() => $engine->renderString('<!--@EndIf-->', []));
    assertThrows(TemplateSyntaxException::class, static fn() => $engine->renderString('<!--@Frobnicate-->', []));
    assertThrows(TemplateSyntaxException::class, static fn() => $engine->renderString('<!--@Else-->', []));
});

test('unclosed raw is an error', function (): void {
    $engine = test_engine();

    assertThrows(TemplateSyntaxException::class, static fn() => $engine->renderString('<!--@Raw-->x', []));
});

test('mismatched end tags are errors with line info', function (): void {
    $engine = test_engine();

    try {
        $engine->renderString("line1\n<!--@Loop rows-->x<!--@EndIf-->", ['rows' => []]);
        fail('expected TemplateSyntaxException');
    } catch (TemplateSyntaxException $error) {
        assertSame(2, $error->context['line'] ?? 0);
        assertContains('Unexpected', $error->getMessage());
    }
});
