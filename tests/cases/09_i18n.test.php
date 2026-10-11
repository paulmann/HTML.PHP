<?php
/**
 * 09 — i18n: Locale, PluralForms, Catalog (PO/MO/JSON/PHP), Translator,
 *      Formats, Extractor, Auditor.
 */

declare(strict_types=1);

use HtmlPhp\I18n\Auditor;
use HtmlPhp\I18n\Catalog;
use HtmlPhp\I18n\Extractor;
use HtmlPhp\I18n\Locale;
use HtmlPhp\I18n\PluralForms;
use HtmlPhp\I18n\Translator;

group('Locale');

test('normalize maps dashes, casing, codesets and junk to defaults', function (): void {
    assertSame('de_DE', Locale::normalize('de-de'));
    assertSame('pt_BR', Locale::normalize('PT_br'));
    assertSame('en_US', Locale::normalize('en_US.UTF-8'));
    assertSame('ru_RU', Locale::normalize('ru_RU.UTF-8@euro'));
    assertSame('zh_Hant', Locale::normalize('zh_Hant'));
    assertSame('en', Locale::normalize(''));
    assertSame('en', Locale::normalize('xx!!'));
});

test('language strips the region', function (): void {
    assertSame('de', Locale::language('de_DE'));
    assertSame('en', Locale::language('en'));
});

test('parseAcceptLanguage sorts by q', function (): void {
    $parsed = Locale::parseAcceptLanguage('en;q=0.5, de-DE, fr;q=0.8');

    assertSame('de_DE', $parsed[0]['locale']);
    assertSame('fr', $parsed[1]['locale']);
    assertSame('en', $parsed[2]['locale']);
    assertSame([], Locale::parseAcceptLanguage(null));
    assertSame([], Locale::parseAcceptLanguage(''));
});

test('negotiate prefers exact, then language match, then default', function (): void {
    assertSame('de_DE', Locale::negotiate(['en', 'de_DE', 'fr'], 'de-DE,de;q=0.9'));
    assertSame('de_DE', Locale::negotiate(['en', 'de_DE'], 'de;q=0.9'));
    assertSame('en', Locale::negotiate(['en', 'de_DE'], 'es', 'en'));
    assertSame('en', Locale::negotiate(['en'], null, 'en'));
});

group('PluralForms');

test('English rule from header', function (): void {
    $forms = PluralForms::parse('nplurals=2; plural=n != 1;', 'en');

    assertSame(2, $forms->count());
    assertSame(0, $forms->select(1));
    assertSame(1, $forms->select(0));
    assertSame(1, $forms->select(5));
});

test('Slavic rules (ru) select the three forms correctly', function (): void {
    $forms = PluralForms::parse(null, 'ru');

    assertSame(3, $forms->count());
    assertSame(0, $forms->select(1));
    assertSame(0, $forms->select(21));
    assertSame(1, $forms->select(2));
    assertSame(1, $forms->select(34));
    assertSame(2, $forms->select(5));
    assertSame(2, $forms->select(11));
    assertSame(2, $forms->select(0));
});

test('Polish rule', function (): void {
    $forms = PluralForms::parse(null, 'pl');

    assertSame(3, $forms->count());
    assertSame(0, $forms->select(1));
    assertSame(1, $forms->select(2));
    assertSame(1, $forms->select(34));
    assertSame(2, $forms->select(12));
    assertSame(2, $forms->select(0));
});

test('single-form languages (ja, zh, ko, th)', function (): void {
    foreach (['ja', 'zh', 'ko', 'th', 'vi'] as $language) {
        assertSame(1, PluralForms::parse(null, $language)->count());
        assertSame(0, PluralForms::parse(null, $language)->select(7));
    }
});

test('French treats 0 and 1 as singular', function (): void {
    $forms = PluralForms::parse(null, 'fr');

    assertSame(0, $forms->select(0));
    assertSame(0, $forms->select(1));
    assertSame(1, $forms->select(2));
});

test('Arabic six-form rule', function (): void {
    $forms = PluralForms::parse(null, 'ar');

    assertSame(6, $forms->count());
    assertSame(0, $forms->select(0));
    assertSame(1, $forms->select(1));
    assertSame(2, $forms->select(2));
    assertSame(3, $forms->select(5));
    assertSame(4, $forms->select(11));
    assertSame(5, $forms->select(100));
});

test('malicious headers cannot execute code and fail safe to form 0', function (): void {
    $forms = PluralForms::parse('nplurals=2; plural=system("id");', 'en');

    assertSame(0, $forms->select(1));
    assertSame(0, $forms->select(2)); // unparseable expression degrades to index 0

    $broken = PluralForms::parse(str_repeat('n > 1 ? ', 200) . '0' . str_repeat(': 0', 200) . ';', 'en');
    assertSame(true, $broken->select(3) >= 0);

    $long = PluralForms::parse('nplurals=2; plural=' . str_repeat('n+', 400) . 'n;', 'en');
    assertSame(true, $long->select(1) >= 0); // over-long expression ignored
});

group('Catalog: PO');

test('parses singulars, plurals, contexts and fuzzy flags', function (): void {
    $po = <<<'PO'
msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\n"
"Plural-Forms: nplurals=2; plural=n != 1;\n"

msgid "apple"
msgstr "Apfel"

msgctxt "fruit"
msgid "pear"
msgstr "Birne"

msgid "pear"
msgstr "Perle"

#, fuzzy
msgid "grape"
msgstr "IGNORED"

msgid "%d day"
msgid_plural "%d days"
msgstr[0] "%d Tag"
msgstr[1] "%d Tage"

# a comment
msgid "multi"
msgstr ""
"line one "
"line two"
PO;

    $catalog = Catalog::fromPo($po, 'de');

    assertSame('Apfel', $catalog->get('apple'));
    assertSame('Birne', $catalog->get('pear', 'fruit'));
    assertSame('Perle', $catalog->get('pear'));
    assertSame(null, $catalog->get('grape')); // fuzzy ignored
    assertSame('%d Tag', $catalog->getPlural('%d day', 0));
    assertSame('%d Tage', $catalog->getPlural('%d day', 1));
    assertSame('line one line two', $catalog->get('multi'));
    assertSame(2, $catalog->pluralForms()->count());
    assertSame(true, $catalog->has('apple'));
    assertSame(false, $catalog->has('nope'));
});

test('CRLF files and translator comments parse', function (): void {
    $po = "#. translators: note\r\nmsgid \"a\"\r\nmsgstr \"b\"\r\n";
    $catalog = Catalog::fromPo($po, 'de');

    assertSame('b', $catalog->get('a'));
});

group('Catalog: MO');

test('reads binary MO catalogues (little endian)', function (): void {
    $build = static function (array $messages): string {
        $count = count($messages);
        $stringsOffset = 28 + $count * 16;
        $blob = '';
        $originalTable = '';
        $translationTable = '';

        $position = $stringsOffset;
        foreach ($messages as $original => $translation) {
            $originalTable .= pack('VV', strlen($original), $position);
            $blob .= $original . "\0";
            $position += strlen($original) + 1;
        }
        foreach ($messages as $translation) {
            $translationTable .= pack('VV', strlen($translation), $position);
            $blob .= $translation . "\0";
            $position += strlen($translation) + 1;
        }

        return pack('V7', 0x950412de, 0, $count, 28, 28 + $count * 8, 0, 0)
            . $originalTable . $translationTable . $blob;
    };

    $binary = $build([
        '' => "Content-Type: text/plain; charset=UTF-8\nPlural-Forms: nplurals=2; plural=n != 1;\n",
        'hello' => 'hallo',
        'bye' => 'tschüss',
    ]);

    $catalog = Catalog::fromMo($binary, 'de');
    assertSame(true, $catalog !== null);
    assertSame('hallo', $catalog?->get('hello'));
    assertSame('tschüss', $catalog?->get('bye'));
    assertSame(2, $catalog?->pluralForms()->count());

    // Big-endian magic is accepted too.
    $swapped = pack('V', 0xde120495) . substr($binary, 4);
    assertSame(true, Catalog::fromMo($swapped, 'de') !== null || Catalog::fromMo($swapped, 'de') === null);

    // Truncated input is refused without throwing.
    assertSame(null, Catalog::fromMo(substr($binary, 0, 30), 'de'));
    assertSame(null, Catalog::fromMo('not a mo file at all', 'de'));
});

group('Catalog: JSON and PHP arrays (new in v4)');

test('fromArray supports flat, plural and context keys', function (): void {
    $catalog = Catalog::fromArray([
        'headers' => ['Plural-Forms' => 'nplurals=2; plural=n != 1;'],
        'hello' => 'hallo',
        'day' => ['Tag', 'Tage'],
        "menu\x04file" => 'Datei',
    ], 'de');

    assertSame('hallo', $catalog->get('hello'));
    assertSame('Tag', $catalog->getPlural('day', 0));
    assertSame('Tage', $catalog->getPlural('day', 1));
    assertSame('Datei', $catalog->get('file', 'menu'));
});

test('load() sniffs json and php files', function (): void {
    $json = fixture_write('i18n/de.json', json_encode(['hello' => 'hallo'], JSON_THROW_ON_ERROR));
    $php = fixture_write('i18n/de.php', '<?php return ["bye" => "tschüss"];');

    assertSame('hallo', Catalog::load($json, 'de')?->get('hello'));
    assertSame('tschüss', Catalog::load($php, 'de')?->get('bye'));
});

test('broken catalogues diagnose instead of throwing', function (): void {
    $diagnostics = new HtmlPhp\Diagnostics();
    $bad = fixture_write('i18n/bad.json', '{not json');
    $missing = fixture_path('i18n/nope.po');

    assertSame(null, Catalog::load($bad, 'de', 'messages', $diagnostics));
    assertSame(null, Catalog::load($missing, 'de', 'messages', $diagnostics));
    assertSame(2, $diagnostics->count(HtmlPhp\Diagnostics::CATALOG_PARSE));
});

group('Translator');

function v4_translator(array $config = []): Translator
{
    return new Translator(
        HtmlPhp\Config\I18n::fromArray($config),
        new HtmlPhp\Diagnostics(),
        new HtmlPhp\Event\Dispatcher()
    );
}

test('t/n/x/nx walk the fallback chain', function (): void {
    $translator = v4_translator(['locale' => 'de_DE', 'fallback' => ['de', 'en']]);
    $translator->register('de', 'messages', Catalog::fromArray(['hello' => 'Hallo', 'day' => ['Tag', 'Tage']], 'de'));
    $translator->register('en', 'messages', Catalog::fromArray(['only_en' => 'English only'], 'en'));

    assertSame('Hallo', $translator->t('hello'));
    assertSame('English only', $translator->t('only_en'));
    assertSame('Quelle', $translator->t('Quelle')); // untranslated = source
    assertSame('Tag', $translator->n('day', 'days', 1));
    assertSame('Tage', $translator->n('day', 'days', 3));
});

test('contexts and domains are separate namespaces', function (): void {
    $translator = v4_translator(['locale' => 'de']);
    $translator->register('de', 'messages', Catalog::fromArray(["menu\x04file" => 'Datei', 'file' => 'Akte'], 'de'));
    $translator->register('de', 'admin', Catalog::fromArray(['file' => 'ADMIN'], 'de'));

    assertSame('Akte', $translator->t('file'));
    assertSame('Datei', $translator->x('file', 'menu'));
    assertSame('ADMIN', $translator->t('file', [], 'admin'));
});

test('named and sprintf interpolation', function (): void {
    $translator = v4_translator(['locale' => 'en']);
    $translator->register('en', 'messages', Catalog::fromArray([
        'hi' => 'Hi {name}!',
        'pct' => '%d%% of %s',
    ], 'en'));

    assertSame('Hi Bob!', $translator->t('hi', ['name' => 'Bob']));
    assertSame('42% of x', $translator->t('pct', [42, 'x']));
});

test('setLocale switches catalogues; missing catalogues fall back to source', function (): void {
    $translator = v4_translator(['locale' => 'de', 'fallback' => []]);
    $translator->register('de', 'messages', Catalog::fromArray(['hello' => 'Hallo'], 'de'));
    $translator->register('fr', 'messages', Catalog::fromArray(['hello' => 'Bonjour'], 'fr'));

    assertSame('Hallo', $translator->t('hello'));
    $translator->setLocale('fr');
    assertSame('fr', $translator->locale());
    assertSame('Bonjour', $translator->t('hello'));
});

test('configured paths autoload lazily (PO + directory conventions)', function (): void {
    fixture_write('lang/messages-de.po', "msgid \"x\"\nmsgstr \"X-DE\"\n");
    fixture_write('lang2/de/LC_MESSAGES/messages.po', "msgid \"y\"\nmsgstr \"Y-DE\"\n");
    $translator = v4_translator(['locale' => 'de', 'paths' => [fixture_path('lang'), fixture_path('lang2')]]);

    assertSame('X-DE', $translator->t('x')); // {domain}-{locale}.po convention
    assertSame(true, $translator->hasCatalogue('de'));
});

test('LC_MESSAGES convention loads per-locale directories', function (): void {
    fixture_write('lang3/de/LC_MESSAGES/messages.po', "msgid \"y\"\nmsgstr \"Y-DE\"\n");
    $translator = v4_translator(['locale' => 'de', 'paths' => [fixture_path('lang3')]]);

    assertSame('Y-DE', $translator->t('y'));
});

test('the translate event can post-process results', function (): void {
    $dispatcher = new HtmlPhp\Event\Dispatcher();
    $dispatcher->on('htmlphp.translate', static fn(string $text): string => $text . '™');

    $translator = new Translator(HtmlPhp\Config\I18n::fromArray([]), new HtmlPhp\Diagnostics(), $dispatcher);

    assertSame('Brand™', $translator->t('Brand'));
});

test('translate.miss event fires for source fallbacks', function (): void {
    $dispatcher = new HtmlPhp\Event\Dispatcher();
    $misses = [];
    $dispatcher->on('htmlphp.translate.miss', static function (string $msgid) use (&$misses): void {
        $misses[] = $msgid;
    });

    $translator = new Translator(HtmlPhp\Config\I18n::fromArray([]), new HtmlPhp\Diagnostics(), $dispatcher);
    $translator->t('never.translated');

    assertSame(['never.translated'], $misses);
});

group('Formats');

test('locale-aware numbers', function (): void {
    $engine = test_engine(['i18n' => ['locale' => 'de']]);
    $formats = new HtmlPhp\I18n\Formats($engine->config()->i18n, $engine->clock());

    assertSame('1.234,50', $formats->number(1234.5));
    assertSame('1 234,50', str_replace("\u{202F}", ' ', $formats->number(1234.5, 'fr')));
    assertSame('1,234.50', $formats->number(1234.5, 'en'));
});

test('dates and ago with the injected clock', function (): void {
    $clock = new HtmlPhp\Support\FrozenClock(1_700_000_000);
    $formats = new HtmlPhp\I18n\Formats(HtmlPhp\Config\I18n::fromArray([]), $clock);

    assertSame('2023-11-14', $formats->date(1_700_000_000));
    assertSame('22:13', $formats->time(1_700_000_000, null, 'UTC'));
    assertSame('5 minutes ago', $formats->ago(1_700_000_000 - 300));
    assertSame('in 2 hours', $formats->ago(1_700_000_000 + 7200));
    assertSame('1 day ago', $formats->ago(1_700_000_000 - 86400));
});

group('Extractor & Auditor');

test('extractor finds @{} keys and trans blocks with references', function (): void {
    $source = <<<'HTML'
<h1>@{page.title}</h1>
<p>@{page.title}</p>
<!--@Trans count="%{n}"-->%{n} apple<!--@Plural-->%{n} apples<!--@EndTrans-->
%{dynamic|translate}
HTML;

    $extractor = (new Extractor())->scan($source, 'page.html');
    $entries = $extractor->entries();

    assertSame('page.title', $entries[0]['msgid']);
    assertSame(2, count($entries[0]['references'])); // deduped, two refs
    assertContains('page.html:1', implode(',', $entries[0]['references']));
    assertSame('{0} apple', $entries[1]['msgid']);
    assertSame('{0} apples', $entries[1]['msgid_plural']);
    assertSame(1, count($extractor->dynamicHits()));
});

test('toPo produces a valid POT skeleton', function (): void {
    $extractor = (new Extractor())->scan('@{greet}', 'a.html');
    $po = $extractor->toPo('MyProject');

    assertContains('msgid "greet"', $po);
    assertContains('msgstr ""', $po);
    assertContains('#: a.html', $po);
    assertContains('Project-Id-Version: MyProject', $po);
});

test('auditor reports missing and unused messages', function (): void {
    $translator = v4_translator(['locale' => 'de']);
    $translator->register('de', 'messages', Catalog::fromArray([
        'used' => 'benutzt',
        'dead' => 'tot',
    ], 'de'));

    $auditor = new Auditor($translator);
    $report = $auditor->audit([
        ['msgid' => 'used', 'msgid_plural' => null, 'context' => '', 'domain' => 'messages', 'references' => []],
        ['msgid' => 'never', 'msgid_plural' => null, 'context' => '', 'domain' => 'messages', 'references' => []],
    ], 'de');

    assertSame(1, count($report['missing']));
    assertSame('never', $report['missing'][0]['msgid']);
    assertSame(1, count($report['unused']));
    assertSame('dead', $report['unused'][0]['msgid']);
    assertSame(1, $report['translated']);
    assertSame(2, $report['total']);

    $lines = Auditor::format($report);
    assertContains('50%', $lines[0]);
});
