<?php
declare(strict_types=1);

/**
 * Splashline test runner. Zero dependencies, like the app:
 *
 *     php tests/run.php
 *
 * Each tests/*.test.php file runs in sequence; check() records a pass or a
 * fail with its message. Exit code 0 = all green.
 */

require __DIR__ . '/../src/FountainParser.php';
require __DIR__ . '/../src/Renderer.php';
require __DIR__ . '/../src/ComicParser.php';
require __DIR__ . '/../src/ComicRenderer.php';
require __DIR__ . '/../src/ComicExporter.php';
require __DIR__ . '/../src/FountainExporter.php';
require __DIR__ . '/../src/Support.php';

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function check(bool $cond, string $msg): void
{
    if ($cond) {
        $GLOBALS['pass']++;
    } else {
        $GLOBALS['fail']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

/** Count pages in rendered screenplay HTML. */
function page_count(string $html): int
{
    return substr_count($html, 'class="page sheet"');
}

foreach (glob(__DIR__ . '/*.test.php') ?: [] as $file) {
    echo '· ', basename($file), "\n";
    require $file;
}

/**
 * The paste formatter is JavaScript, so its tests run under Node and report
 * their counts back on a "#counts pass fail" line. Node is not a dependency of
 * the app and is not required to run it — without it those tests are announced
 * as skipped rather than silently counted as passing, since a suite that goes
 * quiet when a runner is missing is worse than one that admits the gap.
 */
foreach (glob(__DIR__ . '/*.test.js') ?: [] as $file) {
    echo '· ', basename($file);
    $out = [];
    $status = 0;
    exec('command -v node >/dev/null 2>&1 && node ' . escapeshellarg($file) . ' 2>&1', $out, $status);
    $text = implode("\n", $out);

    if (!preg_match('/^#counts (\d+) (\d+)$/m', $text, $m)) {
        echo "  (skipped — needs node)\n";
        continue;
    }
    echo "\n";
    // Everything but the counts line is a failure report; pass it straight on.
    $reported = trim(str_replace($m[0], '', $text));
    if ($reported !== '') {
        fwrite(STDERR, $reported . "\n");
    }
    $GLOBALS['pass'] += (int) $m[1];
    $GLOBALS['fail'] += (int) $m[2];
}

echo "\n{$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);
