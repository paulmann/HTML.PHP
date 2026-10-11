# Architecture — HTML.PHP 4

## Layering

```
Layer 0  Support      Str, Pcre, ClockInterface/SystemClock/FrozenClock     (pure, stateless)
Layer 1  Contracts    Config tree + enums, Container, Scope, RequestContext,
                      Diagnostics, CacheInterface, LoggerInterface, exceptions
Layer 2  Infra        Io\{Files, AtomicWriter, PathGuard}, Html\{Escaper, Cleaner},
                      Cache\{File, Apcu, Array, Null, Psr16}, Log\{File, ErrorLog,
                      Syslog, Array, Null, Psr3}, Event\Dispatcher
Layer 3  Domain       Ssi\{Processor, Scanner, SsiContext, Expression, Exec,
                      Directives registry, Builtins, TimeFormat, SizeFormat},
                      I18n\{Translator, Catalog, PluralForms, Locale, Formats,
                      Extractor, Auditor},
                      Template\{Renderer, TreeRenderer, TagParser, Substitution,
                      Filters, Expression, TemplateLocator, Linter}
Layer 4  Facades      Engine · WordPress\{Bridge, AdminPage, Cli, …}
```

Rule: dependencies point downwards only. Layers 0–3 never read superglobals
or statics; host state enters once, at Layer 4, through constructors.
A token-level test enforces this on every CI run.

## Render pipeline

```
Engine::render(name, vars, options)
  └─ TemplateLocator ──► Renderer::renderFile(path)
       ├─ size guard (limits.max_template_bytes)
       ├─ output cache lookup (CacheInterface; key = version+locale+policy+vars hash)
       ├─ locale override (RenderOptions.locale, restored in finally)
       ├─ TagParser::parse → Node tree        (memoized per source sha1, LRU 512)
       ├─ layout inheritance                  (@Extends chain ≤ 8, override stacks,
       │                                       @SectionParent = next stack level)
       ├─ TreeRenderer walk
       │    text  → Substitution (%{} #{} @{} + Filters + Escaper + missing policy)
       │    loop  → iterable normalize + frame push + magic vars + Break/Continue signals
       │    if    → Template\Expression (memoized AST) truthiness
       │    include → locate (PathGuard) → Renderer::renderPartial (isolated or inherited Scope)
       │    set / trans / section / raw / comment …
       ├─ SSI pass (Ssi\Processor → Scanner per document, DocumentInfo anchored)
       ├─ guarded re-substitution pass (only when SSI output reintroduced template syntax)
       ├─ 'htmlphp.render' filter event
       ├─ cache store + RenderStats finalize + 'htmlphp.rendered' event
       └─ return string
```

Design choices and why:

- **Tree, not regex recursion.** v3 processed blocks with nesting-aware
  regex recursion; v4 parses once into `Node` trees. Correctness comes from
  construction (unbalanced tags are impossible to represent), the linter
  reuses the same parser, and repeated renders reuse the memoized tree.
- **Substitution stays regex-based** (one pass per form) — it is the hot
  path, the v3 patterns are ReDoS-hardened and battle-tested, and parse-time
  variable extraction would duplicate that logic for no measurable gain.
- **SSI runs last, once**, over the assembled document (Apache semantics:
  the server sees the final file). The single guarded re-substitution pass
  replaces v3's `passes` loop with deterministic behaviour.
- **Two expression evaluators on purpose.** SSI keeps mod_include string
  semantics (`'2' > '10'` is true, everything is a string); template
  expressions use PHP-like semantics (`2 > 10` is false). Conflating them
  breaks one of the two contracts.
- **No compile-to-PHP step.** The engine renders data-driven documents, not
  application views at Twig scale; the parse memo + output cache cover the
  win, while avoiding an executable-code cache directory (a classic shared-
  hosting attack surface). `tools/bench.php` documents the numbers.

## State & concurrency

Per-engine instance state only: parse memo (LRU-capped), locator memo,
translator catalogues, filters registry, dispatcher, diagnostics. Safe for
long-running workers (`clearCache()`/`Translator::reset()`/
`Expression::reset()` flush the memos) and for multi-tenant processes
(two engines never share mutable state — test-enforced).

## Editions & build

`src/` (PHP 8.3 syntax floor) is the single source of truth.
`src-8.5/` holds whole-file overlays that use newer language features at
genuine seams:

| Overlay file | 8.4/8.5 features used |
|---|---|
| `Version.php` | `#[\NoDiscard]`, edition constants |
| `Diagnostics.php` | `array_last()` |
| `Template/RenderStats.php` | asymmetric visibility `public private(set)`, computed property hook (`summary`) |
| `Template/Renderer.php` | pipe operator `\|>` for the render pipeline, `array_find()`, `#[\NoDiscard]` |

`tools/build.php` concatenates `src/**/*.php` (namespace-blocked, uses
merged, strict_types once at top) into `php83/html.php`; for `php85/html.php`
it swaps overlays in first. `--check` mode fails CI on drift. Overlays are
behaviour-identical: the full test matrix runs against **both** bundles
(327+327 tests) plus both source trees.

The 8.5 bundle intentionally **fails to parse** on PHP < 8.5 (asymmetric
visibility) — the documented failure mode is the version guard in the 8.3
bundle; on 8.5-syntax the parse error itself is the guard.

## Testing architecture

- `tests/TestRunner.php` — dependency-free runner (groups, filters,
  per-test `$GLOBALS`/superglobal snapshot-restore, assertion origins).
- `tests/bootstrap.php` — library resolution (`src` or `--lib` bundle),
  fixture helpers, deterministic UTC, `test_engine()`/`test_request()`
  factories.
- 14 case files, 330 tests, 968 assertions (identical with and without
  `ext-intl`), covering: config/container,
  support/io, escaping/cleaning, cache/log/events, SSI (core, conditionals,
  exec policies), substitution/filters, structure tags, i18n (incl. binary
  MO built in-test), render API/cache/stats/events, linter, WordPress
  (bridge/adapters/toolkit/CLI/admin/plugin files against 60+ WP stubs),
  security model (incl. the no-eval/no-globals architecture scan), CLI
  (subprocess exit codes).
- Matrix: PHP 8.3/8.4/8.5 × {src, php83 bundle, php85 bundle} — locally via
  `tools/run-matrix.sh`, in CI via GitHub Actions.

## Extension points (summary)

Filters (`Filters::add`), SSI directives (`DirectiveRegistry`), cache
(`CacheInterface` / PSR-16 adapter), logger (`LoggerInterface` / PSR-3
adapter), translator (`TranslatorInterface`), clock (`ClockInterface`),
events (dispatcher tags ↔ WP hooks), escaper (subclass/replace), config
(immutable `with*`/`merge`), container overrides (`Engine::create($c, $setup)`).
