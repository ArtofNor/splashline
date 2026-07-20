<?php
declare(strict_types=1);

namespace Scriptwriter;

/**
 * Shared, framework-free helpers used by the web shell (public/index.php)
 * and testable on their own: script-kind detection and filename sanitising.
 */
final class Support
{
    /** Extension signal only: .md leans comic, .fountain leans screenplay. */
    public static function isComicName(string $name): bool
    {
        return (bool) preg_match('/\.md$/i', $name);
    }

    /**
     * Content signal. The Sahtu comic format is simultaneously valid Markdown
     * and valid Fountain (Fountain sections ARE Markdown headings), so the
     * extension can't be trusted alone. These three signatures appear in comic
     * scripts and never in screenplays: a page slug as H1, a bold panel label
     * as H2, or a tagged beat as H3. Returns null when the content says
     * nothing either way.
     */
    public static function sniffComic(string $content): ?bool
    {
        if (preg_match('/^#\s+(INT|EXT|EST|I\/E)[.\s]/mi', $content)
            || preg_match('/^##\s+\*\*/m', $content)
            || preg_match('/^###\s+[^:\n]+:/m', $content)
        ) {
            return true;
        }
        return null;
    }

    /** Content-first kind detection, extension as fallback. */
    public static function isComicFile(string $name, string $content): bool
    {
        return self::sniffComic($content) ?? self::isComicName($name);
    }

    /**
     * Turn whatever the user typed (a title, really) into a safe filename.
     * Rather than reject titles with apostrophes or colons, we sanitise:
     * "Act 1: Dawn" -> "Act-1-Dawn.fountain", "Night's End" ->
     * "Nights-End.fountain". Unicode letters and digits are kept, so titles
     * in Lao, Thai, or any other script work. An explicitly typed ".md" or
     * ".fountain" is honoured; otherwise the extension follows what the
     * content sniffed as ($comicDefault).
     * Returns null only if nothing usable remains.
     */
    public static function safeFilename(string $input, bool $comicDefault = false): ?string
    {
        $name = trim($input);
        $ext = match (true) {
            self::isComicName($name)                     => '.md',
            (bool) preg_match('/\.fountain$/i', $name)   => '.fountain',
            default                                      => $comicDefault ? '.md' : '.fountain',
        };
        $name = preg_replace('/\.(fountain|md)$/i', '', $name) ?? $name; // Drop extension; re-added below.
        $name = str_replace(["'", "\u{2019}"], '', $name);               // "Night's" -> "Nights", not "Night-s".
        // Letters, digits, and combining marks (Lao vowels and tones are
        // \p{M}, not \p{L}) survive; any other run becomes a single hyphen.
        $name = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', $name) ?? $name;
        $name = trim($name, '-');

        return $name === '' ? null : $name . $ext;
    }

    /**
     * Resolve a stored script name to a safe path inside $dir, or null.
     * basename() strips any directory parts; the pattern allows Unicode
     * letters/digits, spaces, underscores and hyphens, and exactly one
     * known extension — no dots elsewhere, so no traversal.
     */
    public static function scriptPath(string $dir, string $name): ?string
    {
        $base = basename($name);
        if (!preg_match('/^[\p{L}\p{M}\p{N} _\-]+\.(fountain|md)$/u', $base)) {
            return null;
        }
        return $dir . '/' . $base;
    }

    /**
     * The number in a writer-supplied page label: "PAGE 5", "PAGES 12-13"
     * (first of the range), or spelled out up to ninety-nine ("PAGE TWO",
     * "PAGE TWENTY-ONE"). Null when the label carries no readable number.
     */
    public static function pageLabelNumber(string $slug): ?int
    {
        if (!preg_match('/^PAGES?\s+([A-Za-z0-9-]+)/i', $slug, $m)) {
            return null;
        }
        return self::labelNumber($m[1]);
    }

    /**
     * The number in an explicit panel label ("PANEL 5", "PANEL TWO").
     * "PANEL 2A"-style insert labels deliberately return null — inserts are a
     * legitimate revision convention, not a numbering mistake.
     */
    public static function panelLabelNumber(string $label): ?int
    {
        if (!preg_match('/^PANELS?\s+([A-Za-z0-9-]+)/i', $label, $m)) {
            return null;
        }
        return self::labelNumber($m[1]);
    }

    /** Digits, a range's first number, or number words up to ninety-nine. */
    public static function labelNumber(string $tok): ?int
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
}
