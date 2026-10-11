# Template Syntax Reference — HTML.PHP 4

The template language is HTML-first: every structural directive is an HTML
comment, so templates stay valid markup and render sensibly even when the
engine is off. Processing order per render:

```
parse (tag tree, memoized) → layout inheritance → tree render
  (loops / conditionals / partials / set / trans + %{…} substitution)
→ SSI pass → optional re-substitution pass → 'htmlphp.render' filter → cache
```

---

## 1. Substitutions

| Form | Meaning |
|---|---|
| `%{NAME}` | primary substitution |
| `#{NAME}` | alternate brace form (identical behaviour) |
| `@{KEY}` | translation lookup (`KEY` is the message id; falls back to the key itself) |
| `%{user.address.city}` | dot notation walks arrays, `ArrayAccess`, public properties, `label()`/`getLabel()`/`isLabel()`/`hasLabel()` accessors |
| `%{NAME\|filter:arg\|filter2}` | filter chain |
| `{NAME}` | opt-in single-brace form (`syntax.single_braces`, off by default — CSS/JS-safe otherwise) |

Delimiters are configurable (`syntax.var_open`, `var_close`, `alt_open`,
`trans_open`). Anything that is not a *valid variable name*
(`[A-Za-z_][A-Za-z0-9_.-]*`) inside braces is left untouched, so
`body { color: red }` and `if (a) { b(); }` survive untouched.

### Escaping

**Auto-escaping is ON by default** (`syntax.auto_escape`). Every
substitution passes through the HTML escaper (`ENT_QUOTES | ENT_HTML5 |
ENT_SUBSTITUTE`, `double_encode: false` — already-escaped entities are not
double-encoded) unless:

- the chain contains `raw` (and `syntax.allow_raw_filter` is true), or
- the chain already ends in an escaping filter: `escape/esc/e`, `attr`,
  `url`, `json`, `js`, `css`, `nl2br`.

### Missing variables

Governed by `syntax.missing_var` (or `RenderOptions::$missingVar`):

| Policy | Behaviour |
|---|---|
| `empty` (default) | substitutes `''`, records one `missing-var` diagnostic per name |
| `keep` | leaves `%{NAME}` in the output |
| `placeholder` | substitutes `syntax.missing_placeholder_format` (default `[missing:%s]`) |
| `throw` | aborts with `TemplateException` |

`null` values are **not** missing: they stringify to `''` without diagnostics.

---

## 2. Filters

`%{NAME|filter:arg1:'quoted:arg'|filter2}` — arguments are split on `:`;
single/double quotes protect separators (`|date:'H:i'`), `\` escapes inside
quotes. Filters receive and return `mixed`; stringification happens once at
the end.

Built-ins: `escape`/`esc`/`e[:strategy]`, `attr`, `url`, `json`, `js`,
`css`, `upper`, `lower`, `ucfirst`, `title`, `words` (word **count**),
`number[:dec[:point[:thousands]]]` (locale-aware by default), `round[:n]`,
`cleanround[:n]`, `int`, `float`, `div:divisor` (0 on zero divisor), `abs`,
`date[:format]`, `ago`, `truncate[:len[:suffix]]`, `excerpt[:len[:suffix]]`,
`nl2br`, `striptags`, `slug[:sep]`, `default:value`, `raw`,
`pad[:len[:str[:left]]]`, `repeat[:n]`, `translate`, `join[:sep]`, `split:sep`,
`length`, `first`, `last`, `reverse`, `sort[:desc]`, `unique`,
`slice:offset[:len]`, `replace:from:to`, `trim[:chars]`, `base64`, `md5`,
`sha1`, `keys`.

Custom filters (per engine instance):

```php
$engine->filters()->add('shout', fn(mixed $v): string => strtoupper((string) $v) . '!');
```

Unknown filters record a `filter-unknown` diagnostic and pass the value
through — or throw in `syntax.strict` mode. `raw` can be disabled outright
with `syntax.allow_raw_filter: false` (useful for user-editable templates).

---

## 3. Loops

```html
<!--@Loop rows-->            row fields shadow the outer scope (v3 semantics);
                             scalars become a single row exposed as %{value}
<!--@Loop item in rows-->    alias binding only (+ magic vars)
<!--@Loop k, v in map-->     key/value binding
<!--@Loop i in 1..10-->      inclusive integer range (ascending or descending)
<!--@Else-->                 runs when the iterable is empty/missing
<!--@EndLoop rows-->         the end name is optional but linted for matches
```

Iterables: arrays, `Traversable`, `ArrayAccess&Countable`, objects
(public properties), scalars (single row). Magic variables inside a row:
`_index` (0-based), `_position` (1-based), `_key`, `_first`, `_last`,
`_count`, `_odd`, `_even`, plus a `loop` map: `%{loop.index}`,
`%{loop.index0}`, `%{loop.first}`, `%{loop.last}`, `%{loop.length}`,
`%{loop.key}`.

`<!--@Break-->` and `<!--@Continue-->` work inside loops (also nested via
conditionals). Guards: `limits.max_loop_rows` (truncates + `loop-guard`
diagnostic), `limits.max_loop_depth`.

## 4. Conditionals

```html
<!--@If EXPR--> … <!--@Elif EXPR--> … <!--@Else--> … <!--@EndIf-->
<!--@Unless EXPR--> … <!--@EndUnless-->          (= If !EXPR)
```

`EXPR` is the safe expression language — **no `eval`, no arbitrary calls**:

- literals: `123`, `1.5`, `'text'`, `"text"`, `true`, `false`, `null`
- names & dot paths: `user.active`, `rows`
- operators: `== != === !== < <= > >=` · `&&`/`and` · `||`/`or` · `!`/`not`
  · `+ - * / %` (division/modulo by zero yield `0`) · `~` (string concat)
  · `?:` · `??` · `in` (array/string containment) · `matches '/re/'`
- functions: `count`, `length`, `empty`, `defined`, `min`, `max`, `abs`,
  `round`, `floor`, `ceil`, `int`, `float`, `str`, `lower`, `upper`,
  `number`, `default`

Truthiness follows PHP (`''`, `'0'`, `[]`, `null`, `false` are false).
A plain name (`<!--@If flag-->`) is v3-compatible truthiness; `!flag`
still negates. Broken expressions: diagnostic + false branch (lenient) or
`TemplateSyntaxException` (strict).

## 5. Set

```html
<!--@Set total="price * qty + shipping"-->
<!--@Set label = 'Ready'-->
%{total|number:2}
```

Writes into the **current scope frame** — a `@Set` inside a loop dies with
the iteration; the outer scope keeps its value.

## 6. Layout inheritance

```html
<!-- base.html -->
<html><head><title><!--@Section title-->My Site<!--@EndSection--></title></head>
<body><!--@Section content-->fallback<!--@EndSection--></body></html>

<!-- child.html -->
<!--@Extends "base.html"-->
<!--@Section title-->%{pageTitle} — <!--@SectionParent--><!--@EndSection-->
<!--@Section content--><h1>%{pageTitle}</h1><!--@EndSection-->
```

- The first `@Extends` wins; all other top-level output of the child is
  dropped (Twig semantics).
- Multi-level chains resolve deepest-override-first; depth > 8 aborts with
  a `TemplateException` (cycle protection).
- `<!--@SectionParent-->` renders the next level down (parent override, or
  the layout's inline default at the end).

## 7. Partials

```html
<!--@Include "parts/card.html"-->                       inherits the current scope
<!--@Include "parts/card.html" only-->                  empty scope
<!--@Include "parts/card.html" with="title,url"-->      only the listed variables
<!--@Include "parts/optional.html" ignore-missing-->    silent when absent
```

Resolution: the including document's directory first, then
`templates.paths`, `@namespace/...` prefixes map to
`templates.namespaces`. The default extension (`templates.extension`) is
appended when needed. Absolute paths, traversal and denied extensions are
refused (`Io\PathGuard`). Missing partials throw
`TemplateNotFoundException` unless `ignore-missing`.

## 8. Translation blocks

```html
<!--@Trans-->Hello <!--@EndTrans-->                     static msgid
<!--@Trans-->Hello %{name}<!--@EndTrans-->              msgid "Hello {0}"
<!--@Trans count="%{n}" domain="shop" context="cart"-->
  %{n} item
<!--@Plural-->
  %{n} items
<!--@EndTrans-->
```

The body's `%{…}` placeholders become positional `{0}`, `{1}`, … in the
msgid (exactly what `I18n\Extractor` writes to the POT), arguments resolve
against the scope, plural selection uses the catalogue's `Plural-Forms`.
`{count}` is available in both forms when `count=` is given. Arguments are
escaped per the render's auto-escape setting.

## 9. Verbatim & comments

```html
<!--@Raw--> %{not_a_var} <!--@If ignored--> <!--@EndRaw-->   output as-is
<!--@Comment--> never rendered, never parsed <!--@EndComment-->
```

## 10. Strictness & errors

- Unbalanced/unknown tags are **always** a `TemplateSyntaxException`
  (file + line in the exception context). Validate first with
  `Engine::lint()` / `bin/htmlphp lint` in CI.
- `syntax.strict: true` additionally turns unknown filters and broken
  condition expressions into exceptions.
- Non-fatal problems collect in `Diagnostics` (capped, code-stable),
  optionally forwarded to the configured logger and always emitted on the
  `htmlphp.error` event.

## 11. Interaction with SSI

The SSI pass runs **after** the template language, over the assembled
document (Apache semantics) — so directives emitted by loops/partials work.
When the SSI pass changes the output and the result contains template
syntax again (an `#include`d fragment with `%{…}` tags), one guarded
re-substitution pass runs (`ssi.process_rendered_output`, on by default).
This replaces v3's `passes` option with deterministic behaviour.
