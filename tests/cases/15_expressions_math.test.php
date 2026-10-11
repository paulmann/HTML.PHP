<?php

/**
 * 15 — v5: inline expressions in substitutions, nested/dynamic variable
 * access, math filters and the guards around all of it.
 */

declare(strict_types=1);

use HtmlPhp\Diagnostics;
use HtmlPhp\Exception\TemplateSyntaxException;
use HtmlPhp\Scope;
use HtmlPhp\Support\Str;
use HtmlPhp\Template\Expression;
use HtmlPhp\Template\RenderOptions;
use HtmlPhp\Template\Substitution;

group('Inline expressions: %{= …}');

test('%{= expr} computes over the passed data', function (): void {
    $engine = test_engine();

    // The headline case: a percentage of two numbers that only exist at render time.
    assertSame('12.5', $engine->renderString('%{= done / total * 100 | round:1}', ['done' => 5, 'total' => 40]));
    assertSame('33.33', $engine->renderString('%{= a / b * 100 | round:2}', ['a' => 1, 'b' => 3]));
    assertSame('7', $engine->renderString('%{= 3 + 2 * 2}', []));
    assertSame('2', $engine->renderString('%{= 7 % 5}', []));
    assertSame('-4', $engine->renderString('%{= 2 - 6}', []));
    assertSame('6', $engine->renderString('%{= (1 + 2) * 2}', []));
});

test('the = prefix is required: bare payloads stay v4 name lookups', function (): void {
    $engine = test_engine();

    // Without `=`, `a-b` is a *name* (v4 semantics), never a subtraction.
    assertSame('9', $engine->renderString('%{a-b}', ['a-b' => '9', 'a' => 100, 'b' => 1]));
    // Spaces make it an invalid name: the tag is left untouched, not computed.
    assertSame('%{a - b}', $engine->renderString('%{a - b}', ['a' => 5, 'b' => 1]));
    assertSame('4', $engine->renderString('%{= a - b}', ['a' => 5, 'b' => 1]));
});

test('expression results honour auto-escaping and the raw filter', function (): void {
    $engine = test_engine();

    assertSame('&lt;b&gt;', $engine->renderString('%{= html}', ['html' => '<b>']));
    assertSame('&lt;b&gt;', $engine->renderString('%{= html | escape}', ['html' => '<b>']));
    assertSame('<b>', $engine->renderString('%{= html | raw}', ['html' => '<b>']));

    $off = test_engine(['syntax' => ['auto_escape' => false]]);
    assertSame('<b>', $off->renderString('%{= html}', ['html' => '<b>']));
});

test('every filter works on expression results', function (): void {
    $engine = test_engine();

    assertSame('1,234.50', $engine->renderString('%{= price * 1.5 | number:2:.:,}', ['price' => 823.0]));
    assertSame('ABC', $engine->renderString("%{= 'a' ~ 'b' ~ 'c' | upper}", []));
    assertSame('x,y', $engine->renderString("%{= list | join:','}", ['list' => ['x', 'y']]));
    assertSame('3', $engine->renderString('%{= list | length}', ['list' => [1, 2, 3]]));
});

test('the #{= …} alternate form behaves identically', function (): void {
    $engine = test_engine();

    assertSame('4', $engine->renderString('#{= 2 + 2}', []));
    assertSame('4', $engine->renderString('#{= a + b}', ['a' => 1, 'b' => 3]));
});

test('single_braces exposes the {= …} form only when enabled', function (): void {
    $plain = test_engine();
    assertSame('{= 1 + 1}', $plain->renderString('{= 1 + 1}', []));

    $engine = test_engine(['syntax' => ['single_braces' => true]]);
    assertSame('2', $engine->renderString('{= 1 + 1}', []));
    assertSame('body { color: red; }', $engine->renderString('body { color: red; }', []));
});

test('ternary, coalesce and comparisons work inline', function (): void {
    $engine = test_engine();

    assertSame('ok', $engine->renderString("%{= n > 3 ? 'ok' : 'low'}", ['n' => 5]));
    assertSame('fb', $engine->renderString("%{= missing ?? 'fb'}", []));
    assertSame('1', $engine->renderString('%{= a == b}', ['a' => 2, 'b' => '2']));
    assertSame('', $engine->renderString('%{= a === b}', ['a' => 2, 'b' => '2']));
});

test('expressions see loop scope frames', function (): void {
    $engine = test_engine();

    $out = $engine->renderString(
        '<!--@Loop rows-->%{= price * qty};<!--@EndLoop-->',
        ['rows' => [['price' => 2, 'qty' => 3], ['price' => 1.5, 'qty' => 4]]],
    );

    assertSame('6;6;', $out);
});

test('inline expressions accept the whole v4 expression grammar', function (): void {
    $engine = test_engine();

    assertSame('yes', $engine->renderString("%{= 'a' in haystack ? 'yes' : 'no'}", ['haystack' => 'cab']));
    assertSame('1', $engine->renderString("%{= 'abc' matches 'b' }", []));
    assertSame('ab', $engine->renderString("%{= lower('A') ~ 'b'}", []));
    assertSame('2.5', $engine->renderString('%{= round(2.456, 1)}', []));
    assertSame('3', $engine->renderString('%{= max(1, 3, 2)}', []));
    assertSame('', $engine->renderString('%{= default(missing, null)}', []));
});

group('Inline expressions: failure handling');

test('broken expressions keep the tag and diagnose once (lenient)', function (): void {
    $engine = test_engine();

    assertSame('[%{= 1 + }][%{= 1 + }]', $engine->renderString('[%{= 1 + }][%{= 1 + }]', []));
    assertSame(1, $engine->diagnostics()->count(Diagnostics::EXPR_INVALID));
});

test('broken expressions throw in strict mode', function (): void {
    $engine = test_engine(['syntax' => ['strict' => true]]);

    assertThrows(TemplateSyntaxException::class, static fn() => $engine->renderString('%{= 1 + }', []));
});

test('syntax.expressions=false leaves %{= …} untouched and diagnoses', function (): void {
    $engine = test_engine(['syntax' => ['expressions' => false]]);

    // Fail-closed: nothing is evaluated, the tag stays visible in the output.
    assertSame('[%{= 1 + 1 }]', $engine->renderString('[%{= 1 + 1 }]', []));
    assertSame(1, $engine->diagnostics()->count(Diagnostics::EXPR_DISABLED));

    // Plain substitutions keep working with the gate closed.
    assertSame('Hi Bob', $engine->renderString('Hi %{name}', ['name' => 'Bob']));
});

test('the expression length cap applies to inline expressions', function (): void {
    $engine = test_engine(['limits' => ['max_expression_length' => 32]]);

    assertSame('2', $engine->renderString('%{= 1 + 1}', []));
    assertSame('%{= ' . str_repeat('1 + ', 40) . '1}', $engine->renderString('%{= ' . str_repeat('1 + ', 40) . '1}', []));
    assertSame(1, $engine->diagnostics()->count(Diagnostics::EXPR_INVALID));
});

test('deeply nested expressions hit the depth guard, not the stack', function (): void {
    $engine = test_engine();
    $source = '%{= ' . str_repeat('(', 64) . '1' . str_repeat(')', 64) . '}';

    assertSame($source, $engine->renderString($source, []));
    assertSame(true, $engine->diagnostics()->count(Diagnostics::EXPR_INVALID) > 0);
});

test('a pipe inside a string literal does not start a filter chain', function (): void {
    $engine = test_engine();

    assertSame('A|B', $engine->renderString('%{= "a|b" | upper}', []));
    assertSame('a|b', $engine->renderString("%{= 'a|b'}", []));
});

test('splitExpressionPayload separates expression and filters at top level only', function (): void {
    [$expression, $filters] = Substitution::splitExpressionPayload(' a / b * 100 | round:1 | number:2 ');

    assertSame('a / b * 100', $expression);
    assertSame([['round', ['1']], ['number', ['2']]], $filters);

    [$expression, $filters] = Substitution::splitExpressionPayload("rows[i].name | default:'a|b'");

    assertSame('rows[i].name', $expression);
    assertSame([['default', ['a|b']]], $filters);

    // No pipe at all: the payload is the expression.
    assertSame(['1 + 1', []], Substitution::splitExpressionPayload('1 + 1'));
});

group('Nested variables: index access');

test('bracket indexing reads list items and hash keys', function (): void {
    $engine = test_engine();

    assertSame('b', $engine->renderString('%{= rows[1]}', ['rows' => ['a', 'b', 'c']]));
    assertSame('v', $engine->renderString("%{= map['k']}", ['map' => ['k' => 'v']]));
    assertSame('v', $engine->renderString('%{= map[k]}', ['map' => ['k' => 'v'], 'k' => 'k']));
    assertSame('3', $engine->renderString('%{= matrix[1][0]}', ['matrix' => [[1, 2], [3, 4]]]));
});

test('indexing works inside plain dotted paths too', function (): void {
    $engine = test_engine();

    assertSame('Berlin', $engine->renderString('%{= user.addresses[0].city}', [
        'user' => ['addresses' => [['city' => 'Berlin']]],
    ]));

    assertSame('x', $engine->renderString('%{= data.meta[0].tags[1]}', [
        'data' => ['meta' => [['tags' => ['w', 'x']]]],
    ]));
});

test('a variable can hold the key: hash element by dynamic key', function (): void {
    $engine = test_engine();

    $vars = [
        'prices' => ['EUR' => 12.5, 'USD' => 14.0],
        'currency' => 'EUR',
    ];

    assertSame('12.5', $engine->renderString('%{= prices[currency]}', $vars));
    assertSame('12.50', $engine->renderString('%{= prices[currency] | number:2}', $vars));

    // Keys with characters a dotted path cannot express.
    assertSame('HIT', $engine->renderString('%{= hash[key]}', ['hash' => ['x-1 y' => 'HIT'], 'key' => 'x-1 y']));

    // Numeric-string keys follow PHP array semantics.
    assertSame('n', $engine->renderString("%{= map['7']}", ['map' => [7 => 'n']]));
});

test('index keys may themselves be computed', function (): void {
    $engine = test_engine();

    assertSame('c', $engine->renderString('%{= rows[i + 1]}', ['rows' => ['a', 'b', 'c'], 'i' => 1]));
    assertSame('USD', $engine->renderString("%{= codes[upper('usd')]}", ['codes' => ['USD' => 'USD']]));
    assertSame('v', $engine->renderString('%{= map[k ? "a" : "b"]}', ['map' => ['b' => 'v'], 'k' => false]));
});

test('string indexing is UTF-8 aware with PHP-style negative offsets', function (): void {
    $engine = test_engine();

    assertSame('H', $engine->renderString('%{= word[0]}', ['word' => 'Hello']));
    assertSame('o', $engine->renderString('%{= word[-1]}', ['word' => 'Hello']));
    assertSame('ж', $engine->renderString('%{= word[1]}', ['word' => 'ёжик']));
    assertSame('', $engine->renderString('%{= word[99]}', ['word' => 'abc']));
    assertSame('-', $engine->renderString("%{= word[99] | default:'-'}", ['word' => 'abc']));
});

test('ArrayAccess is indexable; plain object properties are not', function (): void {
    $engine = test_engine();

    $access = new class implements ArrayAccess {
        /** @param mixed $offset */
        public function offsetExists($offset): bool
        {
            return $offset === 'k';
        }

        /** @param mixed $offset */
        public function offsetGet($offset): mixed
        {
            return 'from-ArrayAccess';
        }

        /** @param mixed $offset */
        public function offsetSet($offset, $value): void {}

        /** @param mixed $offset */
        public function offsetUnset($offset): void {}
    };

    assertSame('from-ArrayAccess', $engine->renderString("%{= box['k']}", ['box' => $access]));

    // Dynamic keys must never become property or method names.
    $object = new class {
        public string $secret = 'LEAK';

        public function dump(): string
        {
            return 'CALLED';
        }
    };

    assertSame('', $engine->renderString('%{= object[key]}', ['object' => $object, 'key' => 'secret']));
    assertSame('', $engine->renderString('%{= object[key]}', ['object' => $object, 'key' => 'dump']));
    assertNotContains('LEAK', $engine->renderString('%{= object[key]} %{= object["dump"]}', ['object' => $object, 'key' => 'secret']));

    // The literal dotted path keeps its v4 accessor behaviour (author-controlled).
    assertSame('LEAK', $engine->renderString('%{object.secret}', ['object' => $object]));
});

test('index misses yield null, never a warning or a fatal', function (): void {
    $engine = test_engine();

    assertSame('', $engine->renderString('%{= rows[9]}', ['rows' => [1]]));
    assertSame('', $engine->renderString('%{= scalar[0]}', ['scalar' => 42]));
    assertSame('', $engine->renderString('%{= missing[0]}', []));
    assertSame('fb', $engine->renderString("%{= rows[9] ?? 'fb'}", ['rows' => [1]]));

    // Non-scalar keys are refused outright.
    assertSame('', $engine->renderString('%{= map[key]}', ['map' => ['a' => 'x'], 'key' => ['a']]));
    assertSame('', $engine->renderString('%{= map[key]}', ['map' => ['a' => 'x'], 'key' => null]));
});

test('index keys are length-capped and null-byte-free', function (): void {
    $engine = test_engine();

    $long = str_repeat('a', 300);
    assertSame('', $engine->renderString('%{= map[key]}', ['map' => [$long => 'x'], 'key' => $long]));
    assertSame('', $engine->renderString('%{= map[key]}', ['map' => ["a\0b" => 'x'], 'key' => "a\0b"]));
});

test('Scope::index is the documented primitive and is fail-safe', function (): void {
    assertSame([true, 'b'], Scope::index(['a', 'b'], 1));
    assertSame([false, null], Scope::index(['a'], 5));
    assertSame([true, 'v'], Scope::index(['k' => 'v'], 'k'));
    assertSame([false, null], Scope::index('abc', ['x']));
    assertSame([false, null], Scope::index(new stdClass(), 'anything'));
    assertSame([true, 'b'], Scope::index('abc', 1));
    assertSame([false, null], Scope::index('abc', 1.5));
    assertSame([false, null], Scope::index('abc', ''));
});

group('Nested variables: get() indirection');

test('get() resolves a name stored in another variable', function (): void {
    $engine = test_engine();

    // "A variable inside a variable": the *field name* comes from the data.
    $vars = ['field' => 'user.email', 'user' => ['email' => 'a@b.c']];

    assertSame('a@b.c', $engine->renderString('%{= get(field)}', $vars));
    assertSame('a@b.c', $engine->renderString("%{= get('user.email')}", $vars));
    assertSame('', $engine->renderString('%{= get(missingName)}', ['missingName' => 'nope']));
    assertSame('fb', $engine->renderString("%{= get(missingName, 'fb')}", ['missingName' => 'nope']));
});

test('get() paths accept the full static grammar: dots, brackets, quotes', function (): void {
    $engine = test_engine();

    $vars = [
        'rows' => [['name' => 'zero'], ['name' => 'one']],
        'hash' => ['x-1 y' => 'HIT', 7 => 'seven'],
        'p' => "hash['x-1 y']",
    ];

    assertSame('one', $engine->renderString("%{= get('rows[1].name')}", $vars));
    assertSame('one', $engine->renderString("%{= get('rows.1.name')}", $vars));
    assertSame('HIT', $engine->renderString('%{= get(p)}', $vars));
    assertSame('seven', $engine->renderString("%{= get('hash[7]')}", $vars));
    assertSame('seven', $engine->renderString("%{= get(\"hash['7']\")}", $vars));

    // Malformed or hostile paths degrade to the default — never an error.
    foreach (['a[', 'a[]', '9a', 'a..b', 'a.', 'rows[0]name', "hash[x-1 y]", 'a[99999999999999999999]'] as $bad) {
        assertSame(
            'd',
            $engine->renderString('%{= get(p, "d")}', ['p' => $bad]),
            'get() must refuse path: ' . $bad,
        );
    }
});

test('get() composes with concatenation and dot paths', function (): void {
    $engine = test_engine();

    $vars = [
        'row' => ['id' => 7, 'name' => 'Seven'],
        'prefix' => 'row.',
        'field' => 'name',
        'i' => 0,
        'rows' => [['name' => 'Seven']],
    ];

    // The name itself is built at render time.
    assertSame('Seven', $engine->renderString("%{= get(prefix ~ field)}", $vars));
    assertSame('Seven', $engine->renderString('%{= rows[i].name}', $vars));
    assertSame('Seven', $engine->renderString('%{= get(rowField)}', [...$vars, 'rowField' => 'row.name']));
});

test('get() only ever reads the scope stack', function (): void {
    $engine = test_engine();

    foreach (['_SERVER', '_GET', 'GLOBALS', '../../etc/passwd', "a\0b", '', str_repeat('x', 200)] as $hostile) {
        assertSame(
            'denied',
            $engine->renderString("%{= get(name, 'denied')}", ['name' => $hostile]),
            'get() must refuse: ' . var_export($hostile, true),
        );
    }

    // A shadowing frame wins, exactly like a plain name lookup: the loop
    // frame redefines `x`, and `field` holds the *name* to resolve.
    assertSame('inner-x', $engine->renderString(
        '<!--@Loop rows-->%{= get(field)}<!--@EndLoop-->',
        ['rows' => [['field' => 'x', 'x' => 'inner-x']], 'x' => 'outer-x'],
    ));
});

group('Math: expression functions');

test('pow/sqrt are overflow- and domain-guarded', function (): void {
    $engine = test_engine();

    assertSame('1024', $engine->renderString('%{= pow(2, 10)}', []));
    assertSame('9', $engine->renderString('%{= pow(3, 2)}', []));
    assertSame('12', $engine->renderString('%{= sqrt(144)}', []));
    assertSame('0', $engine->renderString('%{= sqrt(-4)}', []));      // NAN guard
    assertSame('0', $engine->renderString('%{= pow(0, -1)}', []));     // INF guard
    assertSame('0', $engine->renderString('%{= pow(10, 9999)}', []));  // INF guard
    assertSame('2', $engine->renderString('%{= pow(4, 0.5)}', []));
});

test('sum/avg aggregate arrays and iterables', function (): void {
    $engine = test_engine();

    assertSame('10', $engine->renderString('%{= sum(v)}', ['v' => [1, 2, 3, 4]]));
    assertSame('2.5', $engine->renderString('%{= avg(v)}', ['v' => [1, 2, 3, 4]]));
    assertSame('0', $engine->renderString('%{= avg(empty)}', ['empty' => []]));
    assertSame('5', $engine->renderString('%{= sum(scalar)}', ['scalar' => 5]));
    assertSame('0', $engine->renderString('%{= sum(none)}', []));
    assertSame('1,234.00', $engine->renderString('%{= sum(v) | number:2}', ['v' => ['1,000.5', '233.5']]));

    // Percentages straight out of an aggregate.
    assertSame('40%', $engine->renderString('%{= sum(done) / sum(all) * 100 | round:0}%', [
        'done' => [2, 2],
        'all' => [5, 5],
    ]));
});

test('clamp bounds a value and tolerates swapped limits', function (): void {
    $engine = test_engine();

    assertSame('100', $engine->renderString('%{= clamp(pct, 0, 100)}', ['pct' => 140]));
    assertSame('0', $engine->renderString('%{= clamp(pct, 0, 100)}', ['pct' => -3]));
    assertSame('42', $engine->renderString('%{= clamp(pct, 0, 100)}', ['pct' => 42]));
    assertSame('5', $engine->renderString('%{= clamp(5, 10, 1)}', []));
});

test('the v5 functions are whitelisted like every other', function (): void {
    $scope = new Scope([]);

    foreach (['pow', 'sqrt', 'sum', 'avg', 'clamp', 'get'] as $name) {
        assertSame(true, in_array($name, Expression::FUNCTIONS, true), $name . ' must be whitelisted');
    }

    // Nothing outside the whitelist became reachable through the new syntax.
    foreach (['system', 'exec', 'file_get_contents', 'eval', 'phpinfo', 'passthru', 'include', 'require'] as $name) {
        assertSame(false, in_array($name, Expression::FUNCTIONS, true), $name . ' must stay out');
        assertThrows(
            TemplateSyntaxException::class,
            static fn() => Expression::evaluate($name . '()', $scope),
        );
    }
});

test('index access and new functions work in @If / @Set / loop ranges', function (): void {
    $engine = test_engine();

    assertSame('big', $engine->renderString(
        '<!--@If rows[0].score > 9-->big<!--@Else-->small<!--@EndIf-->',
        ['rows' => [['score' => 12]]],
    ));

    assertSame('20', $engine->renderString(
        '<!--@Set best="rows[0].score * 2"-->%{best}',
        ['rows' => [['score' => 10]]],
    ));
    assertSame('6', $engine->renderString(
        '<!--@Set total="sum(scores)"-->%{total}',
        ['scores' => [1, 2, 3]],
    ));
    assertSame('25', $engine->renderString(
        '<!--@Set p="clamp(done / total * 100, 0, 100)"-->%{p|round:0}',
        ['done' => 5, 'total' => 20],
    ));

    // Expression-level ranges (fixed in v5: `1..3` used to be a syntax error here).
    assertSame('2', $engine->renderString('%{= (1..3)[1]}', []));
    assertSame('2,3,4', $engine->renderString("%{= (2..4) | join:','}", []));
    assertSame('3,2,1', $engine->renderString("%{= (3..1) | join:','}", []));

    // Loop ranges keep working through the parser path.
    assertSame('123', $engine->renderString('<!--@Loop i in 1..3-->%{i}<!--@EndLoop-->', []));
    assertSame('ab', $engine->renderString('<!--@Loop i in (0..1)-->%{= letters[i]}<!--@EndLoop-->', ['letters' => ['a', 'b']]));
});

group('Math: filters');

test('arithmetic filters compute with literal arguments', function (): void {
    $engine = test_engine();

    assertSame('7', $engine->renderString('%{n|add:3}', ['n' => 4]));
    assertSame('1', $engine->renderString('%{n|sub:3}', ['n' => 4]));
    assertSame('12', $engine->renderString('%{n|mul:3}', ['n' => 4]));
    assertSame('2', $engine->renderString('%{n|div:2}', ['n' => 4]));
    assertSame('1', $engine->renderString('%{n|mod:3}', ['n' => 4]));
    assertSame('8', $engine->renderString('%{n|pow:3}', ['n' => 2]));
    assertSame('3', $engine->renderString('%{n|sqrt}', ['n' => 9]));
    assertSame('5', $engine->renderString('%{n|clamp:5:10}', ['n' => 1]));
    assertSame('10', $engine->renderString('%{n|clamp:5:10}', ['n' => 99]));
});

test('the pct filter renders a share of a total', function (): void {
    $engine = test_engine();

    assertSame('50', $engine->renderString('%{done|pct:40}', ['done' => 20]));
    assertSame('33.3', $engine->renderString('%{done|pct:3:1}', ['done' => 1]));
    assertSame('12.50', $engine->renderString('%{done|pct:250:2}', ['done' => 31.25]));
    assertSame('0', $engine->renderString('%{done|pct:0}', ['done' => 20])); // zero divisor guard

    // Numeric strings from forms/CSV parse with dot or comma decimals.
    assertSame('25', $engine->renderString('%{done|pct:200}', ['done' => '50']));
    assertSame('123.45', $engine->renderString('%{done|pct:1000:2}', ['done' => '1 234,5']));
    assertSame('617', $engine->renderString('%{done|pct:2}', ['done' => '12,345']));
    assertSame('617.25', $engine->renderString('%{done|pct:2:2}', ['done' => '12,345']));
});

test('sum/avg filters aggregate lists', function (): void {
    $engine = test_engine();

    assertSame('6', $engine->renderString('%{v|sum}', ['v' => [1, 2, 3]]));
    assertSame('2', $engine->renderString('%{v|avg}', ['v' => [1, 2, 3]]));
    assertSame('2.5', $engine->renderString('%{v|avg|round:1}', ['v' => [1, 2, 3, 4]]));
    assertSame('0', $engine->renderString('%{v|sum}', ['v' => []]));
    assertSame('3', $engine->renderString('%{v|sum}', ['v' => 3]));
});

test('arithmetic filters never emit INF/NAN and guard zero divisors', function (): void {
    $engine = test_engine();

    assertSame('0', $engine->renderString('%{n|div:0}', ['n' => 5]));
    assertSame('0', $engine->renderString('%{n|mod:0}', ['n' => 5]));
    assertSame('0', $engine->renderString('%{n|pow:9999}', ['n' => 10]));
    assertSame('0', $engine->renderString('%{n|sqrt}', ['n' => -1]));
    assertSame('0', $engine->renderString('%{n|mul:1e400}', ['n' => 5]));

    foreach (['div', 'mod', 'pow', 'sqrt', 'mul', 'add', 'sub', 'pct', 'sum', 'avg', 'clamp'] as $filter) {
        $out = $engine->renderString('%{n|' . $filter . ':0}', ['n' => 1]);
        assertNotContains('INF', $out);
        assertNotContains('NAN', $out);
    }
});

test('new filters are registered and enumerable for the linter', function (): void {
    $engine = test_engine();
    $names = $engine->filters()->names();

    foreach (['add', 'sub', 'mul', 'mod', 'pow', 'sqrt', 'pct', 'sum', 'avg', 'clamp'] as $filter) {
        assertSame(true, $engine->filters()->has($filter), $filter . ' must be a builtin');
        assertSame(true, in_array($filter, $names, true), $filter . ' must be listed for the linter');
    }
});

group('Str: numeric primitives');

test('Str::power and Str::sqrt degrade to 0.0 instead of INF/NAN', function (): void {
    assertSame(1024.0, Str::power(2.0, 10.0));
    assertSame(0.0, Str::power(0.0, -1.0));
    assertSame(0.0, Str::power(10.0, 9999.0));
    assertSame(0.0, Str::power(-8.0, 1.0 / 3.0)); // NAN guard
    assertSame(0.0, Str::power(INF, 2.0));
    assertSame(3.0, Str::sqrt(9.0));
    assertSame(0.0, Str::sqrt(-9.0));
    assertSame(0.0, Str::sqrt(NAN));
});

test('Str::accumulate sums and counts with a hard iteration budget', function (): void {
    assertSame([6.0, 3], Str::accumulate([1, 2, 3]));
    assertSame([0.0, 0], Str::accumulate([]));
    assertSame([0.0, 0], Str::accumulate(null));
    assertSame([4.0, 1], Str::accumulate(4));
    assertSame([2.5, 2], Str::accumulate(['1,5', '1']));

    $infinite = (static function (): Generator {
        while (true) {
            yield 1;
        }
    })();

    [$sum, $count] = Str::accumulate($infinite);

    assertSame((float) Str::MAX_AGGREGATE_ITEMS, $sum);
    assertSame(Str::MAX_AGGREGATE_ITEMS, $count);
});

test('count/in over an infinite generator stop at the budget', function (): void {
    $infinite = static fn(): Generator => (static function (): Generator {
        while (true) {
            yield 1;
        }
    })();

    assertSame(Str::MAX_AGGREGATE_ITEMS, Expression::evaluate('count(gen)', new Scope(['gen' => $infinite()])));
    assertSame(true, Expression::evaluate('1 in gen', new Scope(['gen' => $infinite()])));
    assertSame((float) Str::MAX_AGGREGATE_ITEMS, Expression::evaluate('sum(gen)', new Scope(['gen' => $infinite()])));
});

group('Expression hardening');

test('the AST memo is bounded: 3x the cap stays flat', function (): void {
    Expression::reset();
    $scope = new Scope([]);

    for ($i = 0; $i < 4096; ++$i) {
        Expression::evaluate((string) $i . ' + 1', $scope);
    }

    $warm = memory_get_usage();

    for ($i = 4096; $i < 4096 * 3; ++$i) {
        Expression::evaluate((string) $i . ' + 1', $scope);
    }

    // FIFO eviction keeps the registry at the cap; allow generous slack for
    // allocator noise but fail on unbounded growth.
    assertSame(true, memory_get_usage() - $warm < 2 * 1024 * 1024, 'AST memo grew without bound');

    // Eviction is transparent: memoized expressions still evaluate correctly.
    assertSame(3, Expression::evaluate('1 + 2', $scope));
    Expression::reset();
});

test('matches uses the Pcre guard, so a ReDoS-shaped pattern is a no-match', function (): void {
    $scope = new Scope(['s' => str_repeat('a', 40) . '!']);

    // Catastrophic backtracking: PCRE bails with a backtrack-limit error,
    // which must surface as `false`, never as null/fatal/hang.
    assertSame(false, Expression::evaluate('s matches "(a+)+$"', $scope));
    assertSame(true, Expression::evaluate('s matches "/^a/"', $scope));
    assertSame(true, Expression::evaluate('s matches "a{2,40}"', $scope));
});

test('index chains are bounded by the length cap and the depth guard', function (): void {
    $scope = new Scope(['a' => ['b' => 1]]);

    // Long chains die on the source-length budget, not on the stack.
    assertThrows(
        TemplateSyntaxException::class,
        static fn() => Expression::evaluate('a' . str_repeat('[0]', 2000), $scope),
        'too long',
    );

    // Nested parentheses inside an index die on the depth guard.
    assertThrows(
        TemplateSyntaxException::class,
        static fn() => Expression::evaluate('a[' . str_repeat('(', 40) . '0' . str_repeat(')', 40) . ']', $scope),
        'too deep',
    );
});

test('inline expressions never reach the filesystem, network or a callable', function (): void {
    $engine = test_engine();

    $payloads = [
        '%{= get("HTTP_HOST")}',
        '%{= file_get_contents("/etc/passwd")}',
        '%{= `id`}',
        '%{= ${"GLOBALS"}]',
        '%{= (function(){ return 1; })()}',
        '%{= new SplFileObject("/etc/passwd")}',
        '%{= rows[__construct]}',
        '%{= rows[0]["__destruct"]}',
    ];

    foreach ($payloads as $payload) {
        $out = $engine->renderString($payload, ['rows' => [['x' => 1]]]);
        assertNotContains('root:', $out, $payload . ' must not read files');
        assertSame(true, $out === $payload || trim($out) === '', $payload . ' must stay inert, got: ' . $out);
    }
});

test('expression evaluation leaks no state between engines', function (): void {
    $left = test_engine();
    $right = test_engine();

    assertSame('1', $left->renderString('%{= secret}', ['secret' => '1']));
    assertSame('', $right->renderString('%{= secret}', []));
});

group('Linter: inline expressions');

test('the linter validates %{= …} expressions and their filter chains', function (): void {
    $engine = test_engine();

    $codes = array_map(
        static fn($issue): string => $issue->code,
        $engine->lintString('%{= 1 + } %{= x | nosuchfilter} %{= count(rows)}'),
    );

    assertSame(true, in_array('bad-expression', $codes, true));
    assertSame(true, in_array('unknown-filter', $codes, true));

    assertSame([], $engine->lintString('%{= (a / b * 100) | round:1}'));
    assertSame([], $engine->lintString('%{= rows[i].name | default:"-" | upper}'));
    assertSame([], $engine->lintString('%{= get(field) | number:2}'));
});

test('the linter reports a disabled expression gate', function (): void {
    $engine = test_engine(['syntax' => ['expressions' => false]]);
    $issues = $engine->lintString('%{= 1 + 1}');

    assertSame(1, count($issues));
    assertSame('expressions-disabled', $issues[0]->code);
    assertSame('error', $issues[0]->severity);
});

test('the linter warns when a numeric filter argument looks like a variable', function (): void {
    $engine = test_engine();
    $issues = $engine->lintString('%{done|pct:total}');

    assertSame(1, count($issues));
    assertSame('warning', $issues[0]->severity);
    assertSame('filter-arg-not-numeric', $issues[0]->code);
    assertContains('%{= …}', $issues[0]->message);

    // Literals and quoted strings are not warnings.
    assertSame([], $engine->lintString('%{done|pct:40:1}'));
    assertSame([], $engine->lintString("%{done|pct:'40'}"));
    assertSame([], $engine->lintString("%{name|default:'n/a'}"));
});

test('lint exits non-zero for a broken inline expression via the CLI', function (): void {
    $file = fixture_write('tpl/lint-expr-bad.html', 'total: %{= 1 + }');
    [$code, $out] = run_cli(['lint', $file]);

    assertSame(1, $code, 'lint must fail on a broken inline expression: ' . $out);
    assertContains('bad-expression', $out);

    $clean = fixture_write('tpl/lint-expr-ok.html', 'total: %{= done / total * 100 | round:1}');
    [$code, $out] = run_cli(['lint', $clean]);

    assertSame(0, $code, 'a clean inline expression must lint clean: ' . $out);
});

group('Regression: v4 behaviour is untouched');

test('plain substitutions, CSS/JS braces and dotted paths still behave', function (): void {
    $engine = test_engine();

    assertSame('Hi Bob', $engine->renderString('Hi %{name}', ['name' => 'Bob']));
    assertSame('Berlin', $engine->renderString('%{user.address.city}', ['user' => ['address' => ['city' => 'Berlin']]]));
    assertSame('body { color: red; }', $engine->renderString('body { color: red; }', []));
    assertSame('if (a) { b(); }', $engine->renderString('if (a) { b(); }', []));
    assertSame('a % b { c }', $engine->renderString('a % b { c }', []));
    assertSame('%{=}', $engine->renderString('%{=}', []));          // empty expression: tag kept
    assertSame('%{ }', $engine->renderString('%{ }', []));
});

test('missing-variable policies still apply to plain names only', function (): void {
    $engine = test_engine(['syntax' => ['missing_var' => 'placeholder']]);

    assertSame('[missing:gone]', $engine->renderString('%{gone}', []));

    // An expression with a missing name is *not* a missing variable: it
    // evaluates to null/0 like every other expression context (@If, @Set)
    // and adds no missing-var diagnostics beyond the plain %{gone} above.
    $recorded = $engine->diagnostics()->count(Diagnostics::MISSING_VAR);
    assertSame('', $engine->renderString('%{= gone}', []));
    assertSame('1', $engine->renderString('%{= gone + 1}', [])); // null coerces to 0 in arithmetic
    assertSame($recorded, $engine->diagnostics()->count(Diagnostics::MISSING_VAR));
});

test('raw blocks keep inline expressions verbatim', function (): void {
    $engine = test_engine();

    assertSame('%{= 1 + 1}', $engine->renderString('<!--@Raw-->%{= 1 + 1}<!--@EndRaw-->', []));
});

test('render options and the cache key ignore nothing new', function (): void {
    $engine = test_engine();
    $source = '%{= a * 2}';

    assertSame('4', $engine->renderString($source, ['a' => 2], new RenderOptions(cache: false)));
    assertSame('4', $engine->renderString($source, ['a' => 2]));
    assertSame('6', $engine->renderString($source, ['a' => 3]));
});
