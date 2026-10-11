# HTML.PHP — Template Processing Library 🧩⚡

![Version](https://img.shields.io/badge/version-4.0.0-blue.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)
![PHP](https://img.shields.io/badge/php-8.3%20%7C%208.5-purple.svg)
![Dependencies](https://img.shields.io/badge/dependencies-none-brightgreen.svg)
![Tests](https://img.shields.io/badge/tests-329%20passing-success.svg)
![PHPStan](https://img.shields.io/badge/PHPStan-level%206-success.svg)
![Platform](https://img.shields.io/badge/platform-Windows%20%7C%20Linux%20%7C%20macOS-blue.svg)

> **Production-grade PHP 8.3+/8.5 template engine** with full Apache SSI
> directive support (`#if` expressions, `#exec` policies, `#printenv`,
> `#block`/`stub`), a gettext-compatible i18n layer (PO/MO/JSON/PHP
> catalogues, plural rules for 60+ languages, extraction & audit tooling),
> layout inheritance, scoped partials, a safe expression language, a render
> cache, a template linter, profiling stats, an optional WordPress bridge +
> maintenance toolkit, and a hardened **escape-by-default** security model
> with **zero `$GLOBALS`, zero superglobal reads in the core, zero
> dependencies**.

---

## What's new in 4.0.0

Version 4 is a **clean-break rewrite** of 3.0.0 — no backward compatibility
with the legacy `html4.pl` API and no hidden global state. See
[docs/MIGRATION-3.x-TO-4.0.md](docs/MIGRATION-3.x-TO-4.0.md) for the full
mapping table.

| 3.x | 4.0 |
|---|---|
| ~35 `$GLOBALS['HTML_*']` config keys | one immutable `Config` value object (unknown keys **fail fast**) |
| static registries (`Hooks`, `Errors`, `Filters::$custom`, static `Translator`) | a small DI `Container`; every service is an instance, engines are independent |
| `$_SERVER` reads inside SSI | explicit `RequestContext` built once at the edge |
| `html_render()` global function | `Engine::create(...)->render()` |
| regex-recursion block processing | real parser: tag stream → node tree (shared by renderer **and** linter) |
| — | layout inheritance `@Extends`/`@Section`/`@SectionParent` |
| — | scoped partials `@Include … with="a,b" only ignore-missing` |
| — | safe expression language in `@If`/`@Elif`/`@Unless`/`@Set`/loop ranges |
| — | `@Trans` gettext-plural blocks, `@Raw`, `@Comment`, `@Break`, `@Continue` |
| escape off by default | **auto-escape ON by default**, `\|raw` opt-out (disable-able) |
| SSI: 10 directives | + `#printenv`, `#block`/`#endblock` + `stub=`, `#echo encoding=base64`, `wait=` accepted, custom directive registry |
| PO/MO catalogues | + JSON/PHP-array catalogues, multi-path **merge**, `I18n\Extractor`, `I18n\Auditor`, locale-aware `Formats` |
| file cache hardwired | `CacheInterface`: file / APCu / array / null / **WordPress object cache + transients** / PSR-16 adapter |
| `html_errors()` statics | injectable `Diagnostics` + PSR-3-shaped `LoggerInterface` + tagged `Event\Dispatcher` |
| — | template **linter** (CLI + PHP API + WP admin + WP-CLI, CI exit codes) |
| — | `RenderStats` profiling, `bin/htmlphp` CLI, GitHub Actions matrix, PHPStan level 6 |

## Two editions

| Edition | File | PHP | Language features |
|---|---|---|---|
| **8.3** (portable) | [`php83/html.php`](php83/html.php) | 8.3 – 8.5+ | typed class constants, readonly classes, enums |
| **8.5** (modern) | [`php85/html.php`](php85/html.php) | 8.5+ | everything above **plus** pipe operator `\|>`, `array_find()`, property hooks, asymmetric visibility, `#[\NoDiscard]` |

Both are generated from the same PSR-4 source tree ([`src/`](src), with
[`src-8.5/`](src-8.5) overlays) by [`tools/build.php`](tools/build.php), and
both pass the identical test matrix (329 tests / ~960 assertions each).

## Installation

**Option A — single file (no Composer):** copy `php83/html.php` (or
`php85/html.php` on PHP 8.5) into your project and `require` it. Done.

**Option B — Composer (PSR-4 source):**

```bash
composer require paulmann/html-php        # PHP 8.3/8.4
composer require paulmann/html-php85      # PHP 8.5
```

**Option C — WordPress:** install the bundled plugin in
[`wordpress/html-php/`](wordpress/html-php/) (see
[docs/WORDPRESS.md](docs/WORDPRESS.md)).

## Quick start

```php
require_once __DIR__ . '/php83/html.php';   // or Composer's autoloader

use HtmlPhp\Engine;
use HtmlPhp\Template\RenderOptions;

$engine = Engine::create([
    'templates' => ['paths' => [__DIR__ . '/templates']],
    'i18n'      => ['locale' => 'de', 'paths' => [__DIR__ . '/languages']],
    'cache'     => ['enabled' => true, 'driver' => 'file', 'directory' => sys_get_temp_dir() . '/htmlphp'],
]);

echo $engine->render('page.html', [
    'title' => 'Hello <World>',
    'rows'  => [['name' => 'Ada'], ['name' => 'Grace']],
]);
```

```html
<!-- templates/page.html -->
<h1>%{title}</h1>                      <!-- auto-escaped: Hello &lt;World&gt; -->
<ul>
<!--@Loop rows-->
    <li>%{_position}. %{name|upper}</li>
<!--@Else-->
    <li>@{list.empty}</li>             <!-- translated when the list is empty -->
<!--@EndLoop-->
</ul>
<!--#include virtual="/partials/footer.shtml" -->   <!-- Apache SSI -->
```

### The one-liner tour

```php
$engine->renderString('%{price|number:2}', ['price' => 1234.5]);   // "1,234.50" (locale-aware)
$engine->renderString('<!--@If total > 100 -->FREE<!--@Else-->%{ship|number:2}<!--@EndIf-->', $vars);
$engine->renderString('<!--@Set total="price * qty-->%{total|round:2}', $vars);
$engine->render('child.html');                 // <!--@Extends "base.html"--> inheritance
$engine->t('{0} files', [42]);                 // gettext with interpolation
$engine->processSsi($postContent);             // SSI over arbitrary strings (WP content!)
$engine->lintDirectory(__DIR__ . '/templates'); // CI-ready template validation
$engine->stats();                               // timings, loops, includes, cache hits
```

## Architecture at a glance

```
Engine  ── facade: render / renderFile / renderString / lint / t / processSsi / stats
  │
  ├─ Config (immutable value object)      ├─ Container (PSR-11-shaped, lazy)
  ├─ Template\Renderer  ── pipeline: parse → layouts → tree render → SSI → cache
  │     ├─ TagParser → Node tree           ├─ TreeRenderer (loops/ifs/sections/partials)
  │     ├─ Substitution (%{} #{} @{} + filters)   ├─ Expression (safe evaluator)
  │     └─ Linter (reuses the parser)
  ├─ Ssi\Processor ── mod_include: scanner + DirectiveRegistry + policies
  ├─ I18n\Translator ── catalogues, plural forms, fallbacks, WP delegation
  ├─ Contracts\CacheInterface ── file/apcu/array/null/wp/psr16
  ├─ Log\LoggerInterface ── null/array/file/errorlog/syslog/wp/psr3
  ├─ Event\Dispatcher ── WP-hook-shaped tags & priorities
  ├─ Diagnostics ── capped structured collector (codes stable since 3.x)
  └─ WordPress\* ── Bridge, WpCache, WpLogger, TemplateLoader, Shortcodes,
                    AdminPage, Cli, Mailer + Maintenance toolkit (optional)
```

Hard rule enforced by the test suite (token-level scan): **the core never
calls `eval()` and never reads `$GLOBALS`/superglobals** — the only
sanctioned edge points are `HtmlPhp\Input` (explicit host-input helper) and
the `WordPress\*` adapters (which exist to talk to WordPress).

## Documentation

| Document | Contents |
|---|---|
| [docs/TEMPLATE-SYNTAX.md](docs/TEMPLATE-SYNTAX.md) | substitutions, filters, loops, conditionals, layouts, partials, trans blocks |
| [docs/SSI.md](docs/SSI.md) | every directive, expression grammar, exec policies, security |
| [docs/I18N.md](docs/I18N.md) | catalogues, plural rules, extraction, audit, WordPress delegation |
| [docs/API.md](docs/API.md) | full class/method reference |
| [docs/CONFIGURATION.md](docs/CONFIGURATION.md) | every config key, bounds and default |
| [docs/WORDPRESS.md](docs/WORDPRESS.md) | bridge, plugin, maintenance toolkit, WP-CLI |
| [docs/CLI.md](docs/CLI.md) | `bin/htmlphp` commands |
| [docs/SECURITY.md](docs/SECURITY.md) | threat model & hardening guide |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | layering, pipeline, editions, decisions |
| [docs/MIGRATION-3.x-TO-4.0.md](docs/MIGRATION-3.x-TO-4.0.md) | 1:1 mapping for every removed function |
| [CHANGELOG.md](CHANGELOG.md) | release history |

## Development

```bash
php8.3 tests/run.php                        # source tree
php8.5 tests/run.php --lib=php85/html.php   # 8.5 bundle
bash tools/run-matrix.sh                    # full matrix (8.3/8.4/8.5 × src/bundles)
php tools/build.php                         # regenerate both bundles
php tools/bench.php                         # throughput benchmark
```

CI ([.github/workflows/ci.yml](.github/workflows/ci.yml)) runs the matrix,
a bundle-drift check (`build.php --check`), PHPStan and PHP-CS-Fixer.

## Requirements

PHP 8.3+ (8.5 edition: PHP 8.5+). No extensions required; `mbstring`
improves truncation/slugs, `intl` improves transliteration, `apcu` enables
the APCu cache driver.

## License

MIT © Mikhail Deynekin — see [LICENSE](LICENSE).
