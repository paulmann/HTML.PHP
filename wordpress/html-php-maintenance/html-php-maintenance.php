<?php
/**
 * Plugin Name:       HTML.PHP Maintenance
 * Plugin URI:        https://github.com/paulmann/HTML.PHP
 * Description:       Template engine dashboard for site maintenance: health checks, system information, update overview, render-cache control and a template tester — powered by the HTML.PHP engine (SSI + i18n + loops).
 * Version:           3.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.3
 * Author:            Mikhail Deynekin
 * Author URI:        https://Deynekin.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       htmlphp-maintenance
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

require_once dirname(__DIR__) . '/html-wp.php';

use HtmlPhp\WordPress\Bridge;
use HtmlPhp\WordPress\Maintenance\Cron;
use HtmlPhp\WordPress\Maintenance\Health;
use HtmlPhp\WordPress\Maintenance\Logs;
use HtmlPhp\WordPress\Maintenance\Notices;
use HtmlPhp\WordPress\Maintenance\SystemInfo;
use HtmlPhp\WordPress\Maintenance\Transients;
use HtmlPhp\WordPress\Maintenance\Updates;

/**
 * Plugin bootstrap: bridge, admin page, cron health check, CLI.
 */
final class HtmlPhp_Maintenance_Plugin
{
    public const string VERSION = '3.0.0';
    public const string CAPABILITY = 'manage_options';
    public const string MENU_SLUG = 'htmlphp-maintenance';
    public const string CRON_HOOK = 'htmlphp_daily_health';
    public const string NONCE_ACTION = 'htmlphp_maintenance';

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * Registers every hook. Called on plugins_loaded.
     */
    public function register(): void
    {
        Bridge::boot([
            'cli' => true,
            'notices' => true,
            'config' => [
                'HTML_CACHE_DIR' => $this->cacheDirectory(),
                'HTML_CACHE_TTL' => 300,
            ],
        ]);

        add_action('admin_menu', [$this, 'adminMenu']);
        add_action('admin_post_htmlphp_maintenance', [$this, 'handlePost']);
        add_action(self::CRON_HOOK, [$this, 'dailyHealth']);
        add_action('wp_dashboard_setup', [$this, 'dashboardWidget']);

        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function activate(): void
    {
        Cron::schedule(self::CRON_HOOK, 'daily');
        Logs::info('HTML.PHP Maintenance activated.', ['version' => self::VERSION]);
        Notices::add('HTML.PHP Maintenance activated — see Tools → HTML.PHP.', 'success');
    }

    public function deactivate(): void
    {
        Cron::unschedule(self::CRON_HOOK);
        Logs::info('HTML.PHP Maintenance deactivated.');
    }

    /**
     * Daily cron: run the health check, queue a notice on findings.
     */
    public function dailyHealth(): void
    {
        $issues = Health::check();
        $critical = array_values(array_filter(
            $issues,
            static fn(array $issue): bool => $issue['severity'] === 'critical'
        ));

        Transients::set('last_health', $issues, DAY_IN_SECONDS);

        if ($critical !== []) {
            Notices::add(
                sprintf('HTML.PHP health check found %d critical issue(s). See Tools → HTML.PHP.', count($critical)),
                'error'
            );
            Logs::error('Daily health check found critical issues.', ['count' => count($critical)]);
        }
    }

    public function adminMenu(): void
    {
        $hook = function_exists('add_management_page')
            ? add_management_page(
                'HTML.PHP Maintenance',
                'HTML.PHP',
                self::CAPABILITY,
                self::MENU_SLUG,
                [$this, 'renderPage']
            )
            : null;

        if (is_string($hook) && function_exists('add_action')) {
            add_action('load-' . $hook, [$this, 'handlePostInline']);
        }
    }

    public function dashboardWidget(): void
    {
        if (!function_exists('wp_add_dashboard_widget') || !current_user_can(self::CAPABILITY)) {
            return;
        }

        wp_add_dashboard_widget(
            'htmlphp_health',
            'HTML.PHP Health',
            function (): void {
                $issues = Transients::get('last_health', null);

                if (!is_array($issues)) {
                    $issues = Health::check();
                }

                echo '<ul style="margin:0">';

                foreach (array_slice($issues, 0, 5) as $issue) {
                    printf(
                        '<li><strong>%s</strong> %s</li>',
                        esc_html((string) $issue['severity']),
                        esc_html((string) $issue['message'])
                    );
                }

                echo '</ul>';
            }
        );
    }

    /**
     * Handles the page's own POST actions (admin-post.php flow).
     */
    public function handlePost(): void
    {
        $this->processAction();

        wp_safe_redirect(admin_url('tools.php?page=' . self::MENU_SLUG));
        exit;
    }

    /**
     * Handles POST actions submitted to the page itself.
     */
    public function handlePostInline(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $this->processAction();

        wp_safe_redirect(admin_url('tools.php?page=' . self::MENU_SLUG));
        exit;
    }

    private function processAction(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Insufficient permissions.', 'Forbidden', ['response' => 403]);
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';

        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die('Security check failed.', 'Forbidden', ['response' => 403]);
        }

        $action = isset($_POST['htmlphp_action']) ? sanitize_key((string) $_POST['htmlphp_action']) : '';

        match ($action) {
            'flush_cache' => $this->flushCache(),
            'write_test_log' => $this->writeTestLog(),
            'run_health' => $this->runHealthNow(),
            default => Notices::add('Unknown maintenance action.', 'warning'),
        };
    }

    private function flushCache(): void
    {
        $removed = html_cache_clear();
        Notices::add(sprintf('Render cache flushed (%d entries removed).', $removed), 'success');
        Logs::info('Render cache flushed manually.', ['removed' => $removed]);
    }

    private function writeTestLog(): void
    {
        $written = Logs::write('info', 'Test entry written from the maintenance page.');
        Notices::add(
            $written ? 'Test log entry written: ' . Logs::file() : 'The log directory is not writable.',
            $written ? 'success' : 'error'
        );
    }

    private function runHealthNow(): void
    {
        $issues = Health::check();
        Transients::set('last_health', $issues, DAY_IN_SECONDS);
        Notices::add(sprintf('Health check finished: %d finding(s).', count($issues)), 'success');
    }

    private function cacheDirectory(): string
    {
        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
        $base = is_array($uploads) ? (string) ($uploads['basedir'] ?? '') : '';

        if ($base === '') {
            return '';
        }

        $directory = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'html-php-cache';

        if (!is_dir($directory)) {
            @mkdir($directory, 0o755, true);
        }

        return is_dir($directory) ? $directory : '';
    }

    /**
     * Renders the tools page through the HTML.PHP engine itself — the page is
     * a live demonstration of SSI, loops, conditionals and escaping.
     */
    public function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Insufficient permissions.', 'Forbidden', ['response' => 403]);
        }

        $info = SystemInfo::collect();
        $health = Transients::get('last_health', null);

        if (!is_array($health)) {
            $health = Health::check();
        }

        $updates = Updates::check();
        $updateRows = [];

        foreach ($updates['core'] as $core) {
            $updateRows[] = ['type' => 'core', 'name' => 'WordPress', 'version' => (string) $core['version']];
        }

        foreach ($updates['plugins'] as $plugin) {
            $updateRows[] = ['type' => 'plugin', 'name' => (string) $plugin['file'], 'version' => (string) $plugin['new_version']];
        }

        foreach ($updates['themes'] as $theme) {
            $updateRows[] = ['type' => 'theme', 'name' => (string) $theme['slug'], 'version' => (string) $theme['new_version']];
        }

        $infoRows = [];

        foreach (['wordpress', 'php', 'server', 'engine'] as $section) {
            foreach ((array) ($info[$section] ?? []) as $key => $value) {
                if (is_array($value)) {
                    $value = (string) json_encode($value, JSON_UNESCAPED_SLASHES);
                }

                $infoRows[] = [
                    'section' => $section,
                    'key' => (string) $key,
                    'value' => is_scalar($value) ? (string) $value : '',
                ];
            }
        }

        $template = __DIR__ . '/views/admin.ssi.htm';

        echo HtmlPhp\WordPress\TemplateLoader::render(
            $template,
            [
                'title' => 'HTML.PHP Maintenance',
                'version' => self::VERSION,
                'engine_version' => html_version(),
                'nonce_field' => wp_nonce_field(self::NONCE_ACTION, '_wpnonce', true, false),
                'action_url' => admin_url('tools.php?page=' . self::MENU_SLUG),
                'HEALTH' => $health,
                'INFO' => $infoRows,
                'UPDATES' => $updateRows,
                'HAS_UPDATES' => $updateRows !== [] ? '1' : '',
                'log_tail' => implode("\n", Logs::tail(10)),
            ],
            ['escape' => true, 'file' => $template, 'passes' => 1]
        );
    }
}

add_action('plugins_loaded', static function (): void {
    HtmlPhp_Maintenance_Plugin::instance()->register();
});
