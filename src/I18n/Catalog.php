<?php

declare(strict_types=1);

namespace HtmlPhp\I18n;

use HtmlPhp\Diagnostics;
use HtmlPhp\Io\Files;

/**
 * A message catalogue: gettext PO/MO plus (new in v4) JSON/PHP arrays.
 *
 * Stores singular/plural forms per (context, msgid), parses PO headers
 * (charset conversion included) and derives plural selection from the
 * `Plural-Forms` header via {@see PluralForms}.
 */
final class Catalog
{
    private const string CONTEXT_SEPARATOR = "\x04";

    public readonly string $locale;
    public readonly string $domain;

    /** @var array<string, array{single: ?string, plurals: list<string>, fuzzy: bool}> */
    private array $messages = [];

    /** @var array<string, string> lower-cased header name => value */
    private array $headers = [];

    private ?PluralForms $pluralForms = null;
    private string $charset = 'utf-8';

    private function __construct(string $locale, string $domain)
    {
        $this->locale = $locale;
        $this->domain = $domain;
    }

    /**
     * Loads a catalogue from a file, sniffed by magic bytes and extension:
     * `.mo` (gettext binary), `.po` (gettext text), `.json` and `.php`
     * (arrays shaped `'msgid' => 'translation'` or
     * `'msgid' => ['singular', 'plural', …]`; contexts use a `\x04`
     * separator inside the key, and a `headers` key may carry PO headers).
     */
    public static function load(
        string $path,
        string $locale,
        string $domain = 'messages',
        ?Diagnostics $diagnostics = null,
    ): ?self {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'php') {
            if (!is_file($path)) {
                $diagnostics?->record(Diagnostics::CATALOG_PARSE, 'Translation catalogue is not readable.', ['path' => $path]);

                return null;
            }

            $messages = include $path;

            return \is_array($messages)
                ? self::fromArray($messages, $locale, $domain)
                : self::failed($diagnostics, 'PHP catalogue did not return an array.', $path);
        }

        $contents = Files::slurp($path, 32 * 1024 * 1024);

        if ($contents === null) {
            return self::failed($diagnostics, 'Translation catalogue is not readable.', $path);
        }

        $magic = substr($contents, 0, 4);

        if ($magic === "\xde\x12\x04\x95" || $magic === "\x95\x04\x12\xde") {
            return self::fromMo($contents, $locale, $domain, $diagnostics);
        }

        if ($extension === 'mo') {
            return self::failed($diagnostics, 'File has a .mo extension but no MO magic.', $path);
        }

        if ($extension === 'json') {
            $decoded = json_decode($contents, true);

            return \is_array($decoded)
                ? self::fromArray($decoded, $locale, $domain)
                : self::failed($diagnostics, 'JSON catalogue is malformed.', $path);
        }

        return self::fromPo($contents, $locale, $domain);
    }

    private static function failed(?Diagnostics $diagnostics, string $message, string $path): null
    {
        $diagnostics?->record(Diagnostics::CATALOG_PARSE, $message, ['path' => $path]);

        return null;
    }

    // ------------------------------------------------------------------- PO

    public static function fromPo(string $source, string $locale, string $domain = 'messages'): self
    {
        $catalog = new self(Locale::normalize($locale), $domain);

        /**
         * Parse state, mutated by reference from the loop below. Typed loosely
         * on purpose: the literal initializer shape does not survive
         * reference-flow analysis and would fake "always false" guards.
         *
         * @var array<string, mixed>
         */
        $entry = ['context' => '', 'id' => '', 'idPlural' => '', 'forms' => [], 'fuzzy' => false];
        $target = null; // ['id'|'context'|'idPlural'|'form', int]
        $lines = preg_split('~\r\n|\r|\n~', $source) ?: [];

        $flush = static function () use (&$entry, $catalog): void {
            // $entry is mutated by reference below; typed locals keep the
            // guard honest for static analysis (the literal init shape does
            // not survive reference flow).
            $id = (string) $entry['id'];
            $forms = (array) $entry['forms'];

            if ($id !== '' || $forms !== []) {
                $catalog->store(
                    (string) $entry['context'],
                    $id,
                    (string) $entry['idPlural'],
                    $forms,
                    (bool) $entry['fuzzy'],
                );
            }

            $entry = ['context' => '', 'id' => '', 'idPlural' => '', 'forms' => [], 'fuzzy' => false];
        };

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                $flush();
                $target = null;
                continue;
            }

            if ($line[0] === '#') {
                if (str_starts_with($line, '#,')) {
                    $flags = array_map('trim', explode(',', substr($line, 2)));
                    $entry['fuzzy'] = $entry['fuzzy'] || \in_array('fuzzy', $flags, true);
                }

                // #. #: #| and plain # comments carry no payload for us.
                continue;
            }

            if (preg_match('~^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(.*)$~', $line, $m) === 1) {
                $keyword = $m[1];
                $payload = $m[3];

                if (str_starts_with($keyword, 'msgstr[')) {
                    $target = ['form', (int) $m[2]];
                } elseif ($keyword === 'msgstr') {
                    $target = ['form', 0];
                } elseif ($keyword === 'msgid') {
                    $target = ['id', 0];
                } elseif ($keyword === 'msgid_plural') {
                    $target = ['idPlural', 0];
                } else {
                    $target = ['context', 0];
                }

                $string = self::poString($payload);

                if ($string !== null) {
                    $catalog->appendEntry($entry, $target, $string);
                }

                continue;
            }

            // Continuation line: a bare quoted string.
            if ($line[0] === '"' && $target !== null) {
                $string = self::poString($line);

                if ($string !== null) {
                    $catalog->appendEntry($entry, $target, $string);
                }
            }
        }

        $flush();
        $catalog->finalizeHeaders();

        return $catalog;
    }

    /**
     * Appends a parsed string fragment to the current entry slot.
     *
     * @param array{context: string, id: string, idPlural: string, forms: array<int, string>, fuzzy: bool} $entry
     * @param array{0: string, 1: int} $target
     */
    private function appendEntry(array &$entry, array $target, string $fragment): void
    {
        match ($target[0]) {
            'context' => $entry['context'] .= $fragment,
            'id' => $entry['id'] .= $fragment,
            'idPlural' => $entry['idPlural'] .= $fragment,
            default => $entry['forms'][$target[1]] = ($entry['forms'][$target[1]] ?? '') . $fragment,
        };
    }

    /**
     * Parses one PO quoted string ("…" with escapes); null when invalid.
     */
    private static function poString(string $payload): ?string
    {
        $payload = trim($payload);

        if ($payload === '' || $payload[0] !== '"') {
            return null;
        }

        $length = \strlen($payload);
        $value = '';

        for ($index = 1; $index < $length; ++$index) {
            $char = $payload[$index];

            if ($char === '\\' && $index + 1 < $length) {
                $next = $payload[++$index];
                $value .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '0' => "\0",
                    default => $next,
                };
                continue;
            }

            if ($char === '"') {
                return $value;
            }

            $value .= $char;
        }

        return null; // Unterminated string.
    }

    // ------------------------------------------------------------------- MO

    public static function fromMo(
        string $binary,
        string $locale,
        string $domain = 'messages',
        ?Diagnostics $diagnostics = null,
    ): ?self {
        $length = \strlen($binary);

        if ($length < 28) {
            $diagnostics?->record(Diagnostics::CATALOG_PARSE, 'MO catalogue is truncated.');

            return null;
        }

        $magic = substr($binary, 0, 4);
        $endian = $magic === "\xde\x12\x04\x95" ? 'V' : 'N';

        $header = unpack($endian . 'revision/' . $endian . 'count/' . $endian . 'origOffset/'
            . $endian . 'transOffset/' . $endian . 'hashSize/' . $endian . 'hashOffset', substr($binary, 4, 24));

        if ($header === false) {
            return null;
        }

        $majorRevision = ($header['revision'] >> 16) & 0xFFFF;

        if ($majorRevision > 1) {
            $diagnostics?->record(
                Diagnostics::CATALOG_PARSE,
                \sprintf('Unsupported MO revision %d.', $majorRevision),
            );

            return null;
        }

        $count = (int) $header['count'];
        $origOffset = (int) $header['origOffset'];
        $transOffset = (int) $header['transOffset'];

        if ($count < 0 || $count > 1_000_000
            || $origOffset < 0 || $origOffset + $count * 8 > $length
            || $transOffset < 0 || $transOffset + $count * 8 > $length
        ) {
            $diagnostics?->record(Diagnostics::CATALOG_PARSE, 'MO catalogue tables are out of bounds.');

            return null;
        }

        $catalog = new self(Locale::normalize($locale), $domain);

        for ($index = 0; $index < $count; ++$index) {
            $origMeta = unpack($endian . 'len/' . $endian . 'off', substr($binary, $origOffset + $index * 8, 8));
            $transMeta = unpack($endian . 'len/' . $endian . 'off', substr($binary, $transOffset + $index * 8, 8));

            if ($origMeta === false || $transMeta === false) {
                continue;
            }

            $origLen = (int) $origMeta['len'];
            $origOff = (int) $origMeta['off'];
            $transLen = (int) $transMeta['len'];
            $transOff = (int) $transMeta['off'];

            if ($origOff < 0 || $origOff + $origLen > $length
                || $transOff < 0 || $transOff + $transLen > $length
            ) {
                continue;
            }

            $original = substr($binary, $origOff, $origLen);
            $translation = substr($binary, $transOff, $transLen);

            if ($original === '') {
                // Metadata entry: headers (and possibly a NUL-separated
                // comment block we do not need).
                $catalog->storeHeaders($translation);
                continue;
            }

            $context = '';

            if (str_contains($original, self::CONTEXT_SEPARATOR)) {
                [$context, $original] = explode(self::CONTEXT_SEPARATOR, $original, 2);
            }

            $idParts = explode("\0", $original);
            $id = (string) $idParts[0];
            $idPlural = (string) ($idParts[1] ?? '');
            $forms = [];

            foreach (explode("\0", $translation) as $position => $form) {
                $forms[$position] = $form;
            }

            $catalog->store($context, $id, $idPlural, $forms, false);
        }

        $catalog->finalizeHeaders();

        return $catalog;
    }

    // -------------------------------------------------------------- storage

    /**
     * @param array<int, string> $forms
     */
    private function store(string $context, string $id, string $idPlural, array $forms, bool $fuzzy): void
    {
        if ($id === '') {
            $joined = '';

            foreach ($forms as $form) {
                $joined .= $form;
            }

            if ($joined !== '') {
                $this->storeHeaders($joined);
            }

            return;
        }

        ksort($forms);

        $this->messages[self::key($context, $id)] = [
            'single' => $forms[0] ?? null,
            'plurals' => array_values(array_map('strval', $forms)),
            'fuzzy' => $fuzzy,
        ];

        if ($idPlural !== '') {
            $this->messages[self::key($context, $idPlural)] ??= $this->messages[self::key($context, $id)];
        }
    }

    private function storeHeaders(string $raw): void
    {
        foreach (preg_split('~\r\n|\r|\n~', $raw) ?: [] as $line) {
            if (preg_match('~^([A-Za-z][A-Za-z0-9_-]*)\s*:\s*(.*)$~', trim($line), $m) === 1) {
                $this->headers[strtolower($m[1])] = trim($m[2]);
            }
        }

        $charsetHeader = $this->headers['content-type'] ?? '';

        if (preg_match('~charset\s*=\s*([A-Za-z0-9_-]+)~i', $charsetHeader, $m) === 1) {
            $this->charset = strtolower($m[1]);
        }
    }

    /**
     * Converts non-UTF-8 payloads once, right after parsing.
     */
    private function finalizeHeaders(): void
    {
        $this->pluralForms = PluralForms::parse($this->headers['plural-forms'] ?? null, $this->locale);

        if (\in_array($this->charset, ['utf-8', 'utf8', 'ascii', 'us-ascii'], true)) {
            return;
        }

        $charset = $this->charset;

        $convert = static function (string $text) use ($charset): string {
            if (\function_exists('iconv')) {
                $converted = @iconv($charset, 'UTF-8//TRANSLIT', $text);

                if ($converted !== false) {
                    return $converted;
                }
            }

            if (\function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($text, 'UTF-8', $charset);

                if (\is_string($converted)) {
                    return $converted;
                }
            }

            return $text;
        };

        foreach ($this->messages as $key => $entry) {
            $this->messages[$key] = [
                'single' => $entry['single'] === null ? null : $convert($entry['single']),
                'plurals' => array_map($convert, $entry['plurals']),
                'fuzzy' => $entry['fuzzy'],
            ];
        }
    }

    private static function key(string $context, string $msgid): string
    {
        return $context === '' ? $msgid : $context . self::CONTEXT_SEPARATOR . $msgid;
    }

    // --------------------------------------------------------------- lookup

    /**
     * Singular translation or null (fuzzy entries are ignored).
     */
    public function get(string $msgid, string $context = ''): ?string
    {
        $entry = $this->messages[self::key($context, $msgid)] ?? null;

        if ($entry === null || $entry['fuzzy'] || $entry['single'] === null || $entry['single'] === '') {
            return null;
        }

        return $entry['single'];
    }

    /**
     * Plural translation form or null.
     */
    public function getPlural(string $msgid, int $formIndex, string $context = ''): ?string
    {
        $entry = $this->messages[self::key($context, $msgid)] ?? null;

        if ($entry === null || $entry['fuzzy'] || $entry['plurals'] === []) {
            return null;
        }

        $form = $entry['plurals'][$formIndex] ?? null;

        return \is_string($form) && $form !== '' ? $form : null;
    }

    public function has(string $msgid, string $context = ''): bool
    {
        return $this->get($msgid, $context) !== null;
    }

    public function pluralForms(): PluralForms
    {
        return $this->pluralForms ??= PluralForms::parse(null, $this->locale);
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function count(): int
    {
        return \count($this->messages);
    }

    /**
     * Builds a catalogue from a plain PHP/JSON array.
     *
     * Accepted shapes per key (contexts are encoded as `"ctx\x04msgid"`):
     *  - `'msgid' => 'translation'`
     *  - `'msgid' => ['singular', 'plural form 1', …]`
     *  - `'headers' => ['Plural-Forms' => 'nplurals=2; plural=n != 1;']`
     *
     * @param array<array-key, mixed> $messages
     */
    public static function fromArray(array $messages, string $locale, string $domain = 'messages'): self
    {
        $catalog = new self(Locale::normalize($locale), $domain);

        $headers = $messages['headers'] ?? null;

        if (\is_array($headers)) {
            $lines = [];

            foreach ($headers as $name => $value) {
                if (\is_string($name) && \is_scalar($value)) {
                    $lines[] = $name . ': ' . $value;
                }
            }

            $catalog->storeHeaders(implode("\n", $lines));
        }

        foreach ($messages as $key => $value) {
            if (!\is_string($key) || $key === 'headers') {
                continue;
            }

            [$context, $msgid] = str_contains($key, self::CONTEXT_SEPARATOR)
                ? explode(self::CONTEXT_SEPARATOR, $key, 2)
                : ['', $key];

            if (\is_string($value)) {
                $catalog->store($context, $msgid, '', [0 => $value], false);
                continue;
            }

            if (\is_array($value) && $value !== []) {
                $forms = [];

                foreach (array_values($value) as $index => $form) {
                    if (\is_scalar($form) || $form === null) {
                        $forms[$index] = (string) $form;
                    }
                }

                if ($forms !== []) {
                    $catalog->store($context, $msgid, '', $forms, false);
                }
            }
        }

        $catalog->finalizeHeaders();

        return $catalog;
    }

    /**
     * Merges another catalogue's messages into this one.
     *
     * Existing entries win unless `$otherWins` — used when several
     * configured paths contribute to the same locale/domain (first path
     * takes precedence, like WordPress theme-over-plugin overrides).
     */
    public function mergeFrom(self $other, bool $otherWins = false): void
    {
        foreach ($other->messages as $key => $entry) {
            if ($otherWins || !isset($this->messages[$key])) {
                $this->messages[$key] = $entry;
            }
        }
    }

    /**
     * Every stored message id with its context — used by the i18n auditor.
     *
     * @return list<array{context: string, msgid: string}>
     */
    public function ids(): array
    {
        $ids = [];

        foreach (array_keys($this->messages) as $key) {
            [$context, $msgid] = str_contains($key, self::CONTEXT_SEPARATOR)
                ? explode(self::CONTEXT_SEPARATOR, $key, 2)
                : ['', $key];

            $ids[] = ['context' => $context, 'msgid' => $msgid];
        }

        return $ids;
    }
}
