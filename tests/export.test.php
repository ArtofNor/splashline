<?php
declare(strict_types=1);

use Scriptwriter\ComicExporter;
use Scriptwriter\ComicParser;
use Scriptwriter\ComicRenderer;

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
check(str_contains($number("# EXT. CANAL - NIGHT [SPREAD]\n\n## x\n"), '# PAGES 1–2 - EXT. CANAL - NIGHT [SPREAD]'), '[SPREAD] stays put');

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

// --- Spreads -------------------------------------------------------------------
// A spread is two printed pages, counted by the same rule as Preview, so the
// page after one starting on 16 is 18 — on a left page and on a right one.
$out = $number("# PAGE 16 - A\n\n## x\n\n# B [SPREAD]\n\n## y\n\n# C\n\n## z\n");
check(str_contains($out, '# PAGES 17–18 - B [SPREAD]'), 'a spread on a right page takes two numbers');
check(str_contains($out, '# PAGE 19 - C'), 'and the page after it counts on from the second');
$out = $number("# A [SPREAD]\n\n## x\n\n# B\n\n## y\n");
check(str_contains($out, '# PAGES 1–2 - A [SPREAD]') && str_contains($out, '# PAGE 3 - B'), 'a spread from page 1 counts the same way');

// The export reads its own spread headings back: "PAGES 16–17" starts at 16,
// and a writer-labelled spread still counts as two.
$out = $number("# PAGES 16-17 - A [SPREAD]\n\n## x\n\n# B\n\n## y\n");
check(str_contains($out, '# PAGES 16-17 - A [SPREAD]') && str_contains($out, '# PAGE 18 - B'), 'a labelled spread is adopted and counts as two');
$once = $number("# PAGE 15 - A\n\n## x\n\n# B [SPREAD]\n\n## y\n\n# C\n\n## z\n");
check($number($once) === $once, 'numbering a spread twice changes nothing');

// The export and Preview agree, and keep agreeing after the export is
// rendered in turn: same data-page, data-page-end and data-side on every sheet.
$sheets = function (string $src): array {
    $html = (new ComicRenderer())->toHtml((new ComicParser())->parse($src));
    preg_match_all('/<section class="comic-page sheet"([^>]*)>/', $html, $m);
    return $m[1];
};
foreach (["# PAGE 22 - A\n\n## x\n\n# B [SPREAD]\n\n## y\n\n# C\n\n## z\n",
          "# A\n\n## x\n\n# B [SPREAD]\n\n## y\n\n# C [SPREAD]\n\n## z\n\n# D\n\n## w\n"] as $i => $src) {
    check($sheets($src) === $sheets($number($src)), 'numbers survive export and re-render, case ' . ($i + 1));
}
