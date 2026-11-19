// 1radiœ web app: dialogs, the menu and the pages from the menu.

import { $, $$, T, data, fill, fillTile, state } from './core.js';
import { marquee } from './view.js';

/* ---------- Dialogs ---------- */

function fillInfo() {
  const it = state.cur;
  if (!it) return false;
  fillTile($('[data-info="art"]'), it);
  $('[data-info="name"]').textContent = it.name;
  const desc = $('[data-info="desc"]');
  desc.textContent = it.desc || T.noDesc;
  // origin (city, country in the page's language) and website, each line only if set
  const place = [it.city, regionName(it.country)].filter(Boolean).join(', ');
  $('[data-info="place"]').textContent = place;
  $('[data-info="origin"]').hidden = !place;
  const site = $('[data-info="website"]');
  site.hidden = !it.website;
  $('[data-info="links"]').hidden = !place && !it.website;
  if (it.website) {
    site.href = it.website;
    let host = it.website;
    try { host = new URL(it.website).host.replace(/^www\./, ''); } catch { /* keep the address */ }
    $('[data-info="host"]', site).textContent = host;
    site.setAttribute('aria-label', fill(T.website, { host }));   // the globe says it on screen
  }
  return true;
}

/** A country's name from its ISO code, in the language of the page ("FR": Frankreich, France). */
function regionName(code) {
  if (!code) return '';
  try {
    return new Intl.DisplayNames([data.lang || 'de'], { type: 'region' }).of(code) || code;
  } catch {
    return code;   // old browser: the code itself
  }
}

/** A Deezer entry: a note about where it plays, with the way there. */
export function openDeezer(it) {
  const d = document.getElementById('deezer');
  if (!d || typeof d.showModal !== 'function') {
    window.open(it.url, '_blank', 'noopener');
    return;
  }
  fillTile($('[data-dz="art"]', d), it);
  $('[data-dz="name"]', d).textContent = it.name;
  const desc = $('[data-dz="desc"]', d);
  desc.textContent = it.desc || '';
  desc.hidden = !it.desc;
  $('[data-dz="link"]', d).href = it.url;
  if (!d.open) d.showModal();
}

export function openDialog(id) {
  const d = document.getElementById(id);
  if (!d || d.open || typeof d.showModal !== 'function') return;
  if (id === 'info' && !fillInfo()) return;
  d.showModal();
  if (id === 'sheet') {
    setTimeout(() => $$('.sheet .track:not(.is-desc) > span').forEach((n) => marquee(n)), 700);
  }
}

/* Menu on phones: a dialog that slides in from the right (class is-open moves it in).
   Its links show the text of a page in the menu itself, the arrow leads back to the links.
   The texts stay in the dialogs, which wide screens show as before. */
const menu = $('#menu');
const menuBtn = $('[data-menu]');
const menuNav = $('.drawer-nav');
const menuPage = $('[data-page]');
const menuBack = $('[data-back]');
const PAGES = ['about', 'impressum', 'datenverarbeitung'];
// the page in links like 1radioe.net/#impressum; #datenschutz is the old name of #datenverarbeitung
export const pageFromHash = () => {
  const id = location.hash.slice(1) === 'datenschutz' ? 'datenverarbeitung' : location.hash.slice(1);
  return PAGES.includes(id) ? id : null;
};
const clearHash = () => history.replaceState(null, '', location.pathname + location.search);
const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// copy the text of a dialog into the menu, without its close button and ids
function showPage(id) {
  const src = document.getElementById(id);
  if (!menu || !src) return;
  const nodes = [...src.children].filter((n) => !n.matches('[data-close]')).map((n) => {
    const c = n.cloneNode(true);
    c.removeAttribute('id');
    $$('[id]', c).forEach((x) => x.removeAttribute('id'));
    return c;
  });
  menuPage.replaceChildren(...nodes);
  const h = $('h2', menuPage);
  if (h) {
    h.id = 'menu-page-title';
    h.tabIndex = -1;
  }
  menu.dataset.page = id;
  menuNav.hidden = true;
  menuNav.classList.remove('from-page');
  menuPage.hidden = false;
  menuBack.hidden = false;
  menu.scrollTop = 0;
  if (h) h.focus({ preventScroll: true });
}

function showLinks() {
  const from = menu.dataset.page;
  if (!from) return;
  delete menu.dataset.page;
  menuPage.hidden = true;
  menuPage.replaceChildren();
  menuBack.hidden = true;
  menuNav.hidden = false;
  menuNav.classList.add('from-page');
  menu.scrollTop = 0;
  const link = $(`[data-dialog="${from}"]`, menuNav);
  if (link) link.focus();
}

// a page from a link like 1radioe.net/#impressum: in the menu, on phones and on the desktop
export function openPage(id) {
  if (menu && menuBtn && typeof menu.showModal === 'function') {
    openMenu();
    showPage(id);
  } else openDialog(id);
}

function openMenu() {
  if (!menu || menu.open || typeof menu.showModal !== 'function') return;
  menu.showModal();
  menuBtn.setAttribute('aria-expanded', 'true');
  // read its size, so the browser places it outside first and then slides it in
  void menu.offsetWidth;
  menu.classList.add('is-open');
}

// the menu slides out before it closes, every other dialog closes at once
function closeDialog(d, after) {
  if (!d.open) return;
  if (!d.classList.contains('drawer') || reducedMotion()) {
    d.close();
    if (after) after();
    return;
  }
  let done = false;
  const finish = () => {
    if (done) return;
    done = true;
    d.close();
    if (after) after();
  };
  d.addEventListener('transitionend', finish, { once: true });
  setTimeout(finish, 400);
  d.classList.remove('is-open');
}

const dzLink = $('[data-dz="link"]');

/** Listeners and first state of this part, called once by the entry. */
export function init() {
  if (menu && menuBtn) {
    menuBtn.addEventListener('click', openMenu);
    // Escape and the back gesture on Android: from a page back to the links, then the menu slides out
    menu.addEventListener('cancel', (e) => {
      e.preventDefault();
      if (menu.dataset.page) showLinks();
      else closeDialog(menu);
    });
    menu.addEventListener('close', () => {
      menu.classList.remove('is-open');
      menuBtn.setAttribute('aria-expanded', 'false');
      if (pageFromHash()) clearHash();
      // the next time it opens with the links
      delete menu.dataset.page;
      menuPage.hidden = true;
      menuPage.replaceChildren();
      menuBack.hidden = true;
      menuNav.hidden = false;
      menuNav.classList.remove('from-page');
    });
    menuBack.addEventListener('click', showLinks);
    // the other language: keeps the station that is in the player
    $$('[data-lang-link]', menu).forEach((a) => a.addEventListener('click', () => {
      if (!state.cur || a.getAttribute('aria-current') === 'true') return;
      const url = new URL(a.href);
      url.searchParams.set('s', state.cur.id);
      a.href = url.href;
    }));
  }

  $$('[data-dialog]').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault();
    if (a.closest('dialog.drawer')) showPage(a.dataset.dialog);
    else openDialog(a.dataset.dialog);
  }));

  if (dzLink) dzLink.addEventListener('click', () => closeDialog(dzLink.closest('dialog')));
  $$('dialog').forEach((d) => {
    $$('[data-close]', d).forEach((b) => b.addEventListener('click', () => closeDialog(d)));
    // a click on the dark area around a dialog closes it
    d.addEventListener('click', (e) => {
      if (e.target !== d || d.classList.contains('sheet')) return;
      const r = d.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) closeDialog(d);
    });
    d.addEventListener('close', () => {
      if (pageFromHash() === d.id) clearHash();
    });
  });
}
