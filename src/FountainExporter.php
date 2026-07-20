<?php
declare(strict_types=1);

namespace Scriptwriter;

/**
 * Writes scene numbers into a screenplay's Fountain.
 *
 * "INT. HOUSE - DAY #1#" is Fountain's own syntax for a numbered scene, so an
 * export carrying them opens correctly in anything else that reads the format.
 * The app computes nothing here the way it computes comic panel numbers — a
 * screenplay's scene numbers simply don't exist until someone asks for them,
 * which is what a production draft does.
 *
 * Like ComicExporter this rewrites by line, never through the token stream:
 * only scene headings are touched and every other byte is passed through as
 * the writer left it. Scene headings are found by the same two rules the
 * parser uses — a forced "." or a known prefix after a blank line — so the
 * export can't number a line the app doesn't consider a scene.
 *
 * A scene the writer already numbered keeps its number and is not recounted,
 * matching how ComicExporter treats a spelled-out panel label.
 */
final class FountainExporter
{
    private const SCENE_PREFIXES = '(INT|EXT|EST|INT\.?\/EXT|I\/E)';

    public function numbered(string $text): string
    {
        $eol = str_contains($text, "\r\n") ? "\r\n" : "\n";
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));

        // Everything above the first blank line may be a title page, where a
        // "Draft date: ..." key must never read as a scene.
        $inTitlePage = $this->hasTitlePage($lines);

        $out = [];
        $scene = 1;
        $n = count($lines);

        for ($i = 0; $i < $n; $i++) {
            $line = rtrim($lines[$i], "\r");
            $trimmed = trim($line);

            if ($trimmed === '') {
                $inTitlePage = false;
                $out[] = $line;
                continue;
            }
            if ($inTitlePage) {
                $out[] = $line;
                continue;
            }

            $prevBlank = $i === 0 || trim($lines[$i - 1]) === '';
            $isScene = preg_match('/^\.[^.]/', $trimmed)
                || ($prevBlank && preg_match('/^' . self::SCENE_PREFIXES . '[\. ]/i', $trimmed));

            if (!$isScene) {
                $out[] = $line;
                continue;
            }

            // Already numbered: adopt it, so a hand-numbered scene keeps its
            // place and the ones after it count on from there.
            if (preg_match('/#([^#\s][^#]*)#$/', $trimmed, $m)) {
                $own = Support::labelNumber($m[1]);
                if ($own !== null) {
                    $scene = $own;
                }
                $out[] = $line;
                $scene++;
                continue;
            }

            $out[] = $line . ' #' . $scene . '#';
            $scene++;
        }

        return implode($eol, $out);
    }

    /**
     * A Fountain title page is a run of "Key: value" lines at the very top,
     * ended by the first blank line. Without this an untitled script's opening
     * scene would still be found, but "Title: INT. THE MIND" would not be
     * mistaken for one.
     *
     * @param list<string> $lines
     */
    private function hasTitlePage(array $lines): bool
    {
        $first = trim($lines[0] ?? '');

        return $first !== '' && preg_match('/^[A-Za-z ]+:/', $first) === 1;
    }
}
