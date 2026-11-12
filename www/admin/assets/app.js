// 1radiœ admin: station grid (preview + drag & drop), editor for stations, playlists
// and categories, category view, playlist view with track order, track library with
// uploads, system page.
// Talks to api.php. No HTML from data: all text is inserted with textContent.
(() => {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
  const csrf = $('meta[name="csrf-token"]').content;
  const MAX_IMAGE = 8 * 1024 * 1024;
  const MAX_TRACK = 300 * 1024 * 1024;
  const CHUNK = 2 * 1024 * 1024;

  const state = { stations: [], mimes: {}, playlist: null, category: null, tracks: [] };

  /* ---------- Helpers ---------- */

  async function api(action, data, query = {}) {
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
  function toast(message, kind = '') {
    const box = $('#toast');
    box.textContent = message;
    box.className = 'toast ' + kind;
    box.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { box.hidden = true; }, kind === 'error' ? 7000 : 3400);
  }

  function el(tag, cls, text) {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function initials(title) {
    return title.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => [...w][0]).join('').toUpperCase();
  }

  function art(url, title, cls = 'art') {
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

  function clock(sec) {
    sec = Math.round(sec || 0);
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = String(sec % 60).padStart(2, '0');
    return h ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
  }

  function total(sec) {
    const min = Math.round(sec / 60);
    return min >= 60 ? `${Math.floor(min / 60)} Std. ${min % 60} Min.` : `${min} Min.`;
  }

  function trackCount(n) {
    return n === 1 ? '1 Track' : `${n} Tracks`;
  }

  /** Two-click delete: first click asks, second click within 4 s confirms. */
  function confirmButton(btn, label) {
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

  function showErrors(form, err) {
    for (const [field, msg] of Object.entries(err.fields || {})) {
      const slot = $(`[data-err="${field}"]`, form);
      if (slot) slot.textContent = msg;
    }
  }

  function clearErrors(form) {
    $$('[data-err]', form).forEach((e) => { e.textContent = ''; });
  }

  /* ---------- Image editor (logos and covers) ---------- */

  class ImageEditor {
    constructor(root) {
      this.root = root;
      this.cropper = null;
      this.bg = '';
      const q = (name) => $(`[data-ie="${name}"]`, root);
      this.q = q;
      q('file').addEventListener('change', (e) => { this.readFile(e.target.files[0]); e.target.value = ''; });
      q('fromurl').addEventListener('click', () => { q('urlrow').hidden = false; q('url').focus(); });
      q('urlload').addEventListener('click', () => this.loadUrl());
      q('url').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); this.loadUrl(); } });
      q('remove').addEventListener('click', () => { this.show(null); this.pending = false; this.removeIt = Boolean(this.existing); });
      $$('[data-crop]', root).forEach((b) => b.addEventListener('click', () => this.crop(b.dataset.crop)));
      q('bg').addEventListener('change', (e) => {
        const custom = e.target.value === 'custom';
        q('bgcolor').hidden = !custom;
        this.bg = custom ? q('bgcolor').value : e.target.value;
        this.applyBg();
      });
      q('bgcolor').addEventListener('input', (e) => { this.bg = e.target.value; this.applyBg(); });
    }

    reset(url) {
      this.existing = url || null;
      this.pending = false;
      this.removeIt = false;
      this.bg = '';
      this.q('bg').value = '';
      this.q('bgcolor').hidden = true;
      this.q('urlrow').hidden = true;
      this.q('url').value = '';
      this.show(url);
    }

    show(url) {
      this.destroy();
      this.q('preview').hidden = !url;
      if (url) this.q('preview').src = url;
      this.q('placeholder').hidden = Boolean(url);
      this.q('remove').hidden = !url;
    }

    destroy() {
      if (this.cropper) this.cropper.destroy();
      this.cropper = null;
      this.q('cropwrap').hidden = true;
      this.q('tools').hidden = true;
    }

    start(src) {
      this.destroy();
      this.q('preview').hidden = true;
      this.q('placeholder').hidden = true;
      this.q('cropwrap').hidden = false;
      this.q('tools').hidden = false;
      this.q('remove').hidden = false;
      const img = this.q('cropimg');
      img.src = src;
      this.cropper = new window.Cropper(img, {
        aspectRatio: 1, viewMode: 0, dragMode: 'move', autoCropArea: 1, background: true, restore: false, toggleDragModeOnDblclick: false,
      });
      this.pending = true;
      this.removeIt = false;
      this.applyBg();
    }

    applyBg() {
      const wrap = this.q('cropwrap');
      wrap.classList.toggle('has-bg', Boolean(this.bg));
      wrap.style.backgroundColor = this.bg || '';
    }

    crop(kind) {
      const c = this.cropper;
      if (!c) return;
      if (kind === 'zoom-in') c.zoom(0.1);
      if (kind === 'zoom-out') c.zoom(-0.1);
      if (kind === 'rotate') c.rotate(90);
      if (kind === 'fill') c.reset();
      if (kind === 'fit') {
        // whole image inside the square stage; empty space gets the background color
        c.reset();
        const box = c.getContainerData();
        c.setCropBoxData({ left: 0, top: 0, width: box.width, height: box.height });
      }
    }

    readFile(file) {
      if (!file) return;
      if (file.size > MAX_IMAGE) {
        toast('Bild ist größer als 8 MB.', 'error');
        return;
      }
      const reader = new FileReader();
      reader.onload = () => this.start(reader.result);
      reader.readAsDataURL(file);
    }

    async loadUrl() {
      const url = this.q('url').value.trim();
      if (!/^https?:\/\//i.test(url)) {
        toast('Bitte eine Bildadresse mit http(s) eingeben.', 'error');
        return;
      }
      const btn = this.q('urlload');
      btn.disabled = true;
      try {
        const { dataUrl } = await api('logo.fetch', { url });
        this.start(dataUrl);
        this.q('urlrow').hidden = true;
      } catch (e) {
        toast(e.message, 'error');
      } finally {
        btn.disabled = false;
      }
    }

    blob() {
      const canvas = this.cropper.getCroppedCanvas({
        width: 600, height: 600, fillColor: this.bg || 'transparent', imageSmoothingEnabled: true, imageSmoothingQuality: 'high',
      });
      return new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
    }

    /** Uploads or removes the image after the main record was saved. Returns the API response or null. */
    async commit(uploadAction, removeAction, id) {
      if (this.pending && this.cropper) {
        const fd = new FormData();
        fd.append('id', id);
        fd.append('logo', await this.blob(), 'image.png');
        return api(uploadAction, fd);
      }
      if (this.removeIt) return api(removeAction, { id });
      return null;
    }
  }

  /* ---------- Router ---------- */

  function route() {
    const hash = location.hash || '#stations';
    let view = 'stations';
    if (hash.startsWith('#playlist/')) view = 'playlist';
    else if (hash.startsWith('#category/')) view = 'category';
    else if (hash === '#tracks') view = 'tracks';
    else if (hash === '#system') view = 'system';
    $$('.view').forEach((v) => { v.hidden = v.id !== 'view-' + view; });
    const tab = view === 'playlist' || view === 'category' ? 'stations' : view;
    $$('.tab').forEach((t) => t.setAttribute('aria-current', t.dataset.view === tab ? 'page' : 'false'));
    if (view === 'playlist') loadPlaylist(Number(hash.split('/')[1]));
    if (view === 'category') loadCategory(Number(hash.split('/')[1]));
    if (view === 'tracks') loadLibrary();
    if (view === 'system') loadSystem();
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

  function tile(s) {
    const size = s.kind === 'playlist' ? s.tracks : s.kind === 'category' ? membersOf(s.id).length : 1;
    const empty = s.kind !== 'stream' && size === 0;
    const t = el('div', 'tile' + (s.active ? '' : ' inactive') + (empty ? ' is-empty' : '') + (s.kind !== 'stream' ? ' is-' + s.kind : ''));
    t.dataset.id = s.id;
    t.tabIndex = 0;
    t.setAttribute('role', 'button');
    const kindName = { playlist: ', Playlist', category: ', Kategorie' }[s.kind] || '';
    t.setAttribute('aria-label', s.title + kindName + (s.active ? '' : ', inaktiv') + (empty ? ', leer' : ''));
    const a = art(s.logo_url, s.title);
    if (s.kind !== 'stream') {
      const icon = s.kind === 'playlist' ? '♫ ' : '▦ ';
      const kind = el('span', 'kind', icon + (empty ? 'leer' : s.kind === 'playlist' ? trackCount(size) : entryCount(size)));
      if (empty) kind.title = 'Leere Playlists und Kategorien zeigt Sonos nicht an';
      a.append(kind);
    } else {
      const dot = el('span', 'dot ' + (s.check_ok === null ? 'unknown' : s.check_ok ? 'ok' : 'fail'));
      dot.title = s.check_info ? 'Letzter Test: ' + s.check_info : 'Noch nicht getestet';
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
  function describe(list) {
    const n = (k) => list.filter((s) => s.kind === k).length;
    const parts = [`${n('stream')} Sender`];
    if (n('playlist')) parts.push(n('playlist') === 1 ? '1 Playlist' : `${n('playlist')} Playlists`);
    if (n('category')) parts.push(n('category') === 1 ? '1 Kategorie' : `${n('category')} Kategorien`);
    return parts.join(', ');
  }

  function renderGrid() {
    $('#grid').replaceChildren(...state.stations.filter((s) => !s.category_id).map(tile));
    const active = state.stations.filter((s) => s.active).length;
    $('#summary').textContent = state.stations.length ? `${describe(state.stations)}, davon ${active} aktiv` : '';
    $('#empty').hidden = state.stations.length > 0;
    $('#check-all').hidden = !state.stations.some((s) => s.kind === 'stream');
    if (state.category && location.hash === '#category/' + state.category.id) renderCategory();
  }

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

  async function loadState() {
    try {
      const data = await api('state');
      state.stations = data.stations;
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
  function refreshView() {
    const hash = location.hash;
    if (hash.startsWith('#playlist/')) return loadPlaylist(Number(hash.split('/')[1]));
    if (hash.startsWith('#category/')) return loadCategory(Number(hash.split('/')[1]));
    return loadState();
  }

  /** Drag & drop in the top level grid and in the grid of a category. */
  function initGridSort(grid) {
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

  const stPicker = $('#st-picker');
  async function openStationPicker() {
    await loadState();
    const c = state.category;
    const names = Object.fromEntries(state.stations.filter((s) => s.kind === 'category').map((x) => [x.id, x.title]));
    const list = $('#st-picker-list');
    list.replaceChildren();
    const candidates = state.stations.filter((s) => s.kind !== 'category' && s.category_id !== c.id);
    if (!candidates.length) list.append(el('li', 'muted', 'Alle Sender und Playlists sind schon in dieser Kategorie.'));
    for (const s of candidates) {
      const label = el('label', 'pick');
      const box = el('input');
      box.type = 'checkbox';
      box.value = s.id;
      const text = el('span', 'track-text');
      const where = s.category_id ? 'jetzt in „' + (names[s.category_id] || '?') + '“' : 'jetzt direkt in der Liste';
      text.append(el('span', 'track-title', s.title), el('span', 'track-sub', (s.kind === 'playlist' ? 'Playlist, ' : '') + where));
      label.append(box, art(s.logo_url, s.title, 'thumb'), text);
      const li = el('li');
      li.append(label);
      list.append(li);
    }
    updateStationPickerCount();
    stPicker.showModal();
  }

  function updateStationPickerCount() {
    const n = $$('#st-picker-list input:checked').length;
    $('#st-picker-count').textContent = n ? `${n} ausgewählt` : '';
    $('#st-picker-add').disabled = n === 0;
  }

  async function assignPicked(e) {
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

  async function checkAll() {
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

  /* ---------- Station / playlist editor ---------- */

  const dialog = $('#editor');
  const form = $('#station-form');
  // form.title would return the form's own title attribute, so fields are read via elements
  const F = (name) => form.elements.namedItem(name);
  const stationImage = new ImageEditor($('.image-editor', dialog));
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

  function updateCount() {
    $('#desc-count').textContent = `${F('description').value.length}/500`;
  }

  function openEditor(s, kind = 'stream', categoryId = null) {
    ed.station = s;
    ed.kind = s ? s.kind : kind;
    ed.probe = null;
    const isList = ed.kind !== 'stream';
    form.reset();
    clearErrors(form);
    $$('.stream-only', form).forEach((n) => { n.hidden = isList; });
    $('#editor-title').textContent = s ? s.title : ({ playlist: 'Neue Playlist', category: 'Neue Kategorie' }[ed.kind] || 'Neuer Sender');
    // the category field only appears when there are categories; unknown state keeps the stored one
    const hasCategories = state.stations.some((x) => x.kind === 'category');
    $('.cat-field', form).hidden = ed.kind === 'category' || !hasCategories;
    F('category_id').value = String((s ? s.category_id : categoryId) || '');
    F('title').value = s ? s.title : '';
    F('stream_url').value = s ? s.stream_url : '';
    F('mime').value = s && !isList ? s.mime : '';
    F('description').value = s ? s.description : '';
    F('website').value = s ? (s.website || '') : '';
    F('active').checked = s ? s.active : true;
    F('is_playlist').value = s && s.is_playlist ? '1' : '0';
    $('#smapi-id').textContent = s ? `SMAPI-ID: ${s.smapi_id} (bleibt dauerhaft gleich, Sonos speichert sie in Favoriten)` : '';
    const del = $('#delete');
    del.hidden = !s;
    del.classList.remove('confirm');
    del.textContent = deleteLabel();
    $('#probe-result').hidden = !(s && s.check_info && !isList);
    if (s && s.check_info && !isList) setProbe(s.check_ok, 'Letzter Test: ' + s.check_info);
    setHttps(s && !isList ? s.https_ok : null);
    stationImage.reset(s && s.has_logo ? s.logo_url : null);
    $('[data-ie="placeholder"]', dialog).textContent = ed.kind === 'category' ? 'Ohne Bild: Raster aus den ersten vier Logos' : 'Kein Logo';
    updateCount();
    dialog.showModal();
    F('title').focus();
  }

  function deleteLabel() {
    return { playlist: 'Playlist löschen', category: 'Kategorie löschen' }[ed.kind] || 'Löschen';
  }

  function closeEditor() {
    stationImage.destroy();
    dialog.close();
  }

  async function runProbe() {
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
  function streamUrlChanged() {
    F('is_playlist').value = '0';
    setHttps(null);
    const p = $('#probe-result');
    p.hidden = !F('stream_url').value.trim();
    p.className = 'probe';
    p.textContent = 'Neue Adresse: „Stream testen“ prüft sie und stellt das passende Format ein.';
  }

  async function saveStation(e) {
    e.preventDefault();
    clearErrors(form);
    const btn = $('#save');
    btn.disabled = true;
    try {
      const isNew = !ed.station;
      const url = F('stream_url').value.trim();
      const data = {
        id: ed.station ? ed.station.id : null,
        kind: ed.kind,
        title: F('title').value,
        stream_url: url,
        mime: F('mime').value,
        description: F('description').value,
        website: F('website').value.trim(),
        active: F('active').checked,
        is_playlist: F('is_playlist').value === '1',
        check: ed.probe && ed.probe.url === url ? { ok: ed.probe.ok, info: ed.probe.info, https: ed.probe.https } : null,
      };
      if (!$('.cat-field', form).hidden) data.category_id = Number(F('category_id').value) || null;
      let { station } = await api('station.save', data);
      // from now on the record exists: saving again (e.g. after a failed logo upload) updates it
      ed.station = station;
      const img = await stationImage.commit('logo.upload', 'logo.remove', station.id);
      if (img) station = img.station;
      closeEditor();
      toast('Gespeichert');
      const own = '#' + station.kind + '/' + station.id;
      if (station.kind !== 'stream' && isNew && location.hash !== own) location.hash = own;
      else refreshView();
    } catch (err) {
      showErrors(form, err);
      toast(err.message, 'error');
    } finally {
      btn.disabled = false;
    }
  }

  async function deleteStation() {
    const btn = $('#delete');
    if (!confirmButton(btn, deleteLabel())) return;
    try {
      await api('station.delete', { id: ed.station.id });
      closeEditor();
      toast({
        playlist: 'Playlist gelöscht, die Tracks bleiben in der Bibliothek',
        category: 'Kategorie gelöscht, ihre Einträge stehen wieder direkt in der Liste',
      }[ed.kind] || 'Sender gelöscht');
      if (location.hash === '#' + ed.kind + '/' + ed.station.id) location.hash = '#stations';
      else refreshView();
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  /* ---------- Uploads ---------- */

  /** Uploads MP3 files one after another in 2 MB chunks. Returns the created tracks. */
  async function uploadFiles(files, listEl, playlistId = null) {
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

  function initDropzone(zone, onFiles) {
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

  async function loadPlaylist(id) {
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

  function initPlaylistSort() {
    window.Sortable.create($('#pl-tracks'), {
      animation: 160, handle: '.handle', ghostClass: 'drag-ghost', chosenClass: 'drag-chosen',
      onEnd: (evt) => {
        if (evt.oldIndex === evt.newIndex) return;
        savePlaylistTracks($$('#pl-tracks > li').map((li) => Number(li.dataset.id)), 'Reihenfolge gespeichert');
      },
    });
  }

  async function uploadToPlaylist(files) {
    const id = state.playlist.id;
    const created = await uploadFiles(files, $('#pl-uploads'), id);
    if (created.length) {
      toast(`${created.length} ${created.length === 1 ? 'Track' : 'Tracks'} hinzugefügt`);
      if (state.playlist && state.playlist.id === id) loadPlaylist(id);
    }
  }

  /* Picker: add tracks from the library */

  const picker = $('#picker');
  async function openPicker() {
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

  function updatePickerCount() {
    const n = $$('#picker-list input:checked').length;
    $('#picker-count').textContent = n ? `${n} ausgewählt` : '';
    $('#picker-add').disabled = n === 0;
  }

  async function addPicked(e) {
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

  async function loadLibrary() {
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

  async function importInbox() {
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

  const trackDialog = $('#track-editor');
  const trackForm = $('#track-form');
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

  function closeTrack() {
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

  async function saveTrack(e) {
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

  async function deleteTrack() {
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

  /* ---------- System ---------- */

  function fillKv(target, entries, copy) {
    const dl = $(target);
    dl.replaceChildren();
    for (const [k, v] of Object.entries(entries)) {
      const dd = el('dd');
      dd.append(el('span', 'value', String(v)));
      if (copy) {
        const b = el('button', 'btn ghost small', 'Kopieren');
        b.type = 'button';
        b.addEventListener('click', async () => {
          try { await navigator.clipboard.writeText(String(v)); toast('Kopiert'); } catch { toast('Kopieren nicht möglich', 'error'); }
        });
        dd.append(b);
      }
      dl.append(el('dt', '', k), dd);
    }
  }

  async function loadSystem() {
    try {
      const data = await api('system');
      fillKv('#portal', data.portal, true);
      fillKv('#public', data.public, true);
      fillKv('#status', data.status, false);
      $('#log-enabled').checked = data.log_enabled;
      loadLog();
      loadListen();
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  /* Listening status, for testing: refreshes itself while the system page is visible. */
  let listenTimer;
  function ago(sec) {
    if (sec < 60) return `vor ${sec} s`;
    if (sec < 3600) return `vor ${Math.floor(sec / 60)} min`;
    return `vor ${Math.floor(sec / 3600)} h`;
  }

  async function loadListen() {
    clearTimeout(listenTimer);
    if (location.hash !== '#system') return;
    let rows;
    try {
      ({ rows } = await api('listen'));
    } catch {
      rows = null;
    }
    const body = $('#listen');
    if (rows) {
      body.replaceChildren();
      if (!rows.length) {
        const tr = el('tr');
        const td = el('td', 'muted', 'Noch keine Meldung.');
        td.colSpan = 3;
        tr.append(td);
        body.append(tr);
      }
      for (const r of rows) {
        const tr = el('tr', r.state === 'läuft' ? 'is-live' : '');
        tr.append(el('td', '', r.title), el('td', 'listen-state', r.state === 'läuft' ? '● läuft' : r.state), el('td', '', `${r.at} (${ago(r.age)})`));
        body.append(tr);
      }
    }
    listenTimer = setTimeout(loadListen, document.hidden ? 30000 : 10000);
  }

  async function loadLog() {
    let log;
    try {
      ({ log } = await api('log'));
    } catch (e) {
      toast(e.message, 'error');
      return;
    }
    const body = $('#log');
    body.replaceChildren();
    if (!log.length) {
      const tr = el('tr');
      const td = el('td', 'muted', 'Keine Einträge.');
      td.colSpan = 6;
      tr.append(td);
      body.append(tr);
    }
    for (const r of log) {
      const tr = el('tr', Number(r.status) >= 400 ? 'is-error' : '');
      [r.at, r.method, r.item, r.status, r.note, `${r.ip} ${r.agent}`].forEach((v) => tr.append(el('td', '', String(v))));
      body.append(tr);
    }
  }

  /* ---------- Wire up ---------- */

  window.addEventListener('hashchange', route);
  $('#add-station').addEventListener('click', () => openEditor(null, 'stream'));
  $('#add-playlist').addEventListener('click', () => openEditor(null, 'playlist'));
  $('#add-category').addEventListener('click', () => openEditor(null, 'category'));
  $('#cat-edit').addEventListener('click', () => openEditor(state.category));
  $('#cat-add').addEventListener('click', () => openEditor(null, 'stream', state.category.id));
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
})();
