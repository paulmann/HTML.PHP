# API Reference — HTML.PHP 5.0.0

Namespace `HtmlPhp\`. Both editions expose the identical API. Everything is
instance-based; nothing reads globals. Typical wiring is one call:

```php
$engine = HtmlPhp\Engine::create($configArrayOrObject, $optionalContainerSetup);
```

---

## Engine (facade)

| Method | Description |
|---|---|
| `Engine::create(Config\|array $config = [], ?callable $setup = null): self` | Builds config (arrays go through `Config::fromArray`), wires the container, runs `$setup(Container)` **before** any service instantiation |
| `render(string $name, array $vars = [], ?RenderOptions $o = null): string` | Renders a template resolved through `templates.paths`/`namespaces` (throws `TemplateNotFoundException`) |
| `renderFile(string $path, array $vars = [], ?RenderOptions $o = null): string` | Renders a host-provided path (size-guarded, SSI-anchored) |
| `renderString(string $source, array $vars = [], ?RenderOptions $o = null): string` | Renders inline/DB-sourced templates |
| `display(...) / displayString(...)` | echo variants |
| `processSsi(string $content, ?DocumentInfo $doc = null): string` | Standalone SSI pass (post content, option values, legacy pages) |
| `ssiVariables(?DocumentInfo $doc = null): array<string,string>` | Built-in SSI map (diagnostics/CLI) |
| `t / n / x / nx(...)` | Translation shortcuts (see `TranslatorInterface`) |
| `setLocale(string) / locale(): string` | Active locale |
| `loadTranslations(string $locale, ?string $domain = null): list<string>` | Force-loads configured `i18n.paths`; returns loaded files |
| `lint(string $nameOrPath): list<LintIssue>` · `lintString($src, $origin)` · `lintDirectory($dir): array<file, list<LintIssue>>` | Static template validation |
| `clearCache(): int` | Purges render cache + parse memo |
| `stats(): ?RenderStats` | Profiling of the most recent render |
| `config() container() renderer() locator() ssi() translator() filters() events() cache() logger() diagnostics() clock() linter() escaper()` | Service accessors (all lazy singletons) |
| `version(): string` | `"5.0.0 (8.3 edition)"` |

### Template\RenderOptions (readonly, all nullable = "take config")

`file`, `root`, `request` (RequestContext), `ssi`, `structure`, `autoEscape`,
`missingVar` (enum), `cache`, `ttl`, `locale`, `ssiVars`. Also
`RenderOptions::fromArray($v3StyleOptions)` as a migration aid.

### Template\RenderStats

`template, startedAt, durationMs, cacheHit, substitutions, loops, loopRows,
conditionals, includes, sections, translations, ssiDirectives, outputBytes,
peakMemoryKb, templates[]` + `toArray()`; the 8.5 edition adds a computed
`summary` property hook. Emitted with the `htmlphp.rendered` event.

### Template\LintIssue (readonly)

`severity` (`error|warning|info`), `code`, `message`, `file`, `line`,
`context[]`; `format()` → `file:line SEVERITY [code] message`; `toArray()`.

Codes: `unknown-tag, unclosed-tag, unbalanced-tag, orphan-mid,
unknown-filter, filter-disabled, filter-arg-not-numeric (v5, warning),
bad-expression, expressions-disabled (v5), unresolved-include,
unresolved-extends, missing-reference, loop-range-too-large,
loop-signal-outside-loop, trans-count-without-plural,
trans-plural-without-count, trans-nested-tag, untranslated-message,
unknown-ssi-directive, ssi-missing-expr, ssi-include-target, ssi-echo-var,
ssi-set-var, ssi-bad-expr, ssi-exec-denied, template-unreadable`.

---

## Config (immutable aggregate)

```php
Config::default();  Config::fromArray([...]);  $config->merge([...]);
$config->withDebug(true);  // + withSsi/withI18n/withCache/withTemplates/withSyntax
$config->toArray();  // round-trips through fromArray
```

Groups (each a `final readonly class` with `fromArray`/`toArray`/`KEYS`):
`templates`, `syntax`, `ssi`, `i18n`, `cache`, `log`, `security`, `limits`.
Unknown keys at any level throw `ConfigException` — typos fail fast.
Full key table: [CONFIGURATION.md](CONFIGURATION.md).

Enums: `Config\MissingVarPolicy {Empty, Keep, Placeholder, Throw}`,
`Config\ExecMode {Deny, AllowList, Delegate}`, `Config\SizeFormat {Abbrev,
Bytes}`, `Config\CacheDriver {Null, Array, File, Apcu, WordPress}`,
`Config\LogDriver {Null, Array, ErrorLog, File, Syslog, WordPress}`.

## Container (PSR-11-shaped)

`factory(id, fn(Container))` (lazy, shared) · `instance(id, $obj)` ·
`get/has/service<T>/ids/reset` · `Container::withDefaults(Config)`.
Service ids are the class/interface names: `Config::class`,
`Contracts\CacheInterface::class`, `Log\LoggerInterface::class`,
`Event\Dispatcher::class`, `Diagnostics::class`, `Support\ClockInterface::class`,
`Html\Escaper::class`, `I18n\Formats::class`, `I18n\TranslatorInterface::class`,
`Template\{Filters, TemplateLocator, Renderer, Linter}::class`,
`Ssi\Processor::class`, `Engine::class`.

```php
Engine::create($config, function (Container $c): void {
    $c->instance(Contracts\CacheInterface::class, new MyRedisCache());
    $c->instance(Support\ClockInterface::class, new FrozenClock(1700000000));
    $c->instance(Log\LoggerInterface::class, Log\Psr3Adapter::wrap($monolog));
    $c->instance(I18n\TranslatorInterface::class, new MyDbTranslator());
});
```

## Scope

`new Scope($rootVars)`; `push/pop/with/get/has/set/find/all/depth`.
`find('a.b.c')` returns `[found, value]` walking arrays, `ArrayAccess`,
public props and `get*/is*/has*` accessors. No `$GLOBALS` fallback — ever.

Static accessors (v5), used by the expression evaluator and safe to call
from host code:

- `Scope::step(mixed $value, string $path): array{bool, mixed}` — walks a
  dotted path through arrays / `ArrayAccess` / public properties and
  zero-arg accessors (`getX`/`isX`/`hasX`). Read-only.
- `Scope::index(mixed $value, mixed $key): array{bool, mixed}` — one element
  by a *dynamic* key. Arrays, `ArrayAccess` and UTF-8 string offsets only;
  `int`/`string` keys only (≤ 256 chars, no null bytes); plain objects are
  never indexable by dynamic key. Always `[found, value]`, never a warning.
- `Substitution::splitFilters()`, `Substitution::splitExpressionPayload()`
  (quote- and bracket-aware), `Substitution::splitArguments()` and
  `Substitution::parseSegments()` are public statics for host tooling.

## RequestContext (readonly)

`RequestContext::fromServer($_SERVER)` (edge) · `::cli($cwd)` ·
`http(name)` for `HTTP_*`. Fields: `documentRoot, scriptName, requestUri,
documentName, documentUri, queryString, method, remoteAddr, userAgent,
acceptLanguage, referer, server`.

## Input

`Input::all()` / `Input::source('get|post|cookie|request')` — recursively
`htmlspecialchars`-encoded copies; superglobals are never modified. The
only sanctioned superglobal reader besides the WP adapters.

## Diagnostics

`record(code, message, context)` · `all() byCode(code) count(code='')
last() clear() clean()`. Capped at `limits.max_diagnostics` (100). Forwards
to the logger (when `log.forward_diagnostics`) and fires `htmlphp.error`.
Codes: `missing-var, ssi-include-failed, ssi-include-cycle,
ssi-limit-reached, ssi-exec-denied, ssi-exec-failed, ssi-expr-error,
ssi-unknown-directive, filter-unknown, expr-invalid, expr-disabled,
regex-failed, catalog-parse,
cache-io, loop-guard, template-lint, wp-bridge`.

## Event\Dispatcher

WordPress-hook shaped: `on(tag, callable, priority=10): Closure(unsub)` ·
`off(tag, ?priority)` · `dispatch(tag, ...args)` · `filter(tag, $value,
...args): mixed` (null returns keep the previous value; recursion-capped) ·
`has/tags/reset`.

Engine tags: `htmlphp.render.before`, `htmlphp.render` (filter),
`htmlphp.render.after`, `htmlphp.rendered` (RenderStats), `htmlphp.error`,
`htmlphp.translate` (filter), `htmlphp.translate.miss`,
`htmlphp.filter.apply`, `htmlphp.ssi.builtins` (filter),
`htmlphp.ssi.include_path` (filter), `htmlphp.ssi.echo` (filter).

## Cache / Log contracts

```php
interface Contracts\CacheInterface { get set has delete clear(): int }
interface Log\LoggerInterface { log debug info notice warning error critical alert emergency }
```

Implementations: `Cache\{FileCache, ApcuCache, ArrayCache, NullCache,
Psr16Adapter}` + `WordPress\WpCache`; `Log\{FileLogger, ErrorLogLogger,
SyslogLogger, ArrayLogger, NullLogger, Psr3Adapter}` + `WordPress\WpLogger`.
Factories: `Cache\CacheFactory::create(CacheConfig)`,
`Log\LoggerFactory::create(LogConfig)` / `::wrap($psr3)`.
`FileCache` shards by key hash, writes atomically, GCs probabilistically
(~1 % of writes), and drops a deny-all `.htaccess` + `index.html` into the
cache dir (`cache.harden_directory`).

## SSI classes

`Ssi\Processor` (`process`, `processWithContext`, `variables`,
`directives(): DirectiveRegistry`, `lastProcessed()`) ·
`Ssi\DocumentInfo(file, root, request, vars)` · `Ssi\SsiContext` (state,
budgets, `resolvePath/resolveExecutable/variable/resolve`) ·
`Ssi\DirectiveInterface {name, render}` · `Ssi\Expression` (mod_include
grammar) · `Ssi\Exec` (policies) · `Ssi\{Attributes, TimeFormat,
SizeFormat, Builtins, Scanner(internal)}`.

## I18n classes

`I18n\TranslatorInterface` · `I18n\Translator` (load, register,
loadConfigured, catalogue(s), hasCatalogue, stats, reset, locale,
setLocale, availableLocales, negotiate, fallbackChain, t/n/x/nx,
`interpolate` static) · `I18n\Catalog` (`load` po/mo/json/php, `fromPo`,
`fromMo`, `fromArray`, `mergeFrom`, `get/getPlural/has/ids/headers/count/
pluralForms`) · `I18n\PluralForms` (`parse`, `select`, `count`, MAX_FORMS=6)
· `I18n\Locale` (`normalize`, `language`, `parseAcceptLanguage`,
`negotiate`) · `I18n\Formats` (`number/date/time/datetime/ago`) ·
`I18n\Extractor` (`scan`, `scanDirectory`, `entries`, `dynamicHits`,
`toPo`) · `I18n\Auditor` (`audit`, `format`).

## Template classes

`Template\{Renderer, TreeRenderer, TagParser, Node, Substitution, Filters,
Expression, TemplateLocator, Linter, LintIssue, RenderOptions, RenderStats,
RenderContext, LoopSignal}` — see ARCHITECTURE.md for the pipeline.

## Support / Io / Html

`Support\Str` (stringify, numericValue, round/cleanRound/number/divide,
power/sqrt/accumulate *(v5, INF/NAN-safe, iteration-capped)*,
truncate, slugify, keepWordChars, normalizeEntities, formatSize,
isValidVariableName, sanitizeRecursive, upper/lower/ucfirst/words/length/substr)
· `Support\Pcre` (throwing wrappers; `match/matchAll` degrade to no-match) ·
`Support\{ClockInterface, SystemClock, FrozenClock}` ·
`Io\{Files, AtomicWriter, PathGuard}` · `Html\Escaper` (html/attr/url/json/
js/css/byStrategy/sanitizeArray; subclassable, container-replaceable) ·
`Html\Cleaner` (lcTags, normalize, cleanTag, cleanHtml with injected
allow-list, trimSpaces).

## Exceptions

```
HtmlPhpException (context map, withContext(), describe())
├─ ConfigException        ├─ ContainerException   ├─ IoException
├─ CacheException         ├─ RegexException       ├─ SsiException
├─ I18nException          └─ TemplateException
                              ├─ TemplateNotFoundException
                              └─ TemplateSyntaxException
```

## WordPress namespace

`WordPress\Bridge` (boot/engine/booted/reset) · `TemplateLoader`
(locate/render/renderPart) · `Shortcodes` · `WpCache` · `WpLogger` ·
`AdminPage` · `Cli` · `Mailer` · `Maintenance\{Nonces, Options, Transients,
Notices, Db, Cron, Logs, SystemInfo, Updates, Health, Assets, Rest}`.
Details: [WORDPRESS.md](WORDPRESS.md).
