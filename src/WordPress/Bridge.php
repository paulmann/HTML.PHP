<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress;

use HtmlPhp\Config;
use HtmlPhp\Container;
use HtmlPhp\Diagnostics;
use HtmlPhp\Engine;
use HtmlPhp\Template\RenderStats;

/**
 * The WordPress bootstrap — the *only* place in the library that owns a
 * process-wide Engine instance (a plugin lifecycle needs one; plain PHP
 * hosts construct engines explicitly and never touch this class).
 *
 * ```php
 * // in your plugin, on plugins_loaded:
 * HtmlPhp\WordPress\Bridge::boot([
 *     'templates' => ['paths' => [__DIR__ . '/templates']],
 * ], shortcode: 'htmlphp');
 *
 * echo htmlphp_render('card.html', ['title' => get_the_title()]);
 * ```
 *
 * Booting wires: WP-flavoured config defaults (theme template dirs, uploads
 * cache dir, WP object-cache backend, rotating log, WP i18n delegation),
 * engine→WP event mirroring (`htmlphp.render` → `apply_filters('htmlphp_render')`),
 * the admin bar stats node (debug only), optional `the_content` SSI filter,
 * WP-CLI commands and the admin screen.
 */
final class Bridge
{
    private static ?Engine $engine = null;
    private static bool $booted = false;

    /** @var array<string, mixed> */
    private static array $options = [];

    private function __construct() {}

    /**
     * Boots (once) and returns the engine.
     *
     * @param Config|array<string, mixed> $config engine configuration; WP
     *        defaults are merged underneath, yours win
     * @param (callable(Container): void)|null $setup container overrides
     * @param array<string, mixed> $options bridge behaviour:
     *        `shortcode` (string|null — register `[shortcode]` with a
     *        `template` attribute), `content_ssi` (bool, default false —
     *        run SSI over `the_content`), `admin_bar` (bool, default
     *        WP_DEBUG), `cli` (bool, default true), `admin_page`
     *        (bool, default true), `mirror_events` (bool, default true)
     */
    public static function boot(Config|array $config = [], ?callable $setup = null, array $options = []): Engine
    {
        if (self::$booted && self::$engine !== null) {
            return self::$engine;
        }

        self::$options = $options + [
            'shortcode' => null,
            'content_ssi' => false,
            'admin_bar' => \defined('WP_DEBUG') && (bool) \constant('WP_DEBUG'),
            'cli' => true,
            'admin_page' => true,
            'mirror_events' => true,
        ];

        $config = $config instanceof Config ? $config->toArray() : $config;
        $engine = Engine::create(self::mergeDefaults($config), static function (Container $container) use ($setup): void {
            if ($setup !== null) {
                $setup($container);
            }
        });

        self::$engine = $engine;
        self::$booted = true;

        self::installHooks($engine);

        return $engine;
    }

    /**
     * WordPress-flavoured defaults under the caller's config.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function mergeDefaults(array $config): array
    {
        $paths = $config['templates']['paths'] ?? [];
        $themePaths = [];

        if (\function_exists('get_stylesheet_directory')) {
            $themePaths[] = rtrim((string) get_stylesheet_directory(), '/\\') . '/htmlphp';
        }

        if (\function_exists('get_template_directory')) {
            $themePaths[] = rtrim((string) get_template_directory(), '/\\') . '/htmlphp';
        }

        $defaults = [
            'templates' => [
                'paths' => [...$themePaths, ...$paths],
            ],
            'ssi' => [
                'document_root' => \defined('ABSPATH') ? rtrim((string) \constant('ABSPATH'), '/\\') : null,
            ],
            'cache' => [
                'enabled' => true,
                'driver' => \function_exists('wp_cache_get') ? 'wordpress' : 'file',
                'directory' => self::uploadsCacheDir(),
            ],
            'log' => [
                'driver' => 'wordpress',
                'level' => 'warning',
            ],
        ];

        $merged = self::mergeDeep($defaults, $config);

        // document_root null is invalid for the strict config reader; drop it.
        if (($merged['ssi']['document_root'] ?? null) === null) {
            unset($merged['ssi']['document_root']);
        }

        return $merged;
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overrides
     * @return array<array-key, mixed>
     */
    private static function mergeDeep(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key])) {
                $base[$key] = self::mergeDeep($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    private static function uploadsCacheDir(): string
    {
        if (\function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();
            $base = (string) ($uploads['basedir'] ?? '');

            if ($base !== '') {
                return $base . '/htmlphp-cache';
            }
        }

        return sys_get_temp_dir() . '/htmlphp-cache';
    }

    private static function installHooks(Engine $engine): void
    {
        if (!\function_exists('add_action')) {
            return;
        }

        if (self::$options['mirror_events']) {
            self::mirrorEvents($engine);
        }

        if (\is_string(self::$options['shortcode']) && self::$options['shortcode'] !== '') {
            self::registerGenericShortcode($engine, (string) self::$options['shortcode']);
        }

        if (self::$options['content_ssi']) {
            add_filter('the_content', static function (string $content) use ($engine): string {
                if (!str_contains($content, '<!--#')) {
                    return $content;
                }

                try {
                    return $engine->processSsi($content);
                } catch (\Throwable $error) {
                    $engine->diagnostics()->record(
                        Diagnostics::WP_BRIDGE,
                        'Content SSI failed: ' . $error->getMessage(),
                    );

                    return $content;
                }
            }, 10, 1);
        }

        if (self::$options['admin_bar']) {
            add_action('admin_bar_menu', static function (mixed $bar) use ($engine): void {
                $stats = $engine->stats();

                if (!$stats instanceof RenderStats || !\is_object($bar) || !method_exists($bar, 'add_node')) {
                    return;
                }

                $bar->add_node([
                    'id' => 'htmlphp-stats',
                    'title' => \sprintf('HTML.PHP %.2fms/%d tpl', $stats->durationMs, \count($stats->templates)),
                    'href' => admin_url('tools.php?page=htmlphp'),
                ]);
            }, 999);
        }

        if (self::$options['cli'] && class_exists('\WP_CLI')) {
            \WP_CLI::add_command('htmlphp', Cli::class);
        }

        if (self::$options['admin_page']) {
            add_action('admin_menu', static function () use ($engine): void {
                AdminPage::install($engine);
            });
            add_action('admin_post_htmlphp_action', static function () use ($engine): void {
                AdminPage::handlePost($engine);
            });
        }
    }

    /**
     * Mirrors the engine's tagged events onto WordPress hooks so themes and
     * plugins can filter without knowing the engine exists.
     */
    private static function mirrorEvents(Engine $engine): void
    {
        $dispatcher = $engine->events();

        $dispatcher->on('htmlphp.render', static function (string $text, string $origin) {
            return \function_exists('apply_filters') ? apply_filters('htmlphp_render', $text, $origin) : $text;
        }, 1000);

        $dispatcher->on('htmlphp.error', static function (string $code, string $message, array $context): void {
            if (\function_exists('do_action')) {
                do_action('htmlphp_error', $code, $message, $context);
            }
        });

        $dispatcher->on('htmlphp.translate', static function (string $text, string $msgid, string $domain, string $locale) {
            return \function_exists('apply_filters')
                ? apply_filters('htmlphp_translate', $text, $msgid, $domain, $locale)
                : $text;
        }, 1000);
    }

    /**
     * `[tag template="card.html" key="value"]` — generic template shortcode.
     * The template name resolves through {@see TemplateLoader} and the
     * engine locator; attributes become variables.
     */
    private static function registerGenericShortcode(Engine $engine, string $tag): void
    {
        $tag = preg_replace('~[^a-z0-9_-]~i', '', $tag) ?? '';

        if ($tag === '' || !\function_exists('add_shortcode')) {
            return;
        }

        add_shortcode($tag, static function (mixed $atts, mixed $content = null) use ($engine): string {
            $attributes = \is_array($atts) ? $atts : [];
            $template = '';

            if (isset($attributes['template']) && \is_scalar($attributes['template'])) {
                $template = (string) $attributes['template'];
                unset($attributes['template']);
            }

            if ($template === '') {
                return '';
            }

            $vars = ['content' => \is_string($content) ? $content : ''];

            foreach ($attributes as $key => $value) {
                if (\is_string($key) && \HtmlPhp\Support\Str::isValidVariableName($key)) {
                    $vars[$key] = \is_scalar($value) || $value === null ? $value : '';
                }
            }

            try {
                $path = TemplateLoader::locate($template);

                return $path !== null
                    ? $engine->renderFile($path, $vars, new \HtmlPhp\Template\RenderOptions(file: $path))
                    : $engine->render($template, $vars);
            } catch (\Throwable $error) {
                $engine->diagnostics()->record(
                    Diagnostics::WP_BRIDGE,
                    'Shortcode render failed: ' . $error->getMessage(),
                    ['template' => $template],
                );

                return '';
            }
        });
    }

    public static function engine(): Engine
    {
        if (self::$engine === null) {
            throw new \RuntimeException(
                'HtmlPhp\WordPress\Bridge is not booted — call Bridge::boot() on plugins_loaded.',
            );
        }

        return self::$engine;
    }

    public static function booted(): bool
    {
        return self::$booted;
    }

    /** Drops the singleton (tests, and only tests). */
    public static function reset(): void
    {
        self::$engine = null;
        self::$booted = false;
        self::$options = [];
    }
}
