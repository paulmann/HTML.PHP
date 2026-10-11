# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] - 2026-10-11

Full professional refactor of 2.0.1. The entire legacy public API is
preserved byte-for-byte in behaviour (the 2.0.1 test suite passes against
3.0.0); the suite grows from 276 tests / 748 assertions to **501 tests /
1276 assertions** and now runs as a matrix across PHP 8.3/8.4/8.5 and both
editions.

### Added

* **Two editions**: `php83/html.php` (portable, PHP 8.3–8.5, refuses older
  runtimes with a clear `RuntimeException`) and `php85/html.php` (modern,
  PHP 8.5 property hooks, readonly + asymmetric-visibility promotion, pipe
  operator, `array_first()`/`array_last()`, first-class callable syntax,
  typed class constants, `#[\NoDiscard]`). Identical API and behaviour.
* **Apache SSI completeness**: `#if`/`#elif`/`#else`/`#endif` with a safe
  expression evaluator (comparisons, `/regex/` matching, `&&`/`||`/`!`,
  parentheses, `$VAR`/`${VAR}`, file tests `-f -d -e -s -x -r -w`);
  `#exec cmd=`/`cgi=` (deny-by-default, allow-listed, shell-free argv
  spawning, timeout and output caps); `#fsize` and `#flastmod`;
  `#config sizefmt=` and `#config errmsg=`; `#include file=`;
  `#echo encoding=none|entity|url`; unquoted attribute values; new built-in
  variables (`REQUEST_METHOD`, `REMOTE_ADDR`, `SERVER_SOFTWARE`,
  `SERVER_PROTOCOL`, `REQUEST_URI`, `SCRIPT_NAME`, `CONTENT_TYPE`).
* **i18n subsystem**: gettext PO/MO catalogue parsers (contexts, plurals,
  fuzzy flags, charset conversion, bounds-checked MO), safe plural-form
  expression evaluator with built-in rules for 60+ languages, text domains,
  fallback chains, Accept-Language negotiation, `{named}` + sprintf
  interpolation; `html_t()/html_n()/html_x()/html_nx()`,
  `html_set_locale()/html_get_locale()/html_detect_locale()/
  html_load_translations()`; legacy `@{KEY}`/`Translate.KEY` now consult
  catalogues; WordPress delegation to `__()/_n()/_x()/_nx()`.
* **Modern rendering**: scoped `html_render()/html_render_file()` (dot
  notation, objects, ArrayAccess), 20+ built-in variable filters with
  chains and quoted arguments, custom filters via `html_var_filter()`,
  `<!--@Loop-->`/`<!--@If-->` blocks with row scoping and magic variables,
  file-backed render cache with TTL and atomic writes
  (`html_cache_clear()`), missing-variable policies (`keep`, `placeholder`,
  `exception`), capped diagnostics log `html_errors()` with 14 codes.
* **Hook system**: `html_add_filter()/html_apply_filters()/
  html_add_action()/html_do_action()/html_remove_filter()` and eight engine
  hook points (`htmlphp_render`, `htmlphp_translate`, `htmlphp_error`,
  `htmlphp_ssi_builtins`, `htmlphp_ssi_include_path`, `htmlphp_ssi_echo`,
  `htmlphp_missing_var`, `htmlphp_filter_apply`).
* **Helpers**: `html_escape()/html_escape_attr()/html_escape_url()` (with
  `HTML_ESCAPER` override), `html_slugify()` (intl or built-in
  Cyrillic/European transliteration), `html_truncate()`,
  `html_config()`, `html_version()`, `html_ssi()`, `html_ssi_variables()`,
  `html_reset_state()`.
* **WordPress bridge & maintenance toolkit** (`wordpress/html-wp.php`):
  bridge boot (escaper install, i18n delegation, locale sync, catalogue
  loading), template loader (theme hierarchy + `.ssi.htm` suffixes +
  isolated PHP includes), template shortcodes with scoped attributes,
  nonces, typed options/transients with `remember()`, one-shot admin
  notices, `$wpdb` prepared-query helpers and table statistics, idempotent
  cron scheduling, rotating daily logs, system-info snapshot, pending
  updates report, health checks, filemtime cache-busted assets, REST route
  registration with enforced permission callbacks, WP-CLI commands
  (`wp htmlphp info|health|updates|render|cache|logs`).
* **Ready-made maintenance plugin** (`wordpress/html-php-maintenance/`):
  Tools page rendered by the engine itself, daily health cron with critical
  notices, dashboard widget, lifecycle logging, nonce-protected actions.
* Composer packages for both editions and the WordPress bridge; `dist/`
  single-file downloads; `tests/run-matrix.sh` verification matrix;
  eight runnable examples; English documentation set
  (`docs/API|SSI|I18N|WORDPRESS|SECURITY|MIGRATION.md`).

### Changed

* Internals moved from ~40 global `_html*()` functions to namespaced final
  classes (`HtmlPhp\Support\Str`, `Ssi\Processor`, `I18n\Translator`,
  `Template\Renderer`, …); seven internals used by third-party tests remain
  as deprecated wrappers.
* SSI processing is now a **single sequential document-order scanner**
  (Apache semantics) instead of five regex passes; conditional frames are
  tracked on a stack, so false branches are parsed but never executed.
* `Read_HTML()`/`WriteFile()` failures throw `HtmlPhp\TemplateException`
  (extends `RuntimeException`).
* Configuration gained a typed catalogue (`HtmlPhp\Config`) with bounds and
  defaults; `$GLOBALS` remains the single source of truth.
* Test harness: `--lib=` selection, `skip()/skipIf()` (root-aware
  permission tests), per-test engine-state reset, banner shows the library
  under test.

### Fixed

* `sizefmt=bytes` renders exact digits (Apache parity) instead of grouped
  numbers; `abbrev` matches Apache (`512`, `2K`, `3.1M`).
* Filter arguments may contain `:` when quoted (`%{ts|date:'H:i'}`).
* `Escaper::url()` pattern no longer collides with its own delimiter.
* Expression tokenizer handles `!=` as one operator (previously split into
  `!` + `=` producing spurious trailing-input errors).
* Plural-form evaluator preserves literal branch indices (`plural=…?1:2` no
  longer collapses `2` to `1`).

### Security

* `#exec` sandbox: master switch off by default, binary allow-list,
  shell-free argv spawning, document-root containment for CGI scripts,
  extension allow-list, hard timeout and output caps, denials recorded.
* `#if` file tests honour include containment; expressions length-bounded;
  invalid regexes degrade to `false` with a diagnostic.
* All 2.0.1 guard rails preserved: escaping by default, name gate, reserved
  globals, traversal-safe case-insensitive containment, byte/recursion
  limits, atomic writes, no superglobal mutation by default.

## [2.0.1] - 2026-09-20

### Fixed

* `normalize()` no longer destroys its own `<br>` marker (`\x01` sentinel
  stripped from input and excluded from the control-character strip).
* `Read_HTML()` no longer overwrites a marker-published block with the
  residual text.
* `WriteFile()` suppresses the warning of its locked in-place fallback.
* `clean_html()` drops `<script>`/`<style>` elements with their contents,
  including an unterminated opener.

## [2.0.1] - 2026-09-20 (hardening pass)

### Added

* `declare(strict_types=1)`; stricter `$GLOBALS` name gate via
  `_htmlValidVariableName()`.
* Safe defaults: `HTML_SSI_ECHO_ESCAPE=true`, `HTML_AUTO_SANITIZE_INPUT=false`;
  `HTML_Sanitized_Input()` returns sanitized copies without mutation.
* Generic SSI attribute parsing (order-independent), escaped literal
  characters in `timefmt` conversion, `%h` → abbreviated month.
* Include containment normalises separators and case; enforces
  `HTML_MAX_INCLUDE_BYTES`; explicit umask handling in `WriteFile()`;
  `Show_HTML()` accepts `'0'` selectors; `HTML_MAX_PRECISION` may be 0.

## [2.0.1] - 2026-09-16

### Added

* Apache SSI parity for the conbat.ru site: `#set` with `$VAR` expansion,
  `#echo` (built-ins + allow-list + escaping), legacy `##echo`,
  `#config timefmt` with a strftime→date converter, document-relative
  include resolution, fixed evaluation order (#config → #set → includes →
  echoes → `<!--@var-->`).

## [2.0.0] - 2026-09-04 and earlier

### Added / Fixed

* Security and edge-case audit: UTF-8 fallbacks in `normalize()`/
  `clean_html()`, bounded `_htmlCleanRound()`, umask-aware `WriteFile()`,
  reserved-name protection for SSI writes, hardened `Show_HTML()`.
* Alignment with html4.pl: locale-aware `Round`/`Clean Round`/`Div`/
  `Round Div`/`Clean Round Div`, bounded SSI recursion, include limits,
  traversal protection, atomic file output, quote-aware normalisation, tag
  allow-listing, brace-safe variable parsing, indirect variables and
  translations.

[3.0.0]: https://github.com/paulmann/HTML.PHP/releases/tag/v3.0.0
[2.0.1]: https://github.com/paulmann/HTML.PHP/releases/tag/v2.0.1
