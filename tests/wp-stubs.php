<?php

/**
 * Minimal WordPress API stubs for testing the HtmlPhp\WordPress layer without a real
 * WordPress install. Only the surface used by the bridge and the
 * maintenance toolkit is emulated; behaviour mirrors the real functions
 * closely enough for unit assertions.
 *
 * Loaded by tests/run.php before every case; used by tests/cases/12_wordpress.test.php.
 * Loading order matters: this file defines ABSPATH, which switches the
 * engine's Translator into WordPress-delegation mode.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('HTML_WP_LOG_DIR')) {
    define('HTML_WP_LOG_DIR', fixture_path('wp-logs'));
}

// $wpdb output-mode constants.
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
    define('OBJECT', 'OBJECT');
    define('OBJECT_K', 'OBJECT_K');
}

// ------------------------------------------------------------------- state

$GLOBALS['wp_stub_options'] ??= [];
$GLOBALS['wp_stub_transients'] ??= [];
$GLOBALS['wp_stub_site_transients'] ??= [];
$GLOBALS['wp_stub_cron'] ??= [];
$GLOBALS['wp_stub_hooks'] ??= ['actions' => [], 'filters' => []];
$GLOBALS['wp_stub_shortcodes'] ??= [];
$GLOBALS['wp_stub_assets'] ??= [];
$GLOBALS['wp_stub_rest'] ??= [];
$GLOBALS['wp_stub_cli_log'] ??= [];
$GLOBALS['wp_stub_cli_commands'] ??= [];
$GLOBALS['wp_stub_redirects'] ??= [];
$GLOBALS['wp_stub_did_actions'] ??= [];
$GLOBALS['wp_stub_can'] ??= true;
$GLOBALS['wp_stub_translations'] ??= [];
$GLOBALS['wp_stub_locale'] ??= 'en_US';
$GLOBALS['wp_stub_notices_queue'] ??= [];
$GLOBALS['wp_version'] ??= '6.9-stub';

final class WpStubRedirect extends RuntimeException {}

// ------------------------------------------------------------------ escaping

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url_raw(string $url): string
{
    return filter_var($url, FILTER_SANITIZE_URL) ?: '';
}

function sanitize_text_field(string $text): string
{
    return trim(strip_tags($text));
}

function sanitize_key(string $key): string
{
    return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)));
}

function absint(mixed $value): int
{
    return abs((int) $value);
}

function wp_unslash(mixed $value): mixed
{
    return is_string($value) ? stripslashes($value) : $value;
}

function wp_json_encode(mixed $data, int $flags = 0): string|false
{
    return json_encode($data, $flags);
}

// --------------------------------------------------------------------- i18n

function __(string $text, string $domain = 'default'): string
{
    return $GLOBALS['wp_stub_translations'][$domain][$text] ?? $text;
}

function _e(string $text, string $domain = 'default'): void
{
    echo __($text, $domain);
}

function _n(string $single, string $plural, int $number, string $domain = 'default'): string
{
    $text = $number === 1 ? $single : $plural;

    return $GLOBALS['wp_stub_translations'][$domain][$text] ?? $text;
}

function _x(string $text, string $context, string $domain = 'default'): string
{
    return $GLOBALS['wp_stub_translations'][$domain][$context . "\x04" . $text] ?? $text;
}

function _nx(string $single, string $plural, int $number, string $context, string $domain = 'default'): string
{
    $text = $number === 1 ? $single : $plural;

    return $GLOBALS['wp_stub_translations'][$domain][$context . "\x04" . $text] ?? $text;
}

function determine_locale(): string
{
    return (string) $GLOBALS['wp_stub_locale'];
}

function get_locale(): string
{
    return determine_locale();
}

function load_plugin_textdomain(string $domain, string|bool $deprecated = false, string $dir = ''): bool
{
    return true;
}

// -------------------------------------------------------------------- hooks

function add_action(string $tag, callable $callback, int $priority = 10, int $args = 1): bool
{
    $GLOBALS['wp_stub_hooks']['actions'][$tag][] = ['cb' => $callback, 'priority' => $priority];

    return true;
}

function add_filter(string $tag, callable $callback, int $priority = 10, int $args = 1): bool
{
    $GLOBALS['wp_stub_hooks']['filters'][$tag][] = ['cb' => $callback, 'priority' => $priority];

    return true;
}

function do_action(string $tag, mixed ...$args): void
{
    $GLOBALS['wp_stub_did_actions'][$tag] = ($GLOBALS['wp_stub_did_actions'][$tag] ?? 0) + 1;

    foreach ($GLOBALS['wp_stub_hooks']['actions'][$tag] ?? [] as $hook) {
        ($hook['cb'])(...$args);
    }
}

function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
{
    foreach ($GLOBALS['wp_stub_hooks']['filters'][$tag] ?? [] as $hook) {
        $value = ($hook['cb'])($value, ...$args);
    }

    return $value;
}

function did_action(string $tag): int
{
    return (int) ($GLOBALS['wp_stub_did_actions'][$tag] ?? 0);
}

function has_action(string $tag): bool
{
    return isset($GLOBALS['wp_stub_hooks']['actions'][$tag]);
}

// ---------------------------------------------------------------- shortcodes

function add_shortcode(string $tag, callable $callback): void
{
    $GLOBALS['wp_stub_shortcodes'][$tag] = $callback;
}

function shortcode_atts(array $defaults, mixed $atts): array
{
    $atts = is_array($atts) ? $atts : [];

    return array_merge($defaults, array_intersect_key($atts, $defaults));
}

function do_shortcode(string $content): string
{
    foreach ($GLOBALS['wp_stub_shortcodes'] as $tag => $callback) {
        $quoted = preg_quote((string) $tag, '~');

        // Paired form first, then the self-closing form.
        $content = (string) preg_replace_callback(
            '~\[' . $quoted . '((?:\s+[^\]]*)?)\](.*?)\[/' . $quoted . '\]~s',
            static fn(array $m): string => (string) $callback(wp_stub_atts($m[1]), $m[2]),
            $content,
        );

        $content = (string) preg_replace_callback(
            '~\[' . $quoted . '((?:\s+[^\]]*)?)\]~s',
            static fn(array $m): string => (string) $callback(wp_stub_atts($m[1]), null),
            $content,
        );
    }

    return $content;
}

/**
 * @return array<string, string>
 */
function wp_stub_atts(string $raw): array
{
    $atts = [];

    if (preg_match_all('~(\w+)\s*=\s*"([^"]*)"~', $raw, $pairs, PREG_SET_ORDER)) {
        foreach ($pairs as $pair) {
            $atts[$pair[1]] = $pair[2];
        }
    }

    return $atts;
}

// ------------------------------------------------------- options/transients

function get_option(string $key, mixed $default = false): mixed
{
    return $GLOBALS['wp_stub_options'][$key] ?? $default;
}

function update_option(string $key, mixed $value): bool
{
    $GLOBALS['wp_stub_options'][$key] = $value;

    return true;
}

function add_option(string $key, mixed $value): bool
{
    $GLOBALS['wp_stub_options'][$key] ??= $value;

    return true;
}

function delete_option(string $key): bool
{
    unset($GLOBALS['wp_stub_options'][$key]);

    return true;
}

function get_transient(string $key): mixed
{
    $entry = $GLOBALS['wp_stub_transients'][$key] ?? null;

    if (is_array($entry) && ($entry['expires'] ?? 0) >= time()) {
        return $entry['value'];
    }

    return false;
}

function set_transient(string $key, mixed $value, int $ttl = 0): bool
{
    $GLOBALS['wp_stub_transients'][$key] = ['value' => $value, 'expires' => time() + max(0, $ttl)];

    return true;
}

function delete_transient(string $key): bool
{
    unset($GLOBALS['wp_stub_transients'][$key]);

    return true;
}

function get_site_transient(string $key): mixed
{
    return $GLOBALS['wp_stub_site_transients'][$key] ?? false;
}

function set_site_transient(string $key, mixed $value): bool
{
    $GLOBALS['wp_stub_site_transients'][$key] = $value;

    return true;
}

// -------------------------------------------------------------------- nonces

function wp_create_nonce(string $action = '-1'): string
{
    return substr(md5('stub|' . $action), 0, 10);
}

function wp_verify_nonce(?string $nonce, string $action = '-1'): bool
{
    return is_string($nonce) && hash_equals(wp_create_nonce($action), $nonce);
}

function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $display = true): string
{
    $field = '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr(wp_create_nonce($action)) . '" />';

    if ($display) {
        echo $field;
    }

    return $field;
}

// --------------------------------------------------------------- capabilities

function current_user_can(string $capability): bool
{
    return (bool) $GLOBALS['wp_stub_can'];
}

function is_admin(): bool
{
    return true;
}

function is_multisite(): bool
{
    return false;
}

function wp_die(string $message = '', string $title = '', array $args = []): void
{
    throw new WpStubRedirect('wp_die: ' . $message);
}

function wp_safe_redirect(string $location): void
{
    $GLOBALS['wp_stub_redirects'][] = $location;

    throw new WpStubRedirect('redirect: ' . $location);
}

// ---------------------------------------------------------------------- urls

function home_url(string $path = ''): string
{
    return 'https://example.org' . ($path !== '' && $path[0] !== '/' ? '/' : '') . $path;
}

function admin_url(string $path = ''): string
{
    return home_url('/wp-admin/' . ltrim($path, '/'));
}

function plugins_url(string $path = '', string $plugin = ''): string
{
    return home_url('/wp-content/plugins/' . ltrim($path, '/'));
}

function plugin_dir_path(string $file): string
{
    return rtrim(dirname($file), '/\\') . '/';
}

function wp_normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

function wp_upload_dir(): array
{
    $base = fixture_path('wp-uploads');
    @mkdir($base, 0o777, true);

    return ['basedir' => $base, 'baseurl' => home_url('/wp-content/uploads')];
}

function get_bloginfo(string $show = ''): string
{
    return match ($show) {
        'name' => 'Stub Site',
        'description' => 'Just another stub site',
        'version' => (string) $GLOBALS['wp_version'],
        'charset' => 'UTF-8',
        'language' => str_replace('_', '-', $GLOBALS['wp_stub_locale']),
        default => '',
    };
}

function wp_get_theme(): object
{
    return new class {
        public function get(string $key): string
        {
            return $key === 'Name' ? 'Stub Theme' : '';
        }
    };
}

// ---------------------------------------------------------------------- cron

function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
{
    $GLOBALS['wp_stub_cron'][$hook] = ['timestamp' => $timestamp, 'recurrence' => $recurrence, 'args' => $args];

    return true;
}

function wp_unschedule_event(int $timestamp, string $hook, array $args = []): bool
{
    unset($GLOBALS['wp_stub_cron'][$hook]);

    return true;
}

function wp_next_scheduled(string $hook, array $args = []): int|false
{
    return isset($GLOBALS['wp_stub_cron'][$hook]) ? (int) $GLOBALS['wp_stub_cron'][$hook]['timestamp'] : false;
}

// --------------------------------------------------------------------- $wpdb

final class WpdbStub
{
    public string $prefix = 'wp_';
    /** Mirrors the real `$wpdb->options` (prefixed table name) — `uninstall.php` interpolates it. */
    public string $options = 'wp_options';
    public string $db_version = '8.0-stub';
    public string $charset = 'utf8mb4';
    public int $queries = 0;

    /** @var list<array<string, mixed>> */
    public array $results = [];
    public mixed $var = null;
    public bool $connected = true;

    public function prepare(string $sql, mixed ...$args): string
    {
        if ($args === []) {
            return $sql;
        }

        // Very small %s/%d/%f substitution used by the tests.
        $index = 0;

        return (string) preg_replace_callback(
            '~%[sdf]~',
            static function (array $m) use (&$index, $args): string {
                $value = $args[$index++] ?? '';

                return match ($m[0]) {
                    '%d' => (string) (int) $value,
                    '%f' => (string) (float) $value,
                    default => "'" . addslashes((string) $value) . "'",
                };
            },
            $sql,
        );
    }

    public function query(string $sql): int|bool
    {
        ++$this->queries;

        return true;
    }

    public function get_var(string $sql): mixed
    {
        ++$this->queries;

        return $this->var;
    }

    public function get_row(string $sql, string $output = 'OBJECT'): ?array
    {
        ++$this->queries;
        $row = $this->results[0] ?? null;

        return is_array($row) ? $row : null;
    }

    public function get_results(string $sql, string $output = 'OBJECT'): array
    {
        ++$this->queries;

        return $this->results;
    }

    public function check_connection(bool $allow_bail = true): bool
    {
        return $this->connected;
    }
}

$GLOBALS['wpdb'] ??= new WpdbStub();

// --------------------------------------------------------------- admin/misc

function add_management_page(string $title, string $menu, string $cap, string $slug, ?callable $cb = null): string
{
    $GLOBALS['wp_stub_admin_pages'][$slug] = ['title' => $title, 'cb' => $cb];

    return 'tools_page_' . $slug;
}

function wp_add_dashboard_widget(string $id, string $name, callable $cb): void
{
    $GLOBALS['wp_stub_dashboard'][$id] = $cb;
}

function register_activation_hook(string $file, callable $cb): void
{
    $GLOBALS['wp_stub_lifecycle']['activate'] = $cb;
}

function register_deactivation_hook(string $file, callable $cb): void
{
    $GLOBALS['wp_stub_lifecycle']['deactivate'] = $cb;
}

function register_rest_route(string $namespace, string $route, array $args = []): bool
{
    $GLOBALS['wp_stub_rest'][$namespace . $route] = $args;

    return true;
}

function wp_register_style(string $handle, string $src, array $deps = [], string|bool|null $ver = null, string $media = 'all'): void
{
    $GLOBALS['wp_stub_assets'][$handle] = ['kind' => 'style', 'src' => $src, 'ver' => (string) $ver];
}

function wp_enqueue_style(string $handle): void
{
    $GLOBALS['wp_stub_assets'][$handle]['enqueued'] = true;
}

function wp_register_script(string $handle, string $src, array $deps = [], string|bool|null $ver = null, bool $footer = false): void
{
    $GLOBALS['wp_stub_assets'][$handle] = ['kind' => 'script', 'src' => $src, 'ver' => (string) $ver];
}

function wp_enqueue_script(string $handle): void
{
    $GLOBALS['wp_stub_assets'][$handle]['enqueued'] = true;
}

function locate_template(array|string $names, bool $load = false): string
{
    $names = (array) $names;
    $themeDir = fixture_path('wp-theme');

    foreach ($names as $name) {
        $path = $themeDir . '/' . ltrim((string) $name, '/');

        if (is_file($path)) {
            return $path;
        }
    }

    return '';
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

final class WP_Error
{
    public function __construct(public string $code = '', public string $message = '') {}

    public function get_error_message(): string
    {
        return $this->message;
    }
}

// -------------------------------------------------------------------- WP-CLI

final class WP_CLI
{
    public static function add_command(string $name, mixed $callable): void
    {
        $GLOBALS['wp_stub_cli_commands'][$name] = $callable;
    }

    public static function log(string $message): void
    {
        $GLOBALS['wp_stub_cli_log'][] = 'log: ' . $message;
    }

    public static function line(string $message = ''): void
    {
        $GLOBALS['wp_stub_cli_log'][] = 'line: ' . $message;
    }

    public static function warning(string $message): void
    {
        $GLOBALS['wp_stub_cli_log'][] = 'warning: ' . $message;
    }

    public static function error(string $message, bool $exit = true): void
    {
        $GLOBALS['wp_stub_cli_log'][] = 'error: ' . $message;
    }

    public static function success(string $message): void
    {
        $GLOBALS['wp_stub_cli_log'][] = 'success: ' . $message;
    }
}
