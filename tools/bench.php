<?php

/**
 * Throughput benchmark: parse-memoized rendering, loops, SSI, translations.
 * Usage: php8.3 tools/bench.php [iterations]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/autoload.php';

use HtmlPhp\Engine;
use HtmlPhp\RequestContext;
use HtmlPhp\Ssi\DocumentInfo;

$iterations = (int) ($argv[1] ?? 2000);

$dir = sys_get_temp_dir() . '/htmlphp-bench';
@mkdir($dir, 0777, true);
$rows = [];
for ($i = 0; $i < 50; ++$i) {
    $rows[] = ['name' => "row $i", 'price' => $i * 1.5, 'active' => $i % 2 === 0];
}
file_put_contents($dir . '/bench.html', <<<'HTML'
<h1>%{title|upper}</h1>
<ul>
<!--@Loop item in rows-->
<!--@If item.active--><li>%{_position}. %{item.name} — %{item.price|number:2} — @{cell.label}</li><!--@EndIf-->
<!--@EndLoop-->
</ul>
<!--#set var="x" value="1" --><!--#if expr="$x = '1'" --><!--#echo var="DATE_LOCAL" --><!--#endif-->
HTML);

$engine = Engine::create([
    'templates' => ['paths' => [$dir]],
    'ssi' => ['document_root' => $dir],
    'i18n' => ['locale' => 'en'],
]);
$engine->translator()->register('en', 'messages', HtmlPhp\I18n\Catalog::fromArray(['cell.label' => 'Cell'], 'en'));

$request = RequestContext::fromServer(['DOCUMENT_ROOT' => $dir, 'REQUEST_URI' => '/bench.html']);
$options = new HtmlPhp\Template\RenderOptions(file: $dir . '/bench.html', request: $request);

// warm-up
$engine->render('bench.html', ['title' => 'bench', 'rows' => $rows], $options);

$bench = static function (string $label, callable $fn) use ($iterations): void {
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        $fn($i);
    }
    $ms = (hrtime(true) - $start) / 1e6;
    printf(
        "%-28s %8.1f ms total · %7.3f ms/op · %8.0f ops/s\n",
        $label,
        $ms,
        $ms / $iterations,
        $iterations / ($ms / 1000),
    );
};

$bench('render (loops+ssi+i18n)', fn() => $engine->render('bench.html', ['title' => 'bench', 'rows' => $rows], $options));
$bench('renderString (small)', fn() => $engine->renderString('%{a|upper}-%{b|number:2}', ['a' => 'x', 'b' => 1.234]));
$bench('processSsi (5 directives)', fn() => $engine->processSsi('<!--#set var="a" value="1" --><!--#echo var="a" --><!--#if expr="$a" -->y<!--#endif--><!--#echo var="DATE_LOCAL" -->', new DocumentInfo(root: $dir, request: $request)));
$bench('t() with catalogue', fn() => $engine->t('cell.label'));
$bench('lint (small template)', fn() => $engine->lintString('<!--@Loop r-->%{n|upper}<!--@EndLoop-->'));

printf(
    "\nrows=%d iterations=%d · stats of last render: %s\n",
    count($rows),
    $iterations,
    ($engine->stats()?->toArray()['durationMs'] ?? '?') . 'ms',
);
