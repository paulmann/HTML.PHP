<?php

/**
 * Builds the two single-file editions from the PSR-4 source tree.
 *
 *   php8.3 tools/build.php            → writes php83/html.php and php85/html.php
 *   php8.3 tools/build.php --check    → rebuild in memory, fail on drift (CI)
 *
 * The PHP 8.3 edition is a verbatim bundle of src/. The PHP 8.5 edition
 * swaps in the hand-written overlays from src-8.5/ (property hooks,
 * asymmetric visibility, pipe operator, array_first/array_last,
 * #[\NoDiscard]) before bundling — behaviour is identical, the test suite
 * runs against both bundles.
 *
 * Every file must declare strict_types and a single top-level class; the
 * builder fails loudly otherwise (no silent drift between src and bundles).
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/** Namespace sort order — cosmetic, but keeps bundles diffable. */
const NAMESPACE_ORDER = [
    'HtmlPhp\\Exception',
    'HtmlPhp\\Support',
    'HtmlPhp\\Contracts',
    'HtmlPhp\\Config',
    'HtmlPhp\\Io',
    'HtmlPhp\\Html',
    'HtmlPhp\\Log',
    'HtmlPhp\\Cache',
    'HtmlPhp\\Event',
    'HtmlPhp',
    'HtmlPhp\\Ssi',
    'HtmlPhp\\I18n',
    'HtmlPhp\\Template',
    'HtmlPhp\\WordPress\\Maintenance',
    'HtmlPhp\\WordPress',
];

function collect_files(string $dir): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files, SORT_STRING);

    return $files;
}

/** Reads the single source of truth for the release version. */
function library_version(string $root): string
{
    $source = file_get_contents($root . '/src/Version.php');

    if ($source !== false && preg_match("/VERSION\\s*=\\s*'([^']+)'/", $source, $m) === 1) {
        return $m[1];
    }

    throw new RuntimeException('Cannot read the library version from src/Version.php.');
}

/**
 * @return array{ns: string, uses: list<string>, body: string, file: string}
 */
function parse_unit(string $file): array
{
    $source = (string) file_get_contents($file);

    if (!str_contains($source, 'declare(strict_types=1);')) {
        throw new RuntimeException("Missing strict_types: {$file}");
    }

    if (preg_match('~^namespace ([\w\\\\]+);~m', $source, $m) !== 1) {
        throw new RuntimeException("Missing namespace: {$file}");
    }

    $namespace = $m[1];

    // Strip the opening tag, strict_types and the namespace line.
    $body = preg_replace('~^<\?php\s*~', '', $source, 1);
    $body = (string) preg_replace('~^declare\(strict_types=1\);\s*~m', '', (string) $body, 1);
    $body = (string) preg_replace('~^namespace [\w\\\\]+;\s*~m', '', (string) $body, 1);

    // Pull the use statements out; they are re-emitted per namespace block.
    $uses = [];
    $body = (string) preg_replace_callback(
        '~^use\s+(?:function\s+|const\s+)?[^;]+;\s*$~m',
        static function (array $m) use (&$uses): string {
            $uses[] = trim($m[0]);

            return '';
        },
        (string) $body,
    );

    return ['ns' => $namespace, 'uses' => $uses, 'body' => trim($body), 'file' => $file];
}

function build_edition(string $root, string $edition, bool $withOverlays): string
{
    $files = collect_files($root . '/src');

    if ($withOverlays) {
        $overlays = collect_files($root . '/src-8.5');
        $byRelative = [];

        foreach ($overlays as $overlay) {
            $relative = substr($overlay, strlen($root . '/src-8.5/'));
            $byRelative[$relative] = $overlay;
        }

        foreach ($files as $index => $file) {
            $relative = substr($file, strlen($root . '/src/'));

            if (isset($byRelative[$relative])) {
                $files[$index] = $byRelative[$relative];
            }
        }

        foreach ($byRelative as $relative => $overlay) {
            if (!is_file($root . '/src/' . $relative)) {
                throw new RuntimeException("Overlay without a src counterpart: {$relative}");
            }
        }
    }

    /** @var array<string, list<array>> $grouped */
    $grouped = [];

    foreach ($files as $file) {
        $unit = parse_unit($file);
        $grouped[$unit['ns']][] = $unit;
    }

    $order = array_merge(
        NAMESPACE_ORDER,
        array_values(array_diff(array_keys($grouped), NAMESPACE_ORDER)),
    );

    $editionLabel = $withOverlays ? '8.5' : '8.3';
    $minPhp = $withOverlays ? 80500 : 80300;
    $version = library_version($root);

    $out = "<?php\n";
    $out .= <<<HEADER
/**
 * HTML.PHP — production template-processing library.
 *
 * VERSION: {$version} (PHP {$editionLabel} edition) — single-file build.
 *
 * A zero-dependency template engine + Apache SSI processor + gettext i18n:
 *  - `%{NAME|filters}` substitution with auto-escaping, loops, expression
 *    conditionals, layout inheritance, scoped partials, translation blocks;
 *  - full mod_include SSI: #config #set #include(stub/wait) #echo(encoding)
 *    #if/#elif/#else #exec(policy-gated) #fsize #flastmod #printenv #block;
 *  - i18n: PO/MO/JSON/PHP catalogues, 60+ plural rules, contexts, domains,
 *    fallback chains, extraction + audit tooling;
 *  - immutable Config object, DI container, PSR-shaped cache/log/event
 *    contracts, render cache, diagnostics, linter, profiling — NO globals;
 *  - optional WordPress bridge + maintenance toolkit (HtmlPhp\\WordPress\\*).
 *
 * Generated by tools/build.php from the PSR-4 source tree — do not edit.
 * Source, docs and tests: https://github.com/paulmann/HTML.PHP
 *
 * Author:  Mikhail Deynekin
 * Site:    https://Deynekin.com
 * License: MIT
 */

namespace {
    if (PHP_VERSION_ID < {$minPhp}) {
        fwrite(STDERR, sprintf(
            "HTML.PHP {$version} ({$editionLabel} edition) requires PHP {$editionLabel} or newer; running %s." . PHP_EOL,
            PHP_VERSION
        ));

        if (PHP_SAPI === 'cli') {
            exit(1);
        }

        http_response_code(500);
        exit;
    }
}


HEADER;

    foreach ($order as $namespace) {
        if (!isset($grouped[$namespace])) {
            continue;
        }

        $uses = [];

        foreach ($grouped[$namespace] as $unit) {
            foreach ($unit['uses'] as $use) {
                $uses[$use] = true;
            }
        }

        uksort($uses, 'strcmp');

        $out .= "namespace {$namespace} {\n\n";

        foreach (array_keys($uses) as $use) {
            $out .= $use . "\n";
        }

        if ($uses !== []) {
            $out .= "\n";
        }

        foreach ($grouped[$namespace] as $unit) {
            $out .= $unit['body'] . "\n\n";
        }

        $out .= "}\n\n";
    }

    return $out;
}

$check = in_array('--check', $argv, true);
$targets = [
    'php83/html.php' => false,
    'php85/html.php' => true,
];

$failed = false;

foreach ($targets as $target => $withOverlays) {
    $bundle = build_edition($root, $target, $withOverlays);
    $path = $root . '/' . $target;

    if ($check) {
        $current = is_file($path) ? (string) file_get_contents($path) : '';

        if ($current !== $bundle) {
            fwrite(STDERR, "DRIFT: {$target} is out of date with src/ — run tools/build.php\n");
            $failed = true;
            continue;
        }

        echo "OK {$target}\n";
        continue;
    }

    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, $bundle);
    printf(
        "built %s (%d KB, %d lines)\n",
        $target,
        (int) round(strlen($bundle) / 1024),
        substr_count($bundle, "\n"),
    );
}

exit($failed ? 1 : 0);
