/*
 * Tests for the printed-script paste formatter (public/paste.js).
 *
 * Run by tests/run.php along with everything else, or on its own:
 *
 *     node tests/paste.test.js
 *
 * What is worth pinning here is the guessing. The formatter reconstructs
 * structure that a PDF threw away, and it does that from a handful of
 * thresholds and shapes — the width the dialogue column wraps to, the capitals
 * action opens with, the punctuation a finished line ends on. Those are the
 * parts that drift, and the parts where being wrong quietly damages a script.
 */
'use strict';

var Paste = require('../public/paste.js');

var pass = 0;
var fail = 0;

function check(cond, msg) {
  if (cond) {
    pass++;
  } else {
    fail++;
    process.stderr.write('FAIL: ' + msg + '\n');
  }
}

/** Assert on the whole result, and show both sides when it differs. */
function same(got, want, msg) {
  if (got === want) {
    pass++;
  } else {
    fail++;
    process.stderr.write('FAIL: ' + msg + '\n  want: ' + JSON.stringify(want)
      + '\n  got:  ' + JSON.stringify(got) + '\n');
  }
}

var fromPrinted = Paste.fromPrinted;
var lines = function () {
  return Array.prototype.join.call(arguments, '\n');
};

// --- The guard ---------------------------------------------------------------
// Rejoining lines destroys breaks a writer put in on purpose, so text that was
// written rather than printed has to come back byte for byte. This is the test
// that matters most: everything else being wrong costs a tidy-up, this being
// wrong costs work.

var written = lines(
  'INT. HOUSE - DAY', '',
  'She opens the door.',
  'She closes it again.', '',
  'MARA',
  'One.',
  'Two.'
);
same(fromPrinted(written), written, 'a written script is untouched');

var longWritten = [];
for (var i = 0; i < 12; i++) {
  longWritten.push('INT. ROOM ' + i + ' - DAY', '', 'She waits.', '', 'MARA', 'Line ' + i + '.', '');
}
longWritten = longWritten.join('\n').replace(/\n$/, '');
same(fromPrinted(longWritten), longWritten, 'a long written script is untouched');

check(!Paste.looksPrinted(written.split('\n')), 'written text does not look printed');
check(Paste.looksPrinted(['     A', '     B', '     C'].join('\n').split('\n')),
  'margins look printed');
check(Paste.looksPrinted(['12.', 'Anything'].join('\n').split('\n')),
  'furniture looks printed');
// A script cannot be written without blank lines — they are what makes a cue a
// cue — so a long run without them is a page whose extractor dropped them.
check(Paste.looksPrinted(('A\n'.repeat(9)).split('\n')), 'no blank lines looks printed');
check(!Paste.looksPrinted('A\nB\nC'.split('\n')), 'a short run is not enough');

// --- Furniture ---------------------------------------------------------------

var furnished = lines(
  '                                                     12.',
  '     CONTINUED:', '',
  '     INT. DINER - NIGHT', '',
  '     She waits.'
);
check(!/12\.|CONTINUED/.test(fromPrinted(furnished)), 'page number and CONTINUED dropped');

var forms = ['12.', '12', '(CONTINUED)', 'CONTINUED: (2)', '(MORE)', 'Page 4 of 9',
  '12.  CONTINUED:'];
forms.forEach(function (f) {
  var out = fromPrinted(lines('     ' + f, '', '     INT. ROOM - DAY', '', '     She waits.'));
  check(out.indexOf(f) === -1, 'furniture dropped: ' + f);
});

// A running header carries its number out at the right margin. The number comes
// off rather than the line, so a heading sharing the line keeps its slug.
// (Enough margin here to trip the guard — three indented lines is the floor,
// and below it nothing is reshaped at all, which is the point of the guard.)
same(
  fromPrinted(lines(
    '     INT. ROOM - DAY                                   12.', '',
    '     She waits.', '',
    '     He does not.'
  )),
  lines('INT. ROOM - DAY', '', 'She waits.', '', 'He does not.'),
  'a heading keeps its slug when a page number shared the line'
);

// --- Margins in whatever the extractor encoded them as -----------------------
// A PDF sets its margins with ordinary spaces, non-breaking spaces or tabs
// depending on the producer. All three are the same margin.

var printed = lines(
  '                         MARA',
  '               You said ten. It is nearly',
  '               midnight.', '',
  '     INT. DINER - NIGHT', '',
  '     Rain sheets the window. MARA sits alone in the',
  '     back booth.'
);
var shaped = lines(
  'MARA',
  'You said ten. It is nearly midnight.', '',
  'INT. DINER - NIGHT', '',
  'Rain sheets the window. MARA sits alone in the back booth.'
);
same(fromPrinted(printed), shaped, 'space margins');
same(fromPrinted(printed.replace(/ /g, ' ')), shaped, 'non-breaking space margins');
same(fromPrinted(printed.replace(/ {4}/g, '\t')), shaped, 'tab margins');

// --- Unwrapping --------------------------------------------------------------

check(fromPrinted(lines(
  '     A paragraph the page width broke in three',
  '     places, which has to come back as one',
  '     paragraph.', '',
  '     INT. ROOM - DAY'
)).indexOf('broke in three places, which has to come back as one paragraph.') > -1,
  'a broken action paragraph is rejoined');

same(
  fromPrinted(lines(
    '                         MARA',
    '                    (quietly)',
    '               One line here',
    '               and its wrap.'
  )),
  lines('MARA', '(quietly)', 'One line here and its wrap.'),
  'a parenthetical stands alone inside a speech'
);

// A speech the page break split arrives as two, under a second cue marked
// (CONT'D). It is one speech: the printer divided it, not the writer.
same(
  fromPrinted(lines(
    '                         MARA',
    '               You said ten. It is nearly',
    '                         (MORE)', '',
    '                                                     13.',
    '                         MARA (CONT\'D)',
    '               midnight.'
  )),
  lines('MARA', 'You said ten. It is nearly midnight.'),
  'a speech split by a page break is sewn back into one'
);

// --- Title pages -------------------------------------------------------------

same(
  fromPrinted(lines(
    '', '                    KPOP DEMON HUNTERS', '',
    '                        Written by', '',
    '             Danya Jimenez & Hannah McMechan',
    '                      And Maggie Kang', '',
    '                      First Draft',
    '                  nor@sahtupress.com', '',
    'KPOP DEMON HUNTERS                                    2.', '',
    '     INT. SEOUL ARENA - NIGHT', '',
    '     Lights sweep the crowd.'
  )),
  lines(
    'Title: KPOP DEMON HUNTERS',
    'Credit: Written by',
    'Author: Danya Jimenez & Hannah McMechan',
    '   And Maggie Kang',
    'Draft date: First Draft',
    'Contact: nor@sahtupress.com', '',
    'INT. SEOUL ARENA - NIGHT', '',
    'Lights sweep the crowd.'
  ),
  'a printed title page becomes the Fountain keys that produce one'
);

['Written by', 'Screenplay by', 'Story by', 'by'].forEach(function (c) {
  var page = Paste.titlePage(['THE THING', c, 'Nor Sanavongsay']);
  check(page !== null && page.lines[1] === 'Credit: ' + c, 'credit line read: ' + c);
});

// Without a credit line there is nothing to read a title page by, so nothing is
// claimed — and, more importantly, none of it becomes a speech.
check(Paste.titlePage(['SOME UNTITLED THING', 'A Sahtu Press Film']) === null,
  'no credit line means no title page');
var noCredit = fromPrinted(lines(
  '                    SOME UNTITLED THING', '',
  '                    A Sahtu Press Film', '',
  '     INT. ROOM - DAY', '',
  '     A door.'
));
check(noCredit.indexOf('SOME UNTITLED THING\nA Sahtu Press Film') === -1,
  'a credit-less title page never collapses into a cue and a speech');

// A scene pasted from the middle of a script also sits above the next heading.
// The credit line is what tells it from a title page, so this must still shape.
check(fromPrinted(printed).indexOf('You said ten. It is nearly midnight.') > -1,
  'a mid-scene excerpt above the first heading is still reshaped');

// --- Structure rebuilt from nothing ------------------------------------------
// Some extractors keep the line breaks and drop every blank line. What is left
// has no blocks at all, and Fountain cannot read a cue without a blank above it.

var flat = lines(
  'OVER SONY LOGO:',
  'NY1 REPORTER (V.O.)',
  'We come to you now with revelations',
  'about last week\'s attack in London.',
  'SPIDER-MAN (V.O.)',
  'Do it! Execute them all!',
  'EXPLOSIONS from the Tower Bridge.',
  'DRONES FIRE! OVER MARVEL LOGO:',
  '2.',
  'QUENTIN BECK (V.O.)',
  'Spider-Man\'s real... Spider-Man\'s',
  'real name is...',
  'A DISTORTED SOUND VORTEX, then--',
  'EXT. MADISON SQUARE GARDEN - DAY',
  'The last moments of Quentin Beck\'s broadcast from the end of',
  'FAR FROM HOME.',
  'J. JONAH JAMESON (ON SCREEN)',
  'That\'s right, folks. Peter Parker.',
  'A 17-year-old high school',
  'delinquent harboring a homicidal',
  'hunger is in fact the vile',
  'vigilante villain Spider-Man.',
  'BYSTANDER #1 (O.S.)',
  '(re: MJ)',
  'She knows him.'
);

same(
  fromPrinted(flat),
  lines(
    'OVER SONY LOGO:', '',
    'NY1 REPORTER (V.O.)',
    'We come to you now with revelations about last week\'s attack in London.', '',
    'SPIDER-MAN (V.O.)',
    'Do it! Execute them all!', '',
    'EXPLOSIONS from the Tower Bridge.', '',
    'DRONES FIRE! OVER MARVEL LOGO:', '',
    'QUENTIN BECK (V.O.)',
    'Spider-Man\'s real... Spider-Man\'s real name is...', '',
    'A DISTORTED SOUND VORTEX, then--', '',
    'EXT. MADISON SQUARE GARDEN - DAY',
    'The last moments of Quentin Beck\'s broadcast from the end of FAR FROM HOME.', '',
    'J. JONAH JAMESON (ON SCREEN)',
    'That\'s right, folks. Peter Parker. A 17-year-old high school delinquent '
      + 'harboring a homicidal hunger is in fact the vile vigilante villain Spider-Man.', '',
    'BYSTANDER #1 (O.S.)',
    '(re: MJ)',
    'She knows him.'
  ),
  'an extractor dump with no blank lines is given its blocks back'
);

// The three judgements that whole run rests on, pinned one at a time.

// A cue announces itself by being uppercase, extension and all.
['NY1 REPORTER (V.O.)', 'E.D.I.T.H. (V.O.)', 'BYSTANDER #1 (O.S.)',
  'J. JONAH JAMESON (ON SCREEN)', 'QUENTIN BECK (CONT\'D)'].forEach(function (c) {
  check(Paste.isCharacter(c), 'reads as a cue: ' + c);
});
['Do it! Execute them all!', 'She knows him.', 'A 17-year-old high school'].forEach(function (d) {
  check(!Paste.isCharacter(d), 'does not read as a cue: ' + d);
});

// A speech ends at a line too wide for the dialogue column...
check(Paste.restoreBreaks(['MARA', 'Short line.',
  'This line is far too wide to have been set in a dialogue column.'])
  .join('\n').indexOf('Short line.\n\nThis line is far') > -1,
  'a line too wide for the column ends the speech');

// ...or at one that opens in capitals the way action does, which is what
// separates "EXPLOSIONS from the Tower Bridge." from the speech above it.
check(Paste.restoreBreaks(['MARA', 'Do it!', 'EXPLOSIONS from the bridge.'])
  .join('\n').indexOf('Do it!\n\nEXPLOSIONS') > -1,
  'capitals opening a line end the speech');
check(Paste.restoreBreaks(['MARA', 'Do it!', 'A DISTORTED SOUND VORTEX, then--'])
  .join('\n').indexOf('Do it!\n\nA DISTORTED') > -1,
  'a capitals run after a short word still ends the speech');
check(Paste.restoreBreaks(['MARA', 'I told you.', 'I said NO.'])
  .join('\n').indexOf('I told you.\n\nI said NO.') === -1,
  'capitals inside a line do not end the speech');

// A line stopping mid-sentence is finished by the line under it, whatever case
// that one is in — otherwise a shot ending a paragraph reads as a cue.
check(Paste.restoreBreaks(['Action running on to the end of',
  'FAR FROM HOME.']).join('\n').indexOf('\n\n') === -1,
  'an unfinished line keeps the uppercase line below it');
check(Paste.restoreBreaks(['Action that finished.', 'MARA', 'Hello.'])
  .join('\n').indexOf('finished.\n\nMARA') > -1,
  'a finished line lets the cue below it open a block');

process.stdout.write('\n#counts ' + pass + ' ' + fail + '\n');
process.exit(fail === 0 ? 0 : 1);
