<?php
/**
 * 03 - SSI directives: #config, #set, #echo, ##echo, #include, <!--@var-->.
 */

declare(strict_types=1);

group('SSI: #config');

test('#config stores the time format in HTML_SSI_TIMEFMT', function (): void {
    $output = processSSI('a<!--#config timefmt="%H:%M" -->b');

    assertSame('ab', $output, 'the directive itself must not reach the output');
    assertSame('%H:%M', $GLOBALS['HTML_SSI_TIMEFMT']);
});

test('#config accepts single quoted attribute values', function (): void {
    $GLOBALS['HTML_SSI_TIMEFMT'] = '%A';

    processSSI("<!--#config timefmt='%Y' -->");

    assertSame('%Y', $GLOBALS['HTML_SSI_TIMEFMT']);
});

test('#config ignores an empty time format', function (): void {
    $GLOBALS['HTML_SSI_TIMEFMT'] = '%Y';

    processSSI('<!--#config timefmt="" -->');

    assertSame('%Y', $GLOBALS['HTML_SSI_TIMEFMT']);
});

group('SSI: #set');

test('#set publishes a variable and removes the directive', function (): void {
    $output = processSSI('before<!--#set var="GREETING" value="hello" -->after');

    assertSame('beforeafter', $output);
    assertSame('hello', $GLOBALS['GREETING']);
});

test('#set accepts a reversed attribute order (value before var)', function (): void {
    processSSI('<!--#set value="reversed" var="REVERSED" -->');

    assertSame('reversed', $GLOBALS['REVERSED']);
});

test('#set accepts single quoted attributes in either order', function (): void {
    processSSI("<!--#set var='ONE' value='1' -->");
    processSSI("<!--#set value='2' var='TWO' -->");

    assertSame('1', $GLOBALS['ONE']);
    assertSame('2', $GLOBALS['TWO']);
});

test('#set keeps extra whitespace and newlines inside the attribute list', function (): void {
    processSSI("<!--#set\n   var=\"MULTI\"\n   value=\"ok\"\n-->");

    assertSame('ok', $GLOBALS['MULTI']);
});

test('#set expands $VAR references inside the value', function (): void {
    $GLOBALS['BASE'] = 'root';

    processSSI('<!--#set var="FULL" value="pre-$BASE-post" -->');

    assertSame('pre-root-post', $GLOBALS['FULL']);
});

test('#set expands ${VAR} references inside the value', function (): void {
    $GLOBALS['BASE'] = 'root';

    processSSI('<!--#set var="FULL" value="pre-${BASE}-post" -->');

    assertSame('pre-root-post', $GLOBALS['FULL']);
});

test('#set expands a built-in SSI variable reference', function (): void {
    $_SERVER['QUERY_STRING'] = 'a=1&b=2';

    processSSI('<!--#set var="LINK" value="/page?$QUERY_STRING" -->');

    assertSame('/page?a=1&b=2', $GLOBALS['LINK']);
});

test('#set keeps an unknown reference verbatim', function (): void {
    processSSI('<!--#set var="KEPT" value="x$UNKNOWN_NAME y" -->');

    assertSame('x$UNKNOWN_NAME y', $GLOBALS['KEPT']);
});

test('#set treats a backslash escaped dollar sign as a literal', function (): void {
    $GLOBALS['BASE'] = 'root';

    processSSI('<!--#set var="LITERAL" value="costs \$BASE" -->');

    assertSame('costs $BASE', $GLOBALS['LITERAL']);
});

test('#set cannot overwrite a reserved global', function (): void {
    $server = $_SERVER;
    $_SERVER = ['ORIGINAL' => true];
    $GLOBALS['HTML_SSI_ECHO_ESCAPE'] = true;

    processSSI('<!--#set var="_SERVER" value="pwned" -->');
    processSSI('<!--#set var="HTML_SSI_ECHO_ESCAPE" value="off" -->');
    processSSI('<!--#set var="GLOBALS" value="pwned" -->');

    assertSame(['ORIGINAL' => true], $_SERVER);
    assertSame(true, $GLOBALS['HTML_SSI_ECHO_ESCAPE']);
});

test('#set cannot overwrite a built-in SSI variable', function (): void {
    processSSI('<!--#set var="DATE_LOCAL" value="pwned" -->');
    processSSI('<!--#set var="DOCUMENT_URI" value="pwned" -->');

    assertFalse(array_key_exists('DATE_LOCAL', $GLOBALS), 'DATE_LOCAL must not be published as a template variable');
    assertFalse(array_key_exists('DOCUMENT_URI', $GLOBALS));
});

test('#set ignores a syntactically invalid variable name', function (): void {
    processSSI('<!--#set var="1BAD" value="x" -->');
    processSSI('<!--#set var="" value="x" -->');

    assertFalse(array_key_exists('1BAD', $GLOBALS));
});

test('#set accepts an empty value', function (): void {
    $GLOBALS['EMPTY_ONE'] = 'previous';

    processSSI('<!--#set var="EMPTY_ONE" value="" -->');

    assertSame('', $GLOBALS['EMPTY_ONE']);
});

group('SSI: #echo');

test('#echo renders a template variable', function (): void {
    $GLOBALS['TITLE'] = 'Page title';

    assertSame('Page title', processSSI('<!--#echo var="TITLE" -->'));
});

test('#echo escapes the value by default', function (): void {
    $GLOBALS['UNSAFE'] = '<script>alert("x")</script>';

    assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', processSSI('<!--#echo var="UNSAFE" -->'));
});

test('#echo does not escape when HTML_SSI_ECHO_ESCAPE is disabled', function (): void {
    $GLOBALS['HTML_SSI_ECHO_ESCAPE'] = false;
    $GLOBALS['UNSAFE'] = '<b>bold</b>';

    assertSame('<b>bold</b>', processSSI('<!--#echo var="UNSAFE" -->'));
});

test('#echo accepts single quoted attributes', function (): void {
    $GLOBALS['TITLE'] = 'quoted';

    assertSame('quoted', processSSI("<!--#echo var='TITLE' -->"));
});

test('#echo renders an unknown variable as the empty string', function (): void {
    assertSame('[]', processSSI('[<!--#echo var="DEFINITELY_NOT_SET" -->]'));
});

test('#echo without a var attribute renders nothing', function (): void {
    assertSame('[]', processSSI('[<!--#echo -->]'));
});

test('#echo renders DATE_LOCAL in the configured format', function (): void {
    $GLOBALS['HTML_SSI_TIMEFMT'] = '%Y';
    $before = date('Y');
    $rendered = processSSI('<!--#echo var="DATE_LOCAL" -->');
    $after = date('Y');

    assertTrue($rendered === $before || $rendered === $after, "unexpected DATE_LOCAL: {$rendered}");
});

test('#echo renders DATE_GMT in the configured format', function (): void {
    $GLOBALS['HTML_SSI_TIMEFMT'] = '%Y-%m-%d';
    $before = gmdate('Y-m-d');
    $rendered = processSSI('<!--#echo var="DATE_GMT" -->');
    $after = gmdate('Y-m-d');

    assertTrue(
        $rendered === $before || $rendered === $after,
        "DATE_GMT must be formatted in GMT, got {$rendered}"
    );
});

test('#echo renders DOCUMENT_URI without the query string', function (): void {
    $_SERVER['REQUEST_URI'] = '/catalog/item.htm?page=2';

    assertSame('/catalog/item.htm', processSSI('<!--#echo var="DOCUMENT_URI" -->'));
});

test('#echo renders DOCUMENT_NAME as the basename of the request', function (): void {
    $_SERVER['REQUEST_URI'] = '/catalog/item.htm?page=2';

    assertSame('item.htm', processSSI('<!--#echo var="DOCUMENT_NAME" -->'));
});

test('#echo renders QUERY_STRING', function (): void {
    $_SERVER['QUERY_STRING'] = 'page=2&sort=asc';

    assertSame('page=2&amp;sort=asc', processSSI('<!--#echo var="QUERY_STRING" -->'));
});

test('#echo renders DOCUMENT_ROOT from HTML_WWW_PATH', function (): void {
    // The PHP CLI SAPI populates $_SERVER['DOCUMENT_ROOT']; the override must
    // only be used when the server variable is absent.
    unset($_SERVER['DOCUMENT_ROOT']);
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('echo-root');

    assertSame(fixture_path('echo-root'), processSSI('<!--#echo var="DOCUMENT_ROOT" -->'));
});

test('#echo renders DOCUMENT_ROOT from the server variable when set', function (): void {
    $_SERVER['DOCUMENT_ROOT'] = '/var/www/html';

    assertSame('/var/www/html', processSSI('<!--#echo var="DOCUMENT_ROOT" -->'));
});

test('#echo renders REMOTE_USER from the template globals', function (): void {
    // REMOTE_USER is not an Apache-generated SSI variable in this library; it is
    // resolved as an ordinary template variable.
    assertSame('', processSSI('<!--#echo var="REMOTE_USER" -->'));

    $GLOBALS['REMOTE_USER'] = 'alice';

    assertSame('alice', processSSI('<!--#echo var="REMOTE_USER" -->'));
});

test('#echo renders a built-in variable even when a template variable shadows it', function (): void {
    $GLOBALS['HTML_SSI_TIMEFMT'] = '%Y';
    $GLOBALS['DATE_LOCAL'] = 'hacked';

    $rendered = processSSI('<!--#echo var="DATE_LOCAL" -->');

    assertNotContains('hacked', $rendered);
    assertSame(date('Y'), $rendered);
});

test('#echo renders LAST_MODIFIED for the current file', function (): void {
    $file = fixture_write('lastmod/page.htm', 'x');
    $GLOBALS['HTML_SSI_TIMEFMT'] = '%Y-%m-%d';

    assertSame(
        date('Y-m-d', (int) filemtime($file)),
        processSSI('<!--#echo var="LAST_MODIFIED" -->', $file)
    );
});

test('#echo renders LAST_MODIFIED as empty without a current file', function (): void {
    assertSame('', processSSI('<!--#echo var="LAST_MODIFIED" -->'));
});

group('SSI: legacy ##echo and <!--@var-->');

test('The legacy ##echo directive renders a template variable', function (): void {
    $GLOBALS['LEGACY'] = 'value';

    assertSame('value', processSSI('<!--##echo var="LEGACY" -->'));
});

test('The legacy ##echo directive accepts both quote styles', function (): void {
    $GLOBALS['LEGACY'] = 'value';

    assertSame('value', processSSI("<!--##echo var='LEGACY' -->"));
});

test('The legacy ##echo directive escapes by default', function (): void {
    $GLOBALS['LEGACY'] = '<i>';

    assertSame('&lt;i&gt;', processSSI('<!--##echo var="LEGACY" -->'));
});

test('The legacy ##echo directive renders nothing for an unknown name', function (): void {
    assertSame('[]', processSSI('[<!--##echo var="DEFINITELY_NOT_SET" -->]'));
});

test('The <!--@var="NAME"value--> directive publishes the trailing payload', function (): void {
    $output = processSSI('<!--@var="MARKER"payload text-->');

    assertSame('payload text', $output, 'the payload belongs to the document flow');
    assertSame('payload text', $GLOBALS['MARKER']);
});

test('The <!--@var="NAME"value--> directive trims the stored value', function (): void {
    processSSI('<!--@var="MARKER"   padded   -->');

    assertSame('padded', $GLOBALS['MARKER']);
});

test('The <!--@var--> directive cannot publish a reserved name', function (): void {
    $before = $_SERVER;

    processSSI('<!--@var="_SERVER"pwned-->');

    assertSame($before, $_SERVER);
    assertFalse(in_array('pwned', array_filter($_SERVER, 'is_string'), true));
});

test('The <!--@var--> directive cannot publish a built-in SSI name', function (): void {
    processSSI('<!--@var="DATE_GMT"pwned-->');

    assertFalse(array_key_exists('DATE_GMT', $GLOBALS));
});

group('SSI: #include');

test('A relative include resolves against the including file directory', function (): void {
    $page = fixture_write('rel/inc/deep/page.htm', 'A<!--#include virtual="piece.htm" -->B');
    fixture_write('rel/inc/deep/piece.htm', 'PIECE');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('rel/inc');

    $output = processSSI((string) file_get_contents($page), $page);

    assertSame('APIECEB', $output);
});

test('A relative include starting with ./ resolves against the including file directory', function (): void {
    $page = fixture_write('rel/dot/page.htm', 'A<!--#include virtual="./piece.htm" -->B');
    fixture_write('rel/dot/piece.htm', 'PIECE');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('rel');

    assertSame('APIECEB', processSSI((string) file_get_contents($page), $page));
});

test('A relative include falls back to the document root', function (): void {
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('rel/root');
    fixture_write('rel/root/ssi/head.htm', 'HEAD');

    assertSame('HEAD', processSSI('<!--#include virtual="ssi/head.htm" -->'));
});

test('An absolute include resolves from the document root', function (): void {
    fixture_write('abs/ssi/head.htm', 'HEAD');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('abs');

    assertSame('[HEAD]', processSSI('[<!--#include virtual="/ssi/head.htm" -->]'));
});

test('Nested includes are processed recursively', function (): void {
    $page = fixture_write('nested/page.htm', 'P[<!--#include virtual="part.htm" -->]');
    fixture_write('nested/part.htm', 'PART[<!--#include virtual="sub.htm" -->]');
    fixture_write('nested/sub.htm', 'SUB');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('nested');

    assertSame('P[PART[SUB]]', processSSI((string) file_get_contents($page), $page));
});

test('An include of a missing file contributes nothing', function (): void {
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('missing');

    assertSame('[]', processSSI('[<!--#include virtual="nope.htm" -->]'));
});

test('An include without a virtual attribute contributes nothing', function (): void {
    assertSame('[]', processSSI('[<!--#include -->]'));
});

test('A URL shaped include target is rejected', function (): void {
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('urlshape');

    assertSame('[]', processSSI('[<!--#include virtual="http://example.com/x.htm" -->]'));
});

test('An included file may publish variables with #set', function (): void {
    $page = fixture_write('setinc/page.htm', '[<!--#include virtual="vars.htm" -->]');
    fixture_write('setinc/vars.htm', '<!--#set var="FROM_INCLUDE" value="1" -->VARS');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('setinc');

    assertSame('[VARS]', processSSI((string) file_get_contents($page), $page));
    assertSame('1', $GLOBALS['FROM_INCLUDE']);
});

group('SSI: evaluation order');

test('Directives run as #config, #set, include, #echo, <!--@var-->', function (): void {
    $template = '<!--#config timefmt="%Y" -->'
        . '<!--#set var="ORD" value="set" -->'
        . '<!--#echo var="ORD" -->|'
        . '<!--#include virtual="inc-ord.htm" -->|'
        . '<!--#echo var="LATE" -->'
        . '<!--@var="LATE"late-->';

    $page = fixture_write('order/page.htm', $template);
    fixture_write('order/inc-ord.htm', '<!--#echo var="ORD" -->-<!--#echo var="DATE_GMT" -->');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('order');

    $output = processSSI($template, $page);

    assertSame('set|set-' . gmdate('Y') . '|late', $output);
    assertSame('late', $GLOBALS['LATE'], '@var runs after #echo');
});

test('#config reaches an included partial', function (): void {
    $template = '<!--#config timefmt="%Y" -->[<!--#include virtual="stamp.htm" -->]';
    $page = fixture_write('cfginc/page.htm', $template);
    fixture_write('cfginc/stamp.htm', '<!--#echo var="DATE_GMT" -->');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('cfginc');

    assertSame('[' . gmdate('Y') . ']', processSSI($template, $page));
});

test('#set reaches an included partial', function (): void {
    $template = '<!--#set var="SHARED" value="yes" -->[<!--#include virtual="use.htm" -->]';
    $page = fixture_write('setinc2/page.htm', $template);
    fixture_write('setinc2/use.htm', '<!--#echo var="SHARED" -->');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('setinc2');

    assertSame('[yes]', processSSI($template, $page));
});

group('SSI: time formats');

test('The SSI time format converts the numeric calendar tokens', function (): void {
    $cases = [
        '%Y' => 'Y',
        '%m' => 'm',
        '%d' => 'd',
        '%H' => 'H',
        '%M' => 'i',
        '%S' => 's',
    ];

    foreach ($cases as $token => $dateToken) {
        $GLOBALS['HTML_SSI_TIMEFMT'] = $token;
        $before = gmdate($dateToken);
        $rendered = processSSI('<!--#echo var="DATE_GMT" -->');
        $after = gmdate($dateToken);

        assertTrue(
            $rendered === $before || $rendered === $after,
            "token {$token}: expected {$before} or {$after}, got {$rendered}"
        );
    }
});

test('The SSI time format converts the textual day and month tokens', function (): void {
    $cases = [
        '%h' => 'M',
        '%b' => 'M',
        '%A' => 'l',
        '%a' => 'D',
    ];

    foreach ($cases as $token => $dateToken) {
        $GLOBALS['HTML_SSI_TIMEFMT'] = $token;
        $before = gmdate($dateToken);
        $rendered = processSSI('<!--#echo var="DATE_GMT" -->');
        $after = gmdate($dateToken);

        assertTrue(
            $rendered === $before || $rendered === $after,
            "token {$token}: expected {$before} or {$after}, got {$rendered}"
        );
    }
});

test('The SSI time format converts the 12 hour clock tokens', function (): void {
    $cases = [
        '%p' => 'A',
        '%I' => 'h',
    ];

    foreach ($cases as $token => $dateToken) {
        $GLOBALS['HTML_SSI_TIMEFMT'] = $token;
        $before = gmdate($dateToken);
        $rendered = processSSI('<!--#echo var="DATE_GMT" -->');
        $after = gmdate($dateToken);

        assertTrue(
            $rendered === $before || $rendered === $after,
            "token {$token}: expected {$before} or {$after}, got {$rendered}"
        );
    }
});

test('The SSI time format converts the timezone tokens', function (): void {
    $cases = [
        '%Z' => 'T',
        '%z' => 'O',
    ];

    foreach ($cases as $token => $dateToken) {
        $GLOBALS['HTML_SSI_TIMEFMT'] = $token;

        assertSame(gmdate($dateToken), processSSI('<!--#echo var="DATE_GMT" -->'), "token {$token}");
    }
});

test('The SSI time format converts the composite tokens', function (): void {
    $cases = [
        '%D' => 'm/d/y',
        '%F' => 'Y-m-d',
        '%T' => 'H:i:s',
        '%R' => 'H:i',
        '%r' => 'h:i:s A',
        '%c' => 'Y-m-d\TH:i:sP',
    ];

    foreach ($cases as $token => $dateToken) {
        $GLOBALS['HTML_SSI_TIMEFMT'] = $token;
        $before = gmdate($dateToken);
        $rendered = processSSI('<!--#echo var="DATE_GMT" -->');
        $after = gmdate($dateToken);

        assertTrue(
            $rendered === $before || $rendered === $after,
            "token {$token}: expected {$before} or {$after}, got {$rendered}"
        );
    }
});

test('%j renders the one based day of the year with three digits', function (): void {
    $GLOBALS['HTML_SSI_TIMEFMT'] = '%j';
    $rendered = processSSI('<!--#echo var="DATE_GMT" -->');

    assertSame(sprintf('%03d', (int) gmdate('z') + 1), $rendered);
    assertMatches('~^\d{3}$~', $rendered);
});

test('%% renders a literal percent sign', function (): void {
    $GLOBALS['HTML_SSI_TIMEFMT'] = '%Y%%';

    assertSame(gmdate('Y') . '%', processSSI('<!--#echo var="DATE_GMT" -->'));
});

test('%n and %t render a newline and a tab', function (): void {
    $GLOBALS['HTML_SSI_TIMEFMT'] = 'a%nb';
    assertSame("a\nb", processSSI('<!--#echo var="DATE_GMT" -->'));

    $GLOBALS['HTML_SSI_TIMEFMT'] = "a%tb";
    assertSame("a\tb", processSSI('<!--#echo var="DATE_GMT" -->'));
});

test('Literal text inside a time format is preserved', function (): void {
    $GLOBALS['HTML_SSI_TIMEFMT'] = 'Updated at %H:%M';

    $rendered = processSSI('<!--#echo var="DATE_GMT" -->');

    assertMatches('~^Updated at \d{2}:\d{2}$~', $rendered);
});

test('A literal percent can escape an arbitrary letter', function (): void {
    $GLOBALS['HTML_SSI_TIMEFMT'] = '%Y o%f';

    assertSame(gmdate('Y') . ' of', processSSI('<!--#echo var="DATE_GMT" -->'));
});
