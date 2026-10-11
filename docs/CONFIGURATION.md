# Configuration Reference — HTML.PHP 4

One immutable `Config` value object replaces all `$GLOBALS['HTML_*']` keys
of v3. Unknown keys throw `ConfigException` at every nesting level.

```php
use HtmlPhp\Engine;

$engine = Engine::create([
    'debug'   => false,
    'charset' => 'UTF-8',
    'templates' => [...],
    'syntax'    => [...],
    'ssi'       => [...],
    'i18n'      => [...],
    'cache'     => [...],
    'log'       => [...],
    'security'  => [...],
    'limits'    => [...],
]);
```

## Top level

| Key | Type | Default | Notes |
|---|---|---|---|
| `debug` | bool | `false` | reserved for host tooling; engine behaviour is governed by the specific switches |
| `charset` | string | `UTF-8` | escapers are UTF-8 based |

## `templates`

| Key | Type | Default | Notes |
|---|---|---|---|
| `paths` | list\<string\> | `[]` | ordered template roots; first match wins |
| `namespaces` | map | `{}` | `['@admin' => '/path']` → `@admin/page.html` (`@` optional in keys) |
| `extension` | string | `.html` | appended to extension-less names |
| `cache_file_mtimes` | bool | `true` | reserved (parse memo is per-engine-instance) |

## `syntax`

| Key | Type | Default | Notes |
|---|---|---|---|
| `var_open` / `var_close` | string | `%{` / `}` | primary substitution delimiters |
| `alt_open` | string | `#{` | alternate form (closes with `var_close`-independent `}`) |
| `trans_open` | string | `@{` | translation form |
| `tag_open` / `tag_close` | string | `<!--@` / `-->` | structure tags |
| `auto_escape` | bool | **`true`** | v3 defaulted false — v4 escapes by default |
| `missing_var` | enum | `empty` | `empty` \| `keep` \| `placeholder` \| `throw` |
| `missing_placeholder_format` | string | `[missing:%s]` | for `placeholder` |
| `single_braces` | bool | `false` | enable `{NAME}` form (CSS/JS clash risk) |
| `allow_raw_filter` | bool | `true` | set `false` for user-editable templates |
| `strict` | bool | `false` | unknown filters / broken conditions throw instead of diagnosing |

## `ssi`

| Key | Type | Default | Notes |
|---|---|---|---|
| `enabled` | bool | `true` | master switch |
| `document_root` | ?string | `null` | containment root; falls back to `RequestContext::$documentRoot`, then cwd |
| `allow_parent_paths` | bool | `false` | permit `..`/outside-root includes (not recommended) |
| `escape_echo` | bool | `true` | default escaping of `#echo` |
| `echo_allow` | list | `[]` | restrict echo-able names (empty = all) |
| `time_format` | string | `%A, %d-%b-%Y %H:%M:%S %Z` | strftime-style |
| `size_format` | enum | `abbrev` | `abbrev` \| `bytes` |
| `error_message` | ?string | `null` | emitted by failing directives |
| `exec_mode` | enum | **`deny`** | `deny` \| `allow-list` \| `delegate` (v3's boolean `true` maps to `allow-list`) |
| `exec_cmd_allow` | list | `[]` | realpath-compared or basename-matched binaries |
| `exec_cgi_extensions` | list | `['php']` | for `cgi=` |
| `exec_cgi_mode` | string | `spawn` | `spawn` \| `include` (in-process) |
| `exec_delegate` | ?callable | `null` | `fn(string $kind, array $info): bool\|string` — required by `delegate` |
| `process_rendered_output` | bool | `true` | one guarded re-substitution pass when SSI output contains template syntax |
| `printenv_enabled` | bool | `true` | `#printenv` master switch |

## `i18n`

| Key | Type | Default | Notes |
|---|---|---|---|
| `locale` | string | `en` | normalised (`de-de` → `de_DE`) |
| `fallback` | list | `['en']` | consulted after the active locale |
| `domain` | string | `messages` | default text domain |
| `paths` | list | `[]` | lazily scanned catalogue directories (see I18N.md) |
| `detect` | bool | `false` | negotiate from Accept-Language when available |
| `use_wordpress` | ?bool | `null` | `null` auto-detect, `true`/`false` force WP delegation |
| `number_decimals` | int | `2` | `Formats::number` default |
| `decimal_point` / `thousands_separator` | string | `.` / `,` | fallback when the locale is unknown |
| `date_format` / `time_format` / `datetime_format` | string | `Y-m-d` / `H:i` / `Y-m-d H:i` | `date()` patterns for the `date` filter & Formats |

## `cache`

| Key | Type | Default | Notes |
|---|---|---|---|
| `enabled` | bool | `false` | render-output cache |
| `driver` | enum | `file` | `null` \| `array` \| `file` \| `apcu` \| `wordpress` (fallbacks diagnose, never crash) |
| `directory` | string | `''` | **required** for `file` |
| `ttl` | int | `0` | seconds, 0–30 days (0 = backend default/no expiry) |
| `prefix` | string | `htmlphp` | key/dir namespace |
| `lock_writes` | bool | `true` | reserved for FileCache tuning |
| `harden_directory` | bool | `true` | write deny-all `.htaccess` + `index.html` |

Cache keys cover: version, locale, escape mode, missing-var policy, SSI
flag, anchor file/root, source hash and a **stable scalar view of the
variables** (objects reduce to class name / `__toString`), so two renders
never collide.

## `log`

| Key | Type | Default | Notes |
|---|---|---|---|
| `driver` | enum | `null` | `null` \| `array` \| `errorlog` \| `file` \| `syslog` \| `wordpress` |
| `path` | string | `''` | required for `file` (else degrades to null) |
| `level` | string | `warning` | PSR-3 level gate |
| `channel` | string | `htmlphp` | line prefix |
| `forward_diagnostics` | bool | `true` | mirror `Diagnostics` records to the logger |

## `security`

| Key | Type | Default | Notes |
|---|---|---|---|
| `allowed_tags` | map\|null | `null` | allow-list for `Html\Cleaner` (`null` = documented default set: br, p, h1–h4, strong, b, ul, ol, li, hr) |
| `denied_extensions` | list | see `Security::DENIED_EXTENSIONS_DEFAULT`: php, phtml, php3–php8, phps, phar, cgi, pl, py, rb, sh, bash, exe, dll, so, inc, conf, config, env, htaccess, htpasswd, ini, log, sql, bak, swp, dist | never includable via SSI/partials/layouts; **dotfiles** (`.env`, `.htaccess`, …) are refused separately and unconditionally |
| `deny_php_includes` | bool | `true` | belt-and-braces flag (deny list already covers it) |
| `sanitize_exec_output` | bool | `true` | strip control bytes from `#exec` output |

## `limits`

| Key | Default | Bounds | Purpose |
|---|---|---|---|
| `max_template_bytes` | 16 MiB | 1 KiB – 512 MiB | render input cap |
| `max_include_bytes` | 8 MiB | 1 KiB – 256 MiB | SSI/partial include cap |
| `max_ssi_depth` | 16 | 1 – 128 | include recursion |
| `max_ssi_tags` | 256 | 1 – 10 000 | directive budget per render |
| `max_loop_rows` | 10 000 | 1 – 1 000 000 | rows per loop (truncates + diagnoses) |
| `max_loop_depth` | 32 | 1 – 128 | loop nesting |
| `max_block_depth` | 64 | 1 – 1024 | partial/layout nesting |
| `max_passes` | 2 | 1 – 16 | reserved (v4 pipeline is single-pass + guarded SSI re-pass) |
| `max_expression_length` | 4096 | 16 – 65 536 | `@If`/`@Set`/`#if` source length |
| `max_exec_output_bytes` | 65 536 | 1 – 16 MiB | `#exec` capture cap |
| `exec_timeout` | 5 | 1 – 60 | seconds per spawned process |
| `max_diagnostics` | 100 | 1 – 10 000 | collector cap |
| `max_precision` | 100 | 0 – 1000 | rounding precision cap |

Out-of-bounds values throw `ConfigException` — a guard can never be
silently disabled by a typo.

## Migration map for v3 config keys

See [MIGRATION-3.x-TO-4.0.md](MIGRATION-3.x-TO-4.0.md) §Configuration for
the full `HTML_*` → v4 table.
