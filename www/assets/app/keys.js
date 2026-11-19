// 1radiœ web app: buttons and keyboard.

import { $, levels, state } from './core.js';
import { setView } from './scale.js';
import { pick, step, toggle } from './audio.js';
import { openDialog } from './dialogs.js';

let spaceTaken = false;
let tabbed = false;   // focus moved with Tab since the last click or tap

/** Listeners and first state of this part, called once by the entry. */
export function init() {
  /* ---------- Buttons and keys ---------- */

  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    const act = b.dataset.act;
    if (act === 'toggle') toggle();
    else if (act === 'prev') step(-1);
    else if (act === 'next') step(1);
    else if (act === 'info') openDialog('info');
    else if (act === 'sheet') openDialog('sheet');
  });

  document.addEventListener('pointerdown', () => { tabbed = false; }, true);
  document.addEventListener('keydown', (e) => { if (e.key === 'Tab') tabbed = true; }, true);

  document.addEventListener('keydown', (e) => {
    if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) return;
    if ($('dialog[open]')) return;
    const t = e.target instanceof Element ? e.target : null;
    const tag = t ? t.tagName.toLowerCase() : '';
    const k = e.key;
    if (k === ' ' || k === 'Spacebar') {
      if ((tag === 'input' && t.type !== 'range') || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable)) return;
      // Space is play and pause, as in any player, also right after a click on a button
      // (the click leaves the focus there, e.g. on "next"). Only a control reached with
      // Tab keeps its own Space: keyboard users expect it to press the control.
      // (:focus-visible cannot tell: browsers switch it on with this very key press.)
      if ((tag === 'button' || tag === 'a') && tabbed) return;
      e.preventDefault();
      spaceTaken = true;   // the button would otherwise still be pressed on keyup
      if (!e.repeat) toggle();
      return;
    }
    if (tag === 'input' || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable)) return;
    if (k === 'ArrowRight' || k === 'ArrowLeft') {
      e.preventDefault();
      step(k === 'ArrowRight' ? 1 : -1);
    } else if (/^[0-9]$/.test(k) && t && t.closest('main')) {
      // a single character as a shortcut works only while the focus is in the player or
      // the station picker (WCAG 2.1.4): speech input would otherwise start stations
      const it = (levels[state.view] || [])[k === '0' ? 9 : Number(k) - 1];
      if (it) {
        e.preventDefault();
        pick(it, state.view);
      }
    } else if (k === 'Escape' && state.view !== 'root') {
      e.preventDefault();
      setView('root');
    }
  });
  document.addEventListener('keyup', (e) => {
    if (spaceTaken && (e.key === ' ' || e.key === 'Spacebar')) {
      e.preventDefault();
      spaceTaken = false;
    }
  });
}
