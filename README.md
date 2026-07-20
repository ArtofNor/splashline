# Splashline

**Write screenplays and comic scripts in plain text. See real pages.**

Splashline is a small, framework-free PHP app for writing in two formats:

- **Screenplays** in [Fountain](https://fountain.io), rendered as true
  paginated US-Letter pages: 12pt Courier, industry margins, widow and
  orphan control, print-faithful output.
- **Comic scripts** in the Sahtu format (plain Markdown, three heading
  levels), rendered as manuscript pages with auto-numbered panels and
  speech balloons, panel-type badges, and lettering-aware checks.

The editor is the page: you type into a live-formatting surface that looks
like the finished script while the file on disk stays plain text. No build
step, no database, no dependencies. Your scripts are files you own, and
they diff beautifully in git.

## Quick start

```bash
git clone <this repo>
cd splashline
php -S 127.0.0.1:8791 -t public
```

Open <http://127.0.0.1:8791>. Requires PHP 8.2+ with mbstring (bundled in
most builds). That is the whole setup.

Run the tests the same way, no dependencies:

```bash
php tests/run.php
```

## Screenplays (.fountain)

Standard Fountain: scene headings, action, character cues, parentheticals,
dialogue, transitions, dual dialogue rendered side by side, centered text,
lyrics, page breaks, sections, synopses, notes, boneyard, and inline
emphasis. The preview computes pagination in PHP (54 lines per page at
exact Courier metrics), so page count on screen equals page count in print,
and one page still roughly equals one minute. Text is measured in display
columns, not bytes, so scripts with Lao, Thai, or other non-Latin dialogue
paginate correctly, and titles in any script become valid filenames.

## Comic scripts (.md)

Two heading levels and a cue, and you know the whole format:

```markdown
# EXT. NIGHT MARKET - NIGHT

## SPLASH. A crowded night market under strings of paper lanterns.

CAPTION:	Three minutes before the lanterns go out.

##

NOI (OFF):	Sorry! Borrowing your floor!
```

- `#` is a page. Location slug optional: a bare `#` shows as PAGE N.
  Writer-numbered labels like `# PAGE 22` anchor the count, so excerpts
  work, and out-of-sequence labels get flagged, never silently corrected.
- `##` is a panel. Auto-numbered; explicit `**PANEL N:**` labels are
  checked against position (insert labels like `PANEL 2A` pass untouched).
  A caps keyword at the start of the description becomes a badge: SPLASH,
  SPREAD, WIDE, TALL, THIN, INSET, SILENT, REPEAT, MONTAGE, BORDERLESS,
  BLEED, BROKEN BORDER, FLASHBACK, and friends.
- A caps cue followed by a colon is a beat: dialogue, `SFX:`, or
  `CAPTION:`. No marker needed, since dialogue is the line you type most.
  Balloons are numbered per page for lettering. Balloon-style extensions
  live on the cue, `NOI (WHISPER):`, `CAPTION (NOI):`. Speeches run
  multiline: keep typing under a beat, blank line for a new paragraph. Long
  balloons get a quiet word-count flag.
  - The cue must be caps, which is what keeps prose out: a description
    reading `The convention: caps at the START` stays description. Panel
    keywords end in a period (`SPLASH.`, `BIG PANEL.`), never a colon, so
    they are unaffected — but a colon-style direction like `CLOSE ON: her
    hands` does read as a balloon. Write it `CLOSE ON. Her hands.`
  - `### NOI:` still works and parses identically. Use it for a cue that
    isn't caps A-Z, such as one written in Lao or Thai.
- Anything before the first `#` becomes a cover sheet. `Title:` heads it and
  the creative roles stack under it in the order a page gets made — `Series`,
  `Issue`, `Credit`, `Author`, `Writer`, `Artist`, `Illustrator`, `Penciller`,
  `Inker`, `Colorist`, `Letterer`, `Designer`, `Cover artist`, `Translator`.
  `Editor`, `Contact`, `Email`, `Date`, `Draft` and `Copyright` sit in a
  footer. Any other line stays prose, colon and all.

Open `scripts/panel-types-reference.md` in the app: it is a complete
reference for the format, written in the format.

New scripts start from one of two buttons, Screenplay or Comic, each opening
on its own title block. That is a declaration, not a guess: the house comic
form is ambiguous with Fountain sections on purpose (`#` is a page here and a
section there), so an empty document cannot be recognized, only chosen. The
choice decides the extension and how the editor formats the first keystroke.

After that, detection is content-first and the choice only breaks ties. A
comic script routes to the comic viewer even with a `.fountain` extension,
and Fountain-style comics written as screenplays (sections for pages) still
read fine as screenplays.

## Design principles

- **Plain text is the source of truth.** The app renders; it never rewrites
  your words.
- **Flag, never auto-correct.** Sequence mistakes, long balloons, and
  density problems get advisory chips. The script stays yours.
- **The parsers are the product.** `src/` has zero web-layer coupling: four
  pure PHP classes you can drop into any project. See `INTEGRATION.md` for
  porting into a framework such as CodeIgniter 4.

## Contributing

Small tool, warmly maintained. Issues and pull requests welcome; keep the
pagination contract in mind (documented in `INTEGRATION.md`) and be kind.

## Credits

Fountain is an open screenplay format by John August, Nima Yousefi, Stu
Maschwitz and friends: <https://fountain.io>. The comic script format is
the Sahtu Press house format, published here under the same MIT license as
the code.
