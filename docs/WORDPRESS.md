# WordPress Integration Guide

Two deliverables live in `wordpress/`:

1. **`html-wp.php`** — the bridge + maintenance toolkit (a library file you
   require from your plugin/theme);
2. **`html-php-maintenance/`** — a ready-made plugin that exposes the
   toolkit as a Tools → HTML.PHP dashboard, a daily health cron, a dashboard
   widget and WP-CLI commands. The plugin's admin page is itself rendered by
   the engine (SSI + loops + conditionals + escaping), so it doubles as a
   living demo.

Both work with either engine edition; `Bridge::ensureEngine()` picks
`php85/html.php` on PHP 8.5+ and `php83/html.php` otherwise (override with
the `HTML_WP_ENGINE` constant).

## Booting from your plugin

```php
<?php
require_once __DIR__ . '/vendor-or-relative/html-wp.php';

html_wp_boot([
    'escaper'      => true,                       // install esc_html as HTML_ESCAPER
    'i18n'         => 'auto',                     // 'auto'|'wp'|'engine'|'off'
    'locale'       => true,                       // sync with determine_locale()
    'notices'      => true,                       // render the notice queue
    'cli'          => true,                       // register wp htmlphp …
    'shortcodes'   => ['cta' => __DIR__ . '/templates/cta.htm'],
    'translations' => ['myplugin' => __DIR__ . '/languages'],
    'config'       => ['HTML_CACHE_TTL' => 300],  // engine overrides
]);
```

What boot does:

* installs `esc_html()` as the engine's text escaper (so the `esc_html`
  filter chain of WordPress applies to `%{VAR}` auto-escaping and SSI echo);
* sets translation delegation (`HTML_I18N_WP`) per the `i18n` mode;
* syncs `HTML_I18N_LOCALE` with `determine_locale()`;
* loads your engine catalogues for the active locale (WP's own textdomains
  keep working through delegation);
* registers template shortcodes and the admin-notice renderer.

## Templates & shortcodes

```php
// Render any located template through the engine (SSI + loops + i18n):
echo html_wp_render('template-parts/card', ['post_title' => get_the_title()]);

// get_template_part-style with a variant name:
echo HtmlPhp\WordPress\TemplateLoader::renderPart('content', 'single', $vars);

// Template-driven shortcode: [cta url="…" label="…"]content[/cta]
html_wp_shortcode('cta', __DIR__ . '/templates/cta.htm', ['label' => 'Buy']);
```

* Location order: child theme → parent theme (`locate_template()`) →
  explicit directories → direct paths. Bare slugs try the suffixes
  `.ssi.htm`, `.ssi.html`, `.ssi`, `.htm`, `.html`, `.tpl`, `.php`.
* `.php` templates are included in an isolated scope (variables extracted,
  output captured); everything else goes through `html_render_file()`.
* Every render receives context variables: `wp_blogname`, `wp_description`,
  `wp_version`, `wp_charset`, `wp_language`, `wp_home_url`, `wp_locale`,
  `wp_year`.
* Shortcode attributes become **scoped** variables (gated by the
  variable-name policy); the enclosed content is available as `%{content}`.
  Render failures never propagate — they return `''` and record a
  `wp-bridge` diagnostic.

## Maintenance toolkit (`HtmlPhp\WordPress\Maintenance\…`)

| Class | Highlights |
|---|---|
| `Nonces` | `create/verify/verifyRequest/field` |
| `Options` | prefixed, typed: `get/set/delete/getInt/getBool/getArray` |
| `Transients` | `get/set/delete` + `remember($key, $ttl, $factory)` |
| `Notices` | one-shot admin notice queue (`html_wp_notice()`), escaped output, auto-installed on `admin_notices` |
| `Db` | `prepare/query/getVar/getRow/getResults` (always through `$wpdb->prepare`), `tableSizes()` from information_schema, `countTable()` |
| `Cron` | `schedule` (idempotent), `unschedule`, `next`, `disabledByConstant()` |
| `Logs` | rotating daily files in `{uploads}/html-php-logs/` (`HTML_WP_LOG_DIR` to override), `write/info/error/tail/rotate(keep)` |
| `SystemInfo` | WP/PHP/server/engine snapshot for support tickets and audits |
| `Updates` | pending core/plugin/theme updates from the update transients |
| `Health` | actionable findings: PHP support window, debug display, memory limit, disk space, uploads writability, DB connection, cron mode, pending updates, engine diagnostics backlog |
| `Assets` | `style()/script()` registration with **filemtime cache busting** |
| `Rest` | `register_rest_route` wrapper that refuses to register without a permission callback (defaults to `manage_options`), plus `ok()/fail()` envelopes |

Global shortcuts: `html_wp_boot()`, `html_wp_is_wordpress()`,
`html_wp_render()`, `html_wp_shortcode()`, `html_wp_notice()`.

## WP-CLI

```bash
wp htmlphp info [--json]      # system snapshot
wp htmlphp health             # health findings (exit-friendly severities)
wp htmlphp updates            # pending core/plugin/theme updates
wp htmlphp render tpl.htm --var=name:value   # render through the engine
wp htmlphp cache flush        # purge the render cache
wp htmlphp logs [lines]       # tail the maintenance log
```

Commands self-register when WP-CLI runs and `html_wp_boot(['cli' => true])`
executes (the maintenance plugin does this for you).

## The maintenance plugin

`wordpress/html-php-maintenance/` is a complete plugin:

* **Tools → HTML.PHP** page: health table, updates table, full system
  snapshot, action buttons (run health check, flush render cache, write test
  log entry) and a log tail — all rendered by the engine from
  `views/admin.ssi.htm`;
* **daily cron** (`htmlphp_daily_health`): stores findings in a transient and
  queues an admin notice on critical results;
* **dashboard widget** with the top findings;
* activation/deactivation lifecycle logging;
* every POST action is capability- and nonce-checked.

Install by copying the `html-php-maintenance` folder **together with** the
engine and `html-wp.php` into `wp-content/plugins/html-php/` (the plugin
expects `../html-wp.php`), or adjust the single `require_once` line.

## Testing without WordPress

`tests/wp-stubs.php` emulates the WP surface used by the bridge (hooks,
options, transients, nonces, shortcodes, `$wpdb`, cron, assets, REST,
WP-CLI, `wp_upload_dir`, …). The suite's `18_wordpress` group runs the
bridge and the plugin end-to-end against these stubs — no database, no HTTP.
