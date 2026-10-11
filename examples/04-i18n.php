<?php
/** Catalogues, plurals, @Trans blocks, extraction and audit. */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use HtmlPhp\Engine;
use HtmlPhp\I18n\Auditor;
use HtmlPhp\I18n\Extractor;

$lang = example_dir('i18n/lang');
$langJson = example_dir('i18n/lang-json');
@mkdir($lang, 0777, true);
@mkdir($langJson, 0777, true);
file_put_contents($lang . '/messages-ru.po', <<<'PO'
msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\n"
"Plural-Forms: nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);\n"

msgid "cart.title"
msgstr "Корзина"

msgid "{0} file"
msgid_plural "{0} files"
msgstr[0] "{0} файл"
msgstr[1] "{0} файла"
msgstr[2] "{0} файлов"
PO);
// A second path contributes more messages for the same locale/domain —
// catalogues from multiple paths MERGE (first path wins on conflicts).
file_put_contents($langJson . '/messages-ru.json', json_encode([
    'greeting' => 'Привет, {name}!',
], JSON_UNESCAPED_UNICODE));

$tpl = example_dir('i18n/tpl');
@mkdir($tpl, 0777, true);
file_put_contents($tpl . '/cart.html', <<<'HTML'
<h1>@{cart.title}</h1>
<p>@{greeting}</p>
<!--@Trans count="%{n}"-->%{n} file<!--@Plural-->%{n} files<!--@EndTrans-->
HTML);

$engine = Engine::create([
    'i18n' => ['locale' => 'ru', 'paths' => [$lang, $langJson], 'fallback' => ['en']],
    'templates' => ['paths' => [$tpl]],
]);

echo $engine->render('cart.html', ['n' => 2, 'name' => 'Ада']), "\n";
foreach ([1, 2, 5, 11, 21] as $n) {
    printf("%2d → %s\n", $n, $engine->n('{0} file', '{0} files', $n, [$n]));
}

// Maintenance tooling: extract → audit.
$extractor = (new Extractor())->scan((string) file_get_contents($tpl . '/cart.html'), 'cart.html');
$report = (new Auditor($engine->translator()))->audit($extractor->entries(), 'ru');
echo implode("\n", Auditor::format($report)), "\n";
