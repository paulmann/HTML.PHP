# Security Model

HTML.PHP is designed to run **untrusted templates against a trusted
codebase**: template authors may write markup, SSI directives and variable
references, but must never be able to read secrets, escape the document
root, execute arbitrary code or exhaust resources. This document lists every
guard rail; the `04_ssi_security`, `11`, `12` and `18` test groups assert
them.

## 1. Output escaping

* `#echo` output is HTML-escaped by default (`HTML_SSI_ECHO_ESCAPE=true`);
  `encoding="none"` is an explicit per-directive opt-out, `encoding="url"`
  percent-encodes.
* `%{VAR}` substitution is raw by default (legacy compatibility) — turn on
  `HTML_ESCAPE_VARS`, pass `['escape' => true]` to `html_render()`, or use
  `|escape`/`|attr`/`|url` filters. Shortcodes registered through the WP
  bridge render with escaping on.
* `HTML_ESCAPER` lets hosts (e.g. WordPress `esc_html`) own the text
  escaping policy.
* `sanitizeXSS()`/`HTML_Sanitized_Input()` double-encode by design and never
  mutate superglobals; `HTML_AUTO_SANITIZE_INPUT` (mutating) stays opt-in.

## 2. Variable-name gate

Every template-driven read or write of `$GLOBALS` (`#set`, `#echo`,
`##echo`, `<!--@var-->`, `<!--@Block-->`) passes
`Str::isValidVariableName()` (`^[A-Za-z_][A-Za-z0-9_.-]{0,127}$`, no null
bytes) **and** `Config::isReserved()` (superglobals, every `HTML_*`
configuration key and engine internals) **and** the built-in-SSI-name
check. `HTML_SSI_ECHO_ALLOW` optionally narrows readable names further.
`%{NAME}` resolution is scope-first; the legacy `$GLOBALS` resolver is
read-only and cannot be used to write.

## 3. Filesystem containment

* Includes, `#fsize`/`#flastmod` targets and `#if` file tests resolve
  through one path resolver: normalised, realpath'd, required to stay inside
  the document root (compared case-insensitively on Windows/macOS), size-
  capped (`HTML_MAX_INCLUDE_BYTES`), `..` rejected unless
  `HTML_SSI_ALLOW_PARENT`; URL schemes rejected.
* `#include file=` never accepts absolute paths.
* `WriteFile()`/render cache writes are atomic (temp file in the destination
  directory → verified length → chmod → rename with locked in-place
  fallback).

## 4. Code execution

* `#exec` is **deny by default** (`HTML_SSI_EXEC=false`).
* `cmd=` additionally requires an allow-list (`HTML_SSI_EXEC_CMD_ALLOW`,
  default empty = deny even when enabled). The command never touches a
  shell: it is tokenised (quotes honoured) and spawned via `proc_open()`
  with an argv array, so `>`, `|`, `;`, `` ` ``, `$()` are literal text. The
  child environment is minimal.
* `cgi=` must resolve inside the document root and match
  `HTML_SSI_EXEC_CGI_EXT`; default mode spawns a fresh `PHP_BINARY`
  process. In-process `include` mode is opt-in and documented as
  state-sharing.
* All execs are bounded by `HTML_SSI_EXEC_TIMEOUT` (child killed) and
  `HTML_SSI_EXEC_MAX_BYTES` (output truncated, child killed).
* There is no `eval()`, `assert()`, `create_function()`, `unserialize()` of
  template data, dynamic `include` of template-derived strings or PCRE `/e`
  anywhere in the library. Plural-form and `#if` expressions are parsed by
  purpose-built parsers.

## 5. Resource exhaustion (DoS)

Bounded by configuration, all enforced per render: SSI include depth (16),
include count (256), template size (16 MiB), include size (8 MiB), loop rows
(10 000), loop nesting (32), conditional nesting (64), expression length
(4 KiB), numeric precision (100), diagnostics list (100 entries), exec
timeout/output, `repeat` filter (1000). PCRE failures throw
`TemplateException` instead of silently corrupting output; Unicode patterns
fall back to byte-safe patterns on malformed UTF-8.

## 6. Injection surfaces considered

* **SSI expression parser** — no shell, no eval; regexes wrapped with a
  safe delimiter and error-guarded.
* **PO/MO parsers** — bounds-checked, reject hostile/truncated input with a
  diagnostic; charset conversion failures degrade to raw bytes, never
  warnings.
* **Cache** — keys hash template+vars+locale+config; entries are JSON
  envelopes with expiry; corrupted entries are deleted, not served.
* **WP bridge** — shortcode attribute names pass the variable-name gate;
  REST routes refuse to register without a permission callback; plugin POST
  actions are capability- and nonce-checked; notices are escaped.

## 7. Reporting

Security issues: please report privately to `Mikhail@Deynekin.com`
(coordinated disclosure, credit on request). Non-fatal security-relevant
events (denied execs, containment violations, cycles) surface in
`html_errors()` for monitoring; hook `htmlphp_error` to ship them to your
logger/SIEM.
