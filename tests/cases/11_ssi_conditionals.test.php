<?php
/**
 * 11 - SSI conditionals: #if / #elif / #else / #endif and the expression
 *      evaluator (comparisons, regexes, logic, file tests, nesting).
 */

declare(strict_types=1);

use HtmlPhp\Ssi\Expression;

group('SSI: #if truthiness');

test('A non-empty variable is true', function (): void {
    $GLOBALS['FLAG'] = 'yes';

    assertSame('T', processSSI('<!--#if expr="$FLAG" -->T<!--#else-->F<!--#endif-->'));
});

test('An empty variable is false', function (): void {
    $GLOBALS['FLAG'] = '';

    assertSame('F', processSSI('<!--#if expr="$FLAG" -->T<!--#else-->F<!--#endif-->'));
});

test('The string "0" is false', function (): void {
    $GLOBALS['FLAG'] = '0';

    assertSame('F', processSSI('<!--#if expr="$FLAG" -->T<!--#else-->F<!--#endif-->'));
});

test('An undefined variable is false', function (): void {
    assertSame('F', processSSI('<!--#if expr="$NEVER_DEFINED_XYZ" -->T<!--#else-->F<!--#endif-->'));
});

test('Literal 1 and 0 evaluate as expected', function (): void {
    assertSame('T', processSSI('<!--#if expr="1" -->T<!--#else-->F<!--#endif-->'));
    assertSame('F', processSSI('<!--#if expr="0" -->T<!--#else-->F<!--#endif-->'));
});

group('SSI: #if branches');

test('False branches are removed from the output entirely', function (): void {
    $output = processSSI('a<!--#if expr="0" --><b>HIDDEN</b><!--#endif-->c');

    assertSame('ac', $output);
});

test('#elif selects the first true branch', function (): void {
    assertSame('B', processSSI('<!--#if expr="0" -->A<!--#elif expr="1" -->B<!--#elif expr="1" -->C<!--#else-->D<!--#endif-->'));
});

test('#else runs when every previous branch was false', function (): void {
    assertSame('D', processSSI('<!--#if expr="0" -->A<!--#elif expr="0" -->B<!--#else-->D<!--#endif-->'));
});

test('Directives inside a false branch never execute', function (): void {
    processSSI('<!--#if expr="0" --><!--#set var="LEAKED" value="1" --><!--#endif-->');

    assertFalse(isset($GLOBALS['LEAKED']));
});

test('Includes inside a false branch are not loaded', function (): void {
    fixture_write('ssi/cond-partial.htm', 'PARTIAL');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');

    $output = processSSI('x<!--#if expr="0" --><!--#include virtual="/cond-partial.htm" --><!--#endif-->y');

    assertSame('xy', $output);
});

test('Nested conditionals keep their own branches', function (): void {
    $output = processSSI(
        '<!--#if expr="1" -->1[<!--#if expr="0" -->A<!--#else-->B<!--#endif-->]<!--#endif-->'
        . '<!--#if expr="0" -->2[<!--#if expr="1" -->C<!--#endif-->]<!--#endif-->'
    );

    assertSame('1[B]', $output);
});

test('#endif without #if is ignored', function (): void {
    assertSame('text', processSSI('text<!--#endif-->'));
});

test('#else without #if drops only the directive', function (): void {
    assertSame('textmore', processSSI('text<!--#else-->more'));
    assertTrue(count(html_errors('ssi-expr-error')) > 0);
});

test('A malformed expression is false and recorded', function (): void {
    html_clear_errors();

    assertSame('F', processSSI('<!--#if expr="$A &&& $B" -->T<!--#else-->F<!--#endif-->'));
    assertTrue(count(html_errors('ssi-expr-error')) > 0);
});

group('SSI: expression evaluator');

test('String equality with quoted literals', function (): void {
    $GLOBALS['MODE'] = 'dark';

    assertTrue(Expression::evaluate('$MODE = "dark"'));
    assertTrue(Expression::evaluate('"dark" = $MODE'));
    assertFalse(Expression::evaluate('$MODE = "light"'));
    assertTrue(Expression::evaluate('$MODE != "light"'));
});

test('Unquoted words are string literals', function (): void {
    assertTrue(Expression::evaluate('abc = abc'));
    assertFalse(Expression::evaluate('abc = def'));
});

test('Numeric comparisons', function (): void {
    $GLOBALS['COUNT'] = '42';

    assertTrue(Expression::evaluate('$COUNT > 41'));
    assertTrue(Expression::evaluate('$COUNT >= 42'));
    assertFalse(Expression::evaluate('$COUNT < 42'));
    assertTrue(Expression::evaluate('$COUNT <= 43'));
    assertTrue(Expression::evaluate('10 > 9'));
    assertTrue(Expression::evaluate('"10" > "9"'), 'numeric strings compare numerically, not byte-wise');
});

test('Non-numeric comparisons fall back to strcmp', function (): void {
    assertTrue(Expression::evaluate('"apple" < "banana"'));
    assertFalse(Expression::evaluate('"banana" < "apple"'));
});

test('Regex matching with = /re/ and != /re/', function (): void {
    $GLOBALS['AGENT'] = 'Mozilla/5.0 (compatible)';

    assertTrue(Expression::evaluate('$AGENT = /Mozilla/'));
    assertTrue(Expression::evaluate('$AGENT = /^Mozilla\/5\.0 \(compatible\)$/'));
    assertFalse(Expression::evaluate('$AGENT = /^Chrome$/'));
    assertTrue(Expression::evaluate('$AGENT != /Chrome/'));
});

test('A regex on the left side also matches', function (): void {
    $GLOBALS['V'] = 'hello';

    assertTrue(Expression::evaluate('/ell/ = $V'));
});

test('Logical operators and parentheses', function (): void {
    $GLOBALS['A'] = '1';
    $GLOBALS['B'] = '';

    assertTrue(Expression::evaluate('$A && !$B'));
    assertFalse(Expression::evaluate('$A && $B'));
    assertTrue(Expression::evaluate('$A || $B'));
    assertTrue(Expression::evaluate('!($B)'));
    assertTrue(Expression::evaluate('($A || $B) && ($A = "1")'));
    assertFalse(Expression::evaluate('$A && ($B || !$A)'));
});

test('Negation binds tighter than comparison results', function (): void {
    $GLOBALS['X'] = 'abc';

    assertTrue(Expression::evaluate('!$NOPE_XYZ'));
    assertFalse(Expression::evaluate('!$X'));
});

test('Built-in variables are visible to expressions', function (): void {
    $_SERVER['REQUEST_METHOD'] = 'POST';

    assertTrue(Expression::evaluate('$REQUEST_METHOD = "POST"'));
});

test('An invalid regex inside an expression is false, not fatal', function (): void {
    html_clear_errors();

    assertFalse(Expression::evaluate('"x" = /([unclosed/'));
    assertTrue(count(html_errors('ssi-expr-error')) > 0);
});

test('Expressions are length-bounded', function (): void {
    assertFalse(Expression::evaluate(str_repeat('1 && ', 2000) . '1'));
});

group('SSI: file test operators');

test('-f and -e see files inside the document root', function (): void {
    fixture_write('ssi/ft.txt', 'content');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');

    assertTrue(processSSI('<!--#if expr="-f /ft.txt" -->T<!--#else-->F<!--#endif-->') === 'T');
    assertTrue(processSSI('<!--#if expr="-e /ft.txt" -->T<!--#else-->F<!--#endif-->') === 'T');
    assertTrue(processSSI('<!--#if expr="-s /ft.txt" -->T<!--#else-->F<!--#endif-->') === 'T');
    assertTrue(processSSI('<!--#if expr="-r /ft.txt" -->T<!--#else-->F<!--#endif-->') === 'T');
});

test('-f is false for a missing file', function (): void {
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');

    assertSame('F', processSSI('<!--#if expr="-f /does-not-exist.txt" -->T<!--#else-->F<!--#endif-->'));
});

test('-d detects directories', function (): void {
    fixture_write('ssi/sub/keep.txt', 'x');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');

    assertSame('T', processSSI('<!--#if expr="-d /sub" -->T<!--#else-->F<!--#endif-->'));
});

test('File tests cannot escape the document root', function (): void {
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');
    $GLOBALS['HTML_SSI_ALLOW_PARENT'] = false;

    assertSame('F', processSSI('<!--#if expr="-f /../bootstrap.php" -->T<!--#else-->F<!--#endif-->'));
    assertSame('F', processSSI('<!--#if expr="-f /etc/passwd" -->T<!--#else-->F<!--#endif-->'));
});

group('SSI: document order');

test('Directives evaluate in document order (Apache semantics)', function (): void {
    // #echo before #set sees the old (empty) value; after it, the new one.
    $output = processSSI('[<!--#echo var="ORDER_VAR" -->]<!--#set var="ORDER_VAR" value="late" -->[<!--#echo var="ORDER_VAR" -->]');

    assertSame('[][late]', $output);
});

test('A variable set at the top is visible to includes below', function (): void {
    fixture_write('ssi/order-part.htm', '<!--#echo var="ORDER_TOP" -->');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('ssi');

    $output = processSSI('<!--#set var="ORDER_TOP" value="seen" --><!--#include virtual="/order-part.htm" -->');

    assertSame('seen', $output);
});
