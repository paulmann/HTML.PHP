# Migration Guide — 3.x → 4.0

Version 4 is a **clean break**: the legacy `html4.pl` API is gone, global
configuration is gone, and defaults changed where security demanded it.
This document maps every removed symbol to its v4 replacement.

> PHP requirement: 8.3+ (8.5 edition: 8.5+). WordPress: 6.0+.

---

## 1. Bootstrapping

```php
// v3 ------------------------------------------------------------
require 'html.php';
$GLOBALS['HTML_CACHE_DIR'] = '/var/cache/htmlphp';
$GLOBALS['HTML_ESCAPE_VARS'] = true;
echo html_render($template, $vars);

// v4 ------------------------------------------------------------
require 'php83/html.php';            // or Composer autoload
use HtmlPhp\Engine;

$engine = Engine::create([
    'cache'  => ['enabled' => true, 'driver' => 'file', 'directory' => '/var/cache/htmlphp'],
    // auto-escape is ON by default now
]);
echo $engine->renderString($template, $vars);
```

## 2. Function map (modern 3.x API)

| v3 | v4 |
|---|---|
| `html_version()` | `$engine->version()` / `Version::banner()` |
| `html_config($overrides)` | `Engine::create($config)` · `$config->merge()` (no runtime mutation) |
| `html_render($template, $vars, $options)` | `$engine->renderString($source, $vars, new RenderOptions(...))` |
| `html_render_file($file, $vars, $options)` | `$engine->renderFile($file, $vars, $options)` / `$engine->render($name)` (locator) |
| `html_ssi($html, $file)` | `$engine->processSsi($html, new DocumentInfo(file: $file))` |
| `html_ssi_variables($file)` | `$engine->ssiVariables($documentInfo)` |
| `html_t/n/x/nx(...)` | `$engine->t/n/x/nx(...)` |
| `html_set_locale()/html_get_locale()` | `$engine->setLocale()/$engine->locale()` |
| `html_detect_locale($available, $header)` | `Locale::negotiate($available, $request->acceptLanguage)` |
| `html_load_translations($locale, $path, $domain)` | `$engine->translator()->load(...)` / `i18n.paths` autoload / `loadTranslations()` |
| `html_add_filter/apply_filters/add_action/do_action/remove_filter` | `$engine->events()->on/filter/dispatch/off` (tag names: `htmlphp_render` → `htmlphp.render`, …) |
| `html_var_filter($name, $fn)` | `$engine->filters()->add($name, $fn)` |
| `html_escape/escape_attr/escape_url` | `$engine->escaper()->html/attr/url` |
| `html_slugify()` | `Support\Str::slugify()` |
| `html_truncate()` | `Support\Str::truncate()` |
| `html_errors()/html_clear_errors()` | `$engine->diagnostics()->all()/clear()` |
| `html_cache_clear()` | `$engine->clearCache()` |
| `html_reset_state()` | per-engine instances — drop the engine (or `Translator::reset()` + `clearCache()`) |

## 3. Removed legacy API (html4.pl) — no replacement by design

`HTML()`, `processSSI()`*, `processHtml()`, `update_template()`,
`LoadTemplate()`, `LoadVar()`, `Read_HTML()`, `Show_HTML()`, `Divide()`,
`lc_Tags()`, `normalize()`, `clean()`, `clean_html()`, `WriteFile()`,
`TrimSpaces()`, `sanitizeXSS()`, `HTML_Sanitized_Input()`,
`_htmlStringify()`, `_htmlNumericValue()`, `_htmlPrecision()`,
`_htmlRound()`, `_htmlCleanRound()`, `_htmlPositiveLimit()`,
`_htmlValidVariableName()`, constants `HTML_RESERVED_GLOBALS`,
`HTML_SSI_BUILTIN_VARS`.

\* `processSSI()` → `$engine->processSsi()`.

Closest equivalents for the genuinely useful pieces:

| Legacy | v4 equivalent |
|---|---|
| `Divide($a, $b)` | `Str::divide()` (also `\|div:` filter, `/` in expressions) |
| `lc_Tags($html)` | `Html\Cleaner::lcTags()` |
| `normalize($html)` | `Html\Cleaner::normalize()` |
| `clean_html($html)` | `Html\Cleaner::cleanHtml()` (allow-list injected, not `$GLOBALS['TAGS']`) |
| `WriteFile($f, $d)` | `Io\AtomicWriter::write()` |
| `TrimSpaces($s)` | `Html\Cleaner::trimSpaces()` / `Str::keepWordChars()` |
| `sanitizeXSS($arr)` | `Str::sanitizeRecursive()` / `Escaper::sanitizeArray()` |
| `HTML_Sanitized_Input()` | `Input::all()` |
| `LoadTemplate`/`Show_HTML` block API | `@Loop`/`@Section`/partials (see TEMPLATE-SYNTAX.md) |

## 4. Template syntax changes

Kept: `%{NAME}`, `#{NAME}`, `@{KEY}`, filter chains, `<!--@Loop rows-->`
(incl. `_index/_position/_key/_first/_last/_count/_odd`), `<!--@If-->`,
`<!--@Else-->`, all SSI directives.

**Removed:**

| v3 form | v4 replacement |
|---|---|
| `%[NAME]` (deferred) | not needed — the pipeline is deterministic; use `@Include`/SSI for staged content |
| `%{%{NAME}}` | not needed |
| `%{K.Round:2}` `%{K.Clean Round:2}` `%{K.Div D}` (+combos) | `%{K\|round:2}` · `%{K\|cleanround:2}` · `%{K\|div:D}` |
| `<!--@Block NAME-->` / `<!--@End Block-->` / `<!--@Split-->` / PARITY | `@Loop` with `_odd/_even` magic vars or `@Section` layouts |
| `<!--##echo-->`, `<!--@var="X"…-->` (SSI legacy forms) | standard `<!--#echo-->` / `<!--#set-->` |
| `_odd` parity splitting in `Show_HTML` | `<!--@If _odd-->` inside loops |

**Added:** `@Elif`, `@Unless`, `@Extends`/`@Section`/`@SectionParent`,
`@Include` (+`with`/`only`/`ignore-missing`), `@Set`, `@Trans`/`@Plural`,
`@Raw`, `@Comment`, `@Break`, `@Continue`, loop alias/`k,v`/range forms,
`@Else` for empty loops, expression language in conditions, `loop.*` map,
18 new filters (see TEMPLATE-SYNTAX.md §2), SSI `#printenv`,
`#block`/`#endblock`+`stub=`, `#echo encoding="base64"`, custom directives.

## 5. Behaviour changes (read carefully)

1. **Auto-escaping defaults to ON.** v3 defaulted `HTML_ESCAPE_VARS=false`.
   Templates that inject pre-escaped HTML need `|raw` (or
   `syntax.auto_escape: false` during migration — not recommended).
2. **Missing variables** keep the v3 default (`empty`) but the `Translate.KEY`
   `$GLOBALS` fallback is gone — use catalogues or scope vars.
3. **`#set` no longer writes to `$GLOBALS`** — variables live in the render
   context and die with the render.
4. **SSI includes refuse denied extensions** (`.php`, `.env`, `.sql`,
   dotfiles…) — v3 served them raw. Scripts belong in `#exec cgi`
   (policy-gated) or custom directives.
5. **Single pipeline pass** replaces `passes`/`max_template_passes`
   chaining; the SSI re-substitution pass is automatic and guarded.
6. **`@Loop rows` on a missing variable** renders the `@Else` branch and
   records one diagnostic (v3: silent empty).
7. Unbalanced/unknown structure tags **throw** `TemplateSyntaxException`
   (v3: diagnosed + skipped). Run `bin/htmlphp lint` in CI first.
8. WordPress `TemplateLoader` no longer locates `.php` templates or
   includes them as PHP — templates are data.
9. French/Portuguese/Kabyle plural rules fixed (`n > 1`); catalogues
   relying on the (wrong) v3 default for `fr` will now pluralise 0
   correctly as singular.
10. `Diagnostics` entries include `time`; cap still applies (100).

## 6. Configuration key map

| v3 `$GLOBALS` key | v4 config path |
|---|---|
| `HTML_MAX_SSI_DEPTH` | `limits.max_ssi_depth` |
| `HTML_MAX_SSI_TAGS` | `limits.max_ssi_tags` |
| `HTML_MAX_TEMPLATE_PASSES` | *(removed — single deterministic pipeline)* |
| `HTML_MAX_BLOCK_DEPTH` | `limits.max_block_depth` |
| `HTML_MAX_PRECISION` | `limits.max_precision` |
| `HTML_MAX_TEMPLATE_BYTES` | `limits.max_template_bytes` |
| `HTML_MAX_INCLUDE_BYTES` | `limits.max_include_bytes` |
| `HTML_LOOP_MAX` | `limits.max_loop_rows` |
| `HTML_MAX_LOOP_DEPTH` | `limits.max_loop_depth` |
| `HTML_SSI_ALLOW_PARENT` | `ssi.allow_parent_paths` |
| `HTML_SSI_ECHO_ESCAPE` | `ssi.escape_echo` |
| `HTML_SSI_ECHO_ALLOW` | `ssi.echo_allow` |
| `HTML_SSI_TIMEFMT` | `ssi.time_format` |
| `HTML_SSI_SIZEFMT` | `ssi.size_format` |
| `HTML_SSI_ERRMSG` | `ssi.error_message` |
| `HTML_SSI_EXEC` | `ssi.exec_mode` (`true`→`allow-list`, `false`→`deny`) |
| `HTML_SSI_EXEC_CMD_ALLOW` | `ssi.exec_cmd_allow` |
| `HTML_SSI_EXEC_CGI_EXT` | `ssi.exec_cgi_extensions` |
| `HTML_SSI_EXEC_CGI_MODE` | `ssi.exec_cgi_mode` |
| `HTML_SSI_EXEC_TIMEOUT` | `limits.exec_timeout` |
| `HTML_SSI_EXEC_MAX_BYTES` | `limits.max_exec_output_bytes` |
| `HTML_AUTO_SANITIZE_INPUT` | *(removed — call `Input::all()` explicitly)* |
| `HTML_ALLOWED_TAGS` | `security.allowed_tags` |
| `HTML_ESCAPE_VARS` | `syntax.auto_escape` (**default flipped to true**) |
| `HTML_ESCAPER` | `new Html\Escaper($customCallable)` via container |
| `HTML_MISSING_VAR` | `syntax.missing_var` |
| `HTML_DEBUG` | `debug` (+ `syntax.strict` for fail-fast) |
| `HTML_WWW_PATH` | `ssi.document_root` / `RenderOptions::$root` |
| `HTML_I18N_LOCALE` | `i18n.locale` / `$engine->setLocale()` |
| `HTML_I18N_FALLBACK` | `i18n.fallback` |
| `HTML_I18N_DOMAIN` | `i18n.domain` |
| `HTML_I18N_DETECT` | `i18n.detect` |
| `HTML_I18N_WP` | `i18n.use_wordpress` |
| `HTML_CACHE_DIR` | `cache.directory` (+ `cache.enabled: true`) |
| `HTML_CACHE_TTL` | `cache.ttl` |

## 7. Hook name map

`htmlphp_error` → `htmlphp.error` · `htmlphp_render` → `htmlphp.render` ·
`htmlphp_translate` → `htmlphp.translate` · `htmlphp_filter_apply` →
`htmlphp.filter.apply` · `htmlphp_ssi_builtins` → `htmlphp.ssi.builtins` ·
`htmlphp_ssi_include_path` → `htmlphp.ssi.include_path` ·
`htmlphp_ssi_echo` → `htmlphp.ssi.echo` · `htmlphp_missing_var` →
diagnostics `missing-var` (event removed; hook `htmlphp.error`).
Under the WordPress bridge the old underscore names keep working
(the bridge mirrors engine events onto `apply_filters('htmlphp_render')` etc.).

## 8. WordPress bridge

| v3 | v4 |
|---|---|
| `Bridge::boot($config)` (static, globals-based) | `Bridge::boot(Config\|array, ?setup, options): Engine` |
| `TemplateLoader::render($names, $vars, $options, $dirs)` (static) | `(new TemplateLoader($engine))->render($names, $vars, $dirs)` |
| `Shortcodes::register($tag, $path)` (static) | `(new Shortcodes($engine))->register(...)` or `options.shortcode` |
| `html_render_file()` inside shortcodes | engine instance methods |
| Maintenance toolkit | unchanged API, `Health::check($engine)` / `SystemInfo::collect($engine)` now accept the engine |

## 9. Suggested migration order

1. Run `bin/htmlphp lint templates/` on your template tree (catches removed
   syntax and structural errors before switching).
2. Grep PHP code for `html_`/`HTML(`/legacy calls; rewrite per §2–3.
3. Convert `$GLOBALS['HTML_*']` assignments to one `Engine::create([...])`
   array (§6).
4. Flip-test escaping: render key templates with `syntax.auto_escape: false`
   and `true`, add `|raw` where HTML is intentionally passed in.
5. Run `bin/htmlphp i18n:extract` + `i18n:audit` to catch translation gaps
   introduced by the fixed plural rules.
6. Move CI to the matrix: `bash tools/run-matrix.sh`.
