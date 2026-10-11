<?php
/**
 * 12 - Extended SSI directives: #exec, #fsize, #flastmod, #config
 *      sizefmt/errmsg, #include file=, #echo encoding=, unquoted attributes.
 */

declare(strict_types=1);

group('SSI: #config sizefmt and errmsg');

test('#config sizefmt switches between abbrev and bytes', function (): void {
    processSSI('<!--#config sizefmt="bytes" -->');

    assertSame('bytes', $GLOBALS['HTML_SSI_SIZEFMT']);

    processSSI('<!--#config sizefmt="abbrev" -->');

    assertSame('abbrev', $GLOBALS['HTML_SSI_SIZEFMT']);
});

test('#config errmsg is emitted when an include fails', function (): void {
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');

    $output = processSSI('<!--#config errmsg="[missing]" --><!--#include virtual="/nope.htm" -->');

    assertSame('[missing]', $output);
});

test('Without errmsg a failed include renders empty', function (): void {
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');
    $GLOBALS['HTML_SSI_ERRMSG'] = null;

    assertSame('ab', processSSI('a<!--#include virtual="/nope.htm" -->b'));
});

test('A failed include is recorded in html_errors', function (): void {
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');
    html_clear_errors();

    processSSI('<!--#include virtual="/nope.htm" -->');

    assertTrue(count(html_errors('ssi-include-failed')) > 0);
});

group('SSI: #include file=');

test('file= resolves relative to the containing document', function (): void {
    fixture_write('ssi/pages/inner/part.htm', 'INNER');
    fixture_write('ssi/pages/page.htm', '<!--#include file="inner/part.htm" -->');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');

    $output = processSSI(
        (string) file_get_contents(fixture_path('ssi/pages/page.htm')),
        fixture_path('ssi/pages/page.htm')
    );

    assertSame('INNER', $output);
});

test('file= cannot reference absolute or parent paths', function (): void {
    fixture_write('ssi/pages/p2.htm', 'x');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');

    assertSame('', processSSI('<!--#include file="/etc/passwd" -->', fixture_path('ssi/pages/p2.htm')));
    assertSame('', processSSI('<!--#include file="../../bootstrap.php" -->', fixture_path('ssi/pages/p2.htm')));
});

test('An include cycle is broken and recorded', function (): void {
    fixture_write('ssi/cycle-a.htm', 'A<!--#include virtual="/cycle-b.htm" -->');
    fixture_write('ssi/cycle-b.htm', 'B<!--#include virtual="/cycle-a.htm" -->');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');
    html_clear_errors();

    $output = processSSI('<!--#include virtual="/cycle-a.htm" -->');

    assertSame('AB', $output);
    assertTrue(count(html_errors('ssi-include-cycle')) > 0);
});

group('SSI: #echo encoding=');

test('encoding="entity" always escapes', function (): void {
    $GLOBALS['RAW'] = '<b>&"x"</b>';
    $GLOBALS['HTML_SSI_ECHO_ESCAPE'] = false;

    assertSame(
        '&lt;b&gt;&amp;&quot;x&quot;&lt;/b&gt;',
        processSSI('<!--#echo var="RAW" encoding="entity" -->')
    );
});

test('encoding="none" never escapes', function (): void {
    $GLOBALS['RAW'] = '<b>x</b>';
    $GLOBALS['HTML_SSI_ECHO_ESCAPE'] = true;

    assertSame('<b>x</b>', processSSI('<!--#echo var="RAW" encoding="none" -->'));
});

test('encoding="url" percent-encodes the value', function (): void {
    $GLOBALS['RAW'] = 'a b&c';

    assertSame('a%20b%26c', processSSI('<!--#echo var="RAW" encoding="url" -->'));
});

test('Unquoted attribute values are accepted', function (): void {
    processSSI('<!--#set var=UNQUOTED_SET value=plain -->');

    assertSame('plain', $GLOBALS['UNQUOTED_SET']);
    assertSame('plain', processSSI('<!--#echo var=UNQUOTED_SET -->'));
});

group('SSI: #fsize and #flastmod');

test('#fsize abbrev prints Apache-style sizes', function (): void {
    fixture_write('ssi/sizes/small.txt', str_repeat('x', 512));
    fixture_write('ssi/sizes/kilo.txt', str_repeat('x', 2048));
    fixture_write('ssi/sizes/mega.txt', str_repeat('x', 3 * 1024 * 1024));
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');
    $GLOBALS['HTML_SSI_SIZEFMT'] = 'abbrev';

    assertSame('512', processSSI('<!--#fsize virtual="/sizes/small.txt" -->'));
    assertSame('2K', processSSI('<!--#fsize virtual="/sizes/kilo.txt" -->'));
    assertSame('3M', processSSI('<!--#fsize virtual="/sizes/mega.txt" -->'));
});

test('#fsize bytes prints the exact size', function (): void {
    fixture_write('ssi/sizes/exact.txt', str_repeat('y', 2048));
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');
    $GLOBALS['HTML_SSI_SIZEFMT'] = 'bytes';

    assertSame('2048', processSSI('<!--#fsize virtual="/sizes/exact.txt" -->'));
});

test('#config sizefmt applies to later #fsize directives', function (): void {
    fixture_write('ssi/sizes/cfg.txt', str_repeat('z', 4096));
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');

    $output = processSSI('<!--#config sizefmt="bytes" --><!--#fsize virtual="/sizes/cfg.txt" -->');

    assertSame('4096', $output);
});

test('#flastmod formats the file mtime with the time format', function (): void {
    $path = fixture_write('ssi/mod.txt', 'data');
    touch($path, 1700000000);
    clearstatcache();
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');
    $GLOBALS['HTML_SSI_TIMEFMT'] = '%Y-%m-%d';

    assertSame('2023-11-14', processSSI('<!--#flastmod virtual="/mod.txt" -->'));
});

test('#fsize on a missing file emits errmsg', function (): void {
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');

    assertSame('[x]', processSSI('<!--#config errmsg="[x]" --><!--#fsize virtual="/missing.txt" -->'));
});

group('SSI: #exec');

test('#exec is denied by default', function (): void {
    html_clear_errors();

    assertSame('', processSSI('<!--#exec cmd="echo hi" -->'));
    assertTrue(count(html_errors('ssi-exec-denied')) > 0);
});

test('#exec cmd requires an allow-list entry even when enabled', function (): void {
    $GLOBALS['HTML_SSI_EXEC'] = true;
    $GLOBALS['HTML_SSI_EXEC_CMD_ALLOW'] = [];
    html_clear_errors();

    assertSame('', processSSI('<!--#exec cmd="echo hi" -->'));
    assertTrue(count(html_errors('ssi-exec-denied')) > 0);
});

test('#exec cmd runs an allow-listed binary without a shell', function (): void {
    skipIf(!is_executable('/bin/echo'), '/bin/echo is unavailable');

    $GLOBALS['HTML_SSI_EXEC'] = true;
    $GLOBALS['HTML_SSI_EXEC_CMD_ALLOW'] = ['/bin/echo'];

    assertSame('hello', trim(processSSI('<!--#exec cmd="/bin/echo hello" -->')));
});

test('#exec cmd never interprets shell metacharacters', function (): void {
    skipIf(!is_executable('/bin/echo'), '/bin/echo is unavailable');

    $GLOBALS['HTML_SSI_EXEC'] = true;
    $GLOBALS['HTML_SSI_EXEC_CMD_ALLOW'] = ['/bin/echo'];

    // Without shell interpretation the redirection is a literal argument.
    $output = trim(processSSI('<!--#exec cmd="/bin/echo a > b" -->'));

    assertSame('a > b', $output);
    assertFalse(is_file(fixture_path('ssi') . '/../b'));
});

test('#exec cmd honours quoted arguments', function (): void {
    skipIf(!is_executable('/bin/echo'), '/bin/echo is unavailable');

    $GLOBALS['HTML_SSI_EXEC'] = true;
    $GLOBALS['HTML_SSI_EXEC_CMD_ALLOW'] = ['/bin/echo'];

    assertSame('two words|tail', trim(processSSI('<!--#exec cmd=\'/bin/echo "two words|tail"\' -->')));
});

test('#exec cgi spawns a PHP script inside the document root', function (): void {
    $GLOBALS['HTML_SSI_EXEC'] = true;
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');
    fixture_write('ssi/cgi/hello.php', "<?php echo 'cgi-ok:', \$_SERVER['REQUEST_METHOD'] ?? 'CLI';");

    $output = processSSI('<!--#exec cgi="/cgi/hello.php" -->');

    assertContains('cgi-ok:', $output);
});

test('#exec cgi include mode runs in-process', function (): void {
    $GLOBALS['HTML_SSI_EXEC'] = true;
    $GLOBALS['HTML_SSI_EXEC_CGI_MODE'] = 'include';
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');
    fixture_write('ssi/cgi/inc.php', "<?php echo 'inc-ok';");

    assertSame('inc-ok', processSSI('<!--#exec cgi="/cgi/inc.php" -->'));
});

test('#exec cgi refuses scripts outside the document root', function (): void {
    $GLOBALS['HTML_SSI_EXEC'] = true;
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');
    html_clear_errors();

    assertSame('', processSSI('<!--#exec cgi="/../bootstrap.php" -->'));
    assertTrue(count(html_errors('ssi-exec-denied')) > 0);
});

test('#exec cgi refuses disallowed extensions', function (): void {
    $GLOBALS['HTML_SSI_EXEC'] = true;
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');
    fixture_write('ssi/cgi/bad.sh', '#!/bin/sh');
    html_clear_errors();

    assertSame('', processSSI('<!--#exec cgi="/bad.sh" -->'));
    assertTrue(count(html_errors('ssi-exec-denied')) > 0);
});

test('#exec output is truncated at HTML_SSI_EXEC_MAX_BYTES', function (): void {
    $GLOBALS['HTML_SSI_EXEC'] = true;
    $GLOBALS['HTML_SSI_EXEC_MAX_BYTES'] = 8;
    $GLOBALS['HTML_SSI_EXEC_CGI_MODE'] = 'include';
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');
    fixture_write('ssi/cgi/big.php', "<?php echo str_repeat('x', 100);");
    html_clear_errors();

    assertSame('xxxxxxxx', processSSI('<!--#exec cgi="/cgi/big.php" -->'));
    assertTrue(count(html_errors('ssi-exec-failed')) > 0);
});

test('#exec timeout terminates a runaway script', function (): void {
    $GLOBALS['HTML_SSI_EXEC'] = true;
    $GLOBALS['HTML_SSI_EXEC_TIMEOUT'] = 1;
    $GLOBALS['HTML_SSI_EXEC_CGI_MODE'] = 'include';
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');
    // include mode cannot be killed mid-run, so use spawn mode instead
    $GLOBALS['HTML_SSI_EXEC_CGI_MODE'] = 'spawn';
    fixture_write('ssi/cgi/slow.php', "<?php echo 'start'; flush(); usleep(3000000); echo 'end';");
    html_clear_errors();

    $started = microtime(true);
    $output = processSSI('<!--#exec cgi="/cgi/slow.php" -->');
    $duration = microtime(true) - $started;

    assertContains('start', $output);
    assertNotContains('end', $output);
    assertTrue($duration < 2.5, 'the timeout must interrupt the script');
    assertTrue(count(html_errors('ssi-exec-failed')) > 0);
});

group('SSI: unknown and malformed directives');

test('An unknown directive stays in the output verbatim', function (): void {
    assertSame('<!--#foo bar=1 -->', processSSI('<!--#foo bar=1 -->'));
});

test('A malformed directive without a closing comment is left alone', function (): void {
    assertSame('a<!--#echo var="X" b', processSSI('a<!--#echo var="X" b'));
});

test('html_ssi is an alias of processSSI', function (): void {
    $GLOBALS['ALIAS_VAR'] = 'v';

    assertSame('v', html_ssi('<!--#echo var="ALIAS_VAR" -->'));
});

test('html_ssi_variables exposes the built-ins', function (): void {
    $vars = html_ssi_variables();

    assertTrue(isset($vars['DATE_LOCAL'], $vars['DOCUMENT_URI'], $vars['REQUEST_METHOD']));
    assertContains(date('Y'), $vars['DATE_LOCAL']);
});
