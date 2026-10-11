# SSI Reference — Apache mod_include compatible

HTML.PHP processes Server Side Includes inside rendered documents:

```php
$html = $engine->processSsi($content, new DocumentInfo(
    file: '/var/www/page.shtml',      // anchors file= includes + LAST_MODIFIED
    root: '/var/www',                 // containment root for virtual=
    request: RequestContext::fromServer($_SERVER),  // built ONCE at the edge
    vars: ['username' => 'bob'],      // visible as $username in #if/#echo
));
```

`Engine::render*()` runs this pass automatically after the template
language (`ssi.enabled`). The engine **never** reads `$_SERVER` itself —
the host supplies a `RequestContext`.

## Evaluation model

- Directives are scanned in document order with a per-document conditional
  frame stack (nesting up to 64 deep).
- `#include` recursion is depth-guarded (`limits.max_ssi_depth`, default 16),
  budget-guarded (`limits.max_ssi_tags`, default 256), cycle-guarded
  (realpath stack) and size-guarded (`limits.max_include_bytes`, 8 MiB).
- Failures never throw: they emit `ssi.error_message` (if configured) and
  record a diagnostic (`ssi-include-failed`, `ssi-exec-denied`, …).
- Unknown directives are left in the output verbatim (they are ordinary
  HTML comments) and recorded as `ssi-unknown-directive`.
- The legacy v2 forms `<!--##name …-->` and `<!--@var=…-->` were **removed
  in v4**.

## Directives

### `#config`

```html
<!--#config timefmt="%Y-%m-%d %H:%M" sizefmt="bytes" errmsg="[error]" -->
```

`timefmt` accepts strftime tokens (converted internally — no deprecated
`strftime()`); it resets cached `DATE_LOCAL`/`DATE_GMT`/`LAST_MODIFIED`
values. `sizefmt`: `abbrev` (Apache style: `12K`, `1.2M`) or `bytes`.
`errmsg` is the text emitted by failing directives. State applies to the
current render (including nested includes), never to other requests.

### `#set`

```html
<!--#set var="greeting" value="Hello $username, today is ${DATE_LOCAL}" -->
```

Values expand `$VAR` and `${VAR}` (built-ins → previous `#set` vars →
render-scope vars; unknown names stay verbatim, `\$` escapes). Reserved
names (superglobals, built-ins) cannot be written. Variables live in the
render context only — **not** in `$GLOBALS` (v3 did; v4 does not).

### `#include`

```html
<!--#include file="parts/nav.html" -->          relative to the current document
<!--#include virtual="/shared/footer.html" -->  relative to the document root
<!--#include virtual="/x.html" stub="fallback" wait="yes" -->
```

- Containment: resolved paths must stay inside the document root
  (`ssi.allow_parent_paths` to relax — not recommended), must not match
  `security.denied_extensions` (`.php`, `.env`, `.sql`, dotfiles … — a
  source-disclosure guard v3 lacked), and must fit `max_include_bytes`.
- `stub="blockname"` emits the matching `#block` section when the include
  fails (see below). `wait=` is accepted and ignored (sequential engine).
- The resolved path passes through the `htmlphp.ssi.include_path` filter —
  the WordPress bridge uses it for theme overrides.
- Included documents are processed recursively (SSI inside SSI), sharing
  budgets and `#config`/`#set` state.

### `#echo`

```html
<!--#echo var="DATE_LOCAL" -->
<!--#echo var="username" encoding="none|entity|url|base64" -->
```

Built-ins: `DATE_LOCAL`, `DATE_GMT`, `LAST_MODIFIED`, `DOCUMENT_URI`,
`DOCUMENT_NAME`, `DOCUMENT_ROOT`, `QUERY_STRING`, `SERVER_NAME`,
`SERVER_SOFTWARE`, `SERVER_PROTOCOL`, `REQUEST_METHOD`, `REQUEST_URI`,
`SCRIPT_NAME`, `CONTENT_TYPE`, `REMOTE_ADDR`, `HTTP_USER_AGENT`, plus **any
`HTTP_*` header** from the request context. Then `#set` variables, then
render-scope variables (`DocumentInfo::$vars`).

Escaping: on by default (`ssi.escape_echo`); `encoding=` selects
none/entity/url/base64 explicitly. `ssi.echo_allow` restricts which names
may be echoed at all (empty = all). The `htmlphp.ssi.echo` filter sees the
final value.

### `#if` / `#elif` / `#else` / `#endif`

```html
<!--#if expr="$user = 'admin'" --> … <!--#elif expr="-f /private/notes.txt" --> … <!--#else--> … <!--#endif-->
```

Expression grammar (mod_include compatible, everything is a string):

| Construct | Example |
|---|---|
| variable / `${var}` | `$username`, `${DOCUMENT_URI}` |
| quoted strings | `'admin'`, `"a b"` |
| comparison | `=`, `==`, `!=`, `<`, `<=`, `>`, `>=` (numeric when both sides look numeric, else byte order) |
| regex | `$uri = /^\/admin\//` (also with `!=`) |
| file tests | `-f -d -e -s -x -r -w -F` (containment-checked paths); `-A`/`-U` are **accepted but always false** (a template engine performs no subrequests) |
| logic | `&&`, `\|\|` (a lone `\|` counts as `\|\|`), `!`, parentheses |
| bare truthiness | `<!--#if expr="$flag" -->` (`''` and `'0'` are false) |

Syntax/length errors never throw: they record `ssi-expr-error` and evaluate
false. Length cap: `limits.max_expression_length` (4096).

### `#exec` (deny by default)

```html
<!--#exec cmd="/usr/bin/date +%Y" -->
<!--#exec cgi="/cgi-bin/status.php" -->
```

Policy via `ssi.exec_mode`:

| Mode | Behaviour |
|---|---|
| `deny` (**default**) | every `#exec` is refused + `ssi-exec-denied` diagnostic |
| `allow-list` | `cmd=` binaries must resolve (realpath or basename) against `ssi.exec_cmd_allow`; `cgi=` scripts must live under the document root and carry an extension from `ssi.exec_cgi_extensions` (default `['php']`) |
| `delegate` | `ssi.exec_delegate(kind, info)` callable returns `true` (run), `false` (deny) or a string (literal output) |

Execution details: `cmd` runs through `proc_open()` **without a shell**
(quoted argv tokenization), a minimal CGI environment, a hard timeout
(`limits.exec_timeout`, 1–60 s) and output cap
(`limits.max_exec_output_bytes`). `cgi` spawns `php -f` (CGI env) or, with
`ssi.exec_cgi_mode: 'include'`, runs in-process inside an output buffer
(throws become diagnostics + errmsg). Output has control bytes stripped
(`security.sanitize_exec_output`).

> Production advice: leave `deny`. If you need dynamic fragments, register
> a **custom directive** instead — same power, no shell.

### `#fsize` / `#flastmod`

```html
<!--#fsize file="report.pdf" -->        → 12K / 2048 (per #config sizefmt)
<!--#flastmod virtual="/page.html" -->  → formatted per #config timefmt
```

Without `file=`/`virtual=` they refer to the current document.

### `#printenv` *(new in v4)*

```html
<!--#printenv-->
```

Dumps every visible variable as `NAME=value` lines (escaped per
`escape_echo`, honouring `echo_allow`, reserved names excluded). Disable
with `ssi.printenv_enabled: false` — useful on sites where templates come
from less trusted sources.

### `#block` / `#endblock` *(new in v4)*

```html
<!--#block name="fallback"-->
  <p>Content shown when the include with stub="fallback" fails.</p>
<!--#endblock-->
<!--#include virtual="/fragments/news.html" stub="fallback" -->
```

Block sections are extracted in a pre-pass (they never render in place) and
substitute failed includes carrying the matching `stub=` attribute —
Apache/nginx parity.

### Custom directives *(new in v4)*

```php
$engine->ssi()->directives()->register(new class implements DirectiveInterface {
    public function name(): string { return 'snippet'; }
    public function render(array $attributes, SsiContext $context): string {
        return Snippets::get($attributes['id'] ?? '');
    }
});
```

Registered names are checked **before** built-ins, so hosts may also
override `#include` resolution (the WordPress bridge uses the filter hook
instead).

## Built-in variable notes

- All values derive from the injected `RequestContext` + `ClockInterface`
  (tests freeze time; long-running workers never see stale dates).
- `LAST_MODIFIED` is the anchor document's mtime (`''` for string renders).
- `DOCUMENT_URI` falls back to the file's root-relative path in CLI/string
  renders.
- The `htmlphp.ssi.builtins` filter can extend/override the map.
