<?php
/** Apache SSI: set/echo/if/include/stub/printenv/fsize — standalone + in-render. */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use HtmlPhp\Engine;
use HtmlPhp\RequestContext;
use HtmlPhp\Ssi\DocumentInfo;

$dir = example_dir('ssi');
@mkdir($dir, 0777, true);
file_put_contents($dir . '/nav.html', "<nav>%{SITE|default:Example}</nav>\n");
file_put_contents($dir . '/page.shtml', <<<'HTML'
<!--#config timefmt="%Y-%m-%d" -->
<!--#set var="who" value="$HTTP_USER_AGENT" -->
<!--#if expr="-f /nav.html" --><!--#include virtual="/nav.html" --><!--#else-->no nav<!--#endif-->
User: <!--#echo var="who" -->
Size: <!--#include virtual="/missing.html" stub="fb" -->
<!--#block name="fb"-->[fallback content]<!--#endblock-->
HTML);

$engine = Engine::create(['ssi' => ['document_root' => $dir]]);

$request = RequestContext::fromServer([
    'DOCUMENT_ROOT' => $dir,
    'REQUEST_URI' => '/page.shtml',
    'HTTP_USER_AGENT' => 'ExampleBrowser/1.0',
]);

echo $engine->processSsi(
    (string) file_get_contents($dir . '/page.shtml'),
    new DocumentInfo(file: $dir . '/page.shtml', root: $dir, request: $request)
), "\n";

// Render-scope variables are visible to SSI #if/#echo:
echo $engine->renderString(
    '<!--#if expr=\'$role = "admin"\' -->ADMIN AREA<!--#else-->public<!--#endif-->',
    ['role' => 'admin'],
    new HtmlPhp\Template\RenderOptions(request: $request)
), "\n";

print_r($engine->ssiVariables(new DocumentInfo(root: $dir, request: $request)));
