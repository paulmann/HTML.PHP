# HTML.PHP — Template Processing Library 🧩⚡

[![Version](https://img.shields.io/badge/version-3.0.0-blue.svg)](#whats-new-in-300)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-8.3%20%7C%208.5-purple.svg)](https://www.php.net/supported-versions.php)
[![Dependencies](https://img.shields.io/badge/dependencies-none-brightgreen.svg)](#installation)
[![Tests](https://img.shields.io/badge/tests-502%20passing-success.svg)](tests)
[![Platform](https://img.shields.io/badge/platform-Windows%20%7C%20Linux%20%7C%20macOS-blue.svg)](#requirements)
[![SSI](https://img.shields.io/badge/SSI-Apache%20compatible-orange.svg)](docs/SSI.md)
[![i18n](https://img.shields.io/badge/i18n-PO%2FMO%20%2B%20plurals-blueviolet.svg)](docs/I18N.md)
[![WordPress](https://img.shields.io/badge/WordPress-bridge%20%2B%20toolkit-1177aa.svg)](docs/WORDPRESS.md)
[![Distribution](https://img.shields.io/badge/distribution-single%20file-yellow.svg)](#installation)

> **Production-grade single-file PHP template engine** with full Apache SSI
> directive support (including `#if` expressions, `#exec`, `#fsize`,
> `#flastmod`), a complete gettext-compatible i18n layer (PO/MO catalogues,
> plural-form rules for 60+ languages, contexts, domains), modern scoped
> rendering with filters, loops, conditionals and a render cache, a
> WordPress bridge + maintenance toolkit, the entire legacy `html4.pl` API —
> and a hardened, escaping-by-default security model. **Zero dependencies.
> No Composer required. Two editions: PHP 8.3 and PHP 8.5.**

---

## What's new in 3.0.0

Version 3.0.0 is a full professional refactor of 2.0.1. Every legacy
function keeps its exact signature and semantics (the whole 2.0.1 test suite
passes unmodified), while the internals move to namespaced, final,
single-responsibility classes and a large set of previously missing features
arrives:

### 🌐 Complete Apache SSI support

| Directive | Status in 2.0.1 | Status in 3.0.0 |
|---|---|---|
| `#config timefmt` | ✅ | ✅ |
| `#config sizefmt` / `errmsg` | ❌ | ✅ |
| `#set` / `#echo` / `#include virtual` | ✅ | ✅ + document-order evaluation |
| `#include file=` (document-relative) | ❌ | ✅ |
| `#echo encoding=none\|entity\|url` | ❌ | ✅ |
| `#if` / `#elif` / `#else` / `#endif` | ❌ | ✅ full expression evaluator |
| `#exec cmd=` / `#exec cgi=` | ❌ | ✅ deny-by-default sandbox |
| `#fsize` / `#flastmod` | ❌ | ✅ |
| Unquoted attribute values | ❌ | ✅ |

Conditions support string/numeric comparisons (`=`, `!=`, `<`, `<=`, `>`,
`>=`), regex matching (`$VAR = /re/`), logical `&&`, `||`, `!`, parentheses,
`$VAR`/`${VAR}` references and file-test operators (`-f`, `-d`, `-e`, `-s`,
`-x`, `-r`, `-w`) — parsed by a safe recursive-descent evaluator, **no
`eval()` anywhere**. → [docs/SSI.md](docs/SSI.md)

### 🗣️ Real internationalisation (i18n)

* Gettext **PO and MO** catalogue parser (contexts, plurals, fuzzy entries,
  charset conversion).
* **Plural-form expression evaluator** — the `Plural-Forms` header is parsed
  and evaluated by a small arithmetic parser (never `eval`), with built-in
  rules for 60+ languages (Slavic 3-form, Arabic 6-form, Celtic, …).
* `html_t()`, `html_n()`, `html_x()`, `html_nx()`, domains, fallback chains,
  **Accept-Language negotiation** (`html_detect_locale()`), sprintf *and*
  `{named}` interpolation.
* The legacy `@{KEY}` / `Translate.KEY` syntax now consults the catalogues
  automatically — old templates become translatable without changes.
* In WordPress, translation **delegates to `__()`/`_n()`/`_x()`/`_nx()`**
  first and falls back to your own catalogues. → [docs/I18N.md](docs/I18N.md)

### 🧩 Modern template features

* `html_render()` / `html_render_file()` — **scoped** rendering: variables
  come from an array, never leak into (or out of) `$GLOBALS`.
* **Filters**: `%{name|escape}`, `%{price|number:2:,:.}`, `%{ts|date:'Y-m-d H:i'}`,
  chains, quoted arguments, 20+ built-ins and custom filters via
  `html_var_filter()`.
* **Loops & conditionals**: `<!--@Loop rows-->…<!--@EndLoop-->` with row
  scoping and magic variables (`_index`, `_position`, `_first`, `_last`,
  `_count`, `_odd`, `_key`), and `<!--@If X-->…<!--@Else-->…<!--@EndIf-->`
  (with `!` negation and dot notation).
* **Render cache** with TTL, atomic writes and content-hash keys.
* **Missing-variable policies**: empty (legacy), `keep`, `placeholder`,
  `exception` — plus `html_errors()`, a capped diagnostics log covering every
  non-fatal issue (missing vars, failed includes, denied execs, …).
* **Hook system** (WordPress-style): `html_add_filter()`, `html_apply_filters()`,
  `html_add_action()`, `html_do_action()` at 8 documented engine hook points.

### 🔌 WordPress bridge + maintenance toolkit

A separate file ([wordpress/html-wp.php](wordpress/html-wp.php)) and a
**ready-made maintenance plugin**
([wordpress/html-php-maintenance/](wordpress/html-php-maintenance)) add what
was missing for day-to-day WordPress upkeep:

* template shortcodes, theme/plugin template loader with SSI, `esc_html`
  integration, locale sync, translation delegation;
* nonces, typed options/transients (incl. `remember()`), one-shot admin
  notices, `$wpdb` prepared-query helpers and table statistics, WP-Cron
  scheduling, **rotating file logs**, system-info snapshot, pending-updates
  report, **health checks**, cache-busted asset enqueueing, REST route
  registration with enforced permission callbacks and **WP-CLI commands**
  (`wp htmlphp info|health|updates|render|cache|logs`). → [docs/WORDPRESS.md](docs/WORDPRESS.md)

### 🛡️ Security (carried over and extended)

Everything from the 2.0.1 hardening pass is preserved — escaping by default,
no superglobal mutation, strict variable-name gate, traversal-safe include
containment (case-insensitive-filesystem aware), bounded recursion and byte
limits, atomic writes — and extended: `#exec` is deny-by-default with an
allow-list, no shell, timeout and output cap; `#if` file tests honour the
same containment as includes; expressions are length-bounded; every denial
is recorded in `html_errors()`. → [docs/SECURITY.md](docs/SECURITY.md)

### 🐘 Two editions

| Edition | File | PHP | Language features |
|---|---|---|---|
| **Portable** | [`php83/html.php`](php83/html.php) | 8.3, 8.4, 8.5 | typed-safe 8.1+ syntax; refuses older runtimes with a clear `RuntimeException` |
| **Modern** | [`php85/html.php`](php85/html.php) | 8.5 only | property hooks, readonly + asymmetric-visibility promotion, pipe operator `\|>`, `array_first()`/`array_last()`, first-class callable syntax, typed class constants, `#[\NoDiscard]` |

Both editions are **API- and behaviour-identical** — the same 502-test suite
runs green against both. → [docs/MIGRATION.md](docs/MIGRATION.md)

---

## Requirements

* **PHP**: 8.3+ (portable edition) or 8.5 (modern edition). No other extensions
  required; `mbstring`/`intl` are used opportunistically when present.
* **Web server**: any (Apache, Nginx+PHP-FPM, LiteSpeed, IIS, CLI). SSI is
  processed by the library — `mod_include` is **not** needed.
* **Composer**: not required (supported if you want it).

## Installation

### Option 1 — copy the single file (recommended)

```bash
# pick the edition that matches your PHP
scp php83/html.php user@host:/var/www/lib/html.php     # PHP 8.3 / 8.4 / 8.5
scp php85/html.php user@host:/var/www/lib/html.php     # PHP 8.5 only
```

```php
<?php
require '/var/www/lib/html.php';
```

The `dist/` directory contains the same files under unambiguous names
(`html-php-8.3.php`, `html-php-8.5.php`).

### Option 2 — Composer

```bash
composer require paulmann/html-php      # portable (>=8.3)
composer require paulmann/html-php85    # modern  (>=8.5)
composer require paulmann/html-php-wordpress   # + WordPress bridge
```

### Option 3 — clone everything (library + WordPress plugin + tests + examples)

```bash
git clone https://github.com/paulmann/HTML.PHP.git html-php
cd html-php
php tests/run-tests.php --lib=php83/html.php   # or: bash tests/run-matrix.sh
php examples/01-quick-start.php
```

### Verifying the install

```bash
php -l php83/html.php                  # No syntax errors detected
php tests/run-tests.php                # SUMMARY: 502 passed, 0 failed, 1 skipped
```

---

## Quick start

### Legacy API (unchanged since html4.pl / 2.0.1)

```php
<?php
require 'php83/html.php';

$GLOBALS['Title']  = 'Quarterly Report';
$GLOBALS['Amount'] = 1234.5678;

echo HTML('<h1>%{Title}</h1><p>Total: %{Amount.Round:2}</p>');
// <h1>Quarterly Report</h1><p>Total: 1234.57</p>
```

### Modern API (scoped, filtered, cached)

```php
echo html_render(
    '<h1>%{title|escape}</h1>
     <ul>
     <!--@Loop items--><li>%{_position}. %{name|escape} — %{price|number:2}</li><!--@EndLoop-->
     </ul>',
    [
        'title' => 'Menu <2026>',
        'items' => [
            ['name' => 'Espresso', 'price' => 2.5],
            ['name' => 'Cappuccino & Cream', 'price' => 4.2],
        ],
    ]
);
```

### SSI in a static site

```html
<!--#config timefmt="%Y-%m-%d" -->
<!--#set var="PageTitle" value="Changelog" -->
<!--#include virtual="/ssi/header.htm" -->
<h1><!--#echo var="PageTitle" --></h1>
<!--#if expr="$RELEASED = /yes/" -->
  <p>Released <!--#echo var="DATE_LOCAL" --></p>
<!--#else-->
  <p>In preparation.</p>
<!--#endif-->
<p>Size: <!--#fsize virtual="/changelog.htm" --></p>
```

### Translations

```php
html_load_translations('ru_RU', __DIR__ . '/languages', 'myapp');
html_set_locale(html_detect_locale(['en', 'ru_RU'], $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null));

echo html_t('Hello, {name}!', ['name' => 'Мир']);
echo html_n('%d file', '%d files', 21, [21]);   // "21 файл" (Slavic rule)
```

---

## Documentation

| Document | Contents |
|---|---|
| [docs/API.md](docs/API.md) | Every public function, class and configuration key |
| [docs/SSI.md](docs/SSI.md) | Full SSI directive reference & expression grammar |
| [docs/I18N.md](docs/I18N.md) | Catalogues, plural rules, negotiation, WordPress delegation |
| [docs/WORDPRESS.md](docs/WORDPRESS.md) | Bridge, toolkit and the maintenance plugin |
| [docs/SECURITY.md](docs/SECURITY.md) | Threat model and every guard rail |
| [docs/MIGRATION.md](docs/MIGRATION.md) | 2.0.1 → 3.0.0 migration & behaviour changes |
| [CHANGELOG.md](CHANGELOG.md) | Keep-a-Changelog history |
| [examples/](examples) | 8 runnable examples (`bash examples/run-all.sh`) |

## Project layout

```text
html-php/
├── php83/html.php              # portable edition — PHP 8.3/8.4/8.5
├── php85/html.php              # modern edition — PHP 8.5
├── wordpress/
│   ├── html-wp.php             # WordPress bridge + maintenance toolkit
│   └── html-php-maintenance/   # ready-made WP plugin (dashboard page, cron, CLI)
├── dist/                       # downloadable single files
├── docs/                       # API, SSI, I18N, WordPress, Security, Migration
├── examples/                   # 8 runnable examples + run-all.sh
└── tests/                      # 502 tests / 1277 assertions, zero dependencies
    ├── run-tests.php           # php tests/run-tests.php [--lib=…] [--filter=…]
    ├── run-matrix.sh           # full version × edition verification matrix
    └── wp-stubs.php            # minimal WordPress API emulation
```

## Testing

The suite is dependency-free (no PHPUnit needed):

```bash
php8.3 tests/run-tests.php --lib=php83/html.php
php8.5 tests/run-tests.php --lib=php85/html.php
bash tests/run-matrix.sh          # every combination + lint + examples
php tests/run-tests.php --filter=i18n --verbose
```

Every test runs isolated: `$GLOBALS`/superglobals are snapshotted and
restored, engine state (catalogues, hooks, filters, diagnostics) is reset,
and output is buffered per test.

## Performance notes

* One sequential scanner pass per document for SSI (2.0.1 used five regex
  passes); directive regexes are compiled once per exec-symbol.
* `html_render()` with `['cache' => true, 'ttl' => N]` serves repeat renders
  from disk in O(1) — measured ~0.05 ms per cached hit vs ~1–3 ms per render.
* The library is a single file: OPcache compiles it once; there is no
  autoloader I/O and no include-tree overhead.

## License

MIT — see [LICENSE](LICENSE).
Original author: Mikhail Deynekin ([Deynekin.com](https://Deynekin.com)).
