<?php
declare(strict_types=1);

namespace Scriptwriter;

/**
 * Parser for the Sahtu Press comic script format: plain Markdown with three
 * heading levels.
 *
 *   # EXT. HIGHWAY - DAY [SPREAD]     page, written as a location slug
 *   ## **PANEL 1:** description       panel, bold label then description
 *   ### JANIDA:<tab>line              beat: dialogue, SFX, or CAPTION
 *
 * parse() returns:
 *   [
 *     'preamble' => list<string>,       // any lines before the first page
 *     'pages'    => [ [
 *        'slug' => 'EXT. HIGHWAY - DAY',
 *        'spread' => bool, 'continuous' => bool,
 *        'notes' => list<string>,       // stray lines at page level
 *        'panels' => [ [
 *           'label' => 'PANEL 1'|null,
 *           'desc'  => string,
 *           'beats' => [ ['tag' => 'JANIDA', 'type' => 'dialogue'|'sfx'|'caption', 'text' => string] ],
 *           'notes' => list<string>,
 *        ] ],
 *     ] ],
 *   ]
 */
final class ComicParser
{
    /** @return array{preamble: list<string>, pages: list<array<string, mixed>>} */
    public function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $pages = [];
        $preamble = [];
        $page = null;
        $panel = null;

        $flushPanel = function () use (&$page, &$panel): void {
            if ($panel !== null && $page !== null) {
                $page['panels'][] = $panel;
            }
            $panel = null;
        };
        $flushPage = function () use (&$pages, &$page, $flushPanel): void {
            $flushPanel();
            if ($page !== null) {
                $pages[] = $page;
            }
            $page = null;
        };

        $pendingBlank = false; // A blank line seen since the last content line.

        foreach (explode("\n", $text) as $line) {
            $t = trim($line);
            if ($t === '') {
                $pendingBlank = true;
                continue;
            }

            // Order matters: ### before ## before #. Content after the marks
            // is optional: a bare "#" is simply the next page.
            if (preg_match('/^###(?:\s+(.*))?$/', $t, $m)) {
                $pendingBlank = false;
                $beat = $this->parseBeat($m[1] ?? '');
                if ($panel !== null) {
                    $panel['beats'][] = $beat;
                } elseif ($page !== null) {
                    $page['notes'][] = $m[1] ?? '';
                } else {
                    $preamble[] = $t;
                }
                continue;
            }

            if (preg_match('/^##(?:\s+(.*))?$/', $t, $m)) {
                $pendingBlank = false;
                if ($page === null) {
                    $preamble[] = $t; // A panel with no page above it.
                    continue;
                }
                $flushPanel();
                $panel = $this->parsePanel($m[1] ?? '');
                continue;
            }

            if (preg_match('/^#(?:\s+(.*))?$/', $t, $m)) {
                $pendingBlank = false;
                $flushPage();
                $slug = trim($m[1] ?? '');
                $spread = (bool) preg_match('/\[SPREAD\]\s*$/i', $slug);
                if ($spread) {
                    $slug = trim((string) preg_replace('/\[SPREAD\]\s*$/i', '', $slug));
                }
                $page = [
                    'slug'       => $slug,
                    'spread'     => $spread,
                    'continuous' => (bool) preg_match('/-\s*CONTINUOUS$/i', $slug),
                    'panels'     => [],
                    'notes'      => [],
                ];
                continue;
            }

            // Plain line. Standalone [bracketed notes] stay notes; anything
            // else continues what came before it: dialogue keeps flowing after
            // a beat (a blank line makes a new paragraph, so speeches can run
            // multiline), and description keeps flowing after a panel.
            if ($panel !== null && preg_match('/^\[.*\]$/', $t)) {
                $panel['notes'][] = $t;
            } elseif ($panel !== null && $panel['beats'] !== []) {
                $i = count($panel['beats']) - 1;
                $panel['beats'][$i]['text'] .= ($pendingBlank ? "\n\n" : ' ') . $t;
            } elseif ($panel !== null) {
                $panel['desc'] .= ($panel['desc'] === '' ? '' : ($pendingBlank ? "\n\n" : ' ')) . $t;
            } elseif ($page !== null) {
                $page['notes'][] = $t;
            } else {
                $preamble[] = $t;
            }
            $pendingBlank = false;
        }
        $flushPage();

        return ['preamble' => $preamble, 'pages' => $pages];
    }

    /** @return array{label: ?string, desc: string, beats: list<array<string, string>>, notes: list<string>} */
    private function parsePanel(string $body): array
    {
        $body = trim($body);
        $label = null;
        // House style: ## **PANEL 1:** description
        if (preg_match('/^\*\*(.+?)\*\*:?\s*(.*)$/', $body, $m)) {
            $label = rtrim(trim($m[1]), ':');
            $body = trim($m[2]);
        }

        return ['label' => $label, 'desc' => $body, 'beats' => [], 'notes' => []];
    }

    /** @return array{tag: string, ext: ?string, type: string, text: string} */
    private function parseBeat(string $body): array
    {
        // TAG:<tab>line — tolerate spaces where the tab should be.
        if (preg_match('/^([^:]+):\s*(.*)$/', trim($body), $m)) {
            $tag = trim($m[1]);
            $text = trim($m[2]);
        } else {
            $tag = '';
            $text = trim($body);
        }

        // Balloon-style extension on the tag: "JANIDA (OFF)", "BO (WHISPER)",
        // "CAPTION (NOI)". The letterer reads these; they're part of the cue,
        // not the dialogue.
        $ext = null;
        if (preg_match('/^(.*?)\s*\(([^)]+)\)$/', $tag, $m)) {
            $tag = trim($m[1]);
            $ext = trim($m[2]);
        }

        $upper = strtoupper($tag);
        $type = match (true) {
            $upper === 'SFX'                  => 'sfx',
            str_starts_with($upper, 'CAPTION') => 'caption',
            default                            => 'dialogue',
        };

        return ['tag' => $tag, 'ext' => $ext, 'type' => $type, 'text' => $text];
    }
}
