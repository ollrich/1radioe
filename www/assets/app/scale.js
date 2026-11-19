// 1radiœ web app: the scale with the needle on wide screens, and the crumbs above it.

import { $, $$, SLOT, T, countText, el, fillTile, items, levels, pillEl, state } from './core.js';
import { updateIndicators } from './view.js';
import { pick, step } from './audio.js';

/* ---------- Scale (wide screens) ---------- */

export const scroller = $('[data-r1="scroller"]');
const strip = $('[data-r1="strip"]');
const crumbs = $('[data-r1="crumbs"]');
export const scale = { buttons: new Map(), needle: null };

export function renderScale() {
  const list = levels[state.view] || [];
  const ul = el('ul', 'slots');
  scale.buttons.clear();
  for (const it of list) {
    const li = el('li');
    const b = el('button', 'st');
    b.type = 'button';
    // tooltip: the full name (it may be cut off under the logo) and the description;
    // a category without one tells its number of stations
    const more = it.desc || (it.type === 'category' ? countText(it.count, it) : '');
    b.title = more ? `${it.name}\n${more}` : it.name;
    const tile = el('span', 'tile');
    fillTile(tile, it);
    const slot = el('span', 'pill-slot');
    slot.append(pillEl(it));
    const parts = [el('span', 'num', it.num), el('span', 'tick'), slot, tile, el('span', 'snm', it.name)];
    parts.forEach((p) => p.setAttribute('aria-hidden', 'true'));
    b.append(...parts);
    b.addEventListener('click', () => pick(it, state.view));
    li.append(b);
    ul.append(li);
    scale.buttons.set(it.id, b);
  }
  scale.needle = el('div', 'needle');
  scale.needle.setAttribute('aria-hidden', 'true');
  strip.style.width = (list.length * SLOT + 16) + 'px';
  strip.replaceChildren(scale.needle, ul);
  renderCrumbs();
  updateIndicators();
  requestAnimationFrame(() => { measure(); reveal(true); });
}

function renderCrumbs() {
  if (state.view === 'root') {
    // the same pill as the way back from a category, so nothing moves when one opens
    crumbs.replaceChildren(el('span', 'pillbtn here', T.all));
    return;
  }
  const back = el('button', 'pillbtn');
  back.type = 'button';
  back.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.7 4.3 8 12l7.7 7.7-1.4 1.4L5.2 12l9.1-9.1z"/></svg>';
  back.append(T.all);
  back.addEventListener('click', () => setView('root'));
  const sep = el('span', 'sep', '/');
  sep.setAttribute('aria-hidden', 'true');
  crumbs.replaceChildren(back, sep, el('span', '', items.get(state.view).name));
}

export function setView(view) {
  const from = state.view;
  state.view = view;
  renderScale();
  // keep the keyboard where it was: on "Alle Sender" or back on the category
  const target = view === 'root' ? scale.buttons.get(from) : $('button', crumbs);
  if (target) target.focus();
}

export function needleIndex() {
  const list = levels[state.view] || [];
  if (!state.cur) return [-1, false];
  if (state.view === state.cur.level) return [list.findIndex((x) => x.id === state.cur.id), false];
  if (state.view === 'root') return [list.findIndex((x) => x.id === state.cur.level), true];
  return [-1, false];
}

/* Scroll arrows when the scale is wider than the screen */
const arrows = $$('[data-scroll]');
function measure() {
  const overflow = scroller.scrollWidth > scroller.clientWidth + 1;
  arrows.forEach((a) => {
    a.hidden = !overflow;
    const dir = Number(a.dataset.scroll);
    const can = dir < 0 ? scroller.scrollLeft > 2 : scroller.scrollLeft + scroller.clientWidth < scroller.scrollWidth - 2;
    a.classList.toggle('is-off', !can);
    a.tabIndex = can ? 0 : -1;
  });
}

/** Brings the current station (or its category) into view. */
export function reveal(jump) {
  if (scroller.scrollWidth <= scroller.clientWidth + 1) return;
  const [idx] = needleIndex();
  if (idx < 0) {
    if (jump) scroller.scrollTo({ left: 0, behavior: 'auto' });
    return;
  }
  const left = 8 + idx * SLOT;
  const target = Math.max(0, left + SLOT / 2 - scroller.clientWidth / 2);
  if (jump) {
    // a jump must not animate: the CSS asks for smooth scrolling, which can stall on load
    scroller.style.scrollBehavior = 'auto';
    scroller.scrollLeft = target;
    scroller.style.scrollBehavior = '';
    measure();
  } else if (left < scroller.scrollLeft || left + SLOT > scroller.scrollLeft + scroller.clientWidth) {
    scroller.scrollTo({ left: target, behavior: 'smooth' });
  }
}

let measureFrame = 0;

/** Listeners and first state of this part, called once by the entry. */
export function init() {
  arrows.forEach((a) => a.addEventListener('click', () => {
    const dir = Number(a.dataset.scroll);
    scroller.scrollBy({ left: dir * Math.max(SLOT, scroller.clientWidth - 2 * SLOT) });
  }));
  // the mouse wheel scrolls the scale sideways while it has room in that direction; at its
  // end, and with a trackpad's own sideways swipe, the page behaves as usual
  scroller.addEventListener('wheel', (e) => {
    if (e.ctrlKey || Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
    const max = scroller.scrollWidth - scroller.clientWidth;
    if (max <= 1) return;
    const step = e.deltaMode === 1 ? e.deltaY * 32 : e.deltaMode === 2 ? e.deltaY * scroller.clientWidth : e.deltaY;
    if ((step < 0 && scroller.scrollLeft <= 0) || (step > 0 && scroller.scrollLeft >= max - 1)) return;
    e.preventDefault();
    scroller.style.scrollBehavior = 'auto';   // follow the wheel directly, no smooth lag
    scroller.scrollLeft += step;
    scroller.style.scrollBehavior = '';
  }, { passive: false });

  scroller.addEventListener('scroll', () => {
    cancelAnimationFrame(measureFrame);
    measureFrame = requestAnimationFrame(measure);
  }, { passive: true });
  if ('ResizeObserver' in window) new ResizeObserver(measure).observe(scroller);
}
