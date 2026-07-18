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

// --- Cover -------------------------------------------------------------------
$html = $render("Title: Night Market\nWriter: A. Writer\nContact: x@example.com\n\nThe plan: three pages of chase.\n\n# PAGE{$PANEL}");
check(str_contains($html, 'cc-title">Night Market'), 'cover title');
check(preg_match('/cc-footer.*x@example\.com/s', $html) === 1, 'contact in footer');
check(str_contains($html, 'cc-note">The plan:'), 'free text with colon stays prose');

// --- Detection / Support ------------------------------------------------------
check(Support::sniffComic("# INT. GYM - NIGHT\n") === true, 'H1 slug sniffs comic');
check(Support::sniffComic("## **PANEL 1:** x\n") === true, 'panel label sniffs comic');
check(Support::sniffComic("INT. GYM - NIGHT\n\nAction.\n") === null, 'screenplay sniffs neutral');
check(Support::isComicFile('anything.fountain', "### NOI:\tHi\n") === true, 'content beats extension');
check(Support::safeFilename("Act 1: Dawn") === 'Act-1-Dawn.fountain', 'colon title sanitised');
check(Support::safeFilename("Night's End", true) === 'Nights-End.md', 'comic default extension');
check(Support::safeFilename('!!!') === null, 'punctuation-only title rejected');
