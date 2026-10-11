<?php
/** Loops, expression conditionals, @Set, layout inheritance, partials. */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use HtmlPhp\Engine;

$dir = example_dir('structure');
@mkdir($dir . '/parts', 0777, true);
file_put_contents($dir . '/base.html', <<<HTML
<!doctype html>
<title><!--@Section title-->My Site<!--@EndSection--></title>
<body>
<!--@Section content--><p>Nothing here.</p><!--@EndSection-->
<footer><!--@Include "parts/footer.html"--></footer>
</body>
HTML);
file_put_contents($dir . '/parts/footer.html', '&copy; %{wp_year|default:2026} %{site}');
file_put_contents($dir . '/shop.html', <<<HTML
<!--@Extends "base.html"-->
<!--@Section title-->%{shop|upper} — Checkout<!--@EndSection-->
<!--@Section content-->
<!--@Set total="subtotal + 0"-->
<h1>%{shop}</h1>
<ul>
<!--@Loop item in items-->
  <li class="<!--@If _odd-->odd<!--@Else-->even<!--@EndIf-->">
    %{_position}. %{item.name}: %{item.price|number:2}
  </li>
<!--@Else-->
  <li>@{cart.empty}</li>
<!--@EndLoop-->
</ul>
<!--@If total > 100 --><p>Shipping is FREE.</p><!--@Elif total > 0--><p>Shipping: %{shipping|number:2}</p><!--@EndIf-->
<!--@EndSection-->
HTML);

$engine = Engine::create(['templates' => ['paths' => [$dir]]]);

echo $engine->render('shop.html', [
    'shop' => 'Gadget Store',
    'site' => 'gadgets.example',
    'subtotal' => 338.8,
    'shipping' => 4.9,
    'items' => [
        ['name' => 'Keyboard', 'price' => 59.9],
        ['name' => 'Mouse', 'price' => 29.9],
        ['name' => 'Monitor', 'price' => 249.0],
    ],
]), "\n";

print_r($engine->stats()->toArray());
