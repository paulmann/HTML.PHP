# WordPress Integration Guide

WordPress is a **first-class but optional** host. The core library has zero
WordPress knowledge; everything WP-specific lives in `HtmlPhp\WordPress\*`
and only activates inside WordPress (function/class existence guards).

Two ways in:

1. **The bundled plugin** — [`wordpress/html-php/`](../wordpress/html-php/):
   loader, activation hooks, uninstall cleanup, engine-rendered admin views,
   `htmlphp_render()` / `htmlphp_t()` theme helpers, `[htmlphp]` shortcode.
2. **Your own plugin/theme** — boot the bridge yourself (below).

---

## Booting from your plugin

```php
add_action('plugins_loaded', static function (): void {
    require_once __DIR__ . '/lib/php85/html.php';  // or php83/html.php / Composer

    HtmlPhp\WordPress\Bridge::boot([
        'templates' => ['paths' => [__DIR__ . '/templates']],
        'i18n'      => ['domain' => 'my-plugin'],
        'cache'     => ['ttl' => 300],
    ], setup: null, options: [
        'shortcode'   => 'mycard',   // registers [mycard template="..." k="v"]
        'content_ssi' => true,       // SSI pass over the_content (opt-in!)
        'admin_page'  => true,       // Tools → HTML.PHP screen
        'admin_bar'   => null,       // default: WP_DEBUG
        'cli'         => true,       // wp htmlphp …
        'mirror_events' => true,     // engine events → WP hooks
    ]);
});
```

`Bridge::boot()` merges WordPress-flavoured defaults **under** your config:

- `templates.paths` += `{child theme}/htmlphp`, `{parent theme}/htmlphp`
- `ssi.document_root` = `ABSPATH`
- `cache` = enabled, driver `wordpress` (object cache + transients), or
  `file` in `{uploads}/htmlphp-cache` without an object cache
- `log.driver` = `wordpress` (rotating daily files in
  `{uploads}/html-php-logs/` + `debug.log` mirror when `WP_DEBUG_LOG`)
- i18n delegates to `__()`/`_n()`/`_x()`/`_nx()` automatically
  (`i18n.use_wordpress` to force either way)

`Bridge::engine()` returns the booted instance; `Bridge::reset()` exists for
tests only. This singleton is the **one** documented exception to the
"explicit everything" rule — plugin lifecycles need it; plain PHP hosts
construct engines directly.

## Rendering in themes & plugins

```php
// helper (plugin-provided): locator → theme dirs → engine
echo htmlphp_render('cards/author.html', ['author' => get_user_by('id', $id)]);

// or explicitly:
$loader = new HtmlPhp\WordPress\TemplateLoader(Bridge::engine());
echo $loader->render(['card', 'cards/card'], ['post' => get_post()]);  // suffixes tried
echo $loader->renderPart('content', 'single', ['post' => get_post()]); // get_template_part-style, returns string

// every render auto-provides wp_ context vars:
// %{wp_blogname} %{wp_description} %{wp_home_url} %{wp_locale} %{wp_year} …
```

`TemplateLoader::locate()` search order: child theme → parent theme
(`locate_template()`) → your extra dirs. `.php` files are **never** treated
as templates (v3 allowed it — a source-disclosure risk; v4 refuses).

## Shortcodes

```php
(new HtmlPhp\WordPress\Shortcodes(Bridge::engine()))
    ->register('author_card', 'cards/author.html', ['role' => 'guest']);
// [author_card name="Ada"] → renders cards/author.html with name + role + %{content}
```

The bridge's generic shortcode (`options.shortcode`) works without code:
`[htmlphp template="cards/author.html" name="Ada"]`. Attributes become
scope variables (invalid names dropped); render failures return `''` +
`wp-bridge` diagnostic — a broken template can never white-screen a page.

## Hooks mirror

| Engine event | WordPress hook | Type |
|---|---|---|
| `htmlphp.render` | `apply_filters('htmlphp_render', $text, $origin)` | filter |
| `htmlphp.error` | `do_action('htmlphp_error', $code, $message, $context)` | action |
| `htmlphp.translate` | `apply_filters('htmlphp_translate', $text, $msgid, $domain, $locale)` | filter |

## Content SSI (opt-in)

With `options.content_ssi`, `the_content` passes through the SSI processor —
editors can use `<!--#include virtual="/legal/disclaimer.html" -->` inside
posts. `#exec` stays denied (policy is config, not per-filter), includes are
root-contained and extension-guarded. **Only enable on trusted-author sites.**

## Maintenance toolkit (`HtmlPhp\WordPress\Maintenance\*`)

| Class | Highlights |
|---|---|
| `Nonces` | `create/verify/verifyRequest/field` |
| `Options` | prefixed, typed: `get/set/delete/getInt/getBool/getArray` |
| `Transients` | `get/set/delete` + `remember($key, $ttl, $factory)` |
| `Notices` | one-shot admin notice queue, escaped output |
| `Db` | always-`prepare`d `query/getVar/getRow/getResults`, `tableSizes()`, `countTable()` |
| `Cron` | idempotent `schedule`, `unschedule`, `next`, `disabledByConstant()` |
| `Logs` | rotating daily files in `{uploads}/html-php-logs/`, `write/info/error/tail/rotate(keep)` |
| `SystemInfo` | WP/PHP/server/engine snapshot (pass the engine for live stats) |
| `Updates` | pending core/plugin/theme updates from update transients |
| `Health` | actionable findings: PHP support window, debug display, memory, disk, uploads writability, DB, cron, pending updates, **engine diagnostics backlog** |
| `Assets` | `style()/script()` registration with **filemtime cache busting** |
| `Rest` | `route()` that always attaches a permission callback (defaults to `manage_options`), `ok()/fail()` envelopes |

## Admin screen

Tools → **HTML.PHP**: Health, Templates (lint report over every configured
root), Cache (driver + clear), i18n (locale, catalogue stats), Logs (tail),
Info (SystemInfo). If your theme ships `htmlphp/admin/htmlphp/{tab}.html`,
that tab is rendered **by the engine itself** with `tab/tabs/data/nonce_field/
admin_post_url/page_url` variables (dog-fooding — see the bundled
`views/admin/htmlphp/health.html`). All POST actions are nonce +
`manage_options` protected.

## Mailer

```php
HtmlPhp\WordPress\Mailer::send(
    Bridge::engine(),
    $user->user_email,
    'emails/welcome-subject.txt',   // template, path, or inline '@{key}'
    'emails/welcome.html',
    ['name' => $user->display_name]
);
```

Subjects render unescaped (plain text), bodies escaped per config;
`Content-Type: text/html` is added unless you provide your own headers.

## WP-CLI

```
wp htmlphp info [--json]
wp htmlphp render <template> [--var=k:v]…
wp htmlphp lint [<path-or-dir>]        # exit 1 on errors — CI ready
wp htmlphp cache clear
wp htmlphp i18n extract <dir> [--out=messages.pot]
wp htmlphp i18n audit <dir> [--locale=de] [--domain=messages]
wp htmlphp health
```

## Multisite

Cache groups are blog-suffixed (`htmlphp_{blogId}`), options/transients are
per-site by default, and the file cache dir lives in the (per-site) uploads
directory. Nothing else is site-aware by design.

## Testing without WordPress

The repository's `tests/wp-stubs.php` (+ `wp-stubs-extra.php`) stub the WP
API surface the bridge touches — the v4 test suite runs the whole
WordPress layer under plain CLI PHP. Reuse them in your own CI.

## Roadmap (deliberately out of scope for 4.0)

- Gutenberg block (`htmlphp/template`) — needs a JS build pipeline
- Full-site-editing block themes integration
- REST render endpoint (attack surface not worth it by default)
