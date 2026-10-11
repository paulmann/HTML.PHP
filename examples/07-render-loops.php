<?php
/**
 * 07 - Modern rendering API: scoped variables, filters, loops, conditional
 *      blocks, diagnostics and the render cache.
 *
 * Run:  php examples/07-render-loops.php
 */

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

echo "=== 07 / Render API, loops & filters ===\n\n";

// ---------------------------------------------------------------------------
// 1. Scoped rendering — no $GLOBALS involved.
// ---------------------------------------------------------------------------
$order = [
    'id' => 1042,
    'customer' => ['name' => 'Alice', 'country' => 'DE'],
    'premium' => true,
    'items' => [
        ['sku' => 'A-1', 'name' => 'Widget', 'price' => 9.99, 'qty' => 2],
        ['sku' => 'B-7', 'name' => 'Gadget & Co <Ltd>', 'price' => 24.5, 'qty' => 1],
        ['sku' => 'C-3', 'name' => 'Doohickey', 'price' => 3.25, 'qty' => 4],
    ],
];

$template = <<<'HTML'
<h1>Order #%{id}</h1>
<p>Customer: %{customer.name} (%{customer.country})</p>
<!--@If premium--><p class="badge">Premium member</p><!--@EndIf-->
<table>
<!--@Loop items-->
  <tr class="%{_odd}">
    <td>%{_position}.</td>
    <td>%{name|escape}</td>
    <td>%{sku|lower}</td>
    <td style="text-align:right">%{price|number:2}</td>
    <td>× %{qty}</td>
  </tr>
<!--@EndLoop items-->
</table>
HTML;

echo html_render($template, $order, ['escape' => false, 'passes' => 1]), "\n\n";

// ---------------------------------------------------------------------------
// 2. Filters gallery.
// ---------------------------------------------------------------------------
$demo = ['text' => 'hello world', 'num' => '1234.5678', 'when' => 1700000000];

echo "--- filters ---\n";
echo 'upper     : ', html_render('%{text|upper}', $demo), "\n";
echo 'slug      : ', html_render('%{text|slug}', $demo), "\n";
echo 'truncate  : ', html_render('%{text|truncate:8}', $demo), "\n";
echo 'number    : ', html_render('%{num|number:2:,:.}', $demo), "\n";
echo 'date      : ', html_render("%{when|date:'d.m.Y H:i'}", $demo), ' (UTC)', "\n";
echo 'chain     : ', html_render('%{text|ucfirst|truncate:5:…!}', $demo), "\n\n";

// Custom filters are registered once and usable everywhere:
html_var_filter('money', static fn(string $value, array $args): string =>
    ($args[0] ?? '€') . ' ' . number_format((float) $value, 2, ',', '.'));

echo 'custom    : ', html_render('%{num|money:$}', $demo), "\n\n";

// ---------------------------------------------------------------------------
// 3. Diagnostics instead of silent breakage.
// ---------------------------------------------------------------------------
html_clear_errors();
html_render('Hello %{typo_name}!', []);

echo "--- diagnostics ---\n";
foreach (html_errors('missing-var') as $error) {
    printf("  [%s] %s (%s)\n", $error['code'], $error['message'], $error['context']['name'] ?? '?');
}
html_clear_errors();
echo "\n";

// ---------------------------------------------------------------------------
// 4. Render cache.
// ---------------------------------------------------------------------------
$cacheDir = sys_get_temp_dir() . '/htmlphp-cache-' . getmypid();
@mkdir($cacheDir, 0o777, true);

html_config(['HTML_CACHE_DIR' => $cacheDir]);

$runs = 0;
html_add_filter('htmlphp_render', static function (string $out) use (&$runs): string {
    ++$runs;

    return $out;
});

$frozenNow = time();
$heavy = static fn(): string => html_render("Computed at %{ts|date:'H:i:s'}", ['ts' => $frozenNow], ['cache' => true, 'ttl' => 60, 'passes' => 1]);

echo "--- cache ---\n";
echo 'first  : ', $heavy(), "\n";
sleep(1);
echo 'second : ', $heavy(), '  (served from cache, identical timestamp)', "\n";
echo 'renders executed: ', $runs, "\n";
echo 'cache clear removed ', html_cache_clear(), " entr(ies)\n";

array_map('unlink', glob($cacheDir . '/*') ?: []);
@rmdir($cacheDir);

echo "\nDone.\n";
