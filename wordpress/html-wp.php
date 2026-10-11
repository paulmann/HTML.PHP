<?php
/**
 * HTML.PHP — WordPress bridge & maintenance toolkit.
 *
 * VERSION: 3.0.0
 *
 * Connects the HTML.PHP template engine to WordPress and adds a compact,
 * production-oriented toolkit for plugin/theme maintenance work:
 *
 *  - Bridge          — boots the engine inside WordPress: esc_html-based
 *                      escaping, i18n delegation to __()/_n()/_x(), locale
 *                      sync and optional catalogue loading;
 *  - TemplateLoader  — renders theme/plugin templates through the engine
 *                      (SSI + loops + i18n) with locate_template() fallback;
 *  - Shortcodes      — exposes any template file as a shortcode;
 *  - Maintenance\*   — nonces, options, transients, admin notices, $wpdb
 *                      helpers, cron, rotating logs, system info, update
 *                      checks, health checks, asset enqueuing with cache
 *                      busting, REST route registration and WP-CLI commands.
 *
 * The file is safe to load outside WordPress: every core call is guarded by
 * function_exists(), and the test suite exercises it against the stubs in
 * tests/wp-stubs.php.
 *
 * Usage inside a plugin:
 *
 *   require_once __DIR__ . '/html-wp.php';
 *   html_wp_boot([
 *       'shortcodes'   => ['hello' => __DIR__ . '/templates/hello.htm'],
 *       'translations' => ['myplugin' => __DIR__ . '/languages'],
 *       'cli'          => true,
 *   ]);
 *
 * Requirements: PHP 8.3+, WordPress 6.0+ (older versions work for most
 * features), HTML.PHP 3.0 engine (auto-loaded from ../php83 or ../php85).
 *
 * Author:  Mikhail Deynekin
 * License: MIT
 */

declare(strict_types=1);

namespace HtmlPhp\WordPress {

    /**
     * Boots the engine inside WordPress and wires the integration points.
     */
    final class Bridge
    {
        private static bool $booted = false;

        /** @var array<string, mixed> */
        private static array $config = [];

        private function __construct()
        {
        }

        /**
         * True when the WordPress core API is present.
         */
        public static function isWordPress(): bool
        {
            return \defined('ABSPATH') && function_exists('add_action');
        }

        /**
         * Loads the matching engine edition unless one is already loaded.
         *
         * Define HTML_WP_ENGINE (absolute path) to override the discovery.
         */
        public static function ensureEngine(): void
        {
            if (function_exists('html_render')) {
                return;
            }

            $candidates = [];

            if (\defined('HTML_WP_ENGINE') && \is_string(\HTML_WP_ENGINE)) {
                $candidates[] = \HTML_WP_ENGINE;
            }

            $root = dirname(__DIR__);
            $candidates[] = $root . '/php85/html.php';
            $candidates[] = $root . '/php83/html.php';
            $candidates[] = $root . '/html.php';

            foreach ($candidates as $candidate) {
                if ($candidate !== '' && is_file($candidate)) {
                    require_once $candidate;

                    return;
                }
            }

            throw new \RuntimeException(
                'HTML.PHP engine not found. Place html-wp.php next to the php83/php85 '
                . 'directories or define HTML_WP_ENGINE with the absolute path to html.php.'
            );
        }

        /**
         * Boots the bridge. Idempotent: a second call merges configuration.
         *
         * Recognised options:
         *   escaper      bool   install esc_html as the engine escaper (default true)
         *   i18n         string 'auto'|'wp'|'engine'|'off' (default 'auto')
         *   locale       bool   sync HTML_I18N_LOCALE with determine_locale() (default true)
         *   shortcodes   array  tag => template path
         *   translations array  domain => languages directory
         *   notices      bool   install the admin-notice queue renderer (default true)
         *   cli          bool   register WP-CLI commands when WP-CLI runs (default true)
         *   config       array  engine configuration overrides (HTML_* keys)
         *
         * @param array<string, mixed> $config
         */
        public static function boot(array $config = []): void
        {
            self::ensureEngine();

            self::$config = array_merge(self::$config, $config);

            $engineConfig = self::$config['config'] ?? null;

            if (is_array($engineConfig) && $engineConfig !== []) {
                // Assigned (not discarded) to satisfy the 8.5 edition's
                // #[\NoDiscard] annotation on html_config().
                $applied = html_config($engineConfig);
                unset($applied);
            }

            // 1. Escaping through WordPress (esc_html honours the 'esc_html' filter).
            if (($config['escaper'] ?? true) && function_exists('esc_html')) {
                $GLOBALS['HTML_ESCAPER'] = 'esc_html';
            }

            // 2. Translation delegation mode.
            $mode = (string) ($config['i18n'] ?? 'auto');
            $GLOBALS['HTML_I18N_WP'] = match ($mode) {
                'wp' => true,
                'engine', 'off' => false,
                default => null, // auto: the engine detects WordPress itself
            };

            // 3. Locale sync with WordPress.
            if (($config['locale'] ?? true) && function_exists('determine_locale')) {
                $locale = (string) determine_locale();

                if ($locale !== '') {
                    html_set_locale($locale);
                }
            }

            // 4. Own catalogues for the active locale.
            $translations = $config['translations'] ?? [];

            if (is_array($translations) && function_exists('html_load_translations')) {
                foreach ($translations as $domain => $directory) {
                    if (is_string($domain) && is_string($directory)) {
                        html_load_translations(html_get_locale(), $directory, $domain);
                    }
                }
            }

            // 5. Shortcodes.
            $shortcodes = $config['shortcodes'] ?? [];

            if (is_array($shortcodes)) {
                foreach ($shortcodes as $tag => $template) {
                    if (is_string($tag) && is_string($template)) {
                        Shortcodes::register($tag, $template);
                    }
                }
            }

            // 6. Admin notices queue.
            if (($config['notices'] ?? true) && self::isWordPress()) {
                Maintenance\Notices::install();
            }

            // 7. WP-CLI.
            if (($config['cli'] ?? true) && class_exists('\WP_CLI') && self::isWordPress()) {
                Cli\Commands::register();
            }

            self::$booted = true;

            if (function_exists('html_do_action')) {
                html_do_action('htmlphp_wp_boot', self::$config);
            }
        }

        public static function booted(): bool
        {
            return self::$booted;
        }

        /**
         * Resets the bridge (tests only).
         */
        public static function reset(): void
        {
            self::$booted = false;
            self::$config = [];
        }
    }

    /**
     * Locates and renders template files through the engine.
     */
    final class TemplateLoader
    {
        /** Suffixes tried for a bare slug, in order. */
        private const array SUFFIXES = ['.ssi.htm', '.ssi.html', '.ssi', '.htm', '.html', '.tpl', '.php'];

        private function __construct()
        {
        }

        /**
         * Finds the first existing template among candidate names.
         *
         * Search order: child theme → parent theme (locate_template when
         * available) → each directory in $dirs.
         *
         * @param string|list<string> $names
         * @param list<string> $dirs
         */
        public static function locate(string|array $names, array $dirs = []): ?string
        {
            $names = is_string($names) ? [$names] : $names;
            $candidates = [];

            foreach ($names as $name) {
                if ($name === '' || str_contains($name, "\0")) {
                    continue;
                }

                $candidates[] = $name;

                if (!str_contains($name, '.')) {
                    foreach (self::SUFFIXES as $suffix) {
                        $candidates[] = $name . $suffix;
                    }
                }
            }

            if ($candidates === []) {
                return null;
            }

            if (function_exists('locate_template')) {
                $found = (string) locate_template($candidates);

                if ($found !== '' && is_file($found)) {
                    return $found;
                }
            }

            foreach ($dirs as $dir) {
                if (!is_string($dir) || !is_dir($dir)) {
                    continue;
                }

                foreach ($candidates as $candidate) {
                    $path = rtrim($dir, '/\\') . \DIRECTORY_SEPARATOR
                        . str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, ltrim($candidate, '/\\'));

                    if (is_file($path)) {
                        return $path;
                    }
                }
            }

            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }

            return null;
        }

        /**
         * Renders a located template with scoped variables.
         *
         * @param string|list<string> $names
         * @param array<string, mixed> $vars
         * @param array<string, mixed> $options
         */
        public static function render(string|array $names, array $vars = [], array $options = [], array $dirs = []): string
        {
            $path = self::locate($names, $dirs);

            if ($path === null) {
                if (class_exists(\HtmlPhp\Errors::class)) {
                    \HtmlPhp\Errors::add(
                        \HtmlPhp\Errors::WP_BRIDGE,
                        'WordPress template could not be located.',
                        ['names' => (array) $names]
                    );
                }

                return '';
            }

            $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

            if ($extension === 'php') {
                return self::includePhp($path, $vars);
            }

            $vars = self::withWordPressContext($vars);

            return html_render_file($path, $vars, $options + ['file' => $path]);
        }

        /**
         * Renders like get_template_part(), returning the output as a string.
         *
         * @param array<string, mixed> $vars
         */
        public static function renderPart(string $slug, ?string $name = null, array $vars = []): string
        {
            $names = $name !== null && $name !== ''
                ? [$slug . '-' . $name, $slug]
                : [$slug];

            return self::render($names, $vars);
        }

        /**
         * Runs a PHP template in an isolated scope with $vars extracted.
         *
         * @param array<string, mixed> $vars
         */
        private static function includePhp(string $path, array $vars): string
        {
            $runner = static function () use ($path, $vars): void {
                extract($vars, \EXTR_SKIP);
                include $path;
            };

            ob_start();

            try {
                $runner();
            } finally {
                $output = (string) ob_get_clean();
            }

            return $output;
        }

        /**
         * Adds standard WordPress context variables to every render.
         *
         * @param array<string, mixed> $vars
         * @return array<string, mixed>
         */
        private static function withWordPressContext(array $vars): array
        {
            $context = [];

            if (function_exists('get_bloginfo')) {
                $context['wp_blogname'] = (string) get_bloginfo('name');
                $context['wp_description'] = (string) get_bloginfo('description');
                $context['wp_version'] = (string) get_bloginfo('version');
                $context['wp_charset'] = (string) get_bloginfo('charset');
                $context['wp_language'] = (string) get_bloginfo('language');
            }

            if (function_exists('home_url')) {
                $context['wp_home_url'] = (string) home_url('/');
            }

            $context['wp_locale'] = function_exists('html_get_locale') ? html_get_locale() : '';
            $context['wp_year'] = date('Y');

            return $vars + $context;
        }
    }

    /**
     * Registers template files as shortcodes.
     */
    final class Shortcodes
    {
        private function __construct()
        {
        }

        /**
         * [tag attr="value"] renders $templatePath with the attributes as
         * scoped variables (plus the enclosing content as %{content} when the
         * shortcode is used with a closing tag).
         *
         * @param array<string, mixed> $defaults
         */
        public static function register(string $tag, string $templatePath, array $defaults = []): void
        {
            $tag = preg_replace('~[^a-z0-9_-]~i', '', $tag) ?? '';

            if ($tag === '' || !function_exists('add_shortcode')) {
                return;
            }

            $handler = static function (mixed $atts, mixed $content = null) use ($templatePath, $defaults): string {
                $vars = $defaults;

                if (is_array($atts)) {
                    foreach ($atts as $key => $value) {
                        if (is_string($key) && \HtmlPhp\Support\Str::isValidVariableName($key)) {
                            $vars[$key] = is_scalar($value) || $value === null ? $value : '';
                        }
                    }
                }

                $vars['content'] = is_string($content) ? $content : '';

                try {
                    return html_render_file($templatePath, $vars, [
                        'escape' => true,
                        'passes' => 1,
                        'file' => $templatePath,
                    ]);
                } catch (\Throwable $error) {
                    if (function_exists('html_errors')) {
                        \HtmlPhp\Errors::add(
                            \HtmlPhp\Errors::WP_BRIDGE,
                            'Shortcode render failed: ' . $error->getMessage(),
                            ['template' => $templatePath]
                        );
                    }

                    return '';
                }
            };

            add_shortcode($tag, $handler);
        }
    }
}
namespace HtmlPhp\WordPress\Maintenance {

    use HtmlPhp\TemplateException;

    /**
     * Nonce helpers (create / verify / field) with sane defaults.
     */
    final class Nonces
    {
        private function __construct()
        {
        }

        public static function create(string $action = 'htmlphp_action'): string
        {
            self::requireWordPress();

            return (string) wp_create_nonce($action);
        }

        public static function verify(?string $nonce, string $action = 'htmlphp_action'): bool
        {
            self::requireWordPress();

            if (!is_string($nonce) || $nonce === '') {
                return false;
            }

            return (bool) wp_verify_nonce($nonce, $action);
        }

        /**
         * Verifies the request nonce from $_REQUEST['_wpnonce'] and dies-safe
         * returns false otherwise; use inside form handlers.
         */
        public static function verifyRequest(string $action = 'htmlphp_action'): bool
        {
            $nonce = isset($_REQUEST['_wpnonce']) ? (string) $_REQUEST['_wpnonce'] : null;

            return self::verify($nonce, $action);
        }

        public static function field(string $action = 'htmlphp_action', string $name = '_wpnonce'): string
        {
            self::requireWordPress();

            $escape = static fn(string $value): string => function_exists('esc_attr')
                ? esc_attr($value)
                : htmlspecialchars($value, \ENT_QUOTES);

            return '<input type="hidden" id="' . $escape($name) . '" name="' . $escape($name)
                . '" value="' . $escape(self::create($action)) . '" />';
        }

        private static function requireWordPress(): void
        {
            if (!function_exists('wp_create_nonce')) {
                throw new TemplateException('WordPress nonce API is not available.');
            }
        }
    }

    /**
     * Typed option access with a plugin-scoped key prefix.
     */
    final class Options
    {
        private function __construct()
        {
        }

        public static function get(string $key, mixed $default = null, string $prefix = 'htmlphp_'): mixed
        {
            if (!function_exists('get_option')) {
                return $GLOBALS['htmlphp_options'][$prefix . $key] ?? $default;
            }

            $value = get_option($prefix . $key, $default);

            return $value === false ? $default : $value;
        }

        public static function set(string $key, mixed $value, string $prefix = 'htmlphp_'): bool
        {
            if (!function_exists('update_option')) {
                $GLOBALS['htmlphp_options'][$prefix . $key] = $value;

                return true;
            }

            return (bool) update_option($prefix . $key, $value);
        }

        public static function delete(string $key, string $prefix = 'htmlphp_'): bool
        {
            if (!function_exists('delete_option')) {
                unset($GLOBALS['htmlphp_options'][$prefix . $key]);

                return true;
            }

            return (bool) delete_option($prefix . $key);
        }

        public static function getInt(string $key, int $default = 0, string $prefix = 'htmlphp_'): int
        {
            $value = self::get($key, $default, $prefix);

            return is_numeric($value) ? (int) $value : $default;
        }

        public static function getBool(string $key, bool $default = false, string $prefix = 'htmlphp_'): bool
        {
            $value = self::get($key, $default, $prefix);

            return is_bool($value) ? $value : filter_var($value, \FILTER_VALIDATE_BOOL);
        }

        /**
         * @return array<array-key, mixed>
         */
        public static function getArray(string $key, string $prefix = 'htmlphp_'): array
        {
            $value = self::get($key, [], $prefix);

            return is_array($value) ? $value : [];
        }
    }

    /**
     * Transient access with explicit TTL semantics.
     */
    final class Transients
    {
        private function __construct()
        {
        }

        public static function get(string $key, mixed $default = null, string $prefix = 'htmlphp_'): mixed
        {
            if (!function_exists('get_transient')) {
                $entry = $GLOBALS['htmlphp_transients'][$prefix . $key] ?? null;

                if (is_array($entry) && ($entry['expires'] ?? 0) > time()) {
                    return $entry['value'];
                }

                return $default;
            }

            $value = get_transient($prefix . $key);

            return $value === false ? $default : $value;
        }

        public static function set(string $key, mixed $value, int $ttl = 3600, string $prefix = 'htmlphp_'): bool
        {
            if (!function_exists('set_transient')) {
                $GLOBALS['htmlphp_transients'][$prefix . $key] = ['value' => $value, 'expires' => time() + max(1, $ttl)];

                return true;
            }

            return (bool) set_transient($prefix . $key, $value, max(1, $ttl));
        }

        public static function delete(string $key, string $prefix = 'htmlphp_'): bool
        {
            if (!function_exists('delete_transient')) {
                unset($GLOBALS['htmlphp_transients'][$prefix . $key]);

                return true;
            }

            return (bool) delete_transient($prefix . $key);
        }

        /**
         * get-or-compute with a TTL: the standard maintenance cache pattern.
         */
        public static function remember(string $key, int $ttl, callable $factory, string $prefix = 'htmlphp_'): mixed
        {
            $sentinel = "\0htmlphp-miss\0";
            $cached = self::get($key, $sentinel, $prefix);

            if ($cached !== $sentinel) {
                return $cached;
            }

            $value = $factory();
            self::set($key, $value, $ttl, $prefix);

            return $value;
        }
    }

    /**
     * One-shot admin notice queue rendered through the admin_notices hook.
     */
    final class Notices
    {
        private const string QUEUE_KEY = 'notices_queue';
        private static bool $installed = false;

        private function __construct()
        {
        }

        /**
         * Queues a notice for the next admin screen.
         *
         * @param string $type one of info|success|warning|error; anything else
         *                     is normalised to "info"
         */
        public static function add(string $text, string $type = 'info', bool $dismissible = true): void
        {
            $type = in_array($type, ['info', 'success', 'warning', 'error'], true) ? $type : 'info';
            $queue = Options::getArray(self::QUEUE_KEY);
            $queue[] = ['text' => $text, 'type' => $type, 'dismissible' => $dismissible];
            Options::set(self::QUEUE_KEY, array_slice($queue, -50));
        }

        public static function install(): void
        {
            if (self::$installed || !function_exists('add_action')) {
                return;
            }

            add_action('admin_notices', [self::class, 'render']);
            self::$installed = true;
        }

        /**
         * Renders and clears the queue (called from admin_notices).
         */
        public static function render(): void
        {
            $queue = Options::getArray(self::QUEUE_KEY);

            if ($queue === []) {
                return;
            }

            Options::delete(self::QUEUE_KEY);

            $escape = static fn(string $value): string => function_exists('esc_attr')
                ? esc_attr($value)
                : htmlspecialchars($value, \ENT_QUOTES);

            foreach ($queue as $notice) {
                $class = 'notice notice-' . (is_string($notice['type'] ?? null) ? $notice['type'] : 'info');
                $class .= !empty($notice['dismissible']) ? ' is-dismissible' : '';

                echo '<div class="' . $escape($class) . '"><p>'
                    . $escape((string) ($notice['text'] ?? '')) . '</p></div>';
            }
        }

        /**
         * The queued notices without rendering them (tests, CLI).
         *
         * @return list<array{text: string, type: string, dismissible: bool}>
         */
        public static function queued(): array
        {
            return Options::getArray(self::QUEUE_KEY);
        }

        public static function reset(): void
        {
            self::$installed = false;
        }
    }

    /**
     * $wpdb helpers: prepared queries, bulk statistics and safe DML.
     */
    final class Db
    {
        private function __construct()
        {
        }

        /**
         * The global $wpdb instance (or null outside WordPress).
         */
        public static function wpdb(): ?object
        {
            $wpdb = $GLOBALS['wpdb'] ?? null;

            return is_object($wpdb) ? $wpdb : null;
        }

        /**
         * Prepares SQL through $wpdb->prepare().
         *
         * @param list<mixed> $params
         */
        public static function prepare(string $sql, array $params = []): string
        {
            $wpdb = self::wpdb();

            if ($wpdb === null) {
                throw new TemplateException('$wpdb is not available.');
            }

            return $params === [] ? $sql : (string) $wpdb->prepare($sql, ...$params);
        }

        /**
         * @param list<mixed> $params
         */
        public static function query(string $sql, array $params = []): int|bool
        {
            $wpdb = self::wpdb() ?? throw new TemplateException('$wpdb is not available.');

            return $wpdb->query(self::prepare($sql, $params));
        }

        /**
         * @param list<mixed> $params
         */
        public static function getVar(string $sql, array $params = []): mixed
        {
            $wpdb = self::wpdb() ?? throw new TemplateException('$wpdb is not available.');

            return $wpdb->get_var(self::prepare($sql, $params));
        }

        /**
         * @param list<mixed> $params
         * @return array<string, mixed>|null
         */
        public static function getRow(string $sql, array $params = []): ?array
        {
            $wpdb = self::wpdb() ?? throw new TemplateException('$wpdb is not available.');
            $row = $wpdb->get_row(self::prepare($sql, $params), \ARRAY_A);

            return is_array($row) ? $row : null;
        }

        /**
         * @param list<mixed> $params
         * @return list<array<string, mixed>>
         */
        public static function getResults(string $sql, array $params = []): array
        {
            $wpdb = self::wpdb() ?? throw new TemplateException('$wpdb is not available.');
            $rows = $wpdb->get_results(self::prepare($sql, $params), \ARRAY_A);

            return is_array($rows) ? array_values($rows) : [];
        }

        /**
         * Per-table row counts and byte sizes (information_schema based).
         *
         * @return list<array{table: string, rows: int, bytes: int}>
         */
        public static function tableSizes(): array
        {
            $rows = self::getResults(
                'SELECT TABLE_NAME AS table_name, TABLE_ROWS AS table_rows,
                        (DATA_LENGTH + INDEX_LENGTH) AS bytes
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                 ORDER BY bytes DESC'
            );

            $sizes = [];

            foreach ($rows as $row) {
                $sizes[] = [
                    'table' => (string) ($row['table_name'] ?? ''),
                    'rows' => (int) ($row['table_rows'] ?? 0),
                    'bytes' => (int) ($row['bytes'] ?? 0),
                ];
            }

            return $sizes;
        }

        /**
         * Counts rows of a core table, e.g. posts, postmeta, options.
         */
        public static function countTable(string $table): int
        {
            $wpdb = self::wpdb() ?? throw new TemplateException('$wpdb is not available.');

            if (preg_match('~^[a-z0-9_]+$~i', $table) !== 1) {
                throw new TemplateException('Invalid table name.');
            }

            $prefixed = property_exists($wpdb, 'prefix') ? $wpdb->prefix . $table : $table;

            return (int) self::getVar('SELECT COUNT(*) FROM `' . $prefixed . '`');
        }
    }

    /**
     * WP-Cron scheduling helpers.
     */
    final class Cron
    {
        private function __construct()
        {
        }

        /**
         * Schedules a recurring event when it is not already scheduled.
         *
         * @param list<mixed> $args
         */
        public static function schedule(string $hook, string $recurrence = 'daily', array $args = []): bool
        {
            if (!function_exists('wp_next_scheduled')) {
                throw new TemplateException('WP-Cron is not available.');
            }

            if (wp_next_scheduled($hook, $args) !== false) {
                return false;
            }

            return (bool) wp_schedule_event(time(), $recurrence, $hook, $args);
        }

        /**
         * @param list<mixed> $args
         */
        public static function unschedule(string $hook, array $args = []): bool
        {
            if (!function_exists('wp_next_scheduled')) {
                throw new TemplateException('WP-Cron is not available.');
            }

            $timestamp = wp_next_scheduled($hook, $args);

            if ($timestamp === false) {
                return false;
            }

            return (bool) wp_unschedule_event((int) $timestamp, $hook, $args);
        }

        /**
         * @param list<mixed> $args
         */
        public static function next(string $hook, array $args = []): ?int
        {
            if (!function_exists('wp_next_scheduled')) {
                return null;
            }

            $timestamp = wp_next_scheduled($hook, $args);

            return $timestamp === false ? null : (int) $timestamp;
        }

        /**
         * True when native WP-Cron appears to be disabled (external cron in use).
         */
        public static function disabledByConstant(): bool
        {
            return \defined('DISABLE_WP_CRON') && \DISABLE_WP_CRON === true;
        }
    }

    /**
     * Rotating file logger for maintenance operations.
     *
     * Writes to {uploads}/html-php-logs/ (or HTML_WP_LOG_DIR, or the system
     * temp directory outside WordPress) as one file per day, keeping the
     * newest HTML_WP_LOG_KEEP files (default 14).
     */
    final class Logs
    {
        private const array LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];

        private function __construct()
        {
        }

        public static function directory(): string
        {
            if (\defined('HTML_WP_LOG_DIR') && \is_string(\HTML_WP_LOG_DIR) && \HTML_WP_LOG_DIR !== '') {
                return rtrim(\HTML_WP_LOG_DIR, "/\\");
            }

            if (function_exists('wp_upload_dir')) {
                $uploads = wp_upload_dir();
                $base = is_array($uploads) ? (string) ($uploads['basedir'] ?? '') : '';

                if ($base !== '' && is_dir($base)) {
                    return rtrim($base, "/\\") . \DIRECTORY_SEPARATOR . 'html-php-logs';
                }
            }

            return sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'html-php-logs';
        }

        public static function file(): string
        {
            return self::directory() . \DIRECTORY_SEPARATOR . 'html-php-' . date('Y-m-d') . '.log';
        }

        /**
         * @param array<string, mixed> $context
         */
        public static function write(string $level, string $message, array $context = []): bool
        {
            $level = in_array($level, self::LEVELS, true) ? $level : 'info';
            $directory = self::directory();

            if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
                return false;
            }

            $line = sprintf(
                "[%s] %s: %s%s\n",
                date('Y-m-d H:i:s'),
                strtoupper($level),
                $message,
                $context === [] ? '' : ' ' . (string) json_encode($context, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
            );

            $written = @file_put_contents(self::file(), $line, \FILE_APPEND | \LOCK_EX);

            self::rotate();

            return $written !== false;
        }

        public static function info(string $message, array $context = []): bool
        {
            return self::write('info', $message, $context);
        }

        public static function error(string $message, array $context = []): bool
        {
            return self::write('error', $message, $context);
        }

        /**
         * Keeps only the newest N log files.
         */
        public static function rotate(int $keep = 14): int
        {
            $keep = max(1, $keep);
            $files = (array) glob(self::directory() . \DIRECTORY_SEPARATOR . 'html-php-*.log');
            $files = array_values(array_filter($files, 'is_string'));
            sort($files);

            $removed = 0;

            while (count($files) > $keep) {
                $oldest = (string) array_shift($files);

                if (@unlink($oldest)) {
                    ++$removed;
                }
            }

            return $removed;
        }

        /**
         * Tail of today's log (newest last).
         *
         * @return list<string>
         */
        public static function tail(int $lines = 50): array
        {
            $file = self::file();

            if (!is_file($file)) {
                return [];
            }

            $content = (string) @file_get_contents($file);
            $all = array_values(array_filter(explode("\n", $content), static fn(string $l): bool => trim($l) !== ''));

            return array_slice($all, -$lines);
        }
    }
}
namespace HtmlPhp\WordPress\Maintenance {

    use HtmlPhp\TemplateException;

    /**
     * Snapshot of the running installation for support and audits.
     */
    final class SystemInfo
    {
        private function __construct()
        {
        }

        /**
         * @return array<string, mixed>
         */
        public static function collect(): array
        {
            global $wp_version, $wpdb;

            $info = [
                'wordpress' => [
                    'version' => is_string($wp_version ?? null)
                        ? $wp_version
                        : (function_exists('get_bloginfo') ? (string) get_bloginfo('version') : 'unknown'),
                    'multisite' => function_exists('is_multisite') ? (bool) is_multisite() : false,
                    'locale' => function_exists('determine_locale') ? (string) determine_locale() : '',
                    'debug' => \defined('WP_DEBUG') && \WP_DEBUG === true,
                    'script_debug' => \defined('SCRIPT_DEBUG') && \SCRIPT_DEBUG === true,
                    'cron_disabled' => \defined('DISABLE_WP_CRON') && \DISABLE_WP_CRON === true,
                    'memory_limit' => \defined('WP_MEMORY_LIMIT') ? (string) \WP_MEMORY_LIMIT : '',
                    'site_url' => function_exists('home_url') ? (string) home_url('/') : '',
                    'active_theme' => self::themeName(),
                    'plugins_active' => self::activePlugins(),
                    'db_version' => is_object($wpdb ?? null) && property_exists($wpdb, 'db_version')
                        ? (string) $wpdb->db_version
                        : '',
                    'db_charset' => is_object($wpdb ?? null) && property_exists($wpdb, 'charset')
                        ? (string) $wpdb->charset
                        : '',
                ],
                'php' => [
                    'version' => \PHP_VERSION,
                    'sapi' => \PHP_SAPI,
                    'memory_limit' => (string) ini_get('memory_limit'),
                    'max_execution_time' => (string) ini_get('max_execution_time'),
                    'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                    'post_max_size' => (string) ini_get('post_max_size'),
                    'opcache' => function_exists('opcache_get_status')
                        && (bool) (ini_get('opcache.enable') ?? false),
                    'extensions' => self::relevantExtensions(),
                ],
                'server' => [
                    'software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? \PHP_OS_FAMILY),
                    'os' => \PHP_OS . ' ' . php_uname('m'),
                    'disk_free' => self::diskFree(),
                    'time' => date('c'),
                    'timezone' => date_default_timezone_get(),
                ],
                'engine' => [
                    'version' => function_exists('html_version') ? html_version() : 'not loaded',
                    'loaded_catalogues' => class_exists(\HtmlPhp\I18n\Translator::class)
                        ? \HtmlPhp\I18n\Translator::stats()
                        : [],
                    'pending_errors' => function_exists('html_errors') ? count(html_errors()) : 0,
                ],
            ];

            return $info;
        }

        /**
         * @return list<string>
         */
        private static function relevantExtensions(): array
        {
            $wanted = ['mbstring', 'intl', 'iconv', 'curl', 'gd', 'imagick', 'zip', 'openssl', 'json', 'mysqli', 'pdo_mysql', 'opcache'];
            $loaded = [];

            foreach ($wanted as $extension) {
                if (extension_loaded($extension)) {
                    $loaded[] = $extension;
                }
            }

            return $loaded;
        }

        private static function themeName(): string
        {
            if (!function_exists('wp_get_theme')) {
                return '';
            }

            $theme = wp_get_theme();

            return is_object($theme) && method_exists($theme, 'get') ? (string) $theme->get('Name') : '';
        }

        /**
         * @return list<string>
         */
        private static function activePlugins(): array
        {
            if (!function_exists('get_option')) {
                return [];
            }

            $plugins = get_option('active_plugins', []);

            return is_array($plugins) ? array_values(array_map('strval', $plugins)) : [];
        }

        private static function diskFree(): string
        {
            if (!function_exists('wp_upload_dir')) {
                return '';
            }

            $uploads = wp_upload_dir();
            $base = is_array($uploads) ? (string) ($uploads['basedir'] ?? '') : '';

            if ($base === '' || !is_dir($base)) {
                return '';
            }

            $free = @disk_free_space($base);

            return $free === false ? '' : (string) $free;
        }
    }

    /**
     * Available core/plugin/theme updates from the update transients.
     */
    final class Updates
    {
        private function __construct()
        {
        }

        /**
         * @return array{core: list<array<string, mixed>>, plugins: list<array<string, mixed>>, themes: list<array<string, mixed>>, last_checked: ?int}
         */
        public static function check(): array
        {
            $result = ['core' => [], 'plugins' => [], 'themes' => [], 'last_checked' => null];

            if (!function_exists('get_site_transient')) {
                return $result;
            }

            $core = get_site_transient('update_core');

            if (is_object($core)) {
                $result['last_checked'] = isset($core->last_checked) ? (int) $core->last_checked : null;

                foreach ((array) ($core->updates ?? []) as $update) {
                    if (is_object($update) && ($update->response ?? '') === 'upgrade') {
                        $result['core'][] = [
                            'version' => (string) ($update->version ?? ''),
                            'php' => (string) ($update->php_version ?? ''),
                            'locale' => (string) ($update->locale ?? ''),
                        ];
                    }
                }
            }

            $plugins = get_site_transient('update_plugins');

            if (is_object($plugins)) {
                foreach ((array) ($plugins->response ?? []) as $file => $update) {
                    if (!is_object($update)) {
                        continue;
                    }

                    $result['plugins'][] = [
                        'file' => (string) $file,
                        'slug' => (string) ($update->slug ?? ''),
                        'new_version' => (string) ($update->new_version ?? ''),
                    ];
                }
            }

            $themes = get_site_transient('update_themes');

            if (is_object($themes)) {
                foreach ((array) ($themes->response ?? []) as $slug => $update) {
                    $result['themes'][] = [
                        'slug' => (string) $slug,
                        'new_version' => (string) (is_array($update) ? ($update['new_version'] ?? '') : ''),
                    ];
                }
            }

            return $result;
        }

        public static function count(): int
        {
            $update = self::check();

            return count($update['core']) + count($update['plugins']) + count($update['themes']);
        }
    }

    /**
     * Environment health check producing actionable findings.
     */
    final class Health
    {
        private function __construct()
        {
        }

        /**
         * @return list<array{code: string, severity: 'info'|'warning'|'critical', message: string}>
         */
        public static function check(): array
        {
            $issues = [];

            // PHP version support window.
            if (\PHP_VERSION_ID < 80300) {
                $issues[] = self::issue('php-eol', 'critical', 'PHP ' . \PHP_VERSION . ' is below the 8.3 engine requirement.');
            }

            // Debug mode in what looks like production.
            if (\defined('WP_DEBUG') && \WP_DEBUG === true && \defined('WP_DEBUG_DISPLAY') && \WP_DEBUG_DISPLAY === true) {
                $issues[] = self::issue('debug-display', 'warning', 'WP_DEBUG_DISPLAY is on: errors leak into HTML output.');
            }

            // Memory limit.
            $memory = self::iniBytes((string) ini_get('memory_limit'));

            if ($memory > 0 && $memory < 64 * 1024 * 1024) {
                $issues[] = self::issue('memory-low', 'warning', 'memory_limit is below 64M (' . ini_get('memory_limit') . ').');
            }

            // Disk space.
            if (function_exists('wp_upload_dir')) {
                $uploads = wp_upload_dir();
                $base = is_array($uploads) ? (string) ($uploads['basedir'] ?? '') : '';

                if ($base !== '' && is_dir($base)) {
                    if (!is_writable($base)) {
                        $issues[] = self::issue('uploads-ro', 'critical', 'The uploads directory is not writable.');
                    }

                    $free = @disk_free_space($base);

                    if ($free !== false && $free < 100 * 1024 * 1024) {
                        $issues[] = self::issue('disk-low', 'critical', 'Less than 100 MB of free disk space.');
                    } elseif ($free !== false && $free < 512 * 1024 * 1024) {
                        $issues[] = self::issue('disk-low', 'warning', 'Less than 512 MB of free disk space.');
                    }
                }
            }

            // Database connectivity.
            $wpdb = $GLOBALS['wpdb'] ?? null;

            if (is_object($wpdb) && method_exists($wpdb, 'check_connection')) {
                if (!$wpdb->check_connection($silent = true)) {
                    $issues[] = self::issue('db-down', 'critical', 'The database connection is not alive.');
                }
            }

            // Cron.
            if (\defined('DISABLE_WP_CRON') && \DISABLE_WP_CRON === true) {
                $issues[] = self::issue('cron-external', 'info', 'DISABLE_WP_CRON is on: make sure a real crontab entry calls wp-cron.php.');
            }

            // Pending updates.
            if (class_exists(Updates::class) && function_exists('get_site_transient')) {
                $pending = Updates::count();

                if ($pending > 0) {
                    $issues[] = self::issue('updates-pending', 'info', $pending . ' update(s) available for core, plugins or themes.');
                }
            }

            // Engine diagnostics backlog.
            if (function_exists('html_errors')) {
                $errors = count(html_errors());

                if ($errors > 0) {
                    $issues[] = self::issue('engine-errors', 'warning', $errors . ' template diagnostic(s) pending — inspect html_errors().');
                }
            }

            if ($issues === []) {
                $issues[] = self::issue('ok', 'info', 'No problems detected.');
            }

            return $issues;
        }

        /**
         * @return array{code: string, severity: string, message: string}
         */
        private static function issue(string $code, string $severity, string $message): array
        {
            return ['code' => $code, 'severity' => $severity, 'message' => $message];
        }

        /**
         * Parses shorthand byte values ("256M", "1G").
         */
        public static function iniBytes(string $value): int
        {
            $value = trim($value);

            if ($value === '' || $value === '-1') {
                return $value === '-1' ? \PHP_INT_MAX : 0;
            }

            if (preg_match('~^(\d+(?:\.\d+)?)\s*([kmg]?)b?$~i', $value, $m) !== 1) {
                return (int) $value;
            }

            $factor = match (strtolower($m[2])) {
                'g' => 1024 ** 3,
                'm' => 1024 ** 2,
                'k' => 1024,
                default => 1,
            };

            return (int) ((float) $m[1] * $factor);
        }
    }

    /**
     * Asset registration with automatic filemtime cache busting.
     */
    final class Assets
    {
        private function __construct()
        {
        }

        /**
         * Enqueues a stylesheet; the version is the file mtime when the file
         * is readable, so deploys bust proxies and browser caches.
         *
         * @param list<string> $deps
         */
        public static function style(string $handle, string $src, array $deps = [], string $media = 'all'): void
        {
            self::enqueue('style', $handle, $src, $deps, $media);
        }

        /**
         * @param list<string> $deps
         */
        public static function script(string $handle, string $src, array $deps = [], bool $inFooter = true): void
        {
            self::enqueue('script', $handle, $src, $deps, null, $inFooter);
        }

        private static function enqueue(string $kind, string $handle, string $src, array $deps, ?string $media, bool $inFooter = false): void
        {
            $version = self::version($src);

            if ($kind === 'style') {
                if (function_exists('wp_register_style')) {
                    wp_register_style($handle, $src, $deps, $version, $media);
                }

                if (function_exists('wp_enqueue_style')) {
                    wp_enqueue_style($handle);
                }

                return;
            }

            if (function_exists('wp_register_script')) {
                wp_register_script($handle, $src, $deps, $version, $inFooter);
            }

            if (function_exists('wp_enqueue_script')) {
                wp_enqueue_script($handle);
            }
        }

        /**
         * Resolves the cache-busting version for a URL or path.
         */
        public static function version(string $src): string
        {
            $path = $src;

            if (preg_match('~^https?://~i', $path) === 1) {
                if (function_exists('home_url')) {
                    $home = (string) home_url();

                    if ($home !== '' && str_starts_with($path, $home)) {
                        $relative = substr($path, strlen($home));

                        if (function_exists('wp_normalize_path') && \defined('ABSPATH')) {
                            $path = rtrim((string) \ABSPATH, '/\\') . '/' . ltrim($relative, '/');
                        }
                    }
                }
            }

            if (is_file($path)) {
                $mtime = @filemtime($path);

                if ($mtime !== false) {
                    return (string) $mtime;
                }
            }

            return function_exists('html_version') ? (string) html_version() : '3.0.0';
        }
    }

    /**
     * REST route registration with enforced permission callbacks.
     */
    final class Rest
    {
        private function __construct()
        {
        }

        /**
         * Registers a route; refusing registrations without an explicit
         * permission callback is intentional (WP security guideline).
         */
        public static function route(
            string $namespace,
            string $route,
            callable $handler,
            string $methods = 'GET',
            ?callable $permission = null
        ): void {
            if (!function_exists('register_rest_route')) {
                throw new TemplateException('The REST API is not available.');
            }

            register_rest_route($namespace, $route, [
                'methods' => $methods,
                'callback' => $handler,
                'permission_callback' => $permission ?? static fn(): bool => current_user_can('manage_options'),
            ]);
        }

        /**
         * Standard JSON success envelope.
         *
         * @return array{success: true, data: mixed}
         */
        public static function ok(mixed $data): array
        {
            return ['success' => true, 'data' => $data];
        }

        /**
         * Standard JSON error envelope.
         *
         * @return array{success: false, code: string, message: string}
         */
        public static function fail(string $code, string $message): array
        {
            return ['success' => false, 'code' => $code, 'message' => $message];
        }
    }
}

namespace HtmlPhp\WordPress\Cli {

    use HtmlPhp\WordPress\Maintenance\Health;
    use HtmlPhp\WordPress\Maintenance\Logs;
    use HtmlPhp\WordPress\Maintenance\SystemInfo;
    use HtmlPhp\WordPress\Maintenance\Updates;

    /**
     * WP-CLI commands: wp htmlphp info | health | updates | render | cache | logs.
     *
     * Registered automatically by Bridge::boot(['cli' => true]) when WP-CLI
     * is running.
     */
    final class Commands
    {
        public static function register(): void
        {
            if (class_exists('\WP_CLI')) {
                \WP_CLI::add_command('htmlphp', self::class);
            }
        }

        /**
         * Prints the system information snapshot.
         *
         * @param list<string> $args
         * @param array<string, mixed> $assoc
         */
        public function info(array $args = [], array $assoc = []): void
        {
            $json = (bool) ($assoc['json'] ?? false);
            $info = SystemInfo::collect();

            if ($json) {
                \WP_CLI::line((string) json_encode($info, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

                return;
            }

            foreach ($info as $section => $values) {
                \WP_CLI::log('== ' . strtoupper((string) $section) . ' ==');

                foreach ((array) $values as $key => $value) {
                    \WP_CLI::log(sprintf(
                        '  %-22s %s',
                        (string) $key,
                        is_scalar($value) ? (string) $value : (string) json_encode($value)
                    ));
                }
            }
        }

        /**
         * Runs the health check.
         *
         * @param list<string> $args
         * @param array<string, mixed> $assoc
         */
        public function health(array $args = [], array $assoc = []): void
        {
            foreach (Health::check() as $issue) {
                $line = sprintf('[%s] %s: %s', strtoupper($issue['severity']), $issue['code'], $issue['message']);

                match ($issue['severity']) {
                    'critical' => \WP_CLI::error($line, false),
                    'warning' => \WP_CLI::warning($line),
                    default => \WP_CLI::log($line),
                };
            }
        }

        /**
         * Lists available updates.
         *
         * @param list<string> $args
         * @param array<string, mixed> $assoc
         */
        public function updates(array $args = [], array $assoc = []): void
        {
            $updates = Updates::check();

            foreach ($updates['core'] as $core) {
                \WP_CLI::log('core    → ' . $core['version']);
            }

            foreach ($updates['plugins'] as $plugin) {
                \WP_CLI::log('plugin  → ' . $plugin['file'] . ' ' . $plugin['new_version']);
            }

            foreach ($updates['themes'] as $theme) {
                \WP_CLI::log('theme   → ' . $theme['slug'] . ' ' . $theme['new_version']);
            }

            $total = count($updates['core']) + count($updates['plugins']) + count($updates['themes']);

            if ($total === 0) {
                \WP_CLI::success('Everything is up to date.');
            }
        }

        /**
         * Renders a template through the engine.
         *
         * ## OPTIONS
         *
         * <template>   Path to the template file.
         * [--var=<kv>] Repeatable NAME=value variables.
         *
         * @param list<string> $args
         * @param array<string, mixed> $assoc
         */
        public function render(array $args = [], array $assoc = []): void
        {
            $template = (string) ($args[0] ?? '');

            if ($template === '' || !is_file($template)) {
                \WP_CLI::error('Template file not found: ' . $template);

                return;
            }

            $vars = [];

            foreach ((array) ($assoc['var'] ?? []) as $pair) {
                $pair = (string) $pair;
                $position = strpos($pair, '=');

                if ($position !== false) {
                    $vars[substr($pair, 0, $position)] = substr($pair, $position + 1);
                }
            }

            \WP_CLI::line(html_render_file($template, $vars, ['file' => $template]));
        }

        /**
         * Manages the render cache: wp htmlphp cache flush.
         *
         * @param list<string> $args
         * @param array<string, mixed> $assoc
         */
        public function cache(array $args = [], array $assoc = []): void
        {
            $action = (string) ($args[0] ?? 'flush');

            if ($action === 'flush') {
                $removed = html_cache_clear();
                \WP_CLI::success(sprintf('Removed %d cache entr%s.', $removed, $removed === 1 ? 'y' : 'ies'));

                return;
            }

            \WP_CLI::error('Unknown cache action: ' . $action);
        }

        /**
         * Tails the maintenance log: wp htmlphp logs [lines].
         *
         * @param list<string> $args
         * @param array<string, mixed> $assoc
         */
        public function logs(array $args = [], array $assoc = []): void
        {
            $lines = max(1, min(1000, (int) ($args[0] ?? 50)));

            foreach (Logs::tail($lines) as $line) {
                \WP_CLI::log($line);
            }
        }
    }
}

namespace {

    use HtmlPhp\WordPress\Bridge;
    use HtmlPhp\WordPress\Maintenance\Notices;
    use HtmlPhp\WordPress\Shortcodes;
    use HtmlPhp\WordPress\TemplateLoader;

    /**
     * Boots the HTML.PHP WordPress bridge.
     *
     * @param array<string, mixed> $config see Bridge::boot()
     */
    function html_wp_boot(array $config = []): bool
    {
        Bridge::boot($config);

        return true;
    }

    /**
     * True when running inside WordPress.
     */
    function html_wp_is_wordpress(): bool
    {
        return Bridge::isWordPress();
    }

    /**
     * Renders a theme/plugin template through the engine.
     *
     * @param string|list<string> $names
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $options
     * @param list<string> $dirs
     */
    function html_wp_render(string|array $names, array $vars = [], array $options = [], array $dirs = []): string
    {
        return TemplateLoader::render($names, $vars, $options, $dirs);
    }

    /**
     * Registers a template-driven shortcode.
     *
     * @param array<string, mixed> $defaults
     */
    function html_wp_shortcode(string $tag, string $template, array $defaults = []): void
    {
        Shortcodes::register($tag, $template, $defaults);
    }

    /**
     * Queues a one-shot admin notice.
     *
     * @param 'info'|'success'|'warning'|'error' $type
     */
    function html_wp_notice(string $text, string $type = 'info', bool $dismissible = true): void
    {
        Notices::add($text, $type, $dismissible);
    }
}
