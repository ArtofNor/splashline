<?php
declare(strict_types=1);

namespace Scriptwriter;

/**
 * Renders a parsed Sahtu comic script for reading and lettering.
 *
 * Each comic page gets its own sheet. Beats (balloons, captions, SFX) are
 * numbered per page, the way letterers key them. Balloons that run long get a
 * quiet word-count flag (the classic lettering red line is ~25 words).
 */
final class ComicRenderer
{
    private const LONG_BALLOON_WORDS = 25;

    /**
     * Panel-type keywords recognised at the start of a panel description
     * (house convention: caps keyword first, e.g. "INSET (top left). ...").
     * Ordered longest-first so multi-word types match before their prefixes.
     */
    private const PANEL_KEYWORDS = [
        'SPLASH SPREAD', 'BROKEN BORDER', 'REPEAT PANEL', '9-PANEL GRID',
        'LARGE PANEL', 'BIG PANEL', 'TALL PANEL', 'THIN PANEL', 'FULL BLEED',
        'WIDESCREEN', 'BORDERLESS', 'LETTERBOX', 'FLASHBACK', 'MONTAGE',
        'SLIVER', 'SILENT', 'SPLASH', 'REPEAT', 'INSET', 'BLEED', 'STAT',
        'WIDE', 'TALL', 'THIN',
    ];

    /** @param array{preamble: list<string>, pages: list<array<string, mixed>>} $parsed */
    public function toHtml(array $parsed): string
    {
        $pages = $parsed['pages'];

        $panelTotal = 0;
        $balloonTotal = 0;
        $spreads = 0;
        foreach ($pages as $p) {
            $panelTotal += count($p['panels']);
            $spreads += $p['spread'] ? 1 : 0;
            foreach ($p['panels'] as $panel) {
                $balloonTotal += count($panel['beats']);
            }
        }

        $out = "<div class=\"comic\">\n";

        $stats = count($pages) . ' ' . (count($pages) === 1 ? 'page' : 'pages')
            . ' · ' . $panelTotal . ' panels · ' . $balloonTotal . ' balloons';
        if ($spreads > 0) {
            $stats .= ' · ' . $spreads . ' ' . ($spreads === 1 ? 'spread' : 'spreads');
        }
        $out .= '<p class="comic-stats">' . $stats . "</p>\n";

        $out .= $this->coverHtml($parsed['preamble']);

        // Page numbering: the writer's first numbered label anchors the count
        // (so an excerpt can start at PAGE 22), unlabeled pages continue from
        // it, and a later label that breaks the sequence gets flagged rather
        // than silently trusted or silently corrected.
        $num = 1;

        foreach ($pages as $i => $p) {
            $out .= "<section class=\"comic-page sheet\">\n";

            $labelNum = $this->labelNumber($p['slug']);
            $warn = '';
            if ($labelNum !== null) {
                if ($i > 0 && $labelNum !== $num) {
                    $warn = '<span class="cp-warn" title="Out of sequence: the previous page makes this page '
                        . $num . '">expected PAGE ' . $num . '</span>';
                }
                $num = $labelNum;
            }

            $out .= '<header class="cp-head">';
            if (preg_match('/^PAGE\b/i', $p['slug'])) {
                // The writer numbered/labelled the page themselves; don't
                // print "PAGE N" next to their "PAGE TWO: ...".
                $out .= '<span class="cp-no">' . $this->inline(strtoupper($p['slug'])) . '</span>';
            } else {
                $out .= '<span class="cp-no">PAGE ' . $num . '</span>';
                if ($p['slug'] !== '') {
                    $out .= '<span class="cp-slug">' . $this->inline($p['slug']) . '</span>';
                }
            }
            $out .= $warn;
            $num++;
            if ($p['spread']) {
                $out .= '<span class="cp-badge">SPREAD</span>';
            }
            $out .= '<span class="cp-count">' . count($p['panels']) . ' '
                . (count($p['panels']) === 1 ? 'panel' : 'panels') . '</span>';
            $out .= "</header>\n";

            foreach ($p['notes'] as $note) {
                $out .= '<p class="comic-note">' . $this->inline($note) . "</p>\n";
            }

            $balloonNo = 0; // Letterers number per page, across panels.

            foreach ($p['panels'] as $j => $panel) {
                $label = $panel['label'] ?? ('PANEL ' . ($j + 1));
                // Explicit labels are checked against position: panels always
                // count from 1 within their page. Unparsable labels (inserts
                // like "PANEL 2A") pass untouched.
                $panelWarn = '';
                if ($panel['label'] !== null) {
                    $pn = $this->panelNumber($panel['label']);
                    if ($pn !== null && $pn !== $j + 1) {
                        $panelWarn = ' <span class="cp-warn" title="Out of sequence: this is the '
                            . ($j + 1) . ($j === 0 ? 'st' : ($j === 1 ? 'nd' : ($j === 2 ? 'rd' : 'th')))
                            . ' panel on this page">expected PANEL ' . ($j + 1) . '</span>';
                    }
                }
                [$types, $desc] = $this->panelTypes($panel['desc']);
                $descParas = explode("\n\n", $desc);
                $badges = '';
                foreach ($types as $kw) {
                    $splashy = str_starts_with(strtoupper($kw), 'SPLASH');
                    $badges .= ' <span class="p-badge' . ($splashy ? ' splash' : '') . '">'
                        . $this->inline(strtoupper($kw)) . '</span>';
                }
                $out .= "<div class=\"panel\">\n";
                $out .= '<p class="panel-desc"><strong class="panel-label">' . $this->inline($label)
                    . ':</strong>' . $panelWarn . $badges . ' ' . $this->inline(array_shift($descParas) ?? '') . "</p>\n";
                foreach ($descParas as $para) {
                    $out .= '<p class="panel-desc">' . $this->inline($para) . "</p>\n";
                }

                foreach ($panel['notes'] as $note) {
                    $out .= '<p class="comic-note">' . $this->inline($note) . "</p>\n";
                }

                foreach ($panel['beats'] as $beat) {
                    $balloonNo++;
                    // Whitespace-separated tokens, not str_word_count (which
                    // is ASCII-biased and undercounts non-Latin text).
                    $words = count(preg_split('/\s+/u', trim($beat['text']), -1, PREG_SPLIT_NO_EMPTY) ?: []);
                    $long = $beat['type'] !== 'sfx' && $words > self::LONG_BALLOON_WORDS;

                    $out .= '<div class="beat ' . $beat['type'] . ($long ? ' long' : '') . '">';
                    $ext = isset($beat['ext']) && $beat['ext'] !== null
                        ? ' <span class="beat-ext">(' . $this->inline($beat['ext']) . ')</span>'
                        : '';
                    $out .= '<span class="beat-label"><span class="beat-no">' . $balloonNo . '.</span> '
                        . $this->inline($beat['tag']) . $ext . ':</span>';
                    $out .= '<div class="beat-text">';
                    foreach (explode("\n\n", $beat['text']) as $para) {
                        $out .= '<p>' . $this->inline($para) . '</p>';
                    }
                    $out .= '</div>';
                    if ($long) {
                        $out .= '<span class="beat-wc" title="Long for one balloon; consider splitting">'
                            . $words . 'w</span>';
                    }
                    $out .= "</div>\n";
                }

                $out .= "</div>\n";
            }

            $out .= "</section>\n";
        }

        $out .= "</div>\n";
        return $out;
    }

    /**
     * The number in a writer-supplied page label: "PAGE 5", "PAGES 12-13"
     * (first of the range), or spelled out up to ninety-nine ("PAGE TWO",
     * "PAGE TWENTY-ONE"). Null when the label carries no readable number.
     */
    private function labelNumber(string $slug): ?int
    {
        if (!preg_match('/^PAGES?\s+([A-Za-z0-9-]+)/i', $slug, $m)) {
            return null;
        }
        return $this->parseNumber($m[1]);
    }

    /**
     * The number in an explicit panel label ("PANEL 5", "PANEL TWO").
     * "PANEL 2A"-style insert labels deliberately return null — inserts are a
     * legitimate revision convention, not a numbering mistake.
     */
    private function panelNumber(string $label): ?int
    {
        if (!preg_match('/^PANELS?\s+([A-Za-z0-9-]+)/i', $label, $m)) {
            return null;
        }
        return $this->parseNumber($m[1]);
    }

    /** Digits, a range's first number, or number words up to ninety-nine. */
    private function parseNumber(string $tok): ?int
    {
        $tok = strtoupper(rtrim($tok, ':'));

        if (preg_match('/^(\d+)(?:-\d+)?$/', $tok, $d)) {
            return (int) $d[1];
        }

        $units = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5,
            'SIX' => 6, 'SEVEN' => 7, 'EIGHT' => 8, 'NINE' => 9, 'TEN' => 10,
            'ELEVEN' => 11, 'TWELVE' => 12, 'THIRTEEN' => 13, 'FOURTEEN' => 14,
            'FIFTEEN' => 15, 'SIXTEEN' => 16, 'SEVENTEEN' => 17, 'EIGHTEEN' => 18,
            'NINETEEN' => 19];
        $tens = ['TWENTY' => 20, 'THIRTY' => 30, 'FORTY' => 40, 'FIFTY' => 50,
            'SIXTY' => 60, 'SEVENTY' => 70, 'EIGHTY' => 80, 'NINETY' => 90];

        if (isset($units[$tok])) {
            return $units[$tok];
        }
        if (isset($tens[$tok])) {
            return $tens[$tok];
        }
        $parts = explode('-', $tok, 2);
        if (count($parts) === 2 && isset($tens[$parts[0]], $units[$parts[1]])) {
            return $tens[$parts[0]] + $units[$parts[1]];
        }

        return null;
    }

    /**
     * Anything before the first "#" page becomes the cover sheet. "Key: value"
     * lines are recognised (Title, Writer, Artist, Issue, Contact, ...), any
     * free text renders as a note block — so both a full credits cover and a
     * plain explanatory paragraph work.
     *
     * @param list<string> $preamble
     */
    private function coverHtml(array $preamble): string
    {
        if ($preamble === []) {
            return '';
        }

        // Only known credit keys count as "Key: value" — free text is allowed
        // to contain colons without being mistaken for a credit line. The
        // creative roles are listed in the order a page gets made, and a comic
        // is made by more hands than a screenplay: everyone who draws it is a
        // credit, not a footnote. Anything not named here stays prose.
        $creative = ['series', 'issue', 'credit', 'author', 'writer', 'artist',
            'illustrator', 'penciller', 'penciler', 'inker', 'colorist', 'letterer',
            'designer', 'cover', 'cover artist', 'translator'];
        $known = array_merge(['title'], $creative,
            ['editor', 'contact', 'email', 'date', 'draft', 'draft date', 'copyright']);
        $kv = [];
        $free = [];
        foreach ($preamble as $line) {
            if (preg_match('/^([A-Za-z][A-Za-z ]{0,30}):\s*(.*)$/', $line, $m)
                && in_array(strtolower(trim($m[1])), $known, true)
            ) {
                // A known key with nothing after it is an unfilled credit —
                // the stub a new script opens on. Drop it rather than print
                // "Title:" as prose, which is what Fountain's title page does
                // with the same line.
                if (trim($m[2]) !== '') {
                    $kv[strtolower(trim($m[1]))] = trim($m[2]);
                }
            } else {
                $free[] = $line;
            }
        }

        $out = "<section class=\"comic-cover sheet\">\n";
        $out .= "<div class=\"cc-main\">\n";
        if (isset($kv['title'])) {
            $out .= '<h1 class="cc-title">' . $this->inline($kv['title']) . "</h1>\n";
            unset($kv['title']);
        }
        foreach ($creative as $key) {
            if (isset($kv[$key])) {
                $out .= '<p class="cc-line">' . ($key === 'credit' || $key === 'author' ? ''
                    : '<span class="cc-key">' . ucfirst($key) . '</span> ')
                    . $this->inline($kv[$key]) . "</p>\n";
                unset($kv[$key]);
            }
        }
        foreach ($free as $line) {
            $out .= '<p class="cc-note">' . $this->inline($line) . "</p>\n";
        }
        $out .= "</div>\n";

        if ($kv !== []) {
            $out .= "<footer class=\"cc-footer\">\n";
            foreach ($kv as $key => $value) {
                $out .= '<p class="cc-line"><span class="cc-key">' . ucfirst($this->inline($key))
                    . '</span> ' . $this->inline($value) . "</p>\n";
            }
            $out .= "</footer>\n";
        }
        $out .= "</section>\n";

        return $out;
    }

    /**
     * Panel-type keywords chained at the head of a description, plus the
     * description with those keywords consumed — the badge replaces the text,
     * so "SPLASH. The lighthouse..." doesn't read SPLASH twice. A placement
     * note travels into its badge: "INSET (bottom right). His knuckles..."
     * yields the badge "INSET (bottom right)" and the text "His knuckles...".
     *
     * @return array{0: list<string>, 1: string} [badges, remaining description]
     */
    private function panelTypes(string $desc): array
    {
        $found = [];
        $pos = 0;
        $len = strlen($desc);

        while (true) {
            while ($pos < $len && $desc[$pos] === ' ') {
                $pos++;
            }
            $matched = null;
            foreach (self::PANEL_KEYWORDS as $kw) {
                if (strcasecmp(substr($desc, $pos, strlen($kw)), $kw) === 0) {
                    $next = substr($desc, $pos + strlen($kw), 1);
                    if ($next === '' || !ctype_alnum($next)) {
                        $matched = $kw;
                        break;
                    }
                }
            }
            if ($matched === null) {
                return [$found, substr($desc, $pos)];
            }
            $pos += strlen($matched);

            // An immediate "(placement note)" belongs to this badge.
            $badge = $matched;
            while ($pos < $len && $desc[$pos] === ' ') {
                $pos++;
            }
            if ($pos < $len && $desc[$pos] === '(') {
                $close = strpos($desc, ')', $pos);
                if ($close !== false) {
                    $badge .= ' ' . substr($desc, $pos, $close - $pos + 1);
                    $pos = $close + 1;
                }
            }
            $found[] = $badge;

            while ($pos < $len && str_contains(' .,:;', $desc[$pos])) {
                $pos++;
            }
        }
    }

    /** Escape, then apply minimal Markdown inline styling (bold, italics). */
    private function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES);
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $text) ?? $text;
        // [bracketed notes] are working marks; render them dimmed but visible.
        $text = preg_replace('/\[(.+?)\]/s', '<span class="held">[$1]</span>', $text) ?? $text;

        return $text;
    }
}
