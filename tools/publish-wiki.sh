#!/usr/bin/env bash
# Publishes wiki/*.md to the GitHub wiki repository.
# Dry-run by default; pass --push to actually publish.
#
#   bash tools/publish-wiki.sh          # shows what would change
#   bash tools/publish-wiki.sh --push   # clones, syncs, commits, pushes
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$PWD"
WIKI_URL="${WIKI_URL:-https://github.com/paulmann/HTML.PHP.wiki.git}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PUSH=0
for arg in "$@"; do
    [[ "$arg" == "--push" ]] && PUSH=1
done

# Source of the wiki pages: WIKI_SRC, or a local wiki/ mirror when kept.
# Without either, the wiki repository clone IS the source — edit it there.
WIKI_SRC="${WIKI_SRC:-$ROOT/wiki}"

# 1. Link integrity gate — never publish broken navigation.
php8.3 tools/check-wiki-links.php || php tools/check-wiki-links.php

# 2. Refuse to publish from a dirty tree (keeps wiki commits attributable).
if [[ -d .git && -n "$(git status --porcelain wiki/ 2>/dev/null)" ]]; then
    echo "wiki/ has uncommitted changes — commit them first." >&2
    exit 1
fi

git clone --depth 1 "$WIKI_URL" "$WORK/wiki" 2>/dev/null || {
    # First publish: the wiki repo does not exist until created in the UI.
    mkdir -p "$WORK/wiki"
    git -C "$WORK/wiki" init -q
    git -C "$WORK/wiki" remote add origin "$WIKI_URL"
    echo "# HTML.PHP Wiki" > "$WORK/wiki/Home.md"
}

if [[ ! -d "$WIKI_SRC" ]]; then
    echo "No wiki source directory ('$WIKI_SRC')." >&2
    echo "Set WIKI_SRC=/path/to/wiki-pages, or edit the wiki clone directly." >&2
    exit 2
fi

rsync -a --delete --exclude 'README.md' "$WIKI_SRC/" "$WORK/wiki/"

cd "$WORK/wiki"
git add -A
if git diff --cached --quiet; then
    echo "Wiki already up to date."
    exit 0
fi

git -c user.name="${GIT_AUTHOR_NAME:-htmlphp-bot}" \
    -c user.email="${GIT_AUTHOR_EMAIL:-bot@users.noreply.github.com}" \
    commit -q -m "wiki: sync from html-php/wiki @ $(cd "$ROOT" && git rev-parse --short HEAD 2>/dev/null || echo dev)"

if [[ "$PUSH" == "1" ]]; then
    branch="$(git symbolic-ref --short HEAD 2>/dev/null || echo master)"
    git push -q origin "HEAD:$branch"
    echo "Published to $WIKI_URL ($branch)."
else
    echo "Dry run — commit prepared but not pushed. Re-run with --push."
    git show --stat HEAD | head -20
fi
