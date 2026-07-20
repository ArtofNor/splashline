<?php
declare(strict_types=1);

namespace Scriptwriter;

/**
 * Writes the page and panel numbers into a comic script's Markdown.
 *
 * In the house format the numbers are computed, never stored: "## Noi ducks
 * under a table" is the whole line, and both the editor and Preview count the
 * panels themselves. That keeps a working script from going stale the moment a
 * panel is inserted, but it also means the raw file handed to an artist has
 * nothing to point at in a note. This fills them in.
 *
 * The transform is by line, not by parse tree. Round-tripping through
 * ComicParser would reformat the whole file — it joins description paragraphs,
 * folds blank lines, and forgets whether a beat was written bare or with a
 * "###" — so only "#" and "##" lines are rewritten here and every other byte
 * is passed through exactly as the writer left it.
 *
 * Labels the writer already spelled out are never renumbered. They are adopted
 * instead, the same call ComicRenderer makes before printing its own number, so
 * an export can't quietly disagree with the app about which page you are on.
 */
final class ComicExporter
{
    public function numbered(string $text): string
    {
        $eol = str_contains($text, "\r\n") ? "\r\n" : "\n";
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));

        $out = [];
        $page = 1;     // The number the next unlabelled page will take.
        $panel = 0;    // Panels counted so far on this page.
        $started = false; // Seen a page yet? Panels above one stay preamble.

        foreach ($lines as $line) {
            $t = trim($line);

            // Order matters: ### before ## before #, as in ComicParser.
            if (preg_match('/^###(\s|$)/', $t)) {
                $out[] = $line;
                continue;
            }

            if (preg_match('/^##(?:\s+(.*))?$/', $t, $m)) {
                if (!$started) {
                    $out[] = $line; // A panel with no page above it isn't one.
                    continue;
                }
                $body = trim($m[1] ?? '');
                $own = $this->ownLabel($body);
                if ($own !== null) {
                    $panel = $own ?: $panel + 1;
                    $out[] = $line;
                } else {
                    $panel++;
                    $out[] = rtrim('## **PANEL ' . $panel . ':** ' . $body);
                }
                continue;
            }

            if (preg_match('/^#(?:\s+(.*))?$/', $t, $m)) {
                $slug = trim($m[1] ?? '');
                $started = true;
                $panel = 0;

                $own = Support::pageLabelNumber($slug);
                if ($own !== null) {
                    $page = $own;
                    $out[] = $line;
                } else {
                    $out[] = rtrim('# PAGE ' . $page . ($slug === '' ? '' : ' - ' . $slug));
                }
                $page++;
                continue;
            }

            $out[] = $line;
        }

        return implode($eol, $out);
    }

    /**
     * The number in a panel the writer labelled themselves, 0 when the label
     * carries no readable number ("PANEL 2A" and other insert conventions,
     * which keep their place in the count without dictating it). Null when
     * there is no spelled-out label at all. Match mirrors parsePanel().
     */
    private function ownLabel(string $body): ?int
    {
        if (!preg_match('/^\*\*(.+?)\*\*:?\s*/', $body, $m)) {
            return null;
        }
        return Support::panelLabelNumber(rtrim(trim($m[1]), ':')) ?? 0;
    }
}
