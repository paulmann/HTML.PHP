<?php

/**
 * 05 — SSI core: #config, #set, #echo, #include, #fsize/#flastmod,
 *      #printenv, #block/stub, custom directives, built-ins.
 */

declare(strict_types=1);

use HtmlPhp\Diagnostics;
use HtmlPhp\Ssi\DirectiveInterface;
use HtmlPhp\Ssi\DocumentInfo;
use HtmlPhp\Ssi\SsiContext;
use HtmlPhp\Support\FrozenClock;

/**
 * Helper: run SSI over a string with a fixture document anchor.
 */
function ssi(HtmlPhp\Engine $engine, string $content, ?string $file = null): string
{
    return $engine->processSsi($content, new DocumentInfo(
        file: $file,
        root: fixture_path('tpl'),
        request: test_request(),
    ));
}

group('SSI: #config');

test('#config changes timefmt for subsequent #echo DATE_LOCAL', function (): void {
    $clock = new FrozenClock(1_700_000_000); // 2023-11-14 22:13:20 UTC
    $engine = test_engine([], $clock);

    $out = ssi($engine, '<!--#config timefmt="%Y-%m-%d" --><!--#echo var="DATE_LOCAL" -->');
    assertSame('2023-11-14', $out);

    $out = ssi($engine, '<!--#echo var="DATE_GMT" -->');
    assertSame('Tuesday, 14-Nov-2023 22:13:20 GMT', $out); // DATE_GMT renders %Z as GMT
});

test('#config sizefmt switches #fsize output', function (): void {
    $engine = test_engine();
    $path = fixture_write('tpl/ssi/bytes.html', str_repeat('x', 2048));

    assertSame('2K', ssi($engine, '<!--#fsize file="bytes.html" -->', $path));
    assertSame('2048', ssi($engine, '<!--#config sizefmt="bytes" --><!--#fsize file="bytes.html" -->', $path));
});

test('#config errmsg is emitted for failing directives', function (): void {
    $engine = test_engine();

    assertSame('[oops]', ssi($engine, '<!--#config errmsg="[oops]" --><!--#include virtual="missing.html" -->'));
});

test('directives never reach the output', function (): void {
    $engine = test_engine();

    assertSame('ab', ssi($engine, 'a<!--#config timefmt="%H:%M" -->b'));
});

group('SSI: #set and #echo');

test('#set publishes a variable for later directives only within the same render', function (): void {
    $engine = test_engine();

    assertSame('hello world', ssi($engine, '<!--#set var="GREETING" value="hello" --><!--#echo var="GREETING" --> world'));
    // NOT a global: a second render does not see it.
    assertSame('', ssi($engine, '<!--#echo var="GREETING" -->'));
    assertSame(false, isset($GLOBALS['GREETING']));
});

test('#set expands $VAR and ${VAR}; unknown names stay verbatim; \\$ escapes', function (): void {
    $engine = test_engine();

    $out = ssi($engine, '<!--#set var="A" value="1" --><!--#set var="B" value="${A}2 $A $MISSING \\$LIT" --><!--#echo var="B" -->');
    assertSame('12 1 $MISSING $LIT', $out);
});

test('#set refuses reserved and built-in names', function (): void {
    $engine = test_engine();

    ssi($engine, '<!--#set var="_GET" value="x" --><!--#set var="DATE_LOCAL" value="x" -->');
    assertSame('', ssi($engine, '<!--#echo var="_GET" -->'));
    assertSame(true, $engine->diagnostics()->count() >= 1);
});

test('#echo escapes by default; encoding= selects the strategy', function (): void {
    $engine = test_engine();

    assertSame('&lt;b&gt;', ssi($engine, '<!--#set var="X" value="<b>" --><!--#echo var="X" -->'));
    assertSame('<b>', ssi($engine, '<!--#set var="X" value="<b>" --><!--#echo var="X" encoding="none" -->'));
    assertSame('&lt;b&gt;', ssi($engine, '<!--#set var="X" value="<b>" --><!--#echo var="X" encoding="entity" -->'));
    assertSame('%3Cb%3E', ssi($engine, '<!--#set var="X" value="<b>" --><!--#echo var="X" encoding="url" -->'));
    assertSame('PGI+', ssi($engine, '<!--#set var="X" value="<b>" --><!--#echo var="X" encoding="base64" -->'));
});

test('escape_echo=false passes values through', function (): void {
    $engine = test_engine(['ssi' => ['escape_echo' => false]]);

    assertSame('<b>', ssi($engine, '<!--#set var="X" value="<b>" --><!--#echo var="X" -->'));
});

test('echo_allow gates built-in variables', function (): void {
    $engine = test_engine(['ssi' => ['echo_allow' => ['DATE_LOCAL']]]);

    assertTrue(ssi($engine, '<!--#echo var="DATE_LOCAL" -->') !== '');
    assertSame('', ssi($engine, '<!--#echo var="REMOTE_ADDR" -->'));
});

test('HTTP_* and request built-ins resolve from the injected context', function (): void {
    $engine = test_engine();

    assertSame('203.0.113.7', ssi($engine, '<!--#echo var="REMOTE_ADDR" -->'));
    assertSame('HtmlPhpTests/4.0', ssi($engine, '<!--#echo var="HTTP_USER_AGENT" -->'));
    assertSame('/index.shtml', ssi($engine, '<!--#echo var="DOCUMENT_URI" -->'));
    assertSame('a=1', ssi($engine, '<!--#echo var="QUERY_STRING" -->'));
    assertSame('example.test', ssi($engine, '<!--#echo var="SERVER_NAME" -->'));
    assertSame('GET', ssi($engine, '<!--#echo var="REQUEST_METHOD" -->'));
});

test('LAST_MODIFIED reflects the anchor file mtime', function (): void {
    $engine = test_engine([], new FrozenClock(1_700_000_000));
    $path = fixture_write('tpl/ssi/mod.html', 'x');
    touch($path, 1_690_000_000);
    clearstatcache();

    $out = ssi($engine, '<!--#config timefmt="%Y" --><!--#echo var="LAST_MODIFIED" -->', $path);
    assertSame('2023', $out);
});

test('engine render scope is visible to SSI as document vars', function (): void {
    $engine = test_engine();

    $out = $engine->renderString(
        '<!--#if expr=\'$username = "bob"\' -->hi<!--#else-->no<!--#endif-->',
        ['username' => 'bob'],
        test_options(),
    );
    assertSame('hi', $out);
});

group('SSI: #include');

test('include file= resolves relative to the anchor document', function (): void {
    $engine = test_engine();
    fixture_write('tpl/ssi/doc.html', 'DOC');
    fixture_write('tpl/ssi/sub/part.html', 'PART');

    assertSame('DPART', ssi($engine, 'D<!--#include file="sub/part.html" -->', fixture_path('tpl/ssi/doc.html')));
});

test('include virtual= resolves from the document root', function (): void {
    $engine = test_engine();
    fixture_write('tpl/ssi/v.html', 'V');

    assertSame('V', ssi($engine, '<!--#include virtual="/ssi/v.html" -->'));
});

test('nested includes process recursively and share #set state', function (): void {
    $engine = test_engine();
    fixture_write('tpl/ssi/inner.html', '[<!--#echo var="WHO" -->]');
    fixture_write('tpl/ssi/outer.html', '<!--#set var="WHO" value="deep" --><!--#include file="inner.html" -->');

    assertSame('[deep]', ssi($engine, '<!--#include virtual="/ssi/outer.html" -->'));
});

test('traversal outside the root is refused', function (): void {
    $engine = test_engine();
    fixture_write('outside.html', 'SECRET');
    fixture_write('tpl/ssi/doc.html', 'x');

    assertSame('', ssi($engine, '<!--#include file="../../outside.html" -->', fixture_path('tpl/ssi/doc.html')));
    assertSame(true, $engine->diagnostics()->count(Diagnostics::SSI_INCLUDE_FAILED) > 0);
});

test('script/config extensions are never included (source-disclosure guard)', function (): void {
    $engine = test_engine();
    fixture_write('tpl/ssi/leak.php', '<?php echo "source";');
    fixture_write('tpl/ssi/leak.env', 'DB=secret');
    fixture_write('tpl/ssi/doc.html', 'x');

    assertSame('', ssi($engine, '<!--#include file="leak.php" -->', fixture_path('tpl/ssi/doc.html')));
    assertSame('', ssi($engine, '<!--#include file="leak.env" -->', fixture_path('tpl/ssi/doc.html')));
});

test('include cycles are detected', function (): void {
    $engine = test_engine();
    fixture_write('tpl/ssi/a.html', 'a<!--#include file="b.html" -->');
    fixture_write('tpl/ssi/b.html', 'b<!--#include file="a.html" -->');

    $out = ssi($engine, '<!--#include virtual="/ssi/a.html" -->');
    assertSame(true, str_starts_with($out, 'ab'));
    assertSame(true, $engine->diagnostics()->count(Diagnostics::SSI_INCLUDE_CYCLE) > 0);
});

test('depth and tag budgets are enforced', function (): void {
    $engine = test_engine(['limits' => ['max_ssi_depth' => 1]]);
    fixture_write('tpl/ssi/l1.html', '1<!--#include file="l2.html" -->');
    fixture_write('tpl/ssi/l2.html', '2');

    assertSame('1', ssi($engine, '<!--#include virtual="/ssi/l1.html" -->'));
    assertSame(true, $engine->diagnostics()->count(Diagnostics::SSI_LIMIT_REACHED) > 0);
});

test('oversized includes are refused', function (): void {
    $engine = test_engine(['limits' => ['max_include_bytes' => 1024]]);
    fixture_write('tpl/ssi/big.html', str_repeat('x', 4096));

    assertSame('', ssi($engine, '<!--#include virtual="/ssi/big.html" -->'));
});

test('stub blocks render when the include fails', function (): void {
    $engine = test_engine();

    $out = ssi($engine, '<!--#block name="fb"-->FALLBACK<!--#endblock--><!--#include virtual="/ssi/nope.html" stub="fb" -->');
    assertSame('FALLBACK', $out);
});

test('stub is ignored when the include succeeds; block content never duplicates', function (): void {
    $engine = test_engine();
    fixture_write('tpl/ssi/ok.html', 'OK');

    $out = ssi($engine, '<!--#block name="fb"-->FALLBACK<!--#endblock--><!--#include virtual="/ssi/ok.html" stub="fb" -->');
    assertSame('OK', $out);
});

test('wait= attribute is accepted and ignored', function (): void {
    $engine = test_engine();
    fixture_write('tpl/ssi/w.html', 'W');

    assertSame('W', ssi($engine, '<!--#include virtual="/ssi/w.html" wait="yes" -->'));
});

test('htmlphp.ssi.include_path filter can remap resolved targets', function (): void {
    $engine = test_engine();
    fixture_write('tpl/ssi/alias.html', 'ALIAS');
    fixture_write('tpl/ssi/real.html', 'REAL');
    $engine->events()->on('htmlphp.ssi.include_path', static fn(string $path): string => str_replace('alias', 'real', $path));

    assertSame('REAL', ssi($engine, '<!--#include virtual="/ssi/alias.html" -->'));
});

group('SSI: #fsize / #flastmod / #printenv');

test('#fsize and #flastmod default to the current document', function (): void {
    $engine = test_engine([], new FrozenClock(1_700_000_000));
    $path = fixture_write('tpl/ssi/self.html', str_repeat('y', 3000));
    touch($path, 1_700_000_000);
    clearstatcache();

    assertSame('3K', ssi($engine, '<!--#fsize -->', $path));
    assertSame('2023', ssi($engine, '<!--#config timefmt="%Y" --><!--#flastmod -->', $path));
});

test('#printenv dumps variables, escaped, one per line', function (): void {
    $engine = test_engine([], new FrozenClock(1_700_000_000));

    $out = ssi($engine, '<!--#set var="MY" value="<v>" --><!--#printenv-->');
    assertContains('DATE_LOCAL=', $out);
    assertContains('MY=&lt;v&gt;', $out);
    assertContains('DOCUMENT_ROOT=', $out);
    assertSame(true, substr_count($out, "\n") > 5);
});

test('#printenv can be disabled', function (): void {
    $engine = test_engine(['ssi' => ['printenv_enabled' => false]]);

    assertSame('', ssi($engine, '<!--#printenv-->'));
});

group('SSI: misc');

test('unknown directives stay verbatim and are recorded', function (): void {
    $engine = test_engine();

    assertSame('<!--#frobnicate x="1" -->', ssi($engine, '<!--#frobnicate x="1" -->'));
    assertSame(true, $engine->diagnostics()->count(Diagnostics::SSI_UNKNOWN_DIRECTIVE) > 0);
});

test('custom directives can be registered and override built-ins', function (): void {
    $engine = test_engine();
    $engine->ssi()->directives()->register(new class implements DirectiveInterface {
        public function name(): string
        {
            return 'upper';
        }

        public function render(array $attributes, SsiContext $context): string
        {
            return strtoupper($attributes['text'] ?? '');
        }
    });

    assertSame('ABC', ssi($engine, '<!--#upper text="abc" -->'));
    assertThrows(InvalidArgumentException::class, function () use ($engine): void {
        $engine->ssi()->directives()->register(new class implements DirectiveInterface {
            public function name(): string
            {
                return 'Bad Name';
            }

            public function render(array $attributes, SsiContext $context): string
            {
                return '';
            }
        });
    });
});

test('ssi disabled by config is a no-op', function (): void {
    $engine = test_engine(['ssi' => ['enabled' => false]]);

    assertSame('<!--#echo var="DATE_LOCAL" -->', ssi($engine, '<!--#echo var="DATE_LOCAL" -->'));
});

test('content without directives passes through untouched', function (): void {
    $engine = test_engine();
    $html = '<div class="x">text <!-- normal comment --> more</div>';

    assertSame($html, ssi($engine, $html));
});

test('variables() exposes the built-in map', function (): void {
    $engine = test_engine([], new FrozenClock(1_700_000_000));
    $vars = $engine->ssiVariables(new DocumentInfo(root: fixture_path('tpl'), request: test_request()));

    assertSame('203.0.113.7', $vars['REMOTE_ADDR']);
    assertSame(true, isset($vars['DATE_LOCAL'], $vars['DATE_GMT'], $vars['DOCUMENT_URI']));
});

test('legacy ##name and @var forms are gone in v4', function (): void {
    $engine = test_engine();

    assertSame('<!--##echo var="X" -->', ssi($engine, '<!--##echo var="X" -->'));
});
