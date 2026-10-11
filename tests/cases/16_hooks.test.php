<?php
/**
 * 16 - Hook system: filters, actions, priorities and documented hook points.
 */

declare(strict_types=1);

group('Hooks: filters');

test('A filter transforms the applied value', function (): void {
    html_add_filter('uppercase', static fn(string $v): string => strtoupper($v));

    assertSame('ABC', html_apply_filters('uppercase', 'abc'));
});

test('Filters without callbacks pass the value through', function (): void {
    assertSame('raw', html_apply_filters('never-registered', 'raw'));
});

test('Extra arguments reach the callback', function (): void {
    html_add_filter('concat', static fn(string $v, string $suffix): string => $v . $suffix);

    assertSame('ab', html_apply_filters('concat', 'a', 'b'));
});

test('Priority decides the order', function (): void {
    html_add_filter('order', static fn(string $v): string => $v . '1', 20);
    html_add_filter('order', static fn(string $v): string => $v . '2', 5);
    html_add_filter('order', static fn(string $v): string => $v . '3', 10);

    assertSame('x231', html_apply_filters('order', 'x'));
});

test('html_remove_filter drops a priority bucket', function (): void {
    html_add_filter('rm', static fn(string $v): string => $v . '!');
    html_remove_filter('rm');

    assertSame('plain', html_apply_filters('rm', 'plain'));
});

group('Hooks: actions');

test('Actions receive their arguments in registration order', function (): void {
    $log = [];
    html_add_action('ping', static function (string $who) use (&$log): void {
        $log[] = 'a:' . $who;
    });
    html_add_action('ping', static function (string $who) use (&$log): void {
        $log[] = 'b:' . $who;
    }, 20);

    html_do_action('ping', 'x');

    assertSame(['a:x', 'b:x'], $log);
});

test('An unknown action is a no-op', function (): void {
    html_do_action('never-registered-action');
    assertTrue(true);
});

group('Hooks: engine integration');

test('htmlphp_render filters the final output', function (): void {
    html_add_filter('htmlphp_render', static fn(string $html): string => $html . "\n<!-- rendered -->");

    assertSame("body\n<!-- rendered -->", html_render('body'));
});

test('htmlphp_ssi_builtins can add custom SSI variables', function (): void {
    html_add_filter('htmlphp_ssi_builtins', static function (array $vars): array {
        $vars['DEPLOY_ID'] = 'abc123';

        return $vars;
    });

    // Custom variables are exposed through the map; #echo of non-listed names
    // still goes through the global gate, so assert on the map itself.
    $vars = HtmlPhp\Ssi\Builtins::map(null);

    assertSame('abc123', $vars['DEPLOY_ID']);
});

test('htmlphp_ssi_echo filters echoed values', function (): void {
    $GLOBALS['HOOKED'] = 'value';
    html_add_filter('htmlphp_ssi_echo', static fn(string $v, string $name): string => $name === 'HOOKED' ? strtoupper($v) : $v);

    assertSame('VALUE', processSSI('<!--#echo var="HOOKED" -->'));
});

test('htmlphp_missing_var observes every miss', function (): void {
    $seen = [];
    html_add_action('htmlphp_missing_var', static function (string $name) use (&$seen): void {
        $seen[] = $name;
    });

    html_render('%{A}%{B}', []);

    assertSame(['A', 'B'], $seen);
});

test('htmlphp_ssi_include_path can veto or redirect includes', function (): void {
    fixture_write('hooks/real.htm', 'REAL');
    fixture_write('hooks/fake.htm', 'FAKE');
    $GLOBALS['HTML_WWW_PATH'] = fixture_path('hooks');

    html_add_filter('htmlphp_ssi_include_path', static function (string $path, string $virtual): string {
        return $virtual === '/real.htm' ? fixture_path('hooks/fake.htm') : $path;
    });

    assertSame('FAKE', processSSI('<!--#include virtual="/real.htm" -->'));
});

test('htmlphp_error fires for recorded diagnostics', function (): void {
    $codes = [];
    html_add_action('htmlphp_error', static function (string $code) use (&$codes): void {
        $codes[] = $code;
    });
    html_clear_errors();

    html_render('%{GONE_VAR}', []);

    assertContains('missing-var', implode(',', $codes));
});

test('The documented hook list stays in sync with the engine', function (): void {
    $documented = HtmlPhp\Hooks::documented();

    foreach ([
        'htmlphp_error', 'htmlphp_ssi_builtins', 'htmlphp_ssi_include_path',
        'htmlphp_ssi_echo', 'htmlphp_missing_var', 'htmlphp_render',
        'htmlphp_translate', 'htmlphp_filter_apply',
    ] as $hook) {
        assertContains($hook, implode(',', $documented));
    }
});
