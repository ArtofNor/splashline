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
     * Emits the script as discrete US-Letter pages, each holding the same
     * line-per-line structure the live editor uses — so geometry matches the
     * edit surface, but broken into real sheets. Preview's extras: emphasis is
     * applied, Fountain markup is already stripped by the parser, and outline
     * synopses are private notes — hidden; sections render dimmed so that
     * comics-written-as-screenplays keep their page/panel structure readable.
     *
     * @param array{title: array<string, list<string>>, tokens: list<array<string, mixed>>} $parsed
     */
    public function toHtml(array $parsed): string
    {
        $out = $this->titlePageHtml($parsed['title']);
        $out .= "<div class=\"screenplay\">\n";

        foreach ($this->paginate($parsed['tokens']) as $page) {
            $out .= "<div class=\"page sheet\">\n";
            foreach ($page as $t) {
                if ($t['type'] === 'blank') {
                    $out .= "<div class=\"ln blank\"></div>\n";
                } else {
                    $cls = str_replace('_', '-', $t['type']);
                    $out .= '<div class="ln ' . $cls . '">' . $this->inline((string) $t['text']) . "</div>\n";
                }
            }
            $out .= "</div>\n";
        }

        $out .= "</div>\n";
        return $out;
    }

    /**
     * Split tokens into pages of at most LINES_PER_PAGE visual lines.
     * Widow/orphan control: a scene heading or character cue is pushed to the
     * next page rather than stranded at the bottom with nothing under it.
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

        foreach ($tokens as $t) {
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
        $width = self::WIDTHS[$t['type']] ?? 60;

        return count(explode("\n", wordwrap($text, $width, "\n", true)));
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

        $html = "<div class=\"title-page sheet\">\n";
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
