# Changelog

All notable changes to HTML.PHP. Format follows Keep a Changelog;
the project uses semantic versioning.

## 5.0.0 — 2026-10-12

**Additive major.** No 4.x template, config key, filter or API was removed
or reinterpreted — see `docs/MIGRATION-4.x-TO-5.0.md`. The version bump
carries the language addition (inline expressions reach into the
substitution pipeline) plus a round of evaluator hardening.

### Added — template language
- **Inline expressions in substitutions**: `%{= EXPR |filters}` and
  `#{= EXPR}` (plus `{= EXPR}` under `syntax.single_braces`). The payload
  after `=` runs through the *same* sandboxed evaluator `@If`/`@Set` use —
  no `eval`, no dynamic calls, no I/O — and its result passes the unchanged
  filter + auto-escape pipeline.
  ```html
  Progress: %{= done / total * 100 | round:1 }%
  Total:    %{= (price * qty) * (1 - discount / 100) | number:2 } €
  ```
  The `=` prefix is mandatory, so a bare payload stays a v4 name lookup:
  `%{a-b}` still reads the variable literally named `a-b` and is never a
  subtraction. Filter chains split at the first *top-level* `|`
  (quote- and bracket-aware), so `%{= "a|b" | upper}` works.
- **Nested variables — index access**: `rows[0]`, `prices[currency]`,
  `hash['x-1 y']`, chains (`rows[0].name`, `matrix[i][j]`) and UTF-8-aware
  string offsets with PHP-style negative indexing (`name[-1]`). Available in
  every expression position: `%{= …}`, `@If`, `@Set`, loop sources,
  `@Trans count`.
- **Nested variables — indirect access**: `get(name)` / `get(name, default)`
  resolves a variable whose *name* is itself data ("a variable inside a
  variable"), including dotted paths and literal bracket keys
  (`get('rows[1].name')`) and composed names (`get(prefix ~ suffix)`).
- **New expression functions**: `pow`, `sqrt`, `sum`, `avg`, `clamp`, `get`
  (whitelist grows 17 → 23).
- **New arithmetic filters**: `add:n`, `sub:n`, `mul:n`, `mod:n`, `pow:n`,
  `sqrt`, `pct:total[:dec]`, `sum`, `avg`, `clamp:min:max` — 47 → 57
  registered names; the 4.x docs said "46", which undercounted by one.
  Filter arguments remain literals — the linter now says so.
- **`syntax.expressions`** (default `true`): a fail-closed kill switch for
  inline expressions, for hosts rendering user-editable templates. `false`
  leaves `%{= …}` untouched and records `expr-disabled`.

### Added — operations
- Linter: validates inline expressions (`bad-expression`), reports
  `expressions-disabled` as an error when the gate is off, and warns
  `filter-arg-not-numeric` when a numeric filter argument looks like a
  variable reference (the `%{done|pct:total}` trap) with a pointer to the
  `%{= …}` form.
- Diagnostics codes `expr-invalid` and `expr-disabled`; repeated failures of
  the same expression are deduplicated, so one broken tag in a 10 000-row
  loop records a single entry.
- `Scope::step()` / `Scope::index()` are public statics (the shared accessor
  primitives); `Substitution::splitExpressionPayload()` is public for host
  tooling.
- `Str::power()`, `Str::sqrt()`, `Str::accumulate()` — pure, INF/NAN-safe,
  iteration-capped numeric helpers.
- `examples/05-expressions.php`; `docs/MIGRATION-4.x-TO-5.0.md`.
- `tools/build.php` reads the release version from `src/Version.php` instead
  of hardcoding it in the bundle header and runtime guard.

### Fixed
- **Flaky file-cache test eliminated** (promised in the 4.0.0 release notes,
  never landed): `04_cache_log_events.test.php` asserted a fixed
  `clear() === 2` while `FileCache::set()` legitimately fires the ~1 %
  probabilistic `sweep()` GC, which may unlink the expired file first —
  ~1 % of suite runs were red (`expected: 2, actual: 1`) with no code
  change. The test now asserts the actual contract: `clear()` removes
  exactly the entries present immediately before the call and leaves the
  cache empty. `sweep()` itself is untouched — it is a feature (lazy GC
  without cron), not the defect. Measured: 2 red / 300 runs before,
  0 red / 500 runs after.
- **CLI option parser**: the usage banner advertised `--var k=v` but the
  parser only accepted `--var=k=v` — space-separated values silently became
  positional arguments. Both forms now work for every option (a
  space-separated value is consumed unless the next token is itself an
  option; valueless trailing flags stay boolean).
- **Expression-level ranges**: `a..b` and `(1..10)` threw
  `TemplateSyntaxException: Trailing input` — the lexer emitted a `..` token
  type the parser never tested for, so the documented range grammar and
  `Expression::range()` were unreachable outside `@Loop` (where the tag
  parser handles ranges separately). Ranges now parse, evaluate and are
  indexable: `%{= (1..3)[1]}` → `2`.
- **Unbounded AST memo**: `Template\Expression` memoized parse trees in an
  unbounded static array. Now a 4 096-entry FIFO — a template emitting
  thousands of unique expressions can no longer grow the registry without
  limit (verified by a memory-delta test).
- **Uncapped iteration over host iterables**: `count()`/`in` walked a
  `Traversable` to exhaustion, so an infinite generator from host code hung
  the render. All aggregate walks now stop at
  `Str::MAX_AGGREGATE_ITEMS` (100 000); `sum`/`avg` inherit the budget.
- `matches` routes through `Support\Pcre`, so a backtrack-limit hit from a
  ReDoS-shaped pattern is a clean no-match instead of a raw `false`/`null`.
- Plus the carried-over 4.0-branch fixes listed at the end of this section
  (deterministic `Str::slugify`, `Maintenance\*` exception imports,
  `Mailer` header types, `WpCache` diagnostics, portable test fixtures,
  `tests/run.php --list`, mandatory CI gates, full-tree PHPStan).

### Changed
- `Substitution` takes `Config\Limits` (after `Config\Syntax`) so inline
  expressions honour `limits.max_expression_length`. The container wires it;
  only hosts constructing `Substitution` by hand are affected.
- `Scope::walk()` (private) became `Scope::step()` (public). `find()`
  semantics are unchanged.
- Suite grows 330 → 386 tests / 968 → 1286 assertions, green across
  PHP 8.3/8.4/8.5 × {`src`, `php83`, `php85`} × intl {on, off}.

### Security
- Dynamic keys can never reach a plain object: `[]` works on arrays,
  `ArrayAccess` and string offsets only, so request data cannot be turned
  into a property or method name. Dotted paths keep their author-controlled
  accessor behaviour. (`obj[key]` with `key = 'token'` is a miss — tested.)
- Key and path hygiene: `int`/`string` keys only; ≤ 256 chars; null bytes
  rejected; arrays/objects/`null` as keys are misses; every miss is `null`,
  never a warning or fatal.
- `get()` *parses* its path with a strict grammar and resolves it only
  against the scope stack — no globals, constants, `$_*`, environment or
  filesystem fallback exists to find. `_SERVER`, `../../etc/passwd`, `a[`,
  `a..b` and overlong paths all degrade to the caller's default.
- Inline expressions add no new trust surface: everything computable in
  `%{= …}` was already computable via `<!--@Set v="…"-->%{v}`. Output stays
  auto-escaped; failures stay visible (tag kept) instead of half-rendering.
- Arithmetic degrades instead of poisoning: `pow(0, -1)`, `pow(10, 9999)`,
  `sqrt(-1)` and overflowing products all yield `0`, never `INF`/`NAN`.
- Index chains are bounded by the existing nesting depth (32): each `[…]`
  opens a nested parse, so deep nests are syntax errors, not stack
  exhaustion.
- The no-`eval()` / no-superglobal token-level test (`13_security.test.php`)
  covers every new file and passes unchanged.

### Carried over from the 4.0 development branch

#### Fixed
- **`Str::slugify()` is now deterministic**: the built-in transliteration map
  is applied *before* the ICU fallback, so map-covered characters (Cyrillic,
  European diacritics) produce identical slugs with and without `ext-intl`.
  Previously an intl-loaded host produced `a-b-c` for `Ä B C!` while a host
  without intl produced `ae-b-c` — the same site generated different URLs
  depending on the server. Consequence on intl hosts: umlauts now follow the
  documented map (`ä→ae`, `ö→oe`, `ü→ue`, matching German transliteration
  conventions) instead of ICU's single-letter folding. Scripts outside the
  map (Greek, Georgian, …) still romanise via ICU when present and are
  stripped otherwise — unchanged. (#P0-1)
- **Latent fatal in `src/` PSR-4 layout**: `Maintenance\{Cron, Db, Nonces,
  Rest}` threw `TemplateException` without importing it — the error paths
  died with "class not found" instead of raising the intended exception.
  Found by PHPStan once the WordPress layer was un-excluded from analysis.
- `WordPress\Mailer::send()` accepts `string|string[]` for `$headers` and
  `$attachments` exactly like `wp_mail()` (passing a string header previously
  violated the declared docblock type).
- `WpCache::set()` without a WordPress object cache now records a `cache-io`
  diagnostic instead of failing silently (the injected `Diagnostics` was
  never used).
- Test suite: environment-dependent and POSIX-only assertions made portable
  (NTFS `chmod`, `/proc`-based unwritable-dir fixture replaced with a
  path-under-a-file trick that cannot create directories anywhere,
  separator-tolerant path comparisons via `same_path()`).
- `tests/run.php --list` actually lists tests (group, name, file:line) and
  exits 0 without running them; the option was parsed but ignored before.

#### Changed
- **CI gates are mandatory**: PHPStan and PHP-CS-Fixer steps no longer end in
  `|| true`; tool versions pinned (`PHPSTAN_VERSION`, `PHP_CS_FIXER_VERSION`).
- **PHPStan analyses the full `src/` tree** — the `src/WordPress/*` exclusion
  is removed; the layer is analysed against the project's own WP stubs via
  `bootstrapFiles` (`tests/phpstan-bootstrap.php` provides the two harness
  symbols the stubs need at load time). `phpstan-baseline.neon` is empty by
  policy; `reportUnmatchedIgnoredErrors: true`.
- **CI test matrix pins extensions** and doubles the legs: PHP 8.3/8.4/8.5 ×
  {src, php83, php85} × intl {on, off} = 14 jobs; the bundle drift gate runs
  `build.php --check` on the *committed* bundles (rebuild-then-check masked
  drift before).
- `tools/run-matrix.sh`: no longer rewrites the working tree (rebuild only
  with `--build`), drift-checks first, runs both intl modes (14 combos),
  hard-fails when a required PHP binary is missing (exit 2; `--allow-partial`
  to opt into SKIPs), always prints "executed X of Y", non-zero exit when
  nothing executed or anything failed.
- Whole tree normalised with PHP-CS-Fixer (PER-CS2.x, pinned) — style only.

#### Added
- `tests/smoke.php` wired into CI (job `smoke`, PHP 8.3/8.5) and
  `composer run smoke`.
- `tools/check-wiki-links.php` (validates wiki page links + GitHub heading
  anchors; CI job `wiki-links` runs it against the wiki repository) and
  `tools/publish-wiki.sh` (documented by the wiki, were missing).
- `tests/phpstan-bootstrap.php`, `same_path()` test helper.

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
