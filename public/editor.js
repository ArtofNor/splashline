/*
 * Live-formatting Fountain editor.
 *
 * The contenteditable surface holds one <div class="ln ..."> per line. Its
 * plain text is always valid Fountain (that's what we save); the line classes
 * are applied live so the surface looks like a formatted screenplay as you
 * type. Classification mirrors src/FountainParser.php so screen and editor
 * agree on what every line is.
 *
 * Design notes:
 *  - Typing only rewrites class names, never text nodes, so the caret is never
 *    disturbed and IME/Lao composition is safe.
 *  - Enter and paste are handled manually to keep the strict one-div-per-line
 *    structure that classification relies on.
 */
(function () {
  'use strict';

  var editor = document.getElementById('editor');
  var source = document.getElementById('source');
  var filenameEl = document.getElementById('filename');
  var originalEl = document.getElementById('original');
  var kindEl = document.getElementById('kind');
  var statusEl = document.getElementById('status');

  editor.setAttribute('data-placeholder', 'Start typing…  e.g.  INT. HOUSE - DAY');

  // --- Classification (mirrors FountainParser::parseBody) --------------------

  function isUpper(s) {
    return /[A-Za-z]/.test(s) && !/[a-z]/.test(s);
  }

  function isCharacter(t) {
    if (t.charAt(0) === '@') return true;
    if (!/[A-Za-z]/.test(t)) return false;
    var core = t.replace(/\(.*?\)/g, '').replace(/[\^ ]+$/, '');
    return isUpper(core);
  }

  // Sahtu comic scripts classify by Markdown heading level instead. A script
  // started from the Comic button says so outright, which is the only thing
  // that works on an empty page: the house form (bare "#" page, unlabelled
  // "##" panel, marker-less cues) is deliberately ambiguous with Fountain
  // sections, so a young document cannot be sniffed at all. Otherwise
  // detection is content-first (mirrors sniff_comic() in index.php), with the
  // extension as the last word.
  function isComicDoc(text) {
    if (kindEl.value === 'comic') return true;
    if (kindEl.value === 'screenplay') return false;
    if (/^#\s+(INT|EXT|EST|I\/E)[.\s]/im.test(text)
      || /^##\s+\*\*/m.test(text)
      || /^###\s+[^:\n]+:/m.test(text)) return true;
    return /\.md$/i.test(filenameEl.value.trim() || originalEl.value);
  }

  // A bare cue opens a beat without the ###, mirroring isCue() in
  // ComicParser. Caps is what keeps prose out ("The convention: ..."), and
  // panel-type keywords end in a period rather than a colon, so SPLASH. and
  // BIG PANEL. are unaffected.
  function isCue(t) {
    var m = /^([^:\n]{1,40}):/.exec(t);
    return !!m && isUpper(m[1]);
  }

  // Levels come from each line's absorbed marker rather than its text, since
  // in a comic doc the #s live in data-mark and never on screen (see absorb()).
  /**
   * Which of the three kinds of beat a cue opens — mirrors parseBeat() in
   * ComicParser, including the balloon extension on the tag, so "CAPTION
   * (NOI):" is a caption and not a character called CAPTION (NOI). Dialogue is
   * the default and carries no extra class; it's the one that looks plain.
   */
  function beatType(t) {
    var m = /^([^:]+):/.exec(t);
    if (!m) return '';
    var tag = m[1].replace(/\s*\([^)]*\)\s*$/, '').trim().toUpperCase();
    if (tag === 'SFX') return ' c-sfx';
    if (tag.indexOf('CAPTION') === 0) return ' c-caption';
    return '';
  }

  function classifyAllComic(texts, marks, labels) {
    var out = new Array(texts.length);
    var last = null;    // Last non-blank class, for dialogue continuations.
    var kind = '';      // Its beat type, so a continuation keeps the styling.
    var inPanel = false; // Bare cues only read as beats inside a panel.
    for (var i = 0; i < texts.length; i++) {
      var t = texts[i].trim();
      var mk = marks[i];
      // A blank line is a paragraph break, not a topic change: it leaves `last`
      // alone so the speech below still reads as the same speech. That mirrors
      // $pendingBlank in ComicParser, which appends across it with "\n\n".
      if (t === '' && mk === '') { out[i] = 'blank'; continue; }
      if (mk === '###') { kind = beatType(t); out[i] = 'c-beat' + kind; }
      // A label the writer spelled out is drawn from data-label instead of the
      // counter, mirroring parsePanel(), which still reads those files.
      else if (mk === '##') { out[i] = labels[i] ? 'c-panel c-panel-own' : 'c-panel'; inPanel = true; }
      // A slug the writer numbered themselves ("PAGE TWO: ...") suppresses our
      // counter, the same call ComicRenderer makes before printing "PAGE n".
      else if (mk === '#') { out[i] = /^PAGE\b/i.test(t) ? 'c-page c-page-own' : 'c-page'; inPanel = false; }
      else if (/^\[.*\]$/.test(t)) out[i] = 'c-note';
      else if (inPanel && isCue(t)) { kind = beatType(t); out[i] = 'c-beat' + kind; }
      // A plain line after a beat continues that speech (multiline dialogue),
      // and a continued caption is still a caption.
      else if (last === 'c-beat' || last === 'c-beat-cont') out[i] = 'c-beat-cont' + kind;
      else out[i] = 'c-desc';
      last = out[i].split(' ')[0];
    }
    return out;
  }

  function classifyAll(texts) {
    var out = new Array(texts.length);
    var inDialogue = false;
    for (var i = 0; i < texts.length; i++) {
      var t = texts[i].trim();
      var prevBlank = i === 0 || texts[i - 1].trim() === '';
      var nextBlank = i === texts.length - 1 || texts[i + 1].trim() === '';

      if (t === '') { out[i] = 'blank'; inDialogue = false; continue; }
      if (/^={3,}$/.test(t)) { out[i] = 'page-break'; inDialogue = false; continue; }
      if (/^#{1,6}(\s|$)/.test(t)) { out[i] = 'section'; inDialogue = false; continue; }
      if (t.charAt(0) === '=') { out[i] = 'synopsis'; inDialogue = false; continue; }
      if (/^>\s*.*<$/.test(t)) { out[i] = 'centered'; inDialogue = false; continue; }
      if (t.charAt(0) === '>') { out[i] = 'transition'; inDialogue = false; continue; }
      if (prevBlank && nextBlank && isUpper(t) && /TO:$/.test(t)) { out[i] = 'transition'; inDialogue = false; continue; }
      if (/^\.[^.]/.test(t)) { out[i] = 'scene-heading'; inDialogue = false; continue; }
      if (prevBlank && /^(int|ext|est|int\.?\/ext|i\/e)[. ]/i.test(t)) { out[i] = 'scene-heading'; inDialogue = false; continue; }
      if (t.charAt(0) === '~') { out[i] = 'lyric'; inDialogue = false; continue; }
      if (prevBlank && !nextBlank && isCharacter(t)) { out[i] = 'character'; inDialogue = true; continue; }
      if (inDialogue) { out[i] = /^\(.*\)$/.test(t) ? 'parenthetical' : 'dialogue'; continue; }
      out[i] = 'action';
    }
    return out;
  }

  // --- DOM helpers -----------------------------------------------------------

  function lineDivs() {
    var out = [];
    for (var n = editor.firstChild; n; n = n.nextSibling) {
      if (n.nodeType === 1) out.push(n);
    }
    return out;
  }

  function setDivText(div, text) {
    div.textContent = '';
    if (text === '') div.appendChild(document.createElement('br'));
    else div.appendChild(document.createTextNode(text));
  }

  // --- Markers ---------------------------------------------------------------
  // In a comic doc the heading marks are structure, not text: a line holds its
  // "#"/"##"/"###" in data-mark and shows only the body, so the writer sees
  // PAGE 1 and PANEL 1 (drawn by CSS counters) instead of punctuation. getText
  // puts the marks back, which keeps the saved file plain Markdown and keeps
  // ComicParser the single source of truth for what the format is.

  function markOf(div) {
    return div.getAttribute('data-mark') || '';
  }

  function setMark(div, mark) {
    if (mark) div.setAttribute('data-mark', mark);
    else div.removeAttribute('data-mark');
  }

  // Put an absorbed label back into the text. A line that stops being a panel
  // has no business hiding a panel label, and the words are the writer's — so
  // they return to the page rather than being dropped.
  function releaseLabel(div) {
    var raw = div.getAttribute('data-raw');
    if (!raw) return 0;
    div.removeAttribute('data-raw');
    div.removeAttribute('data-label');
    setDivText(div, raw + div.textContent);
    return raw.length;
  }

  // A panel label the writer spelled out ("## **PANEL 1:** ...") is chrome too,
  // so it comes off the text the same way the marker does — data-label is what
  // gets drawn, data-raw is the exact prefix that goes back on save. Keeping
  // the raw form is what lets getText be lossless: "**PANEL 1:**" carries its
  // colon inside the asterisks, and guessing at that on the way out would
  // rewrite the writer's file. Match mirrors parsePanel() in ComicParser.
  function absorbLabel(div) {
    var m = /^\*\*(.+?)\*\*:?[ \t]*/.exec(div.textContent);
    if (!m) return 0;
    div.setAttribute('data-label', m[1].trim().replace(/:$/, ''));
    div.setAttribute('data-raw', m[0]);
    setDivText(div, div.textContent.slice(m[0].length));
    return m[0].length;
  }

  // Move a leading marker off the text and into data-mark. Returns how many
  // characters left the text, so callers can keep the caret on its character.
  function absorb(div) {
    var m = /^[ \t]*(#{1,3})[ \t]*/.exec(div.textContent);
    if (!m) return 0;
    setMark(div, m[1]);
    setDivText(div, div.textContent.slice(m[0].length));
    return m[0].length + (m[1] === '##' ? absorbLabel(div) : 0);
  }

  function getText() {
    return lineDivs().map(function (d) {
      var mark = markOf(d);
      var body = (d.getAttribute('data-raw') || '') + d.textContent;
      if (!mark) return body;
      return body === '' ? mark : mark + ' ' + body;
    }).join('\n');
  }

  // Ensure the surface is a flat list of line divs with at least one line.
  function ensureStructure() {
    if (!editor.firstChild) {
      var d = document.createElement('div');
      d.className = 'ln';
      d.appendChild(document.createElement('br'));
      editor.appendChild(d);
      return;
    }
    // Wrap any stray top-level node (text/br) that the browser may leave behind.
    var kids = [];
    for (var n = editor.firstChild; n; n = n.nextSibling) kids.push(n);
    for (var i = 0; i < kids.length; i++) {
      var node = kids[i];
      if (node.nodeType === 1 && node.tagName === 'DIV') continue;
      var wrap = document.createElement('div');
      wrap.className = 'ln';
      editor.insertBefore(wrap, node);
      wrap.appendChild(node);
    }
  }

  // Comic mode is sticky once entered. A document that starts out ambiguous
  // (kind=auto, nothing typed yet) is read as a screenplay until its first
  // marker arrives; at that moment every line absorbs its marks at once, which
  // is also what makes a pasted-in comic script snap into shape.
  var comicMode = false;

  function syncComicMode() {
    if (comicMode) return true;
    if (!isComicDoc(getText())) return false;
    comicMode = true;
    editor.classList.add('is-comic');
    lineDivs().forEach(absorb);
    return true;
  }

  /**
   * Give a line the spans its class needs. A beat is two columns — the cue and
   * the speech — and only the speech carries the lettering conventions, so a
   * sound effect can be heavy and tracked without dragging its cue out of the
   * column. Preview splits the same way, into .beat-label and .beat-text.
   *
   * Nothing here hides or invents a character: the line still reads back as
   * exactly its own source, which is what keeps getText and the caret honest.
   */
  function paint(div, cls) {
    var text = div.textContent;
    if (cls.indexOf('c-beat') !== 0 || text === '') {
      // Flatten spans a previous class left behind, so a line that stops being
      // a beat stops being two columns.
      if (div.firstElementChild && div.firstElementChild.tagName !== 'BR') {
        setDivText(div, text);
      }
      return;
    }

    // The cue keeps the tab, so the column still comes from tab-size.
    var tab = text.indexOf('\t');
    var cue = cls === 'c-beat' || cls.indexOf('c-beat ') === 0 ? tab + 1 : 0;

    div.textContent = '';
    if (cue > 0) {
      div.appendChild(span('cue', text.slice(0, cue)));
    }
    div.appendChild(span('say', text.slice(cue)));
  }

  function span(cls, text) {
    var s = document.createElement('span');
    s.className = cls;
    s.textContent = text;
    return s;
  }

  function restyle() {
    ensureStructure();
    var comic = syncComicMode();
    var divs = lineDivs();
    var texts = divs.map(function (d) { return d.textContent; });
    var cls = comic
      ? classifyAllComic(texts, divs.map(markOf),
          divs.map(function (d) { return d.getAttribute('data-label'); }))
      : classifyAll(texts);
    // Repainting replaces a line's child nodes, so the caret is saved and put
    // back — but only when something actually needs repainting, which keeps
    // ordinary typing from disturbing a selection it has no business touching.
    var stale = [];
    for (var i = 0; i < divs.length; i++) {
      var name = 'ln ' + cls[i];
      if (divs[i].className !== name) divs[i].className = name;
      var sig = name + ' ' + texts[i];
      if (divs[i].psig !== sig) stale.push(i);
    }

    if (stale.length > 0) {
      var c = caretLine();
      for (var j = 0; j < stale.length; j++) {
        var k = stale[j];
        paint(divs[k], cls[k]);
        divs[k].psig = 'ln ' + cls[k] + ' ' + divs[k].textContent;
      }
      if (c) placeCaret(c.div, c.offset);
    }

    editor.classList.toggle('is-empty', getText().trim() === '');
  }

  function build(text) {
    editor.innerHTML = '';
    var lines = text.replace(/\r\n?/g, '\n').split('\n');
    for (var i = 0; i < lines.length; i++) {
      var div = document.createElement('div');
      div.className = 'ln';
      setDivText(div, lines[i]);
      editor.appendChild(div);
    }
    restyle();
  }

  // --- Caret helpers ---------------------------------------------------------

  // Return {div, offset} for the current caret, where offset is a character
  // index within the containing line div.
  function posOf(node, offset) {
    var div = node;
    if (div.nodeType !== 1) div = div.parentNode;
    while (div && div.parentNode !== editor) div = div.parentNode;
    if (!div || div.parentNode !== editor) return null;

    var probe = document.createRange();
    probe.selectNodeContents(div);
    probe.setEnd(node, offset);
    return { div: div, offset: probe.toString().length };
  }

  function caretLine() {
    var sel = window.getSelection();
    if (!sel.rangeCount) return null;
    var range = sel.getRangeAt(0);
    return posOf(range.startContainer, range.startOffset);
  }

  // Enter, Tab and paste rewrite line text by hand, so they have to do what
  // the browser would have done first: replace the selection. Reading only the
  // range's start treats selected text as a collapsed caret, which left the
  // selection sitting there while the edit landed beside it — pasting over a
  // selected word kept the word.
  function deleteSelection() {
    var sel = window.getSelection();
    if (!sel.rangeCount || sel.isCollapsed) return;
    var range = sel.getRangeAt(0);
    var from = posOf(range.startContainer, range.startOffset);
    var to = posOf(range.endContainer, range.endOffset);
    if (!from || !to) return;

    var head = from.div.textContent.slice(0, from.offset);
    var tail = to.div.textContent.slice(to.offset);
    if (from.div !== to.div) { // A selection across lines closes the gap.
      for (var n = from.div.nextSibling; n && n !== to.div; ) {
        var next = n.nextSibling;
        editor.removeChild(n);
        n = next;
      }
      editor.removeChild(to.div);
    }
    setDivText(from.div, head + tail);
    restyle();
    placeCaret(from.div, head.length);
  }

  /**
   * The text node holding a character offset into a line, and the offset
   * within it. A painted line is several spans rather than one text node (see
   * paint()), so this walks them instead of assuming div.firstChild.
   */
  function seek(div, offset) {
    var walk = document.createTreeWalker(div, NodeFilter.SHOW_TEXT);
    var node;
    var seen = 0;
    while ((node = walk.nextNode()) !== null) {
      if (seen + node.length >= offset) {
        return { node: node, offset: offset - seen };
      }
      seen += node.length;
    }
    return null;
  }

  function selectRange(div, start, end) {
    var a = seek(div, start);
    var b = seek(div, end);
    if (!a || !b) { placeCaret(div, start); return; }
    var r = document.createRange();
    r.setStart(a.node, a.offset);
    r.setEnd(b.node, b.offset);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(r);
  }

  function placeCaret(div, offset) {
    var at = seek(div, offset);
    var r = document.createRange();
    if (at) r.setStart(at.node, at.offset);
    else r.setStart(div, 0);
    r.collapse(true);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(r);
  }

  // --- Structural edits ------------------------------------------------------

  // The three levels the Sahtu comic format uses, mirroring classifyAllComic:
  // # page, ## panel, and a beat — which wears no marker at all, since the cue
  // is what makes it one. Alt+3 therefore clears the line's level.
  var COMIC_LEVELS = { Digit1: '#', Digit2: '##', Digit3: '' };

  /**
   * Set the caret line's heading level, replacing whatever level it had, so
   * Alt+2 on a page line makes it a panel. Only the mark moves — the body text
   * and the caret both stay exactly where they were.
   */
  function setComicLevel(mark) {
    var c = caretLine();
    if (!c || markOf(c.div) === mark) return false;
    setMark(c.div, mark);
    var back = mark === '##' ? 0 : releaseLabel(c.div);
    placeCaret(c.div, c.offset + back);
    return true;
  }

  /**
   * Enter. In a comic doc it ends a paragraph rather than just a line: the new
   * line gets a blank one above it, because ComicParser joins adjacent lines
   * into a single paragraph and only breaks on a blank ("\n\n" vs " ", :127).
   * Without the blank the screen would show two paragraphs where Preview shows
   * one run-on. The blank is drawn as a gap rather than an empty row, so what
   * you see is the spacing and what's saved is the Markdown that makes it.
   *
   * Splitting mid-line stays a plain split — that's moving text, not ending a
   * thought — and so does Enter on a line that's already blank, which would
   * otherwise pile blanks up.
   */
  function splitLineAtCaret() {
    deleteSelection();
    var c = caretLine();
    if (!c) return false;
    var text = c.div.textContent;
    var before = text.slice(0, c.offset);
    var after = text.slice(c.offset);

    var para = comicMode && after === '' && before.trim() !== '';
    setDivText(c.div, before);

    var ref = c.div;
    if (para) {
      var gap = document.createElement('div');
      gap.className = 'ln';
      setDivText(gap, '');
      editor.insertBefore(gap, ref.nextSibling);
      ref = gap;
    }

    var nd = document.createElement('div');
    nd.className = 'ln';
    setDivText(nd, after);
    editor.insertBefore(nd, ref.nextSibling);
    restyle();
    placeCaret(nd, 0);
    return true;
  }

  function insertMultiline(text) {
    deleteSelection();
    var c = caretLine();
    if (!c) return;
    var lineText = c.div.textContent;
    var before = lineText.slice(0, c.offset);
    var after = lineText.slice(c.offset);
    var parts = text.replace(/\r\n?/g, '\n').split('\n');

    if (parts.length === 1) {
      setDivText(c.div, before + parts[0] + after);
      var eaten = (comicMode && before === '' && !markOf(c.div)) ? absorb(c.div) : 0;
      restyle();
      placeCaret(c.div, Math.max(0, (before + parts[0]).length - eaten));
      return;
    }

    setDivText(c.div, before + parts[0]);
    var ref = c.div;
    var fresh = [];
    // The caret's own line joins the absorbing only when the paste landed at
    // its head — otherwise the marker isn't at the start of a line at all, it's
    // a "#" dropped into the middle of someone's sentence.
    if (before === '' && !markOf(c.div)) fresh.push(c.div);
    for (var i = 1; i < parts.length; i++) {
      var nd = document.createElement('div');
      nd.className = 'ln';
      var last = i === parts.length - 1;
      setDivText(nd, last ? parts[i] + after : parts[i]);
      editor.insertBefore(nd, ref.nextSibling);
      fresh.push(nd);
      ref = nd;
    }
    // Pasted Markdown arrives with its markers still in the text; absorb them
    // so a script dropped in mid-session looks like one already open.
    if (comicMode) fresh.forEach(absorb);
    restyle();
    // The caret belongs where the pasted text ends, which is whatever is left
    // of the last line once `after` is discounted — measured after absorbing,
    // so a swallowed marker doesn't push it past the end.
    placeCaret(ref, Math.max(0, ref.textContent.length - after.length));
  }

  // --- Inline emphasis -------------------------------------------------------

  /**
   * Cmd/Ctrl+B wraps the selection in Markdown bold — the same **...** both
   * parsers already read, so the shortcut writes the source rather than a
   * separate notion of formatting.
   *
   * It toggles: the marks are looked for inside the selection and just outside
   * it, so pressing the key twice on the same words strips them instead of
   * burying them in a second pair. Selecting across lines is left alone, since
   * ** doesn't span a line break in either parser.
   */
  function toggleBold() {
    var sel = window.getSelection();
    if (!sel.rangeCount) return false;
    var range = sel.getRangeAt(0);
    var from = posOf(range.startContainer, range.startOffset);
    var to = posOf(range.endContainer, range.endOffset);
    if (!from || !to || from.div !== to.div) return false;

    var text = from.div.textContent;
    var a = from.offset;
    var b = to.offset;
    var inner = text.slice(a, b);
    var out;

    if (inner.length >= 4 && inner.slice(0, 2) === '**' && inner.slice(-2) === '**') {
      out = [text.slice(0, a) + inner.slice(2, -2) + text.slice(b), a, b - 4];
    } else if (a >= 2 && text.slice(a - 2, a) === '**' && text.slice(b, b + 2) === '**') {
      out = [text.slice(0, a - 2) + inner + text.slice(b + 2), a - 2, b - 2];
    } else {
      out = [text.slice(0, a) + '**' + inner + '**' + text.slice(b), a + 2, b + 2];
    }

    setDivText(from.div, out[0]);
    restyle();
    // An empty selection leaves the caret between the new marks, ready to type.
    if (out[1] === out[2]) placeCaret(from.div, out[1]);
    else selectRange(from.div, out[1], out[2]);
    return true;
  }

  // --- Typing shortcuts ------------------------------------------------------

  /**
   * "# " at the head of a line becomes the page level and the characters
   * vanish; "## " the panel level. This is the one place typing rewrites a
   * text node, which the rest of the editor avoids to protect the caret and
   * IME — it's safe here because it fires only on an ASCII space typed
   * directly after a line-leading marker (never mid-composition, since the
   * input handler returns while composing) and puts the caret back by hand.
   */
  function absorbTyped() {
    var c = caretLine();
    if (!c) return;
    if (!/^[ \t]*#{1,3} $/.test(c.div.textContent.slice(0, c.offset))) return;
    var eaten = absorb(c.div);
    placeCaret(c.div, Math.max(0, c.offset - eaten));
  }

  /**
   * A cue types its own tab: closing "NOI:" or "NOI (WHISPER):" jumps the
   * caret to the dialogue column so the writer can keep going. Only at the end
   * of a line that hasn't got a tab already, and Backspace takes it straight
   * back if it fires on something meant as prose.
   */
  function autoTab() {
    var c = caretLine();
    if (!c) return;
    var text = c.div.textContent;
    if (c.offset !== text.length || text.indexOf('\t') !== -1) return;
    if (text.slice(-1) !== ':' || !isCue(text)) return;
    setDivText(c.div, text + '\t');
    placeCaret(c.div, text.length + 1);
  }

  // --- Events ----------------------------------------------------------------

  var composing = false;
  var dirty = false;

  editor.addEventListener('compositionstart', function () { composing = true; });
  editor.addEventListener('compositionend', function () {
    composing = false;
    restyle();
    markDirty();
  });

  editor.addEventListener('input', function () {
    if (composing) return;
    if (comicMode) { absorbTyped(); autoTab(); }
    restyle();
    markDirty();
  });

  editor.addEventListener('keydown', function (e) {
    // Cmd/Ctrl+B. Always swallowed, even when the selection is one this can't
    // act on: the browser's own bold inserts a <b> element, and a line here is
    // one flat string of the writer's source with no elements in it at all.
    if ((e.metaKey || e.ctrlKey) && !e.altKey && (e.key === 'b' || e.key === 'B')
        && !composing) {
      e.preventDefault();
      if (toggleBold()) markDirty();
      return;
    }

    // Backspace at the head of a marked line takes the level off instead of
    // eating into the line above. An invisible marker needs a visible way out,
    // and this is the same key that would have deleted it when it was text.
    if (e.key === 'Backspace' && !composing && comicMode) {
      var c = caretLine();
      if (c && c.offset === 0 && markOf(c.div) && window.getSelection().isCollapsed) {
        e.preventDefault();
        setMark(c.div, '');
        releaseLabel(c.div);
        restyle();
        placeCaret(c.div, 0);
        markDirty();
        return;
      }
    }

    if (e.key === 'Enter' && !e.shiftKey && !composing) {
      if (splitLineAtCaret()) {
        e.preventDefault();
        markDirty();
      }
      return;
    }

    // Tab types a tab. The cue separator in a comic script is a literal tab,
    // so the key has to reach the document rather than move focus. Shift+Tab
    // is deliberately left alone: it's the way out of the editor for anyone
    // working from the keyboard, so this never becomes a focus trap.
    if (e.key === 'Tab' && !e.shiftKey && !e.altKey && !e.ctrlKey && !e.metaKey
        && !composing && comicMode) {
      e.preventDefault();
      insertMultiline('\t');
      markDirty();
      return;
    }

    // Comic heading levels. Alt is the only modifier free on every platform:
    // Ctrl+digit and Cmd+digit are reserved by browsers for tab switching and
    // can't be preventDefault'd. e.code because Option+1 on macOS reports
    // e.key as '¡'.
    // hasOwnProperty, not truthiness: the beat level is the empty string.
    if (e.altKey && !e.ctrlKey && !e.metaKey && !composing
        && Object.prototype.hasOwnProperty.call(COMIC_LEVELS, e.code)
        && comicMode) {
      // Unconditionally, before the no-op check: a second Alt+3 on a line
      // that is already a beat still has to swallow the key, or macOS types £.
      e.preventDefault();
      if (setComicLevel(COMIC_LEVELS[e.code])) {
        restyle();
        markDirty();
      }
    }
  });

  editor.addEventListener('paste', function (e) {
    e.preventDefault();
    var text = (e.clipboardData || window.clipboardData).getData('text');
    insertMultiline(text);
    markDirty();
  });

  // --- Save ------------------------------------------------------------------

  function setStatus(msg, isError) {
    statusEl.textContent = msg;
    statusEl.classList.toggle('error', !!isError);
  }

  var autosaveTimer = null;

  function markDirty() {
    dirty = true;
    if (autosaveTimer) clearTimeout(autosaveTimer);
    if (filenameEl.value.trim()) {
      autosaveTimer = setTimeout(save, 1200);
    } else {
      // Content but nowhere to put it yet — nudge instead of silently holding.
      setStatus('Give it a title to start saving', true);
    }
  }

  var saving = false; // Single-flight: overlapping saves race the rename guard.

  function save() {
    if (autosaveTimer) { clearTimeout(autosaveTimer); autosaveTimer = null; }
    if (!dirty || saving) return;
    var filename = filenameEl.value.trim();
    if (!filename) {
      setStatus('Give it a title to start saving', true);
      return;
    }
    saving = true;
    setStatus('Saving…');

    var body = new URLSearchParams();
    body.set('action', 'save');
    body.set('ajax', '1');
    body.set('filename', filename);
    body.set('original', originalEl.value);
    body.set('kind', kindEl.value); // Breaks the extension tie before the content can.
    body.set('content', getText());
    var sent = body.get('content');

    fetch('?action=save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    })
      .then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
          return { ok: res.ok, status: res.status, data: data };
        });
      })
      .then(function (r) {
        if (r.ok && r.data.ok) {
          originalEl.value = r.data.file;
          filenameEl.value = r.data.file;
          history.replaceState(null, '', '?action=edit&f=' + encodeURIComponent(r.data.file));
          document.title = 'Splashline · ' + r.data.file;
          // Only clean if nothing changed while the request was in flight.
          if (getText() === sent) {
            dirty = false;
            setStatus('Saved ✓');
          } else {
            setStatus('Saving…');
            autosaveTimer = setTimeout(save, 300);
          }
        } else {
          setStatus(r.data.error || ('Error ' + r.status), true);
        }
      })
      .catch(function () { setStatus('Network error — not saved', true); })
      .finally(function () { saving = false; });
  }

  // The title saves when committed (Enter or clicking away), not per
  // keystroke — otherwise a pause mid-word would create "Nights-En.fountain".
  // Enter just moves focus; the blur handler is the single save path (calling
  // save() here too fired twice and the second request tripped the 409 guard).
  filenameEl.addEventListener('input', function () { dirty = true; });
  filenameEl.addEventListener('blur', function () {
    if (dirty && filenameEl.value.trim()) save();
  });
  filenameEl.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); editor.focus(); }
  });

  document.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
      e.preventDefault();
      save();
    }
  });

  window.addEventListener('beforeunload', function (e) {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });

  // --- Init ------------------------------------------------------------------

  build(source.value);
  editor.focus();
  // A new script opens on its title stub, so the caret belongs after "Title: "
  // rather than in front of it. An existing script opens at the very top.
  var firstLine = lineDivs()[0];
  placeCaret(firstLine, originalEl.value ? 0 : firstLine.textContent.length);
})();
