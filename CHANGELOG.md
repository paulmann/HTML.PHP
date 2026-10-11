# Changelog

All notable changes to HTML.PHP. Format follows Keep a Changelog;
the project uses semantic versioning.

## 4.0.0 — 2026-10-11

**A clean-break rewrite of 3.0.0.** No backward compatibility with the
legacy html4.pl API or the 3.x global-function surface; see
`docs/MIGRATION-3.x-TO-4.0.md`.

### Added — architecture
- Immutable `Config` value object tree (9 groups + 6 policy enums) with
  fail-fast unknown-key rejection and bounds validation; replaces all ~35
  `$GLOBALS['HTML_*']` keys.
- Lazy DI `Container` (PSR-11-shaped) + `Engine::create($config, $setup)`;
  every service replaceable (cache, logger, translator, clock, escaper).
- Instance-based services end to end: `Diagnostics`, `Event\Dispatcher`,
  `Filters`, `Translator`, SSI `Processor` — engines are fully independent;
  the core contains no statics and no global reads (token-level test
  enforces it in CI).
- Explicit `RequestContext` (edge-built) and `Input` helper replace every
  `$_SERVER`/superglobal read in the core; injectable `ClockInterface`.
- PSR-shaped contracts shipped dependency-free: `CacheInterface`
  (File/Apcu/Array/Null/Psr16Adapter/WordPress), `LoggerInterface`
  (File/ErrorLog/Syslog/Array/Null/Psr3Adapter/WordPress), tagged event
  dispatcher with priorities.

### Added — template language
- Real parser: tag stream → node tree (shared by renderer and linter);
  parse trees memoized per source.
- Layout inheritance: `@Extends`, `@Section`, `@SectionParent`, 8-level
  chain guard.
- Scoped partials: `@Include` with `with="a,b"`, `only`, `ignore-missing`,
  document-relative resolution, `@namespace/` roots.
- Safe expression language for `@If/@Elif/@Unless/@Set`, loop ranges and
  trans counts: comparisons, logic, ternary/coalesce, `in`, `matches`,
  concat, whitelisted functions, memoized ASTs, strict/lenient modes.
- Loop upgrades: `item in rows`, `k, v in map`, `i in 1..10` (± direction),
  `@Else` empty branch, `@Break`/`@Continue`, `_even` + `loop.*` context.
- `@Trans`/`@Plural` gettext-plural blocks with positional args, domain
  and context; `@Raw`, `@Comment`.
- 18 new filters (`abs, join, split, length, first, last, reverse, sort,
  unique, slice, replace, trim, base64, md5, sha1, ago, excerpt, keys, js,
  css, title, div, datetime-aware date`) — 46 built-ins total.
- **Auto-escape ON by default**; `raw` disable-able via policy.
- `RenderOptions` (typed) and `RenderStats` profiling (`htmlphp.rendered`).
- Template linter: 25 issue codes, file/dir/API/CLI/WP-CLI/admin, CI exit
  codes, `--ignore` baselining, JSON output.

### Added — SSI
- `#printenv`, `#block`/`#endblock` + `stub=` include fallbacks,
  `#echo encoding="base64"`, `wait=` accepted, `-F` file test, `-A`/`-U`
  explicitly refused (no subrequests/network I/O from templates).
- `ExecMode` policies: `deny` (default) / `allow-list` / `delegate`
  (host callable); `resolveExecutable()` separates include-deny-list from
  exec-allow-list semantics.
- Custom/overriding directives via `DirectiveRegistry`.
- Render scope visible to SSI (`DocumentInfo::$vars`); include hardening:
  denied extensions + dotfiles (fixes v3 source-disclosure of e.g.
  `wp-config.php` through `#include`).

### Added — i18n
- JSON and PHP-array catalogue loaders; multi-path catalogue **merge**
  (first path wins); `Catalog::mergeFrom/ids`.
- `I18n\Extractor` (POT writer, references, dynamic-use warnings) and
  `I18n\Auditor` (missing/unused/% per locale).
- `I18n\Formats`: locale-aware number/date/time/datetime/`ago` (~40
  separator tables), clock-injected.
- Built-in plural rules fixed/extended: **fr, pt, kab** now use `n > 1`
  (v3 shipped no French rule).
- `htmlphp.translate.miss` event for untranslated-string telemetry.

### Added — tooling & operations
- `bin/htmlphp` CLI: `version, help, config:dump, lint, render, ssi,
  cache:clear, i18n:extract, i18n:audit` (exit codes, JSON, `--config`,
  `HTML_PHP_LIB`).
- `tools/build.php` (pure PHP) generates both single-file editions from
  `src/` (+ `src-8.5/` overlays) with `--check` drift gate.
- PHP 8.5 edition overlays: pipe operator, `array_find()`, property hooks,
  asymmetric visibility, `#[\NoDiscard]`.
- GitHub Actions matrix (8.3/8.4/8.5 × src/both bundles), PHPStan level 6
  (+8-entry baseline), PHP-CS-Fixer config, `tools/bench.php`,
  `tools/run-matrix.sh`.
- Composer packages: `paulmann/html-php` (8.3, PSR-4) and
  `paulmann/html-php85` (8.5, single-file autoload), mutually conflicting.

### Added — WordPress
- `Bridge::boot(Config|array, setup, options)` with WP-flavoured defaults
  (theme `htmlphp/` dirs, uploads cache/log dirs, ABSPATH document root,
  WP i18n delegation).
- `WpCache` (object cache + transients, blog-scoped groups, enumerable
  clear), `WpLogger` (rotating files + debug.log mirror).
- Engine→WP hook mirroring (`htmlphp_render`, `htmlphp_error`,
  `htmlphp_translate`), opt-in `the_content` SSI filter, admin-bar stats.
- Admin screen (Tools → HTML.PHP): health, lint report, cache, i18n, log
  tail, system info — theme-overridable, rendered by the engine itself.
- `Mailer` (templated `wp_mail`), expanded WP-CLI (`info/render/lint/cache/
  i18n extract+audit/health`).
- Maintenance toolkit ported (Nonces, Options, Transients, Notices, Db,
  Cron, Logs, SystemInfo, Updates, Health, Assets, Rest).
- Plugin scaffold `wordpress/html-php/` with loader (edition auto-select),
  activation hardening, `uninstall.php` cleanup, `htmlphp_render()/
  htmlphp_t()` helpers.

### Changed
- `FileCache`: sharded layout, atomic writes, probabilistic GC, directory
  hardening (`.htaccess`/`index.html`), diagnostics on I/O failure.
- Diagnostics entries carry timestamps; dispatcher gained recursion guard,
  precise unsubscribe and `filter()` null-keeps-previous semantics.
- `Pcre` wrappers throw `RegexException` on failures; `match/matchAll`
  degrade to no-match (documented).
- WordPress `TemplateLoader` no longer locates/renders `.php` files.

### Removed
- The entire legacy html4.pl API and the 3.x global functions
  (`html_render`, `html_t`, `html_config`, hooks API, …) — see the
  migration guide for the full map.
- All `$GLOBALS` configuration, `Translate.KEY` globals, `$GLOBALS`
  variable fallback in renders, `passes` multi-pass pipeline, `%[NAME]`,
  `%{%{NAME}}`, numeric directives, `<!--@Block-->`/`@Split`/`Show_HTML`
  parity, `##name`/`@var` SSI forms.

### Security
- Escape-by-default; denied-extension includes; exec deny-by-default with
  delegate policy; no-subrequest file tests; expression whitelists; budget
  guards on every recursive construct; token-level no-eval/no-globals CI
  gate. Full model: `docs/SECURITY.md`.

## 3.0.0 — 2026-10-11
Two editions (php83/php85), full Apache SSI, gettext i18n, hooks,
WordPress bridge + maintenance toolkit; legacy 2.0.1 API preserved.

## 2.0.1 and earlier
Single-file html4.pl-compatible template processor (legacy).
