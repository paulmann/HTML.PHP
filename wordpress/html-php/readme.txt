=== HTML.PHP ===
Contributors: paulmann
Tags: templates, ssi, i18n, shortcodes, maintenance
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.3
Stable tag: 5.0.0
License: MIT

Template engine with Apache SSI directives, gettext-compatible i18n and a
WordPress maintenance toolkit. Zero dependencies.

== Description ==

HTML.PHP 5 is a production template processor for any PHP 8.3+ code, with
WordPress as a first-class (but optional) host:

* `%{VAR|filters}` substitution with auto-escaping by default
* Inline expressions: `%{= (part / total) * 100 | round:1 }` — sandboxed
  math, nested/dynamic keys (`rows[0].name`, `hash[keyVar]`, `get(name)`),
  arithmetic filters (`pct`, `mul`, `add`, `sum`, `avg`, `clamp`, …)
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

= 5.0.0 =
* Inline expressions in substitutions: `%{= expr |filters}` (gated by
  `syntax.expressions`, sandboxed evaluator, auto-escaped output).
* Nested/dynamic variable access: `[…]` indexing, `.prop` chains after
  indexes, indirect `get(name)` lookups — in every expression position.
* New math: expression functions `pow/sqrt/sum/avg/clamp/get`; filters
  `add/sub/mul/mod/pow/sqrt/pct/sum/avg/clamp`.
* Hardening: bounded AST memo, iteration budgets on host iterables,
  key validation for dynamic indexes, fixed expression-level ranges.
* Linter: validates `%{= …}`, warns on variable-looking numeric filter
  arguments; new diagnostics codes `expr-invalid`, `expr-disabled`.

= 4.0.0 =
* Full rewrite: immutable Config object + DI container, no $GLOBALS,
  instance-based services, PSR-shaped cache/log/event contracts.
* New: layouts (@Extends/@Section), partials, expression language,
  @Trans blocks, linter, i18n extract/audit, JSON/PHP catalogues,
  #printenv, #block/stub, echo encodings, WP object-cache backend,
  admin screen, Mailer, RenderStats admin bar.
* Removed: the legacy html4.pl API and all HTML_* global config keys
  (see docs/MIGRATION-3.x-TO-4.0.md).
