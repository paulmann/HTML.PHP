#!/usr/bin/env bash
set -e
cd "$(dirname "$0")"
PHP="${1:-php8.3}"
for f in 0*.php; do
    case "$f" in _*) continue;; esac
    echo; echo "########## $f ##########"
    "$PHP" "$f"
done
