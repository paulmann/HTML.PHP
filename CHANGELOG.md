# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Section headings carry both the semantic version declared in `html.php`
(`Library version: 2.0.1`) and the file version of that release.

## [2.0.1] - 2026-09-20.2

### Fixed

* `normalize()` no longer destroys its own `<br>` marker. The `\x01` sentinel
  byte is stripped from the input (instead of only the full marker literal) so
  the marker can only come from this function, and `\x01` is excluded from the
  control-character strip so the restore step finds it again.
  `normalize('<p>a<br>b</p>')` is now `a<br>b` instead of `aHTML_BRb`.
* `Read_HTML()` no longer overwrites a block that a `<!--@block N-->` marker
  already published with the residual text. The residual is only used as a
  fallback when the requested block is undefined; a marker-less template still
  returns its whole trimmed content (legacy behaviour).
* `WriteFile()` suppresses the warning of its locked in-place fallback
  (`@file_put_contents`), matching the surrounding `@chmod`/`@rename`/`@unlink`
  and the `RuntimeException` that already reports the failure.
* `clean_html()` drops `<script>…</script>` and `<style>…</style>` elements
  together with their contents, including an unterminated opener. Those tags
  are not allow-listed, so the leftover code was never usable and surfaced as
  visible text. Every other removed tag still keeps its inner text.

## [2.0.1] - 2026-09-20.1

### Added

* `declare(strict_types=1)` at the top of the library file.
* `HTML_Sanitized_Input()`, returning sanitized copies of `$_GET`, `$_POST`,
  `$_COOKIE` and `$_REQUEST` without mutating them.
* Generic SSI attribute parsing (`_htmlParseSsiAttributes()`), so attribute
  order no longer matters: `<!--#set value="VAL" var="NAME" -->` and a
  reordered `#config` both work.
* `HTML_MAX_INCLUDE_BYTES` is now enforced for every included partial, and the
  `%h` time token maps to the abbreviated month name.

### Changed

* Safer defaults: `HTML_SSI_ECHO_ESCAPE` is now `true` and
  `HTML_AUTO_SANITIZE_INPUT` is now `false`, so request superglobals are only
  mutated on explicit opt-in.
* `_htmlSsiStrftimeToDate()` escapes literal characters, so plain text in a
  time format (`'Updated at %H:%M'`) is no longer reinterpreted as `date()`
  tokens.
* Include containment normalizes separators and case for the case-insensitive
  Windows/macOS filesystems.
* `WriteFile()` makes the umask handling explicit and keeps the atomic
  write-then-rename with a locked in-place fallback.
* `Show_HTML()` accepts `'0'` as a block selector.

### Security

* A stricter `$GLOBALS` name gate: `_htmlValidVariableName()` rejects empty
  names, null bytes and path-like or syntactically broken names before any
  template-driven read or write reaches `$GLOBALS`.
* `clean_html()` no longer publishes a temporary `$GLOBALS['TAGS']`; `clean()`
  keeps reading that global for legacy callers.

## [2.0.0] - 2026-09-16.1

### Added

* Apache SSI parity for the directives used by the conbat.ru site.
  * `<!--#set var="NAME" value="VAL" -->` writes into `$GLOBALS` and is removed
    from the output. `$VAR`/`${VAR}` references inside `VAL` are expanded once
    against the built-in SSI variables and `$GLOBALS`; undefined names are left
    untouched and `\$` escapes a literal dollar sign.
  * `<!--#echo var="NAME" -->` (single or double quotes) outputs the built-in
    SSI variables (`DATE_LOCAL`, `DATE_GMT`, `DOCUMENT_URI`, `DOCUMENT_NAME`,
    `DOCUMENT_ROOT`, `QUERY_STRING`, `SERVER_NAME`, `HTTP_USER_AGENT`,
    `LAST_MODIFIED`) or template variables, honouring `HTML_RESERVED_GLOBALS`
    and the optional `HTML_SSI_ECHO_ALLOW` allow-list. The legacy
    `<!--##echo var="NAME" -->` directive is unchanged and now accepts both
    quote styles.
  * `<!--#config timefmt="FMT" -->` stores the format in `HTML_SSI_TIMEFMT`.
    `strftime()` tokens are converted to `date()` tokens (`%Y %m %d %H %M %S %j
    %B %b %A %a %p %I %Z %z %D %F %T %R %r %c %n %t %%` and friends).
  * Relative includes (`"ssi/x.htm"`, `"./ssi/x.htm"`) resolve against the
    directory of the file containing the directive, with a fallback to the
    document root; absolute paths (`"/ssi/x.htm"`) resolve from the document
    root. The current file travels through the new optional second argument of
    `processSSI()`, so the legacy `processSSI($html)` call keeps working.
  * Directive evaluation order inside one file is `#config`, `#set`, includes,
    `#echo`/`##echo`, `<!--@var-->`, so variables set at the top of a template
    are visible to the partials included below them.

## [1.x] - 2026-09-04.2

### Fixed

* `normalize()` and `clean_html()` no longer lose content when the input is not
  valid UTF-8: Unicode patterns fall back to byte-safe patterns.
* `_htmlCleanRound()` handles values outside the platform integer range and no
  longer produces wrapped or scientific output.
* `WriteFile()` applies umask-based permissions to newly created files instead
  of inheriting the `0600` mode of the temporary file, sanitizes the temporary
  prefix and verifies that the whole payload was written.
* `Show_HTML()` validates `$GLOBALS['html']`, accepts `'0'` as an exec symbol
  and falls back to direct output when the requested handle is unusable.
* `HTML_MAX_PRECISION` can be configured to `0` again.

### Security

* SSI directives can no longer overwrite superglobals or library
  configuration: reserved names are rejected, and an optional allow-list plus
  output escaping are available for `<!--##echo-->`.

## [1.x] - 2026-09-03.1

### Added

* Locale-aware `Round`, `Clean Round`, `Div`, `Round Div` and
  `Clean Round Div` template directives.
* Bounded SSI recursion, include limits and traversal protection.
* Atomic file output, quote-aware normalization and tag allow-listing.
* Brace-safe variable parsing, indirect variables and translations.

### Fixed

* Removed the nested `LoadVar()` declaration that fataled on a second call.

> The `1.x` entries predate the semantic version declared in the library header
> and are listed under their file version identifiers.
