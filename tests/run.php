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

echo "\n{$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);
