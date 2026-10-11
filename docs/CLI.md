# CLI Reference — `bin/htmlphp`

The standalone CLI works from a Composer install (`vendor/bin/htmlphp`), a
git checkout, or next to a single-file bundle. Library resolution order:
`HTML_PHP_LIB` env var → `vendor/autoload.php` → `autoload.php` (dev) →
`php85/html.php` (on PHP 8.5+) → `php83/html.php`.

```
htmlphp <command> [arguments] [--options]
```

Global option: `--config=FILE` — a PHP file that `return`s a v4 config
array (same shape as `Engine::create([...])`).

Exit codes: `0` success · `1` lint errors / runtime failure · `2` usage error.

Options accept `--key=value` and (since v5) `--key value`; repeatable options
(`--var`) accumulate in both forms.

## Commands

### `version`
```
$ htmlphp version
5.0.0 (8.3 edition) · PHP 8.3.35
```

### `config:dump`
Prints the effective configuration as JSON (every group, enum values
resolved) — the fastest way to verify what a `--config` file produced.

### `lint PATH...`
```
$ htmlphp lint templates/ --format=json --ignore=untranslated-message
templates/card.html:7 ERROR [unknown-filter] Unknown filter "uppr".
2 issue(s), 1 error(s).
```
- accepts files and directories (`.html .htm .txt .shtml .xhtml`)
- `--format=text|json` (JSON goes to stdout **pure**, summary to stderr —
  pipe straight into CI annotations)
- `--ignore=code,code` baselines known findings
- exits `1` when any `error`-severity issue remains

What it checks: unknown/unbalanced/unclosed tags, orphan `@Else/@Elif/@Plural`,
unknown or disabled filters, numeric filter arguments that look like variable
names (warning, v5), broken `@If/@Set` **and inline `%{= …}`** expressions,
`%{= …}` while `syntax.expressions` is off (error, v5), unresolvable
`@Include/@Extends` (when template roots are configured), loop range size,
`@Trans` shape problems, untranslated `@{}` messages for the active locale,
and SSI issues (unknown directives, missing `expr=`/`var=`/include targets,
unparseable `#if` expressions, `#exec` present under a deny policy).

### `render TEMPLATE [--var k=v]...`
Renders a file path or a locator name to stdout. `--var` values are strings;
for structured data use `--config` + a small PHP driver script instead.
Both `--var=k=v` and `--var k=v` are accepted (v5): a space-separated value
is consumed unless the next token is itself an option.

```
$ htmlphp render stats.html --var=done=37 --var=total=250
Progress: 14.8%          ← template: %{= done / total * 100 | round:1 }%
```

### `ssi FILE [--root=DIR]`
Runs only the SSI pass over a file, anchored at that file (`file=`
includes, `LAST_MODIFIED`), contained by `--root`.

### `cache:clear`
Empties the configured render cache backend and the in-memory parse memo.

### `i18n:extract DIR [--out=FILE] [--ext=html]`
Scans templates for `@{Key}` and `@Trans` blocks, writes a POT skeleton
(`--out`) or prints it. Dynamic `%{var|translate}` usages are reported as
warnings on stderr (static extraction cannot see their keys).

### `i18n:audit DIR [--locale=de] [--domain=messages]`
Diffs extracted messages against the locale's catalogue:
```
Locale de: 41/52 messages translated (79%), 3 unused.
  missing: checkout.total
  …
  unused:  legacy.banner
```
Exits `1` when anything is missing — wire it into deploys so a half-done
translation never ships silently.

## CI recipe

```yaml
- run: php bin/htmlphp lint templates/ --format=json > lint.json
- run: php bin/htmlphp i18n:audit templates/ --locale=de
```

## WordPress equivalent

Every command exists as `wp htmlphp …` once the bridge is booted
(see [WORDPRESS.md](WORDPRESS.md)), using the plugin's engine, theme
template roots and WP cache backend.
