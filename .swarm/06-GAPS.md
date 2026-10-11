> **[v5 note · 2026-10-12]** Historical document of the v4.0.0 work —
> kept unchanged for the record. Current state: **v5.0.0**, see
> `.swarm/HANDOFF.md` and `CHANGELOG.md`.

# Gap analysis — what v3 is missing (and v4 adds)

The user asked: "add what I forgot". Verified against v3.0.0 source + docs.

## A. Core engine (any-PHP usage)

| # | Gap | v4 answer |
|---|-----|-----------|
| A1 | No layout inheritance — every page repeats header/footer via SSI | `<!--@Extends "base"-->` + `<!--@Block name-->` + `%{parent()}`, multi-level |
| A2 | No scoped partials | `<!--@Partial "card"-->`, `with="a,b"` mapping, `only` isolation, `ignore-missing` |
| A3 | `<!--@If-->` only tests truthiness | full expression language: comparisons, `&&/\|\|/!`, parentheses, dot-paths, literals, functions (`count`, `empty`, `min`, `max`), `<!--@Elif-->` |
| A4 | No `@Set` — cannot compute in-template | `<!--@Set total="price * qty"-->` (safe evaluator, no `eval`) |
| A5 | Silent failure by default (missing var -> `''`) | `strict` mode: exceptions; `MissingVar` enum policy (`Empty/Keep/Placeholder/Throw`) |
| A6 | No template validation | `Engine::lint()` + CLI `lint` — unclosed tags, unknown filters/directives, bad SSI expressions; CI-ready exit codes |
| A7 | Cache is file-only, hardwired | `CacheInterface` + `FileCache/ApcuCache/ArrayCache/NullCache/WpCache/Psr16CacheAdapter`; tagged invalidation |
| A8 | No logging abstraction | PSR-3-shaped `LoggerInterface` + `Null/File/ErrorLog/Syslog/ArrayLogger` + `Psr3Adapter` |
| A9 | Hooks are WP-flavoured statics | `Event\Dispatcher` (PSR-14-shaped) with typed events; WP bridge maps to `do_action/apply_filters` |
| A10 | No DI — statics everywhere | `Container` (PSR-11-shaped) + `Engine::create(config, setup)` |
| A11 | `$_SERVER` read deep inside SSI | `RequestContext::fromServer($_SERVER)` built at the edge, passed explicitly |
| A12 | No render profiling | `RenderStats` (timings, substitution/SSI counters, cache hits) via events + `Engine::stats()` |
| A13 | Filters operate on strings only | filters receive `mixed`, ~18 new: `abs, join, split, length, first, last, reverse, sort, unique, slice, replace, trim, base64, md5, sha1, ago, excerpt, keys` |
| A14 | No locale-aware formatting | `I18n\Formats`: locale decimal/thousand separators, date patterns |
| A15 | i18n catalogues: PO/MO only | + PHP-array and JSON loaders, directory conventions, `TranslatorInterface` (WP delegation is one impl) |
| A16 | No translation tooling | `I18n\Extractor` (scan templates -> POT) + `Auditor` (missing/unused per locale) + CLI |
| A17 | Single-file only | PSR-4 `src/` tree for Composer users **and** built single-file bundles for drop-in hosting |
| A18 | No CLI | `bin/htmlphp`: `version, lint, render, ssi, cache:clear, cache:warm, i18n:extract, i18n:audit, config:dump` |

## B. SSI (Apache mod_include parity gaps in v3)

| # | Gap | v4 answer |
|---|-----|-----------|
| B1 | `#printenv` missing | implemented (escaped var dump, gated by echo allow-list) |
| B2 | `#block`/`#endblock` + `stub=` on `#include` missing | implemented (fallback content when include fails) |
| B3 | `#echo encoding=` ignored | `none/entity/url/base64` supported |
| B4 | `#include wait=` ignored (nginx/apache attr) | accepted, documented as no-op (sequential engine) |
| B5 | `#if` file-test operators incomplete | `-f -d -x -s -A -F -U` + `/regex/` + string compares per mod_include grammar |
| B6 | No custom directives | `DirectiveRegistry::register()` — hosts add `#mydirective` |
| B7 | SSI on arbitrary strings (WP post content) awkward | `Processor::process(string, ?DocumentInfo)` public API + WP content filter |

## C. WordPress (optional bridge + maintenance)

| # | Gap | v4 answer |
|---|-----|-----------|
| C1 | Cache not integrated with WP object cache | `WpCache implements CacheInterface` (`wp_cache_*` group + transient fallback, multisite-safe prefix) |
| C2 | Logger not integrated | `WpLogger implements LoggerInterface` (rotating file in uploads + `error_log` when `WP_DEBUG_LOG`) |
| C3 | i18n delegation is a config flag | `WpTranslator implements TranslatorInterface` (`__/_n/_x/_nx`, `determine_locale`, text-domain aware) |
| C4 | No settings UI | plugin admin page: settings, health, template lint, cache tools, i18n audit — rendered **by the engine itself** |
| C5 | No theme helper | `htmlphp_render()/htmlphp_t()` plugin functions bound to the plugin's Engine (documented singleton) |
| C6 | No content SSI | opt-in `the_content` filter through `Processor` (policy-gated `#exec` off) |
| C7 | No mail templating | `Maintenance\Mailer::send()` — render template -> `wp_mail` |
| C8 | No admin-bar debug | `RenderStats` admin-bar node when `WP_DEBUG` |
| C9 | WP-CLI surface thin | `wp htmlphp info/lint/render/cache/i18n/health/precompile` |
| C10 | Maintenance toolkit (v3) | ported & instance-based: Nonces, Options, Transients, Notices, Db, Cron, Logs, SystemInfo, Updates, Health, Assets, Rest |
| C11 | Gutenberg block | deliberately OUT of scope (needs JS build) — documented roadmap |

## D. Production readiness

| # | Item |
|---|------|
| D1 | GitHub Actions matrix: PHP 8.3/8.4/8.5 × (src, php83 bundle, php85 bundle) |
| D2 | `phpstan.neon` (level 8+) + `.php-cs-fixer.dist.php` |
| D3 | Cache dir auto-hardening (`.htaccess` deny + `index.html`) |
| D4 | SECURITY.md (threat model: traversal, XSS, exec, zip-slip-like include attacks, ReDoS guards) |
| D5 | MIGRATION-3.x-TO-4.0.md with 1:1 mapping table for every removed function |
| D6 | Benchmarks `tools/bench.php` (render throughput, SSI pass, catalog load) |
| D7 | Version guard in bundles (fail fast on wrong PHP), `Version::banner()` |
