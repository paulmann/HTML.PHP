#!/usr/bin/env bash
# Full support matrix. Usage: bash tools/run-matrix.sh
set -u
cd "$(dirname "$0")/.."
status=0

run() {
    local php="$1"; shift
    if ! command -v "$php" >/dev/null 2>&1; then
        echo "SKIP  $php (not installed)"
        return
    fi
    echo "== $php $* =="
    "$php" tests/run.php "$@" || status=1
}

# Rebuild bundles so the matrix always tests the current source.
php8.3 tools/build.php || php tools/build.php || exit 1

run php8.3
run php8.3 --lib=php83/html.php
run php8.4
run php8.4 --lib=php83/html.php
run php8.5
run php8.5 --lib=php83/html.php
run php8.5 --lib=php85/html.php

exit $status
