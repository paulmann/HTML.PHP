<?php
/**
 * 04 - SSI security: reserved names, the variable name gate, output escaping,
 *      include containment and every configured resource limit.
 */

declare(strict_types=1);

group('SSI security: reserved globals');

test('No reserved global can be written by #set', function (): void {
    $reserved = [
        'GLOBALS',
        '_SERVER',
        '_GET',
        '_POST',
        '_FILES',
        '_COOKIE',
        '_SESSION',
        '_REQUEST',
        '_ENV',
        'HTML_MAX_SSI_DEPTH',
        'HTML_MAX_SSI_TAGS',
        'HTML_MAX_TEMPLATE_PASSES',
        'HTML_MAX_BLOCK_DEPTH',
        'HTML_MAX_PRECISION',
        'HTML_MAX_TEMPLATE_BYTES',
        'HTML_MAX_INCLUDE_BYTES',
        'HTML_SSI_ALLOW_PARENT',
        'HTML_SSI_ECHO_ESCAPE',
        'HTML_SSI_ECHO_ALLOW',
        'HTML_SSI_TIMEFMT',
        'HTML_AUTO_SANITIZE_INPUT',
        'HTML_ALLOWED_TAGS',
        'HTML_WWW_PATH',
        'HTML_Prepare_Count',
    ];

    foreach ($reserved as $name) {
        if ($name === 'GLOBALS') {
            processSSI('<!--#set var="GLOBALS" value="pwned" -->');

            assertFalse(array_key_exists('GLOBALS', $GLOBALS), 'GLOBALS must never become a writable key');
            continue;
        }

        $before = array_key_exists($name, $GLOBALS) ? $GLOBALS[$name] : null;

        processSSI('<!--#set var="' . $name . '" value="pwned" -->');

        assertTrue(($GLOBALS[$name] ?? null) !== 'pwned', "{$name} must not be writable by #set");
        assertSame($before, $GLOBALS[$name] ?? null, "{$name} must keep its value");
    }
});

test('No reserved global can be written by <!--@var-->', function (): void {
    foreach (['_SERVER', 'HTML_WWW_PATH', 'HTML_SSI_ECHO_ESCAPE', 'HTML_MAX_SSI_DEPTH'] as $name) {
        $before = array_key_exists($name, $GLOBALS) ? $GLOBALS[$name] : null;

        processSSI('<!--@var="' . $name . '"pwned-->');

        assertTrue(($GLOBALS[$name] ?? null) !== 'pwned', "{$name} must not be writable by @var");
        assertSame($before, $GLOBALS[$name] ?? null, "{$name} must keep its value");
    }
});

test('No reserved global can be read by #echo', function (): void {
    $paths = ['GLOBALS', '_SERVER', '_GET', '_POST', '_COOKIE', '_SESSION', '_REQUEST', '_ENV', 'HTML_WWW_PATH'];

    foreach ($paths as $name) {
        assertSame('', processSSI('<!--#echo var="' . $name . '" -->'), "{$name} must not be readable by #echo");
    }
});

test('No reserved global can be read by the legacy ##echo', function (): void {
    foreach (['GLOBALS', '_SERVER', 'HTML_WWW_PATH', 'HTML_SSI_ECHO_ESCAPE'] as $name) {
        assertSame('', processSSI('<!--##echo var="' . $name . '" -->'), "{$name} must not be readable by ##echo");
    }
});

test('A reserved name inside a #set value is not expanded', function (): void {
    $GLOBALS['HTML_WWW_PATH'] = '/secret/root';

    processSSI('<!--#set var="LEAK" value="[$_SERVER]" -->');

    assertSame('[]', $GLOBALS['LEAK'], 'a reserved reference must expand to nothing, never to the global');
});

test('Built-in SSI names cannot be published as template variables', function (): void {
    foreach (array_keys(HTML_SSI_BUILTIN_VARS) as $name) {
        processSSI('<!--#set var="' . $name . '" value="pwned" -->');

        assertFalse(array_key_exists($name, $GLOBALS), "{$name} must not become a template variable");
    }
});

test('Built-in SSI names cannot be published by <!--@var--> either', function (): void {
    processSSI('<!--@var="QUERY_STRING"pwned-->');

    assertFalse(array_key_exists('QUERY_STRING', $GLOBALS));
});

group('SSI security: variable name gate');

test('_htmlValidVariableName() accepts the documented name shapes', function (): void {
    foreach (['NAME', 'NAME_1', 'Translate.NAME', 'user.name', '_private', 'A', 'Name-With-Dash', str_repeat('A', 128)] as $name) {
        assertTrue(_htmlValidVariableName($name), "{$name} must be accepted");
    }
});

test('_htmlValidVariableName() rejects empty, binary and path-like names', function (): void {
    $rejected = [
        'empty' => '',
        'null byte' => "A\0B",
        'leading digit' => '1NAME',
        'space' => 'NAME SPACE',
        'parent directory' => '../NAME',
        'slash' => 'NAME/OTHER',
        'backslash' => 'NAME\\OTHER',
        'angle bracket' => 'NAME>x',
        'bracket' => 'NAME[0]',
        'equals' => 'NAME=x',
        'over length' => str_repeat('A', 129),
        'dollar' => '$NAME',
    ];

    foreach ($rejected as $label => $name) {
        assertFalse(_htmlValidVariableName($name), "{$label} must be rejected");
    }
});

test('A path-like name is rejected by #set, #echo and <!--@var-->', function (): void {
    foreach (['../NAME', 'A/B', 'A>B', '1BAD'] as $name) {
        processSSI('<!--#set var="' . $name . '" value="pwned" -->');
        processSSI('<!--@var="' . $name . '"pwned-->');

        assertFalse(array_key_exists($name, $GLOBALS), "{$name} must not be published");
        assertSame('', processSSI('<!--#echo var="' . $name . '" -->'), "{$name} must not be echoed");
    }
});

test('A null byte in a directive name is tolerated', function (): void {
    assertSame('', processSSI("<!--#echo var=\"A\0B\" -->"));
    assertSame('', processSSI("<!--#set var=\"A\0B\" value=\"pwned\" -->"));
});

group('SSI security: echo allow-list and escaping');

test('The echo allow-list gates template variables', function (): void {
    $GLOBALS['HTML_SSI_ECHO_ALLOW'] = ['ALLOWED' => true];
    $GLOBALS['ALLOWED'] = 'yes';
    $GLOBALS['BLOCKED'] = 'no';

    assertSame('yes', processSSI('<!--#echo var="ALLOWED" -->'));
    assertSame('', processSSI('<!--#echo var="BLOCKED" -->'));
});

test('The echo allow-list gates the legacy ##echo directive too', function (): void {
    $GLOBALS['HTML_SSI_ECHO_ALLOW'] = ['ALLOWED' => true];
    $GLOBALS['ALLOWED'] = 'yes';
    $GLOBALS['BLOCKED'] = 'no';

    assertSame('yes', processSSI('<!--##echo var="ALLOWED" -->'));
    assertSame('', processSSI('<!--##echo var="BLOCKED" -->'));
});

test('The echo allow-list gates built-in SSI variables', function (): void {
    $GLOBALS['HTML_SSI_ECHO_ALLOW'] = ['DATE_GMT' => true];
    $GLOBALS['HTML_SSI_TIMEFMT'] = '%Y';

    assertSame(gmdate('Y'), processSSI('<!--#echo var="DATE_GMT" -->'));
    assertSame('', processSSI('<!--#echo var="QUERY_STRING" -->'));
});

test('The allow-list accepts a plain list as well as a map', function (): void {
    $GLOBALS['HTML_SSI_ECHO_ALLOW'] = ['ALLOWED'];
    $GLOBALS['ALLOWED'] = 'yes';

    assertSame('yes', processSSI('<!--#echo var="ALLOWED" -->'));
    assertSame('', processSSI('<!--#echo var="BLOCKED" -->'));
});

test('An empty allow-list keeps everything readable', function (): void {
    $GLOBALS['HTML_SSI_ECHO_ALLOW'] = [];
    $GLOBALS['ALLOWED'] = 'yes';

    assertSame('yes', processSSI('<!--#echo var="ALLOWED" -->'));
});

test('The echo allow-list does not stop #set from publishing', function (): void {
    $GLOBALS['HTML_SSI_ECHO_ALLOW'] = ['ALLOWED' => true];

    processSSI('<!--#set var="PUBLISHED" value="v" -->');

    assertSame('v', $GLOBALS['PUBLISHED'], '#set is gated by the reserved list, not by the echo allow-list');
});

test('Echo escaping uses the HTML5 quote rules', function (): void {
    $GLOBALS['QUOTED'] = "it's \"quoted\" & <b>";

    assertSame(
        'it&apos;s &quot;quoted&quot; &amp; &lt;b&gt;',
        processSSI('<!--#echo var="QUOTED" -->')
    );
});

test('Echo escaping can be disabled with a falsy value', function (): void {
    $GLOBALS['HTML_SSI_ECHO_ESCAPE'] = 0;
    $GLOBALS['QUOTED'] = '<b>x</b>';

    assertSame('<b>x</b>', processSSI('<!--#echo var="QUOTED" -->'));
});

test('A built-in echoed value is escaped as well', function (): void {
    $_SERVER['QUERY_STRING'] = 'a=<script>';

    assertSame('a=&lt;script&gt;', processSSI('<!--#echo var="QUERY_STRING" -->'));
});

group('SSI security: include containment');

test('A parent directory include is blocked by default', function (): void {
    $page = fixture_write('guard/inside/page.htm', '[<!--#include virtual="../outside.htm" -->]');
    fixture_write('guard/outside.htm', 'OUTSIDE');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('guard/inside');

    assertSame('[]', processSSI((string) file_get_contents($page), $page));
    assertFalse($GLOBALS['HTML_SSI_ALLOW_PARENT']);
});

test('A parent directory include is allowed on explicit opt-in', function (): void {
    $page = fixture_write('guard2/inside/page.htm', '[<!--#include virtual="../outside.htm" -->]');
    fixture_write('guard2/outside.htm', 'OUTSIDE');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('guard2/inside');
    $GLOBALS['HTML_SSI_ALLOW_PARENT'] = true;

    assertSame('[OUTSIDE]', processSSI((string) file_get_contents($page), $page));
});

test('An include may not escape the document root through a nested path', function (): void {
    $page = fixture_write('guard3/web/page.htm', '[<!--#include virtual="../../secret.htm" -->]');
    fixture_write('guard3/secret.htm', 'SECRET');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('guard3/web');

    assertSame('[]', processSSI((string) file_get_contents($page), $page));
});

test('A backslash separated parent path is blocked as well', function (): void {
    $page = fixture_write('guard4/web/page.htm', '[<!--#include virtual="..\\secret.htm" -->]');
    fixture_write('guard4/secret.htm', 'SECRET');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('guard4/web');

    assertSame('[]', processSSI((string) file_get_contents($page), $page));
});

test('An include path containing a null byte is rejected', function (): void {
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('guard5');
    fixture_write('guard5/ok.htm', 'OK');

    assertSame('[]', processSSI("[<!--#include virtual=\"ok\0.htm\" -->]"));
});

test('An include of a directory contributes nothing', function (): void {
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('guard6');
    fixture_write('guard6/sub/keep.htm', 'KEEP');

    assertSame('[]', processSSI('[<!--#include virtual="sub" -->]'));
});

test('A value containing > inside a quoted attribute still parses', function (): void {
    processSSI('<!--#set var="ARROW" value="a > b" -->');

    assertSame('a > b', $GLOBALS['ARROW']);

    $GLOBALS['ARROW'] = 'x > y';

    assertSame('x &gt; y', processSSI('<!--#echo var="ARROW" -->'));
});

test('An unquoted attribute is not parsed', function (): void {
    assertSame('[]', processSSI('[<!--#echo var=NAME -->]'));
});

group('SSI security: resource limits');

test('A self including file terminates instead of hanging', function (): void {
    $page = fixture_write('self/self.htm', 'X<!--#include virtual="self.htm" -->');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('self');
    $started = microtime(true);

    $output = processSSI((string) file_get_contents($page), $page);

    assertSame('XX', $output, 'the second level must be suppressed by the include stack');
    assertTrue((microtime(true) - $started) < 2.0, 'a self include must not hang');
});

test('A two file include cycle terminates instead of hanging', function (): void {
    $page = fixture_write('cycle/a.htm', 'A<!--#include virtual="b.htm" -->');
    fixture_write('cycle/b.htm', 'B<!--#include virtual="a.htm" -->');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('cycle');
    $started = microtime(true);

    $output = processSSI((string) file_get_contents($page), $page);

    assertSame('ABA', $output);
    assertTrue((microtime(true) - $started) < 2.0, 'an include cycle must not hang');
});

test('HTML_MAX_SSI_DEPTH stops a deep include chain', function (): void {
    $page = fixture_write('depth/a.htm', 'A<!--#include virtual="b.htm" -->');
    fixture_write('depth/b.htm', 'B<!--#include virtual="c.htm" -->');
    fixture_write('depth/c.htm', 'C<!--#include virtual="d.htm" -->');
    fixture_write('depth/d.htm', 'D');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('depth');

    assertSame('ABCD', processSSI((string) file_get_contents($page), $page), 'the default depth allows the chain');

    $GLOBALS['HTML_MAX_SSI_DEPTH'] = 2;

    assertSame('ABC', processSSI((string) file_get_contents($page), $page), 'the configured depth cuts the chain');
});

test('HTML_MAX_SSI_TAGS stops the include processing after N directives', function (): void {
    $page = fixture_write('tags/page.htm', '<!--#include virtual="p.htm" --><!--#include virtual="p.htm" --><!--#include virtual="p.htm" -->');
    fixture_write('tags/p.htm', 'P');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('tags');

    assertSame('PPP', processSSI((string) file_get_contents($page), $page));

    $GLOBALS['HTML_MAX_SSI_TAGS'] = 2;

    assertSame('PP', processSSI((string) file_get_contents($page), $page));
});

test('HTML_MAX_INCLUDE_BYTES rejects an oversized partial', function (): void {
    $page = fixture_write('bytes/page.htm', '[<!--#include virtual="small.htm" -->][<!--#include virtual="big.htm" -->]');
    fixture_write('bytes/small.htm', 'ABCD');
    fixture_write('bytes/big.htm', 'ABCDE');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('bytes');

    assertSame('[ABCD][ABCDE]', processSSI((string) file_get_contents($page), $page));

    $GLOBALS['HTML_MAX_INCLUDE_BYTES'] = 4;

    assertSame('[ABCD][]', processSSI((string) file_get_contents($page), $page));
});

test('HTML_MAX_TEMPLATE_BYTES rejects an oversized template in LoadTemplate()', function (): void {
    $file = fixture_write('bytes/big-template.htm', str_repeat('x', 64));

    assertSame(64, strlen(LoadTemplate($file)), 'the default limit accepts the file');

    $GLOBALS['HTML_MAX_TEMPLATE_BYTES'] = 16;

    assertSame('Bad Template File', LoadTemplate($file));
});

test('HTML_MAX_TEMPLATE_BYTES rejects an oversized template in Read_HTML()', function (): void {
    $file = fixture_write('bytes/big-html.htm', str_repeat('y', 64));

    $GLOBALS['HTML_MAX_TEMPLATE_BYTES'] = 16;

    $error = assertThrows(RuntimeException::class, static fn(): string => Read_HTML($file));
    assertContains('exceeds the configured size limit', $error->getMessage());
});

test('A bounded configuration value is clamped into its range', function (): void {
    $GLOBALS['HTML_MAX_PRECISION'] = -5;
    assertSame(0, _htmlPositiveLimit('HTML_MAX_PRECISION', 100, 1000, 0));

    $GLOBALS['HTML_MAX_PRECISION'] = 5000;
    assertSame(1000, _htmlPositiveLimit('HTML_MAX_PRECISION', 100, 1000, 0));

    $GLOBALS['HTML_MAX_PRECISION'] = '25';
    assertSame(25, _htmlPositiveLimit('HTML_MAX_PRECISION', 100, 1000, 0));

    $GLOBALS['HTML_MAX_PRECISION'] = 'not a number';
    assertSame(100, _htmlPositiveLimit('HTML_MAX_PRECISION', 100, 1000, 0));

    unset($GLOBALS['HTML_MAX_PRECISION']);
    assertSame(100, _htmlPositiveLimit('HTML_MAX_PRECISION', 100, 1000, 0));

    $GLOBALS['HTML_MAX_SSI_DEPTH'] = 0;
    assertSame(1, _htmlPositiveLimit('HTML_MAX_SSI_DEPTH', 16, 128), 'the default minimum is one');
});
