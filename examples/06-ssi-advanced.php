<?php
/**
 * 06 - Advanced SSI: #if/#elif/#else expressions, #fsize, #flastmod,
 *      #config errmsg/sizefmt, #echo encoding= and the #exec guard rails.
 *
 * Run:  php examples/06-ssi-advanced.php
 */

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

echo "=== 06 / Advanced SSI ===\n\n";

$root = sys_get_temp_dir() . '/htmlphp-ssi-' . getmypid();
@mkdir($root, 0o777, true);

file_put_contents($root . '/report.txt', str_repeat('x', 20480));
file_put_contents($root . '/partial.htm', 'included at <!--#echo var="DATE_LOCAL" -->');
touch($root . '/report.txt', 1700000000);
clearstatcache();

$GLOBALS['HTML_WWW_PATH'] = $root;

// ---------------------------------------------------------------------------
// 1. Conditional expressions (Apache-compatible subset).
// ---------------------------------------------------------------------------
$GLOBALS['ROLE'] = 'editor';
$GLOBALS['ARTICLES'] = '42';

$page = <<<'HTML'
<!--#if expr="$ROLE = /admin|editor/" -->
  <p>Welcome, team member ($ROLE).</p>
<!--#elif expr="$ARTICLES > 100" -->
  <p>Prolific author!</p>
<!--#else-->
  <p>Hello, visitor.</p>
<!--#endif-->
HTML;

echo "--- conditionals ---\n", processSSI($page), "\n\n";

// ---------------------------------------------------------------------------
// 2. File information directives.
// ---------------------------------------------------------------------------
$info = <<<'HTML'
<!--#config sizefmt="abbrev" -->
Size (abbrev): <!--#fsize virtual="/report.txt" -->
<!--#config sizefmt="bytes" -->
Size (bytes) : <!--#fsize virtual="/report.txt" -->
<!--#config timefmt="%Y-%m-%d %H:%M" -->
Last modified: <!--#flastmod virtual="/report.txt" -->
HTML;

echo "--- file info ---\n", processSSI($info), "\n\n";

// ---------------------------------------------------------------------------
// 3. Error message for missing includes.
// ---------------------------------------------------------------------------
echo "--- errmsg ---\n";
echo processSSI('<!--#config errmsg="[content unavailable]" --><!--#include virtual="/missing.htm" -->'), "\n\n";

// ---------------------------------------------------------------------------
// 4. Echo encodings.
// ---------------------------------------------------------------------------
$GLOBALS['USER_INPUT'] = '<script>alert("xss")</script> & more';

echo "--- encodings ---\n";
echo 'entity: ', processSSI('<!--#echo var="USER_INPUT" encoding="entity" -->'), "\n";
echo 'url   : ', processSSI('<!--#echo var="USER_INPUT" encoding="url" -->'), "\n";
echo 'none  : ', processSSI('<!--#echo var="USER_INPUT" encoding="none" -->'), "\n\n";

// ---------------------------------------------------------------------------
// 5. #exec is deny-by-default; enabling it requires an explicit allow-list.
// ---------------------------------------------------------------------------
echo "--- exec guard ---\n";
echo 'disabled: [', processSSI('<!--#exec cmd="/bin/echo pwned" -->'), "]\n";

if (is_executable('/bin/echo')) {
    $GLOBALS['HTML_SSI_EXEC'] = true;
    $GLOBALS['HTML_SSI_EXEC_CMD_ALLOW'] = ['/bin/echo'];
    echo 'allowed : [', trim(processSSI('<!--#exec cmd="/bin/echo hello from exec" -->')), "]\n";
    echo 'shell metacharacters stay literal: [',
        trim(processSSI('<!--#exec cmd="/bin/echo a > b" -->')), "]\n\n";
} else {
    echo "allowed : (skipped — /bin/echo is unavailable on this platform)\n\n";
}

// Diagnostics collected along the way:
echo "--- html_errors() ---\n";
foreach (html_errors() as $error) {
    printf("  [%s] %s\n", $error['code'], $error['message']);
}

array_map('unlink', glob($root . '/*') ?: []);
@rmdir($root);

echo "\nDone.\n";
