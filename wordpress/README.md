# WordPress distribution

- `html-php/` — the installable plugin (loader, activation hardening,
  uninstall cleanup, engine-rendered admin views, `htmlphp_render()` /
  `htmlphp_t()` helpers, `[htmlphp]` shortcode).

## Shipping the library with the plugin

The plugin auto-selects its library source:

1. `html-php/vendor/autoload.php` — when installed via Composer
   (`composer require paulmann/html-php85` on PHP 8.5+, else
   `paulmann/html-php`); **or**
2. `html-php/lib/php85/html.php` on PHP 8.5+, `html-php/lib/php83/html.php`
   on PHP 8.3/8.4 — copy the built bundle from the repository root
   (`php83/html.php`, `php85/html.php`) into `lib/php83/` resp. `lib/php85/`.

If neither exists the plugin stays inert and shows a single admin notice —
it never fatals.

## Plugin authors

Prefer booting the bridge from your own plugin (see
[docs/WORDPRESS.md](../docs/WORDPRESS.md)) — you get full control over
config, shortcodes and container overrides; the bundled plugin is the
zero-config path.
