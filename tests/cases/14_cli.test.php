<?php

/**
 * 14 — bin/htmlphp CLI: exit codes and output contracts.
 */

declare(strict_types=1);

/**
 * Runs the CLI in a child process.
 *
 * @param list<string> $arguments
 * @return array{0: int, 1: string, 2: string} [exit code, stdout+stderr, stdout only]
 */
function run_cli(array $arguments, array $env = []): array
{
    $root = dirname(__DIR__, 2);

    if (testing_bundle()) {
        $env['HTML_PHP_LIB'] = library_path();
    }

    $command = [PHP_BINARY, $root . '/bin/htmlphp', ...$arguments];

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        $root,
        $env === [] ? null : (array_replace($_ENV, $env)),
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start the CLI process.');
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout . $stderr, $stdout];
}

group('CLI');

test('version prints banner and exits 0', function (): void {
    [$code, $out] = run_cli(['version']);

    assertSame(0, $code);
    assertContains(\HtmlPhp\Version::VERSION, $out);
    assertContains(PHP_VERSION, $out);
});

test('help exits 0 and lists commands', function (): void {
    [$code, $out] = run_cli(['help']);

    assertSame(0, $code);
    assertContains('lint', $out);
    assertContains('i18n:extract', $out);
});

test('unknown commands exit 2', function (): void {
    [$code, $out] = run_cli(['frobnicate']);

    assertSame(2, $code);
    assertContains('Unknown command', $out);
});

test('render outputs the rendered template', function (): void {
    $path = fixture_write('cli/render.html', 'Hi %{name}!');
    [$code, $out] = run_cli(['render', $path, '--var=name=CLI']);

    assertSame(0, $code);
    assertContains('Hi CLI!', $out);
});

test('render accepts space-separated --var k v (v5 parser)', function (): void {
    $path = fixture_write('cli/render-vars.html', 'P: %{= done / total * 100 | round:1 }%');
    [$code, $out] = run_cli(['render', $path, '--var', 'done=37', '--var', 'total=250']);

    assertSame(0, $code);
    assertContains('P: 14.8%', $out);

    // The = form keeps working; a valueless flag stays a boolean.
    [$code, $out] = run_cli(['render', $path, '--var=done=1', '--var=total=2']);
    assertSame(0, $code);
    assertContains('P: 50.0%', $out);
});

test('lint exits 0 on clean files and 1 on errors', function (): void {
    $clean = fixture_write('cli/clean.html', 'fine %{x} <!--@Loop r-->%{n}<!--@EndLoop-->');
    [$code] = run_cli(['lint', $clean]);
    assertSame(0, $code);

    $bad = fixture_write('cli/bad.html', '<!--@Nope-->%{y|ghost}');
    [$code, $out] = run_cli(['lint', $bad]);
    assertSame(1, $code);
    assertContains('unknown-tag', $out);
    assertContains('unknown-filter', $out);
});

test('lint --ignore downgrades baselined codes', function (): void {
    $bad = fixture_write('cli/bad2.html', '<!--@Nope-->');
    [$code, $out] = run_cli(['lint', $bad, '--ignore=unknown-tag']);

    assertSame(0, $code);
    assertContains('0 error(s)', $out);
});

test('lint --format=json emits machine-readable issues', function (): void {
    $bad = fixture_write('cli/bad3.html', '<!--@Nope-->');
    [$code, $out, $stdout] = run_cli(['lint', $bad, '--format=json']);

    assertSame(1, $code);
    $decoded = json_decode($stdout, true);
    assertSame(true, is_array($decoded));

    $first = null;
    foreach ($decoded as $entry) {
        if (is_array($entry) && ($entry['code'] ?? '') !== '') {
            $first = $entry;
            break;
        }
    }

    assertSame(true, $first !== null);
    assertSame('unknown-tag', $first['code']);
});

test('lint walks directories', function (): void {
    @mkdir(fixture_path('cli/dir'), 0777, true);
    fixture_write('cli/dir/a.html', 'ok');
    fixture_write('cli/dir/b.html', '<!--@Bad-->');

    [$code, $out] = run_cli(['lint', fixture_path('cli/dir')]);

    assertSame(1, $code);
    assertContains('b.html', $out);
});

test('ssi processes a file with document anchoring', function (): void {
    $dir = fixture_path('cli/ssi');
    @mkdir($dir, 0777, true);
    file_put_contents($dir . '/part.html', 'PART');
    $doc = fixture_write('cli/ssi/doc.html', 'A<!--#include file="part.html" -->B');

    [$code, $out] = run_cli(['ssi', $doc, '--root=' . $dir]);

    assertSame(0, $code);
    assertContains('APARTB', $out);
});

test('config:dump prints valid JSON with every group', function (): void {
    [$code, $out] = run_cli(['config:dump']);

    assertSame(0, $code);
    $decoded = json_decode($out, true);
    assertSame(true, is_array($decoded));
    assertSame(true, isset($decoded['templates'], $decoded['ssi'], $decoded['i18n'], $decoded['limits']));
});

test('--config loads a custom configuration file', function (): void {
    $configFile = fixture_write('cli/config.php', '<?php return ["debug" => true, "syntax" => ["auto_escape" => false]];');
    [$code, $out] = run_cli(['config:dump', '--config=' . $configFile]);

    assertSame(0, $code);
    assertContains('"debug": true', $out);
    assertContains('"auto_escape": false', $out);
});

test('i18n:extract writes a POT file', function (): void {
    fixture_write('cli/i18n/page.html', '<h1>@{page.title}</h1>@{page.title}');
    $out = fixture_path('cli/out.pot');

    [$code, $output] = run_cli(['i18n:extract', fixture_path('cli/i18n'), '--out=' . $out]);

    assertSame(0, $code);
    assertSame(true, is_file($out));
    assertContains('msgid "page.title"', (string) file_get_contents($out));
    assertContains('Wrote 1 message(s)', $output);
});

test('i18n:audit exits 1 on missing translations and 0 when complete', function (): void {
    fixture_write('cli/i18n2/page.html', '@{greet}');

    // No catalogue configured → everything missing.
    [$code, $out] = run_cli(['i18n:audit', fixture_path('cli/i18n2'), '--locale=de']);
    assertSame(1, $code);
    assertContains('missing', $out);

    // With a complete catalogue → clean.
    fixture_write('cli/lang/messages-de.po', "msgid \"greet\"\nmsgstr \"Hallo\"\n");
    $configFile = fixture_write('cli/i18n2-config.php', '<?php return ["i18n" => ["locale" => "de", "paths" => [__DIR__ . "/lang"]]];');

    [$code, $out] = run_cli(['i18n:audit', fixture_path('cli/i18n2'), '--config=' . $configFile]);
    assertSame(0, $code);
    assertContains('1/1 messages translated', $out);
});

test('cache:clear reports the number of removed entries', function (): void {
    [$code, $out] = run_cli(['cache:clear']);

    assertSame(0, $code);
    assertContains('Removed', $out);
});

test('runtime errors exit 1 with the exception on stderr', function (): void {
    [$code, $out] = run_cli(['render', '/definitely/missing/template.html']);

    assertSame(1, $code);
    assertContains('TemplateNotFoundException', $out);
});
