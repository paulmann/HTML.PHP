<?php
/**
 * 05 - Internationalisation (i18n): PO/MO catalogues, plural forms,
 *      message contexts, locale negotiation and template integration.
 *
 * Run:  php examples/05-i18n.php
 */

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

echo "=== 05 / Internationalisation ===\n\n";

// ---------------------------------------------------------------------------
// 1. Build a small catalogue directory. In a real project these files come
//    from your translation workflow (Poedit, Weblate, wp i18n make-pot …).
// ---------------------------------------------------------------------------
$dir = sys_get_temp_dir() . '/htmlphp-i18n-' . getmypid();
@mkdir($dir, 0o777, true);

// Standard catalogue naming searched by html_load_translations() for a
// directory: "{domain}-{locale}.mo|po" (or "{locale}/LC_MESSAGES/{domain}").
file_put_contents($dir . '/messages-de_DE.po', <<<'PO'
msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\n"

msgid "Hello, {name}!"
msgstr "Hallo, {name}!"

msgid "Greeting"
msgstr "Willkommen an Bord"

msgctxt "menu"
msgid "File"
msgstr "Datei"

msgid "%d day"
msgid_plural "%d days"
msgstr[0] "%d Tag"
msgstr[1] "%d Tage"
PO);

file_put_contents($dir . '/messages-ru_RU.po', <<<'PO'
msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\n"
"Plural-Forms: nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);\n"

msgid "Hello, {name}!"
msgstr "Привет, {name}!"

msgid "%d day"
msgid_plural "%d days"
msgstr[0] "%d день"
msgstr[1] "%d дня"
msgstr[2] "%d дней"
PO);

// ---------------------------------------------------------------------------
// 2. Load catalogues and translate.
// ---------------------------------------------------------------------------
html_load_translations('de_DE', $dir, 'messages');
html_load_translations('ru_RU', $dir, 'messages');

html_set_locale('de_DE');
echo 'German greeting : ', html_t('Hello, {name}!', ['name' => 'Alice']), "\n";
echo 'German context  : ', html_x('File', 'menu'), "\n";
echo 'German plural 1 : ', html_n('%d day', '%d days', 1, [1]), "\n";
echo 'German plural 5 : ', html_n('%d day', '%d days', 5, [5]), "\n\n";

html_set_locale('ru_RU');
echo 'Russian plural 1 : ', html_n('%d day', '%d days', 1, [1]), "\n";
echo 'Russian plural 3 : ', html_n('%d day', '%d days', 3, [3]), "\n";
echo 'Russian plural 5 : ', html_n('%d day', '%d days', 5, [5]), "\n";
echo 'Russian plural 21: ', html_n('%d day', '%d days', 21, [21]), "\n\n";

// ---------------------------------------------------------------------------
// 3. Accept-Language negotiation.
// ---------------------------------------------------------------------------
$available = ['en', 'de_DE', 'ru_RU'];

echo 'Browser "ru-RU,ru;q=0.9,en;q=0.8" -> ',
    html_detect_locale($available, 'ru-RU,ru;q=0.9,en;q=0.8'), "\n";
echo 'Browser "fr,es;q=0.7"            -> ',
    html_detect_locale($available, 'fr,es;q=0.7', 'en'), " (default)\n\n";

// ---------------------------------------------------------------------------
// 4. Template integration: @{KEY} and Translate.KEY consult the catalogues.
// ---------------------------------------------------------------------------
html_set_locale('de_DE');

// @{KEY} resolves through the active catalogue (keys cannot contain braces;
// use html_t() directly when a message needs interpolation arguments).
echo html_render('<h1>@{Greeting}</h1>'), "\n";

// The legacy engine honours catalogues too:
echo HTML('@{Greeting}'), "\n\n";

// ---------------------------------------------------------------------------
// 5. Diagnostics: what is loaded?
// ---------------------------------------------------------------------------
print_r(HtmlPhp\I18n\Translator::stats());

array_map('unlink', glob($dir . '/*.po') ?: []);
@rmdir($dir);

echo "\nDone.\n";
