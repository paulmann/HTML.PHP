<?php
/** Inline expressions, nested variables and template math — new in v5. */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use HtmlPhp\Engine;

$engine = Engine::create(['debug' => true, 'log' => ['driver' => 'array']]);

$data = [
    'done'     => 37,
    'total'    => 250,
    'currency' => 'EUR',
    'prices'   => ['EUR' => 12.5, 'USD' => 14.0],
    'rows'     => [
        ['name' => 'First', 'scores' => [3, 5]],
        ['name' => 'Second', 'scores' => [4, 4]],
    ],
    'field'    => 'rows[1].name',
    'msg'      => '<b>escaped by default</b>',
];

// 1. The headline case: compute a percentage straight from the passed data.
//    `%{= expr |filters}` evaluates the same sandboxed expression language
//    @If/@Set use — no eval, whitelisted functions only, auto-escaped output.
echo $engine->renderString('Progress: %{= done / total * 100 | round:1}%', $data), "\n";

// 2. The same thing with the pct filter (arguments are literals there —
//    the = form is the data-driven one).
echo $engine->renderString('Progress: %{done|pct:250:1}%', $data), "\n";

// 3. Nested variables: bracket indexing, dynamic keys, dot chains.
echo $engine->renderString('Price: %{= prices[currency]} · Row: %{= rows[1].name} · Score: %{= rows[0].scores[1]}', $data), "\n";

// 4. "A variable inside a variable": the field *name* comes from the data.
echo $engine->renderString('Indirect: %{= get(field)}', $data), "\n";

// 5. Aggregates and math functions: sum, avg, clamp, pow, sqrt.
echo $engine->renderString(
    'Total score: %{= sum(rows[0].scores)} · Avg: %{= avg(rows[1].scores)} · Clamped: %{= clamp(done, 0, 30)} · 2^10=%{= pow(2, 10)}',
    $data,
), "\n";

// 6. Expressions inside structure tags (indexing works everywhere).
echo $engine->renderString(
    '<!--@If rows[0].scores[0] >= 3--><!--@Loop rows-->%{= upper(name)};<!--@EndLoop--><!--@EndIf-->',
    $data,
), "\n";

// 7. Output safety: expression results are auto-escaped like any substitution.
echo $engine->renderString('%{= msg}', $data), "\n";

// 8. Failure handling: a broken expression is visible in the output and
//    recorded as an `expr-invalid` diagnostic (strict mode throws instead).
echo $engine->renderString('[%{= 1 + }]', $data), "\n";
print_r(array_map(
    static fn(array $entry): string => $entry['code'] . ': ' . $entry['message'],
    $engine->diagnostics()->all()
));

// 9. Hosts rendering user-editable templates can close the gate entirely:
//    syntax.expressions=false leaves %{= …} untouched (fail-closed).
$hardened = Engine::create(['syntax' => ['expressions' => false, 'allow_raw_filter' => false]]);
echo $hardened->renderString('[%{= phpinfo()}] but %{name} still works', ['name' => 'Bob']), "\n";
