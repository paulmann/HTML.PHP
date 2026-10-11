#!/usr/bin/env bash
# Runs PHPStan (level 5 per phpstan.neon) over the deliverables.
# Downloads phpstan.phar into tools/ on first use.
set -eu
cd "$(dirname "$0")/.."
PHP="${PHP:-php}"
PHAR="tools/phpstan.phar"
if [ ! -f "$PHAR" ]; then
    echo "Downloading phpstan.phar ..."
    curl -sSLo "$PHAR" https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar
fi
"$PHP" -d memory_limit=1G "$PHAR" analyse --configuration=phpstan.neon "$@"
