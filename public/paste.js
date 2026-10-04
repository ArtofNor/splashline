/*
 * Turning a printed script back into a script.
 *
 * A script copied out of a PDF arrives still wearing the page it was printed
 * on: every element indented to its margin, paragraphs broken wherever the
 * page ran out, and the running furniture — page numbers, CONTINUED, (MORE) —
 * sitting in the text as though someone wrote it. The margins the parser
 * already ignores. The rest it cannot: a broken paragraph is several action
 * lines that will never reflow, and the furniture is action that was never
 * written.
 *
 * fromPrinted() puts the words back into the shape they were in before they
 * were printed. It is the editor's paste handler that calls it, but it is
 * plain text in and plain text out with no DOM anywhere in it, which is what
 * lets tests/paste.test.js run the whole thing under Node.
 *
 * It also owns the three classification primitives editor.js shares with it —
 * isUpper, isCharacter and TRANSITION_END — so that the rules with tests are
 * the rules the live surface uses.
 */
var Paste = (function () {
  'use strict';

  function isUpper(s) {
    return /[A-Za-z]/.test(s) && !/[a-z]/.test(s);
  }

  function isCharacter(t) {
    if (t.charAt(0) === '@') return true;
    if (!/[A-Za-z]/.test(t)) return false;
    var core = t.replace(/\(.*?\)/g, '').replace(/[\^ ]+$/, '');
    return isUpper(core);
  }

  // What ends an unforced transition — mirrors TRANSITION_END in
  // FountainParser. Fountain's own "TO:" misses the transitions that end a
  // scene on nothing (CUT TO BLACK:, FADE OUT.), which are transitions by
  // every other measure and are read as such here too.
  var TRANSITION_END = /(TO:|TO BLACK[.:]?|FADE OUT[.:]?)$/;

  /*
   * A script copied out of a PDF arrives still wearing the page it was printed
   * on: every element indented to its margin, paragraphs broken wherever the
   * page ran out, and the running furniture — page numbers, CONTINUED, (MORE)
   * — sitting in the text as though someone wrote it. The margins the parser
   * already ignores. The rest it cannot: a broken paragraph is several action
   * lines that will never reflow, and the furniture is action that was never
   * written. This puts the words back into the shape they were in before they
   * were printed, so a paste lands as a script instead of as its photograph.
   */

  // Lines the printer added and the writer never wrote.
  var FURNITURE = [
    /^\d{1,4}[.:]?$/,                                     // a page number alone
    /^\d{1,4}\.?\s+\(?CONTINUED\)?[.:]?(\s*\(\d+\))?$/i,  // and one sharing its line
    /^\(?\s*CONTINUED\s*\)?[.:]?(\s*\(\d+\))?$/i,
    /^\(\s*MORE\s*\)$/i,
    /^PAGE\s+\d+(\s+OF\s+\d+)?$/i
  ];

  function isFurniture(t) {
    for (var i = 0; i < FURNITURE.length; i++) {
      if (FURNITURE[i].test(t)) return true;
    }
    return false;
  }

  /**
   * Whether this text came off a printed page rather than out of an editor.
   * The test is deliberately hard to pass: text that is already a script has
   * to come through untouched, because rejoining lines there would destroy
   * breaks the writer put in on purpose. Only margins or furniture — neither
   * of which anyone types — are taken as proof.
   */
  function looksPrinted(lines) {
    var indented = 0;
    var blanks = 0;
    var filled = 0;
    for (var i = 0; i < lines.length; i++) {
      var t = lines[i].trim();
      if (t === '') { blanks++; continue; }
      filled++;
      if (isFurniture(t)) return true;
      if (/^ {4,}/.test(lines[i])) indented++;
    }
    if (indented >= 3) return true;
    // A written script cannot go without blank lines — they are what separates
    // a cue from the speech above it, and Fountain reads nothing without them.
    // A long run of lines with almost none is a page whose extractor dropped
    // them, which is the one case where text this bare is safe to reshape.
    return filled >= 8 && blanks * 8 < filled;
  }

  /** A line that names itself, and so ends the paragraph running into it. */
  function standsAlone(t) {
    return /^(int|ext|est|int\.?\/ext|i\/e)[. ]/i.test(t)
      || (isUpper(t) && TRANSITION_END.test(t))
      || /^[.>!~#=]/.test(t);
  }

  function isParen(t) {
    return /^\(.*\)$/.test(t);
  }

  /** Join the lines a page width broke, leaving the ones that are their own. */
  function unwrap(block, keep) {
    var out = [];
    var run = [];
    for (var i = 0; i < block.length; i++) {
      if (keep(block[i])) {
        if (run.length) { out.push(run.join(' ')); run = []; }
        out.push(block[i]);
      } else {
        run.push(block[i]);
      }
    }
    if (run.length) out.push(run.join(' '));
    return out;
  }

  /**
   * One block of consecutive non-blank lines, put back into paragraphs. A cue
   * opens a speech, where the cue and its parentheticals stand alone and the
   * lines between them are one speech; anything else is action.
   */
  function reflow(block) {
    if (block.length > 1 && isCharacter(block[0])) {
      return [block[0]].concat(unwrap(block.slice(1), isParen));
    }
    return unwrap(block, standsAlone);
  }

  /*
   * Some extractors keep the page's line breaks but drop every blank line
   * between elements. What comes out has no block structure left at all — one
   * unbroken run of lines — which Fountain cannot read (a cue is only a cue
   * with a blank line above it) and neither can the shaping above, which finds
   * the end of a block by looking for the blank line after it.
   *
   * The breaks have to be put back from the lines themselves. A scene heading,
   * a transition and a character cue each say outright what they are, so each
   * one starts a block. Dialogue is what follows a cue, and it gives out at
   * the width its column wraps to: a line wider than that was set in the
   * action column, so the speech ended above it.
   *
   * Where an uppercase line turns out not to be a cue — a shot like "OVER SONY
   * LOGO:" — the break in front of it is still right. It ends up isolated, and
   * an isolated line is action by Fountain's own rule, which is what it is.
   */
  var DIALOGUE_COLUMN = 45;

  // Action names its subject in capitals as it comes in — "EXPLOSIONS from the
  // Tower Bridge", "A DISTORTED SOUND VORTEX, then--". Dialogue doesn't open
  // that way, so this ends a speech that is too narrow to end by width.
  var CAPS_HEAD = /^(?:[A-Z][A-Z0-9'’.-]*\s+)*[A-Z][A-Z0-9'’.-]{2,}\b/;

  // A line stopping mid-sentence is finished by the line under it, whatever
  // case that one is in. Without this the "FAR FROM HOME." ending a paragraph
  // reads as a cue and cuts the paragraph in half.
  var ENDS_CLEAN = /[.!?:;)\]"'—’”-]$/;

  function restoreBreaks(kept) {
    var out = [];
    var inDialogue = false;
    for (var i = 0; i < kept.length; i++) {
      var t = kept[i];
      var cue = isCharacter(t) && !opensBody(t)
        && (i === 0 || ENDS_CLEAN.test(kept[i - 1]));
      var opens = cue || opensBody(t) || (isUpper(t) && TRANSITION_END.test(t));
      // A speech ends at a line too wide for its column, or at one that opens
      // in capitals the way action does. A parenthetical belongs to the speech
      // however short it is.
      var leaves = inDialogue && !isParen(t)
        && (t.length > DIALOGUE_COLUMN || CAPS_HEAD.test(t));
      if ((opens || leaves) && out.length > 0 && out[out.length - 1] !== '') {
        out.push('');
      }
      out.push(t);
      inDialogue = cue || (!opens && !leaves && inDialogue);
    }
    return out;
  }

  /** Where the script proper starts, and so where the title page stops. */
  function opensBody(t) {
    return /^(int|ext|est|int\.?\/ext|i\/e)[. ]/i.test(t)
      || /^\.[^.]/.test(t)
      || /^FADE IN[:.]?$/i.test(t);
  }

  // The line a printed title page puts between the title and the writers.
  var CREDIT = /^((written|screenplay|story|teleplay|adapted|created)\s+by|by)$/i;
  // A draft line names a draft or is a date; anything else down there is a way
  // of reaching the writer.
  var DRAFT = /\bdrafts?\b|\brevis(ed|ion)\b|^\w+ \d{1,2},? \d{4}$|^\d{4}-\d{2}-\d{2}$/i;

  /** One Fountain title key, with its extra values as indented continuations. */
  function titleKey(name, vals) {
    if (vals.length === 0) return [];
    return [name + ': ' + vals[0]].concat(vals.slice(1).map(function (v) {
      return '   ' + v;
    }));
  }

  /**
   * A printed title page turned into the Fountain keys that produce one.
   *
   * The credit — "Written by", "Screenplay by", a bare "by" — is the hinge:
   * the title is above it and the writers are below, which is the whole layout
   * of a title page and the only part of it that is reliably worded. Without
   * that line there is nothing to read the page by, so nothing is claimed and
   * the lines are left as they were found.
   */
  function titlePage(region) {
    var lines = [];
    for (var i = 0; i < region.length; i++) {
      if (region[i] !== '') lines.push(region[i]);
    }
    var c = -1;
    for (var j = 0; j < lines.length; j++) {
      if (CREDIT.test(lines[j])) { c = j; break; }
    }
    if (c < 0) return null;

    // Below the writers sits the draft and the contact, in either order and
    // under no fixed heading. The first line that reads as a date or a draft
    // ends the writers; everything past it is the footer.
    var title = lines.slice(0, c);
    var authors = [];
    var footer = [];
    for (var k = c + 1; k < lines.length; k++) {
      // The header above page two repeats the title. It belongs to the page
      // it was printed on, not to the writers underneath the credit.
      if (title.indexOf(lines[k]) >= 0) continue;
      if (footer.length === 0 && !DRAFT.test(lines[k])) authors.push(lines[k]);
      else footer.push(lines[k]);
    }
    var dates = footer.filter(function (l) { return DRAFT.test(l); });
    var contact = footer.filter(function (l) { return !DRAFT.test(l); });

    return {
      title: title,
      lines: titleKey('Title', title)
        .concat(titleKey('Credit', [lines[c]]))
        .concat(titleKey('Author', authors))
        .concat(titleKey('Draft date', dates.slice(0, 1)))
        .concat(titleKey('Contact', contact))
    };
  }

  /**
   * A PDF hands over its margins in whatever its producer encoded them as:
   * ordinary spaces, non-breaking spaces, thin or figure spaces, or tabs. All
   * of it is the printed margin, and the margin is the evidence the text was
   * printed — so it is levelled to plain spaces before anything reads it.
   */
  function levelSpaces(text) {
    return text.replace(/\r\n?/g, '\n')
      .replace(/[\u00a0\u1680\u2000-\u200a\u202f\u205f\u3000]/g, ' ')
      .replace(/\t/g, '    ');
  }

  function fromPrinted(text) {
    var lines = levelSpaces(text).split('\n');
    // Text that was written rather than printed goes back exactly as it came,
    // levelling included — the guard promises the paste is untouched.
    if (!looksPrinted(lines)) return text;

    // Drop the margins and the furniture, and close the gap the furniture
    // leaves so the blocks stay exactly one blank line apart.
    var kept = [];
    for (var i = 0; i < lines.length; i++) {
      // A running header carries its page number out at the right margin,
      // three or more spaces clear of whatever it shares the line with.
      // Taking the number off leaves the rest to be judged on its own: a
      // scene heading keeps its slug, and a CONTINUED: that was sharing the
      // line becomes furniture the same as one standing alone.
      var t = lines[i].trim().replace(/\s{3,}\d{1,4}[.:]?$/, '');
      if (isFurniture(t)) continue;
      if (t === '' && kept[kept.length - 1] === '') continue;
      kept.push(t);
    }

    // With the blank lines gone there is nothing left to mark where one block
    // ends, so they are put back before anything tries to read blocks.
    var blanks = 0;
    for (var z = 0; z < kept.length; z++) {
      if (kept[z] === '') blanks++;
    }
    if (blanks * 8 < kept.length - blanks) kept = restoreBreaks(kept);

    // A blank at the head has to survive, because it is the one separating the
    // paste from whatever the caret was sitting under. Losing it lands the
    // first scene heading against the line above, where it reads as action.
    var lead = kept[0] === '' ? '\n' : '';

    // Everything above the first scene heading is the title page, and none of
    // it is prose. Left in the body it reads as dialogue — a title is an
    // uppercase line with more lines under it, which is also exactly what a
    // character cue is — so it is taken out before any block is shaped.
    var body = 0;
    while (body < kept.length && !opensBody(kept[body])) body++;
    // A title page sits above the first scene heading — but so does a scene
    // pasted from the middle of a script, where those lines are dialogue and
    // must still be reshaped. The credit line is what tells them apart, so
    // without one there is no title page and every line is script.
    var page = titlePage(kept.slice(0, body));
    var head = [];
    if (!page) {
      body = 0;
    } else {
      head = page.lines;
      lead = '';   // A title page only parses as one from the very first line.
      // That same header repeats above every page after the first. Knowing the
      // title is what makes the repeats recognisable as furniture.
      for (var f = body; f < kept.length; f++) {
        if (page.title.indexOf(kept[f]) >= 0) kept[f] = '';
      }
    }

    var blocks = [];
    for (var a = body; a < kept.length; a++) {
      if (kept[a] === '') continue;
      var b = a;
      while (b < kept.length && kept[b] !== '') b++;
      blocks.push(reflow(kept.slice(a, b)));
      a = b;
    }
    if (head.length) blocks.unshift(head);

    // A speech the page break split comes back as a second cue marked
    // (CONT'D). It is one speech — the printer divided it, not the writer —
    // so it is sewn back onto the line the break interrupted.
    var out = [];
    for (var k = 0; k < blocks.length; k++) {
      var blk = blocks[k];
      var prev = out[out.length - 1];
      var m = /^(.*?)\s*\(\s*CONT'?D\s*\)$/i.exec(blk[0]);
      if (m && blk.length > 1 && prev && prev.length > 1
        && isCharacter(prev[0]) && prev[0] === m[1].trim()) {
        var tail = prev[prev.length - 1];
        if (isParen(tail) || isParen(blk[1])) prev.push(blk[1]);
        else prev[prev.length - 1] = tail + ' ' + blk[1];
        for (var q = 2; q < blk.length; q++) prev.push(blk[q]);
        continue;
      }
      out.push(blk);
    }

    return lead + out.map(function (blk) { return blk.join('\n'); }).join('\n\n');
  }

  return {
    isUpper: isUpper,
    isCharacter: isCharacter,
    TRANSITION_END: TRANSITION_END,
    fromPrinted: fromPrinted,
    // Exposed for the tests, which pin these separately from the whole run:
    // the guard that decides whether to touch the paste at all, and the two
    // reconstructions that do the guessing.
    looksPrinted: looksPrinted,
    restoreBreaks: restoreBreaks,
    titlePage: titlePage
  };
}());

// Node runs this file for tests/paste.test.js; the browser gets the global.
if (typeof module !== 'undefined' && module.exports) module.exports = Paste;
