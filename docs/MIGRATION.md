# Migration Guide — 2.0.1 → 3.0.0

## The short version

Drop in the new file, keep every call site. The full 2.0.1 public API —
`HTML()`, `processSSI()`, `processHtml()`, `LoadTemplate()`, `LoadVar()`,
`update_template()`, `sanitizeXSS()`, `HTML_Sanitized_Input()`,
`Read_HTML()`, `Show_HTML()`, `Divide()`, `lc_Tags()`, `normalize()`,
`clean()`, `clean_html()`, `WriteFile()`, `TrimSpaces()`, the
`HTML_RESERVED_GLOBALS` / `HTML_SSI_BUILTIN_VARS` constants, every
`$GLOBALS['HTML_*']` configuration key and the seven `_html*()` internals
used by third-party test suites — behaves identically. The 2.0.1 test suite
(276 tests) passes against 3.0.0 unmodified except for three assertions
about the file header (version strings) and two call sites of a removed
internal (`_htmlProcessVariables`, replaced by
`HtmlPhp\Template\Substitution::process`).

## Behaviour changes to review

1. **SSI evaluates in document order** (Apache mod_include semantics).
   2.0.1 pre-evaluated all `#config`/`#set` directives of a file before
   includes and echoes. If a template relied on a `#set` placed *below* the
   `#include`/`#echo` that consumes it, move the `#set` up. Everything else
   (variables set at the top being visible to partials below) is unchanged.

2. **Unknown/legacy directives** stay in the output verbatim (unchanged),
   but failed includes/execs now additionally record diagnostics in
   `html_errors()` — invisible unless you look, but hookable via
   `htmlphp_error`.

3. **`#echo` of new built-ins.** The built-in variable list grew
   (`REQUEST_METHOD`, `REMOTE_ADDR`, `SERVER_SOFTWARE`, `SERVER_PROTOCOL`,
   `REQUEST_URI`, `SCRIPT_NAME`, `CONTENT_TYPE`). A template global with one
   of these names is now shadowed by the built-in in `#echo` (Apache
   parity), and `#set` can no longer write those names. Rename such
   template variables if you had them.

4. **Unquoted SSI attribute values** (`<!--#echo var=NAME -->`) are now
   accepted (2.0.1 required quotes). No valid 2.0.1 template changes
   meaning.

5. **`Translate.KEY` resolution order** in `update_template()`:
   `$GLOBALS['Translate.KEY']` still wins (legacy first), then the i18n
   catalogue, then the key. If you load catalogues *and* kept legacy
   globals, globals take precedence in the legacy pipeline; the modern
   `html_render()` pipeline prefers the scope, then the catalogue.

6. **`WriteFile()` / `Read_HTML()` / render size failures** throw
   `HtmlPhp\TemplateException`, which extends `RuntimeException` — existing
   `catch (RuntimeException …)` keeps working.

7. **Percent formatting of `Div` results** is unchanged (full precision via
   `stringify`), including the legacy floating-point tails
   (`617.28390000000002`); use `.Round Div`/`|round` for display values.

## Upgrading step by step

```bash
# 1. Lint against your runtime
php -l php83/html.php            # or php85/html.php on PHP 8.5

# 2. Run your own suite; then ours as a smoke test
php tests/run-tests.php --lib=php83/html.php

# 3. Swap the file
cp php83/html.php /var/www/lib/html.php
```

Runtime floor: the portable edition refuses PHP < 8.3 with a clear
`RuntimeException` (2.0.1 did the same). The modern edition (`php85/`)
requires PHP 8.5 and fails with a parse error below it — intentional; ship
`php83/html.php` to unknown hosts.

## Adopting the new API (optional, incremental)

| Old habit | New option |
|---|---|
| `$GLOBALS['X'] = …; HTML($tpl)` | `html_render($tpl, ['X' => …])` — scoped, no globals |
| hand-rolled loops in PHP | `<!--@Loop rows-->` blocks |
| manual `htmlspecialchars` | `\|escape` filter or `['escape' => true]` |
| `Translate.*` globals | PO/MO catalogues + `html_t()`/`@{KEY}` |
| silent breakage | `html_errors()` + `HTML_MISSING_VAR` policies |
| per-request re-render | `['cache' => true, 'ttl' => 300]` |
| ad-hoc hooks | `html_add_filter()`/`html_add_action()` |

The two APIs mix freely: `HTML()` gains catalogue translations and
diagnostics; `html_render()` understands every legacy directive form.
