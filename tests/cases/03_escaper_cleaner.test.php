<?php
/**
 * 03 — Escaper and Cleaner.
 */

declare(strict_types=1);

use HtmlPhp\Html\Cleaner;
use HtmlPhp\Html\Escaper;

group('Escaper');

test('html escaping does not double-encode', function (): void {
    $escaper = new Escaper();

    assertSame('&lt;b&gt;', $escaper->html('<b>'));
    assertSame('&amp;', $escaper->html('&'));
    assertSame('&quot;&apos;', $escaper->html('"\''));
    assertSame('&amp;', $escaper->html('&amp;')); // double_encode=false
});

test('attr escaping is always the built-in encoder', function (): void {
    $escaper = new Escaper(static fn(string $v): string => strtoupper($v));

    assertSame('ABC', $escaper->html('abc')); // custom escaper applies to text…
    assertSame('&lt;b&gt;', $escaper->attr('<b>')); // …but never to attributes
});

test('custom html escaper is honoured for text only', function (): void {
    $escaper = new Escaper(static fn(string $value): string => '[' . $value . ']');

    assertSame('[x]', $escaper->html('x'));
    assertSame('x', $escaper->attr('x'));
});

test('non-callable custom escaper is rejected', function (): void {
    assertThrows(InvalidArgumentException::class, static fn() => new Escaper('not callable at all!!'));
});

test('url escaping keeps structure and encodes values', function (): void {
    $escaper = new Escaper();

    assertSame('a%20b', $escaper->url('a b'));
    assertSame('a/b?c=d#e', $escaper->url('a/b?c=d#e'));
    assertSame('%22', $escaper->url('"'));
    assertSame('%27', $escaper->url("'"));
    assertSame('%3Cscript%3E', $escaper->url('<script>'));
});

test('json/js escaping is script-tag safe', function (): void {
    $escaper = new Escaper();

    assertSame('"\u003Cscript\u003E"', $escaper->json('<script>'));
    assertSame(false, str_contains($escaper->json('<script>'), '<'));
    assertSame(false, str_contains($escaper->json("a'b\"c&d"), "'"));
    assertSame(false, str_contains($escaper->json('a&b'), '&'));
    assertSame('{"a":1}', $escaper->json(['a' => 1]));
    assertSame('null', $escaper->json(NAN));
});

test('css escaping hex-encodes everything non-alphanumeric', function (): void {
    $escaper = new Escaper();

    assertSame('\\00003B ', $escaper->css(';'));
    assertSame('abc', $escaper->css('abc'));
});

test('byStrategy dispatches and defaults to html', function (): void {
    $escaper = new Escaper();

    assertSame('&lt;x&gt;', $escaper->byStrategy('html', '<x>'));
    assertSame('&lt;x&gt;', $escaper->byStrategy('attr', '<x>'));
    assertSame('%3Cx%3E', $escaper->byStrategy('url', '<x>'));
    assertSame('<x>', $escaper->byStrategy('raw', '<x>'));
    assertSame('&lt;x&gt;', $escaper->byStrategy('weird', '<x>'));
});

test('sanitizeArray encodes recursively with double_encode=true', function (): void {
    $escaper = new Escaper();
    $result = $escaper->sanitizeArray(['a' => '<b>', 'n' => ['c' => '&amp;'], 'i' => 5]);

    assertSame('&lt;b&gt;', $result['a']);
    assertSame('&amp;amp;', $result['n']['c']); // double_encode=true re-encodes entities
    assertSame('5', $result['i']);
});

group('Cleaner');

test('cleanHtml keeps allow-listed tags without attributes', function (): void {
    $cleaner = new Cleaner();

    assertSame('<p>text</p><br><strong>s</strong>', $cleaner->cleanHtml('<p class="x">text</p><br /><strong id="y">s</strong>'));
});

test('cleanHtml drops script and style with contents', function (): void {
    $cleaner = new Cleaner();

    assertSame('safe', $cleaner->cleanHtml('<script>alert(1)</script>safe'));
    assertSame('safe', $cleaner->cleanHtml('<style>a{}</style>safe'));
    assertSame('', $cleaner->cleanHtml('<script src="x">')); // unterminated opener eats the rest
});

test('cleanHtml removes unknown tags but keeps their text', function (): void {
    $cleaner = new Cleaner(['b' => true]);

    assertSame('keep<b>bold</b>', $cleaner->cleanHtml('<div>keep</div><b>bold</b>'));
});

test('custom allow-list is injected, never read from globals', function (): void {
    $GLOBALS['TAGS'] = ['marquee' => true];
    $cleaner = new Cleaner();

    assertSame('x', $cleaner->cleanHtml('<marquee>x</marquee>'));
    unset($GLOBALS['TAGS']);
});

test('lcTags lowercases tag names, keeps attributes', function (): void {
    $cleaner = new Cleaner();

    assertSame('<a HREF="X">t</a>', $cleaner->lcTags('<A HREF="X">t</A>'));
    assertSame('<br/>', $cleaner->lcTags('<BR/>'));
});

test('normalize collapses to one line and can preserve <br>', function (): void {
    $cleaner = new Cleaner();

    assertSame('a b', $cleaner->normalize("<p>a</p>\n<p>b</p>"));
    assertSame('a<br> b', $cleaner->normalize("a<br>\nb"));
    assertSame('a b', $cleaner->normalize("a<br>\nb", 1));
    assertSame('x text', $cleaner->normalize('<script>x</script>text')); // normalize strips tags only; cleanHtml removes contents
    assertSame('x', $cleaner->normalize(',x', null, true));
});

test('trimSpaces keeps word characters', function (): void {
    $cleaner = new Cleaner();

    assertSame('ab_12', $cleaner->trimSpaces(' a-b_ 1.2!'));
});
