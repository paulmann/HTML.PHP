<?php
/**
 * 12b — Security model: escaping defaults, containment, budgets and the
 *       architectural guarantee that the core never touches globals.
 */

declare(strict_types=1);

use HtmlPhp\Ssi\DocumentInfo;

group('Security: containment');

test('template locator refuses absolute paths from template data', function (): void {
    fixture_write('tpl/ok.html', 'ok');
    fixture_write('tpl/secret.txt', 'TOPSECRET');
    $engine = test_engine();

    assertSame(null, $engine->locator()->locate('/etc/passwd'));
    assertSame(null, $engine->locator()->locate('C:\\windows\\win.ini'));
    assertSame(null, $engine->locator()->locate('../secret.txt'));
    assertSame(null, $engine->locator()->locate("nul\0.html"));
});

test('partial includes cannot escape the roots', function (): void {
    fixture_write('secret.txt', 'TOPSECRET');
    fixture_write('tpl/x.html', 'x');
    $engine = test_engine();

    // unknown → TemplateNotFound, never a leak
    assertThrows(
        HtmlPhp\Exception\TemplateNotFoundException::class,
        static fn() => $engine->renderString('<!--@Include "../../secret.txt"-->', [])
    );
});

test('symlinks inside roots that point outside are refused', function (): void {
    $target = fixture_write('outside-secret.html', 'LEAK');
    @mkdir(fixture_path('tpl/links'), 0777, true);
    $link = fixture_path('tpl/links/escape.html');
    @unlink($link);

    if (!@symlink($target, $link)) {
        skip('symlinks not permitted on this filesystem');
    }

    $engine = test_engine();
    assertSame(null, $engine->locator()->locate('links/escape.html'));
});

test('SSI include refuses denied extensions even inside the root', function (): void {
    $engine = test_engine();
    fixture_write('tpl/ssi/wp-config.php', '<?php $db_password = "hunter2";');
    fixture_write('tpl/ssi/notes.txt', 'safe');

    assertSame('', ssi($engine, '<!--#include virtual="/ssi/wp-config.php" -->'));
    assertSame('safe', ssi($engine, '<!--#include virtual="/ssi/notes.txt" -->'));
});

test('denied-extension list is configurable', function (): void {
    $engine = test_engine(['security' => ['denied_extensions' => ['txt']]]);
    fixture_write('tpl/ssi/notes.txt', 'safe');
    fixture_write('tpl/ssi/page.html', 'H');

    assertSame('', ssi($engine, '<!--#include virtual="/ssi/notes.txt" -->'));
    assertSame('H', ssi($engine, '<!--#include virtual="/ssi/page.html" -->'));
});

group('Security: budgets');

test('SSI directive budget stops include bombs', function (): void {
    $engine = test_engine(['limits' => ['max_ssi_tags' => 3]]);
    fixture_write('tpl/ssi/bomb.html', '<!--#include virtual="/ssi/b.html" --><!--#include virtual="/ssi/b.html" --><!--#include virtual="/ssi/b.html" --><!--#include virtual="/ssi/b.html" -->');
    fixture_write('tpl/ssi/b.html', 'b<!--#include virtual="/ssi/c.html" -->');
    fixture_write('tpl/ssi/c.html', 'c');

    $out = ssi($engine, '<!--#include virtual="/ssi/bomb.html" -->');
    assertSame(true, strlen($out) < 20);
    assertSame(true, $engine->diagnostics()->count(HtmlPhp\Diagnostics::SSI_LIMIT_REACHED) > 0);
});

test('template size limit rejects huge documents', function (): void {
    $engine = test_engine(['limits' => ['max_template_bytes' => 1024]]);

    assertThrows(
        HtmlPhp\Exception\TemplateException::class,
        static fn() => $engine->renderString(str_repeat('<div>', 500))
    );
});

test('deep layout chains abort with a clear error', function (): void {
    for ($i = 0; $i < 12; $i++) {
        fixture_write("tpl/chain/l$i.html", "<!--@Extends \"l" . ($i + 1) . ".html\"--><!--@Section s-->$i<!--@EndSection-->");
    }
    fixture_write('tpl/chain/l12.html', '<!--@Section s-->root<!--@EndSection-->');

    $engine = test_engine();
    assertThrows(HtmlPhp\Exception\TemplateException::class, static fn() => $engine->render('chain/l0.html'));
});

test('expression length cap prevents parser abuse', function (): void {
    $engine = test_engine(['limits' => ['max_expression_length' => 64], 'syntax' => ['strict' => true]]);

    assertThrows(
        HtmlPhp\Exception\TemplateSyntaxException::class,
        static fn() => $engine->renderString('<!--@If \'' . str_repeat('a', 200) . '\' == \'x\'-->y<!--@EndIf-->', [])
    );

    // Lenient mode: same guard, recorded as a diagnostic instead.
    $lenient = test_engine(['limits' => ['max_expression_length' => 64]]);
    assertSame('', $lenient->renderString('<!--@If \'' . str_repeat('a', 200) . '\'-->y<!--@EndIf-->', []));
    assertSame(true, $lenient->diagnostics()->count(HtmlPhp\Diagnostics::TEMPLATE_LINT) > 0);
});

group('Security: output');

test('auto-escape neutralises the classic injection vectors', function (): void {
    $engine = test_engine();
    $payloads = [
        '<script>alert(1)</script>',
        '"><img src=x onerror=alert(1)>',
        "' onclick='alert(1)",
        '&lt;already&amp;escaped&gt;', // NOT double-encoded (double_encode=false)
    ];

    foreach ($payloads as $index => $payload) {
        $out = $engine->renderString('<div title="%{v}">%{v}</div>', ['v' => $payload]);
        assertSame(false, str_contains($out, '<script>'), 'payload ' . $index);
        assertSame(false, str_contains($out, '<img'), 'payload ' . $index);
    }

    assertContains('&lt;already&amp;escaped&gt;', $engine->renderString('%{v}', ['v' => '&lt;already&amp;escaped&gt;']));
});

test('raw filter is the only unescape hatch and can be disabled', function (): void {
    $engine = test_engine(['syntax' => ['allow_raw_filter' => false]]);

    assertSame('&lt;b&gt;', $engine->renderString('%{v|raw}', ['v' => '<b>']));
});

test('Input sanitisation is explicit, never automatic', function (): void {
    $_GET['q'] = '<script>x</script>';
    $_POST['nested'] = ['a' => "'quote'"];

    $input = HtmlPhp\Input::all();

    assertSame('&lt;script&gt;x&lt;/script&gt;', $input['get']['q']);
    assertSame('&apos;quote&apos;', $input['post']['nested']['a']);
    assertSame('<script>x</script>', $_GET['q']); // superglobals untouched

    unset($_GET['q'], $_POST['nested']);
});

group('Security: architecture guarantees');

test('the library core contains no eval() and no superglobal reads', function (): void {
    if (testing_bundle()) {
        // Single-file builds mix every class into one file; the per-file
        // allow-list only makes sense against the src/ tree (scanned by the
        // default matrix leg).
        skip('architecture scan runs against src/, not bundles');
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    assertSame(true, count($files) > 30);

    // Documented edge points: the engine core must be global-free; only the
    // explicit host-input helper and the WordPress adapters (which talk to
    // WP's own globals like $wpdb and admin superglobals) are exempt.
    $allowedSuperglobals = ['/src/Input.php'];
    $allowedPrefixes = ['/src/WordPress/'];

    $evalViolations = [];
    $globalViolations = [];

    foreach ($files as $file) {
        $normalized = str_replace('\\', '/', $file);

        // Token-level scan: comments and strings (docs mention $_SERVER etc.)
        // do not count — only real code does.
        $hasEval = false;
        $hasSuperglobal = false;

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_EVAL) {
                $hasEval = true;
            }

            if ($token[0] === T_VARIABLE
                && ($token[1] === '$GLOBALS' || preg_match('~^\$_(GET|POST|REQUEST|COOKIE|SESSION|FILES|SERVER|ENV)$~', (string) $token[1]) === 1)
            ) {
                $hasSuperglobal = true;
            }
        }

        if ($hasEval) {
            $evalViolations[] = $normalized;
        }

        if ($hasSuperglobal) {
            $allowed = false;

            foreach ($allowedSuperglobals as $suffix) {
                if (str_ends_with($normalized, $suffix)) {
                    $allowed = true;
                    break;
                }
            }

            foreach ($allowedPrefixes as $prefix) {
                if (str_contains($normalized, $prefix)) {
                    $allowed = true;
                    break;
                }
            }

            if (!$allowed) {
                $globalViolations[] = $normalized;
            }
        }
    }

    assertSame([], $evalViolations, 'eval() found in: ' . implode(', ', $evalViolations));
    assertSame([], $globalViolations, 'superglobal/$GLOBALS reads in: ' . implode(', ', $globalViolations));
});

test('every src file declares strict types', function (): void {
    if (testing_bundle()) {
        skip('bundles declare strict_types once at the top');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS)
    );

    $missing = [];

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $head = substr((string) file_get_contents($file->getPathname()), 0, 200);

        if (!str_contains($head, 'declare(strict_types=1);')) {
            $missing[] = $file->getPathname();
        }
    }

    assertSame([], $missing);
});
