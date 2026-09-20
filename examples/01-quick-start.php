<?php
/**
 * 01 - Quick start: load a template string, set variables, render with HTML().
 *
 * Run:  php examples/01-quick-start.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'html.php';

date_default_timezone_set('UTC');

echo "=== 01 / Quick start ===\n\n";

// HTML() is the main entry point: it resolves %{NAME} placeholders from
// $GLOBALS through the legacy update_template() callback, runs the SSI pass
// first and evaluates the result twice so escaped references also resolve.
$GLOBALS['TITLE'] = 'Hello, template';
$GLOBALS['BODY'] = 'Rendered by HTML() with nothing but a string.';

$template = "<h1>%{TITLE}</h1>\n<p>%{BODY}</p>\n<p>Missing name: [%{NOPE}]</p>";

echo "--- template ---\n";
echo $template, "\n";
echo "--- rendered ---\n";
echo HTML($template);
echo "\n";

// Numeric directives work on the same placeholders: Round, Clean Round, Div,
// Round Div and Clean Round Div.
$GLOBALS['PRICE'] = '1234.5';

echo "--- numeric directives ---\n";
echo 'raw          : ', HTML('%{PRICE}'), "\n";
echo 'Round: 2     : ', HTML('%{PRICE.Round: 2}'), "\n";
echo 'Div 2        : ', HTML('%{PRICE.Div 2}'), "\n";
echo 'Clean Round  : ', HTML('%{PRICE.Clean Round Div 2: 2}'), "\n\n";

// A second evaluation pass resolves references that were escaped with the
// bracketed form %[...].
$GLOBALS['NAME'] = 'two passes';

echo "--- escaped reference ---\n";
echo HTML('Bracketed %[NAME] resolves on the second pass.'), "\n\n";

echo "done.\n";
