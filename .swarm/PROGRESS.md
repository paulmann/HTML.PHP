# PROGRESS — HTML.PHP v4.0.0 refactor

Legend: [ ] todo · [~] wip · [x] done. Overall % is weighted by stream.

| Weight | Stream | Status | % |
|---|---|---|---|
| 5%  | WS-0  Coordination, plan, workspace, refs | [x] | 100% |
| 12% | WS-1  Support + Contracts + Config + Container | [x] | 100% |
| 10% | WS-2  Infra (Io, Escaper, Cleaner, Cache, Log, Events, Diagnostics) | [x] | 100% |
| 16% | WS-3  SSI subsystem (+printenv/block/stub/encoding, registry) | [x] | 100% |
| 18% | WS-4  Template engine (syntax, loops, ifs+expr, layouts, partials, lint, stats) | [x] | 100% |
| 10% | WS-5  i18n (+json/php catalogs, formats, extractor, auditor) | [x] | 100% |
| 4%  | WS-6  Engine facade + exceptions + Version | [x] | 100% |
| 10% | WS-7  WordPress bridge + maintenance + plugin | [x] | 100% |
| 8%  | WS-8  Tests (port + new), matrix green 8.3 & 8.5 | [x] | 100% |
| 4%  | WS-9  Build tool + single-file bundles (php83/php85) | [x] | 100% |
| 3%  | WS-10 Docs (EN), README, CHANGELOG, examples, CI, phpstan | [x] | 100% |

**OVERALL: 100%**

## Log
- 2026-10-11 WS-10 done — PROJECT COMPLETE:
  Docs (EN): README, docs/{TEMPLATE-SYNTAX, SSI, I18N, API, CONFIGURATION, WORDPRESS, CLI,
  SECURITY, ARCHITECTURE, MIGRATION-3.x-TO-4.0}.md, CHANGELOG, wordpress/README.
  examples/01-04 + run-all.sh (verified on php8.3 AND php8.5). tools/bench.php
  (~1.4k full renders/s incl. SSI+i18n on 50-row templates; 69k simple renders/s; 626k t()/s).
  Release engineering: composer.json x3 (paulmann/html-php + html-php85, conflict-guarded),
  .github/workflows/ci.yml (matrix 8.3/8.4/8.5 x src/php83/php85 + drift check + phpstan + cs-fixer),
  phpstan.neon level 6 + phpstan-baseline.neon (8 entries) -> [OK] No errors,
  .php-cs-fixer.dist.php, .gitignore/.gitattributes, LICENSE, tools/run-matrix.sh.
  FINAL VERIFIED STATE: matrix 5/5 green (329/329 src on 8.3 & 8.5; 327/327 bundles),
  build --check OK (no drift), phpstan OK, all examples + plugin admin view render on both PHPs.
  Late fixes: Auditor plural-source counting, plugin views/ registered as template root,
  bundled health.html view matched to AdminPage data, example 04 multi-path catalogue merge demo.
- 2026-10-11 WS-8/9 done: bin/htmlphp CLI (version/help/lint/render/ssi/config:dump/cache:clear/
  i18n:extract/i18n:audit; exit codes; HTML_PHP_LIB override) + cases 13_security (incl. token-level
  no-eval/no-globals architecture guard) + 14_cli. tools/build.php bundles src (+src-8.5 overlays)
  into php83/html.php & php85/html.php with version guard; overlays use array_find, pipe operator,
  asymmetric visibility, property hooks, #[\NoDiscard]. FULL MATRIX GREEN:
  php8.3+src 329, php8.5+src 329, php8.5+php85bundle 327, php8.5+php83bundle 327, php8.3+php83bundle 327.
  Fixes: RenderStats::trackTemplate (private(set) vs []=), array_first->array_find, linter raw-source
  fallback, CLI json purity, fr/pt plural rules (v3 upstream bug).
- 2026-10-11 WS-7 done + WS-8 at 75%: WP layer (Bridge/WpCache/WpLogger/TemplateLoader/
  Shortcodes/Cli/AdminPage/Mailer + Maintenance x12 ported) + plugin scaffold
  wordpress/html-php/ (loader, uninstall, readme, engine-rendered admin view).
  Suite: 300 tests / 890 assertions GREEN on php8.3 AND php8.5 (cases 01-12).
  wp-stubs extended: wp_cache_*, wp_mail capture, theme dirs, plugin_basename,
  load_plugin_textdomain real signature.
  Remaining: bin/htmlphp CLI + cases 13/14, build tool + bundles (+8.5 overlays), docs/CI/composer.
- 2026-10-11 WS-8 partial: TestRunner+wp-stubs reused from v3; new v4 bootstrap (test_engine/
  test_request/test_options helpers). Cases 01-08 written: 197 tests / 539 assertions green on 8.3.
  Bugs found & fixed: Config toArray snake_case round-trip, Dispatcher has()/off() pruning,
  Formats clock injection, matches-on-lit parse, unless-frame text routing, include isolation
  (only/with → fresh Scope), namespace roots guards, range truncation vs refusal,
  raw-filter-disabled must not suppress escaping, #exec cgi needs resolveExecutable (deny-list
  inversion), SsiContext accessors, Pcre::replaceUnicode restored.
- 2026-10-11 WS-4/6 code-complete: TagParser (tree), Expression (memoized AST), Substitution,
  Filters (46 built-ins), TreeRenderer (loops/ifs/sections/extends/partials/set/trans/break/
  continue), TemplateLocator (namespaces+PathGuard), Renderer pipeline (parse memo, layout chain,
  SSI last + guarded re-substitution pass, output cache, events, stats), Linter+LintIssue,
  Engine facade. tests/smoke.php: 33/33 green on php8.3 AND php8.5.
  Design decisions since last update: @Section/@Extends (not @Block, avoids v3 legacy clash);
  trans bodies carry %{expr} args -> msgid {0}/{1}…; number filter is locale-aware (de => 4,50);
  auto-escape defaults ON; tag scanner payload is .*? (bare '>' allowed in expressions).
- 2026-10-11 WS-1/2/3/5 code-complete (lint green on 8.3): Config tree+enums, Container,
  Scope, RequestContext, Diagnostics, Str/Pcre/Clock, Io(Files/AtomicWriter/PathGuard),
  Escaper/Cleaner, Cache(File/Array/Apcu/Null/Psr16+Factory), Log(6 impls+Psr3Adapter+Factory),
  Event Dispatcher, SSI(DocumentInfo/SsiContext/Builtins/Attributes/TimeFormat/SizeFormat/
  Expression(+ -A/-F/-U, base64 echo)/Exec(ExecMode policies)/Scanner(+printenv/block/stub/
  wait/registry)/Processor), i18n(Locale/PluralForms/Catalog(+json/php)/Translator(instance)/
  TranslatorInterface/Factory/Formats/Extractor/Auditor), Version, Exceptions.
  Sandbox gotcha: PHP processes see only cwd subtree — v3 source copied to tools/v3-source/.
- 2026-10-11 WS-0 done: workspace `/home/user/html-php`, v3 cloned to `/home/user/ref/HTML.PHP`,
  PHP 8.3.35 + 8.5.11 installed (sury), v3 suite verified green (502/502) as behaviour baseline.
