# Migration Guide — HTML.PHP 4.x → 5.0

**Good news first: 5.0 is additive.** Every template that rendered under
4.x renders byte-identically under 5.0 — no syntax was removed, no default
changed, no behaviour reinterpreted. The major number reflects the size of
the language addition (inline expressions and nested access touch the
substitution pipeline), not a compatibility break.

Upgrading:

```bash
composer require paulmann/html-php:^5.0     # PSR-4 source
# — or —
copy php83/html.php (resp. php85/html.php) over the old bundle   # single-file
```

The WordPress plugin (`wordpress/html-php/`) ships in the same repository;
replace the folder and re-run **Tools → HTML.PHP → Cache: clear** once.

---

## 1. What is new

| Feature | 4.x | 5.0 |
|---|---|---|
| Math in a substitution tag | ❌ — compute in `@Set` first | ✅ `%{= done / total * 100 \| round:1 }` |
| Hash element by dynamic key | ❌ — static dot paths only | ✅ `%{= prices[currency]}`, `%{= rows[i].name}` |
| "Variable inside a variable" | ❌ | ✅ `%{= get(fieldName)}`, `%{= get('rows[1].name')}` |
| Arithmetic filters | `div` only | `add sub mul div mod pow sqrt pct sum avg clamp` |
| Expression functions | 17 | 23 (`pow sqrt sum avg clamp get` added) |
| Expression-level ranges `a..b` | broken (syntax error outside `@Loop`) | ✅ fixed, indexable: `%{= (1..3)[1]}` |
| Kill switch for inline expressions | — | `syntax.expressions: false` |

## 2. What changed under the hood

All changes are hardening or bug fixes; none alter 4.x output:

- **Bounded AST memo.** `Template\Expression` memoized parse trees in an
  unbounded static array. It is now a 4 096-entry FIFO. Long-running workers
  rendering many unique expressions keep a flat memory profile.
  (`Expression::reset()` still exists for deploy hooks.)
- **Iteration budget.** `count()`/`sum()`/`avg()`/`in` over a
  `Traversable` stop at 100 000 items (`Str::MAX_AGGREGATE_ITEMS`). An
  infinite generator passed by host code degraded to a hang before; it now
  computes against the budget. Finite iterables below the budget are
  unaffected.
- **`matches` via `Support\Pcre`.** A ReDoS-shaped pattern that trips PCRE's
  backtrack limit is a clean no-match (it already could not hang forever;
  now it also can never surface a `null`).
- **Range parsing fixed.** `a..b` / `(1..3)` inside `@If`, `@Set` and `%{=}`
  previously threw "Trailing input in expression" (the lexer emitted a
  token type the parser never checked). `@Loop i in 1..10` was unaffected —
  the parser handled ranges separately — and stays unaffected.
- **`Substitution` now receives `Config\Limits`.** Hosts constructing
  `Substitution` directly (rare; the container wires it) add the limits
  argument after `$syntax`.
- **CLI options accept both forms**: `--var=k=v` (as before) and the
  documented-but-previously-ignored `--var k=v`. Scripts using the `=` form
  are unaffected.
- **`Scope::walk()` became `Scope::step()` (public)** and gained a sibling
  `Scope::index()`. Both are pure statics; `find()` semantics are unchanged.

## 3. Adopting the new syntax

### Percentages and arithmetic inline

```html
<!-- 4.x -->
<!--@Set share="done / total * 100"-->
<div class="bar" style="width: %{share|round:0}%"></div>

<!-- 5.0 — same result, one tag -->
<div class="bar" style="width: %{= done / total * 100 | round:0}%"></div>
```

### Hash elements by key

```html
<!-- 4.x: only when the key is known at authoring time -->
%{prices.EUR}

<!-- 5.0: the key can come from the data -->
%{= prices[currency] }
%{= rows[i].name }
```

### Names built at runtime

```html
%{= get(fieldName) }                     <!-- field = 'user.email' -->
%{= get(prefix ~ suffix, '—') }          <!-- composed, with default -->
```

### Simple chains without `=`

```html
%{done|pct:250:1}%      <!-- filter arguments are LITERALS … -->
%{price|mul:1.19}       <!-- … so these are for constants only. -->
```

> **The one trap:** filter arguments are never resolved against the scope
> (unchanged 4.x rule). `%{done|pct:total}` divides by the *string*
> `total` → `0`. The linter now warns (`filter-arg-not-numeric`) and points
> at the `%{= …}` form.

## 4. Hardening checklist for user-editable templates

If your users author templates (page builders, multisite editors,
database-stored markup), v5 recommends:

```php
Engine::create([
    'syntax' => [
        'expressions'      => false,  // refuse %{= …} outright (fail-closed)
        'allow_raw_filter' => false,  // no unescape hatch
        'strict'           => true,  // template bugs throw, never half-render
    ],
    'ssi'    => ['exec_mode' => 'deny'],
]);
```

With `expressions: false` the tag stays visible in the output and an
`expr-disabled` diagnostic is recorded — nothing is evaluated. Note that
`@If`/`@Set` expressions remain available (they are structural; a host that
cannot trust a template with `@If` cannot trust it at all — use the
`Security` config group and `PathGuard`-contained roots for that threat
model).

## 5. New diagnostics & lint codes

| Code | Where | Meaning |
|---|---|---|
| `expr-invalid` | Diagnostics | malformed inline expression; tag kept visible |
| `expr-disabled` | Diagnostics | `%{= …}` met `syntax.expressions: false` |
| `bad-expression` | Lint (existing code, new source) | `%{= …}` failed validation |
| `expressions-disabled` | Lint | `%{= …}` while the gate is off |
| `filter-arg-not-numeric` | Lint (warning) | numeric filter argument looks like a variable |

## 6. Deprecations & removals

None. No 4.x API, config key, filter, tag or behaviour was removed or
deprecated in 5.0.
