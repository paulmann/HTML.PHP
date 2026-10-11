# API Reference — HTML.PHP 3.0.0

Both editions (`php83/html.php`, `php85/html.php`) expose the identical API.
Everything lives in one file: global functions for the public surface and
`HtmlPhp\…` classes for structured access.

---

## 1. Legacy API (100 % compatible with 2.0.1 / html4.pl)

| Function | Signature | Description |
|---|---|---|
| `HTML` | `(string $html): string` | Main legacy preprocessor: SSI pass + variable pass via `update_template()`, repeated up to `HTML_MAX_TEMPLATE_PASSES` times so `%[NAME]` escapes resolve. Variables come from `$GLOBALS`. |
| `processSSI` | `(string $html, ?string $currentFile = null): string` | Runs every SSI directive. `$currentFile` anchors relative includes and `LAST_MODIFIED`. |
| `processHtml` | `(string $html, callable $updateTemplate): string` | Variable substitution with a custom resolver callback. |
| `update_template` | `(string $varName): string` | Default resolver: `$GLOBALS[$varName]`, then i18n catalogue for `Translate.KEY`, then the key itself. |
| `LoadTemplate` | `(string $file): string` | Loads a template, publishing `<!--@Block NAME-->…<!--@End Block-->` sections into `$GLOBALS['NAME']`; returns the residual document. |
| `LoadVar` | `($handle, string $startPattern): string` | Recursive block reader used by `LoadTemplate()`. |
| `Read_HTML` | `(string $file, int $block = 1): string` | Loads numbered `<!--@block N-->…<!--@end-->` sections into `$GLOBALS['html'][N]`. |
| `Show_HTML` | `(mixed ...$args): ?string` | Renders a numbered block: accepts one array or legacy key/value pairs. Honours `output` (`var*` returns, `file`/`comb` write to `handle`, otherwise echo), `PARITY` + `<!--@Split-->`, `SSI=off`, `exec_symbol`, `$GLOBALS['SILENT']`. |
| `Divide` | `(mixed $a, mixed $b): float\|int` | Division returning `0` for a zero divisor or non-finite results. |
| `lc_Tags` | `(string $input): string` | Lower-cases tag names, keeps attributes. |
| `normalize` | `(string $buffer, mixed $br = null, mixed $cleanEdges = false): string` | HTML → single-line text; preserves `<br>` unless `$br`; strips edge punctuation when `$cleanEdges`. |
| `clean` | `(string $closing, string $tag): string` | Allow-list check against `$GLOBALS['TAGS']`. |
| `clean_html` | `(string $html): string` | Keeps only `HTML_ALLOWED_TAGS` (attributes removed); drops `<script>`/`<style>` with contents. |
| `WriteFile` | `(string $file, mixed $data): bool` | Atomic write: temp file in the destination dir, verified length, mode preservation, rename with locked in-place fallback. |
| `TrimSpaces` | `(mixed $string): string` | Strips everything except Unicode letters, digits and `_`. |
| `sanitizeXSS` | `(array $input): array` | Recursively `htmlspecialchars()`-encodes a nested array. |
| `HTML_Sanitized_Input` | `(): array` | Sanitized copies of `$_GET/$_POST/$_COOKIE/$_REQUEST` (superglobals untouched). |

Deprecated compatibility wrappers (used by 2.0.1-era third-party tests):
`_htmlStringify`, `_htmlNumericValue`, `_htmlPrecision`, `_htmlRound`,
`_htmlCleanRound`, `_htmlPositiveLimit`, `_htmlValidVariableName`.

Constants: `HTML_RESERVED_GLOBALS`, `HTML_SSI_BUILTIN_VARS`.

## 2. Modern rendering

```php
html_render(string $template, array $vars = [], array $options = []): string
html_render_file(string $file, array $vars = [], array $options = []): string
```

Options:

| Key | Type | Default | Meaning |
|---|---|---|---|
| `file` | `?string` | — | document path for relative SSI includes (auto-set by `html_render_file`) |
| `root` | `?string` | `HTML_WWW_PATH`/`DOCUMENT_ROOT` | document root for SSI containment |
| `ssi` | `bool` | `true` | run the SSI pass |
| `loops` | `bool` | `true` | process `<!--@Loop-->` / `<!--@If-->` blocks |
| `globals` | `bool` | `false` | fall back to `$GLOBALS` for missing scope keys |
| `escape` | `?bool` | `HTML_ESCAPE_VARS` | auto-escape every substitution |
| `passes` | `?int` | `HTML_MAX_TEMPLATE_PASSES` | pipeline repetitions (`%[NAME]` needs 2) |
| `cache` | `bool` | `false` | use the render cache |
| `ttl` | `?int` | `HTML_CACHE_TTL` | cache lifetime in seconds |

Variables resolve through the scope first (`user.address.city` dot notation
walks arrays/objects/ArrayAccess), then — when enabled — `$GLOBALS`.
Missing names follow `HTML_MISSING_VAR`: `''` (default), `keep`,
`placeholder`, `exception`. Every miss is recorded in `html_errors()`.

### Template syntax

| Form | Meaning |
|---|---|
| `%{NAME}` | substitute (filters allowed) |
| `#{NAME}` | substitute, alternate braces |
| `%[NAME]` | rewritten to `%{NAME}` — resolves on the next pass |
| `%{%{NAME}}` | substitute but keep one literal brace level |
| `@{KEY}` | translate `KEY` (catalogue → `Translate.KEY` global → `KEY`) |
| `%{K.Round:N}` `%{K.Clean Round:N}` `%{K.Div D}` `%{K.Round Div D:N}` `%{K.Clean Round Div D:N}` | html4.pl numeric directives |
| `%{NAME\|filter:arg\|filter2:'a:b'}` | filter chain (quoted args may contain `:`) |

Built-in filters: `escape/esc/e`, `attr`, `url`, `json`, `upper`, `lower`,
`ucfirst`, `words`, `number[:dec[:dp[:ts]]]`, `round[:n]`, `cleanround[:n]`,
`int`, `float`, `date[:fmt]`, `truncate[:len[:suffix]]`, `nl2br`,
`striptags`, `slug[:sep]`, `default[:val]`, `raw`, `pad[:len[:str[:left]]]`,
`repeat[:n]`, `translate`. Register your own with
`html_var_filter(string $name, callable $fn)`.

### Blocks

```html
<!--@Loop rows--> %{_position}. %{name} <!--@EndLoop rows-->
<!--@If flag--> … <!--@Else--> … <!--@EndIf-->
<!--@If !flag--> negated <!--@EndIf-->
```

Row scope shadows the outer scope; magic variables: `_index`, `_position`,
`_key`, `_first`, `_last`, `_count`, `_odd`. Rows may be arrays, objects,
`ArrayAccess`/`Traversable` or scalars (exposed as `%{value}`).

### Cache

```php
html_config(['HTML_CACHE_DIR' => '/var/cache/htmlphp', 'HTML_CACHE_TTL' => 300]);
echo html_render($tpl, $vars, ['cache' => true]);
html_cache_clear();          // purge
```

## 3. Internationalisation

| Function | Description |
|---|---|
| `html_t($msgid, $args = [], $domain = null)` | translate; `{name}` and sprintf interpolation |
| `html_n($single, $plural, $count, $args = [], $domain = null)` | plural-aware translation |
| `html_x($msgid, $context, $args = [], $domain = null)` | message context (`msgctxt`) |
| `html_nx($single, $plural, $count, $context, $args = [], $domain = null)` | plural + context |
| `html_set_locale($locale)` / `html_get_locale()` | active locale (normalised `ll_CC`) |
| `html_detect_locale($available, $acceptLanguage = null, $default = null)` | Accept-Language negotiation |
| `html_load_translations($locale, $path, $domain = 'messages')` | load `.po`/`.mo` (file, or directory searched as `{domain}-{locale}.mo\|po`, `{locale}.mo\|po`, `{locale}/LC_MESSAGES/{domain}.mo\|po`) |

Classes: `HtmlPhp\I18n\Translator` (stats/reset), `Catalog` (`fromPo`,
`fromMo`, `load`), `PluralForms` (`parse`, `select`, `count`), `Locale`
(`normalize`, `language`, `parseAcceptLanguage`, `negotiate`).

## 4. Hooks

```php
html_add_filter($tag, $callback, $priority = 10);
html_apply_filters($tag, $value, ...$args);
html_remove_filter($tag, $priority = 10);
html_add_action($tag, $callback, $priority = 10);
html_do_action($tag, ...$args);
```

Engine hook points: `htmlphp_error`, `htmlphp_ssi_builtins`,
`htmlphp_ssi_include_path`, `htmlphp_ssi_echo`, `htmlphp_missing_var`,
`htmlphp_render`, `htmlphp_translate`, `htmlphp_filter_apply`.

## 5. Helpers & diagnostics

| Function | Description |
|---|---|
| `html_version()` | `"3.0.0 (8.3 edition)"` |
| `html_config(?array $overrides = null)` | read/override effective configuration |
| `html_ssi($html, $file = null)` | modern alias of `processSSI()` |
| `html_ssi_variables($file = null)` | built-in SSI environment map |
| `html_escape` / `html_escape_attr` / `html_escape_url` | context escapers (`HTML_ESCAPER` overrides the text escaper) |
| `html_slugify($text, $sep = '-')` | URL slug (intl transliteration or built-in Cyrillic/European map) |
| `html_truncate($text, $len, $suffix = '…')` | word-boundary, multibyte-safe |
| `html_errors($code = '')` / `html_clear_errors()` | capped (100) diagnostics log |
| `html_cache_clear()` | purge render cache |
| `html_reset_state()` | reset catalogues/hooks/filters/errors (tests, workers) |

Diagnostics codes: `missing-var`, `ssi-include-failed`, `ssi-include-cycle`,
`ssi-limit-reached`, `ssi-exec-denied`, `ssi-exec-failed`, `ssi-expr-error`,
`filter-unknown`, `regex-failed`, `catalog-parse`, `cache-io`,
`config-unknown`, `loop-guard`, `wp-bridge`.

## 6. Configuration keys (`$GLOBALS`)

| Key | Default | Bounds | Purpose |
|---|---|---|---|
| `HTML_MAX_SSI_DEPTH` | 16 | 1–128 | include recursion depth |
| `HTML_MAX_SSI_TAGS` | 256 | 1–10 000 | include budget per render |
| `HTML_MAX_TEMPLATE_PASSES` | 2 | 1–16 | pipeline repetitions |
| `HTML_MAX_BLOCK_DEPTH` | 64 | 1–1024 | legacy block nesting |
| `HTML_MAX_PRECISION` | 100 | 0–1000 | rounding precision cap |
| `HTML_MAX_TEMPLATE_BYTES` | 16 MiB | ≤512 MiB | template size cap |
| `HTML_MAX_INCLUDE_BYTES` | 8 MiB | ≤256 MiB | include size cap |
| `HTML_LOOP_MAX` | 10 000 | 1–1 000 000 | rows per loop |
| `HTML_MAX_LOOP_DEPTH` | 32 | 1–128 | block nesting |
| `HTML_SSI_ALLOW_PARENT` | `false` | — | allow `..`/outside-root includes |
| `HTML_SSI_ECHO_ESCAPE` | `true` | — | escape `#echo` output |
| `HTML_SSI_ECHO_ALLOW` | `[]` | — | read allow-list (empty = all) |
| `HTML_SSI_TIMEFMT` | `%A, %d-%b-%Y %H:%M:%S %Z` | — | strftime-style time format |
| `HTML_SSI_SIZEFMT` | `abbrev` | `abbrev\|bytes` | `#fsize` format |
| `HTML_SSI_ERRMSG` | `null` | — | text emitted for failed directives |
| `HTML_SSI_EXEC` | `false` | — | master switch for `#exec` |
| `HTML_SSI_EXEC_CMD_ALLOW` | `[]` | — | allow-listed `cmd=` binaries |
| `HTML_SSI_EXEC_CGI_EXT` | `['php']` | — | allowed `cgi=` extensions |
| `HTML_SSI_EXEC_CGI_MODE` | `spawn` | `spawn\|include` | `cgi=` execution mode |
| `HTML_SSI_EXEC_TIMEOUT` | 5 | 1–60 | seconds per exec |
| `HTML_SSI_EXEC_MAX_BYTES` | 65 536 | ≤16 MiB | exec output cap |
| `HTML_AUTO_SANITIZE_INPUT` | `false` | — | sanitize superglobals on load |
| `HTML_ALLOWED_TAGS` | see source | — | `clean_html()` allow-list |
| `HTML_ESCAPE_VARS` | `false` | — | auto-escape `%{VAR}` |
| `HTML_ESCAPER` | `null` | callable | custom text escaper |
| `HTML_MISSING_VAR` | `''` | `''\|keep\|placeholder\|exception` | missing-variable policy |
| `HTML_DEBUG` | `false` | — | visible placeholders + extra diagnostics |
| `HTML_WWW_PATH` | unset | — | document root override |
| `HTML_I18N_LOCALE` | `en` | — | active locale |
| `HTML_I18N_FALLBACK` | `['en']` | — | fallback chain |
| `HTML_I18N_DOMAIN` | `messages` | — | default text domain |
| `HTML_I18N_DETECT` | `false` | — | auto-detect locale on first use |
| `HTML_I18N_WP` | unset (auto) | `true\|false` | force/forbid WP translation delegation |
| `HTML_CACHE_DIR` | unset | — | render cache directory |
| `HTML_CACHE_TTL` | 0 | 0–30 d | default cache TTL |

## 7. Class map (`HtmlPhp\…`)

```
Version, TemplateException, Config, Errors, Hooks, Escaper
Support\{Str, Pcre}
Io\{Files, AtomicWriter}
Html\Cleaner
Ssi\{Attributes, TimeFormat, SizeFormat, Builtins, Context, Expression,
     ExpressionSyntaxError, Exec, Processor}
I18n\{Locale, PluralForms, Catalog, Translator}
Template\{Substitution, Filters, Resolver, TemplateExceptionMissing,
          Loops, Cache, Renderer}
Legacy\Blocks
```

All classes are `final` with private constructors where instantiation is
not intended; state flows through `Ssi\Context`/`RenderState` and method
arguments — there are no hidden globals beyond the documented `$GLOBALS`
configuration keys.
