# HANDOFF — read this first if you are a fresh agent

## State (updated continuously — keep it truthful!)
- **Overall progress: 100% — v4.0.0 COMPLETE and verified** (see PROGRESS.md)
- Workspace: `/home/user/html-php` — the v4 deliverable.
- v3 reference (read-only): `/home/user/ref/HTML.PHP` (symlinked as `.v3-reference`).
  v3 suite passes 502/502 on php8.3 and php8.5 — it is the behaviour baseline.
- PHP: `php8.3` = 8.3.35, `php8.5` = 8.5.11 (sury, bookworm). `php` NOT on PATH.
  Both have mbstring. No composer binary. Network available (git/curl/apt).

## Non-negotiables
1. v4 = NO backward compatibility with v3/2.0.1 PHP API. Legacy html4.pl functions stay dead.
2. NO `$GLOBALS`/superglobals below the edge layer; Config object + Container + explicit args.
3. Template *syntax* of v3 modern layer is KEPT (users' .html files), extended per 06-GAPS.md.
4. Two editions: `php83/html.php` (8.3+) and `php85/html.php` (8.5, built with overlays).
5. Everything user-facing in English. MIT license, author header preserved (Mikhail Deynekin).
6. Zero runtime dependencies. PSR-* shaped interfaces, self-contained.

## Key files
- `.swarm/00-MISSION.md` — goal + environment facts
- `.swarm/ARCHITECTURE-v4.md` — binding architecture decisions (layers, syntax, editions)
- `.swarm/06-GAPS.md` — feature gap list = v4 scope beyond refactor
- `.swarm/PROGRESS.md` — % table, update after each stream
- `src/` — PSR-4 source (namespace `HtmlPhp\`), PHP 8.3 syntax floor
- `src-8.5/` — overlay files replacing `src/` counterparts in the 8.5 bundle
- `tests/run.php` — test entrypoint (custom micro-runner, no phpunit)
- `tools/build.php` — builds both single-file bundles from src/ (+src-8.5/)

## Conventions
- `declare(strict_types=1);` in every file; final classes; readonly where possible; enums for policies.
- Exceptions: `HtmlPhp\Exception\*` extending `HtmlPhpException extends \RuntimeException`.
- Diagnostics codes from v3 kept (missing-var, ssi-*, cache-io, ...) for continuity.
- Tests: `tests/cases/NN_name.test.php` return `array<string, callable(TestKit)>` groups.
- After ANY change: `php8.3 tests/run.php` must stay green; before committing a stream,
  also run `php8.5 tests/run.php`.

## Next steps (optional polish backlog — project is complete)
- Raise PHPStan toward level 7+ once WP stubs are wired into its scan (see baseline).
- Gutenberg block + FSE integration (roadmap, needs JS build).
- More locale separator tables in I18n\Formats; CLDR plural sync tooling.
- Consider a compiled-to-PHP cache tier if profiling ever demands it (ARCHITECTURE.md §Design choices).

## Completed streams — ALL DONE (see PROGRESS.md log for detail)
WS-0 coordination · WS-1 Support/Contracts/Config/Container · WS-2 Infra (Io/Escaper/Cleaner/
Cache/Log/Events/Diagnostics) · WS-3 SSI (+printenv/#block+stub/encoding/base64/-F/-A/-U,
DirectiveRegistry, ExecMode policies) · WS-4 Template engine (TagParser→Node tree, Expression,
Substitution, Filters×46, TreeRenderer, layouts/partials/set/trans/break/continue, Locator,
Renderer pipeline, Linter×25 codes, RenderStats) · WS-5 i18n (+JSON/PHP catalogues, merge,
Formats, Extractor, Auditor, fr/pt/kab plural fix) · WS-6 Engine facade · WS-7 WordPress
(Bridge/WpCache/WpLogger/TemplateLoader/Shortcodes/Cli/AdminPage/Mailer + Maintenance×12 +
plugin scaffold) · WS-8 tests (329 tests / 966 assertions; matrix 5/5 green) · WS-9 build tool
+ both bundles + 8.5 overlays · WS-10 docs (10 EN docs + README + CHANGELOG + MIGRATION),
examples×4, bench, CI, phpstan L6 clean, composer×3, LICENSE.

## Gotchas learned so far
- v3 `Substitution::process($segment, callable $resolver, '%', $autoEscape)` handles `%{}`/`#{}`/`@{}` — reuse the regexes, they are ReDoS-guarded via `Pcre` (PCRE_JIT + backtrack limits).
- v3 SSI scanner pattern: `~<!--(?<body>\#\#[a-zA-Z]+|\#[a-zA-Z]+|@var\b)(?<rest>.*?)-->~is` (legacy `##name` forms — decide in WS-3 whether to keep; leaning KEEP `#name`, DROP `##name`+`@var` legacy).
- v3 `Loops` supports nested loops, `_index/_position/_key/_first/_last/_count/_odd` magic vars, row scalar => `%{value}`.
- v3 Exec: spawn via proc_open with timeout+output cap; cgi include-mode runs php files with captured output; deny-by-default.
- v3 Catalog: PO + MO binary parser, msgctxt via `\x04` separator, plural forms from PO header `Plural-Forms:`.
- v3 wp-stubs.php (tests/) is a full WP function stub set — REUSE for v4 WP tests.
- strftime is deprecated: v3 has TimeFormat strftime->date() converter — port it (Ssi\TimeFormat).
