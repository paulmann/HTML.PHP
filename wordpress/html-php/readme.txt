=== HTML.PHP ===
Contributors: paulmann
Tags: templates, ssi, i18n, shortcodes, maintenance
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.3
Stable tag: 4.0.0
License: MIT

Template engine with Apache SSI directives, gettext-compatible i18n and a
WordPress maintenance toolkit. Zero dependencies.

== Description ==

HTML.PHP 4 is a production template processor for any PHP 8.3+ code, with
WordPress as a first-class (but optional) host:

* `%{VAR|filters}` substitution with auto-escaping by default
* Loops, expression conditionals, layout inheritance, scoped partials
* Full Apache mod_include SSI: #include, #echo, #if/#elif/#else, #set,
  #config, #fsize, #flastmod, #printenv, #block/stub, policy-gated #exec
* gettext i18n: PO/MO/JSON/PHP catalogues, plural rules for 60+ languages,
  contexts, domains, fallback chains, extraction and audit tooling
* Render cache (WP object cache/transients or files), rotating logs,
  diagnostics, events mirrored to WP hooks
* Shortcode, theme template loader, `htmlphp_render()` helper, admin screen
  under Tools, and `wp htmlphp` CLI commands

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Ship the library: either `composer install` inside the plugin folder, or
   copy `php83/html.php` (PHP 8.3/8.4) or `php85/html.php` (PHP 8.5+) into
   `lib/php83/` resp. `lib/php85/`.
3. Activate. Find the admin screen under Tools → HTML.PHP.

== Changelog ==

= 4.0.0 =
* Full rewrite: immutable Config object + DI container, no $GLOBALS,
  instance-based services, PSR-shaped cache/log/event contracts.
* New: layouts (@Extends/@Section), partials, expression language,
  @Trans blocks, linter, i18n extract/audit, JSON/PHP catalogues,
  #printenv, #block/stub, echo encodings, WP object-cache backend,
  admin screen, Mailer, RenderStats admin bar.
* Removed: the legacy html4.pl API and all HTML_* global config keys
  (see docs/MIGRATION-3.x-TO-4.0.md).
