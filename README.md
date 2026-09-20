# HTML.PHP — Template Processing Library 🧩⚡

![Version](https://img.shields.io/badge/version-2.0.1-blue.svg)
![Build](https://img.shields.io/badge/build-2026--09--20.2-informational.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)
![PHP](https://img.shields.io/badge/php-8.3%2B-purple.svg)
![Dependencies](https://img.shields.io/badge/dependencies-none-brightgreen.svg)
![Tests](https://img.shields.io/badge/tests-276%20passing-success.svg)
![Platform](https://img.shields.io/badge/platform-Windows%20%7C%20Linux%20%7C%20macOS-blue.svg)
![SSI](https://img.shields.io/badge/ssi-apache%20compatible-orange.svg)
![Style](https://img.shields.io/badge/distribution-single%20file-yellow.svg)

> **Production-grade single-file PHP template engine with Apache SSI compatibility, legacy `html4.pl` parity, a hardened security model, and a zero-dependency test suite of 276 tests / 748 assertions. Drop-in replacement — no Composer, no extensions beyond the PHP standard library.**

`html.php` renders templates that mix plain HTML with SSI directives, `%VARIABLE%` substitution, numeric formatting, and legacy `html4.pl`-style blocks. It is deliberately distributed as **one file** so it can be dropped into shared hosting (`/var/www/lib/html.php`), copied over FTP, or vendored into any project without a build step. Version 2.0.1 keeps the entire public API of the Perl-era library while replacing the fragile parts with a bounded, escaping-by-default, traversal-safe implementation.

---

## 🚀 What's New in 2.0.1

### 🛡️ Security hardening

- **Escaping is on by default.** `<!--#echo var="NAME" -->` now HTML-escapes its output unless `HTML_SSI_ECHO_ESCAPE` is explicitly disabled.
- **No more superglobal mutation.** `$_GET`, `$_POST`, `$_COOKIE` and `$_REQUEST` are never rewritten on include. Sanitised input is available on demand via `HTML_Sanitized_Input()`.
- **Strict variable-name gate.** Every `$GLOBALS` read/write driven by a template directive passes `_htmlValidVariableName()` — empty names, null bytes, path-like names and superglobal tricks are rejected.
- **Safer include containment.** Resolved include paths are normalised and compared case-insensitively on Windows/macOS, so a file outside the document root can never be reached even on case-insensitive filesystems.
- **Flexible and safe directive parsing.** SSI attributes are parsed generically, so attribute order no longer matters, while quoted values containing `>` are still handled correctly.

### 🧰 Robustness and correctness

- **`strftime` → `date` conversion fixed.** Literal characters in an SSI time format are escaped, so `Updated at %H:%M` renders as `Updated at 14:30` instead of leaking `date()` tokens. `%h` now maps to the abbreviated month (`Sep`), matching `strftime` semantics.
- **`normalize()` keeps its `<br>` marker.** Line breaks survive normalisation, and a forged marker in the input can no longer fabricate a `<br>`.
- **`Read_HTML()` respects published blocks.** A block loaded from an `<!--@block N-->` marker is no longer overwritten by the file's residual text.
- **`clean_html()` drops code elements.** `<script>` and `<style>` elements (including an unterminated opener) are removed together with their contents; every other removed tag still keeps its inner text.
- **Quiet file writes.** `WriteFile()` suppresses the diagnostic of its locked in-place fallback and still reports the failure as a `RuntimeException`.
- **`Show_HTML()` accepts block `'0'`.** A numeric block of `0` is no longer treated as "missing".

### 🧪 Testability

- A **zero-dependency test runner** with 276 tests / 748 assertions across 35 groups, full `$GLOBALS` and superglobal isolation, `--filter` and `--verbose` flags, ANSI colour when attached to a TTY, and meaningful exit codes.

---

## ⚡ Quick Start

```php
<?php
require __DIR__ . '/html.php';

// 1. Publish template variables.
$GLOBALS['Title']  = 'Dashboard';
$GLOBALS['User']   = 'mikhail';
$GLOBALS['Amount'] = 1234.5678;

// 2. Render a template string.
$html = HTML('<h1>%{Title}</h1><p>Welcome, %{User}!</p><td>%{Amount.Round:2}</td>');

echo $html;
// <h1>Dashboard</h1><p>Welcome, mikhail!</p><td>1234.57</td>
```

Render a file with SSI includes:

```php
<?php
require __DIR__ . '/html.php';

$GLOBALS['HTML_WWW_PATH'] = __DIR__ . '/www';   // document root for "/ssi/head.htm"

echo HTML(file_get_contents(__DIR__ . '/www/page.htm'));
```

```html
<!-- www/page.htm -->
<!--#config timefmt="%Y-%m-%d" -->
<!--#set var="PageTitle" value="Reports" -->
<!--#include virtual="/ssi/head.htm" -->
<h1><!--#echo var="PageTitle" --></h1>
<p>Generated <!--#echo var="DATE_LOCAL" --></p>
```

Run the test suite:

```bash
php tests/run-tests.php              # 276 tests, 748 assertions
php tests/run-tests.php --verbose
php tests/run-tests.php --filter=security
```

---

## 📋 Table of Contents

1. [🚀 What's New in 2.0.1](#-whats-new-in-201)
2. [⚡ Quick Start](#-quick-start)
3. [✨ Key Features](#-key-features)
4. [📦 Requirements & Installation](#-requirements--installation)
5. [🗂️ Project Layout](#️-project-layout)
6. [🧠 Core Concepts](#-core-concepts)
   - [Template variables](#template-variables)
   - [SSI directives](#ssi-directives)
   - [Legacy blocks](#legacy-blocks)
   - [Numeric directives](#numeric-directives)
7. [📖 API Reference](#-api-reference)
8. [🔧 SSI Directive Reference](#-ssi-directive-reference)
9. [⚙️ Configuration Reference](#️-configuration-reference)
10. [🛡️ Security Model](#️-security-model)
11. [🔁 Backward Compatibility & Migration](#-backward-compatibility--migration)
12. [🧪 Testing](#-testing)
13. [🎬 Examples](#-examples)
14. [🔍 Troubleshooting](#-troubleshooting)
15. [🤝 Contributing](#-contributing)
16. [📄 License](#-license)
17. [👨‍💻 Author & Support](#-author--support)

---

## ✨ Key Features

### 🧩 **Single-file distribution**

- **No Composer, no autoloader, no build step.** One `require` and you are done.
- **No non-standard extensions.** Only `pcre`, `mbstring`, `ctype` and `json`-free core functionality — all part of a default PHP build.
- **FTP-friendly.** Ideal for shared hosting where `/var/www/lib/html.php` is shared by many virtual hosts.

### 🔌 **Apache SSI compatibility**

- `#config`, `#set`, `#include virtual`, `#echo` and the legacy `#echo` variant, with the same evaluation order as Apache (`#config` → `#set` → includes → `#echo` → `@var`).
- **Attribute order is irrelevant** — `<!--#set var="A" value="B" -->` and `<!--#set value="B" var="A" -->` are equivalent.
- **Built-in SSI variables**: `DATE_LOCAL`, `DATE_GMT`, `DOCUMENT_URI`, `DOCUMENT_NAME`, `DOCUMENT_ROOT`, `QUERY_STRING`, `SERVER_NAME`, `HTTP_USER_AGENT`, `LAST_MODIFIED`, `REMOTE_USER`.
- **`strftime` formats are translated** to `date()` tokens, including `%j` (three-digit day of year) and `%%`, `%n`, `%t`.

### 🏛️ **Legacy `html4.pl` parity**

- `LoadTemplate()` / `LoadVar()` block loading, `Read_HTML()`, `Show_HTML()`, `Divide()`, `lc_Tags()`, `normalize()`, `clean()`, `clean_html()`, `WriteFile()`, `TrimSpaces()`.
- `%{NAME}`, `#{NAME}` and `%[NAME]` substitution, `@{KEY}` translation lookups, and the `Round` / `Clean Round` / `Div` / `Round Div` numeric directives.
- `PARITY` alternation and `<!--@Split-->` support for alternating output.

### 🛡️ **Hardened by design**

- Escaping-by-default output for SSI echo, an optional `HTML_SSI_ECHO_ALLOW` read allow-list, and reserved-name protection for every superglobal and configuration key.
- Bounded recursion: SSI depth, SSI tag count, include byte size, template byte size, block depth and precision are all capped by configuration.
- Include containment with `..` rejection (`HTML_SSI_ALLOW_PARENT` opt-in) and case-insensitive path comparison.
- Atomic file writes with mode preservation and verified complete payload.

### 🧪 **Verifiable**

- 276 tests / 748 assertions covering the public API, SSI semantics, security boundaries, block loading, cleaning helpers, file I/O, numeric helpers and every fixed regression.
- Test isolation is enforced by the runner (snapshot/restore of `$GLOBALS`, superglobals and output buffering), so test order can never matter.
- Content is byte-compared against `gmdate()` for time-sensitive assertions, so the suite cannot flake on a second or midnight tick.

---

## 📦 Requirements & Installation

### System Requirements

- **PHP**: 8.3 or newer (the library refuses to load on older versions with a clear `RuntimeException`).
- **Extensions**: `pcre`, `ctype`, `mbstring` — all enabled by default in standard builds.
- **Filesystem**: any; `WriteFile()` is Windows- and POSIX-aware.
- **Web server**: any (Apache, Nginx + PHP-FPM, IIS, CLI). SSI is processed by the library, **not** by the web server, so no `mod_include` is required.
- **Composer**: not required and not supported by design.

### Installation Options

#### Option 1 — Copy the single file (recommended)

```bash
# Shared hosting: one library shared by many sites
scp html.php user@host:/var/www/lib/html.php
```

```php
<?php
require '/var/www/lib/html.php';
```

#### Option 2 — Vendor it into your project

```bash
mkdir -p lib && cp html.php lib/html.php
```

```php
<?php
require __DIR__ . '/lib/html.php';
```

#### Option 3 — Clone this project (library + tests + examples)

```bash
git clone <repository-url> html-php
cd html-php
php tests/run-tests.php        # verify the library on your PHP build
php examples/01-quick-start.php
```

### Verifying the install

```bash
php -l html.php                     # No syntax errors detected
php tests/run-tests.php             # SUMMARY: 276 passed, 0 failed
```

> **Windows note.** The project directory is named `html-php`, not `HTML.PHP`: NTFS is case-insensitive, so `C:\path\HTML.PHP` *is* `C:\path\html.php` and a folder with that exact name cannot coexist with the library file. Keep the folder name case-distinct from the file name on Windows and macOS.

---

## 🗂️ Project Layout

```text
html-php/
├── html.php                  # the entire library — this is the deliverable
├── README.md                 # this document
├── CHANGELOG.md              # Keep-a-Changelog history
├── LICENSE                   # MIT
├── .gitignore
├── examples/
│   ├── 01-quick-start.php          # render a template string
│   ├── 02-ssi-partials.php         # #include, #set, #echo, #config
│   ├── 03-legacy-blocks.php        # LoadTemplate / Read_HTML / Show_HTML
│   └── 04-helpers-tour.php         # normalize, clean_html, Divide, sanitizeXSS
└── tests/
    ├── run-tests.php               # entry point
    ├── bootstrap.php               # library bootstrap + fixtures
    ├── TestRunner.php              # zero-dependency runner and assertions
    └── cases/
        ├── 01_foundation.test.php      # 20 tests  — public API surface
        ├── 02_variables.test.php       # 31 tests  — variable substitution
        ├── 03_ssi.test.php             # 63 tests  — SSI directives and time formats
        ├── 04_ssi_security.test.php    # 36 tests  — security boundaries
        ├── 05_blocks.test.php          # 37 tests  — legacy blocks and Show_HTML
        ├── 06_cleaning.test.php        # 34 tests  — normalize / clean_html / helpers
        ├── 07_files.test.php           # 15 tests  — WriteFile atomicity
        ├── 08_numeric.test.php         # 25 tests  — Divide and rounding
        └── 09_regressions.test.php     # 15 tests  — one per fixed defect
```

---

## 🧠 Core Concepts

### Template variables

Variables live in `$GLOBALS` and are pulled into the markup with one of three braced forms:

```php
$GLOBALS['Title']   = 'Reports';
$GLOBALS['Company'] = 'Deynekin';

echo HTML('<h1>%{Title} — %{Company}</h1>');   // <h1>Reports — Deynekin</h1>
echo HTML('<h1>#{Title}</h1>');                 // <h1>Reports</h1>
echo HTML('<h1>%[Title]</h1>');                 // <h1>Reports</h1> (normalised on a later pass)
```

`@{KEY}` performs a translation lookup: it asks the resolver for `Translate.KEY`, and when that global is absent the key itself is emitted as the fallback text.

```php
$GLOBALS['Translate.Welcome'] = 'Willkommen';

echo HTML('@{Welcome}');   // Willkommen
echo HTML('@{Unknown}');   // Unknown
```

A bare `%NAME%` is **not** a substitution form — it is left in the output untouched. Use `%{NAME}`.

Resolution goes through the resolver supplied to `processHtml()`. The default resolver, `update_template()`, returns `$GLOBALS[$name]` when the global exists (and is not `null`), the suffix for a `Translate.*` name, and an empty string otherwise.

Names that are written into `$GLOBALS` from a template (`#set`, `<!--@var-->`, `<!--@Block NAME-->`) and names read by SSI (`#echo`, `##echo`) must match:

```text
^[A-Za-z_][A-Za-z0-9_.-]{0,127}$
```

Names failing this pattern are silently ignored — they are never written to `$GLOBALS` and never echoed. Note that `%{NAME}` substitution is resolved by the resolver itself and is **not** subject to that gate, so only reference globals your templates are meant to expose.

### SSI directives

```html
<!--#config timefmt="%Y-%m-%d %H:%M" -->
<!--#set var="PageTitle" value="Quarterly Report" -->
<!--#set value="Built by $Company" var="FooterNote" -->
<!--#include virtual="/ssi/header.htm" -->
<h1><!--#echo var="PageTitle" --></h1>
<footer><!--#echo var="FooterNote" --></footer>
<span><!--#echo var="DATE_LOCAL" --></span>
```

Evaluation order inside a single file is fixed and intentional:

| Step | Directive | Effect |
|------|-----------|--------|
| 1 | `#config` | Stores the time format used by later `#echo` of date variables |
| 2 | `#set` | Publishes a variable into `$GLOBALS` and is removed from the output |
| 3 | `#include` | Inserts a partial (recursively processed) |
| 4 | `#echo` / `##echo` | Emits a built-in SSI variable or a template variable |
| 5 | `<!--@var="NAME"value-->` | Stores `value` and emits it in place |

Because `#set` runs before includes and echoes, variables declared at the top of a page are visible to every partial below it.

### Legacy blocks

```html
<!--@Block Header-->
<header>%Title%</header>
<!--@End Block Header-->

<!--@block 0-->
First alternating variant
<!--@end-->

<!--@block 1-->
Second alternating variant
<!--@end-->
```

```php
$body = LoadTemplate(__DIR__ . '/page.htm');   // publishes $GLOBALS['Header']
$GLOBALS['html'] = [];
Read_HTML(__DIR__ . '/page.htm', 0);           // publishes numeric blocks 0 and 1

echo Show_HTML(['html' => '0', 'output' => 'var', 'SSI' => 'off']);
```

### Numeric directives

Numeric directives are written inside the same braces, as `%{KEY.<directive>}`:

| Directive | Syntax | Example | Result |
|---|---|---|---|
| Round | `%{KEY.Round:N}` | `%{Amount.Round:2}` | `1234.57` |
| Clean Round | `%{KEY.Clean Round:N}` | `%{Amount.Clean Round:2}` | `1234.57` (no `.00` for whole numbers) |
| Div | `%{KEY.Div DIVISOR}` | `%{Amount.Div 4}` | full-precision quotient, `0` for a zero divisor |
| Round Div | `%{KEY.Round Div DIVISOR:N}` | `%{Amount.Round Div 4:2}` | `308.64` |
| Clean Round Div | `%{KEY.Clean Round Div DIVISOR:N}` | `%{Amount.Clean Round Div 4:2}` | `308.64` |

```php
$GLOBALS['Amount'] = 1234.5678;

echo HTML('%{Amount.Round:2}');             // 1234.57
echo HTML('%{Amount.Round Div 4:2}');       // 308.64
echo HTML('%{Amount.Div 0}');               // 0
echo Divide(10, 4);                         // 2.5
echo Divide(10, 0);                         // 0  — a zero divisor never raises
```

The `Round` family rounds to `N` decimals; `Clean Round` drops a trailing `.00` when the value is an exact integer. `Div` and its rounding variants take the divisor after a space (no colon), while the precision follows a colon.

> **Float precision.** A bare `Div` returns PHP's raw float representation — `%{Amount.Div 4}` on `1234.5678` yields `308.64195000000001`, not `308.64195`. Use `Round Div` / `Clean Round Div` whenever the output is shown to a human.

---

## 📖 API Reference

### Rendering pipeline

| Function | Signature | Description |
|---|---|---|
| `HTML` | `HTML(string $html): string` | Main entry point. Runs `processSSI()` then `processHtml()` and recurses up to `HTML_MAX_TEMPLATE_PASSES` times. |
| `processSSI` | `processSSI(string $html, ?string $currentFile = null): string` | Processes SSI directives only. `$currentFile` enables relative includes. |
| `processHtml` | `processHtml(string $html, callable $updateTemplate): string` | Variable substitution with a caller-supplied resolver. |
| `update_template` | `update_template(string $varName): string` | Default resolver used by `HTML()`. Returns `$GLOBALS[$varName]` when the global exists and is not `null`; for a `Translate.*` name it returns the remainder as literal fallback text; otherwise an empty string. |

### Template loading

| Function | Signature | Description |
|---|---|---|
| `LoadTemplate` | `LoadTemplate(string $file): string` | Loads a template, publishes `<!--@Block NAME-->` sections into `$GLOBALS`, returns the remainder. Returns `'Template Not Found'` / `'Bad Template File'` instead of throwing. |
| `LoadVar` | `LoadVar($handle, string $startPattern): string` | Legacy helper that reads one block from an open stream. |
| `Read_HTML` | `Read_HTML(string $file, int $block = 1): string` | Parses `<!--@block N-->` numeric blocks into `$GLOBALS['html']` and returns the requested block. |
| `Show_HTML` | `Show_HTML(mixed ...$arguments): ?string` | Renders an html4.pl-style block. Accepts one associative array or legacy key/value pairs. |

`Show_HTML()` options:

| Key | Values | Meaning |
|---|---|---|
| `html` | block number (`0`, `1`, …) | Which block from `$GLOBALS['html']` to render. `0` is valid. |
| `output` | *(default)*, `var*`, `file`, `comb` | Default echoes; `var*` returns the string; `file`/`comb` write to `handle`. |
| `handle` | stream resource | Destination for `output => file`/`comb`. |
| `SSI` | `off` | Skips SSI processing for the block. |
| `exec_symbol` | string | Overrides the `%…%` substitution symbol. |
| `PARITY` | truthy | Alternates between the two halves split by `<!--@Split-->`. |
| `www_path` | path | Document root used for SSI includes. |

### Text and markup helpers

| Function | Signature | Description |
|---|---|---|
| `normalize` | `normalize(string $buffer, mixed $br = null, mixed $cleanEdges = false): string` | Flattens markup to text. `<br>` is preserved unless `$br` is truthy; `$cleanEdges` trims leading/trailing punctuation. |
| `clean_html` | `clean_html(string $html): string` | Keeps only allow-listed tags (attributes stripped, comments removed), drops `<script>`/`<style>` elements entirely, normalises entities. |
| `clean` | `clean(string $closing, string $tag): string` | Legacy helper that reads the `$GLOBALS['TAGS']` allow-list. |
| `lc_Tags` | `lc_Tags(string $input): string` | Lower-cases tag names. |
| `TrimSpaces` | `TrimSpaces(mixed $string): string` | Removes whitespace and non-word characters, preserving Unicode letters. |
| `Divide` | `Divide(mixed $a, mixed $b): float\|int` | Safe division; a zero divisor yields `0`. |

### Input and output

| Function | Signature | Description |
|---|---|---|
| `sanitizeXSS` | `sanitizeXSS(array $input): array` | Recursively HTML-escapes an array (`ENT_QUOTES \| ENT_HTML5 \| ENT_SUBSTITUTE`, double-encode off). |
| `HTML_Sanitized_Input` | `HTML_Sanitized_Input(): array` | Returns `['get' => …, 'post' => …, 'cookie' => …, 'request' => …]` sanitised, **without touching** the superglobals. |
| `WriteFile` | `WriteFile(string $file, mixed $data): bool` | Atomic write (temp file + rename, locked in-place fallback). Throws `InvalidArgumentException` / `RuntimeException` on invalid paths or incomplete writes. |

---

## 🔧 SSI Directive Reference

| Directive | Syntax | Notes |
|---|---|---|
| Config | `<!--#config timefmt="FMT" -->` | `strftime`-style format stored in `HTML_SSI_TIMEFMT`. |
| Set | `<!--#set var="NAME" value="VALUE" -->` | Any attribute order; `$VAR` / `${VAR}` inside `VALUE` expands once; `\$` escapes a literal dollar. Reserved and built-in names are never writable. |
| Include | `<!--#include virtual="/ssi/head.htm" -->` | Absolute paths resolve from the document root, relative paths from the including file, with a document-root fallback. Blocked outside the root unless `HTML_SSI_ALLOW_PARENT` is enabled. |
| Echo | `<!--#echo var="NAME" -->` | Built-in SSI variables win over template variables. Escaped unless `HTML_SSI_ECHO_ESCAPE` is disabled. |
| Legacy echo | `<!--##echo var="NAME" -->` | Reads template variables, honours `HTML_SSI_ECHO_ALLOW`. |
| Inline var | `<!--@var="NAME"VALUE-->` | Stores the trimmed value and emits the raw content in place. |
| Block | `<!--@Block NAME--> … <!--@End Block NAME-->` | Named block consumed by `LoadTemplate()` / `LoadVar()`. |
| Numeric block | `<!--@block N--> … <!--@end-->` | Numeric block consumed by `Read_HTML()`. |
| Split | `<!--@Split-->` | Splits a block into two alternating variants for `PARITY` mode. |

### Time format tokens

`%Y %y %C %m %d %e %H %k %I %l %M %S %p %P %B %b %h %A %a %Z %z %u %w %G %V %U %W %D %F %T %R %r %c %x %X %s %n %t %%` are translated to `date()` equivalents. Anything else is passed through as a literal, and literal letters in the format string are escaped so they can never be mistaken for `date()` tokens.

---

## ⚙️ Configuration Reference

All options are plain globals with `??=` defaults — set them **before** including the library, or simply assign them afterwards.

| Global | Default | Range (clamped) | Purpose |
|---|---|---|---|
| `HTML_MAX_SSI_DEPTH` | `16` | 1–128 | Maximum include recursion depth. |
| `HTML_MAX_SSI_TAGS` | `256` | 1–10000 | Maximum number of SSI tags processed per render. |
| `HTML_MAX_TEMPLATE_PASSES` | `2` | 1–16 | Recursion limit of the `HTML()` pipeline. |
| `HTML_MAX_BLOCK_DEPTH` | `64` | 1–1024 | Maximum nesting depth of `<!--@Block-->` loading. |
| `HTML_MAX_PRECISION` | `100` | 0–… | Upper bound for numeric-directive precision. |
| `HTML_MAX_TEMPLATE_BYTES` | `16 MiB` | up to 512 MiB | Largest template `LoadTemplate()` / `Read_HTML()` will read. |
| `HTML_MAX_INCLUDE_BYTES` | `8 MiB` | up to 256 MiB | Largest file `#include` will accept. |
| `HTML_SSI_ALLOW_PARENT` | `false` | bool | Allow `..` in include paths (off by default). |
| `HTML_SSI_ECHO_ESCAPE` | **`true`** | bool | HTML-escape `#echo` output. |
| `HTML_SSI_ECHO_ALLOW` | `[]` | list/array | When non-empty, restricts which names `#echo` may read. |
| `HTML_SSI_TIMEFMT` | `%A, %d-%b-%Y %H:%M:%S %Z` | string | Default SSI date format. |
| `HTML_AUTO_SANITIZE_INPUT` | **`false`** | bool | Legacy opt-in that rewrites the superglobals on include. |
| `HTML_ALLOWED_TAGS` | `br, p, h1–h4, strong, b, ul, ol, li, hr` | map | Allow-list used by `clean_html()`. |
| `HTML_WWW_PATH` | `$_SERVER['DOCUMENT_ROOT']` | path | Document root for absolute SSI includes. |

---

## 🛡️ Security Model

`html.php` is a **template renderer**, not a sandbox — but it assumes templates, variable names and include paths can be attacker-influenced, and refuses to be the weak link.

| Threat | Mitigation |
|---|---|
| Output injection through SSI echo | `HTML_SSI_ECHO_ESCAPE` is `true` by default; `#echo` output is HTML-escaped. |
| Overwriting `$_SESSION`, `$_SERVER`, config globals | `HTML_RESERVED_GLOBALS` is enforced for every directive-driven read and write. |
| Overwriting built-in SSI variables | `HTML_SSI_BUILTIN_VARS` are never writable. |
| Hostile variable names (null bytes, `../`, spaces, leading digits) | `_htmlValidVariableName()` gate on every `$GLOBALS` access. |
| Reading arbitrary globals through `#echo` | Optional `HTML_SSI_ECHO_ALLOW` allow-list. |
| Path traversal through `#include` | `..` rejected unless `HTML_SSI_ALLOW_PARENT`; resolved path must stay inside the document root; comparison is case-insensitive on Windows/macOS. |
| Include/recursion denial of service | Depth, tag-count, template-size and include-size caps; cycle detection via an include stack; self-includes terminate. |
| Silent partial writes | `WriteFile()` uses temp file + rename with a locked fallback and verifies the full payload length. |
| Superglobal corruption | `HTML_AUTO_SANITIZE_INPUT` is `false`; sanitised data is returned by value from `HTML_Sanitized_Input()`. |

> **Deployment guidance.** Keep `HTML_SSI_ALLOW_PARENT` off, keep `HTML_SSI_ECHO_ESCAPE` on, and prefer `HTML_Sanitized_Input()` over rewriting the superglobals. Escape at the output boundary rather than mutating input.

---

## 🔁 Backward Compatibility & Migration

The public API is unchanged from 2.0.0 — same function names, same signatures, same return types. Two **defaults** changed deliberately and are the only source of visible behaviour differences:

| Behaviour | Before | Now | If you need the old behaviour |
|---|---|---|---|
| `#echo` output | emitted raw | HTML-escaped | Set `$GLOBALS['HTML_SSI_ECHO_ESCAPE'] = false;` before the include. |
| Superglobals on include | rewritten by `sanitizeXSS()` | untouched | Use `HTML_Sanitized_Input()`, or set `$GLOBALS['HTML_AUTO_SANITIZE_INPUT'] = true;` before the include. |

Two further narrow edge cases are worth an audit before upgrading an existing site:

- **Variable and block names** must now match `^[A-Za-z_][A-Za-z0-9_.-]{0,127}$`. Names with spaces, leading digits or non-ASCII letters are ignored instead of published to `$GLOBALS`.
- **`%h`** in an SSI time format now yields the abbreviated month (`Sep`) instead of the full month name (`September`), matching `strftime`.

Everything else — SSI ordering, block syntax, `%VARIABLE%` substitution, numeric directives, `html4.pl` helpers — behaves as before.

Enabling `declare(strict_types=1)` inside the library only affects the library's own internal calls; external legacy callers keep PHP's normal coercive argument handling.

---

## 🧪 Testing

The suite is hand-rolled and dependency-free, so it runs anywhere PHP runs.

```bash
php tests/run-tests.php                    # full run
php tests/run-tests.php --verbose          # per-test timings and file:line
php tests/run-tests.php --filter=security  # substring match on group/test names
php tests/run-tests.php --filter=include --verbose
```

```text
========================================================================
SUMMARY: 276 passed, 0 failed (748 assertion(s))
```

Exit codes: `0` all green · `1` at least one failure · `2` harness or bootstrap error.

### What is covered

| Area | Highlights |
|---|---|
| **Foundation** | Public API surface, version guard, `strict_types`, no BOM, function contracts. |
| **Variables** | `%NAME%`, braces, indirect forms, closure and string resolvers, translation fallback. |
| **SSI** | All directives, both attribute orders, quote styles, `$VAR` expansion, nested includes, evaluation order, every time token. |
| **SSI security** | Reserved globals, built-in names, invalid names, allow-list, escaping defaults, traversal, cycles, depth/size caps. |
| **Blocks** | Named and numeric blocks, nesting, block `0`, marker-less templates, `Show_HTML` options, `PARITY`/`@Split`. |
| **Cleaning** | Allow-list, attribute stripping, comment removal, script/style dropping, entity normalisation, non-UTF-8 safety. |
| **Files** | Atomic writes, byte-exact payloads, failure modes, no leaked temp files, zero un-suppressed diagnostics. |
| **Numbers** | `Divide()` semantics, precision clamping, numeric parsing. |
| **Regressions** | One test per defect fixed in the hardening passes, each documented in-line. |

### Test isolation guarantees

- The runner snapshots and restores `$GLOBALS` plus `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_REQUEST`, `$_ENV`, `$_SESSION` around every test.
- Output buffering is reset per test, so a stray `echo` can never corrupt the report.
- A meta-test boots a **separate PHP process** to prove the library behaves correctly from a pristine interpreter.
- The suite pins `date_default_timezone_set('UTC')` and compares time-sensitive output against `gmdate()` sampled before and after the render.
- Fixtures live in `tests/tmp/`, are recreated per run, and are removed on every exit path.

Add your own case by dropping a `tests/cases/NN_name.test.php` file that calls `group()` and `test()`; the runner discovers `*.test.php` automatically.

---

## 🎬 Examples

```bash
php examples/01-quick-start.php      # variables + HTML()
php examples/02-ssi-partials.php     # document root, #include, #set, #echo, #config
php examples/03-legacy-blocks.php    # LoadTemplate / Read_HTML / Show_HTML
php examples/04-helpers-tour.php     # normalize / clean_html / Divide / sanitizeXSS
```

All four exit `0`, print only their own output, write their scratch files to the system temp directory and clean up after themselves.

---

## 🔍 Troubleshooting

### `RuntimeException: This template library requires PHP 8.3 or newer.`

The library guards on `PHP_VERSION_ID`. Check the PHP binary your web server actually uses — CLI and FPM often differ:

```bash
php -v
php -r 'echo PHP_VERSION_ID, PHP_EOL;'
```

### Included partials render as an empty string

An include is skipped when it is missing, unreadable, larger than `HTML_MAX_INCLUDE_BYTES`, or outside the document root.

```php
$GLOBALS['HTML_WWW_PATH'] = __DIR__ . '/www';   // set the document root explicitly
var_dump(realpath(__DIR__ . '/www/ssi/head.htm'));
```

Remember that a relative `virtual="ssi/head.htm"` resolves against the directory of the file that contains the directive, while `virtual="/ssi/head.htm"` resolves against the document root.

### `%NAME%` is rendered literally instead of being substituted

A bare `%NAME%` is not a substitution form. Use the braced syntax:

```php
$GLOBALS['Title'] = 'Reports';
echo HTML('%Title%');    // %Title%   — unchanged
echo HTML('%{Title}');   // Reports
```

The supported forms are `%{NAME}`, `#{NAME}` and `%[NAME]`; note that `%[NAME]` is normalised into `%{NAME}` on a later pass, so it needs the multi-pass `HTML()` pipeline rather than a single `processHtml()` call.

### A numeric directive renders as an empty string

Check the exact shape — the divisor is separated by a space, while the precision follows a colon:

| Wrong | Right |
|---|---|
| `%{AMT.Div:4}` | `%{AMT.Div 4}` |
| `%{AMT.Round 2}` | `%{AMT.Round:2}` |

### A variable or block name silently does nothing

Names must match `^[A-Za-z_][A-Za-z0-9_.-]{0,127}$` and must not be a reserved name. Verify in isolation:

```bash
php -r 'require "html.php"; var_dump(_htmlValidVariableName("My Name"));'   # bool(false)
```

### `#echo` output suddenly shows `&lt;b&gt;` instead of `<b>`

That is escaping-by-default. If the value is trusted markup:

```php
$GLOBALS['HTML_SSI_ECHO_ESCAPE'] = false;   // before requiring html.php
```

Otherwise render the value through `HTML()`/`Show_HTML()` where the markup is intentional.

### My code no longer sees sanitised `$_GET` / `$_POST`

Automatic mutation is off by default. Replace the implicit behaviour explicitly:

```php
$input = HTML_Sanitized_Input();
$page  = $input['get']['page'] ?? 1;
```

### `WriteFile()` throws `Output directory … is not writable`

The target directory must exist and be writable, and an existing target file must be writable too. `WriteFile()` never creates directories — create them first:

```php
if (!is_dir($dir)) { mkdir($dir, 0775, true); }
WriteFile($dir . '/out.htm', $rendered);
```

### An SSI date renders as `Updated at 14:30` but `%j` shows digits

`%j` is a zero-padded day-of-year and is expanded after `date()` formatting; make sure the format string is passed through `#config timefmt` rather than embedded in `date()` directly.

---

## 🤝 Contributing

### Development setup

```bash
git clone <repository-url> html-php
cd html-php
php tests/run-tests.php            # green before you start
php examples/02-ssi-partials.php   # sanity-check the runtime
```

### Guidelines

1. **Fork** the repository and create a feature branch (`git checkout -b feature/ssi-attribute-x`).
2. **Keep the single-file contract.** `html.php` must remain a drop-in, dependency-free file. Do not split it into classes/namespaces in this distribution.
3. **Preserve the public API.** New behaviour goes into new functions; existing signatures stay frozen.
4. **Add a test for every fix.** Bug fixes belong in `tests/cases/09_regressions.test.php` with a comment naming the defect.
5. **Document defaults.** If you introduce a configuration global, add it to the Configuration Reference table and to `HTML_RESERVED_GLOBALS`.
6. **Keep the suite green** on a clean interpreter and from a foreign working directory.
7. **Update `CHANGELOG.md`** under an `Unreleased` heading.

### Priority areas

- **SSI parity**: optional `exec`/`printenv`/`flastmod`/`fsize` directives to complete Apache parity.
- **Syntax documentation**: a machine-readable grammar for the `%{…}` / `#{…}` / `@{…}` substitution family.
- **Streaming includes**: a callback hook so applications can resolve includes from a database or object store.
- **Additional test coverage**: `HTML_SSI_ECHO_ALLOW` effects on block publishing, POSIX-specific file-permission behaviour.
- **Localisation**: message catalogues for the library's exception strings.
- **Benchmarks**: a repeatable micro-benchmark harness for large templates.
- **Static analysis**: a PHPStan/Psalm level that the single-file layout can satisfy.

---

## 📄 License

This project is licensed under the **MIT License** — see the [LICENSE](LICENSE) file for details.

```text
MIT License

Copyright (c) 2026 Mikhail Deynekin

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

---

## 👨‍💻 Author & Support

**Mikhail Deynekin**

- 🌐 Website: [Deynekin.com](https://deynekin.com)
- 📧 Email: [Mikhail@Deynekin.com](mailto:Mikhail@Deynekin.com)
- 🐙 GitHub: [@paulmann](https://github.com/paulmann)

### Support channels

- 📖 **Documentation** — this README covers the API, SSI reference, configuration and security model.
- 🐛 **Bug reports** — include the PHP version, the failing template snippet and the output of `php tests/run-tests.php`.
- 💡 **Feature requests** — describe the template construct you need and the expected rendered output.
- 🚨 **Security issues** — report privately by email with a `[SECURITY]` prefix; please do not open a public issue.

### Related projects

- [Windows-Cleaner-and-Optimizer](https://github.com/paulmann/Windows-Cleaner-and-Optimizer) — Windows cleanup automation with system backup integration.
- [Windows-11-25H2-Update-Script](https://github.com/paulmann/Windows-11-25H2-Update-Script) — Windows 11 update automation with safety checks.

---

### ⭐ Star this project if it saved you a Composer dependency!

**HTML.PHP 2.0.1** — Apache-SSI-compatible templating in a single hardened PHP file 🧩⚡🛡️

---

### 🔒 Safety disclaimer

**Important**: always run the test suite (`php tests/run-tests.php`) on your target PHP build before deploying, and review the changed defaults in [Backward Compatibility & Migration](#-backward-compatibility--migration) when upgrading an existing site. Template rendering is not a security sandbox — treat template sources as trusted input, and keep `HTML_SSI_ALLOW_PARENT` disabled unless you have verified every include path. The author is not responsible for data loss or rendering regressions caused by templates that rely on the pre-2.0.1 defaults.
