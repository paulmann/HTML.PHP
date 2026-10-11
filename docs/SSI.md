# SSI Reference — Apache mod_include compatible

HTML.PHP evaluates Server Side Includes itself — no `mod_include` required.
Directives are HTML comments, so unprocessed files degrade gracefully in
browsers and search snippets.

Processing entry points: `processSSI($html, $currentFile = null)`,
`html_ssi($html, $file = null)`, or automatically inside `HTML()` /
`html_render()` / `Show_HTML()`.

## Evaluation model

Since 3.0 the document is scanned **sequentially** and directives execute in
**document order** — the semantics of Apache mod_include. (2.0.1 evaluated
all `#config`/`#set` first, then includes, then echoes; see
[MIGRATION.md](MIGRATION.md).)

Guards applied to every render:

* include depth ≤ `HTML_MAX_SSI_DEPTH` (default 16);
* include count ≤ `HTML_MAX_SSI_TAGS` (default 256);
* include size ≤ `HTML_MAX_INCLUDE_BYTES` (default 8 MiB);
* cycle detection via a realpath stack (a file never includes itself
  recursively);
* path containment inside the document root (`HTML_WWW_PATH` →
  `$_SERVER['DOCUMENT_ROOT']` → CWD), normalised and case-insensitive-aware
  on Windows/macOS; `..` segments rejected unless `HTML_SSI_ALLOW_PARENT`;
* every refusal/failure is recorded in `html_errors()` and (optionally)
  replaced by the `#config errmsg` text.

## Directives

### `#config`

```html
<!--#config timefmt="%Y-%m-%d %H:%M:%S" -->
<!--#config sizefmt="bytes" -->          <!-- or "abbrev" (default) -->
<!--#config errmsg="[content unavailable]" -->
```

`timefmt` accepts strftime tokens (converted internally; `%j` day-of-year
supported). `sizefmt` and `errmsg` affect later directives in the same
render. `timefmt`/`sizefmt` also update `$GLOBALS['HTML_SSI_TIMEFMT']` /
`HTML_SSI_SIZEFMT` for cross-render persistence (2.0.1 behaviour).

### `#set`

```html
<!--#set var="PageTitle" value="Quarterly Report" -->
<!--#set var="Note" value="Built by $Company on ${DATE_LOCAL}" -->
```

`$VAR` / `${VAR}` references inside `value` expand once against built-ins
and `$GLOBALS` (unknown names stay verbatim, `\$` escapes). Names are gated:
syntactically valid (`^[A-Za-z_][A-Za-z0-9_.-]{0,127}$`), not reserved, not
built-in. Attribute order is free; values may be double-quoted,
single-quoted or unquoted.

### `#include`

```html
<!--#include virtual="/ssi/header.htm" -->   <!-- from the document root -->
<!--#include virtual="ssi/header.htm" -->    <!-- relative to this file, root fallback -->
<!--#include file="inner/part.htm" -->       <!-- strictly relative to this file -->
```

Included files are processed recursively (their own directives run with the
including file as their base). On failure the `errmsg` text (default: empty)
is emitted. The resolved path passes through the
`htmlphp_ssi_include_path` filter — a veto/redirect point for hosts.

### `#echo`

```html
<!--#echo var="PageTitle" -->
<!--#echo var="DOCUMENT_URI" -->
<!--#echo var="USER_INPUT" encoding="entity" -->  <!-- entity | url | none -->
<!--##echo var="Legacy" -->                        <!-- legacy double-hash form -->
```

Built-in variables win over template globals:
`DATE_LOCAL`, `DATE_GMT`, `DOCUMENT_URI`, `DOCUMENT_NAME`, `DOCUMENT_ROOT`,
`QUERY_STRING`, `SERVER_NAME`, `SERVER_SOFTWARE`, `SERVER_PROTOCOL`,
`REQUEST_METHOD`, `REQUEST_URI`, `SCRIPT_NAME`, `CONTENT_TYPE`,
`REMOTE_ADDR`, `HTTP_USER_AGENT`, `LAST_MODIFIED` (of the current file).
The map is extensible through the `htmlphp_ssi_builtins` filter.

Escaping: without an `encoding` attribute, output is HTML-escaped while
`HTML_SSI_ECHO_ESCAPE` is true (the default). `encoding=` overrides that
policy per directive. `HTML_SSI_ECHO_ALLOW` restricts *which* names may be
echoed at all. `##echo` reads `$GLOBALS` only (legacy) and follows the
global escape policy.

### `#if` / `#elif` / `#else` / `#endif`

```html
<!--#if expr="$ROLE = /admin|editor/" -->
  staff-only content
<!--#elif expr="$ARTICLES > 100" -->
  prolific author
<!--#else-->
  visitor content
<!--#endif-->
```

Directives inside a false branch are parsed for nesting but never executed
(no `#set`, no includes, no `#exec`). Nesting is bounded (64 levels).

#### Expression grammar

```text
expr       := or
or         := and ( '||' and )*
and        := not ( '&&' not )*
not        := '!' not | comparison
comparison := operand [ ('=' | '==' | '!=' | '<' | '<=' | '>' | '>=') operand ]
operand    := filetest? primary
filetest   := '-f' | '-d' | '-e' | '-s' | '-x' | '-r' | '-w'
primary    := '(' expr ')' | STRING | REGEX | VARIABLE | WORD
STRING     := "…" | '…'      (escapes: \" \\ \$ \/ \n \t \r)
REGEX      := /…/            (right side of = / != only, \/ escaped)
VARIABLE   := $NAME | ${NAME}
WORD       := unquoted [A-Za-z0-9_.,:;@+/\*-]+
```

Semantics:

* every value is a string; `''` and `'0'` are false, everything else true;
* `=`/`!=` with a `/regex/` operand performs a case-sensitive match;
* `<`, `<=`, `>`, `>=` compare numerically when both sides are numeric
  strings, byte-wise (`strcmp`) otherwise;
* file tests resolve paths exactly like `#include` (document-root
  containment) and yield `'1'`/`''`;
* parse errors, invalid regexes and over-long expressions (> 4 KiB)
  evaluate to **false** and are recorded as `ssi-expr-error`;
* there is no `eval()`, no PHP evaluation and no shell — the evaluator is a
  self-contained tokenizer + recursive-descent parser
  (`HtmlPhp\Ssi\Expression`).

### `#exec` (deny by default)

```html
<!--#exec cmd="/usr/local/bin/report --today" -->
<!--#exec cgi="/cgi/status.php" -->
```

Nothing runs unless **all** applicable gates pass:

| Gate | Default | Notes |
|---|---|---|
| `HTML_SSI_EXEC` | `false` | master switch |
| `HTML_SSI_EXEC_CMD_ALLOW` | `[]` | `cmd=` binaries (path or basename); empty list = deny all |
| document-root containment | on | `cgi=` scripts must resolve inside the root |
| `HTML_SSI_EXEC_CGI_EXT` | `['php']` | allowed `cgi=` extensions |
| `HTML_SSI_EXEC_TIMEOUT` | 5 s | child is killed on exceed |
| `HTML_SSI_EXEC_MAX_BYTES` | 64 KiB | output truncated, child killed |

`cmd=` **never goes through a shell** — the command line is tokenised
(quotes honoured) and spawned with `proc_open()` receiving an argv array, so
metacharacters (`>`, `|`, `;`, `` ` ``, `$()`) are inert literals. The child
environment is minimal (`PATH`, `DOCUMENT_ROOT`, …).

`cgi=` runs in `spawn` mode by default (a fresh `PHP_BINARY` process with
CGI-style environment) or `include` mode (in-process, output-buffered —
faster but shares state; opt-in via `HTML_SSI_EXEC_CGI_MODE`).

Denials and failures emit the `errmsg` text and record `ssi-exec-denied` /
`ssi-exec-failed` diagnostics.

### `#fsize` / `#flastmod`

```html
<!--#fsize virtual="/report.pdf" -->        <!-- obeys #config sizefmt -->
<!--#flastmod file="inner/notes.htm" -->    <!-- obeys #config timefmt -->
```

`abbrev` sizes render Apache-style (`512`, `2K`, `3.1M`); `bytes` renders
exact digits. With no `file=`/`virtual=` attribute the current document is
used. Failures emit `errmsg`.

### Legacy forms

```html
<!--@var="NAME"stored value-->   <!-- sets $GLOBALS['NAME'], emits the value -->
```

Unknown `<!--#directive -->` comments are left in the output verbatim
(mod_include parity: they are ordinary HTML comments to us).

## Built-in variable notes

* `DATE_LOCAL`/`DATE_GMT` use a **stable "now"** captured per render, so a
  page cannot show two different timestamps.
* `DOCUMENT_ROOT` prefers `HTML_WWW_PATH`, then `$_SERVER['DOCUMENT_ROOT']`.
* `LAST_MODIFIED` is the mtime of the file currently being processed,
  formatted with the active `timefmt`.
