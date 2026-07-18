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
}
