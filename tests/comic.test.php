<?php
declare(strict_types=1);

use Scriptwriter\ComicParser;
use Scriptwriter\ComicRenderer;
use Scriptwriter\Support;

$parse = fn (string $src): array => (new ComicParser())->parse($src);
$render = fn (string $src): string => (new ComicRenderer())->toHtml((new ComicParser())->parse($src));

$PANEL = "\n\n## **PANEL 1:** A thing happens.\n\n";

// --- Pages -------------------------------------------------------------------
$r = $parse("# EXT. HIGHWAY - DAY [SPREAD]{$PANEL}# EXT. HIGHWAY - CONTINUOUS{$PANEL}#{$PANEL}");
check(count($r['pages']) === 3, 'three pages');
check($r['pages'][0]['spread'] === true && $r['pages'][0]['slug'] === 'EXT. HIGHWAY - DAY', '[SPREAD] flag stripped from slug');
check($r['pages'][1]['continuous'] === true, 'CONTINUOUS detected');
check($r['pages'][2]['slug'] === '', 'bare # page has empty slug');

$html = $render("#{$PANEL}#{$PANEL}");
check(preg_match_all('/cp-no">PAGE (\d+)</', $html, $m) === 2 && $m[1] === ['1', '2'], 'bare pages auto-number');

// Writer-numbered labels anchor and continue; breaks get flagged.
$html = $render("# PAGE 22{$PANEL}#{$PANEL}");
check(str_contains($html, 'PAGE 22') && str_contains($html, 'cp-no">PAGE 23<'), 'excerpt anchors, unlabeled continues');
check(!str_contains($html, 'cp-warn'), 'anchor itself not flagged');
$html = $render("# PAGE ONE{$PANEL}# PAGE 5{$PANEL}# PAGE 6{$PANEL}");
check(substr_count($html, 'cp-warn') === 1 && str_contains($html, 'expected PAGE 2'), 'sequence break flagged once, then adopted');

// --- Panels ------------------------------------------------------------------
$r = $parse("# PAGE\n\n## SILENT. He kisses her.\n\n## **PANEL 5:** Mislabeled.\n\n## **PANEL 2A:** Insert.\n");
check($r['pages'][0]['panels'][0]['label'] === null, 'unlabeled panel has null label');
$html = $render("# PAGE\n\n## SILENT. He kisses her.\n\n## **PANEL 5:** Mislabeled.\n\n## **PANEL 2A:** Insert.\n");
check(str_contains($html, 'panel-label">PANEL 1:'), 'unlabeled panel auto-numbers');
check(str_contains($html, 'expected PANEL 2'), 'mislabeled panel flagged');
check(!str_contains($html, 'expected PANEL 3'), 'insert label 2A not flagged');

// Panel-type badges consume the keyword and keep placement notes.
$html = $render("# PAGE\n\n## INSET (bottom right). SILENT. His hand on the key.\n");
check(str_contains($html, 'p-badge">INSET (BOTTOM RIGHT)</span>'), 'placement travels into badge');
check(str_contains($html, 'p-badge">SILENT</span>'), 'chained keyword badged');
check(str_contains($html, 'His hand on the key.') && !str_contains($html, 'SILENT. His hand'), 'keywords stripped from text');

// --- Beats -------------------------------------------------------------------
$src = "# PAGE\n\n## **PANEL 1:** One.\n\n### NOI (WHISPER):\tFirst line.\n\nSecond paragraph.\n\n### SFX:\tKRAK\n\n## **PANEL 2:** Two.\n\n### CAPTION (NOI):\tLater.\n\n# PAGE TWO\n\n## **PANEL 1:** Next page.\n\n### BO:\tHello.\n";
$r = $parse($src);
$b = $r['pages'][0]['panels'][0]['beats'][0];
check($b['tag'] === 'NOI' && $b['ext'] === 'WHISPER', 'balloon-style extension split from tag');
check(str_contains($b['text'], "First line.\n\nSecond paragraph."), 'multiline speech keeps paragraphs');
check($r['pages'][0]['panels'][0]['beats'][1]['type'] === 'sfx', 'SFX type');
$cap = $r['pages'][0]['panels'][1]['beats'][0];
check($cap['type'] === 'caption' && $cap['ext'] === 'NOI', 'attributed caption');

$html = $render($src);
check(preg_match_all('/beat-no">(\d+)\./', $html, $m) === 4 && $m[1] === ['1', '2', '3', '1'], 'balloon numbering resets per page');
check(str_contains($html, 'beat-ext">(WHISPER)'), 'extension rendered muted in cue');

// --- Bare cues ----------------------------------------------------------------
// Dialogue is the most frequent line in a comic script, so a caps cue opens a
// beat on its own. The ### form stays valid and parses identically.
$bare = $parse("# PAGE\n\n## **PANEL 1:** One.\n\nNOI (WHISPER):\tFirst line.\n\nSecond paragraph.\n\nSFX:\tKRAK\n");
$long = $parse("# PAGE\n\n## **PANEL 1:** One.\n\n### NOI (WHISPER):\tFirst line.\n\nSecond paragraph.\n\n### SFX:\tKRAK\n");
check($bare === $long, 'bare cue parses identically to the ### form');
$b = $bare['pages'][0]['panels'][0]['beats'][0];
check($b['tag'] === 'NOI' && $b['ext'] === 'WHISPER', 'bare cue splits extension from tag');
check(str_contains($b['text'], "First line.\n\nSecond paragraph."), 'speech after a bare cue still runs multiline');

// What the caps test is for: prose with a colon is description, not a balloon.
$r = $parse("# PAGE\n\n## **PANEL 1:** One.\n\nThe convention: a caps keyword at the START.\n");
check($r['pages'][0]['panels'][0]['beats'] === [], 'lowercase prose with a colon is not a cue');
check(str_contains($r['pages'][0]['panels'][0]['desc'], 'The convention:'), 'that prose joins the description');

// Panel-type keywords end in a period, so they are never read as cues.
$r = $parse("# PAGE\n\n## SPLASH. The market.\n\nBIG PANEL. She skids to a stop.\n");
check($r['pages'][0]['panels'][0]['beats'] === [], 'caps keyword with a period is not a cue');

// Only inside a panel: the cover's "Title:" and page-level lines stay put.
$r = $parse("TITLE:\tNight Market\n\n# PAGE\n\nNOI:\tToo early.\n\n## **PANEL 1:** One.\n");
check($r['preamble'] === ["TITLE:\tNight Market"], 'caps cue above the first page stays preamble');
check($r['pages'][0]['notes'] === ["NOI:\tToo early."], 'caps cue above the first panel stays a page note');
check($r['pages'][0]['panels'][0]['beats'] === [], 'and does not leak into the panel');

// --- Cover -------------------------------------------------------------------
$html = $render("Title: Night Market\nWriter: A. Writer\nContact: x@example.com\n\nThe plan: three pages of chase.\n\n# PAGE{$PANEL}");
check(str_contains($html, 'cc-title">Night Market'), 'cover title');
check(preg_match('/cc-footer.*x@example\.com/s', $html) === 1, 'contact in footer');
check(str_contains($html, 'cc-note">The plan:'), 'free text with colon stays prose');

// A new script opens on an unfilled title stub; empty credits print nothing
// rather than turning up as prose on the cover.
$html = $render("Title: \nCredit: written by\nAuthor: \nContact: \n\n# PAGE{$PANEL}");
check(!str_contains($html, 'cc-note'), 'unfilled credit keys do not render as notes');
check(!str_contains($html, 'cc-title'), 'and an empty Title prints no heading');
check(str_contains($html, 'written by'), 'the filled one still renders');

// Everyone who draws the book is a credit, not a footnote: the creative roles
// render in the main block, production contacts drop to the footer.
$html = $render("Title: Vongfai\nIllustrator: Jane\nPenciller: Ana\nInker: Pat\nDesigner: Kit\nTranslator: Vong\nEditor: Bo\n\n# PAGE{$PANEL}");
$main = substr($html, 0, strpos($html, 'cc-footer') ?: strlen($html));
foreach (['Illustrator', 'Penciller', 'Inker', 'Designer', 'Translator'] as $role) {
    check(str_contains($main, 'cc-key">' . $role . '</span>'), strtolower($role) . ' credited in the main block');
}
check(!str_contains($html, 'cc-note'), 'no creative role falls through to prose');
check(str_contains(substr($html, strpos($html, 'cc-footer') ?: 0), 'Editor'), 'editor stays in the footer');

// --- Detection / Support ------------------------------------------------------
check(Support::sniffComic("# INT. GYM - NIGHT\n") === true, 'H1 slug sniffs comic');
check(Support::sniffComic("## **PANEL 1:** x\n") === true, 'panel label sniffs comic');
check(Support::sniffComic("INT. GYM - NIGHT\n\nAction.\n") === null, 'screenplay sniffs neutral');
check(Support::isComicFile('anything.fountain', "### NOI:\tHi\n") === true, 'content beats extension');
check(Support::safeFilename("Act 1: Dawn") === 'Act-1-Dawn.fountain', 'colon title sanitised');
check(Support::safeFilename("Night's End", true) === 'Nights-End.md', 'comic default extension');
check(Support::safeFilename('!!!') === null, 'punctuation-only title rejected');

// --- Inline emphasis ----------------------------------------------------------
// The same asterisks the screenplay renderer reads, so a writer moving between
// the two formats doesn't have to learn a second set of marks.
$html = $render("# A\n\n## He was *quiet* and **firm** and ***both***.\n");
check(str_contains($html, '<em>quiet</em>'), 'italic emphasis');
check(str_contains($html, '<strong>firm</strong>'), 'bold emphasis');
check(str_contains($html, '<strong><em>both</em></strong>'), 'bold-italic nests properly');

// --- The page number, carried on the sheet ------------------------------------
// A thumbnail or an outline shouldn't have to re-derive this: working it out
// means knowing that the writer's first label anchors the count.
$html = $render("# PAGE 22 - EXT. ROOF - NIGHT\n\n## A panel.\n\n# EXT. STREET - NIGHT\n\n## Another.\n");
preg_match_all('/data-page="(\d+)"/', $html, $m);
check($m[1] === ['22', '23'], "data-page follows the writer's anchor");
check(str_contains($html, '<span class="cp-no">PAGE 23</span>'), 'and agrees with the printed heading');

// --- Page sides and spreads ----------------------------------------------------
// Page 1 is a right-hand page, so odd is right and even is left — following
// the writer's anchor, so an excerpt starting at PAGE 22 starts on the left.
$sides = function (string $html): array {
    preg_match_all('/data-side="(\w+)"/', $html, $m);
    return $m[1];
};
$html = $render("#{$PANEL}#{$PANEL}#{$PANEL}");
check($sides($html) === ['right', 'left', 'right'], 'a plain script starts on a right page and alternates');
check(str_contains($html, 'cp-no">PAGE 1</span><span class="cp-side">RIGHT</span>'), 'the side shows beside the page number');
$html = $render("# PAGE 22{$PANEL}#{$PANEL}");
check($sides($html) === ['left', 'right'], 'an excerpt anchored at an even page starts on the left');
check(str_contains($html, 'cp-side">LEFT</span>'), 'and says so on a writer-labelled page too');

// A spread on a left page: two numbers, no flag, and the next page is 18.
$html = $render("# PAGE 15{$PANEL}# EXT. ROOF [SPREAD]{$PANEL}#{$PANEL}");
check(str_contains($html, 'data-page="16" data-page-end="17" data-side="left"'), 'a spread carries both of its numbers');
check(str_contains($html, 'cp-no">PAGES 16–17</span>'), 'and its header reads PAGES 16–17');
check(str_contains($html, 'data-page="18" data-side="left"') && str_contains($html, 'cp-no">PAGE 18</span>'), 'the page after a spread is 18');
check(!str_contains($html, 'Spread starts on a right page'), 'a spread on a left page is not flagged');
check(substr_count($html, 'data-page-end') === 1, 'only the spread carries an end page');

// A spread on a right page: flagged, and still counted as two.
$html = $render("# EXT. ROOF [SPREAD]{$PANEL}#{$PANEL}");
check(str_contains($html, 'data-page="1" data-page-end="2" data-side="right"'), 'a spread from page 1 starts on the right');
check(substr_count($html, 'Spread starts on a right page') === 1, 'a spread on a right page is flagged once');
check(str_contains($html, 'cp-no">PAGE 3</span>'), 'and the page after it is still 3');

// The out-of-sequence check expects two pages on after a spread.
$html = $render("# PAGE 16 [SPREAD]{$PANEL}# PAGE 18{$PANEL}");
check(!str_contains($html, 'expected PAGE'), 'a label two on from a spread is in sequence');
$html = $render("# PAGE 16 [SPREAD]{$PANEL}# PAGE 17{$PANEL}");
check(str_contains($html, 'expected PAGE 18'), 'a label one on from a spread is flagged');

// Stats count printed pages: a spread adds two.
$html = $render("# A [SPREAD]{$PANEL}#{$PANEL}");
check(str_contains($html, 'comic-stats">3 pages · 2 panels'), 'stats count a spread as two pages');

check(Support::isSpread('EXT. ROOF [spread] ') && !Support::isSpread('EXT. [SPREAD] ROOF'), '[SPREAD] only counts at the end of the slug');
check(Support::pageLabelNumber('PAGES 16–17 - EXT. ROOF') === 16, 'an en-dash page range reads as its first page');

// --- Crossing panels and splash checks -----------------------------------------
// ACROSS. runs a panel over the fold, so it belongs on a spread. The panel is
// marked for a page-turn view to draw across both halves.
$html = $render("# EXT. HIGHWAY [SPREAD]\n\n## ACROSS. The car on the highway.\n\n## INSET. The driver.\n");
check(str_contains($html, 'p-badge">ACROSS</span>'), 'ACROSS gets a badge');
check(substr_count($html, 'data-across') === 1 && str_contains($html, '<div class="panel" data-across>'), 'the crossing panel is marked on the sheet');
check(!str_contains($html, 'crosses the fold'), 'a crossing panel on a spread is not flagged');
$html = $render("# EXT. HIGHWAY\n\n## ACROSS. The car on the highway.\n");
check(str_contains($html, "This panel crosses the fold, but the page isn&#039;t a spread"), 'a crossing panel on a single page is flagged');

// A splash is the whole page; an INSET sits on it rather than beside it.
$html = $render("# A\n\n## SPLASH. The market.\n");
check(!str_contains($html, 'Splash on a page'), 'a splash alone is not flagged');
$html = $render("# A\n\n## SPLASH. The market.\n\n## INSET (bottom right). Her hand.\n");
check(!str_contains($html, 'Splash on a page'), 'a splash with an inset is not flagged');
$html = $render("# A\n\n## SPLASH. The market.\n\n## The guards.\n");
check(substr_count($html, 'Splash on a page with other panels') === 1, 'a splash with other panels is flagged once');
$html = $render("# A\n\n## SPLASH SPREAD. The canal.\n");
check(str_contains($html, 'Splash spread on a single page'), 'a splash spread on a single page is flagged');
check(!str_contains($html, 'Splash on a page'), 'and is not mistaken for a plain splash');
$html = $render("# A [SPREAD]\n\n## SPLASH SPREAD. The canal.\n");
check(!str_contains($html, 'Splash spread on a single page'), 'a splash spread on a spread is not flagged');

// The sample is what a new writer opens first, so it should model the format
// without tripping a single advisory chip.
$html = $render((string) file_get_contents(__DIR__ . '/../scripts/comic-sample.md'));
check(!str_contains($html, 'cp-warn'), 'comic-sample.md trips no advisory chips');

// --- Tiers and the balloon limit -----------------------------------------------
// Consecutive TIER. panels share a row; the sheet numbers rows per page.
$tiersOf = function (string $html): array {
    preg_match_all('/<div class="panel"([^>]*)>/', $html, $m);
    return array_map(fn (string $a): ?string => preg_match('/data-tier="(\d+)"/', $a, $t) ? $t[1] : null, $m[1]);
};
$html = $render("# A\n\n## TIER. Noi looks left.\n\n## TIER. The guard looks right.\n\n## TIER. The cook looks at both.\n\n## The stall collapses.\n");
check($tiersOf($html) === ['1', '1', '1', null], 'three TIER. panels share a tier and the next bare panel has none');
check(substr_count($html, 'p-badge">TIER</span>') === 3, 'TIER gets a badge');
$html = $render("# A\n\n## TIER. One.\n\n## TIER. Two.\n\n## Wide.\n\n## TIER. Three.\n\n## TIER INSET. Four.\n");
check($tiersOf($html) === ['1', '1', null, '2', '2'], 'two tiers on one page get different numbers, an INSET joins its tier');
check($tiersOf($render("# A\n\n## TIER. Alone.\n\n# B\n\n## TIER. New page.\n")) === ['1', '1'], 'a lone TIER. is a tier of one, numbered per page');
$html = $render("# A [SPREAD]\n\n## TIER. ACROSS. Left.\n\n## TIER. Right.\n");
check($tiersOf($html) === ['1', '1'] && substr_count($html, 'data-across') === 1, 'ACROSS rides along without breaking the tier');
check(!str_contains($render("# A\n\n## SPLASH. The market.\n\n## TIER. INSET. Her hand.\n"), 'Splash on a page'), 'a tiered INSET still sits on a splash');

// The chip counts dialogue and captions; SFX keep their balloon numbers but
// don't count toward the limit.
$four = "# A\n\n## Crowded.\n\nNOI:\tHi.\n\nCAPTION:\tLater.\n\nBO:\tYo.\n\nCAPTION (NOI):\tThen.\n";
check(!str_contains($render($four), 'More than 4 balloons'), 'four balloons in a panel are fine');
$html = $render($four . "\nSFX:\tKRAK\n\nSFX:\tWHMP\n");
check(!str_contains($html, 'More than 4 balloons') && str_contains($html, 'beat-no">6.'), 'SFX are numbered but not counted toward the limit');
$html = $render($four . "\nNOI:\tBye.\n");
check(substr_count($html, 'More than 4 balloons in a panel') === 1, 'five dialogue and caption balloons get a chip');

// --- Numbered tiers ------------------------------------------------------------
// "TIER n." lets two rows sit back to back: a 2x2 page is TIER 1 twice, then
// TIER 2 twice. data-tier counts rows in order, never the writer's n.
$rows = function (array $tiers): array {
    $out = [];
    $prev = null;
    foreach ($tiers as $t) {
        if ($t !== null && $t === $prev) {
            $out[count($out) - 1]++;
        } else {
            $out[] = 1;
        }
        $prev = $t;
    }
    return $out;
};
$html = $render("# A\n\n## TIER 1. Noi looks left.\n\n## TIER 1. The guard looks right.\n\n## TIER 2. The cook drops the pot.\n\n## TIER 2. Everyone turns.\n");
check($tiersOf($html) === ['1', '1', '2', '2'], 'a 2x2 page gets two tiers');
check($rows($tiersOf($html)) === [2, 2], 'which group into two rows of two');
check(str_contains($html, 'p-badge">TIER 1</span>') && str_contains($html, 'p-badge">TIER 2</span>'), 'a numbered tier badge shows its number');
check(str_contains($html, '</span> Noi looks left.'), 'the number is not left in the description');
check(!str_contains($html, 'cp-warn'), 'a clean 2x2 trips no chips');

$html = $render("# A\n\n## TIER. One.\n\n## TIER. Two.\n\n## TIER. Three.\n\n## TIER. Four.\n");
check($tiersOf($html) === ['1', '1', '1', '1'] && str_contains($html, 'p-badge">TIER</span>'), 'a bare TIER. run is unchanged');
check($tiersOf($render("# A\n\n## TIER. One.\n\n## TIER. Two.\n\n## TIER 1. Three.\n\n## TIER 1. Four.\n")) === ['1', '1', '2', '2'], 'a bare run then TIER 1 are two rows');

$html = $render("# A\n\n## TIER 1. One.\n\n## TIER 2. Two.\n\n## TIER 1. Three.\n");
check($tiersOf($html) === ['1', '2', '3'], 'TIER 1, TIER 2, TIER 1 are three rows');
check(substr_count($html, 'Tier 1 is split by another tier') === 1
    && strpos($html, 'Tier 1 is split') > strpos($html, 'Two.'), 'the returning tier is flagged once, on itself');
check(!str_contains($render("# A\n\n## TIER 1. One.\n\n## Wide.\n\n## TIER 1. Two.\n"), 'is split'), 'a full-width panel between is not another tier');

$html = $render("# A\n\n## TIER 1 INSET (top left). One.\n\n## TIER 1. WIDE. Two.\n");
check(str_contains($html, 'p-badge">INSET (TOP LEFT)</span>') && str_contains($html, 'p-badge">WIDE</span>'), 'keywords after TIER n still parse');
check($tiersOf($html) === ['1', '1'], 'and the tier still groups');
check($tiersOf($render("# A\n\n## TIER 1. One.\n\n# B\n\n## TIER 1. Two.\n")) === ['1', '1'], 'tier numbers count within their page');
