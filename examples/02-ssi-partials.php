<?php
/**
 * 02 - SSI partials: a document root with a shared ssi/ partial, #include,
 *      #set, #echo and #config timefmt.
 *
 * Run:  php examples/02-ssi-partials.php
 *
 * Everything is built in a private directory under the system temp directory
 * and removed again before the script returns.
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

/**
 * Writes a file below the document root.
 */
$writeInto = static function (string $root, string $relative, string $body): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    @mkdir(dirname($path), 0o777, true);
    file_put_contents($path, $body);

    return $path;
};

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'html_php_example_02_' . getmypid();

echo "=== 02 / SSI partials ===\n\n";
echo 'document root: ', $root, "\n\n";

try {
    // 1. Two partials in the ssi/ folder. Both read the SITE variable that the
    //    page publishes with #set and the built-in DATE_GMT variable.
    $writeInto($root, 'ssi/head.htm', '<div class="head"><!--#echo var="SITE" -->'
        . ' &middot; updated <!--#echo var="DATE_GMT" --></div>' . "\n");
    $writeInto($root, 'ssi/nav.htm', '<nav><!--#echo var="SITE" -->'
        . ' | <!--#echo var="QUERY_STRING" --></nav>' . "\n");

    // 2. The page. #config and #set are evaluated before the includes, so the
    //    partials already see the time format and the SITE variable.
    $page = "<!--#config timefmt=\"%Y-%m-%d\" -->\n"
        . "<!--#set var=\"SITE\" value=\"Demo site\" -->\n"
        . '<h1><!--#echo var="SITE" --></h1>' . "\n"
        . "<!--#include virtual=\"ssi/nav.htm\" -->"
        . "<!--#include virtual=\"/ssi/head.htm\" -->\n"
        . '<p>Rendered at <!--#echo var="DATE_LOCAL" --> (local), '
        . '<!--#echo var="DATE_GMT" --> (GMT).</p>' . "\n";

    $pagePath = $writeInto($root, 'page.htm', $page);

    echo "--- page.htm source ---\n";
    echo $page, "\n";

    // The document root drives absolute includes; the second argument of
    // processSSI() tells the processor which file the markup came from, so
    // relative includes resolve against that file's directory first.
    $GLOBALS['HTML_WWW_PATH'] = $root;
    $GLOBALS['HTML_SSI_TIMEFMT'] = '%Y-%m-%d';
    $_SERVER['QUERY_STRING'] = 'page=2';

    echo "--- rendered page ---\n";
    echo processSSI($page, $pagePath), "\n";

    echo "--- included partials ---\n";
    echo 'ssi/nav.htm : ', trim((string) file_get_contents($root . '/ssi/nav.htm')), "\n";
    echo 'ssi/head.htm: ', trim((string) file_get_contents($root . '/ssi/head.htm')), "\n\n";

    // 3. Values echoed through <!--#echo--> are escaped by default, which is
    //    what keeps request data out of the markup.
    $GLOBALS['USER_INPUT'] = '<script>alert("x")</script>';

    echo "--- escaping ---\n";
    echo 'raw global : ', $GLOBALS['USER_INPUT'], "\n";
    echo 'echoed     : ', processSSI('<!--#echo var="USER_INPUT" -->'), "\n";
    echo 'HTML_SSI_ECHO_ESCAPE = ', var_export($GLOBALS['HTML_SSI_ECHO_ESCAPE'], true), "\n";

    echo "\ndone.\n";
} finally {
    $removeTree($root);
}
