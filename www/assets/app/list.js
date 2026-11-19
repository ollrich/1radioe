// 1radiœ web app: the list on phones, categories open in place.

import { $, $$, el, fillTile, levels, pillEl, state } from './core.js';
import { canHover, marquee, stopMarquee, updateIndicators } from './view.js';
import { pick } from './audio.js';

/* ---------- List (phones) ---------- */

export const listEl = $('[data-r1="list"]');
export const rows = new Map();

function rowFor(it, level) {
  const row = el('button', 'row' + (level !== 'root' ? ' is-sub' : ''));
  row.type = 'button';
  const tile = el('span', 'tile tile-s');
  fillTile(tile, it);
  const name = el('span', 'rname', it.name);
  const sub = el('span', 'rsub');
  const rn = el('span', 'rn');
  rn.append(name, sub);
  const pill = pillEl(it);
  const parts = [el('span', 'num', it.num), tile, rn, pill];
  if (it.type === 'category') {
    const chev = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    chev.setAttribute('class', 'chev');
    chev.setAttribute('width', '20');
    chev.setAttribute('height', '20');
    chev.setAttribute('viewBox', '0 0 24 24');
    chev.innerHTML = '<path d="M5.3 8.3 12 15l6.7-6.7 1.4 1.4L12 17.8 3.9 9.7z"/>';
    parts.push(chev);
  }
  parts.forEach((p) => p.setAttribute('aria-hidden', 'true'));
  row.append(...parts);
  rows.set(it.id, { row, sub, pill, it });
  row.addEventListener('mouseenter', () => marquee(sub, () => canHover.matches && row.matches(':hover')));
  row.addEventListener('focus', () => marquee(sub, () => row === document.activeElement));
  row.addEventListener('mouseleave', () => stopMarquee(sub));
  row.addEventListener('blur', () => stopMarquee(sub));
  return row;
}

export function renderList() {
  listEl.replaceChildren();
  rows.clear();
  for (const it of levels.root) {
    const li = el('li');
    const row = rowFor(it, 'root');
    li.append(row);
    if (it.type === 'category') {
      const inner = el('ul');
      inner.id = 'cat-' + it.id;
      inner.hidden = state.openCat !== it.id;
      row.setAttribute('aria-controls', inner.id);
      row.setAttribute('aria-expanded', String(state.openCat === it.id));
      for (const ch of levels[it.id] || []) {
        const li2 = el('li');
        const r2 = rowFor(ch, it.id);
        r2.addEventListener('click', () => pick(ch, it.id));
        li2.append(r2);
        inner.append(li2);
      }
      row.addEventListener('click', () => {
        state.openCat = state.openCat === it.id ? null : it.id;
        $$('ul[id^="cat-"]', listEl).forEach((u) => { u.hidden = u.id !== 'cat-' + state.openCat; });
        $$('.row[aria-expanded]', listEl).forEach((r) => r.setAttribute('aria-expanded', String(r.getAttribute('aria-controls') === 'cat-' + state.openCat)));
        updateIndicators();
      });
      li.append(inner);
    } else {
      row.addEventListener('click', () => pick(it, 'root'));
    }
    listEl.append(li);
  }
}
