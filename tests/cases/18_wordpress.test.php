<?php
/**
 * 18 - WordPress bridge & maintenance toolkit, exercised against the stubs
 *      in tests/wp-stubs.php (loaded here, before html-wp.php).
 *
 *      NOTE: loading the stubs defines ABSPATH and switches the engine into
 *      WordPress-delegation mode, so this file must run LAST (the runner
 *      loads case files in alphabetical order).
 */

declare(strict_types=1);

use HtmlPhp\WordPress\Bridge;
use HtmlPhp\WordPress\Cli\Commands;
use HtmlPhp\WordPress\Maintenance\Assets;
use HtmlPhp\WordPress\Maintenance\Cron;
use HtmlPhp\WordPress\Maintenance\Db;
use HtmlPhp\WordPress\Maintenance\Health;
use HtmlPhp\WordPress\Maintenance\Logs;
use HtmlPhp\WordPress\Maintenance\Nonces;
use HtmlPhp\WordPress\Maintenance\Notices;
use HtmlPhp\WordPress\Maintenance\Options;
use HtmlPhp\WordPress\Maintenance\Rest;
use HtmlPhp\WordPress\Maintenance\SystemInfo;
use HtmlPhp\WordPress\Maintenance\Transients;
use HtmlPhp\WordPress\Maintenance\Updates;
use HtmlPhp\WordPress\Shortcodes;
use HtmlPhp\WordPress\TemplateLoader;

require_once __DIR__ . '/../wp-stubs.php';
require_once dirname(__DIR__, 2) . '/wordpress/html-wp.php';

/**
 * Restores static state the $GLOBALS snapshot cannot reach.
 */
function wp_test_reset(): void
{
    Bridge::reset();
    Notices::reset();
    unset($GLOBALS['HTML_ESCAPER'], $GLOBALS['HTML_I18N_WP']);
}

group('WordPress: bridge');

test('The bridge detects the stubbed WordPress environment', function (): void {
    wp_test_reset();

    assertTrue(Bridge::isWordPress());
    assertTrue(html_wp_is_wordpress());
});

test('boot() installs esc_html as the engine escaper', function (): void {
    wp_test_reset();
    html_wp_boot();

    assertSame('esc_html', $GLOBALS['HTML_ESCAPER']);
    assertSame('&lt;b&gt;', html_escape('<b>'));
});

test('boot() can keep the native escaper', function (): void {
    wp_test_reset();
    html_wp_boot(['escaper' => false]);

    assertTrue(!isset($GLOBALS['HTML_ESCAPER']) || $GLOBALS['HTML_ESCAPER'] === null);
    assertSame('&lt;b&gt;', html_escape('<b>'));
});

test('boot() syncs the locale with determine_locale()', function (): void {
    wp_test_reset();
    $GLOBALS['wp_stub_locale'] = 'de_DE';
    html_wp_boot();

    assertSame('de_DE', html_get_locale());
});

test('boot() applies engine configuration overrides', function (): void {
    wp_test_reset();
    html_wp_boot(['config' => ['HTML_MISSING_VAR' => 'placeholder']]);

    assertSame('[missing:X]', html_render('%{X}'));
});

test('i18n=wp delegates translations to WordPress first', function (): void {
    wp_test_reset();
    $GLOBALS['wp_stub_translations']['myplugin']['Save'] = 'Speichern (WP)';
    html_wp_boot(['i18n' => 'wp']);

    assertSame('Speichern (WP)', html_t('Save', [], 'myplugin'));
});

test('Engine catalogues still answer when WordPress misses', function (): void {
    wp_test_reset();
    html_wp_boot(['i18n' => 'wp']);
    html_load_translations('de_DE', fixture_write('wp/de.po', "msgid \"Quit\"\nmsgstr \"Beenden\"\n"));
    html_set_locale('de_DE');

    // __() has no entry for Quit → the engine catalogue provides it.
    assertSame('Beenden', html_t('Quit'));
});

test('i18n=engine disables the WordPress delegation', function (): void {
    wp_test_reset();
    $GLOBALS['wp_stub_translations']['myplugin']['Save'] = 'Speichern (WP)';
    html_wp_boot(['i18n' => 'engine']);

    assertSame('Save', html_t('Save', [], 'myplugin'));
});

test('boot() registers configured shortcodes', function (): void {
    wp_test_reset();
    $template = fixture_write('wp/shortcodes/hello.htm', 'Hi %{name}!');
    html_wp_boot(['shortcodes' => ['wp_hello' => $template]]);

    assertSame('Hi World!', do_shortcode('[wp_hello name="World"]'));
});

group('WordPress: template loader');

test('locate() finds templates in explicit directories', function (): void {
    wp_test_reset();
    fixture_write('wp/views/page.htm', 'PAGE');

    $found = TemplateLoader::locate('page', [fixture_path('wp/views')]);

    assertSame(realpath(fixture_path('wp/views/page.htm')), $found === null ? null : realpath($found));
});

test('locate() tries the standard suffixes', function (): void {
    wp_test_reset();
    fixture_write('wp/views/suffixed.ssi.htm', 'SUFF');

    $found = TemplateLoader::locate('suffixed', [fixture_path('wp/views')]);

    assertTrue($found !== null && str_ends_with($found, 'suffixed.ssi.htm'));
});

test('render() injects the WordPress context variables', function (): void {
    wp_test_reset();
    $template = fixture_write('wp/views/context.htm', '%{wp_blogname}|%{wp_home_url}|%{wp_year}');

    $output = TemplateLoader::render($template, [], [], []);

    assertContains('Stub Site|https://example.org/|', $output);
});

test('render() returns an empty string for missing templates', function (): void {
    wp_test_reset();

    assertSame('', TemplateLoader::render('definitely-missing-template-xyz'));
});

test('Scoped shortcode attributes never leak into $GLOBALS', function (): void {
    wp_test_reset();
    $template = fixture_write('wp/shortcodes/leak.htm', '%{secret}');
    Shortcodes::register('leak_test', $template);
    unset($GLOBALS['secret']);

    assertSame('s3cr3t', do_shortcode('[leak_test secret="s3cr3t"]'));
    assertFalse(isset($GLOBALS['secret']));
});

test('Shortcode content is available as %{content}', function (): void {
    wp_test_reset();
    $template = fixture_write('wp/shortcodes/box.htm', '<box>%{content}</box>');
    Shortcodes::register('box_test', $template);

    assertSame('<box>inner</box>', do_shortcode('[box_test]inner[/box_test]'));
});

test('Shortcode attribute names are gated by the variable-name policy', function (): void {
    wp_test_reset();
    $template = fixture_write('wp/shortcodes/gated.htm', '[%{ok}][%{bad}]');
    Shortcodes::register('gated_test', $template);

    // "1bad" is not a valid variable name and is dropped.
    assertSame('[yes][]', do_shortcode('[gated_test ok="yes"]'));
});

group('WordPress: nonces, options, transients');

test('Nonce round-trip', function (): void {
    wp_test_reset();

    $nonce = Nonces::create('do_thing');

    assertTrue(Nonces::verify($nonce, 'do_thing'));
    assertFalse(Nonces::verify($nonce, 'other_action'));
    assertFalse(Nonces::verify('garbage', 'do_thing'));
    assertFalse(Nonces::verify(null));
});

test('Nonce field renders a hidden input', function (): void {
    wp_test_reset();

    assertContains('type="hidden"', Nonces::field('do_thing'));
});

test('Options typed accessors', function (): void {
    wp_test_reset();

    Options::set('count', '42');
    Options::set('flag', '1');
    Options::set('list', ['a' => 1]);

    assertSame(42, Options::getInt('count'));
    assertTrue(Options::getBool('flag'));
    assertSame(['a' => 1], Options::getArray('list'));
    assertSame('d', Options::get('missing', 'd'));

    Options::delete('count');

    assertSame(7, Options::getInt('count', 7));
});

test('Transients remember() caches the factory result', function (): void {
    wp_test_reset();

    $calls = 0;
    $factory = static function () use (&$calls): string {
        ++$calls;

        return 'computed';
    };

    assertSame('computed', Transients::remember('k', 60, $factory));
    assertSame('computed', Transients::remember('k', 60, $factory));
    assertSame(1, $calls);
});

group('WordPress: notices, db, cron, logs');

test('Notices queue, render and clear', function (): void {
    wp_test_reset();

    html_wp_notice('Hello admin', 'warning');
    assertSame(1, count(Notices::queued()));

    ob_start();
    Notices::render();
    $html = (string) ob_get_clean();

    assertContains('notice-warning', $html);
    assertContains('Hello admin', $html);
    assertSame(0, count(Notices::queued()), 'rendering drains the queue');
});

test('Notice text is escaped', function (): void {
    wp_test_reset();
    html_wp_notice('<script>alert(1)</script>', 'error');

    ob_start();
    Notices::render();
    $html = (string) ob_get_clean();

    assertNotContains('<script>', $html);
    assertContains('&lt;script&gt;', $html);
});

test('Db helpers route through $wpdb with preparation', function (): void {
    wp_test_reset();

    assertSame("WHERE x = 'O\\'Brien'", Db::prepare('WHERE x = %s', ["O'Brien"]));

    $GLOBALS['wpdb']->var = '7';
    assertSame('7', Db::getVar('SELECT COUNT(*) FROM t WHERE id = %d', [7]));

    $GLOBALS['wpdb']->results = [['table_name' => 'wp_posts', 'table_rows' => 10, 'bytes' => 1024]];
    $sizes = Db::tableSizes();

    assertSame('wp_posts', $sizes[0]['table']);
    assertSame(1024, $sizes[0]['bytes']);
});

test('Db throws without $wpdb', function (): void {
    wp_test_reset();
    $saved = $GLOBALS['wpdb'];
    unset($GLOBALS['wpdb']);

    assertThrows(HtmlPhp\TemplateException::class, static fn() => Db::getVar('SELECT 1'));

    $GLOBALS['wpdb'] = $saved;
});

test('Cron schedule is idempotent', function (): void {
    wp_test_reset();

    assertTrue(Cron::schedule('my_hook', 'hourly'));
    assertFalse(Cron::schedule('my_hook', 'hourly'), 'already scheduled');
    assertTrue(Cron::next('my_hook') !== null);
    assertTrue(Cron::unschedule('my_hook'));
    assertSame(null, Cron::next('my_hook'));
});

test('Logs write, tail and rotate', function (): void {
    wp_test_reset();

    assertTrue(Logs::write('info', 'first entry', ['k' => 'v']));
    assertTrue(Logs::write('warning', 'second entry'));

    $tail = Logs::tail(10);

    assertSame(2, count($tail));
    assertContains('first entry', $tail[0]);
    assertContains('"k":"v"', $tail[0]);
    assertContains('WARNING', $tail[1]);

    for ($day = 1; $day <= 20; ++$day) {
        file_put_contents(
            Logs::directory() . '/html-php-2020-01-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT) . '.log',
            'old'
        );
    }

    Logs::rotate(3);
    $remaining = glob(Logs::directory() . '/html-php-*.log');

    assertSame(3, count($remaining === false ? [] : $remaining));
});

group('WordPress: system info, updates, health');

test('SystemInfo::collect returns the four sections', function (): void {
    wp_test_reset();
    $info = SystemInfo::collect();

    assertTrue(isset($info['wordpress'], $info['php'], $info['server'], $info['engine']));
    assertSame('6.9-stub', $info['wordpress']['version']);
    assertSame(PHP_VERSION, $info['php']['version']);
    assertSame('Stub Theme', $info['wordpress']['active_theme']);
    assertContains('3.0.0', $info['engine']['version']);
});

test('Updates::check reads the update transients', function (): void {
    wp_test_reset();

    $core = new stdClass();
    $core->last_checked = time();
    $update = new stdClass();
    $update->response = 'upgrade';
    $update->version = '7.0';
    $update->php_version = '8.3';
    $update->locale = 'en_US';
    $core->updates = [$update];

    $plugins = new stdClass();
    $pluginUpdate = new stdClass();
    $pluginUpdate->slug = 'hello';
    $pluginUpdate->new_version = '2.0';
    $plugins->response = ['hello/hello.php' => $pluginUpdate];

    set_site_transient('update_core', $core);
    set_site_transient('update_plugins', $plugins);
    set_site_transient('update_themes', (object) ['response' => ['twenty' => ['new_version' => '1.1']]]);

    $updates = Updates::check();

    assertSame('7.0', $updates['core'][0]['version']);
    assertSame('hello/hello.php', $updates['plugins'][0]['file']);
    assertSame('twenty', $updates['themes'][0]['slug']);
    assertSame(3, Updates::count());
});

test('Health::check reports the injected issues', function (): void {
    wp_test_reset();
    $GLOBALS['wpdb']->connected = false;

    $issues = Health::check();
    $codes = array_column($issues, 'code');

    assertTrue(in_array('db-down', $codes, true));

    foreach ($issues as $issue) {
        assertTrue(in_array($issue['severity'], ['info', 'warning', 'critical'], true));
    }
});

test('Health::iniBytes parses shorthand sizes', function (): void {
    assertSame(268435456, Health::iniBytes('256M'));
    assertSame(1073741824, Health::iniBytes('1G'));
    assertSame(2048, Health::iniBytes('2048'));
    assertSame(PHP_INT_MAX, Health::iniBytes('-1'));
});

group('WordPress: assets, rest, cli');

test('Assets versions from filemtime for local files', function (): void {
    wp_test_reset();
    $file = fixture_write('wp/assets/app.css', 'body{}');

    assertSame((string) filemtime($file), Assets::version($file));

    Assets::style('my-style', $file);

    assertSame((string) filemtime($file), $GLOBALS['wp_stub_assets']['my-style']['ver']);
    assertTrue($GLOBALS['wp_stub_assets']['my-style']['enqueued']);
});

test('Rest::route refuses to register without a permission callback default', function (): void {
    wp_test_reset();

    Rest::route('htmlphp/v1', '/ping', static fn(): array => Rest::ok('pong'));

    $route = $GLOBALS['wp_stub_rest']['htmlphp/v1/ping'];

    assertTrue(is_callable($route['permission_callback']));
    assertSame(['success' => true, 'data' => 'pong'], Rest::ok('pong'));
    assertSame('bad', Rest::fail('bad', 'message')['code']);
});

test('WP-CLI commands produce output through the stub', function (): void {
    wp_test_reset();
    $GLOBALS['wp_stub_cli_log'] = [];

    $commands = new Commands();
    Commands::register();

    assertTrue(isset($GLOBALS['wp_stub_cli_commands']['htmlphp']));

    $commands->info([], ['json' => true]);
    $commands->health();
    $commands->updates();
    $commands->cache(['flush']);

    $log = implode("\n", $GLOBALS['wp_stub_cli_log']);

    assertContains('"wordpress"', $log);
    assertContains('Removed 0 cache entries', $log);
});

test('WP-CLI render command outputs the rendered template', function (): void {
    wp_test_reset();
    $GLOBALS['wp_stub_cli_log'] = [];
    $template = fixture_write('wp/cli/page.htm', 'Hello %{who}');

    (new Commands())->render([$template], ['var' => ['who=CLI']]);

    assertContains('Hello CLI', implode("\n", $GLOBALS['wp_stub_cli_log']));
});

group('WordPress: maintenance plugin end-to-end');

test('The plugin boots, registers hooks and renders its admin page', function (): void {
    wp_test_reset();

    require_once dirname(__DIR__, 2) . '/wordpress/html-php-maintenance/html-php-maintenance.php';

    do_action('plugins_loaded');

    assertTrue(has_action('admin_menu'));
    assertTrue(has_action('htmlphp_daily_health'));
    assertTrue(isset($GLOBALS['wp_stub_lifecycle']['activate']));

    // Activation schedules the daily cron.
    ($GLOBALS['wp_stub_lifecycle']['activate'])();
    assertTrue(wp_next_scheduled('htmlphp_daily_health') !== false);

    // The daily health cron stores its findings.
    do_action('htmlphp_daily_health');
    $stored = Transients::get('last_health', null);
    assertTrue(is_array($stored));

    // The admin page renders through the engine.
    ob_start();
    HtmlPhp_Maintenance_Plugin::instance()->renderPage();
    $html = (string) ob_get_clean();

    assertContains('<h1>HTML.PHP Maintenance', $html);
    assertContains('class="widefat striped"', $html);
    assertContains('Rendered at', $html);
    assertContains('This page is rendered by the HTML.PHP engine itself', $html);
});

test('POST actions are nonce-protected', function (): void {
    wp_test_reset();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['htmlphp_action'] = 'flush_cache';
    $_POST['_wpnonce'] = 'forged';
    $_REQUEST = $_POST;

    $error = assertThrows(
        WpStubRedirect::class,
        static fn() => HtmlPhp_Maintenance_Plugin::instance()->handlePost()
    );

    assertContains('wp_die', $error->getMessage());

    // A valid nonce passes and redirects.
    $_POST['_wpnonce'] = wp_create_nonce('htmlphp_maintenance');
    $_REQUEST = $_POST;

    $redirect = assertThrows(
        WpStubRedirect::class,
        static fn() => HtmlPhp_Maintenance_Plugin::instance()->handlePost()
    );

    assertContains('redirect', $redirect->getMessage());
    assertTrue(count(Notices::queued()) >= 1);
    assertSame('GET', $_SERVER['REQUEST_METHOD'] = 'GET');
});
