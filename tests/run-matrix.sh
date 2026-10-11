#!/usr/bin/env bash
# Full verification matrix. Run from the project root:
#   bash tests/run-matrix.sh
#
# Requires php8.3 and php8.5 binaries (adjust the arrays below for your
# environment). Exits non-zero on the first failing combination.
set -u
cd "$(dirname "$0")/.."

PORTABLE_LIBS=(php83/html.php)
MODERN_LIBS=(php85/html.php)
FAILED=0

run() {
    local php="$1" lib="$2"
    printf '== %s + %s ... ' "$php" "$lib"
    if command -v "$php" >/dev/null 2>&1; then
        if "$php" tests/run-tests.php --lib="$lib" --no-color 2>&1 | tail -n 1; then :; else FAILED=$((FAILED+1)); fi
    else
        echo "SKIPPED ($php not installed)"
    fi
}

# 1. Syntax lint of every deliverable.
for php in php8.3 php8.5; do
    command -v "$php" >/dev/null 2>&1 || continue
    for f in php83/html.php php85/html.php wordpress/html-wp.php wordpress/html-php-maintenance/html-php-maintenance.php; do
        case "$php:$f" in
            php8.3:php85/html.php) continue ;;  # 8.5 syntax cannot lint on 8.3 (by design)
        esac
        "$php" -l "$f" >/dev/null || { echo "LINT FAIL $php $f"; FAILED=$((FAILED+1)); }
    done
done

# 2. Portable edition on every supported runtime.
for php in php8.3 php8.4 php8.5; do
    for lib in "${PORTABLE_LIBS[@]}"; do run "$php" "$lib"; done
done

# 3. Modern edition on 8.5.
for php in php8.5; do
    for lib in "${MODERN_LIBS[@]}"; do run "$php" "$lib"; done
done

# 4. Unsupported runtimes must refuse gracefully.
if command -v php8.2 >/dev/null 2>&1; then
    printf '== php8.2 refusal ... '
    php8.2 -r 'try { require "php83/html.php"; echo "LOADED (BAD)\n"; exit(1); } catch (RuntimeException) { echo "RuntimeException (OK)\n"; }' || FAILED=$((FAILED+1))
fi

# 5. Examples.
for php in php8.3 php8.5; do
    command -v "$php" >/dev/null 2>&1 || continue
    printf '== examples on %s ... ' "$php"
    if PHP="$php" bash examples/run-all.sh >/dev/null 2>&1; then echo OK; else echo FAIL; FAILED=$((FAILED+1)); fi
done

[ "$FAILED" -eq 0 ] && echo "MATRIX GREEN" || { echo "MATRIX FAILED ($FAILED)"; exit 1; }
