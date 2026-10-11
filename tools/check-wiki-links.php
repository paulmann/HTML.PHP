<?php

/**
 * Verifies that every internal link in wiki/*.md resolves to an existing
 * wiki page (GitHub wikis 404 silently on renamed pages). Also checks the
 * three GitHub magic files exist.
 *
 * Usage: php8.3 tools/check-wiki-links.php
 * Exit:  0 clean · 1 broken links
 */

declare(strict_types=1);

$dir = rtrim($argv[1] ?? dirname(__DIR__) . '/wiki', '/\\');

if (!is_dir($dir)) {
    fwrite(STDERR, "Wiki directory not found: {$dir}\n");
    fwrite(STDERR, "Usage: php tools/check-wiki-links.php [wiki-dir]\n");
    exit(2);
}

/**
 * GitHub-style heading slugs for a markdown file (github-slugger semantics:
 * lowercase, strip formatting/emoji/punctuation, spaces to dashes).
 *
 * @return list<string>
 */
function anchors_of(string $file): array
{
    $anchors = [];
    $counts = [];

    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('~^#{1,6}\s+(.+?)\s*$~', $line, $m) !== 1) {
            continue;
        }

        $text = $m[1];
        $text = preg_replace('~`+~', '', $text) ?? $text;          // code ticks
        $text = preg_replace('/\*\*|__|~~/', '', $text) ?? $text; // emphasis
        $text = preg_replace('~\[([^\]]*)\]\([^)]*\)~u', '$1', $text) ?? $text; // links → label

        $slug = mb_strtolower($text, 'UTF-8');
        // keep letters/digits (any script), spaces, dashes, underscores
        $slug = (string) preg_replace('/[^\p{L}\p{N} _-]+/u', '', $slug);
        // emoji & symbols are \p{So}/\p{Cs} — already removed by the class above
        $slug = str_replace(' ', '-', trim($slug));

        // duplicates get -1, -2 … suffixes on GitHub
        $counts[$slug] = ($counts[$slug] ?? 0) + 1;
        $anchors[] = $counts[$slug] > 1 ? $slug . '-' . ($counts[$slug] - 1) : $slug;
    }

    return $anchors;
}

$pages = [];
foreach (glob($dir . '/*.md') ?: [] as $file) {
    $pages[pathinfo($file, PATHINFO_FILENAME)] = true;
}

foreach (['Home', '_Sidebar', '_Footer'] as $magic) {
    if (!isset($pages[$magic])) {
        fwrite(STDERR, "MISSING magic file: {$magic}.md\n");
        exit(1);
    }
}

/**
 * Pages created by the GitHub UI itself (the initial wiki page is named
 * after the repository title). They are not part of the sidebar navigation
 * by design; the list is explicit and documented, not a wildcard.
 */
const GITHUB_UI_ARTIFACTS = ['HTML.PHP-🧩⚡'];

$broken = 0;
$checked = 0;

foreach (array_keys($pages) as $page) {
    $file = $dir . '/' . $page . '.md';
    $source = (string) file_get_contents($file);

    // Markdown links: [text](target) — skip external URLs, mailto, anchors-only.
    if (preg_match_all('~\[[^\]]*\]\(([^)\s]+)\)~', $source, $matches) === false) {
        continue;
    }

    foreach ($matches[1] as $link) {
        if (preg_match('~^(https?:|mailto:|\#)~i', $link) === 1) {
            continue;
        }

        ++$checked;
        [$target, $anchor] = array_pad(explode('#', $link, 2), 2, '');
        $target = rawurldecode($target);

        if ($target === '') {
            // Same-page anchor.
            $name = $page;
        } else {
            $name = preg_replace('~\.md$~', '', $target) ?? $target;
        }

        if (!isset($pages[$name])) {
            printf("BROKEN  %s.md -> %s\n", $page, $link);
            $broken++;
            continue;
        }

        if ($anchor !== '' && !in_array($anchor, anchors_of($dir . '/' . $name . '.md'), true)) {
            printf("ANCHOR  %s.md -> %s (no such heading slug)\n", $page, $link);
            $broken++;
        }
    }

    // Sidebar entries must all resolve too.
    if ($page === '_Sidebar') {
        foreach ($matches[1] as $link) {
            if (preg_match('~^https?:~i', $link) === 1) {
                continue;
            }

            $name = preg_replace('~\.md$~', '', explode('#', $link)[0]) ?? '';

            if ($name !== '' && !isset($pages[$name])) {
                printf("BROKEN (sidebar) -> %s\n", $link);
                $broken++;
            }
        }
    }
}

// Every non-magic page should be reachable from the sidebar.
$sidebar = (string) file_get_contents($dir . '/_Sidebar.md');
$orphaned = [];

foreach (array_keys($pages) as $page) {
    if (in_array($page, ['Home', '_Sidebar', '_Footer', 'README'], true)) {
        continue;
    }

    if (!str_contains($sidebar, '(' . $page) && !str_contains($sidebar, '(' . $page . '.md')) {
        $orphaned[] = $page;
    }
}

foreach ($orphaned as $page) {
    if (in_array($page, GITHUB_UI_ARTIFACTS, true)) {
        continue;
    }

    printf("ORPHAN  %s.md is not linked from _Sidebar.md\n", $page);
    $broken++;
}

printf(
    "%d page(s), %d internal link(s) checked, %d problem(s).\n",
    count($pages),
    $checked,
    $broken,
);

exit($broken === 0 ? 0 : 1);
