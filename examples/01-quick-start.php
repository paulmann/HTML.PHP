<?php
/** Substitution, escaping, filters, diagnostics — the daily drivers. */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use HtmlPhp\Engine;

$engine = Engine::create(['debug' => true, 'log' => ['driver' => 'array']]);

// Auto-escaping is on by default (v4).
echo $engine->renderString('Hello %{name}!', ['name' => '<World>']), "\n";
echo $engine->renderString('Raw: %{name|raw}', ['name' => '<b>ok</b>']), "\n";

// Filter chains, quoted args.
echo $engine->renderString('%{price|number:2} · %{ts|date:Y-m-d} · %{title|slug}', [
    'price' => 1234.5,
    'ts'    => strtotime('2026-10-11 08:00 UTC'),
    'title' => 'Hello World — Пример!',
]), "\n";

// Dot paths walk arrays and objects.
echo $engine->renderString('%{user.address.city}', [
    'user' => ['address' => ['city' => 'Berlin']],
]), "\n";

// Missing variables follow the configured policy (default: empty + diagnostic).
echo $engine->renderString('[%{gone}]', []), "\n";
print_r(array_map(
    static fn(array $entry): string => $entry['code'] . ': ' . $entry['message'],
    $engine->diagnostics()->all()
));

// Custom filters are per-engine.
$engine->filters()->add('shout', static fn(mixed $v): string => strtoupper((string) $v) . '!');
echo $engine->renderString('%{word|shout}', ['word' => 'hey']), "\n";
