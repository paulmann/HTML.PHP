<?php

/**
 * 12 — WordPress bridge, adapters, maintenance toolkit and the plugin.
 *
 * Every test boots the Bridge against the stubbed WordPress API; the
 * TestRunner snapshots $GLOBALS per test so stub state never leaks.
 */

declare(strict_types=1);

use HtmlPhp\Engine;
use HtmlPhp\WordPress\AdminPage;
use HtmlPhp\WordPress\Bridge;
use HtmlPhp\WordPress\Cli;
use HtmlPhp\WordPress\Mailer;
use HtmlPhp\WordPress\Shortcodes;
use HtmlPhp\WordPress\TemplateLoader;
use HtmlPhp\WordPress\WpCache;

/** Boots a bridge scoped to the fixture theme, then returns the engine. */
function wp_engine(array $config = [], array $options = []): Engine
{
    Bridge::reset();

    return Bridge::boot(array_replace_recursive([
        'templates' => ['paths' => [fixture_path('tpl')]],
    ], $config), null, $options);
}

group('WordPress: Bridge');

test('boot applies WP defaults: theme paths, uploads cache, ABSPATH root', function (): void {
    $engine = wp_engine();

    assertSame(true, Bridge::booted());
    assertSame($engine, Bridge::engine());

    $paths = $engine->config()->templates->paths;
    assertSame(true, in_array(fixture_path('wp-theme') . '/htmlphp', $paths, true));
    assertSame(true, in_array(fixture_path('tpl'), $paths, true));

    assertSame('wordpress', $engine->config()->cache->driver->value);
    assertSame(true, $engine->config()->cache->enabled);
    assertSame(rtrim(ABSPATH, '/\\'), $engine->config()->ssi->documentRoot);
    assertSame('wordpress', $engine->config()->log->driver->value);
});

test('booting twice returns the same engine; explicit config wins over defaults', function (): void {
    $first = wp_engine(['debug' => true, 'cache' => ['driver' => 'array']]);
    $second = Bridge::boot();

    assertSame($first, $second);
    assertSame(true, $first->config()->debug);
    assertSame('array', $first->config()->cache->driver->value);
});

test('the setup callback can override container services', function (): void {
    Bridge::reset();
    $marker = new HtmlPhp\Cache\ArrayCache();

    $engine = Bridge::boot([], static function (HtmlPhp\Container $container) use ($marker): void {
        $container->instance(HtmlPhp\Contracts\CacheInterface::class, $marker);
    });

    assertSame($marker, $engine->cache());
});

test('engine events are mirrored onto WordPress filters', function (): void {
    $engine = wp_engine();

    add_filter('htmlphp_render', static fn(string $text): string => $text . '<!--wp-->');

    assertSame('x<!--wp-->', $engine->renderString('x'));
});

test('translation results pass through the htmlphp_translate filter', function (): void {
    $engine = wp_engine();

    add_filter('htmlphp_translate', static fn(string $text): string => strtoupper($text));

    assertSame('BRAND', $engine->t('Brand'));
});

test('content_ssi option runs the SSI pass over the_content', function (): void {
    $engine = wp_engine([], ['content_ssi' => true]);

    $filtered = apply_filters('the_content', 'a<!--#set var="Q" value="1" --><!--#echo var="Q" -->b');
    assertSame('a1b', $filtered);

    // Without directives the content is untouched.
    assertSame('plain', apply_filters('the_content', 'plain'));
});

test('the generic shortcode renders located templates with attributes', function (): void {
    fixture_write('wp-theme/htmlphp/card.html', '<b>%{title}</b>|%{content}');
    wp_engine([], ['shortcode' => 'htmlphp']);

    $out = do_shortcode('[htmlphp template="card.html" title="T"]inner[/htmlphp]');
    assertSame('<b>T</b>|inner', $out);
});

test('unknown shortcode templates render empty and diagnose, never fatal', function (): void {
    $engine = wp_engine([], ['shortcode' => 'htmlphp']);

    $out = do_shortcode('[htmlphp template="ghost.html"]');
    assertSame('', $out);
    assertSame(true, $engine->diagnostics()->count(HtmlPhp\Diagnostics::WP_BRIDGE) > 0);
});

group('WordPress: TemplateLoader & Shortcodes');

test('locate prefers the theme, then extra dirs, then the literal path', function (): void {
    fixture_write('wp-theme/partial.html', 'THEME');
    fixture_write('tpl/extra/extra-only.html', 'EXTRA');

    assertTrue(same_path(fixture_path('wp-theme/partial.html'), TemplateLoader::locate('partial.html')));
    assertTrue(same_path(fixture_path('tpl/extra/extra-only.html'), TemplateLoader::locate('extra-only.html', [fixture_path('tpl/extra')])));
    assertSame(null, TemplateLoader::locate('nowhere.html'));
});

test('bare slugs try the documented suffixes', function (): void {
    fixture_write('wp-theme/slug.html', 'S');

    assertTrue(same_path(fixture_path('wp-theme/slug.html'), TemplateLoader::locate('slug')));
});

test('php files are never treated as templates', function (): void {
    fixture_write('wp-theme/evil.php', '<?php echo "x";');

    assertSame(null, TemplateLoader::locate('evil'));
    assertSame(null, TemplateLoader::locate('evil.php'));
});

test('render injects wp_ context variables', function (): void {
    fixture_write('wp-theme/ctx.html', '%{wp_blogname}|%{wp_home_url}|%{wp_locale}|%{title}');
    $engine = wp_engine();
    $loader = new TemplateLoader($engine);

    assertSame('Stub Site|https://example.org/|en|T', $loader->render('ctx.html', ['title' => 'T']));
});

test('renderPart returns markup instead of echoing and diagnoses misses', function (): void {
    fixture_write('wp-theme/content-single.html', 'SINGLE:%{id}');
    $engine = wp_engine();
    $loader = new TemplateLoader($engine);

    assertSame('SINGLE:7', $loader->renderPart('content', 'single', ['id' => 7]));
    assertSame('', $loader->renderPart('content', 'ghost'));
    assertSame(true, $engine->diagnostics()->count(HtmlPhp\Diagnostics::WP_BRIDGE) > 0);
});

test('missing render() targets throw TemplateNotFound', function (): void {
    $engine = wp_engine();

    assertThrows(
        HtmlPhp\Exception\TemplateNotFoundException::class,
        static fn() => (new TemplateLoader($engine))->render('no-such-template'),
    );
});

test('Shortcodes::register binds a fixed template with defaults', function (): void {
    fixture_write('wp-theme/badge.html', '[%{label}|%{extra}]');
    $engine = wp_engine();
    (new Shortcodes($engine))->register('badge', 'badge.html', ['label' => 'default', 'extra' => 'E']);

    assertSame('[click|E]', do_shortcode('[badge label="click"]'));
    assertSame('[default|E]', do_shortcode('[badge]'));
});

test('shortcode attribute names are validated', function (): void {
    fixture_write('wp-theme/safe.html', '%{ok}|%{content}');
    $engine = wp_engine();
    (new Shortcodes($engine))->register('safe', 'safe.html');

    $out = do_shortcode('[safe ok="yes" 1bad="x"]body[/safe]');
    assertSame('yes|body', $out);
});

group('WordPress: cache & logger adapters');

test('WpCache round-trips through the object cache and transients', function (): void {
    $cache = new WpCache('htmlphp_test');

    assertSame(true, $cache->set('k1', 'v1', 60));
    assertSame('v1', $cache->get('k1'));
    assertSame(true, $cache->has('k1'));

    // Object-cache miss still resolves from the transient tier.
    wp_cache_delete('htmlphp_test:k1', 'htmlphp');
    assertSame('v1', $cache->get('k1'));

    assertSame(true, $cache->delete('k1'));
    assertSame(null, $cache->get('k1'));
});

test('WpCache clear() enumerates the key registry', function (): void {
    $cache = new WpCache('htmlphp_test');
    $cache->set('a', '1');
    $cache->set('b', '2');

    assertSame(2, $cache->clear());
    assertSame(null, $cache->get('a'));
    assertSame(null, $cache->get('b'));
});

test('the cache driver integrates with the render pipeline', function (): void {
    $engine = wp_engine();
    $options = new HtmlPhp\Template\RenderOptions(cache: true, ttl: 60);

    assertSame('v', $engine->renderString('v', [], $options));
    assertSame(false, $engine->stats()->cacheHit);

    assertSame('v', $engine->renderString('v', [], $options));
    assertSame(true, $engine->stats()->cacheHit);

    assertSame(true, $engine->clearCache() >= 1);
});

test('WpLogger writes rotating files under uploads', function (): void {
    $logger = new HtmlPhp\WordPress\WpLogger('htmlphp', 'debug');
    $logger->error('disk on fire {where}', ['where' => 'rack 3']);

    $tail = HtmlPhp\WordPress\Maintenance\Logs::tail(10);
    assertSame(true, count($tail) >= 1);
    assertContains('disk on fire rack 3', implode("\n", $tail));
    assertContains('"where":"rack 3"', implode("\n", $tail));
});

test('WpLogger respects the minimum level', function (): void {
    $logger = new HtmlPhp\WordPress\WpLogger('htmlphp', 'error');
    $before = count(HtmlPhp\WordPress\Maintenance\Logs::tail(100));
    $logger->warning('ignored-line');

    $after = HtmlPhp\WordPress\Maintenance\Logs::tail(100);
    assertSame($before, count($after));
    assertNotContains('ignored-line', implode("\n", $after));
});

group('WordPress: maintenance toolkit');

test('Nonces create, verify and render a field', function (): void {
    $nonce = HtmlPhp\WordPress\Maintenance\Nonces::create('act');

    assertSame(true, HtmlPhp\WordPress\Maintenance\Nonces::verify($nonce, 'act'));
    assertSame(false, HtmlPhp\WordPress\Maintenance\Nonces::verify($nonce, 'other'));
    assertContains('name="_wpnonce"', HtmlPhp\WordPress\Maintenance\Nonces::field('act'));
});

test('Options are prefixed and typed', function (): void {
    $options = HtmlPhp\WordPress\Maintenance\Options::class;

    assertSame(true, $options::set('flag', '1'));
    assertSame(true, $options::getBool('flag'));
    assertSame(true, array_key_exists('htmlphp_flag', (array) $GLOBALS['wp_stub_options']));

    $options::set('num', 42);
    assertSame(42, $options::getInt('num'));

    $options::set('arr', ['a' => 1]);
    assertSame(['a' => 1], $options::getArray('arr'));

    assertSame(true, $options::delete('flag'));
    assertSame(null, $options::get('flag'));
});

test('Transients remember values with a TTL', function (): void {
    $calls = 0;
    $factory = static function () use (&$calls): string {
        ++$calls;

        return 'computed';
    };

    $transients = HtmlPhp\WordPress\Maintenance\Transients::class;
    assertSame('computed', $transients::remember('job', 60, $factory));
    assertSame('computed', $transients::remember('job', 60, $factory));
    assertSame(1, $calls);

    $transients::delete('job');
    assertSame('computed', $transients::remember('job', 60, $factory));
    assertSame(2, $calls);
});

test('Notices queue one-shot admin messages', function (): void {
    $notices = HtmlPhp\WordPress\Maintenance\Notices::class;
    $notices::reset();
    $notices::add('Saved <b>ok</b>', 'success');

    assertSame(1, count($notices::queued()));

    ob_start();
    $notices::render();
    $html = (string) ob_get_clean();

    assertContains('Saved &lt;b&gt;ok&lt;/b&gt;', $html);
    assertContains('notice-success', $html);
    assertSame(0, count($notices::queued())); // one-shot
});

test('Db always prepares and exposes table helpers', function (): void {
    $db = HtmlPhp\WordPress\Maintenance\Db::class;

    assertSame("SELECT * FROM t WHERE id = '7'", $db::prepare('SELECT * FROM t WHERE id = %s', [7]));
    assertSame(true, $db::query('UPDATE t SET a = 1') !== false);

    $GLOBALS['wpdb']->var = '42';
    assertSame('42', $db::getVar('SELECT 42'));

    $GLOBALS['wpdb']->results = [['n' => 1]];
    assertSame([['n' => 1]], $db::getResults('SELECT n FROM t'));
    assertSame(true, is_array($db::tableSizes()));
});

test('Cron schedules idempotently', function (): void {
    $cron = HtmlPhp\WordPress\Maintenance\Cron::class;

    assertSame(true, $cron::schedule('htmlphp_daily', 'daily'));
    assertSame(false, $cron::schedule('htmlphp_daily', 'daily')); // already scheduled → no duplicate
    assertSame(true, $cron::next('htmlphp_daily') > 0);
    assertSame(true, $cron::unschedule('htmlphp_daily'));
    assertNull($cron::next('htmlphp_daily'));
});

test('Logs rotate and keep N files', function (): void {
    $logs = HtmlPhp\WordPress\Maintenance\Logs::class;
    $logs::write('info', 'first');

    assertSame(true, is_file($logs::file()));
    assertSame(true, count($logs::tail(5)) >= 1);
    assertSame(true, $logs::rotate(14) >= 0);
});

test('Assets register with filemtime cache busting', function (): void {
    $path = fixture_write('assets/app.css', 'body{}');
    HtmlPhp\WordPress\Maintenance\Assets::style('htmlphp-test', $path);
    HtmlPhp\WordPress\Maintenance\Assets::script('htmlphp-test-js', $path);

    $registered = $GLOBALS['wp_stub_assets']['htmlphp-test'] ?? null;
    assertSame(true, $registered !== null);
    assertSame((string) filemtime($path), (string) $registered['ver']);
    assertSame(true, isset($GLOBALS['wp_stub_assets']['htmlphp-test-js']));
});

test('Rest routes always carry a permission callback', function (): void {
    HtmlPhp\WordPress\Maintenance\Rest::route(
        'htmlphp/v1',
        '/guarded',
        static fn(): array => HtmlPhp\WordPress\Maintenance\Rest::ok(['x' => 1]),
    );

    $registration = $GLOBALS['wp_stub_rest']['htmlphp/v1/guarded'] ?? null;
    assertSame(true, is_array($registration));
    assertSame(true, is_callable($registration['permission_callback'] ?? null)); // never registered without one

    assertSame(['success' => true, 'data' => ['x' => 1]], HtmlPhp\WordPress\Maintenance\Rest::ok(['x' => 1]));
});

test('SystemInfo and Health include engine state', function (): void {
    $engine = wp_engine();
    $engine->renderString('%{gone}', []); // produce a diagnostic

    $info = HtmlPhp\WordPress\Maintenance\SystemInfo::collect($engine);
    assertSame(true, str_contains((string) $info['engine']['version'], \HtmlPhp\Version::VERSION));
    assertSame(1, $info['engine']['pending_errors']);
    assertSame(true, isset($info['wordpress'], $info['php'], $info['server']));

    $findings = HtmlPhp\WordPress\Maintenance\Health::check($engine);
    assertSame(true, count($findings) >= 1);

    $codes = array_column($findings, 'code');
    assertSame(true, in_array('engine-errors', $codes, true));
});

test('Updates reads the core/plugin/theme transients', function (): void {
    set_site_transient('update_core', (object) ['updates' => [(object) ['response' => 'upgrade', 'version' => '9.9']]]);
    set_site_transient('update_plugins', (object) ['response' => ['a/a.php' => (object) ['new_version' => '2.0', 'slug' => 'a']]]);
    set_site_transient('update_themes', (object) ['response' => ['t' => ['new_version' => '3.0']]]);

    $updates = HtmlPhp\WordPress\Maintenance\Updates::check();

    assertSame(1, count($updates['core']));
    assertSame('9.9', $updates['core'][0]['version']);
    assertSame(1, count($updates['plugins']));
    assertSame(1, count($updates['themes']));
    assertSame(3, HtmlPhp\WordPress\Maintenance\Updates::count());
});

group('WordPress: Mailer, AdminPage, CLI');

test('Mailer renders subject and body through the engine', function (): void {
    fixture_write('tpl/mail/subject.txt', 'Hi %{name}');
    fixture_write('tpl/mail/body.html', '<p>Hello %{name|escape}</p>');
    $engine = wp_engine();

    assertSame(true, Mailer::send($engine, 'to@example.org', 'mail/subject.txt', 'mail/body.html', ['name' => '<Bob>']));

    $mail = $GLOBALS['wp_stub_mail'][0] ?? null;
    assertSame(true, $mail !== null);
    assertSame('Hi <Bob>', $mail['subject']);
    assertSame('<p>Hello &lt;Bob&gt;</p>', $mail['message']);
    assertSame(['to@example.org'], $mail['to']);
    assertContains('Content-Type: text/html; charset=UTF-8', implode('|', $mail['headers']));
});

test('Mailer accepts inline templates and keeps explicit headers', function (): void {
    $engine = wp_engine();

    Mailer::send($engine, 'a@b.c', '@{mail.subject}', 'Body: %{v}', ['v' => 1], ['Content-Type: text/plain']);

    $mail = $GLOBALS['wp_stub_mail'][0];
    assertSame('mail.subject', $mail['subject']); // untranslated key falls back to itself
    assertSame('Body: 1', $mail['message']);
    assertSame(['Content-Type: text/plain'], $mail['headers']);
});

test('AdminPage installs under Tools and renders every tab', function (): void {
    $engine = wp_engine();
    AdminPage::install($engine);

    assertSame(true, isset($GLOBALS['wp_stub_admin_pages']['htmlphp']));

    foreach (['health', 'templates', 'cache', 'i18n', 'logs', 'info'] as $tab) {
        $_GET['tab'] = $tab;
        $html = (string) ($GLOBALS['wp_stub_admin_pages']['htmlphp']['cb'])();

        assertContains('HTML.PHP', $html);
        assertSame(false, str_contains($html, 'Fatal'));
    }

    $_GET['tab'] = '../../../etc/passwd';
    assertContains('Health', (string) ($GLOBALS['wp_stub_admin_pages']['htmlphp']['cb'])()); // falls back to a known tab
});

test('AdminPage prefers an engine-rendered view when the theme ships one', function (): void {
    fixture_write('wp-theme/htmlphp/admin/htmlphp/health.html', 'CUSTOM:%{tab}');
    $engine = wp_engine();
    AdminPage::install($engine);
    $_GET['tab'] = 'health';

    assertSame('CUSTOM:health', (string) ($GLOBALS['wp_stub_admin_pages']['htmlphp']['cb'])());
});

test('AdminPage POST actions are nonce- and capability-protected', function (): void {
    $engine = wp_engine();

    $_POST = ['htmlphp_action' => 'cache_clear', 'tab' => 'cache', '_wpnonce' => 'bogus'];
    $_REQUEST = $_POST;

    try {
        AdminPage::handlePost($engine);
        fail('a bad nonce must redirect');
    } catch (WpStubRedirect $redirect) {
        assertContains('error', $redirect->getMessage());
    }

    $_POST = [
        'htmlphp_action' => 'cache_clear',
        'tab' => 'cache',
        '_wpnonce' => HtmlPhp\WordPress\Maintenance\Nonces::create(AdminPage::NONCE_ACTION),
    ];
    $_REQUEST = $_POST;

    try {
        AdminPage::handlePost($engine);
        fail('a good nonce must redirect to the page');
    } catch (WpStubRedirect $redirect) {
        assertContains('tab=cache', $redirect->getMessage());
    }
});

test('CLI info prints engine, PHP and WordPress sections', function (): void {
    $engine = wp_engine();
    $GLOBALS['wp_stub_cli_log'] = [];

    (new Cli())->info();

    $log = implode("\n", $GLOBALS['wp_stub_cli_log']);
    assertContains('== ENGINE ==', $log);
    assertContains(\HtmlPhp\Version::VERSION, $log);
    assertContains('== WORDPRESS ==', $log);
});

test('CLI lint reports clean trees and errors with a failing status', function (): void {
    fixture_write('tpl/cli/ok.html', 'fine %{x}');
    fixture_write('tpl/cli/bad.html', '<!--@Nope-->');
    $engine = wp_engine();
    $cli = new Cli();

    $GLOBALS['wp_stub_cli_log'] = [];
    $cli->lint([fixture_path('tpl/cli/ok.html')]);
    assertContains('Lint clean', implode("\n", $GLOBALS['wp_stub_cli_log']));

    $GLOBALS['wp_stub_cli_log'] = [];
    $cli->lint([fixture_path('tpl/cli')]);
    $log = implode("\n", $GLOBALS['wp_stub_cli_log']);
    assertContains('unknown-tag', $log);
    assertContains('Lint found errors', $log);
});

test('CLI cache clear empties the WordPress cache backend', function (): void {
    $engine = wp_engine();
    $engine->renderString('x', [], new HtmlPhp\Template\RenderOptions(cache: true, ttl: 60));

    $GLOBALS['wp_stub_cli_log'] = [];
    (new Cli())->cache(['clear']);

    assertContains('Removed', implode("\n", $GLOBALS['wp_stub_cli_log']));
});

test('CLI i18n extract writes a POT and audit reports missing messages', function (): void {
    fixture_write('tpl/i18n/page.html', '<h1>@{page.title}</h1><!--@Trans count="%{n}"-->%{n} item<!--@Plural-->%{n} items<!--@EndTrans-->');
    $engine = wp_engine();
    $cli = new Cli();

    $out = fixture_path('out.pot');
    $GLOBALS['wp_stub_cli_log'] = [];
    $cli->i18n(['extract', fixture_path('tpl/i18n')], ['out' => $out]);

    assertSame(true, is_file($out));
    $pot = (string) file_get_contents($out);
    assertContains('msgid "page.title"', $pot);
    assertContains('msgid "{0} item"', $pot);
    assertContains('msgid_plural "{0} items"', $pot);

    $GLOBALS['wp_stub_cli_log'] = [];
    $cli->i18n(['audit', fixture_path('tpl/i18n')], ['locale' => 'de']);
    $log = implode("\n", $GLOBALS['wp_stub_cli_log']);
    assertContains('0/2 messages translated', $log);
    assertContains('page.title', $log);
});

test('CLI health lists findings', function (): void {
    $engine = wp_engine();
    $GLOBALS['wp_stub_cli_log'] = [];
    (new Cli())->health();

    assertSame(true, count($GLOBALS['wp_stub_cli_log']) >= 1);
});

test('CLI render outputs a located template', function (): void {
    fixture_write('wp-theme/cli.html', 'C:%{v}');
    wp_engine();

    $GLOBALS['wp_stub_cli_log'] = [];
    (new Cli())->render(['cli.html'], ['var' => ['v=9']]);

    assertContains('C:9', implode("\n", $GLOBALS['wp_stub_cli_log']));
});

group('WordPress: plugin file');

test('the plugin loads the library and registers its hooks', function (): void {
    Bridge::reset();
    $pluginFile = dirname(__DIR__, 2) . '/wordpress/html-php/html-php.php';

    assertSame(true, is_file($pluginFile));

    $source = (string) file_get_contents($pluginFile);
    assertContains('Plugin Name:', $source);
    assertContains('Requires PHP:      8.3', $source);

    // The loader picks the edition matching the running PHP.
    require_once $pluginFile;

    assertSame(true, function_exists('htmlphp_render'));
    assertSame(true, function_exists('htmlphp_t'));
    assertSame(true, defined('HTMLPHP_VERSION'));
    assertSame(\HtmlPhp\Version::VERSION, HTMLPHP_VERSION);

    // In the test suite the library is already autoloaded, so the boot runs;
    // in a bare install without lib/ or vendor/ an admin notice is queued.
    do_action('plugins_loaded');

    if (Bridge::booted()) {
        assertSame(true, function_exists('htmlphp_render'));
        assertContains('missing-template-', htmlphp_render('missing-template-%{x}'));
        assertSame('nope', htmlphp_t('nope'));
    } else {
        assertSame(true, has_action('admin_notices'));
    }
});

test('uninstall removes options and the cache directory', function (): void {
    if (!defined('WP_UNINSTALL_PLUGIN')) {
        define('WP_UNINSTALL_PLUGIN', 'html-php/html-php.php');
    }

    update_option('htmlphp_content_ssi', true);
    HtmlPhp\WordPress\Maintenance\Options::set('cache_keys', ['a']);
    $cacheDir = fixture_path('wp-uploads') . '/htmlphp-cache';
    @mkdir($cacheDir, 0o777, true);
    file_put_contents($cacheDir . '/x.cache', 'x');

    require dirname(__DIR__, 2) . '/wordpress/html-php/uninstall.php';

    assertSame(false, get_option('htmlphp_content_ssi'));
    assertSame(false, is_dir($cacheDir));
});
