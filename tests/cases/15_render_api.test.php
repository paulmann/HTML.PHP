<?php
/**
 * 15 - Modern render API: scoping, options, missing-variable policies,
 *      diagnostics and the render cache.
 */

declare(strict_types=1);

group('Render: scoping');

test('html_render resolves scoped variables only', function (): void {
    $GLOBALS['OUTSIDE'] = 'global';

    assertSame('local', html_render('%{X}', ['X' => 'local']));
    assertSame('', html_render('%{OUTSIDE}', []), 'globals are invisible by default');
    assertSame('global', html_render('%{OUTSIDE}', [], ['globals' => true]));
});

test('Scoped rendering never writes to $GLOBALS', function (): void {
    unset($GLOBALS['SCOPED_VAR']);
    html_render('%{SCOPED_VAR}', ['SCOPED_VAR' => 'v']);

    assertFalse(isset($GLOBALS['SCOPED_VAR']));
});

test('Dot notation walks nested scopes', function (): void {
    $vars = ['user' => ['name' => 'Alice', 'address' => ['city' => 'Paris']]];

    assertSame('Alice', html_render('%{user.name}', $vars));
    assertSame('Paris', html_render('%{user.address.city}', $vars));
    assertSame('', html_render('%{user.unknown.deep}', $vars));
});

test('Dot notation resolves object properties', function (): void {
    $inner = new stdClass();
    $inner->city = 'Rome';
    $vars = ['user' => ['address' => $inner]];

    assertSame('Rome', html_render('%{user.address.city}', $vars));
});

test('Stringable values render through __toString', function (): void {
    $vars = ['obj' => new class () implements Stringable {
        public function __toString(): string
        {
            return 'stringable';
        }
    }];

    assertSame('stringable', html_render('%{obj}', $vars));
});

test('Arrays and nulls render as empty strings', function (): void {
    assertSame('[][]', html_render('[%{a}][%{b}]', ['a' => ['x'], 'b' => null]));
});

group('Render: options');

test('ssi=false skips SSI processing', function (): void {
    $GLOBALS['OPT_VAR'] = 'v';

    $output = html_render('<!--#echo var="OPT_VAR" -->', [], ['ssi' => false]);

    assertSame('<!--#echo var="OPT_VAR" -->', $output);
});

test('loops=false keeps loop markers verbatim', function (): void {
    $output = html_render('<!--@Loop R-->x<!--@EndLoop-->', ['R' => [1]], ['loops' => false]);

    assertContains('<!--@Loop R-->', $output);
});

test('passes=1 leaves %[NAME] unresolved', function (): void {
    $output = html_render('%[X]', ['X' => 'v'], ['passes' => 1]);

    assertSame('%{X}', $output);
});

test('passes=2 resolves %[NAME] like the legacy engine', function (): void {
    assertSame('v', html_render('%[X]', ['X' => 'v'], ['passes' => 2]));
});

test('html_render_file renders a file and anchors relative includes', function (): void {
    fixture_write('render/part.htm', 'PART');
    $file = fixture_write('render/page.htm', 'x<!--#include virtual="/part.htm" -->y%{T}');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('render');

    assertSame('xPARTy!', html_render_file($file, ['T' => '!']));
});

test('html_render_file rejects missing files', function (): void {
    assertThrows(HtmlPhp\TemplateException::class, static fn() => html_render_file(fixture_path('render/nope.htm')));
});

test('Oversized templates are rejected', function (): void {
    $GLOBALS['HTML_MAX_TEMPLATE_BYTES'] = 16;

    assertThrows(
        HtmlPhp\TemplateException::class,
        static fn() => html_render(str_repeat('x', 64))
    );
});

group('Render: missing-variable policies');

test('The default policy renders an empty string', function (): void {
    assertSame('[]', html_render('[%{MISSING_ONE}]', []));
});

test('keep leaves the placeholder in the output', function (): void {
    $GLOBALS['HTML_MISSING_VAR'] = 'keep';

    assertSame('[%{MISSING_TWO}]', html_render('[%{MISSING_TWO}]', []));
});

test('placeholder renders a visible marker', function (): void {
    $GLOBALS['HTML_MISSING_VAR'] = 'placeholder';

    assertSame('[missing:MISSING_THREE]', html_render('%{MISSING_THREE}', []));
});

test('exception throws a TemplateException', function (): void {
    $GLOBALS['HTML_MISSING_VAR'] = 'exception';

    $error = assertThrows(
        HtmlPhp\TemplateException::class,
        static fn() => html_render('%{MISSING_FOUR}', [])
    );
    assertContains('MISSING_FOUR', $error->getMessage());
});

test('HTML_DEBUG forces visible placeholders', function (): void {
    $GLOBALS['HTML_DEBUG'] = true;

    assertSame('[missing:DBG]', html_render('%{DBG}', []));
});

test('Missing variables are recorded with context', function (): void {
    html_clear_errors();
    html_render('%{REC_ONE}%{REC_TWO}', []);

    $errors = html_errors('missing-var');

    assertSame(2, count($errors));
    assertSame('REC_ONE', $errors[0]['context']['name']);
});

group('Render: cache');

test('A cached render returns identical output without re-running', function (): void {
    $GLOBALS['HTML_CACHE_DIR'] = fixture_path('cache');
    @mkdir(fixture_path('cache'), 0o777, true);

    $runs = 0;
    html_add_filter('htmlphp_render', static function (string $output) use (&$runs): string {
        ++$runs;

        return $output;
    });

    $first = html_render('cache-%{V}', ['V' => '1'], ['cache' => true, 'ttl' => 60]);
    $second = html_render('cache-%{V}', ['V' => '1'], ['cache' => true, 'ttl' => 60]);

    assertSame('cache-1', $first);
    assertSame('cache-1', $second);
    assertSame(1, $runs, 'the second render must come from the cache');
});

test('Different variables produce different cache entries', function (): void {
    $GLOBALS['HTML_CACHE_DIR'] = fixture_path('cache');
    @mkdir(fixture_path('cache'), 0o777, true);

    $a = html_render('v=%{V}', ['V' => 'a'], ['cache' => true, 'ttl' => 60]);
    $b = html_render('v=%{V}', ['V' => 'b'], ['cache' => true, 'ttl' => 60]);

    assertSame('v=a', $a);
    assertSame('v=b', $b);
});

test('html_cache_clear purges the cache directory', function (): void {
    $GLOBALS['HTML_CACHE_DIR'] = fixture_path('cache');
    @mkdir(fixture_path('cache'), 0o777, true);

    html_render('purge-%{V}', ['V' => '1'], ['cache' => true, 'ttl' => 60]);
    $deleted = html_cache_clear();

    assertTrue($deleted >= 1);

    $runs = 0;
    html_add_filter('htmlphp_render', static function (string $output) use (&$runs): string {
        ++$runs;

        return $output;
    });
    html_render('purge-%{V}', ['V' => '1'], ['cache' => true, 'ttl' => 60]);

    assertSame(1, $runs, 'after the purge the template renders again');
});

test('An expired entry is regenerated', function (): void {
    $GLOBALS['HTML_CACHE_DIR'] = fixture_path('cache');
    @mkdir(fixture_path('cache'), 0o777, true);

    html_render('ttl-%{V}', ['V' => '1'], ['cache' => true, 'ttl' => 60]);

    // Age every entry beyond its TTL.
    foreach ((array) glob(fixture_path('cache') . '/*.htmlphp') as $entry) {
        touch($entry, time() - 120);
        $raw = json_decode((string) file_get_contents($entry), true);
        $raw['expires'] = time() - 1;
        file_put_contents($entry, json_encode($raw));
    }

    $runs = 0;
    html_add_filter('htmlphp_render', static function (string $output) use (&$runs): string {
        ++$runs;

        return $output;
    });

    assertSame('ttl-1', html_render('ttl-%{V}', ['V' => '1'], ['cache' => true, 'ttl' => 60]));
    assertSame(1, $runs);
});

test('The cache stays off without HTML_CACHE_DIR', function (): void {
    $GLOBALS['HTML_CACHE_DIR'] = '';

    $runs = 0;
    html_add_filter('htmlphp_render', static function (string $output) use (&$runs): string {
        ++$runs;

        return $output;
    });

    html_render('off-%{V}', ['V' => '1'], ['cache' => true, 'ttl' => 60]);
    html_render('off-%{V}', ['V' => '1'], ['cache' => true, 'ttl' => 60]);

    assertSame(2, $runs);
});

group('Render: configuration API');

test('html_config applies overrides and reports effective values', function (): void {
    $config = html_config(['HTML_DEBUG' => true, 'HTML_MAX_SSI_DEPTH' => 5]);

    assertTrue($config['HTML_DEBUG']);
    assertSame(5, $config['HTML_MAX_SSI_DEPTH']);
    assertTrue(isset($config['HTML_I18N_LOCALE']));
});

test('html_version reports the semantic version and edition', function (): void {
    assertMatches('~^3\.0\.0 \(8\.[35] edition\)$~', html_version());
});
