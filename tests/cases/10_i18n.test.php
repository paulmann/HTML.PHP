<?php
/**
 * 10 - i18n: catalogues, plural forms, contexts, fallback chains,
 *      Accept-Language negotiation, interpolation and legacy integration.
 */

declare(strict_types=1);

use HtmlPhp\I18n\Catalog;
use HtmlPhp\I18n\Locale;
use HtmlPhp\I18n\PluralForms;
use HtmlPhp\I18n\Translator;

/**
 * Builds a minimal gettext MO binary in memory (little endian).
 *
 * @param array<string, string> $entries msgid => msgstr (header under "")
 */
function build_mo(array $entries, string $pluralHeader = ''): string
{
    $header = "Content-Type: text/plain; charset=UTF-8\n";

    if ($pluralHeader !== '') {
        $header .= 'Plural-Forms: ' . $pluralHeader . "\n";
    }

    $originals = [''];
    $translations = [$header];

    foreach ($entries as $msgid => $msgstr) {
        $originals[] = $msgid;
        $translations[] = $msgstr;
    }

    $count = count($originals);
    $origTable = 28;
    $transTable = $origTable + $count * 8;
    $stringsStart = $transTable + $count * 8;

    $origData = '';
    $transData = '';
    $origEntries = '';
    $transEntries = '';
    $origOffset = $stringsStart;
    $transOffset = $stringsStart;

    foreach ($originals as $index => $string) {
        $origEntries .= pack('VV', strlen($string), $origOffset + strlen($origData));
        $origData .= $string . "\0";
    }

    foreach ($translations as $string) {
        $transEntries .= pack('VV', strlen($string), $transOffset + strlen($transData));
        $transData .= $string . "\0";
    }

    // Fix the tables: originals block, then translations block.
    $transOffset = $origOffset + strlen($origData);
    $transEntries = '';
    $transData = '';

    foreach ($translations as $string) {
        $transEntries .= pack('VV', strlen($string), $transOffset + strlen($transData));
        $transData .= $string . "\0";
    }

    return pack('VVVVVVV', 0x950412de, 0, $count, $origTable, $transTable, 0, 0)
        . $origEntries . $transEntries . $origData . $transData;
}

group('i18n: locale handling');

test('Locale::normalize maps common spellings', function (): void {
    assertSame('en', Locale::normalize('en'));
    assertSame('en_US', Locale::normalize('en-us'));
    assertSame('ru_RU', Locale::normalize('RU_ru.UTF-8'));
    assertSame('de_DE', Locale::normalize('de_DE@euro'));
    assertSame('en', Locale::normalize('not a locale!!'));
    assertSame('en', Locale::normalize(''));
});

test('Locale::language extracts the language subtag', function (): void {
    assertSame('ru', Locale::language('ru_RU'));
    assertSame('pt', Locale::language('pt_BR'));
});

test('Accept-Language parsing sorts by quality', function (): void {
    $parsed = Locale::parseAcceptLanguage('en-US,en;q=0.9,ru;q=0.8,fr;q=0.95');

    assertSame('en_US', $parsed[0]['locale'], 'no q means q=1.0');
    assertSame('fr', $parsed[1]['locale']);
    assertSame('en', $parsed[2]['locale']);
});

test('html_detect_locale negotiates the best available locale', function (): void {
    $available = ['en', 'ru_RU', 'de_DE'];

    assertSame('ru_RU', html_detect_locale($available, 'ru-RU,ru;q=0.9,en;q=0.8'));
    assertSame('de_DE', html_detect_locale($available, 'fr,de;q=0.7,en;q=0.5'));
    assertSame('en', html_detect_locale($available, 'en-GB'));
    assertSame('en', html_detect_locale($available, 'ja', 'en'));
    assertSame('en', html_detect_locale($available, null, 'en'));
});

group('i18n: plural forms');

test('English rule selects two forms', function (): void {
    $forms = PluralForms::parse('nplurals=2; plural=(n != 1);');

    assertSame(2, $forms->count());
    assertSame(0, $forms->select(1));
    assertSame(1, $forms->select(0));
    assertSame(1, $forms->select(5));
});

test('Russian rule selects three forms', function (): void {
    $forms = PluralForms::parse(
        'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);'
    );

    assertSame(3, $forms->count());
    assertSame(0, $forms->select(1));
    assertSame(1, $forms->select(2));
    assertSame(2, $forms->select(5));
    assertSame(2, $forms->select(11));
    assertSame(2, $forms->select(14));
    assertSame(0, $forms->select(21));
    assertSame(1, $forms->select(22));
    assertSame(2, $forms->select(111));
    assertSame(0, $forms->select(121));
});

test('Arabic rule selects six forms', function (): void {
    $forms = PluralForms::parse(
        'nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5);'
    );

    assertSame(6, $forms->count());
    assertSame(0, $forms->select(0));
    assertSame(1, $forms->select(1));
    assertSame(2, $forms->select(2));
    assertSame(3, $forms->select(6));
    assertSame(4, $forms->select(18));
    assertSame(5, $forms->select(100));
});

test('A single-form language always selects form 0', function (): void {
    $forms = PluralForms::parse(null, 'ja');

    assertSame(1, $forms->count());
    assertSame(0, $forms->select(0));
    assertSame(0, $forms->select(42));
});

test('Language built-ins apply without a header', function (): void {
    assertSame(3, PluralForms::parse(null, 'pl')->count());
    assertSame(4, PluralForms::parse(null, 'sl')->count());
    assertSame(2, PluralForms::parse(null, 'en')->count());
    assertSame(1, PluralForms::parse(null, 'zh')->count());
});

test('A broken expression degrades to the English rule', function (): void {
    $forms = PluralForms::parse('nplurals=2; plural=(n !== );');

    assertSame(2, $forms->count());
    assertSame(0, $forms->select(1));
    assertSame(1, $forms->select(2));
});

test('Division by zero inside an expression is safe', function (): void {
    $forms = PluralForms::parse('nplurals=3; plural=(n % 0 ? 2 : 1);');

    assertSame(1, $forms->select(7));
});

group('i18n: PO catalogues');

test('A PO catalogue parses singular, plural and context entries', function (): void {
    $po = <<<PO
    #  Translator comment
    msgid ""
    msgstr ""
    "Content-Type: text/plain; charset=UTF-8\n"
    "Plural-Forms: nplurals=2; plural=(n != 1);\n"

    #: src/app.php:10
    msgid "Hello"
    msgstr "Bonjour"

    msgid "{count} day"
    msgid_plural "{count} days"
    msgstr[0] "{count} jour"
    msgstr[1] "{count} jours"

    msgctxt "menu"
    msgid "Open"
    msgstr "Ouvrir"

    msgid "Multi"
    msgstr ""
    "line "
    "value"
    PO;

    $path = fixture_write('i18n/fr.po', $po);
    $catalog = Catalog::load($path, 'fr_FR');

    assertTrue($catalog instanceof Catalog);
    assertSame('Bonjour', $catalog->get('Hello'));
    assertSame('Ouvrir', $catalog->get('Open', 'menu'));
    assertSame('line value', $catalog->get('Multi'));
    assertSame('{count} jour', $catalog->getPlural('{count} day', 0));
    assertSame('{count} jours', $catalog->getPlural('{count} day', 1));
    assertNull($catalog->get('Open'), 'a context entry is not visible without its context');
    assertSame(2, $catalog->pluralForms()->count());
});

test('Non-UTF-8 catalogues are converted to UTF-8', function (): void {
    $poUtf8 = "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=ISO-8859-1\\n\"\n\n"
        . "msgid \"Gruss\"\nmsgstr \"Grüße\"\n";
    $poLatin1 = mb_convert_encoding($poUtf8, 'ISO-8859-1', 'UTF-8');

    $catalog = Catalog::fromPo($poLatin1, 'de');

    assertSame('Grüße', $catalog->get('Gruss'));
});

test('Fuzzy entries are ignored', function (): void {
    $po = "msgid \"A\"\nmsgstr \"B\"\n\n#, fuzzy\nmsgid \"C\"\nmsgstr \"D\"\n";
    $catalog = Catalog::fromPo($po, 'de');

    assertSame('B', $catalog->get('A'));
    assertNull($catalog->get('C'));
});

test('Escapes inside PO strings are decoded', function (): void {
    $po = "msgid \"tab\\there\"\nmsgstr \"line1\\nline2\\\"q\\\"\"\n";
    $catalog = Catalog::fromPo($po, 'es');

    assertSame("line1\nline2\"q\"", $catalog->get("tab\there"));
});

group('i18n: MO catalogues');

test('A binary MO catalogue is parsed', function (): void {
    $mo = build_mo(['Hello' => 'Привет', 'Bye' => 'Пока']);
    $path = fixture_write('i18n/ru.mo', $mo);
    $catalog = Catalog::load($path, 'ru_RU');

    assertTrue($catalog instanceof Catalog);
    assertSame('Привет', $catalog->get('Hello'));
    assertSame('Пока', $catalog->get('Bye'));
    assertNull($catalog->get('Missing'));
});

test('MO plural entries are split on NUL', function (): void {
    $mo = build_mo(["{n} day\0{n} days" => "{n} jour\0{n} jours"], 'nplurals=2; plural=(n != 1);');
    $catalog = Catalog::fromMo($mo, 'fr');

    assertSame('{n} jour', $catalog->getPlural('{n} day', 0));
    assertSame('{n} jours', $catalog->getPlural('{n} day', 1));
});

test('A truncated MO file is rejected without warnings', function (): void {
    assertNull(Catalog::fromMo("\xde\x12\x04\x95short", 'ru'));
});

group('i18n: translator');

test('html_load_translations + html_t round-trip', function (): void {
    $path = fixture_write('i18n/de.po', "msgid \"Save\"\nmsgstr \"Speichern\"\n");

    assertTrue(html_load_translations('de_DE', $path));
    html_set_locale('de_DE');

    assertSame('de_DE', html_get_locale());
    assertSame('Speichern', html_t('Save'));
    assertSame('Unknown key', html_t('Unknown key'));
});

test('Loading a directory finds domain-locale files', function (): void {
    fixture_write('i18n/dir/myapp-fr_FR.po', "msgid \"Quit\"\nmsgstr \"Quitter\"\n");

    assertTrue(html_load_translations('fr_FR', fixture_path('i18n/dir'), 'myapp'));
    html_set_locale('fr_FR');

    assertSame('Quitter', html_t('Quit', [], 'myapp'));
    assertSame('Quit', html_t('Quit', [], 'other-domain'));
});

test('Plural translation follows the catalogue rule', function (): void {
    $po = <<<PO
    msgid ""
    msgstr ""
    "Plural-Forms: nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);\n"

    msgid "%d file"
    msgid_plural "%d files"
    msgstr[0] "%d файл"
    msgstr[1] "%d файла"
    msgstr[2] "%d файлов"
    PO;

    html_load_translations('ru', fixture_write('i18n/ru-pl.po', $po));
    html_set_locale('ru');

    assertSame('1 файл', html_n('%d file', '%d files', 1, [1]));
    assertSame('3 файла', html_n('%d file', '%d files', 3, [3]));
    assertSame('12 файлов', html_n('%d file', '%d files', 12, [12]));
});

test('Context translations use html_x/html_nx', function (): void {
    $po = <<<'PO'
    msgctxt "verb"
    msgid "Book"
    msgstr "Réserver"

    msgctxt "noun"
    msgid "Book"
    msgstr "Livre"
    PO;

    html_load_translations('fr', fixture_write('i18n/fr-ctx.po', $po));
    html_set_locale('fr');

    assertSame('Réserver', html_x('Book', 'verb'));
    assertSame('Livre', html_x('Book', 'noun'));
    assertSame('Book', html_t('Book'));
});

test('The fallback chain is honoured', function (): void {
    html_load_translations('en', fixture_write('i18n/en.po', "msgid \"Colour\"\nmsgstr \"Color\"\n"));
    html_set_locale('en_GB');
    $GLOBALS['HTML_I18N_FALLBACK'] = ['en'];

    assertSame('Color', html_t('Colour'));
});

test('Named and sprintf interpolation both work', function (): void {
    html_load_translations('fr', fixture_write('i18n/fr-args.po',
        "msgid \"Hi {name}\"\nmsgstr \"Salut {name}\"\n\nmsgid \"%s of %d\"\nmsgstr \"%s sur %d\"\n"));
    html_set_locale('fr');

    assertSame('Salut Alice', html_t('Hi {name}', ['name' => 'Alice']));
    assertSame('3 sur 10', html_t('%s of %d', [3, 10]));
});

test('A broken sprintf format returns the raw translation', function (): void {
    html_set_locale('en');

    // Too few arguments for the format: ValueError is caught, text survives.
    assertSame('Needs %d and %d', Translator::interpolate('Needs %d and %d', [1]));
    // A lone percent sign is not a valid conversion but must not corrupt text
    // when there are no positional arguments to consume.
    assertSame('100% coverage', Translator::interpolate('100% coverage', []));
});

test('html_reset_state drops catalogues and locale stays usable', function (): void {
    html_load_translations('it', fixture_write('i18n/it.po', "msgid \"Yes\"\nmsgstr \"Sì\"\n"));
    html_set_locale('it');
    assertSame('Sì', html_t('Yes'));

    html_reset_state();
    html_set_locale('it');

    assertSame('Yes', html_t('Yes'), 'after reset the catalogue is gone');
});

test('stats() reports loaded catalogues', function (): void {
    html_load_translations('nl', fixture_write('i18n/nl.po', "msgid \"One\"\nmsgstr \"Een\"\n"));

    $stats = Translator::stats();

    assertTrue(isset($stats['messages']['nl']));
    assertTrue($stats['messages']['nl'] >= 1);
});

group('i18n: template integration');

test('@{KEY} resolves through the active catalogue', function (): void {
    html_load_translations('de', fixture_write('i18n/de-tpl.po', "msgid \"Welcome\"\nmsgstr \"Willkommen\"\n"));
    html_set_locale('de');

    assertSame('Willkommen', html_render('@{Welcome}', []));
    assertSame('Willkommen', HTML('@{Welcome}'));
});

test('Translate.KEY global still wins over the catalogue for legacy code', function (): void {
    html_load_translations('de', fixture_write('i18n/de-tpl2.po', "msgid \"Welcome\"\nmsgstr \"Willkommen\"\n"));
    html_set_locale('de');
    $GLOBALS['Translate.Welcome'] = 'Legacy-German';

    assertSame('Legacy-German', HTML('@{Welcome}'));
});

test('translate filter translates substituted values', function (): void {
    html_load_translations('es', fixture_write('i18n/es-tpl.po', "msgid \"Save\"\nmsgstr \"Guardar\"\n"));
    html_set_locale('es');

    assertSame('Guardar', html_render('%{label|translate}', ['label' => 'Save']));
});

test('html_t consults the htmlphp_translate filter', function (): void {
    html_set_locale('en');
    html_add_filter('htmlphp_translate', static fn(string $v): string => strtoupper($v));

    assertSame('HELLO', html_t('hello'));
});
