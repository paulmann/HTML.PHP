<?php
/**
 * 04 - Helpers tour: normalize(), clean_html(), lc_Tags(), TrimSpaces(),
 *      Divide(), sanitizeXSS() and HTML_Sanitized_Input().
 *
 * Run:  php examples/04-helpers-tour.php
 *
 * No temporary files are needed: the helpers are pure string functions.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'html.php';

date_default_timezone_set('UTC');

/**
 * Prints one labelled example.
 */
$show = static function (string $label, string $input, string $output): void {
    echo $label, "\n";
    echo '  in : ', str_replace("\n", '\n', $input), "\n";
    echo '  out: ', str_replace("\n", '\n', $output), "\n\n";
};

echo "=== 04 / Helpers tour ===\n\n";

group_begin('normalize()');
echo "Flattens markup to plain text. Line breaks survive unless the second\n"
    . "argument is non-zero; clean edges strips surrounding punctuation.\n\n";
$show('markup with a line break', '<p>Hello <b>world</b></p><br><p>Second line</p>',
    normalize('<p>Hello <b>world</b></p><br><p>Second line</p>'));
$show('breaks dropped (br = 1)', '<p>Hello <b>world</b></p><br><p>Second line</p>',
    normalize('<p>Hello <b>world</b></p><br><p>Second line</p>', 1));
$show('clean edges', '<p>,quoted,</p>', normalize('<p>,quoted,</p>', null, true));

group_begin('clean_html()');
echo "Keeps the allow-listed tags, drops their attributes, drops <script> and\n"
    . "<style> together with their contents.\n\n";
$show('attributes stripped', '<p class="lead" id="intro">Text</p>', clean_html('<p class="lead" id="intro">Text</p>'));
$show('unknown tag removed, text kept', '<div class="wrap">Text</div>', clean_html('<div class="wrap">Text</div>'));
$show('script dropped with its body', '<p>a</p><script>alert("x")</script><p>b</p>',
    clean_html('<p>a</p><script>alert("x")</script><p>b</p>'));

group_begin('lc_Tags()');
echo "Lowercases tag names only, leaving attributes and text untouched.\n\n";
$show('mixed case markup', '<A HREF="/x">Link</A><BR/>', lc_Tags('<A HREF="/x">Link</A><BR/>'));

group_begin('TrimSpaces()');
echo "Removes whitespace and punctuation, keeping Unicode letters and digits.\n\n";
$show('punctuation removed', ' Hello, World! 42 ', TrimSpaces(' Hello, World! 42 '));
$show('cyrillic kept', 'Привет, мир!', TrimSpaces('Привет, мир!'));

group_begin('Divide()');
echo "Safe division: a zero divisor returns the integer 0 instead of raising.\n\n";
echo '  Divide(10, 4)                  = ', var_export(Divide(10, 4), true), "\n";
echo '  Divide(10, 0)                  = ', var_export(Divide(10, 0), true), "\n";
echo '  Divide("1 234,56", "4")        = ', var_export(Divide('1 234,56', '4'), true), "\n\n";

group_begin('sanitizeXSS() and HTML_Sanitized_Input()');
echo "sanitizeXSS() walks a nested array and HTML-escapes every scalar;\n"
    . "HTML_Sanitized_Input() returns escaped copies of the request arrays\n"
    . "without mutating the superglobals.\n\n";

$input = [
    'title' => '<b>Bold</b>',
    'nested' => [
        'note' => "O'Reilly & <friends>",
        'count' => 3,
    ],
];

echo '  input      : ', var_export($input, true), "\n";
echo '  sanitized  : ', var_export(sanitizeXSS($input), true), "\n\n";

$_GET = ['q' => '<script>steal()</script>'];
$_POST = ['name' => 'a & b'];

$sanitizedInput = HTML_Sanitized_Input();

echo '  $_GET still raw           : ', var_export($_GET['q'], true), "\n";
echo '  HTML_Sanitized_Input()[get] : ', var_export($sanitizedInput['get']['q'], true), "\n";
echo '  HTML_Sanitized_Input()[post]: ', var_export($sanitizedInput['post']['name'], true), "\n";
echo '  HTML_AUTO_SANITIZE_INPUT   : ', var_export($GLOBALS['HTML_AUTO_SANITIZE_INPUT'], true), "\n\n";

echo "done.\n";

/**
 * Prints a small section header.
 */
function group_begin(string $title): void
{
    echo '--- ', $title, " ---\n";
}
