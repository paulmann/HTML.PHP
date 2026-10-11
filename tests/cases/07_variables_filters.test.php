<?php

/**
 * 07 — Variable substitution, filters, escaping and missing-var policies.
 */

declare(strict_types=1);

use HtmlPhp\Config\MissingVarPolicy;
use HtmlPhp\Diagnostics;
use HtmlPhp\Exception\TemplateException;
use HtmlPhp\Template\RenderOptions;

group('Substitution: forms');

test('%{NAME}, #{NAME} and dot paths substitute', function (): void {
    $engine = test_engine();

    assertSame('Hi Bob', $engine->renderString('Hi %{name}', ['name' => 'Bob']));
    assertSame('Hi Bob', $engine->renderString('Hi #{name}', ['name' => 'Bob']));
    assertSame('Berlin', $engine->renderString('%{user.address.city}', ['user' => ['address' => ['city' => 'Berlin']]]));
    assertSame('x', $engine->renderString('%{obj.label}', ['obj' => new class {
        public function label(): string
        {
            return 'x';
        }
    }]));
});

test('auto-escape is ON by default (v4 hardening)', function (): void {
    $engine = test_engine();

    assertSame('&lt;script&gt;', $engine->renderString('%{x}', ['x' => '<script>']));
    assertSame('<script>', $engine->renderString('%{x|raw}', ['x' => '<script>']));
});

test('auto-escape can be disabled globally and per-render', function (): void {
    $engine = test_engine(['syntax' => ['auto_escape' => false]]);
    assertSame('<b>', $engine->renderString('%{x}', ['x' => '<b>']));

    $strict = test_engine();
    assertSame('<b>', $strict->renderString('%{x}', ['x' => '<b>'], new RenderOptions(autoEscape: false)));
});

test('chained escaping filters suppress double-escaping', function (): void {
    $engine = test_engine();

    assertSame('&lt;b&gt;', $engine->renderString('%{x|escape}', ['x' => '<b>']));
    assertSame('&lt;b&gt;', $engine->renderString('%{x|attr}', ['x' => '<b>']));
    assertSame('&lt;b&gt;', $engine->renderString('%{x|nl2br}', ['x' => '<b>'])); // nl2br escapes, then breaks
    assertContains('<br', $engine->renderString('%{x|nl2br}', ['x' => "a\nb"]));
});

test('non-variable braces survive (CSS/JS safe)', function (): void {
    $engine = test_engine();

    assertSame('body { color: red; }', $engine->renderString('body { color: red; }', []));
    assertSame('if (a) { b(); }', $engine->renderString('if (a) { b(); }', []));
    assertSame('{1,2}', $engine->renderString('{1,2}', []));
});

test('single_braces opts into {NAME} form', function (): void {
    $engine = test_engine(['syntax' => ['single_braces' => true]]);

    assertSame('Bob', $engine->renderString('{name}', ['name' => 'Bob']));
    assertSame('body { color: red; }', $engine->renderString('body { color: red; }', [])); // invalid names stay
});

test('custom delimiters are configurable', function (): void {
    $engine = test_engine(['syntax' => ['var_open' => '${{', 'var_close' => '}}']]);

    assertSame('Bob', $engine->renderString('${{name}}', ['name' => 'Bob']));
    assertSame('%{name}', $engine->renderString('%{name}', ['name' => 'Bob']));
});

group('Substitution: missing variables');

test('policy empty (default) records a diagnostic once per name', function (): void {
    $engine = test_engine();

    assertSame('[]', $engine->renderString('[%{gone}%{gone}]', []));
    assertSame(1, $engine->diagnostics()->count(Diagnostics::MISSING_VAR));
});

test('policy keep preserves the placeholder', function (): void {
    $engine = test_engine(['syntax' => ['missing_var' => 'keep']]);

    assertSame('[%{gone}]', $engine->renderString('[%{gone}]', []));
});

test('policy placeholder shows a debug marker', function (): void {
    $engine = test_engine(['syntax' => ['missing_var' => 'placeholder']]);

    assertSame('[[missing:gone]]', $engine->renderString('[%{gone}]', []));
});

test('policy throw aborts the render', function (): void {
    $engine = test_engine(['syntax' => ['missing_var' => 'throw']]);

    assertThrows(TemplateException::class, static fn() => $engine->renderString('%{gone}', []));
});

test('per-render policy override wins', function (): void {
    $engine = test_engine();

    assertSame('keep:%{gone}', $engine->renderString('keep:%{gone}', [], new RenderOptions(missingVar: MissingVarPolicy::Keep)));
});

test('null values stringify to empty without diagnostics', function (): void {
    $engine = test_engine();

    assertSame('[]', $engine->renderString('[%{x}]', ['x' => null]));
    assertSame(0, $engine->diagnostics()->count(Diagnostics::MISSING_VAR));
});

group('Filters');

test('string filters', function (): void {
    $engine = test_engine();

    assertSame('ABC', $engine->renderString('%{x|upper}', ['x' => 'abc']));
    assertSame('abc', $engine->renderString('%{x|lower}', ['x' => 'ABC']));
    assertSame('Abc', $engine->renderString('%{x|ucfirst}', ['x' => 'abc']));
    assertSame('Hello World', $engine->renderString('%{x|title}', ['x' => 'hello world']));
    assertSame('3', $engine->renderString('%{x|words}', ['x' => 'one two three']));
    assertSame('ab', $engine->renderString('%{x|trim:.}', ['x' => '.ab.']));
    assertSame('hexxo', $engine->renderString('%{x|replace:l:x}', ['x' => 'hello']));
});

test('numeric filters', function (): void {
    $engine = test_engine(['i18n' => ['locale' => 'en']]);

    assertSame('1,234.50', $engine->renderString('%{x|number}', ['x' => 1234.5]));
    assertSame('1,234.567', $engine->renderString('%{x|number:3}', ['x' => 1234.567]));
    assertSame('1.234,50', $engine->renderString('%{x|number:2:,:.}', ['x' => 1234.5]));
    assertSame('1.24', $engine->renderString('%{x|round:2}', ['x' => 1.235]));
    assertSame('3', $engine->renderString('%{x|cleanround}', ['x' => 3.0001]));
    assertSame('4', $engine->renderString('%{x|int}', ['x' => '4.4']));
    assertSame('4.5', $engine->renderString('%{x|float}', ['x' => '4.5']));
    assertSame('2.5', $engine->renderString('%{x|div:2}', ['x' => 5]));
    assertSame('0', $engine->renderString('%{x|div:0}', ['x' => 5]));
    assertSame('7', $engine->renderString('%{x|abs}', ['x' => -7]));
});

test('date/time filters use the injected clock', function (): void {
    $engine = test_engine([], new HtmlPhp\Support\FrozenClock(1_700_000_000));

    assertSame('2023-11-14', $engine->renderString('%{ts|date:Y-m-d}', ['ts' => 1_700_000_000]));
    assertSame('2023-11-14', $engine->renderString('%{d|date}', ['d' => '2023-11-14 10:00:00']));
    assertSame('3 days ago', $engine->renderString('%{ts|ago}', ['ts' => 1_700_000_000 - 3 * 86400]));
});

test('array filters', function (): void {
    $engine = test_engine();
    $vars = ['list' => ['a', 'b', 'c'], 'map' => ['x' => 1, 'y' => 2]];

    assertSame('a, b, c', $engine->renderString('%{list|join}', $vars));
    assertSame('a-b-c', $engine->renderString("%{list|join:'-'}", $vars));
    assertSame('3', $engine->renderString('%{list|length}', $vars));
    assertSame('a', $engine->renderString('%{list|first}', $vars));
    assertSame('c', $engine->renderString('%{list|last}', $vars));
    assertSame('cba', $engine->renderString("%{list|reverse|join:''}", $vars));
    assertSame('x, y', $engine->renderString('%{map|keys}', $vars));
    assertSame('1', $engine->renderString('%{list|slice:1:1|length}', $vars));
});

test('default/escape/url/json/slug/truncate/pad/repeat/base64/md5', function (): void {
    $engine = test_engine();

    assertSame('fb', $engine->renderString("%{x|default:'fb'}", ['x' => '']));
    assertSame('keep', $engine->renderString("%{x|default:'fb'}", ['x' => 'keep']));
    assertSame('a%20b', $engine->renderString('%{x|url}', ['x' => 'a b']));
    assertSame('"x"', $engine->renderString('%{x|json}', ['x' => 'x']));
    assertSame('hello-world', $engine->renderString('%{x|slug}', ['x' => 'Hello World!']));
    assertSame('he…', $engine->renderString("%{x|truncate:2:'…'}", ['x' => 'hello']));
    assertSame('abxx', $engine->renderString("%{x|pad:4:'x'}", ['x' => 'ab']));
    assertSame('abab', $engine->renderString('%{x|repeat:2}', ['x' => 'ab']));
    assertSame('aGk=', $engine->renderString('%{x|base64}', ['x' => 'hi']));
    assertSame(md5('x'), $engine->renderString('%{x|md5}', ['x' => 'x']));
    assertSame(sha1('x'), $engine->renderString('%{x|sha1}', ['x' => 'x']));
    assertSame('text', $engine->renderString('%{x|striptags}', ['x' => 'te<b>xt']));
});

test('quoted filter arguments may contain colons (date formats!)', function (): void {
    $engine = test_engine();

    assertSame('10:00', $engine->renderString("%{ts|date:'H:i'}", ['ts' => strtotime('2024-01-01 10:00:00 UTC')]));
});

test('translate filter goes through the translator', function (): void {
    $engine = test_engine();
    $translator = $engine->translator();
    assertTrue($translator instanceof HtmlPhp\I18n\Translator);
    $translator->register('en', 'messages', HtmlPhp\I18n\Catalog::fromArray(['hello' => 'translated'], 'en'));
    $engine->setLocale('en');

    assertSame('translated', $engine->renderString('%{key|translate}', ['key' => 'hello']));
});

test('@{KEY} translates with the key as fallback', function (): void {
    $engine = test_engine();
    $engine->translator()->register('en', 'messages', HtmlPhp\I18n\Catalog::fromArray(['greet' => 'Hello!'], 'en'));
    $engine->setLocale('en');

    assertSame('Hello!', $engine->renderString('@{greet}', []));
    assertSame('unknown.key', $engine->renderString('@{unknown.key}', []));
    assertSame('HELLO!', $engine->renderString('@{greet|upper}', []));
});

test('custom filters are registered per engine', function (): void {
    $engine = test_engine();
    $engine->filters()->add('shout', static fn(mixed $v): string => strtoupper((string) $v) . '!');

    assertSame('HEY!', $engine->renderString('%{x|shout}', ['x' => 'hey']));
    assertSame(true, $engine->filters()->has('shout'));

    $other = test_engine();
    assertSame(false, $other->filters()->has('shout'));
});

test('unknown filters diagnose and pass through (lenient mode)', function (): void {
    $engine = test_engine();

    assertSame('v', $engine->renderString('%{x|nope}', ['x' => 'v']));
    assertSame(true, $engine->diagnostics()->count(Diagnostics::FILTER_UNKNOWN) > 0);
});

test('strict mode turns unknown filters into exceptions', function (): void {
    $engine = test_engine(['syntax' => ['strict' => true]]);

    assertThrows(TemplateException::class, static fn() => $engine->renderString('%{x|nope}', ['x' => 'v']));
});

test('raw filter can be disabled by policy', function (): void {
    $engine = test_engine(['syntax' => ['allow_raw_filter' => false]]);

    assertSame('&lt;b&gt;', $engine->renderString('%{x|raw}', ['x' => '<b>']));
    assertSame(true, $engine->diagnostics()->count(Diagnostics::FILTER_UNKNOWN) > 0);
});

test('filter args can reference nothing dynamic — they are literals', function (): void {
    $engine = test_engine();

    assertSame('ab…', $engine->renderString("%{x|truncate:2:'…'}", ['x' => 'abcdef']));
});
