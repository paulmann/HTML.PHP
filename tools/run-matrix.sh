#!/usr/bin/env bash
# Full support matrix for HTML.PHP.
#
#   bash tools/run-matrix.sh                  # strict: every PHP version required
#   bash tools/run-matrix.sh --allow-partial  # missing PHP versions become SKIPs,
#                                             # and the summary shows executed/total
#   bash tools/run-matrix.sh --build          # rebuild bundles first (default: OFF —
#                                             # a test script must not modify the tree)
#
# Combinations (14): PHP {8.3, 8.4, 8.5} × target {src, php83 bundle} × intl {on, off}
#                    plus PHP 8.5 × php85 bundle × intl {on, off}.
#
# Guarantees:
#   * The committed bundles are drift-checked (tools/build.php --check) BEFORE any
#     test runs; the script never rewrites them unless --build is given.
#   * A missing php8.3/php8.4/php8.5 binary is a hard error (exit 2) unless
#     --allow-partial is passed. "SKIP" lines alone are never a green matrix.
#   * The summary always prints "executed X of Y combinations"; exit is non-zero
#     when nothing executed or any combination failed.
#
# Binary overrides: PHP83=… PHP84=… PHP85=… bash tools/run-matrix.sh
set -u
cd "$(dirname "$0")/.."

ALLOW_PARTIAL=0
DO_BUILD=0

for arg in "$@"; do
    case "$arg" in
        --allow-partial) ALLOW_PARTIAL=1 ;;
        --build)         DO_BUILD=1 ;;
        *) echo "run-matrix: unknown option '$arg' (try --allow-partial, --build)" >&2; exit 2 ;;
    esac
done

PHP83="${PHP83:-php8.3}"
PHP84="${PHP84:-php8.4}"
PHP85="${PHP85:-php8.5}"

have() { command -v "$1" >/dev/null 2>&1; }

# Any php for the build/drift gate (bundles are PHP-version-agnostic artefacts).
ANY_PHP=""
for candidate in "$PHP83" "$PHP84" "$PHP85" php; do
    if have "$candidate"; then ANY_PHP="$candidate"; break; fi
done

if [ "$ANY_PHP" = "" ]; then
    echo "run-matrix: ERROR — no PHP binary found (looked for $PHP83, $PHP84, $PHP85, php)." >&2
    echo "run-matrix: executed 0 of 14 combinations." >&2
    exit 2
fi

if [ "$DO_BUILD" = "1" ]; then
    echo "== rebuilding bundles ($ANY_PHP tools/build.php) =="
    "$ANY_PHP" tools/build.php || exit 1
fi

echo "== drift gate: bundles must match src/ ($ANY_PHP tools/build.php --check) =="
"$ANY_PHP" tools/build.php --check || exit 1

missing=""
for php in "$PHP83" "$PHP84" "$PHP85"; do
    have "$php" || missing="$missing $php"
done

if [ -n "$missing" ] && [ "$ALLOW_PARTIAL" = "0" ]; then
    echo "run-matrix: ERROR — required PHP binaries not found:$missing" >&2
    echo "Install them, point PHP83/PHP84/PHP85 at your binaries, or pass --allow-partial" >&2
    echo "run-matrix: executed 0 of 14 combinations." >&2
    exit 2
fi

executed=0
skipped=0
failed=0

# run <label> <php> [runner args...]
run() {
    label="$1"; php="$2"; shift 2

    if ! have "$php"; then
        echo "SKIP  $label ($php not installed)"
        skipped=$((skipped + 1))
        return 0
    fi

    echo "== $label =="
    if "$php" "$@"; then
        executed=$((executed + 1))
    else
        executed=$((executed + 1))
        failed=$((failed + 1))
        echo "FAIL  $label"
    fi
}

NOINTL=(-d disable_functions=transliterator_transliterate)

for php in "$PHP83" "$PHP84" "$PHP85"; do
    ver="${php##*php}"
    run "src@$ver   intl=on " "$php" tests/run.php
    run "src@$ver   intl=off" "$php" "${NOINTL[@]}" tests/run.php
    run "php83@$ver intl=on " "$php" tests/run.php --lib=php83/html.php
    run "php83@$ver intl=off" "$php" "${NOINTL[@]}" tests/run.php --lib=php83/html.php
done

run "php85@8.5 intl=on " "$PHP85" tests/run.php --lib=php85/html.php
run "php85@8.5 intl=off" "$PHP85" "${NOINTL[@]}" tests/run.php --lib=php85/html.php

total=$((executed + skipped))

echo "---------------------------------------------------------------------"
echo "matrix summary: executed $executed of $total combinations, skipped $skipped, failed $failed"

if [ "$executed" -eq 0 ]; then
    echo "run-matrix: ERROR — nothing was executed; this is NOT a matrix run." >&2
    exit 2
fi

if [ "$failed" -gt 0 ]; then
    exit 1
fi

if [ "$skipped" -gt 0 ]; then
    echo "run-matrix: WARNING — $skipped combination(s) skipped (--allow-partial)." >&2
    exit 3
fi

exit 0
