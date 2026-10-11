<?php

/**
 * 10 — Render API surface: render/renderFile/renderString/display,
 *      options, caching, stats, events, size guards.
 */

declare(strict_types=1);

use HtmlPhp\Exception\TemplateException;
use HtmlPhp\Exception\TemplateNotFoundException;
use HtmlPhp\Template\RenderOptions;
use HtmlPhp\Template\RenderStats;

group('Render API');

test('render resolves through the locator, renderFile takes a path', function (): void {
    fixture_write('tpl/api/hello.html', 'Hi %{name}');
    $engine = test_engine();

    assertSame('Hi Bob', $engine->render('api/hello.html', ['name' => 'Bob']));
    assertSame('Hi Bob', $engine->render('api/hello', ['name' => 'Bob'])); // extension appended
    assertSame('Hi Bob', $engine->renderFile(fixture_path('tpl/api/hello.html'), ['name' => 'Bob']));
});

test('missing templates throw TemplateNotFound with roots in context', function (): void {
    $engine = test_engine();

    try {
        $engine->render('does/not/exist.html');
        fail('expected exception');
    } catch (TemplateNotFoundException $error) {
        assertContains('could not be located', $error->getMessage());
        assertSame(true, $error->context['roots'] !== []);
    }

    assertThrows(TemplateNotFoundException::class, static fn() => $engine->renderFile(fixture_path('tpl/ghost.html')));
});

test('renderString renders ad-hoc sources (database templates)', function (): void {
    $engine = test_engine();

    assertSame('DB:1', $engine->renderString('DB:%{v}', ['v' => 1]));
});

test('display echoes instead of returning', function (): void {
    $engine = test_engine();

    ob_start();
    $engine->displayString('E%{v}', ['v' => 'X']);
    $out = (string) ob_get_clean();

    assertSame('EX', $out);
});

test('template size limit aborts before parsing', function (): void {
    $engine = test_engine(['limits' => ['max_template_bytes' => 2048]]);

    assertThrows(TemplateException::class, static fn() => $engine->renderString(str_repeat('x', 4096)));
});

test('structure=false renders substitutions but keeps tags literal', function (): void {
    $engine = test_engine();

    $out = $engine->renderString('<!--@Loop rows-->%{n}<!--@EndLoop-->', ['rows' => [['n' => 1]], 'n' => 'top'], new RenderOptions(structure: false));
    assertSame('<!--@Loop rows-->top<!--@EndLoop-->', $out);
});

test('ssi=false skips the directive pass', function (): void {
    $engine = test_engine();

    $out = $engine->renderString('<!--#echo var="DATE_LOCAL" -->', [], new RenderOptions(ssi: false));
    assertSame('<!--#echo var="DATE_LOCAL" -->', $out);
});

test('locale option switches translations for one render only', function (): void {
    $engine = test_engine();
    $engine->translator()->register('de', 'messages', HtmlPhp\I18n\Catalog::fromArray(['hi' => 'Hallo'], 'de'));
    $engine->translator()->register('fr', 'messages', HtmlPhp\I18n\Catalog::fromArray(['hi' => 'Bonjour'], 'fr'));
    $engine->setLocale('de');

    assertSame('Hallo', $engine->renderString('@{hi}'));
    assertSame('Bonjour', $engine->renderString('@{hi}', [], new RenderOptions(locale: 'fr')));
    assertSame('de', $engine->locale()); // restored afterwards
});

group('Render cache');

test('identical renders hit the cache, changed vars do not', function (): void {
    $engine = test_engine(['cache' => ['enabled' => true, 'driver' => 'array', 'ttl' => 60]]);
    $options = new RenderOptions(cache: true);

    assertSame('v1', $engine->renderString('v%{n}', ['n' => 1], $options));
    assertSame(false, $engine->stats()->cacheHit);

    assertSame('v1', $engine->renderString('v%{n}', ['n' => 1], $options));
    assertSame(true, $engine->stats()->cacheHit);

    assertSame('v2', $engine->renderString('v%{n}', ['n' => 2], $options));
    assertSame(false, $engine->stats()->cacheHit);
});

test('cache keys separate locales and escape modes', function (): void {
    $engine = test_engine(['cache' => ['enabled' => true, 'driver' => 'array']]);

    $engine->renderString('%{x}', ['x' => '<b>'], new RenderOptions(cache: true));
    $escaped = $engine->renderString('%{x}', ['x' => '<b>'], new RenderOptions(cache: true));

    $raw = $engine->renderString('%{x}', ['x' => '<b>'], new RenderOptions(cache: true, autoEscape: false));

    assertSame('&lt;b&gt;', $escaped);
    assertSame('<b>', $raw);
});

test('clearCache purges backend and parse memo', function (): void {
    $engine = test_engine(['cache' => ['enabled' => true, 'driver' => 'array']]);
    $engine->renderString('a', [], new RenderOptions(cache: true));

    assertSame(true, $engine->clearCache() >= 1);
});

group('Stats & events');

test('stats count loops, includes, substitutions and timings', function (): void {
    fixture_write('tpl/stats/part.html', 'P');
    $engine = test_engine();

    $engine->renderString(
        'x%{a}<!--@Loop r-->%{n}<!--@EndLoop--><!--@Include "stats/part.html"-->',
        ['a' => 1, 'r' => [['n' => 1], ['n' => 2]]],
        test_options(),
    );

    $stats = $engine->stats();
    assertInstanceOf(RenderStats::class, $stats);
    assertSame(1, $stats->loops);
    assertSame(2, $stats->loopRows);
    assertSame(1, $stats->includes);
    assertSame(true, $stats->substitutions >= 3);
    assertSame(true, $stats->durationMs >= 0.0);
    assertSame(true, $stats->outputBytes > 0);
});

test('before/after events see the pipeline', function (): void {
    $engine = test_engine();
    $seen = [];

    $engine->events()->on('htmlphp.render.before', static function (string $origin) use (&$seen): void {
        $seen[] = 'before:' . $origin;
    });
    $engine->events()->on('htmlphp.render.after', static function (string $origin, string $text) use (&$seen): void {
        $seen[] = 'after:' . strlen($text);
    });
    $engine->events()->on('htmlphp.rendered', static function (RenderStats $stats) use (&$seen): void {
        $seen[] = 'stats:' . $stats->template;
    });

    $engine->renderString('abc');

    assertSame(['before:string', 'after:3', 'stats:string'], $seen);
});

test('errors event mirrors diagnostics', function (): void {
    $engine = test_engine();
    $codes = [];
    $engine->events()->on('htmlphp.error', static function (string $code) use (&$codes): void {
        $codes[] = $code;
    });

    $engine->renderString('%{gone}', []);

    assertSame(['missing-var'], $codes);
});

test('engines are independent — no shared state', function (): void {
    $engineA = test_engine(['syntax' => ['auto_escape' => true]]);
    $engineB = test_engine(['syntax' => ['auto_escape' => false]]);

    $engineA->filters()->add('mark', static fn(mixed $v): string => '[' . $v . ']');

    assertSame('&lt;b&gt;', $engineA->renderString('%{x}', ['x' => '<b>']));
    assertSame('<b>', $engineB->renderString('%{x}', ['x' => '<b>']));
    assertSame(false, $engineB->filters()->has('mark'));
});
