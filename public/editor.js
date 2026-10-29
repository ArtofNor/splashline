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
 *  - A line's source is the writer's own text. Where the view differs from it
 *    — a comic's heading marks, a panel label, the asterisks around emphasis —
 *    the source is kept on the line and sourceOf() is what saving, parsing and
 *    editing all read. getText() reassembles the file from those.
 *  - The line the caret is in is never rendered: it holds exactly its source,
 *    a comic's # and ## included (see `live`). Typing therefore always lands
 *    in plain text, which is what keeps the caret and IME/Lao composition safe
 *    even though other lines are rebuilt as spans, and it is how a writer
 *    learns the marks: the line they are on shows what they would type.
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

  // The three the live classifier shares with the paste formatter, which
  // owns them because it is the one with tests. See public/paste.js.
  var isUpper = Paste.isUpper;
  var isCharacter = Paste.isCharacter;
  var TRANSITION_END = Paste.TRANSITION_END;

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

  // Levels come from each line's absorbed marker, or from the text of the line
  // being edited, which holds its marks as typed (see levelOf()).
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
      // A spread takes two page numbers (Support::pageSpan); c-spread lets the
      // stylesheet count it twice.
      else if (mk === '#') {
        out[i] = (/^PAGE\b/i.test(t) ? 'c-page c-page-own' : 'c-page') + (/\[SPREAD\]\s*$/i.test(t) ? ' c-spread' : '');
        inPanel = false;
      }
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
      if (prevBlank && nextBlank && isUpper(t) && TRANSITION_END.test(t)) { out[i] = 'transition'; inDialogue = false; continue; }
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

  // Writing plain text into a line also makes it the line's source again: the
  // view and the source are only ever allowed to differ through paint().
  function setDivText(div, text) {
    div.removeAttribute('data-src');
    div.textContent = '';
    if (text === '') div.appendChild(document.createElement('br'));
    else div.appendChild(document.createTextNode(text));
  }

  /**
   * What a line really says. A rendered line shows fewer characters than it
   * holds — **bold** loses its asterisks — so the source is kept on the side
   * and everything that saves, parses or edits reads it from here rather than
   * from what happens to be on screen.
   */
  function sourceOf(div) {
    var src = div.getAttribute('data-src');
    return src === null ? div.textContent : src;
  }

  /**
   * The three asterisk wrappers both parsers read, longest first so that
   * ***both*** is never seen as **bo** with a stray asterisk on each end.
   * The count of asterisks is the emphasis: one italic, two bold, three both.
   */
  var EMPH = /\*\*\*([^*]+)\*\*\*|\*\*([^*]+)\*\*|\*([^*]+)\*/g;

  /** The text a match wraps, and how many asterisks are doing the wrapping. */
  function marks(m) {
    var inner = m[1] || m[2] || m[3];
    return { inner: inner, n: (m[0].length - inner.length) / 2 };
  }

  /** Append text to a node, emphasised where it is wrapped in asterisks. */
  function rich(parent, text, hide) {
    var last = 0;
    var m;
    EMPH.lastIndex = 0;
    while ((m = EMPH.exec(text)) !== null) {
      if (m.index > last) {
        parent.appendChild(document.createTextNode(text.slice(last, m.index)));
      }
      var e = marks(m);
      var node = document.createElement(e.n === 1 ? 'em' : 'strong');
      if (e.n === 3) {
        // Bold and italic together, nested the way the renderers nest them.
        var inner = document.createElement('em');
        inner.textContent = hide ? e.inner : m[0];
        node.appendChild(inner);
      } else {
        node.textContent = hide ? e.inner : m[0];
      }
      parent.appendChild(node);
      last = m.index + m[0].length;
    }
    if (last < text.length) {
      parent.appendChild(document.createTextNode(text.slice(last)));
    }
  }

  /**
   * A rendered offset back onto the source, adding in the asterisks the view
   * hid. Needed the moment the caret arrives on a rendered line: the markers
   * come back, every character after them shifts, and the caret has to shift
   * with them or it lands somewhere the writer didn't click.
   */
  function toSource(src, offset) {
    var seen = 0;
    var last = 0;
    var m;
    EMPH.lastIndex = 0;
    while ((m = EMPH.exec(src)) !== null) {
      var e = marks(m);
      var plain = m.index - last;
      if (seen + plain >= offset) return last + (offset - seen);
      seen += plain;
      if (seen + e.inner.length >= offset) return m.index + e.n + (offset - seen);
      seen += e.inner.length;
      last = m.index + m[0].length;
    }
    return last + (offset - seen);
  }

  /**
   * Put a line back to plain source before a structural edit, translating an
   * offset into it. Edits work on what the writer wrote, never on the view.
   */
  function raw(div, offset) {
    if (div.getAttribute('data-src') === null) return offset;
    var src = sourceOf(div);
    var at = toSource(src, offset);
    setDivText(div, src);
    div.psig = null;
    return at;
  }

  // --- Markers ---------------------------------------------------------------
  // In a comic doc the heading marks are structure, not text: a line holds its
  // "#"/"##"/"###" in data-mark and shows only the body, so the writer sees
  // PAGE 1 and PANEL 1 (drawn by CSS counters) instead of punctuation. getText
  // puts the marks back, which keeps the saved file plain Markdown and keeps
  // ComicParser the single source of truth for what the format is.
  //
  // The line being edited is the exception. It holds its marks as text, the
  // way **bold** shows its asterisks there, so the # and ## a writer sees are
  // the ones they type and delete. release() puts them on the line when the
  // caret arrives and absorb() takes them off again when it leaves.

  // A heading mark as ComicParser reads one: one to three #s, then whitespace
  // or the end of the line. "#hashtag" is text.
  var MARK = /^[ \t]*(#{1,3})(?:[ \t]+|$)/;

  function markOf(div) {
    return div.getAttribute('data-mark') || '';
  }

  function setMark(div, mark) {
    if (mark) div.setAttribute('data-mark', mark);
    else div.removeAttribute('data-mark');
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
    var src = sourceOf(div);
    var m = markOf(div) ? null : MARK.exec(src);
    if (!m) return 0;
    setMark(div, m[1]);
    setDivText(div, src.slice(m[0].length));
    div.psig = null;
    return m[0].length + (m[1] === '##' ? absorbLabel(div) : 0);
  }

  /**
   * The other way: put a line's absorbed mark and label back into its text,
   * for the line the caret has just arrived on. The mark always gets its
   * space, even on an empty panel, so what the writer types next is the
   * panel's description and not part of the mark. Returns how many characters
   * now sit in front of the body, so the caret can stay on its character.
   */
  function release(div) {
    var mark = markOf(div);
    if (!mark) return 0;
    var raw = div.getAttribute('data-raw') || '';
    var body = sourceOf(div);
    setMark(div, '');
    div.removeAttribute('data-raw');
    div.removeAttribute('data-label');
    setDivText(div, mark + ' ' + raw + body);
    div.psig = null;
    return mark.length + 1 + raw.length;
  }

  /**
   * A line's level, label and body for the classifier. An absorbed line says
   * so in its attributes; the line being edited is read from its text, with
   * the same rules absorb() and parsePanel() use.
   */
  function levelOf(div) {
    var src = sourceOf(div);
    var m = !markOf(div) && div === live && comicMode ? MARK.exec(src) : null;
    if (!m) return { mark: markOf(div), label: div.getAttribute('data-label'), body: src };
    var body = src.slice(m[0].length);
    var l = m[1] === '##' ? /^\*\*(.+?)\*\*/.exec(body) : null;
    return { mark: m[1], label: l ? l[1].trim().replace(/:$/, '') : null, body: body };
  }

  function getText() {
    return lineDivs().map(function (d) {
      var mark = markOf(d);
      var body = (d.getAttribute('data-raw') || '') + sourceOf(d);
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
    // Not the line being edited: it keeps its marks as text.
    lineDivs().forEach(function (d) { if (d !== live) absorb(d); });
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
  function paint(div, cls, active) {
    var src = sourceOf(div);
    var hide = !active;
    var heading = /^c-(page|panel)\b/.test(cls);

    // The stylesheet's cue to show this line as typed: its marks instead of
    // the PAGE and PANEL labels, and the tab after a cue.
    if (active) div.setAttribute('data-live', '');
    else div.removeAttribute('data-live');

    div.textContent = '';
    if (src === '') {
      div.appendChild(document.createElement('br'));
    } else if (cls.indexOf('c-beat') === 0) {
      // The cue keeps the tab, so the column still comes from tab-size.
      var tab = src.indexOf('\t');
      var cue = cls === 'c-beat' || cls.indexOf('c-beat ') === 0 ? tab + 1 : 0;
      if (cue > 0) {
        // On the live line the tab is a span of its own, so it can be shown.
        var cueEl = span('cue', src.slice(0, active ? tab : cue));
        if (active) cueEl.appendChild(span('tab', '\t'));
        div.appendChild(cueEl);
      }
      var say = document.createElement('span');
      say.className = 'say';
      rich(say, src.slice(cue), hide);
      div.appendChild(say);
    } else if (active && heading && MARK.test(src)) {
      // The marks as typed, in a span of their own so they can be dimmed. The
      // space after them stays outside it, so text typed there isn't dimmed.
      var at = src.indexOf('#');
      var n = MARK.exec(src)[1].length;
      if (at > 0) div.appendChild(document.createTextNode(src.slice(0, at)));
      div.appendChild(span('mk', src.slice(at, at + n)));
      rich(div, src.slice(at + n), false);
    } else {
      rich(div, src, hide);
    }

    // Only remember a source when the view has stopped matching it.
    if (div.textContent === src) div.removeAttribute('data-src');
    else div.setAttribute('data-src', src);
  }

  /**
   * The line the caret is in, which is the one line kept as plain source.
   *
   * Markdown editors settled on this years ago: render the markup everywhere
   * except where you are working, so **bold** reads as bold on the page but
   * shows its asterisks the moment you put the caret in it. It is also what
   * makes hiding characters safe here — typing only ever happens on the live
   * line, so the browser is never inserting text into a span that is standing
   * in for something else.
   *
   * It is deliberately not cleared by a non-collapsed selection: re-rendering
   * a line mid-drag would move the very text being selected.
   */
  var live = null;

  function signature(name, isLive, src) {
    return name + '\u0001' + (isLive ? '1' : '0') + '\u0001' + src;
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
    var texts = divs.map(sourceOf);
    var levels = comic ? divs.map(levelOf) : null;
    var cls = comic
      ? classifyAllComic(levels.map(function (l) { return l.body; }),
          levels.map(function (l) { return l.mark; }),
          levels.map(function (l) { return l.label; }))
      : classifyAll(texts);
    // Repainting replaces a line's child nodes, so the caret is saved and put
    // back — but only when something actually needs repainting, which keeps
    // ordinary typing from disturbing a selection it has no business touching.
    var stale = [];
    for (var i = 0; i < divs.length; i++) {
      var name = 'ln ' + cls[i];
      if (divs[i].className !== name) divs[i].className = name;
      var sig = signature(name, divs[i] === live, texts[i]);
      if (divs[i].psig !== sig) stale.push(i);
    }

    if (stale.length > 0) {
      var c = caretLine();
      for (var j = 0; j < stale.length; j++) {
        var k = stale[j];
        paint(divs[k], cls[k], divs[k] === live);
        divs[k].psig = signature('ln ' + cls[k], divs[k] === live, texts[k]);
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

    // Both ends go back to plain source first: slicing the view would drop
    // whatever the view was hiding.
    from.offset = raw(from.div, from.offset);
    to.offset = to.div === from.div ? toSource(sourceOf(to.div), to.offset) : raw(to.div, to.offset);
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

  // The two heading levels the Sahtu comic format uses, mirroring
  // classifyAllComic: # page and ## panel. A beat wears no marker at all,
  // since a caps NAME: is what makes it one, so it has no key.
  var COMIC_LEVELS = { Digit1: '#', Digit2: '##' };

  /**
   * Set the caret line's heading level, replacing whatever level it had, so
   * Alt+2 on a page line makes it a panel. The line is the one being edited,
   * so its marks are text: they are rewritten in place and the caret stays on
   * its character.
   */
  function setComicLevel(mark) {
    var c = caretLine();
    if (!c) return false;
    c.offset = raw(c.div, c.offset);
    if (markOf(c.div)) c.offset += release(c.div);
    var src = sourceOf(c.div);
    var m = MARK.exec(src);
    if (m && m[1] === mark) return false;
    var old = m ? m[0].length : 0;
    var head = mark + ' ';
    setDivText(c.div, head + src.slice(old));
    placeCaret(c.div, Math.max(head.length, c.offset - old + head.length));
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
    c.offset = raw(c.div, c.offset);
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
    c.offset = raw(c.div, c.offset);
    var lineText = c.div.textContent;
    var before = lineText.slice(0, c.offset);
    var after = lineText.slice(c.offset);
    var parts = text.replace(/\r\n?/g, '\n').split('\n');

    // One line lands on the line being edited, which keeps its marks as text.
    if (parts.length === 1) {
      setDivText(c.div, before + parts[0] + after);
      restyle();
      placeCaret(c.div, (before + parts[0]).length);
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
   * Cmd/Ctrl+B and Cmd/Ctrl+I wrap the selection in Markdown emphasis — the
   * same asterisks both parsers already read, so the shortcuts write the
   * writer's source rather than a separate notion of formatting.
   *
   * They toggle, and they compose. The asterisks already hugging the selection
   * say what it is — one italic, two bold, three both — which is the same
   * number as the two bits being toggled here, so bolding italic text raises
   * it to three rather than burying it in a second pair. Selecting across
   * lines is left alone: asterisks don't span a line break in either parser.
   *
   * @param bit 1 to toggle italic, 2 to toggle bold.
   */
  function toggleEmphasis(bit) {
    var sel = window.getSelection();
    if (!sel.rangeCount) return false;
    var range = sel.getRangeAt(0);
    var from = posOf(range.startContainer, range.startOffset);
    var to = posOf(range.endContainer, range.endOffset);
    if (!from || !to || from.div !== to.div) return false;

    var text = from.div.textContent;
    var a = from.offset;
    var b = to.offset;

    // Marks the writer happened to select count as wrapping, not as content —
    // double-clicking through **loud** should still un-bold it.
    while (a < b && text.charAt(a) === '*' && text.charAt(b - 1) === '*') { a++; b--; }

    var k = 0;
    while (k < 3 && a - 1 - k >= 0 && text.charAt(a - 1 - k) === '*' && text.charAt(b + k) === '*') k++;

    var want = k ^ bit;
    var mark = '***'.slice(0, want);
    var out = text.slice(0, a - k) + mark + text.slice(a, b) + mark + text.slice(b + k);
    var na = a - k + want;

    setDivText(from.div, out);
    restyle();
    // An empty selection leaves the caret between the new marks, ready to type.
    if (a === b) placeCaret(from.div, na);
    else selectRange(from.div, na, na + (b - a));
    return true;
  }

  // --- Typing shortcuts ------------------------------------------------------

  /**
   * A cue types its own tab: closing "NOI:" or "NOI (WHISPER):" jumps the
   * caret to the dialogue column so the writer can keep going. Only at the end
   * of a line that hasn't got a tab already, and Backspace takes it straight
   * back if it fires on something meant as prose.
   *
   * A space typed straight after the colon is the same gap, written the way
   * anyone would write it: it becomes the tab, or is dropped when the tab is
   * already there, so "NOI: Sorry!" lines up without the writer knowing a tab
   * is involved.
   */
  function autoTab() {
    var c = caretLine();
    if (!c) return;
    var text = c.div.textContent;
    var gap = /^([^:\n]{1,40}:)(\t?) $/.exec(text.slice(0, c.offset));
    var rest = text.slice(c.offset);
    if (gap && isCue(gap[1]) && (gap[2] || rest.indexOf('\t') === -1)) {
      setDivText(c.div, gap[1] + '\t' + rest);
      placeCaret(c.div, gap[1].length + 1);
      return;
    }
    if (c.offset !== text.length || text.indexOf('\t') !== -1) return;
    if (text.slice(-1) !== ':' || !isCue(text)) return;
    setDivText(c.div, text + '\t');
    placeCaret(c.div, text.length + 1);
  }

  // --- Undo ------------------------------------------------------------------

  /**
   * The editor's own history. The browser's contenteditable undo can't be
   * used: every line is rewritten by hand as you type (setDivText, absorb,
   * paint), and the native stack only knows about the edits it made itself, so
   * Cmd+Z did nothing, or half of something, and lost the caret.
   *
   * A step is the state from just before an edit: the whole text as getText()
   * reads it (so undo never brings back rendered text) and the caret as a line
   * index plus an offset into that line's source. Every edit says it is coming
   * with willEdit(kind) and is settled by markDirty(), which every edit already
   * calls. An edit that changed nothing leaves no step.
   *
   * Typing and plain deleting run together into one step until a pause or a
   * word ends; everything else (Enter, paste, cut, Tab, a level key, a cue's
   * tab, emphasis) is a step of its own.
   */
  var undoStack = [];
  var redoStack = [];
  var UNDO_LIMIT = 200;
  var UNDO_PAUSE = 1000; // ms. A longer pause starts a new step.
  var pending = null;    // { snap, kind } for an edit under way.
  var lastKind = null;   // The kind of the last step, while it may still grow.
  var lastAt = 0;
  var closed = false;    // The last step ended a word, so it can't grow.
  var lastWord = false;  // The last character typed was part of a word.

  /** A line's characters that sit in front of its source in getText(). */
  function prefixLength(div) {
    var mark = markOf(div);
    var raw = div.getAttribute('data-raw') || '';
    var body = raw + sourceOf(div);
    return (mark ? mark.length + (body === '' ? 0 : 1) : 0) + raw.length;
  }

  function snapshot() {
    var caret = { line: 0, offset: 0 };
    var c = caretLine();
    if (c) {
      // The live line shows its source; any other shows the view, which hides
      // asterisks, so the offset is mapped back the same way raw() does.
      var at = c.div.getAttribute('data-src') === null ? c.offset : toSource(sourceOf(c.div), c.offset);
      caret = { line: lineDivs().indexOf(c.div), offset: prefixLength(c.div) + at };
    }
    return { text: getText(), caret: caret };
  }

  function willEdit(kind, ends) {
    pending = { snap: snapshot(), kind: kind, ends: !!ends };
  }

  function settle() {
    if (!pending) return;
    var p = pending;
    pending = null;
    if (getText() === p.snap.text) return;
    var now = Date.now();
    var joins = (p.kind === 'type' || p.kind === 'delete') && p.kind === lastKind
      && now - lastAt < UNDO_PAUSE && !closed;
    if (!joins) {
      undoStack.push(p.snap);
      if (undoStack.length > UNDO_LIMIT) undoStack.shift();
    }
    redoStack.length = 0;
    lastKind = p.kind;
    lastAt = now;
    closed = p.ends;
  }

  /** Run one programmatic rewrite as a step of its own. */
  function step(kind, fn) {
    willEdit(kind);
    fn();
    settle();
  }

  /**
   * Rebuild the surface from text. Comic mode is reset first: syncComicMode()
   * only absorbs the # markers on the way into comic mode, so a second build()
   * with it already on would leave every heading as raw text.
   */
  function rebuild(text) {
    comicMode = false;
    editor.classList.remove('is-comic');
    live = null;
    build(text);
  }

  function restore(snap) {
    rebuild(snap.text);
    var divs = lineDivs();
    var div = divs[Math.min(snap.caret.line, divs.length - 1)];
    // Made the live line, marks and all, so the offset (counted from the head
    // of the line as getText() writes it) lands on the right character.
    live = div;
    release(div);
    var at = Math.min(Math.max(0, snap.caret.offset), sourceOf(div).length);
    restyle();
    placeCaret(div, at);
  }

  /**
   * Undo or redo one step. A step whose state reads back the same as now is
   * passed over, so one key press always visibly changes something.
   */
  function travel(from, to) {
    if (composing || from.length === 0) return;
    pending = null;
    var here = snapshot();
    while (from.length > 0) {
      restore(from.pop());
      if (getText() !== here.text) { to.push(here); break; }
    }
    lastKind = null;
    markDirty();
  }

  function undo() { travel(undoStack, redoStack); }
  function redo() { travel(redoStack, undoStack); }

  // --- Events ----------------------------------------------------------------

  var composing = false;
  var dirty = false;

  editor.addEventListener('compositionstart', function () {
    composing = true;
    willEdit('type'); // The state before the composition, settled at its end.
  });
  editor.addEventListener('compositionend', function () {
    composing = false;
    restyle();
    markDirty();
  });

  // Edits the browser makes itself announce themselves here first. The
  // browser's own undo and redo (Edit menu, shake to undo) are turned into
  // ours, so its broken stack never runs.
  editor.addEventListener('beforeinput', function (e) {
    if (e.inputType === 'historyUndo' || e.inputType === 'historyRedo') {
      e.preventDefault();
      if (e.inputType === 'historyUndo') undo(); else redo();
      return;
    }
    if (composing || e.isComposing) return;
    var collapsed = window.getSelection().isCollapsed;
    var kind = e.inputType;
    var ends = false;
    if (collapsed && kind === 'insertText') {
      kind = 'type';
      // A space or punctuation after a letter ends the word, and its step.
      var word = /[\p{L}\p{M}\p{N}]/u.test(e.data || '');
      ends = !word && lastWord;
      lastWord = word;
    } else if (collapsed && (kind === 'deleteContentBackward' || kind === 'deleteContentForward')) {
      kind = 'delete';
    }
    willEdit(kind, ends);
  });

  editor.addEventListener('input', function () {
    if (composing) return;
    settle(); // The typing itself, before anything below rewrites it.
    if (comicMode) step('cue-tab', autoTab);
    restyle();
    markDirty();
  });

  editor.addEventListener('keydown', function (e) {
    // Cmd/Ctrl+B and Cmd/Ctrl+I. Always swallowed, even when the selection is
    // one these can't act on: the browser's own bold inserts a <b> element,
    // and a line here is one flat string of the writer's source with no
    // elements in it at all.
    if ((e.metaKey || e.ctrlKey) && !e.altKey && !composing) {
      var key = e.key.toLowerCase();
      if (key === 'b' || key === 'i') {
        e.preventDefault();
        willEdit('emphasis');
        if (toggleEmphasis(key === 'b' ? 2 : 1)) markDirty();
        return;
      }
      // Cmd/Ctrl+Z undoes; Cmd/Ctrl+Shift+Z and Ctrl+Y redo.
      if (key === 'z' || (key === 'y' && e.ctrlKey && !e.metaKey)) {
        e.preventDefault();
        if (key === 'z' && !e.shiftKey) undo(); else redo();
        return;
      }
    }

    if (e.key === 'Enter' && !e.shiftKey && !composing) {
      willEdit('enter');
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
      willEdit('tab');
      insertMultiline('\t');
      markDirty();
      return;
    }

    // Comic heading levels. Alt is the only modifier free on every platform:
    // Ctrl+digit and Cmd+digit are reserved by browsers for tab switching and
    // can't be preventDefault'd. e.code because Option+1 on macOS reports
    // e.key as '¡'.
    if (e.altKey && !e.ctrlKey && !e.metaKey && !composing
        && COMIC_LEVELS[e.code] && comicMode) {
      // Unconditionally, before the no-op check: a second Alt+2 on a line
      // that is already a panel still has to swallow the key, or macOS types ™.
      e.preventDefault();
      willEdit('level');
      if (setComicLevel(COMIC_LEVELS[e.code])) {
        restyle();
        markDirty();
      }
    }
  });

  // Moving the caret onto a line brings its markers back, # and ## included,
  // and takes them off the line just left. The caret is remapped as it goes,
  // since the markers reappearing shifts every character after them.
  document.addEventListener('selectionchange', function () {
    if (composing || document.activeElement !== editor) return;
    var sel = window.getSelection();
    if (!sel.rangeCount || !sel.isCollapsed) return;
    var r = sel.getRangeAt(0);
    var c = posOf(r.startContainer, r.startOffset);
    if (!c || c.div === live) return;

    var was = live;
    live = c.div;
    var at = toSource(sourceOf(c.div), c.offset);
    if (comicMode) {
      at += release(c.div);
      if (was && was.parentNode === editor) absorb(was);
    }
    restyle();
    placeCaret(c.div, at);
  });

  function repaint(div) {
    var cls = div.className.replace(/^ln ?/, '');
    paint(div, cls, div === live);
    div.psig = signature(div.className, div === live, sourceOf(div));
  }

  // Copying from a rendered line must yield what the writer wrote, not what
  // the view shows, or the asterisks would quietly vanish on the way out.
  editor.addEventListener('copy', function (e) {
    var sel = window.getSelection();
    if (!sel.rangeCount || sel.isCollapsed) return;
    var r = sel.getRangeAt(0);
    var from = posOf(r.startContainer, r.startOffset);
    var to = posOf(r.endContainer, r.endOffset);
    if (!from || !to) return;

    var parts = [];
    for (var d = from.div; d; d = d.nextSibling) {
      if (d.nodeType !== 1) continue;
      var src = sourceOf(d);
      var a = d === from.div ? toSource(src, from.offset) : 0;
      var b = d === to.div ? toSource(src, to.offset) : src.length;
      parts.push(src.slice(a, b));
      if (d === to.div) break;
    }
    e.clipboardData.setData('text/plain', parts.join('\n'));
    e.preventDefault();
  });


  editor.addEventListener('paste', function (e) {
    e.preventDefault();
    var text = (e.clipboardData || window.clipboardData).getData('text');
    willEdit('paste');
    // Comic scripts are left alone: their pages are numbered "#", which the
    // furniture rules would read as a printer's page number and delete.
    insertMultiline(comicMode ? text : Paste.fromPrinted(text));
    markDirty();
  });

  // --- Save ------------------------------------------------------------------

  function setStatus(msg, isError) {
    statusEl.textContent = msg;
    statusEl.classList.toggle('error', !!isError);
  }

  var autosaveTimer = null;

  // Every edit ends here, so this is also where its undo step is settled.
  function markDirty() {
    settle();
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
