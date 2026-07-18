<?php
declare(strict_types=1);

use Scriptwriter\FountainParser;

$p = new FountainParser();

// --- Title page --------------------------------------------------------------
$r = $p->parse("Title: Night's End\nAuthor: A. Writer\n   Second Line\n\nFADE IN.\n");
check(($r['title']['title'][0] ?? '') === "Night's End", 'title page: title parsed');
check(($r['title']['author'][1] ?? '') === 'Second Line', 'title page: indented continuation joins key');
check($r['tokens'][0]['type'] === 'action', 'body starts after title page');

// No title page when first line is not Key: value.
$r = $p->parse("INT. HOUSE - DAY\n\nAction.\n");
check($r['title'] === [], 'no title page detected');
check($r['tokens'][0]['type'] === 'scene_heading', 'unforced scene heading (INT.)');

// --- Element classification --------------------------------------------------
$src = <<<F
INT. KITCHEN - NIGHT

Sara stands at the counter.

SARA
(quietly)
You're late.

MARCUS
The train stopped.

SMASH CUT TO:

.MONTAGE BEGINS

> BURN TO WHITE <

~A lyric line

!CAPS ACTION NOT A CUE

# Act One

= A synopsis line.

===

Text after page break.
F;
$r = $p->parse($src);
$types = array_column($r['tokens'], 'type');
$byType = [];
foreach ($r['tokens'] as $t) {
    $byType[$t['type']][] = $t;
}

check(in_array('scene_heading', $types, true), 'scene heading present');
check(in_array('blank', $types, true), 'blank lines kept as tokens');
check(count($byType['character'] ?? []) === 2, 'two character cues');
check(($byType['parenthetical'][0]['text'] ?? '') === '(quietly)', 'parenthetical');
check(($byType['transition'][0]['text'] ?? '') === 'SMASH CUT TO:', 'unforced TO: transition');
check(($byType['scene_heading'][1]['text'] ?? '') === 'MONTAGE BEGINS', 'forced . scene heading, dot stripped');
check(($byType['centered'][0]['text'] ?? '') === 'BURN TO WHITE', 'centered, markers stripped');
check(($byType['lyric'][0]['text'] ?? '') === 'A lyric line', 'lyric, tilde stripped');
check(($byType['action'] ?? []) !== [] && in_array('CAPS ACTION NOT A CUE', array_column($byType['action'], 'text'), true), 'forced ! action stays action');
check(($byType['section'][0]['depth'] ?? 0) === 1, 'section with depth');
check(($byType['synopsis'][0]['text'] ?? '') === 'A synopsis line.', 'synopsis');
check(in_array('page_break', $types, true), 'page break token');

// --- Dual dialogue flag ------------------------------------------------------
$r = $p->parse("SARA\nLine one.\n\nMARCUS ^\nLine two.\n");
$cues = array_values(array_filter($r['tokens'], fn ($t) => $t['type'] === 'character'));
check(count($cues) === 2 && empty($cues[0]['dual']) && !empty($cues[1]['dual']), 'dual marker parsed, caret stripped');
check($cues[1]['text'] === 'MARCUS', 'dual cue text has no caret');

// --- Forced character, extension ---------------------------------------------
$r = $p->parse("@McCLANE\nYippee.\n\nMAI (V.O.)\nHome.\n");
$cues = array_values(array_filter($r['tokens'], fn ($t) => $t['type'] === 'character'));
check(($cues[0]['text'] ?? '') === 'McCLANE', 'forced @ cue keeps case, marker stripped');
check(($cues[1]['text'] ?? '') === 'MAI (V.O.)', 'cue extension preserved');

// --- Boneyard ----------------------------------------------------------------
$r = $p->parse("Action one.\n/* cut this\nand this */\nAction two.\n");
$texts = array_column(array_filter($r['tokens'], fn ($t) => $t['type'] === 'action'), 'text');
check(!str_contains(implode(' ', $texts), 'cut this'), 'boneyard removed');
