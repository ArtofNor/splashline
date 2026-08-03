/*
 * The page overview: every sheet at once, in a grid, to get to page 74 without
 * scrolling past 73 others.
 *
 * All of the work is in style.css — the sheets are already on the page, so the
 * overview is one class on <main> rather than a second copy of the document.
 * What is left here is the part CSS can't do: putting the reader back where
 * they asked to be.
 */
(function () {
  'use strict';

  var button = document.getElementById('overview');
  var main = document.querySelector('main');
  if (!button || !main) return;

  function open() {
    return main.classList.contains('is-grid');
  }

  function toggle(on) {
    main.classList.toggle('is-grid', on);
    button.setAttribute('aria-pressed', on ? 'true' : 'false');
  }

  button.addEventListener('click', function () {
    toggle(!open());
    // Coming back out lands wherever the reader was; going in starts at the top
    // so the first sheet is under the cursor rather than halfway up the screen.
    if (open()) window.scrollTo(0, 0);
  });

  // Picking a sheet closes the grid and leaves that page under the reader. The
  // click lands on whatever was drawn inside the sheet, so walk back up to it.
  main.addEventListener('click', function (e) {
    if (!open()) return;
    var sheet = e.target.closest('.sheet');
    if (!sheet) return;
    toggle(false);
    sheet.scrollIntoView();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && open()) toggle(false);
  });
}());
