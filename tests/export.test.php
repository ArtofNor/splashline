<?php
declare(strict_types=1);

use Scriptwriter\ComicExporter;
use Scriptwriter\ComicParser;

$number = fn (string $src): string => (new ComicExporter())->numbered($src);
$parse = fn (string $src): array => (new ComicParser())->parse($src);

// --- Filling in the numbers ---------------------------------------------------
$src = "# EXT. MARKET - NIGHT\n\n## She runs.\n\nNOI:\tGo!\n\n## He follows.\n\n# INT. STALL - DAY\n\n## Quiet.\n";
$out = $number($src);
check(str_contains($out, '# PAGE 1 - EXT. MARKET - NIGHT'), 'page numbered before its slug');
check(str_contains($out, '# PAGE 2 - INT. STALL - DAY'), 'second page counts on');
check(str_contains($out, '## **PANEL 1:** She runs.'), 'panel labelled in house style');
check(str_contains($out, '## **PANEL 2:** He follows.'), 'panels count within a page');
check(substr_count($out, '**PANEL 1:**') === 2, 'panels restart on each page');
check(str_contains($out, "NOI:\tGo!"), 'beats pass through untouched');

// A page with no slug still gets its number, and no trailing separator.
check(str_contains($number("#\n\n## x\n"), "# PAGE 1\n"), 'bare page numbered without a dash');

// [SPREAD] rides at the end of the slug, so prefixing must not disturb it.
check(str_contains($number("# EXT. CANAL - NIGHT [SPREAD]\n\n## x\n"), '# PAGE 1 - EXT. CANAL - NIGHT [SPREAD]'), '[SPREAD] stays put');

// --- Never renumbering the writer --------------------------------------------
$own = "# PAGE THREE: THE CHASE\n\n## **PANEL 2A:** an insert.\n\n## And then.\n";
$out = $number($own);
check(str_contains($out, '# PAGE THREE: THE CHASE'), "a page the writer numbered is left alone");
check(str_contains($out, '## **PANEL 2A:** an insert.'), 'an insert label is left alone');
// An insert still occupies a slot, so the panel after it is the second panel
// on the page — which is exactly what Preview prints for the same script.
// The number in "2A" is deliberately not read; the position is what counts.
check(str_contains($out, '## **PANEL 2:** And then.'), 'an insert holds its place in the count');
check(str_contains($number("# PAGE 7 - A\n\n## x\n\n# B\n\n## y\n"), '# PAGE 8 - B'), "the writer's number is adopted, not ignored");

// The mixed case the reference script exercises: bare panels among labelled
// ones fill their own slot rather than restarting.
$mixed = "# A\n\n## **PANEL 1:** one.\n\n## two.\n\n## **PANEL 3:** three.\n";
check(str_contains($number($mixed), '## **PANEL 2:** two.'), 'a bare panel takes the slot between two labelled ones');

// --- Leaving everything else alone -------------------------------------------
$src = "Title: Thing\n\nSome preamble prose.\n\n# A\n\n[a note]\n\n## desc\ncontinued desc\n\n### NOI:\tspoken\n\nplain continuation\n";
$out = $number($src);
foreach (['Title: Thing', 'Some preamble prose.', '[a note]', 'continued desc', "### NOI:\tspoken", 'plain continuation'] as $kept) {
    check(str_contains($out, $kept), 'passes through: ' . $kept);
}
// A "##" above the first page isn't a panel, so it must not be labelled.
check(str_contains($number("## not a panel\n\n# A\n\n## real\n"), "## not a panel\n"), 'a panel with no page above it is left alone');

// --- Properties ---------------------------------------------------------------
$src = "# A\n\n## one.\n\nNOI:\tHi\n\n# B\n\n## two.\n";
$once = $number($src);
check($number($once) === $once, 'numbering twice changes nothing the second time');

// The export must parse to the same script it came from — same pages, same
// panels, same beats. Only the labels are new.
$strip = function (array $t): array {
    foreach ($t['pages'] as &$pg) {
        $pg['slug'] = '';
        foreach ($pg['panels'] as &$pn) {
            $pn['label'] = null;
        }
    }
    return $t;
};
check($strip($parse($src)) === $strip($parse($once)), 'structure survives the round trip');

// CRLF in, CRLF out.
check(str_contains($number("# A\r\n\r\n## x\r\n"), "\r\n"), 'CRLF line endings preserved');
