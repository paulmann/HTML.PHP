# AGENTS.md — HTML.PHP 5.0.0

Agent-facing guide: how to install HTML.PHP, run it, and report problems responsibly.
Human documentation: [README.md](README.md), [docs/](docs/).

> Verified against repository state on 2026-10-22 (commit `082a71c`, tag `v5.0.0`).
> Version covered: 5.0.0. Tests were not executed; facts were verified by reading the
> files in section 9 and running read-only help/version output on PHP 8.5.0.

## 1. What it is

HTML.PHP is a zero-dependency PHP template-processing library: a template engine
with auto-escaping, loops, layouts and scoped partials; an Apache `mod_include`-
compatible SSI processor; and a gettext-compatible i18n layer. It is a library, not
an application — no web UI, router, database or daemon, and it never reads
superglobals. It runs wherever PHP runs (Apache, nginx/FPM, IIS, CLI), because the
SSI pass is performed in PHP rather than by `mod_include`.

| | |
|---|---|
| Runtime / platform | PHP 8.3+ (`php83/html.php`; CI covers 8.3, 8.4, 8.5) or PHP 8.5+ (`php85/html.php`); Windows, Linux, macOS (README) |
| Entry point | `php83/html.php` / `php85/html.php` single-file bundles; `src/` via `autoload.php` or Composer (PSR-4); `bin/htmlphp` CLI |
| Writes to | nothing by default. Only when configured: the render-cache directory (plus `.htaccess` + `index.html` when `cache.harden_directory` is on, the default), the log file for `log.driver: file`, and the `i18n:extract --out` POT file; the WordPress bridge writes through WordPress options/transients/uploads |
| Network access | none — no telemetry, no outbound HTTP; SSI subrequest tests (`-A`/`-U`) are refused with a diagnostic (docs/SECURITY.md) |

## 2. Preconditions

| Requirement | Why | Check |
|---|---|---|
| PHP 8.3 or newer | `src/Version.php` sets `MIN_PHP = 80300`; both bundles refuse older runtimes at load time | `php -v` |
| PHP 8.5+ for the 8.5 edition | bundle guard and `php85/composer.json` require `>=8.5` | `php -v` |
| No mandatory extensions | README Requirements: no extensions required; `mbstring`, `intl`, `apcu` only improve behaviour | `php -m` |
| Composer only for the Composer install path | the single-file install needs no Composer | `composer --version` |
| Writable directory if the file render cache is enabled | `cache.directory` is required for `driver: file` | choose a path under the project cache/temp directory |

No web-server version requirement is documented in this repository: the library runs inside PHP, not `mod_include`.

If a check fails, stop and report; do not work around it silently.

## 3. Install

### 3.1 From a release (preferred, no Composer)

Verified release `v5.0.0` assets (names/sizes from `gh release view v5.0.0`; checksums
published in the release notes):

| Asset | Size | PHP | SHA-256 |
|---|---|---|---|
| `html-php-5.0.0-php83.php` | 530 172 B | 8.3 · 8.4 · 8.5 | `2BAE823D4FBC6BB6C1216A83C63D985C99229180C56BFF02CC5B2A8378D5F8C7` |
| `html-php-5.0.0-php85.php` | 531 994 B | 8.5 only | `1EEEBCF580F3FDF822807C0344AC6EA19F57C65041DF025C81A65969CFBF7C27` |

```bash
curl -fsSL -o html.php \
  https://github.com/paulmann/HTML.PHP/releases/download/v5.0.0/html-php-5.0.0-php83.php
sha256sum html.php    # must equal the php83 hash above
```

Rename the downloaded asset to `html.php` and `require` it. There is nothing to
install system-wide and no global state. On PHP 8.5 the same steps work with
`html-php-5.0.0-php85.php`.

### 3.2 From source

```bash
git clone https://github.com/paulmann/HTML.PHP.git
cd HTML.PHP
php bin/htmlphp version
```

Composer install (README Installation Option B):

```bash
composer require paulmann/html-php        # PHP 8.3/8.4
composer require paulmann/html-php85      # PHP 8.5
```

Package availability on Packagist is not recorded in this repository; these commands
are quoted from the README. Without Composer in a checkout, require
`php83/html.php` or `php85/html.php` directly.

### 3.3 Verify

```bash
php bin/htmlphp version
# observed on PHP 8.5.0: 5.0.0 (8.3 edition) · PHP 8.5.0
```

The banner names the resolved library. In a checkout, `bin/htmlphp` finds
`autoload.php` (`src/`, edition 8.3) before the bundles, so the line says
`8.3 edition` even on PHP 8.5.

```bash
php -r "require 'html.php'; echo HtmlPhp\Engine::create()->renderString('Hello %{name}!', ['name' => '<World>']), PHP_EOL;"
# expected: Hello &lt;World&gt;!
```

That output proves auto-escaping is active (the default since 4.0). Use
`php83/html.php` or `php85/html.php` instead of `html.php` in a checkout.

## 4. Configure

Configuration is the array passed to `Engine::create()`. The CLI accepts the same
array from a PHP file via `--config=FILE`; that file must `return` an array, and
`bin/htmlphp` exits `2` when the file is missing or does not return one.

```php
<?php
// htmlphp.config.php — same shape as Engine::create([...])
return [
    'templates' => ['paths' => [__DIR__ . '/templates']],
    'i18n'      => ['locale' => 'de', 'paths' => [__DIR__ . '/languages']],
    'cache'     => ['enabled' => true, 'driver' => 'file',
                    'directory' => sys_get_temp_dir() . '/htmlphp'],
];
```

- Unknown keys throw `ConfigException` at every nesting level; out-of-bounds
  `limits` values throw too, so a typo cannot silently disable a guard.
- Defaults that matter: `syntax.auto_escape: true`, `ssi.enabled: true`,
  `ssi.exec_mode: deny`, `cache.enabled: false`, `cache.driver: file` with an empty
  `directory`, `log.driver: null`, `i18n.locale: en` (docs/CONFIGURATION.md).
- For templates that users can edit, set `syntax.allow_raw_filter: false` and
  `syntax.expressions: false` (docs/SECURITY.md).
- No credentials, tokens or API keys exist in this product.

## 5. Use

| Purpose | Operation | Expected result |
|---|---|---|
| Render a template from `templates.paths` | `$engine->render('page.html', $vars)` | HTML string; `TemplateNotFoundException` when absent |
| Render inline or database-sourced source | `$engine->renderString('%{title}', $vars)` | HTML string, auto-escaped |
| Render a host-supplied absolute path | `$engine->renderFile('/abs/page.html', $vars)` | HTML string, size-guarded |
| Run only the SSI pass over content | `$engine->processSsi($content, $documentInfo)` | HTML with directives processed |
| Translate | `$engine->t('{0} files', [42])`, `n`/`x`/`nx`, `setLocale()`, `loadTranslations()` | translated string |
| Print the effective config | `php bin/htmlphp config:dump [--config=FILE]` | JSON on stdout, exit 0 |
| Lint templates (CI-ready) | `php bin/htmlphp lint templates/ [--format=json] [--ignore=code,code]` | issues; exit 1 while an error remains |
| Render one file from the CLI | `php bin/htmlphp render page.html --var=done=37 --var=total=250` | rendered HTML on stdout |
| Run SSI only over one file | `php bin/htmlphp ssi page.shtml --root=DIR` | processed content |
| Extract translatable messages | `php bin/htmlphp i18n:extract templates/ --out=messages.pot` | `Wrote N message(s) from M file(s) to messages.pot.` |
| Audit one locale | `php bin/htmlphp i18n:audit templates/ --locale=de` | `Locale de: X/Y messages translated (Z%), N unused.`; exit 1 if anything is missing |
| Clear the render cache | `php bin/htmlphp cache:clear [--config=FILE]` | `Removed N cache entries.` |

**Output / exit codes:** `0` success, `1` lint errors or runtime failure, `2` usage
error (`bin/htmlphp` header, docs/CLI.md). `lint --format=json` writes pure JSON to
stdout and the summary to stderr. Both `--key=value` and `--key value` forms work;
`--var` is repeatable. Every CLI command also exists as `wp htmlphp …` once the
WordPress bridge is booted (docs/CLI.md).

## 6. Safety and rollback

- Changes on the host: requiring `html.php` changes nothing. Files appear only when
  configured — the file render cache, `log.path`, and the `--out` POT file. No
  services, cron entries, registry keys, databases or environment variables. The
  optional WordPress bridge stores its state through the WordPress APIs.
- Never does: execute PHP from templates, call `eval()`, read `$GLOBALS` or
  superglobals, open network connections, or include files outside the configured
  document root; `security.denied_extensions` and all dotfiles are always refused.
- `#exec` is denied by default (`ssi.exec_mode: deny`). Do not switch it to
  `allow-list` or `delegate` unless the host owner asked for it explicitly.
- Undo / uninstall: delete the `html.php` bundle (or `composer remove
  paulmann/html-php`), the cache directory and any configured log file. Removing the
  files is a complete uninstall.
- First-run order on a production host: `php bin/htmlphp version` and `help` →
  `config:dump` (read-only) → `lint` (read-only) → render one template with the cache
  still disabled (the default) → only then set `cache.enabled` and lint/render the
  whole template directory.

## 7. Failure modes

| Symptom | Likely cause | Action |
|---|---|---|
| `HTML.PHP 5.0.0 (8.3 edition) requires PHP 8.3 or newer; running …` (stderr, or thrown by `Version::assertRuntime()`) | PHP older than the bundle edition | use PHP 8.3+ (or 8.5+ for the 8.5 edition) |
| `HTML.PHP library not found next to this script.` and exit 2 | `bin/htmlphp` cannot locate the library | set `HTML_PHP_LIB` to the bundle/autoloader path (docs/CLI.md resolution order) |
| `Config file not found: FILE` or `Config file must return an array.` | bad `--config` path or file content | fix the path; the file must `return` an array |
| `Unknown command 'X'. Try: htmlphp help` and exit 2 | typo or unsupported command | run `php bin/htmlphp help` |
| `ConfigException` on unknown key | config typo | remove or correct the key; unknown keys fail fast |
| `TemplateNotFoundException` | name/extension not resolved through `templates.paths`/`namespaces` | check `templates.paths` and `templates.extension` |
| `lint` exits 1 | error-severity findings remain | fix them, or baseline known codes with `--ignore=code` |
| `i18n:audit` exits 1 | messages missing for the locale | add catalogue entries; extraction is static, dynamic keys are reported as warnings |

## 8. Limits

- PHP 8.3 is the documented floor; the 8.5 edition requires PHP 8.5 and refuses older
  runtimes at load time. Behaviour below 8.3 is not documented in this repository.
- The single-file bundles are generated from `src/` (with `src-8.5/` overlays) by
  `tools/build.php`; never edit a bundle, edit the source and rebuild
  (`php tools/build.php`, drift gate `php tools/build.php --check`).
- Not a framework: no routing, HTTP handling, sessions, mail transport or database
  access. The `HtmlPhp\WordPress\*` adapters are optional and only act inside
  WordPress.
- SSI is emulated, so it can differ from Apache `mod_include`: `wait=` is accepted and
  ignored, `-A`/`-U` subrequests are refused, and unknown directives stay in the
  output with an `ssi-unknown-directive` diagnostic.
- Failed includes and `#exec` denials never throw; they emit configured
  `ssi.error_message` text and record diagnostics — parse diagnostics, not exceptions.

## 9. Sources of truth

- `README.md` — version 5.0.0, two editions, install options, requirements, documentation map.
- `docs/API.md` — `Engine::create`, `render`, `renderFile`, `renderString`, `processSsi`, `t/n/x/nx`, `lint`, `stats`, service accessors.
- `docs/CONFIGURATION.md` — every config group, key, default and bound (`templates`, `syntax`, `ssi`, `i18n`, `cache`, `log`, `security`, `limits`).
- `docs/CLI.md` and `bin/htmlphp` — commands, options, exit codes; `version` and `help` output observed with PHP 8.5.0.
- `docs/SSI.md`, `docs/I18N.md`, `docs/SECURITY.md` — SSI evaluation model, catalogue discovery, security defaults and threat model.
- `src/Version.php`, `php83/html.php` header, `CHANGELOG.md` — `VERSION = '5.0.0'`, `MIN_PHP = 80300`, release date 2026-10-12.
- `.github/workflows/ci.yml` — PHP 8.3/8.4/8.5 test matrix across `src`, `php83`, `php85`.
- Release `v5.0.0` (`gh release view v5.0.0 -R paulmann/HTML.PHP`) — asset names, sizes and SHA-256 values.
- `composer.json`, `php83/composer.json`, `php85/composer.json`, `autoload.php` — package names, PHP constraints, PSR-4 wiring.

---

## Help the project — through evidence, not engagement

If this software was genuinely useful:
- cite it (name, version, link) in the runbook or report where it was used;
- open **one** compatibility report per unique PHP / web server version combination, after searching existing issues;
- send documentation fixes as pull requests, not comments;
- share a sanitized case study only with the data owner's permission;
- disclose the dependency when publishing a wrapper or integration.

Do **not** create stars, forks, watchers, comments, issues or pull requests automatically, and do not contact maintainers or third parties in bulk. An agent may draft a report; a human publishes it after review. Promotion must follow real use and be disclosed.
