// 1radiœ admin: the editor for stations, playlists, categories and the link entries
// (SoundCloud, Deezer, YouTube), with the stream test.

import { $, $$, api, clearErrors, confirmButton, el, showErrors, state, toast } from './core.js';
import { ImageEditor } from './image.js';
import { refreshView } from './stations.js';

/* ---------- Station / playlist editor ---------- */

export const dialog = $('#editor');
export const form = $('#station-form');
// form.title would return the form's own title attribute, so fields are read via elements
export const F = (name) => form.elements.namedItem(name);
export const stationImage = new ImageEditor($('.image-editor', dialog));
const ed = { station: null, kind: 'stream', probe: null };

function setProbe(ok, text) {
  const p = $('#probe-result');
  p.hidden = false;
  p.className = 'probe ' + (ok ? 'ok' : 'fail');
  p.textContent = (ok ? '✓ ' : '✗ ') + text;
}

/** Browser playback: true = HTTPS, false = HTTP only, null = unknown. $variant: working https address. */
function setHttps(https, variant = null) {
  const box = $('#probe-https');
  box.replaceChildren();
  box.hidden = https === null || https === undefined;
  if (box.hidden) return;
  box.className = 'probe probe-https ' + (https ? 'ok' : 'warn');
  box.append(el('span', '', https
    ? '✓ Per HTTPS erreichbar, spielt auch in der Web-App.'
    : '⚠ Nur per HTTP erreichbar. Browser spielen das auf 1radioe.net nicht ab.'));
  if (!https && variant) {
    const b = el('button', 'btn small', 'HTTPS-Variante übernehmen');
    b.type = 'button';
    b.title = variant;
    b.addEventListener('click', () => {
      F('stream_url').value = variant;
      streamUrlChanged();
      runProbe();
    });
    box.append(b);
  }
}

export function updateCount() {
  $('#desc-count').textContent = `${F('description').value.length}/500`;
}

export function openEditor(s, kind = 'stream', categoryId = null) {
  ed.station = s;
  ed.kind = s ? s.kind : kind;
  ed.probe = null;
  const isList = ed.kind !== 'stream';
  form.reset();
  clearErrors(form);
  const isSc = ed.kind === 'soundcloud';
  const isYt = ed.kind === 'youtube';
  const isLink = isSc || isYt || ed.kind === 'deezer';   // web app only, an address elsewhere
  $$('.stream-only', form).forEach((n) => { n.hidden = isList; });
  $$('.link-only', form).forEach((n) => { n.hidden = !isLink; });
  $$('.yt-only', form).forEach((n) => { n.hidden = !isYt; });
  if (isLink) {
    $('#link-label').textContent = isSc ? 'SoundCloud-Link' : isYt ? 'YouTube-Playlist' : 'Deezer-Link';
    F('link_url').placeholder = isSc ? 'https://soundcloud.com/künstler/track'
      : isYt ? 'https://www.youtube.com/playlist?list=PL…'
      : 'https://link.deezer.com/s/… oder https://www.deezer.com/playlist/…';
    $('#link-help').textContent = isSc
      ? 'Die Adresse des Tracks auf soundcloud.com, auch ein Kurzlink aus der App (on.soundcloud.com). Name, Cover und Beschreibung legst du hier selbst fest. Der Track spielt nur in der Web-App im Player von SoundCloud, nicht in Sonos, und zählt nicht zum Hörstatus.'
      : isYt
        ? 'Die Adresse der Playlist auf YouTube, öffentlich oder nicht gelistet. In der Web-App wird sie ein Stapel wie eine Kategorie, das zuletzt hinzugefügte Video zuerst. Der Server gleicht einmal am Tag ab: neue Videos kommen dazu, private, gelöschte und nicht einbettbare fallen weg. Name, Beschreibung und Cover legst du hier selbst fest, ohne Cover zeigt der Stapel die ersten vier Videos. Die Videos spielen nur in der Web-App, sichtbar im Player von YouTube, nicht in Sonos und ohne Hörstatus.'
        : 'Der Link zur Playlist, auch der Teilen-Link aus der App (link.deezer.com). Gespeichert wird die reine Adresse, ohne Tracking und ohne deine Nutzernummer. Name, Cover und Beschreibung legst du hier selbst fest. In der Web-App führt ein Hinweis zu Deezer, in Sonos erscheint die Playlist nicht.';
  }
  if (isYt) {
    F('yt_key').value = '';
    F('yt_key').placeholder = state.youtube.key ? '••••••••  gespeichert' : '';
    $('#yt-key-hint').textContent = !state.youtube.store ? 'zum Speichern fehlt secret_key in config.php'
      : state.youtube.key ? 'gespeichert, leer lassen zum Behalten' : 'fehlt noch';
    ytState(s);
  }
  $('#active-label').textContent = isLink ? 'Aktiv, in der Web-App sichtbar' : 'Aktiv, in Sonos sichtbar';
  $('#editor-title').textContent = s ? s.title
    : ({ playlist: 'Neue Playlist', category: 'Neue Kategorie', soundcloud: 'Neuer SoundCloud-Track', deezer: 'Neue Deezer-Playlist', youtube: 'Neue YouTube-Playlist' }[ed.kind] || 'Neuer Sender');
  // the category field only appears when there are categories; unknown state keeps the stored one
  const hasCategories = state.stations.some((x) => x.kind === 'category');
  $('.cat-field', form).hidden = ed.kind === 'category' || isYt || !hasCategories;
  F('category_id').value = String((s ? s.category_id : categoryId) || '');
  F('title').value = s ? s.title : '';
  F('stream_url').value = s && !isLink ? s.stream_url : '';
  F('link_url').value = s && isLink ? s.stream_url : '';
  F('mime').value = s && !isList ? s.mime : '';
  F('description').value = s ? s.description : '';
  F('website').value = s ? (s.website || '') : '';
  // origin: streams and SoundCloud
  $('.origin-fields', form).hidden = !(ed.kind === 'stream' || ed.kind === 'soundcloud');
  F('country').value = s ? (s.country || '') : '';
  F('city').value = s ? (s.city || '') : '';
  F('social').checked = s ? s.social !== false : true;
  F('hashtags').value = s ? (s.hashtags || '') : '';
  F('active').checked = s ? s.active : true;
  // profiles: streams and MP3 playlists only; a new one is Olli's, as everything before
  $('.profile-fields', form).hidden = !(ed.kind === 'stream' || ed.kind === 'playlist');
  F('show_olli').checked = s ? s.show_olli !== false : true;
  F('show_cathy').checked = s ? Boolean(s.show_cathy) : false;
  F('is_playlist').value = s && s.is_playlist ? '1' : '0';
  $('#smapi-id').textContent = !s ? '' : ed.kind === 'deezer' || isYt ? `ID: ${s.smapi_id}`
    : isSc ? `ID: ${s.smapi_id}, eigene Seite in der Web-App: /s/${s.smapi_id}`
    : `SMAPI-ID: ${s.smapi_id} (bleibt dauerhaft gleich, Sonos speichert sie in Favoriten)`;
  const del = $('#delete');
  del.hidden = !s;
  del.classList.remove('confirm');
  del.textContent = deleteLabel();
  $('#probe-result').hidden = !(s && s.check_info && !isList);
  if (s && s.check_info && !isList) setProbe(s.check_ok, 'Letzter Test: ' + s.check_info);
  setHttps(s && !isList ? s.https_ok : null);
  stationImage.reset(s && s.has_logo ? s.logo_url : null);
  $('[data-ie="placeholder"]', dialog).textContent = ed.kind === 'category' ? 'Ohne Bild: Raster aus den ersten vier Logos'
    : isYt ? 'Ohne Bild: Raster aus den ersten vier Videos' : isLink ? 'Kein Cover' : 'Kein Logo';
  updateCount();
  dialog.showModal();
  (isLink && !s ? F('link_url') : F('title')).focus();
}

function deleteLabel() {
  return { playlist: 'Playlist löschen', category: 'Kategorie löschen', soundcloud: 'Track löschen', deezer: 'Playlist löschen', youtube: 'Playlist löschen' }[ed.kind] || 'Löschen';
}

/** Result of the last sync of a YouTube playlist, and the button for the next one. */
function ytState(s) {
  const p = $('#yt-state');
  p.hidden = !(s && s.check_info);
  $('#yt-sync-row').hidden = !s;
  if (!p.hidden) {
    const when = s.checked_at ? ', ' + new Date(s.checked_at.replace(' ', 'T')).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' }) : '';
    p.className = 'probe ' + (s.check_ok ? 'ok' : 'fail');
    p.textContent = (s.check_ok ? '✓ Abgeglichen: ' : '✗ Abgleich fehlgeschlagen: ') + s.check_info + when;
  }
}

export async function syncYoutube() {
  if (!ed.station) return;
  const btn = $('#yt-sync');
  btn.disabled = true;
  btn.textContent = 'Gleiche ab …';
  try {
    const { result, station } = await api('youtube.sync', { id: ed.station.id });
    ed.station = station;
    ytState(station);
    toast(result.ok ? 'Abgeglichen: ' + result.info : result.info, result.ok ? '' : 'error');
    refreshView();
  } catch (e) {
    toast(e.message, 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Jetzt abgleichen';
  }
}

export function closeEditor() {
  stationImage.destroy();
  dialog.close();
}

export async function runProbe() {
  const url = F('stream_url').value.trim();
  clearErrors(form);
  if (!/^https?:\/\//i.test(url)) {
    $('[data-err="stream_url"]').textContent = 'Bitte eine gültige http(s)-Adresse eingeben.';
    return;
  }
  const btn = $('#probe');
  btn.disabled = true;
  btn.textContent = 'Teste …';
  try {
    const { probe } = await api('probe', { url, id: ed.station && ed.station.stream_url === url ? ed.station.id : null });
    ed.probe = { url, ok: probe.ok, info: probe.info, https: probe.https };
    setProbe(probe.ok, probe.info);
    setHttps(probe.https, probe.https_url);
    if (probe.ok) {
      F('mime').value = probe.mime;
      F('is_playlist').value = probe.is_playlist ? '1' : '0';
      if (!F('title').value.trim() && probe.name) F('title').value = probe.name;
      if (!F('description').value.trim() && probe.description) F('description').value = probe.description.slice(0, 500);
      updateCount();
    }
  } catch (e) {
    setProbe(false, e.message);
  } finally {
    btn.disabled = false;
    btn.textContent = 'Stream testen';
  }
}

/** A changed address makes the last test and the playlist flag meaningless. */
export function streamUrlChanged() {
  F('is_playlist').value = '0';
  setHttps(null);
  const p = $('#probe-result');
  p.hidden = !F('stream_url').value.trim();
  p.className = 'probe';
  p.textContent = 'Neue Adresse: „Stream testen“ prüft sie und stellt das passende Format ein.';
}

export async function saveStation(e) {
  e.preventDefault();
  clearErrors(form);
  const btn = $('#save');
  btn.disabled = true;
  try {
    const isNew = !ed.station;
    const isLink = ed.kind === 'soundcloud' || ed.kind === 'deezer' || ed.kind === 'youtube';
    const url = (isLink ? F('link_url') : F('stream_url')).value.trim();
    // a new API key first: the playlist is synced right after saving
    if (ed.kind === 'youtube' && F('yt_key').value.trim()) {
      ({ youtube: state.youtube } = await api('youtube.key', { key: F('yt_key').value.trim() }));
      F('yt_key').value = '';
    }
    const data = {
      id: ed.station ? ed.station.id : null,
      kind: ed.kind,
      title: F('title').value,
      stream_url: url,
      mime: F('mime').value,
      description: F('description').value,
      website: F('website').value.trim(),
      country: F('country').value,
      city: F('city').value.trim(),
      social: F('social').checked,
      hashtags: F('hashtags').value,
      active: F('active').checked,
      is_playlist: F('is_playlist').value === '1',
      show_olli: F('show_olli').checked,
      show_cathy: F('show_cathy').checked,
      check: ed.probe && ed.probe.url === url ? { ok: ed.probe.ok, info: ed.probe.info, https: ed.probe.https } : null,
    };
    if (!$('.cat-field', form).hidden) data.category_id = Number(F('category_id').value) || null;
    let { station } = await api('station.save', data);
    // from now on the record exists: saving again (e.g. after a failed logo upload) updates it
    ed.station = station;
    const img = await stationImage.commit('logo.upload', 'logo.remove', station.id);
    if (img) station = img.station;
    closeEditor();
    if (station.kind === 'youtube' && station.check_ok === false) toast('Gespeichert, aber der Abgleich klappt nicht: ' + station.check_info, 'error');
    else toast(station.kind === 'youtube' && station.check_info ? 'Gespeichert, ' + station.check_info : 'Gespeichert');
    const own = '#' + station.kind + '/' + station.id;
    if ((station.kind === 'playlist' || station.kind === 'category') && isNew && location.hash !== own) location.hash = own;
    else refreshView();
  } catch (err) {
    // the address of a SoundCloud track, a Deezer or a YouTube playlist has its own field
    if (['soundcloud', 'deezer', 'youtube'].includes(ed.kind) && err.fields && err.fields.stream_url) err.fields.link_url = err.fields.stream_url;
    showErrors(form, err);
    toast(err.message, 'error');
  } finally {
    btn.disabled = false;
  }
}

export async function deleteStation() {
  const btn = $('#delete');
  if (!confirmButton(btn, deleteLabel())) return;
  try {
    await api('station.delete', { id: ed.station.id });
    closeEditor();
    toast({
      playlist: 'Playlist gelöscht, die Tracks bleiben in der Bibliothek',
      category: 'Kategorie gelöscht, ihre Einträge stehen wieder direkt in der Liste',
      soundcloud: 'SoundCloud-Track gelöscht',
      deezer: 'Deezer-Playlist gelöscht',
      youtube: 'YouTube-Playlist gelöscht',
    }[ed.kind] || 'Sender gelöscht');
    if (location.hash === '#' + ed.kind + '/' + ed.station.id) location.hash = '#stations';
    else refreshView();
  } catch (e) {
    toast(e.message, 'error');
  }
}
