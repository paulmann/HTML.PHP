<?php
/**
 * 06 - Cleaning: clean_html(), clean(), normalize(), lc_Tags(), TrimSpaces()
 *      and the entity normalisation rules.
 */

declare(strict_types=1);

group('Cleaning: clean_html()');

test('clean_html() keeps allow-listed tags and strips their attributes', function (): void {
    assertSame('<p>text</p>', clean_html('<p class="lead" id="x">text</p>'));
    assertSame('<strong>text</strong>', clean_html('<strong style="color:red">text</strong>'));
});

test('clean_html() lowercases allow-listed tag names', function (): void {
    assertSame('<p>text</p>', clean_html('<P>text</P>'));
    assertSame('<br>', clean_html('<BR/>'));
});

test('clean_html() keeps a void tag without its slash', function (): void {
    assertSame('a<br>b', clean_html('a<br/>b'));
    assertSame('a<br>b', clean_html('a<br />b'));
});

test('clean_html() removes other tags but keeps their inner text', function (): void {
    assertSame('keep me', clean_html('<div class="x">keep me</div>'));
    assertSame('<p>abc</p>', clean_html('<p>a<div>b</div>c</p>'));
});

test('clean_html() removes nested unknown wrappers', function (): void {
    assertSame('<p>deep</p>', clean_html('<section><div><p>deep</p></div></section>'));
});

test('clean_html() removes HTML comments', function (): void {
    assertSame('ab', clean_html('a<!-- a comment -->b'));
    assertSame('ab', clean_html("a<!--\nmulti\nline\n-->b"));
});

test('clean_html() drops script elements together with their code', function (): void {
    assertSame('<p>a</p><p>b</p>', clean_html('<p>a</p><script>alert("x")</script><p>b</p>'));
    assertSame("<p>a</p><p>b</p>", clean_html("<p>a</p><script type=\"text/javascript\">\nvar x = 1;\n</script><p>b</p>"));
});

test('clean_html() drops style elements together with their rules', function (): void {
    assertSame('<p>a</p>', clean_html('<style>p { color: red }</style><p>a</p>'));
});

test('clean_html() drops the rest of an unterminated script element', function (): void {
    assertSame('<p>a</p>', clean_html('<p>a</p><script>while (true) {'));
    assertSame('<p>a</p>', clean_html('<p>a</p><style>p {'));
});

test('clean_html() collapses runs of whitespace', function (): void {
    assertSame('a b', clean_html("a\n\n   b"));
    assertSame('a b', clean_html("a\tb"));
});

test('clean_html() normalizes the legacy entity subset', function (): void {
    assertSame('a b', clean_html('a&nbsp;b'));
    assertSame('th', clean_html('&thorn;'));
    assertSame('ss', clean_html('&szlig;'));
    assertSame('&#65;', clean_html('&#x41;'));
    assertSame('&#65;', clean_html('&#65;'));
});

test('clean_html() maps a numeric entity through the legacy table', function (): void {
    $GLOBALS['entities'] = [8364 => 'euro'];

    assertSame('&euro;', clean_html('&#8364;'));
    assertSame('&#8365;', clean_html('&#8365;'), 'an unmapped number is kept as a numeric reference');
});

test('clean_html() keeps the content of a non UTF-8 document', function (): void {
    $output = clean_html("<p>ok \xFF\xFE</p>");

    assertContains('ok', $output);
    assertContains("\xFF\xFE", $output, 'invalid bytes must survive instead of being dropped');
});

test('clean_html() returns an empty string for empty input', function (): void {
    assertSame('', clean_html(''));
    assertSame('', clean_html('   '));
    assertSame('', clean_html('<!-- only a comment -->'));
});

test('clean_html() honours an extended allow-list', function (): void {
    $GLOBALS['HTML_ALLOWED_TAGS']['em'] = true;

    assertSame('<em>x</em>', clean_html('<em>x</em>'));
    assertSame('x', clean_html('<i>x</i>'), 'a tag outside the list is still removed');
});

group('Cleaning: clean()');

test('clean() renders an allow-listed tag from the legacy TAGS table', function (): void {
    $GLOBALS['TAGS'] = ['p' => true, 'br' => true];

    assertSame('<p>', clean('', 'p'));
    assertSame('</p>', clean('/', 'p'));
    assertSame('<br>', clean('', 'BR'), 'the tag name is matched case-insensitively');
});

test('clean() returns nothing for a tag outside the legacy table', function (): void {
    $GLOBALS['TAGS'] = ['p' => true];

    assertSame('', clean('', 'div'));
    assertSame('', clean('/', 'div'));
});

test('clean() returns nothing when the legacy TAGS table is absent', function (): void {
    unset($GLOBALS['TAGS']);

    assertSame('', clean('', 'p'));
});

group('Cleaning: normalize()');

test('normalize() keeps a single br pair as a line break', function (): void {
    assertSame('a<br>b', normalize('<p>a<br>b</p>'));
    assertSame('a<br>b', normalize('a<BR/>b'));
});

test('normalize() drops line breaks when break preservation is off', function (): void {
    assertSame('a b', normalize('a<br>b', 1));
    assertSame('a b', normalize('<p>a</p><p>b</p>', 1));
});

test('normalize() removes comments', function (): void {
    assertSame('ab', normalize('a<!-- gone -->b'));
});

test('normalize() replaces other tags with a single space', function (): void {
    assertSame('a b', normalize('<p>a</p><p>b</p>'));
    assertSame('a b', normalize('a <div class="x">b</div>'));
});

test('normalize() collapses whitespace and trims the result', function (): void {
    assertSame('Hello, world!', normalize("  Hello,   world!  "));
    assertSame('a b', normalize("a\n\n\tb"));
});

test('normalize() removes a trailing comma', function (): void {
    assertSame('value', normalize('value,'));
    assertSame('value, still', normalize('value, still'));
});

test('normalize() normalizes entities', function (): void {
    assertSame('a b', normalize('a&nbsp;b'));
    assertSame('th', normalize('&thorn;'));
    assertSame('&#65;', normalize('&#x41;'));
});

test('normalize() strips control characters', function (): void {
    assertSame('ab', normalize("a\x00\x02\x7Fb"));
    assertSame('ab', normalize("a\x0B\x0Cb"), 'vertical tab and form feed are stripped without leaving a space');
});

test('normalize() keeps bytes that are not control characters', function (): void {
    assertSame("\xFF\xFEok", normalize("\xFF\xFEok"), 'invalid UTF-8 bytes must survive instead of being dropped');
});

test('normalize() strips the leading and trailing punctuation on request', function (): void {
    assertSame('clean', normalize('<p>,clean,</p>', null, true));
    assertSame('', normalize('...', null, true));
    assertSame('...', normalize('...'), 'clean edges is off by default');
});

test('normalize() returns an empty string for empty input', function (): void {
    assertSame('', normalize(''));
    assertSame('', normalize('   '));
});

group('Cleaning: tag and whitespace helpers');

test('lc_Tags() lowercases tag names only', function (): void {
    assertSame('<a HREF="X">T</a>', lc_Tags('<A HREF="X">T</A>'));
    assertSame('<br/>', lc_Tags('<BR/>'));
});

test('lc_Tags() leaves text and attribute values alone', function (): void {
    assertSame('UPPER text <b CLASS="Mixed">lower</b>', lc_Tags('UPPER text <B CLASS="Mixed">lower</b>'));
});

test('TrimSpaces() removes every non word character', function (): void {
    assertSame('HelloWorld42', TrimSpaces(' Hello, World! 42 '));
    assertSame('a_b', TrimSpaces('a _ b'));
});

test('TrimSpaces() preserves Unicode letters', function (): void {
    assertSame('Приветмир', TrimSpaces('Привет, мир!'));
});

test('TrimSpaces() accepts non string input', function (): void {
    assertSame('42', TrimSpaces(42));
    assertSame('', TrimSpaces(null));
    assertSame('', TrimSpaces([1, 2]));
});
