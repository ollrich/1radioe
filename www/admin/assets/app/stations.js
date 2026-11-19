// 1radiœ admin: the router, the station grid with drag and drop, and the category view.

import { $, $$, api, art, el, state, toast, trackCount } from './core.js';
import { openEditor } from './editor.js';
import { loadLibrary, loadPlaylist } from './tracks.js';
import { loadSystem } from './system.js';
import { loadTexts } from './texts.js';
import { loadSocial } from './social.js';

/* ---------- Router ---------- */

export function route() {
  const hash = location.hash || '#stations';
  let view = 'stations';
  if (hash.startsWith('#playlist/')) view = 'playlist';
  else if (hash.startsWith('#category/')) view = 'category';
  else if (hash === '#tracks') view = 'tracks';
  else if (hash === '#system') view = 'system';
  else if (hash === '#posting') view = 'posting';
  else if (hash === '#texts') view = 'texts';
  $$('.view').forEach((v) => { v.hidden = v.id !== 'view-' + view; });
  const tab = view === 'playlist' || view === 'category' ? 'stations' : view;
  $$('.tab').forEach((t) => t.setAttribute('aria-current', t.dataset.view === tab ? 'page' : 'false'));
  // phones with large text: the tab bar scrolls, the current tab stays in view
  const cur = $(`.tab[data-view="${tab}"]`);
  if (cur && cur.parentElement.scrollWidth > cur.parentElement.clientWidth) cur.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  if (view === 'playlist') loadPlaylist(Number(hash.split('/')[1]));
  if (view === 'category') loadCategory(Number(hash.split('/')[1]));
  if (view === 'tracks') loadLibrary();
  if (view === 'system') loadSystem();
  if (view === 'posting') loadSocial();
  if (view === 'texts') loadTexts();
  if (view === 'stations') loadState();
  window.scrollTo(0, 0);
}

/* ---------- Station grid ---------- */

function membersOf(id) {
  return state.stations.filter((x) => x.category_id === id);
}

function entryCount(n) {
  return n === 1 ? '1 Eintrag' : `${n} Einträge`;
}

function videoCount(n) {
  return n === 1 ? '1 Video' : `${n} Videos`;
}

function tile(s) {
  const size = s.kind === 'playlist' ? s.tracks : s.kind === 'category' ? membersOf(s.id).length : s.kind === 'youtube' ? s.videos : 1;
  const empty = s.kind !== 'stream' && size === 0;
  const t = el('div', 'tile' + (s.active ? '' : ' inactive') + (empty ? ' is-empty' : '') + (s.kind !== 'stream' ? ' is-' + s.kind : ''));
  t.dataset.id = s.id;
  t.tabIndex = 0;
  t.setAttribute('role', 'button');
  const kindName = { playlist: ', Playlist', category: ', Kategorie', soundcloud: ', SoundCloud', deezer: ', Deezer', youtube: ', YouTube-Playlist' }[s.kind] || '';
  t.setAttribute('aria-label', s.title + kindName + (s.active ? '' : ', inaktiv') + (empty ? ', leer' : ''));
  const a = art(s.logo_url, s.title);
  if (s.kind === 'youtube') {
    const kind = el('span', 'kind', '▶ ' + (empty ? 'leer' : videoCount(size)));
    kind.title = 'YouTube-Playlist, nur in der Web-App, gleicht sich täglich mit YouTube ab';
    a.append(kind);
    if (s.check_ok === false) {
      const dot = el('span', 'dot fail');
      dot.title = 'Letzter Abgleich: ' + s.check_info;
      a.append(dot);
    }
  } else if (s.kind === 'soundcloud' || s.kind === 'deezer') {
    const kind = el('span', 'kind', s.kind === 'deezer' ? '↗ Deezer' : '☁ SoundCloud');
    kind.title = s.kind === 'deezer' ? 'Nur in der Web-App, führt zu Deezer' : 'Nur in der Web-App, nicht in Sonos';
    a.append(kind);
  } else if (s.kind !== 'stream') {
    const icon = s.kind === 'playlist' ? '♫ ' : '▦ ';
    const kind = el('span', 'kind', icon + (empty ? 'leer' : s.kind === 'playlist' ? trackCount(size) : entryCount(size)));
    if (empty) kind.title = 'Leere Playlists und Kategorien zeigt Sonos nicht an';
    a.append(kind);
  } else {
    const dot = el('span', 'dot ' + (s.check_ok === null ? 'unknown' : s.check_ok ? 'ok' : 'fail'));
    dot.title = s.check_info ? 'Letzter Test: ' + s.check_info + testedAt(s) : 'Noch nicht getestet';
    a.append(dot);
    if (s.https_ok === false) {
      const http = el('span', 'badge http', 'HTTP');
      http.title = 'Nur per HTTP erreichbar, spielt nicht in der Web-App';
      a.append(http);
    }
  }
  if (!s.active) a.append(el('span', 'badge', 'inaktiv'));
  t.append(a, el('span', 'tile-name', s.title));
  const open = () => {
    if (s.kind === 'playlist') location.hash = '#playlist/' + s.id;
    else if (s.kind === 'category') location.hash = '#category/' + s.id;
    else openEditor(s);
  };
  t.addEventListener('click', open);
  t.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
  return t;
}

/** "3 Sender, 1 Playlist, 2 Kategorien" */
/** ", 19.11.26, 12:52": when a stream was tested last (by hand or by the daily test on the server). */
function testedAt(s) {
  return s.checked_at ? ', ' + new Date(s.checked_at.replace(' ', 'T')).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' }) : '';
}

function describe(list) {
  const n = (k) => list.filter((s) => s.kind === k).length;
  const parts = [`${n('stream')} Sender`];
  if (n('playlist')) parts.push(n('playlist') === 1 ? '1 Playlist' : `${n('playlist')} Playlists`);
  if (n('soundcloud')) parts.push(n('soundcloud') === 1 ? '1 SoundCloud-Track' : `${n('soundcloud')} SoundCloud-Tracks`);
  if (n('deezer')) parts.push(n('deezer') === 1 ? '1 Deezer-Playlist' : `${n('deezer')} Deezer-Playlists`);
  if (n('youtube')) parts.push(n('youtube') === 1 ? '1 YouTube-Playlist' : `${n('youtube')} YouTube-Playlists`);
  if (n('category')) parts.push(n('category') === 1 ? '1 Kategorie' : `${n('category')} Kategorien`);
  return parts.join(', ');
}

function renderGrid() {
  $('#grid').replaceChildren(...state.stations.filter((s) => !s.category_id).map(tile));
  const active = state.stations.filter((s) => s.active).length;
  // the server tests the active streams once a day; a red dot stays until a test works again
  const broken = state.stations.filter((s) => s.kind === 'stream' && s.active && s.check_ok === false).length;
  $('#summary').textContent = state.stations.length
    ? `${describe(state.stations)}, davon ${active} aktiv` + (broken ? `, ${broken === 1 ? '1 Stream' : broken + ' Streams'} mit Problem (roter Punkt)` : '')
    : '';
  $('#check-all').title = 'Der Server prüft die aktiven Streams außerdem täglich von selbst';
  $('#empty').hidden = state.stations.length > 0;
  $('#check-all').hidden = !state.stations.some((s) => s.kind === 'stream');
  if (state.category && location.hash === '#category/' + state.category.id) renderCategory();
}

/* Countries for the origin of a station: ISO codes, named in German by the browser */
const COUNTRIES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS XK YE YT ZA ZM ZW'.split(' ');

function fillCategorySelect() {
  const select = $('#category-select');
  const none = el('option', '', 'Keine, direkt in der Liste');
  none.value = '';
  select.replaceChildren(none, ...state.stations.filter((s) => s.kind === 'category').map((c) => {
    const o = el('option', '', c.title);
    o.value = c.id;
    return o;
  }));
}

export async function loadState() {
  try {
    const data = await api('state');
    state.stations = data.stations;
    if (data.youtube) state.youtube = data.youtube;
    fillCategorySelect();
    if (!Object.keys(state.mimes).length) {
      state.mimes = data.mimes;
      const select = $('#mime');
      const none = el('option', '', 'Bitte wählen');
      none.value = '';
      select.replaceChildren(none);
      for (const [value, label] of Object.entries(state.mimes)) {
        const o = el('option', '', label);
        o.value = value;
        select.append(o);
      }
    }
    renderGrid();
    return true;
  } catch (e) {
    toast(e.message, 'error');
    return false;
  }
}

/** Reloads whatever is on screen after a change. */
export function refreshView() {
  const hash = location.hash;
  if (hash.startsWith('#playlist/')) return loadPlaylist(Number(hash.split('/')[1]));
  if (hash.startsWith('#category/')) return loadCategory(Number(hash.split('/')[1]));
  return loadState();
}

/** Drag & drop in the top level grid and in the grid of a category. */
export function initGridSort(grid) {
  window.Sortable.create(grid, {
    animation: 160, draggable: '.tile', delay: 150, delayOnTouchOnly: true, ghostClass: 'drag-ghost', chosenClass: 'drag-chosen',
    onEnd: async (evt) => {
      if (evt.oldIndex === evt.newIndex) return;
      const ids = $$('.tile', grid).map((t) => Number(t.dataset.id));
      try {
        await api('stations.reorder', { ids });
        toast('Reihenfolge gespeichert');
      } catch (e) {
        toast(e.message, 'error');
      }
      loadState();
    },
  });
}

/* ---------- Category view ---------- */

async function loadCategory(id) {
  if (!(await loadState())) return;
  const c = state.stations.find((s) => s.id === id && s.kind === 'category');
  if (!c) {
    toast('Kategorie nicht gefunden.', 'error');
    location.hash = '#stations';
    return;
  }
  state.category = c;
  renderCategory();
}

function renderCategory() {
  const c = state.stations.find((s) => s.id === state.category.id) || state.category;
  state.category = c;
  const members = membersOf(c.id);
  $('#cat-cover').replaceChildren(art(c.logo_url, c.title, 'art'));
  $('#cat-title').textContent = c.title;
  $('#cat-desc').textContent = c.description;
  $('#cat-inactive').hidden = c.active;
  $('#cat-meta').textContent = members.length ? describe(members) : 'Leer, Sonos zeigt die Kategorie erst mit Inhalt an';
  $('#cat-grid').replaceChildren(...members.map(tile));
  $('#cat-empty').hidden = members.length > 0;
}

/* Assign existing streams and playlists to the open category */

export const stPicker = $('#st-picker');
export async function openStationPicker() {
  await loadState();
  const c = state.category;
  const names = Object.fromEntries(state.stations.filter((s) => s.kind === 'category').map((x) => [x.id, x.title]));
  const list = $('#st-picker-list');
  list.replaceChildren();
  // YouTube playlists are stacks of their own and stay on the top level
  const candidates = state.stations.filter((s) => s.kind !== 'category' && s.kind !== 'youtube' && s.category_id !== c.id);
  if (!candidates.length) list.append(el('li', 'muted', 'Alle Sender und Playlists sind schon in dieser Kategorie.'));
  for (const s of candidates) {
    const label = el('label', 'pick');
    const box = el('input');
    box.type = 'checkbox';
    box.value = s.id;
    const text = el('span', 'track-text');
    const where = s.category_id ? 'jetzt in „' + (names[s.category_id] || '?') + '“' : 'jetzt direkt in der Liste';
    const prefix = { playlist: 'Playlist, ', soundcloud: 'SoundCloud, ', deezer: 'Deezer, ' }[s.kind] || '';
    text.append(el('span', 'track-title', s.title), el('span', 'track-sub', prefix + where));
    label.append(box, art(s.logo_url, s.title, 'thumb'), text);
    const li = el('li');
    li.append(label);
    list.append(li);
  }
  updateStationPickerCount();
  stPicker.showModal();
}

export function updateStationPickerCount() {
  const n = $$('#st-picker-list input:checked').length;
  $('#st-picker-count').textContent = n ? `${n} ausgewählt` : '';
  $('#st-picker-add').disabled = n === 0;
}

export async function assignPicked(e) {
  e.preventDefault();
  const ids = $$('#st-picker-list input:checked').map((b) => Number(b.value));
  if (!ids.length) return;
  try {
    const { stations } = await api('stations.assign', { category: state.category.id, ids });
    state.stations = stations;
    stPicker.close();
    renderGrid();
    renderCategory();
    toast(`${entryCount(ids.length)} zugeordnet`);
  } catch (err) {
    toast(err.message, 'error');
  }
}

export async function checkAll() {
  const btn = $('#check-all');
  const streams = state.stations.filter((s) => s.kind === 'stream');
  btn.disabled = true;
  let ok = 0;
  let fail = 0;
  for (const [i, s] of streams.entries()) {
    btn.textContent = `Prüfe ${i + 1}/${streams.length} …`;
    try {
      const { probe } = await api('probe', { url: s.stream_url, id: s.id });
      s.check_ok = probe.ok;
      s.check_info = probe.info;
      s.https_ok = probe.https;
    } catch (e) {
      s.check_ok = false;
      s.check_info = e.message;
    }
    s.check_ok ? ok++ : fail++;
    renderGrid();
  }
  btn.disabled = false;
  btn.textContent = 'Alle Streams prüfen';
  toast(fail ? `${ok} in Ordnung, ${fail} mit Problem (roter Punkt)` : `Alle ${ok} Streams in Ordnung`, fail ? 'error' : '');
}

/** Listeners and first state of this part, called once by the entry. */
export function init() {
  (function fillCountrySelect() {
    const select = $('#country-select');
    if (!select) return;
    let names;
    try {
      const dn = new Intl.DisplayNames(['de'], { type: 'region' });
      names = (c) => dn.of(c) || c;
    } catch {
      names = (c) => c;
    }
    const none = el('option', '', 'Kein Land');
    none.value = '';
    const list = COUNTRIES.map((c) => [c, names(c)]).sort((a, b) => a[1].localeCompare(b[1], 'de'));
    select.replaceChildren(none, ...list.map(([c, n]) => {
      const o = el('option', '', n);
      o.value = c;
      return o;
    }));
  })();
}
