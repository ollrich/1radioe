// 1radiœ admin: station grid (preview + drag & drop), editor with stream test and
// logo editor, system page. Talks to api.php. No inline HTML from data: all text is
// inserted with textContent.
(() => {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
  const csrf = $('meta[name="csrf-token"]').content;
  const MAX_BYTES = 8 * 1024 * 1024;

  const state = { stations: [], mimes: {} };
  const ed = { station: null, cropper: null, pendingImage: false, removeLogo: false, bg: '' };

  /* ---------- API ---------- */

  async function api(action, data) {
    const init = { method: data === undefined ? 'GET' : 'POST', headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' };
    if (data instanceof FormData) {
      init.body = data;
    } else if (data !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(data);
    }
    const res = await fetch('api.php?a=' + encodeURIComponent(action), init);
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
    const el = $('#toast');
    el.textContent = message;
    el.className = 'toast ' + kind;
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.hidden = true; }, 3200);
  }

  function el(tag, cls, text) {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  /* ---------- Views ---------- */

  function showView() {
    const view = location.hash === '#system' ? 'system' : 'stations';
    $$('.view').forEach((v) => { v.hidden = v.id !== 'view-' + view; });
    $$('.tab').forEach((t) => t.setAttribute('aria-current', t.dataset.view === view ? 'page' : 'false'));
    if (view === 'system') loadSystem();
  }

  /* ---------- Station grid ---------- */

  function initials(title) {
    return title.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
  }

  function tile(s) {
    const t = el('div', 'tile' + (s.active ? '' : ' inactive'));
    t.dataset.id = s.id;
    t.tabIndex = 0;
    t.setAttribute('role', 'button');
    t.setAttribute('aria-label', s.title + (s.active ? '' : ' (inaktiv)') + ', bearbeiten');
    const art = el('div', 'art');
    if (s.logo_url) {
      const img = el('img');
      img.src = s.logo_url;
      img.alt = '';
      img.loading = 'lazy';
      img.draggable = false;
      art.append(img);
    } else {
      art.append(el('span', 'initials', initials(s.title)));
    }
    const dot = el('span', 'dot ' + (s.check_ok === null ? 'unknown' : s.check_ok ? 'ok' : 'fail'));
    dot.title = s.check_info ? 'Letzter Test: ' + s.check_info : 'Noch nicht getestet';
    art.append(dot);
    if (!s.active) art.append(el('span', 'badge', 'inaktiv'));
    t.append(art, el('span', 'tile-name', s.title));
    t.addEventListener('click', () => openEditor(s));
    t.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openEditor(s); } });
    return t;
  }

  function render() {
    const grid = $('#grid');
    grid.replaceChildren(...state.stations.map(tile));
    const active = state.stations.filter((s) => s.active).length;
    $('#summary').textContent = state.stations.length
      ? `${state.stations.length} Sender, davon ${active} aktiv`
      : '';
    $('#empty').hidden = state.stations.length > 0;
    $('#check-all').hidden = state.stations.length === 0;
  }

  async function loadState() {
    const data = await api('state');
    state.stations = data.stations;
    state.mimes = data.mimes;
    const select = $('#mime');
    select.replaceChildren(el('option', '', 'Bitte wählen'));
    select.firstChild.value = '';
    for (const [value, label] of Object.entries(state.mimes)) {
      const o = el('option', '', label);
      o.value = value;
      select.append(o);
    }
    render();
  }

  function initSortable() {
    window.Sortable.create($('#grid'), {
      animation: 160,
      draggable: '.tile',
      delay: 150,
      delayOnTouchOnly: true,
      ghostClass: 'drag-ghost',
      chosenClass: 'drag-chosen',
      onEnd: async (evt) => {
        if (evt.oldIndex === evt.newIndex) return;
        const ids = $$('.tile', $('#grid')).map((t) => Number(t.dataset.id));
        state.stations.sort((a, b) => ids.indexOf(a.id) - ids.indexOf(b.id));
        try {
          await api('stations.reorder', { ids });
          toast('Reihenfolge gespeichert');
        } catch (e) {
          toast(e.message, 'error');
          loadState();
        }
      },
    });
  }

  async function checkAll() {
    const btn = $('#check-all');
    btn.disabled = true;
    let ok = 0;
    let fail = 0;
    for (const [i, s] of state.stations.entries()) {
      btn.textContent = `Prüfe ${i + 1}/${state.stations.length} …`;
      try {
        const { probe } = await api('probe', { url: s.stream_url, id: s.id });
        s.check_ok = probe.ok;
        s.check_info = probe.info;
      } catch (e) {
        s.check_ok = false;
        s.check_info = e.message;
      }
      s.check_ok ? ok++ : fail++;
      render();
    }
    btn.disabled = false;
    btn.textContent = 'Alle Streams prüfen';
    toast(fail ? `${ok} in Ordnung, ${fail} mit Problem (roter Punkt)` : `Alle ${ok} Streams in Ordnung`, fail ? 'error' : '');
  }

  /* ---------- Editor ---------- */

  const dialog = $('#editor');
  const form = $('#station-form');
  // F('title') would return the form's own title attribute, so fields are read via elements
  const F = (name) => form.elements.namedItem(name);

  function clearErrors() {
    $$('[data-err]', form).forEach((e) => { e.textContent = ''; });
  }

  function showLogo(url) {
    destroyCropper();
    $('#logo-preview').hidden = !url;
    if (url) $('#logo-preview').src = url;
    $('#logo-placeholder').hidden = Boolean(url);
    $('#logo-remove').hidden = !url;
  }

  function destroyCropper() {
    if (ed.cropper) ed.cropper.destroy();
    ed.cropper = null;
    $('#crop-wrap').hidden = true;
    $('#crop-tools').hidden = true;
  }

  function openEditor(s) {
    ed.station = s;
    ed.pendingImage = false;
    ed.removeLogo = false;
    form.reset();
    clearErrors();
    $('#editor-title').textContent = s ? s.title : 'Neuer Sender';
    F('title').value = s ? s.title : '';
    F('stream_url').value = s ? s.stream_url : '';
    F('mime').value = s ? s.mime : '';
    F('description').value = s ? s.description : '';
    F('active').checked = s ? s.active : true;
    F('is_playlist').value = s && s.is_playlist ? '1' : '0';
    $('#smapi-id').textContent = s ? `SMAPI-ID: ${s.smapi_id} (bleibt dauerhaft gleich, Sonos speichert sie in Favoriten)` : '';
    $('#delete').hidden = !s;
    resetDelete();
    $('#logo-url-row').hidden = true;
    $('#crop-bg').value = '';
    $('#crop-bg-color').hidden = true;
    ed.bg = '';
    const probe = $('#probe-result');
    probe.hidden = !(s && s.check_info);
    if (s && s.check_info) setProbe(s.check_ok, 'Letzter Test: ' + s.check_info);
    showLogo(s ? s.logo_url : null);
    updateCount();
    dialog.showModal();
    F('title').focus();
  }

  function closeEditor() {
    destroyCropper();
    dialog.close();
  }

  function updateCount() {
    $('#desc-count').textContent = `${F('description').value.length}/500`;
  }

  function setProbe(ok, text) {
    const p = $('#probe-result');
    p.hidden = false;
    p.className = 'probe ' + (ok ? 'ok' : 'fail');
    p.textContent = (ok ? '✓ ' : '✗ ') + text;
  }

  async function runProbe() {
    const url = F('stream_url').value.trim();
    clearErrors();
    if (!/^https?:\/\//i.test(url)) {
      $('[data-err="stream_url"]').textContent = 'Bitte eine gültige http(s)-Adresse eingeben.';
      return;
    }
    const btn = $('#probe');
    btn.disabled = true;
    btn.textContent = 'Teste …';
    try {
      const { probe } = await api('probe', { url, id: ed.station && ed.station.stream_url === url ? ed.station.id : null });
      setProbe(probe.ok, probe.info);
      if (probe.ok) {
        F('mime').value = probe.mime;
        F('is_playlist').value = probe.is_playlist ? '1' : '0';
        if (!F('title').value.trim() && probe.name) F('title').value = probe.name;
        if (!F('description').value.trim() && probe.description) F('description').value = probe.description.slice(0, 500);
        updateCount();
      }
      if (ed.station && ed.station.stream_url === url) {
        ed.station.check_ok = probe.ok;
        ed.station.check_info = probe.info;
        render();
      }
    } catch (e) {
      setProbe(false, e.message);
    } finally {
      btn.disabled = false;
      btn.textContent = 'Stream testen';
    }
  }

  /* Logo editor (Cropper.js) */

  function startCropper(src) {
    destroyCropper();
    $('#logo-preview').hidden = true;
    $('#logo-placeholder').hidden = true;
    $('#crop-wrap').hidden = false;
    $('#crop-tools').hidden = false;
    const img = $('#crop-img');
    img.src = src;
    ed.cropper = new window.Cropper(img, {
      aspectRatio: 1,
      viewMode: 0,
      dragMode: 'move',
      autoCropArea: 1,
      background: true,
      restore: false,
      toggleDragModeOnDblclick: false,
    });
    ed.pendingImage = true;
    ed.removeLogo = false;
    applyBg();
  }

  function applyBg() {
    const wrap = $('#crop-wrap');
    wrap.classList.toggle('has-bg', Boolean(ed.bg));
    wrap.style.backgroundColor = ed.bg || '';
  }

  function cropAction(kind) {
    const c = ed.cropper;
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

  function readFile(file) {
    if (!file) return;
    if (file.size > MAX_BYTES) {
      toast('Bild ist größer als 8 MB.', 'error');
      return;
    }
    const reader = new FileReader();
    reader.onload = () => startCropper(reader.result);
    reader.readAsDataURL(file);
  }

  async function loadFromUrl() {
    const url = $('#logo-url').value.trim();
    if (!/^https?:\/\//i.test(url)) {
      toast('Bitte eine Bildadresse mit http(s) eingeben.', 'error');
      return;
    }
    const btn = $('#logo-url-load');
    btn.disabled = true;
    try {
      const { dataUrl } = await api('logo.fetch', { url });
      startCropper(dataUrl);
      $('#logo-url-row').hidden = true;
    } catch (e) {
      toast(e.message, 'error');
    } finally {
      btn.disabled = false;
    }
  }

  function croppedBlob() {
    const canvas = ed.cropper.getCroppedCanvas({
      width: 600,
      height: 600,
      fillColor: ed.bg || 'transparent',
      imageSmoothingEnabled: true,
      imageSmoothingQuality: 'high',
    });
    return new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
  }

  /* Save / delete */

  async function save(e) {
    e.preventDefault();
    clearErrors();
    const btn = $('#save');
    btn.disabled = true;
    try {
      const payload = {
        id: ed.station ? ed.station.id : null,
        title: F('title').value,
        stream_url: F('stream_url').value,
        mime: F('mime').value,
        description: F('description').value,
        active: F('active').checked,
        is_playlist: F('is_playlist').value === '1',
      };
      let { station } = await api('station.save', payload);
      if (ed.pendingImage && ed.cropper) {
        const fd = new FormData();
        fd.append('id', station.id);
        fd.append('logo', await croppedBlob(), 'logo.png');
        ({ station } = await api('logo.upload', fd));
      } else if (ed.removeLogo) {
        ({ station } = await api('logo.remove', { id: station.id }));
      }
      const i = state.stations.findIndex((s) => s.id === station.id);
      if (i >= 0) state.stations[i] = station; else state.stations.push(station);
      render();
      closeEditor();
      toast('Gespeichert');
    } catch (err) {
      for (const [field, msg] of Object.entries(err.fields || {})) {
        const slot = $(`[data-err="${field}"]`, form);
        if (slot) slot.textContent = msg;
      }
      toast(err.message, 'error');
    } finally {
      btn.disabled = false;
    }
  }

  let deleteTimer;
  function resetDelete() {
    clearTimeout(deleteTimer);
    const btn = $('#delete');
    btn.textContent = 'Löschen';
    btn.classList.remove('confirm');
  }

  async function remove() {
    const btn = $('#delete');
    if (!btn.classList.contains('confirm')) {
      btn.textContent = 'Wirklich löschen?';
      btn.classList.add('confirm');
      deleteTimer = setTimeout(resetDelete, 4000);
      return;
    }
    try {
      await api('station.delete', { id: ed.station.id });
      state.stations = state.stations.filter((s) => s.id !== ed.station.id);
      render();
      closeEditor();
      toast('Sender gelöscht');
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
      fillKv('#status', data.status, false);
      $('#log-enabled').checked = data.log_enabled;
      loadLog();
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  async function loadLog() {
    const { log } = await api('log');
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

  window.addEventListener('hashchange', showView);
  $('#add-station').addEventListener('click', () => openEditor(null));
  $('#add-first').addEventListener('click', () => openEditor(null));
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
  dialog.addEventListener('cancel', destroyCropper);
  form.addEventListener('submit', save);
  F('description').addEventListener('input', updateCount);
  $('#probe').addEventListener('click', runProbe);
  $('#delete').addEventListener('click', remove);
  $('#logo-file').addEventListener('change', (e) => { readFile(e.target.files[0]); e.target.value = ''; });
  $('#logo-from-url').addEventListener('click', () => { $('#logo-url-row').hidden = false; $('#logo-url').focus(); });
  $('#logo-url-load').addEventListener('click', loadFromUrl);
  $('#logo-url').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); loadFromUrl(); } });
  $('#logo-remove').addEventListener('click', () => { showLogo(null); ed.pendingImage = false; ed.removeLogo = Boolean(ed.station && ed.station.logo_url); });
  $$('[data-crop]').forEach((b) => b.addEventListener('click', () => cropAction(b.dataset.crop)));
  $('#crop-bg').addEventListener('change', (e) => {
    const custom = e.target.value === 'custom';
    $('#crop-bg-color').hidden = !custom;
    ed.bg = custom ? $('#crop-bg-color').value : e.target.value;
    applyBg();
  });
  $('#crop-bg-color').addEventListener('input', (e) => { ed.bg = e.target.value; applyBg(); });

  $('#log-enabled').addEventListener('change', async (e) => {
    try {
      await api('log.enabled', { enabled: e.target.checked });
      toast(e.target.checked ? 'Protokoll eingeschaltet' : 'Protokoll ausgeschaltet');
    } catch (err) {
      toast(err.message, 'error');
    }
  });
  $('#log-refresh').addEventListener('click', loadLog);
  $('#log-clear').addEventListener('click', async () => { await api('log.clear', {}); loadLog(); });

  initSortable();
  loadState().catch((e) => toast(e.message, 'error'));
  showView();
})();
