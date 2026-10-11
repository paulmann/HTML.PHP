<?php
/**
 * Plugin Name:       HTML.PHP
 * Plugin URI:        https://github.com/paulmann/HTML.PHP
 * Description:       Template engine + Apache SSI + gettext i18n for WordPress. Shortcodes, theme template loader, maintenance tools, WP-CLI.
 * Version:           5.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.3
 * Author:            Mikhail Deynekin
 * Author URI:        https://Deynekin.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       html-php
 * Domain Path:       /languages
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('HTMLPHP_PLUGIN_FILE', __FILE__);
define('HTMLPHP_PLUGIN_DIR', __DIR__);
define('HTMLPHP_VERSION', '5.0.0');

/**
 * Loads the library: Composer autoloader when present, otherwise the
 * single-file bundle matching the running PHP version.
 */
function htmlphp_load_library(): bool
{
    $autoload = __DIR__ . '/vendor/autoload.php';

    if (is_file($autoload)) {
        require_once $autoload;

        return class_exists(\HtmlPhp\Engine::class);
    }

    $bundle = PHP_VERSION_ID >= 80500
        ? __DIR__ . '/lib/php85/html.php'
        : __DIR__ . '/lib/php83/html.php';

    if (is_file($bundle)) {
        require_once $bundle;

        return class_exists(\HtmlPhp\Engine::class);
    }

    // A host (mu-plugin, theme vendor dir) may have loaded the library already.
    return class_exists(\HtmlPhp\Engine::class);
}

/**
 * Renders a template through the plugin's engine.
 *
 * The one documented convenience global for theme developers:
 * `echo htmlphp_render('card.html', ['post' => get_post()]);`
 */
function htmlphp_render(string $template, array $vars = []): string
{
    try {
        $engine = \HtmlPhp\WordPress\Bridge::engine();
        $path = \HtmlPhp\WordPress\TemplateLoader::locate($template);

        return $path !== null
            ? $engine->renderFile($path, $vars)
            : $engine->renderString($template, $vars);
    } catch (\Throwable $error) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[htmlphp] ' . $error->getMessage());
        }

        return '';
    }
}

/** Translates through the plugin's engine i18n layer. */
function htmlphp_t(string $msgid, array $args = []): string
{
    try {
        return \HtmlPhp\WordPress\Bridge::engine()->t($msgid, $args, 'html-php');
    } catch (\Throwable) {
        return $msgid;
    }
}

add_action('plugins_loaded', static function (): void {
    if (!htmlphp_load_library()) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p><strong>HTML.PHP:</strong> library not found. '
                . 'Run <code>composer install</code> in the plugin directory or copy <code>php83/html.php</code> '
                . '(or <code>php85/html.php</code> on PHP 8.5+) into <code>lib/</code>.</p></div>';
        });

        return;
    }

    \HtmlPhp\WordPress\Bridge::boot([
        'i18n' => ['domain' => 'html-php'],
        // views/ ships the engine-rendered admin screens; templates/ is for
        // site-level partials shipped with the plugin.
        'templates' => ['paths' => [__DIR__ . '/templates', __DIR__ . '/views']],
    ], null, [
        'shortcode' => 'htmlphp',
        'content_ssi' => (bool) get_option('htmlphp_content_ssi', false),
        'admin_page' => true,
        'cli' => true,
    ]);

    load_plugin_textdomain('html-php', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

register_activation_hook(__FILE__, static function (): void {
    add_option('htmlphp_content_ssi', false);

    if (function_exists('wp_upload_dir')) {
        $uploads = wp_upload_dir();
        $dir = (string) ($uploads['basedir'] ?? '') . '/htmlphp-cache';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($dir . '/index.html', '');
    }
});

register_deactivation_hook(__FILE__, static function (): void {
    if (class_exists(\HtmlPhp\WordPress\Bridge::class) && \HtmlPhp\WordPress\Bridge::booted()) {
        \HtmlPhp\WordPress\Bridge::engine()->clearCache();
    }
});
