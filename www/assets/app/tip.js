// 1radiœ web app: the info at "Olli hört gerade" (the bars).

import { $, T, el, items, state, stationsOf } from './core.js';
import { scroller } from './scale.js';
import { listEl } from './list.js';

/* ---------- Info at "Olli hört gerade" ----------
 * Hovering the bars shows who listens to what. On touch screens a tap on the bars
 * shows it instead of starting the station; the rest of the button still starts it.
 * Screen readers get the same text from the buttons' labels. */

const ptip = el('div', 'ptip');

let ptipFor = null;
let ptipTimer = 0;
let pointer = 'mouse';

/** Bars of a station, a category or the player; not the switch in the header. */
function pillAt(target) {
  const p = target instanceof Element ? target.closest('.pill') : null;
  return p && !p.hidden && (p.dataset.item || p.dataset.r1 === 'pill') ? p : null;
}

function pillText(pill) {
  const it = pill.dataset.item ? items.get(pill.dataset.item) : state.cur;
  if (!it) return T.listening;
  if (it.type === 'category') {
    const names = stationsOf(it.id).filter((x) => state.active.has(x.id)).map((x) => x.name);
    return names.length ? `${T.listeningTo} ${names.join(` ${T.and} `)}` : T.listening;
  }
  return `${T.listeningTo} ${it.name}`;
}

function showTip(pill, autoHide) {
  clearTimeout(ptipTimer);
  ptip.textContent = pillText(pill);
  ptip.hidden = false;
  ptip.classList.remove('below', 'right');
  const r = pill.getBoundingClientRect();
  let left = r.right + 10;
  let top = Math.round(r.top + r.height / 2 - ptip.offsetHeight / 2);
  // the player's bars sit in a line of text: the info goes beside them on the right,
  // so it covers neither the station name above nor the buttons below
  if (pill.dataset.r1 === 'pill' && left + ptip.offsetWidth <= window.innerWidth - 8) {
    ptip.classList.add('right');
  } else {
    const cx = r.left + r.width / 2;
    left = Math.min(Math.max(8, cx - ptip.offsetWidth / 2), window.innerWidth - 8 - ptip.offsetWidth);
    top = r.top - ptip.offsetHeight - 10;
    if (top < 8) {
      top = r.bottom + 10;
      ptip.classList.add('below');
    }
    ptip.style.setProperty('--ax', (cx - left) + 'px');
  }
  ptip.style.left = left + 'px';
  ptip.style.top = top + 'px';
  ptipFor = pill;
  if (autoHide) ptipTimer = setTimeout(hideTip, 2500);
}

function hideTip() {
  clearTimeout(ptipTimer);
  ptip.hidden = true;
  ptipFor = null;
}

/** Listeners and first state of this part, called once by the entry. */
export function init() {
  ptip.setAttribute('aria-hidden', 'true');
  ptip.hidden = true;
  document.body.append(ptip);

  document.addEventListener('pointerover', (e) => {
    if (e.pointerType !== 'mouse') return;
    const p = pillAt(e.target);
    if (p && p !== ptipFor) showTip(p, false);
  });
  document.addEventListener('pointerout', (e) => {
    if (e.pointerType === 'mouse' && ptipFor && !(e.relatedTarget instanceof Node && ptipFor.contains(e.relatedTarget))) hideTip();
  });
  document.addEventListener('pointerdown', (e) => {
    pointer = e.pointerType;
    if (ptipFor && pillAt(e.target) !== ptipFor) hideTip();
  }, true);
  // captured before the buttons see it: a tap on the bars only shows the info
  document.addEventListener('click', (e) => {
    const p = pillAt(e.target);
    if (!p || pointer === 'mouse') return;
    e.preventDefault();
    e.stopPropagation();
    if (ptipFor === p) hideTip();
    else showTip(p, true);
  }, true);
  window.addEventListener('scroll', hideTip, { passive: true });
  scroller.addEventListener('scroll', hideTip, { passive: true });
  listEl.addEventListener('scroll', hideTip, { passive: true });
}
