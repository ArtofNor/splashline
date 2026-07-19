<?php
declare(strict_types=1);

namespace Scriptwriter;

/**
 * Renders parsed Fountain tokens to screenplay HTML.
 *
 * The CSS in public/style.css does the industry-standard layout (Courier,
 * fixed margins); this class only maps tokens to semantic markup and handles
 * inline emphasis (*italic*, **bold**, _underline_) and note stripping.
 */
final class Renderer
{
    /**
     * At 12pt Courier (10 chars/inch, 6 lines/inch) on US Letter with a
     * 1in top/bottom margin, the text block is 9in tall = 54 lines, and each
     * element's column width in characters follows from its indents.
     */
    private const LINES_PER_PAGE = 54;

    /** Column width in characters per element (6in text block = 60 chars). */
    private const WIDTHS = [
        'action'        => 60,
        'scene_heading' => 60,
        'transition'    => 60,
        'centered'      => 60,
        'character'     => 38, // 2.2in indent
        'parenthetical' => 44, // 1.6in indent
        'dialogue'      => 35, // 1in indent + 1.5in right margin
        'lyric'         => 50,
    ];

    /**
     * Column widths inside a dual-dialogue column (two ~2.9in columns with a
     * 0.2in gap). Must match the .dual-col CSS indents.
     */
    private const DUAL_WIDTHS = [
        'character'     => 19, // 1in indent inside the column
        'parenthetical' => 22, // 0.7in
        'dialogue'      => 23, // 0.4in left + 0.2in right
    ];

    /**
     * Emits the script as discrete US-Letter pages, each holding the same
     * line-per-line structure the live editor uses — so geometry matches the
     * edit surface, but broken into real sheets. Preview's extras: emphasis is
     * applied, Fountain markup is already stripped by the parser, dual
     * dialogue renders side by side, and outline synopses are private notes —
     * hidden; sections render dimmed so that comics-written-as-screenplays
     * keep their page/panel structure readable.
     *
     * @param array{title: array<string, list<string>>, tokens: list<array<string, mixed>>} $parsed
     */
    public function toHtml(array $parsed): string
    {
        $out = $this->titlePageHtml($parsed['title']);
        $out .= "<div class=\"screenplay\">\n";

        $tokens = $this->annotateDual($parsed['tokens']);

        foreach ($this->paginate($tokens) as $pageNo => $page) {
            $out .= "<div class=\"page sheet\">\n";
            // Industry convention: the title page and first script page are
            // unnumbered; from the second script page the number sits in the
            // top-right margin as "2.".
            if ($pageNo > 0) {
                $out .= '<span class="page-num">' . ($pageNo + 1) . ".</span>\n";
            }
            $n = count($page);
            for ($i = 0; $i < $n; $i++) {
                $t = $page[$i];

                if (isset($t['dualPair'])) {
                    // Collect the whole pair (contiguous within a page) and
                    // emit it as one two-column row.
                    $pair = $t['dualPair'];
                    $cols = ['L' => '', 'R' => ''];
                    while ($i < $n && ($page[$i]['dualPair'] ?? null) === $pair) {
                        $cols[$page[$i]['dualSide']] .= $this->lineHtml($page[$i]);
                        $i++;
                    }
                    $i--;
                    $out .= "<div class=\"dual-row\">"
                        . '<div class="dual-col">' . $cols['L'] . '</div>'
                        . '<div class="dual-col">' . $cols['R'] . '</div>'
                        . "</div>\n";
                    continue;
                }

                $out .= $this->lineHtml($t);
            }
            $out .= "</div>\n";
        }

        $out .= "</div>\n";
        return $out;
    }

    /**
     * The paginated token groups, exactly as toHtml() lays them onto sheets —
     * exposed so a non-paper surface (e.g. a reflowed mobile reading view) can
     * mark real page boundaries without duplicating the pagination math. Each
     * inner list is one page's tokens, in order.
     *
     * @param array{title: array<string, list<string>>, tokens: list<array<string, mixed>>} $parsed
     * @return list<list<array<string, mixed>>>
     */
    public function pages(array $parsed): array
    {
        return $this->paginate($this->annotateDual($parsed['tokens']));
    }

    /** One token as one .ln line div. */
    private function lineHtml(array $t): string
    {
        if ($t['type'] === 'blank') {
            return "<div class=\"ln blank\"></div>\n";
        }
        $cls = str_replace('_', '-', $t['type']);

        return '<div class="ln ' . $cls . '">' . $this->inline((string) $t['text']) . "</div>\n";
    }

    /**
     * Mark dual-dialogue pairs: a character cue flagged with '^' pairs with
     * the speech immediately before it. Both speeches get a shared dualPair
     * id and an L/R side; the blank line(s) between them are marked to drop
     * (the two speeches sit beside each other, not above each other).
     *
     * @param list<array<string, mixed>> $tokens
     * @return list<array<string, mixed>>
     */
    private function annotateDual(array $tokens): array
    {
        $n = count($tokens);
        $pair = 0;

        for ($i = 0; $i < $n; $i++) {
            if ($tokens[$i]['type'] !== 'character' || empty($tokens[$i]['dual'])) {
                continue;
            }

            // The right speech: this cue plus its dialogue lines.
            $rEnd = $i;
            for ($j = $i + 1; $j < $n && in_array($tokens[$j]['type'], ['parenthetical', 'dialogue'], true); $j++) {
                $rEnd = $j;
            }

            // The left speech: scan back over blanks to the previous speech.
            $k = $i - 1;
            $dropped = [];
            while ($k >= 0 && $tokens[$k]['type'] === 'blank') {
                $dropped[] = $k;
                $k--;
            }
            if ($k < 0 || !in_array($tokens[$k]['type'], ['parenthetical', 'dialogue'], true)) {
                continue; // No speech to pair with; render as a normal cue.
            }
            $lEnd = $k;
            while ($k >= 0 && in_array($tokens[$k]['type'], ['parenthetical', 'dialogue'], true)) {
                $k--;
            }
            if ($k < 0 || $tokens[$k]['type'] !== 'character') {
                continue;
            }

            $pair++;
            for ($m = $k; $m <= $lEnd; $m++) {
                $tokens[$m]['dualPair'] = $pair;
                $tokens[$m]['dualSide'] = 'L';
            }
            foreach ($dropped as $d) {
                $tokens[$d]['dualDrop'] = true;
            }
            for ($m = $i; $m <= $rEnd; $m++) {
                $tokens[$m]['dualPair'] = $pair;
                $tokens[$m]['dualSide'] = 'R';
            }
            $i = $rEnd;
        }

        return $tokens;
    }

    /**
     * Split tokens into pages of at most LINES_PER_PAGE visual lines.
     * Widow/orphan control: a scene heading or character cue is pushed to the
     * next page rather than stranded at the bottom with nothing under it.
     * A dual-dialogue pair is atomic: it never splits across a page break.
     * Its height is counted as the sum of both columns (the render needs only
     * the taller column) — deliberately conservative, so pages may run a line
     * or two short but can never overflow.
     *
     * @param list<array<string, mixed>> $tokens
     * @return list<list<array<string, mixed>>>
     */
    private function paginate(array $tokens): array
    {
        $pages = [];
        $page = [];
        $used = 0;

        $break = function () use (&$pages, &$page, &$used): void {
            if ($page !== []) {
                $pages[] = $page;
            }
            $page = [];
            $used = 0;
        };

        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $type = $t['type'];

            if ($type === 'synopsis') {
                continue; // Writer's outline notes — never printed.
            }
            // Sections render dimmed rather than hidden: Fountain says they
            // don't print, but comics-as-screenplay scripts (Johnston style)
            // carry their page/panel structure in sections — hiding them would
            // make those scripts unreadable.
            if ($type === 'page_break') {
                $break();
                continue;
            }
            if (!empty($t['dualDrop'])) {
                continue; // Blank swallowed by a dual-dialogue pairing.
            }

            // A dual pair moves as one unit.
            if (isset($t['dualPair'])) {
                $pairId = $t['dualPair'];
                $unit = [];
                $need = 0;
                for ($j = $i; $j < $n; $j++) {
                    if (!empty($tokens[$j]['dualDrop'])) {
                        continue;
                    }
                    if (($tokens[$j]['dualPair'] ?? null) !== $pairId) {
                        break;
                    }
                    $unit[] = $tokens[$j];
                    $need += $this->visualLines($tokens[$j]);
                }
                $i = $j - 1;

                if ($used > 0 && $used + $need > self::LINES_PER_PAGE) {
                    if (($page[count($page) - 1]['type'] ?? '') === 'blank') {
                        array_pop($page);
                    }
                    $break();
                }
                foreach ($unit as $u) {
                    $page[] = $u;
                }
                $used += $need;
                continue;
            }

            if ($type === 'blank') {
                if ($used === 0) {
                    continue; // No blank leading a page (or doubled by omissions above).
                }
                if (($page[count($page) - 1]['type'] ?? '') === 'blank') {
                    continue;
                }
                $page[] = $t;
                $used++;
                continue;
            }

            $need = $this->visualLines($t);

            // An element that would strand its header at the page bottom, or
            // simply not fit, starts the next page instead.
            $reserve = match ($type) {
                'scene_heading' => 2, // heading + blank + first action line
                'character'     => 2, // cue + first dialogue line
                default         => 0,
            };
            if ($used > 0 && $used + $need + $reserve > self::LINES_PER_PAGE) {
                // Drop a trailing blank so pages never end on empty space.
                if (($page[count($page) - 1]['type'] ?? '') === 'blank') {
                    array_pop($page);
                }
                $break();
            }

            $page[] = $t;
            $used += $need;
        }
        $break();

        return $pages;
    }

    /** How many printed lines a token occupies once wrapped in its column. */
    private function visualLines(array $t): int
    {
        $text = $this->plain((string) ($t['text'] ?? ''));
        if ($text === '') {
            return 1;
        }
        $width = isset($t['dualPair'])
            ? (self::DUAL_WIDTHS[$t['type']] ?? 23)
            : (self::WIDTHS[$t['type']] ?? 60);

        return $this->wrappedLines($text, $width);
    }

    /**
     * Greedy word wrap in display columns, not bytes. PHP's wordwrap counts
     * bytes, which triples the apparent width of UTF-8 text (Lao, Thai, and
     * friends) and paginates it wrongly. Widths here are measured with
     * combining marks removed (Lao vowel and tone marks take no column) via
     * mb_strwidth, which also counts East Asian wide characters as two.
     */
    private function wrappedLines(string $text, int $width): int
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return 1;
        }

        $lines = 1;
        $col = 0;
        foreach ($words as $word) {
            $w = self::displayWidth($word);

            if ($w > $width) {
                // A word longer than the column hard-breaks (the CSS uses
                // overflow-wrap: break-word to match).
                if ($col > 0) {
                    $lines++;
                }
                $full = intdiv($w - 1, $width); // Extra lines beyond the first.
                $lines += $full;
                $col = $w - $full * $width;
                continue;
            }
            if ($col === 0) {
                $col = $w;
            } elseif ($col + 1 + $w <= $width) {
                $col += 1 + $w;
            } else {
                $lines++;
                $col = $w;
            }
        }

        return $lines;
    }

    /** Display columns of a string: combining marks are zero-width. */
    private static function displayWidth(string $s): int
    {
        $s = preg_replace('/[\p{Mn}\p{Me}\p{Cf}]/u', '', $s) ?? $s;

        return mb_strwidth($s, 'UTF-8');
    }

    /** The text as it prints: notes removed, emphasis markers stripped. */
    private function plain(string $text): string
    {
        $text = preg_replace('/\[\[.*?\]\]/s', '', $text) ?? $text;
        $text = preg_replace('/(\*{1,3})(.+?)\1/s', '$2', $text) ?? $text;
        $text = preg_replace('/_(.+?)_/s', '$1', $text) ?? $text;

        return $text;
    }

    /** @param array<string, list<string>> $title */
    private function titlePageHtml(array $title): string
    {
        if ($title === []) {
            return '';
        }
        $line = static fn (string $key): string =>
            isset($title[$key]) ? implode('<br>', array_map('htmlspecialchars', $title[$key])) : '';

        // The main block sits in a wrapper with auto vertical margins so it
        // truly centers; a bare margin-top:auto on the footer would swallow
        // all the free space and pin the title to the top of the page.
        $html = "<div class=\"title-page sheet\">\n<div class=\"tp-main\">\n";
        if ($t = $line('title')) {
            $html .= '<h1 class="tp-title">' . $t . "</h1>\n";
        }
        if ($t = $line('credit')) {
            $html .= '<p class="tp-credit">' . $t . "</p>\n";
        }
        if ($t = $line('author') ?: $line('authors')) {
            $html .= '<p class="tp-author">' . $t . "</p>\n";
        }
        if ($t = $line('source')) {
            $html .= '<p class="tp-source">' . $t . "</p>\n";
        }
        $html .= "</div>\n";
        $footer = array_filter([$line('draft date'), $line('contact'), $line('copyright')]);
        if ($footer !== []) {
            $html .= '<p class="tp-footer">' . implode('<br>', $footer) . "</p>\n";
        }
        $html .= "</div>\n";
        return $html;
    }

    /** Escape HTML, strip notes, then apply Fountain inline emphasis. */
    private function inline(string $text): string
    {
        // Remove inline notes [[ ... ]].
        $text = preg_replace('/\[\[.*?\]\]/s', '', $text) ?? $text;

        $text = htmlspecialchars($text, ENT_QUOTES);

        // Order matters: bold-italic before bold before italic.
        $text = preg_replace('/\*\*\*(.+?)\*\*\*/s', '<strong><em>$1</em></strong>', $text) ?? $text;
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/_(.+?)_/s', '<u>$1</u>', $text) ?? $text;

        return $text === '' ? '&nbsp;' : $text;
    }
}
