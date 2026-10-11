<?php

/**
 * 11 — Linter and diagnostics workflows.
 */

declare(strict_types=1);

use HtmlPhp\Template\LintIssue;

/** @return list<string> */
function lint_codes(array $issues): array
{
    return array_map(static fn(LintIssue $issue): string => $issue->code, $issues);
}

/** assertContains for code lists (the runner's assertContains is string-only). */
function assertHas(string $code, array $codes): void
{
    assertTrue(in_array($code, $codes, true), "expected issue code '{$code}' in [" . implode(', ', $codes) . ']');
}

group('Linter');

test('clean templates produce no issues', function (): void {
    $engine = test_engine();

    assertSame([], $engine->lintString('plain %{var} text <!--@Loop rows-->%{n}<!--@EndLoop-->'));
});

test('unknown tags and filters are errors', function (): void {
    $engine = test_engine();

    assertHas('unknown-tag', lint_codes($engine->lintString('<!--@Frobnicate-->')));
    assertHas('unknown-filter', lint_codes($engine->lintString('%{x|nope}')));
});

test('unbalanced blocks report open and close mismatches with lines', function (): void {
    $engine = test_engine();

    $issues = $engine->lintString("a\n<!--@Loop rows-->\nb\n<!--@EndIf-->");
    $codes = lint_codes($issues);
    assertHas('unbalanced-tag', $codes);
    assertHas('unclosed-tag', $codes);

    foreach ($issues as $issue) {
        assertSame(true, $issue->line >= 1);
    }
});

test('orphan else is an error', function (): void {
    $engine = test_engine();

    assertHas('orphan-mid', lint_codes($engine->lintString('<!--@Else-->')));
});

test('bad expressions are reported', function (): void {
    $engine = test_engine();

    assertHas('bad-expression', lint_codes($engine->lintString('<!--@If x && -->y<!--@EndIf-->')));
    assertHas('bad-expression', lint_codes($engine->lintString('<!--@Set v="1 +"-->')));
});

test('unresolved includes/extends are errors when roots exist', function (): void {
    $engine = test_engine();

    assertHas('unresolved-include', lint_codes($engine->lintString('<!--@Include "ghost.html"-->')));
    assertHas('unresolved-extends', lint_codes($engine->lintString('<!--@Extends "ghost.html"-->')));

    // ignore-missing downgrades to nothing
    assertSame([], $engine->lintString('<!--@Include "ghost.html" ignore-missing-->'));
});

test('resolved includes pass', function (): void {
    fixture_write('tpl/real.html', 'R');
    $engine = test_engine();

    assertSame([], $engine->lintString('<!--@Include "real.html"-->'));
});

test('trans block problems are warnings', function (): void {
    $engine = test_engine();

    assertHas('trans-count-without-plural', lint_codes($engine->lintString('<!--@Trans count="%{n}"-->one<!--@EndTrans-->')));
    assertHas('trans-plural-without-count', lint_codes($engine->lintString('<!--@Trans-->one<!--@Plural-->many<!--@EndTrans-->')));
    assertHas('trans-nested-tag', lint_codes($engine->lintString('<!--@Trans--><!--@Loop x-->y<!--@EndLoop--><!--@EndTrans-->')));
});

test('untranslated @{} messages are flagged for the active locale', function (): void {
    $engine = test_engine(['i18n' => ['locale' => 'de']]);
    $engine->translator()->register('de', 'messages', HtmlPhp\I18n\Catalog::fromArray(['known' => 'Bekannt'], 'de'));

    assertSame([], lint_codes(array_values(array_filter(
        $engine->lintString('@{known}'),
        static fn(LintIssue $i): bool => $i->code === 'untranslated-message',
    ))));

    assertHas('untranslated-message', lint_codes($engine->lintString('@{absent.key}')));
});

test('SSI problems are reported', function (): void {
    $engine = test_engine();

    assertHas('unknown-ssi-directive', lint_codes($engine->lintString('<!--#frobnicate-->')));
    assertHas('ssi-missing-expr', lint_codes($engine->lintString('<!--#if-->')));
    assertHas('ssi-include-target', lint_codes($engine->lintString('<!--#include-->')));
    assertHas('ssi-echo-var', lint_codes($engine->lintString('<!--#echo-->')));
    assertHas('ssi-bad-expr', lint_codes($engine->lintString('<!--#if expr="((" -->')));
    assertHas('ssi-exec-denied', lint_codes($engine->lintString('<!--#exec cmd="ls" -->')));
});

test('lint(file) and lintDirectory walk the tree', function (): void {
    fixture_write('tpl/lintdir/ok.html', 'fine %{x}');
    fixture_write('tpl/lintdir/bad.html', '<!--@Nope-->');
    $engine = test_engine();

    assertSame([], $engine->lint(fixture_path('tpl/lintdir/ok.html')));
    assertHas('unknown-tag', lint_codes($engine->lint(fixture_path('tpl/lintdir/bad.html'))));

    $report = $engine->lintDirectory(fixture_path('tpl/lintdir'));
    assertSame(1, count($report));
    assertSame(true, isset($report[fixture_path('tpl/lintdir/bad.html')]));
});

test('lint(name) resolves through the locator', function (): void {
    fixture_write('tpl/lintdir2/x.html', '<!--@Frobnicate-->');
    $engine = test_engine();

    assertHas('unknown-tag', lint_codes($engine->lint('lintdir2/x.html')));
});

test('issue formatting is CI friendly', function (): void {
    $issue = new LintIssue('error', 'unknown-tag', 'Nope.', 'tpl/a.html', 12);

    assertSame('tpl/a.html:12 ERROR [unknown-tag] Nope.', $issue->format());
    assertSame(true, $issue->isError());
    assertSame('unknown-tag', $issue->toArray()['code']);
});
