<?php
declare(strict_types=1);

/**
 * The seams between the browser files.
 *
 * paste.js is loaded as a plain script and read through a global, which is
 * what lets it stay dependency-free and testable under Node — but it also
 * means nothing checks that the two files still agree. Extracting the paste
 * formatter out of editor.js broke exactly this: the tests for the formatter
 * all passed, because they call paste.js directly, while the editor's own call
 * site still named a function that had moved and threw on every paste.
 *
 * These are cheap text checks, not behaviour. They only claim the wiring is
 * present, which is the part the other suites structurally cannot see.
 */

$editor = (string) file_get_contents(__DIR__ . '/../public/editor.js');
$index = (string) file_get_contents(__DIR__ . '/../public/index.php');

// The editor reaches the formatter through the global, never by a bare name.
check(str_contains($editor, 'Paste.fromPrinted('), 'editor calls Paste.fromPrinted');
check(preg_match('/(?<!\.)\bfromPrinted\s*\(/', $editor) === 0, 'no bare fromPrinted call left behind');

// And it reads the shared classification primitives from the same place, so
// the rules the tests pin are the rules the live surface uses.
foreach (['isUpper', 'isCharacter', 'TRANSITION_END'] as $shared) {
    check(str_contains($editor, 'Paste.' . $shared), "editor takes {$shared} from Paste");
}

// Load order: the global has to exist before editor.js reads it.
$pasteAt = strpos($index, 'src="paste.js"');
$editorAt = strpos($index, 'src="editor.js"');
check($pasteAt !== false, 'index.php loads paste.js');
check($editorAt !== false && $pasteAt !== false && $pasteAt < $editorAt, 'paste.js loads before editor.js');

// paste.js stays free of the DOM, which is the whole reason Node can run it.
// Comments come out first — this file explains itself by talking about the
// editor, and prose is not a dependency.
$paste = (string) file_get_contents(__DIR__ . '/../public/paste.js');
$code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $paste) ?? $paste;
check(preg_match('/\b(document|window|navigator)\b/', $code) === 0, 'paste.js touches no DOM');
