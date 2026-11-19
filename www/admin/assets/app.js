// 1radiœ admin: station grid (preview + drag & drop), editor for stations, playlists
// and categories, category view, playlist view with track order, track library with
// uploads, system page.
// Talks to api.php. No HTML from data: all text is inserted with textContent.
//
// The entry: the parts are ES modules in app/, loaded without a build step.
// app/admin.php loads this file as assets/v<version>/app.js (www/.htaccess maps that to
// assets/), so the modules carry the version in their path too. Each part only declares
// at load; its listeners come from its init(), called here in the order the old single
// file ran them, then the buttons are wired up and the view opens.

import { $, $$, api, state, toast } from './app/core.js';
import { assignPicked, checkAll, initGridSort, loadState, openStationPicker, route, stPicker, updateStationPickerCount, init as initStations } from './app/stations.js';
import { F, closeEditor, deleteStation, dialog, form, openEditor, runProbe, saveStation, stationImage, streamUrlChanged, syncYoutube, updateCount } from './app/editor.js';
import { addPicked, closeTrack, deleteTrack, importInbox, initDropzone, initPlaylistSort, loadLibrary, openPicker, picker, saveTrack, trackDialog, trackForm, updatePickerCount, uploadFiles, uploadToPlaylist } from './app/tracks.js';
import { loadDevices, loadListen, loadLog, init as initSystem } from './app/system.js';
import { init as initTexts } from './app/texts.js';
import { init as initSocial } from './app/social.js';

initStations();
initSystem();
initTexts();
initSocial();

/* ---------- Wire up ---------- */

window.addEventListener('hashchange', route);
$('#add-station').addEventListener('click', () => openEditor(null, 'stream'));
$('#add-playlist').addEventListener('click', () => openEditor(null, 'playlist'));
$('#add-category').addEventListener('click', () => openEditor(null, 'category'));
$('#add-soundcloud').addEventListener('click', () => openEditor(null, 'soundcloud'));
$('#add-deezer').addEventListener('click', () => openEditor(null, 'deezer'));
$('#add-youtube').addEventListener('click', () => openEditor(null, 'youtube'));
$('#yt-sync').addEventListener('click', syncYoutube);
$('#cat-edit').addEventListener('click', () => openEditor(state.category));
$('#cat-add').addEventListener('click', () => openEditor(null, 'stream', state.category.id));
$('#cat-add-sc').addEventListener('click', () => openEditor(null, 'soundcloud', state.category.id));
$('#cat-add-dz').addEventListener('click', () => openEditor(null, 'deezer', state.category.id));
$('#cat-pick').addEventListener('click', openStationPicker);
$$('[data-close]', stPicker).forEach((b) => b.addEventListener('click', () => stPicker.close()));
$('#st-picker-form').addEventListener('submit', assignPicked);
$('#st-picker-list').addEventListener('change', updateStationPickerCount);
$('#add-first').addEventListener('click', () => openEditor(null, 'stream'));
$('#check-all').addEventListener('click', checkAll);
$('#import-lite').addEventListener('click', async () => {
  try {
    const { imported } = await api('import.lite', {});
    await loadState();
    toast(`${imported} Sender übernommen`);
  } catch (e) {
    toast(e.message, 'error');
  }
});

$$('[data-close]', dialog).forEach((b) => b.addEventListener('click', closeEditor));
dialog.addEventListener('cancel', () => stationImage.destroy());
form.addEventListener('submit', saveStation);
F('description').addEventListener('input', updateCount);
F('stream_url').addEventListener('input', streamUrlChanged);
$('#probe').addEventListener('click', runProbe);
$('#delete').addEventListener('click', deleteStation);

$('#pl-edit').addEventListener('click', () => openEditor(state.playlist));
$('#pl-pick').addEventListener('click', openPicker);
$('#pl-files').addEventListener('change', (e) => { uploadToPlaylist([...e.target.files]); e.target.value = ''; });
initDropzone($('[data-drop="playlist"]'), uploadToPlaylist);
$$('[data-close]', picker).forEach((b) => b.addEventListener('click', () => picker.close()));
$('#picker-form').addEventListener('submit', addPicked);
$('#picker-list').addEventListener('change', updatePickerCount);
$('#picker-filter').addEventListener('input', (e) => {
  const q = e.target.value.trim().toLowerCase();
  $$('#picker-list > li').forEach((li) => { li.hidden = Boolean(q) && !(li.dataset.search || '').includes(q); });
});

const libraryUpload = async (files) => {
  const created = await uploadFiles(files, $('#lib-uploads'));
  if (created.length) toast(`${created.length} ${created.length === 1 ? 'Track' : 'Tracks'} importiert`);
  loadLibrary();
};
$('#lib-files').addEventListener('change', (e) => { libraryUpload([...e.target.files]); e.target.value = ''; });
initDropzone($('[data-drop="library"]'), libraryUpload);
$('#inbox-import').addEventListener('click', importInbox);

$$('[data-close]', trackDialog).forEach((b) => b.addEventListener('click', closeTrack));
trackDialog.addEventListener('cancel', closeTrack);
trackForm.addEventListener('submit', saveTrack);
$('#track-delete').addEventListener('click', deleteTrack);

$('#log-enabled').addEventListener('change', async (e) => {
  try {
    await api('log.enabled', { enabled: e.target.checked });
    toast(e.target.checked ? 'Protokoll eingeschaltet' : 'Protokoll ausgeschaltet');
  } catch (err) {
    toast(err.message, 'error');
  }
});
$('#log-refresh').addEventListener('click', loadLog);
$('#listen-refresh').addEventListener('click', loadListen);
$('#log-clear').addEventListener('click', async () => {
  try {
    await api('log.clear', {});
    loadLog();
  } catch (e) {
    toast(e.message, 'error');
  }
});

// keep the browser from opening files dropped outside a drop zone
['dragover', 'drop'].forEach((t) => window.addEventListener(t, (e) => { if (!e.target.closest('.dropzone')) e.preventDefault(); }));

initGridSort($('#grid'));
initGridSort($('#cat-grid'));
initPlaylistSort();
route();
if (location.hash !== '#system') loadDevices();   // the system page loads them itself
