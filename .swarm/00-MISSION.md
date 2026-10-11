# Mission — HTML.PHP 4.0.0

Owner: coordinator agent (Claude). Sub-agents read this file first.

## Goal
Full professional refactor of https://github.com/paulmann/HTML.PHP (v3.0.0) into
**v4.0.0**: a modern, dependency-free PHP template engine + Apache-SSI processor +
gettext-compatible i18n layer, with WordPress as an *optional* first-class citizen.

Hard requirements from the user:
1. PHP 8.5 pure target, shipped as **two editions**: `php83/` (PHP 8.3+) and `php85/` (PHP 8.5).
2. **No backward compatibility.** The legacy html4.pl API is gone. Clean break.
3. **Zero `GLOBALS`.** Configuration and state flow explicitly through an immutable
   `Config` value object, a small DI `Container`, and explicit call arguments.
4. Must work for *any* PHP code (framework-less), WordPress is an optional bridge.
5. Production ready: static analysis clean, test matrix green, docs in English.
6. Add the features the user forgot to ask for (see `06-GAPS.md`).

## Reference material
- `.v3-reference/` -> symlink to `/home/user/ref/HTML.PHP` (v3.0.0 clone, 502 tests passing).
  Read-only. Never edit. Use it to port algorithms verbatim where behaviour is correct.
- Installed interpreters: `php8.3` (8.3.35) and `php8.5` (8.5.11). Both have mbstring.
  `php` is NOT on PATH — always call `php8.3` / `php8.5` explicitly.
- No composer binary, no network installs needed: library is zero-dependency by design.

## Working agreement
- Update `.swarm/PROGRESS.md` (percentages) after every workstream completes.
- Update `.swarm/HANDOFF.md` whenever state changes that a fresh agent must know.
- Decisions are recorded in `docs/ARCHITECTURE.md` — do not re-litigate, extend it.
- Tests are the contract: `php8.3 tests/run.php` and `php8.5 tests/run.php` must both pass.
