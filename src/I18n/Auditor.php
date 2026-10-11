<?php

declare(strict_types=1);

namespace HtmlPhp\I18n;

/**
 * Compares extracted template messages against a locale's catalogue.
 *
 * The maintenance question it answers: *"which strings will show up in
 * English on my German site, and which translations are dead weight?"*
 * Wired into `bin/htmlphp i18n:audit`, `wp htmlphp i18n audit` and the
 * WordPress admin screen.
 */
final class Auditor
{
    public function __construct(private readonly Translator $translator)
    {
    }

    /**
     * @param list<array{msgid: string, msgid_plural: ?string, context: string, domain: string, references: list<string>}> $entries
     * @return array{locale: string, missing: list<array{msgid: string, context: string, domain: string}>, unused: list<array{msgid: string, context: string, domain: string}>, total: int, translated: int}
     */
    public function audit(array $entries, string $locale, string $domain = 'messages'): array
    {
        $locale = Locale::normalize($locale);
        $missing = [];
        $used = [];
        $total = 0;

        foreach ($entries as $entry) {
            if (($entry['domain'] ?? 'messages') !== $domain) {
                continue;
            }

            $total++;

            $catalog = $this->translator->catalogue($locale, $domain);
            $translated = $catalog?->get($entry['msgid'], $entry['context'] ?? '');

            $used[$domain . "\x04" . ($entry['context'] ?? '') . "\x04" . $entry['msgid']] = true;

            // gettext catalogues also store the plural source form as a key.
            if (($entry['msgid_plural'] ?? null) !== null && $entry['msgid_plural'] !== '') {
                $used[$domain . "\x04" . ($entry['context'] ?? '') . "\x04" . $entry['msgid_plural']] = true;
            }

            if ($translated === null) {
                $missing[] = [
                    'msgid' => $entry['msgid'],
                    'context' => $entry['context'] ?? '',
                    'domain' => $domain,
                ];
            }
        }

        $unused = [];
        $catalog = $this->translator->catalogue($locale, $domain);

        if ($catalog !== null) {
            foreach ($catalog->ids() as $id) {
                $key = $domain . "\x04" . $id['context'] . "\x04" . $id['msgid'];

                if (!isset($used[$key])) {
                    $unused[] = [
                        'msgid' => $id['msgid'],
                        'context' => $id['context'],
                        'domain' => $domain,
                    ];
                }
            }
        }

        return [
            'locale' => $locale,
            'missing' => $missing,
            'unused' => $unused,
            'total' => $total,
            'translated' => $total - count($missing),
        ];
    }

    /**
     * Human-readable report (CLI / admin screen).
     *
     * @param array{locale: string, missing: list<array{msgid: string, context: string, domain: string}>, unused: list<array{msgid: string, context: string, domain: string}>, total: int, translated: int} $report
     * @return list<string>
     */
    public static function format(array $report): array
    {
        $lines = [];
        $percent = $report['total'] > 0
            ? (int) round($report['translated'] / $report['total'] * 100)
            : 100;

        $lines[] = sprintf(
            'Locale %s: %d/%d messages translated (%d%%), %d unused.',
            $report['locale'],
            $report['translated'],
            $report['total'],
            $percent,
            count($report['unused'])
        );

        foreach (array_slice($report['missing'], 0, 50) as $entry) {
            $lines[] = '  missing: ' . self::label($entry);
        }

        if (count($report['missing']) > 50) {
            $lines[] = sprintf('  … and %d more missing.', count($report['missing']) - 50);
        }

        foreach (array_slice($report['unused'], 0, 20) as $entry) {
            $lines[] = '  unused:  ' . self::label($entry);
        }

        if (count($report['unused']) > 20) {
            $lines[] = sprintf('  … and %d more unused.', count($report['unused']) - 20);
        }

        return $lines;
    }

    /** @param array{msgid: string, context: string} $entry */
    private static function label(array $entry): string
    {
        return ($entry['context'] !== '' ? $entry['context'] . "\x04" : '') . $entry['msgid'];
    }
}
