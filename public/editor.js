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

  // Sahtu comic scripts classify by Markdown heading level instead. Detection
  // is content-first (mirrors sniff_comic() in index.php): the house format is
  // valid Markdown AND valid Fountain, so the extension is only a fallback.
  function isComicDoc(text) {
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

  function classifyAllComic(texts) {
    var out = new Array(texts.length);
    var last = null;    // Last non-blank class, for dialogue continuations.
    var inPanel = false; // Bare cues only read as beats inside a panel.
    for (var i = 0; i < texts.length; i++) {
      var t = texts[i].trim();
      if (t === '') { out[i] = 'blank'; continue; }
      if (/^###(\s|$)/.test(t)) out[i] = 'c-beat';
      else if (/^##(\s|$)/.test(t)) { out[i] = 'c-panel'; inPanel = true; }
      else if (/^#(\s|$)/.test(t)) { out[i] = 'c-page'; inPanel = false; }
      else if (/^\[.*\]$/.test(t)) out[i] = 'c-note';
      else if (inPanel && isCue(t)) out[i] = 'c-beat';
      // A plain line after a beat continues that speech (multiline dialogue).
      else if (last === 'c-beat' || last === 'c-beat-cont') out[i] = 'c-beat-cont';
      else out[i] = 'c-desc';
      last = out[i];
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

  function getText() {
    return lineDivs().map(function (d) { return d.textContent; }).join('\n');
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

  function restyle() {
    ensureStructure();
    var divs = lineDivs();
    var texts = divs.map(function (d) { return d.textContent; });
    var comic = isComicDoc(texts.join('\n'));
    var cls = (comic ? classifyAllComic : classifyAll)(texts);
    for (var i = 0; i < divs.length; i++) divs[i].className = 'ln ' + cls[i];
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
  function caretLine() {
    var sel = window.getSelection();
    if (!sel.rangeCount) return null;
    var range = sel.getRangeAt(0);
    var div = range.startContainer;
    if (div.nodeType !== 1) div = div.parentNode;
    while (div && div.parentNode !== editor) div = div.parentNode;
    if (!div || div.parentNode !== editor) return null;

    var probe = document.createRange();
    probe.selectNodeContents(div);
    probe.setEnd(range.startContainer, range.startOffset);
    return { div: div, offset: probe.toString().length };
  }

  function placeCaret(div, offset) {
    var node = (div.firstChild && div.firstChild.nodeType === 3) ? div.firstChild : null;
    var r = document.createRange();
    if (node) r.setStart(node, Math.min(offset, node.length));
    else r.setStart(div, 0);
    r.collapse(true);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(r);
  }

  // --- Structural edits ------------------------------------------------------

  // The three levels the Sahtu comic format uses, mirroring classifyAllComic:
  // # page, ## panel, ### beat (dialogue, caption, SFX).
  var COMIC_LEVELS = { Digit1: '#', Digit2: '##', Digit3: '###' };

  /**
   * Set the caret line's heading level, replacing whatever level it had, so
   * Alt+2 on "# EXT. MARKET" gives "## EXT. MARKET". Text is rewritten here
   * rather than only class names — that's fine for an explicit command, unlike
   * typing, where touching text nodes would break the caret and IME.
   */
  function setComicLevel(marks) {
    var c = caretLine();
    if (!c) return false;

    var text = c.div.textContent;
    var body = text.replace(/^\s*#{1,6}[ \t]*/, '');
    var prefix = marks + ' ';
    if (prefix + body === text) return false; // already at this level

    c.div.textContent = prefix + body;
    // Keep the caret on the same character of the body, not the same column.
    var intoBody = Math.max(0, c.offset - (text.length - body.length));
    placeCaret(c.div, prefix.length + intoBody);
    return true;
  }

  function splitLineAtCaret() {
    var c = caretLine();
    if (!c) return false;
    var text = c.div.textContent;
    var before = text.slice(0, c.offset);
    var after = text.slice(c.offset);

    setDivText(c.div, before);
    var nd = document.createElement('div');
    nd.className = 'ln';
    setDivText(nd, after);
    editor.insertBefore(nd, c.div.nextSibling);
    restyle();
    placeCaret(nd, 0);
    return true;
  }

  function insertMultiline(text) {
    var c = caretLine();
    if (!c) return;
    var lineText = c.div.textContent;
    var before = lineText.slice(0, c.offset);
    var after = lineText.slice(c.offset);
    var parts = text.replace(/\r\n?/g, '\n').split('\n');

    if (parts.length === 1) {
      setDivText(c.div, before + parts[0] + after);
      restyle();
      placeCaret(c.div, (before + parts[0]).length);
      return;
    }

    setDivText(c.div, before + parts[0]);
    var ref = c.div;
    for (var i = 1; i < parts.length; i++) {
      var nd = document.createElement('div');
      nd.className = 'ln';
      var last = i === parts.length - 1;
      setDivText(nd, last ? parts[i] + after : parts[i]);
      editor.insertBefore(nd, ref.nextSibling);
      ref = nd;
    }
    restyle();
    placeCaret(ref, parts[parts.length - 1].length);
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
    restyle();
    markDirty();
  });

  editor.addEventListener('keydown', function (e) {
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
        && !composing && isComicDoc(getText())) {
      e.preventDefault();
      insertMultiline('\t');
      markDirty();
      return;
    }

    // Comic heading levels. Alt is the only modifier free on every platform:
    // Ctrl+digit and Cmd+digit are reserved by browsers for tab switching and
    // can't be preventDefault'd. e.code because Option+1 on macOS reports
    // e.key as '¡'.
    if (e.altKey && !e.ctrlKey && !e.metaKey && !composing
        && COMIC_LEVELS[e.code] && isComicDoc(getText())) {
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
  placeCaret(lineDivs()[0], 0);
})();
