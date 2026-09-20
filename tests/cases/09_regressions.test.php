<?php
/**
 * 09 - Regressions: one test per defect fixed in the two hardening passes
 *      (2026-09-20.1 and 2026-09-20.2). Each test names the bug it locks down.
 */

declare(strict_types=1);

group('Regressions: 2026-09-20.2');

test('normalize() no longer destroys its own <br> marker', function (): void {
    // Bug: the "\x01" sentinel was stripped after the <br> marker had already
    // been inserted, so the marker was never restored and the tags leaked into
    // the text as the literal "HTML_BR" ("aHTML_BRb" instead of "a<br>b").
    assertSame('a<br>b', normalize('<p>a<br>b</p>'));
    assertSame('x<br>y<br>z', normalize('x<br>y<br/>z'));
});

test('A forged marker in the input cannot fabricate a <br>', function (): void {
    // Bug: the sentinel literal survived sanitizing, so caller supplied text
    // containing "\x01HTML_BR\x01" was turned into a real line break.
    $forged = "a\x01HTML_BR\x01b";

    $output = normalize($forged);

    assertSame('aHTML_BRb', $output);
    assertNotContains('<br>', $output, 'the marker must only ever come from normalize() itself');
    assertNotContains("\x01", $output, 'the sentinel byte must be stripped from the input');
});

test('Read_HTML() no longer overwrites a marker block with the residual text', function (): void {
    // Bug: the residual text was written into $GLOBALS['html'][$block] even when
    // a "<!--@block N-->" marker had already published that very block.
    $file = fixture_write('regression/marked.htm', "residual\n<!--@block 2-->TWO<!--@end 2-->\n");

    assertSame('TWO', Read_HTML($file, 2));
    assertSame('TWO', $GLOBALS['html'][2]);
});

test('WriteFile() suppresses the diagnostics of its locked fallback', function (): void {
    // Bug: the in-place fallback of the atomic write used a bare
    // file_put_contents(), so a failed rename surfaced a PHP warning next to the
    // RuntimeException that already reports the failure.
    $directory = fixture_path('regression/writefile');
    if (!is_dir($directory)) {
        mkdir($directory, 0o777, true);
    }

    $target = $directory . DIRECTORY_SEPARATOR . 'a-directory';
    if (!is_dir($target)) {
        mkdir($target);
    }

    $observed = [];
    set_error_handler(static function (int $number, string $message) use (&$observed): bool {
        $observed[] = [$message, (error_reporting() & $number) !== 0];

        return false;
    });

    try {
        assertThrows(RuntimeException::class, static fn(): bool => WriteFile($target, 'x'));
    } finally {
        restore_error_handler();
    }

    assertTrue(count($observed) >= 1, 'a directory destination must fail the rename and run the fallback');
    foreach ($observed as [$message, $escaped]) {
        assertFalse($escaped, "diagnostic must be suppressed: {$message}");
    }
});

test('clean_html() drops script and style bodies', function (): void {
    // Bug: the tags were removed but their contents were kept, so the JavaScript
    // and CSS bodies showed up as visible text on the page.
    assertSame('<p>a</p><p>b</p>', clean_html('<p>a</p><script>alert("x")</script><p>b</p>'));
    assertSame('keep', clean_html('keep<style>p{color:red}</style>'));
    assertSame('<p>a</p>', clean_html('<p>a</p><script>unterminated'));
});

test("Show_HTML() selects block 0 from the string '0'", function (): void {
    // Bug: the selector used an empty() style test, so '0' was treated as "not
    // set" and block 1 was rendered instead of block 0.
    $GLOBALS['html'] = [0 => 'ZERO', 1 => 'ONE'];

    assertSame('ZERO', Show_HTML(['html' => '0', 'output' => 'var']));
    assertSame('ZERO', Show_HTML(['html' => 0, 'output' => 'var']));
});

test('$GLOBALS[TAGS] is not created by clean_html()', function (): void {
    // Bug: clean_html() published its allow-list as a temporary global named
    // TAGS, which collided with the legacy table used by clean().
    unset($GLOBALS['TAGS']);

    clean_html('<p class="x">text</p>');

    assertFalse(array_key_exists('TAGS', $GLOBALS), 'the allow-list must stay private');
});

group('Regressions: 2026-09-20.1');

test('HTML_SSI_ECHO_ESCAPE defaults to true', function (): void {
    // Bug: the default was false, so a template variable echoed through
    // <!--#echo--> injected raw HTML into the response.
    assertSame(true, $GLOBALS['HTML_SSI_ECHO_ESCAPE']);

    $GLOBALS['INJECTION'] = '<script>alert(1)</script>';

    assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', processSSI('<!--#echo var="INJECTION" -->'));
});

test('HTML_AUTO_SANITIZE_INPUT defaults to false and leaves the superglobals alone', function (): void {
    // Bug: requiring the library mutated $_GET/$_POST/$_COOKIE/$_REQUEST by
    // default, which broke callers that expect raw input plus an explicit
    // sanitizing step.
    $code = '$_GET = ["q" => "<b>&"];'
        . '$_POST = ["p" => "<i>"];'
        . '$_REQUEST = $_GET;'
        . isolated_library_include()
        . '$sanitized = HTML_Sanitized_Input();'
        . 'echo ($_GET["q"] === "<b>&" ? "GET_INTACT" : "GET_MUTATED"), "|",'
        . '($_POST["p"] === "<i>" ? "POST_INTACT" : "POST_MUTATED"), "|",'
        . '($GLOBALS["HTML_AUTO_SANITIZE_INPUT"] === false ? "DEFAULT_OFF" : "DEFAULT_ON"), "|",'
        . '$sanitized["get"]["q"];';

    [$exitCode, $output] = run_isolated_php($code);

    assertSame(0, $exitCode, $output);
    assertContains('GET_INTACT', $output);
    assertContains('POST_INTACT', $output);
    assertContains('DEFAULT_OFF', $output);
    assertContains('&lt;b&gt;&amp;', $output);
});

test('The auto sanitizing include mode still scrubs the superglobals on opt-in', function (): void {
    $code = '$_GET = ["q" => "<b>"];'
        . '$GLOBALS["HTML_AUTO_SANITIZE_INPUT"] = true;'
        . isolated_library_include()
        . 'echo $_GET["q"];';

    [$exitCode, $output] = run_isolated_php($code);

    assertSame(0, $exitCode, $output);
    assertContains('&lt;b&gt;', $output);
    assertNotContains('<b>', $output);
});

test('#set parses a reversed attribute order', function (): void {
    // Bug: #set and #config matched "var" and "value" positionally, so
    // value="VAL" var="NAME" was silently ignored.
    processSSI('<!--#set value="reversed" var="REVERSED" -->');

    assertSame('reversed', $GLOBALS['REVERSED']);

    $GLOBALS['BASE'] = 'root';

    processSSI('<!--#set value="pre-$BASE" var="REVERSED_EXPANDED" -->');

    assertSame('pre-root', $GLOBALS['REVERSED_EXPANDED'], 'the reference expansion must work in both orders');
});

test('Literal letters in an SSI time format are preserved', function (): void {
    // Bug: unescaped literal characters were reinterpreted by date(), so
    // "Updated at %H:%M" rendered as "Updated at" plus substituted date tokens.
    $GLOBALS['HTML_SSI_TIMEFMT'] = 'Updated at %H:%M';

    $rendered = processSSI('<!--#echo var="DATE_GMT" -->');

    assertMatches('~^Updated at \d{2}:\d{2}$~', $rendered);
    assertSame(strlen('Updated at ') + 5, strlen($rendered), 'no extra date token may be substituted');
});

test('A literal word made of date tokens survives the format conversion', function (): void {
    $GLOBALS['HTML_SSI_TIMEFMT'] = 'Last update: %Y';

    assertSame('Last update: ' . gmdate('Y'), processSSI('<!--#echo var="DATE_GMT" -->'));
});

test('%h maps to the abbreviated month name', function (): void {
    // Bug: %h was not in the token map and fell through to the literal "h".
    $GLOBALS['HTML_SSI_TIMEFMT'] = '%h';

    assertSame(gmdate('M'), processSSI('<!--#echo var="DATE_GMT" -->'));
});

test('The %j day of year marker is expanded after formatting', function (): void {
    // Bug: the "\x02\x03" placeholder for the one-based day of year leaked into
    // the output when the format also produced control characters.
    $GLOBALS['HTML_SSI_TIMEFMT'] = '%j of %Y';

    $rendered = processSSI('<!--#echo var="DATE_GMT" -->');

    assertSame(sprintf('%03d', (int) gmdate('z') + 1) . ' of ' . gmdate('Y'), $rendered);
    assertNotContains("\x02", $rendered);
    assertNotContains("\x03", $rendered);
});
