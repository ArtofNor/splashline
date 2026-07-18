<?php
declare(strict_types=1);

namespace Scriptwriter;

/**
 * A compact, spec-faithful Fountain parser.
 *
 * Implements the printed elements of the Fountain syntax
 * (https://fountain.io/syntax) plus the outline elements (sections,
 * synopses) so the app can build a navigation sidebar later.
 *
 * parse() returns:
 *   [
 *     'title'  => ['title' => ['Big Fish'], 'author' => ['...'], ...],
 *     'tokens' => [ ['type' => 'scene_heading', 'text' => 'INT. HOUSE - DAY'], ... ],
 *   ]
 *
 * Tokens are intentionally simple associative arrays so the Renderer (or a
 * future JSON/API layer) can consume them without knowing about this class.
 */
final class FountainParser
{
    /** Lines that begin a scene heading when not forced with a leading '.'. */
    private const SCENE_PREFIXES = '(INT|EXT|EST|INT\.?\/EXT|I\/E)';

    /** @return array{title: array<string, list<string>>, tokens: list<array<string, mixed>>} */
    public function parse(string $text): array
    {
        // Normalise line endings.
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove boneyard blocks /* ... */ (may span multiple lines).
        $text = preg_replace('#/\*.*?\*/#s', '', $text) ?? $text;

        $lines = explode("\n", $text);

        [$title, $bodyStart] = $this->parseTitlePage($lines);

        $tokens = $this->parseBody(array_slice($lines, $bodyStart));

        return ['title' => $title, 'tokens' => $tokens];
    }

    /**
     * A title page exists only if the first non-blank line is a "Key: value"
     * pair. Values may continue on indented lines beneath the key. The page
     * ends at the first blank line.
     *
     * @param list<string> $lines
     * @return array{0: array<string, list<string>>, 1: int} [title, indexAfterTitlePage]
     */
    private function parseTitlePage(array $lines): array
    {
        $n = count($lines);

        $first = 0;
        while ($first < $n && trim($lines[$first]) === '') {
            $first++;
        }
        if ($first >= $n || !preg_match('/^[A-Za-z][A-Za-z0-9 ]*:/', $lines[$first])) {
            return [[], 0]; // No title page — start body at the very top.
        }

        $title = [];
        $currentKey = null;
        $i = $first;
        for (; $i < $n; $i++) {
            $line = $lines[$i];
            if (trim($line) === '') {
                $i++; // Consume the terminating blank line.
                break;
            }
            if (preg_match('/^([A-Za-z][A-Za-z0-9 ]*?):\s*(.*)$/', $line, $m)) {
                $currentKey = strtolower(trim($m[1]));
                $value = trim($m[2]);
                $title[$currentKey] = $value !== '' ? [$value] : [];
            } elseif ($currentKey !== null && preg_match('/^\s+(\S.*)$/', $line, $m)) {
                $title[$currentKey][] = trim($m[1]);
            } else {
                break; // Not a title-page line; treat the rest as body.
            }
        }

        return [$title, $i];
    }

    /**
     * @param list<string> $lines
     * @return list<array<string, mixed>>
     */
    private function parseBody(array $lines): array
    {
        $tokens = [];
        $n = count($lines);

        for ($i = 0; $i < $n; $i++) {
            $raw = $lines[$i];
            $line = rtrim($raw, "\r\n");
            $trimmed = trim($line);

            $prevBlank = $i === 0 || trim($lines[$i - 1]) === '';
            $nextBlank = $i + 1 >= $n || trim($lines[$i + 1]) === '';

            if ($trimmed === '') {
                // Blank lines carry the page rhythm — the renderer spaces the
                // script from these, exactly like the editor surface does.
                $tokens[] = ['type' => 'blank'];
                continue;
            }

            // Page break: a line of three or more '='.
            if (preg_match('/^={3,}$/', $trimmed)) {
                $tokens[] = ['type' => 'page_break'];
                continue;
            }

            // Section headers: '#', '##', ... (outline only, not printed).
            if (preg_match('/^(#{1,6})\s*(.*)$/', $trimmed, $m)) {
                $tokens[] = ['type' => 'section', 'depth' => strlen($m[1]), 'text' => $m[2]];
                continue;
            }

            // Synopsis: single leading '=' (but not a page break, handled above).
            if ($trimmed[0] === '=' && !str_starts_with($trimmed, '==')) {
                $tokens[] = ['type' => 'synopsis', 'text' => trim(substr($trimmed, 1))];
                continue;
            }

            // Centered text: > text <
            if (preg_match('/^>\s*(.*?)\s*<$/', $trimmed, $m)) {
                $tokens[] = ['type' => 'centered', 'text' => $m[1]];
                continue;
            }

            // Forced transition ('>') or an unforced "... TO:" transition.
            if (str_starts_with($trimmed, '>')) {
                $tokens[] = ['type' => 'transition', 'text' => trim(substr($trimmed, 1))];
                continue;
            }
            if ($prevBlank && $nextBlank && $this->isUpper($trimmed) && preg_match('/TO:$/', $trimmed)) {
                $tokens[] = ['type' => 'transition', 'text' => $trimmed];
                continue;
            }

            // Scene heading: forced with '.' (but not '..') or a known prefix.
            if (preg_match('/^\.[^.]/', $trimmed)) {
                $tokens[] = ['type' => 'scene_heading', 'text' => ltrim(substr($trimmed, 1))];
                continue;
            }
            if ($prevBlank && preg_match('/^' . self::SCENE_PREFIXES . '[\. ]/i', $trimmed)) {
                $tokens[] = ['type' => 'scene_heading', 'text' => $trimmed];
                continue;
            }

            // Lyrics: leading '~'.
            if (str_starts_with($trimmed, '~')) {
                $tokens[] = ['type' => 'lyric', 'text' => trim(substr($trimmed, 1))];
                continue;
            }

            // Forced action: leading '!'. Preserve interior whitespace.
            if (str_starts_with($line, '!')) {
                $tokens[] = ['type' => 'action', 'text' => substr($line, 1)];
                continue;
            }

            // Character cue -> begins a dialogue block.
            if ($this->isCharacter($lines, $i, $prevBlank, $nextBlank)) {
                $dual = str_ends_with($trimmed, '^');
                $name = rtrim($trimmed, '^ ');
                $name = ltrim($name, '@'); // Forced-character marker.
                $tokens[] = ['type' => 'character', 'text' => trim($name), 'dual' => $dual];

                // Consume the dialogue block: parentheticals + dialogue lines
                // until a blank line.
                while ($i + 1 < $n && trim($lines[$i + 1]) !== '') {
                    $i++;
                    $dl = trim($lines[$i]);
                    if (preg_match('/^\(.*\)$/', $dl)) {
                        $tokens[] = ['type' => 'parenthetical', 'text' => $dl];
                    } else {
                        $tokens[] = ['type' => 'dialogue', 'text' => $dl];
                    }
                }
                continue;
            }

            // Default: action. Preserve leading whitespace for intentional layout.
            $tokens[] = ['type' => 'action', 'text' => $line];
        }

        return $tokens;
    }

    /**
     * A character cue is an uppercase line, preceded by a blank line and
     * followed by a non-blank line. A leading '@' forces a cue even when the
     * name isn't uppercase (e.g. "@McCLANE").
     *
     * @param list<string> $lines
     */
    private function isCharacter(array $lines, int $i, bool $prevBlank, bool $nextBlank): bool
    {
        if (!$prevBlank || $nextBlank) {
            return false;
        }
        $trimmed = trim($lines[$i]);

        if (str_starts_with($trimmed, '@')) {
            return true;
        }
        // Must contain at least one letter and be uppercase overall. Allow a
        // trailing "(V.O.)" style extension and a "^" dual-dialogue marker.
        if (!preg_match('/[A-Za-z]/', $trimmed)) {
            return false;
        }
        $core = preg_replace('/\(.*?\)/', '', $trimmed); // Ignore extensions.
        $core = rtrim((string) $core, '^ ');

        return $this->isUpper($core);
    }

    /** True if the string has letters and none of them are lowercase. */
    private function isUpper(string $s): bool
    {
        return preg_match('/[A-Za-z]/', $s) === 1 && preg_match('/[a-z]/', $s) === 0;
    }
}
