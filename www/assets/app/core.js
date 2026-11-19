// 1radiœ web app: what every part needs. Station data and texts from the page, the
// state of the player, small helpers for DOM and labels. Imports nothing.
// Also turns the encoded e-mail addresses of the texts into links, at load, even
// without station data.

export const SCRIPT_SRC = new URL('../app.js', import.meta.url).href;   // the entry; hls.js lies beside it in vendor/
export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
/* E-mail addresses in the texts (Impressum, Datenverarbeitung, Über): the server sends
   them encoded (app/texts.php, text_protect_mail), so they are not in the HTML for
   address collectors. Here they become normal mailto links, before anything copies the
   texts (the menu on phones). Runs also when the stations could not be loaded. */
$$('.mail[data-m]').forEach((n) => {
  let target = '';
  try { target = decodeURIComponent(atob([...n.dataset.m].reverse().join(''))); } catch { return; }
  const addr = target.replace(/^mailto:/i, '').split('?')[0];
  if (!/^mailto:/i.test(target) || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(addr)) return;
  const a = document.createElement('a');
  a.href = target;
  if (n.dataset.keep) a.append(...n.childNodes);
  else a.textContent = addr;
  n.replaceWith(a);
});

const dataEl = $('#r1-data');
// without station data the entry starts nothing (app.js)
export const data = dataEl ? JSON.parse(dataEl.textContent) : null;
export const { levels } = data || { levels: {} };

export const SLOT = 88;                                         // width of a place on the scale, as in CSS
export const RETRY = [1, 2, 5, 10, 10, 10, 10, 10, 10, 10, 10, 10];   // seconds between reconnects
export const STATUS_EVERY = 30000;
// texts in the page's language (index.php, webapp_script_strings); German if missing
export const T = Object.assign({
  listening: 'Olli hört gerade', listeningTo: 'Olli hört gerade', and: 'und',
  phase: { idle: 'Bereit', connecting: 'Verbindet', live: 'Live', paused: 'Pausiert', error: 'Keine Verbindung' },
  all: 'Alle Sender', category: 'Kategorie {name}', count1: '1 Sender', countN: '{n} Sender',
  holdsCur: 'enthält den aktuellen Sender', play: 'Abspielen', pause: 'Pause',
  noDesc: 'Keine Beschreibung.', website: 'Website: {host}', listenOn: 'Hören wird erfasst', listenOff: 'Erfassung pausiert',
  scPlaying: 'Spielt', scFrame: 'Player von SoundCloud',
  ytFrame: 'Player von YouTube', ytGone: 'Video nicht verfügbar', videos1: '1 Video', videosN: '{n} Videos',
}, (data && data.text) || {});
const TEXT = T.phase;
const LISTENING = T.listening;
export const fill = (s, vars) => s.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m));
// a category counts stations, a YouTube playlist videos
export const countText = (n, it) => (it && it.videos ? (n === 1 ? T.videos1 : fill(T.videosN, { n })) : n === 1 ? T.count1 : fill(T.countN, { n }));
export const NOW_EVERY = 25000;                                 // how often the song title is asked for while playing
// the page's title from the server (the site, or the station of a station page); the tab
// names the station only while it plays, search engines keep this one
export const PAGE_TITLE = document.title;

/** Every station and category by id; stations know their level ('root' or a category id). */
export const items = new Map();
for (const [level, list] of Object.entries(levels)) {
  for (const it of list) items.set(it.id, Object.assign(it, { level }));
}
// everything playable in a list: stations, SoundCloud tracks and YouTube videos (Deezer only opens a note)
export const stationsOf = (level) => (levels[level] || []).filter((x) => x.type === 'station' || x.type === 'soundcloud' || x.type === 'youtube');
export const isSc = (it) => Boolean(it && it.type === 'soundcloud');
export const isYt = (it) => Boolean(it && it.type === 'youtube');
// plays in a player of its own (SoundCloud, YouTube), not as radio in <audio>
export const isOwn = (it) => isSc(it) || isYt(it);

export const state = {
  view: 'root',        // level shown on the scale
  cur: null,           // the station in the player
  curLevel: 'root',    // level it was started from: Vor/Zurück stays there
  phase: 'idle',       // idle | connecting | live | paused | error
  active: new Set(),   // stations Ollie is listening to
  last: null,          // the station he heard last (api/status), shown while he plays nothing
  openCat: null,       // open category in the phone list
  retry: 0,
  touched: false,      // the visitor started something: from now on changes are announced
  mqPick: null,        // station picked on a phone: its line runs once it plays (view.js)
};
export const isPlaying = () => state.phase === 'live' || state.phase === 'connecting';
// SoundCloud and YouTube are not live radio: "Spielt" instead of "Live"
export const phaseText = (phase) => (phase === 'live' && isOwn(state.cur) ? T.scPlaying
  : phase === 'error' && isYt(state.cur) ? T.ytGone : TEXT[phase]);

/* ---------- Helpers ---------- */

export function el(tag, cls, text) {
  const node = document.createElement(tag);
  if (cls) node.className = cls;
  if (text !== undefined) node.textContent = text;
  return node;
}

function initials(name) {
  const words = String(name).replace(/[^\p{L}\p{N} ]+/gu, ' ').trim().split(/\s+/).filter(Boolean);
  if (!words.length) return '?';
  if (words.length === 1) return words[0].slice(0, 3).toUpperCase();
  return (words[0][0] + words[1][0]).toUpperCase();
}

export function image(src, onFail) {
  const img = new Image();
  img.alt = '';
  img.decoding = 'async';
  img.loading = 'lazy';
  img.addEventListener('error', onFail, { once: true });
  img.src = src;
  return img;
}

/* small stack in the top right corner: this tile is a category, not a station */
const STACK = '<svg viewBox="0 0 16 16" aria-hidden="true">'
  + '<rect x="5.2" y="1.8" width="9" height="9" rx="2.2" fill="none" stroke="currentColor" stroke-width="1.6"/>'
  + '<rect x="1.8" y="5.2" width="9" height="9" rx="2.2" fill="currentColor"/></svg>';

/* arrow out of the corner: this tile leads to Deezer, it does not play here */
const OUT = '<svg viewBox="0 0 16 16" aria-hidden="true">'
  + '<path d="M6 3.5h6.5V10M12.5 3.5 4 12" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';

/**
 * Fills a tile with the logo, or the initials if there is none (or it fails to load).
 * A category shows its own picture from the admin, without one the collage the server
 * makes from the first logos inside (as in Sonos), and the stack on top. A Deezer
 * entry gets an arrow out instead. Small tiles take the small picture (192 px), only
 * the big one in the player the full logo (600 px).
 */
export function fillTile(node, it) {
  const isCat = it.type === 'category';
  const isOut = it.type === 'deezer';
  node.replaceChildren();
  node.classList.toggle('cat', isCat || isOut);
  const stack = () => {
    if (!isCat && !isOut) return;
    const s = el('span', 'stack');
    s.innerHTML = isCat ? STACK : OUT;
    node.append(s);
  };
  const src = node.classList.contains('tile-xl') ? it.logo : (it.thumb || it.logo);
  if (src) {
    node.append(image(src, () => {
      node.textContent = initials(it.name);
      stack();
    }));
  } else node.textContent = initials(it.name);
  stack();
}

export function pillEl(it) {
  const p = el('span', 'pill');
  p.dataset.item = it.id;   // the info on hover or tap names the station
  p.append(el('i'), el('i'), el('i'));
  p.hidden = true;
  return p;
}

export function isActive(it) {
  if (it.type === 'category') return (levels[it.id] || []).some((x) => state.active.has(x.id));
  return state.active.has(it.id);
}

function holdsCur(cat) {
  return Boolean(state.cur && state.cur.level === cat.id);
}

export function label(it) {
  let text = it.type === 'category'
    ? `${it.num} ${fill(T.category, { name: it.name })}, ${countText(it.count, it)}`
    : `${it.num} ${it.name}`;
  if (it.type !== 'category' && state.cur && state.cur.id === it.id) text += ', ' + phaseText(state.phase);
  if (it.type === 'category' && holdsCur(it)) text += ', ' + T.holdsCur;
  if (isActive(it)) text += ', ' + LISTENING;
  return text;
}
