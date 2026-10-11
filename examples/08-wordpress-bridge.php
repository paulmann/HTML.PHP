<?php
/**
 * 08 - WordPress bridge & maintenance toolkit (simulated environment).
 *
 * This example runs OUTSIDE WordPress against the same minimal API stubs
 * the test suite uses, so you can see exactly what the bridge does:
 * esc_html escaping, translation delegation, template rendering with
 * WordPress context variables, shortcodes, notices, health checks and the
 * system-information snapshot used by the bundled maintenance plugin.
 *
 * Run:  php examples/08-wordpress-bridge.php
 */

declare(strict_types=1);

// The stubs emulate just enough WordPress for the demo (they are part of
// the test harness and safe to reuse here).
require_once dirname(__DIR__) . '/tests/TestRunner.php';
require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/tests/wp-stubs.php';
require_once dirname(__DIR__) . '/wordpress/html-wp.php';

use HtmlPhp\WordPress\Maintenance\Health;
use HtmlPhp\WordPress\Maintenance\Nonces;
use HtmlPhp\WordPress\Maintenance\Notices;
use HtmlPhp\WordPress\Maintenance\SystemInfo;
use HtmlPhp\WordPress\TemplateLoader;

echo "=== 08 / WordPress bridge (simulated) ===\n\n";

// ---------------------------------------------------------------------------
// 1. Boot: escaping + translations + locale sync.
// ---------------------------------------------------------------------------
$GLOBALS['wp_stub_translations']['demo']['Read more'] = 'Weiterlesen';
$GLOBALS['wp_stub_locale'] = 'de_DE';

html_wp_boot([
    'shortcodes' => [
        'cta' => fixture_write('demo/cta.htm',
            '<a class="cta" href="%{url|attr}">%{label|escape}</a>'),
    ],
]);

echo 'booted in WP mode : ', var_export(html_wp_is_wordpress(), true), "\n";
echo 'locale synced     : ', html_get_locale(), "\n";
echo 'escaper installed : ', ($GLOBALS['HTML_ESCAPER'] ?? 'native'), "\n";
echo 'WP translation    : ', html_t('Read more', [], 'demo'), "\n\n";

// ---------------------------------------------------------------------------
// 2. Template rendering with automatic WordPress context variables.
// ---------------------------------------------------------------------------
$page = fixture_write('demo/page.htm', <<<'HTML'
<h1>%{heading|escape}</h1>
<p>Site: %{wp_blogname} · Locale: %{wp_locale} · Year: %{wp_year}</p>
<ul>
<!--@Loop menu--><li>%{title|escape} → %{wp_home_url}%{slug|slug}</li>
<!--@EndLoop-->
</ul>
HTML);

echo TemplateLoader::render($page, [
    'heading' => 'Bridge <demo>',
    'menu' => [
        ['title' => 'Home & More', 'slug' => 'Home & More'],
        ['title' => 'Kontakt', 'slug' => 'Kontakt Seite'],
    ],
], ['escape' => true, 'passes' => 1]), "\n\n";

// ---------------------------------------------------------------------------
// 3. Shortcodes.
// ---------------------------------------------------------------------------
echo 'shortcode: ', do_shortcode('[cta url="https://example.com/?a=1&b=2" label="Click <here>"]'), "\n\n";

// ---------------------------------------------------------------------------
// 4. Nonces + admin notices.
// ---------------------------------------------------------------------------
$nonce = Nonces::create('demo_action');

echo 'nonce valid   : ', var_export(Nonces::verify($nonce, 'demo_action'), true), "\n";
echo 'nonce invalid : ', var_export(Nonces::verify($nonce, 'other'), true), "\n";

html_wp_notice('Demo maintenance run finished.', 'success');
echo 'queued notices: ', count(Notices::queued()), "\n\n";

// ---------------------------------------------------------------------------
// 5. Health check + system snapshot (the maintenance plugin dashboard data).
// ---------------------------------------------------------------------------
echo "--- health ---\n";
foreach (Health::check() as $issue) {
    printf("  [%-8s] %-16s %s\n", $issue['severity'], $issue['code'], $issue['message']);
}

$info = SystemInfo::collect();

echo "\n--- system snapshot (excerpt) ---\n";
printf("  WordPress : %s (multisite: %s)\n",
    $info['wordpress']['version'],
    var_export($info['wordpress']['multisite'], true));
printf("  PHP       : %s (%s)\n", $info['php']['version'], $info['php']['sapi']);
printf("  Theme     : %s\n", $info['wordpress']['active_theme']);
printf("  Engine    : %s\n", $info['engine']['version']);

cleanup_fixtures();

echo "\nDone.\n";
