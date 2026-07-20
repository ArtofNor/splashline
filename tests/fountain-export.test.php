<?php
declare(strict_types=1);

use Scriptwriter\FountainExporter;
use Scriptwriter\FountainParser;

$number = fn (string $src): string => (new FountainExporter())->numbered($src);
$parse = fn (string $src): array => (new FountainParser())->parse($src);

$body = "\n\nA thing happens.\n\n";

// --- Numbering scenes ---------------------------------------------------------
$src = "INT. HOUSE - DAY{$body}EXT. STREET - NIGHT{$body}";
$out = $number($src);
check(str_contains($out, 'INT. HOUSE - DAY #1#'), 'first scene numbered');
check(str_contains($out, 'EXT. STREET - NIGHT #2#'), 'second scene counts on');
check(str_contains($out, 'A thing happens.'), 'action passes through');

// Every prefix the parser accepts, plus a forced heading.
foreach (['INT.', 'EXT.', 'EST.', 'INT./EXT.', 'I/E'] as $i => $prefix) {
    check(str_contains($number("{$prefix} SOMEWHERE - DAY{$body}"), "{$prefix} SOMEWHERE - DAY #1#"), "numbers a {$prefix} heading");
}
check(str_contains($number(".A TITLE CARD{$body}"), '.A TITLE CARD #1#'), 'forced "." heading numbered');

// --- Leaving non-scenes alone -------------------------------------------------
// A title page key must not read as a scene, even when it looks like one.
$titled = "Title: INT. THE MIND\nAuthor: A. N. Author\n\nINT. HOUSE - DAY{$body}";
$out = $number($titled);
check(str_contains($out, "Title: INT. THE MIND\n"), 'a title-page key is not a scene');
check(str_contains($out, 'INT. HOUSE - DAY #1#'), 'and the real first scene is still #1');

// A prefix mid-paragraph isn't a heading: the parser needs a blank line above.
$mid = "INT. HOUSE - DAY{$body}She said EXT. was a strange word.\nINT. not a heading either.\n";
$out = $number($mid);
check(!str_contains($out, 'strange word. #'), 'no number on a line that only mentions EXT.');
check(!str_contains($out, 'either. #'), 'a heading needs a blank line above it');

foreach (['CHARACTER', '> FADE OUT:', '= a synopsis', '# a section', '~ a lyric'] as $other) {
    check(!str_contains($number("{$other}{$body}"), '#1#'), 'not numbered: ' . $other);
}

// --- Never renumbering the writer --------------------------------------------
$own = "INT. HOUSE - DAY #7#{$body}EXT. STREET - NIGHT{$body}";
$out = $number($own);
check(str_contains($out, 'INT. HOUSE - DAY #7#'), "a scene the writer numbered is left alone");
check(str_contains($out, 'EXT. STREET - NIGHT #8#'), 'and the next one counts on from it');
check(substr_count($out, '#7#') === 1, 'no second number added beside it');

// Revision-style numbers keep their place without dictating the count.
$out = $number("INT. A - DAY #1A#{$body}INT. B - DAY{$body}");
check(str_contains($out, 'INT. A - DAY #1A#'), 'a revision number is preserved');

// --- Properties ---------------------------------------------------------------
$src = "Title: Thing\n\nINT. HOUSE - DAY{$body}EXT. STREET - NIGHT{$body}";
$once = $number($src);
check($number($once) === $once, 'numbering twice changes nothing the second time');
check(str_contains($number("INT. A - DAY\r\n\r\nAction.\r\n"), "\r\n"), 'CRLF preserved');

// The numbered export must parse to the same script, the numbers aside.
$strip = fn (array $t): array => array_map(function (array $tok): array {
    unset($tok['number']);
    return $tok;
}, $t['tokens']);
check($strip($parse($src)) === $strip($parse($once)), 'structure survives the round trip');

// --- Parsing them back --------------------------------------------------------
$t = $parse("INT. HOUSE - DAY #1#{$body}");
$scene = $t['tokens'][0];
check($scene['type'] === 'scene_heading', 'a numbered heading is still a heading');
check($scene['text'] === 'INT. HOUSE - DAY', 'the number is lifted out of the slug');
check($scene['number'] === '1', 'and kept alongside it');
check($parse("INT. HOUSE - DAY{$body}")['tokens'][0]['number'] === null, 'an unnumbered heading has no number');
// A '#' inside the heading is not a scene number.
check($parse("INT. STUDIO #4 - DAY{$body}")['tokens'][0]['number'] === null, 'an unpaired # is not a scene number');
