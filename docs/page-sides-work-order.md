# Work Order: Page sides and two-page spreads

**From:** Nor (drafted with Claude, 2026-10-15)
**Status:** Items 1–4 shipped in v1.9.0 and items 5–6 in v1.10.0. Items 7–8 are not started; Nor approved them on 2026-10-15. Storyboon's page-turn preview waits on them.

## The goal

Comic writers plan around the page turn, so they need to know which side of the book every page lands on. This is the "Page-turn markers" item on the roadmap, plus one more rule it depends on: a spread is two printed pages, not one.

Splashline owns page numbering, so it should own both rules. Any app built on it reads the answers off the sheets instead of counting for itself.

## What to add

**1. A side for every page.** In a printed book, page 1 is a right-hand page, so odd pages are right and even pages are left. Each comic sheet gets `data-side="left"` or `data-side="right"` beside its `data-page`, and the page header shows LEFT or RIGHT next to the page number. This follows the anchored numbering: an excerpt that starts at PAGE 22 knows page 22 is a left page.

**2. A spread takes two page numbers.** Today `[SPREAD]` counts as one page, so every page after a spread is numbered one too low against the printed book. A spread starting on page 16 should be pages 16 and 17, and the next page is 18. The sheet carries both numbers (for example `data-page="16"` and `data-page-end="17"`), and the header reads PAGES 16–17.

**3. Flag a spread that starts on a right page.** A spread has to start on a left page or it splits across the page turn. When one starts on a right page, show an advisory chip in the same style as the existing out-of-sequence warning: "Spread starts on a right page."

**4. Stats.** The page count in the stats line counts printed pages, so each spread adds two.

**5. A panel that crosses the middle of a spread.** On a spread, an artist often runs one panel across both pages, over the fold, with smaller panels around it. Today there is no way to mark that. Add a panel keyword for it, in the same style as the others (caps, ends in a period). Suggested: `ACROSS.`, as in `## ACROSS. The car on the highway, city behind, country ahead.` Bring Nor the name before building. Rules:
- It only makes sense on a `[SPREAD]` page. Used on a single page, flag it: "This panel crosses the fold, but the page isn't a spread."
- It gets a badge like the other panel types, and the sheet records which panels cross, so a page-turn view can draw them across both halves.

**6. Splash checks.** Two advisory chips:
- A `SPLASH.` panel that shares its page with other panels: "Splash on a page with other panels." An `INSET` on a splash page is fine and should not trip it.
- A `SPLASH SPREAD.` panel on a page that isn't marked `[SPREAD]`: "Splash spread on a single page."

**7. Panels side by side: `TIER.`** A tier is the comics term for a row of panels. Consecutive panels that open with `TIER.` share one tier, side by side, and the next panel without it starts a new full-width row:

```
## TIER. Noi looks left.
## TIER. The guard looks right.
## TIER. The cook looks at both of them.
```

It is a keyword in the same style as the others (caps, ends in a period), and it gets a badge like them. No count is written ("1 of 3"), because a written count goes stale the moment a panel is inserted, which is why page and panel numbers are computed. The sheet records the grouping, so a surface like a page-turn view can lay panels out in their tiers without parsing descriptions: every panel in the same tier carries the same tier number on the page. A lone `TIER.` panel with no neighbour is simply a tier of one.

**8. Too many balloons.** A crowded panel is a script problem an artist and letterer pay for. When a panel has more than 4 balloons, show an advisory chip in the existing style: "More than 4 balloons in a panel." Count what the engine already numbers as balloons, so the chip and the balloon numbers agree.

## Things to get right

- **The numbered export has to agree.** `ComicExporter` writes page numbers into the downloaded file. It must use the same spread rule, or the downloaded script and the preview disagree. Same for any labels a writer spelled out: adopt them, as today.
- **This changes existing numbers.** Every page after a spread moves up by one. That is the fix, but it is a visible change for anyone with spreads in a script. Note it in the release.
- Keep the anchored numbering and the out-of-sequence warning working with spreads in the mix.

## Tests

Add cases to `tests/run.php`:
- Sides for a plain script, and for an excerpt anchored at an even page.
- A spread on a left page (no flag), a spread on a right page (flagged), and the numbering after each.
- The numbered export matching the preview's numbers in both cases.
- A crossing panel on a spread (no flag) and on a single page (flagged).
- A splash alone, a splash with an inset (no flag), a splash with other panels (flagged), and a splash spread on a single page (flagged).
- Tiers: three consecutive `TIER.` panels share one tier number and the next bare panel does not; two tiers on one page get different numbers; a lone `TIER.` panel is a tier of one.
- Balloons: a panel with 4 balloons (no chip) and one with 5 (chip).
- The comic sample still trips no chips.

## Done means

Tests green, tag a release, update README and ROADMAP (move "Page-turn markers" out of the roadmap). Storyboon then re-copies `src/` and the CSS verbatim, as its upstream rule requires.
