<?php

/**
 * Smoke test — exercises the whole v4 stack end to end.
 * Run: php8.3 tests/smoke.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use HtmlPhp\Engine;
use HtmlPhp\I18n\Catalog;
use HtmlPhp\I18n\Translator;
use HtmlPhp\RequestContext;
use HtmlPhp\Ssi\DocumentInfo;

$failures = 0;
$passes = 0;

function check(string $label, mixed $actual, mixed $expected): void
{
    global $failures, $passes;

    if ($actual === $expected) {
        $passes++;
        echo "  ok  {$label}\n";

        return;
    }

    $failures++;
    echo "FAIL  {$label}\n      expected: " . var_export($expected, true) . "\n      actual:   " . var_export($actual, true) . "\n";
}

$tmp = sys_get_temp_dir() . '/htmlphp-smoke-' . getmypid();
@mkdir($tmp . '/tpl/ssi', 0777, true);
@mkdir($tmp . '/tpl/parts', 0777, true);
@mkdir($tmp . '/lang', 0777, true);

file_put_contents($tmp . '/tpl/base.html', '<html><head><title><!--@Section title-->Default<!--@EndSection--></title></head><body><!--@Section content-->none<!--@EndSection--><footer>@{site.footer}</footer></body></html>');
file_put_contents($tmp . '/tpl/page.html', '<!--@Extends "base.html"--><!--@Section title-->%{title|upper}<!--@EndSection--><!--@Section content--><h1>%{title}</h1><!--@Include "parts/list.html"--><!--@EndSection-->');
file_put_contents($tmp . '/tpl/parts/list.html', '<ul><!--@Loop item in rows--><li>%{loop.index}: %{item|escape}</li><!--@Else--><li>empty</li><!--@EndLoop--></ul>');
file_put_contents($tmp . '/tpl/ssi/doc.shtml', '<!--#set var="who" value="world" --><!--#echo var="who" --><!--#if expr=\'$who = "world"\' -->!yes<!--#else-->!no<!--#endif--><!--#include file="part.html" -->');
file_put_contents($tmp . '/tpl/ssi/part.html', '[part:<!--#echo var="who" -->]');
file_put_contents($tmp . '/lang/messages-de.po', <<<'PO'
msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\n"
"Plural-Forms: nplurals=2; plural=n != 1;\n"

msgid "site.footer"
msgstr "Fußzeile"

msgid "Hello {0}"
msgstr "Hallo {0}"

msgid "{0} message"
msgid_plural "{0} messages"
msgstr[0] "{0} Nachricht"
msgstr[1] "{0} Nachrichten"
PO);

$engine = Engine::create([
    'debug' => true,
    'templates' => ['paths' => [$tmp . '/tpl']],
    'i18n' => ['locale' => 'de', 'paths' => [$tmp . '/lang'], 'fallback' => ['en']],
    'ssi' => ['document_root' => $tmp . '/tpl'],
]);

echo "== smoke: HTML.PHP " . $engine->version() . " ==\n";

// 1. basic substitution + escaping (auto-escape ON by default in v4)
check('var', $engine->renderString('Hi %{name}!', ['name' => 'Bob']), 'Hi Bob!');
check('escape default', $engine->renderString('%{x}', ['x' => '<b>&"']), '&lt;b&gt;&amp;&quot;');
check('raw filter', $engine->renderString('%{x|raw}', ['x' => '<b>']), '<b>');
check('filters chain', $engine->renderString('%{x|upper|truncate:3:.}', ['x' => 'hello']), 'HEL.');
check('alt braces', $engine->renderString('#{x}', ['x' => 'y']), 'y');
check('missing empty', $engine->renderString('[%{nope}]', []), '[]');

// 2. loops
check('loop rows', $engine->renderString('<!--@Loop rows-->%{_position}:%{name};<!--@EndLoop-->', [
    'rows' => [['name' => 'a'], ['name' => 'b']],
]), '1:a;2:b;');
check('loop else', $engine->renderString('<!--@Loop rows-->x<!--@Else-->EMPTY<!--@EndLoop-->', ['rows' => []]), 'EMPTY');
check('loop kv', $engine->renderString('<!--@Loop k, v in map-->%{k}=%{v} <!--@EndLoop-->', ['map' => ['a' => 1, 'b' => 2]]), 'a=1 b=2 ');
check('loop range', $engine->renderString('<!--@Loop i in 1..3-->%{i}<!--@EndLoop-->', []), '123');

// 3. ifs + expressions + set
check('if truthy', $engine->renderString('<!--@If flag-->YES<!--@Else-->NO<!--@EndIf-->', ['flag' => true]), 'YES');
check('if expr', $engine->renderString('<!--@If count > 2 && name == "bob"-->M<!--@Else-->N<!--@EndIf-->', ['count' => 3, 'name' => 'bob']), 'M');
check('if neg v3-style', $engine->renderString('<!--@If !flag-->N<!--@EndIf-->', ['flag' => false]), 'N');
check('elif', $engine->renderString('<!--@If a == 1-->one<!--@Elif a == 2-->two<!--@Else-->many<!--@EndIf-->', ['a' => 2]), 'two');
check('set', $engine->renderString('<!--@Set total="price * qty"-->%{total|number:2}', ['price' => 1.5, 'qty' => 3]), '4,50');

// 4. i18n
$translator = $engine->translator();
assert($translator instanceof Translator);
check('po loaded', $translator->hasCatalogue('de'), true);
check('at-key', $engine->renderString('@{site.footer}', []), 'Fußzeile');
check('plural 1', $engine->n('{0} message', '{0} messages', 1, [1]), '1 Nachricht');
check('plural 5', $engine->n('{0} message', '{0} messages', 5, [5]), '5 Nachrichten');
check('trans block', $engine->renderString('<!--@Trans count="%{c}"-->%{c} message<!--@Plural-->%{c} messages<!--@EndTrans-->', ['c' => 2]), '2 Nachrichten');

// 5. layout inheritance + partials
$out = $engine->render('page.html', ['title' => 'hey', 'rows' => ['<x>', 'b']]);
check('extends title', str_contains($out, '<title>HEY</title>'), true);
check('extends content', str_contains($out, '<h1>hey</h1>'), true);
check('partial loop', str_contains($out, '<li>1: &lt;x&gt;</li><li>2: b</li>'), true);
check('footer translated', str_contains($out, 'Fußzeile'), true);

// 6. SSI
$ssi = $engine->processSsi(file_get_contents($tmp . '/tpl/ssi/doc.shtml'), new DocumentInfo(
    file: $tmp . '/tpl/ssi/doc.shtml',
    root: $tmp . '/tpl',
    request: RequestContext::cli($tmp . '/tpl')
));
check('ssi set/echo/if/include', $ssi, 'world!yes[part:world]');
check('ssi printenv has vars', str_contains($engine->processSsi('<!--#printenv-->'), 'DATE_LOCAL='), true);

// 7. lint
$issues = $engine->lintString('<!--@Loop rows-->%{x|nope}<!--@EndLoop-->');
$codes = array_map(static fn($i) => $i->code, $issues);
check('lint unknown filter', in_array('unknown-filter', $codes, true), true);
$issues2 = $engine->lintString('<!--@If x-->oops');
check('lint unclosed', in_array('unclosed-tag', array_map(static fn($i) => $i->code, $issues2), true), true);

// 8. diagnostics
check('missing var recorded', $engine->diagnostics()->count('missing-var') > 0, true);

// 9. cache
$cached = Engine::create([
    'cache' => ['enabled' => true, 'driver' => 'array'],
    'templates' => ['paths' => [$tmp . '/tpl']],
]);
check('cache miss then hit', $cached->renderString('a%{v}', ['v' => 1], new \HtmlPhp\Template\RenderOptions(cache: true)), 'a1');
$cached->renderString('a%{v}', ['v' => 1], new \HtmlPhp\Template\RenderOptions(cache: true));
check('cache hit stat', $cached->stats()?->cacheHit, true);

// 10. events
$seen = null;
$engine->events()->on('htmlphp.render', function (string $text) use (&$seen): string {
    $seen = 'filtered';

    return $text . '!';
});
check('render filter event', $engine->renderString('x', []), 'x!');
check('event ran', $seen, 'filtered');

echo "\nSMOKE: {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
