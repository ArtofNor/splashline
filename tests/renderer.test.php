<?php
declare(strict_types=1);

use Scriptwriter\FountainParser;
use Scriptwriter\Renderer;

$render = fn (string $src): string => (new Renderer())->toHtml((new FountainParser())->parse($src));

// --- Emphasis and notes ------------------------------------------------------
$html = $render("He was *quiet* and **firm** and _sure_ [[fix later]] of it.\n");
check(str_contains($html, '<em>quiet</em>'), 'italic emphasis');
check(str_contains($html, '<strong>firm</strong>'), 'bold emphasis');
check(str_contains($html, '<u>sure</u>'), 'underline emphasis');
check(!str_contains($html, 'fix later'), 'notes stripped from output');

// --- Sections shown dimmed, synopses hidden ----------------------------------
$html = $render("# Act One\n\n= Secret plan.\n\nAction.\n");
check(str_contains($html, 'class="ln section"'), 'sections render (dimmed)');
check(!str_contains($html, 'Secret plan'), 'synopses hidden');

// --- Escaping ----------------------------------------------------------------
$html = $render("Action with <script>alert(1)</script> tags.\n");
check(!str_contains($html, '<script>alert'), 'HTML escaped');

// --- Pagination: exact fill --------------------------------------------------
$html = $render(str_repeat("Action line.\n", 54));   // 54 one-line actions, no blanks
check(page_count($html) === 1, '54 lines fit one page exactly');
$html = $render(str_repeat("Action line.\n", 55));
check(page_count($html) === 2, '55th line starts page two');

// --- Pagination: forced page break -------------------------------------------
$html = $render("One.\n\n===\n\nTwo.\n");
check(page_count($html) === 2, '=== forces a page break');

// --- Widow/orphan: heading never stranded ------------------------------------
$src = str_repeat("Filler.\n", 53) . "\nINT. LATE - NIGHT\n\nAction.\n";
$html = $render($src);
$pages = explode('class="page sheet"', $html);
check(!str_contains($pages[1], 'INT. LATE'), 'heading pushed off page bottom');
check(str_contains($pages[2] ?? '', 'INT. LATE'), 'heading opens the next page');

// --- Dual dialogue: side-by-side, atomic -------------------------------------
$dual = "SARA\nI said now.\n\nMARCUS ^\nAnd I said no.\n";
$html = $render($dual);
check(substr_count($html, 'class="dual-row"') === 1, 'dual pair renders one row');
check(preg_match('/dual-col.*SARA.*dual-col.*MARCUS/s', $html) === 1, 'both cues inside columns');
check(!str_contains($html, 'ln blank"></div>' . "\n" . '<div class="dual-row'), 'no stray blank before the pair');

// A dual pair near the page bottom moves to the next page whole.
$src = str_repeat("Filler.\n", 52) . "\n" . $dual;
$html = $render($src);
$pages = explode('class="page sheet"', $html);
check(page_count($html) === 2, 'dual pair breaks to page two');
check(!str_contains($pages[1], 'dual-row') && str_contains($pages[2] ?? '', 'dual-row'), 'pair never splits across pages');

// A dual cue with nothing before it degrades to a normal speech.
$html = $render("MARCUS ^\nAlone.\n");
check(!str_contains($html, 'dual-row'), 'unpaired dual cue renders normally');
