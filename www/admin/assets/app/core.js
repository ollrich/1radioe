// 1radiœ admin: what every part needs. State, limits, DOM helpers, toasts and the
// calls to api.php. Imports nothing.

export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
const csrf = $('meta[name="csrf-token"]').content;
export const MAX_IMAGE = 8 * 1024 * 1024;
export const MAX_TRACK = 300 * 1024 * 1024;
export const CHUNK = 2 * 1024 * 1024;

export const state = { stations: [], mimes: {}, playlist: null, category: null, tracks: [], youtube: { key: false, store: true } };

/* ---------- Helpers ---------- */

export async function api(action, data, query = {}) {
  const init = { method: data === undefined ? 'GET' : 'POST', headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' };
  if (data instanceof FormData) {
    init.body = data;
  } else if (data !== undefined) {
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(data);
  }
  const params = new URLSearchParams({ a: action, ...query });
  const res = await fetch('api.php?' + params, init);
  let json = {};
  try { json = await res.json(); } catch { /* empty */ }
  if (res.status === 401) {
    location.reload();
    throw new Error('Bitte neu anmelden.');
  }
  if (!res.ok) {
    const err = new Error(json.error || 'Fehler ' + res.status);
    err.fields = json.fields || {};
    throw err;
  }
  return json;
}

let toastTimer;
export function toast(message, kind = '') {
  const box = $('#toast');
  box.textContent = message;
  box.className = 'toast ' + kind;
  box.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { box.hidden = true; }, kind === 'error' ? 7000 : 3400);
}

export function el(tag, cls, text) {
  const node = document.createElement(tag);
  if (cls) node.className = cls;
  if (text !== undefined) node.textContent = text;
  return node;
}

function initials(title) {
  return title.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => [...w][0]).join('').toUpperCase();
}

export function art(url, title, cls = 'art') {
  const box = el('div', cls);
  if (url) {
    const img = el('img');
    img.src = url;
    img.alt = '';
    img.loading = 'lazy';
    img.draggable = false;
    box.append(img);
  } else {
    box.append(el('span', 'initials', initials(title || '?')));
  }
  return box;
}

export function clock(sec) {
  sec = Math.round(sec || 0);
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = String(sec % 60).padStart(2, '0');
  return h ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
}

export function total(sec) {
  const min = Math.round(sec / 60);
  return min >= 60 ? `${Math.floor(min / 60)} Std. ${min % 60} Min.` : `${min} Min.`;
}

export function trackCount(n) {
  return n === 1 ? '1 Track' : `${n} Tracks`;
}

/** Two-click delete: first click asks, second click within 4 s confirms. */
export function confirmButton(btn, label) {
  if (btn.classList.contains('confirm')) {
    btn.classList.remove('confirm');
    btn.textContent = label;
    return true;
  }
  btn.classList.add('confirm');
  btn.textContent = 'Wirklich?';
  clearTimeout(btn._t);
  btn._t = setTimeout(() => { btn.classList.remove('confirm'); btn.textContent = label; }, 4000);
  return false;
}

export function showErrors(form, err) {
  for (const [field, msg] of Object.entries(err.fields || {})) {
    const slot = $(`[data-err="${field}"]`, form);
    if (slot) slot.textContent = msg;
  }
}

export function clearErrors(form) {
  $$('[data-err]', form).forEach((e) => { e.textContent = ''; });
}
