<?php
/**
 * 05 - Legacy blocks: <!--@Block-->, Read_HTML(), Show_HTML() and the
 *      html4.pl style numeric block files.
 */

declare(strict_types=1);

group('Blocks: legacy <!--@Block--> templates');

test('LoadTemplate() publishes named blocks and returns the rest of the file', function (): void {
    $file = fixture_write('legacy/blocks.htm', "Header line\n"
        . "<!--@Block MENU-->\n"
        . "item 1\n"
        . "item 2\n"
        . "<!--@End Block MENU-->\n"
        . "Footer line\n");

    $output = LoadTemplate($file);

    assertSame("Header line\nFooter line\n", $output);
    assertSame("item 1\nitem 2\n", $GLOBALS['MENU']);
});

test('LoadTemplate() handles nested blocks', function (): void {
    $file = fixture_write('legacy/nested.htm', "<!--@Block OUTER-->\n"
        . "outer-a\n"
        . "<!--@Block INNER-->\n"
        . "inner\n"
        . "<!--@End Block INNER-->\n"
        . "outer-b\n"
        . "<!--@End Block OUTER-->\n");

    assertSame('', LoadTemplate($file));
    assertSame("outer-a\nouter-b\n", $GLOBALS['OUTER']);
    assertSame("inner\n", $GLOBALS['INNER']);
});

test('LoadTemplate() reports a missing template', function (): void {
    assertSame('Template Not Found', LoadTemplate(fixture_path('legacy/does-not-exist.htm')));
    assertSame('Template Not Found', LoadTemplate(''));
});

test('LoadTemplate() refuses to publish a reserved block name', function (): void {
    $file = fixture_write('legacy/reserved.htm', "<!--@Block _SERVER-->\npwned\n<!--@End Block _SERVER-->\ntail\n");

    assertSame("tail\n", LoadTemplate($file));
    assertTrue(is_array($GLOBALS['_SERVER']), '_SERVER must survive as an array');
});

test('LoadVar() publishes blocks and collects the surrounding text', function (): void {
    $file = fixture_write('legacy/loadvar.htm', "text\n"
        . "<!--@Block ALPHA-->\n"
        . "A\n"
        . "<!--@End Block ALPHA-->\n"
        . "text2\n");
    $handle = fopen($file, 'rb');
    assertTrue(is_resource($handle));

    $collected = LoadVar($handle, '~<!--@Block\s*(.*?)-->~i');
    fclose($handle);

    assertSame("text\ntext2\n", $collected);
    assertSame("A\n", $GLOBALS['ALPHA']);
});

test('LoadVar() returns an empty string for a non resource handle', function (): void {
    assertSame('', LoadVar('not a handle', '~<!--@Block\s*(.*?)-->~i'));
});

test('A published block can be rendered by HTML()', function (): void {
    $file = fixture_write('legacy/render.htm', "<!--@Block CARD-->\n<p>%{TITLE}</p>\n<!--@End Block CARD-->\n");
    LoadTemplate($file);
    $GLOBALS['TITLE'] = 'Card title';

    assertSame("<p>Card title</p>\n", HTML($GLOBALS['CARD']));
});

group('Blocks: Read_HTML()');

test('Read_HTML() publishes every numbered block and returns the requested one', function (): void {
    $file = fixture_write('blocks/page.htm', "preface\n"
        . "<!--@block 1-->ONE<!--@end 1-->\n"
        . "<!--@block 2-->TWO<!--@end 2-->\n"
        . "tail\n");

    assertSame('ONE', Read_HTML($file));
    assertSame('ONE', $GLOBALS['html'][1]);
    assertSame('TWO', $GLOBALS['html'][2]);
});

test('Read_HTML() returns a later block without losing the earlier ones', function (): void {
    $file = fixture_write('blocks/two.htm', "<!--@block 1-->ONE<!--@end 1-->\n<!--@block 2-->TWO<!--@end 2-->\n");

    assertSame('ONE', Read_HTML($file));
    assertSame('TWO', Read_HTML($file, 2));
    assertSame('ONE', $GLOBALS['html'][1]);
    assertSame('TWO', $GLOBALS['html'][2]);
});

test('Read_HTML() returns block 0 when it is published by a marker', function (): void {
    $file = fixture_write('blocks/zero.htm', "<!--@block 0-->ZERO<!--@end 0-->\n<!--@block 1-->ONE<!--@end 1-->\n");

    assertSame('ZERO', Read_HTML($file, 0));
    assertSame('ONE', Read_HTML($file, 1));
});

test('Read_HTML() clamps a negative block number to block 1', function (): void {
    $file = fixture_write('blocks/negative.htm', "<!--@block 1-->ONE<!--@end 1-->\n<!--@block 2-->TWO<!--@end 2-->\n");

    assertSame('ONE', Read_HTML($file, -7));
});

test('Read_HTML() returns the whole file when no markers are present', function (): void {
    $file = fixture_write('blocks/plain.htm', "  just text\n  more text  \n");

    assertSame("just text\n  more text", Read_HTML($file));
});

test('Read_HTML() uses the residual text only for an undefined block', function (): void {
    $file = fixture_write('blocks/residual.htm', "residual text\n<!--@block 1-->ONE<!--@end 1-->\n");

    assertSame('ONE', Read_HTML($file, 1));
    assertSame('residual text', Read_HTML($file, 5));
    assertSame('residual text', $GLOBALS['html'][5]);
});

test('Read_HTML() keeps a marker block when an undefined block is requested', function (): void {
    $file = fixture_write('blocks/keep.htm', "residual\n<!--@block 2-->TWO<!--@end 2-->\n");

    assertSame('TWO', Read_HTML($file, 2));
    assertSame('TWO', $GLOBALS['html'][2], 'a marker block must not be replaced by the residual text');
});

test('Read_HTML() throws for a missing file', function (): void {
    $error = assertThrows(
        RuntimeException::class,
        static fn(): string => Read_HTML(fixture_path('blocks/missing.htm'))
    );

    assertContains('is not readable', $error->getMessage());
});

test('Read_HTML() throws for an empty path with a RuntimeException', function (): void {
    assertThrows(RuntimeException::class, static fn(): string => Read_HTML(''));
});

group('Blocks: Show_HTML()');

test('Show_HTML() renders the block selected by the html key', function (): void {
    $GLOBALS['html'] = [1 => 'ONE', 2 => 'TWO'];

    assertSame('ONE', Show_HTML(['html' => 1, 'output' => 'var']));
    assertSame('TWO', Show_HTML(['html' => 2, 'output' => 'var']));
});

test('Show_HTML() defaults to block 1', function (): void {
    $GLOBALS['html'] = [1 => 'ONE', 2 => 'TWO'];

    assertSame('ONE', Show_HTML(['output' => 'var']));
});

test('Show_HTML() accepts legacy key/value argument pairs', function (): void {
    $GLOBALS['html'] = [1 => 'ONE', 2 => 'TWO'];

    assertSame('TWO', Show_HTML('html', 2, 'output', 'var'));
});

test("Show_HTML() selects block 0 from the string '0'", function (): void {
    $GLOBALS['html'] = [0 => 'ZERO', 1 => 'ONE'];

    assertSame('ZERO', Show_HTML(['html' => '0', 'output' => 'var']));
    assertSame('ONE', Show_HTML(['html' => '', 'output' => 'var']), 'an empty selector falls back to block 1');
});

test('Show_HTML() returns the rendering for an output mode starting with var', function (): void {
    $GLOBALS['html'] = [1 => 'ONE'];

    assertSame('ONE', Show_HTML(['html' => 1, 'output' => 'variable']));
    assertNull(Show_HTML(['html' => 1, 'output' => 'plain']));
});

test('Show_HTML() renders an empty string for an undefined block', function (): void {
    unset($GLOBALS['html']);

    assertSame('', Show_HTML(['html' => 9, 'output' => 'var']));
});

test('Show_HTML() writes to a stream when output is file', function (): void {
    $GLOBALS['html'] = [1 => 'FILE BODY'];
    $file = fixture_path('blocks/show-out.txt');
    $handle = fopen($file, 'wb');
    assertTrue(is_resource($handle));

    ob_start();
    $return = Show_HTML(['html' => 1, 'output' => 'file', 'handle' => $handle]);
    $echoed = (string) ob_get_clean();
    fclose($handle);

    assertNull($return);
    assertSame('', $echoed, 'a successful file write must not echo');
    assertSame('FILE BODY', (string) file_get_contents($file));
});

test('Show_HTML() writes to a stream when output is comb', function (): void {
    $GLOBALS['html'] = [1 => 'COMB BODY'];
    $file = fixture_path('blocks/show-comb.txt');
    $handle = fopen($file, 'wb');
    assertTrue(is_resource($handle));

    ob_start();
    Show_HTML(['html' => 1, 'output' => 'comb', 'handle' => $handle]);
    $echoed = (string) ob_get_clean();
    fclose($handle);

    assertSame('COMB BODY', (string) file_get_contents($file));
    assertSame('COMB BODY', $echoed, 'comb writes and echoes');
});

test('Show_HTML() falls back to direct output for an unusable handle', function (): void {
    $GLOBALS['html'] = [1 => 'FALLBACK'];

    ob_start();
    Show_HTML(['html' => 1, 'output' => 'file', 'handle' => 'not a handle']);
    $echoed = (string) ob_get_clean();

    assertSame('FALLBACK', $echoed);
});

test('Show_HTML() stays silent when SILENT is set', function (): void {
    $GLOBALS['html'] = [1 => 'QUIET'];
    $GLOBALS['SILENT'] = 1;

    ob_start();
    Show_HTML(['html' => 1]);
    $echoed = (string) ob_get_clean();

    assertSame('', $echoed);
});

test('Show_HTML() echoes the rendered block by default', function (): void {
    $GLOBALS['html'] = [1 => 'SPOKEN'];

    ob_start();
    $return = Show_HTML(['html' => 1]);
    $echoed = (string) ob_get_clean();

    assertNull($return);
    assertSame('SPOKEN', $echoed);
});

test('Show_HTML() processes SSI directives by default', function (): void {
    $GLOBALS['html'] = [1 => '<!--#set var="FROM_BLOCK" value="1" -->X'];

    assertSame('X', Show_HTML(['html' => 1, 'output' => 'var']));
    assertSame('1', $GLOBALS['FROM_BLOCK']);
});

test("Show_HTML() leaves directives alone when SSI is off", function (): void {
    $template = '<!--#set var="FROM_BLOCK" value="1" -->X';
    $GLOBALS['html'] = [1 => $template];

    assertSame($template, Show_HTML(['html' => 1, 'output' => 'var', 'SSI' => 'off']));
    assertFalse(array_key_exists('FROM_BLOCK', $GLOBALS));
});

test('Show_HTML() resolves variables from the argument array', function (): void {
    $GLOBALS['html'] = [1 => '<p>%{TITLE}</p>'];

    assertSame('<p>Hello</p>', Show_HTML(['html' => 1, 'output' => 'var', 'TITLE' => 'Hello']));
    assertSame('<p></p>', Show_HTML(['html' => 1, 'output' => 'var']), 'an absent name renders empty');
});

test('Show_HTML() runs the numeric directives against the argument array', function (): void {
    $GLOBALS['html'] = [1 => '%{AMT.Div 4}'];

    assertSame('2.5', Show_HTML(['html' => 1, 'output' => 'var', 'AMT' => 10]));
});

test('Show_HTML() honours the exec_symbol argument', function (): void {
    $GLOBALS['html'] = [1 => 'x !{TITLE} y'];

    assertSame('x Hello y', Show_HTML(['html' => 1, 'output' => 'var', 'exec_symbol' => '!', 'TITLE' => 'Hello']));
    assertSame('x !{TITLE} y', Show_HTML(['html' => 1, 'output' => 'var', 'TITLE' => 'Hello']));
});

test('Show_HTML() alternates the split halves through the parity state', function (): void {
    $GLOBALS['html'] = [1 => 'A<!--@Split-->B'];
    unset($GLOBALS['parity']);

    assertSame('B', Show_HTML(['html' => 1, 'output' => 'var']));
    assertSame('A', Show_HTML(['html' => 1, 'output' => 'var']));
    assertSame('B', Show_HTML(['html' => 1, 'output' => 'var']));
});

test('Show_HTML() keeps the caller parity when PARITY is supplied', function (): void {
    $GLOBALS['html'] = [1 => 'A<!--@Split-->B'];
    $GLOBALS['parity'] = 0;

    assertSame('A', Show_HTML(['html' => 1, 'output' => 'var', 'PARITY' => 1]));
    assertSame(0, $GLOBALS['parity'], 'the parity state must not be toggled');

    $GLOBALS['parity'] = 1;

    assertSame('B', Show_HTML(['html' => 1, 'output' => 'var', 'PARITY' => 1]));
});

test('Show_HTML() keeps a block without a split marker unchanged', function (): void {
    $GLOBALS['html'] = [1 => 'NO SPLIT HERE'];
    unset($GLOBALS['parity']);

    assertSame('NO SPLIT HERE', Show_HTML(['html' => 1, 'output' => 'var']));
    assertSame('NO SPLIT HERE', Show_HTML(['html' => 1, 'output' => 'var']));
});

test('Show_HTML() decodes the escaped [!--@...--] marker', function (): void {
    $GLOBALS['html'] = [1 => '[!--@Block X--]'];

    assertSame('<!--@Block X-->', Show_HTML(['html' => 1, 'output' => 'var']));
});

test('Show_HTML() strips the legacy <?html if (...)?> wrapper', function (): void {
    $GLOBALS['html'] = [1 => 'A<?html if ($x == 1) ?>B'];

    assertSame('AB', Show_HTML(['html' => 1, 'output' => 'var']));
});
