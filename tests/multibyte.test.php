<?php
declare(strict_types=1);

use Scriptwriter\ComicParser;
use Scriptwriter\ComicRenderer;
use Scriptwriter\FountainParser;
use Scriptwriter\Renderer;
use Scriptwriter\Support;

$render = fn (string $src): string => (new Renderer())->toHtml((new FountainParser())->parse($src));

// A 20-character Lao action line is ~3 bytes/char. Byte-counting would call
// it ~60 columns wide; display-counting keeps it well under one 60-col line.
// 54 such lines must still fit one page.
$lao = 'ນະຄອນຫຼວງວຽງຈັນເປັນເມືອງ';
check(mb_strlen($lao, 'UTF-8') < 30 && strlen($lao) > 60, 'fixture: Lao string is short in chars, long in bytes');
$html = $render(str_repeat($lao . "\n", 54));
check(page_count($html) === 1, 'Lao text paginates by display width, not bytes');

// Combining marks (Lao vowels/tones) take no column.
$plain = 'ກກກກກ';          // 5 base consonants
$marked = 'ກີກີກີກີກີ';    // same 5 with a vowel mark each
$r = new ReflectionMethod(Renderer::class, 'wrappedLines');
$rend = new Renderer();
check($r->invoke($rend, str_repeat($plain . ' ', 12), 60) === $r->invoke($rend, str_repeat($marked . ' ', 12), 60),
    'combining marks add no width');

// Long unbroken words hard-break instead of counting one line.
check($r->invoke($rend, str_repeat('x', 125), 60) === 3, 'long word breaks across lines');
check($r->invoke($rend, 'short words only here', 60) === 1, 'short line counts one');

// Comic balloon word counts are whitespace-based, not ASCII word chars.
$comic = "# ໜ້າໜຶ່ງ\n\n## **PANEL 1:** A test.\n\n### NOI:\t" . str_repeat('ຄຳ ', 26) . "\n";
$html = (new ComicRenderer())->toHtml((new ComicParser())->parse($comic));
check(str_contains($html, 'beat-wc'), '26 Lao words trip the long-balloon flag');
$comic = "# PAGE\n\n## **PANEL 1:** A test.\n\n### NOI:\t" . str_repeat('ຄຳ ', 10) . "\n";
$html = (new ComicRenderer())->toHtml((new ComicParser())->parse($comic));
check(!str_contains($html, 'beat-wc'), '10 words do not');

// Unicode titles survive filename sanitising and path validation.
check(Support::safeFilename('ນິທານພື້ນເມືອງ') === 'ນິທານພື້ນເມືອງ.fountain', 'Lao title becomes a Lao filename');
check(Support::scriptPath('/tmp', 'ນິທານພື້ນເມືອງ.fountain') === '/tmp/ນິທານພື້ນເມືອງ.fountain', 'Lao filename passes path check');
check(Support::scriptPath('/tmp', '../evil.fountain') === '/tmp/evil.fountain', 'traversal stripped by basename');
check(Support::scriptPath('/tmp', 'no.dots.allowed.fountain') === null, 'extra dots rejected');
