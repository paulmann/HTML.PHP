<?php

declare(strict_types=1);

namespace HtmlPhp\I18n;

use HtmlPhp\Support\Pcre;

/**
 * Extracts translatable messages from template sources.
 *
 * Recognised syntax:
 *  - `@{Some.Key}` — static message id;
 *  - `<!--@Trans count="%{n}" domain="d" context="c"-->singular body
 *    <!--@Plural-->plural body<!--@EndTrans-->` — the bodies become the
 *    msgid/msgid_plural with every `%{expr}` normalised to a positional
 *    `{0}`, `{1}`, … placeholder (exactly what the renderer looks up).
 *
 * `%{dynamic|translate}` cannot be extracted (runtime value) and is
 * reported through {@see self::dynamicHits()} so CI can flag it.
 *
 * Feed the result to {@see self::toPo()} (POT skeleton) or the auditor.
 */
final class Extractor
{
    /** @var list<array{msgid: string, msgid_plural: ?string, context: string, domain: string, references: list<string>}> */
    private array $entries = [];

    /** @var list<string> "file:line" of dynamic translate filters */
    private array $dynamic = [];

    /** @var array<string, int> entry key => index */
    private array $index = [];

    /**
     * Scans one template source. `$name` is used for reference annotations.
     */
    public function scan(string $source, string $name = '-'): self
    {
        $this->scanAtKeys($source, $name);
        $this->scanTransBlocks($source, $name);
        $this->scanDynamic($source, $name);

        return $this;
    }

    /**
     * Recursively scans every `.html`/`.htm`/`.txt` template below `$dir`.
     *
     * @return list<string> scanned files
     */
    public function scanDirectory(string $dir, string $extension = 'html'): array
    {
        $scanned = [];

        if (!is_dir($dir)) {
            return $scanned;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $info */
        foreach ($iterator as $info) {
            if (!$info->isFile() || $info->getExtension() !== $extension) {
                continue;
            }

            $contents = @file_get_contents($info->getPathname());

            if (is_string($contents)) {
                $this->scan($contents, $info->getPathname());
                $scanned[] = $info->getPathname();
            }
        }

        return $scanned;
    }

    /** @return list<array{msgid: string, msgid_plural: ?string, context: string, domain: string, references: list<string>}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<string> */
    public function dynamicHits(): array
    {
        return $this->dynamic;
    }

    public function reset(): void
    {
        $this->entries = [];
        $this->dynamic = [];
        $this->index = [];
    }

    /**
     * Renders a PO/POT skeleton of everything extracted so far.
     */
    public function toPo(string $projectId = 'HTML.PHP templates', bool $pot = true): string
    {
        $out = "msgid \"\"\nmsgstr \"\"\n"
            . '"Project-Id-Version: ' . self::escapePo($projectId) . "\\n\"\n"
            . '"MIME-Version: 1.0\\n"' . "\n"
            . '"Content-Type: text/plain; charset=UTF-8\\n"' . "\n"
            . '"Content-Transfer-Encoding: 8bit\\n"' . "\n\n";

        foreach ($this->entries as $entry) {
            if ($entry['references'] !== []) {
                $out .= '#: ' . implode(', ', $entry['references']) . "\n";
            }

            if ($entry['context'] !== '') {
                $out .= 'msgctxt "' . self::escapePo($entry['context']) . "\"\n";
            }

            $out .= 'msgid "' . self::escapePo($entry['msgid']) . "\"\n";

            if ($entry['msgid_plural'] !== null) {
                $out .= 'msgid_plural "' . self::escapePo($entry['msgid_plural']) . "\"\n";
                $out .= 'msgstr[0] ""' . "\n";
                $out .= 'msgstr[1] ""' . "\n";
            } else {
                $out .= 'msgstr ""' . "\n";
            }

            $out .= "\n";
        }

        return $pot ? $out : $out;
    }

    // ---------------------------------------------------------------- scanning

    private function scanAtKeys(string $source, string $name): void
    {
        if (preg_match_all('~@\{([^{}<>]{1,200}?)\}~', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return;
        }

        foreach ($matches[1] as $index => $match) {
            $msgid = trim(explode('|', (string) $match[0])[0]);

            if ($msgid === '') {
                continue;
            }

            $this->add($msgid, null, '', 'messages', $this->reference($source, $name, (int) $match[1]));
        }
    }

    private function scanTransBlocks(string $source, string $name): void
    {
        $count = preg_match_all(
            '~<!--@Trans\b(.*?)-->(.*?)<!--@EndTrans\s*(?:[^>]*)?-->~is',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        if ($count === 0 || $count === false) {
            return;
        }

        foreach ($matches[0] as $index => $whole) {
            $attrs = \HtmlPhp\Ssi\Attributes::parse((string) $matches[1][$index][0]);
            $body = (string) $matches[2][$index][0];

            $parts = preg_split('~<!--@Plural\s*-->~i', $body, 2);
            $singular = self::literalize((string) ($parts[0] ?? ''));
            $plural = isset($parts[1]) ? self::literalize($parts[1]) : null;

            if (trim($singular) === '' && $plural === null) {
                continue;
            }

            $domain = trim($attrs['domain'] ?? '');

            $this->add(
                $singular,
                $plural,
                trim($attrs['context'] ?? ''),
                $domain !== '' ? $domain : 'messages',
                $this->reference($source, $name, (int) $whole[1])
            );
        }
    }

    private function scanDynamic(string $source, string $name): void
    {
        if (preg_match_all('~[%#]\{[^}]*\|\s*translate\b~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as $match) {
                $this->dynamic[] = $this->reference($source, $name, (int) $match[1]);
            }
        }
    }

    /**
     * Turns a trans-block body into its msgid form: `%{expr}` becomes `{n}`,
     * whitespace runs collapse to single spaces, edges are trimmed.
     */
    private static function literalize(string $body): string
    {
        $index = 0;

        $body = Pcre::replaceCallback(
            '~[%#]\{\s*([^}|]+?)(?:\|[^}]*)?\}~',
            static function () use (&$index): string {
                return '{' . $index++ . '}';
            },
            $body
        );

        $body = (string) preg_replace('~\s+~', ' ', $body);

        return trim($body);
    }

    private function add(string $msgid, ?string $plural, string $context, string $domain, string $reference): void
    {
        $key = $domain . "\x04" . $context . "\x04" . $msgid . "\x04" . ($plural ?? '');

        if (isset($this->index[$key])) {
            $entry = &$this->entries[$this->index[$key]];

            if (!in_array($reference, $entry['references'], true)) {
                $entry['references'][] = $reference;
            }

            unset($entry);

            return;
        }

        $this->index[$key] = count($this->entries);
        $this->entries[] = [
            'msgid' => $msgid,
            'msgid_plural' => $plural,
            'context' => $context,
            'domain' => $domain,
            'references' => [$reference],
        ];
    }

    private function reference(string $source, string $name, int $offset): string
    {
        if ($offset <= 0 || $offset > strlen($source)) {
            return $name;
        }

        $line = substr_count($source, "\n", 0, $offset) + 1;

        return $name . ':' . $line;
    }

    private static function escapePo(string $text): string
    {
        return str_replace(
            ["\x00", "\\", '"', "\n", "\t", "\r"],
            ['', '\\\\', '\\"', '\\n', '\\t', '\\r'],
            $text
        );
    }
}
