<?php
/**
 * 03 - Legacy blocks: <!--@Block Name-->, LoadTemplate(), Read_HTML() and
 *      Show_HTML(['output' => 'var']).
 *
 * Run:  php examples/03-legacy-blocks.php
 *
 * The template files are written to a private directory under the system temp
 * directory and removed again before the script returns.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'html.php';

date_default_timezone_set('UTC');

/**
 * Removes a directory tree without emitting diagnostics.
 */
$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_file($path) || is_link($path)) {
        @chmod($path, 0o666);
        @unlink($path);

        return;
    }

    if (!is_dir($path)) {
        return;
    }

    foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
        $removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }

    @rmdir($path);
};

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'html_php_example_03_' . getmypid();

echo "=== 03 / Legacy blocks ===\n\n";
echo 'template root: ', $root, "\n\n";

try {
    @mkdir($root, 0o777, true);

    // 1. A named-block template. Everything outside a block is returned by
    //    LoadTemplate(); every block is published as a global of the same name.
    $named = "<h1>Legacy blocks</h1>\n"
        . "<!--@Block CARD-->\n"
        . "<div class=\"card\"><h2>%{CARD_TITLE}</h2><p>%{CARD_BODY}</p></div>\n"
        . "<!--@End Block CARD-->\n"
        . "<!--@Block MENU-->\n"
        . "<ul><li>%{ITEM_ONE}</li><li>%{ITEM_TWO}</li></ul>\n"
        . "<!--@End Block MENU-->\n"
        . "<p>end of template</p>\n";

    $namedPath = $root . DIRECTORY_SEPARATOR . 'named.htm';
    file_put_contents($namedPath, $named);

    $outside = LoadTemplate($namedPath);

    echo "--- named.htm ---\n";
    echo $named, "\n";
    echo "--- LoadTemplate() returns the text outside the blocks ---\n";
    echo $outside, "\n";

    echo "--- LoadTemplate() published these globals ---\n";
    echo 'CARD present: ', var_export(isset($GLOBALS['CARD']), true), "\n";
    echo 'MENU present: ', var_export(isset($GLOBALS['MENU']), true), "\n\n";

    // 2. Render one of the loaded blocks. HTML() evaluates the SSI pass first
    //    and then resolves the %{...} placeholders from $GLOBALS.
    $GLOBALS['CARD_TITLE'] = 'Published by LoadTemplate()';
    $GLOBALS['CARD_BODY'] = 'The block body is a plain template string.';

    echo "--- HTML(\$GLOBALS['CARD']) ---\n";
    echo HTML($GLOBALS['CARD']), "\n";

    $GLOBALS['ITEM_ONE'] = 'First';
    $GLOBALS['ITEM_TWO'] = 'Second';

    echo "--- HTML(\$GLOBALS['MENU']) ---\n";
    echo HTML($GLOBALS['MENU']), "\n";

    // 3. The html4.pl style numbered blocks. Read_HTML() publishes every block
    //    into $GLOBALS['html'] and returns the requested one.
    $numbered = "<!--@block 1--><p>Block one</p><!--@end 1-->\n"
        . "<!--@block 2--><p>Block two</p><!--@end 2-->\n";

    $numberedPath = $root . DIRECTORY_SEPARATOR . 'numbered.htm';
    file_put_contents($numberedPath, $numbered);

    echo "--- numbered.htm ---\n";
    echo $numbered, "\n";
    echo 'Read_HTML($file, 2) = ', var_export(Read_HTML($numberedPath, 2), true), "\n";

    echo "--- \$GLOBALS['html'] after Read_HTML() ---\n";
    foreach ($GLOBALS['html'] as $number => $body) {
        echo '  [', $number, '] ', $body, "\n";
    }

    // 4. Show_HTML() renders a block. With output => 'var' it returns the
    //    string instead of writing it to the response.
    $GLOBALS['html'][1] = '<p>Hello %{WHO}</p>';

    echo "\n--- Show_HTML() ---\n";
    echo 'output => var : ', Show_HTML(['html' => 1, 'output' => 'var', 'WHO' => 'world']), "\n";

    // Without output => 'var' the block is written to the output stream, and
    // SSI => 'off' leaves the directives untouched.
    $GLOBALS['html'][2] = '<!--#set var="FROM_BLOCK" value="42" --><p>SSI ran</p>';

    echo 'SSI on        : ', Show_HTML(['html' => 2, 'output' => 'var']), "\n";
    echo 'FROM_BLOCK    : ', var_export($GLOBALS['FROM_BLOCK'] ?? null, true), "\n";
    echo 'SSI off       : ', Show_HTML(['html' => 2, 'output' => 'var', 'SSI' => 'off']), "\n";

    echo "\ndone.\n";
} finally {
    $removeTree($root);
}
