#!/usr/bin/env bash
# Runs every example against the edition matching the invoked PHP binary.
# Usage: php8.3 examples/run-all.sh   |   php8.5 examples/run-all.sh
set -u
PHP="${PHP:-php}"
FAILED=0
for example in "$(dirname "$0")"/0*.php; do
    printf '== %s ... ' "$(basename "$example")"
    if "$PHP" "$example" >/dev/null 2>&1; then
        echo "OK"
    else
        echo "FAIL"
        FAILED=$((FAILED + 1))
    fi
done
[ "$FAILED" -eq 0 ] && echo "All examples passed." || { echo "$FAILED example(s) failed."; exit 1; }
