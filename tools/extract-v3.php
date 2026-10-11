<?php

/**
 * Splits the v3 single-file library into per-class PSR-4 files under ported/.
 * Raw material for the v4 rewrite — NOT part of the shipped library.
 *
 * Usage: php8.3 tools/extract-v3.php
 */
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/v3-source/php83/html.php');
if ($source === false) {
    fwrite(STDERR, "cannot read v3 source\n");
    exit(1);
}
$lines = explode("\n", $source);
$out = dirname(__DIR__) . '/ported';

/** Find matching closing brace for a block starting at line $i (0-based), given its indent. */
function blockEnd(array $lines, int $i, string $closer): int
{
    for ($j = $i + 1, $n = count($lines); $j < $n; $j++) {
        if (rtrim($lines[$j]) === $closer) {
            return $j;
        }
    }
    return -1;
}

$made = 0;
for ($i = 0, $n = count($lines); $i < $n; $i++) {
    if (!preg_match('/^namespace ([\w\\\\]+) \{$/', $lines[$i], $m)) {
        continue;
    }
    $ns = $m[1];
    $end = blockEnd($lines, $i, '}');
    if ($end < 0) {
        continue;
    }
    // collect `use` statements of the block
    $uses = [];
    for ($j = $i + 1; $j < $end; $j++) {
        if (preg_match('/^use .+;$/', $lines[$j])) {
            $uses[] = $lines[$j];
        }
    }
    // find classes inside the block (4-space indent)
    for ($j = $i + 1; $j < $end; $j++) {
        if (preg_match('/^    (final |abstract )?(class|interface|enum|trait) (\w+)/', $lines[$j], $cm)) {
            $name = $cm[3];
            $cend = blockEnd($lines, $j, '    }');
            if ($cend < 0 || $cend > $end) {
                continue;
            }
            $body = implode("\n", array_slice($lines, $j, $cend - $j + 1));
            $dir = $out . '/' . str_replace('\\', '/', $ns);
            @mkdir($dir, 0777, true);
            $file = $dir . '/' . $name . '.php';
            $head = "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$ns};\n\n"
                . ($uses ? implode("\n", $uses) . "\n\n" : '');
            file_put_contents($file, $head . $body . "\n");
            $made++;
            $j = $cend;
        }
    }
    $i = $end;
}

// trailing global functions (legacy + modern API) for reference
$start = null;
foreach ($lines as $idx => $line) {
    if (str_contains($line, 'LEGACY PUBLIC API') || preg_match('/^    function HTML\(/', $line)) {
        $start = $idx;
        break;
    }
}
if ($start !== null) {
    file_put_contents($out . '/_functions.php', "<?php\n// v3 global function API (reference only)\n"
        . implode("\n", array_slice($lines, $start)));
}

echo "extracted {$made} classes to {$out}\n";
