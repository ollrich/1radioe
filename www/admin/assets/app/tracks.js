// 1radiœ admin: MP3 uploads in parts, playlist view, library and track editor.

import { $, $$, CHUNK, MAX_TRACK, api, art, clearErrors, clock, confirmButton, el, showErrors, state, toast, total, trackCount } from './core.js';
import { ImageEditor } from './image.js';

/* ---------- Uploads ---------- */

/** Uploads MP3 files one after another in 2 MB chunks. Returns the created tracks. */
export async function uploadFiles(files, listEl, playlistId = null) {
  const created = [];
  for (const file of files) {
    const row = el('li', 'upload');
    const name = el('span', 'up-name', file.name);
    const bar = el('progress');
    bar.max = 100;
    bar.value = 0;
    const status = el('span', 'up-status', 'wartet');
    row.append(name, bar, status);
    listEl.append(row);
    if (!/\.mp3$/i.test(file.name)) {
      row.classList.add('fail');
      status.textContent = 'keine MP3-Datei';
      continue;
    }
    if (file.size > MAX_TRACK) {
      row.classList.add('fail');
      status.textContent = 'größer als 300 MB';
      continue;
    }
    try {
      const { upload, chunk } = await api('upload.start', { size: file.size });
      const step = chunk || CHUNK;
      for (let offset = 0; offset < file.size; offset += step) {
        const fd = new FormData();
        fd.append('upload', upload);
        fd.append('offset', offset);
        fd.append('chunk', file.slice(offset, offset + step), 'chunk');
        await api('upload.chunk', fd);
        bar.value = Math.round(Math.min(file.size, offset + step) / file.size * 100);
        status.textContent = bar.value + ' %';
      }
      status.textContent = 'wird importiert …';
      const { track, duplicate } = await api('upload.finish', { upload, name: file.name, playlist: playlistId });
      created.push(track);
      row.classList.add('ok');
      status.textContent = (duplicate ? '✓ schon in der Bibliothek: ' : '✓ ') + track.title + (track.artist ? ' · ' + track.artist : '');
    } catch (e) {
      row.classList.add('fail');
      status.textContent = e.message;
    }
  }
  setTimeout(() => $$('.upload.ok', listEl).forEach((r) => r.remove()), 6000);
  return created;
}

export function initDropzone(zone, onFiles) {
  zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('over'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('over'));
  zone.addEventListener('drop', (e) => {
    e.preventDefault();
    zone.classList.remove('over');
    if (e.dataTransfer.files.length) onFiles([...e.dataTransfer.files]);
  });
}

/* ---------- Track rows (playlist and library) ---------- */

function trackRow(t, { handle = false, index = null, onRemove = null, showPlaylists = false } = {}) {
  const li = el('li', 'track-row');
  li.dataset.id = t.id;
  if (handle) {
    const h = el('span', 'handle', '⠿');
    h.title = 'Ziehen zum Verschieben';
    li.append(h);
  }
  if (index !== null) li.append(el('span', 'pos', String(index)));
  li.append(art(t.cover_url, t.title, 'thumb'));
  const text = el('div', 'track-text');
  text.append(el('span', 'track-title', t.title));
  const sub = [t.artist, t.album].filter(Boolean).join(' · ');
  text.append(el('span', 'track-sub', t.missing ? 'MP3-Datei fehlt auf dem Server' : (sub || 'Kein Interpret')));
  if (t.missing) li.classList.add('missing');
  li.append(text);
  if (showPlaylists) li.append(el('span', 'track-meta', t.playlists ? (t.playlists === 1 ? 'in 1 Playlist' : `in ${t.playlists} Playlists`) : 'in keiner Playlist'));
  li.append(el('span', 'track-time', clock(t.duration)));
  const edit = el('button', 'btn small ghost edit-btn');
  edit.type = 'button';
  edit.setAttribute('aria-label', 'Bearbeiten');
  edit.append(el('span', 'lbl', 'Bearbeiten'));
  edit.addEventListener('click', () => openTrack(t));
  li.append(edit);
  if (onRemove) {
    const rm = el('button', 'icon-btn', '✕');
    rm.type = 'button';
    rm.title = 'Aus der Playlist entfernen (bleibt in der Bibliothek)';
    rm.setAttribute('aria-label', rm.title);
    rm.addEventListener('click', () => onRemove(t));
    li.append(rm);
  }
  return li;
}

/* ---------- Playlist view ---------- */

export async function loadPlaylist(id) {
  try {
    const data = await api('playlist', undefined, { id });
    state.playlist = data.playlist;
    state.playlistTracks = data.tracks;
    renderPlaylist();
  } catch (e) {
    toast(e.message, 'error');
    location.hash = '#stations';
  }
}

function renderPlaylist() {
  const p = state.playlist;
  const tracks = state.playlistTracks;
  const back = $('#pl-back');
  back.href = p.category_id ? '#category/' + p.category_id : '#stations';
  back.textContent = p.category_id ? '‹ Zurück zur Kategorie' : '‹ Alle Sender';
  $('#pl-cover').replaceChildren(art(p.logo_url, p.title, 'art'));
  $('#pl-title').textContent = p.title;
  $('#pl-desc').textContent = p.description;
  $('#pl-inactive').hidden = p.active;
  const sum = tracks.reduce((a, t) => a + t.duration, 0);
  $('#pl-meta').textContent = tracks.length ? `${trackCount(tracks.length)}, ${total(sum)}` : 'Leer';
  $('#pl-tracks').replaceChildren(...tracks.map((t, i) => trackRow(t, { handle: true, index: i + 1, onRemove: removeFromPlaylist })));
  $('#pl-empty').hidden = tracks.length > 0;
}

async function savePlaylistTracks(ids, message) {
  try {
    const data = await api('playlist.tracks', { id: state.playlist.id, tracks: ids });
    state.playlistTracks = data.tracks;
    renderPlaylist();
    if (message) toast(message);
  } catch (e) {
    toast(e.message, 'error');
    loadPlaylist(state.playlist.id);
  }
}

function removeFromPlaylist(t) {
  savePlaylistTracks(state.playlistTracks.filter((x) => x.id !== t.id).map((x) => x.id), `„${t.title}“ entfernt`);
}

export function initPlaylistSort() {
  window.Sortable.create($('#pl-tracks'), {
    animation: 160, handle: '.handle', ghostClass: 'drag-ghost', chosenClass: 'drag-chosen',
    onEnd: (evt) => {
      if (evt.oldIndex === evt.newIndex) return;
      savePlaylistTracks($$('#pl-tracks > li').map((li) => Number(li.dataset.id)), 'Reihenfolge gespeichert');
    },
  });
}

export async function uploadToPlaylist(files) {
  const id = state.playlist.id;
  const created = await uploadFiles(files, $('#pl-uploads'), id);
  if (created.length) {
    toast(`${created.length} ${created.length === 1 ? 'Track' : 'Tracks'} hinzugefügt`);
    if (state.playlist && state.playlist.id === id) loadPlaylist(id);
  }
}

/* Picker: add tracks from the library */

export const picker = $('#picker');
export async function openPicker() {
  try {
    const { tracks } = await api('tracks');
    const inList = new Set(state.playlistTracks.map((t) => t.id));
    const list = $('#picker-list');
    list.replaceChildren();
    const available = tracks.filter((t) => !inList.has(t.id));
    if (!available.length) list.append(el('li', 'muted', 'Alle Tracks der Bibliothek sind schon in dieser Playlist.'));
    for (const t of available) {
      const li = el('li');
      const label = el('label', 'pick');
      const box = el('input');
      box.type = 'checkbox';
      box.value = t.id;
      label.append(box, art(t.cover_url, t.title, 'thumb'));
      const text = el('span', 'track-text');
      text.append(el('span', 'track-title', t.title), el('span', 'track-sub', [t.artist, t.album].filter(Boolean).join(' · ')));
      label.append(text, el('span', 'track-time', clock(t.duration)));
      li.dataset.search = `${t.title} ${t.artist} ${t.album}`.toLowerCase();
      li.append(label);
      list.append(li);
    }
    $('#picker-filter').value = '';
    updatePickerCount();
    picker.showModal();
    $('#picker-filter').focus();
  } catch (e) {
    toast(e.message, 'error');
  }
}

export function updatePickerCount() {
  const n = $$('#picker-list input:checked').length;
  $('#picker-count').textContent = n ? `${n} ausgewählt` : '';
  $('#picker-add').disabled = n === 0;
}

export async function addPicked(e) {
  e.preventDefault();
  const ids = $$('#picker-list input:checked').map((b) => Number(b.value));
  if (!ids.length) return;
  try {
    const data = await api('playlist.add', { id: state.playlist.id, tracks: ids });
    state.playlistTracks = data.tracks;
    renderPlaylist();
    picker.close();
    toast(`${ids.length} ${ids.length === 1 ? 'Track' : 'Tracks'} hinzugefügt`);
  } catch (err) {
    toast(err.message, 'error');
  }
}

/* ---------- Library ---------- */

export async function loadLibrary() {
  try {
    const { tracks, inbox } = await api('tracks');
    state.tracks = tracks;
    const sum = tracks.reduce((a, t) => a + t.duration, 0);
    $('#lib-summary').textContent = tracks.length ? `${trackCount(tracks.length)}, ${total(sum)}` : '';
    $('#lib-tracks').replaceChildren(...tracks.map((t) => trackRow(t, { showPlaylists: true })));
    $('#lib-empty').hidden = tracks.length > 0;
    const btn = $('#inbox-import');
    btn.hidden = inbox === 0;
    btn.textContent = `FTP-Eingang importieren (${inbox})`;
  } catch (e) {
    toast(e.message, 'error');
  }
}

export async function importInbox() {
  const btn = $('#inbox-import');
  btn.disabled = true;
  btn.textContent = 'Importiere …';
  try {
    const { imported, duplicates, errors } = await api('inbox.import', {});
    const dupes = duplicates ? `, ${duplicates} schon in der Bibliothek` : '';
    toast(`${imported} importiert${dupes}` + (errors.length ? `, ${errors.length} übersprungen: ${errors[0]}` : ''), errors.length ? 'error' : '');
  } catch (e) {
    toast(e.message, 'error');
  } finally {
    btn.disabled = false;
    loadLibrary();
  }
}

/* ---------- Track editor ---------- */

export const trackDialog = $('#track-editor');
export const trackForm = $('#track-form');
const T = (name) => trackForm.elements.namedItem(name);
const trackImage = new ImageEditor($('.image-editor', trackDialog));
let editingTrack = null;

function openTrack(t) {
  editingTrack = t;
  trackForm.reset();
  clearErrors(trackForm);
  $('#track-editor-title').textContent = t.title;
  T('title').value = t.title;
  T('artist').value = t.artist;
  T('album').value = t.album;
  const audio = $('#track-audio');
  audio.src = t.audio_url;
  const mb = (t.size / 1048576).toFixed(1).replace('.', ',');
  $('#track-info').textContent = `${clock(t.duration)} · ${t.bitrate} kbit/s · ${mb} MB · Datei: ${t.orig_name}`;
  const del = $('#track-delete');
  del.classList.remove('confirm');
  del.textContent = 'Aus Bibliothek löschen';
  trackImage.reset(t.cover_url);
  trackDialog.showModal();
}

export function closeTrack() {
  trackImage.destroy();
  const audio = $('#track-audio');
  audio.pause();
  audio.removeAttribute('src');
  audio.load();
  trackDialog.close();
}

function refreshAfterTrackChange() {
  if (location.hash.startsWith('#playlist/') && state.playlist) loadPlaylist(state.playlist.id);
  else if (location.hash === '#tracks') loadLibrary();
}

export async function saveTrack(e) {
  e.preventDefault();
  clearErrors(trackForm);
  const btn = $('#track-save');
  btn.disabled = true;
  try {
    await api('track.save', { id: editingTrack.id, title: T('title').value, artist: T('artist').value, album: T('album').value });
    await trackImage.commit('track.cover', 'track.cover.remove', editingTrack.id);
    closeTrack();
    toast('Track gespeichert');
    refreshAfterTrackChange();
  } catch (err) {
    showErrors(trackForm, err);
    toast(err.message, 'error');
  } finally {
    btn.disabled = false;
  }
}

export async function deleteTrack() {
  const btn = $('#track-delete');
  if (!confirmButton(btn, 'Aus Bibliothek löschen')) return;
  try {
    await api('track.delete', { id: editingTrack.id });
    closeTrack();
    toast('Track gelöscht, auch aus allen Playlists');
    refreshAfterTrackChange();
  } catch (e) {
    toast(e.message, 'error');
  }
}
