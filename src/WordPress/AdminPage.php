<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress;

use HtmlPhp\Engine;
use HtmlPhp\I18n\Auditor;
use HtmlPhp\I18n\Extractor;
use HtmlPhp\WordPress\Maintenance\Health;
use HtmlPhp\WordPress\Maintenance\Logs;
use HtmlPhp\WordPress\Maintenance\Nonces;
use HtmlPhp\WordPress\Maintenance\SystemInfo;

/**
 * Tools → “HTML.PHP” admin screen.
 *
 * Tabs: Health, Templates (lint), Cache, i18n (extract/audit), Logs, Info.
 * When a template named `admin/htmlphp/{tab}.html` exists in any configured
 * template path it is rendered through the engine itself (dog-fooding);
 * otherwise a minimal built-in markup is produced. All mutating actions go
 * through `admin_post_htmlphp_action` with a nonce and `manage_options`.
 */
final class AdminPage
{
    public const string SLUG = 'htmlphp';
    public const string NONCE_ACTION = 'htmlphp_admin';

    private static ?Engine $engine = null;

    private function __construct()
    {
    }

    public static function install(Engine $engine): void
    {
        self::$engine = $engine;

        if (!function_exists('add_management_page')) {
            return;
        }

        add_management_page(
            'HTML.PHP',
            'HTML.PHP',
            'manage_options',
            self::SLUG,
            static fn(): string => self::renderPage()
        );
    }

    /** POST endpoint (admin_post_htmlphp_action). */
    public static function handlePost(Engine $engine): void
    {
        self::$engine = $engine;

        if (!Nonces::verifyRequest(self::NONCE_ACTION) || !self::capable()) {
            self::redirect('error');

            return;
        }

        $action = (string) ($_POST['htmlphp_action'] ?? '');

        switch ($action) {
            case 'cache_clear':
                $engine->clearCache();
                Maintenance\Notices::add('HTML.PHP render cache cleared.', 'success');
                break;

            case 'log_rotate':
                Maintenance\Logs::rotate(7);
                Maintenance\Notices::add('HTML.PHP logs rotated.', 'success');
                break;
        }

        self::redirect('done', ['tab' => (string) ($_POST['tab'] ?? 'health')]);
    }

    private static function renderPage(): string
    {
        $engine = self::$engine ?? Bridge::engine();

        if (!self::capable()) {
            return '<p>Insufficient permissions.</p>';
        }

        $tab = self::currentTab();

        // Dog-fooding: prefer an engine template when the site provides one.
        $template = $engine->locator()->locate('admin/htmlphp/' . $tab . '.html');

        if ($template !== null) {
            try {
                return $engine->renderFile($template, [
                    'tab' => $tab,
                    'tabs' => self::tabs(),
                    'data' => self::tabData($engine, $tab),
                    'nonce_field' => Nonces::field(self::NONCE_ACTION),
                    'admin_post_url' => function_exists('admin_url') ? admin_url('admin-post.php') : '',
                    'page_url' => function_exists('admin_url') ? admin_url('tools.php?page=' . self::SLUG) : '',
                ]);
            } catch (\Throwable) {
                // fall through to the built-in markup
            }
        }

        return self::builtinPage($engine, $tab);
    }

    /** @return list<array{id: string, label: string}> */
    public static function tabs(): array
    {
        return [
            ['id' => 'health', 'label' => 'Health'],
            ['id' => 'templates', 'label' => 'Templates'],
            ['id' => 'cache', 'label' => 'Cache'],
            ['id' => 'i18n', 'label' => 'i18n'],
            ['id' => 'logs', 'label' => 'Logs'],
            ['id' => 'info', 'label' => 'Info'],
        ];
    }

    private static function currentTab(): string
    {
        $tab = isset($_GET['tab']) && is_scalar($_GET['tab']) ? (string) $_GET['tab'] : 'health';

        foreach (self::tabs() as $entry) {
            if ($entry['id'] === $tab) {
                return $tab;
            }
        }

        return 'health';
    }

    /** @return array<string, mixed> */
    public static function tabData(Engine $engine, string $tab): array
    {
        return match ($tab) {
            'health' => ['findings' => Health::check($engine)],
            'templates' => ['lint' => self::lintReport($engine), 'roots' => $engine->config()->templates->paths],
            'cache' => [
                'driver' => $engine->config()->cache->driver->value,
                'enabled' => $engine->config()->cache->enabled,
            ],
            'i18n' => [
                'locale' => $engine->locale(),
                'catalogues' => $engine->translator() instanceof \HtmlPhp\I18n\Translator
                    ? $engine->translator()->stats()
                    : [],
            ],
            'logs' => ['tail' => Logs::tail(30), 'file' => Logs::file()],
            'info' => ['info' => SystemInfo::collect($engine), 'version' => $engine->version()],
            default => [],
        };
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function lintReport(Engine $engine): array
    {
        $report = [];

        foreach ($engine->config()->templates->paths as $root) {
            if (!is_dir($root)) {
                continue;
            }

            foreach ($engine->lintDirectory($root) as $file => $issues) {
                $report[$file] = array_map(
                    static fn(\HtmlPhp\Template\LintIssue $issue): array => $issue->toArray(),
                    $issues
                );
            }
        }

        return $report;
    }

    private static function builtinPage(Engine $engine, string $tab): string
    {
        $esc = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = function_exists('admin_url') ? admin_url('tools.php?page=' . self::SLUG) : '';

        $nav = '';
        foreach (self::tabs() as $entry) {
            $active = $entry['id'] === $tab ? ' class="current"' : '';
            $nav .= sprintf(
                '<a href="%s&amp;tab=%s"%s>%s</a> | ',
                $esc($url),
                $esc($entry['id']),
                $active,
                $esc($entry['label'])
            );
        }

        $body = match ($tab) {
            'health' => self::healthHtml($engine),
            'templates' => self::templatesHtml($engine),
            'cache' => self::cacheHtml($engine),
            'i18n' => self::i18nHtml($engine),
            'logs' => self::logsHtml(),
            default => self::infoHtml($engine),
        };

        return '<div class="wrap"><h1>HTML.PHP ' . $esc(\HtmlPhp\Version::VERSION) . '</h1>'
            . '<p>' . rtrim($nav, ' | ') . '</p>'
            . $body
            . '</div>';
    }

    private static function healthHtml(Engine $engine): string
    {
        $rows = '';

        foreach (Health::check($engine) as $finding) {
            $rows .= sprintf(
                '<li><strong>%s</strong> [%s] %s</li>',
                htmlspecialchars((string) ($finding['code'] ?? ''), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) ($finding['severity'] ?? ''), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) ($finding['message'] ?? ''), ENT_QUOTES, 'UTF-8')
            );
        }

        return '<h2>Health</h2><ul>' . $rows . '</ul>';
    }

    private static function templatesHtml(Engine $engine): string
    {
        $report = self::lintReport($engine);

        if ($report === []) {
            return '<h2>Templates</h2><p>All template roots lint clean.</p>';
        }

        $out = '<h2>Templates</h2><ul>';

        foreach ($report as $file => $issues) {
            $out .= '<li><code>' . htmlspecialchars((string) $file, ENT_QUOTES, 'UTF-8') . '</code><ul>';

            foreach ($issues as $issue) {
                $out .= sprintf(
                    '<li>[%s] line %d: %s (%s)</li>',
                    htmlspecialchars((string) ($issue['severity'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    (int) ($issue['line'] ?? 0),
                    htmlspecialchars((string) ($issue['message'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars((string) ($issue['code'] ?? ''), ENT_QUOTES, 'UTF-8')
                );
            }

            $out .= '</ul></li>';
        }

        return $out . '</ul>';
    }

    private static function cacheHtml(Engine $engine): string
    {
        $form = self::postForm('cache_clear', 'Clear render cache', 'cache');

        return '<h2>Cache</h2><p>Driver: <code>'
            . htmlspecialchars($engine->config()->cache->driver->value, ENT_QUOTES, 'UTF-8')
            . '</code> (' . ($engine->config()->cache->enabled ? 'enabled' : 'disabled') . ')</p>' . $form;
    }

    private static function i18nHtml(Engine $engine): string
    {
        $stats = $engine->translator() instanceof \HtmlPhp\I18n\Translator
            ? $engine->translator()->stats()
            : [];

        $rows = '';

        foreach ($stats as $domain => $locales) {
            foreach ($locales as $locale => $count) {
                $rows .= sprintf(
                    '<li><code>%s/%s</code>: %d message(s)</li>',
                    htmlspecialchars((string) $domain, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars((string) $locale, ENT_QUOTES, 'UTF-8'),
                    (int) $count
                );
            }
        }

        return '<h2>i18n</h2><p>Locale: <code>' . htmlspecialchars($engine->locale(), ENT_QUOTES, 'UTF-8')
            . '</code></p><ul>' . ($rows !== '' ? $rows : '<li>No catalogues loaded.</li>') . '</ul>'
            . '<p>Run <code>wp htmlphp i18n audit &lt;dir&gt;</code> for missing/unused analysis.</p>';
    }

    private static function logsHtml(): string
    {
        $tail = Logs::tail(30);
        $lines = $tail === [] ? '<li>No log entries.</li>' : '';

        foreach ($tail as $line) {
            $lines .= '<li><code>' . htmlspecialchars((string) $line, ENT_QUOTES, 'UTF-8') . '</code></li>';
        }

        return '<h2>Logs</h2><ul>' . $lines . '</ul>' . self::postForm('log_rotate', 'Rotate logs (keep 7)', 'logs');
    }

    private static function infoHtml(Engine $engine): string
    {
        $info = SystemInfo::collect($engine);
        $out = '<h2>Info</h2>';

        foreach ($info as $section => $values) {
            $out .= '<h3>' . htmlspecialchars((string) $section, ENT_QUOTES, 'UTF-8') . '</h3><ul>';

            foreach ((array) $values as $key => $value) {
                $out .= sprintf(
                    '<li><strong>%s</strong>: <code>%s</code></li>',
                    htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars(is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value), ENT_QUOTES, 'UTF-8')
                );
            }

            $out .= '</ul>';
        }

        return $out;
    }

    private static function postForm(string $action, string $label, string $tab): string
    {
        $url = function_exists('admin_url') ? admin_url('admin-post.php') : '';

        return '<form method="post" action="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="action" value="htmlphp_action">'
            . '<input type="hidden" name="htmlphp_action" value="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="tab" value="' . htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') . '">'
            . Nonces::field(self::NONCE_ACTION)
            . '<button class="button">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>'
            . '</form>';
    }

    private static function capable(): bool
    {
        return !function_exists('current_user_can') || current_user_can('manage_options');
    }

    /** @param array<string, string> $query */
    private static function redirect(string $status, array $query = []): void
    {
        if (!function_exists('wp_safe_redirect') || !function_exists('admin_url')) {
            return;
        }

        $url = admin_url('tools.php?page=' . self::SLUG . '&htmlphp=' . $status);

        foreach ($query as $key => $value) {
            $url .= '&' . $key . '=' . rawurlencode($value);
        }

        wp_safe_redirect($url);

        if (function_exists('exit_after_redirect')) {
            exit_after_redirect();
        }
    }
}
