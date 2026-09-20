<?php
/**
 * 08 - Numeric helpers: Divide(), the rounding helpers, HTML_MAX_PRECISION and
 *      the numeric string parser.
 */

declare(strict_types=1);

group('Numbers: Divide()');

test('Divide() returns a float for a fractional result', function (): void {
    assertSame(2.5, Divide(10, 4));
    assertSame(0.5, Divide(1, 2));
    assertTrue(is_float(Divide(1, 2)));
});

test('Divide() returns an integer zero for a zero divisor', function (): void {
    assertSame(0, Divide(5, 0));
    assertTrue(is_int(Divide(5, 0)), 'the zero divisor result must be an int, not 0.0');
    assertSame(0, Divide(0, 0));
});

test('Divide() keeps the sign of the operands', function (): void {
    assertSame(-2.5, Divide(-10, 4));
    assertSame(-2.5, Divide(10, -4));
    assertSame(2.5, Divide(-10, -4));
});

test('Divide() parses its arguments as numbers', function (): void {
    assertSame(2.5, Divide('10', '4'));
    assertSame(308.64, Divide('1 234,56', '4'));
    assertSame(5.0, Divide(10.0, '2.0'));
});

test('Divide() returns zero when an argument is not numeric', function (): void {
    assertSame(0, Divide(10, 'abc'), 'a zero divisor yields the int zero');
    assertSame(0, Divide(10, []));
    assertSame(0.0, Divide('', 5), 'an empty dividend counts as zero');
    assertSame(0.0, Divide(false, 2));
});

test('Divide() returns zero for a result outside the float range', function (): void {
    assertSame(0, Divide(PHP_FLOAT_MAX, 1e-300));
    assertTrue(is_int(Divide(PHP_FLOAT_MAX, 1e-300)));
});

test('Divide() is exported as a public helper', function (): void {
    assertTrue(is_callable('Divide'));
    assertSame(0.25, Divide(1, 4));
});

group('Numbers: numeric parsing');

test('_htmlNumericValue() parses plain numbers', function (): void {
    assertSame(42.0, _htmlNumericValue(42));
    assertSame(3.5, _htmlNumericValue(3.5));
    assertSame(-12.5, _htmlNumericValue('-12.5'));
    assertSame(0.0, _htmlNumericValue('0'));
});

test('_htmlNumericValue() parses grouped and localised numbers', function (): void {
    assertSame(1234.56, _htmlNumericValue('1 234,56'));
    assertSame(1234.56, _htmlNumericValue('1.234,56'));
    assertSame(1234.56, _htmlNumericValue('1,234.56'));
    assertSame(1234567.0, _htmlNumericValue('1,234,567'));
    assertSame(1.5, _htmlNumericValue('1,5'));
    assertSame(1234.5, _htmlNumericValue("1'234.5"));
    assertSame(1234.5, _htmlNumericValue('1 234.5'));
});

test('_htmlNumericValue() parses scientific notation', function (): void {
    assertSame(1000.0, _htmlNumericValue('1e3'));
    assertSame(0.025, _htmlNumericValue('2.5E-2'));
});

test('_htmlNumericValue() returns zero for non numeric input', function (): void {
    assertSame(0.0, _htmlNumericValue(''));
    assertSame(0.0, _htmlNumericValue('   '));
    assertSame(0.0, _htmlNumericValue('abc'));
    assertSame(0.0, _htmlNumericValue('x12'));
    assertSame(0.0, _htmlNumericValue(null));
    assertSame(0.0, _htmlNumericValue(true));
    assertSame(0.0, _htmlNumericValue([]));
    assertSame(0.0, _htmlNumericValue(new stdClass()));
});

test('_htmlNumericValue() returns zero for a non finite float', function (): void {
    assertSame(0.0, _htmlNumericValue(INF));
    assertSame(0.0, _htmlNumericValue(-INF));
    assertSame(0.0, _htmlNumericValue(NAN));
});

test('_htmlNumericValue() reads the leading number of a mixed string', function (): void {
    assertSame(12.0, _htmlNumericValue('12px'));
    assertSame(0.0, _htmlNumericValue('-'));
});

group('Numbers: rounding helpers');

test('_htmlRound() formats with the requested precision', function (): void {
    assertSame('1.23', _htmlRound(1.2345, 2));
    assertSame('1.00', _htmlRound(1.0, 2));
    assertSame('1', _htmlRound(1.0, 0));
    assertSame('2', _htmlRound(1.5, 0));
});

test('_htmlRound() never renders a negative zero', function (): void {
    assertSame('0.00', _htmlRound(-0.0001, 2));
    assertSame('0.00', _htmlRound(-0.0, 2));
    assertSame('0', _htmlRound(-0.0, 0));
});

test('_htmlCleanRound() drops a zero fraction', function (): void {
    assertSame('10', _htmlCleanRound(10.0, 2));
    assertSame('0', _htmlCleanRound(0.0, 2));
    assertSame('10.25', _htmlCleanRound(10.25, 2));
});

test('_htmlCleanRound() keeps the sign of a real fraction', function (): void {
    assertSame('-0.50', _htmlCleanRound(-0.5, 2));
    assertSame('-4', _htmlCleanRound(-4.0, 0));
});

test('_htmlCleanRound() renders very large integers without rounding artefacts', function (): void {
    assertSame('100000000000000000000', _htmlCleanRound(1.0e20, 0));
    assertSame('1234567890123456', _htmlCleanRound(1234567890123456.0, 0));
});

test('The rounding helpers return zero for a non finite value', function (): void {
    assertSame('0.00', _htmlRound(INF, 2));
    assertSame('0', _htmlCleanRound(NAN, 2));
});

test('_htmlStringify() renders scalars and skips everything else', function (): void {
    assertSame('42', _htmlStringify(42));
    assertSame('1.5', _htmlStringify(1.5));
    assertSame('1', _htmlStringify(true));
    assertSame('', _htmlStringify(false));
    assertSame('', _htmlStringify(null));
    assertSame('', _htmlStringify([]));
    assertSame('', _htmlStringify(INF));
    assertSame('', _htmlStringify(NAN));
});

test('_htmlStringify() hides binary float noise', function (): void {
    assertSame('0.3', _htmlStringify(0.1 + 0.2));
    assertSame('100000000000000000000', _htmlStringify(1.0e20));
});

group('Numbers: HTML_MAX_PRECISION');

test('A precision of zero is allowed', function (): void {
    $GLOBALS['AMT'] = 10;
    $GLOBALS['HTML_MAX_PRECISION'] = 0;

    assertSame('10', processHtml('%{AMT.Round: 3}', 'update_template'));
    assertSame('10', processHtml('%{AMT.Clean Round: 3}', 'update_template'));
});

test('The requested precision is clamped to HTML_MAX_PRECISION', function (): void {
    $GLOBALS['AMT'] = 10;
    $GLOBALS['HTML_MAX_PRECISION'] = 2;

    assertSame('10.00', processHtml('%{AMT.Round: 5}', 'update_template'), 'five decimals are clamped to two');
    assertSame('2.50', processHtml('%{AMT.Round Div 4: 9}', 'update_template'));
});

test('An out of range HTML_MAX_PRECISION is bounded before use', function (): void {
    $GLOBALS['HTML_MAX_PRECISION'] = 'not a number';
    assertSame(100, _htmlPositiveLimit('HTML_MAX_PRECISION', 100, 1000, 0), 'a non numeric value falls back');
    assertSame(7, _htmlPrecision(7));

    $GLOBALS['HTML_MAX_PRECISION'] = 5000;
    assertSame(1000, _htmlPositiveLimit('HTML_MAX_PRECISION', 100, 1000, 0), 'the ceiling applies');
    assertSame(7, _htmlPrecision(7));

    $GLOBALS['HTML_MAX_PRECISION'] = 2;
    assertSame(2, _htmlPrecision(7), 'the requested precision is bounded by the maximum');
});

test('A non numeric precision renders the default precision', function (): void {
    assertSame(0, _htmlPrecision('abc'));
    assertSame(0, _htmlPrecision(null));
    assertSame(3, _htmlPrecision('3'));
});
