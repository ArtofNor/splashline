# Integrating Splashline into a CodeIgniter 4 site

This document is written for the developer or AI agent performing the
integration. Follow it and the port is mechanical.

## Getting Splashline and staying updated

Splashline's home is <https://github.com/ArtofNor/splashline>. The site
consuming it should treat that repo as upstream:

1. **Install:** clone the latest tag and copy `src/` into the site
   (`git clone --branch v1.0.0 https://github.com/ArtofNor/splashline`).
   Record which tag you copied (a one-line note in the site repo is enough).
2. **Update:** re-copy `src/` from the newer tag, re-run the behavior tests
   at the bottom of this document, done. Assets (`editor.js`, the paper CSS)
   only need re-copying when a release notes say so.
3. **Never patch the site's copy of `src/`.** If the site needs a parser or
   renderer fix, make it in the Splashline repo, tag it, then update the
   site from the new tag. A locally patched copy silently forks and the two
   drift apart.

If the site later wants dependency management, Splashline can ship a
`composer.json` and be required through a VCS repository entry; ask for it.

## What this app is

A screenplay + comic-script writing tool. Two file formats, one pipeline each:

- `.fountain` screenplays: `src/FountainParser.php` -> `src/Renderer.php`
- Sahtu comic scripts (Markdown headings): `src/ComicParser.php` -> `src/ComicRenderer.php`
- Detection is content-first (`Scriptwriter\Support::sniffComic()`);
  extension is only a fallback. `Support` also owns filename sanitising and
  path validation; `public/index.php` only aliases it.

## The golden rule

**`src/` is the product. Copy it verbatim; do not refactor it.**
The four classes have zero web-layer coupling and no dependencies. Put them
in `app/Libraries/Scriptwriter/` (they already use namespace `Scriptwriter`),
add the namespace to autoload, done. All app behavior lives there; the rest
is shell.

## What to rewrite (the shell)

`public/index.php` is a single-file front controller. Map it to CI4:

- Routes: `list` / `view` / `edit` / `save` (GET x3, POST save) -> a
  `ScriptsController`. Keep URLs or invent your own; nothing depends on them.
- The inline HTML -> three views (listing, editor, viewer).
- Port these helpers with their exact semantics (they encode fixed bugs):
  - `safe_filename()` - sanitizes titles into filenames, honours explicit
    `.md`/`.fountain`, otherwise extension follows content sniffing.
  - The save flow: 409 on a name collision with a *different* file
    (`original` field, case-insensitive compare - macOS/APFS is
    case-insensitive and silent overwrites lost real data once);
    rename deletes the old file only after the new one is written, and
    never on a case-only rename.
  - Single-flight saves are client-side (`editor.js`) - keep the JSON
    response shape `{ok, file}` / `{error}` or update editor.js to match.
- Storage is flat files in `scripts/`. For CI4 either keep a configurable
  directory (writable/scripts) or replace the file layer with a model -
  parsers/renderers do not care where text comes from.

## Assets

- `public/editor.js` - the live-formatting editor. Framework-agnostic
  vanilla JS; only expects elements with ids `editor`, `source`, `filename`,
  `original`, `status`, and the save endpoint. Port near-verbatim.
- `public/style.css` - split it mentally into two halves:
  1. **App chrome** (topbar, listing, editor bar, dark background): restyle
     freely to the site's design system.
  2. **The paper** (`.sheet`, `.screenplay`, `.page`, `.title-page`, comic
     `.comic*`, `.beat*`, `.p-badge`, `.cp-*`): DO NOT restyle the
     screenplay internals. See the contract below.

## The pagination contract (do not break)

Screenplay Preview computes pages **in PHP** (`Renderer::LINES_PER_PAGE`,
`Renderer::WIDTHS`) assuming the CSS renders exactly:

- 12pt Courier ("Courier New"), line-height 1, `.ln { min-height: 1em }`
- 8.5in sheet, padding 1in top / 1.5in left / 0.95in right (the 0.05in slack
  prevents exact-fit wrap drift between PHP `wordwrap` and the browser)
- Element indents: dialogue +1in, parenthetical +1.6in, character +2.2in

CSS and PHP cross-reference each other in comments. Change either side and
pages will overflow or under-fill. Theme *around* the paper, not inside it.
The comic view has no line-count math (sheets are `min-height: 11in`) and
tolerates more restyling, but keep a Courier-metric face for the manuscript
look.

### Changing the font (the one paper-side knob)

The face is a variable, `--script-font` in `:root`, used by `.title-page`,
`.screenplay` and `.comic`. Set it once and all three surfaces follow:

```css
:root { --script-font: "Courier Prime", "Courier New", Courier, monospace; }
```

The requirement is not "a monospace font", it is **10 characters per inch at
12pt** — `Renderer::WIDTHS` counts characters, and the CSS turns them into
inches at that rate. A face with any other advance width still looks fine on
screen while every page break lands in the wrong place. Courier Prime (John
August's, metric-compatible by design), Courier Screenplay and Nimbus Mono
qualify; most system `monospace` defaults do not.

Re-measure after changing it. 60 characters must span exactly 6.00in:

```js
(s => { s.style.cssText = 'position:absolute;visibility:hidden;white-space:pre;font:12pt "Your Font"';
        s.textContent = 'M'.repeat(60); document.body.append(s);
        console.log(s.getBoundingClientRect().width / 96, 'in — want 6'); s.remove();
      })(document.createElement('span'))
```

Measure the result, not the intent: naming a font that isn't installed falls
through the stack silently, and `document.fonts.check()` reports `true` even
for a family that does not exist. Then re-run behavior 4 below (no page's
content overflows its sheet) against a long script.

## Behaviors to preserve (test these after porting)

The parser/renderer layer has an executable suite: `php tests/run.php`
(zero dependencies). Run it against your copied `src/` after porting; the
list below covers the shell behaviors the suite cannot reach.

1. A comic script routes to the comic viewer regardless of extension
   (content sniffing), and vice versa.
1a. `?action=new&kind=comic` saves as `.md` while the file is still only a
   title block, and its editor styles `#`/`##` as page/panel from the first
   keystroke. The declared kind only breaks ties: content still wins, so a
   Fountain screenplay pasted into a comic-started file routes as Fountain.
2. Saving a new script whose name collides with an existing one -> 409,
   never a silent overwrite. Case-insensitive.
3. Renaming via the title field leaves exactly one file.
4. Screenplay preview: no page's content overflows its sheet (check with a
   long script; measure `.page` children bottom vs sheet bottom).
5. Comic viewer: balloon numbering restarts per page; panel/page sequence
   warnings appear for out-of-order explicit labels; `PANEL 2A` inserts do
   not warn.
6. Editor: Enter in the title field saves once (no duplicate POST), autosave
   only fires once a title exists.

## Auth / multi-user (new site concerns, not in this repo)

The app is single-user by design. On a shared site, add: auth on every
route, per-user script directories (or an owner column), and CSRF on the
save endpoint (CI4's filter works; editor.js sends a plain urlencoded POST -
attach the token there).

## Sample content

`scripts/` ships demo + reference files. `panel-types-reference.md` doubles
as user-facing documentation of the comic format - keep it available.
