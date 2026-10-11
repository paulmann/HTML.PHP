# Security Model — HTML.PHP 4

Design principle: **a template is data, never code.** Nothing in the
rendering path can execute PHP, spawn a shell, or read a file the host did
not sanction — unless the host explicitly configures it to, and even then
behind allow-lists, budgets and containment.

## 1. Output escaping (XSS)

- Auto-escaping is **on by default** (v3: off). Every `%{}`/`#{}`/`@{}`
  substitution is HTML-escaped (`ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE`,
  UTF-8, `double_encode: false`) unless the filter chain already produced
  safe output (`escape/attr/url/json/js/css/nl2br`) or explicitly requested
  `raw`.
- Context escapers: `attr`, `url` (encodes `&`/`'` — values, not query
  strings), `json`/`js` (`JSON_HEX_TAG|APOS|QUOT|AMP` — `</script>`
  breakout-safe), `css`.
- `syntax.allow_raw_filter: false` removes the only unescape hatch —
  recommended whenever templates come from users (multisite editors,
  database-stored templates).
- `#echo` output is escaped by default (`ssi.escape_echo`), with explicit
  `encoding=` opt-outs and an `ssi.echo_allow` name gate.
- `#exec` output has control bytes stripped (`security.sanitize_exec_output`).

## 2. Filesystem containment (traversal & source disclosure)

All include paths — SSI `file=`/`virtual=`, `@Include`, `@Extends`,
catalogue files — pass through realpath-based containment:

- `..` segments and URL schemes are rejected before resolution (unless
  `ssi.allow_parent_paths`, off by default).
- After `realpath()` (which also resolves **symlinks**), the target must sit
  inside a configured root.
- `security.denied_extensions` (php, phar, phtml, cgi, pl, py, sh, inc,
  conf, env, htaccess, ini, log, sql, bak, …) and **all dotfiles** are never
  includable — v3 would happily serve `wp-config.php` sources through
  `#include`; v4 refuses (verified by the test suite).
- Absolute paths in template *data* (`@Include "/etc/passwd"`) are rejected
  outright; only host code (`renderFile`) may pass absolute paths.
- The WordPress `TemplateLoader` never treats `.php` as a template suffix.
- Size caps: `limits.max_template_bytes` (16 MiB) and
  `limits.max_include_bytes` (8 MiB).

## 3. Code execution (`#exec`)

- Denied by default (`ssi.exec_mode: deny`) — diagnostics, not silence.
- `allow-list`: `cmd=` binaries must realpath-match (or basename-match) an
  explicit allow-list entry; no shell is involved (`proc_open` argv form,
  quote-aware tokenizer); minimal CGI environment; hard timeout
  (1–60 s) and output cap (≤16 MiB) enforced via `stream_select`.
- `cgi=`: document-root-contained scripts with allow-listed extensions
  only; `spawn` mode runs `php -f` in a subprocess, `include` mode captures
  output in-process and converts throws into diagnostics.
- `delegate`: host callable decides per directive (`true`/`false`/literal
  output) — the recommended way to integrate a job runner safely.

## 4. Expression safety

Two independent evaluators, neither uses `eval()`:

- **SSI `#if`** (`Ssi\Expression`): mod_include string grammar —
  comparisons, `/regex/`, file tests, logic. File tests resolve through the
  same containment as includes; `-A`/`-U` (Apache subrequests) are refused
  with a diagnostic — a template can never trigger network I/O. Length cap
  `limits.max_expression_length`. Errors evaluate `false`, never throw.
- **Template expressions** (`Template\Expression`): tokenizer +
  precedence-climbing parser producing a memoized AST; literals, names,
  arithmetic/logic/ternary/coalesce, `in`, `matches` (string-literal
  patterns only), `a..b` ranges (capped), and a **whitelisted** function
  set (`count/empty/min/max/abs/round/floor/ceil/int/float/str/lower/upper/
  number/default/length/defined`). Unknown function ⇒ syntax error. Division
  and modulo by zero yield `0` (html4.pl semantics, no fatals). Depth and
  length caps; `syntax.strict` turns expression errors into exceptions.
- `PluralForms` header expressions: separate mini-evaluator, length-capped,
  fail-safe to form `0` — a malicious PO file cannot execute anything.

## 5. Resource budgets (DoS)

`limits.*`: template/include byte caps, SSI depth (16) + tag budget (256),
loop rows (10 000, truncates + diagnoses) + nesting (32), partial/layout
nesting (64), layout chain depth (8), expression length, exec timeout +
output cap, diagnostics cap (100, bounded memory). Regex work goes through
`Support\Pcre`, which converts PCRE failures (including backtrack-limit
hits from ReDoS-shaped patterns) into exceptions or no-matches — never
silent `null`s.

## 6. No hidden state

- The core contains **no `eval()` and no `$GLOBALS`/superglobal reads** —
  enforced by a token-level test (`13_security.test.php`) in every CI run.
  Sanctioned edges: `HtmlPhp\Input` (explicit, read-only, sanitising) and
  the `WordPress\*` adapters (which exist to talk to WordPress' own
  globals like `$wpdb`).
- Configuration is an immutable validated object; engines are independent —
  one misconfigured tenant cannot leak into another engine in the same
  process.
- Time comes from an injectable `ClockInterface`; request data from an
  injected `RequestContext`.

## 7. Cache & filesystem hygiene

- `FileCache`: hashed keys, atomic writes (temp + verified length +
  rename), probabilistic GC, and directory hardening — a deny-all
  `.htaccess` plus an empty `index.html` are written into the cache root
  (`cache.harden_directory`).
- The WordPress cache backend keys object-cache groups per blog id and
  stores values as transients, both namespaced by a configurable prefix.
- `AtomicWriter` preserves file modes and never writes outside the
  destination directory.

## 8. Diagnostics & error disclosure

- Exceptions carry structured context (template, line, directive) for logs;
  nothing sensitive is echoed to output — failed directives render the
  configured `errmsg` (or nothing).
- `#printenv` is escapable-by-default, allow-list-gated and can be disabled
  (`ssi.printenv_enabled`).
- The linter surfaces misconfigurations (`ssi-exec-denied` info) **before**
  deploy instead of at runtime.

## Threat model summary

| Vector | Mitigation |
|---|---|
| XSS via variables | auto-escape default, context filters, disable-able `raw` |
| XSS via SSI echo | escaped by default + allow-list |
| LFI / source disclosure | realpath containment, denied extensions, dotfile ban, absolute-path ban |
| Symlink escape | realpath resolution before containment check |
| RCE via `#exec` | deny default, allow-lists, no shell, delegate hook, timeout/caps |
| RCE via expressions | whitelisted functions, no eval, AST evaluator, caps |
| RCE via plural headers | dedicated safe evaluator, length cap, fail-safe |
| DoS (loops/includes/templates/regex) | byte, depth, row, tag, length budgets + PCRE error handling |
| Cache poisoning/collision | keys include version+locale+escape policy+vars hash; atomic writes |
| Cross-tenant state leaks | no statics, per-engine everything (test-enforced) |

## Reporting

Security issues: please report privately to Mikhail@Deynekin.com.
Supported versions: 4.x on PHP 8.3+ (8.5 edition on PHP 8.5+).
